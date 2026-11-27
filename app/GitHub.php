<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const GH_EVENTS = ['issues','pull_request','check_run','check_suite','status','push','repository','installation','installation_repositories','ping'];
const GH_MAX_LINKS = 100;
const GH_LEASE = 600;
const GH_ATTEMPTS = 8;

final class GitHubFailure extends \RuntimeException {
    public function __construct(public readonly int $http, public readonly int $retryAt=0) { parent::__construct('GitHub API: HTTP '.$http); }
}
function ghId(mixed $v): int {
    if (is_int($v) && $v>0) return $v;
    if (is_string($v) && preg_match('/\A[1-9][0-9]{0,18}\z/',$v) && (string)(int)$v===$v) return (int)$v;
    throw new Problem('Некорректный идентификатор GitHub.',422);
}
function ghName(mixed $v): string {
    if (!is_string($v)||!preg_match('~\A[A-Za-z0-9-]{1,100}/[A-Za-z0-9_.-]{1,100}\z~',$v)||in_array(substr($v,strpos($v,'/')+1),['.','..'],true)) throw new Problem('Некорректное имя репозитория.');
    return $v;
}
function ghB64(string $s): string { return rtrim(strtr(base64_encode($s),'+/','-_'),'='); }
function ghJwt(string $app,string $pem,?int $now=null): string {
    ghId($app); $now??=time();
    $key=openssl_pkey_get_private($pem);
    if (!$key || ($d=openssl_pkey_get_details($key))===false || $d['type']!==OPENSSL_KEYTYPE_RSA || $d['bits']<2048) throw new Problem('Требуется RSA-ключ GitHub App (2048+ бит).',503);
    $data=ghB64('{"alg":"RS256","typ":"JWT"}').'.'.ghB64(json_encode(['iat'=>$now-60,'exp'=>$now+480,'iss'=>$app],JSON_THROW_ON_ERROR));
    if (!openssl_sign($data,$signature,$key,OPENSSL_ALGO_SHA256)) throw new Problem('Не удалось подписать JWT.',503);
    return $data.'.'.ghB64($signature);
}
function ghSecret(): string {
    $secret=getenv('WORKSPACE_GITHUB_WEBHOOK_SECRET');
    if (!is_string($secret)||strlen($secret)<32||strlen($secret)>512) throw new Problem('Настройте секрет webhook GitHub (32+ байт).',503);
    return $secret;
}
function ghVerify(string $body,string $signature,string $secret): bool {
    return preg_match('/\Asha256=[a-f0-9]{64}\z/',$signature)===1 && hash_equals('sha256='.hash_hmac('sha256',$body,$secret),$signature);
}
/** Only the GitHub public REST origin is reachable; no redirects, URL from payload or token logging. */
function ghHttp(string $method,string $path,string $credential,?array $body=null): array {
    if (!preg_match('~\A/[A-Za-z0-9_./?=&%-]+\z~',$path) || preg_match('~(?:^|/)\.\.(?:/|$)~',$path) || !in_array($method,['GET','POST','DELETE'],true) || preg_match('/[\r\n]/',$credential)) throw new \LogicException('Invalid GitHub request');
    $ch=curl_init('https://api.github.com'.$path); $response=''; $headers=[];
    $options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json','X-GitHub-Api-Version: 2026-03-10','Authorization: Bearer '.$credential,'Content-Type: application/json'],CURLOPT_USERAGENT=>'CajeerWorkspace/'.VERSION,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>12,
        CURLOPT_WRITEFUNCTION=>static function($ch,string $part) use(&$response): int { if(strlen($response)+strlen($part)>8*1024*1024)return 0; $response.=$part;return strlen($part); },
        CURLOPT_HEADERFUNCTION=>static function($ch,string $line) use(&$headers): int { if(str_contains($line,':')) { [$k,$v]=explode(':',$line,2); $headers[strtolower(trim($k))]=trim($v); } return strlen($line); }];
    if ($body!==null) $options[CURLOPT_POSTFIELDS]=json_encode($body,JSON_THROW_ON_ERROR);
    curl_setopt_array($ch,$options);
    try { $ok=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); } finally { curl_close($ch); }
    if ($ok===false) throw new GitHubFailure(0);
    if ($status<200||$status>=300) {
        $retry=$status===429?time()+60:0;
        if (isset($headers['retry-after']) && asciiDigits($headers['retry-after'])) $retry=max($retry,time()+min(86400,(int)$headers['retry-after']));
        if (($headers['x-ratelimit-remaining']??null)==='0'&&asciiDigits($headers['x-ratelimit-reset']??'')) $retry=max($retry,min(time()+86400,(int)$headers['x-ratelimit-reset']));
        throw new GitHubFailure($status,$retry);
    }
    if ($status===204) return [];
    try { $data=json_decode($response,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING); } catch (\JsonException) { throw new GitHubFailure(502); }
    if (!is_array($data)) throw new GitHubFailure(502);
    return $data;
}
final class GitHubApp {
    private \Closure $http;
    public function __construct(private readonly string $app,private readonly string $pem,?callable $http=null) { ghId($app); $this->http=\Closure::fromCallable($http??__NAMESPACE__.'\\ghHttp'); }
    public static function configured(): self {
        $file=getenv('WORKSPACE_GITHUB_PRIVATE_KEY_FILE'); $app=getenv('WORKSPACE_GITHUB_APP_ID');
        $real=is_string($file)?realpath($file):false; $public=realpath(ROOT.'/public');
        if (!$real||!is_file($real)||!is_readable($real)||$real===$public||str_starts_with($real,$public.DIRECTORY_SEPARATOR)||filesize($real)>65536||!is_string($app)) throw new Problem('Настройте GitHub App ID и RSA-файл вне public.',503);
        $pem=file_get_contents($real); if($pem===false) throw new Problem('Ключ GitHub App недоступен.',503);
        ghJwt($app,$pem); return new self($app,$pem);
    }
    private function request(string $method,string $path,string $token,?array $body=null): array { return ($this->http)($method,$path,$token,$body); }
    /** Confirm installation belongs to this app, is selected and not suspended. Scope token to one immutable repository ID. */
    public function access(int $installation,int $repository,bool $contents=false): array {
        $jwt=ghJwt($this->app,$this->pem);
        $info=$this->request('GET','/app/installations/'.$installation,$jwt);
        if (ghId($info['id']??null)!==$installation||ghId($info['app_id']??null)!==(int)$this->app||($info['repository_selection']??'')!=='selected'||!array_key_exists('suspended_at',$info)||$info['suspended_at']!==null) throw new GitHubFailure(403);
        $requested=['metadata'=>'read','issues'=>'read','pull_requests'=>'read','checks'=>'read','statuses'=>'read'];
        if ($contents) $requested['contents']='read';
        $token=$this->request('POST','/app/installations/'.$installation.'/access_tokens',$jwt,['repository_ids'=>[$repository],'permissions'=>$requested]);
        $value=$token['token']??null; $expires=is_string($token['expires_at']??null)?strtotime($token['expires_at']):false;
        if (!is_string($value)||!preg_match('/\A[A-Za-z0-9._~+\/=\-]{10,4096}\z/',$value)||$expires===false||$expires<=time()+180||$expires>time()+3700) throw new GitHubFailure(502);
        $permissions=$token['permissions']??null;
        if (!is_array($permissions)) throw new GitHubFailure(502);
        foreach (array_keys($requested) as $permission) if (($permissions[$permission]??null)!=='read') throw new GitHubFailure(403);
        foreach($permissions as $permission=>$level) if($level!=='read') throw new GitHubFailure(403);
        $repos=$token['repositories']??[];
        if (!is_array($repos)||count($repos)!==1||ghId($repos[0]['id']??null)!==$repository) throw new GitHubFailure(403);
        $listing=$this->request('GET','/installation/repositories?per_page=100',$value);
        $selected=$listing['repositories']??null;
        if (($listing['total_count']??null)!==1||!is_array($selected)||count($selected)!==1||ghId($selected[0]['id']??null)!==$repository) throw new GitHubFailure(403);
        return ['name'=>ghName($selected[0]['full_name']??null),'repository'=>$repository,'token'=>$value,'expires'=>$expires];
    }
    /** Release reads are scoped to the repository returned by access(). */
    public function releaseRead(array $access,string $suffix): array {
        $base='/repos/'.implode('/',array_map('rawurlencode',explode('/',ghName($access['name']))));
        return $this->request('GET',$base.$suffix,$access['token']);
    }
    public function snapshot(array $access,string $kind,int $number): array {
        if (!in_array($kind,['issue','pull'],true)) throw new \LogicException('Invalid kind');
        $base='/repos/'.implode('/',array_map('rawurlencode',explode('/',ghName($access['name']))));
        $token=$access['token'];
        $row=$this->request('GET',$base.($kind==='issue'?'/issues/':'/pulls/').$number,$token);
        if (ghId($row['number']??null)!==$number||!in_array($row['state']??null,['open','closed'],true)||($kind==='issue'&&isset($row['pull_request']))) throw new GitHubFailure(422);
        $result=['state'=>$row['state'],'title'=>ghText($row['title']??'',500),'updated_at'=>ghText($row['updated_at']??'',80),'merged'=>false,'head_sha'=>null,'checks'=>[],'commit_status'=>null,'complete'=>true];
        if ($kind==='pull') {
            $sha=$row['head']['sha']??null;
            if (ghId($row['base']['repo']['id']??null)!==$access['repository']||!is_string($sha)||!preg_match('/\A[a-f0-9]{40}\z/',$sha)||!is_bool($row['merged']??null)) throw new GitHubFailure(502);
            $result['merged']=$row['merged']; $result['head_sha']=$sha;
            $seen=[]; $expected=null;
            for ($page=1;$page<=10;$page++) {
                $batch=$this->request('GET',$base.'/commits/'.$sha.'/check-runs?filter=latest&per_page=100&page='.$page,$token);
                $rows=$batch['check_runs']??null; $count=$batch['total_count']??null;
                if (!is_array($rows)||!array_is_list($rows)||count($rows)>100||!is_int($count)||$count<0) throw new GitHubFailure(502);
                $expected??=$count; if($count!==$expected) $result['complete']=false;
                foreach($rows as $check) {
                    if (!is_array($check)||($check['head_sha']??null)!==$sha) throw new GitHubFailure(502);
                    $id=ghId($check['id']??null); if(isset($seen[$id])){$result['complete']=false;continue;} $seen[$id]=true;
                    $result['checks'][]=['id'=>$id,'name'=>ghText($check['name']??'',300),'status'=>ghText($check['status']??'',40),'conclusion'=>ghText($check['conclusion']??'',40)];
                }
                if(count($rows)<100||count($seen)>=$count) break;
            }
            if(count($seen)!==$expected) $result['complete']=false;
            $status=$this->request('GET',$base.'/commits/'.$sha.'/status?per_page=1',$token);
            if (($status['sha']??null)!==$sha||!in_array($status['state']??null,['pending','success','failure'],true)) throw new GitHubFailure(502);
            $result['commit_status']=$status['state'];
            $current=$this->request('GET',$base.'/pulls/'.$number,$token);
            if (($current['head']['sha']??null)!==$sha || ($current['state']??null)!==$result['state'] || ($current['merged']??null)!==$result['merged']) throw new GitHubFailure(409);
        }
        return $result;
    }
}
function ghText(mixed $text,int $limit): string {
    if (!is_string($text)||!preg_match('//u',$text)) throw new GitHubFailure(502);
    // byte cap without cutting a UTF-8 sequence; never retain control characters or remote HTML.
    $text=preg_replace('/[\x00-\x1f\x7f]/u',' ',$text); if($text===null) throw new GitHubFailure(502);
    if(strlen($text)>$limit*4) $text=substr($text,0,$limit*4);
    while(!preg_match('//u',$text)) $text=substr($text,0,-1);
    return $text;
}

