#!/usr/bin/env python3
"""Build and verify a deterministic Workspace distribution; no third-party packages."""
import argparse
import hashlib
import os
from pathlib import Path, PurePosixPath
import re
import stat
import sys
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = 'SHA256SUMS'
# Explicit release inventory: no data, private configuration, VCS, secrets or build outputs.
FILES = '''LICENSE README.md ROADMAP.md VERSION composer.json .gitignore
app/Core.php app/Oidc.php app/Auth.php app/Permissions.php app/Payments.php app/Organizations.php app/Postgres.php app/Migrations.php app/Integrations.php app/Web.php app/Workspace.php app/bootstrap.php
bin/workspace config/connections.example.json docs/INTEGRATIONS.md docs/UPGRADE.md docs/ORGANIZATIONS.md docs/PERMISSIONS.md docs/AUTHENTICATION.md
public/health.php public/index.php public/style.css
views/security.php views/guest.php views/guest_join.php views/audit.php views/payments.php views/connections.php views/document.php views/documents.php views/error.php views/finance.php views/frame.php views/login.php views/overview.php views/invite.php views/search.php views/project.php views/projects.php views/team.php
tools/release.py tests/integration_profiles.php tests/run.php tests/web_e2e.py tests/migrations.php tests/release.py tests/tenant_e2e.py tests/tenant_schema.py tests/permissions_schema.py tests/permissions_e2e.py tests/permissions_roles.php tests/auth_security.php tests/auth_schema.py tests/auth_db.php tests/auth_e2e.py .github/workflows/ci.yml'''.split()
VERSION_RE = re.compile(r'[0-9]+\.[0-9]+\.[0-9]+')
MAX_FILE = 32 * 1024 * 1024
MAX_TOTAL = 128 * 1024 * 1024


def sha(data):
    return hashlib.sha256(data).hexdigest()


def file_sha(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest() if hasattr(hashlib, 'file_digest') else stream_digest(stream)


def stream_digest(stream):
    digest = hashlib.sha256()
    for chunk in iter(lambda: stream.read(1024 * 1024), b''):
        digest.update(chunk)
    return digest.hexdigest()


def safe_name(name):
    return bool(re.fullmatch(r'[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*', name)) and all(p not in ('.', '..') for p in name.split('/'))


def read_source(name):
    path = ROOT / name
    if any((ROOT / parent).is_symlink() for parent in [PurePosixPath(name), *PurePosixPath(name).parents]) or not path.is_file():
        raise ValueError(f'Invalid or missing source: {name}')
    if path.stat().st_size > MAX_FILE:
        raise ValueError(f'Source too large: {name}')
    return path.read_bytes()


def validate_payload(payload):
    version = payload['VERSION'].decode('ascii').strip()
    if not VERSION_RE.fullmatch(version):
        raise ValueError('Invalid VERSION')
    core = payload['app/Core.php'].decode('utf-8')
    if not re.search(r"const VERSION = '" + re.escape(version) + r"';", core):
        raise ValueError('VERSION and app/Core.php disagree')
    schema = re.search(r'const SCHEMA_VERSION = (\d+);', core)
    if not schema:
        raise ValueError('Missing schema version')
    n = int(schema.group(1))
    if not 1 <= n <= 999:
        raise ValueError('Invalid schema version')
    expected = set(FILES) | {f'database/{i:03}.sql' for i in range(1, n + 1)} | {f'database/postgresql/{i:03}.sql' for i in range(1, n + 1)}
    if set(payload) != expected:
        raise ValueError('Unexpected or missing distribution files')
    return version


def zip_entry(archive, name, data):
    info = zipfile.ZipInfo(name, (1980, 1, 1, 0, 0, 0))
    info.create_system = 3
    info.external_attr = (stat.S_IFREG | (0o755 if name in ('bin/workspace', 'tools/release.py') else 0o644)) << 16
    info.compress_type = zipfile.ZIP_DEFLATED
    archive.writestr(info, data, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)


def publish(path, data):
    # Link a complete temporary file into place, without clobbering an existing artifact.
    with tempfile.NamedTemporaryFile(dir=path.parent, prefix='.release-', delete=False) as stream:
        temp = Path(stream.name)
        try:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
            stream.close()
            os.link(temp, path)
        finally:
            temp.unlink(missing_ok=True)


def build(out):
    if out.is_symlink():
        raise ValueError('Output directory must not be a symlink')
    out.mkdir(parents=True, exist_ok=True)
    payload = {name: read_source(name) for name in FILES}
    for path in sorted((ROOT / 'database').glob('*.sql')):
        name = 'database/' + path.name
        payload[name] = read_source(name)
    for path in sorted((ROOT / 'database/postgresql').glob('*.sql')):
        name = 'database/postgresql/' + path.name
        payload[name] = read_source(name)
    version = validate_payload(payload)
    name = f'CajeerWorkspace-{version}.zip'
    target = out / name
    checksum = out / (name + '.sha256')
    if target.exists() or checksum.exists() or target.is_symlink() or checksum.is_symlink():
        raise FileExistsError('Release output already exists')
    with tempfile.TemporaryFile() as stream:
        with zipfile.ZipFile(stream, 'w') as archive:
            for filename, data in sorted(payload.items()):
                zip_entry(archive, filename, data)
            manifest = ''.join(f'{sha(data)}  {filename}\n' for filename, data in sorted(payload.items()))
            zip_entry(archive, MANIFEST, manifest.encode('ascii'))
        stream.seek(0)
        data = stream.read()
    publish(target, data)
    try:
        publish(checksum, f'{sha(data)}  {name}\n'.encode('ascii'))
    except Exception:
        # Avoid leaving a published ZIP without its matching checksum.
        target.unlink()
        raise
    verify(target)
    return target


def verify(path):
    checksum = path.with_name(path.name + '.sha256')
    line = checksum.read_text(encoding='ascii')
    if line != f'{file_sha(path)}  {path.name}\n':
        raise ValueError('ZIP SHA-256 mismatch')
    with zipfile.ZipFile(path) as archive:
        entries = archive.infolist()
        names = [e.filename for e in entries]
        if len(names) != len(set(names)) or MANIFEST not in names:
            raise ValueError('Duplicate ZIP entries or missing manifest')
        if any(not safe_name(e.filename) or e.is_dir() or stat.S_IFMT(e.external_attr >> 16) != stat.S_IFREG or e.file_size > MAX_FILE for e in entries) or sum(e.file_size for e in entries) > MAX_TOTAL:
            raise ValueError('Unsafe ZIP member or size')
        manifest = archive.read(MANIFEST).decode('ascii')
        payload = {name: archive.read(name) for name in names if name != MANIFEST}
        expected = ''.join(f'{sha(data)}  {name}\n' for name, data in sorted(payload.items()))
        if manifest != expected:
            raise ValueError('Internal file checksums mismatch')
        version = validate_payload(payload)
        if path.name != f'CajeerWorkspace-{version}.zip':
            raise ValueError('ZIP filename and VERSION disagree')
    return version


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='command', required=True)
    make = commands.add_parser('build')
    make.add_argument('--output', type=Path, default=ROOT / 'dist')
    check = commands.add_parser('verify')
    check.add_argument('archive', type=Path)
    args = parser.parse_args()
    try:
        if args.command == 'build':
            print(build(args.output))
        else:
            print('OK ·', verify(args.archive))
    except (OSError, ValueError, KeyError, RuntimeError, zipfile.BadZipFile) as exc:
        print(f'Release error: {exc}', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
