#!/usr/bin/env python3
"""Build and verify a deterministic Workspace distribution; no third-party packages."""
import argparse
import hashlib
import json
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
FILES = '''app/FullCycle.php tests/full_cycle_unit.php docs/FULL_CYCLE.md app/BotsSupport.php tests/bots_support_unit.php tests/bots_support_schema.py app/EditorialCapabilities.php tests/editorial_capabilities_unit.php app/ReleaseTrace.php views/release_trace.php tests/release_trace_unit.php tests/release_trace_db.php docs/RELEASE_TRACE.md app/Jurisdiction.php tests/jurisdiction_unit.php tests/jurisdiction_schema.py docs/JURISDICTION.md app/ContractControls.php tests/contract_controls_unit.php tests/contract_controls_schema.py app/FinancialBooks.php views/books.php docs/FINANCIAL_BOOKS.md tests/financial_books_unit.php tests/financial_books_db.php tests/financial_books_schema.py app/Freelance.php views/freelance.php views/freelance_bills.php docs/FREELANCE.md tests/freelance_unit.php tests/freelance_db.php tests/freelance_schema.py app/Capacity.php views/capacity.php docs/CAPACITY.md tests/capacity_unit.php tests/capacity_db.php tests/capacity_schema.py app/ProjectManagement.php views/project_management.php docs/PROJECT_MANAGEMENT.md tests/project_management_unit.php tests/project_management_db.php tests/project_management_schema.py app/Portfolio.php views/portfolio.php docs/PORTFOLIO.md tests/portfolio_unit.php tests/portfolio_db.php tests/portfolio_schema.py app/ServiceOps.php views/service_ops.php docs/SERVICE_OPS.md tests/service_ops_unit.php tests/service_ops_db.php tests/service_ops_schema.py app/OssMaintenance.php views/oss_maintenance.php tests/oss_maintenance_unit.php tests/oss_maintenance_schema.py app/TeamLead.php views/lead.php docs/TEAM_LEAD.md tests/team_lead_unit.php tests/team_lead_db.php tests/team_lead_schema.py app/Delivery.php views/delivery.php docs/DELIVERY.md tests/delivery_unit.php tests/delivery_db.php tests/delivery_schema.py app/AssistantOps.php app/AssistantMcp.php views/assistant_ops.php tests/assistant_ops_unit.php tests/assistant_ops_db.php tools/ai_eval.php app/AssistantDraft.php views/assistant_draft.php tests/assistant_draft_unit.php tests/assistant_draft_db.php app/AssistantPolicy.php app/Assistant.php views/assistant.php tests/assistant_unit.php tests/assistant_db.php docs/AI_ASSISTANT.md tools/update_schedule.py tests/update_schedule.py app/UpdateMaintenance.php tools/update_install.py tests/update_install.py app/WorkspaceUpdateCli.php tools/update_package.py tests/update_package.py app/WorkspaceUpdates.php views/workspace_updates.php tools/github_release.py tests/workspace_updates.php docs/WORKSPACE_UPDATES.md LICENSE README.md ROADMAP.md VERSION composer.json .gitignore database/schema-freeze.json docs/CANDIDATE.md docs/PRODUCTION.md config/compatibility.json tools/production.py tests/production.py tools/candidate.py tests/candidate.py tests/candidate_unit.php
app/Recovery.php app/Pilot.php app/Reliability.php app/Discovery.php app/Incidents.php app/Bots.php app/Logs.php app/Editorial.php app/Lite.php app/LiteWorker.php app/Forum.php app/Book.php app/Engine.php app/Core.php app/Contracts.php app/Preparation.php app/Signing.php app/Retention.php app/TaxUk.php app/Economics.php app/Banking.php app/BankClosing.php app/Accounting.php app/Invoices.php app/Deployments.php app/Infrastructure.php app/Releases.php app/GitHub.php app/Oss.php app/Clients.php app/Workload.php app/Planning.php app/Oidc.php app/Auth.php app/AuthPolicy.php app/Identity.php app/AuthAcceptance.php app/Permissions.php app/Payments.php app/Organizations.php app/Postgres.php app/Migrations.php app/Integrations.php app/Web.php app/Workspace.php app/bootstrap.php
bin/workspace config/connections.example.json docs/RECOVERY.md docs/PILOT.md docs/INTEGRATIONS.md docs/UPGRADE.md docs/ORGANIZATIONS.md docs/PERMISSIONS.md docs/AUTHENTICATION.md docs/PLANNING.md docs/WORKLOAD.md docs/CLIENTS.md docs/OSS.md docs/GITHUB.md docs/RELEASES.md docs/INFRASTRUCTURE.md docs/DEPLOYMENTS.md docs/INVOICES.md docs/ACCOUNTING.md docs/BANKING.md docs/ECONOMICS.md docs/UK_VAT.md docs/CONTRACTS.md docs/PREPARATION.md docs/SIGNING.md docs/RETENTION.md docs/ENGINE.md docs/BOOK.md docs/FORUM.md docs/LITE.md docs/EDITORIAL.md docs/LOGS.md docs/BOTS.md docs/RELIABILITY.md docs/DISCOVERY.md docs/INCIDENTS.md integrations/cajeerbots-1.0.2-workspace.patch
public/health.php public/index.php public/style.css
views/reliability.php views/notifications.php views/digest.php views/incidents.php views/bots.php views/logs.php views/editorial.php views/lite.php views/forum.php views/book.php views/engine.php views/contracts.php views/preparation.php views/signing.php views/retention.php views/preparation_print.php views/approved_acts.php views/tax.php views/tax_print.php views/economics.php views/bank.php views/accounting.php views/invoices.php views/deployments.php views/infrastructure.php views/releases.php views/github.php views/github_snapshot.php views/oss.php views/oss_join.php views/oss_public.php views/oss_contributor.php views/clients.php views/workload.php views/planning.php views/task.php views/task_fields.php views/task_state.php views/security.php views/guest.php views/guest_join.php views/audit.php views/payments.php views/connections.php views/document.php views/documents.php views/error.php views/finance.php views/frame.php views/login.php views/overview.php views/invite.php views/search.php views/project.php views/projects.php views/team.php
tools/release.py tools/pilot.py tools/deploy.py tools/recovery.py tools/load.py tests/recovery.py tests/recovery_db.php tests/recovery_http.py tests/recovery_pg.py tests/pilot_db.php tests/contracts_db.php tests/preparation_db.php tests/signing_db.php tests/reliability_db.php tests/reliability_schema.py tests/discovery_db.php tests/discovery_schema.py tests/incidents_db.php tests/incidents_schema.py tests/bots_db.php tests/bots_schema.py tests/logs_db.php tests/logs_schema.py tests/logs_wire.php tests/editorial_db.php tests/editorial_schema.py tests/lite_db.php tests/lite_schema.py tests/forum_db.php tests/forum_schema.py tests/book_db.php tests/book_schema.py tests/engine_db.php tests/engine_schema.py tests/retention_db.php tests/retention_schema.py tests/signing_schema.py tests/signing_unit.php tests/preparation_unit.php tests/preparation_schema.py tests/contracts_schema.py tests/tax_unit.php tests/tax_db.php tests/tax_schema.py tests/economics_db.php tests/economics_schema.py tests/bank_unit.php tests/bank_closing_unit.php tests/bank_closing_schema.py tests/bank_db.php tests/bank_schema.py tests/accounting_unit.php tests/accounting_db.php tests/accounting_schema.py tests/invoice_unit.php tests/invoice_db.php tests/invoice_schema.py tests/deploy_executor.py tests/deploy_schema.py tests/deploy_db.php tests/infra_unit.php tests/infra_db.php tests/infra_schema.py tests/project_release_unit.php tests/project_release_db.php tests/project_release_schema.py tests/github_fixture.php tests/github_unit.php tests/github_db.php tests/github_e2e.py tests/github_schema.py tests/oss_db.php tests/oss_schema.py tests/oss_e2e.py tests/clients_db.php tests/clients_schema.py tests/clients_e2e.py tests/workload_unit.php tests/workload_schema.py tests/workload_db.php tests/workload_e2e.py tests/planning_unit.php tests/planning_schema.py tests/planning_db.php tests/planning_e2e.py tests/integration_profiles.php tests/run.php tests/web_e2e.py tests/migrations.php tests/release.py tests/tenant_e2e.py tests/tenant_schema.py tests/permissions_schema.py tests/permissions_e2e.py tests/permissions_roles.php tests/auth_security.php tests/auth_policy.php tests/auth_policy_e2e.py tests/identity_db.php tests/identity_e2e.py tests/identity_schema.py tests/auth_acceptance.php tests/auth_acceptance_db.php tests/auth_acceptance_schema.py tests/auth_schema.py tests/auth_db.php tests/auth_throttle_db.php tests/auth_e2e.py .github/workflows/ci.yml'''.split()
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
    if sha(payload["database/schema-freeze.json"]) != "79343e2c4b9309a395b2c89e7918de1ea25f9fcc3b995aefc8e485f67c6dda86":
        raise ValueError("Frozen schema manifest changed")
    freeze = json.loads(payload["database/schema-freeze.json"])
    migrations = {name: sha(data) for name, data in payload.items() if name.startswith("database/") and name.endswith(".sql")}
    if freeze != {"schema": n, "migrations": migrations}:
        raise ValueError("Frozen schema mismatch")
    if sha(payload["config/compatibility.json"]) != "897116b35500f4d4243d6913373d7f6e11aa666ec8a657c0286657e2c03fd044":
        raise ValueError("Release compatibility matrix changed")
    matrix = json.loads(payload["config/compatibility.json"])
    if matrix["version"] != version or matrix["schema"] != n:
        raise ValueError("Release compatibility matrix disagrees with release")
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
