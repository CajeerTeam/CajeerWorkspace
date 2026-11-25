<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

/** Operator-owned allowlist. Web code never includes or writes CMS files. */
function liteOrigin(string $v): string {
    $p=parse_url($v);if(!is_array($p)||!isset($p['scheme'],$p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||!in_array($p['path']??'',['','/'],true)||!filter_var($v,FILTER_VALIDATE_URL))throw new Problem('Некорректный origin Lite.',503);
    $scheme=strtolower($p['scheme']);$host=strtolower($p['host']);$port=$p['port']??($scheme==='https'?443:80);
    if($scheme!=='https'&&!($scheme==='http'&&in_array($host,['127.0.0.1','localhost','[::1]'],true)))throw new Problem('Lite требует HTTPS.',503);
    return $scheme.'://'.$host.(($scheme==='https'&&$port===443||$scheme==='http'&&$port===80)?'':':'.$port);
}
function liteTargets(): array {
    $file=getenv('WORKSPACE_LITE_TARGETS');if(!$file)return [];
    if(!str_starts_with($file,'/')||is_link($file)||!is_file($file)||filesize($file)>65536||((fileperms($file)&0022)!==0))throw new Problem('Небезопасная конфигурация Lite.',503);
    try{$data=json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new Problem('Некорректная конфигурация Lite.',503);}
    if(!is_array($data)||count($data)>100)throw new Problem('Некорректная конфигурация Lite.',503);
    $out=[];$roots=[];$origins=[];
    foreach($data as $key=>$row){
        if(!is_string($key)||!preg_match('/\A[a-z][a-z0-9_-]{0,47}\z/D',$key)||!is_array($row)||!is_string($row['root']??null)||!preg_match('~\A/(?:[A-Za-z0-9_-][A-Za-z0-9_.-]*/)*[A-Za-z0-9_-][A-Za-z0-9_.-]*\z~D',$row['root'])||!is_int($row['organization_id']??null)||$row['organization_id']<1||!is_int($row['project_id']??null)||$row['project_id']<1||!is_string($row['origin']??null)||($row['version']??null)!=='1.6.0')throw new Problem('Некорректная цель Lite.',503);
        $origin=liteOrigin($row['origin']);if($origin!==$row['origin']||isset($roots[$row['root']])||isset($origins[$origin]))throw new Problem('Дублирующаяся или неканоническая цель Lite.',503);
        $roots[$row['root']]=true;$origins[$origin]=true;
        $c=['root'=>$row['root'],'organization_id'=>$row['organization_id'],'project_id'=>$row['project_id'],'origin'=>$origin,'version'=>'1.6.0'];
        $c['hash']=hash('sha256',json_encode($c,JSON_THROW_ON_ERROR));$out[$key]=$c;
    }return $out;
}
function liteHash(mixed $v): string {if(!is_string($v)||!preg_match('/\A[a-f0-9]{64}\z/D',$v))throw new Problem('Некорректный хеш Lite.');return $v;}
function litePath(mixed $v): string {
    if(!is_string($v)||strlen($v)>1024||!preg_match('~\A/(?:[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*)?\z~D',$v)||preg_match('~\A/(?:api|admin|search|uploads|install|upgrade|preview|extensions|assets|health|metrics|media)(?:/|$)~i',$v))throw new Problem('Укажите канонический путь Lite (ASCII).');return $v;
}
function liteSite(PDO $db,array $u,int $project,int $site): array {
    $u=organizationUser($db,$u);requireProjectRight($db,$u,$project,'connections');return entity($db,'SELECT * FROM lite_sites WHERE organization_id=? AND project_id=? AND id=?',[$u['organization_id'],$project,$site]);
}
function liteConfig(array $s): array {
    $c=liteTargets()[$s['target_key']]??null;
    if(!(int)$s['active']||!$c||$c['organization_id']!==(int)$s['organization_id']||$c['project_id']!==(int)$s['project_id']||!hash_equals($s['config_hash'],$c['hash']))throw new Problem('Цель Lite отключена или конфигурация изменена.',409);return $c;
}
function liteBind(PDO $db,array $u,int $project,string $key,string $name): int {
    $name=word($name,120,'Сайт');return atomic($db,function()use($db,$u,$project,$key,$name){$u=orgOwner($db,$u);allowedProject($db,$u,$project);$c=liteTargets()[$key]??null;if(!$c||$c['organization_id']!==$u['organization_id']||$c['project_id']!==$project)throw new Problem('Цель не разрешена оператором для проекта.',403);
        $old=query($db,'SELECT * FROM lite_sites WHERE target_key=?',[$key])->fetch();if($old){if((int)$old['project_id']!==$project||$old['config_hash']!==$c['hash']||$old['name']!==$name)throw new Problem('Цель уже привязана.',409);return (int)$old['id'];}
        if(query($db,'SELECT 1 FROM lite_sites WHERE root_hash=? OR origin=?',[hash('sha256',$c['root']),$c['origin']])->fetchColumn())throw new Problem('Установка уже привязана.',409);
        $id=insertId($db,'INSERT INTO lite_sites(organization_id,project_id,target_key,root_hash,config_hash,origin,name,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$u['organization_id'],$project,$key,hash('sha256',$c['root']),$c['hash'],$c['origin'],$name,$u['id'],time()]);auditEvent($db,$u,$project,'lite.bind',$id);return $id;});
}
function liteBusy(PDO $db,int $site): bool {return (bool)query($db,"SELECT 1 FROM lite_jobs WHERE site_id=? AND state IN ('queued','running','uncertain','restore_queued','restoring','restore_uncertain')",[$site])->fetchColumn();}
function liteToggle(PDO $db,array $u,int $project,int $site,bool $active): void {
    atomic($db,function()use($db,$u,$project,$site,$active){$u=orgOwner($db,$u);$s=liteSite($db,$u,$project,$site);if(liteBusy($db,$site))throw new Problem('Сначала завершите операцию Lite.',409);if($active){$s['active']=1;liteConfig($s);}query($db,'UPDATE lite_sites SET active=?,site_hash=NULL,catalog=NULL,inspected_at=NULL WHERE id=?',[$active?1:0,$site]);auditEvent($db,$u,$project,'lite.toggle',$site,$active?'on':'off');});
}
function liteManager(PDO $db,array $u,int $project): array {$u=organizationUser($db,$u);requireProjectRight($db,$u,$project,'manage');requireProjectRight($db,$u,$project,'connections');return $u;}
function liteBook(PDO $db,array $u,int $project,int $edition,string $key): array {
    $r=bookEdition($db,$u,$project,$edition);if(bookReviewState($r,bookReviews($db,$r))!=='approved')throw new Problem('Требуется согласованная редакция Book.',409);
    bookReviewer($db,$u,$project,(int)$r['reviewer_a']);bookReviewer($db,$u,$project,(int)$r['reviewer_b']);
    foreach(json_decode($r['snapshot'],true,64,JSON_THROW_ON_ERROR)['pages'] as $p)if($p['key']===bookKey($key)){
        if(!hash_equals($p['sha256'],hash('sha256',bookJson($p['page']))))throw new Problem('Нарушена целостность страницы Book.',409);
        $page=$p['page'];if($page['media']||strlen($page['body'])>60000||trim($page['body'])==='')throw new Problem('Lite: только непустой текст до 60 000 байт без медиа.',422);
        return ['edition_hash'=>$r['snapshot_hash'],'key'=>$key,'page_hash'=>$p['sha256'],'title'=>$page['title'],'body'=>$page['body']];
    }throw new Problem('Страница отсутствует в подборке.',404);
}
function liteJob(PDO $db,array $u,int $project,int $site,int $id): array {liteSite($db,$u,$project,$site);return entity($db,'SELECT * FROM lite_jobs WHERE project_id=? AND site_id=? AND id=?',[$project,$site,$id]);}
function liteQueue(PDO $db,array $u,int $project,int $site,string $request,array $form): int {
    if(!preg_match('/\A[a-f0-9]{32}\z/D',$request))throw new Problem('Некорректный ключ формы.');
    $kind=choice($form['kind']??'', ['draft'=>'draft','book'=>'book']);$path=litePath($form['path']??null);$expected=liteHash($form['site_hash']??null);
    $edition=$kind==='book'?numberId($form['edition_id']??0):null;$key=$kind==='book'?bookKey($form['page_key']??null):null;
    return atomic($db,function()use($db,$u,$project,$site,$request,$kind,$path,$expected,$edition,$key){$u=liteManager($db,$u,$project);$s=liteSite($db,$u,$project,$site);liteConfig($s);
        $old=query($db,'SELECT * FROM lite_jobs WHERE site_id=? AND request_key=?',[$site,$request])->fetch();if($old){$payload=json_decode($old['payload'],true,64,JSON_THROW_ON_ERROR);if((int)$old['actor_id']!==(int)$u['id']||$old['kind']!==$kind||$old['path']!==$path||$old['expected_hash']!==$expected||($edition!==null&&((int)$old['edition_id']!==$edition||$payload['key']!==$key)))throw new Problem('Ключ уже использован.',409);return (int)$old['id'];}
        if(liteBusy($db,$site)||$s['site_hash']!==$expected||time()-(int)$s['inspected_at']>3600)throw new Problem('Обновите снимок Lite; либо операция уже выполняется.',409);
        $catalog=json_decode($s['catalog'],true,64,JSON_THROW_ON_ERROR);$page=$catalog[$path]??null;$revision=$page['revision']??null;
        if($kind==='draft'){if(!$page||$page['published'])throw new Problem('Выберите существующий черновик.',409);$payload=['revision'=>$revision];}
        else {if($page)throw new Problem('Публикация Book создаёт новую страницу; существующая не заменяется.',409);$payload=liteBook($db,$u,$project,$edition,$key);}
        $raw=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$id=insertId($db,"INSERT INTO lite_jobs(project_id,site_id,request_key,kind,path,expected_hash,page_revision,edition_id,payload,payload_hash,state,actor_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,'queued',?,?,?)",[$project,$site,$request,$kind,$path,$expected,$revision,$edition,$raw,hash('sha256',$raw),$u['id'],time(),time()]);auditEvent($db,$u,$project,'lite.queue',$id,$kind.' '.$path);return $id;});
}
function liteCommand(PDO $db,array $u,int $project,int $site,int $id,string $act): void {
    atomic($db,function()use($db,$u,$project,$site,$id,$act){$u=orgOwner($db,$u);$s=liteSite($db,$u,$project,$site);liteConfig($s);$j=liteJob($db,$u,$project,$site,$id);
        if($act==='cancel'){if(!in_array($j['state'],['queued','restore_queued'],true))throw new Problem('Операция уже началась.',409);$state=$j['state']==='queued'?'cancelled':'published';}
        elseif($act==='restore'){if($j['state']==='restore_queued')return;if($j['state']!=='published'||liteBusy($db,$site)||!$j['backup_name'])throw new Problem('Нет доступного снимка или операция занята.',409);$state='restore_queued';}
        else throw new Problem('Неизвестное действие.');
        query($db,'UPDATE lite_jobs SET state=?,restore_by=?,updated_at=?,detail=? WHERE id=?',[$state,$act==='restore'?$u['id']:null,time(),$act,$id]);auditEvent($db,$u,$project,'lite.'.$act,$id);});
}
function litePage(PDO $db,array $u,?int $project,array $args): array {
    $u=organizationUser($db,$u);$projects=rightProjects($db,$u,'connections');$sites=$available=$jobs=$catalog=$editions=$editionOptions=[];$site=$error=null;$manage=false;
    if($project!==null){requireProjectRight($db,$u,$project,'connections');$manage=roleAllows(projectRole($db,$u,$project),'manage');$sites=query($db,'SELECT * FROM lite_sites WHERE project_id=? ORDER BY id',[$project])->fetchAll();
        if($u['role']==='owner')try{foreach(liteTargets() as $k=>$c)if($c['project_id']===$project&&$c['organization_id']===$u['organization_id'])$available[$k]=$k.' · '.$c['origin'];}catch(Problem $e){$error=$e->getMessage();}
        if(($args['site']??'')!==''){$site=liteSite($db,$u,$project,numberId($args['site']));try{liteConfig($site);$jobs=query($db,'SELECT * FROM lite_jobs WHERE site_id=? ORDER BY id DESC LIMIT 100',[$site['id']])->fetchAll();$catalog=$site['catalog']?json_decode($site['catalog'],true,64,JSON_THROW_ON_ERROR):[];
            if($manage&&$site['site_hash']){
                foreach(query($db,'SELECT id,title,source_id,reviewer_a,reviewer_b,snapshot_hash FROM book_editions WHERE project_id=? ORDER BY id DESC LIMIT 100',[$project])->fetchAll() as $r){try{bookSourceConfig($db,$u,bookSource($db,$u,$project,(int)$r['source_id']));$r['project_id']=$project;if(bookReviewState($r,bookReviews($db,$r))==='approved')$editionOptions[$r['id']]='#'.$r['id'].' '.$r['title'];}catch(Problem){}}
                if(($args['edition']??'')!==''){$r=bookEdition($db,$u,$project,numberId($args['edition']));if(bookReviewState($r,bookReviews($db,$r))!=='approved')throw new Problem('Редакция ещё не согласована.',409);$editions[]=$r;$editionOptions[$r['id']]='#'.$r['id'].' '.$r['title'];}
            }}
            catch(Problem $e){$error=$e->getMessage();}}
    }return compact('projects','project','sites','available','site','jobs','catalog','editions','editionOptions','manage','error');
}
