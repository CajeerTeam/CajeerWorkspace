#!/usr/bin/env python3
"""Offline, verified Workspace DB + object backups and isolated recovery (Linux)."""
import argparse
from contextlib import closing
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import sqlite3
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parents[1]
SCHEMA = int(re.search(r'const SCHEMA_VERSION = (\d+);', (ROOT/'app/Core.php').read_text())[1])
VERSION = (ROOT/'VERSION').read_text().strip()
MARKER = b'CW-OBJECT-1'
HEX = re.compile(r'[a-f0-9]{64}\Z')


def require(condition, message):
    if not condition:
        raise ValueError(message)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def digest(path):
    require(path.is_file() and not path.is_symlink(), 'Missing or unsafe file')
    h = hashlib.sha256()
    with path.open('rb') as f:
        for block in iter(lambda: f.read(1024 * 1024), b''):
            h.update(block)
    return h.hexdigest()


def fsync_dir(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def write(path, data):
    with path.open('xb') as f:
        f.write(data)
        f.flush()
        os.fsync(f.fileno())


def sync_file(path):
    with path.open('rb') as f:
        os.fsync(f.fileno())


def private_dir(path):
    path = Path(path)
    require(path.is_absolute() and not path.is_symlink() and path.is_dir(), 'Expected absolute private directory')
    require(path.stat().st_mode & 0o077 == 0, 'Directory must be private (0700)')
    return path.resolve()


def new_directory(path):
    path = Path(path)
    require(path.is_absolute() and path.parent.is_dir(), 'Destination parent must exist; use absolute path')
    parent = path.parent.resolve()
    require(parent != ROOT and ROOT not in parent.parents, 'Destination must be outside application')
    path = parent / path.name
    path.mkdir(mode=0o700)  # exclusive reservation, never replace an existing directory
    fsync_dir(parent)
    return path


def canonical(data):
    return json.dumps(data, sort_keys=True, ensure_ascii=True, separators=(',', ':'), allow_nan=False).encode()


def object_path(root, key):
    require(isinstance(key, str) and HEX.fullmatch(key), 'Invalid object key')
    return root / (key + '.pdf')


def object_check(root, item):
    p = object_path(root, item['key'])
    require(p.is_file() and not p.is_symlink() and p.stat().st_size == item['size'] and digest(p) == item['sha256'], 'Object integrity failure')
    return p


def copy_object(src, dest, item):
    p = object_check(src, item)
    target = object_path(dest, item['key'])
    with p.open('rb') as a, target.open('xb') as b:
        shutil.copyfileobj(a, b, 1024 * 1024)
        b.flush(); os.fsync(b.fileno())
    object_check(dest, item)


def sqlite_open(path):
    require(path.is_file() and not path.is_symlink(), 'SQLite file missing or unsafe')
    return sqlite3.connect(path.resolve().as_uri() + '?mode=ro', uri=True, timeout=10)


def sqlite_snapshot(path, objects):
    with closing(sqlite_open(path)) as db:
        db.execute('BEGIN')
        require(db.execute('PRAGMA user_version').fetchone()[0] == SCHEMA, 'Unsupported schema')
        require(db.execute('PRAGMA integrity_check').fetchall() == [('ok',)], 'SQLite integrity failure')
        require(db.execute('PRAGMA foreign_key_check').fetchone() is None, 'Foreign key violation')
        expected = [(i, digest(ROOT/'database'/f'{i:03}.sql')) for i in range(1, SCHEMA+1)]
        require(db.execute('SELECT version,sha256 FROM schema_migrations ORDER BY version').fetchall() == expected, 'Migration ledger mismatch')
        tables = [r[0] for r in db.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")]
        hashes = {}
        for table in tables:
            require(re.fullmatch(r'[a-z_0-9]+', table), 'Invalid table name')
            cols = db.execute(f'PRAGMA table_info({table})').fetchall()
            pk = sorted((r[5], r[1]) for r in cols if r[5])
            require(pk and all(re.fullmatch(r'[a-z_0-9]+', r[1]) for r in pk), 'Missing stable row order')
            h = hashlib.sha256(); n = 0
            for row in db.execute(f'SELECT * FROM {table} ORDER BY ' + ','.join(r[1] for r in pk)):
                # Typed, framed row representation; does not export row contents.
                for val in row:
                    raw = val if isinstance(val, bytes) else canonical(val)
                    h.update((b'B' if isinstance(val, bytes) else b'J') + str(len(raw)).encode() + b':' + raw)
                h.update(b'\n'); n += 1
            hashes[table] = {'rows': n, 'sha256': h.hexdigest()}
        refs = []
        for state, key, size, checksum, payload in db.execute('SELECT storage_state,object_key,original_size,sha256,bytes FROM document_versions'):
            if state == 'inline':
                require(sha(payload) == checksum, 'Inline document hash mismatch')
            else:
                require(payload == MARKER, 'Invalid document pointer')
                if state == 'object':
                    refs.append({'key': key, 'size': size, 'sha256': checksum})
                else:
                    require(state == 'purged', 'Invalid document state')
        for state, key, size, checksum, pdf, status, status_hash in db.execute('SELECT storage_state,object_key,pdf_size,pdf_sha256,pdf_base64,status_json,status_sha256 FROM signing_archive'):
            raw = base64.b64decode(pdf, validate=True)
            require(sha(status.encode()) == status_hash, 'Signing status hash mismatch')
            if state == 'object':
                require(raw == MARKER, 'Invalid archive pointer'); refs.append({'key': key, 'size': size, 'sha256': checksum})
            else:
                require(state == 'inline' and len(raw) == size and sha(raw) == checksum, 'Signed PDF hash mismatch')
        for table in ('signing_callbacks', 'signing_receipts'):
            for payload, checksum in db.execute(f'SELECT payload,payload_sha256 FROM {table}'):
                require(sha(payload.encode()) == checksum, 'Receipt integrity failure')
        require(len({r['key'] for r in refs}) == len(refs), 'Duplicate object reference')
        for item in refs: object_check(objects, item)
        cash = db.execute("SELECT p.organization_id,c.currency,SUM(CASE WHEN c.direction='income' THEN c.amount_minor ELSE -c.amount_minor END) FROM cash_entries c JOIN projects p ON p.id=c.project_id GROUP BY p.organization_id,c.currency ORDER BY p.organization_id,c.currency").fetchall()
        ledger = db.execute('SELECT organization_id,currency,account_id,SUM(amount) FROM (SELECT organization_id,currency,debit_id AS account_id,amount_minor AS amount FROM accounting_entries UNION ALL SELECT organization_id,currency,credit_id AS account_id,-amount_minor AS amount FROM accounting_entries) GROUP BY organization_id,currency,account_id ORDER BY organization_id,currency,account_id').fetchall()
        require(db.execute('SELECT 1 FROM accounting_entries a JOIN accounting_accounts d ON d.id=a.debit_id JOIN accounting_accounts c ON c.id=a.credit_id WHERE a.organization_id<>d.organization_id OR a.organization_id<>c.organization_id LIMIT 1').fetchone() is None, 'Cross-organization ledger')
        schema = db.execute("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name").fetchall()
        sequences = db.execute('SELECT name,seq FROM sqlite_sequence ORDER BY name').fetchall() if db.execute("SELECT 1 FROM sqlite_master WHERE name='sqlite_sequence'").fetchone() else []
        return {'schema': SCHEMA, 'tables': hashes, 'objects': sorted(refs,key=lambda x:x['key']), 'cash': cash, 'ledger': ledger, 'schema_hash': sha(canonical(schema)), 'sequences': sequences}


def command(args, env=None, output=False):
    # Do not print child stderr: database clients may include sensitive details.
    result = subprocess.run(args, env=env, stdout=subprocess.PIPE if output else subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=3600, check=False)
    require(result.returncode == 0, f'{Path(args[0]).name} failed (exit {result.returncode})')
    return result.stdout if output else None


def pg_env(objects):
    env = os.environ.copy()
    require(env.get('WORKSPACE_DB') == 'pgsql' and env.get('PGDATABASE') and env.get('PGUSER'), 'Set explicit PostgreSQL environment')
    require(env.get('PGHOST') and env.get('PGPORT') and env.get('PGSSLMODE'), 'Set PGHOST, PGPORT, PGSSLMODE explicitly')
    # libpq must use the same target as PDO; disallow alternate routing.
    require(not any(env.get(k) for k in ('PGSERVICE','PGSERVICEFILE','PGHOSTADDR','PGOPTIONS','PGDATABASEURL')), 'Ambiguous PostgreSQL connection options')
    require(re.fullmatch(r'[A-Za-z0-9_.-]+', env['PGDATABASE']), 'Use simple database name')
    env['WORKSPACE_OBJECT_DIR'] = str(objects)
    return env


def pg_snapshot(objects, data=None):
    env = pg_env(objects)
    if data is not None: env['WORKSPACE_DATA']=str(data)
    result = json.loads(command(['php',str(ROOT/'bin/workspace'),'recovery:inspect-pg'],env,True))
    require(result['schema'] == SCHEMA, 'Unsupported PostgreSQL schema')
    return result


def snapshot(backend, db, objects):
    return sqlite_snapshot(db,objects) if backend == 'sqlite' else pg_snapshot(objects)


def backup(backend, data, objects, dest):
    start = time.time(); objects = private_dir(objects)
    db = private_dir(data)/'workspace.sqlite' if backend == 'sqlite' else None
    # Offline operator assertion is essential for DB+objects consistency.
    planned=Path(dest).resolve()
    require(planned!=objects and objects not in planned.parents and (db is None or db.parent not in planned.parents),"Backup destination must be separate from source")
    point = time.time(); before = snapshot(backend,db,objects)
    dest = new_directory(dest)
    try:
        obj = dest/'objects'; obj.mkdir(mode=0o700)
        name = 'workspace.sqlite' if backend == 'sqlite' else 'workspace.dump'
        target = dest/name
        if backend == 'sqlite':
            with closing(sqlite_open(db)) as source, closing(sqlite3.connect(target)) as copy:
                source.backup(copy, pages=256, sleep=0.05)
                copy.execute('PRAGMA journal_mode=DELETE'); copy.commit()
            sync_file(target)
        else:
            command(['pg_dump','--format=custom','--no-owner','--no-privileges','--schema=public','--file',str(target)],pg_env(objects)); sync_file(target)
        for item in before['objects']: copy_object(objects,obj,item)
        fsync_dir(obj)
        after = snapshot(backend,db,objects)
        require(canonical(before) == canonical(after), 'Source changed during offline backup')
        if backend == 'sqlite': require(canonical(before) == canonical(sqlite_snapshot(target,obj)), 'Backup logical verification failed')
        manifest = {'format':1,'version':VERSION,'backend':backend,'backup_point_unix':point,'created_unix':time.time(),'database':{'name':name,'sha256':digest(target),'size':target.stat().st_size},'snapshot':before}
        # A manifest is the completion marker. Store its digest separately in a trusted location.
        raw = canonical(manifest); write(dest/'manifest.json',raw); fsync_dir(dest)
        return {'status':'backup_created','backend':backend,'manifest_sha256':sha(raw),'backup_point_unix':point,'duration_seconds':round(time.time()-start,3),'objects':len(before['objects']),'tables':len(before['tables']),'restore_verified':backend=='sqlite'}
    except BaseException:
        # Keep incomplete backup isolated, no valid completion manifest.
        (dest/'manifest.json').unlink(missing_ok=True)
        raise


def verify(folder, expected):
    folder = private_dir(folder); path = folder/'manifest.json'
    require(HEX.fullmatch(expected) and digest(path)==expected,'Manifest digest mismatch')
    m = json.loads(path.read_bytes()); require(m['format']==1 and m['snapshot']['schema']==SCHEMA,'Unsupported backup format')
    backend = m['backend']; require(backend in ('sqlite','pgsql'),'Invalid backend')
    name = 'workspace.sqlite' if backend=='sqlite' else 'workspace.dump'
    db = folder/name
    require(m['database']['name']==name and db.stat().st_size==m['database']['size'] and digest(db)==m['database']['sha256'],'Database artifact mismatch')
    require({p.name for p in folder.iterdir()}=={'manifest.json',name,'objects'},'Unexpected files in backup')
    obj=private_dir(folder/'objects'); refs=m['snapshot']['objects']
    require(len({r['key'] for r in refs})==len(refs),'Duplicate object reference')
    for item in refs: object_check(obj,item)
    require({p.name for p in obj.iterdir()}=={r['key']+'.pdf' for r in refs},'Unexpected object files')
    if backend=='sqlite': require(canonical(sqlite_snapshot(db,obj))==canonical(m['snapshot']),'Logical snapshot mismatch')
    return m,db,obj


def restore(folder, expected, dest, outage_at=None):
    start=time.time(); m,db,obj=verify(folder,expected); backend=m['backend']
    if outage_at is not None: require(m['backup_point_unix']<=outage_at<=start,'Outage timestamp must follow backup and precede restore')
    planned=Path(dest).resolve(); source=Path(folder).resolve();require(planned!=source and source not in planned.parents,'Restore target must be separate from backup')
    dest=new_directory(dest)
    data=dest/'data';data.mkdir(mode=0o700);write(data/'recovery.lock',(expected+'\n').encode());fsync_dir(data)
    newobj=dest/'objects'; newobj.mkdir(mode=0o700)
    for item in m['snapshot']['objects']: copy_object(obj,newobj,item)
    fsync_dir(newobj)
    if backend=='sqlite':
        target=data/'workspace.sqlite'
        with db.open('rb') as a,target.open('xb') as b: shutil.copyfileobj(a,b,1024*1024);b.flush();os.fsync(b.fileno())
        fsync_dir(data); result=sqlite_snapshot(target,newobj)
    else:
        env=pg_env(newobj);env['WORKSPACE_DATA']=str(data)
        command(['php',str(ROOT/'bin/workspace'),'recovery:pg-empty'],env)
        toc=command(['pg_restore','--list',str(db)],env,True).decode('utf-8')
        # The target public schema already exists. Ignore only its CREATE SCHEMA entry.
        entries='\n'.join(line for line in toc.splitlines() if not re.match(r'^\d+; \d+ \d+ SCHEMA - public(?: |$)',line))+'\n'
        list_file=dest/'restore.list';write(list_file,entries.encode())
        command(['pg_restore','--use-list',str(list_file),'--single-transaction','--exit-on-error','--no-owner','--no-privileges','--dbname',env['PGDATABASE'],str(db)],env);list_file.unlink()
        result=pg_snapshot(newobj,data)
    require(canonical(result)==canonical(m['snapshot']),'Restored data differs; DO NOT promote target')
    finish=time.time(); report={'status':'restored_verified_offline','backend':backend,'manifest_sha256':expected,'duration_seconds':round(finish-start,3),'tables':len(result['tables']),'objects':len(result['objects']),'cash_balances_match':True,'ledger_balances_match':True,'scope_and_queue_snapshot_match':True,'service_ready':False,'quarantined':True}
    if backend=='pgsql':report['pg_target_sha256']=sha((':'+':'.join(os.environ[k] for k in ('PGHOST','PGPORT','PGDATABASE'))).encode())
    if outage_at is not None:
        report.update(backup_age_at_outage_seconds=round(outage_at-m['backup_point_unix'],3),outage_to_verified_restore_seconds=round(finish-outage_at,3))
    write(dest/'RESTORE-REPORT.json',canonical(report));fsync_dir(dest)
    return report


def main():
    p=argparse.ArgumentParser(description=__doc__); sub=p.add_subparsers(dest='action',required=True)
    b=sub.add_parser('backup');b.add_argument('--backend',choices=['sqlite','pgsql'],required=True);b.add_argument('--data',type=Path);b.add_argument('--objects',type=Path,required=True);b.add_argument('--destination',type=Path,required=True)
    for name in ('verify','restore'):
        v=sub.add_parser(name);v.add_argument('--backup',type=Path,required=True);v.add_argument('--manifest-sha256',required=True)
        if name=='restore': v.add_argument('--destination',type=Path,required=True);v.add_argument('--outage-at',type=float)
    a=p.parse_args();os.umask(0o077)
    try:
        require(os.environ.get('WORKSPACE_RECOVERY_OFFLINE')=='1' or a.action=='verify','Stop web/worker/writers and set WORKSPACE_RECOVERY_OFFLINE=1')
        if a.action=='backup':
            require(a.backend!='sqlite' or a.data is not None,'--data is required for SQLite');r=backup(a.backend,a.data,a.objects,a.destination)
        elif a.action=='verify':
            m,_,_=verify(a.backup,a.manifest_sha256);r={'status':'verified','backend':m['backend'],'restore_verified':m['backend']=='sqlite'}
        else:r=restore(a.backup,a.manifest_sha256,a.destination,a.outage_at)
        print(json.dumps(r,indent=2));return 0
    except (OSError,ValueError,KeyError,TypeError,sqlite3.Error,subprocess.SubprocessError) as e:
        # Never expose raw row data or connection errors.
        print(json.dumps({'status':'failed','error':str(e) if type(e) is ValueError else type(e).__name__}),file=sys.stderr);return 1

if __name__=='__main__': sys.exit(main())
