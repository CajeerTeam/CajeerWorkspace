<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

/** One editorial release owns a pinned Book page, one native CMS operation and one Forum target. */
function editorialActor(PDO $db,array $u,int $project): array {
    $u=organizationUser($db,$u);requireProjectRight($db,$u,$project,'connections');requireProjectRight($db,$u,$project,'manage');return $u;
}
function editorialRow(PDO $db,array $u,int $project,int $id): array {
    editorialActor($db,$u,$project);return entity($db,'SELECT * FROM editorial_releases WHERE project_id=? AND id=?',[$project,$id]);
}
function editorialPageSnapshot(PDO $db,array $u,int $project,int $edition,string $key): array {
    $r=bookEdition($db,$u,$project,$edition);if(bookReviewState($r,bookReviews($db,$r))!=='approved')throw new Problem('Редакция Book не согласована.',409);
    bookReviewer($db,$u,$project,(int)$r['reviewer_a']);bookReviewer($db,$u,$project,(int)$r['reviewer_b']);
    foreach(json_decode($r['snapshot'],true,64,JSON_THROW_ON_ERROR)['pages'] as $p)if($p['key']===bookKey($key)){
        if(!hash_equals($p['sha256'],hash('sha256',bookJson($p['page'])))||$p['page']['media']||trim($p['page']['body'])==='')throw new Problem('Требуется целостная текстовая страница без медиа.',409);
        return ['edition_hash'=>$r['snapshot_hash'],'page_hash'=>$p['sha256'],'title'=>$p['page']['title'],'body'=>$p['page']['body']];
    }throw new Problem('Страница не входит в редакцию.',404);
}
function editorialEngineMatch(array $p,array $page,string $field): void {
    $s=engineExpected($p);if($s['title']!==$page['title']||($s['data'][$field]??null)!==$page['body'])throw new Problem('Заголовок и текст черновика Engine должны точно совпадать с Book.',409);
}
function editorialEvent(PDO $db,array $u,array $r,string $step,string $state,string $detail=''): void {
    // Caller holds the database write lock. Repeated observations are not new events.
    $last=query($db,'SELECT state,detail FROM editorial_events WHERE editorial_id=? AND step=? ORDER BY id DESC LIMIT 1',[$r['id'],$step])->fetch();
    if($last&&$last['state']===$state&&$last['detail']===$detail)return;
    query($db,'INSERT INTO editorial_events(project_id,editorial_id,actor_id,step,state,detail,created_at) VALUES(?,?,?,?,?,?,?)',[$r['project_id'],$r['id'],$u['id'],$step,$state,$detail,time()]);
    auditEvent($db,$u,(int)$r['project_id'],'editorial.'.$step,(int)$r['id'],$state);
}
function editorialKey(array $r,string $purpose): string {return substr(hash('sha256','editorial:'.$r['project_id'].':'.$r['id'].':'.$r['request_key'].':'.$purpose),0,32);}
function editorialCreate(PDO $db,array $u,int $project,string $request,array $f): int {
    $u=editorialActor($db,$u,$project);if(!preg_match('/\A[a-f0-9]{32}\z/D',$request))throw new Problem('Обновите форму.');
    $release=numberId($f['release_id']??0);$edition=numberId($f['edition_id']??0);$key=bookKey($f['page_key']??null);$forum=numberId($f['forum_source_id']??0);
    $kind=choice($f['cms']??'', ['engine'=>'engine','lite'=>'lite']);$ep=$field=$ls=$path=$hash=null;
    if($kind==='engine'){$ep=numberId($f['engine_publication_id']??0);$field=engineSegment($f['engine_field']??null);}else{$ls=numberId($f['lite_site_id']??0);$path=litePath($f['lite_path']??null);$hash=liteHash($f['lite_hash']??null);}
    $base=getenv('WORKSPACE_EDITORIAL_ORIGIN');$origin=$base!==false&&$base!==''?liteOrigin($base):null;
    $plan=hash('sha256',bookJson([$release,$edition,$key,$forum,$kind,$ep,$field,$ls,$path,$hash,$origin]));
    $prior=function()use($db,$u,$project,$request,$plan):?int{$old=query($db,'SELECT * FROM editorial_releases WHERE project_id=? AND request_key=?',[$project,$request])->fetch();if(!$old)return null;if((int)$old['actor_id']!==(int)$u['id']||!hash_equals($old['plan_hash'],$plan))throw new Problem('Ключ уже использован.',409);return (int)$old['id'];};
    if(($id=$prior())!==null)return $id;
    $page=editorialPageSnapshot($db,$u,$project,$edition,$key);$source=forumSource($db,$u,$project,$forum);if(!(int)$source['active'])throw new Problem('Forum отключён.',409);
    if(forumFindTarget($db,$source,'release',$release))throw new Problem('Обсуждение этого выпуска уже зарегистрировано в разделе.',409);
    if($ep!==null){$p=enginePublication($db,$u,$project,$ep);engineSiteConfig($db,$u,engineSite($db,$u,$project,(int)$p['site_id']));if($p['state']!=='ready')throw new Problem('Выберите подготовленную публикацию Engine.',409);editorialEngineMatch($p,$page,$field);}
    else{$s=liteSite($db,$u,$project,$ls);liteConfig($s);liteBook($db,$u,$project,$edition,$key);if($s['site_hash']!==$hash||time()-(int)$s['inspected_at']>3600||isset(json_decode($s['catalog'],true,64,JSON_THROW_ON_ERROR)[$path]))throw new Problem('Обновите снимок Lite и выберите свободный адрес.',409);}
    return atomic($db,function()use($db,$u,$project,$request,$release,$edition,$key,$forum,$ep,$field,$ls,$path,$hash,$plan,$page,$prior,$origin){
        $u=editorialActor($db,$u,$project);if(($id=$prior())!==null)return $id;
        $fresh=editorialPageSnapshot($db,$u,$project,$edition,$key);if($fresh!==$page)throw new Problem('Снимок изменился.',409);
        $s=forumSource($db,$u,$project,$forum);if(!(int)$s['active']||forumFindTarget($db,$s,'release',$release)||query($db,'SELECT 1 FROM editorial_releases WHERE release_id=? AND forum_source_id=? AND abandoned_at IS NULL',[$release,$forum])->fetchColumn())throw new Problem('Уже существует маршрут или обсуждение.',409);
        if($ep!==null){$p=enginePublication($db,$u,$project,$ep);if($p['state']!=='ready'||query($db,'SELECT 1 FROM editorial_releases WHERE engine_publication_id=?',[$ep])->fetchColumn())throw new Problem('Публикация уже используется.',409);editorialEngineMatch($p,$page,$field);}
        else{$s=liteSite($db,$u,$project,$ls);liteConfig($s);if($s['site_hash']!==$hash||time()-(int)$s['inspected_at']>3600||isset(json_decode($s['catalog'],true,64,JSON_THROW_ON_ERROR)[$path]))throw new Problem('Снимок Lite изменился.',409);}
        $rel=releaseRow($db,$project,$release);releaseRepo($db,$rel);$link=bookLinkReleaseLocked($db,$u,$project,$edition,$release,$page['edition_hash']);
        $id=insertId($db,'INSERT INTO editorial_releases(project_id,release_id,book_link_id,edition_id,page_key,page_hash,snapshot_hash,engine_publication_id,engine_field,lite_site_id,lite_path,lite_hash,forum_source_id,actor_id,request_key,plan_hash,workspace_origin,forum_key,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$project,$release,$link,$edition,$key,$page['page_hash'],$page['edition_hash'],$ep,$field,$ls,$path,$hash,$forum,$u['id'],$request,$plan,$origin,bin2hex(random_bytes(16)),time()]);
        $r=editorialRow($db,$u,$project,$id);editorialEvent($db,$u,$r,'book','pinned',$page['edition_hash']);return $id;
    });
}
function editorialCheck(PDO $db,array $u,array $r): array {
    $project=(int)$r['project_id'];$u=editorialActor($db,$u,$project);if($r['abandoned_at']!==null)throw new Problem('Маршрут закрыт.',409);if((int)$r['actor_id']!==(int)$u['id'])throw new Problem('Действия выполняет создатель маршрута с личным токеном Forum.',403);
    $page=editorialPageSnapshot($db,$u,$project,(int)$r['edition_id'],$r['page_key']);$rel=releaseRow($db,$project,(int)$r['release_id']);
    $link=entity($db,'SELECT * FROM book_release_links WHERE project_id=? AND id=?',[$project,$r['book_link_id']]);
    if($page['edition_hash']!==$r['snapshot_hash']||$page['page_hash']!==$r['page_hash']||(int)$link['edition_id']!==(int)$r['edition_id']||(int)$link['release_id']!==(int)$r['release_id']||$link['snapshot_hash']!==$r['snapshot_hash']||$rel['state']!=='confirmed'||!is_string($rel['evidence'])||hash('sha256',$rel['evidence'])!==$link['release_hash']||$rel['evidence_hash']!==$link['release_hash']||$rel['tag']!==$link['tag']||$rel['commit_sha']!==$link['commit_sha'])throw new Problem('Нарушена привязка документации к выпуску.',409);
    releaseRepo($db,$rel);return $page;
}
function editorialForLiteJob(PDO $db,array $j): array|false {
    // Stored request key is derived before creating the native job and survives an interrupted caller.
    foreach(query($db,'SELECT * FROM editorial_releases WHERE project_id=? AND lite_site_id=? AND edition_id=? AND lite_path=? AND lite_hash=?',[$j['project_id'],$j['site_id'],$j['edition_id'],$j['path'],$j['expected_hash']])->fetchAll() as $r)if(hash_equals(editorialKey($r,'lite'),$j['request_key']))return $r;
    return false;
}
function editorialCmsState(PDO $db,array $u,array $r): array {
    $project=(int)$r['project_id'];if($r['engine_publication_id']!==null){$p=enginePublication($db,$u,$project,(int)$r['engine_publication_id']);return ['id'=>(int)$p['id'],'state'=>$p['state'],'url'=>in_array($p['state'],['published','observed'],true)?$p['public_url']:null];}
    $s=liteSite($db,$u,$project,(int)$r['lite_site_id']);$j=query($db,'SELECT * FROM lite_jobs WHERE project_id=? AND site_id=? AND request_key=?',[$project,$s['id'],editorialKey($r,'lite')])->fetch();
    if(!$j)return ['id'=>null,'state'=>'ready','url'=>null];
    if((int)$j['edition_id']!==(int)$r['edition_id']||$j['path']!==$r['lite_path']||$j['expected_hash']!==$r['lite_hash']||$j['kind']!=='book'||(int)$j['actor_id']!==(int)$r['actor_id']||hash('sha256',$j['payload'])!==$j['payload_hash'])throw new Problem('Неверная операция Lite.',409);
    $payload=json_decode($j['payload'],true,64,JSON_THROW_ON_ERROR);if($payload['key']!==$r['page_key']||$payload['page_hash']!==$r['page_hash']||$payload['edition_hash']!==$r['snapshot_hash'])throw new Problem('Снимок Lite не соответствует Book.',409);
    return ['id'=>(int)$j['id'],'state'=>$j['state'],'url'=>$j['state']==='published'&&$j['after_hash']!==null&&$j['backup_hash']!==null?$s['origin'].$j['path']:null];
}
function editorialForumState(PDO $db,array $r): array {
    $op=query($db,"SELECT * FROM forum_operations WHERE actor_id=? AND request_key=? AND project_id=? AND source_id=? AND operation='create'",[$r['actor_id'],$r['forum_key'],$r['project_id'],$r['forum_source_id']])->fetch();
    if(!$op)return ['state'=>'ready','topic'=>null];
    $t=entity($db,'SELECT * FROM forum_topics WHERE id=? AND project_id=? AND source_id=? AND release_id=?',[$op['topic_id'],$r['project_id'],$r['forum_source_id'],$r['release_id']]);
    return ['state'=>$t['state'],'topic'=>$t,'operation'=>$op];
}
function editorialObserveCms(PDO $db,array $u,array $r): array {
    return atomic($db,function()use($db,$u,$r){$r=editorialRow($db,$u,(int)$r['project_id'],(int)$r['id']);$c=editorialCmsState($db,$u,$r);if($c['url']!==null&&$r['cms_url']===null){query($db,'UPDATE editorial_releases SET cms_url=?,cms_confirmed_at=? WHERE id=?',[$c['url'],time(),$r['id']]);}
        editorialEvent($db,$u,$r,'cms',$c['state'],$c['url']??'');return $c;});
}
function editorialCms(PDO $db,array $u,int $project,int $id,?callable $transport=null): array {
    $r=editorialRow($db,$u,$project,$id);$page=editorialCheck($db,$u,$r);$c=editorialCmsState($db,$u,$r);
    atomic($db,fn()=>editorialEvent($db,$u,$r,'cms','requested',$c['state']));
    try{if($r['engine_publication_id']!==null){$p=enginePublication($db,$u,$project,$c['id']);editorialEngineMatch($p,$page,$r['engine_field']);if($c['state']==='ready')engineConfirm($db,$u,$project,$c['id'],$p['snapshot_hash'],$transport);elseif(in_array($c['state'],['sending','uncertain','published','observed'],true))engineReconcile($db,$u,$project,$c['id'],$transport);}
    elseif($c['id']===null)liteQueue($db,$u,$project,(int)$r['lite_site_id'],editorialKey($r,'lite'),['kind'=>'book','path'=>$r['lite_path'],'site_hash'=>$r['lite_hash'],'edition_id'=>$r['edition_id'],'page_key'=>$r['page_key']]);
    }catch(Problem $e){atomic($db,fn()=>editorialEvent($db,$u,$r,'cms','blocked','HTTP '.$e->status));throw $e;}
    return editorialObserveCms($db,$u,$r);
}
function editorialForumPayload(array $r,array $rel): array {
    $title=word('Release '.$rel['tag'],180,'Тема выпуска');$body="Release ".$rel['tag']."\nPublished documentation: ".$r['cms_url'];
    // Optional public origin, explicitly configured by the operator. Never use the incoming Host header.
    if($r['workspace_origin']!==null){$origin=liteOrigin($r['workspace_origin']);$body.="\nRelease record: ".$origin.'/?p=releases&project='.(int)$r['project_id'].'&id='.(int)$r['release_id'];}
    return [$title,forumBody($body)];
}
function editorialForumReservation(PDO $db,array $u,int $project,int $source,string $kind,int $target,string $key,string $payloadHash): void {
    if($kind!=='release')return;
    $r=query($db,'SELECT * FROM editorial_releases WHERE project_id=? AND release_id=? AND forum_source_id=? AND abandoned_at IS NULL',[$project,$target,$source])->fetch();if(!$r)return;
    editorialCheck($db,$u,$r);$cms=editorialCmsState($db,$u,$r);if($r['forum_key']!==$key||$cms['url']===null||$cms['url']!==$r['cms_url'])throw new Problem('Тема зарезервирована редакторским маршрутом; завершите этап CMS и используйте карточку выпуска.',409);
    [$title,$body]=editorialForumPayload($r,releaseRow($db,$project,$target));if(!hash_equals($payloadHash,forumRequestHash('create',[$kind,$target,['title'=>$title,'body'=>$body,'body_format'=>'plain','kind'=>'discussion']])))throw new Problem('Запрос Forum не соответствует редакторскому выпуску.',409);
}
function editorialAbandon(PDO $db,array $u,int $project,int $id): void {
    atomic($db,function()use($db,$u,$project,$id){$u=editorialActor($db,$u,$project);$r=editorialRow($db,$u,$project,$id);if($r['abandoned_at']!==null)return;if((int)$r['actor_id']!==(int)$u['id']&&$u['role']!=='owner')throw new Problem('Требуется создатель или владелец.',403);$c=editorialCmsState($db,$u,$r);$f=editorialForumState($db,$r);
        if($r['cms_url']!==null||$f['topic']!==null||(!in_array($c['state'],['conflict','cancelled','aborted'],true)&&!($r['lite_site_id']!==null&&$c['id']===null)))throw new Problem('Закрыть можно только маршрут без публикации и неопределённых операций.',409);
        query($db,'UPDATE editorial_releases SET abandoned_at=? WHERE id=?',[time(),$id]);editorialEvent($db,$u,$r,'cms','abandoned',$c['state']);});
}
function editorialForum(PDO $db,array $u,int $project,int $id,string $action,string $attemptKey,?callable $transport=null): string {
    $r=editorialRow($db,$u,$project,$id);editorialCheck($db,$u,$r);$cms=editorialObserveCms($db,$u,$r);$r=editorialRow($db,$u,$project,$id);
    if($cms['url']===null||$cms['url']!==$r['cms_url'])throw new Problem('Требуется подтверждённая публикация и неизменившийся публичный маршрут.',409);
    if($r['engine_publication_id']!==null){$p=enginePublication($db,$u,$project,(int)$r['engine_publication_id']);engineSiteConfig($db,$u,engineSite($db,$u,$project,(int)$p['site_id']));}else liteConfig(liteSite($db,$u,$project,(int)$r['lite_site_id']));
    $source=forumSource($db,$u,$project,(int)$r['forum_source_id']);$f=editorialForumState($db,$r);$rel=releaseRow($db,$project,(int)$r['release_id']);[$title,$body]=editorialForumPayload($r,$rel);
    if($action==='retry'){
        $r=atomic($db,function()use($db,$u,$r,$attemptKey){$fresh=editorialRow($db,$u,(int)$r['project_id'],(int)$r['id']);if($fresh['forum_key']!==$attemptKey)throw new Problem('Обновите карточку маршрута.',409);$f=editorialForumState($db,$fresh);if($f['state']!=='rejected')throw new Problem('Повтор разрешён только после однозначного отказа Forum.',409);editorialEvent($db,$u,$fresh,'forum','rejected','Попытка '.$fresh['forum_attempt']);query($db,'UPDATE editorial_releases SET forum_key=?,forum_attempt=forum_attempt+1 WHERE id=?',[bin2hex(random_bytes(16)),$fresh['id']]);return editorialRow($db,$u,(int)$fresh['project_id'],(int)$fresh['id']);});
        $f=editorialForumState($db,$r);
    }elseif($action!=='advance')throw new Problem('Неизвестное действие.');elseif($attemptKey!==$r['forum_key'])throw new Problem('Обновите карточку маршрута.',409);
    atomic($db,fn()=>editorialEvent($db,$u,$r,'forum','requested','Попытка '.$r['forum_attempt'].' · '.$f['state']));
    try{
        if($f['state']==='ready')forumCreate($db,$u,$project,(int)$source['id'],'release',(int)$r['release_id'],$r['forum_key'],$title,$body,$transport,(int)$r['forum_attempt']>1);
        elseif(in_array($f['state'],['pending','sending'],true))forumReconcile($db,$u,$project,(int)$source['id'],(int)$f['topic']['id'],$transport);
        // Uncertain sends are recovered only by the native Forum attachment workflow.
        $f=editorialForumState($db,$r);
        if(isset($f['operation'])&&!hash_equals($f['operation']['payload_hash'],forumRequestHash('create',['release',(int)$r['release_id'],['title'=>$title,'body'=>$body,'body_format'=>'plain','kind'=>'discussion']])))throw new Problem('Неверный снимок запроса Forum.',409);
        if($f['state']==='linked'){
            $c=forumSourceSession($db,$u,$source,$transport);if($c['forum_actor']!==$f['operation']['forum_actor'])throw new Problem('Учётная запись Forum изменилась.',403);$thread=forumThread($c,$source['board_id'],$f['topic']['thread_id'],1,$transport);
            // Verify the actual first post before treating a manually recovered topic as our publication.
            if(($thread['thread']['title']??null)!==$title||!is_string($thread['first_post_id']??null)||($thread['posts'][0]['id']??null)!==$thread['first_post_id']||($thread['posts'][0]['body']??null)!==$body||($thread['posts'][0]['author_id']??null)!==$f['operation']['forum_actor'])throw new Problem('Тема не соответствует тексту или автору редакторского выпуска.',409);
        }
        $state=$f['state'];
    }catch(Problem $e){$state='blocked';atomic($db,function()use($db,$u,$r,$e){editorialEvent($db,$u,$r,'forum','blocked','Попытка '.$r['forum_attempt'].' · HTTP '.$e->status);});throw $e;}
    atomic($db,function()use($db,$u,$r,$state){editorialEvent($db,$u,$r,'forum',$state,'Попытка '.$r['forum_attempt']);});return $state;
}
function editorialPage(PDO $db,array $u,?int $project,array $args): array {
    $u=organizationUser($db,$u);$projects=rightProjects($db,$u,'manage');$runs=$editions=$releases=$engine=$lite=$forum=$events=[];$run=$cms=$discussion=null;$verified=false;
    if($project!==null){$u=editorialActor($db,$u,$project);$filter='';$params=[$project];foreach(['release'=>'release_id','edition'=>'edition_id','publication'=>'engine_publication_id','lite_site'=>'lite_site_id','forum_source'=>'forum_source_id'] as $arg=>$col)if(($args[$arg]??'')!==''){$filter.=' AND e.'.$col.'=?';$params[]=numberId($args[$arg]);}$runs=query($db,'SELECT e.*,r.tag FROM editorial_releases e JOIN project_releases r ON r.id=e.release_id WHERE e.project_id=?'.$filter.' ORDER BY e.id DESC LIMIT 100',$params)->fetchAll();
        if(($args['id']??'')!==''){$run=editorialRow($db,$u,$project,numberId($args['id']));$cms=editorialCmsState($db,$u,$run);$f=editorialForumState($db,$run);$discussion=(int)$run['actor_id']===(int)$u['id']?$f['state']:null;$events=query($db,'SELECT * FROM editorial_events WHERE editorial_id=? ORDER BY id DESC LIMIT 100',[$run['id']])->fetchAll();if($discussion===null)$events=array_values(array_filter($events,fn($e)=>$e['step']!=='forum'));foreach($events as $e)if($e['step']==='forum'){$verified=$e['state']==='linked'&&$discussion==='linked';break;}}
        else{foreach(query($db,'SELECT id,title FROM book_editions WHERE project_id=? ORDER BY id DESC LIMIT 50',[$project])->fetchAll() as $e){try{$b=bookEdition($db,$u,$project,(int)$e['id']);if(bookReviewState($b,bookReviews($db,$b))!=='approved')continue;bookReviewer($db,$u,$project,(int)$b['reviewer_a']);bookReviewer($db,$u,$project,(int)$b['reviewer_b']);foreach(json_decode($b['snapshot'],true,64,JSON_THROW_ON_ERROR)['pages'] as $p)if(!$p['page']['media'])$editions[$b['id'].':'.$p['key']]=$e['title'].' #'.$e['id'].' · '.$p['key'];}catch(Problem){}}$releases=query($db,"SELECT id,tag FROM project_releases WHERE project_id=? AND state='confirmed' ORDER BY id DESC LIMIT 100",[$project])->fetchAll();$engine=query($db,"SELECT p.id,p.content_type,p.entry_id,p.revision,s.name FROM engine_publications p JOIN engine_sites s ON s.id=p.site_id WHERE p.project_id=? AND p.state='ready' AND s.active=1 ORDER BY p.id DESC LIMIT 100",[$project])->fetchAll();$lite=query($db,'SELECT id,name,site_hash FROM lite_sites WHERE project_id=? AND active=1 AND site_hash IS NOT NULL ORDER BY id LIMIT 100',[$project])->fetchAll();$forum=query($db,'SELECT id FROM forum_sources WHERE project_id=? AND active=1 ORDER BY id LIMIT 100',[$project])->fetchAll();}
    }return compact('project','projects','runs','editions','releases','engine','lite','forum','run','cms','discussion','events','verified');
}
