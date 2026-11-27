<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

// Deliberately closed vocabulary; neither audit detail nor provider payloads are exported.
const LOG_EVENTS = [
 'task.create'=>'info','task.state'=>'info','release.create'=>'info','release.verify'=>'info','release.reverify'=>'info','release.confirm'=>'notice','release.cancel'=>'notice','release.failed'=>'error','release.ci_failed'=>'error',
 'deploy.queue'=>'info','deploy.start'=>'info','deploy.recover'=>'warning','deploy.cancel'=>'notice','deploy.succeeded'=>'notice','deploy.rolled_back'=>'warning','deploy.failed'=>'error','deploy.recovery_required'=>'critical',
 'editorial.create'=>'info','editorial.book'=>'info','editorial.cms'=>'info','editorial.forum'=>'info','editorial.abandon'=>'notice','editorial.cms.failed'=>'error','editorial.forum.failed'=>'error',
 'bots.sent'=>'notice','bots.uncertain'=>'warning','bots.failed'=>'error','bots.cancelled'=>'notice',
 'host.aapanel.ok'=>'info','host.aapanel.error'=>'error','host.servers.ok'=>'info','host.servers.error'=>'error','github.sync.failed'=>'error',
];
const LOG_RETRY_WINDOW=43200; // strictly below the provider's minimum 24h idempotency window
function logsUuid(mixed $v): string {
 if(!is_string($v)||!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D',$v))throw new Problem('Некорректный UUID Logs.',409);return $v;
}
function logsOrigin(mixed $v): string {
 if(!is_string($v)||!preg_match('~\Ahttps://[a-zA-Z0-9.-]+(?::[0-9]{1,5})?/?\z~D',$v))throw new Problem('Logs: требуется HTTPS origin без пути.',409);
 $p=parse_url($v);if(!$p||empty($p['host'])||isset($p['port'])&&($p['port']<1||$p['port']>65535))throw new Problem('Некорректный origin Logs.',409);
 $host=strtolower(rtrim($p['host'],'.'));if(!filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME))throw new Problem('Некорректный hostname Logs.',409);
 return 'https://'.$host.(isset($p['port'])&&$p['port']!==443?':'.$p['port']:'');
}
function logsEnvName(mixed $v): string {if(!is_string($v)||!preg_match('/\A[A-Z_][A-Z0-9_]{0,127}\z/D',$v))throw new Problem('Некорректное имя переменной Logs.',409);return $v;}
function logsConfig(int $org,int $project): array {
 $file=getenv('WORKSPACE_LOGS_TARGETS');if(!$file||!is_file($file)||!is_readable($file)||filesize($file)>262144)throw new Problem('Настройте WORKSPACE_LOGS_TARGETS.',503);
 try{$map=json_decode((string)file_get_contents($file),true,16,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new Problem('Некорректная конфигурация Logs.',503);}
 $c=$map[$org.':'.$project]??null;if(!is_array($c))throw new Problem('Нет разрешённой оператором цели Logs.',409);
 $origin=logsOrigin($c['origin']??null);$remote=logsUuid($c['project_uuid']??null);$source=logsUuid($c['source_uuid']??null);$slug=$c['source_slug']??null;
 if(!is_string($slug)||strlen($slug)>100||!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D',$slug))throw new Problem('Некорректный slug источника Logs.',409);
 $read=logsEnvName($c['read_env']??null);$write=logsEnvName($c['write_env']??null);if($read===$write)throw new Problem('Разделите credentials чтения и записи.',409);
 $kid=isset($c['signing_key_id'])?logsUuid($c['signing_key_id']):null;$sign=isset($c['signing_env'])?logsEnvName($c['signing_env']):null;
 if(($kid===null)!==($sign===null))throw new Problem('Необходимо указать ID и secret v2 вместе.',409);
 return ['origin'=>$origin,'remote_project'=>$remote,'remote_source'=>$source,'source_slug'=>$slug,'read_env'=>$read,'write_env'=>$write,'signing_key_id'=>$kid,'signing_env'=>$sign,'identity_hash'=>hash('sha256',json_encode([$origin,$remote,$source,$slug],JSON_THROW_ON_ERROR))];
}
function logsToken(string $env): string {$v=getenv($env);if(!is_string($v)||strlen($v)<16||strlen($v)>4096||preg_match('/[^\x21-\x7e]/',$v))throw new Problem('Credential Logs не настроен.',503);return $v;}
function logsHeaders(array $c,string $method,string $path,?string $body,?string $key,?int $now=null,?string $nonce=null): array {
 $token=logsToken($c[$method==='GET'?'read_env':'write_env']);$headers=['Accept: application/json','Authorization: Bearer '.$token,'Cache-Control: no-store'];
 if($method==='POST'){
  if($body===null||$key===null||$path!=='/api/v1/ingest')throw new \LogicException('Invalid Logs ingest request');
  $t=(string)($now??time());$n=$nonce??bin2hex(random_bytes(16));$kid=$c['signing_key_id'];$parts=$kid===null?['cajeerlogs-ingest-v1']:['cajeerlogs-ingest-v2',$kid];
  $sig=hash_hmac('sha256',implode("\n",[...$parts,'POST',$path,$t,$n,$key,hash('sha256',$body)]),$kid===null?$token:logsToken($c['signing_env']));
  $headers=[...$headers,'Content-Type: application/json','Idempotency-Key: '.$key,'X-Log-Timestamp: '.$t,'X-Log-Nonce: '.$n,'X-Log-Signature: '.$sig];if($kid!==null)$headers[]='X-Log-Key-Id: '.$kid;
 }return $headers;
}
function logsHttp(array $c,string $method,string $path,?string $body=null,?string $key=null): array {
 $headers=logsHeaders($c,$method,$path,$body,$key);if(!extension_loaded('curl'))throw new Problem('Требуется cURL.',503);
 $ch=curl_init($c['origin'].$path);$raw='';$options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT_MS=>2000,CURLOPT_TIMEOUT_MS=>8000,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'CajeerWorkspace/'.VERSION,CURLOPT_WRITEFUNCTION=>static function($ch,string $part)use(&$raw):int{if(strlen($raw)+strlen($part)>4*1024*1024)return 0;$raw.=$part;return strlen($part);}];if($body!==null)$options[CURLOPT_POSTFIELDS]=$body;curl_setopt_array($ch,$options);
 try{$ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$mime=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);}finally{curl_close($ch);}
 if($ok===false)throw new Problem('Logs: сеть или размер ответа.',503);
 if(!in_array($status,$method==='GET'?[200]:[200,201],true))throw new Problem('Logs: HTTP '.$status,$status>=400&&$status<=599?$status:503);
 if(!preg_match('~^application/(?:json|[a-z0-9.+-]+\+json)(?:\s*;|$)~i',$mime))throw new Problem('Logs: несовместимый ответ.',503);
 try{$r=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new Problem('Logs: несовместимый ответ.',503);}if(!is_array($r)||array_is_list($r))throw new Problem('Logs: несовместимый ответ.',503);return $r;
}
function logsRemotePolicy(array $c,int $days,?callable $transport=null): int {
 $read=$transport??__NAMESPACE__.'\\logsHttp';$r=$read($c,'GET','/api/v1/projects/'.$c['remote_project'].'/sources/'.$c['remote_source'],null,null);
 $d=$r['retention_days']??null;if(($r['id']??null)!==$c['remote_source']||($r['project_id']??null)!==$c['remote_project']||($r['slug']??null)!==$c['source_slug']||($r['status']??null)!=='active'||!is_int($d)||$d<1||$d>$days)throw new Problem('Logs: источник не совпадает, отключён или срок хранения не установлен/превышает политику Workspace.',409);return $d;
}
function logsBinding(PDO $db,int $project): ?array {return query($db,'SELECT * FROM logs_bindings WHERE project_id=?',[$project])->fetch()?:null;}
function logsBoundConfig(array $b): array {$c=logsConfig((int)$b['organization_id'],(int)$b['project_id']);if(!hash_equals($b['identity_hash'],$c['identity_hash']))throw new Problem('Цель Logs изменилась; отправка остановлена.',409);return $c;}
function logsConfigure(PDO $db,array $u,int $project,int $days,bool $active,?callable $transport=null): void {
 $u=orgOwner($db,$u);allowedProject($db,$u,$project);if($days<1||$days>365)throw new Problem('Срок хранения: 1–365 дней.');
 $c=null;if($active){$c=logsConfig((int)$u['organization_id'],$project);if(hash_equals(logsToken($c['write_env']),logsToken($c['read_env'])))throw new Problem('Разделите credentials чтения и записи.',409);logsRemotePolicy($c,$days,$transport);logsRemotePolicy(array_replace($c,['read_env'=>$c['write_env']]),$days,$transport);}
 atomic($db,function()use($db,$u,$project,$days,$active,$c){$u=orgOwner($db,$u);allowedProject($db,$u,$project);$b=logsBinding($db,$project);
  if(!$active&&!$b)return;
  if($b){if($active&&!hash_equals($b['identity_hash'],$c['identity_hash']))throw new Problem('Цель действующей привязки неизменяема.',409);query($db,'UPDATE logs_bindings SET retention_days=?,active=?,updated_at=? WHERE project_id=?',[$days,(int)$active,time(),$project]);}
  else{if(query($db,'SELECT 1 FROM logs_bindings WHERE origin=? AND remote_project=?',[$c['origin'],$c['remote_project']])->fetchColumn())throw new Problem('Удалённый проект Logs уже привязан.',409);
   query($db,'INSERT INTO logs_bindings(project_id,organization_id,origin,remote_project,remote_source,source_slug,identity_hash,namespace,retention_days,active,updated_at) VALUES(?,?,?,?,?,?,?,?,?,1,?)',[$project,$u['organization_id'],$c['origin'],$c['remote_project'],$c['remote_source'],$c['source_slug'],$c['identity_hash'],bin2hex(random_bytes(16)),$days,time()]);}
  // Shortened policy must not retain a longer local copy. Increasing it never extends old rows.
  $now=time();query($db,'UPDATE logs_outbox SET expires_at=created_at+? WHERE project_id=? AND expires_at>created_at+?',[$days*86400,$project,$days*86400]);
  auditEvent($db,$u,$project,'logs.policy',$project,$active?'enabled':'disabled');
 });
}
function logsCorrelation(array $b,?int $release,?string $env,string $key): string {return substr(hash('sha256',$b['namespace'].':'.($release!==null?'release:'.$release:($env!==null?'environment:'.$env:'event:'.$key))),0,32);}
function logsEnqueue(PDO $db,int $org,int $project,string $event,int $object,?string $env=null,?int $release=null,?int $host=null): void {
 if(!isset(LOG_EVENTS[$event])||$object<1)return;$b=logsBinding($db,$project);if(!$b||!(int)$b['active']||(int)$b['organization_id']!==$org)return;
 if($env!==null&&!in_array($env,['dev','staging','prod'],true))return;
 $key=bin2hex(random_bytes(16));$now=time();query($db,'INSERT INTO logs_outbox(project_id,organization_id,event_key,correlation_id,event,object_id,environment,release_id,host_id,created_at,expires_at,available_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$project,$org,$key,logsCorrelation($b,$release,$env,$key),$event,$object,$env,$release,$host,$now,$now+(int)$b['retention_days']*86400,$now]);
}
function logsAudit(PDO $db,array $u,?int $project,string $event,int $object): void {
 if($project===null||!isset(LOG_EVENTS[$event]))return;$b=logsBinding($db,$project);if(!$b||!(int)$b['active'])return;
 $release=$env=$host=null;
 if(str_starts_with($event,'release.'))$release=$object;
 elseif(str_starts_with($event,'deploy.')){$r=query($db,'SELECT d.release_id,e.kind,s.host_id FROM deployments d JOIN infra_sites s ON s.id=d.site_id JOIN infra_environments e ON e.id=s.environment_id WHERE d.id=? AND d.project_id=?',[$object,$project])->fetch();if(!$r)return;$release=(int)$r['release_id'];$env=$r['kind'];$host=(int)$r['host_id'];}
 elseif(str_starts_with($event,'bots.')){$r=query($db,'SELECT release_id FROM bots_messages WHERE id=? AND project_id=?',[$object,$project])->fetch();if(!$r)return;$release=$r['release_id']===null?null:(int)$r['release_id'];}
 elseif(str_starts_with($event,'editorial.')){$r=query($db,'SELECT release_id FROM editorial_releases WHERE id=? AND project_id=?',[$object,$project])->fetch();if(!$r)return;$release=(int)$r['release_id'];}
 logsEnqueue($db,(int)$u['organization_id'],$project,$event,$object,$env,$release,$host);
}
function logsHost(PDO $db,int $org,int $host,string $source,bool $ok): void {
 if(!in_array($source,['aapanel','servers'],true))return;
 foreach(query($db,'SELECT DISTINCT s.project_id,e.kind FROM infra_sites s JOIN infra_environments e ON e.id=s.environment_id JOIN logs_bindings b ON b.project_id=s.project_id AND b.active=1 WHERE s.organization_id=? AND s.host_id=?',[$org,$host])->fetchAll() as $r)logsEnqueue($db,$org,(int)$r['project_id'],'host.'.$source.($ok?'.ok':'.error'),$host,$r['kind'],null,$host);
}
function logsBody(array $b,array $r): string {
 $context=['workspace_event'=>$r['event'],'workspace_object'=>(int)$r['object_id']];$tags=['workspace'];
 foreach(['environment','release_id','host_id'] as $k)if($r[$k]!==null){$context[$k]=$k==='environment'?$r[$k]:(int)$r[$k];}
 if($r['environment']!==null)$tags[]='env:'.$r['environment'];if($r['release_id']!==null)$tags[]='release:'.$r['release_id'];
 return json_encode(['timestamp'=>gmdate('Y-m-d\TH:i:s\Z',(int)$r['created_at']),'source'=>$b['source_slug'],'level'=>LOG_EVENTS[$r['event']],'message'=>$r['event'],'service'=>'cajeerworkspace','trace_id'=>$r['correlation_id'],'request_id'=>$r['event_key'],'context'=>$context,'tags'=>$tags],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
}
function logsPrune(PDO $db,?int $now=null): int {
 $now??=time();return atomic($db,function()use($db,$now){
  foreach(query($db,"SELECT id,project_id FROM logs_outbox WHERE state IN ('pending','sending') AND created_at<=? AND (lease_until IS NULL OR lease_until<=?)",[$now-LOG_RETRY_WINDOW,$now])->fetchAll() as $r)relOutcome($db,(int)$r['project_id'],'logs',(int)$r['id'],'dead','retry_window');
  query($db,"UPDATE logs_outbox SET state='expired',claim=NULL,lease_until=NULL,error_code='retry_window' WHERE state IN ('pending','sending') AND created_at<=? AND (lease_until IS NULL OR lease_until<=?)",[$now-LOG_RETRY_WINDOW,$now]);
  foreach(query($db,"SELECT id,project_id FROM logs_outbox WHERE state IN ('pending','sending') AND attempts>=? AND (lease_until IS NULL OR lease_until<=?)",[REL_BUDGET,$now])->fetchAll() as $r){query($db,"UPDATE logs_outbox SET state='failed',claim=NULL,lease_until=NULL,error_code='retry_budget' WHERE id=?",[$r['id']]);relOutcome($db,(int)$r['project_id'],'logs',(int)$r['id'],'dead','retry_budget');}
  // The reliability journal must not outlive the configured local Logs retention.
  foreach(['integration_journal','integration_failures'] as $table)query($db,"DELETE FROM $table WHERE provider='logs' AND object_id IN (SELECT id FROM logs_outbox WHERE expires_at<=? AND (lease_until IS NULL OR lease_until<=?))",[$now,$now]);
  return query($db,"DELETE FROM logs_outbox WHERE expires_at<=? AND (lease_until IS NULL OR lease_until<=?)",[$now,$now])->rowCount();
 });
}
function logsTick(PDO $db,int $limit=10,?callable $transport=null): array {
 if($limit<1||$limit>100)throw new Problem('Лимит Logs: 1–100.');$send=$transport??__NAMESPACE__.'\\logsHttp';$report=['pruned'=>logsPrune($db),'delivered'=>0,'retry'=>0,'failed'=>0];
 for($i=0;$i<$limit;$i++){
  $claim=atomic($db,function()use($db){$now=time();$r=query($db,"SELECT o.* FROM logs_outbox o JOIN logs_bindings b ON b.project_id=o.project_id AND b.active=1 WHERE o.created_at>? AND o.expires_at>? AND ((o.state='pending' AND o.available_at<=?) OR (o.state='sending' AND o.lease_until<=?)) ORDER BY o.available_at,o.id LIMIT 1",[$now-LOG_RETRY_WINDOW,$now,$now,$now])->fetch();if(!$r||!relRate($db,'logs',$now))return null;
   if($r['state']==='sending')relOutcome($db,(int)$r['project_id'],'logs',(int)$r['id'],'retry','lease_expired');$b=logsBinding($db,(int)$r['project_id']);$token=bin2hex(random_bytes(16));query($db,"UPDATE logs_outbox SET state='sending',claim=?,lease_until=?,attempts=attempts+1 WHERE id=?",[$token,$now+60,$r['id']]);return [$b,array_replace($r,['claim'=>$token,'attempts'=>(int)$r['attempts']+1])];});
  if(!$claim)break;[$b,$r]=$claim;$remote=null;$state='pending';$code='temporary';$attempted=false;
  try{$c=logsBoundConfig($b);logsRemotePolicy(array_replace($c,['read_env'=>$c['write_env']]),(int)$b['retention_days'],$transport);$credential=hash('sha256',logsToken($c['write_env']));
   $ok=atomic($db,function()use($db,$b,$r,$credential){$cur=logsBinding($db,(int)$b['project_id']);$row=query($db,'SELECT * FROM logs_outbox WHERE id=?',[$r['id']])->fetch();if(!$cur||!(int)$cur['active']||!$row||$row['claim']!==$r['claim']||(int)$row['lease_until']<=time()||(int)$row['expires_at']<=time()||(int)$row['created_at']<=time()-LOG_RETRY_WINDOW||(int)$cur['retention_days']!==(int)$b['retention_days'])return false;if($row['credential_hash']!==null&&!hash_equals($row['credential_hash'],$credential))throw new Problem('Credential изменён.',409);query($db,'UPDATE logs_outbox SET credential_hash=? WHERE id=?',[$credential,$r['id']]);return true;});
   if(!$ok)throw new Problem('Привязка изменена.',503);
   $attempted=true;$response=$send($c,'POST','/api/v1/ingest',logsBody($b,$r),'workspace-'.$r['event_key']);
   if(($response['accepted']??null)!==true||($response['received']??null)!==1||!(($response['stored']??null)===1&&($response['replayed']??null)===false||($response['stored']??null)===0&&($response['replayed']??null)===true)||($response['rejected']??null)!==0||!is_array($response['event_ids']??null)||count($response['event_ids'])!==1)throw new Problem('Не подтверждено сохранение.',503);
   $remote=logsUuid($response['event_ids'][0]);$state='delivered';$code='';
  }catch(Problem $e){$code=in_array($e->status,[400,401,403,404,409,413,415,422],true)?'rejected_'.$e->status:'temporary';if(!$attempted&&$e->status===409)$code='preflight_409';if(in_array($e->status,[400,409,413,415,422],true))$state='failed';}catch(\Throwable){$code='temporary';}
  if($state==='pending'&&(int)$r['attempts']>=REL_BUDGET){$state='failed';$code='retry_budget';}
  $saved=atomic($db,function()use($db,$r,$state,$code,$remote){$delay=min(1800,15*(2**min(7,(int)$r['attempts'])));$n=query($db,'UPDATE logs_outbox SET state=?,claim=NULL,lease_until=NULL,remote_event=?,error_code=?,available_at=? WHERE id=? AND claim=?',[$state,$remote,$code,time()+$delay,$r['id'],$r['claim']])->rowCount();if($n)relOutcome($db,(int)$r['project_id'],'logs',(int)$r['id'],match($state){'delivered'=>'recovered','failed'=>'dead',default=>'retry'},$code?:'verified');return $n;});
  if($saved)$report[$state==='pending'?'retry':$state]++;
 }return $report;
}

/** Search exclusively the bound project/source. Never expose provider message/context blobs or cursors verbatim. */
function logsFilters(PDO $db,int $project,array $b,array $args): array {
 $env=$args['environment']??'';if(!is_string($env)||!in_array($env,['','dev','staging','prod'],true))throw new Problem('Неизвестное окружение.');
 $release=optionalId($args['release']??'');if($release!==null)entity($db,'SELECT id FROM project_releases WHERE id=? AND project_id=?',[$release,$project]);
 $trace=$args['trace']??'';if(!is_string($trace)||($trace!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$trace)))throw new Problem('Некорректный correlation ID.');
 $level=$args['level']??'';if(!is_string($level)||!in_array($level,['','info','notice','warning','error','critical'],true))throw new Problem('Некорректный уровень.');
 $days=numberId($args['days']??min(7,(int)$b['retention_days']));if($days>(int)$b['retention_days'])throw new Problem('Диапазон превышает срок хранения.');
 $job=optionalId($args['job']??'');$host=null;
 if($job!==null){$d=entity($db,'SELECT release_id,target_snapshot FROM deployments WHERE project_id=? AND id=?',[$project,$job]);$t=json_decode($d['target_snapshot'],true,32,JSON_THROW_ON_ERROR);if($release===null||(int)$d['release_id']!==$release||$env===''||($t['kind']??null)!==$env)throw new Problem('Задание не относится к выбранному выпуску и окружению.',409);$host=numberId($t['host_id']??null);}
 return ['environment'=>$env,'release'=>$release,'trace'=>$trace,'level'=>$level,'days'=>$days,'job'=>$job,'host'=>$host];
}
function logsSearchQuery(array $c,array $f,int $from,int $now): array {
 $q=['project_id'=>$c['remote_project'],'source_id'=>$c['remote_source'],'source'=>$c['source_slug'],'service'=>'cajeerworkspace','tag'=>'workspace','from'=>gmdate('Y-m-d\TH:i:s\Z',$from),'to'=>gmdate('Y-m-d\TH:i:s\Z',$now+1),'limit'=>50];
 $context=[];if($f['environment']!=='')$context['environment']=$f['environment'];if($f['release']!==null)$context['release_id']=$f['release'];if(($f['job']??null)!==null){$context['workspace_object']=$f['job'];$context['host_id']=$f['host'];}if($context)$q['context']=json_encode($context,JSON_THROW_ON_ERROR);if($f['trace']!=='')$q['trace_id']=$f['trace'];if($f['level']!=='')$q['level']=$f['level'];
 return $q;
}
function logsProjectRows(PDO $db,array $u,int $project,array $args,?callable $transport=null): array {
 $u=organizationUser($db,$u);requireProjectRight($db,$u,$project,'connections');$b=logsBinding($db,$project);if(!$b||!(int)$b['active'])throw new Problem('Logs не подключён.',409);
 $f=logsFilters($db,$project,$b,$args);$c=logsBoundConfig($b);$days=min($f['days'],logsRemotePolicy($c,(int)$b['retention_days'],$transport));$now=time();$from=$now-$days*86400;
 $q=logsSearchQuery($c,$f,$from,$now);
 $read=$transport??__NAMESPACE__.'\\logsHttp';$path='/api/v1/logs?'.http_build_query($q,'','&',PHP_QUERY_RFC3986);$r=$read($c,'GET',$path,null,null);$rows=$r['items']??null;if(!is_array($rows)||!array_is_list($rows)||count($rows)>50)throw new Problem('Logs: несовместимая выборка.',503);
 $safe=[];
 foreach($rows as $x){if(!is_array($x)||($x['project_id']??null)!==$c['remote_project']||($x['source_id']??null)!==$c['remote_source']||($x['source']??null)!==$c['source_slug']||($x['service']??null)!=='cajeerworkspace')continue;
  $ctx=$x['context']??null;if(!is_array($ctx))continue;$event=$ctx['workspace_event']??null;$obj=$ctx['workspace_object']??null;$env=$ctx['environment']??null;$rel=$ctx['release_id']??null;$host=$ctx['host_id']??null;
  if(!is_string($event)||!isset(LOG_EVENTS[$event])||!is_int($obj)||$obj<1||($env!==null&&!in_array($env,['dev','staging','prod'],true))||($rel!==null&&(!is_int($rel)||$rel<1))||($host!==null&&(!is_int($host)||$host<1))||($x['level']??null)!==LOG_EVENTS[$event])continue;
  $key=$x['request_id']??null;$trace=$x['trace_id']??null;if(!is_string($key)||!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!is_string($trace)||!hash_equals(logsCorrelation($b,$rel,$env,$key),$trace))continue;
  $timestamp=$x['timestamp']??null;if(!is_string($timestamp)||!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z\z/D',$timestamp))continue;$t=strtotime($timestamp);if($t===false||$t<$from||$t>$now)continue;
  if(($f['job']!==null&&($obj!==$f['job']||$host!==$f['host']||!str_starts_with($event,'deploy.')))||($f['environment']!==''&&$env!==$f['environment'])||($f['release']!==null&&$rel!==$f['release'])||($f['trace']!==''&&$trace!==$f['trace'])||($f['level']!==''&&LOG_EVENTS[$event]!==$f['level']))continue;
  // Cross-check against our own authoritative release ID (no foreign object references in UI).
  if($rel!==null&&!query($db,'SELECT 1 FROM project_releases WHERE id=? AND project_id=?',[$rel,$project])->fetchColumn())continue;
  $safe[]=['timestamp'=>gmdate('Y-m-d H:i:s',$t),'event'=>$event,'level'=>LOG_EVENTS[$event],'object_id'=>$obj,'environment'=>$env,'release_id'=>$rel,'host_id'=>$host,'correlation_id'=>$trace,'request_id'=>$key];
 }
 // Recheck authorization after HTTP. Fail closed on disabled/changed binding or tightened retention.
 requireProjectRight($db,$u,$project,'connections');$fresh=logsBinding($db,$project);if(!$fresh||!(int)$fresh['active']||$fresh['identity_hash']!==$b['identity_hash']||(int)$fresh['retention_days']!==(int)$b['retention_days'])throw new Problem('Привязка Logs изменилась.',409);
 return ['rows'=>$safe,'more'=>!empty($r['next_cursor']),'effective_days'=>$days,'external_url'=>$c['origin'].$path];
}
function logsPage(PDO $db,array $u,?int $project,array $args,?callable $transport=null): array {
 $u=organizationUser($db,$u);$projects=rightProjects($db,$u,'connections');$data=['logProjects'=>$projects,'logProject'=>$project,'logBinding'=>null,'logFilters'=>null,'logRows'=>[],'logMore'=>false,'logError'=>'','logEffective'=>null,'logQueue'=>[],'logDiagnostics'=>[],'logExternal'=>null,'logReleases'=>[],'logOwner'=>$u['role']==='owner'];
 if($project===null)return $data;requireProjectRight($db,$u,$project,'connections');$b=logsBinding($db,$project);$data['logBinding']=$b;$data['logReleases']=query($db,'SELECT id,tag FROM project_releases WHERE project_id=? ORDER BY id DESC LIMIT 100',[$project])->fetchAll();if(!$b)return $data;
 $f=logsFilters($db,$project,$b,$args);$data['logFilters']=$f;
 $data['logQueue']=query($db,'SELECT state,COUNT(*) AS total FROM logs_outbox WHERE project_id=? AND expires_at>? GROUP BY state',[$project,time()])->fetchAll();
 if($data['logOwner'])$data['logDiagnostics']=query($db,"SELECT event,correlation_id,state,attempts,error_code,created_at FROM logs_outbox WHERE project_id=? AND expires_at>? AND state IN ('pending','failed','expired') AND attempts>0 ORDER BY id DESC LIMIT 30",[$project,time()])->fetchAll();
 if((int)$b['active'])try{$r=logsProjectRows($db,$u,$project,$args,$transport);$data['logRows']=$r['rows'];$data['logMore']=$r['more'];$data['logEffective']=$r['effective_days'];$data['logExternal']=$r['external_url'];}catch(Problem $e){$data['logError']='Не удалось получить разрешённую выборку Logs. Проверьте токены, источник, срок хранения и сеть.';}
 return $data;
}
