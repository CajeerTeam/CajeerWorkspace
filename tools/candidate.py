#!/usr/bin/env python3
"""Offline release switch for the schema-33 release candidate (POSIX)."""
import argparse
import fcntl
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile
import zipfile
from contextlib import contextmanager
import recovery
import release

OLD, NEW = '0.9.1', '0.9.2'
SCHEMA = 33
FREEZE = json.loads((release.ROOT / 'database/schema-freeze.json').read_text())


def require(condition, message):
    if not condition:
        raise ValueError(message)


def command(args, env=None):
    result = subprocess.run([str(x) for x in args], env=env, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, timeout=120, check=False)
    # Never echo provider credentials, PHP error bodies or SQL from subprocesses.
    require(result.returncode == 0, f'{Path(str(args[0])).name} command failed ({result.returncode})')
    return result.stdout


def archive_payload(path, version):
    require(path.name == f'CajeerWorkspace-{version}.zip', 'Unexpected archive name')
    with zipfile.ZipFile(path) as z:
        infos = z.infolist()
        names = [i.filename for i in infos]
        require(len(set(names)) == len(names) and 'SHA256SUMS' in names, 'Missing or duplicate manifest')
        require(all(release.safe_name(i.filename) and not i.is_dir() and
                    stat.S_IFMT(i.external_attr >> 16) == stat.S_IFREG and i.file_size <= release.MAX_FILE
                    for i in infos) and sum(i.file_size for i in infos) <= release.MAX_TOTAL, 'Unsafe archive')
        raw = {i.filename: z.read(i) for i in infos}
    sums = raw.pop('SHA256SUMS')
    require(sums == ''.join(f'{release.sha(data)}  {name}\n' for name, data in sorted(raw.items())).encode('ascii'), 'Archive manifest mismatch')
    require(raw['VERSION'].decode('ascii').strip() == version and
            re.search(r"const VERSION = '" + re.escape(version) + r"';", raw['app/Core.php'].decode()) and
            re.search(r'const SCHEMA_VERSION = 33;', raw['app/Core.php'].decode()), 'Incompatible release')
    migrations = {n: release.sha(v) for n, v in raw.items() if n.startswith('database/') and n.endswith('.sql')}
    require(FREEZE == {'schema': SCHEMA, 'migrations': migrations}, 'Frozen migrations differ')
    if version == NEW:
        require(release.validate_payload(raw) == NEW, 'Invalid candidate inventory')
    raw['SHA256SUMS'] = sums
    return raw


def verify_signature(path, signature, public_key, key_sha):
    require(recovery.HEX.fullmatch(key_sha) and recovery.digest(public_key) == key_sha, 'Untrusted public key')
    require(signature.is_file() and not signature.is_symlink() and signature.stat().st_size <= 16384, 'Invalid detached signature')
    command(['openssl', 'dgst', '-sha256', '-verify', public_key, '-signature', signature, path])


def sign(path, private_key, output):
    archive_payload(path, NEW)
    release.verify(path)
    require(private_key.is_file() and not private_key.is_symlink() and private_key.stat().st_mode & 0o077 == 0, 'Private key must be mode 0600 or stricter')
    require(not output.exists() and not output.is_symlink(), 'Signature already exists')
    # openssl reads the passphrase from terminal if the operator uses an encrypted key.
    # Use a subprocess with inherited terminal, no secret in command arguments.
    with tempfile.TemporaryDirectory(dir=output.parent) as temp:
        sig = Path(temp)/'signature'
        subprocess.run(['openssl','dgst','-sha256','-sign',str(private_key),'-out',str(sig),str(path)],check=True,timeout=120)
        release.publish(output, sig.read_bytes())
    return {'signature': str(output), 'archive_sha256': recovery.digest(path)}


def write_tree(folder, payload):
    folder.mkdir(mode=0o700)
    for name, data in sorted(payload.items()):
        target = folder/name
        target.parent.mkdir(parents=True, exist_ok=True)
        recovery.write(target, data)
        target.chmod(0o500 if name == 'bin/workspace' else 0o400)
    for d in sorted((p for p in folder.rglob('*') if p.is_dir()), key=lambda p: len(p.parts), reverse=True):
        recovery.fsync_dir(d)
    recovery.fsync_dir(folder)


def check_tree(folder, payload):
    require(folder.is_dir() and not folder.is_symlink() and folder.stat().st_mode & 0o022 == 0, 'Unsafe release directory')
    actual = set()
    for p in folder.rglob('*'):
        require(not p.is_symlink() and p.stat().st_mode & 0o022 == 0, 'Unexpected symbolic link or writable release member')
        if p.is_dir():
            continue
        require(p.is_file(), 'Unsafe release member')
        name = p.relative_to(folder).as_posix()
        require(name in payload and recovery.digest(p) == release.sha(payload[name]), 'Release tree modified')
        actual.add(name)
    require(actual == set(payload), 'Release tree incomplete')


