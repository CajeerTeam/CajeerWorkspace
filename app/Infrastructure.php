<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const INFRA_ENV=['dev'=>'Development','staging'=>'Staging','prod'=>'Production'];
const INFRA_OPS=['host.restart'=>'Перезапуск хоста','host.backup'=>'Резервирование хоста','site.configure'=>'Настройка сайта','certificate.renew'=>'Продление сертификата','site.deploy'=>'Развёртывание сайта'];
const INFRA_TTL=900;
function infraUuid(mixed $v): string {
    if (!is_string($v)||!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i',$v)) throw new Problem('Требуется UUID ресурса.');
    return strtolower($v);
}
function infraSiteName(mixed $v): string {
    if (!is_string($v)||strlen($v)>253||!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\z/',$v)||str_contains($v,'..')) throw new Problem('Неподдерживаемое имя сайта.');
    return $v;
}
function infraSafeText(mixed $v,int $max=255): string {
    if (!is_string($v)||strlen($v)>$max*4||preg_match('/[[:cntrl:]]/u',$v)!==0||!preg_match('//u',$v)) throw new Problem('Некорректные метаданные источника.');
    return $v;
}
function infraCert(mixed $ssl): array {
    // aaPanel data.getData returns -1 on absent OR unreadable certificate; do not claim absence.
    if ($ssl===-1||$ssl==='-1'||$ssl===null||$ssl===false) return ['state'=>'unknown','expires'=>null,'issuer'=>null,'dns'=>[]];
    if (!is_array($ssl)||!isset($ssl['notAfter'])) return ['state'=>'unknown','expires'=>null,'issuer'=>null,'dns'=>[]];
    $date=calendarDate($ssl['notAfter']);
    $dns=[]; foreach (array_slice(is_array($ssl['dns']??null)?$ssl['dns']:[],0,100) as $d) {
        if (is_string($d)&&preg_match('/\A(?:\*\.)?[a-zA-Z0-9][a-zA-Z0-9.-]{0,252}\z/',$d)) $dns[]=$d;
    }
    return ['state'=>'observed','expires'=>$date,'issuer'=>isset($ssl['issuer'])&&is_string($ssl['issuer'])?infraSafeText($ssl['issuer']):null,'dns'=>array_values(array_unique($dns))];
}
function infraPanel(array $c,string $api,?callable $read=null): array {
    $key=getenv($c['env']??'')?:'';
    if ($key===''||preg_match('/[\x00-\x1f\x7f]/',$key)) throw new Problem('Ключ aaPanel не настроен.');
    $read??=static fn($method,$url,$headers,$form)=>httpJson($method,$url,$headers,$form,2000,5000);
    $sites=[]; $deadline=microtime(true)+20; $limit=25;
    $path=$api==='v2'?'/v2/data?action=getData':'/data?action=getData';
    for($p=1;$p<=40;$p++) {
        if (microtime(true)>$deadline) throw new Problem('Лимит времени инвентаризации aaPanel.');
        $t=(string)time(); $form=['request_time'=>$t,'request_token'=>md5($t.md5($key)),'table'=>'sites','p'=>$p,'limit'=>$limit,'search'=>'','order'=>'id asc','type'=>-1];
        $r=$read('POST',$c['base'].$path,[],$form);
        if (!is_array($r['data']??null)||!array_is_list($r['data'])||count($r['data'])>$limit||($r['status']??null)===false) throw new Problem('Неподдерживаемый ответ списка сайтов aaPanel.');
        foreach ($r['data'] as $row) {
            if (!is_array($row)) throw new Problem('Некорректный сайт aaPanel.');
            $id=numberId($row['id']??null); $name=infraSiteName($row['name']??null);
            if(isset($sites[$id])) throw new Problem('Дублирование ID при пагинации aaPanel.');
            $status=match($row['status']??null){1,'1'=> 'enabled',0,'0'=>'disabled',default=>'unknown'};
            $sites[$id]=['id'=>$id,'name'=>$name,'status'=>$status,'certificate'=>infraCert($row['ssl']??null)];
        }
        if(count($r['data'])<$limit) {
            ksort($sites);
            // The domain table is a separate documented data.getData inventory (no GetSSL/private key).
            foreach($sites as &$site) $site['domains']=[]; unset($site);
            $seen=[];
            for($page=1;$page<=100;$page++){
                if(microtime(true)>$deadline) throw new Problem('Лимит времени инвентаризации доменов.');
                $t=(string)time(); $d=$read('POST',$c['base'].$path,[],['request_time'=>$t,'request_token'=>md5($t.md5($key)),'table'=>'domain','p'=>$page,'limit'=>50,'search'=>'','order'=>'id asc']);
                if(!is_array($d['data']??null)||!array_is_list($d['data'])||count($d['data'])>50||($d['status']??null)===false) throw new Problem('Неподдерживаемый ответ списка доменов.');
                foreach($d['data'] as $row){
                    if(!is_array($row)) throw new Problem('Некорректная строка домена.');
                    $did=numberId($row['id']??null); $pid=numberId($row['pid']??null);
                    if(isset($seen[$did])) throw new Problem('Дублирование ID домена.'); $seen[$did]=true;
                    if(!isset($sites[$pid])) continue;
                    $domain=infraSafeText($row['name']??null,253); $port=numberId($row['port']??null);
                    if($port>65535||!preg_match('/\A(?:\*\.)?[A-Za-z0-9][A-Za-z0-9.-]{0,252}\z/',$domain)) throw new Problem('Некорректный домен или порт.');
                    $sites[$pid]['domains'][]=['name'=>$domain,'port'=>$port];
                }
                if(count($d['data'])<50) return ['sites'=>array_values($sites)];
            }
            throw new Problem('Превышен лимит доменов.');
        }
    }
    throw new Problem('Список aaPanel превышает лимит 999 сайтов; неполный снимок не сохранён.');
}
function infraServers(array $c,string $workspace,string $asset,?callable $read=null): array {
    // The published Inventory API uses a session, not a bearer token. Operator supplies a dedicated session cookie.
    $cookie=getenv($c['env']??'')?:'';
    if ($cookie===''||strlen($cookie)>4096||!preg_match('/\A[A-Za-z0-9_-]+=[A-Za-z0-9._~%+\/=:-]+\z/',$cookie)) throw new Problem('Сессионный cookie CajeerServers не настроен.');
    $read??=static fn($method,$url,$headers,$form)=>httpJson($method,$url,$headers,$form,2000,5000);
    $r=$read('GET',$c['base'].'/api/v1/inventory/assets/'.infraUuid($asset),['Cookie: '.$cookie],null);
    $a=$r['data']??null;
    if(!is_array($a)||infraUuid($a['id']??null)!==$asset||infraUuid($a['workspace_id']??null)!==$workspace||!empty($a['deleted_at'])) throw new Problem('Ресурс CajeerServers не совпадает с привязкой.');
    $ips=[]; foreach(array_slice(is_array($a['ip_addresses']??null)?$a['ip_addresses']:[],0,64) as $ip) if(is_string($ip)&&filter_var($ip,FILTER_VALIDATE_IP)) $ips[]=$ip;
    return ['id'=>$asset,'workspace_id'=>$workspace,'name'=>infraSafeText($a['name']??null),'type'=>infraSafeText($a['type']??null,64),'status'=>infraSafeText($a['status']??null,64),'environment'=>infraSafeText($a['environment']??'',64),'ips'=>$ips,'version'=>numberId($a['version']??null)];
}
function infraConfig(PDO $db,array $u,string $key,string $type,?string $hash=null): array {
    $c=connections()[$key]??null;
    if(!$c||$c['type']!==$type||!$c['configured']||!str_starts_with($c['base'],'https://')||!connectionAllowed($db,$u,$key)||($hash!==null&&!hash_equals($hash,$c['hash']))) throw new Problem('Подключение отсутствует, изменено или недоступно.',409);
    return $c;
}
function infraHost(PDO $db,array $u,int $id): array {return entity($db,'SELECT * FROM infra_hosts WHERE id=? AND organization_id=?',[$id,$u['organization_id']]);}
function infraTarget(array $c): string {
    $url=parse_url($c['base']);
    return hash('sha256',strtolower($url['host']).':'.($url['port']??443).rtrim($url['path']??'','/'));
}
function infraHostCreate(PDO $db,array $user,array $in): int {
    return atomic($db,function()use($db,$user,$in){
        $u=orgOwner($db,$user); $panel=infraConfig($db,$u,word($in['panel_key']??'',48,'aaPanel'),'aapanel'); $servers=infraConfig($db,$u,word($in['servers_key']??'',48,'CajeerServers'),'servers');
        if(!$servers['env']) throw new Problem('Укажите secret_env для CajeerServers.');
        $asset=infraUuid($in['asset_uuid']??null); $workspace=infraUuid($in['workspace_uuid']??null); $api=choice($in['panel_api']??'', ['v2'=>'','legacy'=>'']); $name=word($in['name']??'',120,'Хост');
        if(query($db,'SELECT 1 FROM infra_hosts WHERE panel_key=? OR panel_target=? OR (servers_key=? AND asset_uuid=?) OR (servers_target=? AND asset_uuid=?)',[$panel['id'],infraTarget($panel),$servers['id'],$asset,infraTarget($servers),$asset])->fetchColumn()) throw new Problem('Хост уже зарегистрирован.',409);
        $id=insertId($db,'INSERT INTO infra_hosts(organization_id,name,panel_key,panel_target,panel_hash,panel_api,servers_key,servers_target,servers_hash,workspace_uuid,asset_uuid) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$u['organization_id'],$name,$panel['id'],infraTarget($panel),$panel['hash'],$api,$servers['id'],infraTarget($servers),$servers['hash'],$workspace,$asset]);
        auditEvent($db,$u,null,'infra.host.create',$id); return $id;
    });
}
function infraReadObs(PDO $db,int $host): array {
    $out=[]; foreach(query($db,'SELECT * FROM infra_observations WHERE host_id=?',[$host])->fetchAll() as $r){$r['data']=$r['snapshot']===null?null:json_decode($r['snapshot'],true,32,JSON_THROW_ON_ERROR); unset($r['snapshot']); $r['fresh']=$r['success_at']!==null&&$r['error']===''&&(int)$r['success_at']>=time()-INFRA_TTL; $out[$r['source']]=$r;} return $out;
}
function infraSync(PDO $db,array $user,int $id,?callable $read=null,bool $cli=false): array {
    $u=orgOwner($db,$user); $started=time();
    // Fence overlapping syncs for each host via a generation/token, without holding a DB transaction over network I/O.
    $token=bin2hex(random_bytes(32));
    [$generation,$host]=atomic($db,function()use($db,$u,$id,$token){$h=infraHost($db,orgOwner($db,$u),$id); $v=(int)$h['version']+1; query($db,'UPDATE infra_hosts SET version=?,sync_token=? WHERE id=?',[$v,$token,$id]); foreach(['aapanel','servers'] as $source) query($db,"INSERT INTO infra_observations(host_id,source,attempt_at,error) VALUES(?,?,?,'Сверка выполняется') ON CONFLICT(host_id,source) DO UPDATE SET attempt_at=excluded.attempt_at,error=excluded.error",[$id,$source,time()]); return [$v,$h];});
    $results=[];
    foreach(['aapanel','servers'] as $source){
        $data=null; $error='';
        try {
            $c=infraConfig($db,$u,$host[$source==='aapanel'?'panel_key':'servers_key'],$source,$host[$source==='aapanel'?'panel_hash':'servers_hash']);
            $data=$source==='aapanel'?infraPanel($c,$host['panel_api'],$read):infraServers($c,$host['workspace_uuid'],$host['asset_uuid'],$read);
        } catch (\Throwable $e) { $error='Источник недоступен или ответ несовместим; проверьте настройки и права API.'; }
        if($data!==null){$secret=getenv($c['env']??'')?:''; $serialized=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE); $value=$source==='servers'?substr($secret,strpos($secret,'=')+1):$secret; if(strlen($value)>=8&&str_contains($serialized,$value)){ $data=null;$error='Источник вернул небезопасные данные.'; }}
        $results[$source]=['data'=>$data,'error'=>$error];
    }
    return atomic($db,function()use($db,$u,$id,$generation,$results,$started,$token,$cli){
        $u=orgOwner($db,$u); $h=infraHost($db,$u,$id); if((int)$h['version']!==$generation||$h['sync_token']!==$token) throw new Problem('Начата более новая сверка.',409);
        foreach($results as $source=>$r){
            // Refuse committing observations if the operator changed connection identity during polling.
            try {infraConfig($db,$u,$h[$source==='aapanel'?'panel_key':'servers_key'],$source,$h[$source==='aapanel'?'panel_hash':'servers_hash']);}catch(\Throwable){$r=['data'=>null,'error'=>'Конфигурация источника изменилась.'];}
            if($r['data']!==null) query($db,'INSERT INTO infra_observations(host_id,source,attempt_at,success_at,snapshot,error) VALUES(?,?,?,?,?,?) ON CONFLICT(host_id,source) DO UPDATE SET attempt_at=excluded.attempt_at,success_at=excluded.success_at,snapshot=excluded.snapshot,error=excluded.error',[$id,$source,$started,time(),json_encode($r['data'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'']);
            else query($db,'INSERT INTO infra_observations(host_id,source,attempt_at,error) VALUES(?,?,?,?) ON CONFLICT(host_id,source) DO UPDATE SET attempt_at=excluded.attempt_at,error=excluded.error',[$id,$source,$started,$r['error']]);
            auditEvent($db,$u,null,'infra.sync',$id,($cli?'cli:':'web:').$source.':'.($r['data']!==null?'ok':'error'));
            logsHost($db,(int)$u['organization_id'],$id,$source,$r['data']!==null);
        } query($db,'UPDATE infra_hosts SET sync_token=NULL WHERE id=?',[$id]); return infraReadObs($db,$id);
    });
}
function infraChange(PDO $db,array $user,int $project,string $act,array $in): void {
    atomic($db,function()use($db,$user,$project,$act,$in){
        $u=orgOwner($db,$user); allowedProject($db,$u,$project); $org=$u['organization_id'];
        if($act==='infra.environment'){
            $kind=choice($in['kind']??'',INFRA_ENV); $name=word($in['name']??'',120,'Окружение');
            query($db,'INSERT INTO infra_environments(organization_id,project_id,kind,name) VALUES(?,?,?,?) ON CONFLICT(project_id,kind) DO UPDATE SET name=excluded.name',[$org,$project,$kind,$name]); auditEvent($db,$u,$project,$act,$project,$kind); return;
        }
        if($act==='infra.detach'){
            $site=numberId($in['site_id']??null); entity($db,'SELECT id FROM infra_sites WHERE id=? AND project_id=? AND organization_id=?',[$site,$project,$org]); if(query($db,'SELECT 1 FROM deployments WHERE site_id=?',[$site])->fetchColumn()) throw new Problem('Привязка имеет историю развёртываний и не может быть удалена.',409); if(query($db,'SELECT 1 FROM ops_services WHERE site_id=?',[$site])->fetchColumn())throw new Problem('Сайт зарегистрирован как сервис эксплуатации; история сохраняется.',409); query($db,'DELETE FROM infra_sites WHERE id=?',[$site]); auditEvent($db,$u,$project,$act,$site); return;
        }
        $hid=numberId($in['host_id']??null); $host=infraHost($db,$u,$hid);
        if($act==='infra.owner'){
            $op=choice($in['operation']??'',INFRA_OPS); if($op==='site.deploy'&&query($db,"SELECT 1 FROM deployments d JOIN infra_sites s ON s.id=d.site_id WHERE s.host_id=? AND d.state IN ('running','recovery_required')",[$hid])->fetchColumn())throw new Problem('Сначала завершите или восстановите развёртывания хоста.',409); $provider=choice($in['provider']??'', ['aapanel'=>'','servers'=>'']); $responsible=numberId($in['responsible_id']??null);
            entity($db,'SELECT u.id FROM users u JOIN organization_members m ON m.user_id=u.id WHERE u.enabled=1 AND m.organization_id=? AND u.id=?',[$org,$responsible]);
            query($db,'INSERT INTO infra_operation_owners(host_id,operation,provider,responsible_id) VALUES(?,?,?,?) ON CONFLICT(host_id,operation) DO UPDATE SET provider=excluded.provider,responsible_id=excluded.responsible_id',[$hid,$op,$provider,$responsible]); auditEvent($db,$u,null,$act,$hid,$op.':'.$provider); return;
        }
        if($act==='infra.deletehost'){
            if(query($db,'SELECT 1 FROM infra_sites WHERE host_id=?',[$hid])->fetchColumn()) throw new Problem('Сначала отвяжите все сайты хоста.',409);
            query($db,'DELETE FROM infra_hosts WHERE id=?',[$hid]); auditEvent($db,$u,null,$act,$hid); return;
        }
        if($act!=='infra.bind') throw new Problem('Неизвестное действие.');
        $eid=numberId($in['environment_id']??null); entity($db,'SELECT id FROM infra_environments WHERE id=? AND project_id=? AND organization_id=?',[$eid,$project,$org]);
        infraConfig($db,$u,$host['panel_key'],'aapanel',$host['panel_hash']); infraConfig($db,$u,$host['servers_key'],'servers',$host['servers_hash']);
        $obs=infraReadObs($db,$hid); if(!($obs['aapanel']['fresh']??false)||!($obs['servers']['fresh']??false)) throw new Problem('Нужна свежая успешная сверка обоих источников.',409);
        $remote=numberId($in['remote_id']??null); $name=infraSiteName($in['site_name']??null); $found=false;
        foreach($obs['aapanel']['data']['sites'] as $s) if($s['id']===$remote&&$s['name']===$name) $found=true;
        if(!$found) throw new Problem('Сайт не найден в актуальном инвентаре.',409);
        $existing=query($db,'SELECT * FROM infra_sites WHERE host_id=? AND (remote_id=? OR site_name=?)',[$hid,$remote,$name])->fetch();
        if($existing){if((int)$existing['environment_id']===$eid&&(int)$existing['remote_id']===$remote&&$existing['site_name']===$name)return; throw new Problem('Сайт уже привязан. Сначала отвяжите его в исходном проекте.',409);}
        $id=insertId($db,'INSERT INTO infra_sites(organization_id,project_id,environment_id,host_id,remote_id,site_name) VALUES(?,?,?,?,?,?)',[$org,$project,$eid,$hid,$remote,$name]); auditEvent($db,$u,$project,$act,$id);
    });
}
function infraPage(PDO $db,array $user,int $project): array {
    $u=organizationUser($db,$user); requireProjectRight($db,$u,$project,'connections'); $p=allowedProject($db,$u,$project); $admin=$u['role']==='owner';
    $envs=query($db,'SELECT * FROM infra_environments WHERE project_id=? ORDER BY kind',[$project])->fetchAll();
    $bindings=query($db,'SELECT * FROM infra_sites WHERE project_id=? ORDER BY id',[$project])->fetchAll();
    $hosts=$admin?query($db,'SELECT * FROM infra_hosts WHERE organization_id=? ORDER BY id',[$u['organization_id']])->fetchAll():query($db,'SELECT DISTINCT h.* FROM infra_hosts h JOIN infra_sites s ON s.host_id=h.id WHERE s.project_id=? AND h.organization_id=? ORDER BY h.id',[$project,$u['organization_id']])->fetchAll();
    foreach($hosts as &$h){
        $obs=infraReadObs($db,(int)$h['id']);
        foreach(['aapanel','servers'] as $source){try{infraConfig($db,$u,$h[$source==='aapanel'?'panel_key':'servers_key'],$source,$h[$source==='aapanel'?'panel_hash':'servers_hash']);}catch(\Throwable){$obs[$source]=['fresh'=>false,'error'=>'Источник изменён или недоступен.','data'=>null,'attempt_at'=>null,'success_at'=>null];}}
        if(!$admin&&isset($obs['aapanel']['data']['sites'])) $obs['aapanel']['data']['sites']=array_values(array_filter($obs['aapanel']['data']['sites'],fn($site)=>array_filter($bindings,fn($b)=>(int)$b['host_id']===(int)$h['id']&&(int)$b['remote_id']===$site['id']&&$b['site_name']===$site['name'])));
        $h['observations']=$obs;
        $h['owners']=array_column(query($db,'SELECT o.*,CASE WHEN m.user_id IS NOT NULL AND u.enabled=1 THEN u.name ELSE NULL END AS responsible_name FROM infra_operation_owners o JOIN users u ON u.id=o.responsible_id LEFT JOIN organization_members m ON m.user_id=u.id AND m.organization_id=? WHERE o.host_id=?',[$u['organization_id'],$h['id']])->fetchAll(),null,'operation');
    } unset($h);
    $options=[]; if($admin) foreach(connections() as $key=>$c) if(in_array($c['type'],['aapanel','servers'],true)&&$c['configured']&&connectionAllowed($db,$u,$key)) $options[$c['type']][$key]=$c['label'];
    return ['project'=>$p,'infraAdmin'=>$admin,'environments'=>$envs,'bindings'=>$bindings,'hosts'=>array_column($hosts,null,'id'),'infraOptions'=>$options,'infraMembers'=>$admin?array_column(array_filter(orgMembers($db,$u),fn($m)=>$m['enabled']),'name','id'):[]];
}
