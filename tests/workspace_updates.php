<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
$n=0;
function uc(bool $ok,string $why): void {global $n;if(!$ok)throw new \RuntimeException($why);++$n;}
function reject(callable $fn): void {try{$fn();}catch(\Throwable){uc(true,'rejected');return;}uc(false,'expected rejection');}
$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);$pem=openssl_pkey_get_details($key)['key'];$pin=hash('sha256',$pem);$commit=str_repeat('a',40);$v='1.3.4';$tag='v'.$v;$name='CajeerWorkspace-'.$v.'.zip';$bytes='zip bytes';$psig='package signature';
$m=['format'=>1,'repository'=>UPDATE_REPO,'channel'=>'stable','version'=>$v,'tag'=>$tag,'commit'=>$commit,'schema'=>38,'supported_from'=>[['version'=>VERSION,'schema'=>38,'archive_sha256'=>str_repeat('b',64)]],'runtime'=>['php_min'=>'8.3','php_max_exclusive'=>'9.0','architecture'=>64,'sqlite_min'=>'3.27.0','postgres_min'=>'14','python_min'=>'3.9','extensions'=>['pdo','curl','session','openssl']],'package'=>['name'=>$name,'size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'signature_sha256'=>hash('sha256',$psig)],'matrix_sha256'=>str_repeat('c',64),'acceptance_sha256'=>str_repeat('d',64),'release_key_sha256'=>$pin,'accepted_at'=>'2026-01-01T00:00:00+00:00'];
$sign=static function(array $m)use($key):array{$raw=updateCanonical($m);openssl_sign($raw,$sig,$key,OPENSSL_ALGO_SHA256);return [$raw,$sig];};
[$raw,$sig]=$sign($m);uc(updateManifest($raw,$sig,$pem,$pin,$v,$commit)==$m,'signed metadata');
reject(fn()=>updateManifest($raw.' ',$sig,$pem,$pin,$v,$commit));$duplicate=substr($raw,0,-1).',"format":1}';openssl_sign($duplicate,$dupSig,$key,OPENSSL_ALGO_SHA256);reject(fn()=>updateManifest($duplicate,$dupSig,$pem,$pin,$v,$commit));reject(fn()=>updateManifest($raw,$sig,$pem,str_repeat('0',64),$v,$commit));reject(fn()=>updateManifest($raw,$sig,$pem,$pin,'1.3.5',$commit));reject(fn()=>updateManifest($raw,$sig,$pem,$pin,$v,str_repeat('f',40)));
foreach(['format'=>2,'repository'=>'evil/repo','channel'=>'preview','schema'=>0,'release_key_sha256'=>str_repeat('0',64),'supported_from'=>[]] as $k=>$val){$x=$m;$x[$k]=$val;[$a,$b]=$sign($x);reject(fn()=>updateManifest($a,$b,$pem,$pin,$v,$commit));}
$env=['version'=>VERSION,'schema'=>38,'php'=>'8.4.0','architecture'=>64,'backend'=>'sqlite','database'=>'3.40.1','extensions'=>['pdo','curl','session','openssl','pdo_sqlite'],'runtime_ok'=>true];
uc(updateCompatibility($m,$env)['status']==='compatible','compatible');foreach(['version'=>'1.0.0','schema'=>35,'php'=>'8.2.0','architecture'=>32,'database'=>'3.26.0','extensions'=>[],'runtime_ok'=>false]as $k=>$val)uc(updateCompatibility($m,array_replace($env,[$k=>$val]))['status']==='incompatible',$k);
uc(updateCompatibility($m,array_replace($env,['version'=>$v]))['status']==='current','current');uc(updateCompatibility($m,array_replace($env,['version'=>'1.9.0']))['status']==='ahead','ahead');
$pg=array_replace($env,['backend'=>'pgsql','database'=>'16.0','extensions'=>['pdo','curl','session','openssl','pdo_pgsql']]);uc(updateCompatibility($m,$pg)['status']==='compatible','pgsql');uc(updateCompatibility($m,array_replace($pg,['database'=>'13']))['status']==='incompatible','pgsql old');
$assets=[];foreach([$name=>$bytes,$name.'.sig'=>$psig,UPDATE_META=>$raw,UPDATE_META.'.sig'=>$sig]as $nme=>$b)$assets[]=['name'=>$nme,'size'=>strlen($b),'state'=>'uploaded','digest'=>'sha256:'.hash('sha256',$b)];
$row=['tag_name'=>$tag,'draft'=>false,'prerelease'=>false,'assets'=>$assets];$ref=['ref'=>'refs/tags/'.$tag,'object'=>['sha'=>$commit,'type'=>'commit']];
$transport=static function(array $rows,?string $override=null)use($ref,$raw,$sig):callable{return static function($url,$limit,$asset)use($rows,$ref,$raw,$sig,$override){if(str_contains($url,'/releases?'))return json_encode($rows);if(str_contains($url,'/git/ref/'))return json_encode($ref);if(str_ends_with($url,'.sig'))return $sig;return $override??$raw;};};
uc(updateDiscover($env,$pem,$pin,$transport([$row]))['status']==='compatible','discovery');$captured=null;updateDiscover($env,$pem,$pin,$transport([$row]),static function($a,$b,$c)use(&$captured){$captured=[$a,$b,$c];});uc($captured===[$raw,$sig,strlen($psig)],'capture verified metadata and signature size');reject(fn()=>updateDiscover($env,$pem,$pin,$transport([$row,$row])));uc(updateDiscover($env,$pem,$pin,$transport([]))['status']==='empty','empty');uc(updateDiscover($env,$pem,$pin,$transport([array_replace($row,['draft'=>true]),array_replace($row,['prerelease'=>true])]))['status']==='empty','ignore unpublished');
reject(fn()=>updateDiscover($env,$pem,$pin,$transport([$row],$raw.'x')));$bad=$row;$bad['assets'][0]['digest']='sha256:'.str_repeat('0',64);reject(fn()=>updateDiscover($env,$pem,$pin,$transport([$bad])));$bad=$row;array_pop($bad['assets']);reject(fn()=>updateDiscover($env,$pem,$pin,$transport([$bad])));
$preview=$m;$preview['channel']='preview';[$pr,$ps]=$sign($preview);$previewRow=$row;$previewRow['prerelease']=true;
foreach($previewRow['assets'] as &$a){if($a['name']===UPDATE_META){$a['size']=strlen($pr);$a['digest']='sha256:'.hash('sha256',$pr);}if($a['name']===UPDATE_META.'.sig'){$a['size']=strlen($ps);$a['digest']='sha256:'.hash('sha256',$ps);}}unset($a);
$previewTransport=static function($url,$limit,$asset)use($previewRow,$ref,$pr,$ps){if(str_contains($url,'/releases?'))return json_encode([$previewRow]);if(str_contains($url,'/git/ref/'))return json_encode($ref);return str_ends_with($url,'.sig')?$ps:$pr;};
uc(updateDiscover($env,$pem,$pin,$previewTransport)['status']==='empty','stable excludes preview');uc(updateDiscover($env,$pem,$pin,$previewTransport,null,'preview')['release']['channel']==='preview','preview accepts signed prerelease');uc(updateDiscover($env,$pem,$pin,$transport([$row]),null,'preview')['release']['channel']==='stable','preview accepts stable');
reject(fn()=>updateDiscover($env,$pem,$pin,$transport([array_replace($row,['prerelease'=>true])]),null,'preview'));
uc(updateManifest($pr,$ps,$pem,$pin,$v,$commit,'preview')==$preview,'preview explicit manifest');
uc(updateUrlAllowed(UPDATE_API.'/releases',false),'API allowed');uc(updateUrlAllowed('https://release-assets.githubusercontent.com/signed?x=1',true),'asset host');foreach(['http://github.com/x','https://github.com.evil/x','https://evil@github.com/x','https://github.com:443/x','https://github.com/other/repo/releases/download/v1/x','https://localhost/x']as $u)uc(!updateUrlAllowed($u,true),'reject '.$u);
putenv('WORKSPACE_UPDATE_ADMIN_IDS=1,3');uc(updateAdmin(['id'=>1,'role'=>'owner']),'granted owner');uc(!updateAdmin(['id'=>2,'role'=>'owner']),'tenant owner not granted');uc(!updateAdmin(['id'=>3,'role'=>'developer']),'not owner');$_SESSION=[];reject(fn()=>requireUpdateAdmin(['id'=>1,'role'=>'owner']));$_SESSION['mfa_at']=time();requireUpdateAdmin(['id'=>1,'role'=>'owner']);uc(true,'fresh MFA');
require_once ROOT.'/app/WorkspaceUpdateCli.php';
$dir=sys_get_temp_dir().'/workspace-update-test-'.bin2hex(random_bytes(12));mkdir($dir,0700);
try {
    $pub=$dir.'/key.pem';file_put_contents($pub,$pem);putenv('WORKSPACE_UPDATE_PUBLIC_KEY='.$pub);putenv('WORKSPACE_UPDATE_KEY_SHA256='.$pin);putenv('WORKSPACE_UPDATE_STATE_DIR='.$dir);
    uc(updateStateDirectory()===$dir,'private report directory');
    $report=['installed_version'=>VERSION,'installed_schema'=>38,'trust_pin'=>$pin,'checked_at'=>gmdate('c'),'status'=>'planned','operation'=>'plan','reasons'=>[]];
    updateWriteReport($dir,$report);uc(updateStoredReport(38)===$report,'stored report');uc(updateStoredReport(35)===null,'schema mismatch');
    clearstatcache();uc((fileperms($dir.'/report.json')&0777)===0640,'report permissions');
    updateWriteReport($dir,array_replace($report,['checked_at'=>gmdate('c',time()-86401)]));uc(updateStoredReport(38)===null,'expired report');
    updateWriteReport($dir,$report);putenv('WORKSPACE_UPDATE_KEY_SHA256='.str_repeat('0',64));uc(updateStoredReport(38)===null,'changed trust');putenv('WORKSPACE_UPDATE_KEY_SHA256='.$pin);
    chmod($dir,0770);clearstatcache();reject(fn()=>updateStateDirectory());chmod($dir,0700);clearstatcache();
    putenv('WORKSPACE_UPDATE_STATE_DIR='.realpath(ROOT));reject(fn()=>updateStateDirectory());putenv('WORKSPACE_UPDATE_STATE_DIR='.$dir);
} finally {foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);putenv('WORKSPACE_UPDATE_STATE_DIR');putenv('WORKSPACE_UPDATE_PUBLIC_KEY');putenv('WORKSPACE_UPDATE_KEY_SHA256');}
echo "$n Workspace Updates checks passed\n";
