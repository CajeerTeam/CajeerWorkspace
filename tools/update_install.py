#!/usr/bin/env python3
"""Trusted offline installer for previously planned, signed Workspace releases (POSIX).
Never downloads or invokes a shell. All runtime state is outside the release tree.
"""
import argparse
import fcntl
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import stat
import subprocess
import sys
import tempfile
import time
import zipfile
from contextlib import contextmanager
import recovery
import update_package as pkg

require = pkg.require
FINAL = ('completed', 'rolled_back')


def atomic_json(path, value, mode=0o600):
    fd, name = tempfile.mkstemp(prefix='.write-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as out:
            os.fchmod(out.fileno(), mode)
            out.write(recovery.canonical(value)); out.flush(); os.fsync(out.fileno())
        os.replace(name, path); recovery.fsync_dir(path.parent)
    finally:
        Path(name).unlink(missing_ok=True)


def read_json(path, limit=1024*1024):
    pkg.regular(path, limit)
    return json.loads(path.read_bytes())


def directory(path, private=False):
    require(path.is_absolute() and path.resolve()==path and path.is_dir() and not path.is_symlink(), 'Expected canonical absolute directory')
    require(not path.stat().st_mode & (0o077 if private else 0o027), 'Unsafe directory permissions')
    return path


def disjoint(a,b):
    return a!=b and a not in b.parents and b not in a.parents


@contextmanager
def exclusive(path):
    fd=os.open(path, os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW|os.O_NONBLOCK, 0o600)
    try:
        require(stat.S_ISREG(os.fstat(fd).st_mode), 'Unsafe lock file')
        fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        yield fd
    finally: os.close(fd)


def command(args, env, fds=(), timeout=3600):
    r=subprocess.run([str(x) for x in args],env=env,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=timeout,pass_fds=fds)
    require(r.returncode==0, f'{Path(str(args[0])).name} failed (exit {r.returncode})')
    return r.stdout


def payload(path, version, schema):
    hashes,_=pkg.archive_index(path,version,schema)
    with zipfile.ZipFile(path) as z:
        hashes['SHA256SUMS']=recovery.sha(z.read('SHA256SUMS'))
    return hashes


def verify_tree(folder, hashes):
    directory(folder)
    actual=set()
    for p in folder.rglob('*'):
        require(not p.is_symlink() and not p.stat().st_mode & 0o022, 'Unsafe release member')
        if p.is_dir(): continue
        n=p.relative_to(folder).as_posix()
        require(p.is_file() and n in hashes and recovery.digest(p)==hashes[n], 'Release tree differs: '+n)
        actual.add(n)
    require(actual==set(hashes), 'Incomplete release tree')


def stage_tree(releases, archive, version, hashes):
    dest=releases/version
    if dest.exists() or dest.is_symlink():
        verify_tree(dest,hashes); return dest
    group=releases.stat().st_gid
    with tempfile.TemporaryDirectory(prefix='.install-',dir=releases) as tmp:
        tree=Path(tmp)/'tree'; tree.mkdir(mode=0o700)
        with zipfile.ZipFile(archive) as z:
            for n,h in sorted(hashes.items()):
                b=z.read(n); require(recovery.sha(b)==h,'Package changed during extraction')
                p=tree/n; p.parent.mkdir(parents=True,exist_ok=True); recovery.write(p,b)
                os.chown(p,-1,group); p.chmod(0o550 if n=='bin/workspace' else 0o440); recovery.sync_file(p)
        for p in sorted([tree]+[p for p in tree.rglob('*') if p.is_dir()],key=lambda p:len(p.parts),reverse=True):
            os.chown(p,-1,group); p.chmod(0o2750); recovery.fsync_dir(p)
        verify_tree(tree,hashes); os.rename(tree,dest); recovery.fsync_dir(releases)
    return dest


def current(root):
    link=root/'current'; require(link.is_symlink(),'Managed current symlink required')
    dest=os.readlink(link); require(dest.startswith('releases/') and pkg.VERSION.fullmatch(dest[9:]),'Unexpected current target')
    return dest[9:]


def switch(root, version):
    with tempfile.TemporaryDirectory(dir=root) as tmp:
        link=Path(tmp)/'next'; link.symlink_to('releases/'+version)
        os.replace(link,root/'current'); recovery.fsync_dir(root)


def stop_units(units, env, fds):
    for unit in units:
        require(re.fullmatch(r'[A-Za-z0-9_.@:-]+\.(?:service|timer|socket|path)',unit) is not None and not unit.startswith('-'),'Invalid systemd unit')
    if units:
        command(['systemctl','stop',*units],env,fds,120)
        for unit in units:
            loaded=command(['systemctl','show','--property=LoadState','--value',unit],env,fds,30).decode().strip()
            require(loaded=='loaded','Unknown writer unit: '+unit)
            state=command(['systemctl','show','--property=ActiveState','--value',unit],env,fds,30).decode().strip()
            require(state=='inactive','Writer unit did not stop: '+unit)


def php(folder, args, env, fds):
    return command(['php',folder/'bin/workspace',*args],env,fds)


def environment(folder, env, fds, raw=None, sig=None, key=None, m=None):
    # Use the verified current release's own PHP checks, not a caller-supplied runtime description.
    code=r'''require $argv[1].'/app/bootstrap.php'; $db=\Workspace\database(); $env=\Workspace\updateEnvironment($db); if($argc>2){[$pem,$pin]=\Workspace\updateKey(); $raw=file_get_contents($argv[2]); $sig=file_get_contents($argv[3]); $m=json_decode($raw,true,32,JSON_THROW_ON_ERROR); $m=\Workspace\updateManifest($raw,$sig,$pem,$pin,$m['version'],$m['commit']); $c=\Workspace\updateCompatibility($m,$env); if($c['status']!=='compatible')throw new RuntimeException('Incompatible environment');} echo json_encode($env,JSON_THROW_ON_ERROR);'''
    args=['php','-r',code,folder]
    if raw is not None: args.extend([raw,sig])
    return json.loads(command(args,env,fds,120))


def snapshot(folder, backend, data, objects, env, fds):
    code="import sys,json; from pathlib import Path; sys.path.insert(0,sys.argv[1]); import recovery; print(json.dumps(recovery.sqlite_snapshot(Path(sys.argv[3])/'workspace.sqlite',Path(sys.argv[4])) if sys.argv[2]=='sqlite' else recovery.pg_snapshot(Path(sys.argv[4]),Path(sys.argv[3]))))"
    return json.loads(command([sys.executable,'-B','-c',code,folder/'tools',backend,data,objects],env,fds))


def backup_verify(folder, backup, digest, env, fds):
    command([sys.executable,'-B',folder/'tools/recovery.py','verify','--backup',backup,'--manifest-sha256',digest],env,fds)
    m=read_json(backup/'manifest.json',32*1024*1024)
    require(recovery.digest(backup/'manifest.json')==digest,'Backup manifest changed')
    return m['snapshot']


def controls(before, after, target_schema):
    require(after['schema']==target_schema,'Target schema not ready')
    if before['schema']==target_schema:
        require(before==after,'Unexpected changes to same-schema data')
    else:
        # Forward schema changes may alter tables, but financial balances and referenced files must survive.
        require(all(before[k]==after[k] for k in ('cash','ledger','objects')),'Financial or document control totals changed')
        require(all(k in after['tables'] and after['tables'][k]['rows']>=v['rows'] for k,v in before['tables'].items()),'Rows or tables lost during migration')


def smoke(folder, version, schema, env, fds):
    require(php(folder,['version'],env,fds).decode().strip()==version,'Wrong CLI version')
    require(json.loads(php(folder,['doctor'],env,fds))['ok'],'PHP doctor failed')
    m=json.loads(php(folder,['migrations'],env,fds))
    require(m['current']==schema and m['target']==schema and not m['pending'],'Target migrations not ready')
    code=r'''require $argv[1].'/app/bootstrap.php'; $db=\Workspace\database(); if(!$db->query("SELECT 1 FROM organization_members m JOIN users u ON u.id=m.user_id WHERE m.role='owner' AND u.enabled=1 LIMIT 1")->fetchColumn())exit(1); echo 'OK';'''
    require(command(['php','-r',code,folder],env,fds,120)==b'OK','No enabled owner')


def publish_report(state,j,phase):
    installed=j['target'] if phase in ('switched','completed') else j['source']
    schema=j['target_schema'] if installed==j['target'] else j['source_schema']
    atomic_json(state/'report.json',{'checked_at':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'installed_version':installed,'installed_schema':schema,'trust_pin':j['pin'],'operation':'install','status':phase,'reasons':['Manual installation: '+phase], 'installation':{'transaction':j['id'],'source':j['source'],'target':j['target'],'backup_sha256':j.get('backup_sha256'),'service_ready':False,'maintenance_released':phase in FINAL}},0o640)


def save(state,j,phase):
    j['phase']=phase; j['events'].append({'phase':phase,'at':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime())})
    atomic_json(state/'packages'/ 'install.json',j)
    atomic_json(state/'packages'/'transactions'/j['id']/'journal.json',j)
    publish_report(state,j,phase)


