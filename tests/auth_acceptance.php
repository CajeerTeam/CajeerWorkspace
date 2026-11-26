<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
$n=0;
function ac(bool $ok,string $label):void {global $n;if(!$ok)throw new \RuntimeException($label);++$n;}
$now=1700000000;
function row(string $scenario,string $handle,int $uid,int $org,int $at):array {return ['scenario'=>$scenario,'session_hash'=>hash('sha256',$handle),'principal_id'=>$uid,'organization_id'=>$org,'occurred_at'=>$at];}
$rows=[row('staff_login','a',1,1,$now-100),row('staff_logout','a',1,1,$now-90),row('staff_login','b',2,2,$now-80),row('staff_logout','b',2,2,$now-70),row('guest_invite','c',3,1,$now-60),row('guest_logout','c',3,1,$now-50),row('emergency_login','d',1,1,$now-40),row('staff_backchannel','e',2,2,$now-30)];
ac(!authEvidenceSummary([],$now)['observed_ok'],'no synthetic pass');
ac(authEvidenceSummary($rows,$now)['observed_ok'],'all observed checks');
ac(!authEvidenceSummary(array_slice($rows,0,7),$now)['observed_ok'],'backchannel missing');
ac(!authEvidenceSummary(array_filter($rows,fn($r)=>$r['scenario']!=='guest_invite'),$now)['observed_ok'],'guest invitation required');
ac(!authEvidenceSummary(array_filter($rows,fn($r)=>$r['scenario']!=='emergency_login'),$now)['observed_ok'],'emergency required');
ac(!authEvidenceSummary($rows,$now+AUTH_ACCEPTANCE_WINDOW+1)['observed_ok'],'stale proof');
ac(authEvidenceSummary($rows,$now+AUTH_EVIDENCE_TTL+1)['last_success_utc']['staff_login']===null,'expired evidence');
$bad=$rows;$bad[2]['organization_id']=1;ac(!authEvidenceSummary($bad,$now)['observed_ok'],'same org insufficient');
$bad=$rows;$bad[2]['principal_id']=1;ac(!authEvidenceSummary($bad,$now)['observed_ok'],'same employee insufficient');
$bad=$rows;$bad[3]['session_hash']=str_repeat('0',64);ac(!authEvidenceSummary($bad,$now)['observed_ok'],'logout from unrelated session insufficient');
$bad=$rows;$bad[6]['occurred_at']=$now+1;ac(!authEvidenceSummary($bad,$now)['observed_ok'],'future observation rejected');
ac(authEvidenceSummary($rows,$now)['last_success_utc']['staff_login']===gmdate('Y-m-d\TH:i:s\Z',$now-80),'last success UTC');
putenv('WORKSPACE_AUTH_POLICY=local');putenv('WORKSPACE_MFA_KEY='.str_repeat('ab',32));
$r=authDiagnostic();ac($r['ok']&&$r['discovery']['status']==='disabled','local configuration does not require provider');
ac(!authDiagnostic(true)['ok'],'local never completes realm acceptance');
putenv('WORKSPACE_AUTH_POLICY=mixed');putenv('WORKSPACE_OIDC_CLIENT_ID=fixture');putenv('WORKSPACE_OIDC_CLIENT_SECRET=fixture-secret');putenv('WORKSPACE_PUBLIC_URL=https://workspace.example.test/');
$a=authConfigFingerprint();putenv('WORKSPACE_AUTH_POLICY=required');ac(authConfigFingerprint()===$a,'mode switch preserves client fingerprint');
putenv('WORKSPACE_OIDC_CLIENT_SECRET=other-secret');ac(authConfigFingerprint()!==$a,'credential change invalidates evidence');
$d=['issuer'=>ID_ISSUER];foreach(['authorization_endpoint','token_endpoint','jwks_uri','introspection_endpoint','end_session_endpoint'] as $key)$d[$key]=ID_ISSUER.'/'.$key;
ac(validateOidcDiscovery($d)===$d,'pinned valid discovery');
foreach ([array_replace($d,['issuer'=>'https://wrong.example']),array_replace($d,['jwks_uri'=>'https://wrong.example/key']),array_diff_key($d,['token_endpoint'=>1])] as $bad) {try{validateOidcDiscovery($bad);throw new \RuntimeException('invalid discovery accepted');}catch(Problem){ac(true,'invalid discovery rejected');}}
echo "OK · $n authentication acceptance checks\n";
