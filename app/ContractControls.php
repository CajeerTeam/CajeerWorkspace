<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

function contractPartyDigest(string $snapshot,string $side): string {
    $party=json_decode($snapshot,true,512,JSON_THROW_ON_ERROR)[$side];
    // Freeze identifying details, not the unrelated mutable party version counter.
    $identity=['name'=>(string)$party['name'],'registration'=>(string)$party['registration']];
    return hash('sha256',json_encode($identity,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
}
// A registry entry is an owner's recorded verification, not cryptographic or legal validation.
function contractAuthorityCheck(array $a,array $r,string $side): void {
    if($a['side']!==$side || $a['signer']!==$r[$side.'_signer'] || $a['basis']!==$r[$side.'_authority'] || $a['revoked_at']!==null || $r['signed_on']<$a['valid_from'] || $r['signed_on']>$a['valid_until'] || !hash_equals($a['party_digest'],contractPartyDigest($r['party_snapshot'],$side)))
        throw new Problem('Полномочия подписанта не соответствуют редакции, отозваны или не действовали на дату подписания.',409);
}
function contractTermsDelta(?array $old,array $new): array {
    $keys=['currency','amount_minor','signed_on','starts_on','ends_on','notice_on','renewal_terms'];
    $pick=static function(array $r)use($keys):array { $out=array_intersect_key($r,array_flip($keys));$out['amount_minor']=(int)$out['amount_minor'];return $out; };
    $before=$old?$pick($old):null;$after=$pick($new);
    return ['before'=>$before,'after'=>$after,'price_delta_minor'=>$old&&$old['currency']===$new['currency']?(int)$new['amount_minor']-(int)$old['amount_minor']:null];
}
function contractAuthorityRecord(PDO $db,array $u,array $c,array $f): void {
    orgOwner($db,$u);
    $side=choice($f['side'],['seller'=>'','buyer'=>'']);$from=calendarDate($f['valid_from']);$until=calendarDate($f['valid_until']);
    if($from>$until)throw new Problem('Некорректный период полномочий.');
    if((int)query($db,'SELECT COUNT(*) FROM contract_authorities WHERE contract_id=?',[$c['id']])->fetchColumn()>=500)throw new Problem('Достигнут лимит записей полномочий.');
    query($db,'INSERT INTO contract_authorities(project_id,contract_id,side,signer,basis,party_digest,valid_from,valid_until,verified_by,verified_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$c['project_id'],$c['id'],$side,word($f['signer'],180,'Подписант'),word($f['basis'],500,'Документ и основание полномочий'),contractPartyDigest(contractSnapshot($db,$c),$side),$from,$until,$u['id'],time()]);
}
function contractAuthorityRevoke(PDO $db,array $u,array $c,array $f): void {
    orgOwner($db,$u);$a=entity($db,'SELECT * FROM contract_authorities WHERE contract_id=? AND id=?',[$c['id'],numberId($f['authority_id'])]);
    if($a['revoked_at']!==null)throw new Problem('Полномочия уже отозваны.',409);
    query($db,'UPDATE contract_authorities SET revoked_by=?,revoked_at=?,revoke_reason=? WHERE id=?',[$u['id'],time(),word($f['reason'],500,'Причина отзыва'),$a['id']]);
}
function contractActivationEvidence(PDO $db,array $u,array $c,array $r,?array $active,array $f): void {
    if($f['confirm_terms']!=='1')throw new Problem('Подтвердите цену, валюту и сроки редакции.');
    $authorities=[];
    foreach(['seller','buyer'] as $side){
        $a=entity($db,'SELECT * FROM contract_authorities WHERE contract_id=? AND id=?',[$c['id'],numberId($f[$side.'_authority_id'])]);
        contractAuthorityCheck($a,$r,$side);$authorities[$side]=$a;
    }
    query($db,'INSERT INTO contract_activation_evidence(revision_id,project_id,contract_id,seller_authority_id,buyer_authority_id,previous_revision_id,terms_snapshot,authority_snapshot) VALUES(?,?,?,?,?,?,?,?)',[$r['id'],$c['project_id'],$c['id'],$authorities['seller']['id'],$authorities['buyer']['id'],$active['id']??null,json_encode(contractTermsDelta($active,$r),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),json_encode($authorities,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
}
// Deliberately limited system action: legal staff can create only this fixed, unassigned task.
// Legal content, prices, party identities and PDFs never enter the general task backlog.
function contractControlTask(PDO $db,array $u,array $c,int $revision,string $kind,string $due,?int $obligation=null): int {
    $p=(int)$c['project_id'];requireProjectRight($db,$u,$p,'legal.write');
    $title=match($kind){'notice'=>'Контроль срока уведомления','expiry'=>'Контроль окончания договора','obligation'=>'Контроль исполнения обязательства',default=>throw new Problem('Неизвестный контрольный срок.')};
    $existing=query($db,'SELECT task_id FROM contract_controls WHERE contract_id=? AND revision_id=? AND kind=? AND '.($obligation===null?'obligation_id IS NULL':'obligation_id=?'),[$c['id'],$revision,$kind,...($obligation===null?[]:[$obligation])])->fetchColumn();
    if($existing!==false)return (int)$existing;
    $task=insertId($db,'INSERT INTO tasks(project_id,title,assignee_id,due_on,priority,result_customer,acceptance_criteria) VALUES(?,?,?,?,?,?,?)',[$p,$title,null,calendarDate($due),2,'Юридический ответственный','Передать результат проверки юридическому ответственному. Закрытие задачи не заменяет юридическое решение.']);
    query($db,'INSERT INTO contract_controls(task_id,project_id,contract_id,revision_id,obligation_id,kind,due_on,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$task,$p,$c['id'],$revision,$obligation,$kind,$due,$u['id'],time()]);
    auditEvent($db,$u,$p,'contract.control.task',$task);return $task;
}
