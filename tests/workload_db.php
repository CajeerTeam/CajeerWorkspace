<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
require ROOT.'/app/Web.php';
function wcheck(bool $ok,string $label): void { if (!$ok) throw new \RuntimeException($label); ++$GLOBALS['checks']; }
function wreject(callable $call,int $status,string $label): void { try { $call(); } catch (Problem $e) { wcheck($e->status===$status,$label.' ('.$e->status.')'); return; } throw new \RuntimeException('Unexpected success: '.$label); }
function wk(): string { return bin2hex(random_bytes(16)); }
function wcleanup(string $dir): void { foreach (new \FilesystemIterator($dir) as $e) { if ($e->isDir()&&!$e->isLink()) wcleanup($e->getPathname()); else unlink($e->getPathname()); } rmdir($dir); }
$dir=sys_get_temp_dir().'/cws-workload-'.bin2hex(random_bytes(8)); mkdir($dir,0700); putenv('WORKSPACE_DATA='.$dir); putenv('WORKSPACE_DB=sqlite'); putenv('WORKSPACE_CONFIG'); $checks=0;
try {
    $db=database(true);
    foreach (['owner','developer','manager','viewer','accountant'] as $role) addUser($db,$role.'@example.test',$role,$role,'sample-password-only',$role==='owner');
    [$owner,$dev,$manager,$viewer,$accountant]=array_map(fn($id)=>organizationUser($db,['id'=>$id]),[1,2,3,4,5]);
    $p=createProject($db,$owner,wk(),['title'=>'Work','kind'=>'internal']);
    foreach ([2,3,4,5] as $uid) changeMembership($db,$owner,'project',$p,$uid,true);
    $other=createProject($db,$owner,wk(),['title'=>'Other','kind'=>'internal']); changeMembership($db,$owner,'project',$other,2,true);
    $org=createOrganization($db,$owner,'Other org'); $second=organizationUser($db,['id'=>1],$org); $foreign=createProject($db,$second,wk(),['title'=>'Foreign','kind'=>'internal']);
    $taskForm=['title'=>'Task','result_customer'=>'Customer','acceptance_criteria'=>'Criteria','assignee'=>'2'];
    $t=createTask($db,$owner,wk(),$p,$taskForm); $t2=createTask($db,$owner,wk(),$other,$taskForm); $t3=createTask($db,$owner,wk(),$p,['assignee'=>'3']+$taskForm);
    $week='2024-12-30'; $week2='2025-01-06'; $sf=['title'=>'Sprint 1','starts_on'=>$week,'ends_on'=>weekEnd($week)];
    $s=saveSprint($db,$manager,wk(),$p,null,0,$sf);
    wreject(fn()=>saveSprint($db,$manager,wk(),$p,null,0,$sf),409,'overlap');
    wreject(fn()=>saveSprint($db,$dev,wk(),$p,null,0,$sf),403,'developer sprint write');
    wreject(fn()=>sprintTask($db,$manager,wk(),$p,$s,1,$t2,60),404,'cross-project task');
    sprintTask($db,$manager,wk(),$p,$s,1,$t,180); wreject(fn()=>sprintTask($db,$manager,wk(),$p,$s,1,$t,60),409,'stale sprint');
    $s2=saveSprint($db,$manager,wk(),$p,null,0,['title'=>'Sprint 2','starts_on'=>$week2,'ends_on'=>weekEnd($week2)]);
    wreject(fn()=>sprintTask($db,$manager,wk(),$p,$s2,1,$t,60),409,'one open assignment');
    sprintTransition($db,$manager,wk(),$p,$s,2,'active'); wreject(fn()=>sprintTransition($db,$manager,wk(),$p,$s2,1,'active'),409,'one active');
    sprintTransition($db,$manager,wk(),$p,$s,3,'closed'); sprintTask($db,$manager,wk(),$p,$s2,1,$t,60);
    wcheck((int)query($db,'SELECT completed_on_close FROM sprint_tasks WHERE sprint_id=?',[$s])->fetchColumn()===0,'closure snapshot');
    wreject(fn()=>sprintTask($db,$manager,wk(),$p,$s,4,$t,60),409,'closed sprint locked');
    saveAvailability($db,$dev,wk(),$p,2,$week,240,0); wreject(fn()=>saveAvailability($db,$dev,wk(),$p,3,$week,60,0),403,'other member availability');
    saveAvailability($db,$manager,wk(),$p,2,$week,360,1); wreject(fn()=>saveAvailability($db,$manager,wk(),$p,2,$week,60,1),409,'stale availability');
    $r=['mode'=>'hourly','currency'=>'EUR','amount'=>'100.01']; saveRate($db,$owner,wk(),$p,0,$r);
    wreject(fn()=>saveRate($db,$manager,wk(),$p,1,$r),403,'rate owner only');
    $form=['task_id'=>$t,'worked_on'=>$week,'minutes'=>'90','note'=>'Actual']; $key=wk();
    $id=saveTime($db,$dev,$key,$p,$week,0,$form); wcheck(saveTime($db,$dev,$key,$p,$week,0,$form)===$id,'same request idempotent');
    wreject(fn()=>saveTime($db,$dev,$key,$p,$week,0,['minutes'=>'91']+$form),409,'changed replay');
    wreject(fn()=>saveTime($db,$dev,wk(),$p,$week,1,$form),409,'stale edit');
    wreject(fn()=>saveTime($db,$dev,wk(),$p,$week,2,['task_id'=>$t3]+$form),403,'unassigned task');
    wreject(fn()=>saveTime($db,$dev,wk(),$p,$week,2,['task_id'=>$t2]+$form),404,'other project task');
    wreject(fn()=>saveTime($db,$dev,wk(),$other,$week,0,['task_id'=>$t2,'minutes'=>'1400']+$form),409,'cross-project daily cap');
    wreject(fn()=>saveTime($db,$dev,wk(),$p,$week,2,['worked_on'=>$week2]+$form),422,'wrong week');
    saveTime($db,$dev,wk(),$p,$week,2,['worked_on'=>'2024-12-31','minutes'=>'30']+$form);
    submitTime($db,$dev,wk(),$p,$id,3);
    wreject(fn()=>saveTime($db,$dev,wk(),$p,$week,4,$form),409,'submitted immutable');
    wreject(fn()=>decideTime($db,$dev,wk(),$p,$id,4,true,''),403,'developer approve');
    saveRate($db,$owner,wk(),$p,1,['amount'=>'200.00']+$r);
    $decision=wk(); decideTime($db,$manager,$decision,$p,$id,4,true,'Accepted');
    wcheck(decideTime($db,$manager,$decision,$p,$id,4,true,'Accepted')===$id,'approval idempotent');
    $sh=sheetRow($db,$p,$id); wcheck($sh['status']==='approved'&&(int)$sh['total_minutes']===120&&(int)$sh['cost_minor']===20002&&(int)$sh['rate_version']===1,'approved snapshot immutable across rate changes');
    wreject(fn()=>saveTime($db,$dev,wk(),$p,$week,5,$form),409,'approved immutable');
    $id2=saveTime($db,$dev,wk(),$p,$week2,0,['worked_on'=>$week2]+$form); submitTime($db,$dev,wk(),$p,$id2,2);
    decideTime($db,$manager,wk(),$p,$id2,3,false,'Correct time'); saveTime($db,$dev,wk(),$p,$week2,4,['worked_on'=>$week2,'minutes'=>'60']+$form);
    saveRate($db,$owner,wk(),$p,2,['mode'=>'fixed','amount'=>'1000']+$r); submitTime($db,$dev,wk(),$p,$id2,5);
    decideTime($db,$manager,wk(),$p,$id2,6,true,''); $fixed=sheetRow($db,$p,$id2); wcheck($fixed['rate_mode']==='fixed'&&$fixed['cost_minor']===null&&(int)$fixed['rate_minor']===100000,'fixed price not multiplied');
    $own=saveTime($db,$manager,wk(),$p,$week,0,['task_id'=>$t3]+$form); submitTime($db,$manager,wk(),$p,$own,2);
    wreject(fn()=>decideTime($db,$manager,wk(),$p,$own,3,true,''),403,'self approval blocked');
    $_SESSION=['mfa_at'=>time()]; $report=workloadPage($db,$owner,$p,$week,$s); wcheck($report['approvedMinutes']===120&&$report['costs']['EUR']===20002,'weekly approved totals');
    $report=workloadPage($db,$accountant,$p,$week,null); wcheck(count($report['sheets'])===1&&!$report['entries'],'finance only approved totals');
    $report=workloadPage($db,$dev,$p,$week,null); wcheck(count($report['sheets'])===1&&!isset($report['sheets'][0]['rate_minor']),'worker own only, no rate');
    wreject(fn()=>workloadPage($db,$viewer,$p,$week,null),403,'viewer no time'); wreject(fn()=>workloadPage($db,$owner,$foreign,$week,null),404,'cross-tenant access');
    changeMembership($db,$owner,'project',$p,3,false); wreject(fn()=>decideTime($db,$owner,wk(),$p,$own,3,true,''),404,'revoked worker not approved');
    wcheck((int)query($db,'SELECT COUNT(*) FROM cash_entries')->fetchColumn()===0,'no implicit cash movement');
    if (in_array('--postgres',$argv,true)) {
        $transfer=migrateToPostgres($dir.'/source.sqlite'); wcheck($transfer['tables']['timesheets']['rows']===3&&$transfer['tables']['time_entries']['rows']===4,'time rows transferred');
        $pg=postgresConnect(); $row=sheetRow($pg,$p,$id); wcheck((int)$row['cost_minor']===20002,'PostgreSQL history');
        $new=saveSprint($pg,$owner,wk(),$p,null,0,['title'=>'New','starts_on'=>'2025-02-01','ends_on'=>'2025-02-07']); wcheck($new>$s2,'PostgreSQL sequence');
        $report=workloadPage($pg,$owner,$p,$week,$s); wcheck($report['approvedMinutes']===120,'PostgreSQL report'); $pg=null;
    }
    echo "OK · $checks проверок спринтов и таймшитов\n";
} finally { $db=null; wcleanup($dir); }
