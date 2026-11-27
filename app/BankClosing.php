<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const BANK_COLUMNS=['reference','date','amount','fee','description'];
function bankFormatConfig(array $form): array {
    $delimiter=choice($form['delimiter']??'', ['comma'=>'','semicolon'=>'','tab'=>'']);
    $date=choice($form['date_format']??'', ['Y-m-d'=>'','d.m.Y'=>'','d/m/Y'=>'']);
    $decimal=choice($form['decimal']??'', ['dot'=>'','comma'=>'']);
    $headers=[];foreach(BANK_COLUMNS as $c){$h=word($form['header_'.$c]??'',100,'Заголовок '.$c);if(preg_match('/[\r\n\x00]/',$h))throw new Problem('Недопустимый заголовок.');$headers[$c]=$h;}
    if(count(array_unique($headers))!==5)throw new Problem('Заголовки должны различаться.');
    return ['parser'=>1,'delimiter'=>$delimiter,'date_format'=>$date,'decimal'=>$decimal,'headers'=>$headers];
}
function bankBuiltinFormat(): array {return ['parser'=>1,'delimiter'=>'comma','date_format'=>'Y-m-d','decimal'=>'legacy_dot_or_comma','headers'=>array_combine(BANK_COLUMNS,BANK_COLUMNS)];}
function bankFormatDate(string $value,string $format): string {
    if($format==='Y-m-d')return calendarDate($value);
    $d=\DateTimeImmutable::createFromFormat('!'.$format,$value,new \DateTimeZone('UTC'));
    if(!$d||$d->format($format)!==$value)throw new Problem('Дата не соответствует формату выписки.');return calendarDate($d->format('Y-m-d'));
}
function bankFormatAmount(string $value,string $decimal): string {
    $value=trim($value);$sep=$decimal==='comma'?',':'\.';
    if(!preg_match('/\A-?\d{1,9}(?:'.$sep.'\d{1,2})?\z/',$value))throw new Problem('Сумма не соответствует формату (без разделителей тысяч).');return str_replace(',','.',$value);
}
function bankParseFormat(mixed $csv,array $cfg,string $start,string $end,int $opening,int $closing): array {
    if(!is_string($csv)||strlen($csv)>1048576||!preg_match('//u',$csv)||str_contains($csv,"\0"))throw new Problem('CSV должен быть UTF-8, не более 1 МиБ.');
    if(($cfg['parser']??null)!==1)throw new Problem('Версия парсера не поддерживается.',409);
    $delimiter=['comma'=>',','semicolon'=>';','tab'=>"\t"][$cfg['delimiter']];$q=preg_quote($delimiter,'/');$field='(?:[^"'.$q.']*|"(?:[^"]|"")*")';
    $lines=preg_split('/\r\n|\n|\r/',trim(preg_replace('/\A\xEF\xBB\xBF/','',$csv),"\r\n"));
    if(count($lines)>5001)throw new Problem('Не более 5000 операций.');
    $decode=function(string $line)use($delimiter,$field,$q){if(!preg_match('/\A'.$field.'(?:'.$q.$field.'){4}\z/uD',$line))throw new Problem('Некорректная строка CSV.');return str_getcsv($line,$delimiter,'"','');};
    $header=$decode(array_shift($lines));$order=[];foreach(BANK_COLUMNS as $c){$i=array_search($cfg['headers'][$c],$header,true);if($i===false)throw new Problem('Заголовок не соответствует выбранной редакции формата.');$order[]=$i;}
    if(count(array_unique($order))!==5)throw new Problem('Повтор заголовков.');
    $out=implode(',',BANK_COLUMNS);foreach($lines as $line){$cells=$decode($line);[$ref,$date,$net,$fee,$description]=array_map(fn($i)=>$cells[$i],$order);$date=bankFormatDate($date,$cfg['date_format']);$net=bankFormatAmount($net,$cfg['decimal']);$fee=bankFormatAmount($fee,$cfg['decimal']);$quote=fn($v)=>'"'.str_replace('"','""',$v).'"';$out.="\n".implode(',',array_map($quote,[$ref,$date,$net,$fee,$description]));}
    // Canonical parser validates dates, duplicate IDs, monetary limits and balance.
    return bankParse($out,$start,$end,$opening,$closing);
}
function bankSource(PDO $db,int $org,string $id): array {
    if($id==='')return ['id'=>null,'name'=>'Workspace CSV','revision'=>1,'configuration'=>bankBuiltinFormat()];
    $r=entity($db,'SELECT * FROM bank_formats WHERE organization_id=? AND id=?',[$org,numberId($id)]);return ['id'=>(int)$r['id'],'name'=>$r['name'],'revision'=>(int)$r['revision'],'configuration'=>json_decode($r['configuration'],true,512,JSON_THROW_ON_ERROR)];
}
function bankMutable(PDO $db,int $org,int $entity,string $start): void {
    if(query($db,'SELECT 1 FROM accounting_periods WHERE organization_id=? AND closed_at IS NOT NULL AND ends_on>=?',[$org,$start])->fetchColumn()||query($db,'SELECT 1 FROM finance_book_periods WHERE entity_id=? AND closed_at IS NOT NULL AND ends_on>=?',[$entity,$start])->fetchColumn())throw new Problem('Выписка затрагивает закрытый финансовый период.',409);
}
function bankReviewDigest(array $row): string {
    $h=hash_init('sha256');rowDigest($h,array_intersect_key($row,array_flip(['id','statement_id','version','booked_on','net_minor','fee_minor','external_ref','description','entity_id','currency'])));
    foreach($row['allocations'] as $a){$cash=$a['cash'];$rate=$a['rate'];unset($a['cash'],$a['rate']);rowDigest($h,$a);rowDigest($h,$cash);if($rate)rowDigest($h,$rate);}return hash_final($h);
}
function bankReviewNeeded(array $row,bool $corrected): bool {
    foreach($row['allocations'] as $a)if((int)$a['variance_minor']!==0)return true;return $corrected;
}
function bankIndependent(array $row,int $actor): bool {
    foreach($row['allocations'] as $a)if((int)$a['actor_id']===$actor)return false;return true;
}
function bankReviewRows(PDO $db,array $s,array $result): array {
    $corrected=array_fill_keys(query($db,"SELECT DISTINCT row_id FROM bank_match_history WHERE statement_id=? AND event='unmatched'",[$s['id']])->fetchAll(PDO::FETCH_COLUMN),true);
    $last=array_column(query($db,'SELECT r.* FROM bank_discrepancy_reviews r WHERE r.statement_id=? AND r.id=(SELECT MAX(x.id) FROM bank_discrepancy_reviews x WHERE x.row_id=r.row_id) ORDER BY r.row_id',[$s['id']])->fetchAll(),null,'row_id');$out=[];
    foreach($result['details'] as $row){$id=(int)$row['id'];if(!bankReviewNeeded($row,isset($corrected[$id])))continue;$digest=bankReviewDigest($row);$r=$last[$id]??null;$current=$r&&hash_equals($r['evidence_digest'],$digest)&&(int)$r['row_version']===(int)$row['version']&&bankIndependent($row,(int)$r['actor_id']);$out[$id]=['digest'=>$digest,'status'=>$row['state']!=='matched'?'unmatched':($current?$r['decision']:'pending'),'review'=>$r];}return $out;
}
function bankReviewCommand(PDO $db,array $u,int $org,int $actor,array $f): int {
    $id=numberId($f['id']);$row=bankRow($db,$org,$id);$s=bankStatement($db,$org,(int)$row['statement_id']);bankMutable($db,$org,(int)$s['entity_id'],$s['starts_on']);
    $result=bankReport($db,$s);$reviews=bankReviewRows($db,$s,$result);$check=$reviews[$id]??null;$detail=array_column($result['details'],null,'id')[$id];
    if(!$check||$detail['state']!=='matched'||(int)$row['version']!==numberId($f['version'])||!hash_equals($check['digest'],$f['evidence_digest']))throw new Problem('Доказательства изменились или не требуют подтверждения.',409);
    if(!mfaFresh())throw new Problem('Требуется MFA.',403);
    if(!bankIndependent($detail,$actor))throw new Problem('Требуется независимый сотрудник, не выполнявший текущее сопоставление.',403);
    if((string)($check['review']['id']??'')!==$f['previous_review'])throw new Problem('Решение уже изменено.',409);
    if($check['status']==='approved')throw new Problem('Уже подтверждено.',409);
    $decision=choice($f['decision'],['approved'=>'','rejected'=>'']);$note=word($f['note'],500,'Основание');
    query($db,'INSERT INTO bank_discrepancy_reviews(organization_id,statement_id,row_id,row_version,evidence_digest,decision,note,actor_id,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$org,$s['id'],$id,$row['version'],$check['digest'],$decision,$note,$actor,time()]);return $id;
}
function bankCloseCheck(PDO $db,int $org,?int $entity,string $start,string $end,?int $actor=null): void {
    $args=[$org];$sql='SELECT * FROM bank_accounts WHERE organization_id=?';if($entity!==null){$sql.=' AND entity_id=?';$args[]=$entity;}
    foreach(query($db,$sql,$args)->fetchAll() as $a){
        $statements=query($db,'SELECT * FROM bank_statements WHERE account_id=? ORDER BY starts_on',[$a['id']])->fetchAll();
        if(!$statements)throw new Problem('Нет выписки по банковскому счёту #'.$a['id'],409);
        if($statements[0]['starts_on']>$end)continue; // first observation after the period
        $last=$statements[count($statements)-1];if($last['ends_on']<$end)throw new Problem('Выписки не покрывают конец периода по счёту #'.$a['id'],409);
        foreach($statements as $raw){if($raw['ends_on']<$start||$raw['starts_on']>$end)continue;
            if($raw['ends_on']>$end)throw new Problem('Закрывайте период на границе выписки #'.$raw['id'].' (разделите выписки до импорта).',409);
            $s=bankStatement($db,$org,(int)$raw['id']);$result=bankReport($db,$s);if(!$result['report']['ok'])throw new Problem('Не завершена банковская сверка #'.$s['id'],409);
            $reviews=bankReviewRows($db,$s,$result);foreach($reviews as $r)if($r['status']!=='approved')throw new Problem('Не подтверждены расхождения или исправления выписки #'.$s['id'],409);
            if($actor!==null){$snapshot=$result['report'];$snapshot['closing_ready']=true;$snapshot['closing_scope']=['entity_id'=>$entity,'starts_on'=>$start,'ends_on'=>$end];$snapshot['independent_reviews']=array_map(fn($r)=>['status'=>$r['status'],'digest'=>$r['digest'],'review_id'=>$r['review']['id']??null],$reviews);query($db,'INSERT INTO bank_reconciliations(organization_id,statement_id,digest,report,ok,actor_id,created_at) VALUES(?,?,?,?,1,?,?)',[$org,$s['id'],$result['digest'],json_encode($snapshot,JSON_THROW_ON_ERROR),$actor,time()]);}
        }
    }
}
function bankExport(PDO $db,array $user,int $id): array {
    if(!mfaFresh())throw new Problem('Требуется MFA.',403);
    return atomic($db,function()use($db,$user,$id){$u=accountingUser($db,$user);$org=(int)$u['organization_id'];$s=bankStatement($db,$org,$id);$r=bankReport($db,$s);
        $history=[];foreach(['corrections'=>'bank_match_history','reconciliations'=>'bank_reconciliations','reviews'=>'bank_discrepancy_reviews'] as $key=>$table){$rows=query($db,"SELECT * FROM $table WHERE organization_id=? AND statement_id=? ORDER BY id LIMIT 20001",[$org,$id])->fetchAll();if(count($rows)>20000)throw new Problem('История превышает предел одного экспорта (20000 событий каждого типа).',409);foreach($rows as &$event)foreach(['evidence','report'] as $field)if(isset($event[$field]))$event[$field]=json_decode($event[$field],true,512,JSON_THROW_ON_ERROR);unset($event);$history[$key]=$rows;}
        $source=query($db,'SELECT * FROM bank_import_sources WHERE statement_id=?',[$id])->fetch()?:null;if($source)$source['format_snapshot']=json_decode($source['format_snapshot'],true,512,JSON_THROW_ON_ERROR);
        return ['format'=>1,'exported_at'=>time(),'statement'=>$s,'import_source'=>$source,'current'=>$r,'review_status'=>bankReviewRows($db,$s,$r),'history'=>$history];
    });
}
// Suggestions are never posted automatically; exact same-currency candidates only.
function bankSuggestions(array $row,array $candidates): array {
    $out=[];foreach(['principal'=>(int)$row['net_minor']+(int)$row['fee_minor'],'fee'=>-(int)$row['fee_minor']] as $component=>$target){if(!$target)continue;$out[$component]=[];foreach($candidates as $c){if($c['currency']!==$row['currency']||(int)$c['amount_minor']!==abs($target)||($c['direction']==='income')!==($target>0)||($component==='fee'&&($c['invoice_id']!==null||!empty($c['supplier_bill']))))continue;$ref=$component==='principal'&&in_array($row['external_ref'],[$c['invoice_reference']??null,$c['supplier_reference']??null],true);$out[$component][]=['id'=>(int)$c['id'],'project_id'=>(int)$c['project_id'],'score'=>$ref?100:60,'reason'=>$ref?'Точная сумма, дата, валюта и ID платежа':'Точная сумма, дата и валюта'];}usort($out[$component],fn($a,$b)=>($b['score']<=>$a['score'])?:($a['id']<=>$b['id']));}return $out;
}
