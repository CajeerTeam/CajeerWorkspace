<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

// Staging-only second tenant; never share the primary owner's identity or integrations.
function pilotBoundarySeed(PDO $db,int $owner,array $f): array {
    pilotCatalog(connectionConfig());
    if(array_diff(array_keys($f),['email','name','password','organization','project'])||!is_string($f['password']??null))throw new Problem('Некорректный ввод второй организации.');
    $email=normalizedEmail($f['email']??'');$name=word($f['name']??'',90,'Имя');$hash=passwordHash($f['password']);$orgName=word($f['organization']??'',120,'Организация');$title=word($f['project']??'',120,'Проект');
    return atomic($db,function()use($db,$owner,$email,$name,$hash,$orgName,$title,$f){
        $primary=pilotCheck($db,$owner);
        if(!$primary['topology_ready']||(int)$db->query('SELECT COUNT(*) FROM organizations')->fetchColumn()!==1)throw new Problem('Требуется исходная структура пилота без второй организации.',409);
        foreach($db->query('SELECT email,password_hash FROM users')->fetchAll() as $row)if(strcasecmp($email,$row['email'])===0||password_verify($f['password'],$row['password_hash']))throw new Problem('Используйте независимую учётную запись и пароль.',409);
        $org=insertId($db,'INSERT INTO organizations(name) VALUES(?)',[$orgName]);
        $uid=insertId($db,"INSERT INTO users(email,name,role,password_hash) VALUES(?,?,'owner',?)",[$email,$name,$hash]);
        query($db,"INSERT INTO organization_members VALUES(?,?,'owner')",[$org,$uid]);
        $u=['id'=>$uid,'organization_id'=>$org];
        $project=createProject($db,$u,bin2hex(random_bytes(16)),['title'=>$title,'kind'=>'internal']);
        $task=createTask($db,$u,bin2hex(random_bytes(16)),$project,['title'=>'Boundary control','result_customer'=>'Pilot','acceptance_criteria'=>'Tenant isolation']);
        auditEvent($db,$u,$project,'pilot.boundary',$task);
        return ['organization_id'=>$org,'owner_id'=>$uid,'project_id'=>$project,'task_id'=>$task];
    });
}
function pilotDenied(callable $fn,int $status): bool {
    try{$fn();return false;}catch(Problem $e){return $e->status===$status;}
}
function pilotBoundary(PDO $db,int $owner,array $primary): array {
    $row=query($db,"SELECT a.organization_id,a.actor_id AS owner_id,a.project_id,a.object_id AS task_id FROM audit_events a WHERE a.event='pilot.boundary' ORDER BY a.id LIMIT 2")->fetchAll();
    if(count($row)!==1||(int)$db->query('SELECT COUNT(*) FROM organizations')->fetchColumn()!==2)throw new Problem('Не найдена единственная контрольная организация.',409);
    $b=array_map('intval',$row[0]);$org=$b['organization_id'];$uid=$b['owner_id'];$project=$b['project_id'];$task=$b['task_id'];
    $u=orgOwner($db,['id'=>$owner,'organization_id'=>1]);$v=orgOwner($db,['id'=>$uid,'organization_id'=>$org]);
    $checks=['independent_owner'=>$org!==1&&$uid!==$owner&&count(organizationsFor($db,$uid))===1&&count(organizationsFor($db,$owner))===1,
        'no_shared_members'=>!query($db,'SELECT 1 FROM organization_members a JOIN organization_members b ON a.user_id=b.user_id WHERE a.organization_id=1 AND b.organization_id=? LIMIT 1',[$org])->fetchColumn(),
        'foreign_task'=>pilotDenied(fn()=>planningTask($db,$u,$task),404),
        'foreign_project'=>pilotDenied(fn()=>allowedProject($db,$u,$project),404),
        'foreign_org_switch'=>pilotDenied(fn()=>organizationUser($db,$u,$org),403),
        'reverse_org_switch'=>pilotDenied(fn()=>organizationUser($db,$v,1),403),
        'foreign_connections'=>scopedConnections($db,$v,$project)===[],
        'own_task'=>(int)planningTask($db,$v,$task)['project_id']===$project];
    $matrix=[];
    foreach($primary['staff'] as $slot=>$s){
        $actor=organizationUser($db,['id'=>$s['id'],'organization_id'=>1]);
        foreach($primary['projects'] as $kind=>$pid){
            $role=$s['projects'][$kind]['expected'];
            foreach(PROJECT_RIGHTS as $right=>$allowed){
                $expected=$role!==null&&roleAllows($role,$right);
                try{requireProjectRight($db,$actor,$pid,$right);$ok=$expected;}catch(Problem $e){$ok=!$expected&&$e->status===($role===null?404:403);}
                $matrix[$slot.':'.$kind.':'.$right]=$ok;
            }
        }
        $checks['foreign_staff_'.$slot]=pilotDenied(fn()=>allowedProject($db,$actor,$project),404)&&pilotDenied(fn()=>organizationUser($db,$actor,$org),403);
    }
    foreach($primary['projects'] as $kind=>$pid)$checks['reverse_'.$kind]=pilotDenied(fn()=>allowedProject($db,$v,$pid),404);
    $checks['role_matrix']=count($matrix)===count(PILOT_STAFF)*3*count(PROJECT_RIGHTS)&&!in_array(false,$matrix,true);
    recoveryScopeCheck($db);
    return ['boundary'=>$b,'checks'=>$checks,'matrix'=>$matrix];
}
const CYCLE_IDS=['order','delivery','invoice','statement','contract','signature','product_release','staging_job','production_job','failed_job','oss_release','engine_route','lite_route'];
const CYCLE_CHECKS=['topology','tenant_isolation','client_acceptance','client_payment_and_refund','bank_reconciliation','contract_revisions','signature_archive','delivery_staging','delivery_production','delivery_incident','oss_contribution','editorial_engine','editorial_lite','bots_delivery','guest_boundary'];
function pilotCycleInput(array $f): array {
    if(array_diff(array_keys($f),CYCLE_IDS)||array_diff(CYCLE_IDS,array_keys($f)))throw new Problem('Укажите все ID контрольного сценария.');
    $out=[];foreach(CYCLE_IDS as $k){if(!is_int($f[$k])&&(!is_string($f[$k])||!preg_match('/\A[1-9][0-9]*\z/',$f[$k])))throw new Problem('Некорректный ID сценария.');$out[$k]=numberId($f[$k]);}
    if(count(array_unique([$out['staging_job'],$out['production_job'],$out['failed_job']]))!==3||$out['engine_route']===$out['lite_route'])throw new Problem('Требуются разные задания и маршруты.');return $out;
}
/** Local persisted evidence, not a live-provider attestation. No writes or credential export. */
function pilotCycle(PDO $db,int $owner,array $f): array {
    $ids=pilotCycleInput($f);
    return atomic($db,function()use($db,$owner,$ids){
        $t=pilotCheck($db,$owner);$u=orgOwner($db,['id'=>$owner,'organization_id'=>1]);
        $checks=array_fill_keys(CYCLE_CHECKS,false);$checks['topology']=$t['topology_ready']&&$t['guest_ready'];
        $b=pilotBoundary($db,$owner,$t);$checks['tenant_isolation']=!in_array(false,$b['checks'],true);
        $p=$t['projects']['client'];$internal=$t['projects']['internal'];$oss=$t['projects']['oss'];$proof=[];
        $order=clientOrder($db,$u,$ids['order']);
        $delivery=entity($db,'SELECT d.*,s.offer_id,s.order_id,o.status AS offer_status FROM client_deliveries d JOIN client_stages s ON s.id=d.stage_id JOIN client_offers o ON o.id=s.offer_id WHERE d.project_id=? AND s.order_id=? AND d.id=?',[$p,$ids['order'],$ids['delivery']]);
        $checks['client_acceptance']=(int)$order['project_id']===$p&&$delivery['status']==='accepted'&&$delivery['offer_status']==='accepted'&&(int)$delivery['accepted_minutes']>0&&$delivery['document_id']!==null&&(bool)query($db,"SELECT 1 FROM pm_changes WHERE project_id=? AND order_id=? AND status='applied' AND decided_guest=?",[$p,$ids['order'],$delivery['decided_guest']])->fetchColumn()&&(int)query($db,'SELECT COALESCE(SUM(minutes),0) FROM client_stage_hours WHERE delivery_id=?',[$delivery['id']])->fetchColumn()===(int)$delivery['accepted_minutes'];
        if($delivery['document_id']!==null)$proof['delivery_pdf_sha256']=hash('sha256',retentionDocumentBytes($db,(int)$delivery['document_id'],(int)$delivery['document_revision']));
        $guest=(int)$delivery['decided_guest'];$guestOrder=guestClientOrder($db,$guest);$checks['guest_boundary']=$guestOrder&&(int)$guestOrder['id']===$ids['order']&&in_array($guest,$t['guest_active_ids'],true)&&pilotDenied(fn()=>clientOrder($db,['id'=>$b['boundary']['owner_id'],'organization_id'=>$b['boundary']['organization_id']],$ids['order']),404);
        $i=invoiceGet($db,$u,$p,$ids['invoice']);$mov=invoiceMovements($db,$ids['invoice']);$balance=invoiceBalance($i,$mov);
        $posted=array_values(array_filter($mov,fn($m)=>$m['status']==='posted'));
        $checks['client_payment_and_refund']=$i['state']==='issued'&&(int)$i['counterparty_id']===(int)$order['counterparty_id']&&$balance['received']>0&&$balance['refunded']>0&&$balance['outstanding']>=0&&$balance['pending_receipt']===0&&$balance['pending_refund']===0&&count($posted)>=2&&!array_filter($posted,fn($m)=>$m['decided_by']===null||(int)$m['created_by']===(int)$m['decided_by'])&&count(array_filter($posted,fn($m)=>$m['kind']==='receipt'&&(int)$m['amount_minor']<(int)$i['total_minor']&&(int)$m['created_by']!==(int)$m['decided_by']))>0;
        $checks['client_payment_and_refund']=$checks['client_payment_and_refund']&&((bool)query($db,'SELECT 1 FROM freelance_bills WHERE invoice_id=? AND offer_id=?',[$i['id'],$delivery['offer_id']])->fetchColumn()||(bool)query($db,'SELECT 1 FROM pm_changes WHERE invoice_id=? AND order_id=?',[$i['id'],$ids['order']])->fetchColumn());
        $statement=bankStatement($db,1,$ids['statement']);$report=bankReport($db,$statement);$reviews=bankReviewRows($db,$statement,$report);
        $matched=array_map('intval',query($db,'SELECT cash_id FROM bank_allocations WHERE statement_id=? AND project_id=?',[$ids['statement'],$p])->fetchAll(PDO::FETCH_COLUMN));
        $checks['bank_reconciliation']=$report['report']['ok']&&count($matched)>0&&(int)$statement['entity_id']===(int)$i['entity_id']&&!array_diff(array_map(fn($m)=>(int)$m['cash_entry_id'],$posted),$matched)&&!array_filter($reviews,fn($r)=>$r['status']!=='approved')&&(bool)query($db,'SELECT 1 FROM accounting_periods WHERE organization_id=1 AND closed_at IS NOT NULL AND starts_on<=? AND ends_on>=?',[$statement['starts_on'],$statement['ends_on']])->fetchColumn();
        $checks['bank_reconciliation']=$checks['bank_reconciliation']&&accountingActive($db,1)&&accountingReconcile($db,1)['ok']&&(bool)query($db,'SELECT 1 FROM bank_reconciliations WHERE statement_id=? AND digest=? AND ok=1',[$statement['id'],$report['digest']])->fetchColumn();
        $proof['bank_digest']=$report['digest'];
        $c=contractGet($db,$u,$p,$ids['contract']);$revisions=query($db,'SELECT r.* FROM contract_revisions r JOIN contract_activation_evidence e ON e.revision_id=r.id WHERE r.contract_id=? AND r.activated_at IS NOT NULL ORDER BY r.revision',[$c['id']])->fetchAll();
        $checks['contract_revisions']=count($revisions)>=2&&(int)$c['counterparty_id']===(int)$order['counterparty_id']&&(int)$c['entity_id']===(int)$i['entity_id']&&(bool)query($db,'SELECT 1 FROM contract_obligations WHERE contract_id=? AND invoice_id=?',[$c['id'],$i['id']])->fetchColumn();
        foreach($revisions as $r)if(!hash_equals($r['document_hash'],hash('sha256',retentionDocumentBytes($db,(int)$r['document_id'],(int)$r['document_revision']))))$checks['contract_revisions']=false;
        $sig=entity($db,"SELECT s.*,a.pdf_base64,a.pdf_size,a.pdf_sha256,a.status_json,a.status_sha256,r.document_id,r.document_revision FROM signing_packages s JOIN signing_archive a ON a.package_id=s.id JOIN preparation_revisions r ON r.id=s.revision_id WHERE s.project_id=? AND s.id=?",[$p,$ids['signature']]);
        $checks['signature_archive']=$sig['state']==='archived'&&(int)$sig['test_mode']===1&&hash_equals($sig['status_sha256'],hash('sha256',$sig['status_json']))&&count(array_filter($revisions,fn($r)=>(int)$r['document_id']===(int)$sig['document_id']&&(int)$r['document_revision']===(int)$sig['document_revision']))>0;
        $checks['signature_archive']=$checks['signature_archive']&&(json_decode(signReceiptBundle($db,$sig),true,64,JSON_THROW_ON_ERROR)['test_evidence_ready']??false)===true;
        $proof['signature_sha256']=$sig['pdf_sha256'];
        $dp=deliveryPage($db,$u,$internal,$ids['product_release']);$scopeOk=$dp['deliveryScopes']!==[]&&!array_filter($dp['deliveryScopes'],fn($x)=>!$x['current']||!$x['verified']);
        foreach(['staging','production','failed'] as $kind){$trace=traceLocal($db,$u,$internal,$ids['product_release'],$ids[$kind.'_job']);$j=$trace['traceJob'];$target=$trace['traceTarget'];$proof[$kind.'_release_sha']=$trace['traceRelease']['commit_sha'];
            $logged=(bool)array_filter($trace['traceLogs'],fn($l)=>$l['state']==='delivered'&&$l['remote_event']!==null&&($kind==='failed'?in_array($l['event'],['deploy.failed','deploy.recovery_required'],true):$l['event']==='deploy.succeeded'));
            if($kind==='failed')$checks['delivery_incident']=in_array($j['state'],['failed','rolled_back','recovery_required'],true)&&$logged&&(bool)array_filter($trace['traceIncidents'],fn($x)=>$x['state']==='resolved');
            else {$site=null;foreach($dp['deliverySites'] as $candidate)if((int)($candidate['deployment']['id']??0)===$ids[$kind.'_job']){$site=$candidate;break;}$qa=$site!==null&&$site['matches'];foreach($dp['deliveryScopes'] as $scope)$qa=$qa&&($site['reviews'][(int)$scope['task_id']]['outcome']??null)==='passed';$checks['delivery_'.$kind]=$scopeOk&&$qa&&$trace['traceGithubEvidence']!==null&&$trace['traceCurrent']&&$j['state']==='succeeded'&&$target['kind']===($kind==='production'?'prod':'staging')&&$logged&&($trace['traceInventory']['aapanel']['state']??null)==='observed'&&($trace['traceInventory']['servers']['state']??null)==='observed';}
        }
        $o=entity($db,'SELECT r.* FROM oss_releases r JOIN oss_projects p ON p.project_id=r.project_id WHERE r.project_id=? AND r.id=? AND p.published=1',[$oss,$ids['oss_release']]);
        $checks['oss_contribution']=(bool)query($db,"SELECT 1 FROM oss_release_items i JOIN oss_contributions c ON c.project_id=i.project_id AND c.id=i.contribution_id JOIN tasks t ON t.id=c.task_id AND t.project_id=c.project_id WHERE i.release_id=? AND i.project_id=? AND c.state='accepted' AND t.state='done'",[$o['id'],$oss])->fetchColumn();
        $linked=[];foreach(['engine','lite'] as $kind){$r=editorialRow($db,$u,$oss,$ids[$kind.'_route']);$actor=organizationUser($db,['id'=>(int)$r['actor_id'],'organization_id'=>1]);editorialCheck($db,$actor,$r);$cms=editorialCmsState($db,$actor,$r);$forum=editorialForumState($db,$r);editorialForumLocal($db,$actor,forumSource($db,$actor,$oss,(int)$r['forum_source_id']));$rel=releaseRow($db,$oss,(int)$r['release_id']);
            $checks['editorial_'.$kind]=($r['engine_publication_id']!==null)===($kind==='engine')&&$r['abandoned_at']===null&&$cms['url']!==null&&$r['cms_url']===$cms['url']&&$forum['state']==='linked'&&$rel['state']==='confirmed';
            if($rel['tag']===$o['tag'])$linked[]=(int)$r['release_id'];$proof[$kind.'_snapshot']=$r['snapshot_hash'];
        }
        $checks['oss_contribution']=$checks['oss_contribution']&&count($linked)===2&&count(array_unique($linked))===1;
        foreach(array_unique($linked) as $rid){$m=query($db,"SELECT m.* FROM bots_messages m JOIN bots_bindings b ON b.project_id=m.project_id AND b.identity_hash=m.identity_hash WHERE m.project_id=? AND m.release_id=? AND m.state='sent' AND b.active=1",[$oss,$rid])->fetch();if($m&&hash_equals($m['message_hash'],hash('sha256',$m['message']))&&$m['provider_message_id']!==null&&$m['release_hash']===releaseRow($db,$oss,$rid)['evidence_hash']&&(bool)query($db,"SELECT 1 FROM bots_events WHERE message_id=? AND state='receipt' AND detail=?",[$m['id'],botsReceiptDetail($m,'sent',(int)$m['remote_attempts'],$m['provider_message_id'])])->fetchColumn())$checks['bots_delivery']=true;}
        return ['version'=>VERSION,'schema'=>SCHEMA_VERSION,'mode'=>'persisted_cycle','code_sha256'=>hash_file('sha256',__FILE__),'checked_at'=>gmdate(DATE_ATOM),'projects'=>$t['projects'],'owner_id'=>$owner,'boundary'=>$b['boundary'],'role_checks'=>$b['matrix'],'boundary_checks'=>$b['checks'],'ids'=>$ids,'checks'=>$checks,'proof'=>$proof,'status'=>in_array(false,$checks,true)?'incomplete':'passed'];
    });
}
