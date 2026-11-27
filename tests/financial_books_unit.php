<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
$n=0;function fbUnit(bool $ok):void{global $n;if(!$ok)throw new \RuntimeException('Financial books unit failed');$n++;}
$r=bookPayableControl(['EUR'=>['AP-LIAB'=>['closing'=>-8000]],'USD'=>['AP-LIAB'=>['closing'=>-1500]]],['totals'=>['EUR'=>8000,'USD'=>1500]]);fbUnit($r['EUR']['ok']&&$r['USD']['ok']);
$r=bookPayableControl(['EUR'=>['AP-LIAB'=>['closing'=>-8000]]],['totals'=>['EUR'=>7900,'USD'=>100]]);fbUnit(!$r['EUR']['ok']&&!$r['USD']['ok']);
$r=bookPayableControl(['EUR'=>['AP-LIAB'=>['closing'=>50]]],['totals'=>['EUR'=>-50]]);fbUnit(!$r['EUR']['ok']);
fbUnit(bookReportInteger('12345')===12345);fbUnit(bookReportInteger('-12345')===-12345);fbUnit(bookReportInteger(0)===0);
foreach(['999999999999999999999999','1.25',PHP_INT_MIN,'nan'] as $bad){try{bookReportInteger($bad);throw new \RuntimeException('Invalid amount accepted');}catch(Problem){fbUnit(true);}}
foreach(['books.assign','books.allocate','books.period','books.close','books.bill','books.pay','books.refund','books.cancel'] as $act)fbUnit(sensitiveAction($act));
echo "OK · $n financial books unit checks\n";
