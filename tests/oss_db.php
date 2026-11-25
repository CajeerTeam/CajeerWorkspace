<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
require ROOT.'/app/Web.php';
function oc(bool $ok,string $name): void { if (!$ok) throw new \RuntimeException($name); ++$GLOBALS['checks']; }
function deny(callable $f,int $status,string $name): void { try { $f(); } catch (Problem $e) { oc($e->status===$status,$name.' ('.$e->status.': '.$e->getMessage().')'); return; } throw new \RuntimeException('Unexpected success: '.$name); }
function okKey(): string { return bin2hex(random_bytes(16)); }
function oclean(string $dir): void { foreach (new \FilesystemIterator($dir) as $e) { if ($e->isDir()&&!$e->isLink()) oclean($e->getPathname()); else unlink($e->getPathname()); } rmdir($dir); }
$dir=sys_get_temp_dir().'/cws-oss-'.bin2hex(random_bytes(8)); mkdir($dir,0700); putenv('WORKSPACE_DATA='.$dir); putenv('WORKSPACE_DB=sqlite'); putenv('WORKSPACE_CONFIG'); $checks=0;
try {
    $db=database(true);
    addUser($db,'owner@example.test','PrivateOwner','owner','sample-password-only',true);
    addUser($db,'dev@example.test','PrivateDeveloper','developer','sample-password-only');
    $owner=organizationUser($db,['id'=>1]); $dev=organizationUser($db,['id'=>2]);
    $p=createProject($db,$owner,okKey(),['title'=>'PRIVATE_PROJECT','kind'=>'oss']); changeMembership($db,$owner,'project',$p,2,true);
    $other=createProject($db,$owner,okKey(),['title'=>'PRIVATE_OTHER','kind'=>'oss']);
    $client=createProject($db,$owner,okKey(),['title'=>'PRIVATE_CLIENT','kind'=>'client']);
    $cmd=fn($act,$form,$key=null)=>ossCommand($db,$owner,$key??okKey(),$p,$act,$form);
    $settings=['slug'=>'public-oss','upstream'=>'Example/Repo','public_title'=>'Public OSS','description'=>'Public description','published'=>'0','version'=>0];
    deny(fn()=>ossPublicData($db,'public-oss'),404,'not configured');
    deny(fn()=>ossCommand($db,$dev,okKey(),$p,'oss.settings',$settings),403,'developer cannot publish');
    deny(fn()=>ossCommand($db,$owner,okKey(),$client,'oss.settings',$settings),404,'non OSS denied');
    $key=okKey(); $cmd('oss.settings',$settings,$key); $cmd('oss.settings',$settings,$key); oc((int)ossConfig($db,$p)['version']===1,'settings retry');
    deny(fn()=>ossPublicData($db,'public-oss'),404,'private by explicit configuration');
    deny(fn()=>ossCommand($db,$owner,okKey(),$other,'oss.settings',$settings),409,'duplicate slug');
    $settings=array_replace($settings,['published'=>'1','version'=>1]); $cmd('oss.settings',$settings);
    deny(fn()=>$cmd('oss.settings',array_replace($settings,['version'=>2,'upstream'=>'other/repo'])),409,'immutable upstream');
    $m=saveMilestone($db,$owner,okKey(),$p,null,null,['title'=>'PRIVATE_MILESTONE','due_on'=>'2027-01-01']);
    $t=createTask($db,$owner,okKey(),$p,['title'=>'PRIVATE_TASK','result_customer'=>'PRIVATE_CUSTOMER','acceptance_criteria'=>'PRIVATE_CRITERIA','milestone_id'=>$m]);
    $foreign=createTask($db,$owner,okKey(),$other,['title'=>'PRIVATE_FOREIGN','result_customer'=>'Other','acceptance_criteria'=>'Other']);
    $cmd('oss.milestone',['milestone_id'=>$m,'version'=>0,'milestone_version'=>1,'public_title'=>'Roadmap public','description'=>'Public scope']);
    $pub=ossPublicData($db,'public-oss'); oc($pub['milestones'][0]['state']==='open'&&!str_contains(json_encode($pub),'PRIVATE_'),'explicit public snapshot');
    $k=okKey(); $code=ossIssue($db,$owner,$p,'External pseudonym',1,$k); deny(fn()=>ossIssue($db,$owner,$p,'External pseudonym',1,$k),409,'invite replay');
    oc(strlen($code)===64 && !str_contains(json_encode(query($db,'SELECT * FROM oss_contributors')->fetchAll()),$code),'token hash only');
    $c=ossRedeem($db,$code); $cid=(int)$c['id']; $grant=$c['grant']; deny(fn()=>ossRedeem($db,$code),404,'one time code');
    $c2=ossRedeem($db,ossIssue($db,$owner,$p,'Other author',1,okKey()));
    $external=fn($act,$form,$key=null)=>ossExternal($db,$cid,$grant,$key??okKey(),$act,$form);
    $base=['title'=>'PRIVATE_SUBMISSION','body'=>'PRIVATE_SUBMISSION_BODY','upstream_url'=>'https://github.com/Example/Repo/pull/7'];
    deny(fn()=>$external('oss.submit',array_replace($base,['upstream_url'=>'https://github.com/evil/repo/pull/7'])),422,'foreign repo denied');
    deny(fn()=>$external('oss.submit',array_replace($base,['upstream_url'=>'https://github.com/example/repo/pull/7?secret=1'])),422,'URL params denied');
    $k=okKey(); $con=$external('oss.submit',$base,$k); oc($external('oss.submit',$base,$k)===$con,'submission replay');
    deny(fn()=>$external('oss.submit',array_replace($base,['body'=>'changed']),$k),409,'changed replay');
    deny(fn()=>$external('oss.submit',$base),409,'duplicate URL');
    deny(fn()=>ossContributorPage($db,$c2,$con,1),404,'same project other contributor read');
    deny(fn()=>ossExternal($db,(int)$c2['id'],$c2['grant'],okKey(),'oss.reply',['id'=>$con,'body'=>'no']),404,'same project other contributor write');
    $k=okKey(); $external('oss.reply',['id'=>$con,'body'=>'PRIVATE_COMMENT'],$k); $external('oss.reply',['id'=>$con,'body'=>'PRIVATE_COMMENT'],$k);
    oc(count(ossThread($db,$p,$con))===1,'comment replay');
    $cmd('oss.comment',['id'=>$con,'body'=>'PRIVATE_REVIEW']);
    oc(count(ossContributorPage($db,$c,$con,1)['comments'])===2,'two party discussion');
    deny(fn()=>$cmd('oss.link',['task_id'=>$foreign,'version'=>1,'upstream_url'=>$base['upstream_url']]),404,'foreign task link denied');
    $cmd('oss.link',['task_id'=>$t,'version'=>1,'upstream_url'=>$base['upstream_url']]);
    deny(fn()=>$cmd('oss.triage',['id'=>$con,'version'=>1,'state'=>'accepted','task_id'=>$t,'public_note'=>'Public contribution']),409,'no direct acceptance');
    $cmd('oss.triage',['id'=>$con,'version'=>1,'state'=>'reviewing','task_id'=>$t]);
    deny(fn()=>$cmd('oss.triage',['id'=>$con,'version'=>2,'state'=>'accepted','task_id'=>$t,'public_note'=>'Public contribution']),409,'unfinished task denied');
    deny(fn()=>$cmd('oss.link',['task_id'=>$t,'version'=>2,'upstream_url'=>'https://github.com/example/repo/issues/8']),409,'linked URL immutable');
    $release=['milestone_id'=>$m,'milestone_version'=>1,'tag'=>'v1.0.0','title'=>'Public release','notes'=>'Public notes','contribution_ids'=>[$con]];
    deny(fn()=>$cmd('oss.release',$release),409,'open milestone denied');
    changeTask($db,$owner,okKey(),$t,2,'done');
    $cmd('oss.triage',['id'=>$con,'version'=>2,'state'=>'accepted','task_id'=>$t,'public_note'=>'Public contribution']);
    deny(fn()=>$cmd('oss.triage',['id'=>$con,'version'=>3,'state'=>'rejected']),409,'accepted final');
    changeMilestone($db,$owner,okKey(),$p,$m,1,'closed');
    $release['milestone_version']=2; $k=okKey(); $cmd('oss.release',$release,$k); $cmd('oss.release',$release,$k);
    deny(fn()=>$cmd('oss.release',$release),409,'unique release');
    $data=ossPublicData($db,'public-oss'); $json=json_encode($data,JSON_THROW_ON_ERROR);
    oc(count($data['releases'])===1 && count($data['releases'][0]['items'])===1 && $data['releases'][0]['items'][0]['public_note']==='Public contribution','release snapshot');
    oc(!str_contains($json,'PRIVATE_')&&!str_contains($json,'External pseudonym')&&!str_contains($json,'contributor_id')&&!str_contains($json,'project_id'),'public data isolated');
    oc($data['milestones'][0]['state']==='closed','public milestone closed');
    deny(fn()=>$cmd('oss.milestone',['milestone_id'=>$m,'version'=>2,'milestone_version'=>2,'public_title'=>'Changed','description'=>'Changed']),409,'published milestone immutable');
    $cmd('oss.revoke',['contributor_id'=>$cid]); deny(fn()=>$external('oss.reply',['id'=>$con,'body'=>'no']),404,'revocation on write');
    $new=ossRedeem($db,ossIssue($db,$owner,$p,'',1,okKey(),$cid)); deny(fn()=>ossContributor($db,$cid,$grant),404,'old grant revoked after reissue');
    oc((int)ossContributor($db,$cid,$new['grant'])['id']===$cid,'new grant valid');
    oc(count(ossContributorPage($db,$new,$con,1)['rows'])===1,'reissue preserves history');
    $cmd('oss.settings',array_replace($settings,['version'=>2,'published'=>'0'])); deny(fn()=>ossPublicData($db,'public-oss'),404,'disable public'); deny(fn()=>ossContributor($db,$cid,$new['grant']),404,'disable contributor');
    $cmd('oss.settings',array_replace($settings,['version'=>3,'published'=>'1'])); oc(ossPublicData($db,'public-oss')['releases']===$data['releases'],'release unchanged');
    $org=createOrganization($db,$owner,'Other org'); $otherOwner=organizationUser($db,['id'=>1],$org);
    deny(fn()=>ossAdminPage($db,$otherOwner,$p,null,1,''),404,'cross tenant admin denied');
    $page=ossAdminPage($db,$owner,$p,$con,1,''); oc(count($page['queue'])===1 && $page['selected']['state']==='accepted','maintainer queue');
    oc(sensitiveAction('oss.release')&&sensitiveAction('oss.invite'),'MFA policy');
    if (in_array('--postgres',$argv,true)) {
        $report=migrateToPostgres($dir.'/source.sqlite'); oc($report['tables']['oss_release_items']['rows']===1 && $report['tables']['oss_contributors']['rows']===2,'PostgreSQL transfer');
        $pg=postgresConnect(); oc(ossPublicData($pg,'public-oss')['releases'][0]['items'][0]['public_note']==='Public contribution','PostgreSQL public snapshot');
        deny(fn()=>ossContributor($pg,$cid,$grant),404,'PostgreSQL old grant');
        $fresh=ossRedeem($pg,ossIssue($pg,$owner,$p,'Postgres contributor',1,okKey())); oc((int)$fresh['id']>2,'PostgreSQL sequence'); $pg=null;
    }
    echo "OK · $checks проверок OSS\n";
} finally { $db=null; oclean($dir); }
