<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
$n=0;function checkPM(bool $ok,string $label): void {global $n;if(!$ok)throw new \RuntimeException($label);$n++;}
function rejectPM(callable $f,string $label):void {try{$f();}catch(Problem $e){checkPM(true,$label);return;}throw new \RuntimeException($label);}
$m=fn($id,$date,$state='open')=>['id'=>$id,'title'=>'M'.$id,'due_on'=>$date,'state'=>$state,'version'=>1];
$c=['milestones'=>[1=>$m(1,'2027-01-01'),2=>$m(2,'2027-01-03'),3=>$m(3,'2027-01-04'),4=>$m(4,'2027-01-05'),5=>$m(5,null)],'edges'=>[[2,1],[3,2]]];
pmValidateGraph($c);$after=pmImpact($c,1,'2027-01-06');checkPM($after['milestones'][2]['due_on']==='2027-01-06','direct propagation');checkPM($after['milestones'][3]['due_on']==='2027-01-06','transitive propagation');checkPM($after['milestones'][4]===$c['milestones'][4],'unrelated unchanged');checkPM($after['milestones'][5]===$c['milestones'][5],'undated unchanged');checkPM(count(pmDateChanges($c,$after))===3,'date changes');checkPM(pmDateChanges($c,$after,false)[0]['title']==='Веха','guest projection');checkPM(pmFingerprint($c)!==pmFingerprint($after),'fingerprints');checkPM(pmImpact($c,1,'2026-12-31')['milestones'][2]===$c['milestones'][2],'no unrelated acceleration');
rejectPM(fn()=>pmImpact($c,2,'2026-12-31'),'prerequisite date conflict');rejectPM(fn()=>pmImpact($c,99,'2027-01-01'),'foreign milestone');rejectPM(fn()=>pmImpact($c,1,'2027-02-30'),'invalid date');
$x=$c;$x['edges'][]=[1,3];rejectPM(fn()=>pmValidateGraph($x),'cycle');$x=$c;$x['edges'][]=[1,1];rejectPM(fn()=>pmValidateGraph($x),'self');$x=$c;$x['edges'][]=[4,5];rejectPM(fn()=>pmValidateGraph($x),'undated dependency');$x=$c;$x['edges'][]=[4,99];rejectPM(fn()=>pmValidateGraph($x),'unknown dependency');$x=$c;$x['milestones'][2]['state']='closed';rejectPM(fn()=>pmValidateGraph($x),'closed dependency');$x=$c;$x['milestones'][1]['state']='closed';rejectPM(fn()=>pmImpact($x,1,'2027-01-02'),'closed target');
checkPM(pmDateChanges($c,$c)===[],'scope only');echo "OK · $n project management checks\n";
