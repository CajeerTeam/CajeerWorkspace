#!/usr/bin/env python3
"""Read signed Workspace packages without extracting or executing downloaded code.
Invoked by the trusted Workspace CLI, never by the web application.
"""
import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import ssl
import stat
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile
import release

REPO = 'CajeerTeam/CajeerWorkspace'
LIMIT = 128 * 1024 * 1024
META = 'workspace-release.json'
HEX = re.compile(r'[a-f0-9]{64}')
VERSION = re.compile(r'(?:0|[1-9][0-9]{0,5})(?:\.(?:0|[1-9][0-9]{0,5})){2}')


def require(ok, message):
    if not ok:
        raise ValueError(message)


def allowed(url, initial=False):
    try:
        p = urllib.parse.urlsplit(url)
        if p.scheme != 'https' or p.username is not None or p.password is not None or p.port is not None or p.fragment:
            return False
        if p.netloc == 'github.com':
            return bool(re.fullmatch(r'/' + REPO + r'/releases/download/v[0-9]+\.[0-9]+\.[0-9]+/CajeerWorkspace-[0-9]+\.[0-9]+\.[0-9]+\.zip(?:\.sig)?', p.path)) and not p.query
        return not initial and p.netloc in ('release-assets.githubusercontent.com', 'objects.githubusercontent.com') and bool(p.path)
    except ValueError:
        return False


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def fetch(url, destination, size, digest, opener=None):
    require(allowed(url, True) and 0 < size <= LIMIT and HEX.fullmatch(digest), 'Invalid asset request')
    opener = opener or urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context()))
    deadline = time.monotonic() + 180
    for _ in range(5):
        require(allowed(url) and time.monotonic() < deadline, 'Invalid redirect or download timeout')
        req = urllib.request.Request(url, headers={'User-Agent':'CajeerWorkspace-updater', 'Accept':'application/octet-stream', 'Accept-Encoding':'identity'})
        try:
            response = opener.open(req, timeout=15)
        except urllib.error.HTTPError as e:
            with e:
                if e.code in (301,302,303,307,308):
                    url = e.headers.get('Location', '')
                    continue
                raise ValueError('GitHub asset HTTP '+str(e.code)) from None
        except (urllib.error.URLError, TimeoutError):
            raise ValueError('GitHub asset network/TLS failure') from None
        with response:
            require(response.status == 200 and response.headers.get('Content-Encoding', 'identity') == 'identity', 'Unexpected asset response')
            length = response.headers.get('Content-Length')
            require(length is None or length == str(size), 'Asset Content-Length mismatch')
            h = hashlib.sha256(); count = 0
            with destination.open('xb') as out:
                while True:
                    require(time.monotonic() < deadline, 'Download timeout')
                    reader = getattr(response, 'read1', response.read)
                    chunk = reader(min(1024 * 1024, size - count + 1))
                    if not chunk: break
                    count += len(chunk)
                    require(count <= size, 'Asset exceeds signed size')
                    h.update(chunk); out.write(chunk)
                require(count == size and h.hexdigest() == digest, 'Asset SHA-256 or size mismatch')
                out.flush(); os.fsync(out.fileno())
        return
    raise ValueError('Too many redirects')


def regular(path, limit):
    require(path.is_file() and not path.is_symlink() and 0 < path.stat().st_size <= limit, 'Missing or unsafe file: '+path.name)


def verify_signature(path, signature, key):
    regular(signature, 16384)
    r = subprocess.run(['openssl','dgst','-sha256','-verify',str(key),'-signature',str(signature),str(path)], capture_output=True, timeout=30)
    require(r.returncode == 0, 'Detached signature verification failed: '+path.name)


