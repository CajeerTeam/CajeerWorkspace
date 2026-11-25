<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const UK_VAT_RULE='UK-STD-2026-11-25';
function ukToday(): string {return (new \DateTimeImmutable('now',new \DateTimeZone('Europe/London')))->format('Y-m-d');}
function ukVatNumber(mixed $raw): string {
    if(!is_string($raw))throw new Problem('Введите UK VAT registration number.');
    $v=preg_replace('/\s+/','',strtoupper(trim($raw)));if(str_starts_with($v,'GB'))$v=substr($v,2);
    if(!preg_match('/\A[0-9]{9}\z/',$v)||$v==='000000000')throw new Problem('Нужен девятизначный UK VAT number; проверка регистрации выполняется бухгалтером.');return $v;
}
function ukVatTotals(int $net): array {
    if($net<1||$net>INVOICE_LIMIT)throw new Problem('Некорректная сумма без VAT.');
    // HMRC Notice 700 §17.5: round down total invoice VAT to a whole penny.
    $vat=intdiv($net,5);$gross=$net+$vat;if($gross>INVOICE_LIMIT)throw new Problem('Сумма с VAT превышает лимит счёта.');return ['net'=>$net,'vat'=>$vat,'gross'=>$gross];
}
function ukProfile(PDO $db,int $entity): ?array {return query($db,'SELECT * FROM uk_vat_profiles WHERE entity_id=?',[$entity])->fetch()?:null;}
function ukDocument(PDO $db,int $invoice): ?array {return query($db,'SELECT * FROM uk_vat_documents WHERE invoice_id=?',[$invoice])->fetch()?:null;}
function ukProfileCommand(PDO $db,array $user,string $act,array $form): int {
    $id=numberId($form['entity_id']??0);$version=optionalId($form['version']??'')??0;
    return atomic($db,function()use($db,$user,$act,$form,$id,$version){
        $u=$act==='tax.prepare'?orgOwner($db,$user):accountingUser($db,$user,true);$org=(int)$u['organization_id'];
        $seller=entity($db,'SELECT * FROM legal_entities WHERE id=? AND organization_id=?',[$id,$org]);$p=ukProfile($db,$id);
        if(($p?(int)$p['version']:0)!==$version)throw new Problem('Профиль изменился.',409);
        if($act==='tax.prepare'){
            $vat=ukVatNumber($form['vat_number']??'');if($p&&$p['vat_number']!==$vat&&query($db,'SELECT 1 FROM uk_vat_documents d JOIN invoices i ON i.id=d.invoice_id WHERE i.entity_id=? LIMIT 1',[$id])->fetchColumn())throw new Problem('Для другого VAT registration number создайте отдельного продавца.',409);$name=word($form['proprietor']??'',180,'Имя предпринимателя');$contact=word($form['contact']??'',180,'Контакты');$address=word($form['service_address']??'',500,'Адрес вручения документов');
            $start=calendarDate($form['effective_on']??'');$end=calendarDate($form['reviewed_until']??'');
            if($start<ukToday()||$end<$start||$end>(new \DateTimeImmutable($start))->modify('+365 days')->format('Y-m-d'))throw new Problem('Период проверки начинается не ранее сегодня и длится до 365 дней.');
            if(query($db,'SELECT 1 FROM uk_vat_profiles WHERE organization_id=? AND vat_number=? AND entity_id<>?',[$org,$vat,$id])->fetchColumn())throw new Problem('VAT number уже используется.',409);
            query($db,"INSERT INTO uk_vat_profiles(entity_id,organization_id,vat_number,proprietor,contact,service_address,effective_on,reviewed_until,entity_version,version,prepared_by,prepared_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(entity_id) DO UPDATE SET vat_number=excluded.vat_number,proprietor=excluded.proprietor,contact=excluded.contact,service_address=excluded.service_address,effective_on=excluded.effective_on,reviewed_until=excluded.reviewed_until,entity_version=excluded.entity_version,version=excluded.version,prepared_by=excluded.prepared_by,prepared_at=excluded.prepared_at,approved_by=NULL,approved_at=NULL,review_reference=''",[$id,$org,$vat,$name,$contact,$address,$start,$end,$seller['version'],$version+1,$u['id'],time()]);
        }elseif($act==='tax.approve'){
            if($u['role']!=='accountant'||!$p||(int)$p['prepared_by']===(int)$u['id'])throw new Problem('Требуется отдельный бухгалтер.',403);
            if($p['approved_at']!==null||(int)$seller['version']!==(int)$p['entity_version']||$p['reviewed_until']<ukToday())throw new Problem('Профиль устарел или уже согласован.',409);
            $ref=word($form['review_reference']??'',500,'Основание сверки');if(($form['confirmed']??'')!=='1')throw new Problem('Подтвердите проверку регистрации, режима и правил VAT.');
            if(query($db,"SELECT 1 FROM invoices i LEFT JOIN uk_vat_documents d ON d.invoice_id=i.id WHERE i.entity_id=? AND i.issued_on>=? AND d.invoice_id IS NULL LIMIT 1",[$id,$p['effective_on']])->fetchColumn())throw new Problem('Есть ранее выставленные счета без VAT в периоде активации. Проверьте их и выберите будущую дату.',409);
            query($db,'UPDATE uk_vat_profiles SET approved_by=?,approved_at=?,review_reference=?,version=version+1 WHERE entity_id=?',[$u['id'],time(),$ref,$id]);
        }else throw new Problem('Неизвестное действие.');
        auditEvent($db,$u,null,$act,$id);return $id;
    });
}
// Must run inside invoice.issue transaction. Draft prices are net; issued total is gross.
function ukIssue(PDO $db,array $i,array $seller,array $form,int $net,string $date): ?array {
    $p=ukProfile($db,(int)$i['entity_id']);if(!$p){if(isset($form['uk_confirm']))throw new Problem('VAT-профиль не существует.',409);return null;}
    if($p['approved_at']===null||$date<$p['effective_on']||$date>$p['reviewed_until']||(int)$p['entity_version']!==(int)$seller['version'])throw new Problem('VAT-профиль не согласован, просрочен или реквизиты изменились.',409);
    if((int)$p['version']!==numberId($form['uk_profile_version']??0))throw new Problem('VAT-профиль изменился. Обновите форму.',409);
    if($i['currency']!=='GBP')throw new Problem('UK VAT пакет поддерживает только GBP.');
    if(($form['uk_confirm']??'')!=='1'||($form['tax_point']??'')!==$date)throw new Problem('Подтвердите обычную внутреннюю B2B-услугу, поставленную сегодня, без авансов и скидок.');
    $address=word($form['buyer_address']??'',500,'Адрес покупателя');$t=ukVatTotals($net);
    $snap=['vat_number'=>$p['vat_number'],'proprietor'=>$p['proprietor'],'contact'=>$p['contact'],'service_address'=>$p['service_address'],'effective_on'=>$p['effective_on'],'reviewed_until'=>$p['reviewed_until'],'approved_by'=>(int)$p['approved_by'],'approved_at'=>(int)$p['approved_at'],'review_reference'=>$p['review_reference']];
    query($db,'INSERT INTO uk_vat_documents(invoice_id,profile_version,rule_version,tax_point,net_minor,vat_minor,gross_minor,buyer_address,profile_snapshot) VALUES(?,?,?,?,?,?,?,?,?)',[$i['id'],$p['version'],UK_VAT_RULE,$date,$t['net'],$t['vat'],$t['gross'],$address,json_encode($snap,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);return $t;
}
function ukCredit(PDO $db,array $i,array $user,string $reason): void {
    $d=ukDocument($db,(int)$i['id']);if(!$d||$d['credit_on']!==null||$i['state']!=='issued')throw new Problem('Кредит-нота недоступна.',409);
    $b=invoiceBalance($i,invoiceMovements($db,(int)$i['id']));if($b['received']!==$b['refunded']||$b['pending_receipt']||$b['pending_refund'])throw new Problem('Для полного кредитования сначала завершите возврат всех оплат и согласования.',409);
    $date=ukToday();accountingCheckCashDate($db,(int)$i['project_id'],$date);
    query($db,'UPDATE uk_vat_documents SET credit_on=?,credit_reason=?,credit_by=? WHERE invoice_id=? AND credit_on IS NULL',[$date,$reason,$user['id'],$i['id']]);
    query($db,"UPDATE invoices SET state='void',voided_by=?,voided_at=?,version=version+1 WHERE id=?",[$user['id'],time(),$i['id']]);
}
function ukReport(PDO $db,array $user,string $from,string $to): array {
    $u=accountingUser($db,$user);if($to<$from||$to>(new \DateTimeImmutable($from))->modify('+366 days')->format('Y-m-d'))throw new Problem('Период не более 367 дней.');
    $org=(int)$u['organization_id'];$records=query($db,"SELECT i.*,d.* FROM uk_vat_documents d JOIN invoices i ON i.id=d.invoice_id WHERE i.organization_id=? AND ((d.tax_point BETWEEN ? AND ?) OR (d.credit_on BETWEEN ? AND ?)) ORDER BY i.id LIMIT 10001",[$org,$from,$to,$from,$to])->fetchAll();
    if(count($records)>10000)throw new Problem('Сократите период отчёта.',422);
    $rows=[];$totals=[];
    foreach($records as $r){foreach(['invoice'=>$r['tax_point'],'credit'=>$r['credit_on']] as $kind=>$date){if($date===null||$date<$from||$date>$to)continue;$sign=$kind==='credit'?-1:1;$r['kind']=$kind;$r['date']=$date;$r['doc_number']=($sign<0?'CN-':'').invoiceNumber($r);foreach(['net','vat','gross'] as $k){$r[$k]=$sign*(int)$r[$k.'_minor'];$eid=(int)$r['entity_id'];$totals[$eid][$k]=accountingAdd($totals[$eid][$k]??0,$r[$k]);}$rows[]=$r;}}
    usort($rows,fn($a,$b)=>[$a['date'],$a['doc_number']]<=>[$b['date'],$b['doc_number']]);
    $profiles=query($db,'SELECT e.*,p.vat_number,p.proprietor,p.contact,p.service_address,p.effective_on,p.reviewed_until,p.approved_at,p.entity_version,p.version AS profile_version FROM legal_entities e LEFT JOIN uk_vat_profiles p ON p.entity_id=e.id WHERE e.organization_id=? ORDER BY e.id',[$org])->fetchAll();
    $legacy=(int)query($db,"SELECT COUNT(*) FROM invoices i LEFT JOIN uk_vat_documents d ON d.invoice_id=i.id WHERE i.organization_id=? AND i.issued_on BETWEEN ? AND ? AND d.invoice_id IS NULL",[$org,$from,$to])->fetchColumn();
    return compact('from','to','rows','totals','profiles','legacy');
}
function ukMoney(int $minor): string {return str_replace(',','.',money($minor,false));}
function ukPrintData(PDO $db,array $user,int $project,int $id,bool $credit): array {
    $i=invoiceGet($db,$user,$project,$id);$d=ukDocument($db,$id);if(!$d||($credit&&$d['credit_on']===null))throw new Problem('Документ не найден.',404);
    return ['i'=>$i,'d'=>$d,'credit'=>$credit,'lines'=>invoiceLines($db,$id),'seller'=>json_decode($i['issuer_snapshot'],true,512,JSON_THROW_ON_ERROR),'buyer'=>json_decode($i['client_snapshot'],true,512,JSON_THROW_ON_ERROR),'profile'=>json_decode($d['profile_snapshot'],true,512,JSON_THROW_ON_ERROR)];
}
