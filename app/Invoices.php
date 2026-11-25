<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const INVOICE_LIMIT=99999999999;
function invoiceQuantity(mixed $value): int {
    $n=numberId($value); if($n>100000) throw new Problem('Количество не более 100 000.'); return $n;
}
function invoiceLineAmount(int $qty,int $unit): int {
    if($qty<1 || $qty>100000 || $unit<1 || $unit>intdiv(INVOICE_LIMIT,$qty)) throw new Problem('Слишком большая сумма позиции.');
    return $qty*$unit;
}
function invoiceNumber(array $r): string {
    return $r['number_seq']===null?'Черновик':sprintf('%d-%04d-%06d',(int)$r['entity_id'],(int)$r['number_year'],(int)$r['number_seq']);
}
function invoiceEntitySave(PDO $db,array $user,array $form): int {
    $id=optionalId($form['id']??''); $name=word($form['name']??'',180,'Название'); $reg=word($form['registration']??'',180,'Регистрация'); $address=word($form['address']??'',500,'Адрес'); $bank=word($form['bank_details']??'',500,'Банковские реквизиты',false);
    return atomic($db,function()use($db,$user,$form,$id,$name,$reg,$address,$bank){
        $u=orgOwner($db,$user); $org=$u['organization_id'];
        $old=query($db,'SELECT * FROM legal_entities WHERE organization_id=? AND registration=?',[$org,$reg])->fetch();
        if($old && (int)$old['id']!==$id){ if($id===null && $old['name']===$name && $old['address']===$address && $old['bank_details']===$bank)return (int)$old['id']; throw new Problem('Юрлицо с таким регистрационным номером уже существует.',409); }
        if($id===null)$id=insertId($db,'INSERT INTO legal_entities(organization_id,name,registration,address,bank_details) VALUES(?,?,?,?,?)',[$org,$name,$reg,$address,$bank]);
        elseif(query($db,'UPDATE legal_entities SET name=?,registration=?,address=?,bank_details=?,version=version+1 WHERE id=? AND organization_id=? AND version=?',[$name,$reg,$address,$bank,$id,$org,numberId($form['version']??0)])->rowCount()!==1)throw new Problem('Юрлицо не найдено или изменилось.',409);
        auditEvent($db,$u,null,'invoice.entity',$id); return $id;
    });
}
function invoiceCounterparties(PDO $db,array $u,int $project): array {
    $args=[$u['organization_id']]; $where='c.organization_id=?';
    if($u['role']!=='owner'){ $where.=' AND EXISTS(SELECT 1 FROM client_orders o WHERE o.counterparty_id=c.id AND o.organization_id=c.organization_id AND o.project_id=?)'; $args[]=$project; }
    return query($db,"SELECT c.id,c.name FROM counterparties c WHERE $where ORDER BY c.name,c.id",$args)->fetchAll();
}
function invoiceGet(PDO $db,array $user,int $project,int $id,string $right='finance'): array {
    requireProjectRight($db,$user,$project,$right);
    return entity($db,'SELECT * FROM invoices WHERE id=? AND project_id=?',[$id,$project]);
}
function invoiceCreate(PDO $db,array $user,int $project,string $key,array $form): int {
    $u=organizationUser($db,$user);requireProjectRight($db,$u,$project,'payment.create');
    $entity=numberId($form['entity_id']??0);$client=numberId($form['counterparty_id']??0);$currency=choice($form['currency']??'',array_flip(CURRENCIES));$due=calendarDate($form['due_on']??'');$terms=word($form['terms']??'',1000,'Условия оплаты');
    return action($db,$u,$key,'invoice.create',[$project,$entity,$client,$currency,$due,$terms],function()use($db,$u,$project,$entity,$client,$currency,$due,$terms){
        requireProjectRight($db,$u,$project,'payment.create');$org=$u['organization_id'];
        entity($db,'SELECT id FROM legal_entities WHERE id=? AND organization_id=?',[$entity,$org]);
        if(!array_key_exists($client,array_column(invoiceCounterparties($db,$u,$project),'name','id')))throw new Problem('Контрагент недоступен для проекта.',403);
        return insertId($db,'INSERT INTO invoices(organization_id,project_id,entity_id,counterparty_id,currency,due_on,terms,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$org,$project,$entity,$client,$currency,$due,$terms,$u['id'],time()]);
    });
}
function invoiceLines(PDO $db,int $id): array {return query($db,'SELECT * FROM invoice_lines WHERE invoice_id=? ORDER BY id',[$id])->fetchAll();}
function invoiceChange(PDO $db,array $user,int $project,int $id,string $key,string $act,array $form): int {
    $u=organizationUser($db,$user); invoiceGet($db,$u,$project,$id,'payment.create');
    $v=numberId($form['version']??0);$payload=match($act){
        'invoice.line'=>[word($form['description']??'',500,'Позиция'),invoiceQuantity($form['quantity']??0),moneyMinor($form['unit']??'')],
        'invoice.remove'=>[numberId($form['line_id']??0)],
        'invoice.terms'=>[calendarDate($form['due_on']??''),word($form['terms']??'',1000,'Условия оплаты')],
        'invoice.issue'=>isset($form['uk_confirm'])?[word($form['uk_confirm'],1,'Подтверждение'),numberId($form['uk_profile_version']??0),calendarDate($form['tax_point']??''),word($form['buyer_address']??'',500,'Адрес покупателя')]:[],
        'invoice.credit'=>[word($form['reason']??'',500,'Причина кредит-ноты')], 'invoice.void'=>[],default=>throw new Problem('Неизвестная операция.')};
    return action($db,$u,$key,$act,[$project,$id,$v,$payload],function()use($db,$u,$project,$id,$v,$act,$payload,$form){
        $i=invoiceGet($db,$u,$project,$id,'payment.create'); if((int)$i['version']!==$v)throw new Problem('Счёт изменился. Обновите страницу.',409);
        if($act==='invoice.credit'){ukCredit($db,$i,$u,$payload[0]);return $id;}
        if($act==='invoice.void'){
            if(ukDocument($db,$id))throw new Problem('Для VAT-счёта необходима кредит-нота.',409);
            if($i['state']!=='issued'||query($db,"SELECT 1 FROM invoice_movements m JOIN payment_requests r ON r.id=m.request_id WHERE m.invoice_id=? AND r.status IN ('pending','posted')",[$id])->fetchColumn())throw new Problem('Нельзя аннулировать счёт с проведёнными или ожидающими платежами.',409);
            query($db,"UPDATE invoices SET state='void',voided_by=?,voided_at=?,version=version+1 WHERE id=?",[$u['id'],time(),$id]);return $id;
        }
        if($i['state']!=='draft')throw new Problem('Выставленный счёт неизменяем.',409);
        if($act==='invoice.line'){
            [$description,$qty,$unit]=$payload;$amount=invoiceLineAmount($qty,$unit);
            if(count(invoiceLines($db,$id))>=100 || (int)$i['total_minor']>INVOICE_LIMIT-$amount)throw new Problem('Превышен лимит счёта.');
            query($db,'INSERT INTO invoice_lines(invoice_id,description,quantity,unit_minor) VALUES(?,?,?,?)',[$id,$description,$qty,$unit]);
            query($db,'UPDATE invoices SET total_minor=total_minor+?,version=version+1 WHERE id=?',[$amount,$id]);
        }elseif($act==='invoice.remove'){
            $line=entity($db,'SELECT * FROM invoice_lines WHERE id=? AND invoice_id=?',[$payload[0],$id]);query($db,'DELETE FROM invoice_lines WHERE id=?',[$line['id']]);query($db,'UPDATE invoices SET total_minor=total_minor-?,version=version+1 WHERE id=?',[invoiceLineAmount((int)$line['quantity'],(int)$line['unit_minor']),$id]);
        }elseif($act==='invoice.terms')query($db,'UPDATE invoices SET due_on=?,terms=?,version=version+1 WHERE id=?',[...$payload,$id]);
        else{
            $today=ukProfile($db,(int)$i['entity_id'])?ukToday():gmdate('Y-m-d');$year=(int)substr($today,0,4);$total=0;foreach(invoiceLines($db,$id) as $l)$total+=invoiceLineAmount((int)$l['quantity'],(int)$l['unit_minor']);
            if($total<1||$total!==(int)$i['total_minor']||$i['due_on']<$today)throw new Problem('Проверьте позиции, итог и срок оплаты.');
            if(!array_key_exists((int)$i['counterparty_id'],array_column(invoiceCounterparties($db,$u,$project),'name','id')))throw new Problem('Контрагент больше недоступен.',403);
            $seller=entity($db,'SELECT name,registration,address,bank_details,version FROM legal_entities WHERE id=? AND organization_id=?',[$i['entity_id'],$u['organization_id']]);
            $buyer=entity($db,'SELECT name,registration FROM counterparties WHERE id=? AND organization_id=?',[$i['counterparty_id'],$u['organization_id']]);
            $tax=ukIssue($db,$i,$seller,$form,$total,$today);if($tax){accountingCheckCashDate($db,$project,$today);$total=$tax['gross'];}unset($seller['version']);
            $seq=1+(int)query($db,'SELECT COALESCE(MAX(number_seq),0) FROM invoices WHERE entity_id=? AND number_year=?',[$i['entity_id'],$year])->fetchColumn();
            query($db,"UPDATE invoices SET state='issued',number_year=?,number_seq=?,issued_on=?,issued_by=?,issuer_snapshot=?,client_snapshot=?,total_minor=?,version=version+1 WHERE id=?",[$year,$seq,$today,$u['id'],json_encode($seller,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),json_encode($buyer,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$total,$id]);
        }
        return $id;
    });
}
function invoiceMovements(PDO $db,int $id): array {
    return query($db,'SELECT m.*,r.amount_minor,r.status,r.booked_on,r.memo,r.cash_entry_id,r.created_by,r.decided_by FROM invoice_movements m JOIN payment_requests r ON r.id=m.request_id WHERE m.invoice_id=? ORDER BY m.id',[$id])->fetchAll();
}
function invoiceBalance(array $invoice,array $movements): array {
    $r=['received'=>0,'refunded'=>0,'pending_receipt'=>0,'pending_refund'=>0];
    foreach($movements as $m){$field=match([$m['kind'],$m['status']]){['receipt','posted']=>'received',['refund','posted']=>'refunded',['receipt','pending']=>'pending_receipt',['refund','pending']=>'pending_refund',default=>null};if($field!==null)$r[$field]+=(int)$m['amount_minor'];}
    $r['outstanding']=$invoice['state']==='issued'?(int)$invoice['total_minor']-$r['received']+$r['refunded']:0;return $r;
}
function invoiceValidateMovement(PDO $db,array $i,string $kind,int $amount,string $date,?int $original,?int $exclude=null): void {
    if($i['state']!=='issued'||$date<$i['issued_on']||$date>(ukDocument($db,(int)$i['id'])?ukToday():gmdate('Y-m-d')))throw new Problem('Счёт не выставлен или дата движения недопустима.',409);
    $rows=array_values(array_filter(invoiceMovements($db,(int)$i['id']),fn($m)=>(int)$m['id']!==$exclude));$b=invoiceBalance($i,$rows);
    if($kind==='receipt'){
        if($amount>$b['outstanding']-$b['pending_receipt'])throw new Problem('Сумма превышает остаток с учётом ожидающих оплат.',409);
    }else{
        $source=null;$used=0;foreach($rows as $m){if((int)$m['id']===$original && $m['kind']==='receipt' && $m['status']==='posted')$source=$m;if($m['kind']==='refund' && (int)$m['original_id']===$original && in_array($m['status'],['pending','posted'],true))$used+=(int)$m['amount_minor'];}
        if(!$source||$date<$source['booked_on']||$amount>(int)$source['amount_minor']-$used)throw new Problem('Возврат превышает доступную сумму исходной оплаты или дата неверна.',409);
    }
}
function invoicePayment(PDO $db,array $user,int $project,int $id,string $key,array $form): int {
    $u=organizationUser($db,$user);invoiceGet($db,$u,$project,$id,'payment.create');
    $kind=choice($form['kind']??'',['receipt'=>'','refund'=>'']);$amount=moneyMinor($form['amount']??'');$date=calendarDate($form['booked_on']??'');$reference=word($form['reference']??'',180,'Идентификатор платежа');$original=$kind==='refund'?numberId($form['original_id']??0):null;
    return action($db,$u,$key,'invoice.payment',[$project,$id,$kind,$amount,$date,$reference,$original],function()use($db,$u,$project,$id,$kind,$amount,$date,$reference,$original){
        $i=invoiceGet($db,$u,$project,$id,'payment.create');invoiceValidateMovement($db,$i,$kind,$amount,$date,$original);
        if(query($db,'SELECT 1 FROM invoice_movements WHERE entity_id=? AND kind=? AND reference=?',[$i['entity_id'],$kind,$reference])->fetchColumn())throw new Problem('Такой идентификатор движения уже зарегистрирован.',409);
        $dual=dualControl($db,$project)||!roleAllows(projectRole($db,$u,$project),'payment.approve');$direction=$kind==='receipt'?'income':'expense';$memo=($kind==='receipt'?'Оплата':'Возврат').' счёта '.invoiceNumber($i);
        $p=['project_id'=>$project,'direction'=>$direction,'amount_minor'=>$amount,'currency'=>$i['currency'],'booked_on'=>$date,'memo'=>$memo,'reversal_of'=>null];
        accountingCheckCashDate($db,$project,$date);
        $cash=$dual?null:postPayment($db,$p,(int)$u['id']);
        $request=insertId($db,'INSERT INTO payment_requests(project_id,direction,amount_minor,currency,booked_on,memo,created_by,dual_control,status,decided_by,decided_at,cash_entry_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$project,$direction,$amount,$i['currency'],$date,$memo,$u['id'],(int)$dual,$dual?'pending':'posted',$dual?null:$u['id'],$dual?null:gmdate('Y-m-d H:i:s'),$cash]);
        query($db,'INSERT INTO invoice_movements(invoice_id,project_id,entity_id,request_id,kind,reference,original_id) VALUES(?,?,?,?,?,?,?)',[$id,$project,$i['entity_id'],$request,$kind,$reference,$original]);return $request;
    });
}
// Called within the existing payment approval transaction, before the cash entry is posted.
function invoiceBeforeApproval(PDO $db,array $request): void {
    $m=query($db,'SELECT * FROM invoice_movements WHERE request_id=?',[$request['id']])->fetch();if(!$m)return;
    $i=entity($db,'SELECT * FROM invoices WHERE id=? AND project_id=?',[$m['invoice_id'],$request['project_id']]);
    if($request['currency']!==$i['currency']||$request['direction']!==($m['kind']==='receipt'?'income':'expense')||$request['reversal_of']!==null)throw new Problem('Данные движения счёта не совпадают.',409);
    invoiceValidateMovement($db,$i,$m['kind'],(int)$request['amount_minor'],$request['booked_on'],$m['original_id']===null?null:(int)$m['original_id'],(int)$m['id']);
}
function invoicePage(PDO $db,array $user,?int $project,?int $id,?int $before): array {
    $u=organizationUser($db,$user);[$scope,$args]=projectRightScope($db,$u,'finance');
    if($project!==null){requireProjectRight($db,$u,$project,'finance');$scope.=' AND p.id=?';$args[]=$project;}
    $base="FROM invoices i JOIN projects p ON p.id=i.project_id LEFT JOIN (SELECT m.invoice_id,SUM(CASE WHEN r.status='posted' AND m.kind='receipt' THEN r.amount_minor WHEN r.status='posted' AND m.kind='refund' THEN -r.amount_minor ELSE 0 END) AS paid FROM invoice_movements m JOIN payment_requests r ON r.id=m.request_id GROUP BY m.invoice_id) b ON b.invoice_id=i.id WHERE $scope";
    $totals=query($db,"SELECT i.currency,SUM(i.total_minor-COALESCE(b.paid,0)) AS outstanding,SUM(CASE WHEN i.due_on<? THEN i.total_minor-COALESCE(b.paid,0) ELSE 0 END) AS overdue $base AND i.state='issued' GROUP BY i.currency ORDER BY i.currency",[gmdate('Y-m-d'),...$args])->fetchAll();
    foreach($totals as &$t)foreach(['outstanding','overdue'] as $k){$n=filter_var($t[$k],FILTER_VALIDATE_INT);if($n===false)throw new Problem('Сумма превышает диапазон отчёта.',422);$t[$k]=$n;}unset($t);
    $rows=query($db,"SELECT i.*,p.title AS project_title, i.total_minor-COALESCE(b.paid,0) AS remaining $base".($before===null?'':' AND i.id<?').' ORDER BY i.id DESC LIMIT 51',$before===null?$args:[...$args,$before])->fetchAll();
    $projects=rightProjects($db,$u,'finance');$writable=rightProjects($db,$u,'payment.create');$entities=($u['role']==='owner'||($project!==null&&isset($writable[$project])))?query($db,'SELECT * FROM legal_entities WHERE organization_id=? ORDER BY name,id',[$u['organization_id']])->fetchAll():[];
    $clients=$project!==null&&isset($writable[$project])?invoiceCounterparties($db,$u,$project):[];$invoice=null;$taxDocument=$taxProfile=null;$lines=$movements=$balance=[];
    if($id!==null){if($project===null)throw new Problem('Выберите проект.');$invoice=invoiceGet($db,$u,$project,$id);$lines=invoiceLines($db,$id);$movements=invoiceMovements($db,$id);$balance=invoiceBalance($invoice,$movements);$taxDocument=ukDocument($db,$id);$taxProfile=$invoice['state']==='draft'?ukProfile($db,(int)$invoice['entity_id']):null;}
    return compact('project','projects','writable','entities','clients','invoice','lines','movements','balance','totals','taxDocument','taxProfile')+['rows'=>array_slice($rows,0,50),'nextBefore'=>count($rows)>50?(int)$rows[49]['id']:null];
}
