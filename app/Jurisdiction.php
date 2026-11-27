<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

// Package revisions, not dates of new legislation. Never change a published revision.
const UK_RULES = [
    'UK-STD-2026-11-25'=>['from'=>'2011-01-04','until'=>'2026-11-26','rate_bps'=>2000,'rounding'=>'invoice_floor_penny'],
    'UK-STD-2026-11-27'=>['from'=>'2026-11-27','until'=>null,'rate_bps'=>2000,'rounding'=>'invoice_floor_penny'],
];
const UK_EXCHANGE_MAX=4194304;
function ukRuleOn(string $date): string {
    calendarDate($date);
    foreach(UK_RULES as $id=>$rule)if($date >= $rule['from']&&($rule['until']===null||$date<=$rule['until']))return $id;
    throw new Problem('Нет поддерживаемой редакции UK VAT для даты.',422);
}
function ukRule(string $id): array {
    return UK_RULES[$id]??throw new Problem('Неизвестная редакция UK VAT.',409);
}
function ukResolveDocumentRule(array $d): array {
    $d['calculation_rule']=$d['rule_version'];
    if(($d['package_rule_version']??null)!==null){if(ukRuleOn($d['tax_point'])!==$d['package_rule_version'])throw new Problem('Редакция документа не соответствует tax point.',409);$d['rule_version']=$d['package_rule_version'];}
    ukRule($d['rule_version']);return $d;
}
function ukExchangeJson(mixed $v): string {return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
function ukExchangeRecord(array $r): array {
    $rule=ukRule($r['rule_version']);$kind=$r['kind'];
    if(!in_array($kind,['invoice','credit'],true)||$r['currency']!=='GBP')throw new Problem('Некорректная запись VAT.',409);
    // Legacy documents retain their recorded version, even if issued after the new code cutover.
    $t=ukVatTotals((int)$r['net_minor'],$r['rule_version']);$sign=$kind==='credit'?-1:1;
    if(isset($r['total_minor'])&&(int)$r['total_minor']!==$t['gross'])throw new Problem('Сумма счёта не соответствует VAT-документу.',409);
    foreach($t as $k=>$v)if((int)$r[$k.'_minor']!==$v||(int)$r[$k]!==$sign*$v)throw new Problem('Суммы VAT не соответствуют сохранённым правилам.',409);
    $s=json_decode($r['issuer_snapshot'],true,64,JSON_THROW_ON_ERROR);$b=json_decode($r['client_snapshot'],true,64,JSON_THROW_ON_ERROR);$p=json_decode($r['profile_snapshot'],true,64,JSON_THROW_ON_ERROR);
    return ['document'=>$r['doc_number'],'type'=>$kind,'date'=>$r['date'],'original_invoice'=>invoiceNumber($r),'original_tax_point'=>$r['tax_point'],'entity_id'=>(int)$r['entity_id'],'project_id'=>(int)$r['project_id'],'supplier'=>$s['name'],'supplier_address'=>$s['address'],'vat_number'=>'GB'.$p['vat_number'],'buyer'=>$b['name'],'buyer_address'=>$r['buyer_address'],'currency'=>'GBP','net_minor'=>(int)$r['net'],'vat_minor'=>(int)$r['vat'],'gross_minor'=>(int)$r['gross'],'rule'=>$r['rule_version'],'calculation_rule'=>$r['calculation_rule']??UK_VAT_RULE,'profile_version'=>(int)$r['profile_version'],'rate_bps'=>$rule['rate_bps'],'rounding'=>$rule['rounding'],'credit_reason'=>$kind==='credit'?$r['credit_reason']:''];
}
const UK_EXCHANGE_COLUMNS=['document','type','date','original_invoice','original_tax_point','entity_id','project_id','supplier','supplier_address','vat_number','buyer','buyer_address','currency','net_minor','vat_minor','gross_minor','rule','calculation_rule','profile_version','rate_bps','rounding','credit_reason'];
function ukExchangeBytes(array $report,int $eid,string $format): string {
    if(!in_array($format,['csv-v2','json-v1'],true))throw new Problem('Неизвестный формат выгрузки.');
    $records=[];$totals=['net'=>0,'vat'=>0,'gross'=>0];$rules=[];$size=0;
    foreach($report['rows'] as $r){if((int)$r['entity_id']!==$eid)continue;$record=ukExchangeRecord($r);$size+=strlen(ukExchangeJson($record));if($size>UK_EXCHANGE_MAX)throw new Problem('Сократите период выгрузки.',422);$records[]=$record;$rules[$record['rule']]=ukRule($record['rule']);foreach($totals as $k=>$v)$totals[$k]=accountingAdd($v,$record[$k.'_minor']);}
    if($format==='json-v1')$bytes=ukExchangeJson(['format'=>'workspace-uk-vat-sales-json-v1','jurisdiction'=>'uk_sole_trader_standard_vat_b2b_gbp','entity_id'=>$eid,'from'=>$report['from'],'to'=>$report['to'],'currency'=>'GBP','totals_minor'=>$totals,'rules'=>$rules,'records'=>$records]);
    else{$f=fopen('php://temp/maxmemory:1048576','w+b');if(!$f)throw new Problem('Не удалось подготовить выгрузку.',503);try{fwrite($f,"\xEF\xBB\xBF");fputcsv($f,UK_EXCHANGE_COLUMNS,',','"','');foreach($records as $r){$fields=[];foreach(UK_EXCHANGE_COLUMNS as $c){$v=$r[$c];$fields[]=is_string($v)&&preg_match('/^(?:[\p{Z}\x00-\x20]*[=+@-]|[\t\r\n])/u',$v)?"'".$v:$v;}fputcsv($f,$fields,',','"','');}rewind($f);$bytes=stream_get_contents($f);}finally{fclose($f);}}
    if(!is_string($bytes)||strlen($bytes)>UK_EXCHANGE_MAX)throw new Problem('Сократите период выгрузки.',422);return $bytes;
}
function ukExchangeGet(PDO $db,array $u,int $id): array {
    $u=accountingUser($db,$u);return entity($db,'SELECT * FROM uk_exchange_batches WHERE id=? AND organization_id=?',[$id,$u['organization_id']]);
}
function ukExchangeVerified(array $b): string {
    $bytes=base64_decode($b['payload_base64'],true);
    if($bytes===false||strlen($bytes)!==(int)$b['byte_count']||strlen($bytes)>UK_EXCHANGE_MAX||!hash_equals($b['payload_sha256'],hash('sha256',$bytes)))throw new Problem('Выгрузка повреждена.',409);return $bytes;
}
function ukExchangeCreate(PDO $db,array $user,string $key,array $f): int {
    if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);
    if(!preg_match('/\A[a-f0-9]{32}\z/',$key))throw new Problem('Обновите форму.');
    $eid=numberId($f['entity_id']??0);$from=calendarDate($f['from']??'');$to=calendarDate($f['to']??'');$format=word($f['format']??'',16,'Формат');
    if($to>ukToday())throw new Problem('Выгрузка не может включать будущие даты.');
    return atomic($db,function()use($db,$user,$key,$eid,$from,$to,$format){$u=accountingUser($db,$user,true);$org=(int)$u['organization_id'];
        entity($db,'SELECT id FROM legal_entities WHERE organization_id=? AND id=?',[$org,$eid]);
        $old=query($db,'SELECT * FROM uk_exchange_batches WHERE organization_id=? AND created_by=? AND request_key=?',[$org,$u['id'],$key])->fetch();
        if($old){if((int)$old['entity_id']!==$eid||$old['from_on']!==$from||$old['to_on']!==$to||$old['format']!==$format)throw new Problem('Повторный запрос изменился.',409);ukExchangeVerified($old);return (int)$old['id'];}
        $report=ukReport($db,$u,$from,$to);if(!ukProfile($db,$eid))throw new Problem('Для продавца не настроен UK VAT.',409);$bytes=ukExchangeBytes($report,$eid,$format);$id=insertId($db,'INSERT INTO uk_exchange_batches(organization_id,entity_id,from_on,to_on,format,payload_base64,payload_sha256,byte_count,created_by,created_at,request_key) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$org,$eid,$from,$to,$format,base64_encode($bytes),hash('sha256',$bytes),strlen($bytes),$u['id'],time(),$key]);auditEvent($db,$u,null,'tax.exchange',$id);return $id;
    });
}
function ukExchangeReceipt(PDO $db,array $user,string $key,array $f): int {
    if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);
    if(!preg_match('/\A[a-f0-9]{32}\z/',$key))throw new Problem('Обновите форму.');
    $id=numberId($f['id']??0);$hash=word($f['sha256']??'',64,'SHA-256');$decision=$f['decision']??'';if(!in_array($decision,['accepted','rejected'],true))throw new Problem('Выберите результат проверки.');$ref=word($f['reference']??'',500,'Основание');
    return atomic($db,function()use($db,$user,$key,$id,$hash,$decision,$ref){$u=accountingUser($db,$user,true);$b=ukExchangeGet($db,$u,$id);ukExchangeVerified($b);
        if($u['role']!=='accountant'||(int)$b['created_by']===(int)$u['id'])throw new Problem('Требуется независимый бухгалтер.',403);
        if(!hash_equals($b['payload_sha256'],$hash))throw new Problem('SHA-256 не совпадает.',409);
        $old=query($db,'SELECT * FROM uk_exchange_receipts WHERE batch_id=?',[$id])->fetch();if($old){if((int)$old['actor_id']===(int)$u['id']&&$old['request_key']===$key&&$old['decision']===$decision&&$old['reference']===$ref)return $id;throw new Problem('Результат уже зафиксирован; создайте новую выгрузку.',409);}
        query($db,'INSERT INTO uk_exchange_receipts(batch_id,actor_id,decision,reference,payload_sha256,request_key,received_at) VALUES(?,?,?,?,?,?,?)',[$id,$u['id'],$decision,$ref,$hash,$key,time()]);auditEvent($db,$u,null,'tax.exchange.receipt',$id);return $id;
    });
}
function ukExchangePage(PDO $db,array $u,?int $before=null): array {
    $u=accountingUser($db,$u);return query($db,'SELECT b.id,b.entity_id,b.from_on,b.to_on,b.format,b.payload_sha256,b.byte_count,b.created_by,b.created_at,r.decision,r.reference,r.actor_id,r.received_at FROM uk_exchange_batches b LEFT JOIN uk_exchange_receipts r ON r.batch_id=b.id WHERE b.organization_id=? AND b.id<? ORDER BY b.id DESC LIMIT 51',[$u['organization_id'],$before??PHP_INT_MAX])->fetchAll();
}
function ukExchangeDownload(PDO $db,array $u,int $id,bool $receipt): void {
    if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);
    [$bytes,$format]=atomic($db,function()use($db,$u,$id,$receipt){$b=ukExchangeGet($db,$u,$id);$bytes=ukExchangeVerified($b);$format=$b['format'];if($receipt){$r=entity($db,'SELECT * FROM uk_exchange_receipts WHERE batch_id=?',[$id]);if(!hash_equals($b['payload_sha256'],$r['payload_sha256']))throw new Problem('Квитанция повреждена.',409);$bytes=ukExchangeJson(['format'=>'workspace-accountant-receipt-v1','source'=>'manual_accountant_review_not_hmrc','batch_id'=>$id,'entity_id'=>(int)$b['entity_id'],'export_format'=>$format,'from'=>$b['from_on'],'to'=>$b['to_on'],'sha256'=>$b['payload_sha256'],'byte_count'=>(int)$b['byte_count'],'decision'=>$r['decision'],'reference'=>$r['reference'],'reviewer_id'=>(int)$r['actor_id'],'received_at'=>(int)$r['received_at']]);$format='receipt';}auditEvent($db,accountingUser($db,$u),null,'tax.exchange.download',$id);return [$bytes,$format];});
    $csv=$format==='csv-v2';header('Content-Type: '.($csv?'text/csv; charset=utf-8':'application/json'));header('Content-Disposition: attachment; filename="uk-vat-'.$id.'-'.$format.($csv?'.csv':'.json').'"');header('Content-Length: '.strlen($bytes));header('Cache-Control: no-store');echo $bytes;
}

