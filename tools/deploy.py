#!/usr/bin/env python3
"""Local Linux deployment executor. All paths and probes come from an operator allowlist."""
import argparse
import contextlib
import fcntl
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import sys
import tempfile
import time
import urllib.parse
import urllib.request
import zipfile

MAX_ZIP = 256 * 1024 * 1024
MAX_EXPANDED = 1024 * 1024 * 1024
MARKER = '.workspace-release.json'
HEX = re.compile(r'[a-f0-9]{64}\Z')
SHA = re.compile(r'[a-f0-9]{40}\Z')

class DeploymentError(Exception):
    pass

def require(ok, message):
    if not ok:
        raise DeploymentError(message)

def read_json(path):
    require(path.is_file() and not path.is_symlink(), 'Missing or unsafe JSON file')
    with path.open('rb') as f:
        data = f.read(65537)
    require(len(data) <= 65536, 'JSON file exceeds limit')
    return json.loads(data)

def sync_dir(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)

def write_json(path, data):
    with tempfile.NamedTemporaryFile(dir=path.parent, prefix='.journal-', delete=False) as f:
        tmp = Path(f.name)
        try:
            f.write(json.dumps(data, sort_keys=True, separators=(',', ':')).encode())
            f.flush(); os.fsync(f.fileno()); f.close()
            os.replace(tmp, path); sync_dir(path.parent)
        finally:
            tmp.unlink(missing_ok=True)

def trusted_dir(value):
    p = Path(value)
    require(p.is_absolute() and p.resolve() == p and p.is_dir(), 'Target path must be an existing canonical directory')
    require(not (p.stat().st_mode & 0o022), 'Target directory is writable by group/others')
    return p

def target(config, identity):
    require(config.is_absolute() and config.resolve() == config and not (config.stat().st_mode & 0o022), 'Unsafe config file')
    cfg = read_json(config)
    entry = cfg.get('targets', {}).get(str(identity['site_id']))
    require(isinstance(entry, dict) and entry.get('identity') == identity, 'Target not allowlisted or binding changed')
    root = trusted_dir(entry['root']); spool = trusted_dir(entry['spool'])
    require(root != spool and root not in spool.parents and spool not in root.parents, 'Spool must be separate from target root')
    require(root.stat().st_uid == os.geteuid(), 'Executor must own target root')
    for name in ('releases', 'journal'):
        p = root / name; p.mkdir(mode=0o755 if name == 'releases' else 0o700, exist_ok=True)
        trusted_dir(str(p)); require(p.stat().st_uid == os.geteuid(), 'Unsafe target ownership')
    url = entry['health_url']; u = urllib.parse.urlsplit(url)
    require(bool(u.hostname) and not u.username and not u.password and not u.fragment and (u.scheme == 'https' or (u.scheme == 'http' and u.hostname in ('127.0.0.1', '::1', 'localhost'))), 'Health URL must be HTTPS or loopback HTTP')
    return root, spool, url

