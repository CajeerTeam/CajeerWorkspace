<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const PROJECT_ROLES = ['inherit'=>'Роль организации','viewer'=>'Наблюдатель','manager'=>'Менеджер проекта','developer'=>'Исполнитель','accountant'=>'Бухгалтер','finance_reader'=>'Читатель финансов','payment_creator'=>'Создатель платежей','payment_approver'=>'Согласующий платежей','legal'=>'Юрист','legal_reader'=>'Читатель договоров','integration_reader'=>'Читатель интеграций','operator'=>'Оператор интеграций','auditor'=>'Аудитор'];
const PROJECT_RIGHTS = [
    'manage'=>['manager'], 'work'=>['manager','developer'],
    'finance'=>['accountant','finance_reader','payment_creator','payment_approver'],
    'payment.create'=>['accountant','payment_creator'], 'payment.approve'=>['accountant','payment_approver'],
    'legal'=>['legal','legal_reader'], 'legal.write'=>['legal'],
    'connections'=>['manager','developer','integration_reader','operator'], 'operations'=>['operator'],
    'audit'=>['auditor'],
];
function roleAllows(string $role,string $right): bool {
    return isset(PROJECT_RIGHTS[$right]) && ($role==='owner' || in_array($role,PROJECT_RIGHTS[$right],true));
}
function projectRole(PDO $db,array $user,int $project): string {
    $u=organizationUser($db,$user);
    allowedProject($db,$u,$project);
    if ($u['role']==='owner') return 'owner';
    $role=query($db,'SELECT role FROM project_access WHERE project_id=? AND user_id=?',[$project,$u['id']])->fetchColumn();
    return $role===false?$u['role']:$role;
}
function requireProjectRight(PDO $db,array $user,int $project,string $right): void {
    if (!roleAllows(projectRole($db,$user,$project),$right)) throw new Problem('Недостаточно полномочий в проекте.',403);
}
function projectRightScope(PDO $db,array $user,string $right,string $alias='p'): array {
    $u=organizationUser($db,$user);
    [$scope,$args]=projectScope($db,$u,$alias);
    if (!isset(PROJECT_RIGHTS[$right])) throw new \LogicException('Unknown project right');
    if ($u['role']==='owner') return [$scope,$args];
    $roles=PROJECT_RIGHTS[$right];
    $scope.=" AND COALESCE((SELECT pa.role FROM project_access pa WHERE pa.project_id=$alias.id AND pa.user_id=?),?) IN (".implode(',',array_fill(0,count($roles),'?')).')';
    return [$scope,[...$args,$u['id'],$u['role'],...$roles]];
}
function rightProjects(PDO $db,array $user,string $right): array {
    [$scope,$args]=projectRightScope($db,$user,$right);
    return array_column(query($db,"SELECT p.id,p.title FROM projects p WHERE $scope ORDER BY p.title,p.id",$args)->fetchAll(),'title','id');
}
function hasProjectRight(PDO $db,array $user,string $right): bool {
    $u=organizationUser($db,$user);
    return roleAllows($u['role'],$right) && $u['role']==='owner' || (bool)rightProjects($db,$u,$right);
}
function auditEvent(PDO $db,array $u,?int $project,string $event,int $object,string $detail=''): void {
    query($db,'INSERT INTO audit_events(organization_id,project_id,actor_id,event,object_id,detail) VALUES(?,?,?,?,?,?)',[$u['organization_id'],$project,$u['id'],$event,$object,$detail]);
}
function changeProjectRole(PDO $db,array $user,int $project,int $uid,string $role): void {
    choice($role,PROJECT_ROLES);
    atomic($db,function() use($db,$user,$project,$uid,$role) {
        $u=orgOwner($db,$user); allowedProject($db,$u,$project);
        $member=entity($db,'SELECT m.role FROM project_members p JOIN organization_members m ON m.organization_id=p.organization_id AND m.user_id=p.user_id WHERE p.project_id=? AND p.user_id=?',[$project,$uid]);
        if ($member['role']==='owner') throw new Problem('Полномочия владельца определяются организацией.',409);
        $previous=query($db,'SELECT role FROM project_access WHERE project_id=? AND user_id=?',[$project,$uid])->fetchColumn()?:'inherit';
        if ($previous===$role) return;
        if ($role==='inherit') query($db,'DELETE FROM project_access WHERE project_id=? AND user_id=?',[$project,$uid]);
        else query($db,'INSERT INTO project_access(project_id,user_id,role) VALUES(?,?,?) ON CONFLICT(project_id,user_id) DO UPDATE SET role=excluded.role',[$project,$uid,$role]);
        if (!roleAllows($role==='inherit'?$member['role']:$role,'payment.create')) cancelPendingByCreator($db,$u,$uid,$project);
        if (!roleAllows($role==='inherit'?$member['role']:$role,'work')) query($db,'UPDATE tasks SET assignee_id=NULL,version=version+1 WHERE project_id=? AND assignee_id=?',[$project,$uid]);
        auditEvent($db,$u,$project,'project.role',$uid,$previous.' -> '.$role);
    });
}
function dualControl(PDO $db,int $project): bool {
    return (int)query($db,'SELECT dual_control FROM payment_policies WHERE project_id=?',[$project])->fetchColumn()===1;
}
function changePaymentPolicy(PDO $db,array $user,int $project,bool $enabled): void {
    atomic($db,function() use($db,$user,$project,$enabled) {
        $u=orgOwner($db,$user); allowedProject($db,$u,$project);
        if (dualControl($db,$project)===$enabled) return;
        query($db,'INSERT INTO payment_policies(project_id,dual_control) VALUES(?,?) ON CONFLICT(project_id) DO UPDATE SET dual_control=excluded.dual_control',[$project,(int)$enabled]);
        auditEvent($db,$u,$project,'payment.policy',$project,$enabled?'dual':'direct');
    });
}
function auditRows(PDO $db,array $user,?int $project,?int $before=null): array {
    $u=organizationUser($db,$user);
    if ($project!==null) requireProjectRight($db,$u,$project,'audit');
    elseif (!hasProjectRight($db,$u,'audit')) throw new Problem('Нет доступа к аудиту.',403);
    [$scope,$args]=projectRightScope($db,$u,'audit');
    $where="a.organization_id=? AND (a.project_id IN (SELECT p.id FROM projects p WHERE $scope)".($u['role']==='owner'?' OR a.project_id IS NULL':'').')';
    $params=[$u['organization_id'],...$args];
    if ($project!==null) { $where.=' AND a.project_id=?'; $params[]=$project; }
    if ($before!==null) { $where.=' AND a.id<?'; $params[]=$before; }
    return query($db,"SELECT a.*,u.name actor_name,p.title project_title FROM audit_events a JOIN users u ON u.id=a.actor_id LEFT JOIN projects p ON p.id=a.project_id WHERE $where ORDER BY a.id DESC LIMIT 51",$params)->fetchAll();
}
function changeOrganizationRole(PDO $db,array $user,int $uid,string $role): void {
    choice($role,ROLES);
    atomic($db,function() use($db,$user,$uid,$role) {
        $u=orgOwner($db,$user); $org=$u['organization_id'];
        if ($uid===(int)$u['id']) throw new Problem('Нельзя изменить собственную роль владельца.',409);
        $old=entity($db,'SELECT role FROM organization_members WHERE organization_id=? AND user_id=?',[$org,$uid])['role'];
        if ($old===$role) return;
        // Explicit project grants remain independent, except owners always have full access.
        query($db,'UPDATE organization_members SET role=? WHERE organization_id=? AND user_id=?',[$role,$org,$uid]);
        if ($role!=='owner') {
            foreach (query($db,'SELECT id FROM projects WHERE organization_id=?',[$org])->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                $member=query($db,'SELECT 1 FROM project_members WHERE project_id=? AND user_id=?',[$pid,$uid])->fetchColumn();
                $r=query($db,'SELECT role FROM project_access WHERE project_id=? AND user_id=?',[$pid,$uid])->fetchColumn()?:$role;
                if (!$member || !roleAllows($r,'payment.create')) cancelPendingByCreator($db,$u,$uid,(int)$pid);
                if (!$member || !roleAllows($r,'work')) query($db,'UPDATE tasks SET assignee_id=NULL,version=version+1 WHERE project_id=? AND assignee_id=?',[$pid,$uid]);
            }
        }
        query($db,'UPDATE users SET session_version=session_version+1 WHERE id=?',[$uid]);
        auditEvent($db,$u,null,'org.role',$uid,$old.' -> '.$role);
    });
}
