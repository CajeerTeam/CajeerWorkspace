<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
$n=0;function tcheck(bool $ok):void{global $n;if(!$ok)throw new \RuntimeException('VAT unit failure');$n++;}function tdeny(callable $f):void{try{$f();}catch(Problem){tcheck(true);return;}throw new \RuntimeException('Expected denial');}
foreach([[10000,2000,12000],[4,0,4],[5,1,6],[1234,246,1480],[83333333333,16666666666,99999999999]] as [$net,$vat,$gross])tcheck(ukVatTotals($net)===compact('net','vat','gross'));
tdeny(fn()=>ukVatTotals(0));tdeny(fn()=>ukVatTotals(-1));tdeny(fn()=>ukVatTotals(83333333334));tdeny(fn()=>ukVatTotals(PHP_INT_MAX));
tcheck(ukVatNumber('gb 123 4567 89')==='123456789');tdeny(fn()=>ukVatNumber('GB000000000'));tdeny(fn()=>ukVatNumber('12345'));tdeny(fn()=>ukVatNumber(['123456789']));tcheck(ukMoney(1480)==='14.80');tcheck(sensitiveAction('tax.approve')&&sensitiveAction('invoice.credit'));echo "OK · $n UK VAT unit checks\n";
