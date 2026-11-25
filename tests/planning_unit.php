<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
$checks=0;
function pc(bool $ok,string $name): void { if (!$ok) throw new \RuntimeException($name); ++$GLOBALS['checks']; }
function rejectPlan(callable $call,string $name): void { try {$call();} catch(Problem $e) {pc($e->status===422,$name);return;} throw new \RuntimeException($name); }
pc(planningFold('ЁЛКА ABC')==='ёлка abc','Cyrillic/ASCII folding');
pc(planningLike('100%_!')==='%100!%!_!!%','wildcard escaping');
pc(criteriaText("a\r\nb\rc")==="a\nb\nc",'multiline criteria');
foreach ([''," \n",str_repeat('ё',4001),"bad\0",[1],"\xff"] as $bad) rejectPlan(fn()=>criteriaText($bad),'invalid criteria');
$f=taskPlanData(['title'=>'Task','result_customer'=>'Customer','acceptance_criteria'=>'Checks']);
pc($f===['Task',null,null,3,'Customer','Checks',null],'task data defaults');
rejectPlan(fn()=>taskPlanData(['title'=>'Task','result_customer'=>'','acceptance_criteria'=>'Checks']),'required customer');
rejectPlan(fn()=>taskPlanData(['title'=>'Task','result_customer'=>'Customer','acceptance_criteria'=>'Checks','priority'=>'5']),'priority bounds');
rejectPlan(fn()=>taskPlanData(['title'=>'Task','result_customer'=>'Customer','acceptance_criteria'=>'Checks','due_on'=>'2025-02-29']),'invalid calendar date');
pc(planningFilters(['assignee'=>'none','milestone'=>'none','priority'=>'1','state'=>'doing','blocked'=>'late','overdue'=>'yes','view'=>'backlog'])===['q'=>'','state'=>'doing','priority'=>'1','milestone'=>'none','assignee'=>'none','blocked'=>'late','overdue'=>'yes','view'=>'backlog'],'combined filters');
foreach (['state'=>'invalid','priority'=>'0','view'=>'invalid','blocked'=>'x','assignee'=>'-1','milestone'=>'x','overdue'=>'x'] as $key=>$value) rejectPlan(fn()=>planningFilters([$key=>$value]),'bad filter '.$key);
pc(planningFilters([])['view']==='board','default board');
echo "OK · $checks проверок валидации планирования\n";
