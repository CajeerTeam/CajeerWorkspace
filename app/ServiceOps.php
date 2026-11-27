<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

// Recovery SLO uses observed incident detection, not a fabricated uptime percentage.
function opsSlo(array $rows,int $now,int $target): array {
    $met=$missed=$pending=0;
    foreach($rows as $r){$due=(int)$r['detected_at']+60*(int)$r['recovery_minutes'];
        if($r['resolved_at']!==null){if((int)$r['resolved_at']<=$due)$met++;else $missed++;}
        elseif($now>$due)$missed++;else $pending++;
    }
    $n=$met+$missed;
    return ['met'=>$met,'missed'=>$missed,'pending'=>$pending,'eligible'=>$n,'actual_bp'=>$n?intdiv($met*10000,$n):null,'target_bp'=>$target,'state'=>!$n?'unknown':($met*10000 >= $target*$n?'met':'missed')];
}
function opsService(PDO $db,int $p,int $s): array {
    return entity($db,'SELECT o.*,e.kind,s.host_id,s.site_name FROM ops_services o JOIN infra_sites s ON s.id=o.site_id AND s.project_id=o.project_id JOIN infra_environments e ON e.id=s.environment_id WHERE o.project_id=? AND o.id=?',[$p,$s]);
}
function opsAssociate(PDO $db,int $p,array $s,int $incident,?int $deployment=null): void {
    $old=query($db,'SELECT * FROM ops_service_incidents WHERE incident_id=?',[$incident])->fetch();
    if($old){if((int)$old['service_id']!==(int)$s['id']||($deployment!==null&&(int)$old['deployment_id']!==$deployment))throw new Problem('Инцидент уже связан с другим сервисом или развёртыванием.',409);return;}
    if($deployment!==null&&query($db,'SELECT 1 FROM ops_service_incidents WHERE deployment_id=?',[$deployment])->fetchColumn())throw new Problem('Развёртывание уже связано с инцидентом.',409);
    query($db,'INSERT INTO ops_service_incidents(project_id,service_id,incident_id,deployment_id,recovery_minutes,linked_at) VALUES(?,?,?,?,?,?)',[$p,$s['id'],$incident,$deployment,$s['recovery_minutes'],time()]);
}
// Authoritative managed deployment records are processed before remote logs. One incident per job.
function opsDeploymentSignals(PDO $db,array $u,array $policy,?int $onlyJob=null): array {
    $p=(int)$policy['project_id'];$ids=[];
    // Existing service policy keeps its original start boundary; otherwise use the on-call policy boundary.
    $rows=query($db,"SELECT d.*,o.id AS service_id FROM deployments d LEFT JOIN ops_services o ON o.project_id=d.project_id AND o.site_id=d.site_id AND o.created_at<=d.finished_at WHERE d.project_id=? AND d.state IN ('failed','recovery_required') AND d.started_at IS NOT NULL AND (o.id IS NOT NULL OR d.finished_at>=?) ".($onlyJob!==null?' AND d.id=?':" AND NOT EXISTS(SELECT 1 FROM ops_service_incidents l WHERE l.deployment_id=d.id) AND NOT EXISTS(SELECT 1 FROM incidents i JOIN incident_signals sig ON sig.incident_id=i.id AND sig.project_id=i.project_id WHERE i.project_id=d.project_id AND i.release_id=d.release_id AND i.object_id=d.id AND i.source='logs' AND i.condition_code IN ('deploy.failed','deploy.recovery_required') AND sig.evidence LIKE '%managed_deployment_record%')")." ORDER BY d.id LIMIT 100",$onlyJob===null?[$p,$policy['updated_at']]:[$p,$policy['updated_at'],$onlyJob])->fetchAll();
    foreach($rows as $d){$job=(int)$d['id'];$t=(int)$d['finished_at'];$code='deploy.'.$d['state'];$target=json_decode($d['target_snapshot'],true,32,JSON_THROW_ON_ERROR);
        if(!is_array($target)||(int)($target['project_id']??0)!==$p||(int)($target['site_id']??0)!==(int)$d['site_id']||!in_array($target['kind']??null,['dev','staging','prod'],true)||!isset($target['host_id']))continue;
        $s=$d['service_id']===null?null:opsService($db,$p,(int)$d['service_id']);
        $prior=null;
        $candidates=query($db,"SELECT * FROM incidents WHERE project_id=? AND source='logs' AND object_id=? AND release_id=? AND environment=? AND condition_code IN ('deploy.failed','deploy.recovery_required') ORDER BY CASE WHEN id IN (SELECT incident_id FROM ops_service_incidents WHERE deployment_id=?) THEN 0 ELSE 1 END,id LIMIT 100",[$p,$job,$d['release_id'],$target['kind'],$job])->fetchAll();
        foreach($candidates as $candidate){$signals=query($db,'SELECT evidence FROM incident_signals WHERE project_id=? AND incident_id=? ORDER BY id DESC LIMIT 100',[$p,$candidate['id']])->fetchAll();foreach($signals as $signal){$ev=json_decode($signal['evidence'],true,16,JSON_THROW_ON_ERROR);if(is_array($ev)&&traceIncidentMatches($candidate,$d,$target,$ev)){$prior=$candidate;break 2;}}}
        if($prior){if($s)opsAssociate($db,$p,$s,(int)$prior['id'],$job);if($prior['state']==='resolved')continue;}
        $fp=$prior?$prior['fingerprint']:incidentHash(['managed-deployment',$p,$job]);
        $id=incidentAccept($db,$u,$policy,['source'=>'logs','fingerprint'=>$fp,'key'=>incidentHash(['managed-deployment',$p,$job,$d['attempt'],$t,$code]),'code'=>$code,'object'=>$job,'env'=>$target['kind'],'release'=>(int)$d['release_id'],'severity'=>$d['state']==='recovery_required'?'critical':'error','at'=>$t,'state'=>'bad','evidence'=>['event'=>$code,'object_id'=>$job,'release_id'=>(int)$d['release_id'],'environment'=>$target['kind'],'host_id'=>(int)$target['host_id'],'observed_at'=>$t,'method'=>'managed_deployment_record']]);
        if($id!==null){if($s)opsAssociate($db,$p,$s,$id,$job);$ids[]=$id;}
    }return $ids;
}
function opsIncidentSignal(PDO $db,int $p,array $signal): bool {
    if($signal['source']==='logs'&&in_array($signal['code'],['deploy.failed','deploy.recovery_required'],true)){
        // Managed jobs are reconciled from the executor, never from an unverified remote failure.
        return false;
    }return true;
}
function opsAutoAssociate(PDO $db,int $p,int $id,array $signal): void {
    if($signal['source']==='logs'){
        if(!in_array($signal['code'],['deploy.failed','deploy.recovery_required'],true))return;
        $s=query($db,'SELECT o.* FROM ops_services o JOIN deployments d ON d.site_id=o.site_id AND d.project_id=o.project_id JOIN infra_sites st ON st.id=o.site_id JOIN infra_environments e ON e.id=st.environment_id WHERE o.project_id=? AND d.id=? AND d.release_id=? AND e.kind=? AND st.host_id=? AND d.started_at IS NOT NULL AND o.created_at<=?',[$p,$signal['object'],$signal['release'],$signal['env'],$signal['evidence']['host_id']??null,$signal['at']])->fetch();
        if($s)opsAssociate($db,$p,$s,$id,(int)$signal['object']);return;
    }
    if(!in_array($signal['source'],['servers','aapanel'],true))return;
    $s=query($db,'SELECT * FROM ops_services WHERE project_id=? AND site_id=? AND created_at<=?',[$p,$signal['object'],$signal['at']])->fetch();
    if($s)opsAssociate($db,$p,$s,$id);
}
function opsCommand(PDO $db,array $user,int $p,string $key,string $act,array $f): int {
    if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);
    $u=incidentOperator($db,$user,$p);requireProjectRight($db,$u,$p,'connections');
    if(in_array($act,['ops.service','ops.followup'],true))requireProjectRight($db,$u,$p,'manage');
    unset($f['csrf'],$f['request_key'],$f['act']);ksort($f);
    return action($db,$u,$key,$act,[$p,$f],function()use($db,$u,$p,$key,$act,$f){
        $u=incidentOperator($db,$u,$p);if(staffHandle()!==null&&!staffSessionValid($db,$u))throw new Problem('Сессия отозвана.',403);requireProjectRight($db,$u,$p,'connections');
        if(in_array($act,['ops.service','ops.followup'],true))requireProjectRight($db,$u,$p,'manage');
        if($act==='ops.service'){
            $site=numberId($f['site_id']??null);entity($db,'SELECT id FROM infra_sites WHERE project_id=? AND id=?',[$p,$site]);
            $name=word($f['name']??'',120,'Сервис');$minutes=numberId($f['recovery_minutes']??null);$target=numberId($f['target_bp']??null);
            if($minutes>10080||$target>10000)throw new Problem('Некорректная цель SLO.');
            $old=query($db,'SELECT * FROM ops_services WHERE project_id=? AND site_id=?',[$p,$site])->fetch();
            if($old){if((int)$old['version']!==numberId($f['version']??null))throw new Problem('Политика изменилась.',409);query($db,'UPDATE ops_services SET name=?,recovery_minutes=?,target_bp=?,version=version+1 WHERE id=?',[$name,$minutes,$target,$old['id']]);}
            else {if(($f['version']??'')!=='')throw new Problem('Сервис изменился.',409);insertId($db,'INSERT INTO ops_services(project_id,site_id,name,recovery_minutes,target_bp,created_at) VALUES(?,?,?,?,?,?)',[$p,$site,$name,$minutes,$target,time()]);}
            return $p;
        }
        $s=opsService($db,$p,numberId($f['service_id']??null));$sid=(int)$s['id'];
        if($act==='ops.intake'){
            $ticket=insertId($db,'INSERT INTO ops_support(project_id,service_id,title,description,requester,created_by,created_at) VALUES(?,?,?,?,?,?,?)',[$p,$sid,word($f['title']??'',180,'Запрос'),criteriaText($f['description']??''),word($f['requester']??'',180,'Автор запроса'),$u['id'],time()]);auditEvent($db,$u,$p,'ops.support.opened',$ticket);
        }elseif($act==='ops.associate'){
            $i=incidentRow($db,$u,$p,numberId($f['incident_id']??null));
            if($i['environment']!==$s['kind']||($i['source']!=='logs'&&(int)$i['object_id']!==(int)$s['site_id']))throw new Problem('Инцидент другого окружения или сайта.',409);
            $deployment=null;
            if($i['source']==='logs'){$e=query($db,'SELECT evidence FROM incident_signals WHERE project_id=? AND incident_id=? ORDER BY id DESC LIMIT 1',[$p,$i['id']])->fetchColumn();if($e!==false){$ev=json_decode($e,true,16,JSON_THROW_ON_ERROR);if(isset($ev['host_id'])&&(int)$ev['host_id']!==(int)$s['host_id'])throw new Problem('Инцидент другого хоста.',409);}}
            if($i['source']==='logs'&&in_array($i['condition_code'],['deploy.failed','deploy.recovery_required'],true)){$d=entity($db,'SELECT * FROM deployments WHERE project_id=? AND id=? AND site_id=?',[$p,$i['object_id'],$s['site_id']]);if((int)$d['release_id']!==(int)$i['release_id'])throw new Problem('Выпуск не совпадает.',409);$deployment=(int)$d['id'];}
            opsAssociate($db,$p,$s,(int)$i['id'],$deployment);auditEvent($db,$u,$p,'ops.incident.associated',(int)$i['id']);
        }elseif(in_array($act,['ops.link','ops.close'],true)){
            $r=entity($db,'SELECT * FROM ops_support WHERE project_id=? AND service_id=? AND id=?',[$p,$sid,numberId($f['id']??null)]);
            if($r['state']!=='open'||(int)$r['version']!==numberId($f['version']??null))throw new Problem('Запрос уже изменён или закрыт.',409);
            if($act==='ops.link'){
                $i=numberId($f['incident_id']??null);entity($db,'SELECT incident_id FROM ops_service_incidents WHERE project_id=? AND service_id=? AND incident_id=?',[$p,$sid,$i]);
                if($r['incident_id']!==null&&(int)$r['incident_id']!==$i)throw new Problem('Связь уже зафиксирована.',409);
                query($db,'UPDATE ops_support SET incident_id=?,version=version+1 WHERE id=?',[$i,$r['id']]);auditEvent($db,$u,$p,'ops.support.linked',(int)$r['id']);
            }else{
                if($r['incident_id']!==null&&incidentRow($db,$u,$p,(int)$r['incident_id'])['state']!=='resolved')throw new Problem('Сначала подтвердите восстановление инцидента.',409);
                query($db,"UPDATE ops_support SET state='closed',resolution=?,closed_at=?,version=version+1 WHERE id=?",[criteriaText($f['resolution']??''),time(),$r['id']]);auditEvent($db,$u,$p,'ops.support.closed',(int)$r['id']);
            }
        }elseif($act==='ops.followup'){
            $i=numberId($f['incident_id']??null);entity($db,'SELECT incident_id FROM ops_service_incidents WHERE project_id=? AND service_id=? AND incident_id=?',[$p,$sid,$i]);
            $task=optionalId($f['task_id']??'');
            if($task===null)$task=createTask($db,$u,substr(hash('sha256','ops-task:'.$key),0,32),$p,['title'=>$f['title']??'','assignee'=>$f['assignee']??'','due_on'=>$f['due_on']??'','priority'=>$f['priority']??'3','result_customer'=>$s['name'],'acceptance_criteria'=>$f['acceptance_criteria']??'']);
            else entity($db,'SELECT id FROM tasks WHERE project_id=? AND id=?',[$p,$task]);
            $old=query($db,'SELECT incident_id FROM ops_followups WHERE task_id=?',[$task])->fetchColumn();if($old!==false){if((int)$old!==$i)throw new Problem('Задача уже связана с другим инцидентом.',409);return $p;}
            query($db,'INSERT INTO ops_followups(project_id,service_id,incident_id,task_id,created_by,created_at) VALUES(?,?,?,?,?,?)',[$p,$sid,$i,$task,$u['id'],time()]);auditEvent($db,$u,$p,'ops.task.linked',$task);
        }else throw new Problem('Неизвестная операция.');
        return $p;
    });
}
function opsPage(PDO $db,array $user,int $p,?int $sid,?int $before=null): array {
    $u=organizationUser($db,$user);requireProjectRight($db,$u,$p,'connections');if(staffHandle()!==null&&!staffSessionValid($db,$u))throw new Problem('Сессия отозвана.',403);if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);
    $role=projectRole($db,$u,$p);$manage=roleAllows($role,'manage');$operate=in_array($role,['owner','manager','operator'],true);
    $services=query($db,'SELECT o.*,s.site_name,e.kind FROM ops_services o JOIN infra_sites s ON s.id=o.site_id JOIN infra_environments e ON e.id=s.environment_id WHERE o.project_id=? ORDER BY o.name,o.id',[$p])->fetchAll();
    $sites=$manage?query($db,'SELECT s.id,s.site_name,e.kind FROM infra_sites s JOIN infra_environments e ON e.id=s.environment_id WHERE s.project_id=? AND NOT EXISTS(SELECT 1 FROM ops_services o WHERE o.site_id=s.id) ORDER BY s.id',[$p])->fetchAll():[];
    $s=$sid===null?null:opsService($db,$p,$sid);$slo=null;$tickets=$incidents=$available=$followups=[];
    if($s){$now=time();$cut=max((int)$s['created_at'],$now-30*86400);$cohort=query($db,'SELECT i.detected_at,i.resolved_at,l.recovery_minutes FROM ops_service_incidents l JOIN incidents i ON i.id=l.incident_id WHERE l.project_id=? AND l.service_id=? AND i.detected_at>=? AND i.detected_at<=?',[$p,$sid,$cut,$now])->fetchAll();$slo=opsSlo($cohort,$now,(int)$s['target_bp']);
        $tickets=query($db,'SELECT * FROM ops_support WHERE project_id=? AND service_id=? AND id<? ORDER BY id DESC LIMIT 51',[$p,$sid,$before??PHP_INT_MAX])->fetchAll();
        $incidents=query($db,'SELECT i.*,l.deployment_id,l.recovery_minutes FROM ops_service_incidents l JOIN incidents i ON i.id=l.incident_id WHERE l.project_id=? AND l.service_id=? ORDER BY i.id DESC LIMIT 100',[$p,$sid])->fetchAll();
        $followups=query($db,'SELECT l.incident_id,t.id,t.title,t.state,t.due_on FROM ops_followups l JOIN tasks t ON t.id=l.task_id WHERE l.project_id=? AND l.service_id=? ORDER BY t.id DESC LIMIT 200',[$p,$sid])->fetchAll();
        if($operate)$available=query($db,'SELECT i.id,i.condition_code,i.state FROM incidents i WHERE i.project_id=? AND i.environment=? AND NOT EXISTS(SELECT 1 FROM ops_service_incidents l WHERE l.incident_id=i.id) ORDER BY i.id DESC LIMIT 100',[$p,$s['kind']])->fetchAll();
    }
    return ['opsProject'=>$p,'opsServices'=>$services,'opsSites'=>$sites,'opsSelected'=>$s,'opsSlo'=>$slo,'opsTickets'=>$tickets,'opsIncidents'=>$incidents,'opsAvailable'=>$available,'opsFollowups'=>$followups,'opsManage'=>$manage,'opsOperate'=>$operate];
}
