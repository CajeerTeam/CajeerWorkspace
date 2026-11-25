<?php
declare(strict_types=1);
namespace Workspace;
require __DIR__.'/../app/bootstrap.php';
$n=0;
function icheck(bool $ok,string $why):void{global $n;if(!$ok)throw new \RuntimeException($why);$n++;}
function ideny(callable $fn,string $why):void{try{$fn();}catch(Problem){icheck(true,$why);return;}throw new \RuntimeException($why);}
const IW='12345678-1234-1234-1234-123456789abc';const IA='abcdef01-1234-1234-1234-123456789abc';
function infraFixture(string $method,string $url,array $headers,?array $form):array{
    if($method==='GET') {icheck($url==='https://servers.example.test/api/v1/inventory/assets/'.IA&&$headers===['Cookie: session=sample-secret-cookie'],'server request');return ['data'=>['id'=>IA,'workspace_id'=>IW,'name'=>'Node A','status'=>'active','type'=>'server','environment'=>'prod','version'=>1,'ip_addresses'=>['192.0.2.2','invalid'],'attributes'=>['private'=>'hidden']]];}
    icheck(str_contains($url,'/v2/data?action=getData')&&hash_equals(md5($form['request_time'].md5('sample-panel-key')),$form['request_token']),'panel auth');
    if($form['table']==='sites')return ['data'=>[['id'=>1,'name'=>'one.example.test','status'=>'1','ssl'=>['notAfter'=>'2027-01-01','issuer'=>'CA','dns'=>['one.example.test'],'key'=>'not saved']],['id'=>2,'name'=>'two.example.test','status'=>'0','ssl'=>-1]]];
    if($form['table']==='domain')return ['data'=>[['id'=>4,'pid'=>1,'name'=>'one.example.test','port'=>443],['id'=>5,'pid'=>2,'name'=>'two.example.test','port'=>80],['id'=>6,'pid'=>99,'name'=>'unbound.example.test','port'=>80]]];
    throw new \RuntimeException('unexpected route');
}
putenv('WORKSPACE_TEST_PANEL=sample-panel-key');putenv('WORKSPACE_TEST_SERVERS=session=sample-secret-cookie');
$pc=['base'=>'https://panel.example.test','env'=>'WORKSPACE_TEST_PANEL'];$sc=['base'=>'https://servers.example.test','env'=>'WORKSPACE_TEST_SERVERS'];
$p=infraPanel($pc,'v2',__NAMESPACE__.'\\infraFixture');$s=infraServers($sc,IW,IA,__NAMESPACE__.'\\infraFixture');
icheck(count($p['sites'])===2&&$p['sites'][0]['domains']===[['name'=>'one.example.test','port'=>443]],'domains grouped');
icheck($p['sites'][0]['certificate']['expires']==='2027-01-01'&&!str_contains(json_encode($p),'not saved'),'cert metadata only');
icheck($p['sites'][1]['certificate']['state']==='unknown','unknown not absent');icheck($s['ips']===['192.0.2.2']&&!isset($s['attributes']),'server allowlist');
ideny(fn()=>infraServers($sc,IA,IA,__NAMESPACE__.'\\infraFixture'),'workspace mismatch');
ideny(fn()=>infraPanel($pc,'v2',fn()=>['data'=>[['id'=>1,'name'=>'../evil']]]),'unsafe site');
ideny(fn()=>infraPanel($pc,'v2',fn()=>['data'=>array_fill(0,25,['id'=>1,'name'=>'one.test'])]),'duplicate page');
ideny(fn()=>infraPanel($pc,'v2',fn()=>['status'=>false,'data'=>[]]),'provider error');
ideny(fn()=>infraCert(['notAfter'=>'2027-02-30']),'invalid date');
icheck(sensitiveAction('infra.bind')&&sensitiveAction('infra.owner')&&sensitiveAction('infra.sync'),'MFA');
// Exercise paging, including domain rows outside the selected PHP/WP inventory.
$pages=[];$r=infraPanel($pc,'legacy',function($m,$url,$h,$f)use(&$pages){$pages[]=[$f['table'],$f['p']];icheck(str_contains($url,'/data?action=getData')&&!str_contains($url,'/v2/'),'legacy');if($f['table']==='domain')return ['data'=>[]];$list=[];if($f['p']===1)for($i=1;$i<=25;$i++)$list[]=['id'=>$i,'name'=>'site'.$i.'.test'];return ['data'=>$list];});
icheck(count($r['sites'])===25&&$pages===[['sites',1],['sites',2],['domain',1]],'pagination');
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__)echo "OK · $n infrastructure unit checks\n";
