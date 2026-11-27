<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
$n=0;
function omCheck(bool $ok,string $why): void {global $n;if(!$ok)throw new \RuntimeException($why);++$n;}
function omReject(callable $fn): void {try{$fn();}catch(Problem){omCheck(true,'rejected');return;}throw new \RuntimeException('Expected rejection');}
foreach(['main','release/1.x','support/2026.1'] as $branch)omCheck(ossBranch($branch)===$branch,'branch');
foreach(['../main','main/','main//dev','HEAD','a..b','release/.hidden','release/x.lock','a?b','a%2fb','a b'] as $branch)omReject(fn()=>ossBranch($branch));
omCheck(ossLineSelection(['8:2','3:1'])===[3=>1,8=>2],'ordered version selection');
foreach([[],['1:1','1:2'],['1:0'],['x:1'],['1:1:1'],array_fill(0,11,'1:1')] as $raw)omReject(fn()=>ossLineSelection($raw));
$sha=str_repeat('a',40);$head=str_repeat('b',40);$merge=str_repeat('c',40);$repo=['repository_id'=>40,'full_name'=>'example/repo'];$line=['branch'=>'release/1.x'];$fixes=[['contribution_id'=>1,'pr_number'=>7],['contribution_id'=>2,'pr_number'=>8]];
$rows=[''=>['id'=>40,'private'=>false,'visibility'=>'public','full_name'=>'example/repo'],'/git/ref/tags/v1.0.1'=>['ref'=>'refs/tags/v1.0.1','object'=>['sha'=>$sha,'type'=>'commit']],'/git/ref/heads/release%2F1.x'=>['ref'=>'refs/heads/release/1.x','object'=>['sha'=>$head,'type'=>'commit']],'/releases/tags/v1.0.1'=>['id'=>4,'tag_name'=>'v1.0.1','draft'=>false,'published_at'=>'2026-01-01T00:00:00Z']];
foreach([7,8] as $pr)$rows['/pulls/'.$pr]=['number'=>$pr,'base'=>['ref'=>'release/1.x','repo'=>['id'=>40]],'state'=>'closed','merged'=>true,'merge_commit_sha'=>$merge];
foreach([[$sha,$head],[$merge,$sha]] as [$a,$b])$rows['/compare/'.$a.'...'.$b.'?per_page=1']=['status'=>'ahead','merge_base_commit'=>['sha'=>$a],'base_commit'=>['sha'=>$a]];
$run=fn($r)=>ossLineRemote(fn($path)=>$r[$path]??throw new \RuntimeException('Unexpected path '.$path),$repo,$line,'v1.0.1',$sha,$fixes);
omCheck($run($rows)===['github_release_id'=>4,'merges'=>[1=>$merge,2=>$merge]],'two PRs');
foreach([['','private',true],['','visibility','private'],['/releases/tags/v1.0.1','draft',true],['/pulls/7','merged',false],['/pulls/8','base',['ref'=>'main','repo'=>['id'=>40]]],['/pulls/7','base',['ref'=>'release/1.x','repo'=>['id'=>99]]],['/compare/'.$merge.'...'.$sha.'?per_page=1','status','diverged'],['/compare/'.$sha.'...'.$head.'?per_page=1','status','behind'],['/git/ref/tags/v1.0.1','object',['sha'=>$head,'type'=>'commit']]] as [$path,$key,$value]){$bad=$rows;$bad[$path][$key]=$value;omReject(fn()=>$run($bad));}
$reads=0;omReject(fn()=>ossLineRemote(function($path)use($rows,&$reads,$head){if($path==='/git/ref/tags/v1.0.1'&&++$reads===2)return ['ref'=>'refs/tags/v1.0.1','object'=>['sha'=>$head,'type'=>'commit']];return $rows[$path];},$repo,$line,'v1.0.1',$sha,$fixes));
omCheck(sensitiveAction('oss.line')&&sensitiveAction('oss.fix')&&sensitiveAction('oss.line.publish'),'MFA policy');
echo "OK · $n OSS maintenance checks\n";
