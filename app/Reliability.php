<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const REL_PROVIDERS=['github'=>'GitHub','logs'=>'Logs','bots'=>'Bots','sign'=>'Dropbox Sign','infra'=>'Servers / aaPanel'];
const REL_BUDGET=8;
const REL_DEFAULT_RATE=120;

/** All admissions are made in the same atomic transaction as the native claim. One credit = 1/60 job. */
function relRate(PDO $db,string $provider,?int $now=null): bool {
    if(!isset(REL_PROVIDERS[$provider]))throw new \LogicException('provider');
    $now??=time();
    $r=query($db,'SELECT * FROM integration_rates WHERE provider=?',[$provider])->fetch();
    $limit=$r?(int)$r['per_minute']:REL_DEFAULT_RATE;
    $last=$r?max((int)$r['updated_at'],$now):$now;
    $credit=$r?min($limit*60,(int)$r['credit']+min(60,max(0,$now-(int)$r['updated_at']))*$limit):$limit*60;
    $ok=$credit>=60;if($ok)$credit-=60;
    query($db,'INSERT INTO integration_rates(provider,per_minute,credit,updated_at) VALUES(?,?,?,?) ON CONFLICT(provider) DO UPDATE SET credit=excluded.credit,updated_at=excluded.updated_at',[$provider,$limit,$credit,$last]);
    return $ok;
}
function relSetRate(PDO $db,string $provider,int $limit): void {
    if(!isset(REL_PROVIDERS[$provider])||$limit<1||$limit>600)throw new Problem('Лимит: 1–600 заданий/минуту.');
    atomic($db,function()use($db,$provider,$limit){query($db,'INSERT INTO integration_rates(provider,per_minute,credit,updated_at) VALUES(?,?,?,?) ON CONFLICT(provider) DO UPDATE SET per_minute=excluded.per_minute,credit=0,updated_at=excluded.updated_at',[$provider,$limit,0,time()]);});
}
function relJournal(PDO $db,int $project,string $provider,int $id,string $outcome,string $code,int $failures=0): void {
    if(!isset(REL_PROVIDERS[$provider])||!preg_match('/\A[a-z0-9_]{1,40}\z/D',$code))throw new \LogicException('journal code');
    query($db,'INSERT INTO integration_journal(project_id,provider,object_id,outcome,code,failures,created_at) VALUES(?,?,?,?,?,?,?)',[$project,$provider,$id,$outcome,$code,$failures,time()]);
}
function relFailures(PDO $db,int $project,string $provider,int $id): int {
    return (int)query($db,'SELECT failures FROM integration_failures WHERE project_id=? AND provider=? AND object_id=?',[$project,$provider,$id])->fetchColumn();
}
/** Call only inside the native state transition transaction. Never copy exception text or payload. */
function relOutcome(PDO $db,int $project,string $provider,int $id,string $outcome,string $code): void {
    $old=relFailures($db,$project,$provider,$id);
    if($outcome==='recovered'&&$old===0)return;
    $n=in_array($outcome,['retry','dead','uncertain'],true)?$old+1:0;
    query($db,'INSERT INTO integration_failures(project_id,provider,object_id,failures,updated_at) VALUES(?,?,?,?,?) ON CONFLICT(project_id,provider,object_id) DO UPDATE SET failures=excluded.failures,updated_at=excluded.updated_at',[$project,$provider,$id,$n,time()]);
    relJournal($db,$project,$provider,$id,$outcome,$code,$n);
}
function relActor(PDO $db,array $u,int $p): array {
    $u=orgOwner($db,$u);allowedProject($db,$u,$p);return $u;
}
function relHost(PDO $db,array $u,int $p,int $host): array {
    $u=relActor($db,$u,$p);$h=infraHost($db,$u,$host);
    if(!query($db,'SELECT 1 FROM infra_sites WHERE organization_id=? AND project_id=? AND host_id=?',[$u['organization_id'],$p,$host])->fetchColumn())throw new Problem('Хост больше не связан с проектом.',409);
    return $h;
}
function relHostHash(array $h): string {return hash('sha256',json_encode([$h['panel_key'],$h['panel_target'],$h['panel_hash'],$h['panel_api'],$h['servers_key'],$h['servers_target'],$h['servers_hash'],$h['workspace_uuid'],$h['asset_uuid']],JSON_THROW_ON_ERROR));}
function relQueueHost(PDO $db,array $u,int $p,int $host): int {
    $u=relActor($db,$u,$p);$h=relHost($db,$u,$p,$host);
    $id=query($db,"SELECT id FROM integration_jobs WHERE project_id=? AND host_id=? AND state IN ('pending','running')",[$p,$host])->fetchColumn();if($id!==false)return (int)$id;
    $session=(int)entity($db,'SELECT session_version FROM users WHERE id=?',[$u['id']])['session_version'];$now=time();
    $id=insertId($db,'INSERT INTO integration_jobs(organization_id,project_id,host_id,actor_id,actor_session,target_hash,available_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)',[$u['organization_id'],$p,$host,$u['id'],$session,relHostHash($h),$now,$now,$now]);
    relJournal($db,$p,'infra',$id,'scheduled','reconcile');return $id;
}
function relBotsCheck(PDO $db,array $u,array $r): void {
    $p=(int)$r['project_id'];$id=(int)$r['id'];botsActor($db,$u,$p);
    if(!in_array($r['state'],['pending','sending','retryable','uncertain'],true))throw new Problem('Сверка Bots недоступна.',409);
    $session=(int)entity($db,'SELECT session_version FROM users WHERE id=?',[$u['id']])['session_version'];
    query($db,"UPDATE bots_messages SET state='queued',command='check',actor_id=?,actor_session=?,available_at=?,updated_at=? WHERE id=?",[$u['id'],$session,time(),time(),$id]);
    botsEvent($db,$r,(int)$u['id'],'queued','reliability_check');
}
/** Transactional reconciliation request: schedule authoritative reads only, never repeat an ambiguous mutation. */
function relReconcile(PDO $db,array $u,int $p): array {
    return atomic($db,function()use($db,$u,$p){$u=relActor($db,$u,$p);$result=['github'=>0,'infra'=>0,'bots'=>0,'sign'=>0];
        $repo=query($db,'SELECT * FROM github_repositories WHERE project_id=? AND active=1',[$p])->fetch();if($repo){ghQueueRepository($db,$repo);$result['github']=1;}
        foreach(query($db,'SELECT DISTINCT host_id FROM infra_sites WHERE organization_id=? AND project_id=?',[$u['organization_id'],$p])->fetchAll() as $h){relQueueHost($db,$u,$p,(int)$h['host_id']);++$result['infra'];}
        $b=botsBinding($db,$p);if($b&&(int)$b['active'])foreach(query($db,"SELECT * FROM bots_messages WHERE project_id=? AND state IN ('pending','sending','retryable','uncertain') AND NOT EXISTS(SELECT 1 FROM integration_failures f WHERE f.project_id=bots_messages.project_id AND f.provider='bots' AND f.object_id=bots_messages.id AND f.failures>=?)",[$p,REL_BUDGET])->fetchAll() as $r){relBotsCheck($db,$u,$r);++$result['bots'];}
        $result['sign']=query($db,"UPDATE signing_packages SET next_check=? WHERE project_id=? AND remote_id IS NOT NULL AND state IN ('pending','partial','provider_error') AND (claim IS NULL OR lease_until<=?) AND NOT EXISTS(SELECT 1 FROM integration_failures f WHERE f.project_id=signing_packages.project_id AND f.provider='sign' AND f.object_id=signing_packages.id AND f.failures>=?)",[time(),$p,time(),REL_BUDGET])->rowCount();
        auditEvent($db,$u,$p,'reliability.reconcile',$p,'read-only jobs scheduled');return $result;
    });
}
/** Reopen exactly one native job, with all original identity/version fences intact. */
function relRetry(PDO $db,array $u,int $p,string $provider,int $id): void {
    atomic($db,function()use($db,$u,$p,$provider,$id){$u=relActor($db,$u,$p);$now=time();
        switch($provider){
        case 'github':
            $r=entity($db,'SELECT j.* FROM github_jobs j JOIN github_repositories r ON r.id=j.repository_binding WHERE j.id=? AND r.project_id=?',[$id,$p]);
            $repo=entity($db,'SELECT * FROM github_repositories WHERE id=? AND project_id=? AND active=1 AND generation=?',[$r['repository_binding'],$p,$r['generation']]);
            if($r['state']!=='dead')throw new Problem('Задание не в DLQ.',409);
            if($r['task_id']!==null)entity($db,'SELECT task_id FROM github_task_links WHERE repository_binding=? AND task_id=? AND version=?',[$repo['id'],$r['task_id'],$r['link_version']]);
            $q='SELECT 1 FROM github_jobs WHERE repository_binding=? AND generation=? AND state IN (\'pending\',\'running\') AND '.($r['task_id']===null?'task_id IS NULL':'task_id=? AND link_version=?');$args=[$repo['id'],$r['generation']];if($r['task_id']!==null)array_push($args,$r['task_id'],$r['link_version']);if(query($db,$q,$args)->fetchColumn())throw new Problem('Уже есть актуальное задание.',409);
            query($db,"UPDATE github_jobs SET state='pending',attempts=0,available_at=?,finished_at=NULL,last_error='' WHERE id=?",[$now,$id]);break;
        case 'logs':
            $r=entity($db,'SELECT * FROM logs_outbox WHERE id=? AND project_id=?',[$id,$p]);$b=logsBinding($db,$p);
            if($r['state']!=='failed'||!in_array($r['error_code'],['retry_budget','preflight_409'],true)||(int)$r['created_at']<=$now-LOG_RETRY_WINDOW||(int)$r['expires_at']<=$now||!$b||!(int)$b['active'])throw new Problem('Повтор Logs небезопасен или окно истекло.',409);
            $c=logsBoundConfig($b);if($r['credential_hash']!==null&&!hash_equals($r['credential_hash'],hash('sha256',logsToken($c['write_env']))))throw new Problem('Credential изменён.',409);
            query($db,"UPDATE logs_outbox SET state='pending',attempts=0,available_at=?,error_code='' WHERE id=?",[$now,$id]);break;
        case 'bots':$r=botsRow($db,$p,$id);relBotsCheck($db,$u,$r);break;
        case 'sign':
            $r=signGet($db,$u,$p,$id);if(!(($r['state']==='queued'&&$r['remote_id']===null&&relFailures($db,$p,'sign',$id)>=REL_BUDGET)||($r['remote_id']!==null&&in_array($r['state'],['pending','partial','provider_error'],true)))||($r['claim']!==null&&(int)$r['lease_until']>$now))throw new Problem('Требуется штатная привязка удалённого ID или завершение обработки.',409);
            query($db,'UPDATE signing_packages SET next_check=? WHERE id=?',[$now,$id]);break;
        case 'infra':
            $r=entity($db,'SELECT * FROM integration_jobs WHERE id=? AND project_id=?',[$id,$p]);if(!hash_equals($r['target_hash'],relHostHash(relHost($db,$u,$p,(int)$r['host_id']))))throw new Problem('Цель хоста изменилась.',409);if($r['state']!=='dead')throw new Problem('Задание не в DLQ.',409);
            if(query($db,"SELECT 1 FROM integration_jobs WHERE project_id=? AND host_id=? AND state IN ('pending','running')",[$p,$r['host_id']])->fetchColumn())throw new Problem('Уже есть активная сверка.',409);
            $session=(int)entity($db,'SELECT session_version FROM users WHERE id=?',[$u['id']])['session_version'];query($db,"UPDATE integration_jobs SET state='pending',attempts=0,actor_id=?,actor_session=?,available_at=?,updated_at=? WHERE id=?",[$u['id'],$session,$now,$now,$id]);break;
        default:throw new Problem('Неизвестный провайдер.');
        }
        relOutcome($db,$p,$provider,$id,'requeued','operator');auditEvent($db,$u,$p,'reliability.retry',$id,$provider);
    });
}
function relClaim(PDO $db): ?array {
    return atomic($db,function()use($db){$now=time();
        foreach(query($db,"SELECT * FROM integration_jobs WHERE state='running' AND lease_until<=?",[$now])->fetchAll() as $r){$dead=(int)$r['attempts']>=REL_BUDGET;query($db,'UPDATE integration_jobs SET state=?,claim=NULL,lease_until=NULL,available_at=?,updated_at=? WHERE id=?',[$dead?'dead':'pending',$now,$now,$r['id']]);relOutcome($db,(int)$r['project_id'],'infra',(int)$r['id'],$dead?'dead':'retry','lease_expired');}
        $r=query($db,"SELECT j.* FROM integration_jobs j WHERE j.state='pending' AND j.available_at<=? AND NOT EXISTS(SELECT 1 FROM integration_jobs x WHERE x.host_id=j.host_id AND x.state='running') ORDER BY j.available_at,j.id LIMIT 1",[$now])->fetch();
        if(!$r||!relRate($db,'infra',$now))return null;$claim=bin2hex(random_bytes(24));query($db,"UPDATE integration_jobs SET state='running',attempts=attempts+1,claim=?,lease_until=?,updated_at=? WHERE id=?",[$claim,$now+600,$now,$r['id']]);return entity($db,'SELECT * FROM integration_jobs WHERE id=?',[$r['id']]);
    });
}
function relFinish(PDO $db,array $job,string $code): string {
    return atomic($db,function()use($db,$job,$code){$r=entity($db,'SELECT * FROM integration_jobs WHERE id=?',[$job['id']]);$now=time();if($r['state']!=='running'||$r['claim']!==$job['claim']||(int)$r['lease_until']<=$now)return 'superseded';
        $state=$code==='ok'?'done':($code==='access_revoked'?'cancelled':((int)$r['attempts']>=REL_BUDGET?'dead':'pending'));
        $delay=min(3600,30*(2**min(7,(int)$r['attempts']-1)));query($db,'UPDATE integration_jobs SET state=?,claim=NULL,lease_until=NULL,available_at=?,updated_at=? WHERE id=?',[$state,$now+$delay,$now,$r['id']]);
        if($state==='done'){if(relFailures($db,(int)$r['project_id'],'infra',(int)$r['id'])>0)relOutcome($db,(int)$r['project_id'],'infra',(int)$r['id'],'recovered','sync_complete');else relJournal($db,(int)$r['project_id'],'infra',(int)$r['id'],'recovered','sync_complete');}
        else relOutcome($db,(int)$r['project_id'],'infra',(int)$r['id'],$state==='pending'?'retry':$state,$code);return $state;
    });
}
function relWork(PDO $db,int $limit=10,?callable $read=null): array {
    if($limit<1||$limit>100)throw new Problem('Лимит 1–100.');$out=[];
    for($i=0;$i<$limit;$i++){$r=relClaim($db);if(!$r)break;$code='source_unavailable';
        try{$u=organizationUser($db,['id'=>(int)$r['actor_id'],'organization_id'=>(int)$r['organization_id'],'session_version'=>(int)$r['actor_session']]);if(!hash_equals($r['target_hash'],relHostHash(relHost($db,$u,(int)$r['project_id'],(int)$r['host_id']))))throw new Problem('Цель хоста изменилась.',409);}
        catch(Problem){$out[$r['id']]=relFinish($db,$r,'access_revoked');continue;}
        try{$result=infraSync($db,$u,(int)$r['host_id'],$read,true);if(!empty($result['aapanel']['fresh'])&&!empty($result['servers']['fresh']))$code='ok';}catch(\Throwable){}
        $out[$r['id']]=relFinish($db,$r,$code);
    }return $out;
}
function relPage(PDO $db,array $u,?int $p,?int $before=null): array {
    $u=orgOwner($db,$u);$projects=rightProjects($db,$u,'connections');$rows=[];$journal=[];
    if($p!==null){relActor($db,$u,$p);
        $rows['github']=query($db,"SELECT j.id,j.state,j.attempts,j.available_at FROM github_jobs j JOIN github_repositories r ON r.id=j.repository_binding WHERE r.project_id=? ORDER BY j.id DESC LIMIT 100",[$p])->fetchAll();
        $rows['logs']=query($db,'SELECT id,state,attempts,available_at,error_code FROM logs_outbox WHERE project_id=? ORDER BY id DESC LIMIT 100',[$p])->fetchAll();
        $rows['bots']=query($db,'SELECT id,state,remote_attempts attempts,available_at FROM bots_messages WHERE project_id=? ORDER BY id DESC LIMIT 100',[$p])->fetchAll();
        $rows['sign']=query($db,"SELECT s.id,s.state,COALESCE(f.failures,0) attempts,s.next_check available_at,CASE WHEN s.remote_id IS NULL THEN 0 ELSE 1 END has_remote FROM signing_packages s LEFT JOIN integration_failures f ON f.project_id=s.project_id AND f.provider='sign' AND f.object_id=s.id WHERE s.project_id=? ORDER BY s.id DESC LIMIT 100",[$p])->fetchAll();
        $rows['infra']=query($db,'SELECT id,state,attempts,available_at,host_id FROM integration_jobs WHERE project_id=? ORDER BY id DESC LIMIT 100',[$p])->fetchAll();
        $journal=query($db,'SELECT * FROM integration_journal WHERE project_id=? AND id<? ORDER BY id DESC LIMIT 101',[$p,$before??PHP_INT_MAX])->fetchAll();
    }return ['relProjects'=>$projects,'relProject'=>$p,'relRows'=>$rows,'relJournalRows'=>$journal];
}

