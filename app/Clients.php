<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const OFFER_STATES=['draft'=>'Черновик','offered'=>'На согласовании','accepted'=>'Принято','rejected'=>'Отклонено'];
const DELIVERY_STATES=['submitted'=>'На приёмке','accepted'=>'Принято','returned'=>'На доработке'];
function clientOrder(PDO $db,array $u,int $id): array {
    $u=orgOwner($db,$u);
    return entity($db,'SELECT o.*,p.title project_title FROM client_orders o JOIN projects p ON p.id=o.project_id WHERE o.id=? AND o.organization_id=?',[$id,$u['organization_id']]);
}
function clientOffer(PDO $db,int $order,int $id): array { return entity($db,'SELECT * FROM client_offers WHERE order_id=? AND id=?',[$order,$id]); }
function clientStage(PDO $db,int $order,int $id): array { return entity($db,'SELECT s.*,o.status offer_status FROM client_stages s JOIN client_offers o ON o.id=s.offer_id WHERE s.order_id=? AND s.id=?',[$order,$id]); }
function clientVersion(array $row,int $version): void { if ((int)$row['version']!==$version) throw new Problem('Запись уже изменена. Обновите страницу.',409); }
function clientMoney(mixed $value): int { return moneyMinor($value); }
function clientTotals(array $stages): int { $total=0; foreach ($stages as $s) { $total+=(int)$s['amount_minor']; if ($total>99999999999) throw new Problem('Сумма сметы превышает допустимый предел.'); } return $total; }
// Owner-only commercial mutations: authorization and request replay under the same write lock.
function clientCommand(PDO $db,array $user,string $key,string $act,array $f): int {
    if (!preg_match('/\A[a-f0-9]{32}\z/',$key)) throw new Problem('Обновите форму.');
    $u=orgOwner($db,$user); unset($f['csrf'],$f['request_key']); ksort($f);
    $digest=hash('sha256',json_encode([$act,$u['organization_id'],$f],JSON_THROW_ON_ERROR));
    return atomic($db,function() use($db,$u,$key,$act,$f,$digest) {
        $u=orgOwner($db,$u); $org=$u['organization_id'];
        $old=query($db,'SELECT fingerprint,object_id FROM action_log WHERE actor_id=? AND request_key=?',[$u['id'],$key])->fetch();
        if ($old) { if (!hash_equals($old['fingerprint'],$digest)) throw new Problem('Повторный запрос изменён.',409); return (int)$old['object_id']; }
        $id=0; $project=null;
        if ($act==='client.party') {
            $pid=optionalId($f['party_id']??''); $name=word($f['name']??'',180,'Контрагент'); $reg=word($f['registration']??'',180,'Реквизиты',false); $notes=word($f['notes']??'',2000,'Заметки',false);
            if ($pid===null) $id=insertId($db,'INSERT INTO counterparties(organization_id,name,registration,notes) VALUES(?,?,?,?)',[$org,$name,$reg,$notes]);
            else { $row=entity($db,'SELECT * FROM counterparties WHERE organization_id=? AND id=?',[$org,$pid]); clientVersion($row,numberId($f['version']??0)); query($db,'UPDATE counterparties SET name=?,registration=?,notes=?,version=version+1 WHERE id=?',[$name,$reg,$notes,$pid]); $id=$pid; }
        } elseif ($act==='client.contact') {
            $pid=numberId($f['party_id']??0); entity($db,'SELECT id FROM counterparties WHERE organization_id=? AND id=?',[$org,$pid]);
            $cid=optionalId($f['contact_id']??''); $name=word($f['name']??'',180,'Имя'); $email=normalizedEmail($f['email']??''); $phone=word($f['phone']??'',80,'Телефон',false);
            if (query($db,'SELECT 1 FROM counterparty_contacts WHERE counterparty_id=? AND email=? AND id<>?',[$pid,$email,$cid??0])->fetchColumn()) throw new Problem('Контакт с такой почтой уже есть.',409);
            if ($cid===null) $id=insertId($db,'INSERT INTO counterparty_contacts(organization_id,counterparty_id,name,email,phone) VALUES(?,?,?,?,?)',[$org,$pid,$name,$email,$phone]);
            else { $row=entity($db,'SELECT * FROM counterparty_contacts WHERE organization_id=? AND counterparty_id=? AND id=?',[$org,$pid,$cid]); clientVersion($row,numberId($f['version']??0)); query($db,'UPDATE counterparty_contacts SET name=?,email=?,phone=?,version=version+1 WHERE id=?',[$name,$email,$phone,$cid]); $id=$cid; }
        } elseif ($act==='client.request') {
            $project=numberId($f['project_id']??0); $p=allowedProject($db,$u,$project); if ($p['kind']!=='client') throw new Problem('Выберите клиентский проект.');
            $contact=entity($db,'SELECT c.*,p.name party_name FROM counterparty_contacts c JOIN counterparties p ON p.id=c.counterparty_id WHERE c.organization_id=? AND c.id=?',[$org,numberId($f['contact_id']??0)]);
            $id=insertId($db,'INSERT INTO client_orders(organization_id,project_id,counterparty_id,contact_id,title,request_text,client_name,contact_name,contact_email,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$org,$project,$contact['counterparty_id'],$contact['id'],word($f['title']??'',180,'Запрос'),criteriaText($f['request_text']??''),$contact['party_name'],$contact['name'],$contact['email'],$u['id'],time()]);
        } else {
            $order=clientOrder($db,$u,numberId($f['order_id']??0)); $id=(int)$order['id']; $project=(int)$order['project_id'];
            if ($act==='client.offer') {
                if (query($db,"SELECT 1 FROM client_offers WHERE order_id=? AND status<>'rejected'",[$id])->fetchColumn()) throw new Problem('Сначала завершите текущую редакцию.',409);
                $rev=1+(int)query($db,'SELECT MAX(revision) FROM client_offers WHERE order_id=?',[$id])->fetchColumn();
                insertId($db,'INSERT INTO client_offers(order_id,revision,scope,currency) VALUES(?,?,?,?)',[$id,$rev,criteriaText($f['scope']??''),choice($f['currency']??'',array_combine(CURRENCIES,CURRENCIES))]);
            } elseif (in_array($act,['client.stage','client.stage.remove','client.publish','client.offer.edit'],true)) {
                $offer=clientOffer($db,$id,numberId($f['offer_id']??0)); $oid=(int)$offer['id']; clientVersion($offer,numberId($f['version']??0));
                if ($offer['status']!=='draft') throw new Problem('Отправленная смета неизменяема.',409);
                if ($act!=='client.publish' && freelanceLocked($db,$oid)) throw new Problem('Условия пакетного предложения зафиксированы. Для изменения используйте новую редакцию после отклонения.',409);
                if ($act==='client.offer.edit') query($db,'UPDATE client_offers SET scope=?,currency=? WHERE id=?',[criteriaText($f['scope']??''),choice($f['currency']??'',array_combine(CURRENCIES,CURRENCIES)),$oid]);
                elseif ($act==='client.publish') {
                    $stages=query($db,'SELECT * FROM client_stages WHERE offer_id=?',[$oid])->fetchAll(); if (!$stages) throw new Problem('Добавьте хотя бы один этап.',409); clientTotals($stages);
                    query($db,"UPDATE client_offers SET status='offered',offered_at=? WHERE id=?",[time(),$oid]);
                } else {
                    $sid=optionalId($f['stage_id']??''); if ($sid!==null) entity($db,'SELECT id FROM client_stages WHERE id=? AND offer_id=?',[$sid,$oid]);
                    if ($act==='client.stage.remove') { if ($sid===null) throw new Problem('Выберите этап.'); query($db,'DELETE FROM client_stages WHERE id=?',[$sid]); }
                    else {
                        $values=[word($f['title']??'',180,'Этап'),criteriaText($f['scope']??''),calendarDate($f['due_on']??''),clientMoney($f['amount']??'')];
                        if ($sid===null) { if ((int)query($db,'SELECT COUNT(*) FROM client_stages WHERE offer_id=?',[$oid])->fetchColumn()>=100) throw new Problem('Не более 100 этапов.'); query($db,'INSERT INTO client_stages(order_id,offer_id,project_id,title,scope,due_on,amount_minor) VALUES(?,?,?,?,?,?,?)',[$id,$oid,$project,...$values]); }
                        else query($db,'UPDATE client_stages SET title=?,scope=?,due_on=?,amount_minor=? WHERE id=?',[...$values,$sid]);
                    }
                    clientTotals(query($db,'SELECT amount_minor FROM client_stages WHERE offer_id=?',[$oid])->fetchAll());
                }
                query($db,'UPDATE client_offers SET version=version+1 WHERE id=?',[$oid]);
            } elseif (in_array($act,['client.task','client.deliver'],true)) {
                $stage=clientStage($db,$id,numberId($f['stage_id']??0)); $sid=(int)$stage['id']; if ($stage['offer_status']!=='accepted') throw new Problem('Сначала клиент должен принять предложение.',409);
                $latest=query($db,'SELECT * FROM client_deliveries WHERE stage_id=? ORDER BY revision DESC LIMIT 1',[$sid])->fetch();
                if ($act==='client.task') {
                    if ($latest) throw new Problem('После первой сдачи состав задач неизменяем.',409);
                    $tid=numberId($f['task_id']??0); entity($db,'SELECT id FROM tasks WHERE project_id=? AND id=?',[$project,$tid]);
                    if (($f['remove']??'')==='1') query($db,'DELETE FROM client_stage_tasks WHERE stage_id=? AND task_id=?',[$sid,$tid]);
                    else { $existing=query($db,'SELECT stage_id FROM client_stage_tasks WHERE task_id=?',[$tid])->fetchColumn(); if ($existing!==false && (int)$existing!==$sid) throw new Problem('Задача уже относится к другому этапу.',409); query($db,'INSERT INTO client_stage_tasks(stage_id,project_id,task_id) VALUES(?,?,?) ON CONFLICT DO NOTHING',[$sid,$project,$tid]); }
                } else {
                    if ($latest && $latest['status']!=='returned') throw new Problem('Результат уже на приёмке или принят.',409);
                    if (!query($db,'SELECT 1 FROM client_stage_tasks WHERE stage_id=?',[$sid])->fetchColumn() || query($db,"SELECT 1 FROM client_stage_tasks st JOIN tasks t ON t.id=st.task_id WHERE st.stage_id=? AND t.state<>'done'",[$sid])->fetchColumn()) throw new Problem('Привяжите задачи и завершите их перед сдачей.',409);
                    $doc=null; $rev=null; $pair=word($f['document']??'',64,'Редакция PDF',false);
                    if ($pair!=='') { if (!preg_match('/\A([0-9]+):([0-9]+)\z/',$pair,$m)) throw new Problem('Неверная редакция.'); $doc=numberId($m[1]); $rev=numberId($m[2]); entity($db,'SELECT d.id FROM documents d JOIN document_versions v ON v.document_id=d.id WHERE d.project_id=? AND d.id=? AND v.revision=?',[$project,$doc,$rev]); retentionDocumentBytes($db,$doc,$rev); }
                    query($db,'INSERT INTO client_deliveries(stage_id,project_id,revision,result_text,document_id,document_revision,submitted_by,submitted_at) VALUES(?,?,?,?,?,?,?,?)',[$sid,$project,1+(int)($latest['revision']??0),criteriaText($f['result_text']??''),$doc,$rev,$u['id'],time()]);
                }
            } else throw new Problem('Неизвестное действие.',404);
        }
        query($db,'INSERT INTO action_log(actor_id,request_key,fingerprint,action,object_id) VALUES(?,?,?,?,?)',[$u['id'],$key,$digest,$act,$id]); auditEvent($db,$u,$project,$act,$id); return $id;
    });
}
// A grant is linked to exactly one order; matching email alone never grants access.
function guestClientOrder(PDO $db,int $guest): ?array {
    $r=query($db,'SELECT o.* FROM client_order_guests l JOIN client_orders o ON o.id=l.order_id AND o.project_id=l.project_id JOIN guest_invites g ON g.id=l.guest_id AND g.project_id=l.project_id WHERE g.id=? AND g.email=o.contact_email AND g.accepted_at IS NOT NULL AND g.revoked_at IS NULL AND g.access_until>?',[$guest,time()])->fetch(); return $r?:null;
}
function clientGuestDecision(PDO $db,int $guest,string $act,int $id,int $version,bool $accept,string $note,string $sessionHash): void {
    $note=word($note,1000,'Комментарий',false); if (!$accept && $note==='') throw new Problem('Укажите причину возврата.');
    atomic($db,function() use($db,$guest,$act,$id,$version,$accept,$note,$sessionHash) {
        if (!query($db,'SELECT 1 FROM oidc_sessions WHERE handle=? AND guest_id=? AND revoked_at IS NULL AND expires_at>?',[$sessionHash,$guest,time()])->fetchColumn()) throw new Problem('Сессия отозвана.',403);
        $o=guestClientOrder($db,$guest); if (!$o) throw new Problem('Нет доступа к заказу.',403);
        if ($act==='guest.offer') {
            $r=clientOffer($db,(int)$o['id'],$id); $target=$accept?'accepted':'rejected';
            if ($r['status']===$target && (int)$r['decided_guest']===$guest && $r['decision_note']===$note && (int)$r['version']===$version+1) return;
            clientVersion($r,$version); if ($r['status']!=='offered') throw new Problem('Предложение уже обработано.',409);
            query($db,'UPDATE client_offers SET status=?,decided_at=?,decided_guest=?,decision_note=?,version=version+1 WHERE id=?',[$target,time(),$guest,$note,$id]);
        } elseif ($act==='guest.delivery') {
            $r=entity($db,'SELECT d.* FROM client_deliveries d JOIN client_stages s ON s.id=d.stage_id JOIN client_offers f ON f.id=s.offer_id WHERE d.id=? AND s.order_id=? AND f.status=\'accepted\'',[$id,$o['id']]);
            $target=$accept?'accepted':'returned'; if ((int)$r['revision']!==$version) throw new Problem('Редакция изменилась.',409);
            if ($r['status']===$target && (int)$r['decided_guest']===$guest && $r['decision_note']===$note) return;
            if ($r['status']!=='submitted') throw new Problem('Результат уже обработан.',409);
            $minutes=null;
            if ($accept) {
                if (query($db,"SELECT 1 FROM client_stage_tasks st JOIN tasks t ON t.id=st.task_id WHERE st.stage_id=? AND t.state<>'done'",[$r['stage_id']])->fetchColumn()) throw new Problem('Задачи этапа снова открыты. Запросите доработку.',409);
                $minutes=0; $rows=query($db,"SELECT e.id,e.minutes FROM time_entries e JOIN timesheets t ON t.id=e.sheet_id JOIN client_stage_tasks st ON st.task_id=e.task_id AND st.project_id=e.project_id WHERE st.stage_id=? AND t.status='approved' ORDER BY e.id",[$r['stage_id']])->fetchAll();
                foreach ($rows as $entry) { query($db,'INSERT INTO client_stage_hours(delivery_id,entry_id,minutes) VALUES(?,?,?)',[$id,$entry['id'],$entry['minutes']]); $minutes+=(int)$entry['minutes']; }
            }
            query($db,'UPDATE client_deliveries SET status=?,decided_at=?,decided_guest=?,decision_note=?,accepted_minutes=? WHERE id=?',[$target,time(),$guest,$note,$minutes,$id]);
        } else throw new Problem('Действие недоступно.',403);
    });
}
function clientOrderDetails(PDO $db,array $order,bool $guest=false): array {
    $id=(int)$order['id']; $offers=query($db,'SELECT f.*,g.email decision_email FROM client_offers f LEFT JOIN guest_invites g ON g.id=f.decided_guest WHERE f.order_id=?'.($guest?" AND f.status<>'draft'":'').' ORDER BY f.revision DESC',[$id])->fetchAll();
    foreach ($offers as &$offer) {
        $offer['stages']=query($db,'SELECT * FROM client_stages WHERE offer_id=? ORDER BY id',[$offer['id']])->fetchAll(); $offer['total']=clientTotals($offer['stages']);
        foreach ($offer['stages'] as &$s) {
            $s['deliveries']=query($db,'SELECT d.*,g.email decision_email FROM client_deliveries d LEFT JOIN guest_invites g ON g.id=d.decided_guest WHERE d.stage_id=? ORDER BY d.revision DESC',[$s['id']])->fetchAll();
            if (!$guest) {
                $s['tasks']=query($db,'SELECT t.id,t.title,t.state FROM client_stage_tasks st JOIN tasks t ON t.id=st.task_id WHERE st.stage_id=? ORDER BY t.id',[$s['id']])->fetchAll();
                foreach ($s['deliveries'] as &$d) if ($d['status']==='accepted') $d['hours']=query($db,'SELECT h.minutes,e.worked_on,e.task_id,e.sheet_id,t.week_on,u.name FROM client_stage_hours h JOIN time_entries e ON e.id=h.entry_id JOIN timesheets t ON t.id=e.sheet_id JOIN users u ON u.id=t.user_id WHERE h.delivery_id=? ORDER BY e.worked_on,e.id',[$d['id']])->fetchAll();
                unset($d);
            }
        } unset($s);
    } unset($offer);
    return $offers;
}
function clientsPage(PDO $db,array $user,?int $id,int $page): array {
    $u=orgOwner($db,$user); if (!mfaFresh()) throw new Problem('Подтвердите второй фактор в разделе «Безопасность».',403);
    $org=$u['organization_id']; if ($page>1000000) throw new Problem('Некорректная страница.'); $offset=($page-1)*50;
    if ($id===null) return ['parties'=>query($db,"SELECT * FROM counterparties WHERE organization_id=? ORDER BY id DESC LIMIT 51 OFFSET $offset",[$org])->fetchAll(),'contacts'=>query($db,'SELECT c.*,p.name party_name FROM counterparty_contacts c JOIN counterparties p ON p.id=c.counterparty_id WHERE c.organization_id=? ORDER BY p.name,c.name,c.id',[$org])->fetchAll(),'orders'=>query($db,"SELECT o.*,p.title project_title FROM client_orders o JOIN projects p ON p.id=o.project_id WHERE o.organization_id=? ORDER BY o.id DESC LIMIT 51 OFFSET $offset",[$org])->fetchAll(),'projects'=>array_column(query($db,"SELECT id,title FROM projects WHERE organization_id=? AND kind='client' ORDER BY title,id",[$org])->fetchAll(),'title','id'),'pageNo'=>$page];
    $o=clientOrder($db,$u,$id); return ['order'=>$o,'offers'=>clientOrderDetails($db,$o),'tasks'=>query($db,'SELECT id,title,state FROM tasks WHERE project_id=? ORDER BY id',[$o['project_id']])->fetchAll(),'versions'=>query($db,'SELECT d.id,d.title,v.revision FROM documents d JOIN document_versions v ON v.document_id=d.id WHERE d.project_id=? ORDER BY d.id,v.revision DESC',[$o['project_id']])->fetchAll(),'grants'=>query($db,'SELECT g.* FROM client_order_guests l JOIN guest_invites g ON g.id=l.guest_id WHERE l.order_id=? ORDER BY g.id DESC',[$id])->fetchAll()];
}
