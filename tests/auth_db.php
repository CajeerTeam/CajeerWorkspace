<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
require ROOT.'/app/Web.php';
$dir=sys_get_temp_dir().'/cws-auth-'.bin2hex(random_bytes(8)); mkdir($dir,0700);
putenv('WORKSPACE_DATA='.$dir); putenv('WORKSPACE_DB=sqlite'); putenv('WORKSPACE_MFA_KEY='.str_repeat('ab',32)); putenv('WORKSPACE_OIDC_CLIENT_ID=test-workspace');
$n=0;
function dbCheck(bool $value,string $label): void { global $n; if (!$value) throw new \RuntimeException($label); $n++; }
function dbReject(callable $f,string $label): void { try { $f(); } catch (Problem $e) {dbCheck(true,$label);return;} throw new \RuntimeException($label); }
function keyForTest(): string { return bin2hex(random_bytes(16)); }
function eraseAuthDir(string $dir): void { foreach (scandir($dir) as $f) if ($f!=='.'&&$f!=='..') { $p=$dir.'/'.$f; if (is_dir($p)) eraseAuthDir($p); else unlink($p); } rmdir($dir); }
try {
    $db=database(true); addUser($db,'owner@example.test','Owner','owner','sample-password-only',true);
    $owner=organizationUser($db,entity($db,'SELECT * FROM users WHERE id=1',[]),1);
    $p=createProject($db,$owner,keyForTest(),['title'=>'Client','kind'=>'client']);
    $other=createProject($db,$owner,keyForTest(),['title'=>'Private','kind'=>'client']);
    $pdf1="%PDF-1.4\nfirst\n%%EOF"; $pdf2="%PDF-1.4\nsecond\n%%EOF";
    $doc=storeDocument($db,$owner,keyForTest(),$p,null,0,['title'=>'Agreed','kind'=>'contract'],$pdf1);
    storeDocument($db,$owner,keyForTest(),$p,$doc,1,[],$pdf2);
    $private=storeDocument($db,$owner,keyForTest(),$other,null,0,['title'=>'Private','kind'=>'contract'],$pdf2);
    dbReject(fn()=>issueGuest($db,$owner,$p,$private,1,'client@example.test',1),'foreign document rejected');
    $token=issueGuest($db,$owner,$p,$doc,1,'client@example.test',1); $hash=hash('sha256',$token);
    $c=['sub'=>'guest-sub','email'=>'client@example.test','email_verified'=>true];
    dbReject(fn()=>atomic($db,fn()=>oidcPrincipal($db,$c+[],null)),'unlinked account denied');
    dbReject(fn()=>atomic($db,fn()=>oidcPrincipal($db,array_replace($c,['email_verified'=>false]),$hash)),'unverified guest email denied');
    dbReject(fn()=>atomic($db,fn()=>oidcPrincipal($db,array_replace($c,['email'=>'other@example.test']),$hash)),'wrong guest email denied');
    $g=atomic($db,fn()=>oidcPrincipal($db,$c,$hash)); $gid=$g['guest'];
    dbCheck($g['user']===null&&$gid>0,'guest is not a staff user');
    $grant=activeGuest($db,$gid); dbCheck((int)$grant['project_id']===$p&&(int)$grant['revision']===1&&$grant['sha256']===hash('sha256',$pdf1),'pinned PDF');
    dbReject(fn()=>atomic($db,fn()=>oidcPrincipal($db,$c,$hash)),'guest token consumed');
    dbCheck(atomic($db,fn()=>oidcPrincipal($db,$c,null))['guest']===$gid,'guest can return with same identity');
    dbCheck((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===1,'guest did not receive membership');
    $new=issueGuest($db,$owner,$p,$doc,2,'second@example.test',1);
    query($db,'UPDATE guest_invites SET expires_at=? WHERE token_hash=?',[time()-1,hash('sha256',$new)]);
    dbReject(fn()=>atomic($db,fn()=>oidcPrincipal($db,['sub'=>'second','email'=>'second@example.test','email_verified'=>true],hash('sha256',$new))),'expired guest invitation');
    $s=issueInvitation($db,$owner,'employee@example.test','developer',1);
    $staff=atomic($db,fn()=>oidcPrincipal($db,['sub'=>'staff-sub','email'=>'employee@example.test','email_verified'=>true,'name'=>'Employee'],null,hash('sha256',$s)));
    dbCheck($staff['user']!==null&&$staff['guest']===null,'staff invitation creates bound principal');
    dbReject(fn()=>atomic($db,fn()=>oidcPrincipal($db,['sub'=>'imposter','email'=>'owner@example.test','email_verified'=>true],null)),'no email-based staff linking');
    query($db,'INSERT INTO oidc_identities VALUES(1,?,?)',[ID_ISSUER,'owner-sub']);
    dbCheck((int)atomic($db,fn()=>oidcPrincipal($db,['sub'=>'owner-sub'],null))['user']['id']===1,'explicit staff identity');
    $now=time();
    foreach ([['a','guest-sub','guest-sid',null,$gid],['b','owner-sub','owner-sid',1,null],['c','owner-sub','another-sid',1,null]] as [$ch,$sub,$sid,$uid,$guest]) query($db,'INSERT INTO oidc_sessions(handle,issuer,subject,sid,user_id,guest_id,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?)',[str_repeat($ch,64),ID_ISSUER,$sub,$sid,$uid,$guest,$now-10,$now+3600]);
    $logout=['jti'=>'event-1','sid'=>'owner-sid','sub'=>'owner-sub','iat'=>$now]; applyBackchannelLogout($db,$logout);
    dbCheck(query($db,'SELECT revoked_at FROM oidc_sessions WHERE handle=?',[str_repeat('b',64)])->fetchColumn()!==null,'backchannel revoked matching SID');
    dbCheck(query($db,'SELECT revoked_at FROM oidc_sessions WHERE handle=?',[str_repeat('c',64)])->fetchColumn()===null,'different SID unaffected');
    applyBackchannelLogout($db,$logout); dbCheck((int)$db->query('SELECT COUNT(*) FROM oidc_logout_events')->fetchColumn()===1,'logout replay idempotent');
    revokeGuest($db,$owner,$p,$gid); dbCheck(activeGuest($db,$gid)===null,'guest grant revoked');
    dbCheck(query($db,'SELECT revoked_at FROM oidc_sessions WHERE handle=?',[str_repeat('a',64)])->fetchColumn()!==null,'guest sessions revoked');
    dbReject(fn()=>atomic($db,fn()=>oidcPrincipal($db,$c,null)),'revoked guest cannot return');
    // Enroll through the same server-side routine used by HTTP; verify anti-replay persisted in DB.
    sessionOpen(); $_SESSION['uid']=1; $_SESSION['v']=(int)$owner['session_version']; $_SESSION['born']=time();
    $secret=random_bytes(20); $_SESSION['mfa_pending']=['uid'=>1,'at'=>time(),'box'=>sealMfa($secret,1)];
    $step=intdiv(time(),30); $code=totp($secret,$step); verifyMfa($db,$owner,$code,true);
    $owner=organizationUser($db,entity($db,'SELECT * FROM users WHERE id=1',[]),1);
    dbCheck(mfaFresh() && (int)$owner['session_version']===(int)$_SESSION['v'],'MFA enrollment revokes older sessions');
    dbReject(fn()=>verifyMfa($db,$owner,$code,false),'same code cannot be reused');
    unset($_SESSION['mfa_at']); dbReject(fn()=>requireMfa('cash.post'),'no factor blocks payment');
    verifyMfa($db,$owner,totp($secret,$step+1),false); dbCheck(mfaFresh(),'next TOTP succeeds');
    query($db,'INSERT INTO emergency_accounts VALUES(1)',[]);
    putenv('WORKSPACE_EMERGENCY_LOCAL=1'); dbCheck(emergencyAllowed($db,1),'enrolled owner emergency allowed');
    putenv('WORKSPACE_EMERGENCY_LOCAL=0'); dbCheck(!emergencyAllowed($db,1),'emergency flag disabled');
    session_write_close();
    if (in_array('--postgres',$argv,true)) {
        $report=migrateToPostgres($dir.'/source.sqlite');
        dbCheck($report['tables']['guest_invites']['rows']===2 && $report['tables']['mfa_credentials']['rows']===1 && $report['tables']['oidc_sessions']['rows']===3,'auth tables transferred and checksummed');
        $pg=postgresConnect(); dbCheck(schemaVersion($pg)===SCHEMA_VERSION && activeGuest($pg,$gid)===null,'PostgreSQL revocation preserved');
        $box=query($pg,'SELECT secret_box FROM mfa_credentials WHERE user_id=1')->fetchColumn(); dbCheck(openMfa($box,1)===$secret,'encrypted MFA preserved');
        dbCheck(query($pg,'SELECT sha256 FROM document_versions WHERE document_id=? AND revision=1',[$doc])->fetchColumn()===hash('sha256',$pdf1),'PDF preserved');
        $pg=null;
    }
    echo "OK · $n проверок базы авторизации\n";
} finally { if (session_status()===PHP_SESSION_ACTIVE) session_write_close(); $db=null; eraseAuthDir($dir); }
