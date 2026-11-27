<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const AI_DATA=['tasks'=>'Задачи','milestones'=>'Вехи','releases'=>'Выпуски','budget'=>'Бюджет и отклонения','stages'=>'Согласование этапов','discrepancies'=>'Расхождения финансов','revisions'=>'Редакции договоров'];
const AI_DEFAULT_DATA=['tasks','milestones','releases'];
const AI_SENSITIVE=['budget','stages','discrepancies','revisions'];
function aiModels(array $cfg): array {
    $models=[$cfg['model']];$raw=getenv('WORKSPACE_AI_MODELS');
    if(is_string($raw)&&$raw!=='')foreach(explode(',',$raw) as $m){if(!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}\z/',$m))throw new Problem('Некорректный список моделей AI.',503);$models[]=$m;}
    return array_values(array_unique($models));
}
function aiPolicy(PDO $db,int $org,array $cfg,bool $editing=false): array {
    $r=query($db,'SELECT * FROM ai_org_policies WHERE organization_id=?',[$org])->fetch();
    if(!$r)return ['data'=>AI_DEFAULT_DATA,'model'=>$cfg['model'],'quota'=>40,'days'=>0,'version'=>0];
    $data=json_decode($r['data_types'],true,16,JSON_THROW_ON_ERROR);
    if(!is_array($data)||!array_is_list($data)||array_diff($data,array_keys(AI_DATA)))throw new Problem('Политика AI требует обновления владельцем.',503);
    if(!in_array($r['model'],aiModels($cfg),true)&&!$editing)throw new Problem('Выбранная модель AI больше не разрешена оператором. Владелец должен изменить политику.',503);
    return ['data'=>$data,'model'=>$r['model'],'quota'=>(int)$r['daily_quota'],'days'=>(int)$r['retention_days'],'version'=>(int)$r['version']];
}
function aiPolicySave(PDO $db,array $u,array $form,array $cfg): void {
    if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);
    orgOwner($db,$u);
    $data=$form['data_types']??[];if(!is_array($data)||!array_is_list($data))throw new Problem('Некорректные типы данных.');
    foreach($data as $d)if(!is_string($d)||!isset(AI_DATA[$d]))throw new Problem('Некорректный тип данных.');
    $data=array_values(array_intersect(array_keys(AI_DATA),$data));$model=choice($form['model']??'',array_combine(aiModels($cfg),aiModels($cfg)));
    $quota=workloadInt($form['daily_quota']??null,0,1000);$days=workloadInt($form['retention_days']??null,0,30);$version=workloadInt($form['version']??null,0,2147483646);
    atomic($db,function()use($db,$u,$data,$model,$quota,$days,$version){$u=orgOwner($db,$u);$org=(int)$u['organization_id'];$old=query($db,'SELECT version FROM ai_org_policies WHERE organization_id=?',[$org])->fetchColumn();if((int)$old!==$version)throw new Problem('Политика изменена. Обновите страницу.',409);
        query($db,'INSERT INTO ai_org_policies(organization_id,data_types,model,daily_quota,retention_days,version) VALUES(?,?,?,?,?,?) ON CONFLICT(organization_id) DO UPDATE SET data_types=excluded.data_types,model=excluded.model,daily_quota=excluded.daily_quota,retention_days=excluded.retention_days,version=excluded.version',[$org,json_encode($data,JSON_THROW_ON_ERROR),$model,$quota,$days,$version+1]);
        // Previous answers must not survive a change of permitted data/model/retention.
        query($db,'DELETE FROM ai_history WHERE organization_id=?',[$org]);auditEvent($db,$u,null,'ai.policy',$org,'version '.($version+1));
    });
}
function aiRoleTopic(string $role,string $kind): string {return match($role){'owner'=>$kind==='client'?'stages':'budget','manager'=>'blockers','accountant','finance_reader'=>'discrepancies','legal','legal_reader'=>'revisions',default=>'overview'};}
function aiKindAllowed(PDO $db,array $u,int $p,string $kind): bool {
    $role=projectRole($db,$u,$p);
    return match($kind){'budget','discrepancies'=>roleAllows($role,'finance'),'revisions'=>roleAllows($role,'legal'),'stages'=>$u['role']==='owner','releases'=>roleAllows($role,'connections'),default=>true};
}
function aiReserve(PDO $db,int $org,array $policy): void {
    atomic($db,function()use($db,$org,$policy){$day=gmdate('Y-m-d');query($db,'DELETE FROM ai_daily_usage WHERE day_on<?',[gmdate('Y-m-d',time()-86400)]);
        $v=query($db,'SELECT version FROM ai_org_policies WHERE organization_id=?',[$org])->fetchColumn();if((int)$v!==$policy['version'])throw new Problem('Политика AI изменилась.',409);
        $used=(int)query($db,'SELECT requests FROM ai_daily_usage WHERE organization_id=? AND day_on=?',[$org,$day])->fetchColumn();if($used>=$policy['quota'])throw new Problem('Дневная квота AI организации исчерпана.',429);
        query($db,'INSERT INTO ai_daily_usage(organization_id,day_on,requests) VALUES(?,?,1) ON CONFLICT(organization_id,day_on) DO UPDATE SET requests=ai_daily_usage.requests+1',[$org,$day]);
    });
}
function aiPurge(PDO $db): int {return query($db,'DELETE FROM ai_history WHERE expires_at<=?',[time()])->rowCount();}
function aiFingerprint(array $context): string {return hash('sha256',json_encode($context,JSON_THROW_ON_ERROR));}
function aiRemember(PDO $db,array $u,array $context,array $answer,array $policy): void {
    if($policy['days']===0)return;
    atomic($db,function()use($db,$u,$context,$answer,$policy){aiPurge($db);$org=(int)$u['organization_id'];$v=query($db,'SELECT version FROM ai_org_policies WHERE organization_id=?',[$org])->fetchColumn();if((int)$v!==$policy['version'])throw new Problem('Политика AI изменилась.',409);
        query($db,'INSERT INTO ai_history(handle,organization_id,user_id,project_id,topic,term,context_hash,answer,refs,observed_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[bin2hex(random_bytes(24)),$org,$u['id'],$context['project_id'],$context['requested_topic'],$context['term'],aiFingerprint($context),$answer['answer'],json_encode(array_column($answer['links'],'ref'),JSON_THROW_ON_ERROR),$answer['observed'],$answer['observed']+$policy['days']*86400]);
    });
}
function aiHistory(PDO $db,array $u,int $project,array $cfg): array {
    aiProject($db,$u,$project,$cfg);$u=organizationUser($db,$u);$role=projectRole($db,$u,$project);$freshMfa=mfaFresh();aiPurge($db);$out=[];$contexts=[];
    foreach(query($db,'SELECT * FROM ai_history WHERE organization_id=? AND user_id=? AND project_id=? AND expires_at>? ORDER BY observed_at DESC,handle LIMIT 20',[$u['organization_id'],$u['id'],$project,time()])->fetchAll() as $r){
        try{$cache=$r['topic'].':'.$r['term'];$c=$contexts[$cache]??=aiContext($db,$u,$project,$r['topic'],$r['term'],$cfg);if((int)$r['expires_at']<=time()||!hash_equals($r['context_hash'],aiFingerprint($c)))continue;$map=array_column($c['sources'],null,'ref');$links=[];foreach(json_decode($r['refs'],true,16,JSON_THROW_ON_ERROR) as $ref){if(!isset($map[$ref]))continue 2;$links[]=$map[$ref];}$out[]=['answer'=>$r['answer'],'observed'=>(int)$r['observed_at'],'links'=>$links];}catch(Problem){continue;}
    }if(projectRole($db,$u,$project)!==$role||($freshMfa&&!mfaFresh()))return [];return $out;
}
function aiRoleSources(PDO $db,array $u,int $p,string $kind): array {
    if(!mfaFresh())throw new Problem('Для чувствительного контекста подтвердите MFA.',403);
    if(!aiKindAllowed($db,$u,$p,$kind))throw new Problem('Нет доступа к типу данных AI.',403);
    $out=[];$today=gmdate('Y-m-d');
    if($kind==='budget'){
        $month=substr($today,0,7).'-01';$r=econPage($db,$u,$p,$month,$today);
        foreach($r['budgets'] as $currency=>$b){$t=$r['totals'][$currency];$fields=['currency'=>$currency,'period_start'=>$month,'as_of'=>$today,'unknown_cost_minor'=>$t['unknown'],'unrated_minutes'=>$r['missingMinutes']];foreach(['revenue','labor','direct','margin'] as $f){$plan=$f==='margin'?(int)$b['revenue_minor']-(int)$b['labor_minor']-(int)$b['direct_minor']:(int)$b[$f.'_minor'];$fields[$f]=['planned_minor'=>$plan,'actual_minor'=>$t[$f],'variance_minor'=>accountingAdd($t[$f],-$plan)];}$out[]=aiRoleSource('B',(int)$b['id'],$kind,'Бюджет '.$currency,$fields,url('economics',['project'=>$p,'month'=>substr($month,0,7)]));}
    }elseif($kind==='stages'){
        foreach(query($db,"SELECT s.id,s.title,s.due_on,s.order_id,f.status offer_status,f.revision offer_revision,d.status delivery_status,d.revision delivery_revision FROM client_stages s JOIN client_orders o ON o.id=s.order_id JOIN client_offers f ON f.id=s.offer_id LEFT JOIN client_deliveries d ON d.stage_id=s.id AND d.revision=(SELECT MAX(x.revision) FROM client_deliveries x WHERE x.stage_id=s.id) WHERE s.project_id=? AND o.organization_id=? AND f.status IN ('draft','offered','accepted') AND (d.status IS NULL OR d.status<>'accepted') ORDER BY s.due_on,s.id LIMIT 21",[$p,$u['organization_id']])->fetchAll() as $r){$o=clientOrder($db,$u,(int)$r['order_id']);if((int)$o['project_id']!==$p)throw new Problem('Контекст изменился.',409);$out[]=aiRoleSource('S',(int)$r['id'],$kind,$r['title'],['due_on'=>$r['due_on'],'offer_status'=>$r['offer_status'],'offer_revision'=>(int)$r['offer_revision'],'delivery_status'=>$r['delivery_status'],'delivery_revision'=>$r['delivery_revision']===null?null:(int)$r['delivery_revision']],url('clients',['id'=>$r['order_id']]));}
    }elseif($kind==='discrepancies'){
        // Only project-linked bank rows; unallocated organization-wide bank rows are deliberately excluded.
        // Project invoice balances are available even if the accountant lacks organization-wide bank rights.
        foreach(query($db,"SELECT id FROM invoices WHERE project_id=? AND state='issued' AND due_on<? ORDER BY due_on,id LIMIT 21",[$p,$today])->fetchAll() as $row){$i=invoiceGet($db,$u,$p,(int)$row['id']);$b=invoiceBalance($i,invoiceMovements($db,(int)$i['id']));if($b['outstanding']<=0)continue;$out[]=aiRoleSource('I',(int)$i['id'],$kind,'Просроченный счёт #'.$i['id'],['currency'=>$i['currency'],'due_on'=>$i['due_on'],'outstanding_minor'=>$b['outstanding'],'pending_receipt_minor'=>$b['pending_receipt']],url('invoices',['project'=>$p,'id'=>$i['id']]));}
        try{accountingUser($db,$u);}catch(Problem){return $out;}
        $statements=query($db,'SELECT DISTINCT s.id FROM bank_statements s JOIN bank_allocations a ON a.statement_id=s.id WHERE a.project_id=? AND a.organization_id=? ORDER BY s.id DESC LIMIT 5',[$p,$u['organization_id']])->fetchAll();
        foreach($statements as $s){$statement=bankStatement($db,(int)$u['organization_id'],(int)$s['id']);$report=bankReport($db,$statement);$by=[];foreach(query($db,'SELECT row_id,project_id FROM bank_allocations WHERE statement_id=?',[$statement['id']])->fetchAll() as $a)$by[$a['row_id']][]=(int)$a['project_id'];foreach($report['details'] as $r){$allocs=$by[$r['id']]??[];if(!$allocs||array_diff(array_map('intval',$allocs),[$p]))continue;$variance=0;foreach($r['allocations'] as $a)$variance=accountingAdd($variance,(int)$a['variance_minor']);if($r['state']==='matched'&&$variance===0)continue;$out[]=aiRoleSource('D',(int)$r['id'],$kind,'Банковская операция #'.$r['id'],['currency'=>$statement['currency'],'booked_on'=>$r['booked_on'],'state'=>$r['state'],'net_minor'=>(int)$r['net_minor'],'fx_variance_minor'=>$variance],url('bank',['statement'=>$statement['id'],'row'=>$r['id'],'filter'=>'all']));if(count($out)>20)break 2;}}
    }elseif($kind==='revisions'){
        $rows=contractPage($db,$u,$p,null)['rows'];foreach($rows as $r){$latest=$r['latest'];if(!$latest||(int)$latest['revision']<2)continue;$prev=query($db,'SELECT * FROM contract_revisions WHERE contract_id=? AND revision<? ORDER BY revision DESC LIMIT 1',[$r['id'],$latest['revision']])->fetch();if(!$prev)continue;$changes=[];foreach(['currency','amount_minor','starts_on','ends_on','notice_on','signed_on','document_hash','renewal_terms','seller_signer','buyer_signer','seller_authority','buyer_authority','title','party_snapshot','document_revision','responsible_id'] as $f)if($latest[$f]!==$prev[$f])$changes[$f]=in_array($f,['currency','starts_on','ends_on','notice_on','signed_on'],true)?['before'=>$prev[$f],'after'=>$latest[$f]]:true;
            $attachments=fn($id)=>query($db,'SELECT document_id,document_revision,document_hash,label FROM contract_attachments WHERE revision_id=? ORDER BY document_id,document_revision',[$id])->fetchAll();if($attachments($prev['id'])!==$attachments($latest['id']))$changes['attachments']=true;
            $out[]=aiRoleSource('C',(int)$r['id'],$kind,'Договор #'.$r['id'],['previous_revision'=>(int)$prev['revision'],'latest_revision'=>(int)$latest['revision'],'activated'=>$latest['activated_at']!==null,'changes'=>$changes],url('contracts',['project'=>$p,'id'=>$r['id']]));if(count($out)>20)break;}
    }return $out;
}
function aiRoleSource(string $prefix,int $id,string $kind,string $title,array $fields,string $href): array {return ['ref'=>$prefix.$id,'kind'=>$kind,'title'=>aiText($title),'fields'=>$fields,'href'=>$href];}

function aiTopicsFor(PDO $db,array $u,int $p,array $policy): array {
    $topics=['role'=>AI_TOPICS['role'],'overview'=>AI_TOPICS['overview']];
    foreach(AI_DATA as $k=>$label)if(in_array($k,$policy['data'],true)&&aiKindAllowed($db,$u,$p,$k)){$topics[$k]=AI_TOPICS[$k];if($k==='tasks')$topics['blockers']=AI_TOPICS['blockers'];}
    return $topics;
}
function aiFactLines(array $fields): array {
    $currency=$fields['currency']??null;$lines=[];
    $labels=['revenue'=>'Выручка','labor'=>'Труд','direct'=>'Прямые расходы','margin'=>'Маржа','planned_minor'=>'План','actual_minor'=>'Факт','variance_minor'=>'Отклонение (факт − план)','outstanding_minor'=>'Остаток к оплате','pending_receipt_minor'=>'Ожидает подтверждения','net_minor'=>'Нетто операции','fx_variance_minor'=>'Курсовая разница','unknown_cost_minor'=>'Неклассифицированные расходы','period_start'=>'Начало периода','as_of'=>'На дату','currency'=>'Валюта','offer_status'=>'Согласование предложения','offer_revision'=>'Редакция предложения','delivery_status'=>'Состояние приёмки','delivery_revision'=>'Редакция результата','previous_revision'=>'Предыдущая редакция','latest_revision'=>'Последняя редакция','activated'=>'Активирована','changes'=>'Изменения','before'=>'До','after'=>'После','attachments'=>'Приложения изменились','party_snapshot'=>'Реквизиты сторон изменились','title'=>'Название изменилось','responsible_id'=>'Ответственный изменился','document_revision'=>'Редакция документа изменилась','document_hash'=>'Хеш документа изменился','renewal_terms'=>'Условия продления изменились','seller_signer'=>'Подписант продавца изменился','buyer_signer'=>'Подписант покупателя изменился','seller_authority'=>'Полномочия продавца изменились','buyer_authority'=>'Полномочия покупателя изменились','amount_minor'=>'Сумма','due_on'=>'Срок','booked_on'=>'Дата операции','state'=>'Состояние','unrated_minutes'=>'Минуты без тарифа'];
    $walk=function(array $data,string $prefix='')use(&$walk,&$lines,$currency,$labels){foreach($data as $k=>$v){$label=$prefix.($labels[$k]??$k);if(is_array($v)){$walk($v,$label.' / ');continue;}$text=$v===null?'—':(is_bool($v)?($v?'Да':'Нет'):(string)$v);if(is_int($v)&&str_ends_with((string)$k,'_minor')&&is_string($currency))$text=money($v).' '.$currency;$lines[]=[$label,$text];}};$walk($fields);return $lines;
}
