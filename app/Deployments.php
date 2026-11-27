<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

function deploymentDate(mixed $value): int {
    if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\z/',$value)) throw new Problem('Укажите дату и время UTC.');
    $d=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new \DateTimeZone('UTC'));
    if (!$d || $d->format('Y-m-d\TH:i')!==$value) throw new Problem('Некорректная дата UTC.');
    return $d->getTimestamp();
}
function deploymentTarget(PDO $db,array $user,int $project,int $site): array {
    $u=organizationUser($db,$user); requireProjectRight($db,$u,$project,'operations');
    $s=deploymentIdentity($db,$project,$site,(int)$u['organization_id']);
    if ($s['responsible_id']!==(int)$u['id']) throw new Problem('Деплой доступен только назначенному ответственному с правом operations.',403);
    return $s;
}
function deploymentIdentity(PDO $db,int $project,int $site,int $org): array {
    $s=entity($db,"SELECT s.id site_id,s.project_id,s.host_id,s.remote_id,s.site_name,e.kind,h.asset_uuid,h.workspace_uuid,h.panel_hash,h.servers_hash,o.provider,o.responsible_id FROM infra_sites s JOIN infra_environments e ON e.id=s.environment_id AND e.project_id=s.project_id JOIN infra_hosts h ON h.id=s.host_id AND h.organization_id=s.organization_id JOIN infra_operation_owners o ON o.host_id=h.id AND o.operation='site.deploy' WHERE s.id=? AND s.project_id=? AND s.organization_id=?",[$site,$project,$org]);
    foreach(['site_id','project_id','host_id','remote_id','responsible_id'] as $k) $s[$k]=(int)$s[$k];
    return $s;
}
function deploymentRelease(PDO $db,int $project,int $release): array {
    $r=entity($db,"SELECT r.*,g.active,g.generation FROM project_releases r JOIN github_repositories g ON g.id=r.repository_binding AND g.project_id=r.project_id WHERE r.id=? AND r.project_id=? AND r.state='confirmed'",[$release,$project]);
    if (!(int)$r['active'] || (int)$r['generation']!==(int)$r['repository_generation'] || !is_string($r['evidence']) || !hash_equals((string)$r['evidence_hash'],hash('sha256',$r['evidence']))) throw new Problem('Подтверждение выпуска или подключение GitHub недействительно.',409);
    $e=json_decode($r['evidence'],true,64,JSON_THROW_ON_ERROR);
    if (($e['artifact']['sha256']??null)!==$r['artifact_sha256'] || ($e['artifact']['name']??null)!==$r['artifact_name'] || !is_int($e['artifact']['size']??null) || $e['artifact']['size']<1 || $e['artifact']['size']>268435456) throw new Problem('Некорректный артефакт подтверждённого выпуска.',409);
    $r['artifact_size']=$e['artifact']['size']; return $r;
}
function deploymentCreate(PDO $db,array $user,int $project,string $key,array $in): int {
    if(!preg_match('/\A[a-f0-9]{32}\z/',$key)) throw new Problem('Некорректный ключ формы.');
    $site=numberId($in['site_id']??null); $release=numberId($in['release_id']??null); $start=deploymentDate($in['window_start']??null);$end=deploymentDate($in['window_end']??null);
    return atomic($db,function()use($db,$user,$project,$key,$site,$release,$start,$end){
        $u=organizationUser($db,$user); $target=deploymentTarget($db,$u,$project,$site);
        $old=query($db,'SELECT * FROM deployments WHERE project_id=? AND request_key=?',[$project,$key])->fetch();
        if($old){if((int)$old['actor_id']!==(int)$u['id']||(int)$old['site_id']!==$site||(int)$old['release_id']!==$release||(int)$old['window_start']!==$start||(int)$old['window_end']!==$end)throw new Problem('Ключ формы уже использован.',409);return (int)$old['id'];}
        if($end<=time()||$end<=$start||$end-$start>86400||$start>time()+30*86400)throw new Problem('Окно обслуживания: до 24 часов, начало не далее 30 дней, окончание в будущем.');
        $r=deploymentRelease($db,$project,$release);
        if(query($db,"SELECT 1 FROM deployments WHERE site_id=? AND state IN ('queued','running','recovery_required')",[$site])->fetchColumn())throw new Problem('На сайте уже есть незавершённый деплой.',409);
        $session=(int)entity($db,'SELECT session_version FROM users WHERE id=? AND enabled=1',[$u['id']])['session_version'];
        $id=insertId($db,'INSERT INTO deployments(project_id,site_id,release_id,actor_id,actor_session,provider,target_snapshot,release_hash,request_key,execution_key,window_start,window_end,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[$project,$site,$release,$u['id'],$session,$target['provider'],json_encode($target,JSON_THROW_ON_ERROR),$r['evidence_hash'],$key,bin2hex(random_bytes(32)),$start,$end,time()]);
        auditEvent($db,$u,$project,'deploy.queue',$id);return $id;
    });
}
function deploymentCancel(PDO $db,array $user,int $project,int $id): void {
    atomic($db,function()use($db,$user,$project,$id){$u=organizationUser($db,$user);requireProjectRight($db,$u,$project,'operations');$r=entity($db,'SELECT * FROM deployments WHERE id=? AND project_id=?',[$id,$project]);if($u['role']!=='owner'&&(int)$r['actor_id']!==(int)$u['id'])throw new Problem('Нет права отмены.',403);if($r['state']==='cancelled')return;if($r['state']!=='queued')throw new Problem('Отменить можно только ожидающий деплой.',409);query($db,"UPDATE deployments SET state='cancelled',finished_at=? WHERE id=?",[time(),$id]);auditEvent($db,$u,$project,'deploy.cancel',$id);});
}
function deploymentPage(PDO $db,array $user,int $project): array {
    $u=organizationUser($db,$user);requireProjectRight($db,$u,$project,'connections');$p=allowedProject($db,$u,$project);
    $sites=[];if(roleAllows(projectRole($db,$u,$project),'operations'))foreach(query($db,"SELECT s.id,s.site_name,e.kind FROM infra_sites s JOIN infra_environments e ON e.id=s.environment_id JOIN infra_operation_owners o ON o.host_id=s.host_id AND o.operation='site.deploy' WHERE s.project_id=? AND o.responsible_id=? ORDER BY s.id",[$project,$u['id']])->fetchAll() as $s)$sites[$s['id']]=$s['kind'].' · '.$s['site_name'];
    $releases=array_column(query($db,"SELECT id,tag FROM project_releases WHERE project_id=? AND state='confirmed' ORDER BY id DESC LIMIT 100",[$project])->fetchAll(),'tag','id');
    $rows=query($db,'SELECT d.*,r.tag,r.commit_sha,s.site_name,u.name actor_name FROM deployments d JOIN project_releases r ON r.id=d.release_id JOIN infra_sites s ON s.id=d.site_id JOIN users u ON u.id=d.actor_id WHERE d.project_id=? ORDER BY d.id DESC LIMIT 100',[$project])->fetchAll();
    return ['project'=>$p,'deploymentSites'=>$sites,'deploymentReleases'=>$releases,'deployments'=>$rows,'deploymentUser'=>$u,'deploymentOperate'=>roleAllows(projectRole($db,$u,$project),'operations')];
}
// CLI only. A trusted host has a local target allowlist; no browser-supplied paths or commands.
function deploymentConfig(): string {
    $p=getenv('WORKSPACE_DEPLOY_CONFIG')?:'';
    if($p===''||$p[0]!=='/'||!is_file($p)||is_link($p)||!is_readable($p)||(fileperms($p)&0022)!==0)throw new Problem('Укажите защищённый WORKSPACE_DEPLOY_CONFIG.',503);return $p;
}
function deploymentLocalAllowed(string $path,array $identity): void {
    $raw=file_get_contents($path,false,null,0,65537);if(!is_string($raw)||strlen($raw)>65536)throw new Problem('Некорректная конфигурация исполнителя.',503);
    $cfg=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$expected=$cfg['targets'][(string)$identity['site_id']]['identity']??null;
    if(!is_array($expected))throw new Problem('Сайт не разрешён этому исполнителю.',403);
    ksort($expected);ksort($identity);if($expected!==$identity)throw new Problem('Привязка не соответствует конфигурации исполнителя.',409);
}
function deploymentRun(PDO $db,int $id,bool $recover=false,?callable $executor=null): array {
    $config=$executor===null?deploymentConfig():'';
    $attempt=bin2hex(random_bytes(16));
    $job=atomic($db,function()use($db,$id,$recover,$attempt,$config){
        $d=entity($db,'SELECT * FROM deployments WHERE id=?',[$id]);
        if($recover?!in_array($d['state'],['running','recovery_required'],true):$d['state']!=='queued')throw new Problem('Недопустимое состояние задания.',409);
        $p=entity($db,'SELECT organization_id FROM projects WHERE id=?',[$d['project_id']]);$actor=['id'=>(int)$d['actor_id'],'organization_id'=>(int)$p['organization_id'],'session_version'=>(int)$d['actor_session']];
        $target=json_decode($d['target_snapshot'],true,32,JSON_THROW_ON_ERROR);
        if($config!=='')deploymentLocalAllowed($config,$target);
        if(!$recover){
            if(time()<(int)$d['window_start'])throw new Problem('Окно обслуживания ещё не началось.',409);
            try{
                if(time()>=(int)$d['window_end'])throw new Problem('Окно обслуживания истекло.',409);
                $live=deploymentTarget($db,$actor,(int)$d['project_id'],(int)$d['site_id']);
                if($live!==$target)throw new Problem('Привязка или ответственный изменились.',409);
                $r=deploymentRelease($db,(int)$d['project_id'],(int)$d['release_id']);if(!hash_equals($d['release_hash'],$r['evidence_hash']))throw new Problem('Доказательства выпуска изменились.',409);
            }catch(Problem $e){$result=['state'=>'failed','message'=>$e->getMessage()];query($db,"UPDATE deployments SET state='failed',finished_at=?,result=? WHERE id=?",[time(),json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$id]);auditEvent($db,$actor,(int)$d['project_id'],'deploy.failed',$id);return ['terminal'=>$result];}
        }else{$r=entity($db,'SELECT * FROM project_releases WHERE id=? AND project_id=?',[$d['release_id'],$d['project_id']]);$r['artifact_size']=0;}
        query($db,"UPDATE deployments SET state='running',started_at=COALESCE(started_at,?),attempt=?,finished_at=NULL WHERE id=?",[time(),$attempt,$id]);auditEvent($db,$actor,(int)$d['project_id'],$recover?'deploy.recover':'deploy.start',$id);
        return ['key'=>$d['execution_key'],'target'=>$target,'commit'=>$r['commit_sha'],'digest'=>$r['artifact_sha256'],'size'=>(int)$r['artifact_size'],'not_before'=>(int)$d['window_start'],'deadline'=>(int)$d['window_end']];
    });
    if(isset($job['terminal']))return $job['terminal'];
    try{$result=$executor!==null?$executor($job,$recover):deploymentExecute($config,$job,$recover);if(!is_array($result)||!in_array($result['state']??null,['succeeded','rolled_back','failed','recovery_required'],true))throw new \RuntimeException('Invalid result');
        // Only structured safe fields enter application logs.
        $result=array_intersect_key($result,array_flip(['state','message','before','after','after_digest']));foreach($result as $k=>$v)if(!is_string($v)||strlen($v)>500)throw new \RuntimeException('Invalid result');
        if($result['state']==='succeeded'&&(($result['after']??null)!==$job['commit']||($result['after_digest']??null)!==$job['digest']))throw new \RuntimeException('Deployment identity not confirmed');
    }catch(\Throwable){$result=['state'=>'recovery_required','message'=>'Исполнитель не подтвердил итог. Проверьте локальный журнал и выполните recover.'];}
    atomic($db,function()use($db,$id,$attempt,$result){$d=entity($db,'SELECT * FROM deployments WHERE id=?',[$id]);if($d['state']!=='running'||$d['attempt']!==$attempt)throw new Problem('Попытка уже заменена восстановлением.',409);$p=entity($db,'SELECT organization_id FROM projects WHERE id=?',[$d['project_id']]);query($db,'UPDATE deployments SET state=?,finished_at=?,result=? WHERE id=?',[$result['state'],time(),json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$id]);$actor=['id'=>$d['actor_id'],'organization_id'=>$p['organization_id']];auditEvent($db,$actor,(int)$d['project_id'],'deploy.'.$result['state'],$id);
        if(in_array($result['state'],['failed','recovery_required'],true)){
            // Preserve the deployment result even when the incident policy is temporarily invalid.
            // The incident scan reconciles unprocessed terminal failures independently.
            try{atomic($db,function()use($db,$d,$actor,$id){$project=(int)$d['project_id'];$policy=incidentPolicy($db,$project);if(!$policy||!(int)$policy['active'])return;incidentPerson($db,(int)$actor['organization_id'],$project,(int)$policy['responsible_id']);opsDeploymentSignals($db,$actor,$policy,$id);});}catch(\Throwable){error_log('Workspace: managed deployment incident reconciliation required (job '.$id.')');}
        }});
    return $result;
}
function deploymentExecute(string $config,array $job,bool $recover): array {
    // No shell, bounded stdout, stderr inherited by the operator. The host executor owns its durable journal.
    $python=getenv('WORKSPACE_DEPLOY_PYTHON')?:'/usr/bin/python3';if($python[0]!=='/'||!is_executable($python)||!function_exists('proc_open'))throw new \RuntimeException('Executor unavailable');
    $proc=proc_open([$python,ROOT.'/tools/deploy.py',$recover?'recover':'deploy','--config',$config],[0=>['pipe','r'],1=>['pipe','w'],2=>STDERR],$pipes);
    if(!is_resource($proc))throw new \RuntimeException('Executor unavailable');
    try{ $json=json_encode($job,JSON_THROW_ON_ERROR);if(fwrite($pipes[0],$json)!==strlen($json))throw new \RuntimeException('Executor input');fclose($pipes[0]);$out=stream_get_contents($pipes[1],8193);fclose($pipes[1]);$code=proc_close($proc);$proc=null;if($code!==0||!is_string($out)||strlen($out)>8192)throw new \RuntimeException('Executor failed');return json_decode($out,true,32,JSON_THROW_ON_ERROR); }
    finally{foreach($pipes as $p)if(is_resource($p))fclose($p);if(is_resource($proc)){proc_terminate($proc);proc_close($proc);}}
}
