<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

// A non-PDF sentinel meets the historical BLOB length constraint without changing old migrations.
const RETENTION_MARKER='CW-OBJECT-1';
function retentionRoot(): string {
    $configured=getenv('WORKSPACE_OBJECT_DIR');
    if(!is_string($configured)||$configured===''||$configured[0]!=='/'||is_link($configured))throw new Problem('Настройте приватный WORKSPACE_OBJECT_DIR.',503);
    $root=realpath($configured);
    if($root===false||!is_dir($root)||!is_readable($root)||!is_writable($root)||($root===realpath(ROOT)||str_starts_with($root,realpath(ROOT).'/'))||(fileperms($root)&0077)!==0)throw new Problem('Каталог объектов должен быть вне приложения, приватным (0700) и доступным исполнителю.',503);
    return $root;
}
function retentionPath(string $key): string {
    if(!preg_match('/\A[a-f0-9]{64}\z/',$key))throw new Problem('Некорректный ключ объекта.',503);
    return retentionRoot().'/'.$key.'.pdf';
}
function retentionObjectRead(string $key,int $size,string $sha): string {
    $path=retentionPath($key); clearstatcache(true,$path);
    if(is_link($path)||!is_file($path)||filesize($path)!==$size)throw new Problem('Объект отсутствует или повреждён.',503);
    $bytes=@file_get_contents($path,false,null,0,$size+1);
    if(!is_string($bytes)||strlen($bytes)!==$size||!hash_equals($sha,hash('sha256',$bytes)))throw new Problem('Контрольная сумма объекта не совпадает.',503);
    return $bytes;
}
function retentionObjectPut(string $bytes,string $sha): string {
    $key=bin2hex(random_bytes(32));$path=retentionPath($key);$tmp=$path.'.tmp';$f=@fopen($tmp,'xb');
    if(!$f)throw new Problem('Не удалось создать объект.',503);
    try { try {if(fwrite($f,$bytes)!==strlen($bytes)||!fflush($f)||!fsync($f))throw new Problem('Не удалось записать объект.',503);}finally{fclose($f);}if(!@link($tmp,$path))throw new Problem('Не удалось опубликовать объект.',503);$dir=@fopen(dirname($path),'r');if(!$dir)throw new Problem('Не удалось открыть каталог объектов.',503);try{if(!fsync($dir))throw new Problem('Не удалось синхронизировать каталог объектов.',503);}finally{fclose($dir);}retentionObjectRead($key,strlen($bytes),$sha);return $key;} finally {@unlink($tmp);}
}
function retentionDocument(PDO $db,int $doc,int $rev): array {
    return entity($db,'SELECT d.id,d.project_id,d.kind,p.organization_id,v.revision,v.sha256,v.created_at,v.object_key,v.original_size,v.storage_state FROM documents d JOIN projects p ON p.id=d.project_id JOIN document_versions v ON v.document_id=d.id WHERE d.id=? AND v.revision=?',[$doc,$rev]);
}
function retentionDocumentBytes(PDO $db,int $doc,int $rev): string {
    $expr=isPostgres($db)?"encode(bytes,'hex')":'hex(bytes)';
    $v=entity($db,"SELECT $expr payload,sha256,storage_state,object_key,original_size FROM document_versions WHERE document_id=? AND revision=?",[$doc,$rev]);
    if($v['storage_state']==='purged')throw new Problem('Срок хранения истёк; файл удалён.',410);
    $raw=hex2bin($v['payload']);
    if($v['storage_state']==='object') {
        if($raw!==RETENTION_MARKER)throw new Problem('Некорректный указатель объекта.',503);
        return retentionObjectRead($v['object_key'],(int)$v['original_size'],$v['sha256']);
    }
    if(!is_string($raw)||!hash_equals($v['sha256'],hash('sha256',$raw)))throw new Problem('Целостность PDF нарушена.',409);
    return $raw;
}
function retentionSignedBytes(array $a): string {
    if(!hash_equals($a['status_sha256'],hash('sha256',$a['status_json'])))throw new Problem('Архив повреждён.',409);$raw=base64_decode($a['pdf_base64'],true);
    if($a['storage_state']==='object') {
        if($raw!==RETENTION_MARKER)throw new Problem('Некорректный указатель архива.',503);
        return retentionObjectRead($a['object_key'],(int)$a['pdf_size'],$a['pdf_sha256']);
    }
    if($raw===false||strlen($raw)!==(int)$a['pdf_size']||!hash_equals($a['pdf_sha256'],hash('sha256',$raw)))throw new Problem('Архив повреждён.',409);
    return $raw;
}
function retentionSignedTarget(PDO $db,int $package): array {
    return entity($db,'SELECT s.project_id,r.document_id,r.document_revision,a.storage_state,a.pdf_size,a.pdf_sha256 FROM signing_packages s JOIN preparation_revisions r ON r.id=s.revision_id JOIN signing_archive a ON a.package_id=s.id WHERE s.id=? AND s.state=?',[$package,'archived']);
}
function retentionOwner(PDO $db,array $u,int $doc,int $rev): array {
    $u=orgOwner($db,$u);$d=retentionDocument($db,$doc,$rev);allowedProject($db,$u,(int)$d['project_id']);return [$u,$d];
}
function retentionPolicy(PDO $db,array $u,array $f): int {
    $country=strtoupper(word($f['country']??'',2,'Страна'));if(!preg_match('/\A[A-Z]{2}\z/',$country))throw new Problem('Укажите двухбуквенный код страны.');
    $kind=choice($f['kind']??'',DOC_KINDS);$days=numberId($f['days']??0);if($days>36525)throw new Problem('Срок: 1–36525 дней.');$basis=word($f['basis']??'',1000,'Основание');
    return atomic($db,function()use($db,$u,$country,$kind,$days,$basis){$u=orgOwner($db,$u);$id=insertId($db,'INSERT INTO retention_policies(organization_id,country,kind,days,basis,created_by,created_at) VALUES(?,?,?,?,?,?,?)',[$u['organization_id'],$country,$kind,$days,$basis,$u['id'],time()]);auditEvent($db,$u,null,'retention.policy',$id);return $id;});
}
function retentionAssign(PDO $db,array $u,int $doc,int $rev,int $policy,?string $starts=null): void {
    $starts=calendarDate($starts??gmdate('Y-m-d'));
    atomic($db,function()use($db,$u,$doc,$rev,$policy,$starts){[$u,$d]=retentionOwner($db,$u,$doc,$rev);$p=entity($db,'SELECT * FROM retention_policies WHERE id=? AND organization_id=? AND kind=?',[$policy,$u['organization_id'],$d['kind']]);$old=query($db,'SELECT p.*,r.starts_on FROM retention_documents r JOIN retention_policies p ON p.id=r.policy_id WHERE r.document_id=?',[$doc])->fetch();if($old && ((int)$p['days']<(int)$old['days']||$p['country']!==$old['country']||$starts<$old['starts_on']))throw new Problem('Нельзя сократить назначенный срок или изменить страну.',409);if($old&&(int)$old['id']===$policy&&$starts===$old['starts_on'])return;
        query($db,'INSERT INTO retention_documents(document_id,organization_id,policy_id,assigned_by,assigned_at,starts_on) VALUES(?,?,?,?,?,?) ON CONFLICT(document_id) DO UPDATE SET policy_id=excluded.policy_id,assigned_by=excluded.assigned_by,assigned_at=excluded.assigned_at,starts_on=excluded.starts_on',[$doc,$u['organization_id'],$policy,$u['id'],time(),$starts]);auditEvent($db,$u,(int)$d['project_id'],'retention.assign',$doc,'policy='.$policy.' start='.$starts);});
}
function retentionHeld(PDO $db,int $doc): bool {return (bool)query($db,'SELECT 1 FROM retention_holds WHERE document_id=? AND released_at IS NULL',[$doc])->fetchColumn();}
function retentionHold(PDO $db,array $u,int $doc,int $rev,string $reason,?int $release=null): void {
    $reason=word($reason,1000,'Основание');atomic($db,function()use($db,$u,$doc,$rev,$reason,$release){[$u,$d]=retentionOwner($db,$u,$doc,$rev);if($release===null){$id=insertId($db,'INSERT INTO retention_holds(document_id,reason,created_by,created_at) VALUES(?,?,?,?)',[$doc,$reason,$u['id'],time()]);}else{$id=$release;$r=query($db,'UPDATE retention_holds SET released_by=?,released_at=?,release_reason=? WHERE id=? AND document_id=? AND released_at IS NULL',[$u['id'],time(),$reason,$id,$doc]);if($r->rowCount()!==1)throw new Problem('Удержание уже снято или не найдено.',409);}auditEvent($db,$u,(int)$d['project_id'],$release===null?'retention.hold':'retention.release',$doc,'hold='.$id);});
}
function retentionReferenced(PDO $db,int $doc,int $rev): bool {
    foreach(['guest_invites'=>'revision','client_deliveries'=>'document_revision','contract_revisions'=>'document_revision','contract_attachments'=>'document_revision','preparation_revisions'=>'document_revision'] as $table=>$column)if(query($db,"SELECT 1 FROM $table WHERE document_id=? AND $column=? LIMIT 1",[$doc,$rev])->fetchColumn())return true;
    return false;
}
function retentionEligible(PDO $db,array $d,?int $now=null): bool {
    if($d['storage_state']==='purged'||retentionHeld($db,(int)$d['id'])||retentionReferenced($db,(int)$d['id'],(int)$d['revision']))return false;
    $p=query($db,'SELECT p.days,r.starts_on FROM retention_documents r JOIN retention_policies p ON p.id=r.policy_id WHERE r.document_id=? AND r.organization_id=? AND p.kind=?',[$d['id'],$d['organization_id'],$d['kind']])->fetch();
    $created=strtotime($d['created_at'].' UTC');$start=$p?strtotime($p['starts_on'].' UTC'):false;return $p!==false&&$created!==false&&$start!==false&&max($start,$created)+(int)$p['days']*86400<=($now??time());
}
function retentionBlobUpdate(PDO $db,int $doc,int $rev,string $bytes,string $state,?string $key,int $size): void {
    $sql='UPDATE document_versions SET bytes='.(isPostgres($db)?"decode(?,'hex')":'?').',storage_state=?,object_key=?,original_size=? WHERE document_id=? AND revision=?';$s=$db->prepare($sql);$s->bindValue(1,isPostgres($db)?bin2hex($bytes):$bytes,isPostgres($db)?PDO::PARAM_STR:PDO::PARAM_LOB);$s->bindValue(2,$state);$s->bindValue(3,$key,$key===null?PDO::PARAM_NULL:PDO::PARAM_STR);$s->bindValue(4,$size,PDO::PARAM_INT);$s->bindValue(5,$doc,PDO::PARAM_INT);$s->bindValue(6,$rev,PDO::PARAM_INT);$s->execute();
}
function retentionMove(PDO $db,array $u,int $doc,int $rev,bool $restore=false,?int $package=null): void {
    atomic($db,function()use($db,$u,$doc,$rev,$restore,$package){[$u,$d]=retentionOwner($db,$u,$doc,$rev);
        if($package!==null){$t=retentionSignedTarget($db,$package);if((int)$t['document_id']!==$doc||(int)$t['document_revision']!==$rev)throw new Problem('Архив не относится к редакции.',404);$a=entity($db,'SELECT * FROM signing_archive WHERE package_id=?',[$package]);if($a['storage_state']===($restore?'inline':'object'))return;$b=retentionSignedBytes($a);$key=$a['object_key'];if(!$restore){if($key!==null)retentionObjectRead($key,strlen($b),$a['pdf_sha256']);else $key=retentionObjectPut($b,$a['pdf_sha256']);}query($db,'UPDATE signing_archive SET pdf_base64=?,object_key=?,storage_state=? WHERE package_id=?',[base64_encode($restore?$b:RETENTION_MARKER),$key,$restore?'inline':'object',$package]);}
        else{if($d['storage_state']==='purged')throw new Problem('Файл удалён.',410);if($d['storage_state']===($restore?'inline':'object'))return;$b=retentionDocumentBytes($db,$doc,$rev);$key=$d['object_key'];if(!$restore){if($key!==null)retentionObjectRead($key,strlen($b),$d['sha256']);else $key=retentionObjectPut($b,$d['sha256']);}retentionBlobUpdate($db,$doc,$rev,$restore?$b:RETENTION_MARKER,$restore?'inline':'object',$key,strlen($b));}
        auditEvent($db,$u,(int)$d['project_id'],$restore?'retention.restore':'retention.offload',$doc,'revision='.$rev.($package?' package='.$package:''));});
}
// First commit the tombstone and revoke links; only then remove the object. A failed unlink is retryable.
function retentionPurge(PDO $db,array $u,int $doc,int $rev): void {
    atomic($db,function()use($db,$u,$doc,$rev){[$u,$d]=retentionOwner($db,$u,$doc,$rev);if($d['storage_state']==='purged')return;if(!retentionEligible($db,$d))throw new Problem('Срок не истёк, действует удержание или есть связанные документы.',409);$b=retentionDocumentBytes($db,$doc,$rev);retentionBlobUpdate($db,$doc,$rev,RETENTION_MARKER,'purged',$d['object_key'],strlen($b));query($db,'UPDATE retention_links SET revoked_at=? WHERE document_id=? AND revision=? AND revoked_at IS NULL',[time(),$doc,$rev]);auditEvent($db,$u,(int)$d['project_id'],'retention.purge',$doc,'revision='.$rev);});
    atomic($db,function()use($db,$u,$doc,$rev){[$u,$d]=retentionOwner($db,$u,$doc,$rev);if($d['storage_state']!=='purged'||$d['object_key']===null||retentionHeld($db,$doc))return;$path=retentionPath($d['object_key']);if(is_link($path))throw new Problem('Некорректный объект очистки.',503);if(file_exists($path)&&!@unlink($path))throw new Problem('Файл помечен удалённым, очистка объекта ожидает повтора.',503);$dir=@fopen(dirname($path),'r');if(!$dir)throw new Problem('Не удалось открыть каталог объектов.',503);try{if(!fsync($dir))throw new Problem('Не удалось синхронизировать удаление.',503);}finally{fclose($dir);}query($db,'UPDATE document_versions SET object_key=NULL WHERE document_id=? AND revision=?',[$doc,$rev]);auditEvent($db,$u,(int)$d['project_id'],'retention.object_delete',$doc,'revision='.$rev);});
}
function retentionLog(PDO $db,int $doc,int $rev,?int $package,?int $actor,?int $guest,?int $link,string $channel,string $bytes): void {
    retentionLogDigest($db,$doc,$rev,$package,$actor,$guest,$link,$channel,hash('sha256',$bytes),strlen($bytes));
}
function retentionLogDigest(PDO $db,int $doc,int $rev,?int $package,?int $actor,?int $guest,?int $link,string $channel,string $sha,int $size): void {
    query($db,'INSERT INTO retention_deliveries(document_id,revision,package_id,actor_id,guest_id,link_id,channel,payload_sha256,bytes_size,issued_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$doc,$rev,$package,$actor,$guest,$link,$channel,$sha,$size,time()]);
}
function retentionLink(PDO $db,array $u,int $doc,int $rev,?int $package,int $minutes,int $uses,string $request): string {
    if($minutes<1||$minutes>10080||$uses<1||$uses>20||!preg_match('/\A[a-f0-9]{32}\z/',$request))throw new Problem('Срок 1–10080 минут, 1–20 выдач.');
    return atomic($db,function()use($db,$u,$doc,$rev,$package,$minutes,$uses,$request){[$u,$d]=retentionOwner($db,$u,$doc,$rev);retentionDocumentBytes($db,$doc,$rev);if($package!==null){$t=retentionSignedTarget($db,$package);if((int)$t['document_id']!==$doc||(int)$t['document_revision']!==$rev)throw new Problem('Архив не относится к редакции.',404);$a=entity($db,'SELECT * FROM signing_archive WHERE package_id=?',[$package]);retentionSignedBytes($a);}if(query($db,'SELECT 1 FROM retention_links WHERE issuer_id=? AND request_key=?',[$u['id'],$request])->fetchColumn())throw new Problem('Ссылка уже создана; при утрате отзовите её и создайте новую.',409);
        $token=bin2hex(random_bytes(32));$now=time();$id=insertId($db,'INSERT INTO retention_links(document_id,revision,package_id,token_hash,issuer_id,request_key,created_at,expires_at,max_uses) VALUES(?,?,?,?,?,?,?,?,?)',[$doc,$rev,$package,hash('sha256',$token),$u['id'],$request,$now,$now+$minutes*60,$uses]);auditEvent($db,$u,(int)$d['project_id'],'retention.link',$id);return $token;});
}
function retentionRevoke(PDO $db,array $u,int $doc,int $rev,int $id): void {
    atomic($db,function()use($db,$u,$doc,$rev,$id){[$u,$d]=retentionOwner($db,$u,$doc,$rev);entity($db,'SELECT id FROM retention_links WHERE id=? AND document_id=? AND revision=?',[$id,$doc,$rev]);query($db,'UPDATE retention_links SET revoked_at=? WHERE id=? AND revoked_at IS NULL',[time(),$id]);auditEvent($db,$u,(int)$d['project_id'],'retention.revoke',$id);});
}
function retentionFindLink(PDO $db,string $token): array {
    if(!preg_match('/\A[a-f0-9]{64}\z/',$token))throw new Problem('Ссылка недействительна.',404);
    $r=query($db,'SELECT * FROM retention_links WHERE token_hash=? AND revoked_at IS NULL AND expires_at>? AND uses<max_uses',[hash('sha256',$token),time()])->fetch();
    if(!$r)throw new Problem('Ссылка недействительна или истекла.',404);
    $d=retentionDocument($db,(int)$r['document_id'],(int)$r['revision']);
    if($d['storage_state']==='purged')throw new Problem('Ссылка недействительна.',404);
    try{orgOwner($db,['id'=>(int)$r['issuer_id'],'organization_id'=>(int)$d['organization_id']]);}catch(Problem){throw new Problem('Ссылка отозвана.',404);}
    return $r;
}
function retentionRedeem(PDO $db,string $token): string {
    if(!preg_match('/\A[a-f0-9]{64}\z/',$token))throw new Problem('Ссылка недействительна.',404);
    return atomic($db,function()use($db,$token){$r=retentionFindLink($db,$token);$doc=(int)$r['document_id'];$rev=(int)$r['revision'];$package=$r['package_id']===null?null:(int)$r['package_id'];if($package!==null){$t=retentionSignedTarget($db,$package);if((int)$t['document_id']!==$doc||(int)$t['document_revision']!==$rev)throw new Problem('Ссылка недействительна.',404);$b=retentionSignedBytes(entity($db,'SELECT * FROM signing_archive WHERE package_id=?',[$package]));}else $b=retentionDocumentBytes($db,$doc,$rev);query($db,'UPDATE retention_links SET uses=uses+1 WHERE id=?',[$r['id']]);retentionLog($db,$doc,$rev,$package,null,null,(int)$r['id'],'link',$b);return $b;});
}
function retentionBatch(PDO $db,array $u,string $mode,int $limit=100,int $min=1048576): array {
    $u=orgOwner($db,$u);if($limit<1||$limit>100||$min<1||$min>16777216||!in_array($mode,['preview','cleanup','objects'],true))throw new Problem('Некорректные параметры обработки.');$out=[];
    $rows=query($db,"SELECT d.id,v.revision,v.storage_state,v.object_key FROM document_versions v JOIN documents d ON d.id=v.document_id JOIN projects p ON p.id=d.project_id WHERE p.organization_id=? ORDER BY d.id,v.revision",[$u['organization_id']]);
    // Materialize only candidate IDs before writes, including for SQLite.
    $candidates=[];while($r=$rows->fetch()){$d=retentionDocument($db,(int)$r['id'],(int)$r['revision']);if($mode==='objects'){if($d['storage_state']!=='inline'||(int)query($db,'SELECT length(bytes) FROM document_versions WHERE document_id=? AND revision=?',[$r['id'],$r['revision']])->fetchColumn()<$min)continue;}elseif(!retentionEligible($db,$d)&&!($d['storage_state']==='purged'&&$d['object_key']!==null&&!retentionHeld($db,(int)$d['id'])))continue;$candidates[]=$r;if(count($candidates)>=$limit)break;}$rows=null;
    foreach($candidates as $r){$doc=(int)$r['id'];$rev=(int)$r['revision'];if($mode==='cleanup')retentionPurge($db,$u,$doc,$rev);elseif($mode==='objects')retentionMove($db,$u,$doc,$rev);$out[]=['document'=>$doc,'revision'=>$rev];}
    if($mode==='objects'&&count($out)<$limit){$signed=query($db,"SELECT s.id,r.document_id,r.document_revision FROM signing_archive a JOIN signing_packages s ON s.id=a.package_id JOIN projects p ON p.id=s.project_id JOIN preparation_revisions r ON r.id=s.revision_id WHERE p.organization_id=? AND a.storage_state='inline' AND a.pdf_size>=? ORDER BY s.id LIMIT ".($limit-count($out)),[$u['organization_id'],$min])->fetchAll();foreach($signed as $r){retentionMove($db,$u,(int)$r['document_id'],(int)$r['document_revision'],false,(int)$r['id']);$out[]=['package'=>(int)$r['id']];}}
    return $out;
}
function retentionGuestBytes(PDO $db,array $g,array $r,string $channel): string {
    return atomic($db,function()use($db,$g,$r,$channel){entity($db,'SELECT id FROM guest_invites WHERE id=? AND revoked_at IS NULL AND access_until>?',[$g['id'],time()]);$doc=(int)$r['document_id'];$rev=(int)$r['revision'];$b=retentionDocumentBytes($db,$doc,$rev);retentionLog($db,$doc,$rev,null,null,(int)$g['id'],null,$channel,$b);return $b;});
}
function retentionPage(PDO $db,array $u,?int $doc,?int $rev,?int $before,?int $logBefore=null): array {
    $u=organizationUser($db,$u);[$scope,$args]=projectRightScope($db,$u,'legal');$owner=$u['role']==='owner';
    $data=['owner'=>$owner,'docs'=>query($db,"SELECT d.id,d.title,d.kind,p.title project_title,MAX(v.revision) revision FROM documents d JOIN projects p ON p.id=d.project_id JOIN document_versions v ON v.document_id=d.id WHERE $scope AND d.id<? GROUP BY d.id,d.title,d.kind,p.title ORDER BY d.id DESC LIMIT 51",[...$args,$before??PHP_INT_MAX])->fetchAll(),'policies'=>$owner?query($db,'SELECT * FROM retention_policies WHERE organization_id=? ORDER BY id DESC LIMIT 250',[$u['organization_id']])->fetchAll():[],'selected'=>null,'linkToken'=>null];
    if($doc!==null&&$rev!==null){$d=retentionDocument($db,$doc,$rev);requireProjectRight($db,$u,(int)$d['project_id'],'legal');$data['selected']=$d;$data['versions']=query($db,'SELECT revision,sha256,storage_state,COALESCE(original_size,length(bytes)) size FROM document_versions WHERE document_id=? ORDER BY revision DESC',[$doc])->fetchAll();$data['policy']=query($db,'SELECT p.*,r.starts_on FROM retention_documents r JOIN retention_policies p ON p.id=r.policy_id WHERE r.document_id=?',[$doc])->fetch()?:null;$data['holds']=query($db,'SELECT * FROM retention_holds WHERE document_id=? ORDER BY id DESC',[$doc])->fetchAll();$data['eligible']=retentionEligible($db,$d);$data['signed']=query($db,'SELECT s.id,s.test_mode,a.storage_state,a.pdf_size FROM signing_packages s JOIN signing_archive a ON a.package_id=s.id JOIN preparation_revisions r ON r.id=s.revision_id WHERE r.document_id=? AND r.document_revision=? ORDER BY s.id DESC',[$doc,$rev])->fetchAll();$data['deliveries']=query($db,'SELECT * FROM retention_deliveries WHERE document_id=? AND revision=? AND id<? ORDER BY id DESC LIMIT 101',[$doc,$rev,$logBefore??PHP_INT_MAX])->fetchAll();$data['links']=$owner?query($db,'SELECT id,package_id,created_at,expires_at,max_uses,uses,revoked_at FROM retention_links WHERE document_id=? AND revision=? ORDER BY id DESC LIMIT 100',[$doc,$rev])->fetchAll():[];if($owner&&isset($_SESSION['retention_link'])){$t=$_SESSION['retention_link'];unset($_SESSION['retention_link']);if($t['org']===(int)$u['organization_id']&&$t['doc']===$doc&&$t['rev']===$rev&&$t['actor']===(int)$u['id'])$data['linkToken']=$t['token'];}}
    return $data;
}
