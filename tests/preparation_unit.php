<?php
declare(strict_types=1);namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
$n=0;function pc(bool $ok,string $label):void{if(!$ok)throw new \RuntimeException($label);++$GLOBALS['n'];}
function pf(callable $fn):void{try{$fn();}catch(Problem $e){pc(true,'rejected');return;}throw new \RuntimeException('expected rejection');}
$f=['number'=>'001','date'=>'2026-11-25','supplier'=>'Seller','customer'=>'Buyer','subject'=>'Service','terms'=>'Terms <script>'];
foreach(PREP_KINDS as $k=>$label){$t=prepPreset($k);pc(prepTemplateText($t)===$t,'template');$b=prepRender($t,$f);pc(str_contains($b,'Terms <script>')&&!str_contains($b,'{{'),'render');}
pf(fn()=>prepTemplateText('{{unknown}}'));pf(fn()=>prepRender(prepPreset('nda'),['date'=>'2026-02-30']+$f));pf(fn()=>prepRender(prepPreset('nda'),['subject'=>'{{terms}}']+$f));pf(fn()=>prepText("\x00"));pf(fn()=>prepText(str_repeat("line\n",251)));pc(prepText("a\r\nb")==="a\nb",'normalize');
pc(prepDiff("a\nb\nc","a\nx\nc")===[[' ','a'],['-','b'],['+','x'],[' ','c']],'diff');
$t=['id'=>1,'revision'=>1,'sha256'=>hash('sha256','t')];$p=['id'=>1,'revision'=>1,'sha256'=>hash('sha256','pdf')];pc(prepEvidence($t,'a',$p)!==prepEvidence($t,'b',$p),'body hash');pc(prepEvidence($t,'a',$p)!==prepEvidence($t,'a',['revision'=>2]+$p),'pdf version hash');pc(prepEvidence($t,'a',$p)!==prepEvidence(['revision'=>2]+$t,'a',$p),'template version hash');pc(sensitiveAction('prep.approve'),'mfa');echo "OK · $n проверок подготовки\n";
