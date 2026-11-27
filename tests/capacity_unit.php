<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
$n=0;function capCheck(bool $ok,string $why):void{global $n;if(!$ok)throw new \RuntimeException($why);++$n;}function capReject(callable $fn,int $status):void{try{$fn();}catch(Problem $e){capCheck($e->status===$status,$e->getMessage());return;}throw new \RuntimeException('Expected rejection');}
$days=['mon'=>480,'tue'=>'480','wed'=>0,'thu'=>480,'fri'=>480,'sat'=>0,'sun'=>0];
capCheck(capacityTotal(capacityDays($days))===1920,'calendar total');
capCheck(capacityTotal($days+['other'=>999])===1920,'metadata excluded');
foreach([-1,1441,'1.2',[],null] as $v)capReject(fn()=>capacityDays(array_replace($days,['mon'=>$v])),422);
capReject(fn()=>capacityDays(array_diff_key($days,['sun'=>0])),422);
capCheck(capacityTotal(array_fill_keys(array_keys(CAPACITY_DAYS),1440))===10080,'maximum');
capReject(fn()=>capacityEditable('2020-01-06'),409);capacityEditable(capacityCurrentWeek());capCheck(weekStart(capacityCurrentWeek())===capacityCurrentWeek(),'Monday');
$d=[1=>['minutes'=>960],2=>['minutes'=>600],3=>['minutes'=>0]];
$w=['2027-01-04'=>[1=>480,2=>0,3=>600],'2027-01-11'=>[1=>240,2=>720],'2027-01-18'=>[1=>480]];
$r=capacityForecast($d,$w);capCheck($r[1]['2027-01-04']['remaining']===480,'first week');capCheck($r[1]['2027-01-11']['remaining']===240,'second week');capCheck($r[1]['2027-01-18']['covered']===240,'coverage cap');capCheck($r[2]['2027-01-11']['remaining']===0,'project isolation');capCheck($r[3]['2027-01-04']['covered']===0,'empty queue');capCheck($r[2]['2027-01-18']['capacity']===0,'unknown future capacity');
capCheck(capacityFingerprint($w)!==capacityFingerprint(array_reverse($w,true)),'snapshot changes');capCheck(sensitiveAction('capacity.transfer'),'MFA policy');
echo "OK · $n capacity checks\n";