/** Read-only reconciliation after a lost response or expired idempotency window. Absence is not proof of non-delivery. */
function relLogsVerify(PDO $db,array $u,int $p,int $id,?callable $transport=null): string {
    [$b,$r]=atomic($db,function()use($db,$u,$p,$id){relActor($db,$u,$p);$b=logsBinding($db,$p);$r=entity($db,'SELECT * FROM logs_outbox WHERE project_id=? AND id=?',[$p,$id]);
        if(!$b||!(int)$b['active']||!in_array($r['state'],['failed','expired'],true)||(int)$r['expires_at']<=time())throw new Problem('Сверка Logs недоступна.',409);
        if(!relRate($db,'logs'))throw new Problem('Лимит заданий Logs.',429);return [$b,$r];});
    $c=logsBoundConfig($b);$days=logsRemotePolicy($c,(int)$b['retention_days'],$transport);$now=time();
    if((int)$r['created_at']<$now-$days*86400)throw new Problem('Срок хранения удалённого источника истёк.',409);
    $q=['project_id'=>$c['remote_project'],'source_id'=>$c['remote_source'],'source'=>$c['source_slug'],'service'=>'cajeerworkspace','request_id'=>$r['event_key'],'trace_id'=>$r['correlation_id'],'from'=>gmdate('Y-m-d\TH:i:s\Z',(int)$r['created_at']-1),'to'=>gmdate('Y-m-d\TH:i:s\Z',min($now+1,(int)$r['created_at']+2)),'limit'=>2];
    $read=$transport??__NAMESPACE__.'\\logsHttp';$result=$read($c,'GET','/api/v1/logs?'.http_build_query($q,'','&',PHP_QUERY_RFC3986),null,null);$items=$result['items']??null;
    if(!is_array($items)||!array_is_list($items)||count($items)>2||!array_key_exists('next_cursor',$result)||$result['next_cursor']!==null||count($items)>1)throw new Problem('Logs: неоднозначный результат сверки.',409);
    $remote=null;
    if($items){$x=$items[0];$body=json_decode(logsBody($b,$r),true,32,JSON_THROW_ON_ERROR);if(!is_array($x)||($x['project_id']??null)!==$c['remote_project']||($x['source_id']??null)!==$c['remote_source']||strtotime((string)($x['timestamp']??''))!==(int)$r['created_at'])throw new Problem('Logs: не совпадает квитанция.',409);
        foreach(['source','service','request_id','trace_id','level','message'] as $k)if(($x[$k]??null)!==$body[$k])throw new Problem('Logs: не совпадает квитанция.',409);
        $ctx=$x['context']??null;if(!is_array($ctx))throw new Problem('Logs: не совпадает контекст.',409);ksort($ctx);$expected=$body['context'];ksort($expected);if($ctx!==$expected)throw new Problem('Logs: не совпадает контекст.',409);$remote=logsUuid($x['id']??null);
    }
    return atomic($db,function()use($db,$u,$p,$id,$r,$b,$remote){$u=relActor($db,$u,$p);$fresh=logsBinding($db,$p);$cur=entity($db,'SELECT * FROM logs_outbox WHERE project_id=? AND id=?',[$p,$id]);
        if(!$fresh||!(int)$fresh['active']||$fresh['identity_hash']!==$b['identity_hash']||(int)$fresh['retention_days']!==(int)$b['retention_days']||$cur['event_key']!==$r['event_key']||$cur['state']!==$r['state']||(int)$cur['expires_at']<=time())throw new Problem('Задание изменилось во время сверки.',409);
        logsBoundConfig($fresh);
        if($remote!==null){query($db,"UPDATE logs_outbox SET state='delivered',remote_event=?,error_code='',claim=NULL,lease_until=NULL WHERE id=?",[$remote,$id]);if(relFailures($db,$p,'logs',$id)>0)relOutcome($db,$p,'logs',$id,'recovered','remote_verified');else relJournal($db,$p,'logs',$id,'recovered','remote_verified');}
        else relJournal($db,$p,'logs',$id,'uncertain','not_found',relFailures($db,$p,'logs',$id));
        auditEvent($db,$u,$p,'reliability.verify',$id,$remote!==null?'logs verified':'logs not found');return $remote!==null?'delivered':'not_found';
    });
}
