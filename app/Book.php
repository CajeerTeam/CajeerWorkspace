<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const BOOK_PAGE_LIMIT=12;
const BOOK_SNAPSHOT_LIMIT=2097152;
function bookSlug(mixed $v): string {
    if(!is_string($v)||!preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D',$v))throw new Problem('Некорректный идентификатор Book.',422);
    return $v;
}
function bookScope(mixed $v): string {
    if(!is_string($v)||count($p=explode('/',$v))!==2)throw new Problem('Укажите book/version.',422);
    return bookSlug($p[0]).'/'.bookSlug($p[1]);
}
function bookKey(mixed $v): string {
    if(!is_string($v)||count($p=explode('/',$v,3))!==3||strlen($p[2])>250)throw new Problem('Некорректный путь страницы.',422);
    foreach(explode('/',$v) as $part)bookSlug($part);return $v;
}
function bookConfig(array $c): array {
    if(($c['type']??null)!=='book'||empty($c['base'])||($c['installed_version']??null)!=='1.2.0'||!empty($c['env']))throw new Problem('Настройте публичный Reader Book 1.2.0.',409);
    $p=parse_url($c['base']);$path=$p['path']??'';
    if(!preg_match('~\A(?:/w/[a-f0-9]{32}/[a-f0-9]{32})?\z~D',$path))throw new Problem('Book: допустим origin или /w/ORG_ID/WORKSPACE_ID.',409);
    $origin=engineOrigin(substr($c['base'],0,strlen($c['base'])-strlen($path)));
    $c['reader']=$origin.$path;return $c;
}
/** Reader endpoints are public and read-only. No cookies, redirects or caller-controlled origins. */
function bookHttp(array $c,string $path): array {
    if(!extension_loaded('curl'))throw new Problem('Требуется cURL.',503);
    $ch=curl_init($c['reader'].'/api/v1'.$path);$raw='';
    curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Accept: application/json','Cache-Control: no-cache'],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS|CURLPROTO_HTTP,CURLOPT_CONNECTTIMEOUT_MS=>1500,CURLOPT_TIMEOUT_MS=>4000,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'CajeerWorkspace/'.VERSION,CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$raw):int{if(strlen($raw)+strlen($chunk)>4*1024*1024)return 0;$raw.=$chunk;return strlen($chunk);}]);
    $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$mime=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);unset($ch);
    if($ok===false)throw new Problem('Сеть, TLS или лимит ответа Book.',503);
    if($status!==200)throw new Problem('Reader Book HTTP '.$status,in_array($status,[401,403,404],true)?404:503);
    if(!preg_match('~^application/(?:json|[a-z0-9.+-]+\+json)(?:\s*;|$)~i',$mime))throw new Problem('Некорректный Content-Type Book.',503);
    try{$r=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new Problem('Некорректный JSON Book.',503);}
    if(!is_array($r))throw new Problem('Некорректный ответ Book.',503);return $r;
}
function bookText(mixed $v,int $max,bool $empty=false): string {
    if(!is_string($v)||strlen($v)>$max||(!$empty&&$v==='')||!preg_match('//u',$v)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$v))throw new Problem('Некорректные данные Reader Book.',503);return $v;
}
function bookMeta(array $p): array {
    if(!is_string($p['id']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$p['id'])||!is_int($p['order']??null)||!in_array($p['locale']??null,['en','ru'],true))throw new Problem('Некорректные метаданные Book.',503);
    $media=$p['media']??[];if(!is_array($media)||!array_is_list($media)||count($media)>1000)throw new Problem('Некорректные медиа Book.',503);
    foreach($media as $m)bookText($m,128);
    return ['id'=>$p['id'],'title'=>bookText($p['title']??null,200),'section'=>bookText($p['section']??null,100,true),'order'=>$p['order'],'locale'=>$p['locale'],'updated'=>bookText($p['updated']??null,80),'media'=>$media];
}
function bookCatalog(array $c,string $scope,?callable $transport=null): array {
    $rows=($transport??__NAMESPACE__.'\\bookHttp')($c,'/pages');
    if(!is_array($rows)||count($rows)>1000||($rows!==[]&&array_is_list($rows)))throw new Problem('Некорректный каталог Book.',503);
    $out=[];foreach($rows as $key=>$p){if(!is_string($key)||!str_starts_with($key,$scope.'/'))continue;bookKey($key);if(!is_array($p))throw new Problem('Некорректная страница Book.',503);$out[$key]=bookMeta($p);}return $out;
}
function bookRead(array $c,string $key,?callable $transport=null): array {
    $p=($transport??__NAMESPACE__.'\\bookHttp')($c,'/pages/'.bookKey($key));
    if(!is_array($p))throw new Problem('Некорректная страница Book.',503);
    return bookMeta($p)+['body'=>bookText($p['body']??null,262144,true)];
}
function bookJson(array $v): string {
    $raw=json_encode(engineCanonical($v),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
    if(strlen($raw)>BOOK_SNAPSHOT_LIMIT)throw new Problem('Подборка превышает 2 МиБ.',422);return $raw;
}
function bookActor(PDO $db,array $u,int $project,bool $write=false): array {
    $u=organizationUser($db,$u);requireProjectRight($db,$u,$project,'connections');if($write)requireProjectRight($db,$u,$project,'work');return $u;
}
function bookSource(PDO $db,array $u,int $project,int $id): array {
    $u=bookActor($db,$u,$project);return entity($db,'SELECT * FROM book_sources WHERE project_id=? AND organization_id=? AND id=?',[$project,$u['organization_id'],$id]);
}
function bookSourceConfig(PDO $db,array $u,array $s): array {
    if(!(int)$s['active']||!connectionAllowed($db,$u,$s['connection_key'])||!query($db,'SELECT 1 FROM project_connections WHERE project_id=? AND connection_key=?',[$s['project_id'],$s['connection_key']])->fetchColumn())throw new Problem('Доступ к книге отозван.',403);
    $c=bookConfig(connections()[$s['connection_key']]??[]);if(!hash_equals($s['config_hash'],$c['hash']))throw new Problem('Конфигурация книги изменилась.',409);return $c;
}
function bookBind(PDO $db,array $u,int $project,string $key,string $scope,string $name,?callable $transport=null): int {
    $u=orgOwner($db,$u);allowedProject($db,$u,$project);$scope=bookScope($scope);$name=word($name,120,'Книга');
    if(!connectionAllowed($db,$u,$key))throw new Problem('Нет доступа к подключению.',403);$c=bookConfig(connections()[$key]??[]);
    if(!bookCatalog($c,$scope,$transport))throw new Problem('Нет доступных опубликованных страниц этой книги.',404);
    return atomic($db,function()use($db,$u,$project,$key,$scope,$name,$c){$u=orgOwner($db,$u);allowedProject($db,$u,$project);if(!connectionAllowed($db,$u,$key)||bookConfig(connections()[$key]??[])['hash']!==$c['hash'])throw new Problem('Подключение изменилось.',409);
        $old=query($db,'SELECT * FROM book_sources WHERE project_id=? AND connection_key=? AND scope=?',[$project,$key,$scope])->fetch();
        if($old){if($old['config_hash']!==$c['hash']||$old['name']!==$name)throw new Problem('Привязка уже существует.',409);return (int)$old['id'];}
        query($db,'INSERT INTO project_connections(project_id,connection_key) VALUES(?,?) ON CONFLICT DO NOTHING',[$project,$key]);
        $id=insertId($db,'INSERT INTO book_sources(organization_id,project_id,connection_key,config_hash,scope,name,created_by,created_at) VALUES(?,?,?,?,?,?,?,?)',[$u['organization_id'],$project,$key,$c['hash'],$scope,$name,$u['id'],time()]);auditEvent($db,$u,$project,'book.bind',$id);return $id;});
}
function bookToggle(PDO $db,array $u,int $project,int $id,bool $active): void {
    atomic($db,function()use($db,$u,$project,$id,$active){$u=orgOwner($db,$u);$s=bookSource($db,$u,$project,$id);if($active){$s['active']=1;bookSourceConfig($db,$u,$s);}query($db,'UPDATE book_sources SET active=? WHERE id=?',[$active?1:0,$id]);auditEvent($db,$u,$project,'book.toggle',$id,$active?'active':'disabled');});
}
function bookEdition(PDO $db,array $u,int $project,int $id): array {
    $u=bookActor($db,$u,$project);$r=entity($db,'SELECT * FROM book_editions WHERE project_id=? AND id=?',[$project,$id]);bookSourceConfig($db,$u,bookSource($db,$u,$project,(int)$r['source_id']));
    if(!hash_equals($r['snapshot_hash'],hash('sha256',$r['snapshot'])))throw new Problem('Повреждён снимок книги.',409);return $r;
}
function bookReviewer(PDO $db,array $u,int $project,int $id): void {bookActor($db,['id'=>$id,'organization_id'=>$u['organization_id']],$project,true);}
function bookCapture(PDO $db,array $u,int $project,int $source,string $request,array $form,?callable $transport=null): int {
    $u=bookActor($db,$u,$project,true);$s=bookSource($db,$u,$project,$source);$c=bookSourceConfig($db,$u,$s);
    if(!preg_match('/\A[a-f0-9]{32}\z/D',$request))throw new Problem('Некорректный ключ формы.');
    $title=word($form['title']??'',120,'Название подборки');$a=numberId($form['reviewer_a']??0);$b=numberId($form['reviewer_b']??0);
    if($a===$b||$a===(int)$u['id']||$b===(int)$u['id'])throw new Problem('Нужны два независимых рецензента.',422);
    bookReviewer($db,$u,$project,$a);bookReviewer($db,$u,$project,$b);
    $keys=$form['pages']??null;if(!is_array($keys)||!array_is_list($keys)||!$keys||count($keys)>BOOK_PAGE_LIMIT)throw new Problem('Выберите 1–12 страниц.',422);
    $keys=array_map(__NAMESPACE__.'\\bookKey',$keys);if(count(array_unique($keys))!==count($keys))throw new Problem('Повтор страницы.',422);sort($keys,SORT_STRING);
    foreach($keys as $k)if(!str_starts_with($k,$s['scope'].'/'))throw new Problem('Страница вне книги.',403);
    $selection=hash('sha256',bookJson([$source,$title,$a,$b,$keys]));
    $prior=static function()use($db,$project,$request,$selection,$u):?int{$r=query($db,'SELECT id,selection_hash,author_id FROM book_editions WHERE project_id=? AND request_key=?',[$project,$request])->fetch();if(!$r)return null;if((int)$r['author_id']!==(int)$u['id']||!hash_equals($r['selection_hash'],$selection))throw new Problem('Ключ формы уже использован.',409);return (int)$r['id'];};
    if(($id=$prior())!==null)return $id;
    $catalog=bookCatalog($c,$s['scope'],$transport);$pages=[];$deadline=microtime(true)+17;
    foreach($keys as $k){if(microtime(true)>$deadline)throw new Problem('Слишком долгая загрузка подборки.',503);if(!isset($catalog[$k]))throw new Problem('Страница недоступна в Reader.',404);$p=bookRead($c,$k,$transport);$meta=$p;unset($meta['body']);if($meta!==$catalog[$k])throw new Problem('Каталог изменился при чтении. Обновите страницу.',409);$pages[]=['key'=>$k,'page'=>$p,'sha256'=>hash('sha256',bookJson($p))];}
    $snapshot=bookJson(['schema'=>1,'reader'=>$c['reader'],'scope'=>$s['scope'],'pages'=>$pages]);$hash=hash('sha256',$snapshot);
    return atomic($db,function()use($db,$u,$project,$source,$request,$title,$a,$b,$c,$prior,$selection,$snapshot,$hash){$u=bookActor($db,$u,$project,true);$fresh=bookSourceConfig($db,$u,bookSource($db,$u,$project,$source));if($fresh['hash']!==$c['hash'])throw new Problem('Подключение изменилось.',409);bookReviewer($db,$u,$project,$a);bookReviewer($db,$u,$project,$b);if(($id=$prior())!==null)return $id;
        $id=insertId($db,'INSERT INTO book_editions(project_id,source_id,title,snapshot,snapshot_hash,selection_hash,author_id,reviewer_a,reviewer_b,request_key,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$project,$source,$title,$snapshot,$hash,$selection,$u['id'],$a,$b,$request,time()]);auditEvent($db,$u,$project,'book.capture',$id,$hash);return $id;});
}
function bookReviews(PDO $db,array $r): array {return query($db,'SELECT * FROM book_reviews WHERE project_id=? AND edition_id=? ORDER BY id',[$r['project_id'],$r['id']])->fetchAll();}
function bookReviewState(array $r,array $reviews): string {
    $seen=[];foreach($reviews as $v){$id=(int)$v['reviewer_id'];if(!in_array($id,[(int)$r['reviewer_a'],(int)$r['reviewer_b']],true)||isset($seen[$id])||!hash_equals($r['snapshot_hash'],$v['snapshot_hash']))throw new Problem('Некорректная история рецензий.',409);$seen[$id]=$v['decision'];}
    if(in_array('changes',$seen,true))return 'changes';return count($seen)===2&&count(array_filter($seen,fn($d)=>$d==='approve'))===2?'approved':'review';
}
function bookReview(PDO $db,array $u,int $project,int $id,string $hash,string $decision,string $note): void {
    $decision=choice($decision,['approve'=>'approve','changes'=>'changes']);$note=word($note,1000,'Комментарий рецензии');
    atomic($db,function()use($db,$u,$project,$id,$hash,$decision,$note){$u=bookActor($db,$u,$project,true);$r=bookEdition($db,$u,$project,$id);if(!in_array((int)$u['id'],[(int)$r['reviewer_a'],(int)$r['reviewer_b']],true))throw new Problem('Вы не назначены рецензентом.',403);if(!hash_equals($r['snapshot_hash'],$hash))throw new Problem('Снимок не совпадает.',409);
        $rows=bookReviews($db,$r);$state=bookReviewState($r,$rows);foreach($rows as $v)if((int)$v['reviewer_id']===(int)$u['id']){if($v['decision']===$decision&&$v['note']===$note)return;throw new Problem('Решение уже зафиксировано.',409);}if($state!=='review')throw new Problem('Рецензирование завершено.',409);
        query($db,'INSERT INTO book_reviews(project_id,edition_id,reviewer_id,decision,note,snapshot_hash,created_at) VALUES(?,?,?,?,?,?,?)',[$project,$id,$u['id'],$decision,$note,$hash,time()]);auditEvent($db,$u,$project,'book.review',$id,$decision);});
}
function bookLinkRelease(PDO $db,array $u,int $project,int $id,int $release,string $hash): int {
    return atomic($db,function()use($db,$u,$project,$id,$release,$hash){$u=releaseManager($db,$u,$project);$r=bookEdition($db,$u,$project,$id);if(!hash_equals($r['snapshot_hash'],$hash)||bookReviewState($r,bookReviews($db,$r))!=='approved')throw new Problem('Требуются две рецензии именно этого снимка.',409);
        $rel=releaseRow($db,$project,$release);if($rel['state']!=='confirmed'||!is_string($rel['evidence'])||!is_string($rel['evidence_hash'])||!hash_equals($rel['evidence_hash'],hash('sha256',$rel['evidence'])))throw new Problem('Требуется подтверждённый выпуск с целостным снимком.',409);
        $old=query($db,'SELECT * FROM book_release_links WHERE release_id=? AND source_id=?',[$release,$r['source_id']])->fetch();if($old){if((int)$old['edition_id']===$id)return (int)$old['id'];throw new Problem('Редакция этой книги уже закреплена за выпуском.',409);}
        bookReviewer($db,$u,$project,(int)$r['reviewer_a']);bookReviewer($db,$u,$project,(int)$r['reviewer_b']);releaseRepo($db,$rel);
        $link=insertId($db,'INSERT INTO book_release_links(project_id,release_id,source_id,edition_id,tag,commit_sha,release_hash,snapshot_hash,linked_by,linked_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$project,$release,$r['source_id'],$id,$rel['tag'],$rel['commit_sha'],$rel['evidence_hash'],$hash,$u['id'],time()]);auditEvent($db,$u,$project,'book.release',$link,$hash);return $link;});
}
function bookReleaseLinks(PDO $db,array $u,int $project,int $release): array {
    $u=bookActor($db,$u,$project);$out=[];foreach(query($db,'SELECT l.*,s.name,s.active FROM book_release_links l JOIN book_sources s ON s.id=l.source_id AND s.project_id=l.project_id WHERE l.project_id=? AND l.release_id=? ORDER BY l.id',[$project,$release])->fetchAll() as $row){try{bookSourceConfig($db,$u,bookSource($db,$u,$project,(int)$row['source_id']));$out[]=$row;}catch(Problem){}}return $out;
}
function bookPage(PDO $db,array $u,?int $project,array $args,?callable $transport=null): array {
    $u=organizationUser($db,$u);$projects=rightProjects($db,$u,'connections');$sources=$available=$catalog=$editions=$reviews=$people=$releases=$links=[];$source=$edition=$snapshot=$preview=$error=null;$write=$manage=false;$state=null;
    if($project!==null){$u=bookActor($db,$u,$project);$role=projectRole($db,$u,$project);$write=roleAllows($role,'work');$manage=roleAllows($role,'manage');$sources=query($db,'SELECT * FROM book_sources WHERE project_id=? AND organization_id=? ORDER BY id',[$project,$u['organization_id']])->fetchAll();
        if($u['role']==='owner')foreach(connections() as $key=>$c)if(connectionAllowed($db,$u,$key)){try{bookConfig($c);$available[$key]=$c['label'];}catch(Problem){}}
        if(($args['edition']??'')!==''){$edition=bookEdition($db,$u,$project,numberId($args['edition']));$source=bookSource($db,$u,$project,(int)$edition['source_id']);$snapshot=json_decode($edition['snapshot'],true,64,JSON_THROW_ON_ERROR);$reviews=bookReviews($db,$edition);$state=bookReviewState($edition,$reviews);$links=query($db,'SELECT * FROM book_release_links WHERE project_id=? AND edition_id=? ORDER BY id',[$project,$edition['id']])->fetchAll();if($manage&&$state==='approved')$releases=query($db,"SELECT id,tag,commit_sha FROM project_releases WHERE project_id=? AND state='confirmed' ORDER BY id DESC LIMIT 100",[$project])->fetchAll();}
        elseif(($args['source']??'')!=='')$source=bookSource($db,$u,$project,numberId($args['source']));
        if($source){try{$c=bookSourceConfig($db,$u,$source);$editions=query($db,'SELECT id,title,snapshot_hash,created_at FROM book_editions WHERE project_id=? AND source_id=? ORDER BY id DESC LIMIT 100',[$project,$source['id']])->fetchAll();if(!$edition){$catalog=bookCatalog($c,$source['scope'],$transport);if(($args['page_key']??'')!==''){$k=bookKey($args['page_key']);if(!isset($catalog[$k]))throw new Problem('Страница недоступна в Reader.',404);$preview=['key'=>$k,'page'=>bookRead($c,$k,$transport)];}}}catch(Problem $e){$error=$e->getMessage();}}
        if($write&&!$edition)foreach(query($db,'SELECT u.id,u.name FROM users u JOIN organization_members m ON m.user_id=u.id WHERE m.organization_id=? AND u.enabled=1 ORDER BY u.name,u.id',[$u['organization_id']])->fetchAll() as $p){if((int)$p['id']===(int)$u['id'])continue;try{bookReviewer($db,$u,$project,(int)$p['id']);$people[]=$p;}catch(Problem){}}
    }
    return compact('projects','project','sources','available','catalog','editions','reviews','people','releases','links','source','edition','snapshot','preview','error','write','manage','state');
}
