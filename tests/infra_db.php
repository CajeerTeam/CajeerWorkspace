<?php
declare(strict_types=1);
namespace Workspace;
require __DIR__.'/infra_unit.php';
$dir=sys_get_temp_dir().'/cw-infra-'.bin2hex(random_bytes(8));mkdir($dir,0700);putenv('WORKSPACE_DATA='.$dir);putenv('WORKSPACE_DB=sqlite');putenv('WORKSPACE_CONFIG='.$dir.'/config.json');
file_put_contents($dir.'/config.json',json_encode(['aapanel'=>['base_url'=>'https://panel.example.test','secret_env'=>'WORKSPACE_TEST_PANEL'],'servers'=>['base_url'=>'https://servers.example.test','secret_env'=>'WORKSPACE_TEST_SERVERS']]));
function infraTestClean(string $dir):void{foreach(new \FilesystemIterator($dir) as $f){if($f->isDir())infraTestClean($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
try{
$db=database(true);addUser($db,'owner@example.test','Owner','owner','sample-password-only',true);addUser($db,'dev@example.test','Dev','developer','sample-password-only');
$owner=organizationUser($db,['id'=>1,'name'=>'Owner']);$dev=organizationUser($db,['id'=>2,'name'=>'Dev']);$p=createProject($db,$owner,bin2hex(random_bytes(16)),['title'=>'First','kind'=>'internal']);$other=createProject($db,$owner,bin2hex(random_bytes(16)),['title'=>'Other','kind'=>'internal']);changeMembership($db,$owner,'project',$p,2,true);
$in=['name'=>'Host','panel_key'=>'aapanel','servers_key'=>'servers','panel_api'=>'v2','workspace_uuid'=>IW,'asset_uuid'=>IA];$host=infraHostCreate($db,$owner,$in);
ideny(fn()=>infraHostCreate($db,$owner,$in),'duplicate host');ideny(fn()=>infraHostCreate($db,$dev,$in),'non-owner create');
infraChange($db,$owner,$p,'infra.environment',['kind'=>'prod','name'=>'Production']);infraChange($db,$owner,$other,'infra.environment',['kind'=>'dev','name'=>'Development']);$eid=(int)query($db,'SELECT id FROM infra_environments WHERE project_id=?',[$p])->fetchColumn();$otherE=(int)query($db,'SELECT id FROM infra_environments WHERE project_id=?',[$other])->fetchColumn();
$b=['host_id'=>$host,'environment_id'=>$eid,'remote_id'=>1,'site_name'=>'one.example.test'];ideny(fn()=>infraChange($db,$owner,$p,'infra.bind',$b),'fresh sync required');
$obs=infraSync($db,$owner,$host,__NAMESPACE__.'\\infraFixture');icheck($obs['aapanel']['fresh']&&$obs['servers']['fresh'],'fresh sources');
infraChange($db,$owner,$p,'infra.bind',$b);infraChange($db,$owner,$p,'infra.bind',$b);icheck((int)$db->query('SELECT COUNT(*) FROM infra_sites')->fetchColumn()===1,'idempotent binding');
ideny(fn()=>infraChange($db,$owner,$other,'infra.bind',array_replace($b,['environment_id'=>$otherE])),'site cannot be shared');ideny(fn()=>infraChange($db,$owner,$p,'infra.bind',array_replace($b,['environment_id'=>$otherE])),'environment scope');
infraChange($db,$owner,$p,'infra.owner',['host_id'=>$host,'operation'=>'site.deploy','provider'=>'aapanel','responsible_id'=>1]);infraChange($db,$owner,$p,'infra.owner',['host_id'=>$host,'operation'=>'site.deploy','provider'=>'servers','responsible_id'=>2]);
$page=infraPage($db,$dev,$p);icheck(count($page['hosts'][$host]['observations']['aapanel']['data']['sites'])===1&&$page['hosts'][$host]['owners']['site.deploy']['provider']==='servers','filtered inventory and single executor');ideny(fn()=>infraPage($db,$dev,$other),'foreign project denied');
$obs=infraSync($db,$owner,$host,fn($m,$url,$h,$f)=>$m==='GET'?throw new Problem('outage'):infraFixture($m,$url,$h,$f));icheck($obs['aapanel']['fresh']&&!$obs['servers']['fresh']&&$obs['servers']['data']['id']===IA,'independent failure retains snapshot');
$obs=infraSync($db,$owner,$host,__NAMESPACE__.'\\infraFixture');icheck($obs['servers']['fresh'],'recovery');
require ROOT.'/app/Web.php';$_SESSION=['csrf'=>'test'];ob_start();pageView('infrastructure','Инфраструктура',$dev,infraPage($db,$dev,$p));$html=ob_get_clean();icheck(str_contains($html,'one.example.test')&&!str_contains($html,'two.example.test'),'render scope');
if(in_array('--postgres',$argv,true)){$report=migrateToPostgres($dir.'/source.sqlite');$pg=postgresConnect();icheck($report['tables']['infra_sites']['rows']===1&&count(infraPage($pg,$dev,$p)['bindings'])===1,'PG transfer');$pg=null;}
echo "OK · $n infrastructure checks (unit + DB)\n";
}finally{$db=null;infraTestClean($dir);}
