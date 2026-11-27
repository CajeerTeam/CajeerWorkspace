<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

// Dedicated HTTPS origin; proxy must route the documented exact paths to public/index.php.
function aiMcpConfig(): array {
    $origin=getenv('WORKSPACE_CHATGPT_ORIGIN');$client=getenv('WORKSPACE_CHATGPT_CLIENT_ID');$redirect=getenv('WORKSPACE_CHATGPT_REDIRECT_URI');
    if(!aiEnabled()||getenv('WORKSPACE_CHATGPT_ENABLED')!=='1'||!is_string($origin)||!preg_match('~\Ahttps://[a-zA-Z0-9][a-zA-Z0-9.-]*(?::[0-9]{1,5})?\z~',$origin)||!is_string($client)||!preg_match('~\A[A-Za-z0-9._-]{16,128}\z~',$client)||!is_string($redirect)||!preg_match('~\Ahttps://chatgpt\.com/(?:connector_platform_oauth_redirect|connector/oauth/[A-Za-z0-9_-]+)\z~',$redirect))throw new Problem('Интеграция ChatGPT отключена или не настроена.',503);
    return ['origin'=>$origin,'resource'=>$origin.'/mcp','client'=>$client,'redirect'=>$redirect];
}
function aiMcpBinding(array $c): string {return hash('sha256',json_encode($c,JSON_THROW_ON_ERROR));}
function aiMcpAvailable(): bool {try{aiMcpConfig();return true;}catch(Problem){return false;}}
function aiMcpPolicy(PDO $db,int $org): array {
    $r=query($db,'SELECT enabled,generation FROM ai_delegation_policy WHERE organization_id=?',[$org])->fetch();return ['enabled'=>(int)($r['enabled']??0)===1,'generation'=>(int)($r['generation']??0)];
}
function aiMcpSetPolicy(PDO $db,array $u,bool $enabled): void {
    aiMcpConfig();requireIdentitySession($db,$u);atomic($db,function()use($db,$u,$enabled){$u=orgOwner($db,$u);$org=(int)$u['organization_id'];query($db,'INSERT INTO ai_delegation_policy(organization_id,enabled,generation) VALUES(?,?,1) ON CONFLICT(organization_id) DO UPDATE SET enabled=excluded.enabled,generation=ai_delegation_policy.generation+1',[$org,(int)$enabled]);query($db,'DELETE FROM ai_delegations WHERE organization_id=?',[$org]);auditEvent($db,$u,null,'ai.chatgpt-policy',$org,$enabled?'enabled':'disabled');});
}
function aiMcpRevoke(PDO $db,array $u): void {requireIdentitySession($db,$u);query($db,'DELETE FROM ai_delegations WHERE organization_id=? AND user_id=?',[$u['organization_id'],$u['id']]);}
function aiMcpRequest(array $f,array $c): array {
    $names=['response_type','client_id','redirect_uri','resource','scope','state','code_challenge','code_challenge_method'];$r=[];foreach($names as $k){$v=$f[$k]??null;if(!is_string($v)||strlen($v)>1024)throw new Problem('Некорректный OAuth-запрос.',400);$r[$k]=$v;}
    if($r['response_type']!=='code'||$r['client_id']!==$c['client']||$r['redirect_uri']!==$c['redirect']||$r['resource']!==$c['resource']||$r['scope']!=='project:read'||$r['code_challenge_method']!=='S256'||!preg_match('/\A[A-Za-z0-9_-]{43}\z/',$r['code_challenge'])||$r['state']==='')throw new Problem('OAuth-клиент, ресурс или PKCE не разрешены.',400);return $r;
}
function aiMcpIssue(PDO $db,array $u,int $p,array $r,array $c): string {
    requireIdentitySession($db,$u);if(($u['auth_kind']??'')==='emergency')throw new Problem('Аварийный вход не делегируется.',403);
    return atomic($db,function()use($db,$u,$p,$r,$c){$u=organizationUser($db,$u);$org=(int)$u['organization_id'];$policy=aiMcpPolicy($db,$org);if(!$policy['enabled'])throw new Problem('Владелец не разрешил ChatGPT.',403);$cfg=aiConfig();aiProject($db,$u,$p,$cfg);$ai=aiPolicy($db,$org,$cfg);if(!array_intersect($ai['data'],AI_DEFAULT_DATA))throw new Problem('Нет разрешённых данных.',403);
        $code=bin2hex(random_bytes(32));query($db,'DELETE FROM ai_delegations WHERE expires_at<=?',[time()]);query($db,'INSERT INTO ai_delegations(token_hash,organization_id,user_id,project_id,session_hash,session_version,auth_kind,generation,policy_version,client_binding,challenge,expires_at,kind) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[hash('sha256',$code),$org,$u['id'],$p,staffHandle(),$u['session_version'],$u['auth_kind'],$policy['generation'],$ai['version'],aiMcpBinding($c),$r['code_challenge'],time()+120,'code']);auditEvent($db,$u,$p,'ai.chatgpt-consent',$p);return $code;});
}
function aiMcpPrincipal(PDO $db,array $r): array {
    if(!hash_equals($r['client_binding'],aiMcpBinding(aiMcpConfig())))throw new Problem('Конфигурация делегирования изменена.',401);
    if((int)$r['expires_at']<=time())throw new Problem('Делегирование истекло.',401);
    $org=(int)$r['organization_id'];$uid=(int)$r['user_id'];$p=(int)$r['project_id'];$policy=aiMcpPolicy($db,$org);
    if(!$policy['enabled']||$policy['generation']!==(int)$r['generation'])throw new Problem('Делегирование отозвано.',401);
    $u=query($db,'SELECT id,session_version FROM users WHERE id=? AND enabled=1',[$uid])->fetch();if(!$u||(int)$u['session_version']!==(int)$r['session_version'])throw new Problem('Сессия отозвана.',401);
    if(!in_array($r['auth_kind'],['local','oidc'],true)||!organizationAuthAllowed($db,$org,$r['auth_kind'],$uid))throw new Problem('Способ входа больше не разрешён.',401);
    if(!query($db,'SELECT 1 FROM staff_sessions WHERE handle=? AND user_id=? AND session_version=? AND kind=? AND revoked_at IS NULL AND expires_at>? AND seen_at>=?',[$r['session_hash'],$uid,$u['session_version'],$r['auth_kind'],time(),time()-1800])->fetchColumn())throw new Problem('Сессия истекла.',401);
    if($r['auth_kind']==='oidc'&&!query($db,'SELECT 1 FROM oidc_sessions WHERE handle=? AND user_id=? AND revoked_at IS NULL AND expires_at>? AND EXISTS (SELECT 1 FROM oidc_identities i WHERE i.user_id=oidc_sessions.user_id AND i.issuer=oidc_sessions.issuer AND i.subject=oidc_sessions.subject)',[$r['session_hash'],$uid,time()])->fetchColumn())throw new Problem('Сессия ID отозвана.',401);
    $u=organizationUser($db,$u+['auth_kind'=>$r['auth_kind']],$org);$cfg=aiConfig();aiProject($db,$u,$p,$cfg);$ai=aiPolicy($db,$org,$cfg);if($ai['version']!==(int)$r['policy_version'])throw new Problem('Политика AI изменена.',401);return $u;
}
function aiMcpExchange(PDO $db,array $f,array $c): array {
    foreach(['grant_type','code','code_verifier','client_id','redirect_uri','resource'] as $k)if(!is_string($f[$k]??null)||strlen($f[$k])>1024)throw new Problem('invalid_grant',400);
    if($f['grant_type']!=='authorization_code'||$f['client_id']!==$c['client']||$f['redirect_uri']!==$c['redirect']||$f['resource']!==$c['resource']||!preg_match('/\A[a-f0-9]{64}\z/',$f['code'])||!preg_match('/\A[A-Za-z0-9._~-]{43,128}\z/',$f['code_verifier']))throw new Problem('invalid_grant',400);
    return atomic($db,function()use($db,$f){$r=query($db,"SELECT * FROM ai_delegations WHERE token_hash=? AND kind='code'",[hash('sha256',$f['code'])])->fetch();if(!$r||!hash_equals($r['challenge'],b64url(hash('sha256',$f['code_verifier'],true))))throw new Problem('invalid_grant',400);aiMcpPrincipal($db,$r);$token=bin2hex(random_bytes(32));query($db,"UPDATE ai_delegations SET token_hash=?,kind='token',challenge='',expires_at=? WHERE token_hash=?",[hash('sha256',$token),time()+900,$r['token_hash']]);return ['access_token'=>$token,'token_type'=>'Bearer','expires_in'=>900,'scope'=>'project:read'];});
}
function aiMcpTools(): array {
    $tools=[];foreach(['tasks'=>'Read tasks and blockers','milestones'=>'Read milestones and progress','releases'=>'Read stored release and CI observations'] as $k=>$label)$tools[]=['name'=>'workspace_'.$k,'description'=>$label.' in the single explicitly delegated project. Data may be incomplete or stale.','inputSchema'=>['type'=>'object','properties'=>['query'=>['type'=>'string','maxLength'=>120]],'additionalProperties'=>false],'annotations'=>['readOnlyHint'=>true,'destructiveHint'=>false,'openWorldHint'=>false,'idempotentHint'=>true],'securitySchemes'=>[['type'=>'oauth2','scopes'=>['project:read']]],'_meta'=>['securitySchemes'=>[['type'=>'oauth2','scopes'=>['project:read']]]]];return $tools;
}
function aiMcpCall(PDO $db,array $r,string $name,array $args,array $c): array {
    $kind=match($name){'workspace_tasks'=>'tasks','workspace_milestones'=>'milestones','workspace_releases'=>'releases',default=>throw new Problem('Unknown tool',404)};
    if(array_diff(array_keys($args),['query'])||!is_string($args['query']??''))throw new Problem('Invalid arguments',400);
    $u=aiMcpPrincipal($db,$r);$cfg=aiConfig();$p=(int)$r['project_id'];authThrottle($db,'ai.mcp:'.$u['id'],40);
    $context=aiContext($db,$u,$p,$kind,$args['query']??'',$cfg);
    // Re-read the token and all current rights before returning any data.
    $fresh=query($db,"SELECT * FROM ai_delegations WHERE token_hash=? AND kind='token'",[$r['token_hash']])->fetch();if(!$fresh)throw new Problem('Делегирование отозвано.',401);$u=aiMcpPrincipal($db,$fresh);if($context!==aiContext($db,$u,$p,$kind,$args['query']??'',$cfg))throw new Problem('Данные или права изменены.',409);
    $sources=$context['sources'];foreach($sources as &$s){$s['href']=$c['origin'].'/'.substr($s['href'],2);}unset($s);
    $data=['project'=>$context['title'],'observed_at'=>gmdate('c'),'coverage'=>$context['coverage'],'sources'=>$sources];return ['content'=>[['type'=>'text','text'=>json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]],'structuredContent'=>$data];
}
function aiMcpJson(array $data,int $status=200): never {http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function aiMcpRoute(PDO $db,string $path,string $method): never {
    $c=aiMcpConfig();$origin=$c['origin'];$resource=$c['resource'];
    if((empty($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off')&&getenv('WORKSPACE_HTTPS')!=='1')throw new Problem('HTTPS required',403);
    if(strtolower($_SERVER['HTTP_HOST']??'')!==strtolower(substr($origin,8)))throw new Problem('Host not allowed',403);
    if($path==='/.well-known/oauth-protected-resource'||$path==='/.well-known/oauth-protected-resource/mcp'){if($method!=='GET')throw new Problem('Method not allowed',405);aiMcpJson(['resource'=>$resource,'authorization_servers'=>[$origin],'scopes_supported'=>['project:read'],'bearer_methods_supported'=>['header']]);}
    if($path==='/.well-known/oauth-authorization-server'){if($method!=='GET')throw new Problem('Method not allowed',405);aiMcpJson(['issuer'=>$origin,'authorization_endpoint'=>$origin.'/oauth/authorize','token_endpoint'=>$origin.'/oauth/token','response_types_supported'=>['code'],'grant_types_supported'=>['authorization_code'],'code_challenge_methods_supported'=>['S256'],'token_endpoint_auth_methods_supported'=>['none'],'scopes_supported'=>['project:read'],'authorization_response_iss_parameter_supported'=>true]);}
    if($path==='/oauth/token'){if($method!=='POST')throw new Problem('Method not allowed',405);try{authThrottle($db,'ai.oauth.token:'.($_SERVER['REMOTE_ADDR']??''),60);aiMcpJson(aiMcpExchange($db,$_POST,$c));}catch(Problem){aiMcpJson(['error'=>'invalid_grant'],400);}}
    if($path==='/oauth/authorize'){
        if(!in_array($method,['GET','POST'],true))throw new Problem('Method not allowed',405);$r=aiMcpRequest($method==='GET'?$_GET:$_POST,$c);sessionOpen();if(($_SESSION['auth_kind']??null)==='oidc'&&!validateOidcSession($db))throw new Problem('Сессия ID истекла.',401);$u=currentUser($db);
        if(!$u||empty($u['organization_id'])||!mfaFresh()){header('Content-Type: text/html; charset=utf-8');echo '<h1>Войдите в Workspace и подтвердите MFA</h1><p><a href="/">Открыть Workspace</a>. После входа повторите подключение в ChatGPT.</p>';exit;}
        if($method==='POST'){if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['csrf'],$_POST['csrf'])||($_SESSION['mcp_consent']['request']??null)!==$r||($_SESSION['mcp_consent']['until']??0)<time())throw new Problem('Нет действительного запроса согласия.',403);unset($_SESSION['mcp_consent']);if(($_POST['consent']??'')!=='yes'){header('Location: '.$c['redirect'].'?'.http_build_query(['error'=>'access_denied','state'=>$r['state'],'iss'=>$origin],'','&',PHP_QUERY_RFC3986),true,303);exit;}$code=aiMcpIssue($db,$u,numberId($_POST['project']??0),$r,$c);header('Location: '.$c['redirect'].'?'.http_build_query(['code'=>$code,'state'=>$r['state'],'iss'=>$origin],'','&',PHP_QUERY_RFC3986),true,303);exit;}
        if(!aiMcpPolicy($db,(int)$u['organization_id'])['enabled'])throw new Problem('Владелец организации не разрешил ChatGPT.',403);$projects=aiProjects($db,$u,aiConfig());$_SESSION['mcp_consent']=['request'=>$r,'until'=>time()+300];header('Content-Type: text/html; charset=utf-8');echo '<h1>Делегировать ChatGPT чтение одного проекта</h1><p>Организация: '.h($u['organization_name']).'. Только задачи, вехи, выпуски; доступ действует до 15 минут и прекращается при отзыве сессии.</p><form method="post" action="/oauth/authorize">';foreach($r as $k=>$v)echo '<input type="hidden" name="'.h($k).'" value="'.h($v).'">';echo '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'"><select name="project" required>';selectOptions($projects);echo '</select><p>Выберите проект и подтвердите передачу данных.</p><button name="consent" value="yes">Разрешить</button><button name="consent" value="no" formnovalidate>Отказать</button></form>';exit;
    }
    if($path!=='/mcp')throw new Problem('Not found',404);
    // Stateless Streamable HTTP: no SSE/session transport, no cookies for MCP.
    if($method!=='POST'){header('Allow: POST');aiMcpJson(['error'=>'method_not_allowed'],405);}
    $header=$_SERVER['HTTP_AUTHORIZATION']??'';$r=null;
    if(preg_match('/\ABearer ([a-f0-9]{64})\z/i',$header,$m))$r=query($db,"SELECT * FROM ai_delegations WHERE token_hash=? AND kind='token'",[hash('sha256',$m[1])])->fetch();
    
    // Browser origins are unnecessary; reject unexpected Origin to avoid DNS rebinding.
    if(isset($_SERVER['HTTP_ORIGIN'])&&!in_array($_SERVER['HTTP_ORIGIN'],[$origin,'https://chatgpt.com'],true))aiMcpJson(['error'=>'invalid_origin'],403);
    $body=file_get_contents('php://input',false,null,0,65537);$id=null;
    try{if(!is_string($body)||strlen($body)>65536)throw new Problem('Invalid request',400);$rpc=json_decode($body,true,16,JSON_THROW_ON_ERROR);if(!is_array($rpc)||($rpc['jsonrpc']??null)!=='2.0'||!is_string($rpc['method']??null))throw new Problem('Invalid request',400);$id=$rpc['id']??null;if(!array_key_exists('id',$rpc)){if(in_array($rpc['method'],['notifications/initialized','notifications/cancelled'],true)){http_response_code(202);exit;}throw new Problem('Invalid notification',400);}if(!is_string($id)&&!is_int($id))throw new Problem('Invalid id',400);
        if($rpc['method']==='tools/call'){try{if(!$r)throw new Problem('Unauthorized',401);aiMcpPrincipal($db,$r);}catch(Problem){header('WWW-Authenticate: Bearer resource_metadata="'.$origin.'/.well-known/oauth-protected-resource/mcp", scope="project:read"');aiMcpJson(['error'=>'invalid_token'],401);}}
        $params=$rpc['params']??[];if(!is_array($params))throw new Problem('Invalid params',400);
        $result=match($rpc['method']){'initialize'=>['protocolVersion'=>in_array($params['protocolVersion']??null,['2025-03-26','2025-06-18','2025-11-25'],true)?$params['protocolVersion']:'2025-06-18','capabilities'=>['tools'=>(object)[]],'serverInfo'=>['name'=>'CajeerWorkspace','version'=>VERSION]],'ping'=>(object)[],'tools/list'=>['tools'=>aiMcpTools()],'tools/call'=>aiMcpCall($db,$r,is_string($params['name']??null)?$params['name']:'',is_array($params['arguments']??[])?($params['arguments']??[]):throw new Problem('Invalid arguments',400),$c),default=>throw new Problem('Method not found',404)};
        aiMcpJson(['jsonrpc'=>'2.0','id'=>$id,'result'=>$result]);
    }catch(\JsonException){aiMcpJson(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32700,'message'=>'Parse error']]);}catch(Problem $e){aiMcpJson(['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>$e->status===404?-32601:-32000,'message'=>'Request denied or unavailable']]);}
}
