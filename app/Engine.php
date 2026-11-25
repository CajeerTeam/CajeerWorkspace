<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const ENGINE_STATES=['ready'=>'Ожидает подтверждения','sending'=>'Отправляется / требуется сверка','published'=>'Публикация подтверждена ответом Engine','conflict'=>'Редакция изменилась / отказ','uncertain'=>'Результат неизвестен — не повторять','observed'=>'Публикация обнаружена при сверке','cancelled'=>'Отменено'];
function engineSegment(mixed $v): string {
    if(!is_string($v)||!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,127}\z/',$v))throw new Problem('Некорректный идентификатор Engine.',400);
    return $v;
}
function engineOrigin(string $base): string {
    $p=parse_url($base);
    if(!$p||!isset($p['scheme'],$p['host'])||!in_array($p['path']??'', ['','/'],true))throw new Problem('Engine должен быть настроен на отдельном origin без пути.',409);
    $scheme=strtolower($p['scheme']);$port=$p['port']??($scheme==='https'?443:80);
    if($scheme!=='https'&& !($scheme==='http'&&in_array(strtolower($p['host']),['127.0.0.1','localhost','[::1]'],true)))throw new Problem('Для Engine требуется HTTPS.',409);
    return $scheme.'://'.strtolower($p['host']).(($scheme==='https'&&$port===443||$scheme==='http'&&$port===80)?'':':'.$port);
}
function engineConfig(array $c): array {
    if(($c['type']??null)!=='engine'||empty($c['base'])||empty($c['env'])||($c['installed_version']??null)!=='2.1.0')throw new Problem('Настройте Engine 2.1.0 и secret_env в конфигурации подключения.',409);
    $c['origin']=engineOrigin($c['base']);return $c;
}
function engineHttp(array $c,string $method,string $path,?array $body=null): array {
    $token=getenv($c['env']);if(!is_string($token)||strlen($token)<16||strlen($token)>4096||preg_match('/[^\x21-\x7e]/',$token))throw new Problem('API token Engine не настроен.',503);
    if(!extension_loaded('curl'))throw new Problem('Требуется cURL.',503);
    $ch=curl_init($c['origin'].'/api/v1'.$path);$raw='';$headers=['Accept: application/json','Authorization: Bearer '.$token,'Cache-Control: no-cache'];
    $opt=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS|CURLPROTO_HTTP,CURLOPT_CONNECTTIMEOUT_MS=>2000,CURLOPT_TIMEOUT_MS=>8000,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'CajeerWorkspace/'.VERSION,CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$raw):int{if(strlen($raw)+strlen($chunk)>4*1024*1024)return 0;$raw.=$chunk;return strlen($chunk);}];
    if($body!==null){$headers[]='Content-Type: application/json';$opt[CURLOPT_POSTFIELDS]=json_encode($body,JSON_THROW_ON_ERROR);}$opt[CURLOPT_HTTPHEADER]=$headers;
    curl_setopt_array($ch,$opt);$ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$mime=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);unset($ch);
    if($ok===false)throw new Problem('Сеть, TLS или лимит ответа Engine.',503);
    if($status!==200)throw new Problem('Engine HTTP '.$status,$status===409?409:503);
    if(!preg_match('~^application/(?:json|[a-z0-9.+-]+\+json)(?:\s*;|$)~i',$mime))throw new Problem('Неожиданный тип ответа Engine.',503);
    try{$out=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new Problem('Некорректный JSON Engine.',503);}
    if(!is_array($out)||array_is_list($out))throw new Problem('Некорректный объект Engine.',503);return $out;
}
function engineCall(array $c,string $method,string $path,?array $body=null,?callable $transport=null): array {
    $r=($transport??__NAMESPACE__.'\\engineHttp')($c,$method,$path,$body);
    if(!is_array($r)||!is_array($r['data']??null))throw new Problem('Некорректный ответ Engine.',503);return $r;
}
function engineSnapshot(array $row): array {
    $s=[];foreach(['title','slug','locale','translation_group','status'] as $k){if(!is_string($row[$k]??null)||strlen($row[$k])>4096||!preg_match('//u',$row[$k]))throw new Problem('Некорректный снимок Engine.',503);$s[$k]=$row[$k];}
    if(!in_array($s['status'],['draft','published','archived'],true)||!is_array($row['data']??null)||!is_int($row['revision']??null)||$row['revision']<1||$row['revision']>=2147483647)throw new Problem('Некорректная редакция Engine.',503);
    $s['revision']=$row['revision'];$s['data']=$row['data'];return $s;
}
function engineCanonical(mixed $v): mixed {if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as &$x)$x=engineCanonical($x);unset($x);return $v;}
function engineJson(array $s): string {$j=json_encode(engineCanonical($s),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);if(strlen($j)>512*1024)throw new Problem('Снимок превышает 512 КиБ.',409);return $j;}
function engineHash(array $s): string {return hash('sha256',engineJson($s));}
function engineEntry(array $c,string $type,string $id,?callable $transport=null): array {
    $r=engineCall($c,'GET','/content/'.engineSegment($type).'/'.engineSegment($id),null,$transport)['data'];
    if(($r['id']??null)!==$id||($r['type']??null)!==$type)throw new Problem('Engine вернул другую запись.',503);return engineSnapshot($r);
}
function engineRevisions(array $c,string $type,string $id,?callable $transport=null): array {
    $rows=engineCall($c,'GET','/content/'.engineSegment($type).'/'.engineSegment($id).'/revisions',null,$transport)['data'];
    if(!array_is_list($rows)||count($rows)>10000)throw new Problem('Некорректная история Engine.',503);
    $out=[];foreach($rows as $r){if(!is_array($r))throw new Problem('Некорректная история Engine.',503);$s=engineSnapshot($r);if(isset($out[$s['revision']]))throw new Problem('Повтор редакции Engine.',503);$out[$s['revision']]=$s;}krsort($out);return $out;
}
function engineTypes(array $c,?callable $transport=null): array {
    $rows=engineCall($c,'GET','/content-types',null,$transport)['data'];if(!array_is_list($rows)||count($rows)>500)throw new Problem('Некорректный каталог типов.',503);
    $out=[];foreach($rows as $r){if(!is_array($r))throw new Problem('Некорректный тип.',503);$type=engineSegment($r['handle']??null);if(isset($out[$type]))throw new Problem('Повтор типа.',503);$out[$type]=is_string($r['name']??null)?$r['name']:$type;}return $out;
}
function engineList(array $c,string $type,int $offset=0,string $q='',?callable $transport=null): array {
    if($offset<0||$offset>1000000)throw new Problem('Некорректная страница.');
    $r=engineCall($c,'GET','/content/'.engineSegment($type).'?'.http_build_query(['limit'=>20,'offset'=>$offset,'q'=>$q],'','&',PHP_QUERY_RFC3986),null,$transport);
    $rows=$r['data'];$m=$r['meta']??[];if(!array_is_list($rows)||count($rows)>20||!is_int($m['total']??null)||$m['total']<0||($m['offset']??null)!==$offset||($m['limit']??null)!==20)throw new Problem('Некорректная пагинация Engine.',503);
    $out=[];foreach($rows as $row){if(!is_array($row))throw new Problem('Некорректная запись Engine.',503);$id=engineSegment($row['id']??null);if(isset($out[$id]))throw new Problem('Дублирование материала.',503);$out[$id]=engineSnapshot($row)+['public_path'=>$row['public_path']??null];}return ['items'=>$out,'total'=>$m['total']];
}
function enginePublicUrl(array $c,mixed $path): ?string {
    // Accept only origin-relative paths supplied by the trusted route index, never an arbitrary URL.
    if(!is_string($path)||strlen($path)>2048||!str_starts_with($path,'/')||str_starts_with($path,'//')||preg_match('~[\\x00-\\x20\\x7f\\\\?#]~',$path)||preg_match('~%(?:2f|5c|0[0-9a-f]|1[0-9a-f]|7f)~i',$path))return null;
    return $c['origin'].$path;
}
function engineFindUrl(array $c,string $type,string $id,array $expected,?callable $transport=null): ?string {
    for($offset=0;$offset<100;$offset+=20){$list=engineList($c,$type,$offset,$expected['slug'],$transport);$row=$list['items'][$id]??null;if($row)return engineHash(engineSnapshot($row))===engineHash($expected)?enginePublicUrl($c,$row['public_path']):null;if($offset+20>=$list['total'])break;}return null;
}
function engineSite(PDO $db,array $u,int $project,int $site): array {
    requireProjectRight($db,$u,$project,'connections');return entity($db,'SELECT * FROM engine_sites WHERE id=? AND project_id=? AND organization_id=?',[$site,$project,$u['organization_id']]);
}
function engineSiteConfig(PDO $db,array $u,array $site): array {
    if(!(int)$site['active']||!connectionAllowed($db,$u,$site['connection_key'])||!query($db,'SELECT 1 FROM project_connections WHERE project_id=? AND connection_key=?',[$site['project_id'],$site['connection_key']])->fetchColumn())throw new Problem('Привязка сайта отключена или отозвана.',409);
    $c=engineConfig(connections()[$site['connection_key']]??[]);
    if(!hash_equals($site['config_hash'],$c['hash'])||!hash_equals($site['target_hash'],hash('sha256',$c['origin'])))throw new Problem('Конфигурация Engine изменилась. Публикация заблокирована.',409);return $c;
}
function engineBind(PDO $db,array $u,int $project,string $key,string $name,?callable $transport=null): int {
    $u=orgOwner($db,$u);allowedProject($db,$u,$project);$c=engineConfig(connections()[$key]??[]);if(!connectionAllowed($db,$u,$key))throw new Problem('Нет доступа к подключению.',403);
    engineTypes($c,$transport);$name=word($name,120,'Сайт');$target=hash('sha256',$c['origin']);
    return atomic($db,function()use($db,$u,$project,$key,$name,$target,$c){$u=orgOwner($db,$u);allowedProject($db,$u,$project);$fresh=engineConfig(connections()[$key]??[]);if(!connectionAllowed($db,$u,$key)||$fresh['hash']!==$c['hash'])throw new Problem('Подключение изменилось.',409);
        $old=query($db,'SELECT * FROM engine_sites WHERE connection_key=? OR target_hash=?',[$key,$target])->fetch();if($old){if((int)$old['project_id']===$project&&$old['connection_key']===$key&&$old['config_hash']===$c['hash'])return (int)$old['id'];throw new Problem('Engine уже привязан или конфигурация изменилась.',409);}
        query($db,'INSERT INTO project_connections(project_id,connection_key) VALUES(?,?) ON CONFLICT(project_id,connection_key) DO NOTHING',[$project,$key]);
        $id=insertId($db,'INSERT INTO engine_sites(organization_id,project_id,connection_key,target_hash,config_hash,name,created_by,created_at) VALUES(?,?,?,?,?,?,?,?)',[$u['organization_id'],$project,$key,$target,$c['hash'],$name,$u['id'],time()]);auditEvent($db,$u,$project,'engine.bind',$id,$key);return $id;});
}
function engineToggle(PDO $db,array $u,int $project,int $site,bool $active): void {atomic($db,function()use($db,$u,$project,$site,$active){$u=orgOwner($db,$u);$s=engineSite($db,$u,$project,$site);if((bool)$s['active']===$active)return;if($active){$s['active']=1;engineSiteConfig($db,$u,$s);}query($db,'UPDATE engine_sites SET active=? WHERE id=?',[$active?1:0,$site]);auditEvent($db,$u,$project,'engine.site',$site,$active?'enabled':'disabled');});}
function enginePublication(PDO $db,array $u,int $project,int $id): array {requireProjectRight($db,$u,$project,'connections');return entity($db,'SELECT * FROM engine_publications WHERE id=? AND project_id=?',[$id,$project]);}
function enginePrepare(PDO $db,array $u,int $project,int $site,string $type,string $id,int $revision,string $hash,?callable $transport=null): int {
    requireProjectRight($db,$u,$project,'manage');$s=engineSite($db,$u,$project,$site);$c=engineSiteConfig($db,$u,$s);$current=engineEntry($c,$type,$id,$transport);
    if($current['status']!=='draft'||$current['revision']!==$revision||!hash_equals(engineHash($current),$hash))throw new Problem('Черновик изменился после предпросмотра.',409);
    return atomic($db,function()use($db,$u,$project,$site,$type,$id,$revision,$hash,$current,$c){$u=organizationUser($db,$u);requireProjectRight($db,$u,$project,'manage');$s=engineSite($db,$u,$project,$site);if(engineSiteConfig($db,$u,$s)['hash']!==$c['hash'])throw new Problem('Подключение изменилось.',409);
        $old=query($db,'SELECT * FROM engine_publications WHERE site_id=? AND content_type=? AND entry_id=? AND revision=?',[$site,$type,$id,$revision])->fetch();if($old){if(!hash_equals($old['snapshot_hash'],$hash))throw new Problem('Снимок изменился.',409);return (int)$old['id'];}
        $pk=insertId($db,'INSERT INTO engine_publications(project_id,site_id,content_type,entry_id,revision,snapshot,snapshot_hash,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$project,$site,$type,$id,$revision,engineJson($current),$hash,$u['id'],time(),time()]);auditEvent($db,$u,$project,'engine.prepare',$pk,$hash);return $pk;});
}
function engineExpected(array $p): array {$s=engineSnapshot(json_decode($p['snapshot'],true,64,JSON_THROW_ON_ERROR));if(!hash_equals($p['snapshot_hash'],engineHash($s)))throw new Problem('Повреждён снимок публикации.',503);$s['status']='published';$s['revision']++;return $s;}
function engineResult(PDO $db,array $u,array $p,string $state,string $detail,?int $revision=null,?string $url=null): string {query($db,'UPDATE engine_publications SET state=?,detail=?,result_revision=?,public_url=?,updated_at=? WHERE id=?',[$state,$detail,$revision,$url,time(),$p['id']]);auditEvent($db,$u,(int)$p['project_id'],'engine.'.$state,(int)$p['id'],$detail);return $state;}
function engineConfirm(PDO $db,array $u,int $project,int $id,string $hash,?callable $transport=null): string {
    // Commit the intent BEFORE contacting the remote writer. Never automatically repeat an uncertain PATCH.
    $claimed=atomic($db,function()use($db,$u,$project,$id,$hash){$u=organizationUser($db,$u);requireProjectRight($db,$u,$project,'manage');$p=enginePublication($db,$u,$project,$id);if(!hash_equals($p['snapshot_hash'],$hash))throw new Problem('Неверный снимок подтверждения.',409);if($p['state']!=='ready')return $p['state'];engineExpected($p);engineSiteConfig($db,$u,engineSite($db,$u,$project,(int)$p['site_id']));query($db,"UPDATE engine_publications SET state='sending',confirmed_by=?,attempted_at=?,updated_at=? WHERE id=?",[$u['id'],time(),time(),$id]);auditEvent($db,$u,$project,'engine.confirm',$id,$hash);return null;});if($claimed!==null)return $claimed;
    $state=atomic($db,function()use($db,$u,$project,$id,$transport){$u=organizationUser($db,$u);requireProjectRight($db,$u,$project,'manage');$p=enginePublication($db,$u,$project,$id);if($p['state']!=='sending')return $p['state'];$expected=engineExpected($p);
        try{$c=engineSiteConfig($db,$u,engineSite($db,$u,$project,(int)$p['site_id']));$current=engineEntry($c,$p['content_type'],$p['entry_id'],$transport);}catch(\Throwable){return engineResult($db,$u,$p,'uncertain','Не удалось проверить текущую запись; повтор запрещён, выполните сверку.');}
        if(!hash_equals($p['snapshot_hash'],engineHash($current)))return engineResult($db,$u,$p,'conflict','Черновик изменился до отправки.');
        try{$r=engineCall($c,'PATCH','/content/'.engineSegment($p['content_type']).'/'.engineSegment($p['entry_id']),['status'=>'published','expected_revision'=>(int)$p['revision']],$transport)['data'];if(($r['id']??null)!==$p['entry_id']||($r['type']??null)!==$p['content_type']||engineHash(engineSnapshot($r))!==engineHash($expected))throw new Problem('Неожиданный результат.');}
        catch(Problem $e){return engineResult($db,$u,$p,$e->status===409?'conflict':'uncertain',$e->status===409?'Engine отклонил устаревшую редакцию.':'Результат PATCH неизвестен. Выполните сверку, не повторяйте запрос.');}catch(\Throwable){return engineResult($db,$u,$p,'uncertain','Результат PATCH неизвестен.');}
        // The receipt is recorded without trusting a URL in the mutation response.
        return engineResult($db,$u,$p,'published','Engine подтвердил точную опубликованную редакцию.',(int)$expected['revision']);});
    if($state==='published'){try{engineReconcile($db,$u,$project,$id,$transport);}catch(\Throwable){/* Publication receipt is durable; retry only read-side reconciliation. */}}return $state;
}
function engineReconcile(PDO $db,array $u,int $project,int $id,?callable $transport=null): string {
    requireProjectRight($db,$u,$project,'manage');$p=enginePublication($db,$u,$project,$id);if(!in_array($p['state'],['sending','uncertain','published','observed'],true))throw new Problem('Сверка не применима.',409);if($p['state']==='sending'&&(int)$p['attempted_at']>time()-120)throw new Problem('Дождитесь завершения отправки (до 2 минут).',409);
    $c=engineSiteConfig($db,$u,engineSite($db,$u,$project,(int)$p['site_id']));$expected=engineExpected($p);$current=engineEntry($c,$p['content_type'],$p['entry_id'],$transport);$matches=engineHash($current)===engineHash($expected);$seen=$matches;
    if(!$seen&&!in_array($p['state'],['published','observed'],true)){$history=engineRevisions($c,$p['content_type'],$p['entry_id'],$transport);$snap=$history[$expected['revision']]??null;$seen=$snap!==null&&engineHash($snap)===engineHash($expected);}
    $url=null;if($matches){try{$url=engineFindUrl($c,$p['content_type'],$p['entry_id'],$expected,$transport);}catch(\Throwable){}}
    return atomic($db,function()use($db,$u,$project,$p,$c,$seen,$url,$matches,$expected){$u=organizationUser($db,$u);requireProjectRight($db,$u,$project,'manage');$fresh=enginePublication($db,$u,$project,(int)$p['id']);if($fresh['state']!==$p['state']||(int)$fresh['updated_at']!==(int)$p['updated_at']||engineSiteConfig($db,$u,engineSite($db,$u,$project,(int)$p['site_id']))['hash']!==$c['hash'])throw new Problem('Состояние изменилось.',409);
        $confirmed=in_array($p['state'],['published','observed'],true);$state=$confirmed?$p['state']:($seen?'observed':'uncertain');$detail=$confirmed||$seen?($matches?($url?'Текущая редакция и маршрут подтверждены.':'Редакция подтверждена; публичный маршрут не найден.'):'Историческая редакция; текущий материал изменился.'): 'Доказательство публикации не найдено; повтор PATCH запрещён.';
        return engineResult($db,$u,$p,$state,$detail,($confirmed||$seen)?(int)$expected['revision']:null,$url);});
}
function engineCancel(PDO $db,array $u,int $project,int $id): void {atomic($db,function()use($db,$u,$project,$id){$u=organizationUser($db,$u);requireProjectRight($db,$u,$project,'manage');$p=enginePublication($db,$u,$project,$id);if($p['state']==='cancelled')return;if($p['state']!=='ready')throw new Problem('Можно отменить только неотправленный запрос.',409);engineResult($db,$u,$p,'cancelled','Отменено до отправки.');});}

function enginePage(PDO $db,array $u,?int $project,array $args,?callable $transport=null): array {
    $u=organizationUser($db,$u);$projects=rightProjects($db,$u,'connections');if($project!==null)requireProjectRight($db,$u,$project,'connections');
    $sites=$project===null?[]:query($db,'SELECT * FROM engine_sites WHERE project_id=? AND organization_id=? ORDER BY name,id',[$project,$u['organization_id']])->fetchAll();
    $canPublish=$project!==null&&roleAllows(projectRole($db,$u,$project),'manage');$available=[];
    if($project!==null&&$u['role']==='owner'){foreach(connections() as $key=>$c)if($c['type']==='engine'&&$c['configured']&&connectionAllowed($db,$u,$key)){try{engineConfig($c);$available[$key]=$c['label'];}catch(Problem){}}}
    $site=null;$types=[];$items=[];$total=0;$preview=null;$history=[];$publication=null;$error=null;$type='';$entry='';$offset=0;$q='';$publications=[];
    if($project!==null){$publications=query($db,'SELECT * FROM engine_publications WHERE project_id=? ORDER BY id DESC LIMIT 50',[$project])->fetchAll();
        if(isset($args['publication'])&&$args['publication']!==''){$publication=enginePublication($db,$u,$project,numberId($args['publication']));$site=engineSite($db,$u,$project,(int)$publication['site_id']);}
        elseif(isset($args['site'])&&$args['site']!=='')$site=engineSite($db,$u,$project,numberId($args['site']));
        if($site&&!$publication){try{$c=engineSiteConfig($db,$u,$site);$types=engineTypes($c,$transport);$type=($args['type']??'')===''?'':engineSegment($args['type']);if($type!==''&&!isset($types[$type]))throw new Problem('Тип отсутствует в каталоге.',404);
            if($type!==''){$q=word($args['q']??'',128,'Поиск',false);$offset=isset($args['offset'])?numberId($args['offset'])-1:0;$list=engineList($c,$type,$offset,$q,$transport);$items=$list['items'];$total=$list['total'];
                if(($args['entry']??'')!==''){$entry=engineSegment($args['entry']);$current=engineEntry($c,$type,$entry,$transport);$history=engineRevisions($c,$type,$entry,$transport);$revision=isset($args['revision'])?numberId($args['revision']):$current['revision'];$preview=$revision===$current['revision']?$current:($history[$revision]??throw new Problem('Редакция недоступна.',404));$preview=['snapshot'=>$preview,'hash'=>engineHash($preview),'publishable'=>$revision===$current['revision']&&$preview['status']==='draft'];}
            }
        }catch(Problem $e){$error=$e->getMessage();}}
    }
    return compact('projects','project','sites','site','available','canPublish','types','type','items','total','preview','history','publication','publications','error','entry','offset','q');
}
