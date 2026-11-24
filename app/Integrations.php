<?php
declare(strict_types=1);

namespace Workspace;

use PDO;
use RuntimeException;
use JsonException;

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

function connections(?string $file = null): array {
    $file ??= getenv('WORKSPACE_CONFIG') ?: ROOT.'/config/connections.local.json';
    $config = [];
    if (is_file($file)) {
        $raw = file_get_contents($file);
        $config = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($config) || ($config !== [] && array_is_list($config))) throw new RuntimeException('Нужен JSON-объект подключений.');
    } elseif (getenv('WORKSPACE_CONFIG')) {
        throw new RuntimeException('Указанный файл подключений отсутствует.');
    }
    $items = [];
    foreach (array_unique(array_merge(array_keys(CATALOG),array_keys($config))) as $id) {
        if (!is_string($id) || !preg_match('/\A[a-z][a-z0-9_-]{0,47}\z/',$id)) throw new RuntimeException('Некорректное имя подключения.');
        $options = $config[$id] ?? [];
        if (!is_array($options) || array_diff(array_keys($options),['type','label','base_url','launch_url','repository','secret_env'])) throw new RuntimeException('Неизвестный параметр подключения '.$id.'.');
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
        $items[$id] = [
            'id'=>$id,'type'=>$type,'label'=>word($options['label'] ?? $name,80,'Подключение'),'purpose'=>$purpose,'base'=>$base,
            'repo'=>$repo,'launch'=>$launch,'env'=>$env,'configured'=>$type === 'github' ? $repo!==null : $base!==null,
            'hash'=>hash('sha256',json_encode([$type,$repo,$base,$env],JSON_THROW_ON_ERROR)),
        ];
    }
    return $items;
}

/** Only server-side operator configuration supplies destinations. Redirects would cross that trust boundary. */
function httpJson(string $method, string $url, array $headers = [], ?array $form = null, int $connectMs = 2000, int $totalMs = 5500): array {
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

function probe(array $c, ?callable $transport = null): string {
    $transport ??= __NAMESPACE__.'\\httpJson';
    if (!$c['configured']) throw new Problem('Не настроено.');
    $secret = $c['env'] ? (getenv($c['env']) ?: '') : '';
    if (preg_match('/[\x00-\x1f\x7f]/',$secret)) throw new Problem('Проверьте формат ключа.');
    if ($c['type'] === 'github') {
        $headers = ['X-GitHub-Api-Version: 2026-03-10'];
        if ($secret !== '') $headers[] = 'Authorization: Bearer '.$secret;
        $data = $transport('GET','https://api.github.com/repos/'.$c['repo'],$headers,null,10000,15000);
        if (strcasecmp(providerText($data['full_name'] ?? null),$c['repo']) !== 0 || !is_bool($data['archived'] ?? null)) throw new Problem('Ответ не соответствует репозиторию.');
        $branch = providerText($data['default_branch'] ?? null);
        return $c['repo'].' · '.$branch.($data['archived'] ? ' · архив' : '');
    }
    [$name,,$path,$expected] = CATALOG[$c['type']];
    if ($c['type'] === 'aapanel') {
        if ($secret === '') throw new Problem('Ключ aaPanel не задан.');
        $time = (string)time();
        $data = $transport('POST',$c['base'].$path,[],['request_time'=>$time,'request_token'=>md5($time.md5($secret))]);
        $version = providerText($data['version'] ?? null,32);
        if (!is_numeric($data['cpuNum'] ?? null) || (int)$data['cpuNum'] < 1 || !is_numeric($data['memTotal'] ?? null) || (int)$data['memTotal'] < 1) throw new Problem('Статистика aaPanel недоступна.');
        return 'aaPanel '.$version.' · CPU '.(int)$data['cpuNum'].' · RAM '.(int)$data['memTotal'].' МБ';
    }
    $data = $transport('GET',$c['base'].$path,[],null);
    if (($data['status'] ?? null) !== $expected) throw new Problem('Сервис не подтвердил готовность.');
    return $name.' · '.$expected;
}

function syncConnections(PDO $db, array $items, ?callable $transport = null): int {
    $failed = 0;
    foreach ($items as $c) {
        if (!$c['configured']) { fwrite(STDOUT,$c['id']."\tне настроено\n"); continue; }
        try { $summary = probe($c,$transport); $ok = 1; }
        catch (Problem $e) { $summary = $e->getMessage(); $ok = 0; ++$failed; }
        query($db,'INSERT INTO connection_results(connection_key,target_hash,ok,summary,checked_at) VALUES(?,?,?,?,?)
            ON CONFLICT(connection_key) DO UPDATE SET target_hash=excluded.target_hash,ok=excluded.ok,summary=excluded.summary,checked_at=excluded.checked_at',
            [$c['id'],$c['hash'],$ok,$summary,time()]);
        fwrite(STDOUT,$c['id']."\t".($ok?'OK':'ERROR')."\t".$summary."\n");
    }
    return $failed;
}

function connectionViews(PDO $db): array {
    $items = connections();
    $results = [];
    foreach ($db->query('SELECT * FROM connection_results') as $r) $results[$r['connection_key']] = $r;
    foreach ($items as $key=>&$c) {
        $snapshot = $results[$key] ?? null;
        if (!$snapshot || $snapshot['target_hash'] !== $c['hash'] || !$c['configured']) $snapshot = null;
        $c['snapshot'] = $snapshot;
        $c['status'] = match (true) {
            !$c['configured'] => 'Не настроено',
            $snapshot === null => 'Ожидает проверки',
            time() - (int)$snapshot['checked_at'] > 300 => 'Данные устарели',
            (bool)$snapshot['ok'] => 'Доступно',
            default => 'Ошибка',
        };
    }
    unset($c);
    return $items;
}
