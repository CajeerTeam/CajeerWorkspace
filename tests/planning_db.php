<?php
declare(strict_types=1);
namespace Workspace;
use PDO;
require dirname(__DIR__).'/app/bootstrap.php';
require ROOT.'/app/Web.php';

function planningCheck(bool $ok,string $name): void { if (!$ok) throw new \RuntimeException($name); ++$GLOBALS['checks']; }
function planningReject(callable $call,int $status,string $name): void {
    try { $call(); } catch (Problem $e) { planningCheck($e->status===$status,$name.' ('.$e->status.')'); return; }
    throw new \RuntimeException('Unexpected success: '.$name);
}
function planningKey(): string { return bin2hex(random_bytes(16)); }
function planningCleanup(string $dir): void {
    foreach (new \FilesystemIterator($dir) as $entry) { if ($entry->isDir()&&!$entry->isLink()) planningCleanup($entry->getPathname()); else unlink($entry->getPathname()); }
    rmdir($dir);
}
$dir=sys_get_temp_dir().'/cws-planning-'.bin2hex(random_bytes(8)); mkdir($dir,0700);
putenv('WORKSPACE_DATA='.$dir); putenv('WORKSPACE_DB=sqlite'); putenv('WORKSPACE_CONFIG');
$checks=0;
try {
    $db=database(true);
    foreach (['owner','developer','viewer','manager'] as $role) addUser($db,$role.'@example.test',$role,$role,'sample-password-only',$role==='owner');
    $owner=organizationUser($db,['id'=>1]); $dev=organizationUser($db,['id'=>2]); $viewer=organizationUser($db,['id'=>3]); $manager=organizationUser($db,['id'=>4]);
    $project=createProject($db,$owner,planningKey(),['title'=>'Проект','kind'=>'internal']);
    foreach ([2,3,4] as $uid) changeMembership($db,$owner,'project',$project,$uid,true);
    $other=createProject($db,$owner,planningKey(),['title'=>'Закрытый проект','kind'=>'internal']);
    $org=createOrganization($db,$owner,'Вторая организация'); $second=organizationUser($db,['id'=>1],$org);
    $foreign=createProject($db,$second,planningKey(),['title'=>'Другой тенант','kind'=>'internal']);
    $form=['title'=>'Задача','result_customer'=>'Клиент Ёлка','acceptance_criteria'=>"Проверить 100%\nПринять результат",'priority'=>'1'];
    $m=saveMilestone($db,$manager,planningKey(),$project,null,null,['title'=>'Релиз','due_on'=>'2030-01-01']);
    $m2=saveMilestone($db,$owner,planningKey(),$other,null,null,['title'=>'Другая веха']);
    planningReject(fn()=>createTask($db,$owner,planningKey(),$project,['title'=>'Пустая']),422,'required metadata');
    planningReject(fn()=>createTask($db,$owner,planningKey(),$project,['milestone_id'=>$m2]+$form),404,'foreign milestone');
    planningReject(fn()=>createTask($db,$viewer,planningKey(),$project,$form),403,'viewer cannot create');
    $key=planningKey(); $aform=['title'=>'ФИНАЛ','assignee'=>'2','milestone_id'=>$m]+$form;
    $a=createTask($db,$manager,$key,$project,$aform);
    planningCheck(createTask($db,$manager,$key,$project,$aform)===$a,'idempotent create');
    planningReject(fn()=>createTask($db,$manager,$key,$project,['title'=>'Изменённый']+$aform),409,'different replay');
    $b=createTask($db,$owner,planningKey(),$project,['title'=>'Сборка','due_on'=>'2000-01-01','milestone_id'=>$m]+$form);
    $c=createTask($db,$owner,planningKey(),$project,['title'=>'Тесты','milestone_id'=>$m,'priority'=>'4']+$form);
    $private=createTask($db,$owner,planningKey(),$other,['title'=>'PRIVATEONLY']+$form);
    $remote=createTask($db,$second,planningKey(),$foreign,['title'=>'TENANTONLY']+$form);
    planningReject(fn()=>planningTask($db,$dev,$private),404,'closed project denied');
    planningReject(fn()=>planningTask($db,$owner,$remote),404,'cross-tenant read denied');
    planningReject(fn()=>editTask($db,$dev,planningKey(),$a,1,$aform),403,'developer cannot edit planning metadata');
    planningReject(fn()=>changeDependency($db,$owner,planningKey(),$a,1,$private,true),404,'cross-project dependency');
    planningReject(fn()=>changeDependency($db,$owner,planningKey(),$a,1,$remote,true),404,'cross-tenant dependency');
    planningReject(fn()=>changeDependency($db,$owner,planningKey(),$a,1,$a,true),409,'self-dependency');
    changeDependency($db,$manager,planningKey(),$a,1,$b,true);
    changeDependency($db,$manager,planningKey(),$b,1,$c,true);
    planningReject(fn()=>changeDependency($db,$manager,planningKey(),$c,1,$a,true),409,'transitive cycle');
    planningReject(fn()=>changeDependency($db,$owner,planningKey(),$a,1,$b,false),409,'stale dependency edit');
    planningCheck(blockedTask($db,$project,$a),'blocked');
    planningReject(fn()=>changeTask($db,$dev,planningKey(),$a,2,'doing'),409,'blocked start');
    planningReject(fn()=>changeTask($db,$dev,planningKey(),$b,2,'done'),403,'unassigned developer denied');
    planningReject(fn()=>changeMilestone($db,$owner,planningKey(),$project,$m,1,'closed'),409,'unfinished milestone');
    $f=planningFilters(['q'=>'ёлКА','blocked'=>'late','view'=>'backlog','priority'=>'1','assignee'=>'2','milestone'=>(string)$m]);
    $r=planningRows($db,$dev,$project,$f)['backlog']['rows'];
    planningCheck(count($r)===1&&(int)$r[0]['id']===$a&&(int)$r[0]['late_blocker']===1,'combined filters and Cyrillic');
    $f=planningFilters(['q'=>'100%','view'=>'backlog']); planningCheck(count(planningRows($db,$owner,$project,$f)['backlog']['rows'])===3,'literal percent search');
    $f=planningFilters(['q'=>'%','view'=>'backlog']); planningCheck(count(planningRows($db,$owner,$project,$f)['backlog']['rows'])===3,'literal wildcard');
    planningCheck(count(planningRows($db,$owner,$project,planningFilters(['q'=>'_','view'=>'backlog']))['backlog']['rows'])===0,'underscore is literal');
    changeTask($db,$owner,planningKey(),$c,1,'done');
    changeTask($db,$owner,planningKey(),$b,2,'done');
    planningCheck(!blockedTask($db,$project,$a),'unblocked after predecessor done');
    changeTask($db,$dev,planningKey(),$a,2,'doing'); changeTask($db,$dev,planningKey(),$a,3,'done');
    planningReject(fn()=>changeTask($db,$owner,planningKey(),$c,2,'todo'),409,'cannot reopen prerequisite of done task');
    planningReject(fn()=>editTask($db,$owner,planningKey(),$a,4,['acceptance_criteria'=>'Changed']+$aform),409,'done criteria locked');
    changeMilestone($db,$manager,planningKey(),$project,$m,1,'closed');
    planningReject(fn()=>changeTask($db,$owner,planningKey(),$a,4,'todo'),409,'closed milestone locked');
    planningCheck((int)planningMilestones($db,$project)[0]['done']===3,'milestone progress');
    planningCheck(count(planningRows($db,$owner,$project,planningFilters(['view'=>'backlog']))['backlog']['rows'])===0,'default backlog excludes done');
    planningCheck(count(planningRows($db,$owner,$project,planningFilters([]))['done']['rows'])===3,'board done column');
    changeMilestone($db,$owner,planningKey(),$project,$m,2,'open');
    changeTask($db,$owner,planningKey(),$a,4,'todo');
    changeDependency($db,$owner,planningKey(),$a,5,$b,false);
    planningCheck(count(planningDependencies($db,$owner,$a)['requires'])===0,'dependency removed');
    changeDependency($db,$owner,planningKey(),$a,6,$b,true);
    // Simulate pre-007 legacy row without invented acceptance metadata.
    $legacy=insertId($db,'INSERT INTO tasks(project_id,title) VALUES(?,?)',[$project,'Legacy']);
    planningReject(fn()=>changeTask($db,$owner,planningKey(),$legacy,1,'done'),409,'legacy needs acceptance criteria');
    editTask($db,$owner,planningKey(),$legacy,1,['title'=>'Legacy']+$form);
    changeTask($db,$owner,planningKey(),$legacy,2,'done');
    // Audit permission path, revocation and stable pagination.
    planningCheck((int)query($db,"SELECT COUNT(*) FROM audit_events WHERE project_id=? AND event='task.dependency.add'",[$project])->fetchColumn()===3,'dependency audit');
    changeMembership($db,$owner,'project',$project,2,false);
    planningReject(fn()=>planningTask($db,$dev,$a),404,'revoked project access');
    $pdf="%PDF-1.4\nplanning\n%%EOF"; $doc=storeDocument($db,$owner,planningKey(),$project,null,0,['title'=>'PDF','kind'=>'contract'],$pdf);
    if (in_array('--postgres',$argv,true)) {
        $report=migrateToPostgres($dir.'/source.sqlite');
        planningCheck($report['tables']['milestones']['rows']===2 && $report['tables']['task_milestones']['rows']===3 && $report['tables']['task_dependencies']['rows']===2,'planning rows transferred and checksummed');
        $pg=postgresConnect(); $pr=planningTask($pg,$owner,$a);
        planningCheck($pr['result_customer']===$form['result_customer'] && $pr['acceptance_criteria']===$form['acceptance_criteria'],'planning metadata preserved');
        planningCheck((int)$pr['milestone_id']===$m && count(planningDependencies($pg,$owner,$a)['requires'])===1,'planning relationships preserved');
        planningCheck(query($pg,'SELECT sha256 FROM document_versions WHERE document_id=? AND revision=1',[$doc])->fetchColumn()===hash('sha256',$pdf),'PDF preserved');
        planningCheck(count(planningRows($pg,$owner,$project,planningFilters(['q'=>'ЁЛКА','view'=>'board']))['todo']['rows'])===1,'PostgreSQL Cyrillic filter');
        planningReject(fn()=>planningTask($pg,$owner,$remote),404,'PostgreSQL tenant boundary');
        $new=saveMilestone($pg,$owner,planningKey(),$project,null,null,['title'=>'After import']); planningCheck($new>max($m,$m2),'milestone sequence');
        $pg=null;
    }
    echo "OK · $checks проверок планирования\n";
} finally { $db=null; planningCleanup($dir); }
