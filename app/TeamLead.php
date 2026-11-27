<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

// The queue records internal review, not GitHub approval or release authorization.
function leadAccess(PDO $db,array $user,int $project,bool $manage=false): array {
    $u=organizationUser($db,$user);
    requireProjectRight($db,$u,$project,'work');requireProjectRight($db,$u,$project,'connections');
    if($manage)requireProjectRight($db,$u,$project,'manage');
    return $u;
}
function leadReviewer(PDO $db,array $u,int $project,int $id): void {
    leadAccess($db,organizationUser($db,['id'=>$id],(int)$u['organization_id']),$project);
}
function leadPin(?array $link,int $now): ?array {
    if(!$link||!(int)$link['active']||$link['kind']!=='pull'||$link['last_error']!==''||$link['synced_at']===null||(int)$link['synced_at']<$now-900||(int)$link['synced_at']>$now)return null;
    $s=json_decode((string)$link['snapshot'],true);
    if(!is_array($s)||($s['state']??null)!=='open'||($s['merged']??null)!==false||!is_string($s['head_sha']??null)||!preg_match('/\A[a-f0-9]{40}\z/',$s['head_sha']))return null;
    return ['repository_binding'=>(int)$link['repository_binding'],'generation'=>(int)$link['generation'],'link_version'=>(int)$link['version'],'pr_number'=>(int)$link['number'],'head_sha'=>$s['head_sha']];
}
function leadLink(PDO $db,int $project,int $task): ?array {
    return query($db,'SELECT l.*,r.generation,r.active,r.full_name FROM github_task_links l JOIN github_repositories r ON r.id=l.repository_binding AND r.project_id=l.project_id WHERE l.project_id=? AND l.task_id=?',[$project,$task])->fetch()?:null;
}
function leadPinHash(?array $pin): string { return $pin?hash('sha256',json_encode($pin,JSON_THROW_ON_ERROR)):''; }
function leadSame(?array $pin,array $event): bool {
    if(!$pin)return false;
    foreach($pin as $k=>$v)if((is_int($v)?(int)($event[$k]??0):($event[$k]??null))!==$v)return false;
    return true;
}
function leadLatestReview(PDO $db,int $project,int $task): ?array {
    return query($db,'SELECT * FROM lead_review_events WHERE project_id=? AND task_id=? ORDER BY id DESC LIMIT 1',[$project,$task])->fetch()?:null;
}

