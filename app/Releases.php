<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const RELEASE_TASK_LIMIT=20;
const RELEASE_VERIFY_TTL=900;
function releaseSha(mixed $value,int $length=40): string {
    if(!is_string($value)||!preg_match('/\A[a-f0-9]{'.$length.'}\z/',$value)) throw new Problem('Требуется точный SHA в нижнем регистре.',422);
    return $value;
}
function releaseTag(mixed $value): string {
    if(!is_string($value)||!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/',$value)||str_contains($value,'..')||str_ends_with($value,'.')||str_ends_with($value,'.lock')) throw new Problem('Некорректный тег (до 100 символов, без /).',422);
    return $value;
}
function releaseNotes(mixed $value): string {
    if(!is_string($value)||!preg_match('//u',$value)||strlen($value)>32000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)) throw new Problem('Некорректный changelog.',422);
    $value=trim(str_replace(["\r\n","\r"],"\n",$value));
    if($value==='') throw new Problem('Changelog обязателен.',422);
    return $value;
}
function releaseChecks(mixed $value): array {
    if(!is_string($value)||strlen($value)>8000) throw new Problem('Укажите обязательные CI checks.',422);
    $names=[];
    foreach(preg_split('/\r\n|\r|\n/',$value) as $line){$line=word($line,200,'CI check',false);if($line!=='')$names[]=$line;}
    $names=array_values(array_unique($names));sort($names,SORT_STRING);
    if(!$names||count($names)>30) throw new Problem('Укажите 1–30 точных имён обязательных checks.',422);
    return $names;
}
function releaseManager(PDO $db,array $user,int $project): array {
    $u=organizationUser($db,$user);requireProjectRight($db,$u,$project,'manage');requireProjectRight($db,$u,$project,'connections');return $u;
}
function releaseRow(PDO $db,int $project,int $id): array {return entity($db,'SELECT * FROM project_releases WHERE id=? AND project_id=?',[$id,$project]);}
function releaseResponsible(PDO $db,array $u,int $project,int $uid): void {
    releaseManager($db,['id'=>$uid,'organization_id'=>$u['organization_id']],$project);
}
function releaseTaskRows(PDO $db,array $r): array {
    $rows=query($db,'SELECT rt.*,t.state,t.version current_task_version,l.version current_link_version,l.kind,l.number,l.repository_binding FROM project_release_tasks rt JOIN tasks t ON t.id=rt.task_id AND t.project_id=rt.project_id LEFT JOIN github_task_links l ON l.task_id=rt.task_id AND l.project_id=rt.project_id WHERE rt.release_id=? ORDER BY rt.task_id',[$r['id']])->fetchAll();
    if(!$rows||count($rows)>RELEASE_TASK_LIMIT)throw new Problem('Нет допустимых задач выпуска.',409);
    foreach($rows as $t) if($t['state']!=='done'||(int)$t['task_version']!==(int)$t['current_task_version']||$t['kind']!=='pull'||(int)$t['link_version']!==(int)$t['current_link_version']||(int)$t['number']!==(int)$t['pr_number']||(int)$t['repository_binding']!==(int)$r['repository_binding']) throw new Problem('Задача или связь PR изменена. Отмените карточку и создайте новую.',409);
    return $rows;
}
function releaseRepo(PDO $db,array $r): array {
    return entity($db,'SELECT * FROM github_repositories WHERE id=? AND project_id=? AND generation=? AND active=1',[$r['repository_binding'],$r['project_id'],$r['repository_generation']]);
}
function releaseCreate(PDO $db,array $user,int $project,string $key,array $form): int {
    if(!preg_match('/\A[a-f0-9]{32}\z/',$key))throw new Problem('Некорректный ключ запроса.');
    $tag=releaseTag($form['tag']??null);$sha=releaseSha($form['commit_sha']??null);$notes=releaseNotes($form['changelog']??null);
    $asset=word($form['artifact_name']??'',200,'Артефакт');$digest=releaseSha($form['artifact_sha256']??null,64);
    $checks=json_encode(releaseChecks($form['required_checks']??null),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $responsible=numberId($form['responsible_id']??0);$ids=$form['tasks']??null;
    if(!is_array($ids)||!array_is_list($ids)||!$ids||count($ids)>RELEASE_TASK_LIMIT)throw new Problem('Выберите 1–20 задач.');
    $ids=array_map(__NAMESPACE__.'\\numberId',$ids);$ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);
    return atomic($db,function()use($db,$user,$project,$key,$tag,$sha,$notes,$asset,$digest,$checks,$responsible,$ids){
        $u=releaseManager($db,$user,$project);
        $prior=query($db,'SELECT * FROM project_releases WHERE project_id=? AND request_key=?',[$project,$key])->fetch();
        if($prior){$oldIds=array_map('intval',query($db,'SELECT task_id FROM project_release_tasks WHERE release_id=? ORDER BY task_id',[$prior['id']])->fetchAll(PDO::FETCH_COLUMN));
            if((int)$prior['creator_id']!==(int)$u['id']||$prior['tag']!==$tag||$prior['commit_sha']!==$sha||$prior['changelog']!==$notes||$prior['artifact_name']!==$asset||$prior['artifact_sha256']!==$digest||$prior['required_checks']!==$checks||(int)$prior['responsible_id']!==$responsible||$oldIds!==$ids)throw new Problem('Ключ формы уже использован.',409);return (int)$prior['id'];}
        releaseResponsible($db,$u,$project,$responsible);
        $repo=entity($db,'SELECT * FROM github_repositories WHERE project_id=? AND active=1',[$project]);
        if(query($db,"SELECT 1 FROM project_releases WHERE project_id=? AND tag=? AND state!='cancelled'",[$project,$tag])->fetchColumn())throw new Problem('Тег уже зарегистрирован.',409);
        $rows=[];foreach($ids as $id){$t=entity($db,"SELECT t.id,t.version task_version,l.version link_version,l.number FROM tasks t JOIN github_task_links l ON l.task_id=t.id AND l.project_id=t.project_id WHERE t.project_id=? AND t.id=? AND t.state='done' AND l.kind='pull' AND l.repository_binding=?",[$project,$id,$repo['id']]);$rows[]=$t;}
        $id=insertId($db,'INSERT INTO project_releases(project_id,repository_binding,repository_generation,tag,commit_sha,changelog,artifact_name,artifact_sha256,required_checks,responsible_id,creator_id,request_key,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[$project,$repo['id'],$repo['generation'],$tag,$sha,$notes,$asset,$digest,$checks,$responsible,$u['id'],$key,time()]);
        foreach($rows as $t)query($db,'INSERT INTO project_release_tasks(release_id,project_id,task_id,task_version,link_version,pr_number) VALUES(?,?,?,?,?,?)',[$id,$project,$t['id'],$t['task_version'],$t['link_version'],$t['number']]);
        auditEvent($db,$u,$project,'release.create',$id);return $id;
    });
}
/** Resolve lightweight or nested annotated tags, never trust target_commitish or event payload. */
function releaseResolveTag(callable $read,string $tag): array {
    $row=$read('/git/ref/tags/'.rawurlencode($tag));
    if(($row['ref']??null)!=='refs/tags/'.$tag)throw new Problem('GitHub вернул другой тег.',409);
    $object=$row['object']??[];$root=releaseSha($object['sha']??null);$seen=[];
    for($i=0;$i<6;$i++){
        $sha=releaseSha($object['sha']??null);$type=$object['type']??null;
        if($type==='commit')return ['ref_sha'=>$root,'commit_sha'=>$sha];
        if($type!=='tag'||isset($seen[$sha]))break;
        $seen[$sha]=true;$annotated=$read('/git/tags/'.$sha);
        if(($annotated['sha']??null)!==$sha)throw new Problem('Некорректный annotated tag.',409);
        $object=$annotated['object']??[];
    }
    throw new Problem('Тег не разрешается в commit.',409);
}
function releaseCI(callable $read,string $sha,array $required): array {
    $checks=[];$names=[];$total=null;
    for($p=1;$p<=10;$p++){
        $batch=$read('/commits/'.$sha.'/check-runs?filter=latest&per_page=100&page='.$p);
        $n=$batch['total_count']??null;$rows=$batch['check_runs']??null;
        if(!is_int($n)||$n<1||$n>1000||!is_array($rows)||!array_is_list($rows)||count($rows)>100||($total!==null&&$n!==$total))throw new Problem('Нет полной выборки CI.',409);
        $total=$n;
        foreach($rows as $c){$id=ghId($c['id']??null);$name=word($c['name']??'',200,'CI check');
            if(isset($checks[$id])||($c['head_sha']??null)!==$sha||($c['status']??null)!=='completed'||($c['conclusion']??null)!=='success')throw new Problem('CI не завершён успешно для SHA выпуска.',409);
            $checks[$id]=['id'=>$id,'name'=>$name,'app_id'=>ghId($c['app']['id']??null),'status'=>'completed','conclusion'=>'success'];$names[$name]=true;
        }
        if(count($checks)===$total)break;if(count($rows)<100)throw new Problem('Неполная выборка CI.',409);
    }
    if(count($checks)!==$total||array_diff($required,array_keys($names)))throw new Problem('Обязательные CI checks отсутствуют.',409);
    ksort($checks,SORT_NUMERIC);
    $status=$read('/commits/'.$sha.'/status?per_page=1');$count=$status['total_count']??null;
    if(($status['sha']??null)!==$sha||!is_int($count)||$count<0||($count>0&&($status['state']??null)!=='success'))throw new Problem('Commit Status не подтверждён.',409);
    return ['checks'=>array_values($checks),'commit_status'=>['count'=>$count,'state'=>$count===0?'none':'success']];
}
function releaseRemote(callable $read,array $r,array $tasks,int $repository): array {
    $sha=releaseSha($r['commit_sha']);$tag=releaseTag($r['tag']);$ref=releaseResolveTag($read,$tag);
    if($ref['commit_sha']!==$sha)throw new Problem('Тег указывает на другой commit SHA.',409);
    $prs=[];$taskEvidence=[];
    foreach($tasks as $t){$n=(int)$t['pr_number'];
        if(!isset($prs[$n])){$pr=$read('/pulls/'.$n);
            if(ghId($pr['number']??null)!==$n||ghId($pr['base']['repo']['id']??null)!==$repository||($pr['merged']??null)!==true||($pr['state']??null)!=='closed')throw new Problem('PR не принят в репозиторий выпуска.',409);
            $merge=releaseSha($pr['merge_commit_sha']??null);$head=releaseSha($pr['head']['sha']??null);
            if($merge!==$sha){$compare=$read('/compare/'.$merge.'...'.$sha.'?per_page=1');if(!in_array($compare['status']??null,['ahead','identical'],true)||($compare['merge_base_commit']['sha']??null)!==$merge||($compare['base_commit']['sha']??null)!==$merge)throw new Problem('Merge commit PR не входит в SHA выпуска.',409);}
            $prs[$n]=['number'=>$n,'head_sha'=>$head,'merge_sha'=>$merge];
        }
        $taskEvidence[]=['task_id'=>(int)$t['task_id'],'task_version'=>(int)$t['task_version'],'link_version'=>(int)$t['link_version'],'pr'=>$prs[$n]];
    }
    $ci=releaseCI($read,$sha,json_decode($r['required_checks'],true,32,JSON_THROW_ON_ERROR));
    $remote=$read('/releases/tags/'.rawurlencode($tag));$rid=ghId($remote['id']??null);
    if(($remote['tag_name']??null)!==$tag||($remote['draft']??null)!==false||!is_string($remote['published_at']??null)||strtotime($remote['published_at'])===false||releaseNotes($remote['body']??null)!==$r['changelog'])throw new Problem('GitHub Release не опубликован или changelog не совпадает.',409);
    $asset=null;$seen=[];$complete=false;
    for($p=1;$p<=10;$p++){$rows=$read('/releases/'.$rid.'/assets?per_page=100&page='.$p);if(!array_is_list($rows)||count($rows)>100)throw new Problem('Некорректный список артефактов.',409);
        foreach($rows as $a){$id=ghId($a['id']??null);if(isset($seen[$id]))throw new Problem('Нестабильная выборка артефактов.',409);$seen[$id]=true;
            if(($a['name']??null)!==$r['artifact_name'])continue;
            if($asset!==null||($a['state']??null)!=='uploaded'||($a['digest']??null)!=='sha256:'.$r['artifact_sha256']||!is_int($a['size']??null)||$a['size']<=0)throw new Problem('Артефакт не готов или SHA-256 не совпадает.',409);
            $asset=['id'=>$id,'name'=>$r['artifact_name'],'sha256'=>$r['artifact_sha256'],'size'=>$a['size']];}
        if(count($rows)<100){$complete=true;break;}}
    if(!$asset||!$complete)throw new Problem('Артефакт отсутствует или выборка неполная.',409);
    // Detect moved tags and release edits during remote inspection.
    if(releaseResolveTag($read,$tag)!==$ref)throw new Problem('Тег изменился во время проверки.',409);
    $again=$read('/releases/tags/'.rawurlencode($tag));
    if(ghId($again['id']??null)!==$rid||($again['tag_name']??null)!==$tag||($again['draft']??null)!==false||($again['published_at']??null)!==$remote['published_at']||releaseNotes($again['body']??null)!==$r['changelog'])throw new Problem('Release изменился во время проверки.',409);
    $a=$read('/releases/assets/'.$asset['id']);if(ghId($a['id']??null)!==$asset['id']||($a['name']??null)!==$asset['name']||($a['state']??null)!=='uploaded'||($a['digest']??null)!=='sha256:'.$asset['sha256']||($a['size']??null)!==$asset['size'])throw new Problem('Артефакт изменился во время проверки.',409);
    return ['repository_id'=>$repository,'tag'=>$tag,'ref'=>$ref,'tasks'=>$taskEvidence,'ci'=>$ci,'release'=>['id'=>$rid,'published_at'=>$remote['published_at'],'changelog_sha256'=>hash('sha256',$r['changelog'])],'artifact'=>$asset];
}
function releaseFailure(PDO $db,array $user,int $project,int $id,int $version,Problem $error): void {
    atomic($db,function()use($db,$user,$project,$id,$version,$error){
        $u=releaseManager($db,$user,$project);$r=releaseRow($db,$project,$id);
        if((int)$r['version']!==$version||!in_array($r['state'],['draft','verified'],true))return;
        query($db,"UPDATE project_releases SET state='draft',last_error=?,version=version+1 WHERE id=?",[$error->getMessage(),$id]);
        auditEvent($db,$u,$project,'release.failed',$id);
    });
}
function releaseInspect(PDO $db,array $user,int $project,int $id,int $version,bool $confirm,GitHubApp $app): void {
    // No network requests inside the DB write transaction. Recheck role, session, versions and membership at commit.
    [$r,$repo,$tasks]=atomic($db,function()use($db,$user,$project,$id,$version,$confirm){$u=releaseManager($db,$user,$project);$r=releaseRow($db,$project,$id);
        if($r['state']==='confirmed'&&$confirm&&(int)$r['confirmed_by']===(int)$u['id'])return [$r,null,[]];
        if((int)$r['version']!==$version||!in_array($r['state'],['draft','verified'],true))throw new Problem('Карточка выпуска изменена.',409);
        if($confirm&&((int)$r['responsible_id']!==(int)$u['id']||$r['state']!=='verified'||(int)$r['verified_at']<time()-RELEASE_VERIFY_TTL))throw new Problem('Подтвердить может назначенный ответственный после свежей проверки.',403);
        releaseResponsible($db,$u,$project,(int)$r['responsible_id']);return [$r,releaseRepo($db,$r),releaseTaskRows($db,$r)];});
    if($repo===null)return;
    $started=microtime(true);
    try{$access=$app->access((int)$repo['installation_id'],(int)$repo['repository_id'],true);
        $read=static function(string $path)use($app,$access,$started):array{if(microtime(true)-$started>75||time()>=$access['expires']-30)throw new Problem('Превышен бюджет проверки GitHub. Повторите позже.',503);return $app->releaseRead($access,$path);};
        $evidence=releaseRemote($read,$r,$tasks,(int)$repo['repository_id']);
    }catch(GitHubFailure $e){$error=new Problem('Не удалось проверить выпуск: GitHub HTTP '.$e->http.'. Проверьте Contents: read и доступ к репозиторию.',503);releaseFailure($db,$user,$project,$id,$version,$error);throw $error;}catch(Problem $e){releaseFailure($db,$user,$project,$id,$version,$e);throw $e;}
    if(isset($_SESSION['uid'])) requireMfa($confirm?'release.confirm':'release.verify');
    $json=json_encode($evidence,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$hash=hash('sha256',$json);
    $changed=atomic($db,function()use($db,$user,$project,$id,$version,$confirm,$r,$json,$hash){$u=releaseManager($db,$user,$project);$now=releaseRow($db,$project,$id);
        if((int)$now['version']!==$version||!in_array($now['state'],['draft','verified'],true))throw new Problem('Карточка выпуска изменилась при проверке.',409);
        releaseResponsible($db,$u,$project,(int)$now['responsible_id']);releaseRepo($db,$now);releaseTaskRows($db,$now);
        if($confirm){if((int)$now['responsible_id']!==(int)$u['id']||$now['state']!=='verified'||(int)$now['verified_at']<time()-RELEASE_VERIFY_TTL)throw new Problem('Подтверждение больше недоступно.',409);
            if(!hash_equals((string)$now['evidence_hash'],$hash)){query($db,"UPDATE project_releases SET state='verified',last_error='',evidence=?,evidence_hash=?,verified_at=?,version=version+1 WHERE id=?",[$json,$hash,time(),$id]);auditEvent($db,$u,$project,'release.reverify',$id);return true;}
            query($db,"UPDATE project_releases SET state='confirmed',last_error='',confirmed_at=?,confirmed_by=?,version=version+1 WHERE id=?",[time(),$u['id'],$id]);auditEvent($db,$u,$project,'release.confirm',$id);
        }else{query($db,"UPDATE project_releases SET state='verified',last_error='',evidence=?,evidence_hash=?,verified_at=?,version=version+1 WHERE id=?",[$json,$hash,time(),$id]);auditEvent($db,$u,$project,'release.verify',$id);}return false;});
    if($changed)throw new Problem('Доказательства изменились. Новый снимок сохранён; ознакомьтесь с ним и подтвердите повторно.',409);
}
function releaseCancel(PDO $db,array $user,int $project,int $id,int $version): void {
    atomic($db,function()use($db,$user,$project,$id,$version){$u=releaseManager($db,$user,$project);$r=releaseRow($db,$project,$id);if($r['state']==='cancelled')return;if($r['state']==='confirmed'||(int)$r['version']!==$version)throw new Problem('Карточка изменена или выпуск уже подтверждён.',409);query($db,"UPDATE project_releases SET state='cancelled',version=version+1 WHERE id=?",[$id]);auditEvent($db,$u,$project,'release.cancel',$id);});
}
function releasePage(PDO $db,array $user,int $project,?int $id): array {
    $u=organizationUser($db,$user);requireProjectRight($db,$u,$project,'connections');$p=allowedProject($db,$u,$project);$manage=roleAllows(projectRole($db,$u,$project),'manage');
    $repo=query($db,'SELECT * FROM github_repositories WHERE project_id=?',[$project])->fetch()?:null;
    $rows=query($db,'SELECT id,tag,commit_sha,state,created_at FROM project_releases WHERE project_id=? ORDER BY id DESC LIMIT 100',[$project])->fetchAll();
    $r=$id===null?null:releaseRow($db,$project,$id);$tasks=[];$eligible=[];$responsibles=[];$evidence=null;
    if($r){$tasks=query($db,'SELECT rt.task_id,rt.pr_number FROM project_release_tasks rt WHERE release_id=? ORDER BY task_id',[$id])->fetchAll();
        // Historical records stay, but remote snapshots are hidden on access loss or rebinding.
        if($repo&&(int)$repo['active']&&(int)$repo['generation']===(int)$r['repository_generation']&&$r['evidence']!==null)$evidence=json_decode($r['evidence'],true,64,JSON_THROW_ON_ERROR);
    }
    if($manage&&$repo&&(int)$repo['active']){
        $eligible=query($db,"SELECT t.id,t.title,l.number FROM tasks t JOIN github_task_links l ON l.task_id=t.id AND l.project_id=t.project_id WHERE t.project_id=? AND t.state='done' AND l.kind='pull' AND l.repository_binding=? ORDER BY t.id LIMIT 100",[$project,$repo['id']])->fetchAll();
        foreach(query($db,"SELECT u.id,u.name FROM users u JOIN organization_members m ON m.user_id=u.id WHERE m.organization_id=? AND u.enabled=1 AND (m.role='owner' OR EXISTS(SELECT 1 FROM project_members pm WHERE pm.project_id=? AND pm.user_id=u.id)) ORDER BY u.name,u.id",[$u['organization_id'],$project])->fetchAll() as $person){try{releaseResponsible($db,$u,$project,(int)$person['id']);$responsibles[]=$person;}catch(Problem){}}
    }
    return ['project'=>$p,'repository'=>$repo,'releases'=>$rows,'release'=>$r,'releaseTasks'=>$tasks,'eligible'=>$eligible,'responsibles'=>$responsibles,'releaseManage'=>$manage,'evidence'=>$evidence,'bookLinks'=>$r?bookReleaseLinks($db,$u,$project,(int)$r['id']):[]];
}