def install(args):
    state=directory(Path(os.environ['WORKSPACE_UPDATE_STATE_DIR'])); store=directory(state/'packages',True)
    root=directory(args.root); releases=directory(root/'releases'); data=directory(args.data,True); objects=directory(args.objects,True)
    require(all(disjoint(a,b) for i,a in enumerate((state,root,data,objects)) for b in (state,root,data,objects)[i+1:]),'Deployment, updates, database and objects must be separate')
    require(os.environ.get('WORKSPACE_DATA')==str(data) and os.environ.get('WORKSPACE_OBJECT_DIR')==str(objects),'Set exact WORKSPACE_DATA and WORKSPACE_OBJECT_DIR')
    require(args.offline_confirmed,'Confirm all external writers, schedulers and alternate nodes are stopped')
    backend=os.environ.get('WORKSPACE_DB','sqlite'); require(backend in ('sqlite','pgsql'),'Unknown DB backend')
    require(not (data/'recovery.lock').exists() and not (data/'recovery.lock').is_symlink(),'Recovery quarantine is active')
    pin=os.environ['WORKSPACE_UPDATE_KEY_SHA256']; key=Path(os.environ['WORKSPACE_UPDATE_PUBLIC_KEY']); pkg.regular(key,16384)
    require(pkg.HEX.fullmatch(pin) and recovery.digest(key)==pin,'Untrusted release key')
    previous=args.previous; require(previous.is_absolute() and previous.resolve()==previous,'Use canonical absolute predecessor ZIP path'); pkg.regular(previous,pkg.LIMIT)
    env=dict(os.environ,WORKSPACE_RECOVERY_OFFLINE='1',PYTHONDONTWRITEBYTECODE='1')
    env.pop('WORKSPACE_UPDATE_MAINTENANCE_TOKEN',None)
    pg_identity=None
    if backend=='pgsql':
        recovery.pg_env(objects)
        pg_identity=recovery.sha(recovery.canonical([os.environ[k] for k in ('PGHOST','PGPORT','PGDATABASE','PGUSER','PGSSLMODE')]))
        require(args.pg_restore_database and re.fullmatch(r'[A-Za-z0-9_.-]+',args.pg_restore_database) and args.pg_restore_database!=os.environ['PGDATABASE'],'Distinct empty PostgreSQL restore-test database required')
    identity={'root':str(root),'data':str(data),'objects':str(objects),'backend':backend,'pg_identity':pg_identity,'pg_restore_database':args.pg_restore_database,'units':sorted(set(args.stop_unit)),'previous_sha256':recovery.digest(previous),'pin':pin}
    jp=store/'install.json'; marker=data/'update-maintenance.json'; txs=store/'transactions'; txs.mkdir(mode=0o700,exist_ok=True); directory(txs,True)
    with exclusive(state/'update.lock') as update_fd:
        j=read_json(jp) if jp.exists() else None
        if j is None or j['phase'] in FINAL:
            require(args.action=='install','No active installation')
            require(not marker.exists() and not marker.is_symlink(),'Existing maintenance marker')
            r=read_json(state/'report.json',131072); m=r['release']; p=r['package_result']
            require(r['status']=='planned' and r['operation']=='plan' and p['status']=='planned' and r['trust_pin']==pin,'Run updates:plan successfully first')
            age=time.time()-__import__('datetime').datetime.fromisoformat(r['checked_at'].replace('Z','+00:00')).timestamp()
            require(-60<=age<=86400,'Plan expired')
            source=r['installed_version']; source_schema=r['installed_schema']; target=m['version']; require(current(root)==source,'Plan does not match active release')
            require(p['previous_sha256']==identity['previous_sha256'] and p['source_schema']==source_schema and p['target_schema']==m['schema'],'Plan identity differs')
            for k,v in {'target_version':target,'target_sha256':m['package']['sha256'],'metadata_sha256':recovery.sha(recovery.canonical(m))}.items():
                require(k not in p or p[k]==v,'Plan target identity differs')
            j={**identity,'id':secrets.token_hex(16),'token':secrets.token_hex(32),'source':source,'source_schema':source_schema,'target':target,'target_schema':m['schema'],'package_sha256':m['package']['sha256'],'metadata_sha256':recovery.sha(recovery.canonical(m)),'events':[],'phase':'new'}
            fresh=True
        else:
            require(all(j.get(k)==v for k,v in identity.items()),'Installation parameters differ from journal'); fresh=False
        require(pkg.VERSION.fullmatch(j['source']) and pkg.VERSION.fullmatch(j['target']) and pkg.HEX.fullmatch(j['package_sha256']),'Invalid journal identity')
        old=releases/j['source']; new=releases/j['target']
        old_hash=payload(previous,j['source'],j['source_schema']); verify_tree(old,old_hash)
        rolling=args.action=='rollback' or j['phase']=='rollback_pending'
        if not rolling:
            folder=directory(store/j['package_sha256'],True); raw=folder/pkg.META; sig=folder/(pkg.META+'.sig')
            pkg.regular(raw,65536); pkg.verify_signature(raw,sig,key); m=read_json(raw,65536)
            require(recovery.digest(raw)==j['metadata_sha256'] and m['repository']==pkg.REPO and m['channel']=='stable' and m['tag']=='v'+j['target'] and m['version']==j['target'] and m['schema']==j['target_schema'] and m['release_key_sha256']==pin and m['package']['sha256']==j['package_sha256'] and m['package']['name']=='CajeerWorkspace-'+j['target']+'.zip','Signed release identity differs')
            require(tuple(map(int,j['target'].split('.')))>tuple(map(int,j['source'].split('.'))) and j['source_schema']<=j['target_schema'] and any(s=={'version':j['source'],'schema':j['source_schema'],'archive_sha256':identity['previous_sha256']} for s in m['supported_from']),'Unsupported signed transition')
            require(sys.version_info[:3]>=tuple(map(int,m['runtime']['python_min'].split('.'))),'Python below signed minimum')
            archive=folder/m['package']['name']; pkg.package_check(folder,m,raw.read_bytes(),sig.read_bytes(),key); new_hash=payload(archive,j['target'],j['target_schema'])
            require(all(new_hash.get(n)==h for n,h in old_hash.items() if n.startswith('database/') and n.endswith('.sql')),'Previously shipped migrations changed')
            manifest_code=r'''require $argv[1].'/app/bootstrap.php'; [$pem,$pin]=\Workspace\updateKey(); $raw=file_get_contents($argv[2]); $m=json_decode($raw,true,32,JSON_THROW_ON_ERROR); \Workspace\updateManifest($raw,file_get_contents($argv[3]),$pem,$pin,$m['version'],$m['commit']);'''; command(['php','-r',manifest_code,old,raw,sig],env,(update_fd,),120)
        require(current(root) in (j['source'],j['target']),'Unexpected active release')
        if fresh:
            environment(old,env,(update_fd,),raw,sig,key,m)
            (txs/j['id']).mkdir(mode=0o700); recovery.fsync_dir(txs); save(state,j,'quiescing')
        stop_units(j['units'],env,(update_fd,))
        with exclusive(data/'update-access.lock') as access_fd:
            fds=(update_fd,access_fd)
            if marker.exists() or marker.is_symlink():
                require(read_json(marker,4096)=={'token':j['token'],'transaction':j['id']},'Maintenance marker belongs to another operation')
            else:
                require(j['phase'] in ('quiescing','completed_pending','rollback_pending'),'Missing persistent maintenance marker')
                atomic_json(marker,{'token':j['token'],'transaction':j['id']})
            env['WORKSPACE_UPDATE_MAINTENANCE_TOKEN']=j['token']
            tx=directory(txs/j['id'],True)
            if j['phase']=='quiescing': save(state,j,'guarded')
            if args.action=='rollback':
                if 'backup_sha256' in j:
                    before=backup_verify(old,tx/j['backup_dir'],j['backup_sha256'],env,fds)
                    require(snapshot(old,backend,data,objects,env,fds)==before,'Rollback requires exact restored pre-update DB and objects; keep writers stopped')
                else:
                    require(j['phase'] in ('guarded','backup_started') and current(root)==j['source'],'No verified backup after mutation; keep writers stopped')
                    snapshot(old,backend,data,objects,env,fds)  # integrity/ledger/objects, no installer mutation yet
                smoke(old,j['source'],j['source_schema'],env,fds); verify_tree(old,old_hash); switch(root,j['source']); save(state,j,'rollback_pending')
            elif args.action=='finish':
                require(j['phase'] in ('switched','completed_pending','rollback_pending'),'Installation has not reached a verified switch')
                if j['phase']=='rollback_pending':
                    require(current(root)==j['source'],'Rollback pointer differs'); verify_tree(old,old_hash); smoke(old,j['source'],j['source_schema'],env,fds)
                    if 'backup_sha256' in j:
                        before=backup_verify(old,tx/j['backup_dir'],j['backup_sha256'],env,fds); require(snapshot(old,backend,data,objects,env,fds)==before,'Rollback state changed')
                    else: snapshot(old,backend,data,objects,env,fds)
                else:
                    require(current(root)==j['target'],'Current pointer differs'); verify_tree(new,new_hash); smoke(new,j['target'],j['target_schema'],env,fds)
                    before=backup_verify(old,tx/j['backup_dir'],j['backup_sha256'],env,fds); controls(before,snapshot(new,backend,data,objects,env,fds),j['target_schema']); save(state,j,'completed_pending')
            else:
                require(j['phase'] not in ('rollback_pending','completed_pending'),'Use finish to release maintenance')
                if 'backup_sha256' not in j:
                    require(current(root)==j['source'],'Backup must precede switching'); environment(old,env,fds,raw,sig,key,m)
                    j['backup_dir']='backup-'+secrets.token_hex(8); save(state,j,'backup_started'); backup=tx/j['backup_dir']
                    b=json.loads(command([sys.executable,'-B',old/'tools/recovery.py','backup','--backend',backend,'--data',data,'--objects',objects,'--destination',backup],env,fds))
                    j['backup_sha256']=b['manifest_sha256']; before=backup_verify(old,backup,j['backup_sha256'],env,fds)
                    require(snapshot(old,backend,data,objects,env,fds)==before,'Live data changed after backup'); save(state,j,'backed_up')
                backup=tx/j['backup_dir']; before=backup_verify(old,backup,j['backup_sha256'],env,fds)
                if backend=='pgsql' and not j.get('pg_restore_verified'):
                    # No destructive action against the source database. Existing restore tool refuses nonempty target.
                    test_env=dict(env,PGDATABASE=args.pg_restore_database); dest=tx/('restore-test-'+secrets.token_hex(8))
                    rr=json.loads(command([sys.executable,'-B',old/'tools/recovery.py','restore','--backup',backup,'--manifest-sha256',j['backup_sha256'],'--destination',dest],test_env,fds))
                    require(rr['status']=='restored_verified_offline','PostgreSQL restore rehearsal failed'); j['pg_restore_verified']=True; save(state,j,'backed_up')
                stage_tree(releases,archive,j['target'],new_hash)
                if j['phase'] not in ('migrating','migrated','switched'):
                    require(snapshot(old,backend,data,objects,env,fds)==before,'Live data differs from backup'); save(state,j,'staged'); save(state,j,'migrating')
                if j['phase']=='migrating':
                    # On restart, only proceed from the exact predecessor snapshot or a fully verified target schema.
                    try: live=snapshot(old,backend,data,objects,env,fds)
                    except (ValueError,subprocess.SubprocessError): live=None
                    if live is not None:
                        require(live==before,'Source data changed during installation')
                        php(new,['upgrade'],env,fds)
                    smoke(new,j['target'],j['target_schema'],env,fds); controls(before,snapshot(new,backend,data,objects,env,fds),j['target_schema']); save(state,j,'migrated')
                verify_tree(new,new_hash); smoke(new,j['target'],j['target_schema'],env,fds)
                controls(before,snapshot(new,backend,data,objects,env,fds),j['target_schema']); switch(root,j['target']); save(state,j,'switched')
            if j['phase'] in ('completed_pending','rollback_pending'):
                # Explicit operator action only; units remain stopped until the operator starts them.
                marker.unlink(); recovery.fsync_dir(data); save(state,j,'rolled_back' if j['phase']=='rollback_pending' else 'completed')
            return {'status':j['phase'],'transaction':j['id'],'current':current(root),'backup_sha256':j.get('backup_sha256'),'service_ready':False,'maintenance_released':j['phase'] in FINAL}


