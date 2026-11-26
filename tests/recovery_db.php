<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';

if(($argv[1]??'')==='claim'){
    $db=database();$job=relClaim($db);if(!$job)exit(2);echo json_encode($job,JSON_THROW_ON_ERROR),PHP_EOL;flush();sleep(60);exit(0);
}
$n=0;
function recoveryAssert(bool $ok,string $label):void{global $n;if(!$ok)throw new \RuntimeException($label);++$n;}
function recoveryReject(callable $f,string $label):void{try{$f();}catch(Problem){recoveryAssert(true,$label);return;}throw new \RuntimeException($label);}
function recoveryErase(string $dir):void{foreach(scandir($dir) as $f){if($f==='.'||$f==='..')continue;$p=$dir.'/'.$f;if(is_dir($p)&&!is_link($p))recoveryErase($p);else unlink($p);}rmdir($dir);}
$dir=sys_get_temp_dir().'/cws-recovery-'.bin2hex(random_bytes(8));mkdir($dir,0700);putenv('WORKSPACE_DATA='.$dir);putenv('WORKSPACE_DB=sqlite');putenv('WORKSPACE_RECOVERY_OFFLINE=1');$old=str_repeat('ab',32);$new=str_repeat('cd',32);putenv('WORKSPACE_MFA_KEY='.$old);
try{
    $db=database(true);addUser($db,'owner@example.test','Owner','owner','test-password-only',true);addUser($db,'staff@example.test','Staff','developer','test-password-other');
    $secret1=random_bytes(20);$secret2=random_bytes(20);$box1=sealMfa($secret1,1);$box2=sealMfa($secret2,2);
    query($db,'INSERT INTO mfa_credentials(user_id,secret_box,last_step) VALUES(?,?,?)',[1,$box1,123]);query($db,'INSERT INTO mfa_credentials(user_id,secret_box,last_step) VALUES(?,?,?)',[2,$box2,456]);
    $now=time();query($db,'INSERT INTO oidc_sessions(handle,issuer,subject,user_id,created_at,expires_at) VALUES(?,?,?,?,?,?)',[str_repeat('a',64),ID_ISSUER,'staff',2,$now,$now+3600]);
    recoveryAssert(recoveryMfaCheck($db)===2,'old key valid');recoveryReject(fn()=>recoveryMfaRotate($db,$old),'same key denied');
    query($db,'UPDATE mfa_credentials SET secret_box=? WHERE user_id=2',[sealMfa($secret2,1)]);
    recoveryReject(fn()=>recoveryMfaRotate($db,$new),'corrupt second credential denies rotation');
    recoveryAssert(entity($db,'SELECT secret_box FROM mfa_credentials WHERE user_id=1',[])['secret_box']===$box1,'atomic rollback of first credential');
    recoveryAssert((int)entity($db,'SELECT session_version FROM users WHERE id=1',[])['session_version']===1,'rollback did not revoke sessions');
    query($db,'UPDATE mfa_credentials SET secret_box=? WHERE user_id=2',[$box2]);putenv('WORKSPACE_RECOVERY_OFFLINE=0');recoveryReject(fn()=>recoveryMfaRotate($db,$new),'offline guard');putenv('WORKSPACE_RECOVERY_OFFLINE=1');
    $r=recoveryMfaRotate($db,$new);recoveryAssert($r===['credentials'=>2,'staff_sessions_invalidated'=>2,'oidc_sessions_revoked'=>1],'rotation report');
    recoveryAssert(getenv('WORKSPACE_MFA_KEY')===$old,'environment remains old until operator switch');recoveryReject(fn()=>recoveryMfaCheck($db),'old key no longer decrypts');putenv('WORKSPACE_MFA_KEY='.$new);
    recoveryAssert(recoveryMfaCheck($db)===2,'new key verifies all');$rows=query($db,'SELECT * FROM mfa_credentials ORDER BY user_id')->fetchAll();recoveryAssert(openMfa($rows[0]['secret_box'],1)===$secret1&&openMfa($rows[1]['secret_box'],2)===$secret2,'TOTP seeds preserved');recoveryAssert((int)$rows[0]['last_step']===123&&(int)$rows[1]['last_step']===456,'replay protection preserved');
    recoveryAssert((int)entity($db,'SELECT session_version FROM users WHERE id=1',[])['session_version']===2,'staff sessions invalidated');recoveryAssert(entity($db,'SELECT revoked_at FROM oidc_sessions WHERE user_id=2',[])['revoked_at']!==null,'OIDC session revoked');
    // Crash a real worker immediately after its durable claim. Expire the lease
    // in this disposable database instead of waiting ten minutes in CI.
    $u=organizationUser($db,entity($db,'SELECT * FROM users WHERE id=1',[]),1);$p=createProject($db,$u,bin2hex(random_bytes(16)),['title'=>'Recovery','kind'=>'internal']);
    query($db,"INSERT INTO integration_jobs(organization_id,project_id,host_id,actor_id,actor_session,target_hash,state,available_at,created_at,updated_at) VALUES(1,?,1,1,2,?,'pending',0,0,0)",[$p,str_repeat('a',64)]);
    $db=null;
    $proc=proc_open([PHP_BINARY,__FILE__,'claim'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);if(!is_resource($proc))throw new \RuntimeException('worker spawn failed');
    try{stream_set_timeout($pipes[1],15);$raw=fgets($pipes[1]);if(!is_string($raw))throw new \RuntimeException('worker claim failed');$claimed=json_decode($raw,true,16,JSON_THROW_ON_ERROR);recoveryAssert($claimed['state']==='running','worker committed claim');proc_terminate($proc,9);}
    finally{foreach($pipes as $pipe)fclose($pipe);proc_close($proc);}
    $db=database();recoveryAssert(relClaim($db)===null,'active lease survives restart');query($db,'UPDATE integration_jobs SET lease_until=0 WHERE id=?',[$claimed['id']]);$fresh=relClaim($db);recoveryAssert($fresh!==null&&$fresh['claim']!==$claimed['claim']&&(int)$fresh['attempts']===2,'expired lease recovered');recoveryAssert(relFinish($db,$claimed,'ok')==='superseded','stale worker cannot finish');recoveryAssert(relFinish($db,$fresh,'ok')==='done','new worker completed job');
    // A completed restore marker blocks normal CLI just like FPM.
    $digest=str_repeat('f',64);file_put_contents($dir.'/recovery.lock',$digest."\n");recoveryReject(fn()=>database(),'quarantine blocks ordinary worker');recoveryAssert(database(recovery:true) instanceof \PDO,'offline recovery access');recoveryReject(fn()=>recoveryRelease($db,str_repeat('0',64)),'wrong manifest rejected');
    // The test directory is not a complete restore, therefore release is refused.
    recoveryReject(fn()=>recoveryRelease($db,$digest),'missing verified restore report');unlink($dir.'/recovery.lock');
    echo "OK · $n recovery database checks\n";
}finally{$db=null;recoveryErase($dir);}
