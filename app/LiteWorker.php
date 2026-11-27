<?php
declare(strict_types=1);
namespace Workspace;
use PDO;
use CajeerEngine\Lite as NativeLite;
use CajeerEngine\Storage\{SiteRepository,SiteState,BackupService};
use CajeerEngine\Admin\PagesManagement;
use CajeerEngine\Update\{OperationLock,OperationFiles};
use CajeerEngine\Operations\RecoveryJournal;
use CajeerEngine\Auth\SessionRegistry;

/** CLI only; runs on the CMS host under a dedicated OS account with native storage permissions. */
function liteNative(array $c,callable $fn): mixed {
    if(PHP_SAPI!=='cli')throw new Problem('Lite worker доступен только CLI.',403);
    $profile=editorialCapability('lite',$c['version']??null,'publish_locked');$root=$c['root'];if(realpath($root)!==$root||!is_dir($root)||is_link($root)||!is_file($root.'/VERSION')||is_link($root.'/VERSION')||trim((string)file_get_contents($root.'/VERSION'))!==$profile['version']||!is_file($root.'/bootstrap.php')||is_link($root.'/bootstrap.php'))throw new Problem('Требуется установленный Lite 1.6.0 без symlink корня.',409);
    // One installation per process: autoloaders must never resolve classes from another root.
    static $loaded=null;if($loaded!==null&&$loaded!==$root)throw new Problem('Один корень Lite на процесс.',409);
    if($loaded===null){require_once $root.'/bootstrap.php';$loaded=$root;}
    if(NativeLite::VERSION!==$profile['version']||SiteState::SCHEMA!==$profile['schema']||!str_starts_with((string)(new \ReflectionClass(NativeLite::class))->getFileName(),$root.'/core/'))throw new Problem('Несовместимое ядро Lite.',409);
    $lock=OperationLock::acquire($root,true);
    try{if(OperationLock::maintenance($root)||RecoveryJournal::pending($root))throw new Problem('В Lite выполняется обслуживание или recovery.',409);
        return $fn(new SiteRepository($root),$root);
    }finally{OperationLock::release($lock);}
}
function liteReadNative(SiteRepository $repo,array $c): array {
    $raw=$repo->raw();if($raw===null)throw new Problem('Lite не установлен.',409);
    $state=json_decode($raw,true,512,JSON_THROW_ON_ERROR);SiteState::validate($state);
    if($state['schema']!==SiteState::SCHEMA||$state['settings']['url']!==$c['origin'])throw new Problem('Схема или origin Lite не совпадает.',409);
    return [$state,hash('sha256',$raw)];
}
function liteContentHash(array $state): string {
    // Native restore deliberately preserves current authentication controls.
    unset($state['password'],$state['identity'],$state['login'],$state['auth_policy']);return hash('sha256',json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}
function liteCatalog(array $state): array {
    $out=[];foreach($state['pages'] as $path=>$page)$out[$path]=['title'=>$page['title'],'published'=>$page['published'],'revision'=>PagesManagement::revision($page),'body'=>$page['body']];return $out;
}
function liteInspect(PDO $db,int $site,int $owner): array {
    $s=entity($db,'SELECT * FROM lite_sites WHERE id=?',[$site]);$u=orgOwner($db,['id'=>$owner,'organization_id'=>$s['organization_id']]);$p=(int)$s['project_id'];allowedProject($db,$u,$p);$c=liteConfig($s);editorialCapability('lite',$c['version'],'inspect');
    return liteNative($c,function($repo)use($db,$site,$u,$p,$c){return $repo->exclusive(function($repo)use($db,$site,$u,$p,$c){[$state,$hash]=liteReadNative($repo,$c);$catalog=liteCatalog($state);$raw=json_encode($catalog,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        return atomic($db,function()use($db,$site,$u,$p,$c,$hash,$catalog,$raw){$u=orgOwner($db,$u);$s=liteSite($db,$u,$p,$site);if(liteConfig($s)['hash']!==$c['hash']||liteBusy($db,$site))throw new Problem('Сайт занят или изменён.',409);query($db,'UPDATE lite_sites SET site_hash=?,catalog=?,inspected_at=? WHERE id=?',[$hash,$raw,time(),$site]);auditEvent($db,$u,$p,'lite.inspect',$site,$hash);return ['site'=>$site,'sha256'=>$hash,'pages'=>count($catalog)];});});});
}
function liteWorkerJob(PDO $db,int $id): array {
    $j=entity($db,'SELECT * FROM lite_jobs WHERE id=?',[$id]);$s=entity($db,'SELECT * FROM lite_sites WHERE id=? AND project_id=?',[$j['site_id'],$j['project_id']]);return [$j,$s,liteConfig($s)];
}
function liteJobActor(PDO $db,array $j,array $s,bool $restore=false): array {
    $u=['id'=>(int)($restore?$j['restore_by']:$j['actor_id']),'organization_id'=>(int)$s['organization_id']];if($restore)return orgOwner($db,$u);$u=liteManager($db,$u,(int)$s['project_id']);if($r=editorialForLiteJob($db,$j))editorialCheck($db,$u,$r);return $u;
}
/** Caller holds DB atomic lock. Do not expose native exception messages (paths, config). */
function liteFinish(PDO $db,array $j,array $s,string $state,string $detail): array {
    query($db,'UPDATE lite_jobs SET state=?,updated_at=?,detail=? WHERE id=?',[$state,time(),$detail,$j['id']]);
    query($db,'UPDATE lite_sites SET catalog=NULL,site_hash=NULL,inspected_at=NULL WHERE id=?',[$s['id']]);
    $actor=(int)(in_array($j['state'],['restore_queued','restoring','restore_uncertain'],true)?$j['restore_by']:$j['actor_id']);
    auditEvent($db,['id'=>$actor,'organization_id'=>$s['organization_id']],(int)$s['project_id'],'lite.'.$state,(int)$j['id'],$detail);
    if($r=editorialForLiteJob($db,$j)){$url=$state==='published'?$s['origin'].$j['path']:null;if($url!==null&&$r['cms_url']===null)query($db,'UPDATE editorial_releases SET cms_url=?,cms_confirmed_at=? WHERE id=?',[$url,time(),$r['id']]);editorialEvent($db,['id'=>$actor,'organization_id'=>$s['organization_id']],$r,'cms',$state,$url??'');}
    return ['id'=>(int)$j['id'],'state'=>$state,'detail'=>$detail];
}
function liteCandidate(PDO $db,array $j,array $s,array $u,array $state): array {
    if(!hash_equals($j['payload_hash'],hash('sha256',$j['payload'])))throw new Problem('Повреждён снимок публикации.',409);
    $payload=json_decode($j['payload'],true,64,JSON_THROW_ON_ERROR);$path=litePath($j['path']);
    if($j['kind']==='draft'){
        if(!isset($state['pages'][$path])||$state['pages'][$path]['published']||$payload!==['revision'=>$j['page_revision']])throw new Problem('Черновик изменён.',409);
        return PagesManagement::status($state,$path,$j['page_revision'],true);
    }
    if($payload!==liteBook($db,$u,(int)$j['project_id'],(int)$j['edition_id'],$payload['key']))throw new Problem('Снимок Book изменён.',409);
    if(isset($state['pages'][$path])||count($state['pages'])>=100)throw new Problem('Путь занят или лимит страниц.',409);
    $page=NativeLite::page(['path'=>$path,'title'=>$payload['title'],'description'=>'','body'=>$payload['body'],'published'=>'1','menu'=>'0']);
    // Preserve exact reviewed Markdown bytes. Lite's stock template escapes plain text.
    $page['body']=$payload['body'];if($page['title']!==$payload['title'])throw new Problem('Заголовок Book неканонический.',422);
    $state['pages'][$path]=$page;return $state;
}
function liteBackupHash(string $root,BackupService $backup,string $name): string {
    $backup->verify($name);return OperationFiles::hash(OperationFiles::path($root,'storage/backups/'.$name));
}
function litePublish(PDO $db,int $id,?callable $fault=null): array {
    [$j,$s,$c]=liteWorkerJob($db,$id);
    return liteNative($c,function($repo,$root)use($db,$id,$c,$fault){
        [$j,$s,$refusal]=atomic($db,function()use($db,$id,$c){[$j,$s,$fresh]=liteWorkerJob($db,$id);if($fresh['hash']!==$c['hash']||$j['state']!=='queued')throw new Problem('Пакет не ожидает публикации.',409);try{liteJobActor($db,$j,$s);}catch(Problem){return [$j,$s,liteFinish($db,$j,$s,'aborted','authorization or review revoked before native write')];}query($db,"UPDATE lite_jobs SET state='running',updated_at=? WHERE id=?",[time(),$id]);$j['state']='running';return [$j,$s,null];});if($refusal!==null)return $refusal;
        try{return $repo->exclusive(function($repo)use($db,$id,$c,$root,$j,$s,$fault){[$state,$hash]=liteReadNative($repo,$c);
            if(!hash_equals($j['expected_hash'],$hash))return atomic($db,fn()=>liteFinish($db,$j,$s,'conflict','site changed since inspection'));
            // Compute expected result and validate using native schema before backup or commit.
            $next=atomic($db,function()use($db,$j,$s,$state){$u=liteJobActor($db,$j,$s);return liteCandidate($db,$j,$s,$u,$state);});
            $after=hash('sha256',SiteState::encode($next));$backup=new BackupService($repo);$name=$backup->createLocked();$backupHash=liteBackupHash($root,$backup,$name);
            // Persist recovery evidence BEFORE the filesystem mutation, while holding native site.lock.
            atomic($db,function()use($db,$id,$c,$name,$backupHash,$state,$next,$after){[$j,$s,$fresh]=liteWorkerJob($db,$id);if($j['state']!=='running'||$fresh['hash']!==$c['hash'])throw new Problem('Пакет изменился.',409);liteJobActor($db,$j,$s);query($db,'UPDATE lite_jobs SET backup_name=?,backup_hash=?,before_content=?,after_hash=?,after_content=?,updated_at=? WHERE id=?',[$name,$backupHash,liteContentHash($state),$after,liteContentHash($next),time(),$id]);});
            if($fault)$fault('before_commit');
            return atomic($db,function()use($db,$id,$c,$repo,$next,$state,$after,$fault){[$j,$s,$fresh]=liteWorkerJob($db,$id);if($j['state']!=='running'||$fresh['hash']!==$c['hash'])throw new Problem('Пакет изменился.',409);$u=liteJobActor($db,$j,$s);if(liteCandidate($db,$j,$s,$u,$state)!==$next)throw new Problem('Пакет изменился.',409);
                $repo->commit($next);if($fault)$fault('after_commit');[, $actual]=liteReadNative($repo,$c);if(!hash_equals($after,$actual))throw new Problem('Нарушена целостность записи.',409);return liteFinish($db,$j,$s,'published','native commit verified');});
        });}catch(\Throwable $e){return atomic($db,function()use($db,$id){[$j,$s]=liteWorkerJob($db,$id);if($j['state']!=='running')throw new Problem('Состояние публикации изменилось.',409);return liteFinish($db,$j,$s,$j['backup_name']?'uncertain':'aborted',$j['backup_name']?'inspect before retry; no automatic writes':'no native commit attempted');});}
    });
}
/** Restores entire native site JSON only. Operator MUST stop all CMS writers and keep them stopped. */
function liteRestore(PDO $db,int $id,bool $offline): array {
    if(!$offline)throw new Problem('Остановите все writers Lite и передайте --offline.',409);
    [$j,$s,$c]=liteWorkerJob($db,$id);editorialCapability('lite',$c['version'],'restore_offline');
    return liteNative($c,function($repo,$root)use($db,$id,$c){
        [$j,$s]=atomic($db,function()use($db,$id,$c){[$j,$s,$fresh]=liteWorkerJob($db,$id);if($fresh['hash']!==$c['hash']||$j['state']!=='restore_queued')throw new Problem('Восстановление не подтверждено владельцем.',409);liteJobActor($db,$j,$s,true);allowedProject($db,liteJobActor($db,$j,$s,true),(int)$s['project_id']);query($db,"UPDATE lite_jobs SET state='restoring',updated_at=? WHERE id=?",[time(),$id]);$j['state']='restoring';return [$j,$s];});
        $attempted=false;
        try{return atomic($db,function()use($db,$id,$repo,$root,$c,&$attempted){[$j,$s,$fresh]=liteWorkerJob($db,$id);if($j['state']!=='restoring'||$fresh['hash']!==$c['hash'])throw new Problem('Пакет изменился.',409);liteJobActor($db,$j,$s,true);
            // Restore itself acquires site.lock and revokes sessions. Offline requirement covers non-cooperating CLI writers.
            [, $current]=$repo->exclusive(fn($r)=>liteReadNative($r,$c));if(!hash_equals($j['after_hash'],$current))return liteFinish($db,$j,$s,'published','restore refused: later site change');
            $backup=new BackupService($repo);if(!hash_equals($j['backup_hash'],liteBackupHash($root,$backup,$j['backup_name'])))throw new Problem('Снимок повреждён.',409);
            $attempted=true;$backup->restore($j['backup_name']);[$state]=$repo->exclusive(fn($r)=>liteReadNative($r,$c));if(!hash_equals($j['before_content'],liteContentHash($state)))throw new Problem('Восстановленная версия не совпадает.',409);
            return liteFinish($db,$j,$s,'restored','native backup restored; content verified; sessions revoked');});
        }catch(\Throwable){return atomic($db,function()use($db,$id,$attempted){[$j,$s]=liteWorkerJob($db,$id);return liteFinish($db,$j,$s,$attempted?'restore_uncertain':'published',$attempted?'restore interrupted; offline reconcile required':'restore refused before mutation');});}
    });
}
function liteReconcile(PDO $db,int $id,bool $offline=false): array {
    [$j,$s,$c]=liteWorkerJob($db,$id);editorialCapability('lite',$c['version'],'reconcile');
    return liteNative($c,function($repo,$root)use($db,$id,$c,$offline){return $repo->exclusive(function($repo)use($db,$id,$c,$offline,$root){[$state,$hash]=liteReadNative($repo,$c);return atomic($db,function()use($db,$id,$c,$state,$hash,$offline,$root){[$j,$s,$fresh]=liteWorkerJob($db,$id);if($fresh['hash']!==$c['hash'])throw new Problem('Конфигурация изменилась.',409);
        if(in_array($j['state'],['running','uncertain'],true)){
            $result=editorialLiteRecovery($j,$hash);
            if($result==='aborted')return liteFinish($db,$j,$s,'aborted','original bytes observed; new inspection required');
            if($result==='published'){try{$backup=new BackupService(new SiteRepository($root));if(!hash_equals($j['backup_hash'],liteBackupHash($root,$backup,$j['backup_name'])))throw new Problem('Backup digest mismatch.',409);}catch(\Throwable){return liteFinish($db,$j,$s,'uncertain','expected bytes observed but backup verification failed');}return liteFinish($db,$j,$s,'published','expected commit and backup verified after interruption');}
            return liteFinish($db,$j,$s,'uncertain','site diverged; manual investigation required');
        }
        if(in_array($j['state'],['restoring','restore_uncertain'],true)){
            if(hash_equals($j['after_hash'],$hash))return liteFinish($db,$j,$s,'published','pre-restore state observed; no replay');
            if($offline&&hash_equals($j['before_content'],liteContentHash($state))){liteJobActor($db,$j,$s,true);(new SessionRegistry($root))->invalidateAll($state);return liteFinish($db,$j,$s,'restored','restored content observed; sessions revoked offline');}
            return liteFinish($db,$j,$s,'restore_uncertain','restore needs offline verification; no replay');
        }throw new Problem('Нет прерванной операции.',409);
    });});});
}
