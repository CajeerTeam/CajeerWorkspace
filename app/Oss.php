<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const OSS_STATES=['new'=>'Новый','reviewing'=>'На рассмотрении','accepted'=>'Принят','rejected'=>'Отклонён'];
function ossSlug(mixed $raw): string {
    $s=word($raw,80,'Публичный адрес');
    if (!preg_match('/\A[a-z0-9][a-z0-9-]{1,78}[a-z0-9]\z/',$s)) throw new Problem('Slug: 3–80 строчных латинских букв, цифр и дефисов.');
    return $s;
}
function ossRepo(mixed $raw): string {
    $s=word($raw,140,'Upstream');
    if (!preg_match('~\A([a-zA-Z0-9][a-zA-Z0-9-]{0,38})/([a-zA-Z0-9_.-]{1,100})\z~',$s,$m) || in_array($m[2],['.','..'],true)) throw new Problem('Укажите GitHub owner/repository.');
    return strtolower($s);
}
function ossUpstream(mixed $raw,string $repo): string {
    $s=word($raw,220,'Upstream issue/PR');
    if (!preg_match('~\Ahttps://github\.com/([^/]+/[^/]+)/(issues|pull)/([1-9][0-9]{0,11})\z~',$s,$m) || ossRepo($m[1])!==$repo) throw new Problem('Нужна HTTPS-ссылка на issue или PR настроенного upstream GitHub.');
    return 'https://github.com/'.$repo.'/'.$m[2].'/'.$m[3];
}
function ossText(mixed $raw): string { return is_string($raw) && trim($raw)==='' ? '' : criteriaText($raw); }
function ossTag(mixed $raw): string {
    $s=word($raw,100,'Тег выпуска');
    if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,99}\z/',$s) || str_contains($s,'..') || str_ends_with($s,'.lock') || str_ends_with($s,'.')) throw new Problem('Некорректный тег.');
    return $s;
}
function ossMaintainer(PDO $db,array $user,int $pid): array {
    $u=organizationUser($db,$user);
    $p=allowedProject($db,$u,$pid);
    if ($p['kind']!=='oss') throw new Problem('Раздел доступен только OSS-проектам.',404);
    requireProjectRight($db,$u,$pid,'manage');
    return $u;
}
function ossConfig(PDO $db,int $pid): array { return entity($db,'SELECT * FROM oss_projects WHERE project_id=?',[$pid]); }
function ossPublic(PDO $db,string $slug): array {
    return entity($db,"SELECT o.project_id,o.slug,o.public_title,o.description,o.upstream FROM oss_projects o JOIN projects p ON p.id=o.project_id WHERE o.slug=? AND o.published=1 AND p.kind='oss'",[ossSlug($slug)]);
}
function ossPublicData(PDO $db,string $slug,int $page=1): array {
    if ($page<1 || $page>1000000) throw new Problem('Некорректная страница.');
    $offset=($page-1)*50;
    $p=ossPublic($db,$slug); $pid=(int)$p['project_id']; unset($p['project_id']);
    $milestones=query($db,'SELECT public_title,description,due_on,state FROM oss_milestones WHERE project_id=? ORDER BY milestone_id DESC LIMIT 51 OFFSET '.$offset,[$pid])->fetchAll();
    $releases=query($db,'SELECT id,tag,title,notes,published_at FROM oss_releases WHERE project_id=? ORDER BY id DESC LIMIT 51 OFFSET '.$offset,[$pid])->fetchAll();
    $lineReleases=ossLinePublic($db,$pid,$offset);
    $more=count($milestones)>50 || count($releases)>50 || count($lineReleases)>50; $milestones=array_slice($milestones,0,50); $releases=array_slice($releases,0,50);
    foreach ($releases as &$r) {
        $r['items']=query($db,'SELECT public_note,upstream_url FROM oss_release_items WHERE project_id=? AND release_id=? ORDER BY contribution_id',[$pid,$r['id']])->fetchAll(); unset($r['id']);
    } unset($r);
    return ['project'=>$p,'milestones'=>$milestones,'releases'=>$releases,'line_releases'=>array_slice($lineReleases,0,50),'page'=>$page,'next_page'=>$more?$page+1:null];
}
function ossContribution(PDO $db,int $pid,int $id): array {
    return entity($db,'SELECT * FROM oss_contributions WHERE project_id=? AND id=?',[$pid,$id]);
}
function ossContributor(PDO $db,int $id,string $grant): array {
    return entity($db,"SELECT c.id,c.project_id,c.display_name,c.access_until,o.slug,o.upstream FROM oss_contributors c JOIN oss_projects o ON o.project_id=c.project_id JOIN projects p ON p.id=o.project_id WHERE c.id=? AND c.token_hash=? AND c.accepted_at IS NOT NULL AND c.revoked_at IS NULL AND c.access_until>? AND o.published=1 AND p.kind='oss'",[$id,$grant,time()]);
}
function ossIssue(PDO $db,array $user,int $pid,mixed $name,int $hours,string $key,?int $reissue=null): string {
    if (!preg_match('/\A[a-f0-9]{32}\z/',$key)) throw new Problem('Обновите форму.');
    if ($hours<1||$hours>168) throw new Problem('Срок приглашения: 1–168 часов.');
    $name=$reissue===null?word($name,120,'Псевдоним contributor'):'';
    return atomic($db,function() use($db,$user,$pid,$name,$hours,$key,$reissue) {
        $u=ossMaintainer($db,$user,$pid); ossConfig($db,$pid);
        if (query($db,'SELECT 1 FROM action_log WHERE actor_id=? AND request_key=?',[$u['id'],$key])->fetchColumn()) throw new Problem('Код уже был создан; выдайте новый только новой формой.',409);
        $token=bin2hex(random_bytes(32)); $hash=hash('sha256',$token); $expires=time()+$hours*3600;
        if ($reissue===null) $id=insertId($db,'INSERT INTO oss_contributors(project_id,display_name,token_hash,expires_at,created_by) VALUES(?,?,?,?,?)',[$pid,$name,$hash,$expires,$u['id']]);
        else { $id=$reissue; entity($db,'SELECT id FROM oss_contributors WHERE project_id=? AND id=?',[$pid,$id]); query($db,'UPDATE oss_contributors SET token_hash=?,expires_at=?,accepted_at=NULL,access_until=NULL,revoked_at=NULL WHERE project_id=? AND id=?',[$hash,$expires,$pid,$id]); }
        query($db,'INSERT INTO action_log(actor_id,request_key,fingerprint,action,object_id) VALUES(?,?,?,?,?)',[$u['id'],$key,hash('sha256',json_encode([$pid,$name,$hours,$reissue],JSON_THROW_ON_ERROR)),'oss.invite',$id]);
        auditEvent($db,$u,$pid,$reissue===null?'oss.invite':'oss.reissue',$id); return $token;
    });
}
function ossRedeem(PDO $db,mixed $raw): array {
    $token=word($raw,64,'Код'); if (!preg_match('/\A[a-f0-9]{64}\z/',$token)) throw new Problem('Недействительное приглашение.',403);
    return atomic($db,function() use($db,$token) {
        $now=time(); $c=entity($db,"SELECT c.id,c.project_id,o.slug FROM oss_contributors c JOIN oss_projects o ON o.project_id=c.project_id JOIN projects p ON p.id=o.project_id WHERE c.token_hash=? AND c.expires_at>? AND c.accepted_at IS NULL AND c.revoked_at IS NULL AND o.published=1 AND p.kind='oss'",[hash('sha256',$token),$now]);
        query($db,'UPDATE oss_contributors SET accepted_at=?,access_until=? WHERE id=?',[$now,$now+30*86400,$c['id']]);
        return $c+['grant'=>hash('sha256',$token)];
    });
}
function ossExternal(PDO $db,int $uid,string $grant,string $key,string $act,array $form): int {
    if (!preg_match('/\A[a-f0-9]{32}\z/',$key)) throw new Problem('Обновите форму.');
    return atomic($db,function() use($db,$uid,$grant,$key,$act,$form) {
        $c=ossContributor($db,$uid,$grant); $pid=(int)$c['project_id'];
        $data=match($act) {
            'oss.submit'=>[word($form['title']??'',180,'Название'),ossText($form['body']??''),ossUpstream($form['upstream_url']??'',$c['upstream'])],
            'oss.reply'=>[numberId($form['id']??0),ossText($form['body']??'')],
            default=>throw new Problem('Действие недоступно.',403)
        };
        if (in_array('',$data,true)) throw new Problem('Заполните описание.');
        $hash=hash('sha256',json_encode([$act,$data],JSON_THROW_ON_ERROR));
        $old=query($db,'SELECT fingerprint,object_id FROM oss_requests WHERE contributor_id=? AND request_key=?',[$uid,$key])->fetch();
        if ($old) { if (!hash_equals($old['fingerprint'],$hash)) throw new Problem('Содержимое повторного запроса изменилось.',409); return (int)$old['object_id']; }
        if ($act==='oss.submit') {
            if (query($db,'SELECT 1 FROM oss_contributions WHERE project_id=? AND upstream_url=?',[$pid,$data[2]])->fetchColumn()) throw new Problem('Этот upstream уже зарегистрирован.',409);
            $id=insertId($db,'INSERT INTO oss_contributions(project_id,contributor_id,title,body,upstream_url,created_at) VALUES(?,?,?,?,?,?)',[$pid,$uid,...$data,time()]);
        } else {
            $id=$data[0]; entity($db,'SELECT id FROM oss_contributions WHERE project_id=? AND id=? AND contributor_id=?',[$pid,$id,$uid]);
            query($db,"INSERT INTO oss_comments(project_id,contribution_id,author_kind,body,created_at) VALUES(?,?,'contributor',?,?)",[$pid,$id,$data[1],time()]);
        }
        query($db,'INSERT INTO oss_requests VALUES(?,?,?,?)',[$uid,$key,$hash,$id]); return $id;
    });
}
function ossCommand(PDO $db,array $user,string $key,int $pid,string $act,array $f): int {
    $u=ossMaintainer($db,$user,$pid);
    // Whitelist all action names before the action log is used.
    if (!in_array($act,['oss.settings','oss.milestone','oss.revoke','oss.link','oss.triage','oss.comment','oss.release'],true)) throw new Problem('Неизвестное действие.',404);
    return action($db,$u,$key,$act,[$pid,$f],function() use($db,$u,$pid,$act,$f) {
        $u=ossMaintainer($db,$u,$pid);
        if ($act==='oss.settings') {
            $slug=ossSlug($f['slug']??''); $repo=ossRepo($f['upstream']??''); $title=word($f['public_title']??'',180,'Публичное название'); $desc=ossText($f['description']??''); if ($desc==='') throw new Problem('Заполните публичное описание.');
            $pub=choice($f['published']??'',['0'=>'','1'=>'']); $version=workloadInt($f['version']??null,0,PHP_INT_MAX);
            $old=query($db,'SELECT * FROM oss_projects WHERE project_id=?',[$pid])->fetch();
            if ($old) { if ((int)$old['version']!==$version || $old['slug']!==$slug || $old['upstream']!==$repo) throw new Problem('Конфигурация изменилась; slug и upstream неизменяемы.',409); query($db,'UPDATE oss_projects SET public_title=?,description=?,published=?,version=version+1 WHERE project_id=?',[$title,$desc,(int)$pub,$pid]); }
            else { if ($version!==0 || query($db,'SELECT 1 FROM oss_projects WHERE slug=?',[$slug])->fetchColumn()) throw new Problem('Slug занят или форма устарела.',409); query($db,'INSERT INTO oss_projects(project_id,slug,upstream,public_title,description,published) VALUES(?,?,?,?,?,?)',[$pid,$slug,$repo,$title,$desc,(int)$pub]); }
            return $pid;
        }
        $cfg=ossConfig($db,$pid);
        switch ($act) {
            case 'oss.milestone':
                $mid=numberId($f['milestone_id']??0); $m=entity($db,'SELECT * FROM milestones WHERE project_id=? AND id=?',[$pid,$mid]);
                $title=word($f['public_title']??'',180,'Публичная веха'); $desc=ossText($f['description']??''); if ($desc==='') throw new Problem('Заполните описание.');
                $v=workloadInt($f['version']??null,0,PHP_INT_MAX); $mv=numberId($f['milestone_version']??0);
                if ((int)$m['version']!==$mv) throw new Problem('Веха изменилась.',409);
                $old=query($db,'SELECT version FROM oss_milestones WHERE project_id=? AND milestone_id=?',[$pid,$mid])->fetchColumn();
                if (($old===false?0:(int)$old)!==$v || query($db,'SELECT 1 FROM oss_releases WHERE project_id=? AND milestone_id=?',[$pid,$mid])->fetchColumn()) throw new Problem('Публичная веха изменилась или уже выпущена.',409);
                query($db,'INSERT INTO oss_milestones(project_id,milestone_id,public_title,description,due_on,state) VALUES(?,?,?,?,?,?) ON CONFLICT(project_id,milestone_id) DO UPDATE SET public_title=excluded.public_title,description=excluded.description,due_on=excluded.due_on,state=excluded.state,version=oss_milestones.version+1',[$pid,$mid,$title,$desc,$m['due_on'],$m['state']]); break;
            case 'oss.revoke':
                $id=numberId($f['contributor_id']??0); entity($db,'SELECT id FROM oss_contributors WHERE project_id=? AND id=?',[$pid,$id]); query($db,'UPDATE oss_contributors SET revoked_at=? WHERE project_id=? AND id=? AND revoked_at IS NULL',[time(),$pid,$id]); break;
            case 'oss.link':
                $tid=numberId($f['task_id']??0); $t=entity($db,'SELECT version FROM tasks WHERE project_id=? AND id=?',[$pid,$tid]);
                if ((int)$t['version']!==numberId($f['version']??0)) throw new Problem('Задача изменилась.',409);
                $url=ossUpstream($f['upstream_url']??'',$cfg['upstream']);
                $old=query($db,'SELECT upstream_url FROM oss_task_links WHERE project_id=? AND task_id=?',[$pid,$tid])->fetchColumn();
                if ($old!==false && $old!==$url && query($db,'SELECT 1 FROM oss_contributions WHERE project_id=? AND task_id=?',[$pid,$tid])->fetchColumn()) throw new Problem('Нельзя изменить upstream связанного вклада.',409);
                query($db,'INSERT INTO oss_task_links(task_id,project_id,upstream_url) VALUES(?,?,?) ON CONFLICT(task_id) DO UPDATE SET upstream_url=excluded.upstream_url',[$tid,$pid,$url]); query($db,'UPDATE tasks SET version=version+1 WHERE id=?',[$tid]); break;
            case 'oss.comment':
                $id=numberId($f['id']??0); ossContribution($db,$pid,$id); $body=ossText($f['body']??''); if ($body==='') throw new Problem('Заполните сообщение.');
                query($db,"INSERT INTO oss_comments(project_id,contribution_id,author_kind,body,created_at) VALUES(?,?,'maintainer',?,?)",[$pid,$id,$body,time()]); break;
            case 'oss.triage':
                $id=numberId($f['id']??0); $c=ossContribution($db,$pid,$id); $state=choice($f['state']??'',OSS_STATES);
                if ((int)$c['version']!==numberId($f['version']??0) || !in_array($c['state'],['new','reviewing'],true) || !in_array($state,['reviewing','accepted','rejected'],true) || ($state==='accepted'&&$c['state']!=='reviewing')) throw new Problem('Недопустимый переход или устаревшая форма.',409);
                $task=optionalId($f['task_id']??''); $note='';
                if ($task!==null) { $t=entity($db,'SELECT t.state,l.upstream_url FROM tasks t JOIN oss_task_links l ON l.task_id=t.id AND l.project_id=t.project_id WHERE t.project_id=? AND t.id=?',[$pid,$task]); if ($t['upstream_url']!==$c['upstream_url']) throw new Problem('Upstream задачи не совпадает с вкладом.',409); }
                if ($state==='accepted') { if ($task===null || $t['state']!=='done') throw new Problem('Для приёмки нужна завершённая связанная задача.',409); $note=ossText($f['public_note']??''); if ($note==='') throw new Problem('Подготовьте публичную release note.'); }
                query($db,'UPDATE oss_contributions SET state=?,task_id=?,public_note=?,version=version+1 WHERE project_id=? AND id=?',[$state,$task,$note,$pid,$id]);
                $body=ossText($f['body']??''); if ($body!=='') query($db,"INSERT INTO oss_comments(project_id,contribution_id,author_kind,body,created_at) VALUES(?,?,'maintainer',?,?)",[$pid,$id,$body,time()]); break;
            case 'oss.release':
                $mid=numberId($f['milestone_id']??0); $m=entity($db,'SELECT m.state,m.version FROM milestones m JOIN oss_milestones om ON om.milestone_id=m.id AND om.project_id=m.project_id WHERE m.project_id=? AND m.id=?',[$pid,$mid]);
                if ($m['state']!=='closed' || (int)$m['version']!==numberId($f['milestone_version']??0)) throw new Problem('Для выпуска закройте актуальную публичную веху.',409);
                $tag=ossTag($f['tag']??''); $title=word($f['title']??'',180,'Название выпуска'); $notes=ossText($f['notes']??''); if ($notes==='') throw new Problem('Заполните release notes.');
                if (query($db,'SELECT 1 FROM oss_releases WHERE project_id=? AND (milestone_id=? OR tag=?)',[$pid,$mid,$tag])->fetchColumn() || query($db,'SELECT 1 FROM oss_line_releases WHERE project_id=? AND tag=?',[$pid,$tag])->fetchColumn()) throw new Problem('Выпуск или тег уже существует.',409);
                $ids=$f['contribution_ids']??[]; if (!is_array($ids)||count($ids)>100) throw new Problem('До 100 вкладов на выпуск.'); $ids=array_map(__NAMESPACE__.'\\numberId',$ids); if (count(array_unique($ids))!==count($ids)) throw new Problem('Повтор вклада.');
                $items=[]; foreach ($ids as $id) { $items[]=entity($db,"SELECT c.id,c.public_note,c.upstream_url FROM oss_contributions c JOIN task_milestones tm ON tm.project_id=c.project_id AND tm.task_id=c.task_id JOIN tasks t ON t.id=c.task_id AND t.project_id=c.project_id WHERE c.project_id=? AND c.id=? AND c.state='accepted' AND t.state='done' AND tm.milestone_id=? AND NOT EXISTS(SELECT 1 FROM oss_release_items ri WHERE ri.contribution_id=c.id) AND NOT EXISTS(SELECT 1 FROM oss_line_fixes f WHERE f.project_id=c.project_id AND f.contribution_id=c.id)",[$pid,$id,$mid]); }
                $rid=insertId($db,'INSERT INTO oss_releases(project_id,milestone_id,tag,title,notes,published_at,published_by) VALUES(?,?,?,?,?,?,?)',[$pid,$mid,$tag,$title,$notes,time(),$u['id']]);
                foreach ($items as $item) query($db,'INSERT INTO oss_release_items(release_id,project_id,contribution_id,public_note,upstream_url) VALUES(?,?,?,?,?)',[$rid,$pid,$item['id'],$item['public_note'],$item['upstream_url']]);
                query($db,"UPDATE oss_milestones SET state='closed',version=version+1 WHERE project_id=? AND milestone_id=?",[$pid,$mid]); break;
        }
        $object=match($act) {'oss.milestone'=>$mid,'oss.revoke'=>$id,'oss.link'=>$tid,'oss.comment','oss.triage'=>$id,'oss.release'=>$rid,default=>$pid};
        auditEvent($db,$u,$pid,'oss.object',$object,$act);
        return $pid;
    });
}

