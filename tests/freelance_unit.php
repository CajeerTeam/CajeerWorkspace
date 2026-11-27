<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
$n=0;function fc(bool $ok,string $label):void{global $n;if(!$ok)throw new \RuntimeException($label);$n++;}
foreach([['2027-01-31',0,'2027-01-31'],['2027-01-31',1,'2027-02-28'],['2027-01-31',2,'2027-03-31'],['2028-01-31',1,'2028-02-29'],['2027-12-30',1,'2028-01-30']] as [$first,$offset,$expected])fc(freelancePeriod($first,$offset)===$expected,'calendar anchor');
fc(freelanceDue('2027-12-31',14)==='2028-01-14','payment due');foreach(['','0','0.00','0,0'] as $s)fc(freelanceOptionalMoney($s)===0,'optional zero');fc(freelanceOptionalMoney('24.51')===2451,'minor units');
foreach(['-1','1.001','1e3'] as $v){try{freelanceOptionalMoney($v);throw new \RuntimeException('invalid amount');}catch(Problem){$n++;}}
$i=['state'=>'issued','due_on'=>'2027-01-01'];$b=['outstanding'=>7500,'received'=>3000,'refunded'=>500];$r=freelanceBillStatus($i,$b,false,'2027-01-02');fc($r['outstanding']===7500&&$r['overdue']&&$r['refunded']===500,'partial and refund');fc(!freelanceBillStatus($i,$b,false,'2027-01-01')['overdue'],'due date inclusive');fc(!freelanceBillStatus($i,array_replace($b,['outstanding'=>0]),false,'2027-01-02')['overdue'],'fully paid');foreach(['draft','void'] as $state)fc(freelanceBillStatus(array_replace($i,['state'=>$state]),$b,false,'2027-01-02')['outstanding']===0,'not issued');fc(freelanceBillStatus($i,$b,true,'2027-01-02')['outstanding']===0,'credited');
echo "OK · $n freelance unit checks\n";
