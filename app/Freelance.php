<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

function freelanceOptionalMoney(mixed $v): int {
    if(in_array($v,['','0','0.0','0.00','0,0','0,00'],true))return 0;
    return moneyMinor($v);
}
// Calendar months anchored to the original day, including leap years and short months.
function freelancePeriod(string $first,int $offset): string {
    calendarDate($first); if($offset<0||$offset>1200)throw new Problem('Недопустимый период обслуживания.');
    $date=new \DateTimeImmutable($first);$month=$date->modify('first day of this month')->modify('+'.$offset.' months');
    return $month->setDate((int)$month->format('Y'),(int)$month->format('m'),min((int)$date->format('d'),(int)$month->format('t')))->format('Y-m-d');
}
function freelanceNext(PDO $db,array $a): ?string {
    if(!$a['first_service_on']||$a['stopped_at']!==null)return null;
    $n=(int)query($db,"SELECT COUNT(*) FROM freelance_bills WHERE offer_id=? AND slot NOT IN ('advance','balance')",[$a['offer_id']])->fetchColumn();
    return freelancePeriod($a['first_service_on'],$n);
}
function freelanceAgreement(PDO $db,int $order,int $offer): array {
    return entity($db,'SELECT a.*,p.title,p.scope,p.currency,p.price_minor,p.advance_minor,p.monthly_minor,p.service_scope,p.payment_days,f.status FROM freelance_agreements a JOIN freelance_packages p ON p.organization_id=a.organization_id AND p.number=a.package_number JOIN client_offers f ON f.id=a.offer_id WHERE a.order_id=? AND a.offer_id=?',[$order,$offer]);
}
function freelanceLocked(PDO $db,int $offer): bool {
    return (bool)query($db,'SELECT 1 FROM freelance_agreements WHERE offer_id=?',[$offer])->fetchColumn();
}
function freelanceAcceptedDelivery(PDO $db,int $offer): bool {
    return (bool)query($db,'SELECT 1 FROM client_stages WHERE offer_id=?',[$offer])->fetchColumn() && !query($db,"SELECT 1 FROM client_stages s WHERE s.offer_id=? AND NOT EXISTS (SELECT 1 FROM client_deliveries d WHERE d.stage_id=s.id AND d.status='accepted')",[$offer])->fetchColumn();
}
function freelanceDue(string $today,int $days): string {return (new \DateTimeImmutable($today))->modify('+'.$days.' days')->format('Y-m-d');}
function freelanceCommand(PDO $db,array $user,string $key,string $act,array $f): int {
    if(!preg_match('/\A[a-f0-9]{32}\z/',$key))throw new Problem('Обновите форму.');
    unset($f['csrf'],$f['request_key']);ksort($f);
    return atomic($db,function()use($db,$user,$key,$act,$f){
        $u=portfolioOwner($db,$user);$org=(int)$u['organization_id'];$actor=(int)$u['id'];$order=null;$project=null;$id=0;
        if($act!=='freelance.package'){$order=clientOrder($db,$u,numberId($f['order_id']??0));$id=(int)$order['id'];$project=(int)$order['project_id'];}
        $digest=hash('sha256',json_encode([$org,$act,$f],JSON_THROW_ON_ERROR));
        $old=query($db,'SELECT fingerprint,object_id FROM action_log WHERE actor_id=? AND request_key=?',[$actor,$key])->fetch();
        if($old){if(!hash_equals($old['fingerprint'],$digest))throw new Problem('Повторный запрос изменён.',409);return (int)$old['object_id'];}
        if($act==='freelance.package'){
            $title=word($f['title']??'',180,'Пакет');$scope=word($f['scope']??'',2000,'Объём');$currency=choice($f['currency']??'',array_flip(CURRENCIES));
            $price=moneyMinor($f['price']??'');$advance=freelanceOptionalMoney($f['advance']??'');$monthly=freelanceOptionalMoney($f['monthly']??'');$service=word($f['service_scope']??'',800,'Обслуживание',false);$days=workloadInt($f['payment_days']??'',1,90);
            if($advance>$price||(($monthly===0)!==($service==='')))throw new Problem('Проверьте аванс и условия обслуживания.');
            $id=1+(int)query($db,'SELECT COALESCE(MAX(number),0) FROM freelance_packages WHERE organization_id=?',[$org])->fetchColumn();
            query($db,'INSERT INTO freelance_packages VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$org,$id,$title,$scope,$currency,$price,$advance,$monthly,$service,$days,$actor,time()]);
        }elseif($act==='freelance.offer'){
            $p=entity($db,'SELECT * FROM freelance_packages WHERE organization_id=? AND number=?',[$org,numberId($f['package_number']??0)]);
            $due=calendarDate($f['due_on']??'');$first=calendarDate($f['first_service_on']??'',true);$today=gmdate('Y-m-d');
            if($due<$today||((int)$p['monthly_minor']>0)!==($first!==null)||($first!==null&&$first<$due))throw new Problem('Проверьте срок этапа и первый период обслуживания.');
            $scope=$p['scope']."\nЦена без налогов: ".money((int)$p['price_minor']).' '.$p['currency']."; аванс: ".money((int)$p['advance_minor'])."; остаток после приёмки. Оплата в течение ".$p['payment_days']." дней с выставления счёта.";
            if($first!==null)$scope.="\nЕжемесячное обслуживание с $first: ".$p['service_scope'].'; '.money((int)$p['monthly_minor']).' '.$p['currency'].' без налогов за период. Будущие периоды можно остановить.';
            if(query($db,"SELECT 1 FROM client_offers WHERE order_id=? AND status<>'rejected'",[$id])->fetchColumn())throw new Problem('Сначала завершите текущую редакцию.',409);
            $rev=1+(int)query($db,'SELECT MAX(revision) FROM client_offers WHERE order_id=?',[$id])->fetchColumn();
            $offer=insertId($db,'INSERT INTO client_offers(order_id,revision,scope,currency) VALUES(?,?,?,?)',[$id,$rev,$scope,$p['currency']]);
            query($db,'INSERT INTO client_stages(order_id,offer_id,project_id,title,scope,due_on,amount_minor) VALUES(?,?,?,?,?,?,?)',[$id,$offer,$project,$p['title'],$p['scope'],$due,$p['price_minor']]);
            query($db,'INSERT INTO freelance_agreements(offer_id,order_id,project_id,organization_id,package_number,first_service_on) VALUES(?,?,?,?,?,?)',[$offer,$id,$project,$org,$p['number'],$first]);
        }else{
            $a=freelanceAgreement($db,$id,numberId($f['offer_id']??0));$offer=(int)$a['offer_id'];
            if($act==='freelance.discard'){
                if($a['status']!=='draft'||($f['confirmed']??'')!=='yes')throw new Problem('Удалить можно только подтверждённый неопубликованный черновик.',409);
                query($db,'DELETE FROM freelance_agreements WHERE offer_id=?',[$offer]);query($db,'DELETE FROM client_stages WHERE offer_id=?',[$offer]);query($db,'DELETE FROM client_offers WHERE id=?',[$offer]);
            }elseif($a['status']!=='accepted')throw new Problem('Клиент ещё не принял условия.',409);
            elseif($act==='freelance.stop'){
                if(!$a['first_service_on']||$a['stopped_at']!==null)throw new Problem('Обслуживание уже остановлено или не согласовано.',409);
                query($db,'UPDATE freelance_agreements SET stopped_at=?,stopped_by=? WHERE offer_id=?',[time(),$actor,$offer]);
            }elseif($act==='freelance.bill'){
                $slot=word($f['slot']??'',10,'Период');$today=gmdate('Y-m-d');
                $amount=match($slot){'advance'=>(int)$a['advance_minor'],'balance'=>freelanceAcceptedDelivery($db,$offer)?(int)$a['price_minor']-(int)$a['advance_minor']:throw new Problem('Сначала приёмка этапа.',409),default=>($slot===freelanceNext($db,$a)&&$slot<=$today)?(int)$a['monthly_minor']:throw new Problem('Период обслуживания ещё не наступил или изменился.',409)};
                if($amount<=0||query($db,'SELECT 1 FROM freelance_bills WHERE offer_id=? AND slot=?',[$offer,$slot])->fetchColumn())throw new Problem('Счёт уже создан или сумма равна нулю.',409);
                $label=match($slot){'advance'=>'Аванс','balance'=>'Остаток после приёмки',default=>'Обслуживание с '.$slot};
                $entity=numberId($f['entity_id']??0);entity($db,'SELECT id FROM legal_entities WHERE id=? AND organization_id=?',[$entity,$org]);freelanceTaxGuard($db,$entity,$a);$due=freelanceDue(ukProfile($db,$entity)?ukToday():$today,(int)$a['payment_days']);
                $i=invoiceCreate($db,$u,$project,substr(hash('sha256','freelance.create:'.$key),0,32),['entity_id'=>$entity,'counterparty_id'=>$order['counterparty_id'],'currency'=>$a['currency'],'due_on'=>$due,'terms'=>$label.' · пакет #'.$a['package_number']]);
                invoiceChange($db,$u,$project,$i,substr(hash('sha256','freelance.line:'.$key),0,32),'invoice.line',['version'=>1,'description'=>$label.' — '.$a['title'],'quantity'=>1,'unit'=>money($amount,false)]);
                query($db,'INSERT INTO freelance_bills VALUES(?,?,?,?,?,?)',[$offer,$project,$slot,$i,$amount,time()]);
            }else throw new Problem('Неизвестная операция.');
        }
        query($db,'INSERT INTO action_log(actor_id,request_key,fingerprint,action,object_id) VALUES(?,?,?,?,?)',[$actor,$key,$digest,$act,$id]);auditEvent($db,$u,$project,$act,$id);return $id;
    });
}
function freelanceTaxGuard(PDO $db,int $entity,array $a): void {
    if(ukProfile($db,$entity)&&((int)$a['advance_minor']>0||(int)$a['monthly_minor']>0))throw new Problem('Профиль UK VAT не поддерживает авансы и регулярное обслуживание пакетов. Выберите допустимый финансовый процесс с бухгалтером.',409);
}
function freelanceInvoiceGuard(PDO $db,array $i): void {
    $r=query($db,'SELECT b.amount_minor,p.currency,p.payment_days,p.advance_minor,p.monthly_minor,o.counterparty_id,f.status FROM freelance_bills b JOIN freelance_agreements a ON a.offer_id=b.offer_id JOIN freelance_packages p ON p.organization_id=a.organization_id AND p.number=a.package_number JOIN client_orders o ON o.id=a.order_id JOIN client_offers f ON f.id=a.offer_id WHERE b.invoice_id=? AND b.project_id=?',[$i['id'],$i['project_id']])->fetch();
    if(!$r)return;
    freelanceTaxGuard($db,(int)$i['entity_id'],$r);
    $today=ukProfile($db,(int)$i['entity_id'])?ukToday():gmdate('Y-m-d');
    if($r['status']!=='accepted'||(int)$i['total_minor']!==(int)$r['amount_minor']||$i['currency']!==$r['currency']||(int)$i['counterparty_id']!==(int)$r['counterparty_id']||$i['due_on']!==freelanceDue($today,(int)$r['payment_days']))throw new Problem('Счёт должен соответствовать принятому пакету: сумма без налогов, валюта, контрагент и срок от даты выставления.',409);
}
function freelanceBillStatus(array $i,array $balance,bool $credited,string $today): array {
    $out=$i['state']==='issued'&&!$credited?max(0,(int)$balance['outstanding']):0;
    return ['outstanding'=>$out,'overdue'=>$out>0&&$i['due_on']<$today,'credited'=>$credited,'received'=>(int)$balance['received'],'refunded'=>(int)$balance['refunded']];
}
function freelanceBills(PDO $db,array $order,bool $guest=false): array {
    // Only invoices explicitly linked to this order, never all invoices of the project or counterparty.
    $rows=query($db,"SELECT i.*,b.slot FROM freelance_bills b JOIN freelance_agreements a ON a.offer_id=b.offer_id JOIN invoices i ON i.id=b.invoice_id AND i.project_id=b.project_id WHERE a.order_id=? AND a.project_id=? UNION ALL SELECT i.*,'change' AS slot FROM pm_changes c JOIN invoices i ON i.id=c.invoice_id AND i.project_id=c.project_id WHERE c.order_id=? AND c.project_id=? ORDER BY id DESC",[$order['id'],$order['project_id'],$order['id'],$order['project_id']])->fetchAll();
    $result=[];foreach($rows as $i){if($guest&&$i['state']==='draft')continue;if((int)$i['counterparty_id']!==(int)$order['counterparty_id'])continue;$d=ukDocument($db,(int)$i['id']);$status=freelanceBillStatus($i,invoiceBalance($i,invoiceMovements($db,(int)$i['id'])),$d&&$d['credit_on']!==null,gmdate('Y-m-d'));
        $result[]=array_intersect_key($i,array_flip(['id','project_id','slot','currency','due_on','state','total_minor']))+['number'=>$i['state']==='draft'?'':invoiceNumber($i)]+$status;
    }return $result;
}
function freelancePageData(PDO $db,array $u,?array $order): array {
    $u=portfolioOwner($db,$u);$org=$u['organization_id'];$packages=query($db,'SELECT * FROM freelance_packages WHERE organization_id=? ORDER BY number DESC',[$org])->fetchAll();$agreements=[];$bills=[];$entities=[];
    if($order){foreach(query($db,'SELECT offer_id FROM freelance_agreements WHERE order_id=? AND organization_id=? ORDER BY offer_id DESC',[$order['id'],$org])->fetchAll() as $row){$a=freelanceAgreement($db,(int)$order['id'],(int)$row['offer_id']);$a['next']=freelanceNext($db,$a);$a['slots']=array_column(query($db,'SELECT slot FROM freelance_bills WHERE offer_id=?',[$a['offer_id']])->fetchAll(),'slot');$a['delivered']=freelanceAcceptedDelivery($db,(int)$a['offer_id']);$agreements[]=$a;}$bills=freelanceBills($db,$order);$entities=query($db,'SELECT id,name FROM legal_entities WHERE organization_id=? ORDER BY name,id',[$org])->fetchAll();}
    return compact('packages','agreements','bills','entities');
}

function freelanceGuestServices(PDO $db,array $o): array {
    return query($db,"SELECT p.title,p.currency,p.monthly_minor,p.service_scope,a.first_service_on,a.stopped_at FROM freelance_agreements a JOIN freelance_packages p ON p.organization_id=a.organization_id AND p.number=a.package_number JOIN client_offers f ON f.id=a.offer_id WHERE a.order_id=? AND a.project_id=? AND f.status='accepted' AND p.monthly_minor>0",[$o['id'],$o['project_id']])->fetchAll();
}
// A live owner inbox, independent of the order list's pagination. No delivery to third parties.
function freelanceReminders(PDO $db,array $u,?int $project=null): array {
    $u=portfolioOwner($db,$u);if($project!==null)allowedProject($db,$u,$project);
    $scope=$project===null?'':' AND o.project_id=?';$args=[$u['organization_id'],gmdate('Y-m-d')];if($project!==null)$args[]=$project;
    return query($db,"SELECT o.id order_id,o.title,i.id invoice_id,i.project_id,i.currency,i.due_on,i.total_minor-COALESCE(paid.total,0) outstanding
        FROM (SELECT a.order_id,b.project_id,b.invoice_id FROM freelance_bills b JOIN freelance_agreements a ON a.offer_id=b.offer_id UNION SELECT order_id,project_id,invoice_id FROM pm_changes WHERE invoice_id IS NOT NULL AND order_id IS NOT NULL) l
        JOIN client_orders o ON o.id=l.order_id AND o.project_id=l.project_id JOIN invoices i ON i.id=l.invoice_id AND i.project_id=l.project_id AND i.counterparty_id=o.counterparty_id
        LEFT JOIN (SELECT m.invoice_id,SUM(CASE WHEN m.kind='receipt' THEN r.amount_minor ELSE -r.amount_minor END) total FROM invoice_movements m JOIN payment_requests r ON r.id=m.request_id WHERE r.status='posted' GROUP BY m.invoice_id) paid ON paid.invoice_id=i.id
        WHERE o.organization_id=? AND i.due_on<? AND i.state='issued' AND i.total_minor>COALESCE(paid.total,0)$scope ORDER BY i.due_on,i.id LIMIT 51",$args)->fetchAll();
}