def managed_directory(path):
    # Permit an explicitly configured read-only runtime group, but never group write
    # or world access. State and backup directories still require strict 0700.
    require(path.is_absolute() and path.is_dir() and not path.is_symlink(), 'Unsafe managed directory')
    require(path.stat().st_mode & 0o027 == 0, 'Managed directory must not be group-writable or public')
    return path.resolve()


def pointer(root):
    p = root/'current'
    require(p.is_symlink(), 'Missing managed current link')
    target = os.readlink(p)
    require(target in ('releases/'+OLD, 'releases/'+NEW), 'Unexpected current link')
    return target.split('/')[-1]


def set_pointer(root, version):
    with tempfile.TemporaryDirectory(dir=root) as d:
        link = Path(d)/'next'
        link.symlink_to('releases/'+version)
        os.replace(link, root/'current')
        recovery.fsync_dir(root)


def prepare(root, previous, previous_sha, candidate, signature, public_key, key_sha):
    require(recovery.HEX.fullmatch(previous_sha) and recovery.digest(previous)==previous_sha, 'Untrusted previous release')
    verify_signature(candidate, signature, public_key, key_sha)
    candidate_sha = recovery.digest(candidate)
    old, new = archive_payload(previous, OLD), archive_payload(candidate, NEW)
    require(recovery.digest(candidate)==candidate_sha, "Candidate changed during inspection")
    root = recovery.new_directory(root)
    releases = root/'releases'; releases.mkdir(mode=0o700)
    # Keep the exact trusted package bytes inside the private deployment root. No mutable configuration in code trees.
    for path, filename in [(previous,'previous.zip'),(candidate,'candidate.zip'),(signature,'candidate.sig'),(public_key,'release.pub')]:
        recovery.write(root/filename, path.read_bytes())
    # Verify the actual stored bytes, not just the source at time of inspection.
    require(recovery.digest(root/'previous.zip') == previous_sha, 'Previous release changed during preparation')
    verify_signature(root/'candidate.zip', root/'candidate.sig', root/'release.pub', key_sha)
    require(recovery.digest(root/'candidate.zip') == candidate_sha, 'Candidate changed during preparation')
    write_tree(releases/OLD, old); write_tree(releases/NEW, new)
    recovery.fsync_dir(releases)
    recovery.write(root/'deployment.json', recovery.canonical({'previous_sha256':previous_sha,'candidate_sha256':recovery.digest(root/'candidate.zip'),'public_key_sha256':key_sha}))
    verify_trees(root)
    set_pointer(root, OLD)
    return {'status':'prepared','current':OLD,'schema':SCHEMA,'previous_sha256':previous_sha,'candidate_sha256':recovery.digest(root/'candidate.zip')}