@contextlib.contextmanager
def lock(root):
    fd = os.open(root / '.deploy.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        require(stat.S_ISREG(os.fstat(fd).st_mode), 'Unsafe lock file')
        try: fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError: raise DeploymentError('Target is busy; retry recovery after original worker stops')
        yield
    finally:
        os.close(fd)

def valid_meta(root, digest):
    require(isinstance(digest, str) and HEX.fullmatch(digest), 'Invalid release digest')
    path = root / 'releases' / digest
    require(path.is_dir() and not path.is_symlink(), 'Release directory is absent')
    meta = read_json(path / MARKER)
    require(meta.get('digest') == digest and isinstance(meta.get('commit'), str) and SHA.fullmatch(meta['commit']), 'Invalid release marker')
    return meta

def current(root):
    p = root / 'current'
    require(p.is_symlink(), 'Current must be a symlink to a prepared baseline')
    value = os.readlink(p)
    require(value.startswith('releases/') and HEX.fullmatch(value[9:]), 'Current points outside the managed releases')
    return valid_meta(root, value[9:])

def switch(root, digest):
    valid_meta(root, digest)
    temp = root / ('.switch-' + os.urandom(12).hex())
    try:
        temp.symlink_to('releases/' + digest)
        os.replace(temp, root / 'current'); sync_dir(root)
    finally:
        temp.unlink(missing_ok=True)

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

def healthy(url, commit):
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    for n in range(3):
        try:
            req = urllib.request.Request(url, headers={'Accept':'application/json','Cache-Control':'no-cache', 'User-Agent':'CajeerWorkspace-Deploy/0.4.3'})
            with opener.open(req, timeout=5) as res:
                raw = res.read(8193)
                if res.status == 200 and len(raw) <= 8192:
                    data = json.loads(raw)
                    if isinstance(data, dict) and data.get('ready') is True and data.get('commit_sha') == commit:
                        return True
        except (OSError, ValueError, TypeError):
            pass
        if n < 2: time.sleep(1)
    return False

def tree_digest(root):
    manifest = {}
    for p in root.rglob('*'):
        relative = p.relative_to(root).as_posix()
        require(not p.is_symlink(), 'Unexpected symlink in staged release')
        if p.is_dir(): manifest[relative] = 'directory'
        else:
            require(p.is_file(), 'Unexpected special file in staged release')
            h = hashlib.sha256()
            with p.open('rb') as f:
                for chunk in iter(lambda: f.read(1024*1024), b''): h.update(chunk)
            manifest[relative] = h.hexdigest()
    return manifest

def stage(root, spool, digest, commit, size):
    require(HEX.fullmatch(digest) and SHA.fullmatch(commit) and type(size) is int and 0 < size <= MAX_ZIP, 'Invalid artifact identity')
    final = root / 'releases' / digest
    # Copy to an immutable private temporary file before hashing/extracting (no hash/read race).
    with tempfile.TemporaryDirectory(prefix='.stage-', dir=root / 'releases') as tmp:
        tmp = Path(tmp); archive = tmp / 'artifact.zip'; tree = tmp / 'tree'; tree.mkdir(mode=0o755)
        fd = os.open(spool / (digest + '.zip'), os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        with os.fdopen(fd, 'rb') as src, archive.open('wb') as dest:
            st = os.fstat(src.fileno()); require(stat.S_ISREG(st.st_mode) and st.st_size == size, 'Artifact size mismatch')
            h = hashlib.sha256(); count = 0
            for chunk in iter(lambda: src.read(1024*1024), b''):
                count += len(chunk); require(count <= size, 'Artifact size mismatch'); h.update(chunk); dest.write(chunk)
            require(count == size and h.hexdigest() == digest, 'Artifact SHA-256 mismatch')
        with zipfile.ZipFile(archive) as z:
            entries = z.infolist(); require(0 < len(entries) <= 20000, 'Archive entry limit')
            seen = set(); total = 0
            for item in entries:
                name = item.filename.rstrip('/'); parts = name.split('/')
                require(name and len(name) <= 1024 and all(re.fullmatch(r'[A-Za-z0-9_.@+ -]{1,255}', p) and p not in ('.','..',MARKER) for p in parts), 'Unsafe ZIP path')
                require(name not in seen, 'Duplicate ZIP entry'); seen.add(name)
                mode = stat.S_IFMT(item.external_attr >> 16)
                require(mode in (0, stat.S_IFDIR if item.is_dir() else stat.S_IFREG) and not item.flag_bits & 1, 'Symlink, special or encrypted ZIP entry')
                total += item.file_size; require(total <= MAX_EXPANDED, 'Expanded archive limit')
                out = tree.joinpath(*parts)
                if item.is_dir(): out.mkdir(mode=0o755, parents=True, exist_ok=True); continue
                out.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
                with z.open(item) as source, out.open('xb') as dest:
                    shutil.copyfileobj(source, dest, 1024*1024); dest.flush(); os.fsync(dest.fileno())
                out.chmod(0o644)
        require(any(p.is_file() for p in tree.rglob('*')), 'Empty artifact')
        write_json(tree / MARKER, {'commit':commit,'digest':digest}); (tree / MARKER).chmod(0o644)
        for directory, _, _ in os.walk(tree, topdown=False): sync_dir(Path(directory))
        if final.exists() or final.is_symlink():
            require(valid_meta(root,digest)['commit']==commit and tree_digest(final)==tree_digest(tree), 'Previously staged release differs from approved artifact')
            return
        os.rename(tree, final); sync_dir(final.parent)

def result(state, message, before='', after=''):
    return {'state':state,'message':message,'before':before,'after':after}

def terminal(path, journal, outcome):
    journal['outcome'] = outcome; write_json(path, journal); return outcome

def rollback(root, url, path, journal):
    old = journal['before']; new = journal['after']
    try:
        live = current(root)
        require(live['digest'] in (old['digest'], new['digest']), 'Unexpected current release; manual repair required')
        if live['digest'] != old['digest']: switch(root, old['digest'])
        require(healthy(url, old['commit']), 'Previous version failed readiness after rollback')
        return terminal(path, journal, result('rolled_back','Previous release restored and readiness confirmed',old['commit'],old['commit']))
    except Exception:
        return result('recovery_required','Rollback could not be verified; inspect target and retry recovery',old['commit'],'')

def execute(config, job, recover=False):
    require(isinstance(job.get('key'), str) and HEX.fullmatch(job['key']), 'Invalid execution key')
    root, spool, url = target(config, job['target'])
    with lock(root):
        path = root / 'journal' / (job['key'] + '.json')
        if path.exists():
            journal = read_json(path)
            require(journal.get('key') == job['key'] and journal['after'] == {'commit':job['commit'],'digest':job['digest']} and journal.get('target') == job['target'], 'Journal identity mismatch')
            if 'outcome' in journal:
                outcome = journal['outcome']
                if outcome['state'] in ('succeeded','rolled_back'):
                    expected = journal['after'] if outcome['state']=='succeeded' else journal['before']
                    require(current(root)==expected and healthy(url,expected['commit']), 'Recorded outcome no longer matches a healthy target')
                return outcome
            return rollback(root,url,path,journal)
        if recover:
            # A missing journal is ambiguous (wrong host or lost disk). Never assume nothing happened.
            return result('recovery_required','Local journal absent; verify original host and restore journal before recovery')
        old = current(root); new = {'commit':job['commit'],'digest':job['digest']}
        journal = {'key':job['key'],'target':job['target'],'before':old,'after':new}
        write_json(path,journal) # durable intent before any live modification
        try:
            require(job.get('not_before',0) <= time.time() < job['deadline'], 'Outside maintenance window')
            require(healthy(url,old['commit']), 'Baseline readiness failed; no deployment attempted')
            stage(root,spool,job['digest'],job['commit'],job['size'])
            require(time.time() < job['deadline'], 'Maintenance window expired before activation')
        except Exception as e:
            message=str(e) if isinstance(e,DeploymentError) else 'Artifact could not be staged'
            return terminal(path,journal,result('failed',message+'; current unchanged',old['commit'],old['commit']))
        try:
            switch(root,new['digest'])
            if healthy(url,new['commit']):
                return terminal(path,journal,result('succeeded','Artifact activated and readiness confirmed',old['commit'],new['commit']))
        except Exception:
            pass
        return rollback(root,url,path,journal)

def main():
    os.umask(0o022)
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('action',choices=['deploy','recover','prepare']);parser.add_argument('--config',type=Path,required=True);args=parser.parse_args()
    try:
        raw=sys.stdin.buffer.read(65537);require(len(raw)<=65536,'Input limit');job=json.loads(raw)
        if args.action=='prepare':
            root,spool,_=target(args.config,job['target'])
            with lock(root): stage(root,spool,job['digest'],job['commit'],job['size'])
            out={'state':'prepared','message':'Verified baseline staged; no live switch'}
        else: out=execute(args.config,job,args.action=='recover')
        print(json.dumps(out));return 0
    except (Exception, KeyboardInterrupt):
        # No secrets, paths or untrusted upstream data in the application log.
        print('Executor preflight or journal error; inspect configuration and local journal.',file=sys.stderr);return 1
if __name__=='__main__': sys.exit(main())
