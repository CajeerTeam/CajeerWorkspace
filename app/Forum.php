<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const FORUM_EDITION='1.6.0';
const FORUM_CORE='2.1.0';
function forumId(mixed $v): string {
    if(!is_string($v)||!preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$v))throw new Problem('Некорректный UUID Forum.',422);
    return $v;
}
function forumBody(mixed $v): string {
    if(!is_string($v)||strlen($v)>40000||!preg_match('//u',$v)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$v)||preg_match_all('/./us',trim($v))<1||preg_match_all('/./us',trim($v))>10000)throw new Problem('Введите текст до 10 000 символов.',422);
    return trim($v);
}
function forumActor(PDO $db,array $u,int $project,string $right='connections'): array {
    $u=organizationUser($db,$u);requireProjectRight($db,$u,$project,'connections');requireProjectRight($db,$u,$project,$right);return $u;
}
function forumConfig(PDO $db,array $u,string $key): array {
    if(!connectionAllowed($db,$u,$key))throw new Problem('Forum недоступен.',404);
    $c=connections()[$key]??null;
    if(!$c||$c['type']!=='forum'||!$c['base']||$c['installed_version']!==FORUM_EDITION)throw new Problem('Настройте Forum Edition '.FORUM_EDITION.'.',409);
    $c['origin']=engineOrigin($c['base']);return $c;
}
/** Explicit operator-owned mapping; never accept a token or principal from a browser form. */
function forumIdentity(array $c,array $u): array {
    $file=getenv('WORKSPACE_FORUM_IDENTITIES');
    if(!$file||!is_file($file)||filesize($file)>262144)throw new Problem('Индивидуальная учётная запись Forum не настроена.',403);
    try{$map=json_decode((string)file_get_contents($file),true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new Problem('Некорректная карта Forum.',503);}
    if(!is_array($map))throw new Problem('Некорректная карта Forum.',503);$rows=$map[$c['id']]??[];if(!is_array($rows))throw new Problem('Некорректная карта Forum.',503);$key=$u['organization_id'].':'.$u['id'];$entry=$rows[$key]??null;
    if(!is_array($entry)||!is_string($entry['token_env']??null)||!preg_match('/\AWORKSPACE_FORUM_[A-Z0-9_]{1,80}\z/D',$entry['token_env']))throw new Problem('Индивидуальная учётная запись Forum не настроена.',403);
    $id=forumId($entry['user_id']??null);$token=getenv($entry['token_env']);
    if(!is_string($token)||strlen($token)<16||strlen($token)>4096||preg_match('/[^\x21-\x7e]/',$token))throw new Problem('Индивидуальный токен Forum недоступен.',403);
    // Aliases of the same origin must not turn a personal identity into a shared credential.
    foreach(connections() as $alias=>$cfg){if($cfg['type']!=='forum'||!$cfg['base'])continue;try{$origin=engineOrigin($cfg['base']);}catch(Problem){continue;}if($origin!==$c['origin'])continue;
        $aliases=$map[$alias]??[];if(!is_array($aliases))throw new Problem('Некорректная карта Forum.',503);
        foreach($aliases as $other=>$r)if((string)$other!==$key&&is_array($r)&&(($r['user_id']??null)===$id||($r['token_env']??null)===$entry['token_env']||is_string($r['token_env']??null)&&getenv($r['token_env'])===$token))throw new Problem('Общая учётная запись Forum запрещена.',403);
    }
    return array_replace($c,['forum_actor'=>$id,'token'=>$token]);
}
function forumHttp(array $c,string $method,string $path,?array $body=null): array {
    if(!extension_loaded('curl'))throw new Problem('Требуется cURL.',503);
    $ch=curl_init($c['origin'].$path);$raw='';$headers=['Accept: application/json','Authorization: Bearer '.$c['token'],'Cache-Control: no-cache'];
    $opt=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT_MS=>1500,CURLOPT_TIMEOUT_MS=>5000,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'CajeerWorkspace/'.VERSION,CURLOPT_WRITEFUNCTION=>static function($ch,string $s)use(&$raw):int{if(strlen($raw)+strlen($s)>4194304)return 0;$raw.=$s;return strlen($s);}];
    if($body!==null){$headers[]='Content-Type: application/json';$opt[CURLOPT_POSTFIELDS]=json_encode($body,JSON_THROW_ON_ERROR);}$opt[CURLOPT_HTTPHEADER]=$headers;
    curl_setopt_array($ch,$opt);$ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$mime=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);unset($ch);
    if($ok===false)throw new Problem('Forum: сеть, TLS или лимит ответа.',503);
    if(!in_array($status,[200,201],true))throw new Problem('Forum HTTP '.$status,in_array($status,[400,401,403,404,409,422,429],true)?$status:503);
    if(!preg_match('~^application/(?:json|[a-z0-9.+-]+\+json)(?:\s*;|$)~i',$mime))throw new Problem('Некорректный Content-Type Forum.',503);
    try{$r=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new Problem('Некорректный JSON Forum.',503);}
    if(!is_array($r))throw new Problem('Некорректный ответ Forum.',503);return $r;
}
function forumCall(array $c,string $method,string $path,?array $body=null,?callable $transport=null): array {
    $r=($transport??__NAMESPACE__.'\\forumHttp')($c,$method,'/api/v1'.$path,$body);
    if(!is_array($r['data']??null))throw new Problem('Некорректный ответ Forum.',503);return $r['data'];
}
function forumSession(PDO $db,array $u,string $key,?callable $transport=null): array {
    $c=forumIdentity(forumConfig($db,$u,$key),$u);
    $me=forumCall($c,'GET','/auth/me',null,$transport);
    if(($me['user_id']??null)!==$c['forum_actor']||!empty($me['suspended'])||!empty($me['session']))throw new Problem('Идентификатор Forum не совпадает.',403);
    $health=($transport??__NAMESPACE__.'\\forumHttp')($c,'GET','/api/v1/health',null);
    if(($health['version']??null)!==FORUM_CORE||($health['edition_version']??null)!==FORUM_EDITION||($health['engine_api_version']??null)!=='1.1')throw new Problem('Несовместимая редакция или ядро Forum.',409);
    return $c;
}
function forumSource(PDO $db,array $u,int $project,int $source): array {
    $u=forumActor($db,$u,$project);return entity($db,'SELECT * FROM forum_sources WHERE id=? AND project_id=? AND organization_id=?',[$source,$project,$u['organization_id']]);
}
function forumSourceSession(PDO $db,array $u,array $s,?callable $transport=null): array {
    if(!(int)$s['active'])throw new Problem('Forum недоступен.',404);
    $cfg=forumConfig($db,$u,$s['connection_key']);if(!hash_equals($s['config_hash'],$cfg['hash']))throw new Problem('Конфигурация Forum изменилась.',409);return forumSession($db,$u,$s['connection_key'],$transport);
}
function forumBoard(array $c,string $board,int $page=1,?callable $transport=null): array {
    $r=forumCall($c,'GET','/forum/boards/'.forumId($board).'?page='.max(1,min(100,$page)),null,$transport);
    if(($r['board']['id']??null)!==$board||($r['board']['permissions']['thread:read']??false)!==true||!is_array($r['threads']??null))throw new Problem('Раздел Forum недоступен.',404);return $r;
}
function forumThread(array $c,string $board,string $thread,int $page=1,?callable $transport=null): array {
    $r=forumCall($c,'GET','/forum/threads/'.forumId($thread).'?page='.max(1,min(100,$page)),null,$transport);
    if(($r['thread']['id']??null)!==$thread||($r['thread']['board_id']??null)!==$board||($r['board']['id']??null)!==$board||($r['board']['permissions']['thread:read']??false)!==true||!is_array($r['posts']??null))throw new Problem('Тема Forum недоступна.',404);return $r;
}
function forumBind(PDO $db,array $u,int $project,string $key,string $board,?callable $transport=null): int {
    $u=forumActor($db,$u,$project,'manage');if($u['role']!=='owner')throw new Problem('Требуется владелец.',403);
    $c=forumSession($db,$u,$key,$transport);forumBoard($c,$board,1,$transport);
    return atomic($db,function()use($db,$u,$project,$key,$board,$c){$u=forumActor($db,$u,$project,'manage');if($u['role']!=='owner'||!connectionAllowed($db,$u,$key))throw new Problem('Доступ отозван.',403);
        $r=query($db,'SELECT id,config_hash FROM forum_sources WHERE project_id=? AND connection_key=? AND board_id=?',[$project,$key,$board])->fetch();if($r){if($r['config_hash']!==$c['hash'])throw new Problem('Изменилась конфигурация.',409);return (int)$r['id'];}
        $id=insertId($db,'INSERT INTO forum_sources(organization_id,project_id,connection_key,config_hash,board_id,created_by,created_at) VALUES(?,?,?,?,?,?,?)',[$u['organization_id'],$project,$key,$c['hash'],$board,$u['id'],time()]);auditEvent($db,$u,$project,'forum.bind',$id);return $id;});
}
function forumToggle(PDO $db,array $u,int $project,int $source,bool $active):void {
    atomic($db,function()use($db,$u,$project,$source,$active){$u=forumActor($db,$u,$project,'manage');if($u['role']!=='owner')throw new Problem('Требуется владелец.',403);forumSource($db,$u,$project,$source);query($db,'UPDATE forum_sources SET active=? WHERE id=?',[(int)$active,$source]);auditEvent($db,$u,$project,'forum.toggle',$source,$active?'active':'disabled');});
}
function forumTarget(PDO $db,int $project,string $kind,int $id): array {
    return match($kind){'task'=>entity($db,'SELECT id,title FROM tasks WHERE project_id=? AND id=?',[$project,$id]),'release'=>entity($db,"SELECT id,tag AS title FROM project_releases WHERE project_id=? AND id=? AND state='confirmed'",[$project,$id]),default=>throw new Problem('Выберите задачу или подтверждённый выпуск.',422)};
}
function forumTopic(PDO $db,array $s,int $id): array {return entity($db,'SELECT * FROM forum_topics WHERE project_id=? AND source_id=? AND id=?',[$s['project_id'],$s['id'],$id]);}
function forumFindTarget(PDO $db,array $s,string $kind,int $target): array|false {
    $col=$kind==='task'?'task_id':'release_id';return query($db,"SELECT * FROM forum_topics WHERE source_id=? AND $col=?",[$s['id'],$target])->fetch();
}
function forumTopicInsert(PDO $db,array $u,array $s,array $c,string $kind,int $target,?string $thread,string $state):int {
    return insertId($db,'INSERT INTO forum_topics(project_id,source_id,task_id,release_id,thread_id,state,actor_id,forum_actor,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$s['project_id'],$s['id'],$kind==='task'?$target:null,$kind==='release'?$target:null,$thread,$state,$u['id'],$c['forum_actor'],time()]);
}
function forumAttach(PDO $db,array $u,int $project,int $source,string $kind,int $target,string $thread,?callable $transport=null):int {
    $u=forumActor($db,$u,$project,'manage');$s=forumSource($db,$u,$project,$source);forumTarget($db,$project,$kind,$target);$c=forumSourceSession($db,$u,$s,$transport);forumThread($c,$s['board_id'],$thread,1,$transport);
    return atomic($db,function()use($db,$u,$project,$s,$c,$kind,$target,$thread){$u=forumActor($db,$u,$project,'manage');$fresh=forumSource($db,$u,$project,(int)$s['id']);if(!(int)$fresh['active']||!connectionAllowed($db,$u,$s['connection_key']))throw new Problem('Forum недоступен.',404);forumTarget($db,$project,$kind,$target);$old=forumFindTarget($db,$s,$kind,$target);$duplicate=query($db,'SELECT id FROM forum_topics WHERE source_id=? AND thread_id=?',[$s['id'],$thread])->fetchColumn();if($duplicate!==false&&(!$old||(int)$duplicate!==(int)$old['id']))throw new Problem('Тема уже связана с другой карточкой.',409);if($old){if($old['state']==='linked'&&$old['thread_id']===$thread)return (int)$old['id'];if(!in_array($old['state'],['uncertain','rejected'],true))throw new Problem('Уже существует привязка или незавершённая отправка.',409);$id=(int)$old['id'];query($db,"UPDATE forum_topics SET thread_id=?,state='linked' WHERE id=?",[$thread,$id]);}else $id=forumTopicInsert($db,$u,$s,$c,$kind,$target,$thread,'linked');auditEvent($db,$u,$project,'forum.attach',$id);return $id;});
}
function forumRequestHash(string $op,array $payload):string{return hash('sha256',json_encode([$op,$payload],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));}
function forumPrior(PDO $db,array $u,string $key,array $s,string $op,string $hash):array|false {
    if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new Problem('Обновите форму.',422);
    $r=query($db,'SELECT * FROM forum_operations WHERE actor_id=? AND request_key=?',[$u['id'],$key])->fetch();if($r&&((int)$r['source_id']!==(int)$s['id']||$r['operation']!==$op||!hash_equals($r['payload_hash'],$hash)))throw new Problem('Ключ формы уже использован.',409);return $r;
}
function forumReserve(PDO $db,array $u,array $s,array $c,int $topic,string $key,string $op,string $hash):int {
    return insertId($db,"INSERT INTO forum_operations(project_id,source_id,topic_id,actor_id,forum_actor,request_key,operation,payload_hash,state,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,'sending',?,?)",[$s['project_id'],$s['id'],$topic,$u['id'],$c['forum_actor'],$key,$op,$hash,time(),time()]);
}
function forumFinish(PDO $db,array $u,array $s,int $topic,int $op,string $action,string $state,?string $remote):void {
    atomic($db,function()use($db,$u,$s,$topic,$op,$action,$state,$remote){query($db,'UPDATE forum_operations SET state=?,remote_id=?,updated_at=? WHERE id=? AND state=\'sending\'',[$state,$remote,time(),$op]);if($action==='create'){
        $status=match($state){'accepted'=>'linked','pending'=>'pending','rejected'=>'rejected',default=>'uncertain'};
        query($db,"UPDATE forum_topics SET state=?,thread_id=?,submission_id=? WHERE id=? AND state='sending'",[$status,$status==='linked'?$remote:null,$status==='pending'?$remote:null,$topic]);}
        auditEvent($db,$u,(int)$s['project_id'],'forum.'.$action,$topic,$state);});
}
function forumSend(PDO $db,array $u,array $s,array $c,int $topic,int $op,string $action,string $path,array $payload,?callable $transport=null):void {
    $state='uncertain';$remote=null;$received=false;
    try{$right=in_array($action,['create','lock','unlock','pin','unpin'],true)?'manage':'work';$u=forumActor($db,$u,(int)$s['project_id'],$right);$fresh=forumSource($db,$u,(int)$s['project_id'],(int)$s['id']);if(!(int)$fresh['active']||forumConfig($db,$u,$s['connection_key'])['hash']!==$s['config_hash'])throw new Problem('Доступ отозван.',403);$identity=forumIdentity($c,$u);if($identity['forum_actor']!==$c['forum_actor']||!hash_equals($identity['token'],$c['token']))throw new Problem('Учётная запись Forum изменилась.',403);$r=forumCall($c,in_array($action,['watch','unwatch'],true)?'PUT':'POST',$path,$payload,$transport);$received=true;$state=($r['pending_review']??false)===true?'pending':'accepted';
        if($state==='pending'&&!in_array($action,['create','reply'],true))throw new Problem('Неожиданный ответ.',503);if($state==='pending')$remote=forumId($r['submission_id']??null);elseif(!in_array($action,['watch','unwatch'],true)){$remote=forumId($r['id']??null);if(in_array($action,['lock','unlock','pin','unpin'],true)&&$path!=='/forum/threads/'.$remote.'/moderate')throw new Problem('Неожиданный ответ.',503);if($action==='reply'&&$path!=='/forum/threads/'.forumId($r['thread_id']??null).'/posts')throw new Problem('Неожиданный ответ.',503);}elseif(($r['enabled']??null)!==($action==='watch'))throw new Problem('Неожиданный ответ.',503);
    }catch(Problem $e){$state=!$received&&in_array($e->status,[400,401,403,404,409,422,429],true)?'rejected':'uncertain';$remote=null;}catch(\Throwable){$state='uncertain';}
    forumFinish($db,$u,$s,$topic,$op,$action,$state,$remote);
}
function forumCreate(PDO $db,array $u,int $project,int $source,string $kind,int $target,string $key,string $title,string $body,?callable $transport=null):int {
    $u=forumActor($db,$u,$project,'manage');$s=forumSource($db,$u,$project,$source);forumTarget($db,$project,$kind,$target);$c=forumSourceSession($db,$u,$s,$transport);$b=forumBoard($c,$s['board_id'],1,$transport);
    $title=word($title,180,'Название');if(preg_match_all('/./us',$title)<3)throw new Problem('Название от 3 символов.',422);$payload=['title'=>$title,'body'=>forumBody($body),'body_format'=>'plain','kind'=>'discussion'];$hash=forumRequestHash('create',[$kind,$target,$payload]);
    if($old=forumPrior($db,$u,$key,$s,'create',$hash))return (int)$old['topic_id'];
    if(($b['board']['permissions']['thread:create']??false)!==true)throw new Problem('Forum запрещает создание темы.',403);
    [$topic,$op]=atomic($db,function()use($db,$u,$project,$s,$c,$kind,$target,$key,$hash){$u=forumActor($db,$u,$project,'manage');$fresh=forumSource($db,$u,$project,(int)$s['id']);if(!(int)$fresh['active']||!connectionAllowed($db,$u,$s['connection_key']))throw new Problem('Forum недоступен.',404);forumTarget($db,$project,$kind,$target);if($old=forumPrior($db,$u,$key,$s,'create',$hash))return [(int)$old['topic_id'],null];if(forumFindTarget($db,$s,$kind,$target))throw new Problem('Тема уже зарегистрирована. Не повторяйте отправку; используйте сверку.',409);$topic=forumTopicInsert($db,$u,$s,$c,$kind,$target,null,'sending');return [$topic,forumReserve($db,$u,$s,$c,$topic,$key,'create',$hash)];});
    if($op!==null)forumSend($db,$u,$s,$c,$topic,$op,'create','/forum/boards/'.$s['board_id'].'/threads',$payload,$transport);return $topic;
}
function forumAction(PDO $db,array $u,int $project,int $source,int $topic,string $key,string $op,array $form,?callable $transport=null):void {
    $right=in_array($op,['lock','unlock','pin','unpin'],true)?'manage':'work';$u=forumActor($db,$u,$project,$right);$s=forumSource($db,$u,$project,$source);$c=forumSourceSession($db,$u,$s,$transport);forumBoard($c,$s['board_id'],1,$transport);$t=forumTopic($db,$s,$topic);if($t['state']!=='linked')throw new Problem('Тема не опубликована.',409);$r=forumThread($c,$s['board_id'],$t['thread_id'],numberId($form['page']??1),$transport);$perms=$r['board']['permissions'];$path='/forum/threads/'.$t['thread_id'];
    switch($op){case 'reply':if(!empty($r['thread']['locked']))throw new Problem('Тема закрыта.',409);$payload=['body'=>forumBody($form['body']??null),'body_format'=>'plain'];$path.='/posts';$perm='post:reply';break;
        case 'lock':case 'unlock':case 'pin':case 'unpin':$payload=['action'=>$op];$path.='/moderate';$perm='thread:moderate';break;
        case 'report':$post=forumId($form['post_id']??null);if(!in_array($post,array_column(array_filter($r['posts'],fn($p)=>empty($p['ignored_author'])),'id'),true))throw new Problem('Сообщение недоступно.',404);$reason=word($form['reason']??'',500,'Причина');if(preg_match_all('/./us',$reason)<3)throw new Problem('Укажите не менее трёх символов причины.',422);$payload=['reason'=>$reason,'category'=>'other'];$path='/forum/posts/'.$post.'/report';$perm='report:create';break;
        case 'watch':case 'unwatch':$payload=['enabled'=>$op==='watch'];$path.='/watch';$perm='watch:use';break;
        default:throw new Problem('Неизвестное действие.',422);}
    $hash=forumRequestHash($op,[$topic,$path,$payload]);if(forumPrior($db,$u,$key,$s,$op,$hash))return;if(($perms[$perm]??false)!==true)throw new Problem('Forum запрещает действие.',403);
    $id=atomic($db,function()use($db,$u,$project,$right,$s,$c,$topic,$key,$op,$hash){$u=forumActor($db,$u,$project,$right);$fresh=forumSource($db,$u,$project,(int)$s['id']);if(!(int)$fresh['active']||!connectionAllowed($db,$u,$s['connection_key']))throw new Problem('Forum недоступен.',404);if(forumPrior($db,$u,$key,$s,$op,$hash))return null;return forumReserve($db,$u,$s,$c,$topic,$key,$op,$hash);});if($id!==null)forumSend($db,$u,$s,$c,$topic,$id,$op,$path,$payload,$transport);
}
function forumReconcile(PDO $db,array $u,int $project,int $source,int $topic,?callable $transport=null):void {
    $u=forumActor($db,$u,$project,'manage');$s=forumSource($db,$u,$project,$source);$c=forumSourceSession($db,$u,$s,$transport);forumBoard($c,$s['board_id'],1,$transport);$t=forumTopic($db,$s,$topic);
    if((int)$t['actor_id']!==(int)$u['id']||$c['forum_actor']!==$t['forum_actor'])throw new Problem('Сверку выполняет автор запроса.',403);
    if($t['state']==='sending'&&time()-(int)$t['created_at']>60){atomic($db,function()use($db,$u,$s,$topic){query($db,"UPDATE forum_topics SET state='uncertain' WHERE id=? AND state='sending'",[$topic]);query($db,"UPDATE forum_operations SET state='uncertain',updated_at=? WHERE topic_id=? AND operation='create' AND state='sending'",[time(),$topic]);auditEvent($db,$u,(int)$s['project_id'],'forum.reconcile',$topic,'uncertain');});return;}
    if($t['state']!=='pending')throw new Problem('Для неопределённого запроса проверьте Forum и вручную привяжите найденную тему.',409);
    $found=null;for($page=1;$page<=5;$page++){$rows=forumCall($c,'GET','/forum/submissions?page='.$page,null,$transport);foreach($rows as $r)if(($r['id']??null)===$t['submission_id']){$found=$r;break 2;}if(count($rows)<25)break;}
    if(!$found||($found['board_id']??null)!==$s['board_id']||($found['author_id']??null)!==$c['forum_actor'])throw new Problem('Заявка недоступна. Проверьте её в Forum.',404);
    $state=$found['status']??null;if($state==='pending')return;$remote=null;if($state==='approved'){$remote=forumId($found['result_id']??null);forumThread($c,$s['board_id'],$remote,1,$transport);$status='linked';}elseif(in_array($state,['rejected','cancelled'],true))$status='rejected';else throw new Problem('Неизвестное состояние заявки.',409);
    atomic($db,function()use($db,$u,$s,$topic,$status,$remote){query($db,"UPDATE forum_topics SET state=?,thread_id=? WHERE id=? AND state='pending'",[$status,$remote,$topic]);auditEvent($db,$u,(int)$s['project_id'],'forum.reconcile',$topic,$status);});
}
/** No remote content, board names or counts are retained in the database. */
function forumPage(PDO $db,array $u,?int $project,array $args,?callable $transport=null):array {
    $u=organizationUser($db,$u);$projects=rightProjects($db,$u,'connections');$sources=$available=$rows=$participants=$operations=[];$source=$board=$detail=$pending=$error=null;$ownPending=[];$manage=$work=false;$n=numberId($args['n']??1);if($n>100)throw new Problem('Уточните запрос.',422);
    $kind=choice($args['kind']??'task',['task'=>'task','release'=>'release']);$target=optionalId($args['target']??'');$term=word($args['q']??'',100,'Поиск',false);$sourceId=optionalId($args['source']??'');$offset=numberId($args['bindings_page']??1);if($offset>100)throw new Problem('Уточните запрос.',422);
    if($project===null)return compact('projects','project','sources','available','rows','participants','operations','source','board','detail','pending','ownPending','error','manage','work','n','kind','target','term','offset');
    $u=forumActor($db,$u,$project);$role=projectRole($db,$u,$project);$manage=roleAllows($role,'manage');$work=roleAllows($role,'work');
    if($target!==null)forumTarget($db,$project,$kind,$target);
    if($u['role']==='owner')foreach(connections() as $key=>$cfg){try{forumConfig($db,$u,$key);$available[$key]=$cfg['label'];}catch(Problem){}}
    // Bound the number of requests. Only display names from live ACL-filtered responses.
    $bindings=query($db,'SELECT * FROM forum_sources WHERE project_id=? AND organization_id=? ORDER BY id LIMIT 5 OFFSET '.(($offset-1)*5),[$project,$u['organization_id']])->fetchAll();$sessions=[];$indexes=[];
    if($sourceId===null)foreach($bindings as $s){if(!(int)$s['active']){if($u['role']==='owner')$sources[]=['id'=>$s['id'],'name'=>'Отключённая привязка #'.$s['id'],'active'=>0];continue;}try{$key=$s['connection_key'];if(!isset($sessions[$key]))$sessions[$key]=forumSourceSession($db,$u,$s,$transport);$c=$sessions[$key];if($s['config_hash']!==$c['hash'])continue;if(!isset($indexes[$key]))$indexes[$key]=forumCall($c,'GET','/forum',null,$transport);foreach($indexes[$key]['boards']??[] as $b)if(($b['id']??null)===$s['board_id']&&($b['permissions']['thread:read']??false)===true){$sources[]=['id'=>$s['id'],'name'=>$b['name']??'Раздел','active'=>1];break;}}catch(Problem){/* fail closed, no names or IDs */}}
    if($sourceId!==null){try{$s=forumSource($db,$u,$project,$sourceId);$c=forumSourceSession($db,$u,$s,$transport);$b=forumBoard($c,$s['board_id'],$n,$transport);$source=['id'=>$s['id'],'active'=>1,'origin'=>$c['origin']];$board=$b['board'];
        $selected=optionalId($args['topic']??'');if($selected===null&&$target!==null){$link=forumFindTarget($db,$s,$kind,$target);if($link)$selected=(int)$link['id'];}
        if($selected!==null){$t=forumTopic($db,$s,$selected);if($t['state']==='linked'){$detail=forumThread($c,$s['board_id'],$t['thread_id'],$n,$transport);$detail['local_id']=$t['id'];$detail['task_id']=$t['task_id'];$detail['release_id']=$t['release_id'];foreach($detail['posts'] as $post)if(!empty($post['author_profile_visible'])&&empty($post['ignored_author'])&&is_string($post['author_id']??null)&&is_string($post['author']??null))$participants[$post['author_id']]=$post['author'];$operations=query($db,'SELECT operation,state,created_at FROM forum_operations WHERE topic_id=? AND actor_id=? ORDER BY id DESC LIMIT 20',[$t['id'],$u['id']])->fetchAll();}
            elseif((int)$t['actor_id']===(int)$u['id']&&$t['forum_actor']===$c['forum_actor'])$pending=['id'=>$t['id'],'state'=>$t['state'],'task_id'=>$t['task_id'],'release_id'=>$t['release_id']];else throw new Problem('Тема Forum недоступна.',404);}
        if(!$detail&&!$pending){$ownPending=query($db,"SELECT id,state,task_id,release_id FROM forum_topics WHERE source_id=? AND actor_id=? AND forum_actor=? AND state<>'linked' ORDER BY id DESC LIMIT 20",[$s['id'],$u['id'],$c['forum_actor']])->fetchAll();$remote=$b['threads'];if($term!==''){$remote=forumCall($c,'GET','/forum/search?'.http_build_query(['q'=>$term,'mode'=>'threads','board_id'=>$s['board_id'],'page'=>$n],'','&',PHP_QUERY_RFC3986),null,$transport);}
            $ids=array_values(array_filter(array_column(array_slice($remote,0,25),'id'),fn($v)=>is_string($v)&&preg_match('/\A[a-f0-9-]{36}\z/D',$v)));$local=$ids?query($db,"SELECT id,thread_id,task_id,release_id FROM forum_topics WHERE source_id=? AND state='linked' AND thread_id IN (".implode(',',array_fill(0,count($ids),'?')).')',[$s['id'],...$ids])->fetchAll():[];$byId=array_column($local,null,'thread_id');foreach($remote as $r){$rid=$r['id']??null;if(!is_string($rid)||!isset($byId[$rid])||($term===''&&($r['board_id']??null)!==$s['board_id'])||(isset($r['board_id'])&&$r['board_id']!==$s['board_id']))continue;$rows[]=['local'=>$byId[$rid],'title'=>$r['title']??'Тема','locked'=>$r['locked']??0,'pinned'=>$r['pinned']??0];}}
        }catch(Problem $e){$source=$board=$detail=$pending=null;$rows=$participants=$operations=$ownPending=[];$error='Раздел, тема или подключение недоступны. Проверьте права Forum и Workspace.';}}
    return compact('projects','project','sources','available','rows','participants','operations','source','board','detail','pending','ownPending','error','manage','work','n','kind','target','term','offset');
}
