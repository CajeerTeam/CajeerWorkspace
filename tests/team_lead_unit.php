<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
$n=0;function lcUnit(bool $b,string $label):void{if(!$b)throw new \RuntimeException($label);++$GLOBALS['n'];}
$now=1800000000;$sha=str_repeat('a',40);$link=['active'=>1,'kind'=>'pull','last_error'=>'','synced_at'=>$now-20,'snapshot'=>json_encode(['state'=>'open','merged'=>false,'head_sha'=>$sha]),'repository_binding'=>1,'generation'=>2,'version'=>3,'number'=>7];
$p=leadPin($link,$now);lcUnit($p!==null&&$p['head_sha']===$sha&&$p['pr_number']===7,'valid observation');lcUnit(leadSame($p,$p),'same SHA and identity');lcUnit(leadSame($p,array_map('strval',$p)),'PDO strings');
foreach(['active'=>0,'kind'=>'issue','last_error'=>'failed','synced_at'=>$now-901,'snapshot'=>'{}'] as $k=>$v)lcUnit(leadPin(array_replace($link,[$k=>$v]),$now)===null,'reject '.$k);
lcUnit(leadPin(array_replace($link,['synced_at'=>$now+1]),$now)===null,'future');lcUnit(leadPin(null,$now)===null,'no link');
foreach(['repository_binding'=>2,'generation'=>3,'link_version'=>4,'pr_number'=>8,'head_sha'=>str_repeat('b',40)] as $k=>$v)lcUnit(!leadSame($p,array_replace($p,[$k=>$v]))&&leadPinHash($p)!==leadPinHash(array_replace($p,[$k=>$v])),'changed '.$k);
lcUnit(leadDuration(0,3660)==='1 ч 1 мин','duration');lcUnit(leadDuration(10,0)==='0 ч 0 мин','clock backwards');lcUnit(sensitiveAction('lead.review')&&sensitiveAction('lead.decision')&&sensitiveAction('lead.wait'),'MFA');echo "OK · $n team lead unit checks\n";
