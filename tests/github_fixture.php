<?php
declare(strict_types=1);
namespace Workspace;
function gcheck(bool $condition,string $name): void { if(!$condition) throw new \RuntimeException($name); ++$GLOBALS['gh_checks']; }
function gdeny(callable $fn,int $status,string $name): void {
    try{$fn();}catch(Problem|GitHubFailure $e){gcheck(($e instanceof Problem?$e->status:$e->http)===$status,$name.': '.$e->getMessage());return;}
    throw new \RuntimeException('Unexpected success: '.$name);
}
function gkey(): string { return bin2hex(random_bytes(16)); }
function gdelivery(): string { $h=bin2hex(random_bytes(16));return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }
function gsign(string $body,string $secret): string{return 'sha256='.hash_hmac('sha256',$body,$secret);}
function gpair(): array {
    $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
    if(!$key||!openssl_pkey_export($key,$pem))throw new \RuntimeException('RSA');
    return [$pem,openssl_pkey_get_details($key)['key']];
}
final class GitHubFixture {
    public array $calls=[];
    public string $sha='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    public string $issueState='open';
    public bool $suspended=false;
    public bool $all=false;
    public bool $wrongRepo=false;
    public bool $wrongScope=false;
    public bool $expired=false;
    public bool $wrongPermissions=false;
    public bool $wrongCheckSha=false;
    public int $checkCount=2;
    public ?int $failure=null;
    public ?int $objectFailure=null;
    public int $retryAt=0;
    public string $name='owner/example';
    public function http(string $method,string $path,string $token,?array $body): array {
        $this->calls[]=[$method,$path,$token,$body];
        if($this->failure!==null)throw new GitHubFailure($this->failure,$this->retryAt);
        if($method==='GET'&&$path==='/app/installations/30')return ['id'=>30,'app_id'=>20,'repository_selection'=>$this->all?'all':'selected','suspended_at'=>$this->suspended?'2026-01-01':null];
        if($method==='POST'&&$path==='/app/installations/30/access_tokens'){
            if(($body['repository_ids']??[])!==[40])throw new \RuntimeException('Unexpected repository scope');
            $permissions=['metadata'=>'read','issues'=>'read','pull_requests'=>'read','checks'=>'read','statuses'=>'read'];
            if(($body['permissions']??[])!==$permissions)throw new \RuntimeException('Unexpected permissions');
            if($this->wrongPermissions)$permissions['issues']='write';
            return ['token'=>'ghs_fixture_ephemeral_token','expires_at'=>gmdate('c',time()+($this->expired?30:3600)),'permissions'=>$permissions,'repositories'=>[['id'=>$this->wrongRepo?41:40,'full_name'=>$this->name]]];
        }
        if($token!=='ghs_fixture_ephemeral_token')throw new \RuntimeException('No installation token');
        if($path==='/installation/repositories?per_page=100')return ['total_count'=>$this->wrongScope?2:1,'repositories'=>[['id'=>40,'full_name'=>$this->name]]];
        if($this->objectFailure!==null)throw new GitHubFailure($this->objectFailure,$this->retryAt);
        $base='/repos/'.$this->name;
        if($path===$base.'/issues/7')return ['number'=>7,'state'=>$this->issueState,'title'=>'Issue <unsafe>','updated_at'=>'2026-01-01T00:00:00Z'];
        if($path===$base.'/pulls/8')return ['number'=>8,'state'=>'open','title'=>'PR <unsafe>','updated_at'=>'2026-01-01T00:00:00Z','merged'=>false,'head'=>['sha'=>$this->sha],'base'=>['repo'=>['id'=>40]]];
        if(preg_match('~^'.preg_quote($base,'~').'/commits/'.preg_quote($this->sha,'~').'/check-runs\?filter=latest&per_page=100&page=(\d+)$~',$path,$m)){
            $page=(int)$m[1];$rows=[];
            for($i=($page-1)*100;$i<min($page*100,$this->checkCount);$i++)$rows[]=['id'=>$i+1,'head_sha'=>$this->wrongCheckSha?str_repeat('f',40):$this->sha,'name'=>'test <'.$i.'>','status'=>$i===0?'completed':'in_progress','conclusion'=>$i===0?'success':null];
            return ['total_count'=>$this->checkCount,'check_runs'=>$rows];
        }
        if($path===$base.'/commits/'.$this->sha.'/status?per_page=1')return ['sha'=>$this->sha,'state'=>'pending'];
        throw new \RuntimeException('Unexpected API path: '.$path);
    }
}