// Read-only receipt package. Historical account is taken from the frozen approval, not current environment.
function signReceiptBundle(PDO $db,array $j): string {
    $id=(int)$j['id'];$account=signDecode($j['approval_json'])['account'];$cfg=['account'=>$account];
    $r=entity($db,'SELECT * FROM preparation_revisions WHERE id=? AND preparation_id=? AND project_id=?',[$j['revision_id'],$j['preparation_id'],$j['project_id']]);
    if(!hash_equals($j['evidence_hash'],$r['evidence_hash']))throw new Problem('Снимок пакета повреждён.',409);
    $archive=null;if($j['state']==='archived'){signId($j['remote_id']);[$a,$pdf]=signArchive($db,$j);[, $status]=signResponse($a['status_json'],$j,$cfg,$j['remote_id']);if($status!=='complete')throw new Problem('Архив не подтверждён провайдером.',409);$archive=['pdf_sha256'=>$a['pdf_sha256'],'pdf_size'=>(int)$a['pdf_size'],'status_sha256'=>$a['status_sha256'],'archived_at'=>(int)$a['archived_at']];}
    $result=['format'=>'workspace-dropbox-sign-receipts-v1','provider'=>'dropbox_sign_v3','mode'=>(int)$j['test_mode']?'TEST':'LIVE','test_mode'=>(bool)$j['test_mode'],'package_id'=>$id,'remote_id'=>$j['remote_id'],'state'=>$j['state'],'revision_id'=>(int)$j['revision_id'],'evidence_sha256'=>$j['evidence_hash'],'source_pdf_sha256'=>$r['document_hash'],'archive'=>$archive,'api_receipts'=>[],'callbacks'=>[]];$size=0;
    foreach(['signing_receipts'=>'api_receipts','signing_callbacks'=>'callbacks'] as $table=>$field){$last=(int)query($db,"SELECT COALESCE(MAX(id),0) FROM $table WHERE package_id=?",[$id])->fetchColumn();foreach(signCallbackRows($db,$id,$last,$table) as $e){
        if($table==='signing_receipts'){if(!$j['remote_id'])throw new Problem('Нет подтверждённого ID пакета.',409);[, $state]=signResponse($e['payload'],$j,$cfg,$j['remote_id']);$e['validated_state']=$state;}
        else{$callback=signDecode($e['payload']);$cb=$callback['signature_request']??[];$event=$callback['event']??[];if(($event['event_type']??null)!==$e['event_type']||(int)($event['event_time']??0)!==(int)$e['event_time'])throw new Problem('Метаданные callback повреждены.',409);if(($cb['metadata']['workspace_nonce']??null)!==$j['nonce']||($cb['metadata']['workspace_evidence']??null)!==$j['evidence_hash']||($cb['test_mode']??null)!==(bool)$j['test_mode']||strtolower((string)($cb['requester_email_address']??''))!==$account||($j['remote_id']!==null&&($cb['signature_request_id']??null)!==$j['remote_id']))throw new Problem('Квитанция callback не соответствует пакету.',409);$e['verification']='hmac_checked_at_ingest_payload_hash_checked_at_export';}
        $size+=strlen(ukExchangeJson($e));if($size>UK_EXCHANGE_MAX)throw new Problem('Слишком много квитанций для единого файла; используйте архив и отдельные квитанции.',422);$result[$field][]=$e;
    }}
    $archiveReceipt=$archive!==null&&in_array($archive['status_sha256'],array_column($result['api_receipts'],'payload_sha256'),true);
    $result['test_evidence_ready']=$result['test_mode']&&$archiveReceipt&&in_array('signature_request_all_signed',array_column($result['callbacks'],'event_type'),true);
    $bytes=ukExchangeJson($result);if(strlen($bytes)>UK_EXCHANGE_MAX)throw new Problem('Слишком много квитанций для единого файла.',422);return $bytes;
}
