<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const ACCOUNT_KINDS=['asset'=>'Актив','liability'=>'Обязательство','equity'=>'Капитал','income'=>'Доход','expense'=>'Расход'];
const ACCOUNT_DEFAULTS=['1000'=>['Денежные средства','asset'],'2000'=>['Обязательства','liability'],'3000'=>['Начальный капитал','equity'],'4000'=>['Поступления (кассовый метод)','income'],'5000'=>['Расходы (кассовый метод)','expense']];

// Organization-wide reports must never expose projects to a partially authorized accountant.
function accountingUser(PDO $db,array $user,bool $write=false): array {
    $u=organizationUser($db,$user);
    if($u['role']==='owner')return $u;
    if($u['role']!=='accountant')throw new Problem('Требуется владелец или бухгалтер организации.',403);
    foreach($write?['finance','payment.create','payment.approve']:['finance'] as $right){
        [$scope,$args]=projectRightScope($db,$u,$right);
        if(query($db,"SELECT 1 FROM projects p WHERE p.organization_id=? AND NOT ($scope) LIMIT 1",[$u['organization_id'],...$args])->fetchColumn())throw new Problem($write?'Для изменения общего журнала бухгалтеру нужны права создания и согласования платежей во всех проектах.':'Для общего журнала бухгалтеру нужен доступ к финансам всех проектов организации.',403);
    }
    return $u;
}
function accountingAccounts(PDO $db,int $org): array {
    return query($db,'SELECT * FROM accounting_accounts WHERE organization_id=? ORDER BY code',[$org])->fetchAll();
}
function accountingSeed(PDO $db,int $org): void {
    foreach(ACCOUNT_DEFAULTS as $code=>[$name,$kind])query($db,'INSERT INTO accounting_accounts(organization_id,code,name,kind) VALUES(?,?,?,?) ON CONFLICT(organization_id,code) DO NOTHING',[$org,(string)$code,$name,$kind]);
}
function accountingActive(PDO $db,int $org): bool {
    return (bool)query($db,'SELECT 1 FROM accounting_openings WHERE organization_id=? AND approved_at IS NOT NULL',[$org])->fetchColumn();
}
function accountingPeriod(PDO $db,int $org,string $date): array {
    $p=query($db,'SELECT * FROM accounting_periods WHERE organization_id=? AND starts_on<=? AND ends_on>=?',[$org,$date,$date])->fetch();
    if(!$p || $p['closed_at']!==null)throw new Problem('Для даты нет открытого бухгалтерского периода.',409);
    return $p;
}
function accountingAdd(int $a,int $b): int {
    if(($b>0 && $a>PHP_INT_MAX-$b)||($b<0 && $a<(-PHP_INT_MAX)-$b))throw new Problem('Превышен диапазон финансового отчёта.',422);
    return $a+$b;
}
function accountingSigned(mixed $raw): int {
    if(!is_string($raw)||!preg_match('/\A(-?)(\d{1,9})(?:[.,](\d{1,2}))?\z/',trim($raw),$m))throw new Problem('Укажите остаток числом с двумя десятичными знаками.');
    return ($m[1]==='-'?-1:1)*((int)$m[2]*100+(int)str_pad($m[3]??'',2,'0'));
}
function accountingCashRows(PDO $db,int $org): \PDOStatement {
    return query($db,'SELECT e.* FROM cash_entries e JOIN projects p ON p.id=e.project_id WHERE p.organization_id=? ORDER BY e.id',[$org]);
}
function accountingCashSnapshot(PDO $db,int $org): array {
    $hash=hash_init('sha256');$count=0;$totals=[];$first=null;$last=null;
    foreach(CURRENCIES as $c)$totals[$c]=['income'=>0,'expense'=>0,'net'=>0];
    $source=accountingCashRows($db,$org);while($r=$source->fetch()){
        rowDigest($hash,$r);$count++;$date=$r['booked_on'];$first=$first===null? $date:min($first,$date);$last=$last===null?$date:max($last,$date);
        $c=$r['currency'];$d=$r['direction'];$a=(int)$r['amount_minor'];$totals[$c][$d]=accountingAdd($totals[$c][$d],$a);$totals[$c]['net']=accountingAdd($totals[$c]['net'],$d==='income'?$a:-$a);
    }
    $source->closeCursor();
    return ['digest'=>hash_final($hash),'count'=>$count,'first'=>$first,'last'=>$last,'totals'=>$totals];
}
function accountingPairs(array $accounts,mixed $raw): array {
    if(!is_string($raw)||strlen($raw)>24000)throw new Problem('Некорректные начальные остатки.');
    $map=array_column($accounts,null,'code');$pairs=[];
    foreach(preg_split('/\r\n|\n|\r/',trim($raw)) as $line){
        if(trim($line)==='')continue;
        $parts=preg_split('/\s+/',trim($line));if(count($parts)!==4||count($pairs)>=100)throw new Problem('Одна строка: валюта дебет кредит сумма; максимум 100 строк.');
        [$currency,$debit,$credit,$amount]=$parts;choice($currency,array_flip(CURRENCIES));
        if(!isset($map[$debit],$map[$credit])||$debit===$credit||!in_array($map[$debit]['kind'],['asset','liability','equity'],true)||!in_array($map[$credit]['kind'],['asset','liability','equity'],true))throw new Problem('Начальные остатки допускают только разные балансовые счета.');
        $pairs[]=['currency'=>$currency,'debit'=>(int)$map[$debit]['id'],'credit'=>(int)$map[$credit]['id'],'amount'=>moneyMinor($amount)];
    }
    return $pairs;
}
function accountingExpected(array $accounts,array $snapshot,array $pairs,array $expected): void {
    $cash=(int)array_column($accounts,'id','code')['1000'];
    $actual=[];foreach(CURRENCIES as $c)$actual[$c]=$snapshot['totals'][$c]['net'];
    foreach($pairs as $p){$delta=$p['debit']===$cash?$p['amount']:($p['credit']===$cash?-$p['amount']:0);$actual[$p['currency']]=accountingAdd($actual[$p['currency']],$delta);}
    foreach(CURRENCIES as $c)if($actual[$c]!==$expected[$c])throw new Problem('Остаток денежных средств не сходится: '.$c.'. Проверьте выписку и начальные проводки.',409);
}
function accountingInsert(PDO $db,int $org,int $period,?int $project,string $date,string $currency,int $debit,int $credit,int $amount,string $kind,string $memo,int $actor,?int $cash=null,?int $reverse=null): int {
    return insertId($db,'INSERT INTO accounting_entries(organization_id,period_id,project_id,booked_on,currency,debit_id,credit_id,amount_minor,kind,memo,actor_id,created_at,cash_id,reversal_of) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$org,$period,$project,$date,$currency,$debit,$credit,$amount,$kind,$memo,$actor,time(),$cash,$reverse]);
}
// Called inside the cash transaction, never opens a second transaction.
function accountingCashEntry(PDO $db,int $id,bool $import=false): void {
    $r=entity($db,'SELECT e.*,p.organization_id FROM cash_entries e JOIN projects p ON p.id=e.project_id WHERE e.id=?',[$id]);$org=(int)$r['organization_id'];
    if(!$import&&!accountingActive($db,$org))return;
    $period=accountingPeriod($db,$org,$r['booked_on']);$accounts=array_column(accountingAccounts($db,$org),'id','code');
    $cash=(int)$accounts['1000'];$opposite=(int)$accounts[$r['direction']==='income'?'4000':'5000'];$debit=$r['direction']==='income'?$cash:$opposite;$credit=$r['direction']==='income'?$opposite:$cash;$reverse=null;$amount=(int)$r['amount_minor'];
    if($r['reversal_of']!==null){
        $old=entity($db,"SELECT * FROM accounting_entries WHERE organization_id=? AND cash_id=? AND kind='cash'",[$org,$r['reversal_of']]);
        if($amount!==-(int)$old['amount_minor']||$r['currency']!==$old['currency']||(int)$r['project_id']!==(int)$old['project_id'])throw new Problem('Сторно кассы не соответствует исходной проводке.',409);
        $reverse=(int)$old['id'];$debit=(int)$old['credit_id'];$credit=(int)$old['debit_id'];$amount=-$amount;
    }
    accountingInsert($db,$org,(int)$period['id'],(int)$r['project_id'],$r['booked_on'],$r['currency'],$debit,$credit,$amount,'cash','Касса #'.$id,(int)$r['actor_id'],$id,$reverse);
}
function accountingCheckCashDate(PDO $db,int $project,string $date): void {
    $org=(int)entity($db,'SELECT organization_id FROM projects WHERE id=?',[$project])['organization_id'];
    if(accountingActive($db,$org))accountingPeriod($db,$org,$date);
}
function accountingReconcile(PDO $db,int $org): array {
    $snapshot=accountingCashSnapshot($db,$org);$cash=(int)array_column(accountingAccounts($db,$org),'id','code')['1000'];
    $rows=query($db,"SELECT currency,COUNT(*) n,SUM(CASE WHEN debit_id=? THEN amount_minor WHEN credit_id=? THEN -amount_minor ELSE 0 END) net FROM accounting_entries WHERE organization_id=? AND kind='cash' GROUP BY currency",[$cash,$cash,$org])->fetchAll();
    $count=0;$net=array_fill_keys(CURRENCIES,0);foreach($rows as $r){$count+=(int)$r['n'];$net[$r['currency']]=(int)$r['net'];}
    $missing=(int)query($db,"SELECT COUNT(*) FROM cash_entries c JOIN projects p ON p.id=c.project_id LEFT JOIN accounting_entries e ON e.cash_id=c.id AND e.organization_id=p.organization_id AND e.kind='cash' WHERE p.organization_id=? AND (e.id IS NULL OR e.project_id<>c.project_id OR e.booked_on<>c.booked_on OR e.currency<>c.currency OR e.amount_minor<>ABS(c.amount_minor) OR (CASE WHEN e.debit_id=? THEN e.amount_minor WHEN e.credit_id=? THEN -e.amount_minor ELSE 0 END)<>(CASE WHEN c.direction='income' THEN c.amount_minor ELSE -c.amount_minor END))",[$org,$cash,$cash])->fetchColumn();
    $ok=$count===$snapshot['count']&&$missing===0;foreach(CURRENCIES as $c)$ok=$ok&&$net[$c]===$snapshot['totals'][$c]['net'];
    return ['ok'=>$ok,'count'=>$count,'missing'=>$missing,'cash'=>$snapshot,'net'=>$net];
}
function accountingCommand(PDO $db,array $user,string $key,string $act,array $form): int {
    if(!preg_match('/\A[a-f0-9]{32}\z/',$key))throw new Problem('Обновите форму.');
    $u=accountingUser($db,$user,true);$org=$u['organization_id'];
    // Fingerprint only business fields, excluding CSRF and request metadata.
    $fields=match($act){'accounting.init'=>[],'accounting.account'=>['code','name','kind'],'accounting.period'=>['starts_on','ends_on'],'accounting.period_edit'=>['id','starts_on','ends_on'],'accounting.prepare'=>['period_id','pairs','evidence',...CURRENCIES],'accounting.approve'=>['id','version','note'],'accounting.post'=>['booked_on','currency','debit','credit','amount','project_id','memo'],'accounting.reverse'=>['id','booked_on','memo'],'accounting.close'=>['id'],default=>throw new Problem('Неизвестная операция.')};
    $f=[];foreach($fields as $field){$value=$form[$field]??'';if(!is_string($value)&&!is_int($value))throw new Problem('Некорректная форма.');$f[$field]=(string)$value;}
    $digest=hash('sha256',json_encode([$act,$org,$f],JSON_THROW_ON_ERROR));
    return atomic($db,function()use($db,$u,$org,$key,$act,$f,$digest){
        $u=accountingUser($db,$u,true);$owner=$u['role']==='owner';$actor=(int)$u['id'];
        if(in_array($act,['accounting.init','accounting.account','accounting.prepare','accounting.period_edit'],true)&&!$owner)throw new Problem('Требуется владелец организации.',403);
        if($act==='accounting.approve'&&$u['role']!=='accountant')throw new Problem('Начальные остатки подтверждает бухгалтер организации.',403);
        $previous=query($db,'SELECT fingerprint,object_id FROM action_log WHERE actor_id=? AND request_key=?',[$actor,$key])->fetch();
        if($previous){if(!hash_equals($previous['fingerprint'],$digest))throw new Problem('Повтор с изменёнными данными.',409);return (int)$previous['object_id'];}
        $active=accountingActive($db,$org);$id=$org;
        if($act==='accounting.init')accountingSeed($db,$org);
        elseif($act==='accounting.account'){
            accountingSeed($db,$org);$code=word($f['code'],20,'Код');if(!preg_match('/\A[A-Z0-9][A-Z0-9.-]{0,19}\z/',$code))throw new Problem('Код счёта: A-Z, цифры, точка, дефис.');
            if(query($db,'SELECT 1 FROM accounting_accounts WHERE organization_id=? AND code=?',[$org,$code])->fetchColumn())throw new Problem('Код уже существует.',409);
            $id=insertId($db,'INSERT INTO accounting_accounts(organization_id,code,name,kind) VALUES(?,?,?,?)',[$org,$code,word($f['name'],180,'Название'),choice($f['kind'],ACCOUNT_KINDS)]);
        }elseif($act==='accounting.period'){
            $start=calendarDate($f['starts_on']);$end=calendarDate($f['ends_on']);if($start>$end)throw new Problem('Проверьте даты периода.');
            $last=query($db,'SELECT * FROM accounting_periods WHERE organization_id=? ORDER BY ends_on DESC LIMIT 1',[$org])->fetch();
            if($last&&(!$active||$last['ends_on']==='9999-12-31'||gmdate('Y-m-d',strtotime($last['ends_on'].' +1 day'))!==$start))throw new Problem('Следующий период должен начинаться на следующий день; сначала примите начальные остатки.',409);
            $id=insertId($db,'INSERT INTO accounting_periods(organization_id,starts_on,ends_on) VALUES(?,?,?)',[$org,$start,$end]);
        }elseif($act==='accounting.period_edit'){
            if($active)throw new Problem('Период после активации неизменяем.',409);
            $id=numberId($f['id']);entity($db,'SELECT id FROM accounting_periods WHERE id=? AND organization_id=?',[$id,$org]);
            $start=calendarDate($f['starts_on']);$end=calendarDate($f['ends_on']);if($start>$end)throw new Problem('Проверьте даты периода.');
            query($db,'UPDATE accounting_periods SET starts_on=?,ends_on=? WHERE id=?',[$start,$end,$id]);
            query($db,'UPDATE accounting_openings SET version=version+1 WHERE period_id=?',[$id]);
        }elseif($act==='accounting.prepare'){
            if($active)throw new Problem('Перенос уже принят.',409);accountingSeed($db,$org);
            $period=entity($db,'SELECT * FROM accounting_periods WHERE id=? AND organization_id=?',[numberId($f['period_id']),$org]);
            $s=accountingCashSnapshot($db,$org);$today=gmdate('Y-m-d');
            if($period['closed_at']!==null||$period['starts_on']>($s['first']??$today)||$period['ends_on']<max($s['last']??$today,$today)||$period['starts_on']>$today)throw new Problem('Первый период должен охватывать всю кассовую книгу и текущую дату.',409);
            $accounts=accountingAccounts($db,$org);$pairs=accountingPairs($accounts,$f['pairs']);$expected=[];foreach(CURRENCIES as $c)$expected[$c]=accountingSigned($f[$c]);accountingExpected($accounts,$s,$pairs,$expected);
            $s['period_dates']=[$period['starts_on'],$period['ends_on']];
            $evidence=word($f['evidence'],500,'Основание сверки');$json=fn($v)=>json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $old=query($db,'SELECT id FROM accounting_openings WHERE organization_id=?',[$org])->fetchColumn();$args=[$period['id'],$actor,time(),$s['digest'],$s['count'],$json($s),$json($pairs),$json($expected),$evidence];
            if($old){$id=(int)$old;query($db,'UPDATE accounting_openings SET period_id=?,prepared_by=?,prepared_at=?,cash_digest=?,cash_count=?,snapshot=?,pairs=?,expected=?,evidence=?,version=version+1 WHERE id=?',[...$args,$id]);}
            else $id=insertId($db,'INSERT INTO accounting_openings(organization_id,period_id,prepared_by,prepared_at,cash_digest,cash_count,snapshot,pairs,expected,evidence) VALUES(?,?,?,?,?,?,?,?,?,?)',[$org,...$args]);
        }elseif($act==='accounting.approve'){
            $id=numberId($f['id']);$o=entity($db,'SELECT * FROM accounting_openings WHERE id=? AND organization_id=?',[$id,$org]);
            if($active||(int)$o['version']!==numberId($f['version'])||(int)$o['prepared_by']===$actor)throw new Problem('Предложение изменилось, принято или подготовлено вами.',409);
            $note=word($f['note'],500,'Подтверждение бухгалтера');$s=accountingCashSnapshot($db,$org);
            if(!hash_equals($o['cash_digest'],$s['digest'])||(int)$o['cash_count']!==$s['count'])throw new Problem('Кассовая книга изменилась. Подготовьте сверку заново.',409);
            $p=entity($db,'SELECT * FROM accounting_periods WHERE id=? AND organization_id=?',[$o['period_id'],$org]);$today=gmdate('Y-m-d');$saved=json_decode($o['snapshot'],true,512,JSON_THROW_ON_ERROR);if(($saved['period_dates']??null)!==[$p['starts_on'],$p['ends_on']]||$p['closed_at']!==null||$p['ends_on']<$today)throw new Problem('Первый период уже недействителен.',409);
            $pairs=json_decode($o['pairs'],true,512,JSON_THROW_ON_ERROR);$expected=json_decode($o['expected'],true,512,JSON_THROW_ON_ERROR);accountingExpected(accountingAccounts($db,$org),$s,$pairs,$expected);
            foreach($pairs as $pair)accountingInsert($db,$org,(int)$p['id'],null,$p['starts_on'],$pair['currency'],$pair['debit'],$pair['credit'],$pair['amount'],'opening','Начальный остаток · сверка #'.$id,$actor);
            // Source rows are ordered by ID; reversals always refer to earlier cash rows.
            $source=accountingCashRows($db,$org);while($r=$source->fetch())accountingCashEntry($db,(int)$r['id'],true);$source->closeCursor();
            if(!accountingReconcile($db,$org)['ok'])throw new Problem('Перенос не сошёлся. Все проводки отменены.',409);
            query($db,'UPDATE accounting_openings SET approved_by=?,approved_at=?,review_note=? WHERE id=?',[$actor,time(),$note,$id]);
        }else{
            if(!$active)throw new Problem('Сначала бухгалтер должен принять начальные остатки.',409);
            if($act==='accounting.close'){
                $id=numberId($f['id']);$p=entity($db,'SELECT * FROM accounting_periods WHERE id=? AND organization_id=?',[$id,$org]);
                if($p['closed_at']!==null||$p['ends_on']>=gmdate('Y-m-d')||query($db,'SELECT 1 FROM accounting_periods WHERE organization_id=? AND starts_on<? AND closed_at IS NULL',[$org,$p['starts_on']])->fetchColumn())throw new Problem('Закрывайте завершённые периоды последовательно.',409);
                if(query($db,"SELECT 1 FROM payment_requests r JOIN projects p ON p.id=r.project_id WHERE p.organization_id=? AND r.status='pending' AND r.booked_on>=? AND r.booked_on<=?",[$org,$p['starts_on'],$p['ends_on']])->fetchColumn())throw new Problem('Есть нерассмотренные платежи периода.',409);
                if(!accountingReconcile($db,$org)['ok'])throw new Problem('Кассовая книга не сходится с журналом.',409);
                foreach(accountingTrial($db,$org,$p)['totals'] as $t)if($t['opening']!==0||$t['closing']!==0||$t['debit']!==$t['credit'])throw new Problem('Оборотно-сальдовая ведомость не сходится.',409);
                query($db,'UPDATE accounting_periods SET closed_by=?,closed_at=? WHERE id=?',[$actor,time(),$id]);
            }else{
                $date=calendarDate($f['booked_on']);if($date>gmdate('Y-m-d'))throw new Problem('Проводка не может иметь будущую дату.');$period=accountingPeriod($db,$org,$date);$memo=word($f['memo'],500,'Основание');
                if($act==='accounting.post'){
                    $debit=numberId($f['debit']);$credit=numberId($f['credit']);$accounts=array_column(accountingAccounts($db,$org),null,'id');
                    if($debit===$credit||!isset($accounts[$debit],$accounts[$credit])||$accounts[$debit]['code']==='1000'||$accounts[$credit]['code']==='1000')throw new Problem('Выберите разные счета организации; денежный счёт изменяется только через кассу.');
                    $project=$f['project_id']===''?null:numberId($f['project_id']);if($project!==null)requireProjectRight($db,$u,$project,'finance');
                    $id=accountingInsert($db,$org,(int)$period['id'],$project,$date,choice($f['currency'],array_flip(CURRENCIES)),$debit,$credit,moneyMinor($f['amount']),'manual',$memo,$actor);
                }else{
                    $original=numberId($f['id']);$r=entity($db,'SELECT * FROM accounting_entries WHERE organization_id=? AND id=?',[$org,$original]);
                    if($r['kind']!=='manual'||$date<$r['booked_on']||query($db,'SELECT 1 FROM accounting_entries WHERE reversal_of=?',[$original])->fetchColumn())throw new Problem('Разрешено однократное сторно ручной проводки; кассовое сторно оформляется через кассу.',409);
                    $id=accountingInsert($db,$org,(int)$period['id'],$r['project_id']===null?null:(int)$r['project_id'],$date,$r['currency'],(int)$r['credit_id'],(int)$r['debit_id'],(int)$r['amount_minor'],'reversal',$memo,$actor,null,$original);
                }
            }
        }
        query($db,'INSERT INTO action_log(actor_id,request_key,fingerprint,action,object_id) VALUES(?,?,?,?,?)',[$actor,$key,$digest,$act,$id]);auditEvent($db,$u,null,$act,$id);return $id;
    });
}
// Opening balances plus debit/credit turnover and closing balances, grouped by account and currency.
function accountingTrial(PDO $db,int $org,array $period): array {
    $sql="SELECT e.currency,a.id,a.code,a.name, SUM(CASE WHEN e.booked_on<? OR e.kind='opening' THEN CASE WHEN e.debit_id=a.id THEN e.amount_minor ELSE -e.amount_minor END ELSE 0 END) opening, SUM(CASE WHEN e.booked_on>=? AND e.kind<>'opening' AND e.debit_id=a.id THEN e.amount_minor ELSE 0 END) debit, SUM(CASE WHEN e.booked_on>=? AND e.kind<>'opening' AND e.credit_id=a.id THEN e.amount_minor ELSE 0 END) credit FROM accounting_entries e JOIN accounting_accounts a ON a.organization_id=e.organization_id AND (a.id=e.debit_id OR a.id=e.credit_id) WHERE e.organization_id=? AND e.booked_on<=? GROUP BY e.currency,a.id,a.code,a.name ORDER BY e.currency,a.code";
    $rows=query($db,$sql,[$period['starts_on'],$period['starts_on'],$period['starts_on'],$org,$period['ends_on']])->fetchAll();$totals=[];
    foreach($rows as &$r){foreach(['opening','debit','credit'] as $k){$v=filter_var($r[$k],FILTER_VALIDATE_INT);if($v===false||$v===PHP_INT_MIN)throw new Problem('Превышен диапазон отчёта.');$r[$k]=$v;}$r['closing']=accountingAdd(accountingAdd($r['opening'],$r['debit']),-$r['credit']);$c=$r['currency'];$totals[$c]??=['opening'=>0,'debit'=>0,'credit'=>0,'closing'=>0];foreach(array_keys($totals[$c]) as $k)$totals[$c][$k]=accountingAdd($totals[$c][$k],$r[$k]);}unset($r);
    return ['rows'=>$rows,'totals'=>$totals];
}
function accountingPage(PDO $db,array $user,?int $periodId,?int $before): array {
    return atomic($db,function()use($db,$user,$periodId,$before){
        $u=accountingUser($db,$user);$org=$u['organization_id'];$accounts=accountingAccounts($db,$org);$periods=query($db,'SELECT * FROM accounting_periods WHERE organization_id=? ORDER BY starts_on DESC',[$org])->fetchAll();
        $period=$periodId===null?($periods[0]??null):entity($db,'SELECT * FROM accounting_periods WHERE organization_id=? AND id=?',[$org,$periodId]);
        $opening=query($db,'SELECT * FROM accounting_openings WHERE organization_id=?',[$org])->fetch()?:null;$active=$opening!==null&&$opening['approved_at']!==null;
        $snapshot=accountingCashSnapshot($db,$org);$reconcile=$active?accountingReconcile($db,$org):null;$trial=$period?accountingTrial($db,$org,$period):['rows'=>[],'totals'=>[]];
        $entries=[];if($period){$args=[$org,$period['id']];if($before!==null)$args[]=$before;$entries=query($db,'SELECT e.*,d.code debit_code,c.code credit_code,u.name actor_name,r.id reversed_by FROM accounting_entries e JOIN accounting_accounts d ON d.id=e.debit_id JOIN accounting_accounts c ON c.id=e.credit_id JOIN users u ON u.id=e.actor_id LEFT JOIN accounting_entries r ON r.reversal_of=e.id WHERE e.organization_id=? AND e.period_id=?'.($before!==null?' AND e.id<?':'').' ORDER BY e.id DESC LIMIT 51',$args)->fetchAll();}
        $projects=rightProjects($db,$u,'finance');$writable=true;try{accountingUser($db,$u,true);}catch(Problem){$writable=false;}return compact('writable','accounts','periods','period','opening','active','snapshot','reconcile','trial','projects')+['entries'=>array_slice($entries,0,50),'nextBefore'=>count($entries)>50?(int)$entries[49]['id']:null];
    });
}