def archive_index(path, version, schema, matrix_hash=None, runtime=None):
    regular(path, LIMIT)
    require(path.name == 'CajeerWorkspace-'+version+'.zip', 'Unexpected package filename')
    with zipfile.ZipFile(path) as z:
        entries = z.infolist(); names = [e.filename for e in entries]; name_set=set(names)
        require(0 < len(entries) <= 10000 and len(names) == len(name_set) and 'SHA256SUMS' in names, 'Duplicate or missing package entries')
        require(sum(e.file_size for e in entries) <= LIMIT, 'Uncompressed package exceeds limit')
        require(all(release.safe_name(e.filename) and stat.S_IFMT(e.external_attr >> 16) == stat.S_IFREG and not e.is_dir() and not e.flag_bits & 1 and e.file_size <= release.MAX_FILE for e in entries), 'Unsafe ZIP member')
        require(not any(str(p) in name_set for n in names for p in Path(n).parents if str(p) != '.'), 'Conflicting ZIP paths')
        hashes = {}; selected = {}
        needed = {'VERSION','app/Core.php','config/compatibility.json','database/schema-freeze.json','bin/workspace','public/index.php'}
        require(needed.issubset(names), 'Incomplete distribution')
        for e in entries:
            data = z.read(e)
            if e.filename in needed or e.filename == 'SHA256SUMS': selected[e.filename] = data
            if e.filename != 'SHA256SUMS': hashes[e.filename] = release.sha(data)
        require(selected['SHA256SUMS'] == ''.join(f'{h}  {n}\n' for n,h in sorted(hashes.items())).encode('ascii'), 'Internal package checksums mismatch')
    require(selected['VERSION'].decode('ascii').strip() == version and re.search(r"const VERSION = '"+re.escape(version)+r"';",selected['app/Core.php'].decode()) and re.search(r'const SCHEMA_VERSION = '+str(schema)+r';',selected['app/Core.php'].decode()), 'Package version/schema mismatch')
    matrix = json.loads(selected['config/compatibility.json'])
    require(matrix['version'] == version and matrix['schema'] == schema and (matrix_hash is None or hashes['config/compatibility.json'] == matrix_hash), 'Compatibility matrix mismatch')
    if runtime is not None:
        require(all(matrix['runtime'].get(k)==runtime[k] for k in ('php_min','sqlite_min','postgres_min','python_min','architecture')), 'Signed runtime does not match package matrix')
    migrations = {n:h for n,h in hashes.items() if n.startswith('database/') and n.endswith('.sql')}
    expected = {f'database/{i:03}.sql' for i in range(1,schema+1)} | {f'database/postgresql/{i:03}.sql' for i in range(1,schema+1)}
    require(set(migrations)==expected and json.loads(selected['database/schema-freeze.json']) == {'schema':schema,'migrations':migrations}, 'Invalid migration freeze')
    return hashes, migrations


def package_check(folder, m, raw, sig, key):
    require(folder.is_dir() and not folder.is_symlink() and not folder.stat().st_mode & 0o077, 'Unsafe package directory')
    p = m['package']; archive = folder / p['name']; signature = folder / (p['name']+'.sig')
    for name, data in ((META,raw),(META+'.sig',sig)):
        regular(folder/name,65536); require((folder/name).read_bytes()==data, 'Staged metadata changed')
    regular(archive,LIMIT); regular(signature,16384)
    require(archive.stat().st_size==p['size'] and release.file_sha(archive)==p['sha256'] and release.file_sha(signature)==p['signature_sha256'], 'Staged asset size or hash mismatch')
    verify_signature(archive,signature,key)
    return archive_index(archive,m['version'],m['schema'],m['matrix_sha256'],m['runtime'])


def plan(folder, m, raw, sig, key, previous, env, root):
    target, migrations = package_check(folder,m,raw,sig,key)
    sources = [s for s in m['supported_from'] if s['version']==env['version'] and s['schema']==env['schema']]
    require(len(sources)==1, 'No supported predecessor')
    regular(previous,LIMIT)
    require(release.file_sha(previous)==sources[0]['archive_sha256'], 'Predecessor SHA-256 mismatch')
    old, old_migrations = archive_index(previous,env['version'],env['schema'])
    require(all(migrations.get(n)==h for n,h in old_migrations.items()), 'Previously shipped migrations changed')
    for n,h in old.items():
        path=root/n
        require(not any(p.is_symlink() for p in [path,*path.parents] if p!=root and root in p.parents) and path.is_file() and release.file_sha(path)==h, 'Installed distribution differs: '+n)
    return {'status':'planned','target_version':m['version'],'target_sha256':m['package']['sha256'],'metadata_sha256':release.sha(raw),'previous_sha256':sources[0]['archive_sha256'], 'source_schema':env['schema'],'target_schema':m['schema'],
            'added':len(target.keys()-old.keys()),'removed':len(old.keys()-target.keys()),'changed':sum(target[n]!=old[n] for n in target.keys()&old.keys()),
            'pending_migrations':list(range(env['schema']+1,m['schema']+1)), 'python':'.'.join(map(str,sys.version_info[:3])), 'installation_performed':False,
            'required_actions':['Остановить все процессы записи и workers; установить блокировку обслуживания.','Создать и проверить резервные копии БД и файловых объектов.','Перед установкой повторно проверить среду, доверие, выпуск и исходный дистрибутив.','Запустить ручную установку по этому плану, проверить сервис и восстановление.']}


