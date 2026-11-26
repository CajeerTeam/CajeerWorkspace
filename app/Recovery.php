<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

// Maintenance operations are deliberately CLI-only. Offline confirmation is an
// operator assertion, not a distributed lock against independent processes.
function recoveryOffline(): void {
    if(PHP_SAPI!=='cli'||getenv('WORKSPACE_RECOVERY_OFFLINE')!=='1')throw new Problem('Остановите FPM, cron и worker; подтвердите WORKSPACE_RECOVERY_OFFLINE=1.',409);
}
function recoveryMfaCheck(PDO $db): int {
    authKey(); $n=0;
    foreach($db->query('SELECT user_id,secret_box FROM mfa_credentials ORDER BY user_id') as $r){openMfa($r['secret_box'],(int)$r['user_id']);++$n;}
    return $n;
}
function recoveryMfaRotate(PDO $db,string $newKey): array {
    recoveryOffline(); $old=getenv('WORKSPACE_MFA_KEY'); authKey();
    if(!preg_match('/\A[a-fA-F0-9]{64}\z/',$newKey)||hash_equals(strtolower((string)$old),strtolower($newKey)))throw new Problem('Новый ключ должен содержать 32 случайных байта в hex и отличаться от старого.');
    // Keep the old process environment even if commit fails. The operator must
    // switch the service secret only after checking the committed database.
    return atomic($db,function()use($db,$old,$newKey){
        $count=0;
        foreach(query($db,'SELECT user_id,secret_box FROM mfa_credentials ORDER BY user_id')->fetchAll() as $row){
            $uid=(int)$row['user_id'];$plain=openMfa($row['secret_box'],$uid);
            try{putenv('WORKSPACE_MFA_KEY='.$newKey);$box=sealMfa($plain,$uid);if(!hash_equals($plain,openMfa($box,$uid)))throw new Problem('Не пройдена проверка перешифрования.',503);}
            finally{putenv('WORKSPACE_MFA_KEY='.$old);if(function_exists('sodium_memzero'))sodium_memzero($plain);}
            query($db,'UPDATE mfa_credentials SET secret_box=? WHERE user_id=?',[$box,$uid]);++$count;
        }
        $staff=query($db,'UPDATE users SET session_version=session_version+1')->rowCount();
        $oidc=query($db,'UPDATE oidc_sessions SET revoked_at=? WHERE revoked_at IS NULL',[time()])->rowCount();
        return ['credentials'=>$count,'staff_sessions_invalidated'=>$staff,'oidc_sessions_revoked'=>$oidc];
    });
}
// Logical snapshot for the PostgreSQL backup/restore verifier. No row payloads
// or credentials leave the process. transferRows checks PDF and receipt hashes.
function recoveryPgInspect(PDO $db): array {
    recoveryOffline();if(!isPostgres($db))throw new Problem('Требуется PostgreSQL.');
    return atomic($db,function()use($db){
        validateLedger($db,SCHEMA_VERSION,migrationFiles($db));
        $tables=TRANSFER_TABLES+['schema_migrations'=>'version'];
        $actual=$db->query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
        $expected=array_keys($tables);sort($expected,SORT_STRING);sort($actual,SORT_STRING);if($actual!==$expected)throw new Problem('Неизвестный состав таблиц.',503);
        if($db->query("SELECT 1 FROM pg_constraint WHERE connamespace='public'::regnamespace AND NOT convalidated LIMIT 1")->fetchColumn())throw new Problem('Непроверенные ограничения БД.',503);
        $digests=[];$objects=[];
        foreach($tables as $table=>$order){$h=hash_init('sha256');$n=0;foreach(transferRows($db,$table,$order) as $r){rowDigest($h,$r);++$n;if($table==='document_versions'&&$r['storage_state']==='object')$objects[]=['key'=>$r['object_key'],'size'=>(int)$r['original_size'],'sha256'=>$r['sha256']];if($table==='signing_archive'&&$r['storage_state']==='object')$objects[]=['key'=>$r['object_key'],'size'=>(int)$r['pdf_size'],'sha256'=>$r['pdf_sha256']];}$digests[$table]=['rows'=>$n,'sha256'=>hash_final($h)];}
        $cash=query($db,"SELECT p.organization_id,c.currency,SUM(CASE WHEN c.direction='income' THEN c.amount_minor ELSE -c.amount_minor END) AS balance FROM cash_entries c JOIN projects p ON p.id=c.project_id GROUP BY p.organization_id,c.currency ORDER BY p.organization_id,c.currency")->fetchAll();
        $ledger=query($db,"SELECT organization_id,currency,account_id,SUM(amount) AS balance FROM (SELECT organization_id,currency,debit_id AS account_id,amount_minor AS amount FROM accounting_entries UNION ALL SELECT organization_id,currency,credit_id AS account_id,-amount_minor AS amount FROM accounting_entries) x GROUP BY organization_id,currency,account_id ORDER BY organization_id,currency,account_id")->fetchAll();
        // Sequence state is part of recovery: do not reuse issued identifiers.
        $seq=[];foreach($db->query("SELECT sequencename FROM pg_sequences WHERE schemaname='public' ORDER BY sequencename")->fetchAll(PDO::FETCH_COLUMN) as $name){if(!preg_match('/\A[a-z_0-9]+\z/',$name))throw new Problem('Неизвестная последовательность.');$seq[$name]=$db->query('SELECT last_value,is_called FROM public."'.$name.'"')->fetch();}
        recoveryScopeCheck($db);
        usort($objects,fn($a,$b)=>strcmp($a['key'],$b['key']));ksort($digests);
        return ['schema'=>SCHEMA_VERSION,'tables'=>$digests,'objects'=>$objects,'cash'=>$cash,'ledger'=>$ledger,'sequences'=>$seq];
    });
}
function recoveryScopeCheck(PDO $db): void {
    foreach([
        'SELECT 1 FROM project_members m LEFT JOIN projects p ON p.id=m.project_id AND p.organization_id=m.organization_id LEFT JOIN organization_members o ON o.organization_id=m.organization_id AND o.user_id=m.user_id WHERE p.id IS NULL OR o.user_id IS NULL',
        'SELECT 1 FROM project_access a LEFT JOIN project_members m ON m.project_id=a.project_id AND m.user_id=a.user_id WHERE m.user_id IS NULL',
        'SELECT 1 FROM guest_invites g LEFT JOIN projects p ON p.id=g.project_id AND p.organization_id=g.organization_id LEFT JOIN documents d ON d.id=g.document_id AND d.project_id=g.project_id LEFT JOIN document_versions v ON v.document_id=g.document_id AND v.revision=g.revision WHERE p.id IS NULL OR d.id IS NULL OR v.document_id IS NULL',
        'SELECT 1 FROM accounting_entries a JOIN accounting_accounts d ON d.id=a.debit_id JOIN accounting_accounts c ON c.id=a.credit_id WHERE a.organization_id<>d.organization_id OR a.organization_id<>c.organization_id'
    ] as $sql)if($db->query($sql.' LIMIT 1')->fetchColumn())throw new Problem('Нарушена изоляция организаций или проектов.',503);
}

