<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const PM_STATES=['pending'=>'На согласовании','accepted'=>'Принято клиентом','rejected'=>'Отклонено','applied'=>'Применено','cancelled'=>'Отменено'];
function pmAccess(PDO $db,array $user,int $project): array {
    $u=organizationUser($db,$user);requireProjectRight($db,$u,$project,'manage');
    if(staffHandle()!==null&&!staffSessionValid($db,$u))throw new Problem('Сессия отозвана.',403);
    if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);
    return $u;
}
function pmCalendar(PDO $db,int $p): array {
    $ms=[];foreach(query($db,'SELECT id,title,due_on,state,version FROM milestones WHERE project_id=? ORDER BY id',[$p])->fetchAll() as $m){$m['id']=(int)$m['id'];$m['version']=(int)$m['version'];$ms[$m['id']]=$m;}
    $edges=[];foreach(query($db,'SELECT milestone_id,prerequisite_id FROM pm_milestone_dependencies WHERE project_id=? ORDER BY milestone_id,prerequisite_id',[$p])->fetchAll() as $e)$edges[]=[(int)$e['milestone_id'],(int)$e['prerequisite_id']];
    return ['milestones'=>$ms,'edges'=>$edges];
}
function pmJson(array $x): string{return json_encode($x,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}
function pmFingerprint(array $x): string{return hash('sha256',pmJson($x));}
function pmValidateGraph(array $cal): void {
    $ms=$cal['milestones'];$pre=[];foreach($cal['edges'] as [$id,$dep]){
        if(!isset($ms[$id],$ms[$dep])||$id===$dep)throw new Problem('Некорректная зависимость вех.',409);
        $pre[$id][]=$dep;
    }
    $vis=[];$visit=function($id)use(&$visit,&$vis,$pre){if(($vis[$id]??0)===1)throw new Problem('Циклическая зависимость вех.',409);if(($vis[$id]??0)===2)return;$vis[$id]=1;foreach($pre[$id]??[] as $d)$visit($d);$vis[$id]=2;};
    foreach(array_keys($ms) as $id)$visit($id);
    foreach($cal['edges'] as [$id,$dep]){
        if(!$ms[$id]['due_on']||!$ms[$dep]['due_on']||$ms[$id]['due_on']<$ms[$dep]['due_on'])throw new Problem('Срок зависимой вехи должен быть не раньше срока предварительной.',409);
        if($ms[$id]['state']==='closed'&&$ms[$dep]['state']!=='closed')throw new Problem('Закрытая веха зависит от незавершённой.',409);
    }
}
function pmImpact(array $cal,int $id,string $due): array {
    pmValidateGraph($cal);$ms=&$cal['milestones'];if(!isset($ms[$id])||$ms[$id]['state']!=='open')throw new Problem('Выберите открытую веху.',409);
    $ms[$id]['due_on']=calendarDate($due);
    // Propagate only forward conflicts. Existing gaps and unrelated dates are left unchanged.
    for($i=0;$i<count($ms);$i++){
        $changed=false;foreach($cal['edges'] as [$to,$from])if($ms[$to]['due_on']<$ms[$from]['due_on']){
            if($to===$id||$ms[$to]['state']!=='open')throw new Problem('Изменение нарушает срок предварительной или закрытой вехи.',409);
            $ms[$to]['due_on']=$ms[$from]['due_on'];$changed=true;
        }if(!$changed)break;
    }
    pmValidateGraph($cal);return $cal;
}
function pmLatest(PDO $db,int $p): ?array {return query($db,'SELECT * FROM pm_scope_versions WHERE project_id=? ORDER BY version DESC LIMIT 1',[$p])->fetch()?:null;}
function pmRow(PDO $db,int $p,int $n): array{return entity($db,'SELECT * FROM pm_changes WHERE project_id=? AND number=?',[$p,$n]);}
function pmSaveBaseline(PDO $db,int $p,int $v,string $scope,array $cal,int $actor): void {
    query($db,'INSERT INTO pm_scope_versions(project_id,version,scope,calendar_json,actor_id,created_at) VALUES(?,?,?,?,?,?)',[$p,$v,$scope,pmJson($cal),$actor,time()]);
}
function pmCommand(PDO $db,array $user,int $p,string $key,string $act,array $f): int {
    $u=pmAccess($db,$user,$p);unset($f['csrf'],$f['request_key']);ksort($f);
    return action($db,$u,$key,$act,[$p,$f],function()use($db,$u,$p,$act,$f,$key){
        $u=pmAccess($db,$u,$p);$actor=(int)$u['id'];$project=allowedProject($db,$u,$p);$latest=pmLatest($db,$p);$cal=pmCalendar($db,$p);
        if($act==='pm.baseline'){
            if($latest)throw new Problem('Базовый объём уже зафиксирован; используйте запрос на изменение.',409);
            if(!hash_equals(pmFingerprint($cal),(is_string($f['calendar']??null)?$f['calendar']:'')))throw new Problem('Календарь изменился.',409);
            pmValidateGraph($cal);pmSaveBaseline($db,$p,1,criteriaText($f['scope']??''),$cal,$actor);
        }elseif($act==='pm.dependency'){
            $id=numberId($f['milestone_id']??0);$pre=numberId($f['prerequisite_id']??0);$remove=choice($f['mode']??'',['add'=>'','remove'=>''])==='remove';
            if($id===$pre)throw new Problem('Веха не может зависеть от самой себя.',409);
            if(!isset($cal['milestones'][$id],$cal['milestones'][$pre]))throw new Problem('Веха недоступна.',404);
            if(!hash_equals(pmFingerprint($cal),(is_string($f['calendar']??null)?$f['calendar']:'')))throw new Problem('Календарь изменился.',409);
            if($remove)query($db,'DELETE FROM pm_milestone_dependencies WHERE project_id=? AND milestone_id=? AND prerequisite_id=?',[$p,$id,$pre]);
            else query($db,'INSERT INTO pm_milestone_dependencies(project_id,milestone_id,prerequisite_id) VALUES(?,?,?) ON CONFLICT DO NOTHING',[$p,$id,$pre]);
            pmValidateGraph(pmCalendar($db,$p));
        }elseif($act==='pm.propose'){
            if(!$latest||(int)$latest['version']!==numberId($f['baseline']??0)||!hash_equals(pmFingerprint($cal),(is_string($f['calendar']??null)?$f['calendar']:'')))throw new Problem('Базовый план или календарь изменился.',409);
            $mid=optionalId($f['milestone_id']??'');$due=calendarDate($f['due_on']??'',true);if(($mid===null)!==($due===null))throw new Problem('Выберите веху и срок вместе либо оставьте оба поля пустыми.');$after=$mid===null?$cal:pmImpact($cal,$mid,$due);pmValidateGraph($after);$title=word($f['title']??'',180,'Изменение');$scope=criteriaText($f['scope']??'');$reason=criteriaText($f['reason']??'');
            $order=null;$currency=null;$amount=0;$pay=null;
            if($project['kind']==='client'){
                orgOwner($db,$u);$o=clientOrder($db,$u,numberId($f['order_id']??0));if((int)$o['project_id']!==$p)throw new Problem('Заказ другого проекта.',403);
                $offer=entity($db,"SELECT currency FROM client_offers WHERE order_id=? AND status='accepted'",[$o['id']]);$order=(int)$o['id'];$currency=$offer['currency'];$amount=econAmount($f['amount']??'');$pay=calendarDate($f['payment_due']??'');
                if($pay<gmdate('Y-m-d'))throw new Problem('Дата оплаты уже прошла.');
                if(($f['share']??'')!=='yes')throw new Problem('Подтвердите передачу нового объёма и календарного эффекта клиенту.');
            }
            $n=1+(int)query($db,'SELECT MAX(number) FROM pm_changes WHERE project_id=?',[$p])->fetchColumn();
            query($db,'INSERT INTO pm_changes(project_id,number,baseline_version,milestone_id,order_id,title,proposed_scope,reason,calendar_before,calendar_after,currency,amount_minor,payment_due,status,actor_id,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$p,$n,$latest['version'],$mid,$order,$title,$scope,$reason,pmJson($cal),pmJson($after),$currency,$amount,$pay,'pending',$actor,time()]);
        }elseif(in_array($act,['pm.apply','pm.cancel','pm.invoice'],true)){
            $n=numberId($f['number']??0);$r=pmRow($db,$p,$n);if($r['order_id']!==null)orgOwner($db,$u);
            if($act==='pm.cancel'){
                if(!in_array($r['status'],['pending','accepted'],true))throw new Problem('Запрос уже завершён.',409);
                query($db,"UPDATE pm_changes SET status='cancelled' WHERE project_id=? AND number=?",[$p,$n]);
            }elseif($act==='pm.apply'){
                if(($f['confirmed']??'')!=='yes'||$r['status']!==($r['order_id']===null?'pending':'accepted'))throw new Problem('Сначала подтвердите изменение и согласование.',409);
                if(!$latest||(int)$latest['version']!==(int)$r['baseline_version']||!hash_equals($r['calendar_before'],pmJson($cal)))throw new Problem('План изменился. Отмените запрос и подготовьте новую редакцию.',409);
                $after=json_decode($r['calendar_after'],true,512,JSON_THROW_ON_ERROR);pmValidateGraph($after);
                foreach($after['milestones'] as $id=>$m)if($m['due_on']!==$cal['milestones'][$id]['due_on'])query($db,'UPDATE milestones SET due_on=?,version=version+1 WHERE project_id=? AND id=?',[$m['due_on'],$p,$id]);
                pmSaveBaseline($db,$p,(int)$latest['version']+1,$r['proposed_scope'],pmCalendar($db,$p),$actor);
                query($db,"UPDATE pm_changes SET status='applied',applied_by=?,applied_at=? WHERE project_id=? AND number=?",[$actor,time(),$p,$n]);
            }else{
                if($r['status']!=='applied'||$r['order_id']===null||(int)$r['amount_minor']<=0||$r['invoice_id']!==null)throw new Problem('Дополнительный счёт недоступен.',409);
                $o=clientOrder($db,$u,(int)$r['order_id']);$entity=numberId($f['entity_id']??0);
                // Separate draft, never issue or post payments; nested domain operations share transaction.
                $i=invoiceCreate($db,$u,$p,substr(hash('sha256','pm.invoice.create:'.$key),0,32),['entity_id'=>$entity,'counterparty_id'=>$o['counterparty_id'],'currency'=>$r['currency'],'due_on'=>$r['payment_due'],'terms'=>'Дополнительные работы по согласованному запросу #'.$n]);
                invoiceChange($db,$u,$p,$i,substr(hash('sha256','pm.invoice.line:'.$key),0,32),'invoice.line',['version'=>1,'description'=>$r['title'],'quantity'=>1,'unit'=>money((int)$r['amount_minor'],false)]);
                query($db,'UPDATE pm_changes SET invoice_id=? WHERE project_id=? AND number=?',[$i,$p,$n]);
            }
        }else throw new Problem('Неизвестная операция.');
        return $p;
    });
}
// Called inside the invoice transaction before tax calculation and issue.
function pmInvoiceGuard(PDO $db,array $invoice): void {
    $r=query($db,"SELECT c.amount_minor,c.currency,c.payment_due,c.status,o.counterparty_id FROM pm_changes c JOIN client_orders o ON o.id=c.order_id AND o.project_id=c.project_id WHERE c.invoice_id=? AND c.project_id=?",[$invoice['id'],$invoice['project_id']])->fetch();
    if(!$r)return;
    if($r['status']!=='applied'||(int)$invoice['total_minor']!==(int)$r['amount_minor']||$invoice['currency']!==$r['currency']||$invoice['due_on']!==$r['payment_due']||(int)$invoice['counterparty_id']!==(int)$r['counterparty_id'])throw new Problem('Счёт не соответствует согласованному запросу на изменение: проверьте сумму без налогов, валюту, срок и контрагента.',409);
}
function pmGuestRows(PDO $db,array $order): array {
    // Only the explicit client-facing projection, never internal reason or milestone names.
    $rows=query($db,'SELECT number,title,proposed_scope,calendar_before,calendar_after,currency,amount_minor,payment_due,status,decision_note,decided_at FROM pm_changes WHERE project_id=? AND order_id=? ORDER BY number DESC',[$order['project_id'],$order['id']])->fetchAll();
    foreach($rows as &$r){$before=json_decode($r['calendar_before'],true,512,JSON_THROW_ON_ERROR);$after=json_decode($r['calendar_after'],true,512,JSON_THROW_ON_ERROR);$r['dates']=pmDateChanges($before,$after,false);unset($r['calendar_before'],$r['calendar_after']);}unset($r);return $rows;
}
function pmDateChanges(array $before,array $after,bool $staff=true): array {
    $rows=[];foreach($after['milestones'] as $id=>$m)if($m['due_on']!==$before['milestones'][$id]['due_on'])$rows[]=['title'=>$staff?$m['title']:'Веха','before'=>$before['milestones'][$id]['due_on'],'after'=>$m['due_on']];return $rows;
}
function pmGuestDecision(PDO $db,int $guest,int $n,bool $accept,string $note,string $handle): void {
    $note=word($note,1000,'Комментарий',false);if(!$accept&&$note==='')throw new Problem('Укажите причину отклонения.');
    atomic($db,function()use($db,$guest,$n,$accept,$note,$handle){
        if(!query($db,'SELECT 1 FROM oidc_sessions WHERE handle=? AND guest_id=? AND revoked_at IS NULL AND expires_at>?',[$handle,$guest,time()])->fetchColumn())throw new Problem('Сессия отозвана.',403);
        $o=guestClientOrder($db,$guest);if(!$o)throw new Problem('Нет доступа к заказу.',403);$p=(int)$o['project_id'];$r=entity($db,'SELECT * FROM pm_changes WHERE project_id=? AND number=? AND order_id=?',[$p,$n,$o['id']]);$state=$accept?'accepted':'rejected';
        if($r['status']===$state&&(int)$r['decided_guest']===$guest&&$r['decision_note']===$note)return;
        if($r['status']!=='pending')throw new Problem('Запрос уже обработан.',409);
        if($accept&&((int)(pmLatest($db,$p)['version']??0)!==(int)$r['baseline_version']||!hash_equals($r['calendar_before'],pmJson(pmCalendar($db,$p)))))throw new Problem('План изменился. Запросите актуальную редакцию.',409);
        query($db,'UPDATE pm_changes SET status=?,decided_guest=?,decided_at=?,decision_note=? WHERE project_id=? AND number=?',[$state,$guest,time(),$note,$p,$n]);
    });
}
function pmPage(PDO $db,array $user,int $p): array {
    return atomic($db,function()use($db,$user,$p){$u=pmAccess($db,$user,$p);$project=allowedProject($db,$u,$p);$cal=pmCalendar($db,$p);$latest=pmLatest($db,$p);$commercial=$u['role']==='owner';
        $versions=query($db,'SELECT * FROM pm_scope_versions WHERE project_id=? ORDER BY version DESC',[$p])->fetchAll();
        $changes=query($db,'SELECT '.($commercial?'*':'project_id,number,baseline_version,milestone_id,title,proposed_scope,reason,calendar_before,calendar_after,status,actor_id,created_at,applied_at').' FROM pm_changes WHERE project_id=? ORDER BY number DESC',[$p])->fetchAll();
        $orders=$commercial?query($db,"SELECT o.id,o.title,f.currency FROM client_orders o JOIN client_offers f ON f.order_id=o.id AND f.status='accepted' WHERE o.project_id=? ORDER BY o.id",[$p])->fetchAll():[];
        $entities=$commercial?query($db,'SELECT id,name FROM legal_entities WHERE organization_id=? ORDER BY name,id',[$u['organization_id']])->fetchAll():[];
        return compact('p','project','cal','latest','versions','changes','orders','entities','commercial');
    });
}
