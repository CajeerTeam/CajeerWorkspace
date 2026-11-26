<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
require __DIR__.'/github_fixture.php';
$gh_checks=0;
foreach (['0','001','65535','999999999999999999999999999'] as $digits) gcheck(asciiDigits($digits),'ASCII decimal accepted');
foreach (['','-1','+1','1.0','1e3',' 1',"1\n","1\0",'１２','١٢'] as $digits) gcheck(!asciiDigits($digits),'Non-ASCII/non-decimal rejected');
[$pem,$public]=gpair();$now=time();$jwt=ghJwt('20',$pem,$now);
[$header,$payload,$sig]=explode('.',$jwt);
$decode=fn($v)=>base64_decode(strtr($v,'-_','+/'),true);
gcheck(json_decode($decode($header),true)['alg']==='RS256','JWT RS256');
$claims=json_decode($decode($payload),true);gcheck($claims===['iat'=>$now-60,'exp'=>$now+480,'iss'=>'20'],'JWT claims and expiry');
gcheck(openssl_verify($header.'.'.$payload,$decode($sig),$public,OPENSSL_ALGO_SHA256)===1,'RSA verifies');
gcheck(openssl_verify($header.'.'.$payload.'x',$decode($sig),$public,OPENSSL_ALGO_SHA256)!==1,'tampering fails');
$secret=str_repeat('s',32);$raw='{"installation":{"id":30}}';$signature=gsign($raw,$secret);
gcheck(ghVerify($raw,$signature,$secret),'valid webhook');gcheck(!ghVerify($raw.' ',$signature,$secret),'modified raw bytes rejected');gcheck(!ghVerify($raw,$signature,str_repeat('x',32)),'wrong secret');gcheck(!ghVerify($raw,'sha1='.str_repeat('a',40),$secret),'SHA1 denied');
gdeny(fn()=>ghId('9223372036854775808'),422,'overflow');gdeny(fn()=>ghId('0'),422,'zero');gdeny(fn()=>ghName('owner/..'),422,'traversal');gdeny(fn()=>ghName('https://example.com/repo'),422,'foreign API denied');
$f=new GitHubFixture();$app=new GitHubApp('20',$pem,[$f,'http']);$access=$app->access(30,40);gcheck($access['repository']===40&&$access['name']==='owner/example','selected installation and repo');
gcheck($f->calls[1][3]['repository_ids']===[40],'scoped token request');gcheck($f->calls[2][2]==='ghs_fixture_ephemeral_token','installation token not app JWT');
foreach(['all','suspended','wrongRepo','wrongScope','wrongPermissions','expired'] as $key){$f->$key=true;gdeny(fn()=>$app->access(30,40),$key==='expired'?502:403,$key.' rejected');$f->$key=false;}
$issue=$app->snapshot($access,'issue',7);gcheck($issue['state']==='open'&&$issue['head_sha']===null,'issue snapshot');
$pr=$app->snapshot($access,'pull',8);gcheck($pr['head_sha']===$f->sha&&count($pr['checks'])===2&&$pr['complete'],'PR checks exact SHA');gcheck($pr['checks'][1]['conclusion']===''&&$pr['commit_status']==='pending','pending is not success');
$f->sha=str_repeat('b',40);$pr2=$app->snapshot($access,'pull',8);gcheck($pr2['head_sha']!==$pr['head_sha'],'new PR SHA');
$f->checkCount=205;$pr=$app->snapshot($access,'pull',8);gcheck(count($pr['checks'])===205&&$pr['complete'],'pagination');
$f->checkCount=1001;$pr=$app->snapshot($access,'pull',8);gcheck(count($pr['checks'])===1000&&!$pr['complete'],'truncation explicitly incomplete');
$f->wrongCheckSha=true;gdeny(fn()=>$app->snapshot($access,'pull',8),502,'foreign check SHA');
gcheck(sensitiveAction('github.sync')&&sensitiveAction('github.link')&&sensitiveAction('github.disable'),'MFA');
echo "OK · $gh_checks проверок GitHub API/JWT/HMAC\n";