def main():
    p=argparse.ArgumentParser(description=__doc__); p.add_argument('action',choices=['install','finish','rollback','status']); p.add_argument('--root',type=Path); p.add_argument('--previous',type=Path); p.add_argument('--data',type=Path); p.add_argument('--objects',type=Path); p.add_argument('--stop-unit',action='append',default=[]); p.add_argument('--offline-confirmed',action='store_true'); p.add_argument('--pg-restore-database'); a=p.parse_args(); os.umask(0o077)
    try:
        if a.action=='status':
            j=read_json(directory(Path(os.environ['WORKSPACE_UPDATE_STATE_DIR']))/'packages'/'install.json'); print(json.dumps({k:v for k,v in j.items() if k not in ('token','pg_identity')},indent=2)); return 0
        require(all(x is not None for x in (a.root,a.previous,a.data,a.objects)),'Provide root, previous, data and objects')
        print(json.dumps(install(a),indent=2)); return 0
    except (OSError,ValueError,KeyError,TypeError,RuntimeError,subprocess.SubprocessError,zipfile.BadZipFile) as e:
        print(json.dumps({'status':'failed','reason':str(e)[:400],'instruction':'Keep writers stopped; inspect status and journal; never remove maintenance marker manually.'}),file=sys.stderr); return 1

if __name__=='__main__': sys.exit(main())
