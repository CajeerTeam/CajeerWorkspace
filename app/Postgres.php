<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

function postgresSelected(): bool { return getenv('WORKSPACE_DB')==='pgsql'; }
function postgresConnect(): PDO {
    updateWriterLease();
    if (!extension_loaded('pdo_pgsql')) throw new Problem('Не установлено расширение pdo_pgsql.',503);
    $values=['host'=>getenv('PGHOST')?:'localhost','port'=>getenv('PGPORT')?:'5432','dbname'=>getenv('PGDATABASE')?:'', 'sslmode'=>getenv('PGSSLMODE')?:'verify-full'];
    foreach ($values as $v) if ($v==='' || preg_match('/[;\s\x00-\x1f\'"\\\\]/',$v)) throw new Problem('Проверьте PGHOST, PGPORT, PGDATABASE, PGSSLMODE.',503);
    if (!asciiDigits($values['port']) || (int)$values['port']<1 || (int)$values['port']>65535) throw new Problem('Некорректный PGPORT.',503);
    if (!in_array($values['sslmode'],['disable','require','verify-ca','verify-full'],true)) throw new Problem('Некорректный PGSSLMODE.',503);
    $dsn='pgsql:'.implode(';',array_map(fn($k,$v)=>$k.'='.$v,array_keys($values),$values));
    $db=new PDO($dsn,getenv('PGUSER')?:null,getenv('PGPASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $db->exec("SET search_path TO public; SET TIME ZONE 'UTC'; SET lock_timeout TO '10s'");
    if ((int)$db->query('SHOW server_version_num')->fetchColumn()<140000) throw new Problem('Требуется PostgreSQL 14+.',503);
    return $db;
}
function postgresEmpty(PDO $db): void {
    if ($db->query("SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind IN ('r','p','v','m','S','f') LIMIT 1")->fetchColumn()) throw new Problem('Целевая схема public должна быть пустой.',409);
}
function installPostgres(PDO $db): void {
    postgresEmpty($db);
    $files=migrationFiles($db);
    foreach ($files as $v=>$file) {
        $db->exec($file['sql']);
        if ($v===3) for ($i=1;$i<3;$i++) recordMigration($db,$i,$files,'applied');
        if ($v>=3) recordMigration($db,$v,$files,'applied');
    }
    validateLedger($db,SCHEMA_VERSION,$files);
}
function migratePostgres(PDO $db,bool $install): void {
    atomic($db,function() use($db,$install) {
        $v=schemaVersion($db);
        if ($v===SCHEMA_VERSION) { validateLedger($db,$v,migrationFiles($db)); return; }
        if ($v===0 && $install) { installPostgres($db); return; }
        if ($install || !in_array($v,[4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42],true)) throw new Problem('Неподдерживаемая исходная схема PostgreSQL.',503);
        $files=migrationFiles($db); validateLedger($db,$v,$files);
        // Operator must take a verified pg_dump before calling upgrade. Transactional DDL rolls back on error.
        for ($i=$v+1;$i<=SCHEMA_VERSION;$i++) { $db->exec($files[$i]['sql']); recordMigration($db,$i,$files,'applied'); }
        validateLedger($db,SCHEMA_VERSION,$files);
    });
}
// Explicit table inventory, including identities, invitations and all binary versions.
const TRANSFER_TABLES = [
    'users'=>'id','auth_scenario_evidence'=>'scenario,session_hash','staff_sessions'=>'handle','identity_history'=>'event_key','login_attempts'=>'bucket','organizations'=>'id','organization_auth_policies'=>'organization_id','organization_members'=>'organization_id,user_id','projects'=>'id','ai_org_policies'=>'organization_id','ai_daily_usage'=>'organization_id,day_on','ai_history'=>'handle','ai_calls'=>'handle','ai_delegation_policy'=>'organization_id','ai_delegations'=>'token_hash',
    'tasks'=>'id','cash_entries'=>'id','documents'=>'id','document_versions'=>'document_id,revision','project_connections'=>'project_id,connection_key',
    'connection_results'=>'connection_key','action_log'=>'id','connection_checks'=>'id','teams'=>'id','team_members'=>'team_id,user_id',
    'project_members'=>'project_id,user_id','invitations'=>'id','organization_connections'=>'connection_key',
    'project_access'=>'project_id,user_id','payment_policies'=>'project_id','payment_requests'=>'id','audit_events'=>'id',
    'oidc_identities'=>'user_id','emergency_accounts'=>'user_id','mfa_credentials'=>'user_id','guest_invites'=>'id','oidc_sessions'=>'handle','oidc_logout_events'=>'event_hash',
    'milestones'=>'id','task_milestones'=>'task_id','task_dependencies'=>'task_id,prerequisite_id',
    'sprints'=>'id','sprint_tasks'=>'sprint_id,task_id','project_availability'=>'project_id,user_id,week_on','project_rates'=>'project_id','timesheets'=>'id','time_entries'=>'id',
    'counterparties'=>'id','counterparty_contacts'=>'id','client_orders'=>'id','client_offers'=>'id','client_stages'=>'id','client_stage_tasks'=>'stage_id,task_id','client_order_guests'=>'guest_id','client_deliveries'=>'id','client_stage_hours'=>'delivery_id,entry_id',
    'oss_projects'=>'project_id','oss_milestones'=>'project_id,milestone_id','oss_contributors'=>'id','oss_task_links'=>'task_id','oss_contributions'=>'id','oss_comments'=>'id','oss_releases'=>'id','oss_release_items'=>'release_id,contribution_id','oss_requests'=>'contributor_id,request_key','oss_lines'=>'id','oss_line_releases'=>'id','oss_line_fixes'=>'line_id,contribution_id',
    'github_repositories'=>'id','github_task_links'=>'task_id','github_deliveries'=>'delivery_id','github_jobs'=>'id','project_releases'=>'id','project_release_tasks'=>'release_id,task_id',
    'infra_hosts'=>'id','infra_environments'=>'id','infra_sites'=>'id','infra_observations'=>'host_id,source','infra_operation_owners'=>'host_id,operation','deployments'=>'id','delivery_task_scope'=>'release_id,task_id','delivery_reviews'=>'id','lead_review_events'=>'id','lead_decisions'=>'id','lead_waits'=>'id',
    'legal_entities'=>'id','invoices'=>'id','invoice_lines'=>'id','invoice_movements'=>'id',
    'accounting_accounts'=>'id','accounting_periods'=>'id','accounting_openings'=>'id','accounting_entries'=>'id',
    'bank_accounts'=>'id','bank_rates'=>'id','bank_statements'=>'id','bank_rows'=>'id','bank_allocations'=>'id','bank_reconciliations'=>'id','bank_match_history'=>'id',
    'economics_settings'=>'project_id','economics_budgets'=>'id','portfolio_budget_versions'=>'budget_id,version','portfolio_decisions'=>'project_id,version','economics_labor_rates'=>'id','economics_cash_classes'=>'cash_id','economics_plans'=>'id','economics_plan_cash'=>'plan_id,cash_id',
    'uk_vat_profiles'=>'entity_id','uk_vat_documents'=>'invoice_id',
    'contracts'=>'id','contract_revisions'=>'id','contract_attachments'=>'id','contract_obligations'=>'id',
    'preparation_templates'=>'id','preparations'=>'id','preparation_revisions'=>'id','preparation_events'=>'id','signing_packages'=>'id','signing_archive'=>'package_id','signing_callbacks'=>'id','signing_receipts'=>'id','retention_policies'=>'id','retention_documents'=>'document_id','retention_holds'=>'id','retention_links'=>'id','retention_deliveries'=>'id','engine_sites'=>'id','engine_publications'=>'id','book_sources'=>'id','book_editions'=>'id','book_reviews'=>'id','book_release_links'=>'id','forum_sources'=>'id','forum_topics'=>'id','forum_operations'=>'id','lite_sites'=>'id','lite_jobs'=>'id','editorial_releases'=>'id','editorial_events'=>'id','logs_bindings'=>'project_id','logs_outbox'=>'id','bots_bindings'=>'project_id','bots_messages'=>'id','bots_events'=>'id','incident_policies'=>'project_id','incidents'=>'id','incident_signals'=>'id','incident_events'=>'id','ops_services'=>'id','ops_service_incidents'=>'service_id,incident_id','ops_support'=>'id','ops_followups'=>'incident_id,task_id','reminder_subscriptions'=>'id','reminder_reads'=>'id','integration_rates'=>'provider','integration_jobs'=>'id','integration_failures'=>'id','integration_journal'=>'id'
];
function transferRows(PDO $db,string $table,string $order): iterable {
    // C collation for PostgreSQL text keys (SQLite default BINARY). All named columns are fixed by the inventory.
    $order=implode(',',array_map(fn($c)=>isPostgres($db)&&in_array($c,['bucket','connection_key','handle','event_key','event_hash','week_on','request_key','delivery_id','source','operation','provider'],true)?$c.' COLLATE "C"':$c,explode(',',$order)));
    $columns=$table==='document_versions' && isPostgres($db)?"document_id,revision,sha256,encode(bytes,'hex') AS bytes,actor_id,created_at,object_key,original_size,storage_state":"*";
    $stmt=$db->query("SELECT $columns FROM $table ORDER BY $order");
    while ($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($table==='document_versions') {
            if (isPostgres($db)) $row['bytes']=hex2bin($row['bytes']);
            if (is_resource($row['bytes'])) $row['bytes']=stream_get_contents($row['bytes']);
            if($row['storage_state']==='inline'){if(!is_string($row['bytes'])||!hash_equals($row['sha256'],hash('sha256',$row['bytes'])))throw new Problem('Нарушена контрольная сумма PDF.',503);}else{if($row['bytes']!==RETENTION_MARKER)throw new Problem('Нарушен указатель PDF.',503);if($row['storage_state']==='object')retentionObjectRead($row['object_key'],(int)$row['original_size'],$row['sha256']);}
        }
        if ($table==='signing_archive') {
            $pdf=retentionSignedBytes($row);
            if($pdf===false||strlen($pdf)!==(int)$row['pdf_size']||!hash_equals($row['pdf_sha256'],hash('sha256',$pdf))||!hash_equals($row['status_sha256'],hash('sha256',$row['status_json'])))throw new Problem('Нарушена целостность архива подписания.',503);
        }
        if (in_array($table,['signing_callbacks','signing_receipts'],true)&&!hash_equals($row['payload_sha256'],hash('sha256',$row['payload'])))throw new Problem('Нарушена целостность квитанции подписания.',503);
        yield $row;
    }
}
function rowDigest($hash,array $row): void {
    ksort($row);
    foreach ($row as $key=>$value) {
        $value=$value===null?null:(string)$value;
        hash_update($hash,strlen($key).':'.$key.($value===null?'N':'V'.strlen($value).':'.$value));
    }
    hash_update($hash,"\n");
}
function transferSqlite(PDO $source,PDO $target): array {
    if (isPostgres($source) || !isPostgres($target) || schemaVersion($source)!==SCHEMA_VERSION) throw new Problem('Сначала обновите SQLite до актуальной схемы Workspace.');
    validateLedger($source,SCHEMA_VERSION,migrationFiles($source)); checkedDatabase($source);
    return atomic($target,function() use($source,$target) {
        installPostgres($target);
        // Fresh install seeds the first organization only. The source owns the exact records.
        $target->exec('DELETE FROM organizations');
        $report=[];
        foreach (TRANSFER_TABLES as $table=>$order) {
            $hash=hash_init('sha256'); $count=0;
            foreach (transferRows($source,$table,$order) as $row) {
                rowDigest($hash,$row); $count++;
                $columns=array_keys($row); $placeholders=array_fill(0,count($row),'?');
                if ($table==='document_versions') { $placeholders[array_search('bytes',$columns,true)]="decode(?,'hex')"; $row['bytes']=bin2hex($row['bytes']); }
                query($target,"INSERT INTO $table(".implode(',',$columns).') VALUES('.implode(',',$placeholders).')',array_values($row));
            }
            $expected=hash_final($hash); $actual=hash_init('sha256'); $n=0;
            foreach (transferRows($target,$table,$order) as $row) { rowDigest($actual,$row); $n++; }
            if ($count!==$n || !hash_equals($expected,hash_final($actual))) throw new Problem('Данные не совпали: '.$table,503);
            $report[$table]=['rows'=>$count,'sha256'=>$expected];
            if ($order==='id') $target->exec("SELECT setval(pg_get_serial_sequence('$table','id'),COALESCE(MAX(id),1),MAX(id) IS NOT NULL) FROM $table");
        }
        $target->exec('SET CONSTRAINTS ALL IMMEDIATE');
        return $report;
    });
}
function migrateToPostgres(string $backupPath): array {
    if (postgresSelected()) throw new Problem('Для переноса установите WORKSPACE_DB=sqlite, настройте PG-переменные назначения.');
    $source=database(); $target=postgresConnect();
    return atomic($source,function() use($source,$target,$backupPath) {
        $reader=openDatabase(); $backup=snapshotDatabase($reader,$backupPath); $reader=null;
        $snapshot=new PDO('sqlite:'.$backup['path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $snapshot->exec('PRAGMA query_only=ON');
        $report=transferSqlite($snapshot,$target);
        return ['backup'=>$backup,'tables'=>$report];
    });
}