function ossSession(PDO $db): array {
    if (!is_int($_SESSION['oss_contributor']??null)||!is_string($_SESSION['oss_grant']??null)||time()-(int)($_SESSION['oss_seen']??0)>1800) { killLogin(); throw new Problem('Сессия contributor истекла. Запросите новый код.',403); }
    try { $c=ossContributor($db,$_SESSION['oss_contributor'],$_SESSION['oss_grant']); }
    catch (Problem $e) { killLogin(); throw new Problem('Доступ contributor прекращён. Запросите новый код.',403); }
    $_SESSION['oss_seen']=time(); return $c;
}
function ossPageOffset(int $page): int { if ($page<1||$page>1000000) throw new Problem('Некорректная страница.'); return ($page-1)*50; }
function ossThread(PDO $db,int $pid,int $id): array {
    return array_reverse(query($db,'SELECT author_kind,body,created_at FROM oss_comments WHERE project_id=? AND contribution_id=? ORDER BY id DESC LIMIT 200',[$pid,$id])->fetchAll());
}
function ossContributorPage(PDO $db,array $c,?int $id,int $page): array {
    $pid=(int)$c['project_id']; $uid=(int)$c['id']; $offset=ossPageOffset($page);
    $rows=query($db,'SELECT id,title,body,upstream_url,state,created_at FROM oss_contributions WHERE project_id=? AND contributor_id=? ORDER BY id DESC LIMIT 51 OFFSET '.$offset,[$pid,$uid])->fetchAll();
    $selected=$id===null?null:entity($db,'SELECT id,title,body,upstream_url,state,created_at FROM oss_contributions WHERE project_id=? AND contributor_id=? AND id=?',[$pid,$uid,$id]);
    return ['contributor'=>$c,'rows'=>array_slice($rows,0,50),'more'=>count($rows)>50,'page'=>$page,'selected'=>$selected,'comments'=>$selected?ossThread($db,$pid,$id):[], 'line_status'=>$selected?query($db,'SELECT l.label,f.state,r.tag FROM oss_line_fixes f JOIN oss_lines l ON l.project_id=f.project_id AND l.id=f.line_id LEFT JOIN oss_line_releases r ON r.project_id=f.project_id AND r.id=f.release_id WHERE f.project_id=? AND f.contribution_id=? ORDER BY l.id',[$pid,$id])->fetchAll():[], 'line_notifications'=>query($db,"SELECT c.id,c.title,l.label,f.state,r.tag,f.updated_at FROM oss_line_fixes f JOIN oss_contributions c ON c.project_id=f.project_id AND c.id=f.contribution_id JOIN oss_lines l ON l.project_id=f.project_id AND l.id=f.line_id LEFT JOIN oss_line_releases r ON r.project_id=f.project_id AND r.id=f.release_id WHERE f.project_id=? AND c.contributor_id=? ORDER BY f.updated_at DESC,f.line_id DESC,f.contribution_id DESC LIMIT 50",[$pid,$uid])->fetchAll()];
}
function ossAdminPage(PDO $db,array $user,int $pid,?int $id,int $page,string $state): array {
    ossMaintainer($db,$user,$pid); $offset=ossPageOffset($page);
    $cfg=query($db,'SELECT * FROM oss_projects WHERE project_id=?',[$pid])->fetch()?:null;
    $data=['pid'=>$pid,'cfg'=>$cfg,'page'=>$page,'state'=>$state]; if (!$cfg) return $data;
    $params=[$pid]; $where='c.project_id=?'; if ($state!=='') { choice($state,OSS_STATES); $where.=' AND c.state=?'; $params[]=$state; }
    $rows=query($db,"SELECT c.id,c.title,c.upstream_url,c.state,c.version,u.display_name FROM oss_contributions c JOIN oss_contributors u ON u.project_id=c.project_id AND u.id=c.contributor_id WHERE $where ORDER BY c.id DESC LIMIT 51 OFFSET ".$offset,$params)->fetchAll();
    $data['queue']=array_slice($rows,0,50); $data['more']=count($rows)>50;
    $data['selected']=$id===null?null:ossContribution($db,$pid,$id); $data['comments']=$id===null?[]:ossThread($db,$pid,$id);
    $data['contributors']=query($db,'SELECT id,display_name,expires_at,accepted_at,access_until,revoked_at FROM oss_contributors WHERE project_id=? ORDER BY id DESC LIMIT 51 OFFSET '.$offset,[$pid])->fetchAll();
    $data['milestones']=query($db,'SELECT m.id,m.title,m.state,m.version,m.due_on,om.public_title,om.description,om.version public_version,r.tag FROM milestones m LEFT JOIN oss_milestones om ON om.project_id=m.project_id AND om.milestone_id=m.id LEFT JOIN oss_releases r ON r.project_id=m.project_id AND r.milestone_id=m.id WHERE m.project_id=? ORDER BY m.id DESC LIMIT 51 OFFSET '.$offset,[$pid])->fetchAll();
    $data['more']=$data['more']||count($data['contributors'])>50||count($data['milestones'])>50;
    $data['contributors']=array_slice($data['contributors'],0,50); $data['milestones']=array_slice($data['milestones'],0,50);
    // Only approved, still completed and unreleased contributions are candidates; publication rechecks all of this.
    $visibleIds=array_column($data['milestones'],'id');
    $data['eligible']=!$visibleIds?[]:query($db,"SELECT id,public_note,milestone_id FROM (SELECT c.id,c.public_note,tm.milestone_id,ROW_NUMBER() OVER (PARTITION BY tm.milestone_id ORDER BY c.id) AS rn FROM oss_contributions c JOIN task_milestones tm ON tm.project_id=c.project_id AND tm.task_id=c.task_id JOIN tasks t ON t.project_id=c.project_id AND t.id=c.task_id WHERE c.project_id=? AND tm.milestone_id IN (".implode(',',array_fill(0,count($visibleIds),'?')).") AND c.state='accepted' AND t.state='done' AND NOT EXISTS(SELECT 1 FROM oss_release_items ri WHERE ri.contribution_id=c.id) AND NOT EXISTS(SELECT 1 FROM oss_line_fixes f WHERE f.project_id=c.project_id AND f.contribution_id=c.id)) AS candidates WHERE rn<=100 ORDER BY id",[$pid,...$visibleIds])->fetchAll();
    return $data;
}
