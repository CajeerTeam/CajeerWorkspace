<?php
declare(strict_types=1);
namespace Workspace;

const ID_ISSUER='https://id.skif4er.ru/realms/skif4er';
// Explicit installation policy is controlled by the OS administrator. Unset preserves 1.0.x behavior.
function installationAuthPolicy(): string {
    $mode=getenv('WORKSPACE_AUTH_POLICY');
    if ($mode!==false && $mode!=='') {
        if (!in_array($mode,['local','mixed','required'],true)) throw new Problem('Некорректный WORKSPACE_AUTH_POLICY: local, mixed или required.',503);
        return $mode;
    }
    return getenv('WORKSPACE_OIDC_REQUIRED')==='1' || (getenv('WORKSPACE_OIDC_CLIENT_ID') ?: '')!=='' || (getenv('WORKSPACE_OIDC_CLIENT_SECRET') ?: '')!=='' ? 'required' : 'local';
}
function oidcEnabled(): bool { return installationAuthPolicy()!=='local'; }
function localAuthEnabled(): bool { return installationAuthPolicy()!=='required'; }
function oidcConfig(): array {
    $id=getenv('WORKSPACE_OIDC_CLIENT_ID')?:''; $secret=getenv('WORKSPACE_OIDC_CLIENT_SECRET')?:'';
    $base=getenv('WORKSPACE_PUBLIC_URL')?:'';
    $u=parse_url($base);
    if (!$id || !$secret || !is_array($u) || ($u['scheme']??'')!=='https' || empty($u['host']) || isset($u['user'],$u['pass']) || isset($u['user']) || isset($u['query']) || isset($u['fragment']) || !str_ends_with($base,'/') || preg_match('/[\s\\\\]/',$base)) throw new Problem('Проверьте настройку SkiF4er ID и HTTPS URL.',503);
    return ['id'=>$id,'secret'=>$secret,'callback'=>$base.'?p=oidc.callback','logout'=>$base.'?p=login'];
}
function b64url(string $bytes): string { return rtrim(strtr(base64_encode($bytes),'+/','-_'),'='); }
function unb64url(string $s): string {
    if ($s==='' || !preg_match('/\A[A-Za-z0-9_-]+\z/',$s) || strlen($s)%4===1) throw new Problem('Некорректный токен ID.',401);
    $v=base64_decode(strtr($s,'-_','+/'),true);
    if ($v===false || b64url($v)!==$s) throw new Problem('Некорректный токен ID.',401);
    return $v;
}
function oidcEndpoint(string $url): string {
    if (!str_starts_with($url,ID_ISSUER.'/') || preg_match('/[\s?#\\\\]/',$url) || str_contains($url,'..')) throw new Problem('Недопустимый endpoint ID.',503);
    return $url;
}
function oidcHttp(string $url,?array $form=null): array {
    $url=oidcEndpoint($url); $ch=curl_init($url); $body='';
    $options=[CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_WRITEFUNCTION=>static function($ch,$chunk) use (&$body){ if (strlen($body)+strlen($chunk)>1048576) return 0; $body.=$chunk; return strlen($chunk); }];
    if ($form!==null) { $cfg=oidcConfig(); $options += [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($form,'','&',PHP_QUERY_RFC3986),CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_USERPWD=>urlencode($cfg['id']).':'.urlencode($cfg['secret'])]; }
    try { curl_setopt_array($ch,$options); $ok=curl_exec($ch); $status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE); }
    finally { curl_close($ch); }
    if ($ok===false || $status!==200) throw new Problem('SkiF4er ID недоступен или отклонил запрос.',503);
    try { $data=json_decode($body,true,32,JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new Problem('Некорректный ответ SkiF4er ID.',503); }
    if (!is_array($data)) throw new Problem('Некорректный ответ SkiF4er ID.',503);
    return $data;
}
function oidcDiscovery(): array {
    static $d=null;
    if ($d!==null) return $d;
    $candidate=oidcHttp(ID_ISSUER.'/.well-known/openid-configuration');
    if (($candidate['issuer']??null)!==ID_ISSUER) throw new Problem('Issuer не совпадает.',503);
    foreach (['authorization_endpoint','token_endpoint','jwks_uri','introspection_endpoint','end_session_endpoint'] as $key) {
        if (!is_string($candidate[$key]??null)) throw new Problem('Неполная конфигурация SkiF4er ID.',503);
        oidcEndpoint($candidate[$key]);
    }
    return $d=$candidate;
}
function der(string $tag,string $value): string {
    $n=strlen($value); $len=$n<128?chr($n):ltrim(pack('N',$n),"\0");
    return $tag.($n<128?$len:chr(128+strlen($len)).$len).$value;
}
function derInt(string $v): string { $v=ltrim($v,"\0"); if ($v==='' || (ord($v[0])&128)) $v="\0".$v; return der("\x02",$v); }
function jwkPem(array $key): string {
    $n=unb64url((string)($key['n']??'')); $e=unb64url((string)($key['e']??''));
    if (strlen($n)<256 || strlen($n)>1024 || strlen($e)>8) throw new Problem('Недопустимый RSA ключ.',401);
    $rsa=der("\x30",derInt($n).derInt($e));
    $spki=der("\x30",hex2bin('300d06092a864886f70d0101010500').der("\x03","\0".$rsa));
    return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki),64,"\n")."-----END PUBLIC KEY-----\n";
}
/** Validate signed tokens against freshly retrieved JWKS; never accept algorithm from configuration or token. */
function verifyOidcJwt(string $jwt,array $jwks,string $client,?string $nonce=null,bool $logout=false,?int $now=null): array {
    $now??=time();
    if (strlen($jwt)>65536 || count($parts=explode('.',$jwt))!==3) throw new Problem('Некорректный JWT.',401);
    try { $h=json_decode(unb64url($parts[0]),true,16,JSON_THROW_ON_ERROR); $c=json_decode(unb64url($parts[1]),true,32,JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new Problem('Некорректный JWT.',401); }
    if (!is_array($h)||!is_array($c)||($h['alg']??null)!=='RS256'||!is_string($h['kid']??null)||isset($h['crit'])) throw new Problem('JWT алгоритм или ключ не разрешён.',401);
    $keys=array_values(array_filter($jwks['keys']??[],fn($k)=>is_array($k)&&($k['kid']??null)===$h['kid']&&($k['kty']??null)==='RSA'&&($k['use']??'sig')==='sig'&&($k['alg']??'RS256')==='RS256'));
    if (count($keys)!==1 || openssl_verify($parts[0].'.'.$parts[1],unb64url($parts[2]),jwkPem($keys[0]),OPENSSL_ALGO_SHA256)!==1) throw new Problem('Подпись JWT не подтверждена.',401);
    $aud=$c['aud']??null; $aud=is_string($aud)?[$aud]:$aud;
    if (($c['iss']??null)!==ID_ISSUER || !is_array($aud)||!array_is_list($aud)||count(array_filter($aud,'is_string'))!==count($aud)||!in_array($client,$aud,true)||(array_key_exists('azp',$c)&&$c['azp']!==$client)||(!$logout&&count($aud)>1&&($c['azp']??null)!==$client)||!is_int($c['iat']??null)||$c['iat']>$now+30 || (isset($c['nbf'])&&(!is_int($c['nbf'])||$c['nbf']>$now+30))) throw new Problem('JWT claims не подтверждены.',401);
    if ((!$logout||isset($c['exp']))&&(!is_int($c['exp']??null)||$c['exp']<=$now||$c['exp']<$c['iat'])) throw new Problem('JWT истёк.',401);
    if (isset($c['sid'])&&(!is_string($c['sid'])||$c['sid']===''||strlen($c['sid'])>255)) throw new Problem('Некорректный sid.',401);
    if (isset($c['sub'])&&(!is_string($c['sub'])||$c['sub']===''||strlen($c['sub'])>255)) throw new Problem('Некорректный sub.',401);
    if ($logout) {
        if (array_key_exists('nonce',$c)||$c['iat']<$now-300||!is_string($c['jti']??null)||$c['jti']===''||(!isset($c['sid'])&&!isset($c['sub']))||!is_array($c['events']??null)||!array_key_exists('http://schemas.openid.net/event/backchannel-logout',$c['events'])||$c['events']['http://schemas.openid.net/event/backchannel-logout']!==[]) throw new Problem('Некорректный logout token.',401);
    } else {
        if (!isset($c['sub'])||($nonce!==null&&(!is_string($c['nonce']??null)||!hash_equals($nonce,$c['nonce'])))||array_key_exists('events',$c)) throw new Problem('ID token не соответствует сессии.',401);
    }
    return $c;
}
function oidcClaims(string $jwt,?string $nonce=null,bool $logout=false): array {
    return verifyOidcJwt($jwt,oidcHttp(oidcDiscovery()['jwks_uri']),oidcConfig()['id'],$nonce,$logout);
}
function oidcBegin(?string $guestToken=null,?string $staffInvite=null,?array $switch=null,?array $link=null): never {
    if (!oidcEnabled()) throw new Problem('Вход ID отключён политикой установки.',403);
    $cfg=oidcConfig(); $d=oidcDiscovery();
    $tx=['state'=>b64url(random_bytes(32)),'nonce'=>b64url(random_bytes(32)),'verifier'=>b64url(random_bytes(48)),'at'=>time(),'guest'=>$guestToken,'staff'=>$staffInvite,'switch'=>$switch,'link'=>$link];
    $_SESSION['oidc_tx']=$tx;
    header('Location: '.$d['authorization_endpoint'].'?'.http_build_query(['client_id'=>$cfg['id'],'redirect_uri'=>$cfg['callback'],'response_type'=>'code','scope'=>'openid profile email','state'=>$tx['state'],'nonce'=>$tx['nonce'],'code_challenge'=>b64url(hash('sha256',$tx['verifier'],true)),'code_challenge_method'=>'S256']+($link!==null?['prompt'=>'login','max_age'=>0]:($switch!==null?['prompt'=>'login']:[])),'','&',PHP_QUERY_RFC3986),true,303); exit;
}
function oidcTokenSet(array $response,?array $old=null): array {
    if (!is_string($response['access_token']??null)||$response['access_token']===''||strlen($response['access_token'])>65536||strtolower((string)($response['token_type']??''))!=='bearer'||!is_int($response['expires_in']??null)||$response['expires_in']<1) throw new Problem('Некорректный набор токенов.',401);
    $set=['access'=>$response['access_token'],'until'=>time()+min(86400,$response['expires_in']),'refresh'=>$response['refresh_token']??$old['refresh']??null,'id'=>$response['id_token']??$old['id']??null,'checked'=>0];
    foreach (['refresh','id'] as $key) if (!is_string($set[$key])||$set[$key]===''||strlen($set[$key])>65536) throw new Problem('Провайдер не выдал серверные токены.',401);
    return $set;
}
function oidcOnline(array &$tokens,array $session): void {
    if ($tokens['until']<=time()+10) {
        $response=oidcHttp(oidcDiscovery()['token_endpoint'],['grant_type'=>'refresh_token','refresh_token'=>$tokens['refresh']]);
        $tokens=oidcTokenSet($response,$tokens);
        if (isset($response['id_token'])) {
            $c=oidcClaims($tokens['id']);
            if ($c['sub']!==$session['subject']||(isset($c['sid'])&&$session['sid']!==null&&$c['sid']!==$session['sid'])) throw new Problem('Сессия ID изменилась.',401);
        }
    }
    if ($tokens['checked']<=time()-30) {
        $c=oidcHttp(oidcDiscovery()['introspection_endpoint'],['token'=>$tokens['access'],'token_type_hint'=>'access_token']);
        if (($c['active']??false)!==true||($c['sub']??null)!==$session['subject']||(($c['client_id']??$c['azp']??null)!==oidcConfig()['id'])||(isset($c['iss'])&&$c['iss']!==ID_ISSUER)||(isset($c['sid'])&&$session['sid']!==null&&$c['sid']!==$session['sid'])) throw new Problem('Сессия ID завершена.',401);
        $tokens['checked']=time();
    }
}
