<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
require ROOT.'/app/Web.php';
$n=0;
function rcCheck(bool $ok,string $label):void {global $n;if(!$ok)throw new \RuntimeException($label);++$n;}
function rcDeny(callable $fn):void {try{$fn();}catch(Problem){rcCheck(true,'denial');return;}throw new \RuntimeException('Expected denial');}
// Independent expectations: never derive expected permissions from PROJECT_RIGHTS.
$matrix=[
 'owner'=>'manage work finance payment.create payment.approve legal legal.write connections operations audit',
 'manager'=>'manage work connections','developer'=>'work connections','accountant'=>'finance payment.create payment.approve',
 'legal'=>'legal legal.write','viewer'=>'','inherit'=>'','finance_reader'=>'finance','payment_creator'=>'finance payment.create',
 'payment_approver'=>'finance payment.approve','legal_reader'=>'legal','integration_reader'=>'connections','operator'=>'connections operations','auditor'=>'audit'];
$rights=explode(' ',$matrix['owner']);
rcCheck(count(PROJECT_RIGHTS)===count($rights)&&!array_diff($rights,array_keys(PROJECT_RIGHTS)),'Right inventory');
rcCheck(count(PROJECT_ROLES)===count($matrix)-1&&!array_diff(array_keys(PROJECT_ROLES),array_keys($matrix)),'Role inventory');
foreach($matrix as $role=>$allowed){foreach($rights as $right)rcCheck(roleAllows($role,$right)===in_array($right,explode(' ',$allowed),true),"$role/$right");rcCheck(!roleAllows($role,'unknown'),'Unknown right');}
foreach(['finance'=>'finance','export'=>'finance','documents'=>'legal','document'=>'legal','download'=>'legal','tax.print'=>'finance','prep.act'=>'finance','approved_acts'=>'finance','sign.download'=>'legal','logs.export'=>'connections','book.export'=>'connections','audit'=>'audit'] as $p=>$r)rcCheck((PAGE_RIGHTS[$p]??null)===$r,"Page permission $p");
rcCheck(CURRENCIES===['RUB','EUR','USD','PLN','GBP'],'Currency inventory');
foreach(['1,23'=>123,'1.23'=>123,'0,01'=>1,'999999999.99'=>99999999999,'1234'=>123400] as $text=>$value)rcCheck(moneyMinor((string)$text)===$value,'Parse amount');
foreach(['0','-1','1,234','1 234','1e3','1.2.3','NaN','1234567890'] as $s)rcDeny(fn()=>moneyMinor($s));
foreach([[123456,'1 234,56','1234.56'],[-123456,'-1 234,56','-1234.56'],[0,'0,00','0.00'],[PHP_INT_MIN,'-92 233 720 368 547 758,08','-92233720368547758.08'],[PHP_INT_MAX,'92 233 720 368 547 758,07','92233720368547758.07']] as [$m,$ru,$uk])rcCheck(money($m)===$ru&&ukMoney($m)===$uk,'Exact minor units');
rcCheck(calendarDate('2024-02-29')==='2024-02-29'&&displayDate('2024-02-29')==='29.02.2024'&&displayDate(null)==='—','Date display');
foreach(['2026-02-29','29.02.2024','2026-13-01','2026-01-00'] as $s)rcDeny(fn()=>calendarDate($s));
rcCheck(h('Договор <script> & "')==='Договор &lt;script&gt; &amp; &quot;','UTF-8 escaping');
rcCheck(csvText(' =1+2')==="' =1+2"&&csvText('Договор')==='Договор','CSV injection');
rcCheck(str_contains(url('search',['q'=>'Договор & NDA']),'%26'),'UTF-8 URL');
rcCheck(count(migrationFiles())===SCHEMA_VERSION,'Frozen runtime migrations');
echo "OK · $n frozen RBAC/localization checks\n";
