<?php
declare(strict_types=1);
namespace Workspace;

use PDO;
use RuntimeException;
use Throwable;
require dirname(__DIR__).'/app/bootstrap.php';

function expectMigration(bool $condition,string $message): void {
    if (!$condition) throw new RuntimeException($message);
    ++$GLOBALS['checks'];
}
function failsMigration(callable $fn,string $message): void {
    try { $fn(); } catch (Throwable) { expectMigration(true,$message); return; }
    throw new RuntimeException('Unexpected success: '.$message);
}
function eraseTree(string $dir): void {
    foreach (new \FilesystemIterator($dir) as $item) {
        if ($item->isDir()&&!$item->isLink()) eraseTree($item->getPathname()); else unlink($item->getPathname());
    }
    rmdir($dir);
}
function seededLegacy(string $dir,int $version): array {
    mkdir($dir,0700);
    $db=new PDO('sqlite:'.$dir.'/workspace.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');
    for ($v=1;$v<=min(3,$version);$v++) $db->exec(file_get_contents(ROOT.'/database/'.sprintf('%03d',$v).'.sql'));
    if ($version>=3) for ($v=1;$v<=3;$v++) recordMigration($db,$v,migrationFiles(),'applied');
    $db->exec('PRAGMA user_version='.$version);
    $db->exec("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'test@example.test','Test','owner','test-hash')");
    $db->exec("INSERT INTO projects(id,title,kind) VALUES(1,'Existing','client')");
    $db->exec("INSERT INTO tasks(project_id,title,assignee_id) VALUES(1,'Task',1)");
    $db->exec("INSERT INTO cash_entries(project_id,direction,amount_minor,currency,booked_on,memo,actor_id) VALUES(1,'income',1250,'PLN','2026-01-01','Payment',1),(1,'expense',500,'EUR','2026-01-02','Cost',1)");
    $db->exec("INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'Contract','contract')");
    foreach ([1,2] as $revision) {
        $pdf="%PDF-1.4\nrevision $revision\n%%EOF\n";
        $q=$db->prepare('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(1,?,?,?,1)');
        $q->bindValue(1,$revision,PDO::PARAM_INT); $q->bindValue(2,hash('sha256',$pdf)); $q->bindValue(3,$pdf,PDO::PARAM_LOB); $q->execute();
    }
    $db->exec("INSERT INTO connection_results VALUES('engine','target',1,'ready',100)");
    if ($version>=2) $db->exec("INSERT INTO connection_checks(connection_key,target_hash,checked_at,ok,summary,transition) VALUES('engine','target',100,1,'ready','initial')");
    for ($v=4;$v<=$version;$v++) { $db->exec(file_get_contents(ROOT.'/database/'.sprintf('%03d',$v).'.sql')); recordMigration($db,$v,migrationFiles(),'applied'); }
    $expected=businessData($db);
    $db=null;
    return $expected;
}
function businessData(PDO $db): array {
    $data=[];
    foreach (['users','projects','tasks','cash_entries','documents','document_versions','connection_results'] as $table) $data[$table]=$db->query("SELECT ".(match($table) {'projects'=>'id,title,client,kind,created_at','tasks'=>'id,project_id,title,assignee_id,due_on,state,version','document_versions'=>'document_id,revision,sha256,bytes,actor_id,created_at',default=>'*'})." FROM $table ORDER BY rowid")->fetchAll(PDO::FETCH_ASSOC);
    return $data;
}
$root=sys_get_temp_dir().'/cws-migration-'.bin2hex(random_bytes(8));
mkdir($root,0700); $checks=0;
try {
    expectMigration(runtimeReport()['ok'],'Runtime requirements');
    foreach ([1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22] as $version) {
        $dir=$root.'/v'.$version; $expected=seededLegacy($dir,$version);
        putenv('WORKSPACE_DATA='.$dir);
        failsMigration(fn()=>database(),'Web refuses old schema');
        failsMigration(fn()=>database(true),'Init refuses old schema');
        $old=openDatabase();
        expectMigration(schemaVersion($old)===$version,'No implicit migration');
        $old=null;
        $existing=$dir.'/occupied.sqlite'; file_put_contents($existing,'do not overwrite');
        failsMigration(fn()=>database(false,true,$existing),'Backup collision blocks upgrade');
        expectMigration(file_get_contents($existing)==='do not overwrite','Backup target preserved');
        $old=openDatabase(); expectMigration(schemaVersion($old)===$version,'Failed backup leaves schema untouched'); $old=null;
        $backup=null;
        $db=database(false,true,null,static function($b) use (&$backup) {$backup=$b;});
        expectMigration($backup!==null && is_file($backup['path']) && hash_file('sha256',$backup['path'])===$backup['sha256'],'Snapshot exists and checksum matches');
        expectMigration(schemaVersion($db)===SCHEMA_VERSION && businessData($db)===$expected,'Migration preserves exact business data');
        expectMigration((int)$db->query('SELECT COUNT(*) FROM project_members')->fetchColumn()===1 && (int)$db->query('SELECT organization_id FROM projects')->fetchColumn()===1,'Legacy project membership preserved');
        expectMigration((int)$db->query('SELECT COUNT(*) FROM connection_checks')->fetchColumn()===1,'Connection history preserved');
        $history=$db->query('SELECT origin FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
        expectMigration($history===array_merge(array_fill(0,$version<3?$version:0,'legacy'),array_fill(0,SCHEMA_VERSION-($version<3?$version:0),'applied')),'Legacy origin correctly recorded');
        $snapshot=new PDO('sqlite:'.$backup['path']);
        expectMigration(schemaVersion($snapshot)===$version && businessData($snapshot)===$expected,'Restorable snapshot contains old schema and data');
        checkedDatabase($snapshot); $snapshot=null;
        $db=null;
        $called=false;
        $db=database(false,true,null,static function() use (&$called) {$called=true;});
        expectMigration(!$called,'Repeated upgrade does not create backup');
        $db->exec("UPDATE schema_migrations SET sha256=printf('%064d',0) WHERE version=1");
        $db=null;
        failsMigration(fn()=>database(),'Tampered ledger rejected');
        failsMigration(fn()=>database(false,true),'Tampered ledger cannot be upgraded');
    }
    // A migration-specific failure after a valid backup and schema validation.
    // An existing unrelated table collides with CREATE TABLE in 003, so SQL must roll back.
    $dir=$root.'/failure'; $expected=seededLegacy($dir,1); putenv('WORKSPACE_DATA='.$dir);
    $old=openDatabase(); $old->exec('CREATE TABLE schema_migrations (collision TEXT)'); $old=null;
    failsMigration(fn()=>database(false,true),'Migration error rolls back');
    $snapshots=glob($dir.'/pre-upgrade-v1-*.sqlite');
    expectMigration(count($snapshots)===1,'Failure preserves preliminary backup');
    $old=openDatabase();
    expectMigration(schemaVersion($old)===1 && businessData($old)===$expected,'Migration failure leaves business data');
    expectMigration(!$old->query("SELECT 1 FROM sqlite_master WHERE name='connection_checks'")->fetchColumn(),'Earlier migration rolled back too');
    $old=null;
    $snapshot=new PDO('sqlite:'.$snapshots[0]); checkedDatabase($snapshot);
    expectMigration(schemaVersion($snapshot)===1 && businessData($snapshot)===$expected,'Failure snapshot can be restored'); $snapshot=null;
    $dir=$root.'/fresh'; mkdir($dir,0700); putenv('WORKSPACE_DATA='.$dir);
    $db=database(true);
    expectMigration(schemaVersion($db)===SCHEMA_VERSION && $db->query('SELECT origin FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN)===array_fill(0,SCHEMA_VERSION,'applied'),'Clean install records all migrations');
    expectMigration(!glob($dir.'/pre-upgrade-*.sqlite'),'Fresh install needs no snapshot');
    $db->exec('PRAGMA user_version=99'); $db=null;
    failsMigration(fn()=>database(false,true),'Future schema rejected');
    echo "OK · $checks проверок миграций\n";
} finally {
    $db=$old=$snapshot=null;
    eraseTree($root);
}
