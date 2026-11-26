<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

// Static SQL registry. Never derive table names, columns, predicates or rights from request data.
function discoverySources(): array {
    $current='x.revision=(SELECT MAX(r.revision) FROM contract_revisions r WHERE r.contract_id=x.contract_id AND r.activated_at IS NOT NULL) AND x.activated_at IS NOT NULL';
    $paid="(SELECT COALESCE(SUM(CASE WHEN m.kind='receipt' THEN r.amount_minor WHEN m.kind='refund' THEN -r.amount_minor ELSE 0 END),0) FROM invoice_movements m JOIN payment_requests r ON r.id=m.request_id WHERE m.invoice_id=x.id AND r.status='posted')";
    return [
      'projects'=>['Проекты',null,false,'projects p','p.id','p.title',['p.title','p.client'],'1=1','project'],
      'tasks'=>['Задачи',null,false,'tasks x JOIN projects p ON p.id=x.project_id','x.id','x.title',['x.title','x.result_customer','x.acceptance_criteria'],'1=1','task','x.due_on',"x.state<>'done'",'x.assignee_id'],
      'milestones'=>['Вехи',null,false,'milestones x JOIN projects p ON p.id=x.project_id','x.id','x.title',['x.title'],'1=1','milestone','x.due_on',"x.state='open'"],
      'sprints'=>['Спринты',null,false,'sprints x JOIN projects p ON p.id=x.project_id','x.id','x.title',['x.title'],'1=1','sprint','x.ends_on',"x.state<>'closed'"],
      'documents'=>['Документы','legal',false,'documents x JOIN projects p ON p.id=x.project_id','x.id','x.title',['x.title'],'1=1','document'],
      'invoices'=>['Счета','finance',false,'invoices x JOIN projects p ON p.id=x.project_id','x.id',"'Счёт #' || CAST(x.id AS TEXT) || COALESCE(' · ' || CAST(x.number_year AS TEXT) || '/' || CAST(x.number_seq AS TEXT),'')",['CAST(x.id AS TEXT)','COALESCE(CAST(x.number_year AS TEXT),\'\')','COALESCE(CAST(x.number_seq AS TEXT),\'\')'],'1=1','invoices','x.due_on',"x.state='issued' AND x.total_minor>$paid"],
      'contracts'=>['Договоры','legal',true,'contracts x JOIN projects p ON p.id=x.project_id','x.id',"x.reference || COALESCE(' · ' || (SELECT r.title FROM contract_revisions r WHERE r.contract_id=x.id ORDER BY r.revision DESC LIMIT 1),'')",['x.reference',"COALESCE((SELECT r.title FROM contract_revisions r WHERE r.contract_id=x.id ORDER BY r.revision DESC LIMIT 1),'')"],'1=1','contracts'],
      'contract_notice'=>['Договоры: уведомление','legal',true,'contract_revisions x JOIN contracts c ON c.id=x.contract_id JOIN projects p ON p.id=x.project_id','x.contract_id','c.reference || \' · \' || x.title',[],$current,'contracts','x.notice_on','1=1','x.responsible_id'],
      'contract_end'=>['Договоры: окончание','legal',true,'contract_revisions x JOIN contracts c ON c.id=x.contract_id JOIN projects p ON p.id=x.project_id','x.contract_id','c.reference || \' · \' || x.title',[],$current,'contracts','x.ends_on','1=1','x.responsible_id'],
      'obligations'=>['Обязательства','legal',true,'contract_obligations x JOIN projects p ON p.id=x.project_id','x.id','x.title',['x.title'],'1=1','obligation','x.due_on',"x.state='open'",null,'x.contract_id'],
      'book'=>['Подборки Book','connections',true,'book_editions x JOIN projects p ON p.id=x.project_id JOIN book_sources bs ON bs.id=x.source_id AND bs.active=1','x.id','x.title',['x.title'],'1=1','book'],
      'preparation'=>['Согласование документов','legal',true,'preparations x JOIN projects p ON p.id=x.project_id','x.id','x.title',['x.title'],'1=1','preparation'],
      'orders'=>['Заказы','owner',true,'client_orders x JOIN projects p ON p.id=x.project_id','x.id','x.title',['x.title'],'1=1','clients'],
      'releases'=>['Выпуски','connections',false,'project_releases x JOIN projects p ON p.id=x.project_id','x.id','x.tag',['x.tag','x.commit_sha'],'1=1','releases'],
      'environments'=>['Окружения','connections',false,'infra_environments x JOIN projects p ON p.id=x.project_id','x.id','x.name',['x.name','x.kind'],'1=1','infrastructure'],
      'incidents'=>['Инциденты: реакция','connections',true,'incidents x JOIN projects p ON p.id=x.project_id','x.id','x.condition_code',['x.condition_code','CAST(x.id AS TEXT)'],'1=1','incidents','x.response_due',"x.state='open'",'x.responsible_id',null,true],
      'engine'=>['Сайты Engine','connections',true,'engine_sites x JOIN projects p ON p.id=x.project_id','x.id','x.name',['x.name'],'1=1','engine'],
      'lite'=>['Сайты Lite','connections',true,'lite_sites x JOIN projects p ON p.id=x.project_id','x.id','x.name',['x.name'],'1=1','lite'],
      'editorial'=>['Редакторские выпуски','manage',true,'editorial_releases x JOIN projects p ON p.id=x.project_id JOIN project_releases r ON r.id=x.release_id','x.id','r.tag',['r.tag'],'1=1','editorial'],
    ];
}
function discoveryScope(PDO $db,array $u,array $s,?int $project,bool $fresh): ?array {
    if($s[2]&&!$fresh)return null;
    if($s[1]==='owner'&&$u['role']!=='owner')return null;
    [$where,$args]=$s[1]===null||$s[1]==='owner'?projectScope($db,$u):projectRightScope($db,$u,$s[1]);
    if($project!==null){$where.=' AND p.id=?';$args[]=$project;}
    return [$where,$args];
}
function discoveryAllowed(PDO $db,array $u,int $project,array $s,bool $fresh): void {
    allowedProject($db,$u,$project);
    if($s[2]&&!$fresh)throw new Problem('Подтвердите второй фактор.',403);
    if($s[1]==='owner')orgOwner($db,$u);elseif($s[1]!==null)requireProjectRight($db,$u,$project,$s[1]);
}
function discoveryHref(array $r,string $kind): string {
    $s=discoverySources()[$kind];$id=(int)$r['id'];$p=(int)$r['project_id'];
    return match($s[8]){
      'project','task','document','clients'=>url($s[8],['id'=>$id]),
      'milestone'=>url('project',['id'=>$p,'milestone'=>$id]).'#milestone-'.$id,
      'sprint'=>url('workload',['project'=>$p,'sprint'=>$id]),
      'obligation'=>url('contracts',['project'=>$p,'id'=>(int)$r['parent_id']]),
      'book'=>url('book',['project'=>$p,'edition'=>$id]),
      'engine','lite'=>url($s[8],['project'=>$p,'site'=>$id]),
      'infrastructure'=>url('infrastructure',['project'=>$p]),
      default=>url($s[8],['project'=>$p,'id'=>$id])};
}
function discoverySelect(array $s): string {
    return "$s[4] AS id,p.id AS project_id,p.title AS project_title,$s[5] AS title,".($s[12]??$s[4]).' AS parent_id';
}
function discoveryProjects(PDO $db,array $u): array {
    [$scope,$args]=projectScope($db,$u);return array_column(query($db,"SELECT p.id,p.title FROM projects p WHERE $scope ORDER BY p.title,p.id",$args)->fetchAll(),'title','id');
}
function discoverySearch(PDO $db,array $user,string $term,?int $project,string $kind,int $page,bool $fresh,?callable $forumTransport=null): array {
    $u=organizationUser($db,$user);if($project!==null)allowedProject($db,$u,$project);
    $sources=discoverySources();$labels=[''=>'Все объекты'];foreach($sources as $k=>$s)if($s[6])$labels[$k]=$s[0];$labels['forum']='Обсуждения Forum (выбранный проект)';
    choice($kind,$labels);if($page<1||$page>100||($kind===''&&$page!==1))throw new Problem('Выберите тип для продолжения поиска.');
    $term=word($term,120,'Поиск',false);$matches=$errors=$more=[];$restricted=false;$limit=$kind===''?20:50;$offset=($page-1)*$limit;
    if($term!=='')foreach($sources as $k=>$s){if(!$s[6]||($kind!==''&&$kind!==$k))continue;
        if($s[2]&&!$fresh){$restricted=true;continue;}
        try{$sc=discoveryScope($db,$u,$s,$project,$fresh);if($sc===null)continue;[$where,$args]=$sc;
            if($k==='invoices'){
                $number=isPostgres($db)?"CAST(x.entity_id AS TEXT)||'-'||LPAD(CAST(x.number_year AS TEXT),4,'0')||'-'||LPAD(CAST(x.number_seq AS TEXT),GREATEST(6,LENGTH(CAST(x.number_seq AS TEXT))),'0')":"printf('%d-%04d-%06d',x.entity_id,x.number_year,x.number_seq)";
                $s[5]="CASE WHEN x.number_seq IS NULL THEN 'Черновик #' || CAST(x.id AS TEXT) ELSE $number END";$s[6][]=$s[5];
            }
            if($k==='book'){
                // Authorize the source before pagination so revoked books cannot affect counts.
                $bindings=query($db,"SELECT bs.* FROM book_sources bs JOIN projects p ON p.id=bs.project_id WHERE ($where) AND bs.active=1 ORDER BY bs.id LIMIT 201",$args)->fetchAll();$allowed=[];
                if(count($bindings)>200)$errors[$k]='Проверены первые 200 привязок; уточните проект.';
                foreach(array_slice($bindings,0,200) as $binding){try{bookSourceConfig($db,$u,$binding);$allowed[]=(int)$binding['id'];}catch(Problem){/* denied or disabled */}catch(\RuntimeException|\JsonException){$errors[$k]='Один или несколько источников недоступны.';}}
                if(!$allowed){$matches[$k]=[];$more[$k]=false;continue;}
                $where.=' AND x.source_id IN ('.implode(',',array_fill(0,count($allowed),'?')).')';$args=[...$args,...$allowed];
            }
            $predicate=planningSearchSql($db,$s[6]);$sql='SELECT '.discoverySelect($s)." FROM $s[3] WHERE ($where) AND ($s[7]) AND $predicate ORDER BY $s[4] DESC LIMIT ".($limit+1)." OFFSET $offset";
            $rows=query($db,$sql,[...$args,...array_fill(0,count($s[6]),planningLike($term))])->fetchAll();$more[$k]=count($rows)>$limit;$rows=array_slice($rows,0,$limit);
            if($k==='book'){$valid=[];foreach($rows as $r){try{bookEdition($db,$u,(int)$r['project_id'],(int)$r['id']);$valid[]=$r;}catch(Problem){/* revoked or invalid source: do not expose the title */}}$rows=$valid;}
            $matches[$k]=$rows;
        }catch(\RuntimeException|\JsonException $e){$errors[$k]='Источник временно недоступен.';}}
    // Only linked topics in a selected project. The existing adapter checks individual Forum ACLs live.
    if($term!==''&&($kind===''||$kind==='forum')&&$project!==null&&$fresh){
        try{requireProjectRight($db,$u,$project,'connections');$bindings=query($db,'SELECT id FROM forum_sources WHERE project_id=? AND organization_id=? AND active=1 ORDER BY id LIMIT 4',[$project,$u['organization_id']])->fetchAll();$matches['forum']=[];
            if(count($bindings)>3)$errors['forum']='Проверены первые три привязки; остальные доступны в разделе Forum.';
            $started=hrtime(true);$transport=static function($c,$method,$path,$body)use($started,$forumTransport){if(hrtime(true)-$started>8_000_000_000)throw new Problem('Лимит времени поиска Forum.',503);return ($forumTransport??__NAMESPACE__.'\\forumHttp')($c,$method,$path,$body);};
            foreach(array_slice($bindings,0,3) as $binding){try{$r=forumPage($db,$u,$project,['source'=>$binding['id'],'q'=>word($term,100,'Поиск Forum'),'n'=>$page],$transport);
                if($r['error'])throw new Problem('Forum unavailable');foreach($r['rows'] as $t)$matches['forum'][]=['id'=>$t['local']['id'],'project_id'=>$project,'title'=>$t['title'],'source_id'=>$binding['id']];
            }catch(\RuntimeException|\JsonException|\TypeError|\ValueError $e){$errors['forum']='Один или несколько разделов Forum недоступны или поиск неполный.';}}
        }catch(Problem $e){/* No project-level connection permission. */}catch(\PDOException $e){$errors['forum']='Источник временно недоступен.';}
    }
    return ['term'=>$term,'project'=>$project,'kind'=>$kind,'pageNo'=>$page,'projects'=>discoveryProjects($db,$u),'labels'=>$labels,'matches'=>$matches,'errors'=>$errors,'more'=>$more,'restricted'=>$restricted];
}
function reminderSources(): array {return array_filter(discoverySources(),fn($s)=>isset($s[9]));}
function reminderSettings(PDO $db,array $u,?int $project,bool $fresh): array {
    $u=organizationUser($db,$u);[$scope,$args]=projectScope($db,$u);$rows=query($db,"SELECT s.*,p.title project_title FROM reminder_subscriptions s JOIN projects p ON p.id=s.project_id WHERE $scope AND s.user_id=? ORDER BY p.id,s.kind",[...$args,$u['id']])->fetchAll();$visible=[];
    foreach($rows as $r){try{discoveryAllowed($db,$u,(int)$r['project_id'],reminderSources()[$r['kind']],$fresh);$visible[]=$r;}catch(Problem){}}
    $options=[];if($project!==null)foreach(reminderSources() as $k=>$s){try{discoveryAllowed($db,$u,$project,$s,$fresh);$options[$k]=$s[0];}catch(Problem){}}
    return ['subscriptions'=>$visible,'options'=>$options];
}
function reminderSubscribe(PDO $db,array $user,int $project,string $kind,int $lead,bool $assigned,bool $enable,bool $fresh): void {
    $s=reminderSources()[$kind]??throw new Problem('Неизвестный тип напоминаний.');
    if($lead<0||$lead>90||($assigned&&!isset($s[11])))throw new Problem('Некорректные параметры подписки.');
    atomic($db,function()use($db,$user,$project,$kind,$lead,$assigned,$enable,$fresh,$s){$u=organizationUser($db,$user);discoveryAllowed($db,$u,$project,$s,$fresh);
        if(!$enable){query($db,'DELETE FROM reminder_subscriptions WHERE organization_id=? AND project_id=? AND user_id=? AND kind=?',[$u['organization_id'],$project,$u['id'],$kind]);return;}
        query($db,'INSERT INTO reminder_subscriptions(organization_id,project_id,user_id,kind,lead_days,assigned_only,updated_at) VALUES(?,?,?,?,?,?,?) ON CONFLICT(organization_id,project_id,user_id,kind) DO UPDATE SET lead_days=excluded.lead_days,assigned_only=excluded.assigned_only,updated_at=excluded.updated_at',[$u['organization_id'],$project,$u['id'],$kind,$lead,$assigned?1:0,time()]);
    });
}
// Date-only deadlines use UTC calendar days; incidents preserve seconds.
function reminderQuery(PDO $db,array $u,string $kind,?int $project,bool $fresh,int $now,bool $personal,int $horizon=7): ?array {
    $s=reminderSources()[$kind];$sc=discoveryScope($db,$u,$s,$project,$fresh);if($sc===null)return null;[$scope,$args]=$sc;
    $due=$s[9];$epoch=$s[13]??false;$today=gmdate('Y-m-d',$now);$from=$s[3];$extra='';$cutoff=$epoch?$now: $today;
    if($personal){$from.=' JOIN reminder_subscriptions sub ON sub.project_id=p.id AND sub.organization_id=p.organization_id';
        $scope.=' AND sub.user_id=? AND sub.kind=?';$args[]=$u['id'];$args[]=$kind;
        if(isset($s[11])){$scope.=" AND (sub.assigned_only=0 OR $s[11]=sub.user_id)";}
        $extra=',sub.id AS subscription_id,rr.read_at';$from.=" LEFT JOIN reminder_reads rr ON rr.subscription_id=sub.id AND rr.object_id=$s[4] AND rr.deadline_key=CAST($due AS TEXT)";
        $end=$epoch?'(CAST(? AS BIGINT) + sub.lead_days * 86400)':(isPostgres($db)?'CAST(CAST(? AS DATE)+CAST(sub.lead_days AS INTEGER) AS TEXT)':"date(?, '+' || sub.lead_days || ' days')");$args[]=$cutoff;
    }else{$end='?';$args[]=$epoch?$now+$horizon*86400:gmdate('Y-m-d',$now+$horizon*86400);}
    $where="FROM $from WHERE ($scope) AND ($s[7]) AND ($s[10]) AND $due IS NOT NULL AND $due <= $end";
    return ['select'=>discoverySelect($s).",$due AS deadline,CAST($due AS TEXT) AS deadline_key".$extra,'where'=>$where,'args'=>$args,'epoch'=>$epoch,'cutoff'=>$cutoff,'due'=>$due];
}
function reminderInbox(PDO $db,array $user,?int $project,bool $fresh,int $now,string $filter='',int $page=1,bool $unread=false): array {
    $u=organizationUser($db,$user);if($project!==null)allowedProject($db,$u,$project);$sources=reminderSources();choice($filter,[''=>'Все']+array_map(fn($s)=>$s[0],$sources));if($page<1||$page>100||($filter===''&&$page!==1))throw new Problem('Выберите тип напоминаний для следующей страницы.');
    $groups=$errors=$more=[];$limit=$filter===''?20:50;$offset=($page-1)*$limit;
    foreach($sources as $kind=>$s){if($filter!==''&&$filter!==$kind)continue;try{$q=reminderQuery($db,$u,$kind,$project,$fresh,$now,true);if($q===null)continue;$rows=query($db,'SELECT '.$q['select'].' '.$q['where'].($unread?' AND rr.read_at IS NULL':'').' ORDER BY '.$q['due'].',id LIMIT '.($limit+1).' OFFSET '.$offset,$q['args'])->fetchAll();$more[$kind]=count($rows)>$limit;$groups[$kind]=array_slice($rows,0,$limit);}catch(\PDOException|Problem){$errors[$kind]='Источник временно недоступен.';}}
    return compact('groups','errors','more','project','filter','page','unread','now')+['projects'=>discoveryProjects($db,$u),'labels'=>array_map(fn($s)=>$s[0],$sources)];
}
function reminderMark(PDO $db,array $user,int $subscription,int $object,string $deadline,bool $read,bool $fresh,int $now): void {
    atomic($db,function()use($db,$user,$subscription,$object,$deadline,$read,$fresh,$now){$u=organizationUser($db,$user);$sub=entity($db,'SELECT * FROM reminder_subscriptions WHERE id=? AND user_id=? AND organization_id=?',[$subscription,$u['id'],$u['organization_id']]);$p=(int)$sub['project_id'];$s=reminderSources()[$sub['kind']];discoveryAllowed($db,$u,$p,$s,$fresh);
        $q=reminderQuery($db,$u,$sub['kind'],$p,$fresh,$now,true);$row=query($db,'SELECT '.$q['select'].' '.$q['where']." AND sub.id=? AND $s[4]=? AND CAST($q[due] AS TEXT)=?",[...$q['args'],$subscription,$object,$deadline])->fetch();if(!$row)throw new Problem('Напоминание больше не актуально.',409);
        if($read)query($db,'INSERT INTO reminder_reads(subscription_id,object_id,deadline_key,read_at) VALUES(?,?,?,?) ON CONFLICT(subscription_id,object_id,deadline_key) DO NOTHING',[$subscription,$object,$deadline,$now]);else query($db,'DELETE FROM reminder_reads WHERE subscription_id=? AND object_id=? AND deadline_key=?',[$subscription,$object,$deadline]);
    });
}
function reminderDigest(PDO $db,array $user,?int $project,bool $fresh,int $now,int $horizon): array {
    $u=orgOwner($db,$user);if(!$fresh)throw new Problem('Подтвердите второй фактор.',403);if($horizon<0||$horizon>90)throw new Problem('Горизонт: 0–90 дней.');if($project!==null)allowedProject($db,$u,$project);
    $summary=$errors=[];foreach(reminderSources() as $kind=>$s){try{$q=reminderQuery($db,$u,$kind,$project,$fresh,$now,false,$horizon);$cmp=$q['epoch']?'<=':'<';$rows=query($db,"SELECT p.id AS project_id,p.title AS project_title,COUNT(*) AS total,SUM(CASE WHEN {$q['due']} $cmp ? THEN 1 ELSE 0 END) AS overdue {$q['where']} GROUP BY p.id,p.title ORDER BY p.id",[$q['cutoff'],...$q['args']])->fetchAll();$summary[$kind]=$rows;}catch(\PDOException|Problem){$errors[$kind]='Источник временно недоступен.';}}
    return compact('summary','errors','project','horizon','now')+['projects'=>discoveryProjects($db,$u),'labels'=>array_map(fn($s)=>$s[0],reminderSources())];
}

/** Exact live unread count for the overview. A failed source is not counted as zero. */
function reminderOverview(PDO $db,array $user,bool $fresh,int $now): array {
    $u=organizationUser($db,$user);$count=0;$errors=[];
    foreach(reminderSources() as $kind=>$s){try{$q=reminderQuery($db,$u,$kind,null,$fresh,$now,true);if($q===null)continue;$count+=(int)query($db,'SELECT COUNT(*) '.$q['where'].' AND rr.read_at IS NULL',$q['args'])->fetchColumn();}catch(\PDOException|Problem){$errors[]=$kind;}}
    return compact('count','errors');
}