@contextmanager
def lock(root):
    fd = os.open(root/'switch.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        yield
    finally:
        os.close(fd)


def stored_payload(root, version):
    # Use an expected ZIP name in an isolated temporary directory; no executable code extracted here.
    manifest = json.loads((root/'deployment.json').read_bytes())
    name, hash_key = ('previous.zip','previous_sha256') if version == OLD else ('candidate.zip','candidate_sha256')
    path = root/name
    require(recovery.digest(path) == manifest[hash_key], 'Stored package digest differs')
    if version == NEW:
        verify_signature(path,root/'candidate.sig',root/'release.pub',manifest['public_key_sha256'])
    with tempfile.TemporaryDirectory(dir=root) as d:
        renamed = Path(d)/f'CajeerWorkspace-{version}.zip'
        os.link(path, renamed)
        return archive_payload(renamed, version)


def verify_trees(root):
    for version in (OLD,NEW):
        check_tree(root/'releases'/version, stored_payload(root,version))


def live_snapshot(backend, data, objects):
    if backend == 'sqlite':
        return recovery.sqlite_snapshot(data/'workspace.sqlite',objects)
    return recovery.pg_snapshot(objects,data)


def smoke(folder, version, env, upgrade=False):
    cli = ['php',folder/'bin/workspace']
    require(command(cli+['version'],env).decode().strip()==version,'Unexpected executable version')
    require(json.loads(command(cli+['doctor'],env))['ok'],'PHP runtime requirements failed')
    def ledger():
        m=json.loads(command(cli+['migrations'],env))
        require(m['current']==SCHEMA and m['target']==SCHEMA and not m['pending'],'Schema not frozen')
    ledger()
    if upgrade:
        command(cli+['upgrade'],env)
        ledger()


def switch(root, target, backup, backup_sha, data, objects):
    require(os.environ.get('WORKSPACE_RECOVERY_OFFLINE')=='1','Stop ALL writers and set WORKSPACE_RECOVERY_OFFLINE=1')
    root = managed_directory(root); data = recovery.private_dir(data); objects = recovery.private_dir(objects)
    for path in (data,objects):
        require(path!=root and root not in path.parents and path not in root.parents,'Persistent state must be outside managed releases')
    require(not (data/'recovery.lock').exists() and not (data/'recovery.lock').is_symlink(), 'Target is in recovery quarantine')
    with lock(root):
        verify_trees(root)
        active = pointer(root)
        m, _, _ = recovery.verify(backup,backup_sha)
        require(m['version']==OLD and m['snapshot']['schema']==SCHEMA,'Requires verified pre-upgrade 0.9.1 backup')
        backend=m['backend']
        require((os.environ.get('WORKSPACE_DB') or 'sqlite')==backend,'Database backend mismatch')
        env=dict(os.environ,WORKSPACE_DATA=str(data),WORKSPACE_OBJECT_DIR=str(objects),WORKSPACE_DB=backend)
        # Bind snapshots to the exact PG connection provided in the environment. No automatic data overwrite.
        if backend=='pgsql': recovery.pg_env(objects)
        identity={'backup_sha256':backup_sha,'backend':backend,'data':str(data),'objects':str(objects)}
        if backend=='pgsql': identity['pg_target_sha256']=recovery.sha((':'.join(os.environ[k] for k in ('PGHOST','PGPORT','PGDATABASE','PGUSER'))).encode())
        journal=root/'upgrade.json'
        if journal.exists() or journal.is_symlink():
            require(not journal.is_symlink() and json.loads(journal.read_bytes())==identity,'Backup or target differs from recorded upgrade')
        else:
            require(active==OLD and target==NEW,'No recorded upgrade to roll back')
        before=live_snapshot(backend,data,objects)
        require(recovery.canonical(before)==recovery.canonical(m['snapshot']),'Live state differs from pre-upgrade backup; do not switch. Use reviewed recovery.')
        smoke(root/'releases'/active,active,env)
        smoke(root/'releases'/target,target,env,upgrade=target==NEW)
        require(recovery.canonical(live_snapshot(backend,data,objects))==recovery.canonical(before),'State changed during offline smoke; current link not changed')
        verify_trees(root)
        if not journal.exists():
            recovery.write(journal,recovery.canonical(identity))
            recovery.fsync_dir(root)
        set_pointer(root,target)
        return {'status':'switched','previous':active,'current':target,'schema':SCHEMA,'snapshot_match':True,'backup_sha256':backup_sha,'service_ready':False}


def main():
    parser=argparse.ArgumentParser(description=__doc__); sub=parser.add_subparsers(dest='action',required=True)
    p=sub.add_parser('sign'); p.add_argument('archive',type=Path); p.add_argument('--private-key',type=Path,required=True); p.add_argument('--output',type=Path,required=True)
    p=sub.add_parser('verify-signature'); p.add_argument('archive',type=Path); p.add_argument('--signature',type=Path,required=True); p.add_argument('--public-key',type=Path,required=True); p.add_argument('--key-sha256',required=True)
    p=sub.add_parser('prepare'); p.add_argument('--root',type=Path,required=True); p.add_argument('--previous',type=Path,required=True); p.add_argument('--previous-sha256',required=True); p.add_argument('--candidate',type=Path,required=True); p.add_argument('--signature',type=Path,required=True); p.add_argument('--public-key',type=Path,required=True); p.add_argument('--key-sha256',required=True)
    for action in ('upgrade','rollback'):
        p=sub.add_parser(action); p.add_argument('--root',type=Path,required=True); p.add_argument('--backup',type=Path,required=True); p.add_argument('--backup-sha256',required=True); p.add_argument('--data',type=Path,required=True); p.add_argument('--objects',type=Path,required=True)
    a=parser.parse_args(); os.umask(0o077)
    try:
        if a.action=='sign': r=sign(a.archive,a.private_key,a.output)
        elif a.action=='verify-signature':
            verify_signature(a.archive,a.signature,a.public_key,a.key_sha256); archive_payload(a.archive,NEW); r={'status':'signature_verified','version':NEW,'archive_sha256':recovery.digest(a.archive)}
        elif a.action=='prepare': r=prepare(a.root,a.previous,a.previous_sha256,a.candidate,a.signature,a.public_key,a.key_sha256)
        else: r=switch(a.root,NEW if a.action=='upgrade' else OLD,a.backup,a.backup_sha256,a.data,a.objects)
        print(json.dumps(r,sort_keys=True)); return 0
    except (OSError,ValueError,KeyError,RuntimeError,subprocess.SubprocessError,zipfile.BadZipFile) as e:
        print('Candidate error: '+str(e),file=sys.stderr); return 1


if __name__=='__main__': sys.exit(main())
