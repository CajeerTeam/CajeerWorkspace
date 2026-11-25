<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
require __DIR__.'/github_fixture.php';
$gh_checks=0;
final class ReleaseFixture {
    public string $sha='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    public string $merge='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    public string $digest='cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    public bool $annotated=true,$wrongTag=false,$merged=true,$ancestor=true,$failed=false,$wrongCheckSha=false,$noChecks=false,$wrongAsset=false,$draft=false,$wrongNotes=false,$pendingStatus=false;
    public int $checkId=8;
    public function read(string $path): array {
        $s=$this->sha;$m=$this->merge;$t=str_repeat('d',40);$asset=['id'=>22,'name'=>'app.zip','digest'=>'sha256:'.($this->wrongAsset?str_repeat('e',64):$this->digest),'state'=>'uploaded','size'=>100];
        return match($path){
            '/git/ref/tags/v1'=>['ref'=>'refs/tags/v1','object'=>['type'=>$this->annotated?'tag':'commit','sha'=>$this->annotated?$t:($this->wrongTag?$m:$s)]],
            '/git/tags/'.$t=>['sha'=>$t,'object'=>['type'=>'commit','sha'=>$this->wrongTag?$m:$s]],
            '/pulls/7'=>['number'=>7,'base'=>['repo'=>['id'=>40]],'merged'=>$this->merged,'state'=>'closed','merge_commit_sha'=>$m,'head'=>['sha'=>str_repeat('f',40)]],
            '/compare/'.$m.'...'.$s.'?per_page=1'=>['status'=>$this->ancestor?'ahead':'diverged','merge_base_commit'=>['sha'=>$m],'base_commit'=>['sha'=>$m]],
            '/commits/'.$s.'/check-runs?filter=latest&per_page=100&page=1'=>['total_count'=>$this->noChecks?0:1,'check_runs'=>$this->noChecks?[]:[['id'=>$this->checkId,'name'=>'Build','head_sha'=>$this->wrongCheckSha?$m:$s,'status'=>'completed','conclusion'=>$this->failed?'failure':'success','app'=>['id'=>9]]]],
            '/commits/'.$s.'/status?per_page=1'=>['sha'=>$s,'total_count'=>$this->pendingStatus?1:0,'state'=>'pending'],
            '/releases/tags/v1'=>['id'=>21,'tag_name'=>'v1','draft'=>$this->draft,'published_at'=>'2026-01-01T00:00:00Z','body'=>$this->wrongNotes?'wrong':"Notes\nSecond"],
            '/releases/21/assets?per_page=100&page=1'=>[$asset], '/releases/assets/22'=>$asset,
            default=>throw new \RuntimeException('Unexpected path: '.$path)};
    }
    public function http(string $method,string $path,string $token,?array $body): array {
        if($path==='/app/installations/30')return ['id'=>30,'app_id'=>20,'repository_selection'=>'selected','suspended_at'=>null];
        if($path==='/app/installations/30/access_tokens'){
            if($body['repository_ids']!==[40]||($body['permissions']['contents']??null)!=='read')throw new \RuntimeException('Wrong scope');
            return ['token'=>'ghs_fixture_ephemeral_token','expires_at'=>gmdate('c',time()+3600),'permissions'=>$body['permissions'],'repositories'=>[['id'=>40,'full_name'=>'owner/example']]];
        }
        if($path==='/installation/repositories?per_page=100')return ['total_count'=>1,'repositories'=>[['id'=>40,'full_name'=>'owner/example']]];
        if(!str_starts_with($path,'/repos/owner/example/'))throw new \RuntimeException('Wrong repo');
        return $this->read(substr($path,strlen('/repos/owner/example')));
    }
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    $f=new ReleaseFixture();$r=['commit_sha'=>$f->sha,'tag'=>'v1','changelog'=>"Notes\nSecond",'artifact_name'=>'app.zip','artifact_sha256'=>$f->digest,'required_checks'=>'["Build"]'];$tasks=[['task_id'=>1,'task_version'=>1,'link_version'=>1,'pr_number'=>7]];
    $run=fn()=>releaseRemote([$f,'read'],$r,$tasks,40);$e=$run();gcheck($e['ref']['commit_sha']===$f->sha&&$e['tasks'][0]['pr']['merge_sha']===$f->merge&&$e['artifact']['sha256']===$f->digest,'full annotated chain');
    $f->annotated=false;gcheck($run()['ref']['ref_sha']===$f->sha,'lightweight tag');$f->annotated=true;
    foreach(['wrongTag','wrongCheckSha','noChecks','failed','wrongAsset','draft','wrongNotes','pendingStatus'] as $prop){$f->$prop=true;gdeny($run,409,$prop);$f->$prop=false;}
    foreach(['merged','ancestor'] as $prop){$f->$prop=false;gdeny($run,409,$prop);$f->$prop=true;}
    gdeny(fn()=>releaseRemote([$f,'read'],$r,$tasks,41),409,'foreign repo');
    gdeny(fn()=>releaseCI([$f,'read'],$f->sha,['Missing']),409,'required missing');
    foreach(['../v1','v1.lock','v1/2','v1..2','v1.',''] as $tag)gdeny(fn()=>releaseTag($tag),422,'bad tag');
    gdeny(fn()=>releaseSha('bad'),422,'bad SHA');
    gcheck(releaseChecks("Test\nBuild\nTest")===['Build','Test'],'required names sorted');
    gcheck(sensitiveAction('release.confirm'),'MFA required');
    [$pem]=gpair();$app=new GitHubApp('20',$pem,[$f,'http']);$access=$app->access(30,40,true);gcheck($app->releaseRead($access,'/pulls/7')['number']===7,'contents scope');
    echo "OK · $gh_checks проверок release remote\n";
}
