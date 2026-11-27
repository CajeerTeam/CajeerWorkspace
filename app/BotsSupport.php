<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

// The only supported upstream is the shipped adapter on the pinned Bots source.
const BOTS_UPSTREAM_VERSION='1.0.2';
const BOTS_UPSTREAM_COMMIT='f1366da1c548b124af379b139f381dbc3ab165e6';
const BOTS_DELIVERY_API='workspace-delivery-v1';
function botsExternalId(mixed $v): string {
    if(!is_string($v)||!preg_match('/\A[1-9][0-9]{0,23}\z/D',$v))throw new Problem('ID сообщения канала: 1–24 цифры, без ведущих нулей.');
    return $v;
}
function botsSupportSnapshot(PDO $db,int $project,?int $id): ?array {
    if($id===null)return null;
    $s=entity($db,'SELECT id,version FROM ops_support WHERE project_id=? AND id=?',[$project,$id]);
    return ['support_id'=>(int)$s['id'],'support_version'=>(int)$s['version']];
}
function botsSupportLink(PDO $db,int $project,int $message): ?array {
    $r=query($db,'SELECT support_id,support_version FROM bots_support_links WHERE project_id=? AND message_id=?',[$project,$message])->fetch();
    return $r?['support_id'=>(int)$r['support_id'],'support_version'=>(int)$r['support_version']]:null;
}
function botsSupportAssert(PDO $db,array $r,?array $link): void {
    if($link!==null&&botsSupportSnapshot($db,(int)$r['project_id'],$link['support_id'])!==$link)throw new Problem('Запрос поддержки изменился. Отмените неотправленный черновик и подготовьте новый.',409);
}
function botsFeedback(PDO $db,array $user,int $project,int $service,mixed $external,mixed $received,mixed $title,mixed $description,mixed $requester): int {
    if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);
    $u=botsActor($db,$user,$project);$external=botsExternalId($external);$title=word($title,180,'Тема');$description=criteriaText($description);$requester=word($requester,180,'Автор');
    if(!is_string($received)||!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\z/D',$received))throw new Problem('Укажите время сообщения UTC.');
    $dt=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$received,new \DateTimeZone('UTC'));
    if(!$dt||$dt->format('Y-m-d\TH:i')!==$received||$dt->getTimestamp()>time()||$dt->getTimestamp()<0)throw new Problem('Некорректное время сообщения UTC.');
    $at=$dt->getTimestamp();$fingerprint=hash('sha256',json_encode([$service,$external,$at,$title,$description,$requester],JSON_THROW_ON_ERROR));
    return atomic($db,function()use($db,$u,$project,$service,$external,$at,$title,$description,$requester,$fingerprint){
        $u=botsActor($db,$u,$project);if(staffHandle()!==null&&!staffSessionValid($db,$u))throw new Problem('Сессия отозвана.',403);
        $b=botsBinding($db,$project);if(!$b||!(int)$b['active'])throw new Problem('Канал Bots отключён.',409);botsBound($b);opsService($db,$project,$service);
        $old=query($db,'SELECT support_id,fingerprint FROM bots_feedback WHERE project_id=? AND identity_hash=? AND external_id=?',[$project,$b['identity_hash'],$external])->fetch();
        if($old){if(!hash_equals($old['fingerprint'],$fingerprint))throw new Problem('Этот ID уже зарегистрирован с другими данными.',409);return (int)$old['support_id'];}
        // Never send the text automatically. Existing support workflow owns resolution and incident links.
        $id=insertId($db,'INSERT INTO ops_support(project_id,service_id,title,description,requester,created_by,created_at) VALUES(?,?,?,?,?,?,?)',[$project,$service,$title,$description,$requester,$u['id'],time()]);
        query($db,'INSERT INTO bots_feedback(support_id,project_id,identity_hash,external_id,fingerprint,received_at,recorded_by,recorded_at) VALUES(?,?,?,?,?,?,?,?)',[$id,$project,$b['identity_hash'],$external,$fingerprint,$at,$u['id'],time()]);
        auditEvent($db,$u,$project,'bots.feedback',$id);return $id;
    });
}
function botsReceiptDetail(array $r,string $state,int $attempts,?string $pid): string {
    return json_encode(['remote_id'=>$r['remote_id'],'revision'=>$r['target_revision'],'message_sha256'=>$r['message_hash'],'state'=>$state,'attempts'=>$attempts,'provider_message_id'=>$pid],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
}
function botsQueueReason(array $r,int $now): string {
    if($r['state']==='uncertain')return 'Неопределённая доставка: только сверка';
    if($r['state']==='failed')return 'Отказ до передачи';
    if($r['state']==='retryable')return 'Подтверждённый отказ до вызова провайдера';
    if($r['state']==='processing'&&(int)$r['lease_until']<=$now)return 'Истекла аренда worker';
    if(in_array($r['state'],['queued','pending','sending'],true)&&$now-(int)$r['state_at']>=900)return 'Нет изменения состояния ≥ 15 минут';
    return '';
}
function botsQueue(PDO $db,int $p,?int $before=null): array {
    // Use last state transition, not updated_at: successful polling must not hide a stalled job.
    $now=time();$rows=query($db,"SELECT q.* FROM (SELECT m.*,COALESCE((SELECT MAX(e.created_at) FROM bots_events e WHERE e.message_id=m.id AND e.project_id=m.project_id AND e.state=m.state),m.created_at) AS state_at FROM bots_messages m WHERE m.project_id=? AND m.id<? AND m.state IN ('uncertain','failed','retryable','queued','pending','sending','processing')) q WHERE q.state IN ('uncertain','failed','retryable') OR (q.state='processing' AND q.lease_until<=?) OR (q.state IN ('queued','pending','sending') AND q.state_at<=?) ORDER BY q.id DESC LIMIT 51",[$p,$before??PHP_INT_MAX,$now,$now-900])->fetchAll();
    foreach($rows as &$r)$r['reason']=botsQueueReason($r,$now);unset($r);return $rows;
}