// Operator explicitly accepts the restored state and reconciliation of remote
// side effects. This command never dispatches or changes a queued job.
function recoveryRelease(PDO $db,string $digest): void {
    recoveryOffline();$file=dataDir().'/recovery.lock';
    if(!preg_match('/\A[a-f0-9]{64}\z/',$digest)||is_link($file)||!is_file($file)||filesize($file)>128||!hash_equals($digest,trim((string)file_get_contents($file))))throw new Problem('Карантин отсутствует или контрольная сумма не совпадает.',409);
    $report=dirname(dataDir()).'/RESTORE-REPORT.json';
    if(is_link($report)||!is_file($report)||filesize($report)>65536)throw new Problem('Нет проверенного отчёта восстановления.',409);
    $r=json_decode((string)file_get_contents($report),true);
    if(!is_array($r)||($r['status']??null)!=='restored_verified_offline'||($r['manifest_sha256']??null)!==$digest||($r['backend']??null)!==(isPostgres($db)?'pgsql':'sqlite'))throw new Problem('Отчёт восстановления не соответствует цели.',409);
    if(isPostgres($db)){
        $target=hash('sha256',':'.getenv('PGHOST').':'.getenv('PGPORT').':'.getenv('PGDATABASE'));
        if(($r['pg_target_sha256']??null)!==$target)throw new Problem('Подключение PostgreSQL изменилось.',409);
    }
    recoveryScopeCheck($db);if(!isPostgres($db))checkedDatabase($db);
    foreach(['document_versions','signing_archive','signing_callbacks','signing_receipts'] as $table)foreach(transferRows($db,$table,TRANSFER_TABLES[$table]) as $unused){}
    if((int)$db->query('SELECT COUNT(*) FROM mfa_credentials')->fetchColumn()>0)recoveryMfaCheck($db);
    if(!unlink($file))throw new Problem('Не удалось снять карантин.',503);
    $dir=fopen(dirname($file),'r');if($dir){try{fsync($dir);}finally{fclose($dir);}}
}
