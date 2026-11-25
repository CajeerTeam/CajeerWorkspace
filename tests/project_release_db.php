<?php
declare(strict_types=1);
namespace Workspace;
require __DIR__.'/project_release_unit.php';
$dir=sys_get_temp_dir().'/cw-release-'.gkey();mkdir($dir,0700);putenv('WORKSPACE_DATA='.$dir);putenv('WORKSPACE_DB=sqlite');putenv('WORKSPACE_CONFIG');
function releaseTestClean(string $dir):void{foreach(new \FilesystemIterator($dir) as $f){if($f->isDir())releaseTestClean($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
try{
    $db=database(true);addUser($db,'owner@example.test','Owner','owner','sample-password-only',true);addUser($db,'dev@example.test','Dev','developer','sample-password-only');
    $owner=organizationUser($db,['id'=>1,'name'=>'Owner']);$dev=organizationUser($db,['id'=>2,'name'=>'Dev']);$p=createProject($db,$owner,gkey(),['title'=>'First','kind'=>'oss']);$other=createProject($db,$owner,gkey(),['title'=>'Other','kind'=>'internal']);changeMembership($db,$owner,'project',$p,2,true);
    $task=createTask($db,$owner,gkey(),$p,['title'=>'PR task','result_customer'=>'Owner','acceptance_criteria'=>'CI']);query($db,"UPDATE tasks SET state='done' WHERE id=?",[$task]);
    $repo=insertId($db,'INSERT INTO github_repositories(project_id,repository_id,installation_id,full_name) VALUES(?,?,?,?)',[$p,40,30,'owner/example']);ghManage($db,$owner,$p,'github.link',['task_id'=>$task,'version'=>0,'kind'=>'pull','number'=>7]);
    [$pem]=gpair();$f=new ReleaseFixture();$app=new GitHubApp('20',$pem,[$f,'http']);$form=['tag'=>'v1','commit_sha'=>$f->sha,'changelog'=>"Notes\nSecond",'artifact_name'=>'app.zip','artifact_sha256'=>$f->digest,'required_checks'=>'Build','responsible_id'=>1,'tasks'=>[$task]];
    $key=gkey();$id=releaseCreate($db,$owner,$p,$key,$form);gcheck(releaseCreate($db,$owner,$p,$key,$form)===$id,'duplicate create');gdeny(fn()=>releaseCreate($db,$dev,$p,gkey(),$form),403,'developer cannot create');
    gdeny(fn()=>releasePage($db,$owner,$other,$id),404,'cross project ID');
    releaseInspect($db,$owner,$p,$id,1,false,$app);gcheck(releaseRow($db,$p,$id)['state']==='verified','verified');
    $f->checkId=9;gdeny(fn()=>releaseInspect($db,$owner,$p,$id,2,true,$app),409,'changed evidence requires review');gcheck((int)releaseRow($db,$p,$id)['version']===3,'new evidence persisted');
    $f->failed=true;gdeny(fn()=>releaseInspect($db,$owner,$p,$id,3,true,$app),409,'failed CI blocks approval');$f->failed=false;gcheck(releaseRow($db,$p,$id)['state']==='draft','failed verification invalidates previous result');
    releaseInspect($db,$owner,$p,$id,4,false,$app);releaseInspect($db,$owner,$p,$id,5,true,$app);releaseInspect($db,$owner,$p,$id,5,true,$app);gcheck(releaseRow($db,$p,$id)['state']==='confirmed','confirmed once');
    gdeny(fn()=>releaseCancel($db,$owner,$p,$id,6),409,'confirmed immutable');
    $page=releasePage($db,$dev,$p,$id);gcheck($page['evidence']['artifact']['id']===22&&!$page['releaseManage'],'read only evidence');
    require ROOT.'/app/Web.php'; $_SESSION=['csrf'=>'test']; ob_start();pageView('releases','Выпуски',$owner,releasePage($db,$owner,$p,$id));$html=ob_get_clean();gcheck(str_contains($html,'GitHub Release #21')&&str_contains($html,'app.zip')&&str_contains($html,'Merge SHA'),'release view');
    $db->exec('UPDATE github_repositories SET active=0 WHERE id='.(int)$repo);gcheck(releasePage($db,$owner,$p,$id)['evidence']===null,'revocation hides evidence');
    if(in_array('--postgres',$argv,true)){$report=migrateToPostgres($dir.'/source.sqlite');$pg=postgresConnect();gcheck($report['tables']['project_releases']['rows']===1&&releaseRow($pg,$p,$id)['state']==='confirmed','Postgres transfer');$pg=null;}
    echo "OK · $gh_checks проверок release DB\n";
}finally{$db=null;releaseTestClean($dir);}
