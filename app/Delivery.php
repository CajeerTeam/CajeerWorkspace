<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

// Supplemental PRs are release-specific, not replacements of the primary task link.
function deliveryPrs(mixed $raw): array {
    if(!is_string($raw)||strlen($raw)>256)throw new Problem('Некорректный список дополнительных PR.',422);
    if(trim($raw)==='')return [];
    $nums=[];foreach(preg_split('/[\s,]+/',trim($raw)) as $part){
        if(!preg_match('/\A[1-9][0-9]{0,9}\z/',$part))throw new Problem('Укажите номера PR через пробел или запятую.',422);
        $nums[]=(int)$part;
    }
    $nums=array_values(array_unique($nums));sort($nums,SORT_NUMERIC);
    if(count($nums)>5)throw new Problem('Не более пяти дополнительных PR на задачу.',422);
    return $nums;
}
function deliveryInput(mixed $raw,array $ids): array {
    if(!is_array($raw)||count($raw)>100)throw new Problem('Некорректные связи PR.',422);
    $out=[];foreach($ids as $id)$out[$id]=deliveryPrs($raw[$id]??'');
    if(array_sum(array_map('count',$out))+count($ids)>30)throw new Problem('Не более 30 связей задача–PR на выпуск.',422);
    return $out;
}
function deliveryExpand(PDO $db,array $release,array $rows): array {
    $out=[];foreach($rows as $t){
        $scope=query($db,'SELECT * FROM delivery_task_scope WHERE release_id=? AND task_id=? AND project_id=?',[$release['id'],$t['task_id'],$release['project_id']])->fetch();
        if($scope){
            $t['criteria']=$scope['criteria'];$t['title']=$scope['title'];
            if($scope['criteria']!==$t['current_criteria']||$scope['title']!==$t['current_title'])throw new Problem('Критерии или название задачи изменились.',409);
            $extra=json_decode($scope['extra_prs'],true,16,JSON_THROW_ON_ERROR);
            $out[]=$t;
            foreach($extra as $n){$copy=$t;$copy['pr_number']=$n;$out[]=$copy;}
        }else $out[]=$t; // Historical release created before 1.4.0.
    }
    return $out;
}
function deliveryEvidenceMatches(array $r,array $scope,array $e): bool {
    if(($e['ref']['commit_sha']??null)!==$r['commit_sha']||($e['artifact']['sha256']??null)!==$r['artifact_sha256']||!is_array($e['tasks']??null))return false;
    $expected=array_values(array_unique([(int)$scope['pr_number'],...json_decode($scope['extra_prs'],true,16,JSON_THROW_ON_ERROR)]));sort($expected,SORT_NUMERIC);
    $actual=[];foreach($e['tasks'] as $t){if(($t['task_id']??null)!==(int)$scope['task_id'])continue;
        if(($t['task_version']??null)!==(int)$scope['task_version']||($t['link_version']??null)!==(int)$scope['link_version']||($t['criteria']??null)!==$scope['criteria']||($t['title']??null)!==$scope['title']||!is_int($t['pr']['number']??null))return false;
        $actual[]=$t['pr']['number'];
    }
    sort($actual,SORT_NUMERIC);return $actual===$expected;
}
function deliveryObserved(array $r,array $d,array $identity): bool {
    if($d['state']!=='succeeded'||(int)$d['release_id']!==(int)$r['id']||$d['finished_at']===null||$d['release_hash']!==$r['evidence_hash'])return false;
    $result=json_decode((string)$d['result'],true);$target=json_decode($d['target_snapshot'],true);
    return $target===$identity&&($result['state']??null)==='succeeded'&&($result['after']??null)===$r['commit_sha']&&($result['after_digest']??null)===$r['artifact_sha256'];
}
function deliveryPage(PDO $db,array $user,int $project,int $release): array {
    $u=organizationUser($db,$user);requireProjectRight($db,$u,$project,'connections');requireProjectRight($db,$u,$project,'work');
    $p=allowedProject($db,$u,$project);$r=releaseRow($db,$project,$release);
    $scopes=query($db,'SELECT s.*,rt.task_version,rt.pr_number,t.version current_version,t.state current_state,t.acceptance_criteria current_criteria,t.title current_title,rt.link_version,l.version current_link_version,l.number current_pr,l.kind current_link_kind,l.repository_binding current_binding FROM delivery_task_scope s JOIN project_release_tasks rt ON rt.release_id=s.release_id AND rt.task_id=s.task_id JOIN tasks t ON t.id=s.task_id AND t.project_id=s.project_id LEFT JOIN github_task_links l ON l.task_id=s.task_id AND l.project_id=s.project_id WHERE s.release_id=? AND s.project_id=? ORDER BY s.task_id',[$release,$project])->fetchAll();
    $active=false;$e=null;$repo=null;try{$repo=releaseRepo($db,$r);$active=true;}catch(Problem){}
    if($active&&in_array($r['state'],['verified','confirmed'],true)&&is_string($r['evidence'])&&hash_equals((string)$r['evidence_hash'],hash('sha256',$r['evidence'])))$e=json_decode($r['evidence'],true,64,JSON_THROW_ON_ERROR);
    foreach($scopes as &$s){$s['current']=(int)$s['task_version']===(int)$s['current_version']&&$s['criteria']===$s['current_criteria']&&$s['title']===$s['current_title']&&$s['current_state']==='done'&&(int)$s['link_version']===(int)$s['current_link_version']&&(int)$s['pr_number']===(int)$s['current_pr']&&$s['current_link_kind']==='pull'&&(int)$s['current_binding']===(int)$r['repository_binding'];$s['verified']=$e!==null&&deliveryEvidenceMatches($r,$s,$e);}unset($s);
    $sites=query($db,'SELECT s.id,s.site_name,e.name environment_name,e.kind FROM infra_sites s JOIN infra_environments e ON e.id=s.environment_id AND e.project_id=s.project_id WHERE s.project_id=? ORDER BY e.kind,s.id',[$project])->fetchAll();
    foreach($sites as &$site){
        $d=query($db,"SELECT d.*,r.tag observed_tag,r.commit_sha observed_sha FROM deployments d JOIN project_releases r ON r.id=d.release_id AND r.project_id=d.project_id WHERE d.project_id=? AND d.site_id=? AND d.state!='cancelled' ORDER BY d.id DESC LIMIT 1",[$project,$site['id']])->fetch()?:null;
        $site['deployment']=$d;$site['matches']=false;$site['observed']=null;$site['reviews']=[];
        if($d&&$d['state']==='succeeded'){
            try{
                $observed=deploymentRelease($db,$project,(int)$d['release_id']);
                if(deliveryObserved($observed,$d,deploymentIdentity($db,$project,(int)$site['id'],(int)$u['organization_id']))){
                    $site['observed']=['sha'=>$observed['commit_sha'],'digest'=>$observed['artifact_sha256'],'tag'=>$observed['tag']];
                    $site['matches']=$active&&$r['state']==='confirmed'&&$e!==null&&(int)$d['release_id']===$release;
                }
            }catch(Problem){}
        }
        if($d&&(int)$d['release_id']===$release&&$active){foreach(query($db,'SELECT q.* FROM delivery_reviews q WHERE q.project_id=? AND q.release_id=? AND q.deployment_id=? AND q.id=(SELECT MAX(x.id) FROM delivery_reviews x WHERE x.release_id=q.release_id AND x.task_id=q.task_id AND x.deployment_id=q.deployment_id) ORDER BY q.id DESC',[$project,$release,$d['id']])->fetchAll() as $review){$site['reviews'][(int)$review['task_id']]??=$review;}}
    }unset($site);
    return ['project'=>$p,'deliveryRelease'=>$r,'deliveryScopes'=>$scopes,'deliverySites'=>$sites,'deliveryEvidence'=>$e,'deliveryActive'=>$active,'deliveryRepository'=>$repo,'deliveryHistory'=>$active?query($db,'SELECT q.*,d.site_id FROM delivery_reviews q JOIN deployments d ON d.id=q.deployment_id WHERE q.project_id=? AND q.release_id=? ORDER BY q.id DESC LIMIT 100',[$project,$release])->fetchAll():[]];
}
function deliveryFingerprint(array $r,array $s,array $d,?array $previous): string {
    return hash('sha256',json_encode([(int)$r['id'],$r['evidence_hash'],(int)$s['task_id'],(int)$s['task_version'],(int)$d['id'],$d['result'],$d['target_snapshot'],(int)($previous['id']??0)],JSON_THROW_ON_ERROR));
}
function deliveryReview(PDO $db,array $user,int $project,int $release,string $key,array $form): int {
    if(!preg_match('/\A[a-f0-9]{32}\z/',$key))throw new Problem('Некорректный ключ формы.',422);
    $task=numberId($form['task_id']??null);$dep=numberId($form['deployment_id']??null);$outcome=choice($form['outcome']??'', ['passed'=>'passed','failed'=>'failed']);$notes=releaseNotes($form['notes']??null);if(preg_match_all('/./us',$notes)>2000)throw new Problem('Результат проверки: не более 2000 символов.',422);$pin=releaseSha($form['fingerprint']??null,64);
    $request=hash('sha256',json_encode([$project,$release,$task,$dep,$outcome,$notes,$pin],JSON_THROW_ON_ERROR));
    return atomic($db,function()use($db,$user,$project,$release,$key,$task,$dep,$outcome,$notes,$pin,$request){
        $u=organizationUser($db,$user);if(isset($_SESSION['uid']))requireIdentitySession($db,$u);
        $page=deliveryPage($db,$u,$project,$release);
        $old=query($db,'SELECT * FROM delivery_reviews WHERE actor_id=? AND request_key=?',[$u['id'],$key])->fetch();
        if($old){if(!hash_equals($old['fingerprint'],$request))throw new Problem('Ключ формы уже использован.',409);return (int)$old['id'];}
        foreach($page['deliveryScopes'] as $s)if((int)$s['task_id']===$task&&$s['current']&&$s['verified']&&trim($s['criteria'])!==''){
            foreach($page['deliverySites'] as $site)if($site['matches']&&(int)$site['deployment']['id']===$dep){
                $r=$page['deliveryRelease'];$previous=$site['reviews'][$task]??null;
                if(!hash_equals(deliveryFingerprint($r,$s,$site['deployment'],$previous),$pin))throw new Problem('Карточка изменилась. Повторите проверку.',409);
                $id=insertId($db,'INSERT INTO delivery_reviews(project_id,release_id,task_id,deployment_id,actor_id,request_key,fingerprint,outcome,notes,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$project,$release,$task,$dep,$u['id'],$key,$request,$outcome,$notes,time()]);
                auditEvent($db,$u,$project,'release.qa.'.$outcome,$id);return $id;
            }
        }
        throw new Problem('Нет актуального подтверждения задачи, SHA, артефакта и окружения.',409);
    });
}
