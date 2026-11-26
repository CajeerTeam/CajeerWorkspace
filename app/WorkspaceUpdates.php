<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const UPDATE_REPO='CajeerTeam/CajeerWorkspace';
const UPDATE_API='https://api.github.com/repos/'.UPDATE_REPO;
const UPDATE_META='workspace-release.json';

function updateAdmin(array $user): bool {
    // Installation-scoped grant, never inferred from tenant ownership alone.
    $ids=getenv('WORKSPACE_UPDATE_ADMIN_IDS')?:'';
    return ($user['role']??null)==='owner' && in_array((string)($user['id']??''),explode(',',$ids),true);
}
function requireUpdateAdmin(array $user): void {
    if (!updateAdmin($user)) throw new Problem('Требуются полномочия администратора установки.',403);
    if (!mfaFresh()) throw new Problem('Подтвердите второй фактор в разделе «Безопасность».',403);
}
function updateAssert(bool $ok,string $message): void {
    if (!$ok) throw new \RuntimeException($message);
}
function updateHex(mixed $v): bool { return is_string($v)&&preg_match('/\A[a-f0-9]{64}\z/',$v)===1; }
function updateVersion(mixed $v): bool { return is_string($v)&&preg_match('/\A(?:0|[1-9][0-9]{0,5})\.(?:0|[1-9][0-9]{0,5})\.(?:0|[1-9][0-9]{0,5})\z/',$v)===1; }
function updateKey(): array {
    $path=getenv('WORKSPACE_UPDATE_PUBLIC_KEY')?:'';$pin=getenv('WORKSPACE_UPDATE_KEY_SHA256')?:'';
    updateAssert(str_starts_with($path,'/') && updateHex($pin) && is_file($path) && !is_link($path) && is_readable($path),'Настройте доверенный публичный ключ и его SHA-256.');
    $pem=@file_get_contents($path,false,null,0,16385);
    updateAssert(is_string($pem)&&strlen($pem)<=16384&&hash_equals($pin,hash('sha256',$pem)),'Отпечаток доверенного ключа не совпадает.');
    $key=openssl_pkey_get_public($pem);
    updateAssert($key!==false,'Некорректный доверенный ключ.');
    $details=openssl_pkey_get_details($key);
    updateAssert(is_array($details)&&(($details['type']===OPENSSL_KEYTYPE_RSA&&$details['bits']>=2048)||($details['type']===OPENSSL_KEYTYPE_EC&&in_array($details['ec']['curve_name']??'', ['prime256v1','secp384r1','secp521r1'],true))),'Недопустимый алгоритм ключа.');
    return [$pem,$pin];
}
function updateJson(string $raw): array {
    $obj=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    updateAssert(is_array($obj),'Некорректный JSON ответа.');return $obj;
}
function updateUrlAllowed(string $url,bool $asset): bool {
    $p=parse_url($url);
    if (!is_array($p)||($p['scheme']??'')!=='https'||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||isset($p['port']))return false;
    if (!$asset)return ($p['host']??'')==='api.github.com'&&str_starts_with($p['path']??'','/repos/'.UPDATE_REPO.'/');
    if(($p['host']??'')==='github.com')return !isset($p['query'])&&preg_match('~\A/'.preg_quote(UPDATE_REPO,'~').'/releases/download/v[0-9]+\.[0-9]+\.[0-9]+/(?:workspace-release\.json(?:\.sig)?|CajeerWorkspace-[0-9]+\.[0-9]+\.[0-9]+\.zip(?:\.sig)?)\z~',$p['path']??'')===1;
    return in_array($p['host']??'', ['release-assets.githubusercontent.com','objects.githubusercontent.com'],true)&&!empty($p['path']);
}
function updateHttp(string $url,int $limit,bool $asset=false,?float $overallDeadline=null): string {
    updateAssert(function_exists('curl_init'),'Недоступен cURL.');
    $deadline=min(microtime(true)+12,$overallDeadline??PHP_FLOAT_MAX);
    for($i=0;$i<4;$i++){
        updateAssert(updateUrlAllowed($url,$asset),'Неразрешённый адрес GitHub.');
        $remaining=(int)floor(($deadline-microtime(true))*1000);updateAssert($remaining>0,'Тайм-аут GitHub.');
        $body='';$location=null;$ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_CONNECTTIMEOUT_MS=>min(3000,$remaining),CURLOPT_TIMEOUT_MS=>$remaining,CURLOPT_PROXY=>'',CURLOPT_USERAGENT=>'CajeerWorkspace/'.VERSION,
            CURLOPT_HTTPHEADER=>$asset?['Accept: application/octet-stream']:['Accept: application/vnd.github+json','X-GitHub-Api-Version: 2022-11-28'],
            CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$body,$limit){if(strlen($body)+strlen($chunk)>$limit)return 0;$body.=$chunk;return strlen($chunk);},
            CURLOPT_HEADERFUNCTION=>static function($ch,string $line)use(&$location){if(stripos($line,'Location:')===0)$location=trim(substr($line,9));return strlen($line);}]);
        try{$ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);}finally{curl_close($ch);}
        updateAssert($ok!==false,'Ошибка TLS, сети или лимита ответа GitHub.');
        if($asset&&in_array($status,[301,302,303,307,308],true)&&is_string($location)){$url=$location;continue;}
        updateAssert($status===200,'GitHub HTTP '.$status.' (проверьте доступность и лимит запросов).');return $body;
    }
    throw new \RuntimeException('Превышен лимит перенаправлений GitHub.');
}
function updateFields(mixed $obj,array $fields): bool {
    if(!is_array($obj)||count($obj)!==count($fields))return false;
    foreach($fields as $f)if(!array_key_exists($f,$obj))return false;return true;
}
function updateCanonical(array $obj): string {
    $normalize=static function(mixed $v)use(&$normalize):mixed {
        if(!is_array($v))return $v;
        if(!array_is_list($v))ksort($v,SORT_STRING);
        foreach($v as $k=>$item)$v[$k]=$normalize($item);
        return $v;
    };
    return json_encode($normalize($obj),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
}
function updateManifest(string $raw,string $signature,string $pem,string $pin,string $version,string $commit): array {
    updateAssert(strlen($raw)<=65536&&strlen($signature)>0&&strlen($signature)<=16384&&hash_equals($pin,hash('sha256',$pem))&&openssl_verify($raw,$signature,$pem,OPENSSL_ALGO_SHA256)===1,'Подпись метаданных не подтверждена.');
    $m=updateJson($raw);
    updateAssert(hash_equals(updateCanonical($m),$raw),'Неканонический JSON метаданных или повторяющиеся поля.');
    updateAssert(updateFields($m,['format','repository','channel','version','tag','commit','schema','supported_from','runtime','package','matrix_sha256','acceptance_sha256','release_key_sha256','accepted_at']),'Неподдерживаемый формат метаданных.');
    updateAssert($m['format']===1&&$m['repository']===UPDATE_REPO&&$m['channel']==='stable'&&updateVersion($m['version'])&&$m['version']===$version&&$m['tag']==='v'.$version&&$m['commit']===$commit&&preg_match('/\A[a-f0-9]{40}\z/',$commit)===1&&$m['release_key_sha256']===$pin,'Метаданные не соответствуют репозиторию, тегу или ключу.');
    updateAssert(is_int($m['schema'])&&$m['schema']>=1&&$m['schema']<=999&&updateHex($m['matrix_sha256'])&&updateHex($m['acceptance_sha256'])&&is_string($m['accepted_at'])&&preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/',$m['accepted_at'])===1&&strtotime($m['accepted_at'])!==false,'Некорректная схема или подтверждение допуска.');
    $p=$m['package'];updateAssert(updateFields($p,['name','size','sha256','signature_sha256'])&&$p['name']==='CajeerWorkspace-'.$version.'.zip'&&is_int($p['size'])&&$p['size']>0&&$p['size']<=134217728&&updateHex($p['sha256'])&&updateHex($p['signature_sha256']),'Некорректное описание пакета.');
    $r=$m['runtime'];updateAssert(updateFields($r,['php_min','php_max_exclusive','architecture','sqlite_min','postgres_min','python_min','extensions']),'Некорректные требования среды.');
    foreach(['php_min','php_max_exclusive','sqlite_min','postgres_min','python_min'] as $f)updateAssert(is_string($r[$f])&&preg_match('/\A[0-9]{1,3}(?:\.[0-9]{1,3}){0,2}\z/',$r[$f])===1,'Некорректная версия среды.');
    updateAssert($r['architecture']===64&&$r['extensions']===['pdo','curl','session','openssl']&&version_compare($r['php_min'],$r['php_max_exclusive'],'<'),'Неподдерживаемые требования среды.');
    updateAssert(is_array($m['supported_from'])&&array_is_list($m['supported_from'])&&count($m['supported_from'])>0&&count($m['supported_from'])<=32,'Некорректный список исходных версий.');
    $seen=[];foreach($m['supported_from'] as $s){updateAssert(updateFields($s,['version','schema','archive_sha256'])&&updateVersion($s['version'])&&version_compare($s['version'],$version,'<')&&is_int($s['schema'])&&$s['schema']>=1&&$s['schema']<=$m['schema']&&updateHex($s['archive_sha256'])&&!isset($seen[$s['version']]),'Некорректная исходная версия.');$seen[$s['version']]=true;}
    return $m;
}
function updateEnvironment(PDO $db): array {
    $backend=isPostgres($db)?'pgsql':'sqlite';
    $v=(string)$db->query($backend==='pgsql'?'SHOW server_version':'SELECT sqlite_version()')->fetchColumn();
    preg_match('/\A[0-9]+(?:\.[0-9]+){0,2}/',$v,$match);
    $extensions=[];foreach(['pdo','curl','session','openssl','pdo_sqlite','pdo_pgsql'] as $e)if(extension_loaded($e))$extensions[]=$e;
    return ['version'=>VERSION,'schema'=>schemaVersion($db),'php'=>PHP_VERSION,'architecture'=>PHP_INT_SIZE*8,'backend'=>$backend,'database'=>$match[0]??'', 'extensions'=>$extensions,'runtime_ok'=>runtimeReport()['ok']];
}
function updateCompatibility(array $m,array $env): array {
    $reasons=[];$r=$m['runtime'];$from=null;
    if(version_compare($m['version'],$env['version'],'<='))return ['status'=>$m['version']===$env['version']?'current':'ahead','reasons'=>['Новая версия отсутствует.']];
    foreach($m['supported_from'] as $s)if($s['version']===$env['version']&&$s['schema']===$env['schema'])$from=$s;
    if(!$from)$reasons[]='Нет поддерживаемого перехода с текущей версии и схемы.';
    if($env['schema']>$m['schema'])$reasons[]='Целевая схема ниже установленной.';
    if(version_compare($env['php'],$r['php_min'],'<')||version_compare($env['php'],$r['php_max_exclusive'],'>='))$reasons[]='Версия PHP вне допустимого диапазона.';
    if($env['architecture']!==$r['architecture'])$reasons[]='Несовместимая архитектура PHP.';
    $driver=match($env['backend']){'sqlite'=>'pdo_sqlite','pgsql'=>'pdo_pgsql',default=>null};
    if(!$driver||array_diff([...$r['extensions'],$driver],$env['extensions']))$reasons[]='Отсутствуют необходимые расширения PHP/драйвер БД.';
    if(!$driver||$env['database']===''||version_compare($env['database'],$r[$env['backend']==='pgsql'?'postgres_min':'sqlite_min'],'<'))$reasons[]='Версия СУБД не подтверждена или недостаточна.';
    if(!$env['runtime_ok'])$reasons[]='Текущая диагностика PHP не пройдена.';
    return ['status'=>$reasons?'incompatible':'compatible','reasons'=>$reasons?:['Версия, схема и текущая PHP/БД среда соответствуют подписанным требованиям.','Хеш исходного пакета, Python и эксплуатационные условия проверяются перед установкой.']];
}
function updateAsset(array $row,string $name,int $limit): array {
    $matches=array_values(array_filter($row['assets']??[],static fn($a)=>is_array($a)&&($a['name']??null)===$name));
    updateAssert(count($matches)===1,'Отсутствующий или неоднозначный asset: '.$name);$a=$matches[0];
    updateAssert(($a['state']??null)==='uploaded'&&is_int($a['size']??null)&&$a['size']>0&&$a['size']<=$limit&&is_string($a['digest']??null)&&preg_match('/\Asha256:[a-f0-9]{64}\z/',$a['digest'])===1,'Некорректный asset: '.$name);return $a;
}
function updateDiscover(array $env,string $pem,string $pin,?callable $http=null,?callable $capture=null): array {
    $deadline=microtime(true)+22;
    $http??=static fn($url,$limit,$asset)=>updateHttp($url,$limit,$asset,$deadline);$best=null;$version=null;$seen=[];
    for($page=1;$page<=3;$page++){
        $rows=updateJson($http(UPDATE_API.'/releases?per_page=100&page='.$page,2097152,false));updateAssert(array_is_list($rows),'Некорректный список релизов.');
        foreach($rows as $row){if(!is_array($row)||($row['draft']??null)!==false||($row['prerelease']??null)!==false)continue;$tag=$row['tag_name']??null;
            if(!is_string($tag)||!str_starts_with($tag,'v')||!updateVersion(substr($tag,1)))continue;$v=substr($tag,1);
            updateAssert(!isset($seen[$v]),'Неоднозначный стабильный выпуск.');$seen[$v]=true;
            if($version===null||version_compare($v,$version,'>')){$best=$row;$version=$v;}
        }
        if(count($rows)<100)break;updateAssert($page<3,'Список релизов превышает предел проверки.');
    }
    if(!$best)return ['status'=>'empty','reasons'=>['Нет опубликованных стабильных выпусков.']];
    $name='CajeerWorkspace-'.$version.'.zip';$tag='v'.$version;
    $ref=updateJson($http(UPDATE_API.'/git/ref/tags/'.$tag,65536,false));$commit=$ref['object']['sha']??'';
    updateAssert(($ref['ref']??null)==='refs/tags/'.$tag&&($ref['object']['type']??null)==='commit'&&is_string($commit)&&preg_match('/\A[a-f0-9]{40}\z/',$commit)===1,'Тег не указывает непосредственно на коммит.');
    $base='https://github.com/'.UPDATE_REPO.'/releases/download/'.$tag.'/';
    $download=static function(string $n,int $limit)use($best,$http,$base){$a=updateAsset($best,$n,$limit);$b=$http($base.$n,$limit,true);updateAssert(strlen($b)===$a['size']&&hash_equals($a['digest'],'sha256:'.hash('sha256',$b)),'Изменённый asset: '.$n);return $b;};
    $raw=$download(UPDATE_META,65536);$signature=$download(UPDATE_META.'.sig',16384);
    $m=updateManifest($raw,$signature,$pem,$pin,$version,$commit);
    $p=updateAsset($best,$name,134217728);$s=updateAsset($best,$name.'.sig',16384);
    updateAssert($p['size']===$m['package']['size']&&hash_equals($p['digest'],'sha256:'.$m['package']['sha256'])&&hash_equals($s['digest'],'sha256:'.$m['package']['signature_sha256']),'Пакет или подпись не соответствуют подписанным метаданным.');
    if($capture!==null)$capture($raw,$signature,$s['size']);
    return updateCompatibility($m,$env)+['release'=>$m];
}
// FPM reads only the bounded status document; it cannot start the downloader.
function updateStateDirectory(): string {
    $dir=getenv('WORKSPACE_UPDATE_STATE_DIR')?:'';
    updateAssert($dir!==''&&str_starts_with($dir,'/')&&realpath($dir)===$dir&&is_dir($dir)&&!is_link($dir),'Настройте отдельный абсолютный WORKSPACE_UPDATE_STATE_DIR.');
    $mode=fileperms($dir);updateAssert($mode!==false&&($mode&0027)===0,'Каталог обновлений не должен разрешать запись группе или доступ остальным.');
    $root=realpath(ROOT);$data=realpath(getenv('WORKSPACE_DATA')?:ROOT.'/var');
    updateAssert($root!==false&&$dir!==$root&&!str_starts_with($dir,$root.'/')&&!str_starts_with($root,$dir.'/')&&($data===false||($dir!==$data&&!str_starts_with($dir,$data.'/')&&!str_starts_with($data,$dir.'/'))),'Каталог обновлений должен быть отдельным от дерева приложения и данных.');
    return $dir;
}
function updateStoredReport(int $schema): ?array {
    try {
        $dir=updateStateDirectory();$path=$dir.'/report.json';clearstatcache(true,$path);
        if(!is_file($path)||is_link($path)||($mode=fileperms($path))===false||($mode&0027)!==0)return null;
        $raw=@file_get_contents($path,false,null,0,131073);if(!is_string($raw)||strlen($raw)>131072)return null;
        $r=updateJson($raw);$pin=getenv('WORKSPACE_UPDATE_KEY_SHA256')?:'';
        if(($r['installed_version']??null)!==VERSION||($r['installed_schema']??null)!==$schema||($r['trust_pin']??null)!==$pin)return null;
        try{updateKey();}catch(\Throwable){if(($r['status']??null)!=='failed'||isset($r['release'])||isset($r['package_result']))return null;}
        $time=strtotime(is_string($r['checked_at']??null)?$r['checked_at']:'');
        if($time===false||$time>time()+60||$time<time()-86400)return null;
        if(!is_string($r['status']??null)||!is_array($r['reasons']??null)||!in_array($r['operation']??null,['check','download','plan','install'],true))return null;
        return $r;
    } catch(\Throwable){return null;}
}
