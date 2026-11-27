<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
$n=0;
function cycleAssert(bool $ok):void {if(!$ok)throw new \RuntimeException('Full cycle assertion failed');++$GLOBALS['n'];}
function cycleReject(array $v):void {try{pilotCycleInput($v);}catch(Problem){cycleAssert(true);return;}throw new \RuntimeException('Invalid input accepted');}
$ids=array_combine(CYCLE_IDS,range(1,count(CYCLE_IDS)));
cycleAssert(pilotCycleInput($ids)===$ids);
cycleAssert(pilotCycleInput(array_map('strval',$ids))===$ids);
cycleAssert(count(CYCLE_CHECKS)===15);
cycleAssert(count(PILOT_STAFF)*3*count(PROJECT_RIGHTS)===240);
foreach([0,-1,true,false,1.0,[],null,'1e2',' 1','01','99999999999999999999999'] as $bad)cycleReject(array_replace($ids,['order'=>$bad]));
cycleReject(array_diff_key($ids,['order'=>true]));
cycleReject($ids+['extra'=>1]);
cycleReject(array_replace($ids,['failed_job'=>$ids['staging_job']]));
cycleReject(array_replace($ids,['lite_route'=>$ids['engine_route']]));
cycleAssert(pilotDenied(fn()=>throw new Problem('denied',404),404));
cycleAssert(!pilotDenied(fn()=>throw new Problem('denied',403),404));
cycleAssert(!pilotDenied(fn()=>true,404));
echo "OK · $n full cycle checks\n";
