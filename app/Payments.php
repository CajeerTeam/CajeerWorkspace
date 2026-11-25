<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

function paymentData(array $form): array {
    $date=calendarDate($form['booked_on']??'');
    if ($date>gmdate('Y-m-d')) throw new Problem('Для фактического движения денег укажите сегодняшнюю или прошедшую дату.');
    return [choice($form['direction']??'',DIRECTIONS),moneyMinor($form['amount']??''),choice($form['currency']??'',array_flip(CURRENCIES)),$date,word($form['memo']??'',200,'Назначение')];
}
function checkReversal(PDO $db,int $id): array {
    $entry=entity($db,'SELECT * FROM cash_entries WHERE id=?',[$id]);
    if ($entry['reversal_of']!==null || query($db,'SELECT 1 FROM cash_entries WHERE reversal_of=?',[$id])->fetchColumn()) throw new Problem('Для этой записи сторно уже проведено.',409);
    return $entry;
}
function postPayment(PDO $db,array $p,int $actor): int {
    if ($p['reversal_of']!==null) checkReversal($db,(int)$p['reversal_of']);
    return insertId($db,'INSERT INTO cash_entries(project_id,direction,amount_minor,currency,booked_on,memo,reversal_of,actor_id) VALUES(?,?,?,?,?,?,?,?)',[$p['project_id'],$p['direction'],$p['amount_minor'],$p['currency'],$p['booked_on'],$p['memo'],$p['reversal_of'],$actor]);
}
function submitPayment(PDO $db,array $user,string $key,int $project,array $data,?int $reversal=null): array {
    $u=organizationUser($db,$user); requireProjectRight($db,$u,$project,'payment.create');
    $name=$reversal===null?'payment.submit':'payment.reverse';
    $id=action($db,$u,$key,$name,[$project,$data,$reversal],function() use($db,$u,$project,$data,$reversal) {
        requireProjectRight($db,$u,$project,'payment.create');
        if ($reversal!==null) {
            $entry=checkReversal($db,$reversal);
            if ((int)$entry['project_id']!==$project) throw new Problem('Запись не найдена.',404);
            if (query($db,"SELECT 1 FROM payment_requests WHERE reversal_of=? AND status='pending'",[$reversal])->fetchColumn()) throw new Problem('Сторно уже ожидает решения.',409);
            $data=[$entry['direction'],-(int)$entry['amount_minor'],$entry['currency'],gmdate('Y-m-d'),'Сторно · '.$entry['memo']];
        }
        [$direction,$amount,$currency,$date,$memo]=$data;
        // A creator-only role cannot bypass approval even when direct posting is enabled.
        $dual=dualControl($db,$project) || !roleAllows(projectRole($db,$u,$project),'payment.approve');
        $p=['project_id'=>$project,'direction'=>$direction,'amount_minor'=>$amount,'currency'=>$currency,'booked_on'=>$date,'memo'=>$memo,'reversal_of'=>$reversal];
        $cash=$dual?null:postPayment($db,$p,(int)$u['id']);
        return insertId($db,'INSERT INTO payment_requests(project_id,direction,amount_minor,currency,booked_on,memo,reversal_of,created_by,dual_control,status,decided_by,decided_at,cash_entry_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[$project,$direction,$amount,$currency,$date,$memo,$reversal,$u['id'],(int)$dual,$dual?'pending':'posted',$dual?null:$u['id'],$dual?null:gmdate('Y-m-d H:i:s'),$cash]);
    });
    return entity($db,'SELECT * FROM payment_requests WHERE id=?',[$id]);
}
function decidePayment(PDO $db,array $user,string $key,int $id,string $decision): int {
    choice($decision,['approve'=>'','reject'=>'','cancel'=>'']);
    $u=organizationUser($db,$user);
    $p=entity($db,'SELECT project_id,created_by FROM payment_requests WHERE id=?',[$id]);
    $project=(int)$p['project_id'];
    requireProjectRight($db,$u,$project,$decision==='cancel'?'payment.create':'payment.approve');
    if ($decision==='cancel' && (int)$p['created_by']!==(int)$u['id']) throw new Problem('Отменить заявку может только её автор.',403);
    if ($decision==='approve' && (int)$p['created_by']===(int)$u['id']) throw new Problem('Автор не может утверждать собственную заявку.',403);
    return action($db,$u,$key,'payment.'.$decision,[$id],function() use($db,$u,$id,$project,$decision) {
        requireProjectRight($db,$u,$project,$decision==='cancel'?'payment.create':'payment.approve');
        $p=entity($db,'SELECT * FROM payment_requests WHERE id=?',[$id]);
        if ($p['status']!=='pending') throw new Problem('Заявка уже обработана.',409);
        if ($decision==='cancel' && (int)$p['created_by']!==(int)$u['id']) throw new Problem('Отменить заявку может только её автор.',403);
        if ($decision==='approve') {
            if ((int)$p['created_by']===(int)$u['id']) throw new Problem('Автор не может утверждать собственную заявку.',403);
            // Revocation of the original creator's authority invalidates pending execution.
            requireProjectRight($db,['id'=>(int)$p['created_by'],'organization_id'=>$u['organization_id']],$project,'payment.create');
        }
        $cash=$decision==='approve'?postPayment($db,$p,(int)$u['id']):null;
        $status=match($decision){'approve'=>'posted','reject'=>'rejected','cancel'=>'cancelled'};
        if (query($db,"UPDATE payment_requests SET status=?,decided_by=?,decided_at=?,cash_entry_id=? WHERE id=? AND status='pending'",[$status,$u['id'],gmdate('Y-m-d H:i:s'),$cash,$id])->rowCount()!==1) throw new Problem('Заявка уже обработана.',409);
        return $id;
    });
}
function paymentQueue(PDO $db,array $user,?int $project,?int $before=null): array {
    if ($project!==null) requireProjectRight($db,$user,$project,'finance');
    [$scope,$args]=projectRightScope($db,$user,'finance');
    $where=$scope;
    if ($project!==null) { $where.=' AND p.id=?'; $args[]=$project; }
    if ($before!==null) { $where.=' AND r.id<?'; $args[]=$before; }
    return query($db,"SELECT r.*,p.title project_title,u.name creator,v.name decider FROM payment_requests r JOIN projects p ON p.id=r.project_id JOIN users u ON u.id=r.created_by LEFT JOIN users v ON v.id=r.decided_by WHERE $where ORDER BY r.id DESC LIMIT 51",$args)->fetchAll();
}

// Must run inside the same transaction as the access change; no stale request may be resurrected by a later grant.
function cancelPendingByCreator(PDO $db,array $actor,int $uid,?int $project=null): void {
    $args=[$uid,$actor['organization_id']];
    $where="r.created_by=? AND p.organization_id=? AND r.status='pending'";
    if ($project!==null) { $where.=' AND p.id=?'; $args[]=$project; }
    $rows=query($db,"SELECT r.id,r.project_id FROM payment_requests r JOIN projects p ON p.id=r.project_id WHERE $where",$args)->fetchAll();
    foreach ($rows as $row) {
        query($db,"UPDATE payment_requests SET status='cancelled',decided_by=?,decided_at=? WHERE id=? AND status='pending'",[$actor['id'],gmdate('Y-m-d H:i:s'),$row['id']]);
        auditEvent($db,$actor,(int)$row['project_id'],'payment.access_revoked',(int)$row['id']);
    }
}
