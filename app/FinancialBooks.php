<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const BOOK_AP_ACCOUNTS=['AP-EXP'=>['Начисленные расходы поставщиков','expense'],'AP-LIAB'=>['Задолженность поставщикам','liability']];
function bookSupplierAccounts(PDO $db,int $org): array {
    foreach(BOOK_AP_ACCOUNTS as $code=>[$name,$kind]){
        $a=query($db,'SELECT * FROM accounting_accounts WHERE organization_id=? AND code=?',[$org,$code])->fetch();
        if(!$a){query($db,'INSERT INTO accounting_accounts(organization_id,code,name,kind) VALUES(?,?,?,?)',[$org,$code,$name,$kind]);continue;}
        if($a['kind']!==$kind||$a['name']!==$name||(!query($db,'SELECT 1 FROM supplier_bills WHERE organization_id=?',[$org])->fetchColumn()&&query($db,'SELECT 1 FROM accounting_entries WHERE organization_id=? AND (debit_id=? OR credit_id=?)',[$org,$a['id'],$a['id']])->fetchColumn()))throw new Problem('Конфликт зарезервированного счёта '.$code,409);
    }return array_column(accountingAccounts($db,$org),'id','code');
}
function bookProjectEntity(PDO $db,int $project): ?int {
    $v=query($db,'SELECT entity_id FROM finance_book_projects WHERE project_id=?',[$project])->fetchColumn();return $v===false?null:(int)$v;
}
function bookCheckEntity(PDO $db,int $project,int $entity): void {
    $assigned=bookProjectEntity($db,$project);if($assigned!==null&&$assigned!==$entity)throw new Problem('Проект закреплён за другим юрлицом.',409);
}
function bookCheckDate(PDO $db,int $project,string $date): void {
    $e=bookProjectEntity($db,$project);if($e===null)return;
    if(query($db,'SELECT 1 FROM finance_book_periods WHERE entity_id=? AND ends_on>=? AND closed_at IS NOT NULL',[$e,$date])->fetchColumn())throw new Problem('Период книги юрлица закрыт.',409);
}
function bookOpenPeriod(PDO $db,int $entity,string $date): void {
    if(!query($db,'SELECT 1 FROM finance_book_periods WHERE entity_id=? AND starts_on<=? AND ends_on>=? AND closed_at IS NULL',[$entity,$date,$date])->fetchColumn())throw new Problem('Нет открытого периода книги юрлица.',409);
}
function bookBalance(PDO $db,array $bill,?int $exclude=null): array {
    $paid=0;$pending=0;$pendingRefund=0;
    foreach(query($db,'SELECT r.*,s.kind FROM supplier_payments s JOIN payment_requests r ON r.id=s.request_id WHERE s.bill_id=? ORDER BY r.id',[$bill['id']])->fetchAll() as $r){
        if($exclude!==null&&(int)$r['id']===$exclude)continue;
        $amount=(int)$r['amount_minor'];
        if($r['status']==='posted')$paid=accountingAdd($paid,$r['kind']==='payment'?$amount:-$amount);
        elseif($r['status']==='pending'){if($r['kind']==='payment')$pending=accountingAdd($pending,$amount);else $pendingRefund=accountingAdd($pendingRefund,$amount);}
    }
    $due=$bill['cancelled_entry_id']===null?(int)$bill['amount_minor']:0;
    return ['paid'=>$paid,'pending'=>$pending,'pending_refund'=>$pendingRefund,'outstanding'=>$due-$paid,'available'=>$due-$paid-$pending];
}
function bookRefundAvailable(PDO $db,array $b,int $original,string $date,?int $exclude=null): int {
    $r=entity($db,"SELECT r.* FROM supplier_payments s JOIN payment_requests r ON r.id=s.request_id WHERE s.bill_id=? AND s.request_id=? AND s.kind='payment' AND r.status='posted'",[$b['id'],$original]);
    if($date<$r['booked_on'])throw new Problem('Дата возврата раньше оплаты.',409);
    $args=[$original];$sql="SELECT COALESCE(SUM(r.amount_minor),0) FROM supplier_payments s JOIN payment_requests r ON r.id=s.request_id WHERE s.original_request_id=? AND r.status IN ('pending','posted')";if($exclude!==null){$sql.=' AND s.request_id<>?';$args[]=$exclude;}
    return (int)$r['amount_minor']-(int)query($db,$sql,$args)->fetchColumn();
}
function supplierBeforeApproval(PDO $db,array $request): ?int {
    $b=query($db,'SELECT b.*,s.kind,s.original_request_id FROM supplier_payments s JOIN supplier_bills b ON b.id=s.bill_id WHERE s.request_id=?',[$request['id']])->fetch();if(!$b)return null;
    $refund=$b['kind']==='refund';$amount=(int)$request['amount_minor'];
    if($b['cancelled_entry_id']!==null||(int)$b['project_id']!==(int)$request['project_id']||$request['direction']!==($refund?'income':'expense')||$request['currency']!==$b['currency']||$request['reversal_of']!==null||$request['booked_on']<$b['booked_on']||$amount<1)throw new Problem('Заявка поставщика больше не соответствует обязательству.',409);
    if(query($db,"SELECT 1 FROM supplier_payments s JOIN payment_requests r ON r.id=s.request_id WHERE s.bill_id=? AND r.status='pending' AND (r.booked_on<? OR (r.booked_on=? AND r.id<?))",[$b['id'],$request['booked_on'],$request['booked_on'],$request['id']])->fetchColumn())throw new Problem('Сначала рассмотрите предыдущую заявку поставщика.',409);
    $balance=bookBalance($db,$b,(int)$request['id']);$limit=$refund?min($balance['paid']-$balance['pending_refund'],bookRefundAvailable($db,$b,(int)$b['original_request_id'],$request['booked_on'],(int)$request['id'])):$balance['available'];
    if($amount>$limit)throw new Problem('Недостаточный остаток для движения поставщика.',409);
    bookOpenPeriod($db,(int)$b['entity_id'],$request['booked_on']);
    $entry=entity($db,'SELECT credit_id FROM accounting_entries WHERE id=? AND organization_id=?',[$b['entry_id'],$b['organization_id']]);return (int)$entry['credit_id'];
}
function bookTrial(PDO $db,int $org,int $entity,string $start,string $end): array {
    // PDO execute(array) binds values as strings; COALESCE has no column affinity in SQLite.
    // Cast the parameter (not the IDs) to preserve integer comparison on both backends.
    $rows=query($db,'SELECT e.*,d.code debit_code,c.code credit_code FROM accounting_entries e JOIN accounting_accounts d ON d.id=e.debit_id JOIN accounting_accounts c ON c.id=e.credit_id LEFT JOIN finance_book_projects p ON p.project_id=e.project_id LEFT JOIN finance_book_allocations a ON a.entry_id=e.id WHERE e.organization_id=? AND COALESCE(p.entity_id,a.entity_id,0)=CAST(? AS BIGINT) AND e.booked_on<=? ORDER BY e.id',[$org,$entity,$end]);
    $out=[];while($r=$rows->fetch()){
        foreach(['debit'=>1,'credit'=>-1] as $side=>$sign){$k=$r[$side.'_code'];$cur=$r['currency'];$out[$cur][$k]??=['opening'=>0,'debit'=>0,'credit'=>0,'closing'=>0];$v=(int)$r['amount_minor'];$t=&$out[$cur][$k];$field=$r['booked_on']<$start||$r['kind']==='opening'?'opening':$side;$t[$field]=accountingAdd($t[$field],$field==='opening'?$sign*$v:$v);$t['closing']=accountingAdd($t['closing'],$sign*$v);unset($t);}
    }return $out;
}
// Supplier subledger as of the selected date, tied to the same liability account.
function bookReportInteger(mixed $v): int {
    $i=filter_var($v,FILTER_VALIDATE_INT);if($i===false||$i===PHP_INT_MIN)throw new Problem('Превышен диапазон финансовой книги.',422);return $i;
}
function bookPayableAt(PDO $db,int $entity,string $end): array {
    $rows=query($db,"SELECT b.currency,b.supplier_id,c.name supplier_name,SUM(b.amount_minor-COALESCE(m.paid,0)) outstanding FROM supplier_bills b JOIN counterparties c ON c.id=b.supplier_id LEFT JOIN accounting_entries j ON j.id=b.cancelled_entry_id LEFT JOIN (SELECT s.bill_id,SUM(CASE WHEN s.kind='payment' THEN r.amount_minor ELSE -r.amount_minor END) paid FROM supplier_payments s JOIN payment_requests r ON r.id=s.request_id WHERE r.status='posted' AND r.booked_on<=? GROUP BY s.bill_id) m ON m.bill_id=b.id WHERE b.entity_id=? AND b.booked_on<=? AND (j.id IS NULL OR j.booked_on>?) GROUP BY b.currency,b.supplier_id,c.name ORDER BY b.currency,c.name,b.supplier_id",[$end,$entity,$end,$end])->fetchAll();
    $totals=[];foreach($rows as &$r){$r['outstanding']=bookReportInteger($r['outstanding']);$c=$r['currency'];$totals[$c]=accountingAdd($totals[$c]??0,$r['outstanding']);}unset($r);return ['rows'=>$rows,'totals'=>$totals];
}
function bookPayableControl(array $trial,array $payables): array {
    $out=[];foreach(array_unique([...array_keys($trial),...array_keys($payables['totals'])]) as $c){$ledger=-($trial[$c]['AP-LIAB']['closing']??0);$debt=$payables['totals'][$c]??0;$out[$c]=['ledger'=>$ledger,'debt'=>$debt,'ok'=>$ledger===$debt&&$debt>=0];}return $out;
}
function bookCashControl(PDO $db,int $org,string $end): array {
    $out=[];$accounts=array_column(accountingAccounts($db,$org),'id','code');if(!isset($accounts['1000']))return $out;$cash=(int)$accounts['1000'];
    foreach(query($db,"SELECT e.currency,COALESCE(p.entity_id,a.entity_id,0) entity_id,SUM(CASE WHEN e.debit_id=? THEN e.amount_minor ELSE -e.amount_minor END) net FROM accounting_entries e LEFT JOIN finance_book_projects p ON p.project_id=e.project_id LEFT JOIN finance_book_allocations a ON a.entry_id=e.id WHERE e.organization_id=? AND e.booked_on<=? AND (e.debit_id=? OR e.credit_id=?) GROUP BY e.currency,COALESCE(p.entity_id,a.entity_id,0)",[$cash,$org,$end,$cash,$cash])->fetchAll() as $r){$c=$r['currency'];$out[$c]??=['assigned'=>0,'unallocated'=>0,'ledger'=>0];$k=(int)$r['entity_id']?'assigned':'unallocated';$out[$c][$k]=accountingAdd($out[$c][$k],bookReportInteger($r['net']));}
    foreach(query($db,"SELECT currency,SUM(CASE WHEN debit_id=? THEN amount_minor ELSE -amount_minor END) net FROM accounting_entries WHERE organization_id=? AND booked_on<=? AND (debit_id=? OR credit_id=?) GROUP BY currency",[$cash,$org,$end,$cash,$cash])->fetchAll() as $r){$c=$r['currency'];$out[$c]??=['assigned'=>0,'unallocated'=>0,'ledger'=>0];$out[$c]['ledger']=bookReportInteger($r['net']);}
    foreach($out as &$r)$r['ok']=accountingAdd($r['assigned'],$r['unallocated'])===$r['ledger'];unset($r);return $out;
}
function bookCommand(PDO $db,array $user,string $key,string $act,array $form): int {
    if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);
    if(!preg_match('/\A[a-f0-9]{32}\z/',$key))throw new Problem('Обновите форму.');
    $fields=match($act){'books.assign'=>['entity_id','project_id'],'books.allocate'=>['entity_id','entry_id'],'books.period'=>['entity_id','starts_on','ends_on'],'books.close'=>['entity_id','id'],'books.bill'=>['entity_id','project_id','supplier_id','reference','booked_on','due_on','currency','amount','memo'],'books.pay'=>['entity_id','id','amount','booked_on','reference'],'books.refund'=>['entity_id','id','amount','booked_on','reference','original_request_id'],'books.cancel'=>['entity_id','id','booked_on','memo'],default=>throw new Problem('Неизвестная операция.')};
    $f=[];foreach($fields as $field){$v=$form[$field]??'';if(!is_string($v)&&!is_int($v))throw new Problem('Некорректная форма.');$f[$field]=(string)$v;}
    return atomic($db,function()use($db,$user,$key,$act,$f){
        $u=accountingUser($db,$user,true);$org=(int)$u['organization_id'];$actor=(int)$u['id'];$e=numberId($f['entity_id']);entity($db,'SELECT id FROM legal_entities WHERE id=? AND organization_id=?',[$e,$org]);
        if(in_array($act,['books.assign','books.allocate','books.period','books.close'],true)&&$u['role']!=='owner')throw new Problem('Требуется владелец организации.',403);
        $hash=hash('sha256',json_encode([$org,$act,$f],JSON_THROW_ON_ERROR));$old=query($db,'SELECT * FROM action_log WHERE actor_id=? AND request_key=?',[$actor,$key])->fetch();if($old){if(!hash_equals($old['fingerprint'],$hash))throw new Problem('Повторный запрос изменён.',409);return (int)$old['object_id'];}
        if(!accountingActive($db,$org))throw new Problem('Сначала примите начальные остатки общего журнала.',409);
        if($act==='books.assign'){
            $id=numberId($f['project_id']);requireProjectRight($db,$u,$id,'finance');if(bookProjectEntity($db,$id)!==null)throw new Problem('Привязка проекта неизменяема.',409);
            foreach(['invoices','contracts'] as $table)if(query($db,"SELECT 1 FROM $table WHERE project_id=? AND entity_id<>?",[$id,$e])->fetchColumn())throw new Problem('Исторические документы относятся к другому юрлицу.',409);
            if(query($db,'SELECT 1 FROM bank_allocations a JOIN bank_statements s ON s.id=a.statement_id JOIN bank_accounts b ON b.id=s.account_id WHERE a.project_id=? AND b.entity_id<>?',[$id,$e])->fetchColumn())throw new Problem('Историческая банковская сверка относится к другому юрлицу.',409);
            if(query($db,'SELECT 1 FROM accounting_entries j JOIN finance_book_periods p ON p.entity_id=? AND p.closed_at IS NOT NULL AND j.booked_on<=p.ends_on WHERE j.project_id=?',[$e,$id])->fetchColumn())throw new Problem('Привязка изменит закрытую книгу.',409);
            query($db,'INSERT INTO finance_book_projects(project_id,organization_id,entity_id,actor_id,created_at) VALUES(?,?,?,?,?)',[$id,$org,$e,$actor,time()]);
        }elseif($act==='books.allocate'){
            $id=numberId($f['entry_id']);$j=entity($db,'SELECT * FROM accounting_entries WHERE id=? AND organization_id=? AND project_id IS NULL',[$id,$org]);
            // Allocate only immutable opening entries; other common entries remain explicitly unassigned.
            if($j['kind']!=='opening'||query($db,'SELECT 1 FROM finance_book_periods WHERE entity_id=? AND closed_at IS NOT NULL AND ends_on>=?',[$e,$j['booked_on']])->fetchColumn()||query($db,'SELECT 1 FROM finance_book_allocations WHERE entry_id=?',[$id])->fetchColumn())throw new Problem('Распределение недопустимо.',409);
            query($db,'INSERT INTO finance_book_allocations(entry_id,organization_id,entity_id,actor_id,created_at) VALUES(?,?,?,?,?)',[$id,$org,$e,$actor,time()]);
        }elseif($act==='books.period'){
            $start=calendarDate($f['starts_on']);$end=calendarDate($f['ends_on']);if($start>$end||query($db,'SELECT 1 FROM finance_book_periods WHERE entity_id=? AND closed_at IS NOT NULL AND ends_on>=?',[$e,$start])->fetchColumn()||query($db,'SELECT 1 FROM finance_book_periods WHERE entity_id=? AND starts_on<=? AND ends_on>=?',[$e,$end,$start])->fetchColumn())throw new Problem('Периоды пересекаются.',409);
            $id=insertId($db,'INSERT INTO finance_book_periods(organization_id,entity_id,starts_on,ends_on) VALUES(?,?,?,?)',[$org,$e,$start,$end]);
        }elseif($act==='books.close'){
            $id=numberId($f['id']);$p=entity($db,'SELECT * FROM finance_book_periods WHERE id=? AND entity_id=?',[$id,$e]);
            if($p['closed_at']!==null||$p['ends_on']>=gmdate('Y-m-d')||query($db,'SELECT 1 FROM finance_book_periods WHERE entity_id=? AND starts_on<? AND closed_at IS NULL',[$e,$p['starts_on']])->fetchColumn())throw new Problem('Закройте завершённые периоды по порядку.',409);
            if(query($db,"SELECT 1 FROM payment_requests r JOIN finance_book_projects p ON p.project_id=r.project_id WHERE p.entity_id=? AND r.status='pending' AND r.booked_on<=?",[$e,$p['ends_on']])->fetchColumn())throw new Problem('Есть ожидающие платежи.',409);
            $trial=bookTrial($db,$org,$e,$p['starts_on'],$p['ends_on']);
            foreach($trial as $currency=>$rows){$total=['opening'=>0,'debit'=>0,'credit'=>0,'closing'=>0];foreach($rows as $row)foreach($total as $k=>$v)$total[$k]=accountingAdd($v,$row[$k]);if($total['opening']!==0||$total['closing']!==0||$total['debit']!==$total['credit'])throw new Problem('Книга не сбалансирована: '.$currency,409);}
            foreach(bookPayableControl($trial,bookPayableAt($db,$e,$p['ends_on'])) as $c=>$row)if(!$row['ok'])throw new Problem('Задолженность поставщикам не сходится: '.$c,409);
            foreach(bookCashControl($db,$org,$p['ends_on']) as $row)if(!$row['ok'])throw new Problem('Распределение кассы не сходится.',409);
            if(!accountingReconcile($db,$org)['ok'])throw new Problem('Общая касса не сверена.',409);
            bankCloseCheck($db,$org,$e,$p['starts_on'],$p['ends_on'],$actor);
            query($db,'UPDATE finance_book_periods SET closed_by=?,closed_at=? WHERE id=?',[$actor,time(),$id]);
        }else{
            $date=calendarDate($f['booked_on']);if($date>gmdate('Y-m-d'))throw new Problem('Будущая дата начисления или оплаты недопустима.');bookOpenPeriod($db,$e,$date);$period=accountingPeriod($db,$org,$date);
            if($act==='books.bill'){
                $project=numberId($f['project_id']);requireProjectRight($db,$u,$project,'finance');if(bookProjectEntity($db,$project)!==$e)throw new Problem('Сначала закрепите проект за юрлицом.',409);
                $supplier=numberId($f['supplier_id']);entity($db,'SELECT id FROM counterparties WHERE id=? AND organization_id=?',[$supplier,$org]);$ref=word($f['reference'],180,'Номер счёта');$due=calendarDate($f['due_on']);if($due<$date)throw new Problem('Срок оплаты раньше начисления.');$amount=moneyMinor($f['amount']);$currency=choice($f['currency'],array_flip(CURRENCIES));$memo=word($f['memo'],400,'Основание');
                if(query($db,'SELECT 1 FROM supplier_bills WHERE entity_id=? AND supplier_id=? AND reference=?',[$e,$supplier,$ref])->fetchColumn())throw new Problem('Счёт поставщика уже зарегистрирован.',409);
                $accounts=bookSupplierAccounts($db,$org);$entry=accountingInsert($db,$org,(int)$period['id'],$project,$date,$currency,(int)$accounts['AP-EXP'],(int)$accounts['AP-LIAB'],$amount,'manual','Начисление поставщика · '.$memo,$actor);
                $id=insertId($db,'INSERT INTO supplier_bills(organization_id,project_id,entity_id,supplier_id,reference,booked_on,due_on,currency,amount_minor,entry_id,actor_id,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$org,$project,$e,$supplier,$ref,$date,$due,$currency,$amount,$entry,$actor,time()]);
            }else{
                $b=entity($db,'SELECT * FROM supplier_bills WHERE entity_id=? AND id=?',[$e,numberId($f['id'])]);$project=(int)$b['project_id'];requireProjectRight($db,$u,$project,'payment.create');$balance=bookBalance($db,$b);bookCheckDate($db,$project,$date);
                if(query($db,"SELECT 1 FROM supplier_payments s JOIN payment_requests r ON r.id=s.request_id WHERE s.bill_id=? AND r.status IN ('posted','pending') AND r.booked_on>?",[$b['id'],$date])->fetchColumn())throw new Problem('Дата раньше уже зарегистрированного движения обязательства.',409);
                if($b['cancelled_entry_id']!==null||$date<$b['booked_on'])throw new Problem('Обязательство недоступно.',409);
                if(in_array($act,['books.pay','books.refund'],true)){
                    $refund=$act==='books.refund';$original=$refund?numberId($f['original_request_id']):null;$limit=$refund?min($balance['paid']-$balance['pending_refund'],bookRefundAvailable($db,$b,$original,$date)):$balance['available'];
                    $amount=moneyMinor($f['amount']);$ref=word($f['reference'],180,'Идентификатор платежа');if($amount>$limit||query($db,'SELECT 1 FROM supplier_payments s JOIN supplier_bills b ON b.id=s.bill_id WHERE b.entity_id=? AND s.reference=?',[$e,$ref])->fetchColumn())throw new Problem('Сумма превышает доступный остаток или платёж уже зарегистрирован.',409);
                    $id=insertId($db,"INSERT INTO payment_requests(project_id,direction,amount_minor,currency,booked_on,memo,created_by,dual_control,status) VALUES(?,?,?,?,?,?,?,?,?)",[$project,$refund?'income':'expense',$amount,$b['currency'],$date,($refund?'Возврат поставщика':'Поставщик').' · счёт #'.$b['id'],$actor,1,'pending']);
                    query($db,'INSERT INTO supplier_payments(request_id,bill_id,project_id,reference,kind,original_request_id) VALUES(?,?,?,?,?,?)',[$id,$b['id'],$project,$ref,$refund?'refund':'payment',$original]);
                }else{
                    if($balance['paid']||$balance['pending']||$balance['pending_refund'])throw new Problem('Нельзя отменить оплаченный счёт или счёт с ожидающим платежом.',409);
                    $j=entity($db,'SELECT * FROM accounting_entries WHERE id=?',[$b['entry_id']]);$memo=word($f['memo'],400,'Причина');$id=(int)$b['id'];
                    $rev=accountingInsert($db,$org,(int)$period['id'],$project,$date,$b['currency'],(int)$j['credit_id'],(int)$j['debit_id'],(int)$b['amount_minor'],'reversal','Отмена начисления · '.$memo,$actor,null,(int)$j['id']);query($db,'UPDATE supplier_bills SET cancelled_entry_id=? WHERE id=?',[$rev,$id]);
                }
            }
        }
        query($db,'INSERT INTO action_log(actor_id,request_key,fingerprint,action,object_id) VALUES(?,?,?,?,?)',[$actor,$key,$hash,$act,$id]);auditEvent($db,$u,null,$act,$id);return $id;
    });
}
function booksPage(PDO $db,array $user,?int $entityId,?int $periodId,?int $before): array {
    if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);
    return atomic($db,function()use($db,$user,$entityId,$periodId,$before){
        $u=accountingUser($db,$user);$org=(int)$u['organization_id'];$entities=query($db,'SELECT id,name FROM legal_entities WHERE organization_id=? ORDER BY name,id',[$org])->fetchAll();$entityId??=(int)($entities[0]['id']??0);
        if($entityId)entity($db,'SELECT id FROM legal_entities WHERE id=? AND organization_id=?',[$entityId,$org]);
        $periods=query($db,'SELECT * FROM finance_book_periods WHERE entity_id=? ORDER BY starts_on DESC',[$entityId])->fetchAll();$period=$periodId===null?($periods[0]??null):entity($db,'SELECT * FROM finance_book_periods WHERE entity_id=? AND id=?',[$entityId,$periodId]);
        $start=$period['starts_on']??gmdate('Y-m-01');$end=$period['ends_on']??gmdate('Y-m-d');$trial=$entityId?bookTrial($db,$org,$entityId,$start,$end):[];$unallocated=bookTrial($db,$org,0,$start,$end);
        $projects=query($db,'SELECT p.id,p.title,m.entity_id FROM projects p LEFT JOIN finance_book_projects m ON m.project_id=p.id WHERE p.organization_id=? ORDER BY p.id',[$org])->fetchAll();$suppliers=query($db,'SELECT id,name FROM counterparties WHERE organization_id=? ORDER BY name,id',[$org])->fetchAll();
        $openings=query($db,"SELECT j.* FROM accounting_entries j LEFT JOIN finance_book_allocations a ON a.entry_id=j.id WHERE j.organization_id=? AND j.kind='opening' AND a.entry_id IS NULL ORDER BY j.id",[$org])->fetchAll();
        $args=[$entityId];if($before!==null)$args[]=$before;$bills=query($db,'SELECT b.*,c.name supplier_name FROM supplier_bills b JOIN counterparties c ON c.id=b.supplier_id WHERE b.entity_id=?'.($before!==null?' AND b.id<?':'').' ORDER BY b.id DESC LIMIT 51',$args)->fetchAll();foreach($bills as &$b){$b+=bookBalance($db,$b);$b['movements']=query($db,'SELECT r.id,r.status,r.amount_minor,r.booked_on,s.kind,s.reference FROM supplier_payments s JOIN payment_requests r ON r.id=s.request_id WHERE s.bill_id=? ORDER BY r.id DESC',[$b['id']])->fetchAll();}unset($b);
        $totals=[];foreach(query($db,'SELECT * FROM supplier_bills WHERE entity_id=?',[$entityId])->fetchAll() as $b){$c=$b['currency'];$v=bookBalance($db,$b);$totals[$c]??=['outstanding'=>0,'overdue'=>0,'pending'=>0,'pending_refund'=>0];foreach(['outstanding','pending','pending_refund'] as $k)$totals[$c][$k]=accountingAdd($totals[$c][$k],$v[$k]);if($b['due_on']<gmdate('Y-m-d'))$totals[$c]['overdue']=accountingAdd($totals[$c]['overdue'],$v['outstanding']);}
        $payables=bookPayableAt($db,$entityId,$end);$payableControl=bookPayableControl($trial,$payables);$cashControl=bookCashControl($db,$org,$end);
        $writable=true;try{accountingUser($db,$u,true);}catch(Problem){$writable=false;}$active=accountingActive($db,$org);$owner=$u['role']==='owner';$nextBefore=count($bills)>50?(int)$bills[49]['id']:null;$bills=array_slice($bills,0,50);return compact('entities','entityId','periods','period','trial','unallocated','projects','suppliers','openings','bills','totals','payables','payableControl','cashControl','writable','active','owner','nextBefore');
    });
}
