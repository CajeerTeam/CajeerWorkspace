<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';

// Run only against a disposable database (a dedicated CI matrix service for PostgreSQL).
function throttleCheck(bool $ok,string $label): void {
    if (!$ok) throw new \RuntimeException($label);
    $GLOBALS['throttleChecks']++;
}
function throttleReject(callable $call): void {
    try { $call(); } catch (Problem $e) { throttleCheck($e->status===429,'expected 429'); return; }
    throw new \RuntimeException('Expected rate limit rejection');
}
function throttleRow(\PDO $db,string $key): array {
    $row=query($db,'SELECT started,attempts FROM login_attempts WHERE bucket=?',[$key])->fetch();
    return $row?['started'=>(int)$row['started'],'attempts'=>(int)$row['attempts']]:[];
}
function throttleErase(string $dir): void {
    foreach (new \FilesystemIterator($dir) as $f) {
        if ($f->isDir()&&!$f->isLink()) throttleErase($f->getPathname()); else unlink($f->getPathname());
    }
    rmdir($dir);
}

// Each worker has its own PDO connection and transaction. The parent releases them together.
if (in_array('--worker',$argv,true)) {
    if (fgets(STDIN)===false) exit(2);
    $db=database();
    try { authThrottle($db,'parallel',8); echo "accepted\n"; }
    catch (Problem $e) { if ($e->status!==429) throw $e; echo "limited\n"; }
    exit(0);
}
$pg=in_array('--postgres',$argv,true);
$dir=sys_get_temp_dir().'/cw-throttle-'.bin2hex(random_bytes(8));
if (!mkdir($dir,0700)) throw new \RuntimeException('Cannot create test directory');
putenv('WORKSPACE_DATA='.$dir); putenv('WORKSPACE_DB='.($pg?'pgsql':'sqlite'));
$throttleChecks=0; $workers=[];
try {
    if ($pg) { $db=postgresConnect(); postgresEmpty($db); migratePostgres($db,true); }
    else $db=database(true);
    $bucket=hash('sha256','auth:ai-draft-test');
    authThrottle($db,'ai-draft-test',3); $first=throttleRow($db,$bucket);
    throttleCheck($first['attempts']===1,'first request');
    authThrottle($db,'ai-draft-test',3); $second=throttleRow($db,$bucket);
    throttleCheck($second===['started'=>$first['started'],'attempts'=>2],'conflict increments existing row without extending window');
    authThrottle($db,'ai-draft-test',3);
    throttleReject(fn()=>authThrottle($db,'ai-draft-test',3));
    throttleCheck(throttleRow($db,$bucket)['attempts']===3,'blocked request leaves count unchanged');
    query($db,'UPDATE login_attempts SET started=? WHERE bucket=?',[time()-601,$bucket]);
    authThrottle($db,'ai-draft-test',3);
    throttleCheck(throttleRow($db,$bucket)['attempts']===1,'expired window restarts');

    addUser($db,'owner@example.test','Owner','owner','sample-password-only',true);
    $email=hash('sha256','email:owner@example.test'); $ip=hash('sha256','ip:127.0.0.8');
    for ($i=1;$i<=2;$i++) {
        throttleCheck(authenticate($db,'OWNER@example.test','incorrect','127.0.0.8')===null,'failed login');
        throttleCheck(throttleRow($db,$email)['attempts']===$i&&throttleRow($db,$ip)['attempts']===$i,'both login counters increment');
    }
    throttleCheck(authenticate($db,'owner@example.test','sample-password-only','127.0.0.8')!==null,'successful login');
    throttleCheck(throttleRow($db,$email)['attempts']===2&&throttleRow($db,$ip)['attempts']===2,'successful login refunds its own attempt');
    query($db,'UPDATE login_attempts SET attempts=80 WHERE bucket=?',[$ip]);
    throttleReject(fn()=>authenticate($db,'other@example.test','incorrect','127.0.0.8'));
    throttleCheck(throttleRow($db,hash('sha256','email:other@example.test'))===[],'IP rejection rolls back earlier email increment');
    query($db,'UPDATE login_attempts SET attempts=10 WHERE bucket=?',[$email]);
    throttleReject(fn()=>authenticate($db,'owner@example.test','sample-password-only','127.0.0.9'));
    throttleCheck(throttleRow($db,hash('sha256','ip:127.0.0.9'))===[],'email rejection leaves other IP untouched');

    for ($i=0;$i<12;$i++) {
        $proc=proc_open([PHP_BINARY,__FILE__,'--worker'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
        if (!is_resource($proc)) throw new \RuntimeException('Cannot launch concurrent worker');
        $workers[]=[$proc,$pipes];
    }
    foreach ($workers as [$proc,$pipes]) { fwrite($pipes[0],"start\n"); fclose($pipes[0]); }
    $results=['accepted'=>0,'limited'=>0];
    foreach ($workers as $i=>[$proc,$pipes]) {
        $out=trim(stream_get_contents($pipes[1])); $err=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $status=proc_close($proc); unset($workers[$i]);
        throttleCheck($status===0&&isset($results[$out]),'concurrent worker: '.$err);
        $results[$out]++;
    }
    throttleCheck($results===['accepted'=>8,'limited'=>4],'exactly eight accepted under contention');
    throttleCheck(throttleRow($db,hash('sha256','auth:parallel'))['attempts']===8,'no lost increments or limit bypass');
    echo "OK · $throttleChecks auth throttle checks (".($pg?'PostgreSQL':'SQLite').")\n";
} finally {
    foreach ($workers as [$proc,$pipes]) {
        proc_terminate($proc); foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_close($proc);
    }
    $db=null; throttleErase($dir);
}
