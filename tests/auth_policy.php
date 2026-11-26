<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
$n=0;
function policyCheck(bool $ok,string $message):void {global $n;if(!$ok)throw new \RuntimeException($message);++$n;}
foreach(['WORKSPACE_AUTH_POLICY','WORKSPACE_OIDC_REQUIRED','WORKSPACE_OIDC_CLIENT_ID','WORKSPACE_OIDC_CLIENT_SECRET'] as $name) putenv($name);
policyCheck(installationAuthPolicy()==='local'&&!oidcEnabled()&&localAuthEnabled(),'legacy local');
putenv('WORKSPACE_OIDC_REQUIRED=1');policyCheck(installationAuthPolicy()==='required'&&!localAuthEnabled(),'legacy required even if missing credentials');
putenv('WORKSPACE_OIDC_REQUIRED');putenv('WORKSPACE_OIDC_CLIENT_ID=legacy');policyCheck(installationAuthPolicy()==='required','legacy client id');
putenv('WORKSPACE_OIDC_CLIENT_ID');putenv('WORKSPACE_OIDC_CLIENT_SECRET=legacy');policyCheck(installationAuthPolicy()==='required','legacy client secret');
foreach(['local','mixed','required'] as $mode){putenv('WORKSPACE_AUTH_POLICY='.$mode);policyCheck(installationAuthPolicy()===$mode,'explicit mode overrides legacy');}
putenv('WORKSPACE_AUTH_POLICY=invalid');try{installationAuthPolicy();throw new \RuntimeException('Invalid policy accepted');}catch(Problem $e){policyCheck($e->status===503,'invalid policy fails closed');}
$expected=[
 'local'=>['inherit'=>['local'],'required'=>[]],
 'mixed'=>['inherit'=>['local','oidc'],'required'=>['oidc']],
 'required'=>['inherit'=>['oidc','emergency'],'required'=>['oidc']]
];
foreach($expected as $install=>$orgs)foreach($orgs as $org=>$methods)foreach(['local','oidc','emergency','unknown'] as $kind) {
    policyCheck(authMethodAllowed($install,$org,$kind,true)===in_array($kind,$methods,true),"$install/$org/$kind");
    policyCheck(authMethodAllowed($install,$org,$kind,false)===($kind!=='emergency'&&in_array($kind,$methods,true)),"$install/$org/$kind without emergency");
}
policyCheck(sensitiveAction('org.auth-policy'),'policy requires MFA');
echo "OK · $n authentication policy checks\n";
