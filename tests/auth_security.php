<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
$n=0;
function authCheck(bool $ok,string $name): void { global $n; if (!$ok) throw new \RuntimeException($name); $n++; }
function authReject(callable $f,string $name): void { try {$f();} catch (Problem $e) {authCheck(true,$name);return;} throw new \RuntimeException($name); }
function fixtureKey(string $kid): array {
    $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
    $d=openssl_pkey_get_details($key)['rsa'];
    return [$key,['kty'=>'RSA','kid'=>$kid,'use'=>'sig','alg'=>'RS256','n'=>b64url($d['n']),'e'=>b64url($d['e'])]];
}
function jwtFixture($key,array $claims,array $header=['alg'=>'RS256','kid'=>'one']): string {
    $body=b64url(json_encode($header,JSON_THROW_ON_ERROR)).'.'.b64url(json_encode($claims,JSON_THROW_ON_ERROR));
    openssl_sign($body,$sig,$key,OPENSSL_ALGO_SHA256); return $body.'.'.b64url($sig);
}
[$key,$jwk]=fixtureKey('one'); [$new,$rotated]=fixtureKey('two');
$now=time(); $claims=['iss'=>ID_ISSUER,'sub'=>'user-1','aud'=>'workspace','iat'=>$now-10,'exp'=>$now+300,'nonce'=>'expected','sid'=>'id-session'];
$jwt=jwtFixture($key,$claims); $jwks=['keys'=>[$jwk]];
authCheck(verifyOidcJwt($jwt,$jwks,'workspace','expected',false,$now)===$claims,'valid signed ID token');
authReject(fn()=>verifyOidcJwt($jwt,$jwks,'workspace','wrong',false,$now),'nonce mismatch');
authReject(fn()=>verifyOidcJwt($jwt,$jwks,'other','expected',false,$now),'aud mismatch');
foreach ([['iss'=>'https://evil.invalid'],['sub'=>''],['exp'=>$now-1],['iat'=>$now+90],['nbf'=>$now+90],['aud'=>['workspace','other']],['azp'=>'other'],['azp'=>''],['nonce'=>null]] as $change) authReject(fn()=>verifyOidcJwt(jwtFixture($key,array_replace($claims,$change)),$jwks,'workspace','expected',false,$now),'invalid claims '.array_key_first($change));
authReject(fn()=>verifyOidcJwt(jwtFixture($new,$claims),$jwks,'workspace','expected',false,$now),'bad signature');
authReject(fn()=>verifyOidcJwt(jwtFixture($key,$claims,['alg'=>'HS256','kid'=>'one']),$jwks,'workspace','expected',false,$now),'algorithm substitution');
authReject(fn()=>verifyOidcJwt(jwtFixture($key,$claims,['alg'=>'none','kid'=>'one']),$jwks,'workspace','expected',false,$now),'unsigned token');
authReject(fn()=>verifyOidcJwt($jwt,['keys'=>[$jwk,$jwk]],'workspace','expected',false,$now),'ambiguous key');
$rot=jwtFixture($new,$claims,['alg'=>'RS256','kid'=>'two']);
authReject(fn()=>verifyOidcJwt($rot,$jwks,'workspace','expected',false,$now),'unknown key');
authCheck(verifyOidcJwt($rot,['keys'=>[$rotated]],'workspace','expected',false,$now)===$claims,'rotated key');
$logout=['iss'=>ID_ISSUER,'aud'=>'workspace','iat'=>$now,'jti'=>'logout-id','sid'=>'id-session','events'=>['http://schemas.openid.net/event/backchannel-logout'=>(object)[]]];
authCheck(verifyOidcJwt(jwtFixture($key,$logout),$jwks,'workspace',null,true,$now)['sid']==='id-session','logout token');
foreach ([['nonce'=>'bad'],['iat'=>$now-301],['events'=>[]],['sid'=>null],['jti'=>'']] as $change) authReject(fn()=>verifyOidcJwt(jwtFixture($key,array_replace($logout,$change)),$jwks,'workspace',null,true,$now),'invalid logout '.array_key_first($change));
authReject(fn()=>verifyOidcJwt(jwtFixture($key,$logout),$jwks,'workspace','expected',false,$now),'logout cannot be used for login');
authCheck(b64url(hash('sha256','dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk',true))==='E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM','RFC 7636 PKCE');
foreach (['http://id.skif4er.ru/realms/skif4er/x','https://evil.invalid/','https://id.skif4er.ru/realms/skif4er/../x','https://id.skif4er.ru/realms/skif4er/x?foo=1'] as $url) authReject(fn()=>oidcEndpoint($url),'unsafe endpoint');
authCheck(oidcEndpoint(ID_ISSUER.'/protocol/openid-connect/token')===ID_ISSUER.'/protocol/openid-connect/token','allowed endpoint');
$secret='12345678901234567890';
authCheck(totp($secret,1)==='287082','RFC 6238 SHA1 59 seconds (6 digits)');
authCheck(totp($secret,37037036)==='081804','RFC 6238 SHA1 1111111109 seconds');
authCheck(base32Secret('foobar')==='MZXW6YTBOI','RFC 4648 base32');
authCheck(matchingStep($secret,'287082',-1,59)===1,'TOTP accepts code');
authCheck(matchingStep($secret,'287082',1,59)===null,'TOTP prevents replay');
authCheck(matchingStep($secret,'287082',-1,1000)===null,'TOTP rejects stale code');
putenv('WORKSPACE_MFA_KEY='.str_repeat('aa',32)); $box=sealMfa($secret,1);
authCheck(openMfa($box,1)===$secret && !str_contains($box,$secret),'encrypted MFA round trip');
authReject(fn()=>openMfa($box,2),'credential bound to user');
$bytes=base64_decode($box); $bytes[35]=chr(ord($bytes[35])^1); authReject(fn()=>openMfa(base64_encode($bytes),1),'tampered MFA box');
foreach (['cash.post','cash.reverse','payment.approve','document.version','connection.attach','org.role','project.role','guest.issue','guest.revoke','invite.issue'] as $action) authCheck(sensitiveAction($action),'MFA action '.$action);
$_SESSION=[]; authReject(fn()=>requireMfa('payment.approve'),'missing factor blocked');
$_SESSION['mfa_at']=time()-301; authReject(fn()=>requireMfa('cash.post'),'expired factor blocked');
$_SESSION['mfa_at']=time(); requireMfa('cash.post'); authCheck(mfaFresh(),'fresh factor accepted');
echo "OK · $n проверок OIDC/MFA\n";
