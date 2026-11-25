<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
$n=0;function ac(bool $v):void{global $n;if(!$v)throw new \RuntimeException('Accounting unit failure');$n++;}function bad(callable $f):void{try{$f();}catch(Problem){ac(true);return;}throw new \RuntimeException('Expected denial');}
ac(accountingSigned('0')===0);ac(accountingSigned('-1,23')===-123);ac(accountingSigned('250.5')===25050);bad(fn()=>accountingSigned('1e4'));bad(fn()=>accountingSigned('1.001'));bad(fn()=>accountingSigned([]));ac(accountingAdd(10,-20)===-10);bad(fn()=>accountingAdd(PHP_INT_MAX,1));bad(fn()=>accountingAdd(-PHP_INT_MAX,-1));
$a=[['id'=>1,'code'=>'1000','kind'=>'asset'],['id'=>2,'code'=>'3000','kind'=>'equity'],['id'=>3,'code'=>'4000','kind'=>'income']];$p=accountingPairs($a,"EUR 1000 3000 10.25\nUSD 3000 1000 3");ac(count($p)===2&&$p[0]['amount']===1025&&$p[1]['credit']===1);ac(accountingPairs($a,'')===[]);bad(fn()=>accountingPairs($a,'EUR 1000 4000 1'));bad(fn()=>accountingPairs($a,'EUR 1000 1000 1'));bad(fn()=>accountingPairs($a,'XXX 1000 3000 1'));bad(fn()=>accountingPairs($a,'EUR 1000 3000 0'));
$s=['totals'=>array_fill_keys(CURRENCIES,['net'=>0])];$s['totals']['EUR']['net']=250;$e=array_fill_keys(CURRENCIES,0);$e['EUR']=1275;$e['USD']=-300;accountingExpected($a,$s,$p,$e);ac(true);$e['EUR']++;bad(fn()=>accountingExpected($a,$s,$p,$e));ac(sensitiveAction('accounting.approve'));echo "OK · $n accounting unit checks\n";
