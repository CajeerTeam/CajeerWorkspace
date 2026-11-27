<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';require ROOT.'/app/Web.php';
$n=0;function cc(bool $ok,string $label):void{global $n;if(!$ok)throw new \RuntimeException($label);$n++;}function ck():string{return bin2hex(random_bytes(16));}function cr(callable $fn,int $status):void{try{$fn();}catch(Problem $e){cc($e->status===$status,$e->getMessage());return;}throw new \RuntimeException('Expected rejection');}function cleanupCapacity(string $dir):void{foreach(new \FilesystemIterator($dir) as $f){if($f->isDir()&&!$f->isLink())cleanupCapacity($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
$dir=sys_get_temp_dir().'/cw-capacity-'.ck();mkdir($dir,0700);putenv('WORKSPACE_DATA='.$dir);putenv('WORKSPACE_DB=sqlite');
try{
$db=database(true);addUser($db,'owner@example.test','Owner','owner','sample-password-only',true);addUser($db,'dev@example.test','Dev','developer','sample-password-only');addUser($db,'other@example.test','Other','developer','sample-password-only');$owner=organizationUser($db,['id'=>1,'name'=>'Owner']);$dev=organizationUser($db,['id'=>2]);$_SESSION=['mfa_at'=>time(),'csrf'=>ck()];
$p=createProject($db,$owner,ck(),['title'=>'P','kind'=>'internal']);$q=createProject($db,$owner,ck(),['title'=>'Q','kind'=>'internal']);foreach([$p,$q] as $project)foreach([2,3] as $uid)changeMembership($db,$owner,'project',$project,$uid,true);createTeam($db,$owner,'Team A');createTeam($db,$owner,'Team B');$a=(int)query($db,"SELECT id FROM teams WHERE name='Team A'")->fetchColumn();$b=(int)query($db,"SELECT id FROM teams WHERE name='Team B'")->fetchColumn();changeMembership($db,$owner,'team',$a,2,true);changeMembership($db,$owner,'team',$b,2,true);changeMembership($db,$owner,'team',$b,3,true);
$week=(new \DateTimeImmutable(capacityCurrentWeek()))->modify('+1 week')->format('Y-m-d');$snapshot=fn()=>capacityFingerprint(capacityWeekData($db,1,$week));$cmd=fn($act,$f,$k=null)=>capacityCommand($db,$owner,$k??ck(),$act,$f+['week'=>$week,'snapshot'=>$snapshot(),'reason'=>'Planning']);
$days=array_fill_keys(array_keys(CAPACITY_DAYS),0);$days['mon']=480;$days['tue']=480;$days['wed']=480;$days['thu']=480;$days['fri']=480;
$task=createTask($db,$owner,ck(),$p,['title'=>'Queue task','assignee'=>'2','result_customer'=>'Owner','acceptance_criteria'=>'Done']);
$past=(new \DateTimeImmutable(capacityCurrentWeek()))->modify('-1 week')->format('Y-m-d');saveRate($db,$owner,ck(),$p,0,['mode'=>'hourly','currency'=>'EUR','amount'=>'100.00']);$sheet=saveTime($db,$dev,ck(),$p,$past,0,['task_id'=>$task,'worked_on'=>$past,'minutes'=>100,'note'=>'Actual']);submitTime($db,$dev,ck(),$p,$sheet,2);decideTime($db,$owner,ck(),$p,$sheet,3,true,'Approved');
$before=query($db,'SELECT * FROM timesheets')->fetchAll();$entries=query($db,'SELECT * FROM time_entries')->fetchAll();$rates=query($db,'SELECT * FROM project_rates')->fetchAll();
$cmd('capacity.calendar',['user_id'=>2]+$days);$cmd('capacity.calendar',['user_id'=>3]+$days);
$cmd('capacity.assign',['user_id'=>2,'project_id'=>$p,'team_id'=>$a,'minutes'=>1200]);$cmd('capacity.assign',['user_id'=>2,'project_id'=>$q,'team_id'=>$b,'minutes'=>1200]);
cr(fn()=>$cmd('capacity.assign',['user_id'=>2,'project_id'=>$q,'team_id'=>$b,'minutes'=>1201]),409);
cr(fn()=>saveAvailability($db,$dev,ck(),$q,2,$week,1300,1),409);
$k=ck();$form=['user_id'=>2,'project_id'=>$p,'target_user'=>2,'target_project'=>$q,'target_team'=>$b,'minutes'=>480,'snapshot'=>$snapshot()];$cmd('capacity.transfer',$form,$k);$cmd('capacity.transfer',$form,$k);
cc((int)capacityAllocation($db,$p,2,$week)['minutes']===720,'source reduced once');cc((int)capacityAllocation($db,$q,2,$week)['minutes']===1680,'target increased once');
cr(fn()=>$cmd('capacity.assign',['user_id'=>2,'project_id'=>$p,'team_id'=>$a,'minutes'=>600,'snapshot'=>$form['snapshot']]),409);
$cmd('capacity.transfer',['user_id'=>2,'project_id'=>$q,'target_user'=>3,'target_project'=>$q,'target_team'=>$b,'minutes'=>480]);cc(capacityAllocated($db,1,2,$week)===1920&&capacityAllocated($db,1,3,$week)===480,'substitution');
cr(fn()=>$cmd('capacity.calendar',['user_id'=>2]+array_fill_keys(array_keys(CAPACITY_DAYS),0)),409);
$s=saveSprint($db,$owner,ck(),$p,null,0,['title'=>'Sprint','starts_on'=>$week,'ends_on'=>weekEnd($week)]);sprintTask($db,$owner,ck(),$p,$s,1,$task,1000);
$page=capacityPage($db,$owner,$week);cc($page['demand'][$p]['minutes']===900,'estimated queue');cc($page['forecast'][$p][$week]['remaining']===180,'project queue forecast');cc($page['teamTotals'][$a]===720&&$page['teamTotals'][$b]===1680,'team aggregation');cc($before===query($db,'SELECT * FROM timesheets')->fetchAll()&&$entries===query($db,'SELECT * FROM time_entries')->fetchAll()&&$rates===query($db,'SELECT * FROM project_rates')->fetchAll(),'approved time and rates unchanged');
cr(fn()=>capacityPage($db,$dev,$week),403);$org=createOrganization($db,$owner,'Other org');$foreignOwner=organizationUser($db,$owner,$org);$foreign=createProject($db,$foreignOwner,ck(),['title'=>'Foreign','kind'=>'internal']);cr(fn()=>$cmd('capacity.assign',['user_id'=>2,'project_id'=>$foreign,'team_id'=>$a,'minutes'=>60]),404);cc(!isset(capacityPage($db,$owner,$week)['projects'][$foreign]),'tenant isolation');
changeMembership($db,$owner,'team',$a,2,false);cc(capacityPage($db,$owner,$week)['forecast'][$p][$week]['capacity']===0,'removed membership excluded');
$_SESSION['mfa_at']=time()-301;cr(fn()=>capacityPage($db,$owner,$week),403);$_SESSION['mfa_at']=time();ob_start();pageView('capacity','Capacity',$owner,capacityPage($db,$owner,$week));$html=ob_get_clean();cc(str_contains($html,'Прогноз очереди'),'render');
if(in_array('--postgres',$argv,true)){$report=migrateToPostgres($dir.'/source.sqlite');cc($report['tables']['capacity_weeks']['rows']===2,'PG calendar transfer');$pg=postgresConnect();cc(capacityAllocated($pg,1,2,$week)===1920,'PG allocations');cc(capacityPage($pg,$owner,$week)['forecast'][$p][$week]['capacity']===0,'PG forecast');$pg=null;}
echo "OK · $n capacity DB checks\n";
}finally{$db=null;cleanupCapacity($dir);}
