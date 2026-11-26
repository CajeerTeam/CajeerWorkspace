<?php
declare(strict_types=1);
namespace Workspace;
use PDO;
require dirname(__DIR__).'/app/bootstrap.php';
require ROOT.'/app/Web.php';
$dir=sys_get_temp_dir().'/cws-identity-'.bin2hex(random_bytes(8)); mkdir($dir,0700);
putenv('WORKSPACE_DATA='.$dir); putenv('WORKSPACE_DB=sqlite'); putenv('WORKSPACE_AUTH_POLICY=mixed'); putenv('WORKSPACE_MFA_KEY='.str_repeat('ab',32));
$n=0;
function ic(bool $ok,string $label): void { global $n; if (!$ok) throw new \RuntimeException($label); ++$n; }
function deny(callable $f,string $label): void { try {$f();} catch (Problem $e) {ic(true,$label);return;} throw new \RuntimeException($label); }
function fresh(PDO $db,int $uid): array { return organizationUser($db,entity($db,'SELECT * FROM users WHERE id=?',[$uid]),1); }
function browser(PDO $db,array $u,string $raw,string $kind='local'): void {
    atomic($db,fn()=>staffSessionCreate($db,$u,$kind,$raw));
    $_SESSION=['uid'=>(int)$u['id'],'v'=>(int)$u['session_version'],'staff_handle'=>$raw,'auth_kind'=>$kind,'mfa_at'=>time(),'born'=>time(),'seen'=>time(),'org'=>1];
}
function linkTx(array $u): array {return ['uid'=>(int)$u['id'],'version'=>(int)$u['session_version'],'handle'=>staffHandle(),'mfa_at'=>$_SESSION['mfa_at']];}
function erase(string $d): void {foreach(scandir($d) as $f) if($f!=='.'&&$f!=='..'){ $p=$d.'/'.$f; is_dir($p)?erase($p):unlink($p); } rmdir($d);}
try {
    $db=database(true);
    addUser($db,'owner@example.test','Owner','owner','test-password-only',true);
    addUser($db,'other@example.test','Other','developer','test-password-only');
    $u=fresh($db,1); $other=fresh($db,2);
    $a=bin2hex(random_bytes(32)); $b=bin2hex(random_bytes(32)); $c=bin2hex(random_bytes(32));
    browser($db,$other,$c); browser($db,$u,$b); browser($db,$u,$a);
    ic(count(activeStaffSessions($db,$u))===2,'own sessions only');
    ic(staffSessionValid($db,$u,true),'registered current session');
    deny(fn()=>selfRevokeSession($db,$u,hash('sha256',$c)),'cannot revoke foreign');
    unset($_SESSION['mfa_at']); deny(fn()=>selfRevokeSession($db,$u,hash('sha256',$b)),'fresh TOTP mandatory');
    $_SESSION['mfa_at']=time();
    ic(!selfRevokeSession($db,$u,hash('sha256',$b)),'revoke other browser');
    ic(count(activeStaffSessions($db,$u))===1,'revoked session excluded');
    $original=$_SESSION; $_SESSION['staff_handle']=$b; ic(!staffSessionValid($db,$u),'revoked denied'); $_SESSION=$original;
    $_SESSION['staff_handle']=$c; ic(!staffSessionValid($db,$u),'foreign handle denied'); $_SESSION=$original;
    deny(fn()=>proveLocalPassword($db,$u,'wrong-password'),'password proof required');
    proveLocalPassword($db,$u,'test-password-only');
    $tx=linkTx($u); $now=time(); $claims=['sub'=>'owner-id','auth_time'=>$now];
    query($db,'INSERT INTO oidc_identities VALUES(?,?,?)',[2,ID_ISSUER,'other-id']);
    deny(fn()=>atomic($db,fn()=>completeSelfLink($db,$u,$tx,['sub'=>'other-id','auth_time'=>$now],$now)),'cannot steal foreign identity');
    deny(fn()=>atomic($db,fn()=>completeSelfLink($db,$u,$tx,['sub'=>'owner-id','auth_time'=>$now-400],$now)),'stale IdP authentication');
    deny(fn()=>atomic($db,fn()=>completeSelfLink($db,$u,array_replace($tx,['uid'=>2]),$claims,$now)),'wrong original user');
    deny(fn()=>atomic($db,fn()=>completeSelfLink($db,$u,array_replace($tx,['handle'=>str_repeat('0',64)]),$claims,$now)),'wrong originating session');
    deny(fn()=>atomic($db,fn()=>completeSelfLink($db,$u,$tx,['sub'=>'owner-id'],$now)),'missing auth_time');
    atomic($db,fn()=>completeSelfLink($db,$u,$tx,$claims,$now));
    ic(query($db,'SELECT subject FROM oidc_identities WHERE user_id=1')->fetchColumn()==='owner-id','self bind persists');
    ic((int)atomic($db,fn()=>oidcPrincipal($db,['sub'=>'owner-id'],null))['user']['id']===1,'same staff account');
    deny(fn()=>atomic($db,fn()=>completeSelfLink($db,$u,$tx,$claims,$now)),'callback replay denied');
    ic(!staffSessionValid($db,$u),'old sessions revoked');
    $u=fresh($db,1); browser($db,$u,bin2hex(random_bytes(32)));
    query($db,"INSERT INTO organization_auth_policies VALUES(1,'required')");
    deny(fn()=>selfUnlink($db,$u,'test-password-only'),'strict organization blocks unlink');
    deny(fn()=>assertUnlinkAllowed($db,1),'operator cannot unlink mandatory identity');
    query($db,'DELETE FROM organization_auth_policies');
    putenv('WORKSPACE_AUTH_POLICY=required'); deny(fn()=>assertUnlinkAllowed($db,1),'required installation blocks unlink'); putenv('WORKSPACE_AUTH_POLICY=mixed');
    // Create a second OIDC staff session to check unlink revokes both kinds.
    $raw=bin2hex(random_bytes(32)); $hash=hash('sha256',$raw);
    query($db,'INSERT INTO oidc_sessions(handle,issuer,subject,sid,user_id,created_at,expires_at) VALUES(?,?,?,?,?,?,?)',[$hash,ID_ISSUER,'owner-id','sid',1,time(),time()+3600]);
    atomic($db,fn()=>staffSessionCreate($db,$u,'oidc',$raw));
    ic(count(activeStaffSessions($db,$u))===2,'OIDC session visible');
    selfUnlink($db,$u,'test-password-only');
    ic(!query($db,'SELECT 1 FROM oidc_identities WHERE user_id=1')->fetchColumn(),'unlink deletes binding');
    ic(query($db,'SELECT revoked_at FROM oidc_sessions WHERE handle=?',[$hash])->fetchColumn()!==null,'unlink revokes OIDC');
    deny(fn()=>operatorRecoverIdentity($db,'owner@example.test','other-id','TICKET-1'),'recovery cannot steal identity');
    operatorRecoverIdentity($db,'owner@example.test','recovered-id','TICKET-1');
    ic(query($db,'SELECT subject FROM oidc_identities WHERE user_id=1')->fetchColumn()==='recovered-id','operator recovery');
    ic(count(identityHistory($db,1))===4,'history of revoke, bind, unlink, recover');
    ic(count(identityHistory($db,2))===0,'history isolated');
    $u=fresh($db,1); browser($db,$u,bin2hex(random_bytes(32)));
    ic(selfRevokeSession($db,$u,staffHandle()),'current revocation identified');
    ic(!staffSessionValid($db,$u),'current session denied');
    if (in_array('--postgres',$argv,true)) {
        $report=migrateToPostgres($dir.'/source.sqlite');
        ic($report['tables']['staff_sessions']['rows']>0&&$report['tables']['identity_history']['rows']===5,'security tables transferred');
        $pg=postgresConnect(); $u=fresh($pg,1); browser($pg,$u,bin2hex(random_bytes(32)));
        ic(staffSessionValid($pg,$u,true),'PostgreSQL session check');
        ic(selfRevokeSession($pg,$u,staffHandle())&&!staffSessionValid($pg,$u),'PostgreSQL session revoke');
        ic(count(identityHistory($pg,1))===6,'PostgreSQL history');
    }
    echo "OK · $n identity DB checks\n";
} finally { $db=null; erase($dir); }
