<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const BANK_LIMIT=99999999999;
const BANK_SCALE=1000000;
// Currency units are stored in minor units (all supported currencies have 2 decimals).
function bankRate(mixed $raw): int {
    if(!is_string($raw)||!preg_match('/\A(\d{1,4})(?:[.,](\d{1,6}))?\z/',trim($raw),$m))throw new Problem('Курс: положительное число, до 6 десятичных знаков.');
    $v=(int)$m[1]*BANK_SCALE+(int)str_pad($m[2]??'',6,'0');if($v<1)throw new Problem('Курс должен быть положительным.');return $v;
}
function bankConvert(int $amount,int $rate): int {
    if(abs($amount)>BANK_LIMIT||$rate<1||$rate>9999999999)throw new Problem('Недопустимая сумма или курс.');
    // Avoid overflowing amount * rate. Half away from zero, once per component.
    $a=abs($amount);$v=intdiv($a,BANK_SCALE)*$rate+intdiv(($a%BANK_SCALE)*$rate+intdiv(BANK_SCALE,2),BANK_SCALE);
    if($v<1||$v>BANK_LIMIT)throw new Problem('Пересчитанная сумма вне диапазона.');return $amount<0?-$v:$v;
}
function bankParse(mixed $csv,string $start,string $end,int $opening,int $closing): array {
    if(!is_string($csv)||strlen($csv)>1048576||!preg_match('//u',$csv))throw new Problem('CSV должен быть UTF-8, не более 1 МиБ.');
    $csv=preg_replace('/\A\xEF\xBB\xBF/','',$csv);$lines=preg_split('/\r\n|\n|\r/',trim($csv,"\r\n"));
    if(array_shift($lines)!=='reference,date,amount,fee,description'||count($lines)>5000)throw new Problem('Неверный заголовок CSV или более 5000 операций.');
    $rows=[];$seen=[];$balance=$opening;
    foreach($lines as $n=>$line){
        if(!preg_match('/\A(?:[^",]*|"(?:[^"]|"")*")(?:,(?:[^",]*|"(?:[^"]|"")*")){4}\z/uD',$line))throw new Problem('Некорректная строка CSV: '.($n+2));
        [$ref,$date,$net,$fee,$description]=str_getcsv($line,',','"','');$ref=word($ref,180,'Банковский идентификатор');$date=calendarDate($date);$net=accountingSigned($net);$fee=accountingSigned($fee);$description=word($description,500,'Назначение');
        if(isset($seen[$ref])||$date<$start||$date>$end||$net===0||$fee<0||abs($net+$fee)>BANK_LIMIT)throw new Problem('Повтор ID, недопустимая дата или сумма в строке '.($n+2));
        $seen[$ref]=true;$balance=accountingAdd($balance,$net);$rows[]=['ref'=>$ref,'date'=>$date,'net'=>$net,'fee'=>$fee,'description'=>$description];
    }
    if($balance!==$closing)throw new Problem('Начальный остаток + движение не равны конечному остатку.',409);
    usort($rows,fn($a,$b)=>strcmp($a['ref'],$b['ref']));
    return $rows;
}
function bankStatement(PDO $db,int $org,int $id): array {
    return entity($db,'SELECT s.*,a.currency,a.entity_id,a.name account_name FROM bank_statements s JOIN bank_accounts a ON a.id=s.account_id WHERE s.organization_id=? AND s.id=?',[$org,$id]);
}
function bankRow(PDO $db,int $org,int $id): array {
    return entity($db,'SELECT r.*,s.organization_id,a.currency,a.entity_id FROM bank_rows r JOIN bank_statements s ON s.id=r.statement_id JOIN bank_accounts a ON a.id=s.account_id WHERE s.organization_id=? AND r.id=?',[$org,$id]);
}
function bankCashSql(): string {
    return "SELECT c.*,m.invoice_id,m.entity_id,m.kind invoice_kind,i.number_year,i.number_seq,r.status payment_status, CASE WHEN rev.id IS NULL THEN 0 ELSE 1 END reversed FROM cash_entries c JOIN projects p ON p.id=c.project_id LEFT JOIN cash_entries rev ON rev.reversal_of=c.id LEFT JOIN payment_requests r ON r.cash_entry_id=c.id LEFT JOIN invoice_movements m ON m.request_id=r.id LEFT JOIN invoices i ON i.id=m.invoice_id WHERE p.organization_id=?";
}
function bankCash(PDO $db,int $org,int $id): array {
    return entity($db,bankCashSql().' AND c.id=?',[$org,$id]);
}
function bankComponent(array $row,array $cash,string $component,?array $rate,string $explanation): array {
    $target=$component==='fee'?-(int)$row['fee_minor']:(int)$row['net_minor']+(int)$row['fee_minor'];
    if(!$target||(int)$cash['amount_minor']<=0||$cash['reversal_of']!==null||(int)$cash['reversed']!==0||$cash['booked_on']!==$row['booked_on']||($cash['direction']==='income')!==($target>0)||($cash['payment_status']!==null&&$cash['payment_status']!=='posted'))throw new Problem('Кассовая запись не соответствует дате, направлению или уже сторнирована.',409);
    if($cash['invoice_id']!==null&&((int)$cash['entity_id']!==(int)$row['entity_id']||$component==='fee'))throw new Problem('Счёт относится к другому юрлицу или комиссия связана со счётом.',409);
    $amount=($target>0?1:-1)*(int)$cash['amount_minor'];
    if($cash['currency']===$row['currency']){
        if($rate!==null||$amount!==$target)throw new Problem('Сумма в валюте счёта должна совпадать точно.',409);
        return ['equivalent'=>$amount,'variance'=>0];
    }
    if($component==='fee'||$rate===null||$rate['base_currency']!==$cash['currency']||$rate['quote_currency']!==$row['currency']||$rate['rate_on']!==$row['booked_on'])throw new Problem('Необходим курс на дату операции из валюты кассы в валюту банка.',409);
    $equivalent=bankConvert($amount,(int)$rate['rate_scaled']);$variance=$target-$equivalent;
    if(abs($variance)>BANK_LIMIT||($variance!==0&&$explanation===''))throw new Problem('Укажите основание валютного расхождения.',409);
    return ['equivalent'=>$equivalent,'variance'=>$variance];
}
function bankReport(PDO $db,array $statement): array {
    $rows=query($db,'SELECT * FROM bank_rows WHERE statement_id=? ORDER BY id',[$statement['id']])->fetchAll();
    $allocs=query($db,'SELECT * FROM bank_allocations WHERE statement_id=? ORDER BY row_id,component',[$statement['id']])->fetchAll();$by=[];foreach($allocs as $a)$by[$a['row_id']][$a['component']]=$a;
    $cashRows=array_column(query($db,bankCashSql().' AND c.id IN (SELECT cash_id FROM bank_allocations WHERE statement_id=?)',[$statement['organization_id'],$statement['id']])->fetchAll(),null,'id');
    $rateRows=array_column(query($db,'SELECT * FROM bank_rates WHERE organization_id=? AND id IN (SELECT rate_id FROM bank_allocations WHERE statement_id=?)',[$statement['organization_id'],$statement['id']])->fetchAll(),null,'id');
    $hash=hash_init('sha256');rowDigest($hash,$statement);$report=['rows'=>count($rows),'unmatched'=>0,'invalid'=>0,'net'=>0,'fees'=>0,'equivalent'=>0,'fx_variance'=>0,'unmatched_net'=>0];$details=[];
    foreach($rows as $row){
        rowDigest($hash,$row);$net=(int)$row['net_minor'];$fee=(int)$row['fee_minor'];$report['net']=accountingAdd($report['net'],$net);$report['fees']=accountingAdd($report['fees'],$fee);$row+=['currency'=>$statement['currency'],'entity_id'=>$statement['entity_id']];$matched=0;$state='matched';$entries=[];$projects=[];
        foreach(['principal'=>$net+$fee,'fee'=>-$fee] as $component=>$target){
            $a=$by[$row['id']][$component]??null;if($target===0&&$a===null)continue;
            if(!$a){if($state!=='invalid')$state='unmatched';continue;}rowDigest($hash,$a);
            try{$cash=$cashRows[$a['cash_id']]??throw new Problem('Кассовая запись недоступна.',409);rowDigest($hash,$cash);$rate=$a['rate_id']===null?null:($rateRows[$a['rate_id']]??throw new Problem('Курс недоступен.',409));if($rate)rowDigest($hash,$rate);$computed=bankComponent($row,$cash,$component,$rate,$a['explanation']);
                if($computed['equivalent']!==(int)$a['equivalent_minor']||$computed['variance']!==(int)$a['variance_minor'])throw new Problem('Снимок сопоставления изменился.',409);
                $projects[]=(int)$cash['project_id'];$entries[$component]=$a+['cash'=>$cash,'rate'=>$rate];$report['equivalent']=accountingAdd($report['equivalent'],$computed['equivalent']);$report['fx_variance']=accountingAdd($report['fx_variance'],$computed['variance']);$matched=accountingAdd($matched,$target);
            }catch(Problem){$state='invalid';}
        }
        if(count(array_unique($projects))>1)$state='invalid';if($state==='invalid')$report['invalid']++;if($state!=='matched')$report['unmatched']++;
        $report['unmatched_net']=accountingAdd($report['unmatched_net'],$net-$matched);$details[]=$row+['state'=>$state,'allocations'=>$entries,'has_allocations'=>isset($by[$row['id']])];
    }
    $report['calculated_closing']=accountingAdd((int)$statement['opening_minor'],$report['net']);$report['balance_difference']=$report['calculated_closing']-(int)$statement['closing_minor'];
    $report['unexplained']=accountingAdd(accountingAdd($report['net'],-$report['equivalent']),-$report['fx_variance']);
    $report['ok']=$report['unmatched']===0&&$report['invalid']===0&&$report['balance_difference']===0&&$report['unexplained']===0;
    return ['report'=>$report,'digest'=>hash_final($hash),'details'=>$details];
}
function bankCommand(PDO $db,array $user,string $key,string $act,array $form): int {
    if(!preg_match('/\A[a-f0-9]{32}\z/',$key))throw new Problem('Обновите форму.');
    $u=accountingUser($db,$user,true);$org=(int)$u['organization_id'];
    $fields=match($act){'bank.account'=>['entity_id','name','account_ref','currency'],'bank.rate'=>['rate_on','base_currency','quote_currency','rate','source'],'bank.import'=>['account_id','starts_on','ends_on','opening','closing','csv','format_id'],'bank.format'=>['name','delimiter','date_format','decimal',...array_map(fn($c)=>'header_'.$c,BANK_COLUMNS)],'bank.review'=>['id','version','evidence_digest','decision','note','previous_review'],'bank.match'=>['id','version','principal_id','fee_id','rate_id','explanation'],'bank.unmatch'=>['id','version','explanation'],'bank.reconcile'=>['id'],default=>throw new Problem('Неизвестная банковская операция.')};
    $f=[];foreach($fields as $field){$v=$form[$field]??'';if(!is_string($v)&&!is_int($v))throw new Problem('Некорректная форма.');$f[$field]=(string)$v;}
    if(strlen($f['csv']??'')>1048576)throw new Problem('CSV превышает 1 МиБ.');$fingerprint=$f;if($act==='bank.import'&&$f['format_id']==='')unset($fingerprint['format_id']);$digest=hash('sha256',json_encode([$act,$org,$fingerprint],JSON_THROW_ON_ERROR));
    return atomic($db,function()use($db,$u,$org,$key,$act,$f,$digest){
        $u=accountingUser($db,$u,true);$actor=(int)$u['id'];if($act==='bank.account'&&$u['role']!=='owner')throw new Problem('Требуется владелец.',403);
        $old=query($db,'SELECT fingerprint,object_id FROM action_log WHERE actor_id=? AND request_key=?',[$actor,$key])->fetch();if($old){if(!hash_equals($old['fingerprint'],$digest))throw new Problem('Повтор с изменёнными данными.',409);return (int)$old['object_id'];}
        $curr=array_flip(CURRENCIES);
        if($act==='bank.format'){
            $name=word($f['name'],120,'Название формата');if((int)query($db,'SELECT COUNT(*) FROM bank_formats WHERE organization_id=?',[$org])->fetchColumn()>=500)throw new Problem('Достигнут предел 500 редакций форматов.',409);$cfg=bankFormatConfig($f);$rev=1+(int)query($db,'SELECT COALESCE(MAX(revision),0) FROM bank_formats WHERE organization_id=? AND name=?',[$org,$name])->fetchColumn();
            $id=insertId($db,'INSERT INTO bank_formats(organization_id,name,revision,configuration,actor_id,created_at) VALUES(?,?,?,?,?,?)',[$org,$name,$rev,json_encode($cfg,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$actor,time()]);
        }elseif($act==='bank.review'){$id=bankReviewCommand($db,$u,$org,$actor,$f);
        }elseif($act==='bank.account'){
            $entity=numberId($f['entity_id']);entity($db,'SELECT id FROM legal_entities WHERE organization_id=? AND id=?',[$org,$entity]);$name=word($f['name'],180,'Название');$ref=word($f['account_ref'],180,'Идентификатор счёта');$currency=choice($f['currency'],$curr);
            if(query($db,'SELECT 1 FROM bank_accounts WHERE organization_id=? AND account_ref=? AND currency=?',[$org,$ref,$currency])->fetchColumn())throw new Problem('Банковский счёт уже существует.',409);
            $id=insertId($db,'INSERT INTO bank_accounts(organization_id,entity_id,name,account_ref,currency) VALUES(?,?,?,?,?)',[$org,$entity,$name,$ref,$currency]);
        }elseif($act==='bank.rate'){
            $date=calendarDate($f['rate_on']);$base=choice($f['base_currency'],$curr);$quote=choice($f['quote_currency'],$curr);$rate=bankRate($f['rate']);$source=word($f['source'],500,'Источник курса');if($base===$quote||$date>gmdate('Y-m-d'))throw new Problem('Выберите разные валюты и прошедшую дату.');
            $r=query($db,'SELECT * FROM bank_rates WHERE organization_id=? AND rate_on=? AND base_currency=? AND quote_currency=?',[$org,$date,$base,$quote])->fetch();
            if($r){if((int)$r['rate_scaled']!==$rate||$r['source']!==$source)throw new Problem('Курс уже зафиксирован на эту дату и пару.',409);$id=(int)$r['id'];}else $id=insertId($db,'INSERT INTO bank_rates(organization_id,rate_on,base_currency,quote_currency,rate_scaled,source,actor_id,created_at) VALUES(?,?,?,?,?,?,?,?)',[$org,$date,$base,$quote,$rate,$source,$actor,time()]);
        }elseif($act==='bank.import'){
            $account=numberId($f['account_id']);$accountRow=entity($db,'SELECT * FROM bank_accounts WHERE organization_id=? AND id=?',[$org,$account]);$start=calendarDate($f['starts_on']);$end=calendarDate($f['ends_on']);if($start>$end||$end>gmdate('Y-m-d'))throw new Problem('Недопустимый период выписки.');$opening=accountingSigned($f['opening']);$closing=accountingSigned($f['closing']);$source=bankSource($db,$org,$f['format_id']);$rows=$source['id']===null?bankParse($f['csv'],$start,$end,$opening,$closing):bankParseFormat($f['csv'],$source['configuration'],$start,$end,$opening,$closing);$sha=hash('sha256',json_encode([$start,$end,$opening,$closing,$rows],JSON_THROW_ON_ERROR));
            $same=query($db,'SELECT id FROM bank_statements WHERE account_id=? AND digest=?',[$account,$sha])->fetchColumn();
            if($same)$id=(int)$same;else{
                bankMutable($db,$org,(int)$accountRow['entity_id'],$start);
                $last=query($db,'SELECT * FROM bank_statements WHERE account_id=? ORDER BY ends_on DESC LIMIT 1',[$account])->fetch();
                if($last&&($start!==gmdate('Y-m-d',strtotime($last['ends_on'].' UTC')+86400)||$opening!==(int)$last['closing_minor']))throw new Problem('Выписки должны идти последовательно, без разрыва дат и остатков.',409);
                foreach($rows as $r)if(query($db,'SELECT 1 FROM bank_rows WHERE account_id=? AND external_ref=?',[$account,$r['ref']])->fetchColumn())throw new Problem('Банковская операция уже импортирована.',409);
                $id=insertId($db,'INSERT INTO bank_statements(organization_id,account_id,starts_on,ends_on,opening_minor,closing_minor,digest,actor_id,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$org,$account,$start,$end,$opening,$closing,$sha,$actor,time()]);
                query($db,'INSERT INTO bank_import_sources(statement_id,organization_id,format_id,format_snapshot,raw_digest) VALUES(?,?,?,?,?)',[$id,$org,$source['id'],json_encode($source,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),hash('sha256',$f['csv'])]);
                foreach($rows as $r)query($db,'INSERT INTO bank_rows(account_id,statement_id,external_ref,booked_on,net_minor,fee_minor,description) VALUES(?,?,?,?,?,?,?)',[$account,$id,$r['ref'],$r['date'],$r['net'],$r['fee'],$r['description']]);
            }
        }elseif($act==='bank.reconcile'){
            $s=bankStatement($db,$org,numberId($f['id']));$result=bankReport($db,$s);$reviewRows=bankReviewRows($db,$s,$result);$result['report']['closing_ready']=$result['report']['ok']&&!array_filter($reviewRows,fn($r)=>$r['status']!=='approved');$result['report']['independent_reviews']=array_map(fn($r)=>['status'=>$r['status'],'digest'=>$r['digest'],'review_id'=>$r['review']['id']??null],$reviewRows);$id=insertId($db,'INSERT INTO bank_reconciliations(organization_id,statement_id,digest,report,ok,actor_id,created_at) VALUES(?,?,?,?,?,?,?)',[$org,$s['id'],$result['digest'],json_encode($result['report'],JSON_THROW_ON_ERROR),(int)$result['report']['ok'],$actor,time()]);
        }else{
            $id=numberId($f['id']);$row=bankRow($db,$org,$id);$statement=bankStatement($db,$org,(int)$row['statement_id']);bankMutable($db,$org,(int)$row['entity_id'],$statement['starts_on']);if((int)$row['version']!==numberId($f['version']))throw new Problem('Операция изменена. Обновите страницу.',409);
            $existing=query($db,'SELECT * FROM bank_allocations WHERE row_id=? ORDER BY component',[$id])->fetchAll();$note=word($f['explanation'],500,'Основание',$act==='bank.unmatch');
            if($act==='bank.unmatch'){
                if(!$existing)throw new Problem('Нет сопоставления.',409);query($db,'DELETE FROM bank_allocations WHERE row_id=?',[$id]);
                // Preserve previous links and correction reason in protected accounting history.
            }else{
                if($existing)throw new Problem('Сначала отмените прежнее сопоставление.',409);$rate=$f['rate_id']===''?null:entity($db,'SELECT * FROM bank_rates WHERE organization_id=? AND id=?',[$org,numberId($f['rate_id'])]);$projects=[];
                foreach(['principal'=>(int)$row['net_minor']+(int)$row['fee_minor'],'fee'=>-(int)$row['fee_minor']] as $component=>$target){
                    $raw=$f[$component.'_id'];if(!$target){if($raw!=='')throw new Problem('Для нулевой составляющей запись не требуется.');continue;}
                    $cashId=numberId($raw);if(query($db,'SELECT 1 FROM bank_allocations WHERE cash_id=?',[$cashId])->fetchColumn())throw new Problem('Кассовая запись уже сопоставлена.',409);
                    if(query($db,"SELECT 1 FROM payment_requests WHERE reversal_of=? AND status='pending'",[$cashId])->fetchColumn())throw new Problem('Ожидается сторно кассовой записи.',409);
                    $cash=bankCash($db,$org,$cashId);$project=(int)$cash['project_id'];bookCheckEntity($db,$project,(int)$row['entity_id']);if($component==='fee'&&query($db,'SELECT 1 FROM supplier_payments s JOIN payment_requests r ON r.id=s.request_id WHERE r.cash_entry_id=?',[$cashId])->fetchColumn())throw new Problem('Комиссия должна быть отдельной кассовой записью.',409);requireProjectRight($db,$u,$project,'finance');$projects[]=$project;$selectedRate=$component==='principal'?$rate:null;$v=bankComponent($row,$cash,$component,$selectedRate,$note);
                    query($db,'INSERT INTO bank_allocations(organization_id,statement_id,row_id,component,project_id,cash_id,rate_id,equivalent_minor,variance_minor,explanation,actor_id,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$org,$row['statement_id'],$id,$component,$project,$cashId,$selectedRate['id']??null,$v['equivalent'],$v['variance'],$note,$actor,time()]);
                }
                if(count(array_unique($projects))>1)throw new Problem('Комиссия и основной платёж должны принадлежать одному проекту.',409);if($rate!==null&&(int)$row['net_minor']+(int)$row['fee_minor']===0)throw new Problem('Для отдельной комиссии курс не требуется.');
            }
            if($act==='bank.match')$existing=query($db,'SELECT * FROM bank_allocations WHERE row_id=? ORDER BY component',[$id])->fetchAll();
            query($db,'INSERT INTO bank_match_history(organization_id,statement_id,row_id,event,evidence,note,actor_id,created_at) VALUES(?,?,?,?,?,?,?,?)',[$org,$row['statement_id'],$id,$act==='bank.match'?'matched':'unmatched',json_encode($existing,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$note,$actor,time()]);
            query($db,'UPDATE bank_rows SET version=version+1 WHERE id=?',[$id]);
        }
        query($db,'INSERT INTO action_log(actor_id,request_key,fingerprint,action,object_id) VALUES(?,?,?,?,?)',[$actor,$key,$digest,$act,$id]);auditEvent($db,$u,null,$act,$id);return $id;
    });
}
function bankPage(PDO $db,array $user,?int $accountId,?int $statementId,?int $rowId,string $filter='unmatched',?int $after=null): array {
    return atomic($db,function()use($db,$user,$accountId,$statementId,$rowId,$filter,$after){
        $u=accountingUser($db,$user);$org=(int)$u['organization_id'];$writable=true;try{accountingUser($db,$u,true);}catch(Problem){$writable=false;}
        $accounts=query($db,'SELECT a.*,e.name entity_name FROM bank_accounts a JOIN legal_entities e ON e.id=a.entity_id WHERE a.organization_id=? ORDER BY a.name,a.id',[$org])->fetchAll();$entities=query($db,'SELECT id,name FROM legal_entities WHERE organization_id=? ORDER BY name,id',[$org])->fetchAll();
        $account=$accountId===null?($accounts[0]??null):entity($db,'SELECT * FROM bank_accounts WHERE organization_id=? AND id=?',[$org,$accountId]);
        $statement=$statementId===null?null:bankStatement($db,$org,$statementId);if($statement){$account=entity($db,'SELECT * FROM bank_accounts WHERE organization_id=? AND id=?',[$org,$statement['account_id']]);if($accountId!==null&&$accountId!==(int)$account['id'])throw new Problem('Выписка не принадлежит счёту.',404);}
        $statements=$account?query($db,'SELECT * FROM bank_statements WHERE account_id=? ORDER BY ends_on DESC LIMIT 100',[$account['id']])->fetchAll():[];$statement??=isset($statements[0])?bankStatement($db,$org,(int)$statements[0]['id']):null;
        $result=$statement?bankReport($db,$statement):null;$formats=query($db,'SELECT * FROM bank_formats WHERE organization_id=? ORDER BY name,revision DESC',[$org])->fetchAll();$source=$statement?(query($db,'SELECT * FROM bank_import_sources WHERE statement_id=?',[$statement['id']])->fetch()?:null):null;$reviews=$statement?bankReviewRows($db,$statement,$result):[];$history=$statement?query($db,'SELECT * FROM bank_reconciliations WHERE statement_id=? ORDER BY id DESC LIMIT 20',[$statement['id']])->fetchAll():[];$rates=query($db,'SELECT * FROM bank_rates WHERE organization_id=? ORDER BY rate_on DESC,id DESC LIMIT 100',[$org])->fetchAll();
        $filter=choice($filter,['all'=>'','unmatched'=>'','matched'=>'','review'=>'']);$visible=array_values(array_filter($result['details']??[],fn($r)=>($after===null||(int)$r['id']>$after)&&($filter==='all'||($filter==='review'?isset($reviews[$r['id']])&&$reviews[$r['id']]['status']!=='approved':($filter==='matched'?$r['state']==='matched':$r['state']!=='matched')))));$next=count($visible)>50?(int)$visible[49]['id']:null;$visible=array_slice($visible,0,50);
        $selected=null;$suggestions=[];$candidates=[];$rowRates=[];$matchHistory=[];if($rowId!==null){$selected=bankRow($db,$org,$rowId);if(!$statement||(int)$selected['statement_id']!==(int)$statement['id'])throw new Problem('Операция не принадлежит выписке.',404);foreach($result['details'] as $r)if((int)$r['id']===$rowId)$selected=$r;
            $candidates=query($db,"SELECT c.id,c.project_id,c.direction,c.amount_minor,c.currency,c.memo,p.title,m.invoice_id,m.entity_id,m.reference invoice_reference,sp.reference supplier_reference,sp.bill_id supplier_bill FROM cash_entries c JOIN projects p ON p.id=c.project_id LEFT JOIN payment_requests r ON r.cash_entry_id=c.id LEFT JOIN invoice_movements m ON m.request_id=r.id LEFT JOIN supplier_payments sp ON sp.request_id=r.id WHERE p.organization_id=? AND c.booked_on=? AND c.amount_minor>0 AND c.reversal_of IS NULL AND NOT EXISTS(SELECT 1 FROM cash_entries rev WHERE rev.reversal_of=c.id) AND NOT EXISTS(SELECT 1 FROM bank_allocations a WHERE a.cash_id=c.id) AND (m.entity_id IS NULL OR m.entity_id=?) AND (r.status IS NULL OR r.status='posted') AND NOT EXISTS(SELECT 1 FROM payment_requests pr WHERE pr.reversal_of=c.id AND pr.status='pending') AND NOT EXISTS(SELECT 1 FROM finance_book_projects fp WHERE fp.project_id=c.project_id AND fp.entity_id<>?) ORDER BY CASE WHEN m.reference=? OR sp.reference=? THEN 0 WHEN c.currency=? AND ((c.direction='income' AND c.amount_minor=?) OR (c.direction='expense' AND (c.amount_minor=? OR c.amount_minor=?))) THEN 1 ELSE 2 END,c.id DESC LIMIT 100",[$org,$selected['booked_on'],$selected['entity_id'],$selected['entity_id'],$selected['external_ref'],$selected['external_ref'],$selected['currency'],max(0,(int)$selected['net_minor']+(int)$selected['fee_minor']),max(0,-((int)$selected['net_minor']+(int)$selected['fee_minor'])),(int)$selected['fee_minor']])->fetchAll();
            $suggestions=bankSuggestions($selected,$candidates);$matchHistory=query($db,'SELECT * FROM bank_match_history WHERE organization_id=? AND row_id=? ORDER BY id DESC LIMIT 20',[$org,$rowId])->fetchAll();
            $rowRates=query($db,'SELECT * FROM bank_rates WHERE organization_id=? AND rate_on=? AND quote_currency=? ORDER BY base_currency',[$org,$selected['booked_on'],$selected['currency']])->fetchAll();
        }
        return compact('accounts','account','entities','statements','statement','result','history','rates','filter','visible','next','selected','candidates','rowRates','matchHistory','writable','formats','source','reviews','suggestions');
    });
}
