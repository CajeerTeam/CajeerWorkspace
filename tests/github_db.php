<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
require ROOT.'/app/Web.php';
require __DIR__.'/github_fixture.php';
function gclean(string $dir): void { foreach(new \FilesystemIterator($dir) as $e){if($e->isDir()&&!$e->isLink())gclean($e->getPathname());else unlink($e->getPathname());}rmdir($dir); }
$dir=sys_get_temp_dir().'/cws-github-'.gkey();mkdir($dir,0700);putenv('WORKSPACE_DATA='.$dir);putenv('WORKSPACE_DB=sqlite');putenv('WORKSPACE_CONFIG');$gh_checks=0;
try {
    $db=database(true);
    addUser($db,'owner@example.test','Owner','owner','sample-password-only',true);addUser($db,'dev@example.test','Dev','developer','sample-password-only');
    $owner=organizationUser($db,['id'=>1,'name'=>'Owner']);$dev=organizationUser($db,['id'=>2]);
    $p=createProject($db,$owner,gkey(),['title'=>'First','kind'=>'oss']);$other=createProject($db,$owner,gkey(),['title'=>'Other','kind'=>'internal']);
    changeMembership($db,$owner,'project',$p,2,true);
    $task=createTask($db,$owner,gkey(),$p,['title'=>'PR task','result_customer'=>'Owner','acceptance_criteria'=>'CI']);
    $issue=createTask($db,$owner,gkey(),$p,['title'=>'Issue task','result_customer'=>'Owner','acceptance_criteria'=>'Fixed']);
    $foreign=createTask($db,$owner,gkey(),$other,['title'=>'Foreign task','result_customer'=>'Owner','acceptance_criteria'=>'Done']);
    [$pem]=gpair();$fixture=new GitHubFixture();$app=new GitHubApp('20',$pem,[$fixture,'http']);
    $binding=ghBind($db,$app,$p,30,40);gcheck($binding>0,'binding created');
    gdeny(fn()=>ghBind($db,$app,$other,30,40),409,'same repository cannot cross projects');
    $form=['task_id'=>$task,'version'=>0,'kind'=>'pull','number'=>8];
    gdeny(fn()=>ghManage($db,$dev,$p,'github.link',$form),403,'developer cannot link');
    gdeny(fn()=>ghManage($db,$owner,$p,'github.link',array_replace($form,['task_id'=>$foreign])),404,'foreign task cannot link');
    ghManage($db,$owner,$p,'github.link',$form);ghManage($db,$owner,$p,'github.link',$form);
    ghManage($db,$owner,$p,'github.link',['task_id'=>$issue,'version'=>0,'kind'=>'issue','number'=>7]);
    $pending=fn()=>(int)query($db,"SELECT count(*) FROM github_jobs WHERE state='pending'")->fetchColumn();
    gcheck($pending()===3,'one health and two task jobs, idempotent link');
    gcheck(count(ghWork($db,$app,10))===3,'worker drains jobs');
    $view=fn()=>ghTaskData($db,$owner,$p,$task);gcheck($view()['link']['data']['head_sha']===$fixture->sha,'PR snapshot in task');
    gcheck(ghTaskData($db,$dev,$p,$task)['link']['data']['checks'][0]['conclusion']==='success','permitted developer read');
    $org=createOrganization($db,$owner,'Other tenant');$otherOwner=organizationUser($db,['id'=>1],$org);
    gdeny(fn()=>ghPage($db,$otherOwner,$p),404,'cross tenant read denied');
    $secret=str_repeat('s',32);$body=fn($extra)=>json_encode(['installation'=>['id'=>30],'repository'=>['id'=>40]]+$extra,JSON_THROW_ON_ERROR);
    $event=fn($event,$payload,$id=null)=>ghReceive($db,$payload,gsign($payload,$secret),$id??gdelivery(),$event,$secret);
    $raw=$body(['action'=>'synchronize','pull_request'=>['number'=>8]]);$id=gdelivery();
    gdeny(fn()=>ghReceive($db,$raw,'bad',$id,'pull_request',$secret),403,'invalid signature');
    gcheck($event('pull_request',$raw,$id)==='accepted'&&$pending()===2,'PR event targeted');
    gcheck($event('pull_request',$raw,$id)==='duplicate'&&$pending()===2,'delivery dedupe');
    gdeny(fn()=>$event('pull_request',$raw.' ',$id),409,'conflicting delivery');
    $fixture->sha=str_repeat('b',40);ghWork($db,$app,10);gcheck($view()['link']['data']['head_sha']===$fixture->sha,'fetch current SHA not event payload');
    $fixture->issueState='closed';ghManual($db,$p);ghManual($db,$p);gcheck($pending()===3,'manual coalescing');ghWork($db,$app,10);
    gcheck(ghTaskData($db,$owner,$p,$issue)['link']['data']['state']==='closed','missed event restored by reconcile');
    ghManual($db,$p);$claimed=ghClaim($db);gcheck($claimed!==null&&ghClaim($db)===null,'one running job per repository');
    $event('check_run',$body(['action'=>'completed']));gcheck($pending()===3,'event during running creates next job');
    query($db,'UPDATE github_jobs SET lease_until=? WHERE id=?',[time()-1,$claimed['id']]);$retry=ghClaim($db);gcheck((int)$retry['id']===(int)$claimed['id']&&$retry['claim']!==$claimed['claim'],'expired lease reclaimed');
    gcheck(ghFinish($db,$claimed,null,null)==='superseded','old worker cannot commit');
    ghFinish($db,$retry,$app->access(30,40),null);ghWork($db,$app,10);
    ghManual($db,$p);$fixture->failure=429;$fixture->retryAt=time()+120;ghWork($db,$app,10);
    gcheck((int)query($db,"SELECT min(available_at) FROM github_jobs WHERE state='pending'")->fetchColumn()>=$fixture->retryAt,'rate limit backoff');
    $fixture->failure=null;$fixture->retryAt=0;query($db,"UPDATE github_jobs SET available_at=? WHERE state='pending'",[time()]);ghWork($db,$app,10);
    ghManual($db,$p);$claimed=ghClaim($db);$removed=json_encode(['action'=>'removed','installation'=>['id'=>30],'repositories_removed'=>[['id'=>40]]],JSON_THROW_ON_ERROR);
    gcheck($event('installation_repositories',$removed)==='accepted','revocation accepted');gcheck(!(int)$view()['repository']['active']&&$view()['link']['data']===null,'revocation clears snapshots');
    gcheck($pending()===0&&ghFinish($db,$claimed,$app->access(30,40),null)==='superseded','revocation cancels running and queued jobs');
    gcheck(ghBind($db,$app,$p,30,40)===$binding,'verified rebind');ghWork($db,$app,10);
    gcheck($view()['link']['data']['head_sha']===$fixture->sha,'rebind recovers');
    $fixture->objectFailure=404;ghManual($db,$p);ghWork($db,$app,10);gcheck((int)$view()['repository']['active']===1&&$view()['link']['data']===null,'deleted PR clears snapshot without revoking entire repo');
    $fixture->objectFailure=null;ghManual($db,$p);ghWork($db,$app,10);
    $fixture->failure=404;ghManual($db,$p);ghWork($db,$app,10);gcheck(!(int)$view()['repository']['active'],'missed installation revocation detected by API');$fixture->failure=null;
    ghBind($db,$app,$p,30,40);ghWork($db,$app,10);
    gcheck(!str_contains(json_encode(query($db,'SELECT * FROM github_task_links')->fetchAll()),'ghs_fixture_ephemeral_token'),'token not persisted');
    $page=ghPage($db,$owner,$p);gcheck(count($page['links'])===2&&count($page['jobs'])>0,'project interface data');
    $_SESSION=['csrf'=>'test'];ob_start();pageView('github','GitHub',$owner,$page);$html=ob_get_clean();gcheck(str_contains($html,'PR &lt;unsafe&gt;')&&!str_contains($html,'PR <unsafe>'),'remote title escaped');
    if(in_array('--postgres',$argv,true)) {
        $report=migrateToPostgres($dir.'/source.sqlite');gcheck($report['tables']['github_task_links']['rows']===2,'PostgreSQL transfer');
        $pg=postgresConnect();gcheck(ghTaskData($pg,$owner,$p,$task)['link']['data']['head_sha']===$fixture->sha,'PostgreSQL snapshot preserved');ghManual($pg,$p);gcheck(count(ghWork($pg,$app,10))===3,'PostgreSQL worker and sequences');$pg=null;
    }
    echo "OK · $gh_checks проверок GitHub DB/worker\n";
} finally {$db=null;gclean($dir);}
