<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
$n=0;function ck(bool $b,string $label):void{global $n;if(!$b)throw new \RuntimeException($label);++$n;}
function deny(callable $f,string $label):void{try{$f();}catch(Problem){ck(true,$label);return;}throw new \RuntimeException('Expected denial: '.$label);}
ck(BOTS_UPSTREAM_VERSION==='1.0.2'&&BOTS_DELIVERY_API==='workspace-delivery-v1','pinned protocol');
ck(botsExternalId('123456789012345678901234')==='123456789012345678901234','no integer coercion');foreach(['0','001','-1','1e3','1/2','1234567890123456789012345',123] as $v)deny(fn()=>botsExternalId($v),'invalid external id');
$r=['remote_id'=>'workspace-'.str_repeat('a',32),'identity_hash'=>str_repeat('b',64),'target_revision'=>'2026-11-25T00:00:00.000Z','release_id'=>null,'release_hash'=>null,'message_hash'=>str_repeat('c',64),'remote_attempts'=>1];
ck(botsPreviewHash($r)===hash('sha256',json_encode([$r['remote_id'],$r['identity_hash'],$r['target_revision'],null,null,$r['message_hash']],JSON_THROW_ON_ERROR)),'legacy preview unchanged');
ck(botsPreviewHash($r,['support_id'=>1,'support_version'=>1])!==botsPreviewHash($r,['support_id'=>1,'support_version'=>2]),'support version hash');
ck(botsPreviewHash($r,['support_id'=>1,'support_version'=>1])!==botsPreviewHash($r,['support_id'=>2,'support_version'=>1]),'support identity hash');
$c=['community_id'=>'c','account_id'=>'a','provider'=>'telegram','channel_id'=>'-100123456'];$remote=['id'=>$r['remote_id'],'communityId'=>'c','accountId'=>'a','provider'=>'telegram','channelId'=>$c['channel_id'],'sha256'=>$r['message_hash'],'revision'=>$r['target_revision'],'status'=>'sent','attempts'=>2,'providerMessageId'=>'123'];
ck(botsReceipt($c,$r,$remote)===['sent',2,'123'],'valid receipt');foreach(['channelId'=>'-100123457','sha256'=>str_repeat('d',64),'attempts'=>0,'providerMessageId'=>null,'status'=>'pending'] as $k=>$v)deny(fn()=>botsReceipt($c,$r,array_replace($remote,[$k=>$v])),'receipt mismatch '.$k);
$detail=json_decode(botsReceiptDetail($r,'sent',2,'123'),true,16,JSON_THROW_ON_ERROR);ck($detail['provider_message_id']==='123'&&$detail['attempts']===2&&!isset($detail['message']),'metadata receipt');
foreach(['uncertain','failed','retryable'] as $state)ck(botsQueueReason(['state'=>$state],1000)!=='','manual queue '.$state);
ck(botsQueueReason(['state'=>'sending','state_at'=>100],1000)!=='','stalled sending');ck(botsQueueReason(['state'=>'pending','state_at'=>101],1000)==='','fresh pending');ck(botsQueueReason(['state'=>'processing','lease_until'=>1000],1000)!=='','expired claim');ck(botsQueueReason(['state'=>'processing','lease_until'=>1001],1000)==='','active claim');ck(botsQueueReason(['state'=>'sent'],1000)==='','sent not pending');ck(sensitiveAction('bots.feedback'),'MFA feedback');echo "OK · $n Bots support checks\n";
