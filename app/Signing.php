<?php
declare(strict_types=1);
namespace Workspace;
use PDO;
const SIGN_MAX=16777216;
const SIGN_STATES=['queued'=>'В очереди','sending'=>'Отправляется','uncertain'=>'Требуется восстановление ID','pending'=>'Ожидает подписей','partial'=>'Частично подписан','declined'=>'Отклонён','provider_error'=>'Ошибка провайдера','archived'=>'Архивирован','cancelled'=>'Отменён до отправки'];
function signConfig(): array {
    $key=getenv('WORKSPACE_SIGN_API_KEY');$org=getenv('WORKSPACE_SIGN_ORG_ID');$mode=getenv('WORKSPACE_SIGN_TEST_MODE');$account=getenv('WORKSPACE_SIGN_ACCOUNT_EMAIL');
    if(!is_string($key)||strlen($key)<20||strlen($key)>256||preg_match('/[\s:]/',$key)||!is_string($org)||!preg_match('/\A[1-9][0-9]{0,14}\z/',$org)||!in_array($mode,['0','1'],true)||!is_string($account)||!filter_var($account,FILTER_VALIDATE_EMAIL))throw new Problem('Настройте выделенный аккаунт Dropbox Sign, организацию и явный test mode.',503);
    return ['key'=>$key,'org'=>(int)$org,'test'=>(int)$mode,'account'=>strtolower($account)];
}
function signJson(mixed $v): string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
function signDecode(string $s): array {try{$a=json_decode($s,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new Problem('Некорректный JSON Dropbox Sign.',502);}if(!is_array($a))throw new Problem('Некорректный ответ Dropbox Sign.',502);return $a;}
function signId(mixed $id): string {if(!is_string($id)||!preg_match('/\A[a-f0-9]{40}\z/',$id))throw new Problem('Некорректный ID запроса Dropbox Sign.');return $id;}
function signSigners(array $f): array {
    $out=[];foreach([1,2] as $i){$name=word($f['name'.$i]??'',100,'Имя подписанта');$email=strtolower(word($f['email'.$i]??'',254,'Email подписанта'));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new Problem('Проверьте email подписанта.');$out[]=['name'=>$name,'email_address'=>$email];}if($out[0]['email_address']===$out[1]['email_address'])throw new Problem('Требуются два разных подписанта.');return $out;
}
function signPdf(string $bytes,int $max=SIGN_MAX): string {if(strlen($bytes)>$max||!str_starts_with($bytes,'%PDF-')||!str_contains(substr($bytes,-2048),'%%EOF'))throw new Problem('Некорректный PDF Dropbox Sign.',409);return $bytes;}
function signSource(PDO $db,array $r): string {
    prepVerify($db,$r);$b=retentionDocumentBytes($db,(int)$r['document_id'],(int)$r['document_revision']);
    if(!hash_equals($r['document_hash'],hash('sha256',$b)))throw new Problem('Исходный PDF изменён.',409);return signPdf($b,2097152);
}
function signApproved(PDO $db,array $u,int $p,int $id): array {
    $c=prepGet($db,$u,$p,$id,'legal.write');if($c['state']!=='approved')throw new Problem('Требуется согласованная редакция.',409);
    prepRoute($db,$u,$p,(int)$c['editor_id'],(int)$c['lawyer_id'],(int)$c['approver_id']);$r=prepLatest($db,$id);$b=signSource($db,$r);$events=[];
    foreach(['submit'=>'editor_id','legal_accept'=>'lawyer_id','approve'=>'approver_id'] as $decision=>$actor){$e=entity($db,'SELECT * FROM preparation_events WHERE revision_id=? AND decision=? ORDER BY id DESC LIMIT 1',[$r['id'],$decision]);if((int)$e['actor_id']!==(int)$c[$actor]||!hash_equals($e['evidence_hash'],$r['evidence_hash'])||!hash_equals($e['route_snapshot'],prepRouteSnapshot($c)))throw new Problem('Согласования не соответствуют редакции.',409);$events[]=$e;}
    return [$r,$b,$events];
}
function signGet(PDO $db,array $u,int $p,int $id): array {requireProjectRight($db,$u,$p,'legal');return entity($db,'SELECT * FROM signing_packages WHERE project_id=? AND id=?',[$p,$id]);}
function signCreate(PDO $db,array $user,int $p,string $key,array $f,array $cfg): int {
    $u=orgOwner($db,$user);if((int)$u['organization_id']!==$cfg['org'])throw new Problem('Провайдер не подключён к организации.',403);$id=numberId($f['preparation_id']??0);$version=numberId($f['revision_id']??0);$signers=signSigners($f);if(($f['mode']??null)!==(string)$cfg['test'])throw new Problem('Режим отправки изменился. Обновите форму.',409);if(($f['confirm']??'')!=='yes')throw new Problem('Подтвердите передачу PDF и email провайдеру.');
    return action($db,$u,$key,'sign.create',[$p,$id,$version,$signers,$cfg['test'],$cfg['account']],function()use($db,$u,$p,$id,$version,$signers,$cfg){
        [$r,$b,$events]=signApproved($db,$u,$p,$id);if((int)$r['id']!==$version)throw new Problem('Редакция изменилась.',409);if(query($db,'SELECT id FROM signing_packages WHERE revision_id=?',[$version])->fetchColumn())throw new Problem('Пакет для этой редакции уже существует.',409);
        return insertId($db,'INSERT INTO signing_packages(project_id,preparation_id,revision_id,nonce,evidence_hash,signers_json,approval_json,test_mode,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$p,$id,$version,bin2hex(random_bytes(32)),$r['evidence_hash'],signJson($signers),signJson(['account'=>$cfg['account'],'events'=>$events]),$cfg['test'],$u['id'],time(),time()]);
    });
}
// Fixed HTTPS origin, no redirects, no secret in logs; bounded response body.
function signHttp(array $cfg,string $path,?array $form=null,bool $pdf=false): string {
    if(!preg_match('~\A/signature_request/(?:send|[a-f0-9]{40}|files/[a-f0-9]{40}\?file_type=pdf)\z~',$path))throw new \LogicException('Signing API path');
    if(!extension_loaded('curl'))throw new Problem('Требуется cURL.',503);$c=curl_init('https://api.hellosign.com/v3'.$path);$buf='';$max=$pdf?SIGN_MAX:1048576;
    $options=[CURLOPT_USERPWD=>$cfg['key'].':',CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>90,CURLOPT_HTTPHEADER=>['Accept: '.($pdf?'application/pdf':'application/json')],CURLOPT_WRITEFUNCTION=>static function($c,$chunk)use(&$buf,$max){if(strlen($buf)+strlen($chunk)>$max)return 0;$buf.=$chunk;return strlen($chunk);}];
    if($form!==null){$form['files[0]']=new \CURLStringFile($form['files[0]'],'approved.pdf','application/pdf');$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=$form;}curl_setopt_array($c,$options);try{$ok=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE);if($ok===false||$status<200||$status>=300)throw new Problem('Dropbox Sign: '.($status?'HTTP '.$status:'ошибка транспорта'),502);return $buf;}finally{curl_close($c);}
}
function signResponse(string $raw,array $job,array $cfg,?string $expected=null): array {
    $r=signDecode($raw)['signature_request']??null;if(!is_array($r))throw new Problem('Ответ не содержит запроса подписи.',502);$remote=signId($r['signature_request_id']??null);
    if(($expected!==null&&$remote!==$expected)||($r['metadata']['workspace_nonce']??null)!==$job['nonce']||($r['metadata']['workspace_evidence']??null)!==$job['evidence_hash']||($r['test_mode']??null)!==(bool)$job['test_mode']||strtolower((string)($r['requester_email_address']??''))!==$cfg['account'])throw new Problem('Ответ провайдера не соответствует пакету.',409);
    $wanted=signDecode($job['signers_json']);$s=$r['signatures']??null;if(!is_array($s)||count($s)!==count($wanted))throw new Problem('Состав подписантов изменился.',409);$emails=[];$signed=0;$ids=[];
    foreach($s as $v){$email=strtolower((string)($v['signer_email_address']??''));$emails[]=$email;$ids[]=word($v['signature_id']??'',128,'ID подписи');if(($v['status_code']??null)==='signed'&&is_int($v['signed_at']??null)&&$v['signed_at']>0)$signed++;}
    $expectedEmails=array_column($wanted,'email_address');sort($emails);sort($expectedEmails);if($emails!==$expectedEmails||count(array_unique($ids))!==count($ids))throw new Problem('Подписанты не соответствуют пакету.',409);
    foreach(['is_complete','is_declined','has_error'] as $k)if(!is_bool($r[$k]??null))throw new Problem('Неполный статус провайдера.',502);
    if($r['is_complete']&&($r['has_error']||$r['is_declined']||$signed!==count($wanted)))throw new Problem('Противоречивый статус подписания.',409);
    return [$remote,$r['is_complete']?'complete':($r['is_declined']?'declined':($r['has_error']?'provider_error':($signed?'partial':'pending')))];
}
function signJobConfig(PDO $db,array $job,array $cfg): void {
    $org=(int)entity($db,'SELECT organization_id FROM projects WHERE id=?',[$job['project_id']])['organization_id'];
    if($org!==$cfg['org']||signDecode($job['approval_json'])['account']!==$cfg['account']||(int)$job['test_mode']!==$cfg['test'])throw new Problem('Конфигурация аккаунта или режима изменилась.',409);
}
function signClaim(PDO $db,int $id,array $cfg,string $op,?string $remote=null,bool $worker=false): array {
    return atomic($db,function()use($db,$id,$cfg,$op,$remote,$worker){$j=entity($db,'SELECT * FROM signing_packages WHERE id=?',[$id]);signJobConfig($db,$j,$cfg);$now=time();
        if($worker&&((int)$j['next_check']>$now||relFailures($db,(int)$j['project_id'],'sign',$id)>=REL_BUDGET))throw new Problem('Задание пока недоступно.',409);
        if($j['claim']!==null&&(int)$j['lease_until']>$now)throw new Problem('Пакет уже обрабатывается.',409);
        if($j['state']==='sending'){query($db,"UPDATE signing_packages SET state='uncertain',claim=NULL,lease_until=NULL,last_error='Отправка прервана; проверьте Dashboard' WHERE id=?",[$id]);$j['state']='uncertain';}
        // Uncertain sends must never be retried blindly. Only attach a verified existing provider ID.
        if(($op==='send'&&$j['state']!=='queued')||($op==='adopt'&&($j['state']!=='uncertain'||$j['remote_id']!==null))||($op==='sync'&&(!in_array($j['state'],['pending','partial','provider_error'],true)||!$j['remote_id'])))throw new Problem('Операция недоступна в текущем состоянии.',409);
        if($op==='send'){$u=organizationUser($db,['id'=>(int)$j['created_by']],$cfg['org']);orgOwner($db,$u);[$r,$bytes,$events]=signApproved($db,$u,(int)$j['project_id'],(int)$j['preparation_id']);if((int)$r['id']!==(int)$j['revision_id']||$r['evidence_hash']!==$j['evidence_hash'])throw new Problem('Согласованная редакция изменилась.',409);$j['bytes']=$bytes;}
        if(!relRate($db,'sign',$now))throw new Problem('Лимит заданий подписания.',429);
        $j['claim']=bin2hex(random_bytes(24));$j['op']=$op;$j['lookup']=$remote??$j['remote_id'];query($db,'UPDATE signing_packages SET state=?,claim=?,lease_until=?,updated_at=? WHERE id=?',[$op==='send'?'sending':$j['state'],$j['claim'],$now+300,$now,$id]);return $j;
    });
}
function signFinish(PDO $db,array $j,?string $raw,?string $remote,?string $state,?string $pdf,?string $error=null,?string $sendReceipt=null): string {
    return atomic($db,function()use($db,$j,$raw,$remote,$state,$pdf,$error,$sendReceipt){$current=entity($db,'SELECT * FROM signing_packages WHERE id=?',[$j['id']]);if($current['claim']!==$j['claim']||(int)$current['lease_until']<=time())throw new Problem('Аренда обработки истекла.',409);
        $now=time();foreach(array_unique(array_filter([$sendReceipt,$raw],fn($x)=>$x!==null)) as $receipt){$hash=hash('sha256',$receipt);if(!query($db,'SELECT id FROM signing_receipts WHERE package_id=? AND payload_sha256=?',[$j['id'],$hash])->fetchColumn())query($db,'INSERT INTO signing_receipts(package_id,payload_sha256,payload,received_at) VALUES(?,?,?,?)',[$j['id'],$hash,$receipt,$now]);}$next=$error?($remote!==null?'pending':($j['op']==='send'?'uncertain':$current['state'])):($state==='complete'?($pdf!==null?'archived':'pending'):$state);
        if($pdf!==null){signPdf($pdf);query($db,'INSERT INTO signing_archive(package_id,pdf_base64,pdf_sha256,pdf_size,status_json,status_sha256,archived_at) VALUES(?,?,?,?,?,?,?)',[$j['id'],base64_encode($pdf),hash('sha256',$pdf),strlen($pdf),$raw,hash('sha256',$raw),$now]);}
        query($db,'UPDATE signing_packages SET state=?,remote_id=COALESCE(?,remote_id),send_receipt=COALESCE(?,send_receipt),status_receipt=COALESCE(?,status_receipt),last_error=?,claim=NULL,lease_until=NULL,next_check=?,updated_at=? WHERE id=?',[$next,$remote,$sendReceipt,$raw,$error??'', $now+($error?300:60),$now,$j['id']]);if($next!==$current['state']){$org=(int)entity($db,'SELECT organization_id FROM projects WHERE id=?',[$j['project_id']])['organization_id'];auditEvent($db,['organization_id'=>$org,'id'=>$j['created_by']],(int)$j['project_id'],'sign.provider',(int)$j['id'],'worker: '.$current['state'].' -> '.$next);}$p=(int)$j['project_id'];$id=(int)$j['id'];if($error!==null){$outcome=$next==='uncertain'?'uncertain':(relFailures($db,$p,'sign',$id)+1>=REL_BUDGET?'dead':'retry');relOutcome($db,$p,'sign',$id,$outcome,'provider_error');}else relOutcome($db,$p,'sign',$id,'recovered','verified');return $next;
    });
}
function signProcess(PDO $db,int $id,array $cfg,string $op='sync',?string $remote=null,?callable $transport=null,bool $worker=false): string {
    if($transport===null&&!extension_loaded('curl'))throw new Problem('Требуется cURL.',503);if(!in_array($op,['send','sync','adopt'],true))throw new \LogicException('operation');if($op==='adopt')$remote=signId($remote);$j=signClaim($db,$id,$cfg,$op,$remote,$worker);$http=$transport??fn($path,$form=null,$pdf=false)=>signHttp($cfg,$path,$form,$pdf);$raw=null;$rid=null;$state=null;$pdf=null;$sendReceipt=null;
    try{
        if($op==='send'){$form=['files[0]'=>$j['bytes'],'title'=>'Workspace document '.$j['nonce'],'subject'=>'Signature request','test_mode'=>(string)$j['test_mode'],'allow_decline'=>'1','metadata[workspace_nonce]'=>$j['nonce'],'metadata[workspace_evidence]'=>$j['evidence_hash']];foreach(signDecode($j['signers_json']) as $i=>$s)foreach($s as $k=>$v)$form['signers['.$i.']['.$k.']']=$v;$raw=$http('/signature_request/send',$form);}
        else $raw=$http('/signature_request/'.$j['lookup']);
        [$rid,$state]=signResponse($raw,$j,$cfg,$op==='send'?null:$j['lookup']);if($op==='send')$sendReceipt=$raw;
        if($state==='complete'){$pdf=signPdf($http('/signature_request/files/'.$rid.'?file_type=pdf',null,true));$again=$http('/signature_request/'.$rid);[, $check]=signResponse($again,$j,$cfg,$rid);if($check!=='complete')throw new Problem('Статус изменился при скачивании.',409);$raw=$again;}
    }catch(\Throwable $e){$msg=$e instanceof Problem?$e->getMessage():'Ошибка обработки Dropbox Sign.';return signFinish($db,$j,$rid!==null?$raw:null,$rid,$state,null,$msg,$sendReceipt);}
    return signFinish($db,$j,$raw,$rid,$state,$pdf,null,$sendReceipt);
}
function signCallbackVerify(string $raw,string $signature,string $key): array {
    if(strlen($raw)>1048576)throw new Problem('Callback слишком большой.',413);
    // Dropbox Sign Content-Sha256 is base64 of the hexadecimal HMAC, not base64 of raw bytes.
    if(!hash_equals(base64_encode(hash_hmac('sha256',$raw,$key)),$signature))throw new Problem('Неверная подпись callback.',403);
    $data=signDecode($raw);$e=$data['event']??null;if(!is_array($e))throw new Problem('Нет события.',400);
    $t=$e['event_time']??null;$type=$e['event_type']??null;$h=$e['event_hash']??null;
    if((!is_string($t)&&!is_int($t))||!preg_match('/\A[0-9]{1,12}\z/',(string)$t)||!is_string($type)||!preg_match('/\A[a-z_]{1,80}\z/',$type)||!is_string($h)||!hash_equals(hash_hmac('sha256',$t.$type,$key),$h))throw new Problem('Неверный event_hash.',403);
    return $data;
}
function signReceive(PDO $db,string $raw,string $signature,array $cfg): string {
    $data=signCallbackVerify($raw,$signature,$cfg['key']);$e=$data['event'];$r=$data['signature_request']??null;if(!is_array($r))return 'ignored';
    $nonce=$r['metadata']['workspace_nonce']??null;if(!is_string($nonce)||!preg_match('/\A[a-f0-9]{64}\z/',$nonce))return 'ignored';
    $remote=signId($r['signature_request_id']??null);$hash=hash('sha256',$raw);
    return atomic($db,function()use($db,$raw,$signature,$cfg,$r,$e,$nonce,$remote,$hash){$j=query($db,'SELECT * FROM signing_packages WHERE nonce=?',[$nonce])->fetch();if(!$j)return 'ignored';signJobConfig($db,$j,$cfg);
        if($j['state']==='queued'||$j['state']==='cancelled'||($j['remote_id']!==null&&$j['remote_id']!==$remote)||($r['metadata']['workspace_evidence']??null)!==$j['evidence_hash']||($r['test_mode']??null)!==(bool)$j['test_mode']||strtolower((string)($r['requester_email_address']??''))!==$cfg['account'])return 'ignored';
        if(query($db,'SELECT id FROM signing_callbacks WHERE payload_sha256=?',[$hash])->fetchColumn())return 'duplicate';
        query($db,'INSERT INTO signing_callbacks(package_id,payload_sha256,event_type,event_time,payload,content_signature,received_at) VALUES(?,?,?,?,?,?,?)',[$j['id'],$hash,$e['event_type'],(int)$e['event_time'],$raw,$signature,time()]);
        // Only authenticated GET may advance the package. Even a valid callback cannot supply a final status.
        query($db,'UPDATE signing_packages SET next_check=0 WHERE id=?',[$j['id']]);return 'stored';
    });
}
function signWebhook(PDO $db): never {
    if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'multipart/form-data')&&!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/x-www-form-urlencoded'))throw new Problem('Требуется callback form.',415);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>1200000)throw new Problem('Callback слишком большой.',413);$raw=$_POST['json']??null;if(!is_string($raw))throw new Problem('Нет json callback.',400);
    signReceive($db,$raw,$_SERVER['HTTP_CONTENT_SHA256']??'',signConfig());header('Content-Type: text/plain; charset=utf-8');echo 'Hello API Event Received';exit;
}
function signTick(PDO $db,array $cfg,int $limit=10,?callable $transport=null): array {
    if($limit<1||$limit>100)throw new Problem('Лимит 1–100.');
    atomic($db,function()use($db,$cfg){$now=time();foreach(query($db,"SELECT s.id,s.project_id FROM signing_packages s JOIN projects p ON p.id=s.project_id WHERE s.state IN ('pending','partial','provider_error') AND s.claim IS NOT NULL AND s.lease_until<=? AND p.organization_id=?",[$now,$cfg['org']])->fetchAll() as $r){$p=(int)$r['project_id'];$id=(int)$r['id'];relOutcome($db,$p,'sign',$id,relFailures($db,$p,'sign',$id)+1>=REL_BUDGET?'dead':'retry','lease_expired');query($db,'UPDATE signing_packages SET claim=NULL,lease_until=NULL,next_check=? WHERE id=?',[$now,$id]);}foreach(query($db,"SELECT s.id,s.project_id FROM signing_packages s JOIN projects p ON p.id=s.project_id WHERE s.state='sending'  AND s.lease_until<=? AND p.organization_id=?",[$now,$cfg['org']])->fetchAll() as $r)relOutcome($db,(int)$r['project_id'],'sign',(int)$r['id'],'uncertain','lease_expired');query($db,"UPDATE signing_packages SET state='uncertain',claim=NULL,lease_until=NULL,last_error='Прерванная отправка; требуется проверка Dashboard' WHERE state='sending' AND lease_until<=? AND project_id IN (SELECT id FROM projects WHERE organization_id=?)",[$now,$cfg['org']]);});
    $rows=query($db,"SELECT s.id,s.state,s.project_id,s.next_check,s.updated_at FROM signing_packages s JOIN projects p ON p.id=s.project_id WHERE p.organization_id=? AND s.test_mode=? AND s.state IN ('queued','pending','partial','provider_error') AND s.next_check<=? AND (s.claim IS NULL OR s.lease_until<=?) AND NOT EXISTS(SELECT 1 FROM integration_failures f WHERE f.project_id=s.project_id AND f.provider='sign' AND f.object_id=s.id AND f.failures>=?) ORDER BY s.next_check,s.id LIMIT $limit",[$cfg['org'],$cfg['test'],time(),time(),REL_BUDGET])->fetchAll();$out=[];
    foreach($rows as $r){try{$out[$r['id']]=signProcess($db,(int)$r['id'],$cfg,$r['state']==='queued'?'send':'sync',null,$transport,true);}catch(\Throwable $e){$out[$r['id']]='blocked';// avoid starving later jobs; no automatic resend of uncertain requests
        if($e instanceof Problem&&$e->status===429){$out[$r['id']]='rate_limited';break;}atomic($db,function()use($db,$r){$n=query($db,'UPDATE signing_packages SET next_check=?,last_error=? WHERE id=? AND (claim IS NULL OR lease_until<=?) AND state=? AND next_check=? AND updated_at=?',[time()+300,'Проверьте конфигурацию, полномочия или редакцию.', $r['id'],time(),$r['state'],$r['next_check'],$r['updated_at']])->rowCount();if($n){$p=(int)$r['project_id'];$id=(int)$r['id'];$f=relFailures($db,$p,'sign',$id);if($f<REL_BUDGET)relOutcome($db,$p,'sign',$id,$f+1>=REL_BUDGET?'dead':'retry','preflight');}});}}
    return $out;
}
function signCancel(PDO $db,array $user,int $p,int $id): void {
    $u=orgOwner($db,$user);atomic($db,function()use($db,$u,$p,$id){$u=orgOwner($db,$u);$j=signGet($db,$u,$p,$id);if($j['state']==='cancelled')return;if($j['state']!=='queued')throw new Problem('Отмена доступна только до отправки; после отправки используйте кабинет провайдера.',409);query($db,"UPDATE signing_packages SET state='cancelled',updated_at=? WHERE id=?",[time(),$id]);auditEvent($db,$u,$p,'sign.cancel',$id);});
}
function signPage(PDO $db,array $u,?int $p,?int $id,?int $before): array {
    $data=['projects'=>rightProjects($db,$u,'legal'),'selected'=>$p,'packages'=>[],'approved'=>[],'package'=>null,'callbacks'=>[],'configured'=>false];if(!$p)return $data;requireProjectRight($db,$u,$p,'legal');
    try{$cfg=signConfig();$data['configured']=$cfg['org']===(int)$u['organization_id'];$data['test']=$cfg['test'];}catch(Problem $e){}
    $data['packages']=query($db,'SELECT id,preparation_id,revision_id,state,remote_id,test_mode,updated_at FROM signing_packages WHERE project_id=? AND id<? ORDER BY id DESC LIMIT 51',[$p,$before??PHP_INT_MAX])->fetchAll();
    if($u['role']==='owner'&&$data['configured'])$data['approved']=query($db,"SELECT c.id,c.title,r.id revision_id,r.revision FROM preparations c JOIN preparation_revisions r ON r.preparation_id=c.id WHERE c.project_id=? AND c.state='approved' AND r.revision=(SELECT MAX(x.revision) FROM preparation_revisions x WHERE x.preparation_id=c.id) AND NOT EXISTS(SELECT 1 FROM signing_packages s WHERE s.revision_id=r.id) ORDER BY c.id DESC LIMIT 100",[$p])->fetchAll();
    if($id){$data['package']=signGet($db,$u,$p,$id);$data['callbacks']=query($db,'SELECT id,event_type,event_time,payload_sha256,received_at FROM signing_callbacks WHERE package_id=? ORDER BY id DESC LIMIT 100',[$id])->fetchAll();}return $data;
}
function signArchive(PDO $db,array $j): array {
    if($j['state']!=='archived')throw new Problem('Подписанный оригинал ещё не архивирован.',409);$a=entity($db,'SELECT * FROM signing_archive WHERE package_id=?',[$j['id']]);$bytes=retentionSignedBytes($a);
    if($bytes===false||strlen($bytes)!==(int)$a['pdf_size']||!hash_equals($a['pdf_sha256'],hash('sha256',$bytes))||!hash_equals($a['status_sha256'],hash('sha256',$a['status_json'])))throw new Problem('Архив повреждён.',409);return [$a,signPdf($bytes)];
}
function signCallbackRows(PDO $db,int $id,int $last,string $table='signing_callbacks'): iterable {
    if(!in_array($table,['signing_callbacks','signing_receipts'],true))throw new \LogicException('Evidence table');$after=0;while(true){$rows=query($db,"SELECT * FROM $table WHERE package_id=? AND id>? AND id<=? ORDER BY id LIMIT 10",[$id,$after,$last])->fetchAll();if(!$rows)return;foreach($rows as $e){$after=(int)$e['id'];if(!hash_equals($e['payload_sha256'],hash('sha256',$e['payload'])))throw new Problem('Квитанция повреждена.',409);yield $e;}}
}
function signDownload(PDO $db,array $u,int $p,int $id,string $kind): never {
    $j=signGet($db,$u,$p,$id);$last=0;$lastReceipt=0;$r=entity($db,'SELECT * FROM preparation_revisions WHERE id=? AND preparation_id=? AND project_id=?',[$j['revision_id'],$j['preparation_id'],$p]);if(!hash_equals($j['evidence_hash'],$r['evidence_hash']))throw new Problem('Снимок пакета повреждён.',409);
    if($kind==='original'){$bytes=signSource($db,$r);$name='unsigned.pdf';$mime='application/pdf';}
    elseif($kind==='signed'){[, $bytes]=signArchive($db,$j);$name='signed.pdf';$mime='application/pdf';}
    elseif($kind==='evidence'){[$a,$signed]=signArchive($db,$j);$source=signSource($db,$r);$last=(int)query($db,'SELECT COALESCE(MAX(id),0) FROM signing_callbacks WHERE package_id=?',[$id])->fetchColumn();foreach(signCallbackRows($db,$id,$last) as $checked){}$lastReceipt=(int)query($db,'SELECT COALESCE(MAX(id),0) FROM signing_receipts WHERE package_id=?',[$id])->fetchColumn();foreach(signCallbackRows($db,$id,$lastReceipt,'signing_receipts') as $checked){}$name='evidence.json';$mime='application/json';$bytes=signJson(['format'=>'workspace-signing-evidence-v1','provider'=>'Dropbox Sign','package_id'=>$id,'preparation_id'=>(int)$j['preparation_id'],'revision_id'=>(int)$r['id'],'test_mode'=>(bool)$j['test_mode'],'remote_id'=>$j['remote_id'],'nonce'=>$j['nonce'],'evidence_hash'=>$j['evidence_hash'],'signers'=>signDecode($j['signers_json']),'approval'=>signDecode($j['approval_json']),'prepared_text'=>$r['body'],'template'=>prepTemplate($db,$p,(int)$r['template_id']),'source_pdf'=>['sha256'=>$r['document_hash'],'base64'=>base64_encode($source)],'signed_pdf'=>['sha256'=>$a['pdf_sha256'],'base64'=>base64_encode($signed)],'send_receipt'=>$j['send_receipt'],'status_receipt'=>['sha256'=>$a['status_sha256'],'json'=>$a['status_json']],'archived_at'=>(int)$a['archived_at']]);}
    elseif($kind==='receipts'){$bytes=atomic($db,fn()=>signReceiptBundle($db,signGet($db,$u,$p,$id)));$name=($j['test_mode']?'test':'live').'-receipts.json';$mime='application/json';}
    elseif($kind==='receipt'){$bytes=$j['status_receipt']??$j['send_receipt']??throw new Problem('Квитанция ещё не получена.',404);$name='receipt.json';$mime='application/json';}
    else{$event=numberId($kind);$e=entity($db,'SELECT * FROM signing_callbacks WHERE package_id=? AND id=?',[$id,$event]);if(!hash_equals($e['payload_sha256'],hash('sha256',$e['payload'])))throw new Problem('Квитанция повреждена.',409);$bytes=signJson(['payload'=>$e['payload'],'sha256'=>$e['payload_sha256'],'content_signature'=>$e['content_signature'],'received_at'=>(int)$e['received_at']]);$name='callback-'.$event.'.json';$mime='application/json';}
    $stream=fopen('php://temp/maxmemory:2097152','w+b');if(!$stream)throw new Problem('Не удалось подготовить выдачу.',503);$hash=hash_init('sha256');$size=0;$write=function(string $part)use($stream,$hash,&$size){if(fwrite($stream,$part)!==strlen($part))throw new Problem('Не удалось подготовить выдачу.',503);hash_update($hash,$part);$size+=strlen($part);};
    if($kind==='evidence'){$write(substr($bytes,0,-1).',"callbacks":[');$sep='';foreach(signCallbackRows($db,$id,$last) as $e){$write($sep.signJson($e));$sep=',';}$write('],"api_receipts":[');$sep='';foreach(signCallbackRows($db,$id,$lastReceipt,'signing_receipts') as $e){$write($sep.signJson($e));$sep=',';}$write(']}');}else $write($bytes);$digest=hash_final($hash);
    atomic($db,function()use($db,$u,$p,$id,$r,$kind,$digest,$size){requireProjectRight($db,$u,$p,'legal');retentionLogDigest($db,(int)$r['document_id'],(int)$r['document_revision'],$id,(int)$u['id'],null,null,'sign.'.$kind,$digest,$size);auditEvent($db,$u,$p,'sign.download',$id);});header('Content-Type: '.$mime);header('Content-Disposition: attachment; filename="sign-'.$id.'-'.$name.'"');header('Content-Length: '.$size);rewind($stream);fpassthru($stream);fclose($stream);exit;
}
