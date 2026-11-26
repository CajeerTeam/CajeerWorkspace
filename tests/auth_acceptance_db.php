<?php
declare(strict_types=1);
namespace Workspace;
use PDO;
require dirname(__DIR__).'/app/bootstrap.php';
require ROOT.'/app/Web.php';
$n=0;
function acdb(bool $ok,string $label):void {global $n;if(!$ok)throw new \RuntimeException($label);++$n;}
$dir=sys_get_temp_dir().'/cws-acceptance-'.bin2hex(random_bytes(8));mkdir($dir,0700);
putenv('WORKSPACE_DATA='.$dir);putenv('WORKSPACE_DB=sqlite');putenv('WORKSPACE_AUTH_POLICY=required');putenv('WORKSPACE_MFA_KEY='.str_repeat('ab',32));putenv('WORKSPACE_OIDC_CLIENT_ID=fixture');putenv('WORKSPACE_OIDC_CLIENT_SECRET=fixture-only');putenv('WORKSPACE_PUBLIC_URL=https://workspace.example.test/');putenv('WORKSPACE_EMERGENCY_LOCAL=1');
try {
    $db=database(true); addUser($db,'owner@example.test','Owner','owner','test-password-only',true);
    $u=entity($db,'SELECT * FROM users WHERE id=1');$u['organization_id']=1;$config=authConfigFingerprint();$now=time();
    $raw=bin2hex(random_bytes(32));$handle=hash('sha256',$raw);
    // Fixture represents a previously validated callback. No real IdP acceptance is claimed by this test.
    query($db,'INSERT INTO oidc_identities VALUES(?,?,?)',[1,ID_ISSUER,'fixture-subject']);
    query($db,'INSERT INTO oidc_sessions(handle,issuer,subject,sid,user_id,created_at,expires_at) VALUES(?,?,?,?,?,?,?)',[$handle,ID_ISSUER,'fixture-subject','fixture-sid',1,$now,$now+3600]);
    atomic($db,fn()=>staffSessionCreate($db,$u,'oidc',$raw));
    $_SESSION=['staff_handle'=>$raw,'oidc_handle'=>$raw,'auth_kind'=>'oidc','uid'=>1,'v'=>(int)$u['session_version'],'auth_evidence_pending'=>['config'=>$config]];
    observeAuthRequest($db,$u,'security');acdb((int)query($db,'SELECT COUNT(*) FROM auth_scenario_evidence')->fetchColumn()===0,'no evidence before protected overview');
    observeAuthRequest($db,$u,'overview');observeAuthRequest($db,$u,'overview');acdb((int)query($db,'SELECT COUNT(*) FROM auth_scenario_evidence')->fetchColumn()===1,'one observation per session');
    applyBackchannelLogout($db,['jti'=>'unmatched','sid'=>'wrong','iat'=>$now]);acdb((int)query($db,'SELECT COUNT(*) FROM auth_scenario_evidence')->fetchColumn()===1,'unmatched logout no proof');
    applyBackchannelLogout($db,['jti'=>'matched','sid'=>'fixture-sid','iat'=>$now]);applyBackchannelLogout($db,['jti'=>'matched','sid'=>'fixture-sid','iat'=>$now]);acdb((int)query($db,"SELECT COUNT(*) FROM auth_scenario_evidence WHERE scenario='staff_backchannel'")->fetchColumn()===1,'matched signed-handler observation idempotent');
    acdb(query($db,'SELECT revoked_at FROM oidc_sessions WHERE handle=?',[$handle])->fetchColumn()!==null,'actual revocation');
    $raw=bin2hex(random_bytes(32));query($db,'INSERT INTO emergency_accounts VALUES(1)');query($db,'INSERT INTO mfa_credentials(user_id,secret_box,last_step) VALUES(?,?,?)',[1,sealMfa(random_bytes(20),1),-1]);atomic($db,fn()=>staffSessionCreate($db,$u,'emergency',$raw));
    $_SESSION=['staff_handle'=>$raw,'auth_kind'=>'emergency','uid'=>1,'v'=>(int)$u['session_version'],'auth_evidence_pending'=>['config'=>$config]];
    observeAuthRequest($db,$u,'overview');acdb((int)query($db,"SELECT COUNT(*) FROM auth_scenario_evidence WHERE scenario='emergency_login'")->fetchColumn()===0,'no proof before MFA');
    $_SESSION['mfa_at']=$now;$_SESSION['emergency_verified']=true;observeAuthRequest($db,$u,'overview');acdb((int)query($db,"SELECT COUNT(*) FROM auth_scenario_evidence WHERE scenario='emergency_login'")->fetchColumn()===1,'authorized emergency with verified MFA');
    $rows=query($db,'SELECT * FROM auth_scenario_evidence')->fetchAll();acdb(!authEvidenceSummary($rows,$now+5)['observed_ok'],'partial evidence cannot pass');
    if(in_array('--postgres',$argv,true)){$report=migrateToPostgres($dir.'/source.sqlite');acdb($report['tables']['auth_scenario_evidence']['rows']===3,'evidence transferred');}
    echo "OK · $n authentication evidence DB checks\n";
} finally { $db=null;foreach(glob($dir.'/*')?:[] as $file)if(is_file($file))unlink($file);rmdir($dir); }
