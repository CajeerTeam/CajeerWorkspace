<?php
declare(strict_types=1);

namespace Workspace;

use PDO;
use RuntimeException;
use JsonException;

const GITHUB_API_VERSION = '2026-03-10';
const CATALOG = [
    'engine'=>['CajeerEngine','CMS','/health/ready','ready'],
    'logs'=>['CajeerLogs','Журналы','/ready','ok'],
    'forum'=>['CajeerEngineForum','Форум','/health/ready','ready'],
    'book'=>['CajeerEngineBook','База знаний','/health/ready','ok'],
    'lite'=>['CajeerEngineLite','Файловая CMS','/health/ready','ready'],
    'bots'=>['CajeerBots','Боты и сообщества','/api/health','ok'],
    'servers'=>['CajeerServers','Инвентарь серверов','/api/v1/ready','ready'],
    'aapanel'=>['aaPanel','Хостинг','/system?action=GetSystemTotal',null],
    'github'=>['GitHub','Репозиторий','/repos/{owner}/{repo}',null],
];

function trustedUrl(mixed $value, bool $httpsOnly): ?string {
    if ($value === null || $value === '') return null;
    if (!is_string($value) || !filter_var($value,FILTER_VALIDATE_URL) || preg_match('/[\x00-\x20\\\\]/',$value)) throw new RuntimeException('Некорректный URL подключения.');
    $url = parse_url($value);
    if (!$url || !isset($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) throw new RuntimeException('URL подключения должен содержать только адрес и путь.');
    $loopback = in_array(strtolower($url['host']),['localhost','127.0.0.1','[::1]'],true);
    if ($url['scheme'] !== 'https' && !(!$httpsOnly && $loopback && $url['scheme'] === 'http')) throw new RuntimeException('Для подключения требуется HTTPS. Для локальной проверки Cajeer допускается HTTP на loopback.');
    return rtrim($value,'/');
}

function connectionConfig(?string $file = null): array {
    $file ??= getenv('WORKSPACE_CONFIG') ?: ROOT.'/config/connections.local.json';
    $config = [];
    if (is_file($file)) {
        $raw = file_get_contents($file);
        $config = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($config) || ($config !== [] && array_is_list($config))) throw new RuntimeException('Нужен JSON-объект подключений.');
    } elseif (getenv('WORKSPACE_CONFIG')) {
        throw new RuntimeException('Указанный файл подключений отсутствует.');
    }
    $stand = $config['_stand'] ?? [];
    unset($config['_stand']);
    if (!is_array($stand) || array_diff(array_keys($stand),['name','environment','owner'])) throw new RuntimeException('Неизвестный параметр профиля стенда.');
    $stand = [
        'name'=>word($stand['name'] ?? 'Workspace',100,'Название стенда'),
        'environment'=>word($stand['environment'] ?? '',80,'Окружение',false),
        'owner'=>word($stand['owner'] ?? '',120,'Владелец стенда',false),
    ];
    $items = [];
    foreach (array_unique(array_merge(array_keys(CATALOG),array_keys($config))) as $id) {
        if (!is_string($id) || !preg_match('/\A[a-z][a-z0-9_-]{0,47}\z/',$id)) throw new RuntimeException('Некорректное имя подключения.');
        $options = $config[$id] ?? [];
        if (!is_array($options) || array_diff(array_keys($options),['type','label','base_url','launch_url','repository','secret_env','owner','installed_version','version_path','version_field'])) throw new RuntimeException('Неизвестный параметр подключения '.$id.'.');
        $type = $options['type'] ?? $id;
        if (!is_string($type) || !isset(CATALOG[$type]) || (isset(CATALOG[$id]) && $id !== $type)) throw new RuntimeException('Неизвестный тип подключения '.$id.'.');
        [$name,$purpose] = CATALOG[$type];
        $repo = $options['repository'] ?? null;
        if ($repo !== null && ($type !== 'github' || !is_string($repo) || !preg_match('~\A[A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9_.-]{1,100}\z~',$repo) || in_array(explode('/',$repo)[1],['.','..'],true))) throw new RuntimeException('Ожидалось имя репозитория owner/repo.');
        $base = trustedUrl($options['base_url'] ?? null,$type === 'aapanel');
        if ($type === 'github' && $base !== null) throw new RuntimeException('Адрес GitHub API задан адаптером.');
        $env = $options['secret_env'] ?? ($type === 'aapanel' ? 'WORKSPACE_AAPANEL_KEY' : ($type === 'github' ? 'WORKSPACE_GITHUB_TOKEN' : null));
        if ($env !== null && (!in_array($type,['aapanel','github'],true) || !is_string($env) || !preg_match('/\AWORKSPACE_[A-Z0-9_]{1,80}\z/',$env))) throw new RuntimeException('Проверьте имя переменной с ключом.');
        $launch = trustedUrl($options['launch_url'] ?? null,$type === 'aapanel') ?? ($repo ? 'https://github.com/'.$repo : null);
        $versionPath = $options['version_path'] ?? null;
        $versionField = $options['version_field'] ?? 'version';
        if ($versionPath !== null && (in_array($type,['github','aapanel'],true) || !is_string($versionPath) || strlen($versionPath)>200 || !preg_match('~\A/(?:[A-Za-z0-9_-][A-Za-z0-9_.-]*)(?:/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*\z~',$versionPath))) throw new RuntimeException('Некорректный относительный путь версии '.$id.'.');
        if ((isset($options['version_field']) && in_array($type,['github','aapanel'],true)) || !is_string($versionField) || strlen($versionField)>100 || !preg_match('/\A[A-Za-z_][A-Za-z0-9_-]*(?:\.[A-Za-z_][A-Za-z0-9_-]*)*\z/',$versionField)) throw new RuntimeException('Некорректное поле версии '.$id.'.');
        $installed = $options['installed_version'] ?? null;
        if ($installed !== null) {
            if ($type==='github') throw new RuntimeException('Версия установленного продукта не применяется к GitHub.com.');
            $installed = versionText($installed);
        }
        $owner = word($options['owner'] ?? '',120,'Владелец подключения',false);
        // Preserve the 0.1.0 target identity when no extra version route is used.
        $identity = [$type,$repo,$base,$env];
        if ($versionPath !== null || $versionField !== 'version') $identity = [...$identity,$versionPath,$versionField];
        $items[$id] = [
            'id'=>$id,'type'=>$type,'label'=>word($options['label'] ?? $name,80,'Подключение'),'purpose'=>$purpose,'base'=>$base,
            'repo'=>$repo,'launch'=>$launch,'env'=>$env,'configured'=>$type === 'github' ? $repo!==null : $base!==null,
            'owner'=>$owner,'installed_version'=>$installed,'version_path'=>$versionPath,'version_field'=>$versionField,
            'requested_api_version'=>$type==='github'?GITHUB_API_VERSION:null,
            'hash'=>hash('sha256',json_encode($identity,JSON_THROW_ON_ERROR)),
        ];
    }
    return ['stand'=>$stand,'items'=>$items];
}

