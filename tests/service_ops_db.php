<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
$dir=sys_get_temp_dir().'/cw-service-'.bin2hex(random_bytes(8));mkdir($dir,0700);putenv('WORKSPACE_DATA='.$dir);putenv('WORKSPACE_DB=sqlite');
$n=0;function dcheck(bool $ok,string $label):void{global $n;if(!$ok)throw new \RuntimeException($label);$n++;}
function ddeny(callable $fn,string $label):void{try{$fn();}catch(Problem){dcheck(true,$label);return;}throw new \RuntimeException('Expected denial: '.$label);}
function dclean(string $dir):void{foreach(new \FilesystemIterator($dir) as $f){if($f->isDir()&&!$f->isLink())dclean($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
try{
$db=database(true);addUser($db,'owner@example.test','Owner','owner','sample-password-only',true);addUser($db,'dev@example.test','Dev','developer','sample-password-only');$owner=organizationUser($db,['id'=>1,'name'=>'Owner']);$dev=organizationUser($db,['id'=>2,'name'=>'Dev']);$p=createProject($db,$owner,bin2hex(random_bytes(16)),['title'=>'Project','kind'=>'oss']);$other=createProject($db,$owner,bin2hex(random_bytes(16)),['title'=>'Other','kind'=>'internal']);changeMembership($db,$owner,'project',$p,2,true);
$repo=insertId($db,'INSERT INTO github_repositories(project_id,repository_id,installation_id,full_name) VALUES(?,?,?,?)',[$p,40,30,'owner/example']);$digest=str_repeat('b',64);$sha=str_repeat('a',40);$e=json_encode(['artifact'=>['name'=>'app.zip','sha256'=>$digest,'size'=>123]],JSON_THROW_ON_ERROR);$hash=hash('sha256',$e);
$r=insertId($db,"INSERT INTO project_releases(project_id,repository_binding,repository_generation,tag,commit_sha,changelog,artifact_name,artifact_sha256,required_checks,responsible_id,creator_id,request_key,created_at,state,evidence,evidence_hash,verified_at,confirmed_at,confirmed_by) VALUES(?,?,1,?,?,?,?,?, ?,1,1,?,1,'confirmed',?,?,1,1,1)",[$p,$repo,'v1',$sha,'Notes','app.zip',$digest,'["Build"]',bin2hex(random_bytes(16)),$e,$hash]);
$host=insertId($db,"INSERT INTO infra_hosts(organization_id,name,panel_key,panel_target,panel_hash,panel_api,servers_key,servers_target,servers_hash,workspace_uuid,asset_uuid) VALUES(1,'Host','panel',?,?,'v2','servers',?,?,?,?)",[str_repeat('a',64),str_repeat('b',64),str_repeat('c',64),str_repeat('d',64),str_repeat('e',36),str_repeat('f',36)]);$env=insertId($db,"INSERT INTO infra_environments(organization_id,project_id,kind,name) VALUES(1,?,'prod','Production')",[$p]);$site=insertId($db,"INSERT INTO infra_sites(organization_id,project_id,environment_id,host_id,remote_id,site_name) VALUES(1,?,?,?,1,'example.test')",[$p,$env,$host]);query($db,"INSERT INTO infra_operation_owners VALUES(?,'site.deploy','aapanel',1)",[$host]);

$_SESSION=['mfa_at'=>time(),'csrf'=>'test'];$key=fn()=>bin2hex(random_bytes(16));
$cmd=fn($act,$data,$k=null)=>opsCommand($db,$owner,$p,$k??$key(),$act,$data);
$cmd('ops.service',['site_id'=>$site,'name'=>'API','recovery_minutes'=>'30','target_bp'=>'9900']);$sid=(int)query($db,'SELECT id FROM ops_services WHERE site_id=?',[$site])->fetchColumn();
incidentConfigure($db,$owner,$p,1,15,true);
$in=['site_id'=>$site,'release_id'=>$r,'window_start'=>gmdate('Y-m-d\TH:i',time()-60),'window_end'=>gmdate('Y-m-d\TH:i',time()+3600)];
$id=deploymentCreate($db,$owner,$p,$key(),$in);
dcheck(deploymentRun($db,$id,false,fn()=>['state'=>'failed','message'=>'failed'])['state']==='failed','actual deployment failure');
$i=entity($db,'SELECT * FROM incidents WHERE project_id=?',[$p]);$iid=(int)$i['id'];dcheck((int)$i['release_id']===$r,'exact release');dcheck((int)$i['responsible_id']===1,'on call');dcheck((int)query($db,'SELECT deployment_id FROM ops_service_incidents WHERE incident_id=?',[$iid])->fetchColumn()===$id,'job link');
dcheck(incidentScan($db,$owner,$p)['accepted']===0,'repeat scan dedup');dcheck(!opsIncidentSignal($db,$p,['source'=>'logs','code'=>'deploy.failed','object'=>$id]),'remote dedup');
$intake=['service_id'=>$sid,'title'=>'API outage','description'=>'Observed failure','requester'=>'Customer'];$k=$key();$cmd('ops.intake',$intake,$k);$cmd('ops.intake',$intake,$k);$cmd('ops.intake',array_replace($intake,['title'=>'Second report']));
dcheck((int)$db->query('SELECT COUNT(*) FROM ops_support')->fetchColumn()===2,'two reports and idempotence');$ticket=(int)$db->query('SELECT MIN(id) FROM ops_support')->fetchColumn();
$cmd('ops.link',['service_id'=>$sid,'id'=>$ticket,'version'=>'1','incident_id'=>$iid]);ddeny(fn()=>$cmd('ops.close',['service_id'=>$sid,'id'=>$ticket,'version'=>'2','resolution'=>'fixed']),'open incident cannot close support');
incidentCommand($db,$owner,$p,$iid,1,'ack',[]);incidentCommand($db,$owner,$p,$iid,2,'resolve',['report'=>'restored','verification'=>'independent probe','manual'=>'yes']);
$cmd('ops.close',['service_id'=>$sid,'id'=>$ticket,'version'=>'2','resolution'=>'verified restoration']);
$task=['service_id'=>$sid,'incident_id'=>$iid,'title'=>'Regression prevention','acceptance_criteria'=>'Test failed deployment and recovery','assignee'=>(string)$dev['id'],'priority'=>'2'];$k=$key();$cmd('ops.followup',$task,$k);$cmd('ops.followup',$task,$k);dcheck((int)$db->query('SELECT COUNT(*) FROM ops_followups')->fetchColumn()===1,'task idempotence');
$view=opsPage($db,$owner,$p,$sid);dcheck($view['opsSlo']['met']===1&&$view['opsSlo']['eligible']===1,'SLO uses confirmed recovery');dcheck(count($view['opsFollowups'])===1,'task visible');
$cmd('ops.service',['service_id'=>$sid,'site_id'=>$site,'version'=>'1','name'=>'API','recovery_minutes'=>'60','target_bp'=>'9500']);dcheck((int)$db->query('SELECT recovery_minutes FROM ops_service_incidents')->fetchColumn()===30,'historical threshold preserved');
ddeny(fn()=>opsPage($db,$owner,$other,$sid),'cross project service');ddeny(fn()=>opsCommand($db,$dev,$p,$key(),'ops.intake',$intake),'developer cannot change support');
require ROOT.'/app/Web.php';ob_start();pageView('service_ops','Operations',$owner,opsPage($db,$owner,$p,$sid));$html=ob_get_clean();dcheck(str_contains($html,'Regression prevention')&&str_contains($html,'API outage'),'render');
$_SESSION['mfa_at']=time()-301;ddeny(fn()=>opsPage($db,$owner,$p,$sid),'MFA required');$_SESSION['mfa_at']=time();
if(in_array('--postgres',$argv,true)){$report=migrateToPostgres($dir.'/source.sqlite');$pg=postgresConnect();dcheck($report['tables']['ops_support']['rows']===2,'PostgreSQL transfer');dcheck(opsPage($pg,$owner,$p,$sid)['opsSlo']['met']===1,'PostgreSQL report');$pg=null;}
echo "OK · $n service operations DB checks\n";
}finally{$db=null;dclean($dir);}