def run(action, store, envelope, previous=None, downloader=fetch, root=release.ROOT):
    raw=base64.b64decode(envelope['metadata'],validate=True); sig=base64.b64decode(envelope['signature'],validate=True); pem=base64.b64decode(envelope['key'],validate=True)
    require(0<len(raw)<=65536 and 0<len(sig)<=16384 and 0<len(pem)<=16384,'Invalid input sizes')
    m=json.loads(raw); p=m['package']; version=m['version']; env=envelope['environment']
    require(VERSION.fullmatch(version) and m['repository']==REPO and m['channel'] in ('stable','preview') and m['tag']=='v'+version and p['name']=='CajeerWorkspace-'+version+'.zip' and HEX.fullmatch(p['sha256']) and HEX.fullmatch(p['signature_sha256']) and type(p['size']) is int and 0<p['size']<=LIMIT and hashlib.sha256(pem).hexdigest()==m['release_key_sha256'], 'Invalid signed package identity')
    require(sys.version_info[:3]>=tuple(int(x) for x in m['runtime']['python_min'].split('.')), 'Python version below signed minimum')
    require(store.is_absolute() and store.resolve()==store and store.is_dir() and not store.stat().st_mode & 0o077,'Unsafe private package store')
    require(type(envelope['signature_size']) is int and 0<envelope['signature_size']<=16384, 'Invalid package signature size')
    folder=store/p['sha256']
    with tempfile.TemporaryDirectory(prefix='.stage-',dir=store) as tmp:
        stage=Path(tmp); key=stage/'key.pem'; key.write_bytes(pem)
        (stage/META).write_bytes(raw); (stage/(META+'.sig')).write_bytes(sig)
        verify_signature(stage/META,stage/(META+'.sig'),key)
        if action=='download':
            if not folder.exists() and not folder.is_symlink():
                base='https://github.com/'+REPO+'/releases/download/'+m['tag']+'/'
                # Separate staging directory; no partially downloaded file becomes visible.
                payload=stage/'payload'; payload.mkdir(mode=0o700)
                (payload/META).write_bytes(raw); (payload/(META+'.sig')).write_bytes(sig)
                downloader(base+p['name'],payload/p['name'],p['size'],p['sha256'])
                downloader(base+p['name']+'.sig',payload/(p['name']+'.sig'),envelope['signature_size'],p['signature_sha256'])
                package_check(payload,m,raw,sig,key)
                os.rename(payload,folder)
                fd=os.open(store,os.O_RDONLY|os.O_DIRECTORY)
                try: os.fsync(fd)
                finally: os.close(fd)
            package_check(folder,m,raw,sig,key)
            return {'status':'downloaded','version':version,'sha256':p['sha256'],'size':p['size'],'installation_performed':False}
        require(action=='plan' and previous is not None,'Invalid command')
        return plan(folder,m,raw,sig,key,previous,env,root)


def main():
    parser=argparse.ArgumentParser(description=__doc__); parser.add_argument('action',choices=['download','plan']); parser.add_argument('store',type=Path); parser.add_argument('previous',type=Path,nargs='?'); args=parser.parse_args()
    os.umask(0o077)
    try:
        raw=sys.stdin.buffer.read(200001); require(len(raw)<=200000,'Oversized command input')
        print(json.dumps(run(args.action,args.store,json.loads(raw),args.previous)))
    except (ValueError,TypeError,KeyError,OSError,RuntimeError,zipfile.BadZipFile,subprocess.SubprocessError) as e:
        print(json.dumps({'status':'failed','reason':str(e)[:300]})); return 1
    return 0

if __name__=='__main__': sys.exit(main())
