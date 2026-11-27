<?php
declare(strict_types=1);namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
$n=0;function testOps(bool $v,string $why):void{if(!$v)throw new \RuntimeException($why);++$GLOBALS['n'];}
$r=fn($det,$res,$minutes=10)=>['detected_at'=>$det,'resolved_at'=>$res,'recovery_minutes'=>$minutes];
$a=opsSlo([],1000,9900);testOps($a['state']==='unknown'&&$a['actual_bp']===null,'empty cohort');
$a=opsSlo([$r(100,700),$r(100,701),$r(100,null),$r(900,null)],1000,3333);testOps($a['met']===1,'boundary met');testOps($a['missed']===2,'late and overdue');testOps($a['pending']===1,'pending');testOps($a['eligible']===3,'denominator');testOps($a['actual_bp']===3333,'exact calculation');testOps($a['state']==='met','threshold compare');testOps(opsSlo([$r(100,700),$r(100,701),$r(100,null)],1000,3334)['state']==='missed','not rounded into success');
testOps(opsSlo([$r(100,null)],700,10000)['pending']===1,'at deadline');testOps(opsSlo([$r(100,700,10),$r(100,700,5)],1000,5000)['state']==='met','per incident policy');
foreach(['ops.service','ops.intake','ops.associate','ops.link','ops.close','ops.followup'] as $act)testOps(sensitiveAction($act),'MFA '.$act);
echo "OK · $n service operations checks\n";
