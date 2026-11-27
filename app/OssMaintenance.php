<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const OSS_LINE_STATES=['planned'=>'Запланировано','deferred'=>'Отложено','rejected'=>'Отклонено','ready'=>'Готово к проверке выпуска','released'=>'Выпущено'];
const OSS_LINE_LIMIT=50;
const OSS_FIX_LIMIT=10;
function ossBranch(mixed $raw): string {
    $s=word($raw,100,'Ветка');
    if (!preg_match('~\A[A-Za-z0-9][A-Za-z0-9._/-]*\z~',$s)||str_contains($s,'..')||str_ends_with($s,'.')||preg_match('~(?:^|/)(?:\.|[^/]*\.lock(?:/|$))|//|/$~',$s)||$s==='HEAD') throw new Problem('Некорректное имя ветки.');
    return $s;
}
function ossLine(PDO $db,int $pid,int $id): array {return entity($db,'SELECT * FROM oss_lines WHERE project_id=? AND id=?',[$pid,$id]);}
function ossLineMaintainer(PDO $db,array $u,int $pid): array {
    $u=ossMaintainer($db,$u,$pid);requireProjectRight($db,$u,$pid,'connections');return $u;
}
function ossLineConfirm(PDO $db,array $u): void {
    if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);
    if(isset($u['auth_kind']))requireIdentitySession($db,$u);
}
function ossLineNotify(PDO $db,int $pid,int $cid,string $body): void {
    query($db,"INSERT INTO oss_comments(project_id,contribution_id,author_kind,body,created_at) VALUES(?,?,'maintainer',?,?)",[$pid,$cid,$body,time()]);
}
function ossLineCommand(PDO $db,array $user,int $pid,string $key,string $act,array $f): int {
    $u=ossLineMaintainer($db,$user,$pid);ossLineConfirm($db,$u);
    if(!in_array($act,['oss.line','oss.fix'],true))throw new Problem('Неизвестная операция.',404);
    return action($db,$u,$key,$act,[$pid,$f],function()use($db,$u,$pid,$act,$f){
        ossLineMaintainer($db,$u,$pid);ossLineConfirm($db,$u);ossConfig($db,$pid);
        if($act==='oss.line'){
            $id=optionalId($f['line_id']??'');$v=workloadInt($f['version']??null,0,PHP_INT_MAX);
            if($id===null){$branch=ossBranch($f['branch']??'');$label=word($f['label']??'',100,'Публичное название линии');
                if($v!==0||(int)query($db,'SELECT COUNT(*) FROM oss_lines WHERE project_id=?',[$pid])->fetchColumn()>=OSS_LINE_LIMIT||query($db,'SELECT 1 FROM oss_lines WHERE project_id=? AND branch=?',[$pid,$branch])->fetchColumn())throw new Problem('Линия существует или достигнут лимит.',409);
                insertId($db,'INSERT INTO oss_lines(project_id,label,branch) VALUES(?,?,?)',[$pid,$label,$branch]);
            }else{$line=ossLine($db,$pid,$id);$active=(int)choice($f['active']??'',['0'=>'','1'=>'']);if((int)$line['version']!==$v)throw new Problem('Линия изменилась.',409);
                query($db,'UPDATE oss_lines SET active=?,version=version+1 WHERE project_id=? AND id=?',[$active,$pid,$id]);}
        }else{
            $lid=numberId($f['line_id']??0);$cid=numberId($f['contribution_id']??0);$line=ossLine($db,$pid,$lid);$c=ossContribution($db,$pid,$cid);
            if(!(int)$line['active'])throw new Problem('Линия закрыта.',409);
            $state=choice($f['state']??'',array_diff_key(OSS_LINE_STATES,['released'=>true]));$pr=optionalId($f['pr_number']??'');if($pr!==null&&$pr>999999999999)throw new Problem('Некорректный номер PR.');
            $reason=ossText($f['reason']??'');if(in_array($state,['deferred','rejected'],true)&&$reason==='')throw new Problem('Укажите причину.');
            if($state==='ready'&&($pr===null||$c['state']!=='accepted'))throw new Problem('Нужны принятый вклад и PR исправления.',409);
            $v=workloadInt($f['version']??null,0,PHP_INT_MAX);$old=query($db,'SELECT * FROM oss_line_fixes WHERE project_id=? AND line_id=? AND contribution_id=?',[$pid,$lid,$cid])->fetch();
            if(($old?(int)$old['version']:0)!==$v||($old&&$old['state']==='released'))throw new Problem('Назначение изменилось или уже выпущено.',409);
            query($db,'INSERT INTO oss_line_fixes(project_id,line_id,contribution_id,state,pr_number,reason,updated_at) VALUES(?,?,?,?,?,?,?) ON CONFLICT(line_id,contribution_id) DO UPDATE SET state=excluded.state,pr_number=excluded.pr_number,reason=excluded.reason,updated_at=excluded.updated_at,version=oss_line_fixes.version+1',[$pid,$lid,$cid,$state,$pr,$reason,time()]);
            // Reasons are internal; only explicit public line labels and status reach the contributor.
            if(!$old||$old['state']!==$state)ossLineNotify($db,$pid,$cid,'Линия '.$line['label'].': '.OSS_LINE_STATES[$state].'.');
            auditEvent($db,$u,$pid,'oss.line.triage',$cid,'line='.$lid.'; state='.$state);
        }return $pid;
    });
}
function ossLineAncestor(callable $read,string $ancestor,string $descendant): void {
    if($ancestor===$descendant)return;
    $c=$read('/compare/'.$ancestor.'...'.$descendant.'?per_page=1');
    if(($c['status']??null)!=='ahead'||($c['base_commit']['sha']??null)!==$ancestor||($c['merge_base_commit']['sha']??null)!==$ancestor)throw new Problem('Коммит не входит в историю целевой ветки или тега.',409);
}
/** Only public repository data, with exact branch, tag and merged PR evidence. */
function ossLineRemote(callable $read,array $repo,array $line,string $tag,string $sha,array $fixes): array {
    $rid=(int)$repo['repository_id'];$remote=$read('');
    if(ghId($remote['id']??null)!==$rid||($remote['private']??null)!==false||($remote['visibility']??null)!=='public'||strtolower(ghName($remote['full_name']??null))!==strtolower($repo['full_name']))throw new Problem('Нужен подтверждённый публичный upstream.',409);
    $ref=releaseResolveTag($read,$tag);if($ref['commit_sha']!==$sha)throw new Problem('Тег указывает на другой SHA.',409);
    $branch=ossBranch($line['branch']);$path='/git/ref/heads/'.rawurlencode($branch);$b=$read($path);
    if(($b['ref']??null)!=='refs/heads/'.$branch||($b['object']['type']??null)!=='commit')throw new Problem('Ветка не подтверждена.',409);
    $head=releaseSha($b['object']['sha']??null);ossLineAncestor($read,$sha,$head);
    $release=$read('/releases/tags/'.rawurlencode($tag));$releaseId=ghId($release['id']??null);
    if(($release['tag_name']??null)!==$tag||($release['draft']??null)!==false||!is_string($release['published_at']??null)||strtotime($release['published_at'])===false)throw new Problem('GitHub Release не опубликован.',409);
    $merges=[];$prs=[];
    foreach($fixes as $f){$n=(int)$f['pr_number'];if(!isset($prs[$n])){$p=$read('/pulls/'.$n);
        if(ghId($p['number']??null)!==$n||ghId($p['base']['repo']['id']??null)!==$rid||($p['base']['ref']??null)!==$branch||($p['merged']??null)!==true||($p['state']??null)!=='closed')throw new Problem('PR не слит в выбранную ветку.',409);
        $merge=releaseSha($p['merge_commit_sha']??null);ossLineAncestor($read,$merge,$sha);$prs[$n]=$merge;}
        $merges[(int)$f['contribution_id']]=$prs[$n];}
    if(releaseResolveTag($read,$tag)!==$ref||$read($path)!==$b)throw new Problem('Тег или ветка изменились во время проверки.',409);
    $lastRepo=$read('');$lastRelease=$read('/releases/tags/'.rawurlencode($tag));
    if(ghId($lastRepo['id']??null)!==$rid||($lastRepo['private']??null)!==false||($lastRepo['visibility']??null)!=='public'||strtolower(ghName($lastRepo['full_name']??null))!==strtolower($repo['full_name'])||ghId($lastRelease['id']??null)!==$releaseId||($lastRelease['tag_name']??null)!==$tag||($lastRelease['draft']??null)!==false||!is_string($lastRelease['published_at']??null)||strtotime($lastRelease['published_at'])===false)throw new Problem('Публичность upstream или релиз изменились во время проверки.',409);
    return ['github_release_id'=>$releaseId,'merges'=>$merges];
}
function ossLineSelection(mixed $raw): array {
    if(!is_array($raw)||!array_is_list($raw)||!$raw||count($raw)>OSS_FIX_LIMIT)throw new Problem('Выберите 1–'.OSS_FIX_LIMIT.' исправлений.');
    $ids=[];foreach($raw as $v){if(!is_string($v)||!preg_match('/\A([1-9][0-9]{0,11}):([1-9][0-9]{0,11})\z/',$v,$m)||isset($ids[(int)$m[1]]))throw new Problem('Некорректный выбор исправлений.');$ids[(int)$m[1]]=(int)$m[2];}ksort($ids);return $ids;
}
function ossLineSnapshot(PDO $db,int $pid,int $lid,int $version,array $ids,string $tag): array {
    $cfg=ossConfig($db,$pid);$line=ossLine($db,$pid,$lid);
    if(!(int)$cfg['published']||!(int)$line['active']||(int)$line['version']!==$version)throw new Problem('Публичный профиль или линия изменились.',409);
    if(query($db,'SELECT 1 FROM oss_releases WHERE project_id=? AND tag=?',[$pid,$tag])->fetchColumn()||query($db,'SELECT 1 FROM oss_line_releases WHERE project_id=? AND tag=?',[$pid,$tag])->fetchColumn())throw new Problem('Тег уже опубликован.',409);
    $repo=entity($db,'SELECT * FROM github_repositories WHERE project_id=? AND active=1',[$pid]);
    if(strtolower($repo['full_name'])!==$cfg['upstream'])throw new Problem('GitHub App не связан с настроенным upstream.',409);
    $fixes=[];foreach($ids as $id=>$v){$f=entity($db,"SELECT f.*,c.public_note AS contribution_note,c.upstream_url,c.version AS contribution_version FROM oss_line_fixes f JOIN oss_contributions c ON c.project_id=f.project_id AND c.id=f.contribution_id JOIN tasks t ON t.project_id=c.project_id AND t.id=c.task_id WHERE f.project_id=? AND f.line_id=? AND f.contribution_id=? AND f.state='ready' AND c.state='accepted' AND t.state='done'",[$pid,$lid,$id]);if((int)$f['version']!==$v)throw new Problem('Исправление изменилось.',409);$fixes[]=$f;}
    return ['config'=>$cfg,'line'=>$line,'repo'=>$repo,'fixes'=>$fixes];
}
function ossLinePublish(PDO $db,array $user,int $pid,string $key,array $f,?GitHubApp $app=null): int {
    $u=ossLineMaintainer($db,$user,$pid);ossLineConfirm($db,$u);
    $lid=numberId($f['line_id']??0);$version=numberId($f['version']??0);$tag=ossTag($f['tag']??'');$sha=releaseSha($f['commit_sha']??null);$title=word($f['title']??'',180,'Название выпуска');$notes=ossText($f['notes']??'');if($notes==='')throw new Problem('Заполните публичный changelog.');$ids=ossLineSelection($f['fixes']??null);
    $data=[$pid,$lid,$version,$tag,$sha,$title,$notes,$ids];
    if(query($db,'SELECT 1 FROM action_log WHERE actor_id=? AND request_key=?',[$u['id'],$key])->fetchColumn())return action($db,$u,$key,'oss.line.publish',$data,static fn()=>throw new Problem('Повтор запроса.',409));
    if(!preg_match('/\A[a-f0-9]{32}\z/',$key))throw new Problem('Обновите форму.');
    $s=ossLineSnapshot($db,$pid,$lid,$version,$ids,$tag);$repo=$s['repo'];$app??=GitHubApp::configured();
    authThrottle($db,'oss.line.publish:'.$u['id'],12);$deadline=microtime(true)+90;
    try{$access=$app->access((int)$repo['installation_id'],(int)$repo['repository_id'],true);if(strtolower($access['name'])!==$s['config']['upstream'])throw new Problem('Upstream изменился.',409);
        $e=ossLineRemote(function($path)use($app,$access,$deadline){if(microtime(true)>$deadline)throw new Problem('Истекло время проверки GitHub.',503);return $app->releaseRead($access,$path);},$repo,$s['line'],$tag,$sha,$s['fixes']);
        if(microtime(true)>$deadline)throw new Problem('Истекло время проверки GitHub.',503);
    }catch(GitHubFailure $err){throw new Problem('GitHub не подтвердил публикацию (HTTP '.$err->http.'). Повторите проверку позже.',503);}
    return action($db,$u,$key,'oss.line.publish',$data,function()use($db,$u,$pid,$lid,$version,$ids,$tag,$sha,$title,$notes,$s,$e){
        ossLineMaintainer($db,$u,$pid);ossLineConfirm($db,$u);if(ossLineSnapshot($db,$pid,$lid,$version,$ids,$tag)!==$s)throw new Problem('Данные или полномочия изменились во время проверки.',409);
        $rid=insertId($db,'INSERT INTO oss_line_releases(project_id,line_id,tag,commit_sha,github_release_id,title,notes,published_at,published_by) VALUES(?,?,?,?,?,?,?,?,?)',[$pid,$lid,$tag,$sha,$e['github_release_id'],$title,$notes,time(),$u['id']]);
        foreach($s['fixes'] as $fix){$id=(int)$fix['contribution_id'];query($db,"UPDATE oss_line_fixes SET state='released',release_id=?,merge_sha=?,public_note=?,version=version+1,updated_at=? WHERE project_id=? AND line_id=? AND contribution_id=?",[$rid,$e['merges'][$id],$fix['contribution_note'],time(),$pid,$lid,$id]);
            ossLineNotify($db,$pid,$id,'Ваш вклад включён в выпуск '.$tag.' ('.$s['line']['label'].'). https://github.com/'.$s['config']['upstream'].'/releases/tag/'.$tag);}
        auditEvent($db,$u,$pid,'oss.line.release',$rid,$tag);return $pid;
    });
}
function ossLinePage(PDO $db,array $u,int $pid,?int $lid,?int $cid,int $page,string $state): array {
    ossLineMaintainer($db,$u,$pid);$cfg=ossConfig($db,$pid);$offset=ossPageOffset($page);$lines=query($db,'SELECT * FROM oss_lines WHERE project_id=? ORDER BY id',[$pid])->fetchAll();
    $line=$lid===null?null:ossLine($db,$pid,$lid);$selected=$cid===null?null:ossContribution($db,$pid,$cid);$queue=[];$ready=[];$releases=[];$fix=null;$more=false;
    if($line){$params=[$line['id'],$pid];$where='c.project_id=?';if($state!==''){choice($state,OSS_LINE_STATES+['unassigned'=>'']);$where.=$state==='unassigned'?' AND f.state IS NULL':' AND f.state=?';if($state!=='unassigned')$params[]=$state;}
        $queue=query($db,"SELECT c.id,c.title,c.state contribution_state,f.state,f.pr_number,f.version,f.reason FROM oss_contributions c LEFT JOIN oss_line_fixes f ON f.project_id=c.project_id AND f.contribution_id=c.id AND f.line_id=? WHERE $where ORDER BY c.id DESC LIMIT 51 OFFSET ".$offset,$params)->fetchAll();$more=count($queue)>50;$queue=array_slice($queue,0,50);
        if($selected)$fix=query($db,'SELECT * FROM oss_line_fixes WHERE project_id=? AND line_id=? AND contribution_id=?',[$pid,$lid,$cid])->fetch()?:null;
        $ready=query($db,"SELECT f.contribution_id,f.version,f.pr_number,c.public_note FROM oss_line_fixes f JOIN oss_contributions c ON c.project_id=f.project_id AND c.id=f.contribution_id JOIN tasks t ON t.project_id=c.project_id AND t.id=c.task_id WHERE f.project_id=? AND f.line_id=? AND f.state='ready' AND c.state='accepted' AND t.state='done' ORDER BY f.contribution_id LIMIT 50",[$pid,$lid])->fetchAll();
        $releases=query($db,'SELECT tag,title,commit_sha,published_at FROM oss_line_releases WHERE project_id=? AND line_id=? ORDER BY id DESC LIMIT 50',[$pid,$lid])->fetchAll();}
    return compact('pid','cfg','lines','line','selected','fix','queue','ready','releases','page','state','more');
}
function ossLinePublic(PDO $db,int $pid,int $offset): array {
    $rows=query($db,'SELECT r.id,r.tag,r.title,r.notes,r.commit_sha,r.published_at,l.label AS line,l.branch FROM oss_line_releases r JOIN oss_lines l ON l.project_id=r.project_id AND l.id=r.line_id WHERE r.project_id=? ORDER BY r.id DESC LIMIT 51 OFFSET '.$offset,[$pid])->fetchAll();
    foreach($rows as &$r){$r['items']=query($db,'SELECT f.public_note,c.upstream_url,f.pr_number,f.merge_sha FROM oss_line_fixes f JOIN oss_contributions c ON c.project_id=f.project_id AND c.id=f.contribution_id WHERE f.project_id=? AND f.release_id=? ORDER BY f.contribution_id',[$pid,$r['id']])->fetchAll();unset($r['id']);}unset($r);return $rows;
}
