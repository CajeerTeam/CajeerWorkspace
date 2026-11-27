<?php
declare(strict_types=1);

namespace Workspace;

use PDO;
use Throwable;

// The same checks run in CLI and FPM. Doctor does not need an installed database.
function runtimeReport(): array {
    $checks = [
        'php'=>['ok'=>PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 90000,'actual'=>PHP_VERSION,'required'=>'>=8.3 <9.0'],
        'architecture'=>['ok'=>PHP_INT_SIZE === 8,'actual'=>PHP_INT_SIZE*8,'required'=>64],
    ];
    foreach (['pdo',postgresSelected()?'pdo_pgsql':'pdo_sqlite','curl','session','openssl'] as $ext) {
        $checks[$ext]=['ok'=>extension_loaded($ext),'actual'=>extension_loaded($ext)?(phpversion($ext) ?: 'loaded'):'missing','required'=>'loaded'];
    }
    $sqlite=null;
    if ($checks['pdo']['ok'] && ($checks['pdo_sqlite']['ok']??false)) {
        try { $sqlite=(new PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn(); } catch (Throwable) {}
    }
    if (!postgresSelected()) $checks['sqlite_version']=['ok'=>is_string($sqlite)&&version_compare($sqlite,'3.27.0','>='),'actual'=>$sqlite,'required'=>'>=3.27.0'];
    $tls=false;
    if ($checks['curl']['ok'] && function_exists('curl_version') && function_exists('curl_init')) {
        try { $tls=in_array('https',curl_version()['protocols']??[],true); } catch (Throwable) {}
    }
    $checks['curl_https']=['ok'=>$tls,'actual'=>$tls?'supported':'unavailable','required'=>'HTTPS'];
    $checks['session_api']=['ok'=>function_exists('session_start') && function_exists('session_regenerate_id'),'actual'=>function_exists('session_start')?'available':'unavailable','required'=>'session API'];
    return ['version'=>VERSION,'sapi'=>PHP_SAPI,'ok'=>!in_array(false,array_column($checks,'ok'),true),'checks'=>$checks];
}

function requireRuntime(): void {
    static $report=null;
    $report ??= runtimeReport();
    if (!$report['ok']) {
        $failed=array_keys(array_filter($report['checks'],static fn($check)=>!$check['ok']));
        throw new Problem('Не выполнены требования PHP: '.implode(', ',$failed).'. Выполните php bin/workspace doctor.',503);
    }
}

function schemaVersion(PDO $db): int {
    if (isPostgres($db)) {
        if (!(int)$db->query("SELECT CASE WHEN to_regclass('public.schema_migrations') IS NULL THEN 0 ELSE 1 END")->fetchColumn()) return 0;
        return (int)$db->query('SELECT COALESCE(MAX(version),0) FROM schema_migrations')->fetchColumn();
    }
    return (int)$db->query('PRAGMA user_version')->fetchColumn();
}

function openDatabase(bool $install=false): PDO {
    updateWriterLease();
    requireRuntime();
    if (postgresSelected()) return postgresConnect();
    if (getenv('WORKSPACE_DB') && getenv('WORKSPACE_DB')!=='sqlite') throw new Problem('Неизвестный WORKSPACE_DB.',503);
    $file=dataDir().'/workspace.sqlite';
    if (!$install && !is_file($file)) throw new Problem('Рабочее пространство ещё не установлено. Выполните bin/workspace init.',503);
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
    // PHP 8.4+ exposes driver-specific methods on Pdo\Sqlite.
    $db=method_exists(PDO::class,'connect') ? PDO::connect('sqlite:'.$file,null,null,$options) : new PDO('sqlite:'.$file,null,null,$options);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('PRAGMA foreign_keys=ON');
    return $db;
}

/** Read a contiguous, immutable series of shipped SQL migrations. */
function migrationFiles(?PDO $db=null): array {
    $files=[];
    foreach (glob(ROOT.'/database/'.($db && isPostgres($db)?'postgresql/':'').'*.sql') ?: [] as $path) {
        if (!preg_match('/\A([0-9]{3})\.sql\z/',basename($path),$m) || !is_file($path)) throw new Problem('Некорректное имя миграции.',503);
        $version=(int)$m[1];
        $sql=file_get_contents($path);
        if ($version<1 || $sql===false || trim($sql)==='') throw new Problem('Некорректный файл миграции.',503);
        $files[$version]=['sql'=>$sql,'sha256'=>hash('sha256',$sql)];
    }
    ksort($files,SORT_NUMERIC);
    if (array_keys($files)!==range(1,SCHEMA_VERSION)) throw new Problem('Набор миграций не соответствует версии схемы.',503);
    // The candidate freezes both dialects independently of the mutable DB ledger.
    $rawFreeze=@file_get_contents(ROOT.'/database/schema-freeze.json');
    if ($rawFreeze===false || !hash_equals('90ddfa79f4148a79e37eab9720a233e5dfcad111482f954e47d1e30a1e0987a4',hash('sha256',$rawFreeze))) throw new Problem('Контрольная сумма заморозки схемы не совпадает.',503);
    $freeze=json_decode($rawFreeze,true);
    if (!is_array($freeze) || ($freeze['schema']??null)!==SCHEMA_VERSION || !is_array($freeze['migrations']??null) || count($freeze['migrations'])!==2*SCHEMA_VERSION) throw new Problem('Нарушена заморозка схемы.',503);
    $prefix='database/'.($db && isPostgres($db)?'postgresql/':'');
    foreach ($files as $v=>$file) {
        $expected=$freeze['migrations'][$prefix.sprintf('%03d',$v).'.sql']??null;
        if (!is_string($expected)||!hash_equals($expected,$file['sha256'])) throw new Problem('Изменена замороженная миграция '.$v.'.',503);
    }
    return $files;
}

function validateLedger(PDO $db, int $version, array $files): void {
    if ($version<3) return;
    $rows=$db->query('SELECT version,sha256 FROM schema_migrations ORDER BY version')->fetchAll();
    if (count($rows)!==$version) throw new Problem('Журнал миграций неполон.',503);
    foreach ($rows as $i=>$row) {
        $v=$i+1;
        if ((int)$row['version']!==$v || !isset($files[$v]) || !hash_equals($files[$v]['sha256'],$row['sha256'])) {
            throw new Problem('Контрольная сумма миграции '.$v.' не совпадает.',503);
        }
    }
}

// For pre-ledger databases verify the shipped schema, not just the user_version marker.
function validateLegacy(PDO $db,int $version,array $files): void {
    $reference=new PDO('sqlite::memory:');
    for ($v=1;$v<=$version;$v++) $reference->exec($files[$v]['sql']);
    $sql="SELECT type,name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' AND sql IS NOT NULL";
    $actual=[];
    foreach ($db->query($sql) as $row) $actual[$row['name']]=[$row['type'],$row['sql']];
    foreach ($reference->query($sql) as $row) {
        if (($actual[$row['name']]??null)!==[$row['type'],$row['sql']]) throw new Problem('Структура старой схемы отличается: '.$row['name'].'.',503);
    }
}

function recordMigration(PDO $db,int $version,array $files,string $origin): void {
    query($db,'INSERT INTO schema_migrations(version,sha256,origin) VALUES(?,?,?)',[$version,$files[$version]['sha256'],$origin]);
}

function checkedDatabase(PDO $db): void {
    $rows=$db->query('PRAGMA quick_check')->fetchAll(PDO::FETCH_COLUMN);
    if ($rows!==['ok'] || $db->query('PRAGMA foreign_key_check')->fetch()!==false) throw new Problem('Проверка целостности базы не пройдена.',503);
}

function backupTarget(string $path): string {
    if (!str_starts_with($path,DIRECTORY_SEPARATOR)) throw new Problem('Укажите абсолютный путь для новой копии.');
    $dir=realpath(dirname($path));
    $public=realpath(ROOT.'/public');
    if ($dir===false || !is_dir($dir) || $public===false || $dir===$public || str_starts_with($dir,$public.DIRECTORY_SEPARATOR)) throw new Problem('Выберите существующий каталог вне public.');
    $target=$dir.DIRECTORY_SEPARATOR.basename($path);
    if (file_exists($target) || is_link($target)) throw new Problem('Файл назначения уже существует.');
    return $target;
}

/** Consistent standalone copy; publish only after validation, never overwrite. */
function snapshotDatabase(PDO $source,string $path): array {
    if (isPostgres($source)) throw new Problem('Для PostgreSQL используйте pg_dump и pg_restore согласно docs/ORGANIZATIONS.md.');
    $target=backupTarget($path);
    $dir=dirname($target);
    $tmp=tempnam($dir,'.cws-backup-');
    if ($tmp===false) throw new Problem('Не удалось создать временный файл копии.');
    try {
        if (dirname($tmp)!==$dir || !chmod($tmp,0600)) throw new Problem('Не удалось защитить временный файл копии.');
        $source->exec('VACUUM INTO '.$source->quote($tmp));
        $copy=new PDO('sqlite:'.$tmp,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        checkedDatabase($copy);
        $version=schemaVersion($copy);
        $copy=null;
        // VACUUM INTO on older SQLite does not guarantee a durable output file.
        $handle=fopen($tmp,'rb');
        if ($handle===false) throw new Problem('Не удалось открыть копию для синхронизации.');
        try { if (!fsync($handle)) throw new Problem('Не удалось синхронизировать копию на диске.'); }
        finally { fclose($handle); }
        $digest=hash_file('sha256',$tmp);
        if ($digest===false || !@link($tmp,$target)) throw new Problem('Не удалось сохранить копию под выбранным именем.');
        // On Unix also persist the directory entry before changing the source.
        if (PHP_OS_FAMILY !== 'Windows') {
            $directory=@fopen($dir,'r');
            if ($directory===false) throw new Problem('Копия сохранена, но каталог не удалось открыть для синхронизации.');
            try { if (!@fsync($directory)) throw new Problem('Копия сохранена, но синхронизация каталога не удалась.'); }
            finally { fclose($directory); }
        }
        return ['path'=>$target,'schema'=>$version,'sha256'=>$digest];
    } finally { @unlink($tmp); }
}

function migrateDatabase(PDO $db,bool $install=false,?string $backupPath=null,?callable $onBackup=null): void {
    $files=migrationFiles();
    // BEGIN IMMEDIATE serializes writers and competing migrations. A separate reader
    // makes the snapshot while this lock is held, before any schema or data changes.
    atomic($db,static function () use ($db,$install,$backupPath,$onBackup,$files) {
        $start=schemaVersion($db);
        if ($start<0 || $start>SCHEMA_VERSION || ($start===0 && !$install)) throw new Problem('Версия схемы данных несовместима с этим выпуском.',503);
        if ($start===0 && $db->query("SELECT 1 FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' LIMIT 1")->fetchColumn()) throw new Problem('Непустая база без версии схемы.',503);
        if ($start>=3) validateLedger($db,$start,$files);
        if ($start===SCHEMA_VERSION) return;
        if ($start>0) {
            $path=$backupPath ?? dataDir().'/pre-upgrade-v'.$start.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6)).'.sqlite';
            $reader=openDatabase();
            $backup=snapshotDatabase($reader,$path);
            $reader=null;
            if ($onBackup!==null) $onBackup($backup);
            if ($backup['schema']!==$start) throw new Problem('Версия снимка отличается от исходной схемы.',503);
            if ($start<3) validateLegacy($db,$start,$files);
        }
        for ($v=$start+1;$v<=SCHEMA_VERSION;$v++) {
            try {
                $db->exec($files[$v]['sql']);
                if ($v===3) {
                    for ($previous=1;$previous<3;$previous++) recordMigration($db,$previous,$files,$previous<=$start?'legacy':'applied');
                }
                if ($v>=3) recordMigration($db,$v,$files,'applied');
                $db->exec('PRAGMA user_version='.$v);
            } catch (Throwable $e) {
                throw new Problem('Миграция '.sprintf('%03d',$v).' не выполнена; транзакция будет отменена. Проверьте резервную копию и пакет.',503);
            }
        }
        validateLedger($db,SCHEMA_VERSION,$files);
        checkedDatabase($db);
    });
}
