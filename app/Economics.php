<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const ECON_MODES=['none'=>'Без признания выручки','hourly'=>'Почасовая выручка','fixed'=>'Принятые фиксированные этапы'];
const ECON_CLASSES=['direct'=>'Прямой расход','labor'=>'Оплата труда (не дублировать себестоимость)','excluded'=>'Не относится к валовой марже'];
function econMonth(mixed $raw): string {
    if(!is_string($raw)||!preg_match('/\A\d{4}-\d{2}\z/',$raw))throw new Problem('Выберите месяц.');
    return calendarDate($raw.'-01');
}
function econAmount(mixed $raw): int {
    $n=accountingSigned($raw);if($n<0)throw new Problem('Сумма не может быть отрицательной.');return $n;
}
function econCurrency(mixed $raw): string {return choice($raw,array_flip(CURRENCIES));}
function econVersion(mixed $raw): int {return workloadInt($raw,0,2147483646);}
function econCash(PDO $db,int $p,int $id): array {
    return entity($db,"SELECT c.*,m.kind invoice_kind,m.invoice_id,rev.id reversal_id FROM cash_entries c LEFT JOIN payment_requests r ON r.cash_entry_id=c.id LEFT JOIN invoice_movements m ON m.request_id=r.id LEFT JOIN cash_entries rev ON rev.reversal_of=c.id WHERE c.project_id=? AND c.id=?",[$p,$id]);
}
function econPlan(PDO $db,int $p,int $id): array {return entity($db,'SELECT * FROM economics_plans WHERE project_id=? AND id=?',[$p,$id]);}
function econPlanLinks(PDO $db,int $p,int $id): array {
    return query($db,'SELECT c.id,c.booked_on,c.amount_minor,rev.id reversal_id,rev.booked_on reversal_date FROM economics_plan_cash l JOIN cash_entries c ON c.id=l.cash_id LEFT JOIN cash_entries rev ON rev.reversal_of=c.id WHERE l.project_id=? AND l.plan_id=? ORDER BY c.id',[$p,$id])->fetchAll();
}
function econPlanPaid(array $links,string $today): int {
    $paid=0;foreach($links as $c)if($c['booked_on']<=$today&&($c['reversal_id']===null||$c['reversal_date']>$today))$paid=accountingAdd($paid,(int)$c['amount_minor']);return $paid;
}
function econCommand(PDO $db,array $user,int $p,string $key,string $act,array $form): int {
    if(!preg_match('/\A[a-f0-9]{32}\z/',$key))throw new Problem('Обновите форму.');
    $fields=match($act){'econ.settings'=>['mode','version'],'econ.budget'=>['month','currency','revenue','labor','direct','version'],'econ.rate'=>['user_id','effective_on','currency','rate'],'econ.class'=>['cash_id','category','reason','version'],'econ.plan'=>['direction','currency','amount','due_on','title'],'econ.allocate'=>['id','cash_id','version'],'econ.cancel'=>['id','version'],'econ.reschedule'=>['id','version','due_on','amount','title'],'econ.unallocate'=>['id','version','cash_id','reason'],default=>throw new Problem('Неизвестная операция.')};
    $f=[];foreach($fields as $field){$v=$form[$field]??'';if(!is_string($v)&&!is_int($v))throw new Problem('Некорректная форма.');$f[$field]=(string)$v;}
    return atomic($db,function()use($db,$user,$p,$key,$act,$f){
        $u=orgOwner($db,$user);requireProjectRight($db,$u,$p,'finance');$actor=(int)$u['id'];$detail='';$now=time();$today=gmdate('Y-m-d');
        $digest=hash('sha256',json_encode([$act,$u['organization_id'],$p,$f],JSON_THROW_ON_ERROR));
        $old=query($db,'SELECT fingerprint,object_id FROM action_log WHERE actor_id=? AND request_key=?',[$actor,$key])->fetch();
        if($old){if(!hash_equals($old['fingerprint'],$digest))throw new Problem('Повтор с изменёнными данными.',409);return (int)$old['object_id'];}
        if($act==='econ.settings'){
            $mode=choice($f['mode'],ECON_MODES);$row=query($db,'SELECT version FROM economics_settings WHERE project_id=?',[$p])->fetch();$v=econVersion($f['version']);
            if((int)($row['version']??0)!==$v)throw new Problem('Настройки изменились.',409);
            query($db,'INSERT INTO economics_settings(project_id,mode,version,actor_id,changed_at) VALUES(?,?,?,?,?) ON CONFLICT(project_id) DO UPDATE SET mode=excluded.mode,version=excluded.version,actor_id=excluded.actor_id,changed_at=excluded.changed_at',[$p,$mode,$v+1,$actor,$now]);$id=$p;
        }elseif($act==='econ.budget'){
            $month=econMonth($f['month']);$c=econCurrency($f['currency']);$rev=econAmount($f['revenue']);$labor=econAmount($f['labor']);$direct=econAmount($f['direct']);$v=econVersion($f['version']);
            $row=query($db,'SELECT id,version FROM economics_budgets WHERE project_id=? AND month_on=? AND currency=?',[$p,$month,$c])->fetch();
            if((int)($row['version']??0)!==$v)throw new Problem('Бюджет изменился. Обновите страницу.',409);
            if($row){$id=(int)$row['id'];query($db,'UPDATE economics_budgets SET revenue_minor=?,labor_minor=?,direct_minor=?,version=?,actor_id=?,changed_at=? WHERE id=?',[$rev,$labor,$direct,$v+1,$actor,$now,$id]);}
            else $id=insertId($db,'INSERT INTO economics_budgets(project_id,month_on,currency,revenue_minor,labor_minor,direct_minor,version,actor_id,changed_at) VALUES(?,?,?,?,?,?,?,?,?)',[$p,$month,$c,$rev,$labor,$direct,1,$actor,$now]);
        }elseif($act==='econ.rate'){
            $uid=numberId($f['user_id']);$date=calendarDate($f['effective_on']);$c=econCurrency($f['currency']);$rate=econAmount($f['rate']);
            // Historical contributors may receive an initial rate after leaving a project, but never foreign users.
            if(!query($db,'SELECT 1 FROM timesheets WHERE project_id=? AND user_id=? LIMIT 1',[$p,$uid])->fetchColumn())workloadMember($db,$u,$p,$uid);
            $previous=query($db,'SELECT MAX(effective_on) FROM economics_labor_rates WHERE project_id=? AND user_id=?',[$p,$uid])->fetchColumn();
            if($previous!==null&&$previous!==false){$first=query($db,'SELECT MIN(effective_on) FROM economics_labor_rates WHERE project_id=? AND user_id=?',[$p,$uid])->fetchColumn();$worked=query($db,"SELECT MAX(e.worked_on) FROM time_entries e JOIN timesheets s ON s.id=e.sheet_id WHERE s.project_id=? AND s.user_id=? AND s.status IN ('submitted','approved')",[$p,$uid])->fetchColumn();if($date>=$first&&($date<=$previous||($worked&&$date<=$worked)))throw new Problem('Новая ставка должна начинаться после предыдущей ставки и уже отправленных работ.',409);}
            $id=insertId($db,'INSERT INTO economics_labor_rates(project_id,user_id,effective_on,currency,hourly_minor,actor_id,created_at) VALUES(?,?,?,?,?,?,?)',[$p,$uid,$date,$c,$rate,$actor,$now]);
        }elseif($act==='econ.class'){
            $id=numberId($f['cash_id']);$cash=econCash($db,$p,$id);$cat=choice($f['category'],ECON_CLASSES);$reason=word($f['reason'],500,'Основание');$v=econVersion($f['version']);
            if($cash['direction']!=='expense'||$cash['reversal_of']!==null||$cash['invoice_kind']==='refund')throw new Problem('Классифицируется только исходный расход, не возврат счёта.',409);
            $row=query($db,'SELECT version FROM economics_cash_classes WHERE project_id=? AND cash_id=?',[$p,$id])->fetch();if((int)($row['version']??0)!==$v)throw new Problem('Классификация изменена.',409);
            query($db,'INSERT INTO economics_cash_classes(cash_id,project_id,category,reason,version,actor_id,changed_at) VALUES(?,?,?,?,?,?,?) ON CONFLICT(cash_id) DO UPDATE SET category=excluded.category,reason=excluded.reason,version=excluded.version,actor_id=excluded.actor_id,changed_at=excluded.changed_at',[$id,$p,$cat,$reason,$v+1,$actor,$now]);
        }elseif($act==='econ.plan'){
            $id=insertId($db,'INSERT INTO economics_plans(project_id,direction,currency,amount_minor,due_on,title,actor_id,created_at) VALUES(?,?,?,?,?,?,?,?)',[$p,choice($f['direction'],DIRECTIONS),econCurrency($f['currency']),moneyMinor($f['amount']),calendarDate($f['due_on']),word($f['title'],180,'Назначение'),$actor,$now]);
        }else{
            $id=numberId($f['id']);$plan=econPlan($db,$p,$id);$links=econPlanLinks($db,$p,$id);$paid=econPlanPaid($links,$today);
            if((int)$plan['version']!==econVersion($f['version'])||(int)$plan['cancelled'])throw new Problem('План изменён или отменён.',409);
            if($act==='econ.cancel'){
                if($paid>0)throw new Problem('План с проведёнными платежами нельзя отменить.',409);
                query($db,'UPDATE economics_plans SET cancelled=1,version=version+1 WHERE id=?',[$id]);
            }elseif($act==='econ.reschedule'){
                $amount=moneyMinor($f['amount']);if($amount<$paid)throw new Problem('План меньше проведённой суммы.',409);
                query($db,'UPDATE economics_plans SET due_on=?,amount_minor=?,title=?,version=version+1 WHERE id=?',[calendarDate($f['due_on']),$amount,word($f['title'],180,'Назначение'),$id]);
            }elseif($act==='econ.unallocate'){
                $cid=numberId($f['cash_id']);entity($db,'SELECT cash_id FROM economics_plan_cash WHERE project_id=? AND plan_id=? AND cash_id=?',[$p,$id,$cid]);$reason=word($f['reason'],380,'Основание');
                query($db,'DELETE FROM economics_plan_cash WHERE plan_id=? AND cash_id=?',[$id,$cid]);query($db,'UPDATE economics_plans SET version=version+1 WHERE id=?',[$id]);$detail='cash #'.$cid.' · '.$reason;
            }else{
                $cid=numberId($f['cash_id']);$cash=econCash($db,$p,$cid);
                if($cash['invoice_id']!==null||$cash['reversal_of']!==null||$cash['reversal_id']!==null||$cash['booked_on']>$today||$cash['direction']!==$plan['direction']||$cash['currency']!==$plan['currency']||(int)$cash['amount_minor']>(int)$plan['amount_minor']-$paid)throw new Problem('Кассовая запись не подходит по сумме, валюте, типу или уже относится к счёту/сторно.',409);
                if(query($db,'SELECT 1 FROM economics_plan_cash WHERE cash_id=?',[$cid])->fetchColumn())throw new Problem('Движение уже связано с планом.',409);
                query($db,'INSERT INTO economics_plan_cash(plan_id,project_id,cash_id,actor_id,created_at) VALUES(?,?,?,?,?)',[$id,$p,$cid,$actor,$now]);$detail='cash #'.$cid;query($db,'UPDATE economics_plans SET version=version+1 WHERE id=?',[$id]);
            }
        }
        query($db,'INSERT INTO action_log(actor_id,request_key,fingerprint,action,object_id) VALUES(?,?,?,?,?)',[$actor,$key,$digest,$act,$id]);auditEvent($db,$u,$p,$act,$id,$detail);return $id;
    });
}
function econBlank(): array {return array_fill_keys(['revenue','labor','direct','unknown','cash_in','cash_out','opening','receivable','forecast_in','forecast_out','contract'],0);}
function econAdd(array &$totals,string $currency,string $field,int $amount): void {$totals[$currency][$field]=accountingAdd($totals[$currency][$field],$amount);}
function econReport(PDO $db,int $p,string $month,string $horizon,string $today): array {
    $end=(new \DateTimeImmutable($month))->format('Y-m-t');$startTs=(new \DateTimeImmutable($month))->getTimestamp();$endTs=(new \DateTimeImmutable($end))->modify('+1 day')->getTimestamp();
    $settings=query($db,'SELECT * FROM economics_settings WHERE project_id=?',[$p])->fetch()?:['mode'=>'none','version'=>0];$mode=$settings['mode'];
    $totals=array_fill_keys(CURRENCIES,econBlank());$budgets=array_column(query($db,'SELECT * FROM economics_budgets WHERE project_id=? AND month_on=?',[$p,$month])->fetchAll(),null,'currency');
    $rates=query($db,'SELECT r.*,u.name FROM economics_labor_rates r JOIN users u ON u.id=r.user_id WHERE r.project_id=? ORDER BY r.user_id,r.effective_on DESC',[$p])->fetchAll();$byUser=[];foreach($rates as $r)$byUser[$r['user_id']][]=$r;
    $hours=[];$minutes=0;$missingMinutes=0;$missingRevenueMinutes=0;
    // Reporting uses approval date; actual work dates select immutable internal labor tariffs.
    $sheets=query($db,"SELECT s.*,u.name FROM timesheets s JOIN users u ON u.id=s.user_id WHERE s.project_id=? AND s.status='approved' AND s.decided_at>=? AND s.decided_at<? AND s.decided_at<=? ORDER BY s.id",[$p,$startTs,$endTs,(new \DateTimeImmutable($today))->modify('+1 day')->getTimestamp()-1]);
    foreach($sheets->fetchAll() as $s){$mins=(int)$s['total_minutes'];$minutes=accountingAdd($minutes,$mins);$cost=[];$unrated=0;$groups=[];
        foreach(query($db,'SELECT * FROM time_entries WHERE project_id=? AND sheet_id=? ORDER BY worked_on,id',[$p,$s['id']])->fetchAll() as $e){$r=null;foreach($byUser[$s['user_id']]??[] as $candidate)if($candidate['effective_on']<=$e['worked_on']){$r=$candidate;break;}
            if(!$r){$unrated+=(int)$e['minutes'];continue;}$rid=(int)$r['id'];if(!isset($groups[$rid]))$groups[$rid]=['rate'=>$r,'minutes'=>0];$groups[$rid]['minutes']+=(int)$e['minutes'];}
        foreach($groups as $g){$r=$g['rate'];$v=timeCost($g['minutes'],(int)$r['hourly_minor']);$c=$r['currency'];$cost[$c]=accountingAdd($cost[$c]??0,$v);econAdd($totals,$c,'labor',$v);}
        $missingMinutes=accountingAdd($missingMinutes,$unrated);$revenue=null;
        if($mode==='hourly'){if($s['rate_mode']==='hourly'&&$s['cost_minor']!==null){$revenue=(int)$s['cost_minor'];econAdd($totals,$s['rate_currency'],'revenue',$revenue);}else $missingRevenueMinutes=accountingAdd($missingRevenueMinutes,$mins);}
        if(count($hours)<100)$hours[]=['sheet'=>$s,'cost'=>$cost,'groups'=>array_values($groups),'missing'=>$unrated,'revenue'=>$revenue];
    }
    $stages=[];$fixedCount=0;$contractRate=query($db,'SELECT * FROM project_rates WHERE project_id=?',[$p])->fetch()?:null;
    foreach(query($db,"SELECT s.id,s.title,s.amount_minor,o.currency,d.id delivery_id,d.decided_at FROM client_stages s JOIN client_offers o ON o.id=s.offer_id JOIN client_orders co ON co.id=s.order_id LEFT JOIN client_deliveries d ON d.stage_id=s.id AND d.status='accepted' WHERE s.project_id=? AND co.project_id=? AND o.status='accepted' ORDER BY s.id",[$p,$p])->fetchAll() as $s){$fixedCount++;econAdd($totals,$s['currency'],'contract',(int)$s['amount_minor']);if($mode==='fixed'&&$s['decided_at']!==null&&(int)$s['decided_at']>=$startTs&&(int)$s['decided_at']<$endTs&&gmdate('Y-m-d',(int)$s['decided_at'])<=$today)econAdd($totals,$s['currency'],'revenue',(int)$s['amount_minor']);if(count($stages)<100)$stages[]=$s;}
    $cashRows=[];$unknownCount=0;
    foreach(query($db,"SELECT c.*,cl.category,cl.reason,cl.version class_version,COALESCE(c.reversal_of,c.id) original_id,m.kind invoice_kind FROM cash_entries c LEFT JOIN economics_cash_classes cl ON cl.cash_id=COALESCE(c.reversal_of,c.id) LEFT JOIN payment_requests r ON r.cash_entry_id=COALESCE(c.reversal_of,c.id) LEFT JOIN invoice_movements m ON m.request_id=r.id WHERE c.project_id=? AND c.booked_on<=? ORDER BY c.booked_on,c.id",[$p,$today])->fetchAll() as $c){$amount=(int)$c['amount_minor'];$currency=$c['currency'];$income=$c['direction']==='income';$date=$c['booked_on'];$signed=$income?$amount:-$amount;$totals[$currency]['current']=accountingAdd($totals[$currency]['current']??0,$signed);
        if($date<$month){econAdd($totals,$currency,'opening',$signed);continue;}if($date>$end)continue;
        econAdd($totals,$currency,$income?'cash_in':'cash_out',$amount);
        if(!$income&&$c['invoice_kind']!=='refund'){if($c['category']==='direct')econAdd($totals,$currency,'direct',$amount);elseif($c['category']===null){econAdd($totals,$currency,'unknown',$amount);$unknownCount++;}}
        if(count($cashRows)<100)$cashRows[]=$c;
    }
    $invoices=[];$forecast=[];
    foreach(query($db,"SELECT * FROM invoices WHERE project_id=? AND state='issued' AND issued_on<=? ORDER BY due_on,id",[$p,$today])->fetchAll() as $i){$moves=array_values(array_filter(invoiceMovements($db,(int)$i['id']),fn($m)=>$m['booked_on']<=$today));$b=invoiceBalance($i,$moves);$out=$b['outstanding'];econAdd($totals,$i['currency'],'receivable',$out);
        if($out>0&&$i['due_on']<=$horizon){econAdd($totals,$i['currency'],'forecast_in',$out);$forecast[]=['kind'=>'invoice','id'=>(int)$i['id'],'title'=>invoiceNumber($i),'due_on'=>$i['due_on'],'direction'=>'income','currency'=>$i['currency'],'remaining'=>$out];}
        if(count($invoices)<100)$invoices[]=['invoice'=>$i,'balance'=>$b];
    }
    $plans=[];foreach(query($db,'SELECT * FROM economics_plans WHERE project_id=? ORDER BY due_on,id',[$p])->fetchAll() as $plan){$links=econPlanLinks($db,$p,(int)$plan['id']);$paid=econPlanPaid($links,$today);$left=(int)$plan['amount_minor']-$paid;
        if(!(int)$plan['cancelled']&&$left>0&&$plan['due_on']<=$horizon){econAdd($totals,$plan['currency'],$plan['direction']==='income'?'forecast_in':'forecast_out',$left);$forecast[]=['kind'=>'plan','id'=>(int)$plan['id'],'title'=>$plan['title'],'due_on'=>$plan['due_on'],'direction'=>$plan['direction'],'currency'=>$plan['currency'],'remaining'=>$left];}
        $plans[]=['plan'=>$plan,'paid'=>$paid,'remaining'=>$left,'links'=>$links];}
    usort($forecast,fn($a,$b)=>[$a['due_on'],$a['kind'],$a['id']]<=>[$b['due_on'],$b['kind'],$b['id']]);
    // Forecast starts from current cumulative cash, not from selected historical month.
    foreach($totals as &$t){$t['current']??=0;$t['margin']=accountingAdd(accountingAdd($t['revenue'],-$t['labor']),-$t['direct']);$t['net']=accountingAdd($t['cash_in'],-$t['cash_out']);$t['closing']=accountingAdd($t['opening'],$t['net']);$t['forecast_closing']=accountingAdd(accountingAdd($t['current'],$t['forecast_in']),-$t['forecast_out']);}unset($t);
    $running=array_column($totals,'current');$running=array_combine(CURRENCIES,$running);foreach($forecast as &$f){$c=$f['currency'];$running[$c]=accountingAdd($running[$c],($f['direction']==='income'?1:-1)*$f['remaining']);$f['projected']=$running[$c];}unset($f);
    return compact('settings','budgets','rates','totals','hours','minutes','missingMinutes','missingRevenueMinutes','stages','fixedCount','contractRate','cashRows','unknownCount','invoices','plans','forecast','month','end','horizon','today');
}
function econPage(PDO $db,array $user,?int $project,string $month,string $horizon): array {
    $today=gmdate('Y-m-d');if($horizon<$today||$horizon>(new \DateTimeImmutable($today))->modify('+366 days')->format('Y-m-d'))throw new Problem('Горизонт прогноза: от сегодня до 366 дней.');
    return atomic($db,function()use($db,$user,$project,$month,$horizon,$today){$u=organizationUser($db,$user);$options=rightProjects($db,$u,'finance');
        if($project===null)return ['options'=>$options,'project'=>null,'month'=>$month,'horizon'=>$horizon];requireProjectRight($db,$u,$project,'finance');
        $members=array_column(query($db,"SELECT DISTINCT u.id,u.name FROM users u WHERE u.id IN (SELECT user_id FROM project_members WHERE project_id=? UNION SELECT user_id FROM timesheets WHERE project_id=? UNION SELECT user_id FROM organization_members WHERE organization_id=? AND role='owner') ORDER BY u.name,u.id",[$project,$project,$u['organization_id']])->fetchAll(),'name','id');
        return ['options'=>$options,'project'=>$project,'writable'=>$u['role']==='owner','members'=>$members]+econReport($db,$project,$month,$horizon,$today);});
}
