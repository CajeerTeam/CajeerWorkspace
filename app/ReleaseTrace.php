<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

// A release is not a deployment; a host inventory is not a live application probe.
function traceInventory(array $target,array $obs,int $now,bool $syncing=false): array {
    $out=[];
    foreach(['aapanel','servers'] as $source){
        $o=$obs[$source]??[];$at=$o['success_at']??null;
        $fresh=!$syncing&&($o['fresh']??false)&&$at!==null&&(int)$at<=$now&&(int)$at>=$now-INFRA_TTL;
        $v=['state'=>$fresh?'unknown':'unavailable','observed_at'=>$at===null?null:(int)$at,'data'=>null];
        if($fresh){
            if($source==='aapanel'){
                $matches=array_values(array_filter($o['data']['sites']??[],fn($s)=>is_array($s)&&(int)($s['id']??0)===(int)$target['remote_id']&&($s['name']??null)===$target['site_name']));
                if(count($matches)===1){$s=$matches[0];$v['state']=match($s['status']??null){'enabled'=>'observed','disabled'=>'disabled',default=>'unknown'};$v['data']=['id'=>(int)$s['id'],'name'=>$s['name'],'status'=>$s['status'],'domains'=>$s['domains']??[],'certificate'=>$s['certificate']??null];}
                else $v['state']=count($matches)===0?'missing':'unknown';
            }else{
                $s=$o['data']??[];
                if(($s['id']??null)===$target['asset_uuid']&&($s['workspace_id']??null)===$target['workspace_uuid']){
                    $v['state']=match($s['status']??null){'online'=>'observed','offline'=>'offline',default=>'unknown'};
                    $v['data']=array_intersect_key($s,array_flip(['id','workspace_id','name','type','status','environment','version']));
                    if(($s['environment']??'')!==''&&$s['environment']!==$target['kind'])$v['state']='environment_mismatch';
                }else $v['state']='identity_mismatch';
            }
        }
        $out[$source]=$v;
    }
    return $out;
}
function traceLogMatches(array $x,array $d,array $target): bool {
    return str_starts_with((string)($x['event']??''),'deploy.')&&isset(LOG_EVENTS[$x['event']])
        &&(int)($x['object_id']??0)===(int)$d['id']&&(int)($x['release_id']??0)===(int)$d['release_id']
        &&($x['environment']??null)===$target['kind']&&(int)($x['host_id']??0)===(int)$target['host_id'];
}
function traceReceiptMatches(array $row,array $receipt): bool {
    return is_string($row['request_id']??null)&&preg_match('/\A[a-f0-9]{32}\z/D',$row['request_id'])===1&&is_string($row['correlation_id']??null)&&preg_match('/\A[a-f0-9]{32}\z/D',$row['correlation_id'])===1&&$row['request_id']===($receipt['request_id']??null)&&($row['correlation_id']??null)===($receipt['correlation_id']??null)
        &&($row['event']??null)===($receipt['event']??null)&&($row['environment']??null)===($receipt['environment']??null)&&(int)($row['object_id']??0)===(int)($receipt['object_id']??0)&&(int)($row['release_id']??0)===(int)($receipt['release_id']??0)&&(int)($row['host_id']??0)===(int)($receipt['host_id']??0)&&($row['timestamp']??null)===gmdate('Y-m-d H:i:s',(int)$receipt['created_at']);
}
function traceIncidentMatches(array $i,array $d,array $target,array $evidence): bool {
    return $i['source']==='logs'&&in_array($i['condition_code'],['deploy.failed','deploy.recovery_required'],true)
        &&(int)$i['object_id']===(int)$d['id']&&(int)$i['release_id']===(int)$d['release_id']&&$i['environment']===$target['kind']
        &&($evidence['event']??null)===$i['condition_code']&&(int)($evidence['object_id']??0)===(int)$d['id']
        &&(int)($evidence['release_id']??0)===(int)$d['release_id']&&($evidence['environment']??null)===$target['kind']&&(int)($evidence['host_id']??0)===(int)$target['host_id'];
}
function traceGithubRead(callable $read,array $r,array $e): array {
    $tag=releaseTag($r['tag']);$ref=releaseResolveTag($read,$tag);
    if($ref!==$e['ref']||$ref['commit_sha']!==$r['commit_sha'])throw new Problem('Тег GitHub изменился.',409);
    $remote=$read('/releases/tags/'.rawurlencode($tag));$asset=$e['artifact'];
    if(ghId($remote['id']??null)!==(int)$e['release']['id']||($remote['tag_name']??null)!==$tag||($remote['draft']??null)!==false||($remote['published_at']??null)!==$e['release']['published_at']||hash('sha256',releaseNotes($remote['body']??null))!==$e['release']['changelog_sha256'])throw new Problem('GitHub Release изменился.',409);
    $a=$read('/releases/assets/'.ghId($asset['id']));
    if(ghId($a['id']??null)!==(int)$asset['id']||($a['name']??null)!==$r['artifact_name']||($a['state']??null)!=='uploaded'||($a['digest']??null)!=='sha256:'.$r['artifact_sha256']||($a['size']??null)!==$asset['size'])throw new Problem('Артефакт GitHub изменился.',409);
    if(releaseResolveTag($read,$tag)!==$ref)throw new Problem('Тег изменился во время проверки.',409);
    return ['state'=>'matched','observed_at'=>time(),'ref'=>$ref,'release_id'=>(int)$e['release']['id'],'asset_id'=>(int)$asset['id'],'sha256'=>$r['artifact_sha256']];
}
function traceLocal(PDO $db,array $user,int $p,int $rid,?int $job): array {
    $u=organizationUser($db,$user);requireProjectRight($db,$u,$p,'connections');if(isset($_SESSION['uid']))requireIdentitySession($db,$u);
    $project=allowedProject($db,$u,$p);$r=releaseRow($db,$p,$rid);
    $release=array_intersect_key($r,array_flip(['id','tag','state','commit_sha','artifact_name','artifact_sha256','verified_at','confirmed_at','evidence_hash','repository_generation']));
    $repo=null;$e=null;try{$confirmed=deploymentRelease($db,$p,$rid);$repo=releaseRepo($db,$confirmed);$e=json_decode($confirmed['evidence'],true,64,JSON_THROW_ON_ERROR);if(!isset($e['ref'],$e['release']['id'],$e['artifact']['id'])||($e['repository_id']??null)!==(int)$repo['repository_id']||($e['tag']??null)!==$r['tag']||($e['ref']['commit_sha']??null)!==$r['commit_sha'])$e=null;}catch(Problem){}
    $jobs=query($db,'SELECT d.id,d.site_id,d.state,d.finished_at,s.site_name,e.kind FROM deployments d JOIN infra_sites s ON s.id=d.site_id AND s.project_id=d.project_id JOIN infra_environments e ON e.id=s.environment_id AND e.project_id=s.project_id WHERE d.project_id=? AND d.release_id=? ORDER BY d.id DESC LIMIT 101',[$p,$rid])->fetchAll();
    $more=count($jobs)>100;$jobs=array_slice($jobs,0,100);$job??=isset($jobs[0])?(int)$jobs[0]['id']:null;
    $data=['project'=>$project,'traceRelease'=>$release,'traceRepository'=>$repo?['full_name'=>$repo['full_name'],'repository_id'=>(int)$repo['repository_id']]:null,'traceGithubEvidence'=>$e?['ref'=>$e['ref'],'release'=>$e['release'],'artifact'=>$e['artifact'],'ci_count'=>count($e['ci']['checks']??[])]:null,'traceJobs'=>$jobs,'traceJobsMore'=>$more,'traceJob'=>null,'traceTarget'=>null,'traceCurrent'=>false,'traceInventory'=>[],'traceLogs'=>[],'traceLogsMore'=>false,'traceBinding'=>false,'traceIncidents'=>[],'traceIncidentsMore'=>false,'traceService'=>null,'traceContextIncidents'=>[],'traceContextMore'=>false,'traceObservedAt'=>time(),'traceLive'=>null];
    if($job===null)return $data;
    $d=entity($db,'SELECT * FROM deployments WHERE project_id=? AND release_id=? AND id=?',[$p,$rid,$job]);
    $target=json_decode($d['target_snapshot'],true,32,JSON_THROW_ON_ERROR);
    if(!is_array($target)||(int)($target['project_id']??0)!==$p||(int)($target['site_id']??0)!==(int)$d['site_id']||!isset($target['host_id'],$target['kind'],$target['site_name'],$target['remote_id'],$target['asset_uuid'],$target['workspace_uuid']))throw new Problem('Некорректное доказательство цели деплоя.',409);
    $data['traceJob']=array_intersect_key($d,array_flip(['id','site_id','release_id','state','started_at','finished_at','created_at']));
    $result=json_decode((string)$d['result'],true);$data['traceJob']['result']=is_array($result)?array_intersect_key($result,array_flip(['state','before','after','after_digest'])):null;
    $data['traceTarget']=array_intersect_key($target,array_flip(['site_id','host_id','remote_id','site_name','kind','asset_uuid','workspace_uuid','provider']));
    $latest=query($db,"SELECT id FROM deployments WHERE project_id=? AND site_id=? AND state!='cancelled' ORDER BY id DESC LIMIT 1",[$p,$d['site_id']])->fetchColumn();
    $data['traceJob']['latest_id']=$latest===false?null:(int)$latest;
    $identity=null;try{$identity=deploymentIdentity($db,$p,(int)$d['site_id'],(int)$u['organization_id']);}catch(Problem){}
    $data['traceJob']['identity_matches']=$identity===$target;
    $data['traceJob']['artifact_observed']=$e!==null&&deliveryObserved($r,$d,$target);
    $data['traceCurrent']=$data['traceJob']['artifact_observed']&&$identity===$target&&(int)$latest===$job;
    if($identity===$target){
        $h=infraHost($db,$u,(int)$target['host_id']);$obs=infraReadObs($db,(int)$h['id']);
        foreach(['aapanel','servers'] as $source){$prefix=$source==='aapanel'?'panel':'servers';try{infraConfig($db,$u,$h[$prefix.'_key'],$source,$h[$prefix.'_hash']);}catch(Problem){unset($obs[$source]);}}
        $data['traceInventory']=traceInventory($target,$obs,time(),$h['sync_token']!==null);
    }
    $b=logsBinding($db,$p);if($b&&(int)$b['active']&&(int)$b['organization_id']===(int)$u['organization_id']){
        try{logsBoundConfig($b);$data['traceBinding']=true;}catch(Problem){}
        if($data['traceBinding']){
            $rows=query($db,"SELECT event_key AS request_id,correlation_id,event,created_at,state,remote_event,object_id,release_id,environment,host_id FROM logs_outbox WHERE project_id=? AND organization_id=? AND release_id=? AND object_id=? AND host_id=? AND environment=? AND event LIKE 'deploy.%' AND expires_at>? ORDER BY id DESC LIMIT 101",[$p,$u['organization_id'],$rid,$job,$target['host_id'],$target['kind'],time()])->fetchAll();
            $data['traceLogsMore']=count($rows)>100;$data['traceLogs']=array_slice($rows,0,100);
        }
    }
    // The exact deployment link is distinguished from contextual incidents on the same site.
    $rows=query($db,"SELECT i.* FROM incidents i WHERE i.project_id=? AND i.organization_id=? AND i.source='logs' AND i.release_id=? AND i.object_id=? AND i.environment=? AND i.condition_code IN ('deploy.failed','deploy.recovery_required') ORDER BY i.id DESC LIMIT 101",[$p,$u['organization_id'],$rid,$job,$target['kind']])->fetchAll();
    $data['traceIncidentsMore']=count($rows)>100;
    foreach(array_slice($rows,0,100) as $i){$signals=query($db,'SELECT evidence FROM incident_signals WHERE project_id=? AND incident_id=? ORDER BY id DESC LIMIT 100',[$p,$i['id']])->fetchAll();foreach($signals as $s){$ev=json_decode($s['evidence'],true,16,JSON_THROW_ON_ERROR);if(is_array($ev)&&traceIncidentMatches($i,$d,$target,$ev)){$data['traceIncidents'][]=array_intersect_key($i,array_flip(['id','state','severity','condition_code','detected_at','resolved_at']));break;}}}
    if($identity===$target){
        $data['traceService']=query($db,'SELECT id,name FROM ops_services WHERE project_id=? AND site_id=?',[$p,$d['site_id']])->fetch()?:null;
        $rows=query($db,"SELECT id,state,severity,condition_code,detected_at,resolved_at FROM incidents WHERE project_id=? AND organization_id=? AND source IN ('aapanel','servers') AND object_id=? AND environment=? ORDER BY id DESC LIMIT 101",[$p,$u['organization_id'],$d['site_id'],$target['kind']])->fetchAll();$data['traceContextMore']=count($rows)>100;$data['traceContextIncidents']=array_slice($rows,0,100);
    }
    return $data;
}
function tracePage(PDO $db,array $user,int $p,int $rid,?int $job,string $source='',?GitHubApp $app=null,?callable $transport=null): array {
    if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);
    $data=atomic($db,fn()=>traceLocal($db,$user,$p,$rid,$job));
    if($source==='')return $data;
    if(!in_array($source,['github','logs'],true))throw new Problem('Неизвестный источник.');
    $u=organizationUser($db,$user);authThrottle($db,'release-trace:'.$source.':'.$u['id'],12);authThrottle($db,'release-trace:'.$source.':installation',60);$job=$data['traceJob']===null?null:(int)$data['traceJob']['id'];$live=['source'=>$source,'state'=>'unavailable','observed_at'=>time()];
    try{
        if($source==='github'){
            if(!$data['traceGithubEvidence'])throw new Problem('Нет доказательств GitHub.',409);$r=deploymentRelease($db,$p,$rid);$repo=releaseRepo($db,$r);$e=json_decode($r['evidence'],true,64,JSON_THROW_ON_ERROR);$app??=GitHubApp::configured();$access=$app->access((int)$repo['installation_id'],(int)$repo['repository_id'],true);$start=microtime(true);
            $read=static function(string $path)use($app,$access,$start):array{if(microtime(true)-$start>35||time()>=$access['expires']-30)throw new Problem('Лимит времени проверки.',503);return $app->releaseRead($access,$path);};
            $live=['source'=>$source]+traceGithubRead($read,$r,$e);
        }elseif($job!==null&&$data['traceBinding']){
            $b=logsBinding($db,$p);$t=$data['traceTarget'];$trace=logsCorrelation($b,$rid,$t['kind'],'');$rows=logsProjectRows($db,$u,$p,['release'=>$rid,'environment'=>$t['kind'],'trace'=>$trace,'days'=>min(7,(int)$b['retention_days']),'job'=>$job],$transport);
            $safe=[];foreach($rows['rows'] as $x)if(traceLogMatches($x,$data['traceJob'],$t)){$x['local_receipt']=false;foreach($data['traceLogs'] as $receipt)if(traceReceiptMatches($x,$receipt)){$x['local_receipt']=true;break;}$safe[]=$x;}
            $live=['source'=>$source,'state'=>'observed','observed_at'=>time(),'rows'=>$safe,'more'=>$rows['more'],'days'=>$rows['effective_days']];
        }
    }catch(Problem $ex){$live['state']=$source==='github'&&$ex->status===409?'not_verified':'unavailable';}catch(GitHubFailure){}
    // No DB write transaction is held during external calls. Re-read all local evidence and reject stale responses.
    if(!mfaFresh())throw new Problem('Подтвердите второй фактор повторно.',403);
    $fresh=atomic($db,fn()=>traceLocal($db,$user,$p,$rid,$job));
    foreach(['traceRelease','traceRepository','traceGithubEvidence','traceJob','traceTarget','traceCurrent','traceBinding'] as $k)if($fresh[$k]!==$data[$k])throw new Problem('Связь выпуска изменилась во время проверки. Повторите запрос.',409);
    if($source==='logs'&&$fresh['traceLogs']!==$data['traceLogs'])throw new Problem('Журнал изменился во время проверки. Повторите запрос.',409);
    $fresh['traceLive']=$live;return $fresh;
}
