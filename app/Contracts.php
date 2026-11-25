<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

function contractGet(PDO $db,array $u,int $p,int $id,string $right='legal'): array {
    requireProjectRight($db,$u,$p,$right);
    return entity($db,'SELECT * FROM contracts WHERE project_id=? AND id=?',[$p,$id]);
}
function contractResponsible(PDO $db,int $org,int $p,int $uid): bool {
    try {requireProjectRight($db,organizationUser($db,['id'=>$uid],$org),$p,'legal.write');return true;}
    catch(Problem $e){if(in_array($e->status,[403,404],true))return false;throw $e;}
}
function contractPdf(PDO $db,int $p,mixed $raw,bool $main=false): array {
    if(!is_string($raw)||!preg_match('/\A([1-9][0-9]*):([1-9][0-9]*)\z/',$raw,$m))throw new Problem('Выберите конкретную редакцию PDF.');
    $d=entity($db,'SELECT d.id,d.kind,d.title,v.revision,v.sha256 FROM documents d JOIN document_versions v ON v.document_id=d.id WHERE d.project_id=? AND d.id=? AND v.revision=?',[$p,numberId($m[1]),numberId($m[2])]);
    if(retentionDocument($db,(int)$d['id'],(int)$d['revision'])['storage_state']==='purged')throw new Problem('Файл удалён.',410);
    if($main&&!in_array($d['kind'],['contract','nda','amendment'],true))throw new Problem('Основной PDF должен быть договором, NDA или дополнительным соглашением.');
    return $d;
}
function contractCheckPdf(PDO $db,array $d): void {
    $bytes=retentionDocumentBytes($db,(int)$d['id'],(int)$d['revision']);
    if(!hash_equals($d['sha256'],hash('sha256',$bytes)))throw new Problem('Содержимое PDF не соответствует SHA-256.',409);
}
function contractLatest(PDO $db,int $id,bool $effective=false): ?array {
    return query($db,'SELECT * FROM contract_revisions WHERE contract_id=?'.($effective?' AND activated_at IS NOT NULL':'').' ORDER BY revision DESC LIMIT 1',[$id])->fetch()?:null;
}
function contractDates(array $f): array {
    $signed=calendarDate($f['signed_on']??'');$start=calendarDate($f['starts_on']??'');$end=calendarDate($f['ends_on']??'');$notice=calendarDate($f['notice_on']??'');
    if($end<$start||$end<$signed||$notice<$signed||$notice>$end)throw new Problem('Проверьте даты договора и крайний срок уведомления.');
    return [$signed,$start,$end,$notice];
}
function contractStatus(?array $r,string $today): string {
    if(!$r)return 'Черновик';
    if($r['ends_on']<$today)return 'Срок истёк';
    if($r['starts_on']>$today)return 'Вступление в силу ожидается';
    if($r['notice_on']<$today)return 'Срок уведомления пропущен';
    if($r['notice_on']<=gmdate('Y-m-d',strtotime($today.' UTC')+30*86400))return 'Уведомление в течение 30 дней';
    return 'Действует';
}
function contractSnapshot(PDO $db,array $c): string {
    $seller=entity($db,'SELECT name,registration,address,version FROM legal_entities WHERE organization_id=? AND id=?',[$c['organization_id'],$c['entity_id']]);
    $buyer=entity($db,'SELECT name,registration,version FROM counterparties WHERE organization_id=? AND id=?',[$c['organization_id'],$c['counterparty_id']]);
    // Do not copy bank credentials or private contacts into legal records.
    return json_encode(['seller'=>$seller,'buyer'=>$buyer],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}
function contractRevision(PDO $db,array $c,array $f,int $actor,int $rev): int {
    $p=(int)$c['project_id'];$responsible=numberId($f['responsible_id']??'');
    if(!contractResponsible($db,(int)$c['organization_id'],$p,$responsible))throw new Problem('Ответственный должен иметь право редактирования договоров.',403);
    $d=contractPdf($db,$p,$f['document']??'',true);$dates=contractDates($f);
    return insertId($db,'INSERT INTO contract_revisions(contract_id,project_id,revision,title,party_snapshot,responsible_id,seller_signer,seller_authority,buyer_signer,buyer_authority,currency,amount_minor,signed_on,starts_on,ends_on,notice_on,renewal_terms,document_id,document_revision,document_hash,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[
        $c['id'],$p,$rev,word($f['title']??'',180,'Название'),contractSnapshot($db,$c),$responsible,word($f['seller_signer']??'',180,'Представитель нашей стороны'),word($f['seller_authority']??'',500,'Полномочия нашей стороны'),word($f['buyer_signer']??'',180,'Представитель контрагента'),word($f['buyer_authority']??'',500,'Полномочия контрагента'),choice($f['currency']??'',array_flip(CURRENCIES)),econAmount($f['amount']??''),...$dates,word($f['renewal_terms']??'',1000,'Условия продления'),$d['id'],$d['revision'],$d['sha256'],$actor,time()]);
}
function contractCommand(PDO $db,array $user,int $p,string $key,string $act,array $form): int {
    $user=organizationUser($db,$user);requireProjectRight($db,$user,$p,'legal.write');
    $terms=['title','responsible_id','seller_signer','seller_authority','buyer_signer','buyer_authority','currency','amount','signed_on','starts_on','ends_on','notice_on','renewal_terms','document'];
    $fields=match($act){'contract.create'=>['reference','entity_id','counterparty_id',...$terms],'contract.revise'=>['id','version',...$terms],'contract.attach'=>['id','version','document','label'],'contract.detach'=>['id','version','attachment_id'],'contract.activate'=>['id','version','reason'],'contract.obligation'=>['id','version','title','due_on','invoice_id','plan_id'],'contract.decide'=>['id','version','obligation_id','state','reason'],default=>throw new Problem('Неизвестная операция.')};
    $f=[];foreach($fields as $field){$v=$form[$field]??'';if(!is_string($v)&&!is_int($v))throw new Problem('Некорректная форма.');$f[$field]=(string)$v;}
    if($act==='contract.activate'||($act==='contract.obligation'&&($f['invoice_id']!==''||$f['plan_id']!=='')))orgOwner($db,$user);
    return action($db,$user,$key,$act,[$p,$f],function()use($db,$user,$p,$act,$f){
        $u=organizationUser($db,$user);requireProjectRight($db,$u,$p,'legal.write');$org=(int)$u['organization_id'];$actor=(int)$u['id'];
        if($act==='contract.create'){
            $ref=word($f['reference'],120,'Номер договора');$seller=numberId($f['entity_id']);$buyer=numberId($f['counterparty_id']);
            entity($db,'SELECT id FROM legal_entities WHERE organization_id=? AND id=?',[$org,$seller]);entity($db,'SELECT id FROM counterparties WHERE organization_id=? AND id=?',[$org,$buyer]);
            if(query($db,'SELECT 1 FROM contracts WHERE organization_id=? AND reference=?',[$org,$ref])->fetchColumn())throw new Problem('Номер договора уже используется.',409);
            $id=insertId($db,'INSERT INTO contracts(organization_id,project_id,entity_id,counterparty_id,reference,created_by,created_at) VALUES(?,?,?,?,?,?,?)',[$org,$p,$seller,$buyer,$ref,$actor,time()]);
            contractRevision($db,contractGet($db,$u,$p,$id,'legal.write'),$f,$actor,1);return $id;
        }
        $id=numberId($f['id']);$c=contractGet($db,$u,$p,$id,'legal.write');
        if((int)$c['version']!==numberId($f['version']))throw new Problem('Карточка изменилась. Обновите страницу.',409);
        $r=contractLatest($db,$id);$active=contractLatest($db,$id,true);
        if(!$r)throw new Problem('Нет редакции договора.',409);
        if($act==='contract.revise'){
            contractRevision($db,$c,$f,$actor,(int)$r['revision']+1);
        }elseif(in_array($act,['contract.attach','contract.detach','contract.activate'],true)){
            if($r['activated_at']!==null)throw new Problem('Действующая редакция неизменяема. Создайте новую редакцию.',409);
            if($act==='contract.attach'){
                $d=contractPdf($db,$p,$f['document']);
                if((int)$d['id']===(int)$r['document_id']&&(int)$d['revision']===(int)$r['document_revision'])throw new Problem('Это основной документ.',409);
                if(query($db,'SELECT 1 FROM contract_attachments WHERE revision_id=? AND document_id=? AND document_revision=?',[$r['id'],$d['id'],$d['revision']])->fetchColumn())throw new Problem('Приложение уже добавлено.',409);
                if((int)query($db,'SELECT COUNT(*) FROM contract_attachments WHERE revision_id=?',[$r['id']])->fetchColumn()>=100)throw new Problem('Максимум 100 приложений.');
                query($db,'INSERT INTO contract_attachments(project_id,contract_id,revision_id,document_id,document_revision,document_hash,label) VALUES(?,?,?,?,?,?,?)',[$p,$id,$r['id'],$d['id'],$d['revision'],$d['sha256'],word($f['label'],180,'Название приложения')]);
            }elseif($act==='contract.detach'){
                $a=numberId($f['attachment_id']);entity($db,'SELECT id FROM contract_attachments WHERE revision_id=? AND id=?',[$r['id'],$a]);query($db,'DELETE FROM contract_attachments WHERE revision_id=? AND id=?',[$r['id'],$a]);
            }else{
                orgOwner($db,$u);$today=gmdate('Y-m-d');
                if($r['signed_on']>$today||$r['starts_on']>$today)throw new Problem('Фиксировать вступление в силу можно только после подписания и начала действия.',409);
                if(!contractResponsible($db,$org,$p,(int)$r['responsible_id']))throw new Problem('Ответственный утратил полномочия.',409);
                if(!hash_equals($r['party_snapshot'],contractSnapshot($db,$c)))throw new Problem('Реквизиты сторон изменились. Создайте актуальную редакцию.',409);
                $pdf=contractPdf($db,$p,$r['document_id'].':'.$r['document_revision'],true);
                if(!hash_equals($r['document_hash'],$pdf['sha256']))throw new Problem('Хеш основного документа изменился.',409);contractCheckPdf($db,$pdf);
                foreach(query($db,'SELECT * FROM contract_attachments WHERE revision_id=?',[$r['id']])->fetchAll() as $a){$pdf=contractPdf($db,$p,$a['document_id'].':'.$a['document_revision']);if(!hash_equals($a['document_hash'],$pdf['sha256']))throw new Problem('Хеш приложения изменился.',409);contractCheckPdf($db,$pdf);}
                query($db,'UPDATE contract_revisions SET activated_by=?,activated_at=?,activation_note=? WHERE id=?',[$actor,time(),word($f['reason'],500,'Основание вступления в силу'),$r['id']]);
            }
        }elseif($act==='contract.obligation'){
            if(!$active)throw new Problem('Сначала зафиксируйте действующую редакцию.',409);
            $invoice=$f['invoice_id']===''?null:numberId($f['invoice_id']);$plan=$f['plan_id']===''?null:numberId($f['plan_id']);
            if($invoice&&$plan)throw new Problem('Укажите только один финансовый источник.');
            if($invoice||$plan)orgOwner($db,$u);
            if($invoice){$i=entity($db,'SELECT * FROM invoices WHERE project_id=? AND id=?',[$p,$invoice]);if((int)$i['entity_id']!==(int)$c['entity_id']||(int)$i['counterparty_id']!==(int)$c['counterparty_id']||$i['currency']!==$active['currency']||$i['state']!=='issued')throw new Problem('Счёт должен быть выставлен по тем же сторонам и валюте.',409);if(query($db,'SELECT 1 FROM contract_obligations WHERE invoice_id=?',[$invoice])->fetchColumn())throw new Problem('Счёт уже связан с обязательством.',409);}
            if($plan){$i=econPlan($db,$p,$plan);if($i['currency']!==$active['currency']||(int)$i['cancelled'])throw new Problem('План отменён или имеет другую валюту.',409);if(query($db,'SELECT 1 FROM contract_obligations WHERE plan_id=?',[$plan])->fetchColumn())throw new Problem('План уже связан с обязательством.',409);}
            query($db,'INSERT INTO contract_obligations(project_id,contract_id,revision_id,title,due_on,invoice_id,plan_id,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$p,$id,$active['id'],word($f['title'],180,'Обязательство'),calendarDate($f['due_on']),$invoice,$plan,$actor,time()]);
        }else{
            $oid=numberId($f['obligation_id']);$o=entity($db,'SELECT * FROM contract_obligations WHERE contract_id=? AND id=?',[$id,$oid]);
            if($o['state']!=='open')throw new Problem('Обязательство уже закрыто.',409);
            // The legal decision is not a financial settlement; linked financial obligations need owner rights.
            if($o['invoice_id']!==null||$o['plan_id']!==null){orgOwner($db,$u);if($f['state']==='completed'&&!contractSettlement($db,$p,$o)['settled'])throw new Problem('Финансовое обязательство ещё не исполнено.',409);}
            query($db,'UPDATE contract_obligations SET state=?,decided_by=?,decided_at=?,decision_note=? WHERE id=?',[choice($f['state'],['completed'=>'','cancelled'=>'']),$actor,time(),word($f['reason'],500,'Основание решения'),$oid]);
        }
        query($db,'UPDATE contracts SET version=version+1 WHERE id=?',[$id]);return $id;
    });
}
function contractSettlement(PDO $db,int $p,array $o): array {
    if($o['invoice_id']!==null){$i=entity($db,'SELECT * FROM invoices WHERE project_id=? AND id=?',[$p,$o['invoice_id']]);$b=invoiceBalance($i,invoiceMovements($db,(int)$i['id']));return ['label'=>invoiceNumber($i),'settled'=>$i['state']==='issued'&&$b['outstanding']===0&&$b['pending_refund']===0,'remaining'=>$b['outstanding'],'currency'=>$i['currency'],'state'=>$i['state']];}
    if($o['plan_id']!==null){$i=econPlan($db,$p,(int)$o['plan_id']);$remaining=max(0,(int)$i['amount_minor']-econPlanPaid(econPlanLinks($db,$p,(int)$i['id']),gmdate('Y-m-d')));return ['label'=>'План #'.$i['id'],'settled'=>!(int)$i['cancelled']&&$remaining===0,'remaining'=>$remaining,'currency'=>$i['currency'],'state'=>$i['cancelled']?'cancelled':'open'];}
    return ['settled'=>true];
}
function contractPage(PDO $db,array $u,?int $p,?int $id,?int $before=null): array {
    $u=organizationUser($db,$u);$projects=rightProjects($db,$u,'legal');
    if($p!==null)requireProjectRight($db,$u,$p,'legal');
    if(!$projects&&$u['role']!=='owner')throw new Problem('Нет доступа к договорам.',403);
    [$scope,$params]=projectRightScope($db,$u,'legal');
    $where=$scope;if($p!==null){$where.=' AND c.project_id=?';$params[]=$p;}if($before!==null){$where.=' AND c.id<?';$params[]=$before;}
    $rows=query($db,"SELECT c.*,p.title project_title FROM contracts c JOIN projects p ON p.id=c.project_id WHERE $where ORDER BY c.id DESC LIMIT 51",$params)->fetchAll();
    $nextBefore=count($rows)>50?(int)$rows[49]['id']:null;$rows=array_slice($rows,0,50);$today=gmdate('Y-m-d');
    foreach($rows as &$row){$row['active']=contractLatest($db,(int)$row['id'],true);$row['latest']=contractLatest($db,(int)$row['id']);}unset($row);
    $card=null;$revisions=[];$obligations=[];$pdfs=[];$mainPdfs=[];$members=[];$sellers=[];$buyers=[];$writable=false;$finance=false;$owner=$u['role']==='owner';$invoiceOptions=[];$planOptions=[];$latest=null;$active=null;
    if($id!==null){if($p===null)throw new Problem('Выберите проект.');$card=contractGet($db,$u,$p,$id);$latest=contractLatest($db,$id);$active=contractLatest($db,$id,true);
        $revisions=query($db,'SELECT r.*,u.name responsible_name FROM contract_revisions r JOIN users u ON u.id=r.responsible_id WHERE r.contract_id=? ORDER BY r.revision DESC',[$id])->fetchAll();
        foreach($revisions as &$r){$r['attachments']=query($db,'SELECT a.*,d.title FROM contract_attachments a JOIN documents d ON d.id=a.document_id WHERE a.revision_id=? ORDER BY a.id',[$r['id']])->fetchAll();$r['responsible_valid']=contractResponsible($db,(int)$u['organization_id'],$p,(int)$r['responsible_id']);}unset($r);
        $finance=roleAllows(projectRole($db,$u,$p),'finance');$obligations=query($db,'SELECT * FROM contract_obligations WHERE contract_id=? ORDER BY due_on,id',[$id])->fetchAll();
        if($finance)foreach($obligations as &$o){$o['settlement']=contractSettlement($db,$p,$o);}unset($o);
        if($owner&&$active){foreach(query($db,"SELECT i.* FROM invoices i WHERE i.project_id=? AND i.entity_id=? AND i.counterparty_id=? AND i.currency=? AND i.state='issued' AND NOT EXISTS(SELECT 1 FROM contract_obligations o WHERE o.invoice_id=i.id) ORDER BY i.id DESC LIMIT 200",[$p,$card['entity_id'],$card['counterparty_id'],$active['currency']])->fetchAll() as $i)$invoiceOptions[$i['id']]=invoiceNumber($i).' · '.money((int)$i['total_minor']).' '.$i['currency'];
            foreach(query($db,'SELECT i.* FROM economics_plans i WHERE i.project_id=? AND i.currency=? AND i.cancelled=0 AND NOT EXISTS(SELECT 1 FROM contract_obligations o WHERE o.plan_id=i.id) ORDER BY i.id DESC LIMIT 200',[$p,$active['currency']])->fetchAll() as $i)$planOptions[$i['id']]='#'.$i['id'].' · '.$i['title'];}
    }
    if($p!==null){$writable=roleAllows(projectRole($db,$u,$p),'legal.write');if($writable){
        foreach(query($db,'SELECT d.id,d.title,d.kind,v.revision FROM documents d JOIN document_versions v ON v.document_id=d.id WHERE d.project_id=? ORDER BY d.id DESC,v.revision DESC',[$p])->fetchAll() as $d){$k=$d['id'].':'.$d['revision'];$pdfs[$k]=$d['title'].' · v'.$d['revision'].' (#'.$d['id'].')';if(in_array($d['kind'],['contract','nda','amendment'],true))$mainPdfs[$k]=$pdfs[$k];}
        foreach(query($db,'SELECT u.id,u.name FROM users u JOIN organization_members m ON m.user_id=u.id WHERE m.organization_id=? AND u.enabled=1 ORDER BY u.name,u.id',[$u['organization_id']])->fetchAll() as $m)if(contractResponsible($db,(int)$u['organization_id'],$p,(int)$m['id']))$members[$m['id']]=$m['name'];
        $sellers=array_column(query($db,'SELECT id,name FROM legal_entities WHERE organization_id=? ORDER BY name,id',[$u['organization_id']])->fetchAll(),'name','id');$buyers=array_column(query($db,'SELECT id,name FROM counterparties WHERE organization_id=? ORDER BY name,id',[$u['organization_id']])->fetchAll(),'name','id');
    }}
    return compact('projects','p','rows','nextBefore','today','card','revisions','obligations','pdfs','mainPdfs','members','sellers','buyers','writable','finance','owner','invoiceOptions','planOptions','latest','active');
}