function ghQueue(PDO $db,array $repo,?array $link=null): void {
    if (!(int)$repo['active']) return;
    $args=[$repo['id'],$repo['generation']];
    $where=$link?'task_id=? AND link_version=?':'task_id IS NULL';
    if($link) array_push($args,$link['task_id'],$link['version']);
    // Coalesce pending work only. An event during a running fetch must create a new job.
    if(query($db,"SELECT 1 FROM github_jobs WHERE repository_binding=? AND generation=? AND $where AND state='pending' LIMIT 1",$args)->fetchColumn()) return;
    query($db,'INSERT INTO github_jobs(repository_binding,generation,task_id,link_version,available_at,created_at) VALUES(?,?,?,?,?,?)',[$repo['id'],$repo['generation'],$link['task_id']??null,$link['version']??null,time(),time()]);
}
function ghQueueRepository(PDO $db,array $repo,?string $kind=null,?int $number=null): void {
    ghQueue($db,$repo);
    $args=[$repo['id']]; $filter='';
    if($kind!==null&&$number!==null){$filter=' AND kind=? AND number=?';array_push($args,$kind,$number);}
    foreach(query($db,'SELECT task_id,version FROM github_task_links WHERE repository_binding=?'.$filter,$args)->fetchAll() as $link) ghQueue($db,$repo,$link);
}
function ghDeactivate(PDO $db,array $repo,string $reason): void {
    query($db,'UPDATE github_repositories SET active=0,generation=generation+1,last_error=?,checked_at=? WHERE id=?',[$reason,time(),$repo['id']]);
    query($db,"UPDATE github_jobs SET state='cancelled',claim=NULL,lease_until=NULL,finished_at=?,last_error=? WHERE repository_binding=? AND state IN ('pending','running')",[time(),$reason,$repo['id']]);
    query($db,"UPDATE github_task_links SET snapshot=NULL,synced_at=NULL,last_error=? WHERE repository_binding=?",[$reason,$repo['id']]);
}
/** Trusted operator, not a user-supplied installation ID from an OAuth callback. */
function ghBind(PDO $db,GitHubApp $app,int $project,int $installation,int $repository): int {
    $access=$app->access($installation,$repository);
    return atomic($db,function()use($db,$project,$installation,$repository,$access){
        entity($db,'SELECT id FROM projects WHERE id=?',[$project]);
        $other=query($db,'SELECT * FROM github_repositories WHERE repository_id=?',[$repository])->fetch();
        $old=query($db,'SELECT * FROM github_repositories WHERE project_id=?',[$project])->fetch();
        if($other&&(int)$other['project_id']!==$project) throw new Problem('Репозиторий уже закреплён за другим проектом.',409);
        if($old&&(int)$old['repository_id']!==$repository) throw new Problem('Смена репозитория требует отдельного переноса связей; существующее подключение не перезаписано.',409);
        if($old) {
            ghDeactivate($db,$old,'Подключение перепроверено');
            query($db,"UPDATE github_repositories SET installation_id=?,full_name=?,active=1,checked_at=?,last_error='' WHERE id=?",[$installation,$access['name'],time(),$old['id']]);
            $id=(int)$old['id'];
        } else $id=insertId($db,'INSERT INTO github_repositories(project_id,repository_id,installation_id,full_name,checked_at) VALUES(?,?,?,?,?)',[$project,$repository,$installation,$access['name'],time()]);
        ghQueueRepository($db,entity($db,'SELECT * FROM github_repositories WHERE id=?',[$id]));
        return $id;
    });
}
function ghManage(PDO $db,array $user,int $project,string $act,array $form): void {
    atomic($db,function() use($db,$user,$project,$act,$form){
        $u=organizationUser($db,$user); requireProjectRight($db,$u,$project,'connections');
        $role=projectRole($db,$u,$project);
        if($act==='github.sync') {
            if(!roleAllows($role,'manage')&&!roleAllows($role,'operations')) throw new Problem('Недостаточно полномочий для синхронизации.',403);
        } elseif($act==='github.disable') { orgOwner($db,$u); }
        else requireProjectRight($db,$u,$project,'manage');
        $repo=entity($db,'SELECT * FROM github_repositories WHERE project_id=?',[$project]);
        if($act==='github.disable') { if((int)$repo['active']){ghDeactivate($db,$repo,'Отключено владельцем');auditEvent($db,$u,$project,$act,(int)$repo['id']);}return; }
        if(!(int)$repo['active']) throw new Problem('Подключение отключено. Обратитесь к оператору.',409);
        if($act==='github.sync') { ghQueueRepository($db,$repo); auditEvent($db,$u,$project,$act,(int)$repo['id']);return; }
        if(!in_array($act,['github.link','github.unlink'],true)) throw new \LogicException('Invalid GitHub action');
        $task=numberId($form['task_id']??0); entity($db,'SELECT id FROM tasks WHERE project_id=? AND id=?',[$project,$task]);
        $old=query($db,'SELECT * FROM github_task_links WHERE task_id=? AND project_id=?',[$task,$project])->fetch();
        $version=workloadInt($form['version']??null,0,PHP_INT_MAX);
        if($act==='github.link') {
            $kind=choice($form['kind']??null,['issue'=>'','pull'=>'']);$number=ghId($form['number']??null);
            if($old && $old['kind']===$kind&&(int)$old['number']===$number) return;
        } elseif(!$old) return;
        if(($old?(int)$old['version']:0)!==$version) throw new Problem('Связь уже изменена. Обновите страницу.',409);
        query($db,"UPDATE github_jobs SET state='cancelled',claim=NULL,lease_until=NULL,finished_at=?,last_error='Связь изменена' WHERE repository_binding=? AND task_id=? AND state IN ('pending','running')",[time(),$repo['id'],$task]);
        if($act==='github.unlink') query($db,'DELETE FROM github_task_links WHERE task_id=?',[$task]);
        else {
            if(!$old && (int)query($db,'SELECT count(*) FROM github_task_links WHERE repository_binding=?',[$repo['id']])->fetchColumn()>=GH_MAX_LINKS) throw new Problem('Лимит: 100 связанных задач на репозиторий.',409);
            query($db,"INSERT INTO github_task_links(task_id,project_id,repository_binding,kind,number) VALUES(?,?,?,?,?) ON CONFLICT(task_id) DO UPDATE SET kind=excluded.kind,number=excluded.number,version=github_task_links.version+1,snapshot=NULL,synced_at=NULL,last_error=''",[$task,$project,$repo['id'],$kind,$number]);
            ghQueue($db,$repo,entity($db,'SELECT task_id,version FROM github_task_links WHERE task_id=?',[$task]));
        }
        leadInvalidateLink($db,$u,$project,$task);
        auditEvent($db,$u,$project,$act,$task);
    });
}
/** Verify raw bytes first, keep only event metadata; snapshots are always read from GitHub. */
function ghReceive(PDO $db,string $raw,string $signature,string $delivery,string $event,string $secret): string {
    if(strlen($raw)>2*1024*1024) throw new Problem('Webhook слишком большой.',413);
    if(!ghVerify($raw,$signature,$secret)) throw new Problem('Неверная подпись webhook.',403);
    if(!preg_match('/\A[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}\z/',$delivery)||!preg_match('/\A[a-z_]{1,40}\z/',$event)) throw new Problem('Некорректные заголовки webhook.',400);
    $delivery=strtolower($delivery);
    try{$payload=json_decode($raw,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\JsonException){throw new Problem('Некорректный JSON webhook.',400);}
    if(!is_array($payload)) throw new Problem('Некорректный webhook.',400);
    if(!in_array($event,GH_EVENTS,true)) return 'ignored';
    $installation=$event==='ping'?0:ghId($payload['installation']['id']??null);
    $hash=hash('sha256',$raw);
    return atomic($db,function() use($db,$payload,$event,$delivery,$installation,$hash){
        $old=query($db,'SELECT body_hash,event FROM github_deliveries WHERE delivery_id=?',[$delivery])->fetch();
        if($old) {if(!hash_equals($old['body_hash'],$hash)||$old['event']!==$event)throw new Problem('Конфликт идентификатора доставки.',409);return 'duplicate';}
        query($db,'INSERT INTO github_deliveries(delivery_id,body_hash,event,installation_id,received_at) VALUES(?,?,?,?,?)',[$delivery,$hash,$event,$installation,time()]);
        if($event==='ping') return 'accepted';
        $action=$payload['action']??'';
        if($event==='installation'&&!isset($payload['repository'])&&!isset($payload['repositories_removed'])&&in_array($action,['deleted','suspend'],true)) {
            foreach(query($db,'SELECT * FROM github_repositories WHERE installation_id=? AND active=1',[$installation])->fetchAll() as $r) ghDeactivate($db,$r,'GitHub: installation revoked or suspended');
        } elseif($event==='installation_repositories'&&$action==='removed') {
            foreach(($payload['repositories_removed']??[]) as $removed) {
                $id=ghId($removed['id']??null);$r=query($db,'SELECT * FROM github_repositories WHERE installation_id=? AND repository_id=? AND active=1',[$installation,$id])->fetch();
                if($r) ghDeactivate($db,$r,'GitHub: repository removed');
            }
        } elseif(!in_array($event,['installation','installation_repositories'],true)) {
            $id=ghId($payload['repository']['id']??null);$r=query($db,'SELECT * FROM github_repositories WHERE installation_id=? AND repository_id=? AND active=1',[$installation,$id])->fetch();
            if($r) {
                if($event==='repository'&&in_array($action,['deleted','transferred'],true)) ghDeactivate($db,$r,'GitHub: repository deleted or transferred');
                elseif(in_array($event,['issues','pull_request'],true)) {
                    $kind=$event==='issues'?'issue':'pull'; $object=$payload[$event==='issues'?'issue':'pull_request']??[];
                    ghQueueRepository($db,$r,$kind,ghId($object['number']??null));
                } else ghQueueRepository($db,$r);
            }
        }
        return 'accepted';
    });
}
function ghWebhook(PDO $db): never {
    if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'',2)[0]))!=='application/json') throw new Problem('Требуется application/json.',415);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>2*1024*1024) throw new Problem('Webhook слишком большой.',413);
    $raw=file_get_contents('php://input',false,null,0,2*1024*1024+1);if($raw===false)throw new Problem('Не удалось прочитать webhook.',400);
    $result=ghReceive($db,$raw,$_SERVER['HTTP_X_HUB_SIGNATURE_256']??'',$_SERVER['HTTP_X_GITHUB_DELIVERY']??'',$_SERVER['HTTP_X_GITHUB_EVENT']??'',ghSecret());
    http_response_code(202);header('Content-Type: application/json');echo json_encode(['status'=>$result],JSON_THROW_ON_ERROR);exit;
}

