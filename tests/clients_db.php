<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
require ROOT.'/app/Web.php';
function ccheck(bool $ok,string $label): void { if (!$ok) throw new \RuntimeException($label); ++$GLOBALS['checks']; }
function creject(callable $call,int $status,string $label): void { try { $call(); } catch (Problem $e) { ccheck($e->status===$status,$label.' ('.$e->status.')'); return; } throw new \RuntimeException('Unexpected success: '.$label); }
function ck(): string { return bin2hex(random_bytes(16)); }
function cclean(string $dir): void { foreach (new \FilesystemIterator($dir) as $e) { if ($e->isDir()&&!$e->isLink()) cclean($e->getPathname()); else unlink($e->getPathname()); } rmdir($dir); }
$dir=sys_get_temp_dir().'/cws-clients-'.bin2hex(random_bytes(8)); mkdir($dir,0700); putenv('WORKSPACE_DATA='.$dir); putenv('WORKSPACE_DB=sqlite'); putenv('WORKSPACE_CONFIG'); putenv('WORKSPACE_OIDC_CLIENT_ID=test-workspace'); $checks=0;
try {
    $db=database(true);
    addUser($db,'owner@example.test','Owner','owner','sample-password-only',true);
    addUser($db,'dev@example.test','Developer','developer','sample-password-only');
    $owner=organizationUser($db,['id'=>1]); $dev=organizationUser($db,['id'=>2]);
    $p=createProject($db,$owner,ck(),['title'=>'Client project','kind'=>'client']); changeMembership($db,$owner,'project',$p,2,true);
    $p2=createProject($db,$owner,ck(),['title'=>'Private project','kind'=>'client']);
    $t=createTask($db,$owner,ck(),$p,['title'=>'Implement','result_customer'=>'Client','acceptance_criteria'=>'Working','assignee'=>'2']);
    $t2=createTask($db,$owner,ck(),$p2,['title'=>'Private','result_customer'=>'Other','acceptance_criteria'=>'Working']);
    $pdf="%PDF-1.4\nexample\n%%EOF"; $doc=storeDocument($db,$owner,ck(),$p,null,0,['title'=>'Proposal','kind'=>'contract'],$pdf);
    $cmd=fn($act,$data,$key=null)=>clientCommand($db,$owner,$key??ck(),$act,$data);
    $k=ck(); $party=$cmd('client.party',['name'=>'Acme'],$k); ccheck($cmd('client.party',['name'=>'Acme'],$k)===$party,'idempotent party');
    creject(fn()=>$cmd('client.party',['name'=>'Changed'],$k),409,'changed replay');
    $contact=$cmd('client.contact',['party_id'=>$party,'name'=>'Alice','email'=>'alice@example.test']);
    creject(fn()=>$cmd('client.contact',['party_id'=>$party,'name'=>'Same','email'=>'Alice@example.test']),409,'duplicate email');
    $order=$cmd('client.request',['project_id'=>$p,'contact_id'=>$contact,'title'=>'Order','request_text'=>"Request\nDetails"]);
    $other=$cmd('client.request',['project_id'=>$p,'contact_id'=>$contact,'title'=>'Other order','request_text'=>'Other']);
    $cmd('client.contact',['party_id'=>$party,'contact_id'=>$contact,'version'=>1,'name'=>'Alice changed','email'=>'new@example.test']);
    ccheck(clientOrder($db,$owner,$order)['contact_email']==='alice@example.test','order contact snapshot');
    creject(fn()=>clientCommand($db,$dev,ck(),'client.party',['name'=>'No']),403,'developer denied');
    $org=createOrganization($db,$owner,'Second org'); $second=organizationUser($db,['id'=>1],$org);
    creject(fn()=>clientOrder($db,$second,$order),404,'foreign order denied');
    creject(fn()=>clientCommand($db,$second,ck(),'client.contact',['party_id'=>$party,'name'=>'Foreign','email'=>'a@example.test']),404,'foreign counterparty denied');
    $cmd('client.offer',['order_id'=>$order,'scope'=>'Offer 1','currency'=>'EUR']);
    $offer=(int)query($db,'SELECT id FROM client_offers WHERE order_id=?',[$order])->fetchColumn();
    $common=['order_id'=>$order,'offer_id'=>$offer];
    creject(fn()=>$cmd('client.publish',$common+['version'=>1]),409,'empty estimate');
    $stageForm=['title'=>'Stage','scope'=>'Accepted scope','due_on'=>'2026-12-01','amount'=>'100.50'];
    $cmd('client.stage',$common+['version'=>1]+$stageForm);
    creject(fn()=>$cmd('client.stage',$common+['version'=>1]+$stageForm),409,'stale estimate');
    $cmd('client.publish',$common+['version'=>2]);
    creject(fn()=>$cmd('client.stage',$common+['version'=>3]+$stageForm),409,'published immutable');
    $token=issueGuest($db,$owner,$p,$doc,1,'alice@example.test',24,$order);
    $g=atomic($db,fn()=>oidcPrincipal($db,['sub'=>'client','email'=>'alice@example.test','email_verified'=>true],hash('sha256',$token)))['guest'];
    $session=str_repeat('a',64); query($db,'INSERT INTO oidc_sessions(handle,issuer,subject,user_id,guest_id,created_at,expires_at) VALUES(?,?,?,?,?,?,?)',[$session,ID_ISSUER,'client',null,$g,time(),time()+3600]);
    ccheck((int)guestClientOrder($db,$g)['id']===$order,'explicit order binding');
    $cmd('client.offer',['order_id'=>$other,'scope'=>'Secret','currency'=>'USD']);
    $otherOffer=(int)query($db,'SELECT id FROM client_offers WHERE order_id=?',[$other])->fetchColumn();
    creject(fn()=>clientGuestDecision($db,$g,'guest.offer',$otherOffer,1,true,'',$session),404,'same project other order denied');
    creject(fn()=>clientGuestDecision($db,$g,'guest.offer',$offer,3,true,'',str_repeat('b',64)),403,'invalid session');
    clientGuestDecision($db,$g,'guest.offer',$offer,3,false,'Revise',$session);
    $cmd('client.offer',['order_id'=>$order,'scope'=>'Offer 2','currency'=>'EUR']);
    $offer2=(int)query($db,'SELECT id FROM client_offers WHERE order_id=? AND revision=2',[$order])->fetchColumn(); $common=['order_id'=>$order,'offer_id'=>$offer2];
    $cmd('client.stage',$common+['version'=>1]+$stageForm);
    $cmd('client.publish',$common+['version'=>2]); clientGuestDecision($db,$g,'guest.offer',$offer2,3,true,'Agreed',$session);
    clientGuestDecision($db,$g,'guest.offer',$offer2,3,true,'Agreed',$session); ccheck(clientOffer($db,$order,$offer2)['status']==='accepted','offer replay');
    $sid=(int)query($db,'SELECT id FROM client_stages WHERE offer_id=?',[$offer2])->fetchColumn(); $sc=['order_id'=>$order,'stage_id'=>$sid];
    creject(fn()=>$cmd('client.task',$sc+['task_id'=>$t2]),404,'foreign task denied');
    $cmd('client.task',$sc+['task_id'=>$t]); creject(fn()=>$cmd('client.deliver',$sc+['result_text'=>'Result']),409,'unfinished task denied');
    $week='2024-12-30'; $sheet=saveTime($db,$dev,ck(),$p,$week,0,['task_id'=>$t,'worked_on'=>$week,'minutes'=>'30','note'=>'Work']);
    // Force 2 and 10 to distinguish numeric from lexicographic ordering.
    query($db,'UPDATE time_entries SET id=2 WHERE sheet_id=?',[$sheet]);
    saveTime($db,$dev,ck(),$p,$week,2,['task_id'=>$t,'worked_on'=>'2024-12-31','minutes'=>'60','note'=>'Work 2']);
    query($db,'UPDATE time_entries SET id=10 WHERE sheet_id=? AND worked_on=?',[$sheet,'2024-12-31']);
    submitTime($db,$dev,ck(),$p,$sheet,3); decideTime($db,$owner,ck(),$p,$sheet,4,true,'Approved');
    changeTask($db,$dev,ck(),$t,1,'done');
    $cmd('client.deliver',$sc+['result_text'=>'First result','document'=>$doc.':1']);
    $did=(int)query($db,'SELECT id FROM client_deliveries WHERE stage_id=?',[$sid])->fetchColumn();
    creject(fn()=>$cmd('client.task',$sc+['task_id'=>$t,'remove'=>'1']),409,'scope locked');
    clientGuestDecision($db,$g,'guest.delivery',$did,1,false,'Fix it',$session);
    $cmd('client.deliver',$sc+['result_text'=>'Second result','document'=>$doc.':1']); $did2=(int)query($db,'SELECT id FROM client_deliveries WHERE stage_id=? AND revision=2',[$sid])->fetchColumn();
    clientGuestDecision($db,$g,'guest.delivery',$did2,2,true,'Accepted',$session); clientGuestDecision($db,$g,'guest.delivery',$did2,2,true,'Accepted',$session);
    ccheck((int)query($db,'SELECT accepted_minutes FROM client_deliveries WHERE id=?',[$did2])->fetchColumn()===90,'approved time snapshot');
    ccheck((int)$db->query('SELECT COUNT(*) FROM client_stage_hours')->fetchColumn()===2,'no duplicate hours');
    ccheck((int)$db->query('SELECT COUNT(*) FROM cash_entries')->fetchColumn()===0,'no automatic cash operations');
    $details=clientOrderDetails($db,guestClientOrder($db,$g),true); ccheck(count($details)===2 && $details[0]['total']===10050 && !isset($details[0]['stages'][0]['tasks']),'guest published scope only');
    $_SESSION=['mfa_at'=>time(),'csrf'=>str_repeat('a',64)]; $page=clientsPage($db,$owner,$order,1); ccheck(count($page['offers'])===2,'owner order history');
    revokeGuest($db,$owner,$p,$g); ccheck(guestClientOrder($db,$g)===null,'revoked guest');
    creject(fn()=>clientGuestDecision($db,$g,'guest.delivery',$did2,2,true,'Accepted',$session),403,'revoked decision denied');
    // Webhook identifiers are textual even though the client delivery key shares their name.
    $deliveryKeys=['a0000000-0000-4000-8000-000000000001','20000000-0000-4000-8000-000000000001','10000000-0000-4000-8000-000000000001'];
    $raw='{}'; $secret=str_repeat('s',32); $signature='sha256='.hash_hmac('sha256',$raw,$secret);
    foreach ($deliveryKeys as $key) ccheck(ghReceive($db,$raw,$signature,$key,'ping',$secret)==='accepted','text delivery fixture');
    sort($deliveryKeys,SORT_STRING);
    $checkTransferOrder=function(\PDO $conn)use($deliveryKeys,$did2){
        $hours=iterator_to_array(transferRows($conn,'client_stage_hours',TRANSFER_TABLES['client_stage_hours']),false);
        ccheck(array_map('intval',array_column($hours,'entry_id'))===[2,10],'numeric composite key order');
        ccheck(array_map('intval',array_column($hours,'delivery_id'))===[$did2,$did2],'delivery references preserved');
        ccheck(array_map('intval',array_column($hours,'minutes'))===[30,60],'hour amounts preserved');
        $deliveries=iterator_to_array(transferRows($conn,'github_deliveries',TRANSFER_TABLES['github_deliveries']),false);
        ccheck(array_column($deliveries,'delivery_id')===$deliveryKeys,'binary text delivery order');
    };
    $checkTransferOrder($db);
    if (in_array('--postgres',$argv,true)) {
        $r=migrateToPostgres($dir.'/source.sqlite'); ccheck($r['tables']['client_orders']['rows']===2 && $r['tables']['client_deliveries']['rows']===2 && $r['tables']['client_stage_hours']['rows']===2,'PostgreSQL transfer');
        $pg=postgresConnect();
        ccheck(transferOrder($pg,'client_stage_hours',TRANSFER_TABLES['client_stage_hours'])==='"delivery_id","entry_id"','integer keys must not use collation');
        ccheck(transferOrder($pg,'github_deliveries',TRANSFER_TABLES['github_deliveries'])==='"delivery_id" COLLATE pg_catalog."C"','text keys use C collation');
        $checkTransferOrder($pg);
        putenv('WORKSPACE_RECOVERY_OFFLINE=1');
        $snapshot=recoveryPgInspect($pg);
        foreach ($r['tables'] as $table=>$digest) ccheck($snapshot['tables'][$table]===$digest,'recovery digest matches transfer: '.$table);
        putenv('WORKSPACE_RECOVERY_OFFLINE');
        ccheck(clientOrder($pg,$owner,$order)['title']==='Order','PostgreSQL order'); ccheck((int)query($pg,'SELECT accepted_minutes FROM client_deliveries WHERE id=?',[$did2])->fetchColumn()===90,'PostgreSQL hours');
        ccheck(clientCommand($pg,$owner,ck(),'client.party',['name'=>'Postgres'])>$party,'PostgreSQL sequence'); $pg=null;
    }
    echo "OK · $checks проверок заказов и приёмки\n";
} finally { $db=null; cclean($dir); }
