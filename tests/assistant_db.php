<?php
declare(strict_types=1);
namespace Workspace;
require __DIR__.'/assistant_unit.php';
function aiClean(string $dir):void {foreach(new \FilesystemIterator($dir) as $f){if($f->isDir()&&!$f->isLink())aiClean($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
$dir=sys_get_temp_dir().'/cw-ai-'.bin2hex(random_bytes(8));mkdir($dir,0700);putenv('WORKSPACE_DATA='.$dir);putenv('WORKSPACE_DB=sqlite');putenv('WORKSPACE_CONFIG='.$dir.'/connections.json');file_put_contents($dir.'/connections.json','{}');
try{
    $db=database(true);foreach(['owner','developer','viewer'] as $i=>$role)addUser($db,'member'.$i.'@example.test','NameNeverSent'.$i,$role,'sample-password-only',$i===0);
    $owner=organizationUser($db,['id'=>1]);$dev=organizationUser($db,['id'=>2]);$viewer=organizationUser($db,['id'=>3]);$key=fn()=>bin2hex(random_bytes(16));
    $p=createProject($db,$owner,$key(),['title'=>'Pilot','kind'=>'internal']);$private=createProject($db,$owner,$key(),['title'=>'PrivateClientNeverSent','kind'=>'client','client'=>'Private client']);
    changeMembership($db,$owner,'project',$p,2,true);changeMembership($db,$owner,'project',$p,3,true);
    $org2=createOrganization($db,$owner,'OtherOrgNeverSent');$owner2=organizationUser($db,['id'=>1,'organization_id'=>$org2]);$other=createProject($db,$owner2,$key(),['title'=>'TenantNeverSent','kind'=>'internal']);
    $task=insertId($db,"INSERT INTO tasks(project_id,title,assignee_id,due_on,result_customer,acceptance_criteria) VALUES(?,?,?,?,?,?)",[$p,'Проверка релиза <script>',2,'2026-12-01','SecretResultNeverSent','SecretCriteriaNeverSent']);
    $pre=insertId($db,'INSERT INTO tasks(project_id,title) VALUES(?,?)',[$p,'Dependency']);query($db,'INSERT INTO task_dependencies(project_id,task_id,prerequisite_id) VALUES(?,?,?)',[$p,$task,$pre]);
    $mil=insertId($db,'INSERT INTO milestones(project_id,title) VALUES(?,?)',[$p,'Milestone']);query($db,'INSERT INTO task_milestones(task_id,project_id,milestone_id) VALUES(?,?,?)',[$task,$p,$mil]);
    foreach([$private,$other] as $foreign)query($db,'INSERT INTO tasks(project_id,title) VALUES(?,?)',[$foreign,'Проверка ForeignNeverSent']);
    query($db,'INSERT INTO documents(project_id,title,kind) VALUES(?,?,?)',[$p,'DocumentNeverSent','contract']);
    $repo=insertId($db,'INSERT INTO github_repositories(project_id,repository_id,installation_id,full_name) VALUES(?,?,?,?)',[$p,40,30,'owner/repo-never-sent']);
    $sha=str_repeat('a',40);$e=json_encode(['ref'=>['commit_sha'=>$sha],'ci'=>['checks'=>[['status'=>'completed','conclusion'=>'success']],'commit_status'=>['state'=>'success']],'secret'=>'RawEvidenceNeverSent'],JSON_THROW_ON_ERROR);
    $rel=insertId($db,"INSERT INTO project_releases(project_id,repository_binding,repository_generation,tag,commit_sha,changelog,artifact_name,artifact_sha256,required_checks,responsible_id,creator_id,request_key,state,evidence,evidence_hash,verified_at,created_at) VALUES(?,?,1,'v1',?,'ChangelogNeverSent','artifact.zip',?,'[]',1,1,?,'verified',?,?,?,?)",[$p,$repo,$sha,str_repeat('b',64),$key(),$e,hash('sha256',$e),time()-2000,time()-3000]);
    $cfg=['key'=>'test-only-key','model'=>'test-model','projects'=>[$p=>true,$private=>true,$other=>true]];
    function aiDbChecks(\PDO $db,array $cfg,array $owner,array $dev,array $viewer,int $p,int $private,int $other,int $task,int $mil,int $rel,int $repo):void {
        aiCheck(array_keys(aiProjects($db,$dev,$cfg))===[$p],'only member project offered');aiDeny(fn()=>aiContext($db,$dev,$private,'overview','',$cfg),404);aiDeny(fn()=>aiContext($db,$owner,$other,'overview','',$cfg),404);aiDeny(fn()=>aiContext($db,$owner,$p,'overview','',array_replace($cfg,['projects'=>[]])),403);
        $c=aiContext($db,$dev,$p,'overview','',$cfg);$map=array_column($c['sources'],null,'ref');aiCheck(isset($map['T'.$task],$map['M'.$mil],$map['R'.$rel]),'all topics');aiCheck($map['T'.$task]['fields']['blocked']===true,'actual dependency');aiCheck($map['M'.$mil]['fields']['tasks']===1,'actual milestone totals');aiCheck($map['R'.$rel]['fields']['ci']==='historical_success','local evidence');
        $payload=aiPayload($c,'Question','test-model',time());foreach(['ForeignNeverSent','PrivateClientNeverSent','TenantNeverSent','DocumentNeverSent','ChangelogNeverSent','RawEvidenceNeverSent','SecretResultNeverSent','SecretCriteriaNeverSent','NameNeverSent','owner/repo-never-sent'] as $bad)aiCheck(!str_contains($payload['input'],$bad),'excluded '.$bad);
        $c=aiContext($db,$dev,$p,'tasks','проверка',$cfg);aiCheck(count($c['sources'])===1&&$c['sources'][0]['ref']==='T'.$task,'existing search and exact project');
        $v=aiContext($db,$viewer,$p,'overview','',$cfg);aiCheck(is_string($v['coverage']['releases'])&&!isset(array_column($v['sources'],null,'ref')['R'.$rel]),'viewer cannot see release');aiDeny(fn()=>aiContext($db,$viewer,$p,'releases','',$cfg),403);
        $called=0;$transport=function($payload,$apiKey)use(&$called,$task){++$called;aiCheck($apiKey==='test-only-key','server key');aiCheck(!str_contains($payload['input'],'NeverSent'),'outbound isolation');return aiFixture('Blocked [T'.$task.']',['T'.$task]);};$recheck=fn()=>organizationUser($db,$dev);
        $before=query($db,'SELECT * FROM tasks ORDER BY id')->fetchAll();$r=aiAsk($db,$dev,$p,'tasks','проверка','Question',$cfg,$recheck,$transport);aiCheck($called===1&&$r['links'][0]['href']===url('task',['id'=>$task]),'actual orchestration and link');aiCheck(query($db,'SELECT * FROM tasks ORDER BY id')->fetchAll()===$before,'no task mutation');
        $empty=aiAsk($db,$dev,$p,'tasks','no-match-xyz','Question',$cfg,$recheck,$transport);aiCheck($called===1&&!$empty['links'],'no API request for empty selection');
        $revoke=function($payload,$apiKey)use($db,$owner,$p,$task){changeProjectRole($db,$owner,$p,2,'viewer');return aiFixture('old data [T'.$task.']',['T'.$task]);};
        aiDeny(fn()=>aiAsk($db,$dev,$p,'overview','','Question',$cfg,$recheck,$revoke),409);changeProjectRole($db,$owner,$p,2,'inherit');
        $remove=function($payload,$apiKey)use($db,$owner,$p,$task){changeMembership($db,$owner,'project',$p,2,false);return aiFixture('old data [T'.$task.']',['T'.$task]);};aiDeny(fn()=>aiAsk($db,$dev,$p,'tasks','','Question',$cfg,$recheck,$remove),404);changeMembership($db,$owner,'project',$p,2,true);
        query($db,'UPDATE github_repositories SET active=0 WHERE id=?',[$repo]);$c=aiContext($db,$dev,$p,'releases','',$cfg);aiCheck($c['sources'][0]['fields']['ci']==='unknown'&&$c['sources'][0]['fields']['verified_at']===null,'binding revocation hides evidence');
        query($db,'UPDATE github_repositories SET active=1 WHERE id=?',[$repo]);
    }
    function aiPolicyDbChecks(\PDO $db,array $cfg,array $owner,array $dev,array $viewer,int $p,int $private,int $other,int $task):void {
        $org=(int)$owner['organization_id'];$key=fn()=>bin2hex(random_bytes(16));
        $_SESSION=[];aiDeny(fn()=>aiContext($db,$owner,$p,'budget','',$cfg),403);
        $form=['data_types'=>array_keys(AI_DATA),'model'=>'test-model','daily_quota'=>'2','retention_days'=>'2','version'=>'0'];
        aiDeny(fn()=>aiPolicySave($db,$owner,$form,$cfg),403);$_SESSION=['mfa_at'=>time(),'csrf'=>'test'];aiDeny(fn()=>aiPolicySave($db,$dev,$form,$cfg),403);
        putenv('WORKSPACE_AI_MODELS=approved-model');aiDeny(fn()=>aiPolicySave($db,$owner,array_replace($form,['model'=>'unapproved']),$cfg),422);
        aiPolicySave($db,$owner,array_replace($form,['model'=>'approved-model']),$cfg);$policy=aiPolicy($db,$org,$cfg);aiCheck($policy['model']==='approved-model'&&$policy['days']===2&&$policy['quota']===2,'stored policy');aiDeny(fn()=>aiPolicySave($db,$owner,$form,$cfg),409);aiCheck(aiPolicy($db,(int)organizationUser($db,['id'=>1],(int)query($db,'SELECT organization_id FROM projects WHERE id=?',[$other])->fetchColumn())['organization_id'],$cfg)['version']===0,'other organization isolated');
        $month=gmdate('Y-m');$budget=econCommand($db,$owner,$p,$key(),'econ.budget',['month'=>$month,'currency'=>'EUR','revenue'=>'100','labor'=>'10','direct'=>'5','version'=>0]);
        $c=aiContext($db,$owner,$p,'role','',$cfg);aiCheck($c['topic']==='budget'&&$c['sources'][0]['fields']['revenue']['variance_minor']===-10000,'domain budget variance');aiDeny(fn()=>aiContext($db,$viewer,$p,'budget','',$cfg),403);
        $_SESSION=[];aiDeny(fn()=>aiContext($db,$owner,$p,'budget','',$cfg),403);$_SESSION=['mfa_at'=>time(),'csrf'=>'test'];
        changeProjectRole($db,$owner,$p,2,'manager');$c=aiContext($db,$dev,$p,'role','',$cfg);aiCheck($c['topic']==='blockers'&&count($c['sources'])===1&&$c['sources'][0]['ref']==='T'.$task,'manager gets actual blocked task');changeProjectRole($db,$owner,$p,2,'inherit');
        $party=clientCommand($db,$owner,$key(),'client.party',['name'=>'PartyNeverSent']);$contact=clientCommand($db,$owner,$key(),'client.contact',['party_id'=>$party,'name'=>'ContactNeverSent','email'=>'client@example.test']);$order=clientCommand($db,$owner,$key(),'client.request',['project_id'=>$private,'contact_id'=>$contact,'title'=>'Order','request_text'=>'ScopeNeverSent']);
        clientCommand($db,$owner,$key(),'client.offer',['order_id'=>$order,'scope'=>'SecretNeverSent','currency'=>'EUR']);$offer=(int)query($db,'SELECT id FROM client_offers WHERE order_id=?',[$order])->fetchColumn();clientCommand($db,$owner,$key(),'client.stage',['order_id'=>$order,'offer_id'=>$offer,'version'=>1,'title'=>'Waiting stage','scope'=>'ScopeNeverSent','due_on'=>gmdate('Y-m-d'),'amount'=>'12']);
        $c=aiContext($db,$owner,$private,'role','',$cfg);aiCheck($c['topic']==='stages'&&$c['sources'][0]['fields']['offer_status']==='draft'&&!preg_match('/(?:Scope|Party|Contact|Secret)NeverSent/',aiPayload($c,'Question','test-model',time())['input']),'freelancer stage projection');
        $seller=invoiceEntitySave($db,$owner,['name'=>'Seller','registration'=>'REG','address'=>'Address']);$invoice=invoiceCreate($db,$owner,$p,$key(),['entity_id'=>$seller,'counterparty_id'=>$party,'currency'=>'EUR','due_on'=>gmdate('Y-m-d'),'terms'=>'Transfer']);invoiceChange($db,$owner,$p,$invoice,$key(),'invoice.line',['version'=>1,'description'=>'Service','quantity'=>1,'unit'=>'100']);invoiceChange($db,$owner,$p,$invoice,$key(),'invoice.issue',['version'=>2]);$yesterday=gmdate('Y-m-d',time()-86400);query($db,'UPDATE invoices SET due_on=?,issued_on=? WHERE id=?',[$yesterday,$yesterday,$invoice]);
        changeProjectRole($db,$owner,$p,2,'accountant');$c=aiContext($db,$dev,$p,'role','',$cfg);aiCheck($c['topic']==='discrepancies'&&$c['sources'][0]['fields']['outstanding_minor']===10000,'accountant project invoice discrepancy');changeProjectRole($db,$owner,$p,2,'inherit');
        $doc=storeDocument($db,$owner,$key(),$p,null,0,['title'=>'Contract','kind'=>'contract'],"%PDF-1.4\nfirst\n%%EOF");storeDocument($db,$owner,$key(),$p,$doc,1,[],"%PDF-1.4\nsecond\n%%EOF");
        $today=gmdate('Y-m-d');$f=['reference'=>'AI-AGR','entity_id'=>$seller,'counterparty_id'=>$party,'title'=>'Agreement','responsible_id'=>1,'seller_signer'=>'SignerNeverSent','seller_authority'=>'AuthorityNeverSent','buyer_signer'=>'BuyerNeverSent','buyer_authority'=>'AuthorityNeverSent','currency'=>'EUR','amount'=>'100','signed_on'=>$today,'starts_on'=>$today,'ends_on'=>gmdate('Y-m-d',time()+86400*60),'notice_on'=>gmdate('Y-m-d',time()+86400*30),'renewal_terms'=>'PrivateNeverSent','document'=>$doc.':1'];
        $cid=contractCommand($db,$owner,$p,$key(),'contract.create',$f);contractCommand($db,$owner,$p,$key(),'contract.revise',['id'=>$cid,'version'=>contractGet($db,$owner,$p,$cid)['version'],'document'=>$doc.':2','ends_on'=>gmdate('Y-m-d',time()+86400*90)]+$f);
        changeProjectRole($db,$owner,$p,2,'legal');$c=aiContext($db,$dev,$p,'role','',$cfg);aiCheck($c['topic']==='revisions'&&$c['sources'][0]['fields']['changes']['document_hash']===true&&!str_contains(aiPayload($c,'Question','test-model',time())['input'],'NeverSent'),'legal revision metadata without private terms');changeProjectRole($db,$owner,$p,2,'inherit');
        // Reserve before transport, including failures; no content history for other staff.
        query($db,'DELETE FROM ai_daily_usage WHERE organization_id=?',[$org]);query($db,'DELETE FROM login_attempts');$recheck=fn()=>organizationUser($db,$dev);
        $transport=function($payload,$apiKey)use($task){aiCheck($payload['model']==='approved-model','selected model used');return aiFixture('Blocked [T'.$task.']',['T'.$task]);};
        aiAsk($db,$dev,$p,'tasks','проверка','Question',$cfg,$recheck,$transport);aiCheck(count(aiHistory($db,$dev,$p,$cfg))===1&&!aiHistory($db,$viewer,$p,$cfg),'personal history');
        changeProjectRole($db,$owner,$p,2,'viewer');aiCheck(!aiHistory($db,$dev,$p,$cfg),'role revocation hides stored answer');changeProjectRole($db,$owner,$p,2,'inherit');
        aiDeny(fn()=>aiAsk($db,$dev,$p,'tasks','проверка','Question',$cfg,$recheck,fn()=>throw new Problem('Transport failure',503)),503);aiDeny(fn()=>aiAsk($db,$dev,$p,'tasks','проверка','Question',$cfg,$recheck,$transport),429);
        query($db,'UPDATE ai_history SET observed_at=?,expires_at=? WHERE organization_id=?',[time()-100,time()-1,$org]);aiCheck(aiPurge($db)===1,'retention purge');
        aiPolicySave($db,$owner,array_replace($form,['version'=>1,'data_types'=>['tasks'],'retention_days'=>0,'daily_quota'=>0]),$cfg);aiDeny(fn()=>aiContext($db,$owner,$p,'budget','',$cfg),403);aiDeny(fn()=>aiAsk($db,$dev,$p,'tasks','проверка','Question',$cfg,$recheck,$transport),429);putenv('WORKSPACE_AI_MODELS');
    }
    aiDbChecks($db,$cfg,$owner,$dev,$viewer,$p,$private,$other,$task,$mil,$rel,$repo);
    if(in_array('--postgres',$argv,true)){
        migrateToPostgres($dir.'/source.sqlite');$pg=postgresConnect();query($pg,'DELETE FROM login_attempts');aiDbChecks($pg,$cfg,$owner,$dev,$viewer,$p,$private,$other,$task,$mil,$rel,$repo);aiPolicyDbChecks($pg,$cfg,$owner,$dev,$viewer,$p,$private,$other,$task);$pg=null;
    }
    aiPolicyDbChecks($db,$cfg,$owner,$dev,$viewer,$p,$private,$other,$task);
    echo "OK · $n assistant cumulative checks (unit + database)\n";
}finally{$db=null;aiClean($dir);}