function connections(?string $file = null): array { return connectionConfig($file)['items']; }

function connectionEndpoint(array $c): ?string {
    if (!$c['configured']) return null;
    return $c['type']==='github' ? 'https://api.github.com/repos/'.$c['repo'] : $c['base'].CATALOG[$c['type']][2];
}

/** Only server-side operator configuration supplies destinations. Redirects would cross that trust boundary. */
function httpJson(string $method, string $url, array $headers = [], ?array $form = null, int $connectMs = 2000, int $totalMs = 5500, ?array &$trace = null): array {
    $trace = ['http_status'=>null];
    $curl = curl_init($url);
    $body = '';
    $tooLarge = false;
    $options = [
        CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_CONNECTTIMEOUT_MS=>$connectMs, CURLOPT_TIMEOUT_MS=>$totalMs,
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_USERAGENT=>'CajeerWorkspace-PHP/'.VERSION,
        CURLOPT_HTTPHEADER=>array_merge(['Accept: application/json'], $headers),
        CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$body,&$tooLarge): int {
            if (strlen($body)+strlen($chunk) > 131072) { $tooLarge = true; return 0; }
            $body .= $chunk;
            return strlen($chunk);
        },
    ];
    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($form ?? [], '', '&', PHP_QUERY_RFC3986);
    }
    curl_setopt_array($curl,$options);
    $sent = curl_exec($curl);
    $status = (int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    $trace['http_status'] = $status ?: null;
    $mime = (string)curl_getinfo($curl,CURLINFO_CONTENT_TYPE);
    $error = curl_errno($curl);
    if ($tooLarge) throw new Problem('Ответ превышает 128 КиБ.');
    if ($sent === false) throw new Problem($error === CURLE_OPERATION_TIMEDOUT ? 'Истекло время ожидания.' : 'Ошибка сети или TLS.');
    if ($status !== 200) throw new Problem('HTTP '.$status);
    if (!preg_match('~^application/(?:json|[a-z0-9.+-]+\+json)(?:\s*;|$)~i',$mime)) throw new Problem('Ответ имеет неожиданный Content-Type.');
    try { $data = json_decode($body,true,32,JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new Problem('Ответ содержит некорректный JSON.'); }
    if (!is_array($data) || array_is_list($data)) throw new Problem('Ожидался JSON-объект.');
    return $data;
}

function providerText(mixed $value, int $max = 100): string {
    if (!is_string($value) || $value === '' || strlen($value)>$max*4 || preg_match('/[[:cntrl:]]/u',$value)) throw new Problem('Провайдер вернул неожиданные данные.');
    return $value;
}

function versionText(mixed $value): string {
    if (!is_string($value) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._+~-]{0,79}\z/',$value)) throw new Problem('Провайдер вернул некорректную версию.');
    return $value;
}

function versionValue(array $data, string $field): mixed {
    foreach (explode('.',$field) as $part) {
        if (!is_array($data) || !array_key_exists($part,$data)) return null;
        $data = $data[$part];
    }
    return $data;
}

/** Read-only observation: raw response, request headers and signatures never enter the journal. */
function observeConnection(array $c, ?callable $transport = null): array {
    $r = ['ok'=>0,'summary'=>'Не настроено.','endpoint'=>connectionEndpoint($c),
        'method'=>$c['type']==='aapanel'?'POST':'GET','http_status'=>null,'duration_ms'=>null,
        'observed_version'=>null,'version_source'=>null,'version_error'=>null,'version_http_status'=>null,
        'version_duration_ms'=>null,'version_endpoint'=>null,'repository_owner'=>null,'repository_branch'=>null,'repository_archived'=>null];
    $secret = $c['env'] ? (getenv($c['env']) ?: '') : '';
    // Protect against a broken provider reflecting the supplied credential in an allowed field.
    $safe = static function (mixed $value, bool $version=false) use ($secret): string {
        $value = $version ? versionText($value) : providerText($value);
        if ($secret !== '' && str_contains($value,$secret)) throw new Problem('Провайдер вернул небезопасные данные.');
        return $value;
    };
    $read = static function (string $method,string $url,array $headers=[],?array $form=null,int $connect=2000,int $total=5500,bool $version=false) use (&$r,$transport): array {
        $trace = null;
        $start = hrtime(true);
        try {
            return $transport === null ? httpJson($method,$url,$headers,$form,$connect,$total,$trace) : $transport($method,$url,$headers,$form,$connect,$total);
        } finally {
            $prefix = $version?'version_':'';
            $r[$prefix.'http_status'] = $trace['http_status'] ?? null;
            $r[$prefix.'duration_ms'] = (int)round((hrtime(true)-$start)/1000000);
        }
    };
    try {
        if (!$c['configured']) throw new Problem('Не настроено.');
        if (preg_match('/[\x00-\x1f\x7f]/',$secret)) throw new Problem('Проверьте формат ключа.');
        if ($c['type'] === 'github') {
            $headers = ['X-GitHub-Api-Version: '.GITHUB_API_VERSION];
            if ($secret !== '') $headers[] = 'Authorization: Bearer '.$secret;
            $data = $read('GET',$r['endpoint'],$headers,null,10000,15000);
            if (strcasecmp($safe($data['full_name'] ?? null),$c['repo']) !== 0 || !is_bool($data['archived'] ?? null)) throw new Problem('Ответ не соответствует репозиторию.');
            $branch = $safe($data['default_branch'] ?? null);
            $r['repository_owner'] = explode('/',$data['full_name'],2)[0];
            $r['repository_branch'] = $branch;
            $r['repository_archived'] = (int)$data['archived'];
            $r['summary'] = $c['repo'].' · '.$branch.($data['archived'] ? ' · архив' : '');
        } elseif ($c['type'] === 'aapanel') {
            if ($secret === '') throw new Problem('Ключ aaPanel не задан.');
            $time = (string)time();
            $data = $read('POST',$r['endpoint'],[],['request_time'=>$time,'request_token'=>md5($time.md5($secret))]);
            $version = $safe($data['version'] ?? null,true);
            if (str_contains($version,md5($time.md5($secret))) || str_contains($version,md5($secret))) throw new Problem('Провайдер вернул небезопасные данные.');
            if (!is_numeric($data['cpuNum'] ?? null) || (int)$data['cpuNum'] < 1 || !is_numeric($data['memTotal'] ?? null) || (int)$data['memTotal'] < 1) throw new Problem('Статистика aaPanel недоступна.');
            $r['observed_version']=$version; $r['version_source']='panel_api';
            $r['summary']='aaPanel '.$version.' · CPU '.(int)$data['cpuNum'].' · RAM '.(int)$data['memTotal'].' МБ';
        } else {
            [$name,,,$expected] = CATALOG[$c['type']];
            $data = $read('GET',$r['endpoint']);
            if (($data['status'] ?? null) !== $expected) throw new Problem('Сервис не подтвердил готовность.');
            $r['summary']=$name.' · '.$expected;
            try {
                if (($c['version_path'] ?? null) !== null) {
                    $r['version_endpoint']=$c['base'].$c['version_path'];
                    $versionData=$read('GET',$r['version_endpoint'],[],null,2000,5500,true);
                    $r['observed_version']=$safe(versionValue($versionData,$c['version_field']),true);
                    $r['version_source']='version_api';
                } elseif (($versionValue=versionValue($data,$c['version_field'] ?? 'version')) !== null) {
                    $r['observed_version']=$safe($versionValue,true);
                    $r['version_source']='readiness';
                } else {
                    $r['version_error']='API готовности не сообщает версию.';
                }
            } catch (Problem $e) { $r['version_error']=$e->getMessage(); }
        }
        $r['ok']=1;
    } catch (Problem $e) { $r['summary']=$e->getMessage(); }
    return $r;
}

function probe(array $c, ?callable $transport = null): string {
    $r = observeConnection($c,$transport);
    if (!$r['ok']) throw new Problem($r['summary']);
    return $r['summary'];
}

function syncConnections(PDO $db, array $items, ?callable $transport = null): int {
    $failed = 0;
    foreach ($items as $c) {
        if (!$c['configured']) { fwrite(STDOUT,$c['id']."\tне настроено\n"); continue; }
        $r = observeConnection($c,$transport);
        $now = time();
        if (!$r['ok']) ++$failed;
        atomic($db, static function () use ($db,$c,$r,$now) {
            $old = query($db,'SELECT ok FROM connection_results WHERE connection_key=? AND target_hash=?',[$c['id'],$c['hash']])->fetch();
            $transition = !$old ? 'initial' : ((int)$old['ok']===$r['ok'] ? 'unchanged' : ($r['ok']?'recovered':'down'));
            $row = ['connection_key'=>$c['id'],'target_hash'=>$c['hash'],'checked_at'=>$now,'transition'=>$transition]+$r;
            query($db,'INSERT INTO connection_checks('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')',array_values($row));
            query($db,'INSERT INTO connection_results(connection_key,target_hash,ok,summary,checked_at) VALUES(?,?,?,?,?)
                ON CONFLICT(connection_key) DO UPDATE SET target_hash=excluded.target_hash,ok=excluded.ok,summary=excluded.summary,checked_at=excluded.checked_at',
                [$c['id'],$c['hash'],$r['ok'],$r['summary'],$now]);
            // Retain 30 days, plus the last successful observation and the last known API version of the current target.
            query($db,'DELETE FROM connection_checks WHERE connection_key=? AND checked_at<? AND id NOT IN (
                SELECT id FROM connection_checks WHERE connection_key=? AND target_hash=? AND ok=1 ORDER BY id DESC LIMIT 1
            ) AND id NOT IN (
                SELECT id FROM connection_checks WHERE connection_key=? AND target_hash=? AND observed_version IS NOT NULL ORDER BY id DESC LIMIT 1
            )',[$c['id'],$now-30*86400,$c['id'],$c['hash'],$c['id'],$c['hash']]);
        });
        fwrite(STDOUT,$c['id']."\t".($r['ok']?'OK':'ERROR')."\t".$r['summary']."\n");
    }
    return $failed;
}

function connectionViews(PDO $db, ?array $items=null): array {
    $items ??= connections();
    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) $db->beginTransaction();
    try {
        $results = [];
        foreach ($db->query('SELECT * FROM connection_results') as $r) $results[$r['connection_key']] = $r;
        foreach ($items as $key=>&$c) {
            $snapshot = $results[$key] ?? null;
            if (!$snapshot || $snapshot['target_hash'] !== $c['hash'] || !$c['configured']) $snapshot = null;
            $c['snapshot'] = $snapshot;
            $c['latest'] = $c['last_success'] = $c['last_version'] = null;
            if ($snapshot !== null) {
                $args=[$key,$c['hash']];
                $c['latest']=query($db,'SELECT * FROM connection_checks WHERE connection_key=? AND target_hash=? ORDER BY id DESC LIMIT 1',$args)->fetch() ?: null;
                $c['last_success']=query($db,'SELECT * FROM connection_checks WHERE connection_key=? AND target_hash=? AND ok=1 ORDER BY id DESC LIMIT 1',$args)->fetch() ?: null;
                $c['last_version']=query($db,'SELECT * FROM connection_checks WHERE connection_key=? AND target_hash=? AND observed_version IS NOT NULL ORDER BY id DESC LIMIT 1',$args)->fetch() ?: null;
            }
            $c['status'] = match (true) {
                !$c['configured'] => 'Не настроено',
                $snapshot === null => 'Ожидает проверки',
                time() - (int)$snapshot['checked_at'] > 300 => 'Данные устарели',
                (bool)$snapshot['ok'] => 'Доступно',
                default => 'Ошибка',
            };
            $c['endpoint']=connectionEndpoint($c);
        }
        unset($c);
        if ($ownTransaction) $db->commit();
    } catch (\Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return $items;
}

function connectionHistory(PDO $db,string $id,?int $before=null,int $limit=50): array {
    $limit=max(1,min(100,$limit));
    return query($db,'SELECT * FROM connection_checks WHERE connection_key=?'.($before===null?'':' AND id<?').' ORDER BY id DESC LIMIT '.$limit,
        $before===null?[$id]:[$id,$before])->fetchAll();
}

function profileReport(PDO $db): array {
    $config=connectionConfig();
    $items=connectionViews($db,$config['items']);
    foreach ($items as &$c) {
        unset($c['env'],$c['hash']);
        foreach (['snapshot','latest','last_success','last_version'] as $field) if ($c[$field]!==null) unset($c[$field]['target_hash']);
    }
    unset($c);
    $issues=[];
    foreach (['environment','owner'] as $field) if ($config['stand'][$field]==='') $issues[]='stand: '.$field.' не задано';
    $configuredTypes=[];
    foreach ($items as $id=>$c) {
        if (!$c['configured']) continue;
        $configuredTypes[$c['type']]=true;
        if ($c['owner']==='') $issues[]=$id.': ответственный не задан';
        if ($c['launch']===null) $issues[]=$id.': адрес интерфейса не задан';
        if ($c['status']!=='Доступно' || ($c['latest']['transition'] ?? 'legacy')==='legacy') $issues[]=$id.': нет свежей успешной проверки с протоколом';
        if ($c['type']!=='github' && ($c['latest']['observed_version'] ?? null)===null && $c['installed_version']===null) $issues[]=$id.': версия не подтверждена';
        if ($c['type']!=='github' && $c['installed_version']!==null && ($c['latest']['observed_version'] ?? null)!==null && $c['installed_version']!==$c['latest']['observed_version']) $issues[]=$id.': версия оператора отличается от API';
        if ($c['version_path']!==null && ($c['latest']['version_error'] ?? null)!==null) $issues[]=$id.': дополнительный API версии не подтверждён';
    }
    foreach (array_keys(CATALOG) as $type) if (!isset($configuredTypes[$type])) $issues[]=$type.': нет настроенного экземпляра данного типа';
    return ['workspace_version'=>VERSION,'generated_at'=>gmdate(DATE_ATOM),'stand'=>$config['stand'],'connections'=>$items,'issues'=>$issues];
}
