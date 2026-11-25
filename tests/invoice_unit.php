<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
$n=0;function ic(bool $ok):void{global $n;if(!$ok)throw new \RuntimeException('invoice unit failed');$n++;}
function denied(callable $fn):void{try{$fn();}catch(Problem){ic(true);return;}throw new \RuntimeException('expected denial');}
ic(invoiceLineAmount(3,2500)===7500);ic(invoiceLineAmount(1,INVOICE_LIMIT)===INVOICE_LIMIT);denied(fn()=>invoiceLineAmount(2,INVOICE_LIMIT));denied(fn()=>invoiceLineAmount(0,100));denied(fn()=>invoiceQuantity('100001'));denied(fn()=>moneyMinor('1.001'));ic(invoiceQuantity('100000')===100000);
$i=['state'=>'issued','total_minor'=>100000];$r=[['kind'=>'receipt','status'=>'posted','amount_minor'=>25000],['kind'=>'receipt','status'=>'posted','amount_minor'=>50000],['kind'=>'receipt','status'=>'pending','amount_minor'=>5000],['kind'=>'refund','status'=>'posted','amount_minor'=>10000],['kind'=>'refund','status'=>'pending','amount_minor'=>2000],['kind'=>'receipt','status'=>'cancelled','amount_minor'=>1000]];
$b=invoiceBalance($i,$r);ic($b===['received'=>75000,'refunded'=>10000,'pending_receipt'=>5000,'pending_refund'=>2000,'outstanding'=>35000]);ic(invoiceBalance($i,[])['outstanding']===100000);ic(invoiceBalance(['state'=>'void','total_minor'=>100000],[])['outstanding']===0);ic(invoiceNumber(['entity_id'=>3,'number_year'=>2026,'number_seq'=>1])==='3-2026-000001');ic(invoiceNumber(['number_seq'=>null])==='Черновик');ic(sensitiveAction('invoice.payment'));echo "OK · $n invoice unit checks\n";
