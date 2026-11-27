<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

// A pilot is a deliberately isolated installation, not a demo mode in production.
const PILOT_STAFF = [
    'lead'=>['manager',['internal','oss']],
    'developer'=>['developer',['internal','oss']],
    'client_manager'=>['manager',['client']],
    'client_developer'=>['developer',['client']],
    'accountant'=>['accountant',['client']],
    'legal'=>['legal',['client']],
    'legal_reviewer'=>['legal',['client']],
    'operator'=>['viewer',['internal','oss']],
];
const PILOT_CONNECTIONS = [
    'engine'=>['internal','oss'], 'logs'=>['internal','oss'],
    'forum'=>['oss'], 'book'=>['oss'], 'lite'=>['oss'], 'bots'=>['oss'],
    'servers'=>['internal','oss'], 'aapanel'=>['internal','oss'], 'github'=>['internal','oss'],
];
function pilotCatalog(array $config): array {
    if (getenv('WORKSPACE_PILOT')!=='1'||($config['stand']['environment']??null)!=='staging')
        throw new Problem('Pilot: требуется отдельный стенд staging и WORKSPACE_PILOT=1.',403);
    $byType=[];
    foreach ($config['items'] as $key=>$c) {
        if (!$c['configured']) continue;
        if (isset($byType[$c['type']])) throw new Problem('Pilot: нужен один экземпляр каждого типа.');
        if ($c['owner']==='' || $c['launch']===null) throw new Problem('Pilot: задайте владельца и адрес интерфейса каждого подключения.');
        $byType[$c['type']]=$c;
    }
    if (array_diff_key(PILOT_CONNECTIONS,$byType)||$config['stand']['owner']==='') throw new Problem('Pilot: настройте девять подключений и владельца стенда.');
    return $byType;
}
function pilotInput(array $raw): array {
    if (array_diff(array_keys($raw),['organization','internal','client','oss','client_name','oss_slug','staff']) || !is_array($raw['staff']??null) || array_diff_key(PILOT_STAFF,$raw['staff']) || array_diff_key($raw['staff'],PILOT_STAFF)) throw new Problem('Pilot: проверьте состав JSON и восемь сотрудников.');
    $out=['organization'=>word($raw['organization']??'',120,'Организация'),'client_name'=>word($raw['client_name']??'',120,'Клиент'),'oss_slug'=>ossSlug($raw['oss_slug']??'')];
    foreach (['internal','client','oss'] as $kind) $out[$kind]=word($raw[$kind]??'',120,'Проект');
    if (count(array_unique([$out['internal'],$out['client'],$out['oss']]))!==3) throw new Problem('Pilot: названия проектов должны различаться.');
    $emails=[]; $passwords=[];
    foreach (PILOT_STAFF as $slot=>[$role,$projects]) {
        $r=$raw['staff'][$slot];
        if (!is_array($r)||array_diff(array_keys($r),['email','name','password'])||!is_string($r['password']??null)) throw new Problem('Pilot: проверьте данные сотрудника.');
        $email=normalizedEmail($r['email']??''); $pw=$r['password'];
        if (isset($emails[$email]) || isset($passwords[hash('sha256',$pw)])) throw new Problem('Pilot: адреса и пароли сотрудников должны различаться.');
        $emails[$email]=true; $passwords[hash('sha256',$pw)]=true;
        $out['staff'][$slot]=['email'=>$email,'name'=>word($r['name']??'',90,'Имя'),'role'=>$role,'hash'=>passwordHash($pw)];
    }
    return $out;
}
/** Fresh installation only. A single transaction prevents partially provisioned teams. */
function pilotSeed(PDO $db,int $ownerId,array $raw): array {
    $catalog=pilotCatalog(connectionConfig()); $data=pilotInput($raw);
    return atomic($db,function() use($db,$ownerId,$catalog,$data) {
        $u=orgOwner($db,['id'=>$ownerId,'organization_id'=>1]);
        foreach (['organizations'=>1,'users'=>1,'projects'=>0,'teams'=>0,'invitations'=>0,'organization_connections'=>0,'connection_checks'=>0,'connection_results'=>0] as $table=>$expected) {
            if ((int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn()!==$expected) throw new Problem('Pilot: требуется новая установка только с одним владельцем, без проектов и подключений.',409);
        }
        $owner=entity($db,'SELECT email FROM users WHERE id=?',[$ownerId]);
        foreach ($data['staff'] as $r) if (strcasecmp($r['email'],$owner['email'])===0) throw new Problem('Pilot: адрес владельца уже используется.');
        query($db,'UPDATE organizations SET name=? WHERE id=1',[$data['organization']]);
        $projects=[]; $staff=[];
        foreach (['internal','client','oss'] as $kind) {
            $id=insertId($db,'INSERT INTO projects(organization_id,title,client,kind) VALUES(1,?,?,?)',[$data[$kind],$kind==='client'?$data['client_name']:'',$kind]);
            $projects[$kind]=$id;
            query($db,'INSERT INTO project_members(organization_id,project_id,user_id) VALUES(1,?,?)',[$id,$ownerId]);
            auditEvent($db,$u,$id,'project.create',$id);
        }
        $teams=['internal'=>insertId($db,'INSERT INTO teams(organization_id,name) VALUES(1,?)',['Pilot Internal']), 'client'=>insertId($db,'INSERT INTO teams(organization_id,name) VALUES(1,?)',['Pilot Client'])];
        foreach (PILOT_STAFF as $slot=>[$role,$kinds]) {
            $r=$data['staff'][$slot];
            $id=insertId($db,'INSERT INTO users(email,name,role,password_hash) VALUES(?,?,?,?)',[$r['email'],$r['name'],$role,$r['hash']]);
            $staff[$slot]=$id; query($db,'INSERT INTO organization_members VALUES(1,?,?)',[$id,$role]);
            $team=$teams[in_array('client',$kinds,true)?'client':'internal'];
            query($db,'INSERT INTO team_members(organization_id,team_id,user_id) VALUES(1,?,?)',[$team,$id]);
            auditEvent($db,$u,null,'pilot.staff',$id,$slot);
            foreach ($kinds as $kind) {
                $p=$projects[$kind]; query($db,'INSERT INTO project_members(organization_id,project_id,user_id) VALUES(1,?,?)',[$p,$id]);
                if ($slot==='operator') query($db,"INSERT INTO project_access(project_id,user_id,role) VALUES(?,?,'operator')",[$p,$id]);
                auditEvent($db,$u,$p,'pilot.member',$id,$slot);
            }
        }
        query($db,'INSERT INTO oss_projects(project_id,slug,upstream,public_title,description,published) VALUES(?,?,?,?,?,0)',[$projects['oss'],$data['oss_slug'],ossRepo($catalog['github']['repo']),$data['oss'],'Изолированный пилотный репозиторий.']);
        query($db,'INSERT INTO payment_policies(project_id,dual_control) VALUES(?,1)',[$projects['client']]);
        foreach (PILOT_CONNECTIONS as $type=>$kinds) {
            $key=$catalog[$type]['id']; query($db,'INSERT INTO organization_connections VALUES(?,1)',[$key]);
            auditEvent($db,$u,null,'pilot.target',1,$key.':'.$catalog[$type]['hash']);
            foreach ($kinds as $kind) {
                $p=$projects[$kind]; query($db,'INSERT INTO project_connections(project_id,connection_key) VALUES(?,?)',[$p,$key]);
                auditEvent($db,$u,$p,'pilot.connection',$p,$key);
            }
        }
        auditEvent($db,$u,null,'pilot.seed',1);
        return ['version'=>VERSION,'organization_id'=>1,'owner_id'=>$ownerId,'projects'=>$projects,'staff'=>$staff,'teams'=>$teams,'guest'=>'invite_required','connections'=>array_map(fn($c)=>$c['id'],$catalog)];
    });
}
/** Probes are independent; never replay a mutation or print arbitrary provider responses. */
function pilotProbes(array $catalog,?callable $transport=null): array {
    $out=[];
    foreach ($catalog as $type=>$c) {
        $start=gmdate(DATE_ATOM);
        $row=['key'=>$c['id'],'checked_at'=>$start,'ready'=>false,'observed_version'=>null,'declared_version'=>$c['installed_version'],'version_source'=>null,'http_status'=>null,'duration_ms'=>null,'status'=>'unavailable','target_hash'=>$c['hash']];
        try {
            $r=observeConnection($c,$transport);
            $row['ready']=(bool)$r['ok']; $row['observed_version']=$r['observed_version']; $row['version_source']=$r['version_source']; $row['http_status']=$r['http_status']; $row['duration_ms']=$r['duration_ms'];
            $row['status']=!$r['ok']?'unavailable':($type==='github'?((int)$r['repository_archived']===0?'ready':'archived'):(($r['observed_version']!==null&&$c['installed_version']!==null&&$r['observed_version']!==$c['installed_version'])?'version_mismatch':($r['observed_version']!==null?'ready':($c['installed_version']!==null?'declared_version_only':'version_unknown'))));
            if ($type==='github' && $r['ok']) { $row['repository']=$c['repo']; $row['default_branch']=$r['repository_branch']; }
            if ($c['version_path']!==null&&$r['version_error']!==null&&$r['ok']) $row['status']='version_check_failed';
        } catch (\Throwable) { $row['status']='probe_error'; }
        $out[$type]=$row;
    }
    return $out;
}
/** Read-only verification of the persisted setup and actual permission checks. */
function pilotCheck(PDO $db,int $ownerId): array {
    $catalog=pilotCatalog(connectionConfig()); $u=orgOwner($db,['id'=>$ownerId,'organization_id'=>1]);
    $issues=[]; $projects=[];
    foreach (query($db,'SELECT id,kind FROM projects WHERE organization_id=1 ORDER BY id')->fetchAll() as $r) {
        if (isset($projects[$r['kind']])) $issues[]='duplicate_project_kind';
        $projects[$r['kind']]=(int)$r['id'];
    }
    if (count($projects)!==3 || array_diff(['internal','client','oss'],array_keys($projects))) throw new Problem('Pilot: ожидаются три проекта стенда.',409);
    // A second independent control tenant is allowed only if provisioned by pilot:boundary.
    $orgCount=(int)$db->query('SELECT COUNT(*) FROM organizations')->fetchColumn();
    if ($orgCount!==1 && ($orgCount!==2 || (int)$db->query("SELECT COUNT(*) FROM audit_events WHERE event='pilot.boundary' AND organization_id<>1")->fetchColumn()!==1)) $issues[]='not_isolated';
    // The seed records slot assignments, not credentials; re-evaluate roles using the normal RBAC.
    $staff=[]; $seen=[];
    foreach (query($db,"SELECT object_id,detail FROM audit_events WHERE organization_id=1 AND event='pilot.staff' ORDER BY id",[])->fetchAll() as $r) {
        $slot=$r['detail']; $id=(int)$r['object_id'];
        if (!isset(PILOT_STAFF[$slot])||isset($staff[$slot])||isset($seen[$id])||$id===$ownerId) { $issues[]='staff_assignment'; continue; }
        $seen[$id]=true; [$role,$kinds]=PILOT_STAFF[$slot]; $checks=[];
        try {
            $actor=organizationUser($db,['id'=>$id,'organization_id'=>1]);
            if ($actor['role']!==$role) $issues[]=$slot.':organization_role';
            foreach ($projects as $kind=>$p) {
                $expected=in_array($kind,$kinds,true)?($slot==='operator'?'operator':$role):null;
                try { $actual=projectRole($db,$actor,$p); } catch (Problem $e) { if ($e->status!==404) throw $e; $actual=null; }
                $checks[$kind]=['expected'=>$expected,'actual'=>$actual,'ok'=>$actual===$expected];
                if ($actual!==$expected) $issues[]=$slot.':'.$kind;
            }
        } catch (Problem) { $issues[]=$slot.':inactive'; }
        $staff[$slot]=['id'=>$id,'projects'=>$checks];
    }
    if (count($staff)!==count(PILOT_STAFF)) $issues[]='missing_staff';
    $teams=[];
    foreach (query($db,'SELECT id,name FROM teams WHERE organization_id=1 ORDER BY id',[])->fetchAll() as $t) $teams[$t['name']]=(int)$t['id'];
    if (count($teams)!==2||!isset($teams['Pilot Internal'],$teams['Pilot Client'])) $issues[]='team_layout';
    else foreach (['Pilot Internal','Pilot Client'] as $name) {
        $expected=[]; foreach (PILOT_STAFF as $slot=>[$role,$kinds]) if (isset($staff[$slot])&&((in_array('client',$kinds,true))===($name==='Pilot Client'))) $expected[]=$staff[$slot]['id'];
        $actual=array_map('intval',query($db,'SELECT user_id FROM team_members WHERE team_id=?',[$teams[$name]])->fetchAll(PDO::FETCH_COLUMN));
        sort($expected); sort($actual); if ($expected!==$actual) $issues[]='team_membership';
    }
    if ((int)$db->query('SELECT COUNT(*) FROM organization_members WHERE organization_id=1')->fetchColumn()!==count(PILOT_STAFF)+1) $issues[]='unexpected_staff';
    foreach (PILOT_CONNECTIONS as $type=>$kinds) {
        $key=$catalog[$type]['id'];
        if (!query($db,"SELECT 1 FROM audit_events WHERE organization_id=1 AND event='pilot.target' AND detail=?",[$key.':'.$catalog[$type]['hash']])->fetchColumn()) $issues[]=$type.':target_changed';
        if ((int)query($db,'SELECT organization_id FROM organization_connections WHERE connection_key=?',[$key])->fetchColumn()!==1) $issues[]=$type.':organization';
        foreach ($projects as $kind=>$p) if ((bool)query($db,'SELECT 1 FROM project_connections WHERE project_id=? AND connection_key=?',[$p,$key])->fetchColumn()!==in_array($kind,$kinds,true)) $issues[]=$type.':'.$kind;
    }
    $repo=query($db,'SELECT upstream FROM oss_projects WHERE project_id=?',[$projects['oss']])->fetchColumn();
    if ($repo!==ossRepo($catalog['github']['repo'])) $issues[]='oss_repository';
    if (!dualControl($db,$projects['client'])) $issues[]='dual_control';
    $guests=[];
    foreach (query($db,'SELECT id,email FROM guest_invites WHERE organization_id=1 AND project_id=? AND revoked_at IS NULL',[$projects['client']])->fetchAll() as $r) {
        $g=activeGuest($db,(int)$r['id']);
        if ($g && $g['issuer']===ID_ISSUER && !query($db,'SELECT 1 FROM users WHERE LOWER(email)=?',[$r['email']])->fetchColumn() && !query($db,'SELECT 1 FROM oidc_identities WHERE issuer=? AND subject=?',[$g['issuer'],$g['subject']])->fetchColumn()) {
            try { retentionDocumentBytes($db,(int)$g['document_id'],(int)$g['revision']); $guests[]=(int)$r['id']; } catch (Problem) { $issues[]='guest_document_unavailable'; }
        }
    }
    return ['version'=>VERSION,'schema'=>SCHEMA_VERSION,'owner_id'=>$ownerId,'checked_at'=>gmdate(DATE_ATOM),'projects'=>$projects,'staff'=>$staff,'teams'=>$teams,'guest_active_ids'=>$guests,'issues'=>$issues,'topology_ready'=>$issues===[],'guest_ready'=>$guests!==[]];
}

/** Issue the real OIDC guest invitation for the pilot client project. No synthetic identity. */
function pilotGuestInvite(PDO $db,int $ownerId,int $document,int $revision,string $email,int $hours,?int $order=null): string {
    $report=pilotCheck($db,$ownerId);
    if (!$report['topology_ready']) throw new Problem('Pilot: сначала устраните расхождения структуры стенда.',409);
    oidcConfig();
    return issueGuest($db,['id'=>$ownerId,'organization_id'=>1],$report['projects']['client'],$document,$revision,$email,$hours,$order);
}
