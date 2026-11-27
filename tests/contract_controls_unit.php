<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
$n=0;
function check(bool $b):void{if(!$b)throw new \RuntimeException('assertion failed');++$GLOBALS['n'];}
function reject(callable $fn):void{try{$fn();}catch(Problem $e){check($e->status===409);return;}throw new \RuntimeException('Expected rejection');}
$r=['seller_signer'=>'Alice','seller_authority'=>'Mandate 1','signed_on'=>'2026-01-15','starts_on'=>'2026-01-15','ends_on'=>'2026-03-15','notice_on'=>'2026-02-15','renewal_terms'=>'None','currency'=>'EUR','amount_minor'=>10000];
$r['party_snapshot']='{"seller":{"name":"Seller","registration":"A-1"}}';
$a=['party_digest'=>contractPartyDigest($r['party_snapshot'],'seller'),'side'=>'seller','signer'=>'Alice','basis'=>'Mandate 1','valid_from'=>'2026-01-15','valid_until'=>'2026-01-15','revoked_at'=>null];
contractAuthorityCheck($a,$r,'seller');check(true);
foreach(['side'=>'buyer','signer'=>'Bob','basis'=>'Mandate 2','valid_from'=>'2026-01-16','valid_until'=>'2026-01-14','revoked_at'=>1,'party_digest'=>str_repeat('f',64)] as $k=>$v)reject(fn()=>contractAuthorityCheck(array_replace($a,[$k=>$v]),$r,'seller'));
$d=contractTermsDelta(null,$r);check($d['before']===null&&$d['price_delta_minor']===null);
$d=contractTermsDelta($r,array_replace($r,['amount_minor'=>'15000','ends_on'=>'2026-04-15']));check($d['price_delta_minor']===5000);check($d['after']['ends_on']==='2026-04-15');check($d['before']['ends_on']==='2026-03-15');check($d['after']['amount_minor']===15000);
check(contractTermsDelta($r,array_replace($r,['currency'=>'PLN']))['price_delta_minor']===null);
check(contractTermsDelta($r,array_replace($r,['amount_minor'=>0]))['price_delta_minor']===-10000);
foreach(['contract.authority','contract.revoke','contract.activate','contract.control','contract.obligation'] as $act)check(sensitiveAction($act));
echo "OK · $n проверок полномочий и изменений договора\n";
