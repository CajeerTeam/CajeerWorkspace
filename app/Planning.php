<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const PRIORITIES = ['1'=>'Критический','2'=>'Высокий','3'=>'Обычный','4'=>'Низкий'];
const MILESTONE_STATES = ['open'=>'Открыта','closed'=>'Закрыта'];

function criteriaText(mixed $value): string {
    if (!is_string($value)) throw new Problem('Укажите критерии приёмки.');
    $value=str_replace(["\r\n","\r"],"\n",$value);
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$value)!==0) throw new Problem('Некорректные символы в критериях приёмки.');
    $value=trim($value);
    $length=preg_match_all('/./us',$value);
    if (!$length || $length>4000) throw new Problem('Критерии приёмки: от 1 до 4000 символов.');
    return $value;
}
function optionalId(mixed $value): ?int { return $value==='' || $value===null ? null : numberId($value); }
function taskPlanData(array $form): array {
    return [word($form['title']??'',180,'Задача'),optionalId($form['assignee']??''),calendarDate($form['due_on']??'',true),
        (int)choice($form['priority']??'3',PRIORITIES),word($form['result_customer']??'',180,'Заказчик результата'),criteriaText($form['acceptance_criteria']??''),optionalId($form['milestone_id']??'')];
}
function checkTaskAssignee(PDO $db,array $user,int $project,?int $assignee): void {
    if ($assignee!==null) {
        $candidate=organizationUser($db,['id'=>$assignee],$user['organization_id']);
        requireProjectRight($db,$candidate,$project,'work');
    }
}
function taskMilestone(PDO $db,int $project,int $task): ?int {
    $id=query($db,'SELECT milestone_id FROM task_milestones WHERE project_id=? AND task_id=?',[$project,$task])->fetchColumn();
    return $id===false?null:(int)$id;
}
function requireOpenMilestone(PDO $db,int $project,?int $milestone): void {
    if ($milestone===null) return;
    $m=entity($db,'SELECT state FROM milestones WHERE project_id=? AND id=?',[$project,$milestone]);
    if ($m['state']!=='open') throw new Problem('Сначала откройте закрытую веху.',409);
}
function setTaskMilestone(PDO $db,int $project,int $task,?int $milestone): void {
    if ($milestone===null) query($db,'DELETE FROM task_milestones WHERE project_id=? AND task_id=?',[$project,$task]);
    else query($db,'INSERT INTO task_milestones(task_id,project_id,milestone_id) VALUES(?,?,?) ON CONFLICT(task_id) DO UPDATE SET milestone_id=excluded.milestone_id',[$task,$project,$milestone]);
}
function planningTask(PDO $db,array $user,int $task): array {
    [$scope,$args]=projectScope($db,$user);
    return entity($db,"SELECT t.*,p.title project_title,u.name assignee,tm.milestone_id,m.title milestone_title,m.state milestone_state FROM tasks t JOIN projects p ON p.id=t.project_id LEFT JOIN users u ON u.id=t.assignee_id LEFT JOIN task_milestones tm ON tm.task_id=t.id LEFT JOIN milestones m ON m.id=tm.milestone_id WHERE t.id=? AND $scope",[$task,...$args]);
}
function taskVersion(array $task,int $version): void {
    if ((int)$task['version']!==$version) throw new Problem('Задача уже изменилась. Обновите страницу.',409);
}
function editTask(PDO $db,array $user,string $key,int $taskId,int $version,array $form): int {
    $user=organizationUser($db,$user); $task=planningTask($db,$user,$taskId); $project=(int)$task['project_id'];
    requireProjectRight($db,$user,$project,'manage'); $data=taskPlanData($form);
    return action($db,$user,$key,'task.edit',[$taskId,$version,...$data],function() use($db,$user,$taskId,$version,$data,$project) {
        requireProjectRight($db,$user,$project,'manage');
        $task=planningTask($db,$user,$taskId); taskVersion($task,$version);
        [$title,$assignee,$due,$priority,$customer,$criteria,$milestone]=$data;
        requireOpenMilestone($db,$project,taskMilestone($db,$project,$taskId));
        if ($task['state']==='done' && (($task['result_customer']!=='' && $task['result_customer']!==$customer) || ($task['acceptance_criteria']!=='' && $task['acceptance_criteria']!==$criteria))) throw new Problem('Откройте завершённую задачу перед изменением согласованных условий.',409);
        requireOpenMilestone($db,$project,$milestone); checkTaskAssignee($db,$user,$project,$assignee);
        query($db,'UPDATE tasks SET title=?,assignee_id=?,due_on=?,priority=?,result_customer=?,acceptance_criteria=?,version=version+1 WHERE id=?',[$title,$assignee,$due,$priority,$customer,$criteria,$taskId]);
        setTaskMilestone($db,$project,$taskId,$milestone); return $taskId;
    });
}
function milestoneData(array $form): array {
    return [word($form['title']??'',180,'Веха'),calendarDate($form['due_on']??'',true)];
}
function saveMilestone(PDO $db,array $user,string $key,int $project,?int $id,?int $version,array $form): int {
    $user=organizationUser($db,$user); requireProjectRight($db,$user,$project,'manage'); $data=milestoneData($form);
    return action($db,$user,$key,$id===null?'milestone.create':'milestone.edit',[$project,$id,$version,...$data],function() use($db,$user,$project,$id,$version,$data) {
        requireProjectRight($db,$user,$project,'manage');
        if ($id===null) return insertId($db,'INSERT INTO milestones(project_id,title,due_on) VALUES(?,?,?)',[$project,...$data]);
        $m=entity($db,'SELECT * FROM milestones WHERE project_id=? AND id=?',[$project,$id]);
        if ((int)$m['version']!==$version || $m['state']!=='open') throw new Problem('Веха закрыта или изменена. Обновите страницу.',409);
        query($db,'UPDATE milestones SET title=?,due_on=?,version=version+1 WHERE id=?',[...$data,$id]); pmValidateGraph(pmCalendar($db,$project)); return $id;
    });
}
function changeMilestone(PDO $db,array $user,string $key,int $project,int $id,int $version,string $state): int {
    $user=organizationUser($db,$user); requireProjectRight($db,$user,$project,'manage'); choice($state,MILESTONE_STATES);
    return action($db,$user,$key,'milestone.state',[$project,$id,$version,$state],function() use($db,$user,$project,$id,$version,$state) {
        requireProjectRight($db,$user,$project,'manage');
        $m=entity($db,'SELECT * FROM milestones WHERE project_id=? AND id=?',[$project,$id]);
        if ((int)$m['version']!==$version) throw new Problem('Веха уже изменилась.',409);
        if ($m['state']===$state) return $id;
        if ($state==='closed') {
            $counts=query($db,"SELECT COUNT(*) total,COUNT(CASE WHEN t.state='done' AND t.result_customer<>'' AND t.acceptance_criteria<>'' THEN 1 END) done FROM task_milestones tm JOIN tasks t ON t.id=tm.task_id WHERE tm.project_id=? AND tm.milestone_id=?",[$project,$id])->fetch();
            if (!(int)$counts['total'] || (int)$counts['total']!==(int)$counts['done']) throw new Problem('Для закрытия вехи нужны завершённые задачи с заказчиком и критериями приёмки.',409);
        }
        query($db,'UPDATE milestones SET state=?,version=version+1 WHERE id=?',[$state,$id]); pmValidateGraph(pmCalendar($db,$project)); return $id;
    });
}
function blockedTask(PDO $db,int $project,int $task): bool {
    return (bool)query($db,"SELECT 1 FROM task_dependencies d JOIN tasks t ON t.id=d.prerequisite_id WHERE d.project_id=? AND d.task_id=? AND t.state<>'done' LIMIT 1",[$project,$task])->fetchColumn();
}
function changeDependency(PDO $db,array $user,string $key,int $taskId,int $version,int $prerequisite,bool $add): int {
    $user=organizationUser($db,$user); $task=planningTask($db,$user,$taskId); $project=(int)$task['project_id']; requireProjectRight($db,$user,$project,'manage');
    return action($db,$user,$key,$add?'task.dependency.add':'task.dependency.remove',[$taskId,$version,$prerequisite],function() use($db,$user,$project,$taskId,$version,$prerequisite,$add) {
        requireProjectRight($db,$user,$project,'manage'); $task=planningTask($db,$user,$taskId); taskVersion($task,$version);
        requireOpenMilestone($db,$project,taskMilestone($db,$project,$taskId));
        $pre=entity($db,'SELECT id,state FROM tasks WHERE project_id=? AND id=?',[$project,$prerequisite]);
        if ($add) {
            if ($taskId===$prerequisite || query($db,'WITH RECURSIVE chain(id) AS (SELECT prerequisite_id FROM task_dependencies WHERE project_id=? AND task_id=? UNION SELECT d.prerequisite_id FROM task_dependencies d JOIN chain c ON c.id=d.task_id WHERE d.project_id=?) SELECT 1 FROM chain WHERE id=? LIMIT 1',[$project,$prerequisite,$project,$taskId])->fetchColumn()) throw new Problem('Циклическая зависимость недопустима.',409);
            if ($task['state']!=='todo' && $pre['state']!=='done') throw new Problem('Верните задачу в очередь перед добавлением незавершённой зависимости.',409);
            $changed=query($db,'INSERT INTO task_dependencies(project_id,task_id,prerequisite_id) VALUES(?,?,?) ON CONFLICT DO NOTHING',[$project,$taskId,$prerequisite])->rowCount();
        } else $changed=query($db,'DELETE FROM task_dependencies WHERE project_id=? AND task_id=? AND prerequisite_id=?',[$project,$taskId,$prerequisite])->rowCount();
        if ($changed) { query($db,'UPDATE tasks SET version=version+1 WHERE id=?',[$taskId]); leadWaitSync($db,$project,$taskId,$prerequisite); }
        return $taskId;
    });
}
function checkTaskTransition(PDO $db,array $task,string $state): void {
    $project=(int)$task['project_id']; $id=(int)$task['id'];
    requireOpenMilestone($db,$project,taskMilestone($db,$project,$id));
    if ($state!=='todo' && blockedTask($db,$project,$id)) throw new Problem('Задача заблокирована незавершёнными зависимостями.',409);
    if ($state==='done' && ($task['result_customer']==='' || $task['acceptance_criteria']==='')) throw new Problem('Укажите заказчика результата и критерии приёмки перед завершением.',409);
    if ($task['state']==='done' && $state!=='done' && query($db,"SELECT 1 FROM task_dependencies d JOIN tasks t ON t.id=d.task_id WHERE d.project_id=? AND d.prerequisite_id=? AND t.state<>'todo' LIMIT 1",[$project,$id])->fetchColumn()) throw new Problem('Сначала верните зависимые задачи в очередь.',409);
}
function planningMilestones(PDO $db,int $project,?array $ids=null,?int $limit=null): array {
    if($ids===[])return [];
    if($limit!==null&&($limit<1||$limit>100))throw new \LogicException('Invalid milestone limit');
    $where='m.project_id=?';$args=[$project];
    if($ids!==null){$where.=' AND m.id IN ('.implode(',',array_fill(0,count($ids),'?')).')';$args=[...$args,...$ids];}
    return query($db,"SELECT m.*,COUNT(tm.task_id) total,COUNT(CASE WHEN t.state='done' THEN 1 END) done FROM milestones m LEFT JOIN task_milestones tm ON tm.milestone_id=m.id LEFT JOIN tasks t ON t.id=tm.task_id WHERE $where GROUP BY m.id ORDER BY m.state='closed',m.due_on IS NULL,m.due_on,m.id".($limit===null?'':' LIMIT '.$limit),$args)->fetchAll();
}
function planningPeople(PDO $db,array $user,int $project): array {
    $u=organizationUser($db,$user); allowedProject($db,$u,$project);
    return array_column(query($db,"SELECT u.id,u.name FROM users u JOIN organization_members om ON om.user_id=u.id WHERE om.organization_id=? AND u.enabled=1 AND (om.role='owner' OR EXISTS(SELECT 1 FROM project_members pm WHERE pm.project_id=? AND pm.user_id=u.id)) ORDER BY u.name,u.id",[$u['organization_id'],$project])->fetchAll(),'name','id');
}
function planningFold(string $value): string {
    static $map=null;
    $map??=array_combine(preg_split('//u','АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ',-1,PREG_SPLIT_NO_EMPTY),preg_split('//u','абвгдеёжзийклмнопрстуфхцчшщъыьэюя',-1,PREG_SPLIT_NO_EMPTY));
    return strtolower(strtr($value,$map));
}
function planningLower(PDO $db,string $column): string {
    if (isPostgres($db)) return "LOWER(translate($column,'АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ','абвгдеёжзийклмнопрстуфхцчшщъыьэюя'))";
    return "planning_fold($column)";
}
function planningLike(string $term): string { return '%'.strtr(planningFold($term),['!'=>'!!','%'=>'!%','_'=>'!_']).'%'; }
function planningSearchSql(PDO $db,array $columns): string {
    if (!isPostgres($db)) {
        $callback=__NAMESPACE__.'\\planningFold';
        if (method_exists($db,'createFunction')) $db->createFunction('planning_fold',$callback,1);
        else $db->sqliteCreateFunction('planning_fold',$callback,1);
    }
    return '('.implode(' OR ',array_map(fn($col)=>planningLower($db,$col)." LIKE ? ESCAPE '!'",$columns)).')';
}
function planningFilters(array $input): array {
    return ['q'=>word($input['q']??'',120,'Поиск',false),'state'=>choice($input['state']??'',[''=>'Все']+STATES),
        'priority'=>choice($input['priority']??'',[''=>'Все']+PRIORITIES),'milestone'=>($input['milestone']??'')==='none'?'none':(optionalId($input['milestone']??'')??''),
        'assignee'=>($input['assignee']??'')==='none'?'none':(optionalId($input['assignee']??'')??''),
        'blocked'=>choice($input['blocked']??'',[''=>'Все','yes'=>'Заблокированы','no'=>'Без блокировок','late'=>'Просроченные предшественники']),
        'overdue'=>choice($input['overdue']??'',[''=>'Все','yes'=>'Просрочены']),
        'view'=>choice($input['view']??'board',['board'=>'Доска','backlog'=>'Backlog'])];
}
function planningBlockedSql(bool $late=false): string {
    return "EXISTS(SELECT 1 FROM task_dependencies d JOIN tasks pre ON pre.id=d.prerequisite_id WHERE d.project_id=t.project_id AND d.task_id=t.id AND pre.state<>'done'".($late?" AND pre.due_on<'".gmdate('Y-m-d')."'":'').')';
}
function planningRows(PDO $db,array $user,int $project,array $filters,int $page=1): array {
    allowedProject($db,$user,$project);
    if ($page<1 || $page>100000) throw new Problem('Некорректная страница.');
    $where=['t.project_id=?']; $args=[$project];
    if ($filters['q']!=='') { $where[]=planningSearchSql($db,['t.title','t.result_customer','t.acceptance_criteria']); array_push($args,...array_fill(0,3,planningLike($filters['q']))); }
    foreach (['state'=>'t.state','priority'=>'t.priority','milestone'=>'tm.milestone_id','assignee'=>'t.assignee_id'] as $key=>$col) {
        $value=$filters[$key]; if ($value==='') continue;
        if ($value==='none') $where[]="$col IS NULL"; else { $where[]="$col=?"; $args[]=$value; }
    }
    $blocked=planningBlockedSql(); $late=planningBlockedSql(true);
    if ($filters['blocked']!=='') $where[]=$filters['blocked']==='late'?$late:(($filters['blocked']==='no'?'NOT ':'').$blocked);
    if ($filters['overdue']==='yes') { $where[]="t.state<>'done' AND t.due_on<?"; $args[]=gmdate('Y-m-d'); }
    $where=implode(' AND ',$where);
    $sql="SELECT t.*,u.name assignee,tm.milestone_id,m.title milestone_title,m.state milestone_state,CASE WHEN $blocked THEN 1 ELSE 0 END blocked,CASE WHEN $late THEN 1 ELSE 0 END late_blocker FROM tasks t LEFT JOIN users u ON u.id=t.assignee_id LEFT JOIN task_milestones tm ON tm.task_id=t.id LEFT JOIN milestones m ON m.id=tm.milestone_id WHERE $where";
    $order=' ORDER BY t.priority,t.due_on IS NULL,t.due_on,t.id';
    $out=[]; $lanes=$filters['view']==='board'?array_keys(STATES):['backlog'];
    foreach ($lanes as $lane) {
        $extra=$lane==='backlog'?($filters['state']===''?" AND t.state<>'done'":''):' AND t.state=?';
        $params=$lane==='backlog'?$args:[...$args,$lane];
        $rows=query($db,$sql.$extra.$order.' LIMIT 51 OFFSET '.(($page-1)*50),$params)->fetchAll();
        $out[$lane]=['rows'=>array_slice($rows,0,50),'more'=>count($rows)>50];
    }
    return $out;
}
function planningDependencies(PDO $db,array $user,int $taskId): array {
    $task=planningTask($db,$user,$taskId); $project=(int)$task['project_id'];
    $result=[];
    foreach (['requires'=>['task_id','prerequisite_id'],'dependents'=>['prerequisite_id','task_id']] as $key=>[$from,$to]) {
        $result[$key]=query($db,"SELECT t.id,t.title,t.state,t.due_on FROM task_dependencies d JOIN tasks t ON t.id=d.$to WHERE d.project_id=? AND d.$from=? ORDER BY t.id",[$project,$taskId])->fetchAll();
    }
    return $result;
}
