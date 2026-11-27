<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const PORTFOLIO_STATES=['proposed'=>'Решение о старте не зафиксировано','active'=>'В работе','stopped'=>'Остановлен'];
const PORTFOLIO_ACTIONS=['plan'=>'Сохранить цели и условия','start'=>'Запустить / возобновить','continue'=>'Продолжить','stop'=>'Остановить'];
function portfolioTransition(string $state,string $action): string {
    choice($state,PORTFOLIO_STATES);choice($action,PORTFOLIO_ACTIONS);
    return match($action){'plan'=>$state,'start'=>$state!=='active'?'active':throw new Problem('Проект уже запущен.',409),
        'continue'=>$state==='active'?'active':throw new Problem('Проект не запущен.',409),
        'stop'=>$state==='active'?'stopped':throw new Problem('Остановить можно только активный проект.',409)};
}
function portfolioOwner(PDO $db,array $user): array {
    $u=orgOwner($db,$user);
    if(staffHandle()!==null&&!staffSessionValid($db,$u))throw new Problem('Сессия отозвана.',403);
    if(!mfaFresh())throw new Problem('Подтвердите второй фактор в разделе «Безопасность».',403);
    return $u;
}
function portfolioPeriod(string $month,string $horizon,string $today): void {
    if(econMonth(substr($month,0,7))!==$month||$month>substr($today,0,7).'-01')throw new Problem('Выберите текущий или прошедший месяц.');
    if(calendarDate($horizon)!==$horizon||$horizon<$today||$horizon>(new \DateTimeImmutable($today))->modify('+366 days')->format('Y-m-d'))throw new Problem('Горизонт прогноза: от сегодня до 366 дней.');
}
// All values remain in original minor currency units. Invoice debt and manual expectations are separate.
function portfolioMetrics(array $report): array {
    $rows=[];
    foreach($report['totals'] as $c=>$t){
        $b=$report['budgets'][$c]??null;
        $row=array_intersect_key($t,array_flip(['revenue','labor','direct','margin','unknown','receivable','current','cash_in','cash_out','forecast_in','forecast_out']));
        $row['invoice_due']=0;$row['invoice_overdue']=0;$row['manual_in']=0;
        $row['budget']=$b?['id'=>(int)$b['id'],'version'=>(int)$b['version'],'revenue'=>(int)$b['revenue_minor'],'labor'=>(int)$b['labor_minor'],'direct'=>(int)$b['direct_minor']]:null;
        if($b){$bm=accountingAdd(accountingAdd((int)$b['revenue_minor'],-(int)$b['labor_minor']),-(int)$b['direct_minor']);$row['margin_delta']=accountingAdd($t['margin'],-$bm);}
        else $row['margin_delta']=null;
        $rows[$c]=$row;
    }
    foreach($report['forecast'] as $f){if($f['direction']!=='income')continue;$r=&$rows[$f['currency']];$k=$f['kind']==='invoice'?'invoice_due':'manual_in';$r[$k]=accountingAdd($r[$k],$f['remaining']);if($f['kind']==='invoice'&&$f['due_on']<$report['today'])$r['invoice_overdue']=accountingAdd($r['invoice_overdue'],$f['remaining']);unset($r);}
    return ['currencies'=>$rows,'mode'=>$report['settings']['mode'],'missing_minutes'=>$report['missingMinutes'],'missing_revenue_minutes'=>$report['missingRevenueMinutes'],'unknown_count'=>$report['unknownCount'],'fixed_count'=>$report['fixedCount']];
}
function portfolioSnapshot(array $report): array {
    return portfolioMetrics($report)+['as_of'=>$report['today'],'month'=>$report['month'],'horizon'=>$report['horizon'],'forecast'=>$report['forecast']];
}
function portfolioFingerprint(array $snapshot): string {return hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));}
function portfolioLatest(PDO $db,int $p): array {
    return query($db,'SELECT project_id,version,state,action,goal,start_conditions,stop_conditions,reason,actor_id,created_at,month_on,horizon FROM portfolio_decisions WHERE project_id=? ORDER BY version DESC LIMIT 1',[$p])->fetch()?:['version'=>0,'state'=>'proposed','goal'=>'','start_conditions'=>'','stop_conditions'=>'','reason'=>''];
}
function portfolioCommand(PDO $db,array $user,int $p,string $key,array $form): int {
    if(!preg_match('/\A[a-f0-9]{32}\z/',$key))throw new Problem('Обновите форму.');
    $action=choice($form['decision']??'',PORTFOLIO_ACTIONS);$version=econVersion($form['version']??'');
    $goal=word($form['goal']??'',500,'Цель');$start=word($form['start_conditions']??'',1000,'Условия старта');$stop=word($form['stop_conditions']??'',1000,'Условия остановки');$reason=word($form['reason']??'',1000,'Основание');
    $fingerprint=$form['snapshot']??'';if(!is_string($fingerprint)||!preg_match('/\A[a-f0-9]{64}\z/',$fingerprint))throw new Problem('Обновите финансовый снимок.');
    $month=econMonth($form['month']??'');$horizon=calendarDate($form['horizon']??'');
    if(($form['confirmed']??'')!=='yes')throw new Problem('Подтвердите проверку условий и финансовых данных.');
    return atomic($db,function()use($db,$user,$p,$key,$action,$version,$goal,$start,$stop,$reason,$month,$horizon,$fingerprint){
        $u=portfolioOwner($db,$user);requireProjectRight($db,$u,$p,'finance');$actor=(int)$u['id'];
        $digest=hash('sha256',json_encode([$u['organization_id'],$p,$action,$version,$goal,$start,$stop,$reason,$month,$horizon,$fingerprint],JSON_THROW_ON_ERROR));
        $old=query($db,'SELECT fingerprint,object_id FROM action_log WHERE actor_id=? AND request_key=?',[$actor,$key])->fetch();
        if($old){if(!hash_equals($old['fingerprint'],$digest))throw new Problem('Повтор с изменёнными данными.',409);return (int)$old['object_id'];}
        $current=portfolioLatest($db,$p);if((int)$current['version']!==$version)throw new Problem('Решение изменилось. Обновите страницу.',409);
        $state=portfolioTransition($current['state'],$action);
        $today=gmdate('Y-m-d');portfolioPeriod($month,$horizon,$today);
        $report=econReport($db,$p,$month,$horizon,$today);
        $snapshot=portfolioSnapshot($report);if(!hash_equals(portfolioFingerprint($snapshot),$fingerprint))throw new Problem('Финансовые данные изменились. Проверьте новый снимок.',409);
        query($db,'INSERT INTO portfolio_decisions(project_id,version,state,action,goal,start_conditions,stop_conditions,reason,actor_id,created_at,month_on,horizon,snapshot_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[$p,$version+1,$state,$action,$goal,$start,$stop,$reason,$actor,time(),$month,$horizon,json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
        query($db,'INSERT INTO action_log(actor_id,request_key,fingerprint,action,object_id) VALUES(?,?,?,?,?)',[$actor,$key,$digest,'portfolio.decision',$p]);
        auditEvent($db,$u,$p,'portfolio.decision',$p,$action.' #'.($version+1));return $p;
    });
}
function portfolioPage(PDO $db,array $user,?int $project,string $month,string $horizon,?int $before=null): array {
    $today=gmdate('Y-m-d');portfolioPeriod($month,$horizon,$today);
    return atomic($db,function()use($db,$user,$project,$month,$horizon,$today,$before){
        $u=portfolioOwner($db,$user);$options=rightProjects($db,$u,'finance');if($project!==null)requireProjectRight($db,$u,$project,'finance');
        $rows=[];$aggregate=[];$history=[];$budgetHistory=[];$selected=null;
        foreach($options as $id=>$title){$id=(int)$id;$report=econReport($db,$id,$month,$horizon,$today);$metrics=portfolioMetrics($report);$decision=portfolioLatest($db,$id);$rows[]=['id'=>$id,'title'=>$title,'decision'=>$decision,'metrics'=>$metrics];
            foreach($metrics['currencies'] as $c=>$t)foreach(['revenue','margin','receivable','current','cash_in','cash_out','invoice_due','invoice_overdue','manual_in'] as $field)$aggregate[$c][$field]=accountingAdd($aggregate[$c][$field]??0,$t[$field]);
            if($project===$id)$selected=['report'=>$report,'metrics'=>$metrics,'decision'=>$decision,'title'=>$title];
        }
        if($project!==null){
            $history=query($db,'SELECT d.*,u.name actor FROM portfolio_decisions d JOIN users u ON u.id=d.actor_id WHERE d.project_id=?'.($before!==null?' AND d.version<?':'').' ORDER BY d.version DESC LIMIT 21',$before===null?[$project]:[$project,$before])->fetchAll();
            $budgetHistory=query($db,'SELECT v.*,b.month_on,b.currency,u.name actor FROM portfolio_budget_versions v JOIN economics_budgets b ON b.id=v.budget_id JOIN users u ON u.id=v.actor_id WHERE b.project_id=? AND b.month_on=? ORDER BY b.currency,v.version DESC',[$project,$month])->fetchAll();
        }
        return compact('options','project','month','horizon','today','rows','aggregate','selected','history','budgetHistory');
    });
}
