<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

function organizationsFor(PDO $db,int $uid): array {
    return query($db,'SELECT o.id,o.name,m.role,u.session_version FROM organizations o JOIN organization_members m ON m.organization_id=o.id JOIN users u ON u.id=m.user_id WHERE m.user_id=? AND u.enabled=1 ORDER BY o.id',[$uid])->fetchAll();
}
function organizationUser(PDO $db,array $user,?int $org=null,bool $enforceAuth=true): array {
    $org ??= isset($user['organization_id'])?(int)$user['organization_id']:null;
    $rows=organizationsFor($db,(int)$user['id']);
    foreach ($rows as $r) if ($org===null || (int)$r['id']===$org) {
        if (isset($user['session_version']) && (int)$user['session_version']!==(int)$r['session_version']) throw new Problem('Сессия отозвана.',403);
        if ($enforceAuth && isset($user['auth_kind'])) requireOrganizationAuth($db,(int)$r['id'],$user['auth_kind'],(int)$user['id']);
        return array_replace($user,['organization_id'=>(int)$r['id'],'organization_name'=>$r['name'],'role'=>$r['role']]);
    }
    throw new Problem('Нет доступа к организации.',403);
}
// Owners govern their own organization. Every other role needs explicit project membership.
function projectScope(PDO $db,array $user,string $alias='p'): array {
    $u=organizationUser($db,$user);
    $sql="$alias.organization_id=?"; $args=[$u['organization_id']];
    if ($u['role']!=='owner') { $sql.=" AND EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id=$alias.id AND pm.organization_id=$alias.organization_id AND pm.user_id=?)"; $args[]=$u['id']; }
    return [$sql,$args];
}
function allowedProject(PDO $db,array $user,int $id): array {
    [$scope,$args]=projectScope($db,$user);
    return entity($db,"SELECT p.* FROM projects p WHERE p.id=? AND $scope",[$id,...$args]);
}
function orgOwner(PDO $db,array $user): array {
    $u=organizationUser($db,$user);
    if ($u['role']!=='owner') throw new Problem('Требуются права владельца организации.',403);
    return $u;
}
function createOrganization(PDO $db,array $user,string $name): int {
    $name=word($name,120,'Организация');
    return atomic($db,function() use($db,$user,$name) {
        // Only a current owner may provision a new organization.
        orgOwner($db,$user);
        // Freeze the legacy installation's current catalog before opening a second tenant.
        if ((int)$db->query('SELECT COUNT(*) FROM organizations')->fetchColumn()===1) {
            foreach (array_keys(connections()) as $key) query($db,'INSERT INTO organization_connections VALUES(?,1) ON CONFLICT DO NOTHING',[$key]);
        }
        $id=insertId($db,'INSERT INTO organizations(name) VALUES(?)',[$name]);
        query($db,'INSERT INTO organization_members VALUES(?,?,?)',[$id,$user['id'],'owner']);
        auditEvent($db,['organization_id'=>$id,'id'=>$user['id']],null,'org.create',$id);
        return $id;
    });
}
function renameOrganization(PDO $db,array $user,string $name): void {
    $name=word($name,120,'Организация');
    atomic($db,function() use($db,$user,$name) {
        $u=orgOwner($db,$user);
        query($db,'UPDATE organizations SET name=? WHERE id=?',[$name,$u['organization_id']]);
        auditEvent($db,$u,null,'org.rename',$u['organization_id']);
    });
}
function editTeam(PDO $db,array $user,int $teamId,?string $name): void {
    if ($name!==null) $name=word($name,100,'Команда');
    atomic($db,function() use($db,$user,$teamId,$name) {
        $u=orgOwner($db,$user); $org=$u['organization_id'];
        entity($db,'SELECT id FROM teams WHERE id=? AND organization_id=?',[$teamId,$org]);
        if ($name===null) {
            query($db,'DELETE FROM team_members WHERE team_id=? AND organization_id=?',[$teamId,$org]);
            query($db,'DELETE FROM teams WHERE id=? AND organization_id=?',[$teamId,$org]);
        } else {
            if (query($db,'SELECT 1 FROM teams WHERE organization_id=? AND name=? AND id<>?',[$org,$name,$teamId])->fetchColumn()) throw new Problem('Команда уже существует.',409);
            query($db,'UPDATE teams SET name=? WHERE id=? AND organization_id=?',[$name,$teamId,$org]);
        }
        auditEvent($db,$u,null,$name===null?'team.delete':'team.rename',$teamId);
    });
}
function orgMembers(PDO $db,array $user): array {
    $u=organizationUser($db,$user);
    return query($db,'SELECT u.id,u.name,u.email,u.enabled,m.role FROM users u JOIN organization_members m ON m.user_id=u.id WHERE m.organization_id=? ORDER BY u.enabled DESC,u.name,u.id',[$u['organization_id']])->fetchAll();
}
function createTeam(PDO $db,array $user,string $name): void {
    $name=word($name,100,'Команда');
    atomic($db,function() use($db,$user,$name) {
        $u=orgOwner($db,$user);
        if (query($db,'SELECT 1 FROM teams WHERE organization_id=? AND name=?',[$u['organization_id'],$name])->fetchColumn()) throw new Problem('Команда уже существует.',409);
        $id=insertId($db,'INSERT INTO teams(organization_id,name) VALUES(?,?)',[$u['organization_id'],$name]);
        auditEvent($db,$u,null,'team.create',$id);
    });
}
function changeMembership(PDO $db,array $user,string $type,int $object,int $uid,bool $add): void {
    atomic($db,function() use($db,$user,$type,$object,$uid,$add) {
        $u=organizationUser($db,$user); $org=$u['organization_id'];
        if ($type==='team') { orgOwner($db,$u); entity($db,'SELECT id FROM teams WHERE id=? AND organization_id=?',[$object,$org]); $table='team_members'; $col='team_id'; }
        elseif ($type==='project') { permit($u,'manage'); requireProjectRight($db,$u,$object,'manage'); $table='project_members'; $col='project_id'; if ($u['role']!=='owner' && query($db,'SELECT 1 FROM project_access WHERE project_id=? AND user_id=?',[$object,$uid])->fetchColumn()) throw new Problem('Явные проектные роли изменяет только владелец.',403); }
        else throw new Problem('Неизвестный тип членства.');
        entity($db,'SELECT m.user_id FROM organization_members m JOIN users a ON a.id=m.user_id WHERE m.organization_id=? AND m.user_id=?'.($add?' AND a.enabled=1':''),[$org,$uid]);
        if (!$add && $type==='project' && $uid===(int)$u['id'] && $u['role']!=='owner') throw new Problem('Нельзя удалить собственный доступ.');
        if ($add) $changed=query($db,"INSERT INTO $table(organization_id,$col,user_id) VALUES(?,?,?) ON CONFLICT DO NOTHING",[$org,$object,$uid])->rowCount();
        else {
             $changed=query($db,"DELETE FROM $table WHERE organization_id=? AND $col=? AND user_id=?",[$org,$object,$uid])->rowCount();
            if ($type==='project') query($db,'UPDATE tasks SET assignee_id=NULL,version=version+1 WHERE project_id=? AND assignee_id=? AND NOT EXISTS(SELECT 1 FROM organization_members WHERE organization_id=? AND user_id=? AND role=?)',[$object,$uid,$org,$uid,'owner']);
        }
        if ($changed && !$add && $type==='project') cancelPendingByCreator($db,$u,$uid,$object);
        if ($changed) auditEvent($db,$u,$type==='project'?$object:null,$type.($add?'.add':'.remove'),$uid,$type==='team'?'team #'.$object:'');
    });
}
function removeOrganizationMember(PDO $db,array $user,int $uid): void {
    atomic($db,function() use($db,$user,$uid) {
        $u=orgOwner($db,$user); $org=$u['organization_id'];
        $member=entity($db,'SELECT role FROM organization_members WHERE organization_id=? AND user_id=?',[$org,$uid]);
        if ($uid===(int)$u['id']) throw new Problem('Нельзя исключить собственную учётную запись.',409);
        if ($member['role']==='owner' && !query($db,"SELECT 1 FROM organization_members m JOIN users a ON a.id=m.user_id WHERE m.organization_id=? AND m.role='owner' AND a.enabled=1 AND m.user_id<>?",[$org,$uid])->fetchColumn()) throw new Problem('В организации должен остаться активный владелец.',409);
        query($db,'UPDATE tasks SET assignee_id=NULL,version=version+1 WHERE assignee_id=? AND project_id IN (SELECT id FROM projects WHERE organization_id=?)',[$uid,$org]);
        foreach (query($db,'SELECT project_id FROM project_members WHERE organization_id=? AND user_id=?',[$org,$uid])->fetchAll(PDO::FETCH_COLUMN) as $pid) auditEvent($db,$u,(int)$pid,'project.remove',$uid);
        cancelPendingByCreator($db,$u,$uid);
        foreach (['team_members','project_members','organization_members'] as $table) query($db,"DELETE FROM $table WHERE organization_id=? AND user_id=?",[$org,$uid]);
        query($db,'UPDATE users SET session_version=session_version+1 WHERE id=?',[$uid]);
        auditEvent($db,$u,null,'org.remove',$uid);
    });
}
function issueInvitation(PDO $db,array $user,string $email,string $role,int $hours): string {
    $email=normalizedEmail($email); choice($role,ROLES);
    if ($hours<1 || $hours>168) throw new Problem('Срок приглашения: 1–168 часов.');
    return atomic($db,function() use($db,$user,$email,$role,$hours) {
        $u=orgOwner($db,$user); $org=$u['organization_id'];
        if (query($db,'SELECT 1 FROM organization_members m JOIN users u ON u.id=m.user_id WHERE m.organization_id=? AND LOWER(u.email)=?',[$org,$email])->fetchColumn()) throw new Problem('Участник уже состоит в организации.',409);
        query($db,'UPDATE invitations SET revoked_at=? WHERE organization_id=? AND email=? AND accepted_at IS NULL AND revoked_at IS NULL',[time(),$org,$email]);
        $token=bin2hex(random_bytes(32));
        $id=insertId($db,'INSERT INTO invitations(organization_id,email,role,token_hash,expires_at,created_by) VALUES(?,?,?,?,?,?)',[$org,$email,$role,hash('sha256',$token),time()+$hours*3600,$u['id']]);
        auditEvent($db,$u,null,'invite.issue',$id,$role);
        return $token;
    });
}
function revokeInvitation(PDO $db,array $user,int $id): void {
    atomic($db,function() use($db,$user,$id) {
        $u=orgOwner($db,$user);
        if (!query($db,'UPDATE invitations SET revoked_at=? WHERE id=? AND organization_id=? AND revoked_at IS NULL AND accepted_at IS NULL',[time(),$id,$u['organization_id']])->rowCount()) throw new Problem('Активное приглашение не найдено.',404);
        auditEvent($db,$u,null,'invite.revoke',$id);
    });
}
function acceptInvitation(PDO $db,string $token,?array $user,string $name='',string $password=''): array {
    if (!preg_match('/\A[a-f0-9]{64}\z/',$token)) throw new Problem('Недействительное приглашение.',404);
    return atomic($db,function() use($db,$token,$user,$name,$password) {
        $inv=entity($db,'SELECT * FROM invitations WHERE token_hash=? AND expires_at>? AND accepted_at IS NULL AND revoked_at IS NULL',[hash('sha256',$token),time()]);
        requireOrganizationAuth($db,(int)$inv['organization_id'],$user['auth_kind']??'local',(int)($user['id']??0));
        $existing=query($db,'SELECT id,email,enabled,session_version FROM users WHERE LOWER(email)=?',[$inv['email']])->fetch();
        if ($existing) {
            if (!$user || (int)$existing['id']!==(int)$user['id'] || !(int)$existing['enabled'] || (isset($user['session_version']) && (int)$user['session_version']!==(int)$existing['session_version'])) throw new Problem('Войдите в приглашённую учётную запись.',403);
            $uid=(int)$existing['id'];
        } else {
            if ($user) throw new Problem('Выйдите, чтобы зарегистрировать приглашённую учётную запись.',403);
            $uid=insertId($db,'INSERT INTO users(email,name,role,password_hash) VALUES(?,?,?,?)',[$inv['email'],word($name,90,'Имя'),'viewer',passwordHash($password)]);
        }
        if (query($db,'SELECT 1 FROM organization_members WHERE organization_id=? AND user_id=?',[$inv['organization_id'],$uid])->fetchColumn()) throw new Problem('Участник уже зарегистрирован.',409);
        query($db,'INSERT INTO organization_members VALUES(?,?,?)',[$inv['organization_id'],$uid,$inv['role']]);
        query($db,'UPDATE invitations SET accepted_at=?,accepted_by=? WHERE id=?',[time(),$uid,$inv['id']]);
        auditEvent($db,['organization_id'=>$inv['organization_id'],'id'=>$uid],null,'invite.accept',(int)$inv['id']);
        return ['id'=>$uid,'organization_id'=>(int)$inv['organization_id']];
    });
}
// The original installation owns unassigned configuration keys; other organizations need an explicit grant.
function connectionAllowed(PDO $db,array $user,string $key): bool {
    $u=organizationUser($db,$user);
    $org=query($db,'SELECT organization_id FROM organization_connections WHERE connection_key=?',[$key])->fetchColumn();
    if ($org===false) return $u['organization_id']===1 && (int)$db->query('SELECT COUNT(*) FROM organizations')->fetchColumn()===1;
    return (int)$org===$u['organization_id'];
}
function scopedConnections(PDO $db,array $user,?int $projectId=null): array {
    $u=organizationUser($db,$user);
    if ($projectId!==null) requireProjectRight($db,$u,$projectId,'connections');
    elseif (!hasProjectRight($db,$u,'connections') && !can($u,'connections')) throw new Problem('Нет доступа к подключениям.',403);
    $items=connectionViews($db);
    $keys=[];
    $catalog=$u['role']==='owner';
    if ($projectId!==null && !$catalog) {
        allowedProject($db,$u,$projectId);
        $keys=query($db,'SELECT connection_key FROM project_connections WHERE project_id=?',[$projectId])->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($projectId===null && $u['role']!=='owner') {
        [$scope,$args]=projectRightScope($db,$u,'connections');
        $keys=query($db,"SELECT DISTINCT c.connection_key FROM project_connections c JOIN projects p ON p.id=c.project_id WHERE $scope",$args)->fetchAll(PDO::FETCH_COLUMN);
    }
    return array_filter($items,fn($c,$key)=>connectionAllowed($db,$u,$key) && ($catalog || in_array($key,$keys,true)),ARRAY_FILTER_USE_BOTH);
}
