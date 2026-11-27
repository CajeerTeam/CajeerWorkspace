<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const INCIDENT_CONDITIONS=['servers.sync'=>'Не удалось прочитать Servers','servers.offline'=>'Ресурс Servers offline','aapanel.sync'=>'Не удалось прочитать aaPanel','aapanel.disabled'=>'Сайт отключён','aapanel.missing'=>'Сайт отсутствует в инвентаре','aapanel.cert'=>'Сертификат просрочен'];
function incidentOperator(PDO $db,array $user,int $project): array {
 $u=organizationUser($db,$user);$r=projectRole($db,$u,$project);if(!in_array($r,['owner','manager','operator'],true))throw new Problem('Недостаточно прав для управления инцидентами.',403);return $u;
}
function incidentPerson(PDO $db,int $org,int $project,int $id): array {return incidentOperator($db,organizationUser($db,['id'=>$id],$org),$project);}
function incidentPolicy(PDO $db,int $project): ?array {return query($db,'SELECT * FROM incident_policies WHERE project_id=?',[$project])->fetch()?:null;}
function incidentConfigure(PDO $db,array $user,int $project,int $person,int $minutes,bool $active): void {
 if($minutes<1||$minutes>10080)throw new Problem('Срок реакции: 1–10080 минут.');
 atomic($db,function()use($db,$user,$project,$person,$minutes,$active){$u=orgOwner($db,$user);allowedProject($db,$u,$project);if($active)incidentPerson($db,(int)$u['organization_id'],$project,$person);else entity($db,'SELECT user_id FROM organization_members WHERE organization_id=? AND user_id=?',[$u['organization_id'],$person]);query($db,'INSERT INTO incident_policies(project_id,organization_id,responsible_id,response_minutes,active,updated_at) VALUES(?,?,?,?,?,?) ON CONFLICT(project_id) DO UPDATE SET responsible_id=excluded.responsible_id,response_minutes=excluded.response_minutes,active=excluded.active,updated_at=excluded.updated_at',[$project,$u['organization_id'],$person,$minutes,(int)$active,time()]);auditEvent($db,$u,$project,'incident.policy',$project);});
}
function incidentHash(array $v): string {return hash('sha256',json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));}
function incidentEvent(PDO $db,array $u,int $project,int $id,string $event,array $detail=[]): void {
 query($db,'INSERT INTO incident_events(project_id,incident_id,actor_id,event,detail,created_at) VALUES(?,?,?,?,?,?)',[$project,$id,$u['id'],$event,json_encode($detail,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),time()]);auditEvent($db,$u,$project,'incident.'.$event,$id);
}
// Called only inside atomic(), with trusted normalized source data; no free-form remote payloads.
function incidentAccept(PDO $db,array $u,array $policy,array $s): ?int {
 $p=(int)$policy['project_id'];$org=(int)$policy['organization_id'];$key=$s['key'];$fp=$s['fingerprint'];$t=$s['at'];
 if(query($db,'SELECT 1 FROM incident_signals WHERE project_id=? AND signal_key=?',[$p,$key])->fetchColumn())return null;
 $r=query($db,"SELECT * FROM incidents WHERE project_id=? AND fingerprint=? AND state!='resolved'",[$p,$fp])->fetch();
 if(!$r){$closed=query($db,'SELECT MAX(verification_at) FROM incidents WHERE project_id=? AND fingerprint=? AND state=\'resolved\'',[$p,$fp])->fetchColumn();if($closed!==null&&$closed!==false&&$t<=(int)$closed)return null;
  $now=time();$id=insertId($db,"INSERT INTO incidents(organization_id,project_id,source,fingerprint,condition_code,object_id,environment,release_id,responsible_id,state,severity,detected_at,first_signal_at,last_signal_at,response_due) VALUES(?,?,?,?,?,?,?,?,?,'open',?,?,?,?,?)",[$org,$p,$s['source'],$fp,$s['code'],$s['object'],$s['env'],$s['release'],$policy['responsible_id'],$s['severity'],$now,$t,$t,$now+(int)$policy['response_minutes']*60]);incidentEvent($db,$u,$p,$id,'opened');
 }else{$id=(int)$r['id'];query($db,'UPDATE incidents SET first_signal_at=?,last_signal_at=?,version=version+1,severity=? WHERE id=?',[min($t,(int)$r['first_signal_at']),max($t,(int)$r['last_signal_at']),$s['severity']==='critical'?'critical':$r['severity'],$id]);}
 query($db,'INSERT INTO incident_signals(project_id,incident_id,signal_key,observed_at,evidence) VALUES(?,?,?,?,?)',[$p,$id,$key,$t,json_encode($s['evidence'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)]);incidentEvent($db,$u,$p,$id,'signal',['key'=>$key]);return $id;
}
// Current observation only: never treat stale/unknown data as recovery.
function incidentInfra(PDO $db,array $u,int $project): array {
 $out=[];$now=time();
 $sites=query($db,'SELECT s.*,e.kind,h.version AS host_version,h.sync_token,h.panel_key,h.panel_hash,h.servers_key,h.servers_hash FROM infra_sites s JOIN infra_environments e ON e.id=s.environment_id JOIN infra_hosts h ON h.id=s.host_id WHERE s.organization_id=? AND s.project_id=? ORDER BY s.id',[$u['organization_id'],$project])->fetchAll();
 foreach($sites as $s){if($s['sync_token']!==null)continue;$obs=infraReadObs($db,(int)$s['host_id']);
  foreach(['servers','aapanel'] as $source){$o=$obs[$source]??null;if(!$o||$o['attempt_at']===null)continue;$t=(int)$o['attempt_at'];if($t<$now-INFRA_TTL||$t>$now)continue;
   try{infraConfig($db,$u,$s[$source==='servers'?'servers_key':'panel_key'],$source,$s[$source==='servers'?'servers_hash':'panel_hash']);}catch(Problem){continue;}
   $bad=$o['error']!=='';$checks=[$source.'.sync'=>$bad?'bad':(($o['fresh']??false)?'ok':'unknown')];
   if(!$bad&&($o['fresh']??false)){
    if($source==='servers'){$status=$o['data']['status']??'';$checks['servers.offline']=match($status){'offline'=>'bad','online'=>'ok',default=>'unknown'};}
    else{$found=null;foreach($o['data']['sites']??[] as $site)if((int)$site['id']===(int)$s['remote_id']&&$site['name']===$s['site_name']){$found=$site;break;}
     $checks['aapanel.missing']=$found===null?'bad':'ok';$checks['aapanel.disabled']=match($found['status']??''){ 'disabled'=>'bad','enabled'=>'ok',default=>'unknown'};
     $cert=$found['certificate']['expires']??null;$checks['aapanel.cert']=is_string($cert)&&($found['certificate']['state']??'')==='observed'?($cert<gmdate('Y-m-d',$t)?'bad':'ok'):'unknown';}
   }
   foreach($checks as $code=>$state){$fp=incidentHash([$source,(int)$s['id'],$code,$s['kind']]);$out[$fp]=['source'=>$source,'fingerprint'=>$fp,'key'=>incidentHash([$fp,$t,(int)$s['host_version']]),'code'=>$code,'object'=>(int)$s['id'],'env'=>$s['kind'],'release'=>null,'severity'=>$code==='servers.offline'?'critical':'error','at'=>$t,'state'=>$state,'evidence'=>['host_id'=>(int)$s['host_id'],'site_id'=>(int)$s['id'],'observation_at'=>$t,'condition'=>$code,'result'=>$state]];}
  }
 }return $out;
}
function incidentLogSignal(array $x): array {
 $fp=incidentHash(['logs',$x['event'],$x['object_id'],$x['environment'],$x['release_id'],$x['host_id']]);$t=strtotime($x['timestamp'].' UTC');
 return ['source'=>'logs','fingerprint'=>$fp,'key'=>incidentHash(['logs',$x['request_id']]),'code'=>$x['event'],'object'=>$x['object_id'],'env'=>$x['environment'],'release'=>$x['release_id'],'severity'=>$x['level'],'at'=>$t,'state'=>'bad','evidence'=>['event'=>$x['event'],'object_id'=>$x['object_id'],'release_id'=>$x['release_id'],'environment'=>$x['environment'],'host_id'=>$x['host_id'],'correlation_id'=>$x['correlation_id'],'request_id'=>$x['request_id'],'observed_at'=>$t]];
}
function incidentScan(PDO $db,array $user,int $project,?callable $transport=null): array {
 $u=incidentOperator($db,$user,$project);$policy=incidentPolicy($db,$project);if(!$policy||!(int)$policy['active'])throw new Problem('Включите политику инцидентов.',409);incidentPerson($db,(int)$u['organization_id'],$project,(int)$policy['responsible_id']);
 $remote=[];$errors=[];$more=false;$b=logsBinding($db,$project);
 if($b&&(int)$b['active'])foreach(['error','critical'] as $level)try{$r=logsProjectRows($db,$u,$project,['level'=>$level,'days'=>1],$transport);foreach($r['rows'] as $x)$remote[]=incidentLogSignal($x);$more=$more||$r['more'];}catch(Problem){$errors[]='logs:'.$level;}
 return atomic($db,function()use($db,$u,$project,$policy,$remote,$errors,$more,$b){$u=incidentOperator($db,$u,$project);$fresh=incidentPolicy($db,$project);if(!$fresh||!(int)$fresh['active']||$fresh!=$policy)throw new Problem('Политика изменилась.',409);incidentPerson($db,(int)$u['organization_id'],$project,(int)$fresh['responsible_id']);$current=logsBinding($db,$project);if($current!=$b){$remote=[];$errors[]='logs:binding_changed';}
  $signals=array_merge(array_values(incidentInfra($db,$u,$project)),$remote); $ids=opsDeploymentSignals($db,$u,$fresh);foreach($signals as $s)if($s['state']==='bad'&&opsIncidentSignal($db,$project,$s)){if(($id=incidentAccept($db,$u,$fresh,$s))!==null){$ids[]=$id;opsAutoAssociate($db,$project,$id,$s);}}
  return ['accepted'=>count($ids),'incidents'=>array_values(array_unique($ids)),'errors'=>$errors,'logs_truncated'=>$more,'infra_checks'=>count($signals)-count($remote)];});
}
function incidentRow(PDO $db,array $u,int $p,int $id): array {requireProjectRight($db,$u,$p,'connections');return entity($db,'SELECT * FROM incidents WHERE id=? AND project_id=? AND organization_id=?',[$id,$p,$u['organization_id']]);}
function incidentText(mixed $v): string {if(!is_string($v)||strlen($v)>16000||!preg_match('//u',$v)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$v)||trim($v)==='')throw new Problem('Укажите текст отчёта (до 16000 байт).');return trim($v);}
function incidentLogRecovery(PDO $db,array $u,int $p,array $r,?callable $transport=null): array {
 $pair=['host.servers.error'=>'host.servers.ok','host.aapanel.error'=>'host.aapanel.ok','bots.failed'=>'bots.sent','deploy.failed'=>'deploy.succeeded','deploy.recovery_required'=>'deploy.succeeded'];$expected=$pair[$r['condition_code']]??null;
 if(!$expected)throw new Problem('Для этого сигнала требуется независимая проверка оператором.',409);
 $last=entity($db,'SELECT evidence FROM incident_signals WHERE project_id=? AND incident_id=? ORDER BY observed_at DESC,id DESC LIMIT 1',[$p,$r['id']]);$original=json_decode($last['evidence'],true,16,JSON_THROW_ON_ERROR);$binding=logsBinding($db,$p);
 if(!$binding||!(int)$binding['active'])throw new Problem('Logs не подключён.',409);
 $args=['level'=>LOG_EVENTS[$expected],'days'=>min(7,(int)$binding['retention_days'])];if($original['environment']!==null)$args['environment']=$original['environment'];if($original['release_id']!==null)$args['release']=$original['release_id'];
 $result=logsProjectRows($db,$u,$p,$args,$transport);$best=null;foreach($result['rows'] as $x){$t=strtotime($x['timestamp'].' UTC');if($x['event']!==$expected||$x['object_id']!==$original['object_id']||$x['release_id']!==$original['release_id']||$x['environment']!==$original['environment']||$x['host_id']!==$original['host_id']||$t<=(int)$r['last_signal_at'])continue;if($best===null||$t>$best['at'])$best=['at'=>$t,'evidence'=>['event'=>$x['event'],'request_id'=>$x['request_id'],'correlation_id'=>$x['correlation_id'],'observed_at'=>$t]];}
 if($best===null||$result['more'])throw new Problem('Нет однозначного подтверждающего события в полной выборке. Выполните независимую проверку.',409);
 $args['level']=LOG_EVENTS[$r['condition_code']];$failures=logsProjectRows($db,$u,$p,$args,$transport);if($failures['more'])throw new Problem('Неполная выборка ошибок; требуется независимая проверка.',409);
 foreach($failures['rows'] as $x)if($x['event']===$r['condition_code']&&$x['object_id']===$original['object_id']&&$x['release_id']===$original['release_id']&&$x['environment']===$original['environment']&&$x['host_id']===$original['host_id']&&strtotime($x['timestamp'].' UTC')>=$best['at'])throw new Problem('После подтверждения обнаружен повторный отказ.',409);
 return $best+['binding'=>$binding];
}
function incidentCommand(PDO $db,array $user,int $p,int $id,int $version,string $act,array $in,?callable $transport=null): void {
 $remote=null;if($act==='resolve'&&($in['manual']??'')!=='yes'){$u=incidentOperator($db,$user,$p);$r=incidentRow($db,$u,$p,$id);if((int)$r['version']!==$version||$r['state']!=='acknowledged'||(int)$r['responsible_id']!==(int)$u['id'])throw new Problem('Карточка изменилась или ответственный не подтвердил реакцию.',409);if($r['source']==='logs')$remote=incidentLogRecovery($db,$u,$p,$r,$transport);}
 atomic($db,function()use($db,$user,$p,$id,$version,$act,$in,$remote){$u=incidentOperator($db,$user,$p);$r=incidentRow($db,$u,$p,$id);if((int)$r['version']!==$version||$r['state']==='resolved')throw new Problem('Карточка изменилась или закрыта.',409);$now=time();$detail=[];
  if($act==='assign'){$person=numberId($in['responsible_id']??null);incidentPerson($db,(int)$u['organization_id'],$p,$person);query($db,'UPDATE incidents SET responsible_id=?,version=version+1 WHERE id=?',[$person,$id]);$detail=['from'=>(int)$r['responsible_id'],'to'=>$person];$event='assigned';}
  elseif($act==='link'){$rel=numberId($in['release_id']??null);entity($db,'SELECT id FROM project_releases WHERE project_id=? AND id=?',[$p,$rel]);if($r['release_id']!==null&&(int)$r['release_id']!==$rel)throw new Problem('Связь с выпуском уже зафиксирована.',409);if($r['release_id']!==null)return;query($db,'UPDATE incidents SET release_id=?,version=version+1 WHERE id=?',[$rel,$id]);$detail=['release_id'=>$rel];$event='linked';}
  elseif($act==='ack') {if((int)$r['responsible_id']!==(int)$u['id']||$r['state']!=='open')throw new Problem('Подтвердить реакцию должен назначенный ответственный.',409);query($db,"UPDATE incidents SET state='acknowledged',acknowledged_at=?,version=version+1 WHERE id=?",[$now,$id]);$detail=['late'=>$now>(int)$r['response_due']];$event='acknowledged';}
  elseif($act==='resolve') {if(query($db,"SELECT 1 FROM ops_service_incidents l JOIN deployments d ON d.id=l.deployment_id AND d.project_id=l.project_id WHERE l.project_id=? AND l.incident_id=? AND d.state IN ('running','recovery_required')",[$p,$id])->fetchColumn())throw new Problem('Сначала завершите восстановление задания развёртывания.',409);if($r['state']!=='acknowledged'||(int)$r['responsible_id']!==(int)$u['id'])throw new Problem('Сначала ответственный должен подтвердить реакцию.',409);$report=incidentText($in['report']??null);$verification=incidentText($in['verification']??null);
   if($r['source']!=='logs'){$check=incidentInfra($db,$u,$p)[$r['fingerprint']]??null;if(!$check||$check['state']!=='ok'||$check['at']<=(int)$r['last_signal_at'])throw new Problem('Нужна новая успешная сверка того же источника после сигнала.',409);$mode='source';$at=$check['at'];$evidence=$check['evidence'];}
   else{if($remote!==null){if(logsBinding($db,$p)!=$remote['binding']||$remote['at']<=(int)$r['last_signal_at'])throw new Problem('Сигнал или привязка изменились.',409);$mode='source';$at=$remote['at'];$evidence=$remote['evidence'];}else{if(($in['manual']??'')!=='yes')throw new Problem('Для Logs требуется повторная проверка.',409);$mode='manual';$at=$now;$evidence=['method'=>'operator_attestation'];}}
   query($db,"UPDATE incidents SET state='resolved',resolved_at=?,verification_at=?,verification_mode=?,version=version+1 WHERE id=?",[$now,$at,$mode,$id]);$detail=['report'=>$report,'verification'=>$verification,'mode'=>$mode,'evidence'=>$evidence];$event='resolved';
  }else throw new Problem('Неизвестное действие.');incidentEvent($db,$u,$p,$id,$event,$detail);
 });
}
function incidentPage(PDO $db,array $user,?int $p,array $args): array {
 $u=organizationUser($db,$user);$data=['incidentProjects'=>rightProjects($db,$u,'connections'),'incidentProject'=>$p,'incidentPolicy'=>null,'incidentRows'=>[],'incidentDetail'=>null,'incidentSignals'=>[],'incidentEvents'=>[],'incidentPeople'=>[],'incidentReleases'=>[],'incidentManage'=>false,'incidentOwner'=>$u['role']==='owner','incidentRelease'=>null,'incidentTraceJob'=>null];if($p===null)return $data;requireProjectRight($db,$u,$p,'connections');$data['incidentManage']=in_array(projectRole($db,$u,$p),['owner','manager','operator'],true);$data['incidentPolicy']=incidentPolicy($db,$p);$rel=optionalId($args['release']??'');if($rel!==null)entity($db,'SELECT id FROM project_releases WHERE project_id=? AND id=?',[$p,$rel]);$data['incidentRelease']=$rel;$data['incidentRows']=query($db,'SELECT i.*,u.name AS responsible_name FROM incidents i JOIN users u ON u.id=i.responsible_id WHERE i.project_id=?'.($rel!==null?' AND i.release_id=?':'').' ORDER BY CASE WHEN i.state=\'resolved\' THEN 1 ELSE 0 END,i.response_due,i.id DESC LIMIT 100',$rel!==null?[$p,$rel]:[$p])->fetchAll();
 if($data['incidentManage']){foreach(orgMembers($db,$u) as $m)try{incidentPerson($db,(int)$u['organization_id'],$p,(int)$m['id']);$data['incidentPeople'][(int)$m['id']]=$m['name'];}catch(Problem){}$data['incidentReleases']=array_column(query($db,'SELECT id,tag FROM project_releases WHERE project_id=? ORDER BY id DESC LIMIT 100',[$p])->fetchAll(),'tag','id');}
 if(($id=optionalId($args['id']??''))!==null){$data['incidentDetail']=incidentRow($db,$u,$p,$id);$data['incidentSignals']=query($db,'SELECT * FROM incident_signals WHERE project_id=? AND incident_id=? ORDER BY id DESC LIMIT 100',[$p,$id])->fetchAll();$data['incidentEvents']=query($db,'SELECT * FROM incident_events WHERE project_id=? AND incident_id=? ORDER BY id DESC LIMIT 100',[$p,$id])->fetchAll();$i=$data['incidentDetail'];if($i['source']==='logs'&&$i['release_id']!==null&&in_array($i['condition_code'],['deploy.failed','deploy.recovery_required'],true)){$d=query($db,'SELECT * FROM deployments WHERE project_id=? AND release_id=? AND id=?',[$p,$i['release_id'],$i['object_id']])->fetch();if($d){$t=json_decode($d['target_snapshot'],true,32,JSON_THROW_ON_ERROR);foreach($data['incidentSignals'] as $sig){$ev=json_decode($sig['evidence'],true,16,JSON_THROW_ON_ERROR);if(is_array($t)&&isset($t['kind'],$t['host_id'])&&is_array($ev)&&traceIncidentMatches($i,$d,$t,$ev)){$data['incidentTraceJob']=(int)$d['id'];break;}}}}}return $data;
}