function leadInvalidateLink(PDO $db,array $u,int $project,int $task): void {
    $old=leadLatestReview($db,$project,$task);
    if(!$old||$old['state']==='cancelled')return;
    insertId($db,'INSERT INTO lead_review_events(project_id,task_id,repository_binding,generation,link_version,pr_number,head_sha,reviewer_id,actor_id,previous_id,operation,state,note,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$project,$task,$old['repository_binding'],$old['generation'],$old['link_version'],$old['pr_number'],$old['head_sha'],$old['reviewer_id'],$u['id'],$old['id'],'cancelled','cancelled','Связь задачи с PR изменена.',time()]);
}
function leadReview(PDO $db,array $user,int $project,string $key,array $form): int {
    $op=choice($form['operation']??'',array_fill_keys(['assign','replace','approved','changes','cancelled'],true));
    $manage=in_array($op,['assign','replace','cancelled'],true);$u=leadAccess($db,$user,$project,$manage);
    $task=numberId($form['task_id']??null);$prev=optionalId($form['previous_id']??'');$reviewer=$manage&&$op!=='cancelled'?numberId($form['reviewer_id']??null):null;
    $note=word($form['note']??'',1000,'Причина / результат');$sha=word($form['head_sha']??'',40,'SHA',false);$pinHash=word($form['pin_hash']??'',64,'Наблюдение PR',false);
    return action($db,$u,$key,'lead.review',[$project,$task,$prev,$op,$reviewer,$note,$sha,$pinHash],function()use($db,$u,$project,$task,$prev,$op,$reviewer,$note,$sha,$pinHash,$manage){
        $u=leadAccess($db,$u,$project,$manage);if(isset($_SESSION['uid']))requireIdentitySession($db,$u);$t=entity($db,'SELECT * FROM tasks WHERE project_id=? AND id=?',[$project,$task]);
        $old=leadLatestReview($db,$project,$task);if(($old?(int)$old['id']:null)!==$prev)throw new Problem('Очередь изменилась. Обновите страницу.',409);
        $pin=leadPin(leadLink($db,$project,$task),time());
        if($op==='assign'||$op==='replace'){
            if(!$pin||$pin['head_sha']!==$sha||!hash_equals(leadPinHash($pin),$pinHash))throw new Problem('Сначала синхронизируйте открытый PR; SHA изменился.',409);
            if($op==='replace'&&(!$old||$old['state']!=='pending'||(int)$old['reviewer_id']===$reviewer||!leadSame($pin,$old)))throw new Problem('Замещение возможно для актуального ожидающего review.',409);
            if($op==='assign'&&$old&&$old['state']==='pending'&&leadSame($pin,$old))throw new Problem('Используйте замещение или отмену.',409);
            leadReviewer($db,$u,$project,$reviewer);if((int)$t['assignee_id']===$reviewer)throw new Problem('Исполнитель задачи не может быть её ревьюером.',409);
            $state='pending';
        }else{
            if(!$old||$old['state']!=='pending')throw new Problem('Нет ожидающего review.',409);
            $reviewer=(int)$old['reviewer_id'];
            if($op!=='cancelled'){
                if((int)$u['id']!==$reviewer||(int)$t['assignee_id']===$reviewer)throw new Problem('Решение принимает назначенный независимый ревьюер.',403);
                if(!leadSame($pin,$old)||$pin['head_sha']!==$sha||!hash_equals(leadPinHash($pin),$pinHash))throw new Problem('PR изменился или наблюдение устарело.',409);
            }else $pin=array_intersect_key($old,array_flip(['repository_binding','generation','link_version','pr_number','head_sha']));
            $state=$op;
        }
        return insertId($db,'INSERT INTO lead_review_events(project_id,task_id,repository_binding,generation,link_version,pr_number,head_sha,reviewer_id,actor_id,previous_id,operation,state,note,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$project,$task,$pin['repository_binding'],$pin['generation'],$pin['link_version'],$pin['pr_number'],$pin['head_sha'],$reviewer,$u['id'],$prev,$op,$state,$note,time()]);
    });
}
function leadDecision(PDO $db,array $user,int $project,string $key,array $form): int {
    $u=leadAccess($db,$user,$project,true);$prev=optionalId($form['previous_id']??'');
    $state=choice($form['state']??'',array_fill_keys(['open','accepted','rejected'],true));
    $task=$prev===null?optionalId($form['task_id']??''):null;
    $title=$prev===null?word($form['title']??'',180,'Название'):'';
    $context=$prev===null?criteriaText($form['context']??null):'';$resolution=$prev===null?criteriaText($form['resolution']??null):'';
    $note=word($form['note']??'',1000,'Обоснование',$prev!==null);
    return action($db,$u,$key,'lead.decision',[$project,$prev,$state,$task,$title,$context,$resolution,$note],function()use($db,$u,$project,$prev,$state,$task,$title,$context,$resolution,$note){
        leadAccess($db,$u,$project,true);if(isset($_SESSION['uid']))requireIdentitySession($db,$u);
        if($prev===null){if($state!=='open')throw new Problem('Сначала создайте открытое решение.',409);if($task!==null)entity($db,'SELECT id FROM tasks WHERE project_id=? AND id=?',[$project,$task]);}
        else{$old=entity($db,'SELECT * FROM lead_decisions WHERE project_id=? AND id=?',[$project,$prev]);if($state==='open'||$old['state']!=='open'||query($db,'SELECT 1 FROM lead_decisions WHERE previous_id=?',[$prev])->fetchColumn())throw new Problem('Решение уже рассмотрено.',409);$task=$old['task_id'];$title=$old['title'];$context=$old['context'];$resolution=$old['resolution'];}
        return insertId($db,'INSERT INTO lead_decisions(project_id,task_id,previous_id,actor_id,title,context,resolution,state,note,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$project,$task,$prev,$u['id'],$title,$context,$resolution,$state,$note,time()]);
    });
}
// Called inside the same transaction as the dependency/state mutation.
function leadWaitSync(PDO $db,int $project,int $task,int $pre,bool $observed=false): void {
    $active=(bool)query($db,"SELECT 1 FROM task_dependencies d JOIN tasks t ON t.id=d.prerequisite_id AND t.project_id=d.project_id WHERE d.project_id=? AND d.task_id=? AND d.prerequisite_id=? AND t.state<>'done'",[$project,$task,$pre])->fetchColumn();
    $now=time();
    if($active)query($db,'INSERT INTO lead_waits(project_id,task_id,prerequisite_id,started_at,observed_only) VALUES(?,?,?,?,?) ON CONFLICT DO NOTHING',[$project,$task,$pre,$now,(int)$observed]);
    else{ $exists=query($db,'SELECT 1 FROM task_dependencies WHERE project_id=? AND task_id=? AND prerequisite_id=?',[$project,$task,$pre])->fetchColumn();query($db,'UPDATE lead_waits SET ended_at=CASE WHEN started_at>? THEN started_at ELSE ? END,end_reason=?,version=version+1 WHERE project_id=? AND task_id=? AND prerequisite_id=? AND ended_at IS NULL',[$now,$now,$exists?'completed':'removed',$project,$task,$pre]);}
}
function leadWaitReason(PDO $db,array $user,int $project,string $key,array $form): int {
    $u=leadAccess($db,$user,$project,true);$task=numberId($form['task_id']??null);$pre=numberId($form['prerequisite_id']??null);$id=optionalId($form['wait_id']??'');$version=optionalId($form['version']??'');$reason=word($form['reason']??'',1000,'Причина ожидания');
    return action($db,$u,$key,'lead.wait',[$project,$task,$pre,$id,$version,$reason],function()use($db,$u,$project,$task,$pre,$id,$version,$reason){
        leadAccess($db,$u,$project,true);if(isset($_SESSION['uid']))requireIdentitySession($db,$u);$row=query($db,'SELECT * FROM lead_waits WHERE project_id=? AND task_id=? AND prerequisite_id=? AND ended_at IS NULL',[$project,$task,$pre])->fetch();
        if(($row?(int)$row['id']:null)!==$id||($row?(int)$row['version']:null)!==$version)throw new Problem('Ожидание изменилось.',409);
        leadWaitSync($db,$project,$task,$pre,true);$row=query($db,'SELECT * FROM lead_waits WHERE project_id=? AND task_id=? AND prerequisite_id=? AND ended_at IS NULL',[$project,$task,$pre])->fetch();if(!$row)throw new Problem('Зависимость уже не блокирует задачу.',409);
        query($db,'UPDATE lead_waits SET reason=?,version=version+1 WHERE id=?',[$reason,$row['id']]);auditEvent($db,$u,$project,'lead.wait.reason',(int)$row['id'],$reason);return (int)$row['id'];
    });
}
function leadPage(PDO $db,array $user,int $project,int $after=0,string $mode='all'): array {
    $u=leadAccess($db,$user,$project);$manage=roleAllows(projectRole($db,$u,$project),'manage');$people=[];
    foreach(planningPeople($db,$u,$project) as $id=>$name){try{leadReviewer($db,$u,$project,(int)$id);$people[$id]=$name;}catch(Problem){}}
    $mode=choice($mode,['all'=>true,'pending'=>true,'mine'=>true]);
    $args=[$project,$after];$filter='';
    if($mode!=='all'){$filter=" AND r.state='pending'";if($mode==='mine'){$filter.=' AND r.reviewer_id=?';$args[]=$u['id'];}}
    $tasks=query($db,"SELECT t.id,t.title,t.assignee_id,t.state,t.due_on FROM tasks t LEFT JOIN lead_review_events r ON r.id=(SELECT MAX(e.id) FROM lead_review_events e WHERE e.project_id=t.project_id AND e.task_id=t.id) WHERE t.project_id=? AND t.id>? AND (EXISTS(SELECT 1 FROM github_task_links l WHERE l.task_id=t.id AND l.kind='pull') OR r.id IS NOT NULL)$filter ORDER BY t.id LIMIT 51",$args)->fetchAll();
    $more=count($tasks)>50;$tasks=array_slice($tasks,0,50);
    foreach($tasks as &$t){$id=(int)$t['id'];$t['pin']=leadPin(leadLink($db,$project,$id),time());$t['review']=leadLatestReview($db,$project,$id);$t['history']=query($db,'SELECT * FROM lead_review_events WHERE project_id=? AND task_id=? ORDER BY id DESC LIMIT 20',[$project,$id])->fetchAll();}unset($t);
    $waits=query($db,"SELECT d.task_id,d.prerequisite_id,t.title,p.title prerequisite_title,p.due_on,w.id,w.started_at,w.observed_only,w.reason,w.version FROM task_dependencies d JOIN tasks t ON t.id=d.task_id AND t.project_id=d.project_id JOIN tasks p ON p.id=d.prerequisite_id AND p.project_id=d.project_id LEFT JOIN lead_waits w ON w.project_id=d.project_id AND w.task_id=d.task_id AND w.prerequisite_id=d.prerequisite_id AND w.ended_at IS NULL WHERE d.project_id=? AND p.state<>'done' ORDER BY t.priority,t.id,p.id LIMIT 101",[$project])->fetchAll();
    $decisions=query($db,'SELECT d.*, original.actor_id original_actor, original.created_at original_at FROM lead_decisions d LEFT JOIN lead_decisions original ON original.id=d.previous_id WHERE d.project_id=? AND NOT EXISTS(SELECT 1 FROM lead_decisions n WHERE n.previous_id=d.id) ORDER BY CASE WHEN d.state=\'open\' THEN 0 ELSE 1 END,d.id DESC LIMIT 100',[$project])->fetchAll();
    return ['project'=>allowedProject($db,$u,$project),'leadManage'=>$manage,'leadPeople'=>$people,'leadTasks'=>$tasks,'leadMore'=>$more,'leadMode'=>$mode,'leadWaits'=>$waits,'leadWaitHistory'=>query($db,'SELECT * FROM lead_waits WHERE project_id=? AND ended_at IS NOT NULL ORDER BY id DESC LIMIT 100',[$project])->fetchAll(),'leadDecisions'=>$decisions];
}
function leadDuration(int $start,int $end): string { $m=intdiv(max(0,$end-$start),60);return intdiv($m,60).' ч '.($m%60).' мин'; }
