<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const SPRINT_STATES=['planned'=>'Запланирован','active'=>'В работе','closed'=>'Закрыт'];
const TIME_STATES=['draft'=>'Черновик','submitted'=>'На согласовании','approved'=>'Согласован','rejected'=>'Возвращён'];
const RATE_MODES=['hourly'=>'Почасовая','fixed'=>'Фиксированная'];
function workloadInt(mixed $value,int $min,int $max): int {
    if (!is_int($value)&&!is_string($value)) throw new Problem('Укажите целое число.');
    $n=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);
    if ($n===false) throw new Problem("Допустимо целое число от $min до $max.");
    return $n;
}
function weekStart(mixed $value): string {
    $d=calendarDate($value); $date=new \DateTimeImmutable($d,new \DateTimeZone('UTC'));
    if ($date->format('N')!=='1') throw new Problem('Укажите понедельник отчётной недели.');
    return $d;
}
function weekEnd(string $week): string { return (new \DateTimeImmutable($week))->modify('+6 days')->format('Y-m-d'); }
function timeCost(int $minutes,int $rate): int { return intdiv($minutes*$rate+30,60); }
function workloadMember(PDO $db,array $u,int $project,int $uid): void {
    $member=organizationUser($db,['id'=>$uid],(int)$u['organization_id']);
    requireProjectRight($db,$member,$project,'work');
}
function saveSprint(PDO $db,array $user,string $key,int $project,?int $id,int $version,array $form): int {
    $u=organizationUser($db,$user); requireProjectRight($db,$u,$project,'manage');
    $title=word($form['title']??'',180,'Спринт'); $start=calendarDate($form['starts_on']??''); $end=calendarDate($form['ends_on']??'');
    if ($start>$end || (new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days>90) throw new Problem('Спринт должен длиться от 1 до 91 дня.');
    return action($db,$u,$key,'sprint.save',[$project,$id,$version,$title,$start,$end],function() use($db,$u,$project,$id,$version,$title,$start,$end) {
        requireProjectRight($db,$u,$project,'manage');
        if ($id!==null) {
            $row=entity($db,'SELECT * FROM sprints WHERE project_id=? AND id=?',[$project,$id]);
            if ($row['state']!=='planned'||(int)$row['version']!==$version) throw new Problem('Редактировать можно только актуальный запланированный спринт.',409);
        }
        if (query($db,'SELECT 1 FROM sprints WHERE project_id=? AND starts_on<=? AND ends_on>=? AND id<>? LIMIT 1',[$project,$end,$start,$id??0])->fetchColumn()) throw new Problem('Даты пересекаются с другим спринтом.',409);
        if ($id===null) return insertId($db,'INSERT INTO sprints(project_id,title,starts_on,ends_on) VALUES(?,?,?,?)',[$project,$title,$start,$end]);
        query($db,'UPDATE sprints SET title=?,starts_on=?,ends_on=?,version=version+1 WHERE id=?',[$title,$start,$end,$id]); return $id;
    });
}
function sprintTransition(PDO $db,array $user,string $key,int $project,int $id,int $version,string $state): int {
    $u=organizationUser($db,$user); requireProjectRight($db,$u,$project,'manage'); choice($state,SPRINT_STATES);
    return action($db,$u,$key,'sprint.state',[$project,$id,$version,$state],function() use($db,$u,$project,$id,$version,$state) {
        requireProjectRight($db,$u,$project,'manage'); $s=entity($db,'SELECT * FROM sprints WHERE project_id=? AND id=?',[$project,$id]);
        if ((int)$s['version']!==$version || !in_array($s['state'].'/'.$state,['planned/active','active/closed'],true)) throw new Problem('Недопустимый переход или устаревшая форма.',409);
        if ($state==='active' && query($db,"SELECT 1 FROM sprints WHERE project_id=? AND state='active'",[$project])->fetchColumn()) throw new Problem('Закройте текущий активный спринт.',409);
        if ($state==='closed') query($db,"UPDATE sprint_tasks SET completed_on_close=CASE WHEN task_id IN (SELECT id FROM tasks WHERE project_id=? AND state='done') THEN 1 ELSE 0 END WHERE sprint_id=?",[$project,$id]);
        query($db,'UPDATE sprints SET state=?,version=version+1 WHERE id=?',[$state,$id]); return $id;
    });
}
function sprintTask(PDO $db,array $user,string $key,int $project,int $sprint,int $version,int $task,?int $estimate): int {
    $u=organizationUser($db,$user); requireProjectRight($db,$u,$project,'manage');
    if ($estimate!==null) workloadInt($estimate,0,60000);
    return action($db,$u,$key,'sprint.task',[$project,$sprint,$version,$task,$estimate],function() use($db,$u,$project,$sprint,$version,$task,$estimate) {
        requireProjectRight($db,$u,$project,'manage'); $s=entity($db,'SELECT * FROM sprints WHERE project_id=? AND id=?',[$project,$sprint]);
        if ($s['state']==='closed'||(int)$s['version']!==$version) throw new Problem('Спринт закрыт или изменён.',409);
        entity($db,'SELECT id FROM tasks WHERE project_id=? AND id=?',[$project,$task]);
        if ($estimate===null) query($db,'DELETE FROM sprint_tasks WHERE sprint_id=? AND task_id=?',[$sprint,$task]);
        else {
            if (query($db,"SELECT 1 FROM sprint_tasks st JOIN sprints s ON s.id=st.sprint_id WHERE st.project_id=? AND st.task_id=? AND st.sprint_id<>? AND s.state<>'closed'",[$project,$task,$sprint])->fetchColumn()) throw new Problem('Задача уже в другом незакрытом спринте.',409);
            query($db,'INSERT INTO sprint_tasks(sprint_id,project_id,task_id,estimate_minutes) VALUES(?,?,?,?) ON CONFLICT(sprint_id,task_id) DO UPDATE SET estimate_minutes=excluded.estimate_minutes',[$sprint,$project,$task,$estimate]);
        }
        query($db,'UPDATE sprints SET version=version+1 WHERE id=?',[$sprint]); return $sprint;
    });
}
function saveAvailability(PDO $db,array $user,string $key,int $project,int $uid,string $week,int $minutes,int $version): int {
    $u=organizationUser($db,$user); requireProjectRight($db,$u,$project,(int)$u['id']===$uid?'work':'manage'); weekStart($week); workloadInt($minutes,0,10080);
    return action($db,$u,$key,'availability.save',[$project,$uid,$week,$minutes,$version],function() use($db,$u,$project,$uid,$week,$minutes,$version) {
        requireProjectRight($db,$u,$project,(int)$u['id']===$uid?'work':'manage'); workloadMember($db,$u,$project,$uid);
        $old=query($db,'SELECT version FROM project_availability WHERE project_id=? AND user_id=? AND week_on=?',[$project,$uid,$week])->fetchColumn();
        if ((int)$old!==$version) throw new Problem('Доступность уже изменена.',409);
        $allocation=capacityAllocation($db,$project,$uid,$week);
        if($allocation['team_id']!==null)throw new Problem('Назначение команды изменяется в разделе «Ёмкость команд».',409);
        capacityGuardAvailability($db,(int)$u['organization_id'],$project,$uid,$week,$minutes);
        query($db,'INSERT INTO project_availability(project_id,user_id,week_on,minutes,version) VALUES(?,?,?,?,1) ON CONFLICT(project_id,user_id,week_on) DO UPDATE SET minutes=excluded.minutes,version=project_availability.version+1',[$project,$uid,$week,$minutes]); if($minutes===0)query($db,'DELETE FROM capacity_teams WHERE project_id=? AND user_id=? AND week_on=?',[$project,$uid,$week]); return $project;
    });
}
function saveRate(PDO $db,array $user,string $key,int $project,int $version,array $form): int {
    $u=orgOwner($db,$user); allowedProject($db,$u,$project);
    $mode=choice($form['mode']??'',RATE_MODES); $currency=choice($form['currency']??'',array_combine(CURRENCIES,CURRENCIES)); $amount=moneyMinor($form['amount']??'');
    return action($db,$u,$key,'rate.save',[$project,$version,$mode,$currency,$amount],function() use($db,$u,$project,$version,$mode,$currency,$amount) {
        orgOwner($db,$u); allowedProject($db,$u,$project);
        $old=query($db,'SELECT version FROM project_rates WHERE project_id=?',[$project])->fetchColumn();
        if ((int)$old!==$version) throw new Problem('Ставка уже изменена.',409);
        query($db,'INSERT INTO project_rates(project_id,mode,currency,amount_minor,version) VALUES(?,?,?,?,1) ON CONFLICT(project_id) DO UPDATE SET mode=excluded.mode,currency=excluded.currency,amount_minor=excluded.amount_minor,version=project_rates.version+1',[$project,$mode,$currency,$amount]); return $project;
    });
}
function sheetRow(PDO $db,int $project,int $id): array { return entity($db,'SELECT * FROM timesheets WHERE project_id=? AND id=?',[$project,$id]); }
function ownEditableSheet(array $s,array $u,int $version): void {
    if ((int)$s['user_id']!==(int)$u['id']) throw new Problem('Это чужой таймшит.',403);
    if ((int)$s['version']!==$version || !in_array($s['status'],['draft','rejected'],true)) throw new Problem('Таймшит уже изменён или заблокирован.',409);
}
function saveTime(PDO $db,array $user,string $key,int $project,string $week,int $version,array $form): int {
    $u=organizationUser($db,$user); requireProjectRight($db,$u,$project,'work'); weekStart($week);
    $task=numberId($form['task_id']??0); $day=calendarDate($form['worked_on']??''); $minutes=workloadInt($form['minutes']??null,0,1440); $note=word($form['note']??'',500,'Комментарий',false);
    if ($day<$week||$day>weekEnd($week)||$day>gmdate('Y-m-d')) throw new Problem('Дата должна быть в выбранной неделе и не в будущем.');
    return action($db,$u,$key,'time.save',[$project,$week,$version,$task,$day,$minutes,$note],function() use($db,$u,$project,$week,$version,$task,$day,$minutes,$note) {
        requireProjectRight($db,$u,$project,'work');
        $t=entity($db,'SELECT * FROM tasks WHERE project_id=? AND id=?',[$project,$task]);
        $s=query($db,'SELECT * FROM timesheets WHERE project_id=? AND user_id=? AND week_on=?',[$project,$u['id'],$week])->fetch();
        if (!$s) {
            if ($version!==0||$minutes===0) throw new Problem('Таймшит не найден или изменён.',409);
            $id=insertId($db,'INSERT INTO timesheets(project_id,user_id,week_on) VALUES(?,?,?)',[$project,$u['id'],$week]);
        } else { ownEditableSheet($s,$u,$version); $id=(int)$s['id']; }
        $old=query($db,'SELECT id FROM time_entries WHERE sheet_id=? AND task_id=? AND worked_on=?',[$id,$task,$day])->fetchColumn();
        if ($minutes===0) {
            if (!$old) throw new Problem('Запись времени не найдена.',404);
            query($db,'DELETE FROM time_entries WHERE id=?',[$old]);
        } else {
            if (!$old && (int)$t['assignee_id']!==(int)$u['id']&&!roleAllows(projectRole($db,$u,$project),'manage')) throw new Problem('Учитывайте время по назначенным вам задачам.',403);
            $used=(int)query($db,'SELECT COALESCE(SUM(e.minutes),0) FROM time_entries e JOIN timesheets s ON s.id=e.sheet_id WHERE s.user_id=? AND e.worked_on=? AND e.id<>?',[$u['id'],$day,$old?:0])->fetchColumn();
            if ($used+$minutes>1440) throw new Problem('Суммарное время за сутки превышает 24 часа.',409);
            query($db,'INSERT INTO time_entries(project_id,sheet_id,task_id,worked_on,minutes,note) VALUES(?,?,?,?,?,?) ON CONFLICT(sheet_id,task_id,worked_on) DO UPDATE SET minutes=excluded.minutes,note=excluded.note',[$project,$id,$task,$day,$minutes,$note]);
        }
        query($db,'UPDATE timesheets SET version=version+1 WHERE id=?',[$id]); return $id;
    });
}
function submitTime(PDO $db,array $user,string $key,int $project,int $id,int $version): int {
    $u=organizationUser($db,$user); requireProjectRight($db,$u,$project,'work');
    return action($db,$u,$key,'time.submit',[$project,$id,$version],function() use($db,$u,$project,$id,$version) {
        requireProjectRight($db,$u,$project,'work'); $s=sheetRow($db,$project,$id); ownEditableSheet($s,$u,$version);
        $total=(int)query($db,'SELECT COALESCE(SUM(minutes),0) FROM time_entries WHERE sheet_id=?',[$id])->fetchColumn();
        if ($total<1||$total>10080) throw new Problem('В таймшите должны быть записи времени за неделю.',409);
        $rate=query($db,'SELECT * FROM project_rates WHERE project_id=?',[$project])->fetch();
        $cost=$rate&&$rate['mode']==='hourly'?timeCost($total,(int)$rate['amount_minor']):null;
        query($db,"UPDATE timesheets SET status='submitted',version=version+1,total_minutes=?,rate_mode=?,rate_currency=?,rate_minor=?,rate_version=?,cost_minor=?,submitted_at=?,decided_at=NULL,decided_by=NULL,decision_note='' WHERE id=?",[$total,$rate['mode']??null,$rate['currency']??null,$rate['amount_minor']??null,$rate['version']??null,$cost,time(),$id]); return $id;
    });
}
function decideTime(PDO $db,array $user,string $key,int $project,int $id,int $version,bool $approve,string $note): int {
    $u=organizationUser($db,$user); requireProjectRight($db,$u,$project,'manage'); $note=word($note,500,'Решение',!$approve);
    return action($db,$u,$key,$approve?'time.approve':'time.reject',[$project,$id,$version,$note],function() use($db,$u,$project,$id,$version,$approve,$note) {
        requireProjectRight($db,$u,$project,'manage'); $s=sheetRow($db,$project,$id);
        if ((int)$s['user_id']===(int)$u['id']) throw new Problem('Нельзя согласовать собственный таймшит.',403);
        if ($s['status']!=='submitted'||(int)$s['version']!==$version) throw new Problem('Таймшит уже изменён или рассмотрен.',409);
        if ($approve) workloadMember($db,$u,$project,(int)$s['user_id']);
        query($db,'UPDATE timesheets SET status=?,version=version+1,decided_at=?,decided_by=?,decision_note=? WHERE id=?',[$approve?'approved':'rejected',time(),$u['id'],$note,$id]); return $id;
    });
}

function workloadPage(PDO $db,array $user,int $project,string $week,?int $selected): array {
    $u=organizationUser($db,$user); $p=allowedProject($db,$u,$project); weekStart($week);
    $role=projectRole($db,$u,$project); $manage=roleAllows($role,'manage'); $work=roleAllows($role,'work'); $finance=roleAllows($role,'finance');
    if (!$work&&!$finance) throw new Problem('Нет доступа к учёту времени.',403);
    $sprints=query($db,'SELECT * FROM sprints WHERE project_id=? ORDER BY starts_on DESC,id DESC LIMIT 100',[$project])->fetchAll();
    $sprint=$selected!==null?entity($db,'SELECT * FROM sprints WHERE project_id=? AND id=?',[$project,$selected]):($sprints[0]??null);
    $sprintTasks=$sprint?query($db,"SELECT st.*,t.title,t.state,t.assignee_id,u.name assignee FROM sprint_tasks st JOIN tasks t ON t.id=st.task_id LEFT JOIN users u ON u.id=t.assignee_id WHERE st.project_id=? AND st.sprint_id=? ORDER BY t.priority,t.id",[$project,$sprint['id']])->fetchAll():[];
    $people=[];
    if ($work) {
        foreach (planningPeople($db,$u,$project) as $uid=>$name) if (roleAllows(projectRole($db,['id'=>$uid,'organization_id'=>$u['organization_id']],$project),'work')&&($manage||(int)$uid===(int)$u['id'])) $people[$uid]=$name;
    }
    $availability=[];
    if ($people) foreach (query($db,'SELECT a.*,c.team_id FROM project_availability a LEFT JOIN capacity_teams c ON c.project_id=a.project_id AND c.user_id=a.user_id AND c.week_on=a.week_on WHERE a.project_id=? AND a.week_on=?',[$project,$week])->fetchAll() as $r) if (isset($people[$r['user_id']])) $availability[$r['user_id']]=$r;
    // Managers review all sheets; financial readers see only approved totals; workers see their own.
    $params=[$project,$week]; $where='s.project_id=? AND s.week_on=?';
    if (!$manage) { if ($work) { $where.=' AND (s.user_id=?'.($finance?" OR s.status='approved'":'').')'; $params[]=$u['id']; } else $where.=" AND s.status='approved'"; }
    $costVisible=$finance&&mfaFresh();
    $cols='s.id,s.project_id,s.user_id,s.week_on,s.status,s.version,s.total_minutes,s.submitted_at,s.decided_at,s.decided_by';
    $cols.= $manage ? ',s.decision_note' : ($work ? ',s.decision_note' : ",'' AS decision_note");
    if ($costVisible) $cols.=',s.rate_mode,s.rate_currency,s.rate_minor,s.rate_version,s.cost_minor';
    $sheets=query($db,"SELECT $cols,u.name,COALESCE((SELECT SUM(e.minutes) FROM time_entries e WHERE e.sheet_id=s.id),0) actual_minutes FROM timesheets s JOIN users u ON u.id=s.user_id WHERE $where ORDER BY u.name,s.id",$params)->fetchAll();
    $entries=[]; $own=null; $approvedMinutes=0; $costs=[];
    foreach ($sheets as $sheet) {
        if ((int)$sheet['user_id']===(int)$u['id']) $own=$sheet;
        if ($sheet['status']==='approved') { $approvedMinutes+=(int)$sheet['total_minutes']; if ($costVisible&&$sheet['cost_minor']!==null) { $c=$sheet['rate_currency']; $costs[$c]=($costs[$c]??0)+(int)$sheet['cost_minor']; } }
        if ($manage||($work&&(int)$sheet['user_id']===(int)$u['id'])) $entries[$sheet['id']]=query($db,'SELECT e.*,t.title FROM time_entries e JOIN tasks t ON t.id=e.task_id WHERE e.sheet_id=? ORDER BY e.worked_on,e.id',[$sheet['id']])->fetchAll();
    }
    $tasks=[];
    if ($work) $tasks=query($db,'SELECT id,title FROM tasks WHERE project_id=?'.($manage?'':' AND assignee_id=?').' ORDER BY id DESC LIMIT 200',$manage?[$project]:[$project,$u['id']])->fetchAll();
    $rate=$costVisible?query($db,'SELECT * FROM project_rates WHERE project_id=?',[$project])->fetch():null;
    return compact('project','week','p','role','manage','work','finance','costVisible','sprints','sprint','sprintTasks','people','availability','sheets','entries','own','approvedMinutes','costs','tasks','rate');
}
