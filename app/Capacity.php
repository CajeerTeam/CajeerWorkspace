<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const CAPACITY_DAYS=['mon'=>'Пн','tue'=>'Вт','wed'=>'Ср','thu'=>'Чт','fri'=>'Пт','sat'=>'Сб','sun'=>'Вс'];
function capacityAccess(PDO $db,array $user): array {
    $u=orgOwner($db,$user);
    if(!mfaFresh())throw new Problem('Подтвердите второй фактор в разделе «Безопасность».',403);
    return $u;
}
function capacityCurrentWeek(): string {return (new \DateTimeImmutable('today',new \DateTimeZone('UTC')))->modify('monday this week')->format('Y-m-d');}
function capacityEditable(string $week): void {
    if($week<capacityCurrentWeek())throw new Problem('Прошедшая неделя доступна только для чтения.',409);
}
function capacityTotal(array $days): int {return array_sum(array_map('intval',array_intersect_key($days,CAPACITY_DAYS)));}
function capacityDays(array $form): array {
    $days=[];foreach(CAPACITY_DAYS as $k=>$label)$days[$k]=workloadInt($form[$k]??null,0,1440);return $days;
}
function capacityCalendar(PDO $db,int $org,int $uid,string $week): ?array {
    return query($db,'SELECT * FROM capacity_weeks WHERE organization_id=? AND user_id=? AND week_on=?',[$org,$uid,$week])->fetch()?:null;
}
function capacityAllocated(PDO $db,int $org,int $uid,string $week): int {
    return (int)query($db,'SELECT COALESCE(SUM(a.minutes),0) FROM project_availability a JOIN projects p ON p.id=a.project_id WHERE p.organization_id=? AND a.user_id=? AND a.week_on=?',[$org,$uid,$week])->fetchColumn();
}
function capacityAllocation(PDO $db,int $project,int $uid,string $week): array {
    return query($db,'SELECT a.minutes,a.version,c.team_id FROM project_availability a LEFT JOIN capacity_teams c ON c.project_id=a.project_id AND c.user_id=a.user_id AND c.week_on=a.week_on WHERE a.project_id=? AND a.user_id=? AND a.week_on=?',[$project,$uid,$week])->fetch()?:['minutes'=>0,'version'=>0,'team_id'=>null];
}
// Also used by the legacy workload form; no second source of project capacity.
function capacityGuardAvailability(PDO $db,int $org,int $project,int $uid,string $week,int $minutes): void {
    $calendar=capacityCalendar($db,$org,$uid,$week);$old=capacityAllocation($db,$project,$uid,$week);
    if(!$calendar&&$old['team_id']===null)return; // Legacy rows remain editable until a calendar is adopted.
    capacityEditable($week);
    if($minutes>(int)$old['minutes']&&(!$calendar||capacityAllocated($db,$org,$uid,$week)-(int)$old['minutes']+$minutes>capacityTotal($calendar)))throw new Problem('Распределение превышает доступность сотрудника.',409);
}
function capacityMember(PDO $db,int $org,int $project,int $uid,int $team): void {
    $m=organizationUser($db,['id'=>$uid],$org);
    requireProjectRight($db,$m,$project,'work');
    entity($db,'SELECT tm.user_id FROM team_members tm JOIN teams t ON t.id=tm.team_id AND t.organization_id=tm.organization_id WHERE tm.organization_id=? AND tm.team_id=? AND tm.user_id=?',[$org,$team,$uid]);
}
function capacitySetAllocation(PDO $db,int $org,int $project,int $uid,string $week,int $minutes,?int $team): void {
    entity($db,'SELECT id FROM projects WHERE organization_id=? AND id=?',[$org,$project]);
    if($minutes>0){if($team===null)throw new Problem('Выберите команду.');capacityMember($db,$org,$project,$uid,$team);if(!capacityCalendar($db,$org,$uid,$week))throw new Problem('Сначала заполните календарь сотрудника.',409);}
    capacityGuardAvailability($db,$org,$project,$uid,$week,$minutes);
    query($db,'INSERT INTO project_availability(project_id,user_id,week_on,minutes,version) VALUES(?,?,?,?,1) ON CONFLICT(project_id,user_id,week_on) DO UPDATE SET minutes=excluded.minutes,version=project_availability.version+1',[$project,$uid,$week,$minutes]);
    if($minutes===0)query($db,'DELETE FROM capacity_teams WHERE project_id=? AND user_id=? AND week_on=?',[$project,$uid,$week]);
    else query($db,'INSERT INTO capacity_teams(organization_id,project_id,user_id,week_on,team_id) VALUES(?,?,?,?,?) ON CONFLICT(project_id,user_id,week_on) DO UPDATE SET team_id=excluded.team_id',[$org,$project,$uid,$week,$team]);
}
function capacityWeekData(PDO $db,int $org,string $week): array {
    $cal=query($db,'SELECT * FROM capacity_weeks WHERE organization_id=? AND week_on=? ORDER BY user_id',[$org,$week])->fetchAll();
    $alloc=query($db,'SELECT a.project_id,a.user_id,a.minutes,a.version,c.team_id FROM project_availability a JOIN projects p ON p.id=a.project_id LEFT JOIN capacity_teams c ON c.project_id=a.project_id AND c.user_id=a.user_id AND c.week_on=a.week_on WHERE p.organization_id=? AND a.week_on=? ORDER BY a.project_id,a.user_id',[$org,$week])->fetchAll();
    return ['week'=>$week,'calendars'=>$cal,'allocations'=>$alloc];
}
function capacityFingerprint(array $data): string {return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));}
function capacityCommand(PDO $db,array $user,string $key,string $act,array $f): void {
    $u=capacityAccess($db,$user);$org=(int)$u['organization_id'];$actor=(int)$u['id'];$week=weekStart($f['week']??'');capacityEditable($week);
    if(!preg_match('/\A[a-f0-9]{32}\z/',$key))throw new Problem('Обновите форму.');
    $reason=word($f['reason']??'',500,'Причина',$act!=='capacity.calendar');
    $uid=numberId($f['user_id']??0);
    $snapshot=word($f['snapshot']??'',64,'Версия плана');
    $payload=match($act){
        'capacity.calendar'=>capacityDays($f),
        'capacity.assign'=>['project'=>numberId($f['project_id']??0),'team'=>optionalId($f['team_id']??''),'minutes'=>workloadInt($f['minutes']??null,0,10080)],
        'capacity.transfer'=>['project'=>numberId($f['project_id']??0),'target_project'=>numberId($f['target_project']??0),'target_user'=>numberId($f['target_user']??0),'target_team'=>numberId($f['target_team']??0),'minutes'=>workloadInt($f['minutes']??null,1,10080)],
        default=>throw new Problem('Неизвестная операция.')};
    $digest=capacityFingerprint([$org,$act,$week,$uid,$reason,$snapshot,$payload]);
    atomic($db,function()use($db,$u,$org,$actor,$week,$uid,$reason,$snapshot,$payload,$digest,$act,$key){
        capacityAccess($db,$u);
        $previous=query($db,'SELECT fingerprint FROM action_log WHERE actor_id=? AND request_key=?',[$actor,$key])->fetchColumn();
        if($previous!==false){if(!hash_equals($previous,$digest))throw new Problem('Повторный запрос изменён.',409);return;}
        if(!hash_equals(capacityFingerprint(capacityWeekData($db,$org,$week)),$snapshot))throw new Problem('План изменён. Обновите страницу.',409);
        $before=null;$after=null;
        if($act==='capacity.calendar'){
            $before=capacityCalendar($db,$org,$uid,$week);
            organizationUser($db,['id'=>$uid],$org);
            if(capacityAllocated($db,$org,$uid,$week)>capacityTotal($payload))throw new Problem('Сначала перераспределите лишнюю ёмкость.',409);
            query($db,'INSERT INTO capacity_weeks(organization_id,user_id,week_on,mon,tue,wed,thu,fri,sat,sun,reason,actor_id,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(organization_id,user_id,week_on) DO UPDATE SET mon=excluded.mon,tue=excluded.tue,wed=excluded.wed,thu=excluded.thu,fri=excluded.fri,sat=excluded.sat,sun=excluded.sun,reason=excluded.reason,actor_id=excluded.actor_id,updated_at=excluded.updated_at',[$org,$uid,$week,...array_values($payload),$reason,$actor,time()]);
            $after=$payload;
        }elseif($act==='capacity.assign'){
            entity($db,'SELECT id FROM projects WHERE organization_id=? AND id=?',[$org,$payload['project']]);
            // Zeroing a stale allocation is allowed even after membership removal, but cannot create a foreign row.
            if($payload['minutes']===0&&!capacityAllocation($db,$payload['project'],$uid,$week)['version'])throw new Problem('Назначение не найдено.',404);
            $before=capacityAllocation($db,$payload['project'],$uid,$week);
            capacitySetAllocation($db,$org,$payload['project'],$uid,$week,$payload['minutes'],$payload['team']);
            $after=capacityAllocation($db,$payload['project'],$uid,$week);
        }else{
            $p=$payload['project'];$tp=$payload['target_project'];$tu=$payload['target_user'];$tt=$payload['target_team'];$n=$payload['minutes'];
            entity($db,'SELECT id FROM projects WHERE organization_id=? AND id=?',[$org,$p]);entity($db,'SELECT id FROM projects WHERE organization_id=? AND id=?',[$org,$tp]);
            if($p===$tp&&$uid===$tu)throw new Problem('Выберите другое назначение.');
            $from=capacityAllocation($db,$p,$uid,$week);$to=capacityAllocation($db,$tp,$tu,$week);
            $before=['source'=>$from,'target'=>$to];
            if((int)$from['minutes']<$n)throw new Problem('Недостаточно исходной ёмкости.',409);
            if((int)$to['minutes']>0&&$to['team_id']!==null&&(int)$to['team_id']!==$tt)throw new Problem('Целевое назначение относится к другой команде.',409);
            // Reducing the source is permitted even if its team or project membership was revoked.
            query($db,'UPDATE project_availability SET minutes=minutes-?,version=version+1 WHERE project_id=? AND user_id=? AND week_on=?',[$n,$p,$uid,$week]);
            if((int)$from['minutes']===$n)query($db,'DELETE FROM capacity_teams WHERE project_id=? AND user_id=? AND week_on=?',[$p,$uid,$week]);
            capacitySetAllocation($db,$org,$tp,$tu,$week,(int)$to['minutes']+$n,$tt);
            $after=['source'=>capacityAllocation($db,$p,$uid,$week),'target'=>capacityAllocation($db,$tp,$tu,$week)];
        }
        query($db,'INSERT INTO action_log(actor_id,request_key,fingerprint,action,object_id) VALUES(?,?,?,?,?)',[$actor,$key,$digest,$act,$uid]);
        auditEvent($db,$u,null,$act,$uid,json_encode(['week'=>$week,'reason'=>$reason,'data'=>$payload,'before'=>$before,'after'=>$after],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    });
}
// Only a known calendar with a valid, non-overbooked allocation contributes to the forecast.
function capacityForecast(array $demand,array $weeks): array {
    $remaining=[];foreach($demand as $p=>$d)$remaining[$p]=(int)$d['minutes'];$out=[];
    foreach($weeks as $week=>$supply){foreach($remaining as $p=>$left){$cap=(int)($supply[$p]??0);$used=min($left,$cap);$remaining[$p]-=$used;$out[$p][$week]=['capacity'=>$cap,'covered'=>$used,'remaining'=>$remaining[$p]];}}
    return $out;
}
function capacityPage(PDO $db,array $user,string $week): array {
    $u=capacityAccess($db,$user);$org=(int)$u['organization_id'];weekStart($week);
    return atomic($db,function()use($db,$u,$org,$week){
        capacityAccess($db,$u);$data=capacityWeekData($db,$org,$week);
        $people=array_column(query($db,'SELECT u.id,u.name FROM users u JOIN organization_members m ON m.user_id=u.id WHERE m.organization_id=? AND u.enabled=1 ORDER BY u.name,u.id',[$org])->fetchAll(),'name','id');
        $teams=array_column(query($db,'SELECT id,name FROM teams WHERE organization_id=? ORDER BY name,id',[$org])->fetchAll(),'name','id');
        $projects=array_column(query($db,'SELECT id,title FROM projects WHERE organization_id=? ORDER BY title,id',[$org])->fetchAll(),'title','id');
        $members=[];foreach(query($db,'SELECT team_id,user_id FROM team_members WHERE organization_id=?',[$org])->fetchAll() as $r)$members[(int)$r['team_id']][(int)$r['user_id']]=true;
        $rights=[];foreach(query($db,"SELECT m.user_id,p.id project_id,m.role,a.role project_role FROM organization_members m JOIN users u ON u.id=m.user_id JOIN projects p ON p.organization_id=m.organization_id LEFT JOIN project_members pm ON pm.project_id=p.id AND pm.user_id=m.user_id LEFT JOIN project_access a ON a.project_id=p.id AND a.user_id=m.user_id WHERE m.organization_id=? AND u.enabled=1 AND (m.role='owner' OR pm.user_id IS NOT NULL)",[$org])->fetchAll() as $r)$rights[(int)$r['project_id']][(int)$r['user_id']]=roleAllows($r['role']==='owner'?'owner':($r['project_role']??$r['role']),'work');
        $demand=[];foreach($projects as $p=>$title)$demand[$p]=['minutes'=>0,'unknown'=>0,'blocked'=>0,'tasks'=>0];
        // Use the latest non-closed sprint estimate (a task may have historical closed sprint estimates). Approved time is a historical fact, never modified.
        $rows=query($db,"SELECT t.id,t.project_id,t.state,(SELECT st.estimate_minutes FROM sprint_tasks st JOIN sprints s ON s.id=st.sprint_id WHERE st.task_id=t.id AND s.state<>'closed' ORDER BY s.starts_on DESC,s.id DESC LIMIT 1) estimate,COALESCE((SELECT SUM(e.minutes) FROM time_entries e JOIN timesheets ts ON ts.id=e.sheet_id WHERE e.task_id=t.id AND ts.status='approved'),0) actual,CASE WHEN EXISTS(SELECT 1 FROM task_dependencies d JOIN tasks pre ON pre.id=d.prerequisite_id WHERE d.task_id=t.id AND pre.state<>'done') THEN 1 ELSE 0 END blocked FROM tasks t JOIN projects p ON p.id=t.project_id WHERE p.organization_id=? AND t.state<>'done'",[$org])->fetchAll();
        foreach($rows as $r){$p=(int)$r['project_id'];$demand[$p]['tasks']++;$demand[$p]['blocked']+=(int)$r['blocked'];if($r['estimate']===null||(int)$r['estimate']===0)$demand[$p]['unknown']++;else $demand[$p]['minutes']+=max(0,(int)$r['estimate']-(int)$r['actual']);}
        $weeks=[];$summary=[];$teamTotals=[];
        for($i=0;$i<8;$i++){
            $w=(new \DateTimeImmutable($week))->modify('+'.$i.' weeks')->format('Y-m-d');$wd=$i===0?$data:capacityWeekData($db,$org,$w);$cal=array_column($wd['calendars'],null,'user_id');$totals=[];
            foreach($wd['allocations'] as $r)$totals[$r['user_id']]=($totals[$r['user_id']]??0)+(int)$r['minutes'];
            $weeks[$w]=[];
            foreach($wd['allocations'] as $r){$uid=(int)$r['user_id'];$p=(int)$r['project_id'];$team=$r['team_id'];$n=(int)$r['minutes'];
                $valid=isset($people[$uid],$cal[$uid])&&($rights[$p][$uid]??false)&&$totals[$uid]<=capacityTotal($cal[$uid])&&($team===null||isset($members[$team][$uid]));
                if($valid&&$w>capacityCurrentWeek())$weeks[$w][$p]=($weeks[$w][$p]??0)+$n;
                if($i===0){$r['valid']=$valid;$summary[]=$r;if($valid)$teamTotals[$team??0]=($teamTotals[$team??0]??0)+$n;}
            }
        }
        $history=query($db,"SELECT a.*,u.name FROM audit_events a LEFT JOIN users u ON u.id=a.actor_id WHERE a.organization_id=? AND a.event IN ('capacity.calendar','capacity.assign','capacity.transfer') ORDER BY a.id DESC LIMIT 50",[$org])->fetchAll();
        return ['week'=>$week,'snapshot'=>capacityFingerprint($data),'calendars'=>array_column($data['calendars'],null,'user_id'),'allocations'=>$summary,'people'=>$people,'teams'=>$teams,'projects'=>$projects,'teamTotals'=>$teamTotals,'demand'=>$demand,'forecast'=>capacityForecast($demand,$weeks),'history'=>$history,'editable'=>$week>=capacityCurrentWeek()];
    });
}