function ghClaim(PDO $db): ?array {
    return atomic($db,function()use($db){
        $now=time();
        foreach(query($db,"SELECT j.id,j.attempts,r.project_id FROM github_jobs j JOIN github_repositories r ON r.id=j.repository_binding WHERE j.state='running' AND j.lease_until<=?",[$now])->fetchAll() as $expired)relOutcome($db,(int)$expired['project_id'],'github',(int)$expired['id'],(int)$expired['attempts']>=GH_ATTEMPTS?'dead':'retry','lease_expired');
        query($db,"UPDATE github_jobs SET state=CASE WHEN attempts>=? THEN 'dead' ELSE 'pending' END,claim=NULL,lease_until=NULL,available_at=?,finished_at=CASE WHEN attempts>=? THEN ? ELSE NULL END,last_error='Истёк срок аренды worker' WHERE state='running' AND lease_until<=?",[GH_ATTEMPTS,$now,GH_ATTEMPTS,$now,$now]);
        // Do not allow two workers to publish out-of-order snapshots for the same repository.
        $job=query($db,"SELECT j.* FROM github_jobs j JOIN github_repositories r ON r.id=j.repository_binding WHERE j.state='pending' AND j.available_at<=? AND r.active=1 AND r.generation=j.generation AND NOT EXISTS(SELECT 1 FROM github_jobs x WHERE x.repository_binding=j.repository_binding AND x.state='running') ORDER BY j.id LIMIT 1",[$now])->fetch();
        if(!$job||!relRate($db,'github',$now))return null;
        $claim=bin2hex(random_bytes(24));
        query($db,"UPDATE github_jobs SET state='running',claim=?,lease_until=?,attempts=attempts+1 WHERE id=?",[$claim,$now+GH_LEASE,$job['id']]);
        return entity($db,'SELECT * FROM github_jobs WHERE id=?',[$job['id']]);
    });
}
function ghFinish(PDO $db,array $job,?array $access,?array $snapshot,?\Throwable $error=null): string {
    return atomic($db,function()use($db,$job,$access,$snapshot,$error){
        $current=entity($db,'SELECT * FROM github_jobs WHERE id=?',[$job['id']]);
        if($current['state']!=='running'||!hash_equals((string)$current['claim'],(string)$job['claim'])||(int)$current['lease_until']<=time())return 'superseded';
        $repo=entity($db,'SELECT * FROM github_repositories WHERE id=?',[$job['repository_binding']]);
        $link=$job['task_id']===null?null:query($db,'SELECT * FROM github_task_links WHERE repository_binding=? AND task_id=?',[$repo['id'],$job['task_id']])->fetch();
        $now=time();
        if(!(int)$repo['active']||(int)$repo['generation']!==(int)$job['generation']||($job['task_id']!==null&&(!$link||(int)$link['version']!==(int)$job['link_version']))) {
            query($db,"UPDATE github_jobs SET state='cancelled',claim=NULL,lease_until=NULL,finished_at=? WHERE id=?",[$now,$job['id']]); return 'cancelled';
        }
        if($access!==null) query($db,"UPDATE github_repositories SET full_name=?,checked_at=?,last_error='' WHERE id=?",[$access['name'],$now,$repo['id']]);
        if($error===null) {
            if($link) query($db,"UPDATE github_task_links SET snapshot=?,synced_at=?,last_error='' WHERE task_id=?",[json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$now,$link['task_id']]);
            query($db,"UPDATE github_jobs SET state='done',claim=NULL,lease_until=NULL,finished_at=?,last_error='' WHERE id=?",[$now,$job['id']]);relOutcome($db,(int)$repo['project_id'],'github',(int)$job['id'],'recovered','verified'); return 'done';
        }
        $status=$error instanceof GitHubFailure?$error->http:0;
        $retryAt=$error instanceof GitHubFailure?$error->retryAt:0;
        $reason=$status?'GitHub API: HTTP '.$status:'Ошибка проверки или транспорта GitHub';
        $org=(int)entity($db,'SELECT organization_id FROM projects WHERE id=?',[$repo['project_id']])['organization_id'];
        logsEnqueue($db,$org,(int)$repo['project_id'],'github.sync.failed',(int)$job['id']);
        // Access loss must hide all cached repository data. 404 on a linked item alone does not revoke the repository.
        if($access===null && $status===404) {ghDeactivate($db,$repo,'GitHub: installation or repository no longer accessible');relOutcome($db,(int)$repo['project_id'],'github',(int)$job['id'],'cancelled','access_lost'); return 'cancelled';}
        if($access===null) {
            query($db,'UPDATE github_repositories SET last_error=? WHERE id=?',[$reason,$repo['id']]);
            if(in_array($status,[401,403,404,422],true)&&$retryAt===0) query($db,'UPDATE github_task_links SET snapshot=NULL,synced_at=NULL,last_error=? WHERE repository_binding=?',[$reason,$repo['id']]);
        }
        if($link) {
            query($db,'UPDATE github_task_links SET last_error=? WHERE task_id=?',[$reason,$link['task_id']]);
            if(in_array($status,[401,403,404,422],true)&&$retryAt===0) query($db,'UPDATE github_task_links SET snapshot=NULL,synced_at=NULL WHERE task_id=?',[$link['task_id']]);
        }
        $dead=(int)$current['attempts']>=GH_ATTEMPTS||($access!==null&&in_array($status,[404,422],true));
        $delay=min(3600,30*(2**min(7,(int)$current['attempts']-1)))+random_int(0,15);
        query($db,'UPDATE github_jobs SET state=?,claim=NULL,lease_until=NULL,available_at=?,finished_at=?,last_error=? WHERE id=?',[$dead?'dead':'pending',max($now+$delay,$retryAt),$dead?$now:null,$reason,$job['id']]);
        // Respect a shared rate-limit response for other pending work on the same installation.
        if($retryAt>$now) query($db,"UPDATE github_jobs SET available_at=? WHERE state='pending' AND available_at<? AND repository_binding IN (SELECT id FROM github_repositories WHERE installation_id=?)",[$retryAt,$retryAt,$repo['installation_id']]);
        relOutcome($db,(int)$repo['project_id'],'github',(int)$job['id'],$dead?'dead':'retry',$status?'http_'.$status:'transport');
        return $dead?'dead':'retry';
    });
}
function ghWork(PDO $db,GitHubApp $app,int $limit=10): array {
    if($limit<1||$limit>100)throw new Problem('Количество заданий: 1–100.');
    $results=[];
    for($i=0;$i<$limit;$i++) {
        $job=ghClaim($db);if(!$job)break;
        $access=null;$snapshot=null;$error=null;
        try {
            $repo=entity($db,'SELECT * FROM github_repositories WHERE id=? AND active=1 AND generation=?',[$job['repository_binding'],$job['generation']]);
            $link=$job['task_id']===null?null:entity($db,'SELECT * FROM github_task_links WHERE repository_binding=? AND task_id=? AND version=?',[$repo['id'],$job['task_id'],$job['link_version']]);
            $access=$app->access((int)$repo['installation_id'],(int)$repo['repository_id']);
            if($link)$snapshot=$app->snapshot($access,$link['kind'],(int)$link['number']);
        } catch(\Throwable $e) { $error=$e; }
        $state=ghFinish($db,$job,$access,$snapshot,$error);
        $results[]=['id'=>(int)$job['id'],'state'=>$state];
    }
    return $results;
}
function ghManual(PDO $db,?int $project=null): int {
    return atomic($db,function()use($db,$project){
        $rows=$project===null?query($db,'SELECT * FROM github_repositories WHERE active=1')->fetchAll():[entity($db,'SELECT * FROM github_repositories WHERE project_id=? AND active=1',[$project])];
        foreach($rows as $repo)ghQueueRepository($db,$repo);
        return count($rows);
    });
}
function ghLinkData(array $row,array $repo): array {
    $row['data']=null;
    if((int)$repo['active'] && $row['snapshot']!==null) $row['data']=json_decode($row['snapshot'],true,64,JSON_THROW_ON_ERROR);
    unset($row['snapshot']);return $row;
}
function ghPage(PDO $db,array $user,int $project): array {
    requireProjectRight($db,$user,$project,'connections');$p=allowedProject($db,$user,$project);$role=projectRole($db,$user,$project);
    $repo=query($db,'SELECT * FROM github_repositories WHERE project_id=?',[$project])->fetch()?:null;
    $links=[];$jobs=[];$counts=[];
    if($repo) {
        foreach(query($db,'SELECT l.*,t.title task_title FROM github_task_links l JOIN tasks t ON t.id=l.task_id AND t.project_id=l.project_id WHERE l.repository_binding=? ORDER BY l.task_id',[$repo['id']])->fetchAll() as $row)$links[]=ghLinkData($row,$repo);
        $jobs=query($db,'SELECT id,task_id,state,attempts,available_at,created_at,finished_at,last_error FROM github_jobs WHERE repository_binding=? ORDER BY id DESC LIMIT 50',[$repo['id']])->fetchAll();
        $counts=array_column(query($db,'SELECT state,COUNT(*) count FROM github_jobs WHERE repository_binding=? GROUP BY state',[$repo['id']])->fetchAll(),'count','state');
    }
    return ['project'=>$p,'repository'=>$repo,'links'=>$links,'jobs'=>$jobs,'counts'=>$counts,'githubManage'=>roleAllows($role,'manage'),'githubSync'=>roleAllows($role,'manage')||roleAllows($role,'operations'),'githubOwner'=>$role==='owner'];
}
function ghTaskData(PDO $db,array $user,int $project,int $task): ?array {
    $role=projectRole($db,$user,$project);if(!roleAllows($role,'connections'))return null;
    $repo=query($db,'SELECT * FROM github_repositories WHERE project_id=?',[$project])->fetch();if(!$repo)return null;
    $link=query($db,'SELECT * FROM github_task_links WHERE task_id=? AND project_id=?',[$task,$project])->fetch();
    return ['repository'=>$repo,'link'=>$link?ghLinkData($link,$repo):null,'manage'=>roleAllows($role,'manage')];
}
