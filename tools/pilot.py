#!/usr/bin/env python3
"""Run isolated regression groups for Team Pilot; no real provider credentials or traffic."""
import argparse
import hashlib
import json
import os
import re
from pathlib import Path
import shutil
import subprocess
import sys
import time
import zipfile
from datetime import datetime, timezone

ROOT = Path(__file__).resolve().parents[1]
GROUPS = {
    'topology': ['full_cycle_unit.php', 'pilot_db.php', 'auth_db.php', 'auth_security.php', 'tenant_e2e.py', 'permissions_e2e.py'],
    'client': ['clients_e2e.py', 'clients_db.php', 'workload_db.php', 'project_management_db.php', 'freelance_db.php', 'preparation_db.php', 'invoice_db.php', 'bank_db.php'],
    'product': ['github_db.php', 'project_release_db.php', 'deploy_db.php', 'infra_db.php', 'logs_db.php', 'reliability_db.php', 'delivery_db.php', 'team_lead_db.php', 'service_ops_db.php', 'release_trace_db.php'],
    'oss': ['oss_e2e.py', 'book_db.php', 'engine_db.php', 'lite_db.php', 'editorial_db.php', 'forum_db.php', 'bots_db.php'],
    'legal_finance': ['contracts_db.php', 'signing_db.php', 'retention_db.php', 'accounting_db.php', 'financial_books_db.php', 'tax_db.php'],
    'assistant': ['assistant_db.php', 'assistant_draft_db.php', 'assistant_ops_db.php'],
    'recovery': ['recovery.py', 'recovery_db.php', 'candidate.py', 'update_install.py'],
}


def run(groups, php, lite_source=None, timeout=180, previous=None):
    # Explicitly strip all service credentials and installation configuration. Tests set their own fixtures.
    env = {k: v for k, v in os.environ.items() if k in ('PATH', 'LANG', 'LC_ALL', 'LC_CTYPE', 'SYSTEMROOT', 'WINDIR')}
    env['PHP_BIN'] = php
    env['WORKSPACE_DB'] = 'sqlite'
    # Ignore operator local configuration, even if this runner is invoked inside an installation.
    from tempfile import TemporaryDirectory
    results = {}
    with TemporaryDirectory(prefix='workspace-pilot-run-') as tmp:
        import release
        # Copy the verified distribution rather than the live checkout: several legacy E2E
        # scripts intentionally unset WORKSPACE_CONFIG and would otherwise see local secrets.
        archive = release.build(Path(tmp) / 'dist')
        sandbox = Path(tmp) / 'source'
        with zipfile.ZipFile(archive) as bundle:
            bundle.extractall(sandbox)
        home = Path(tmp) / 'home'
        home.mkdir(mode=0o700)
        env['HOME'] = str(home)
        env['XDG_CONFIG_HOME'] = str(home)
        config = Path(tmp) / 'connections.json'
        config.write_text('{}', encoding='utf-8')
        env['WORKSPACE_CONFIG'] = str(config)
        env['WORKSPACE_DATA'] = str(Path(tmp) / 'data')
        if lite_source:
            env['WORKSPACE_LITE_TEST_SOURCE'] = str(Path(lite_source).resolve())
        # Preflight is a real runtime check, not a substitute for tests.
        preflight = subprocess.run([php, 'bin/workspace', 'doctor'], cwd=sandbox, env=env, capture_output=True, timeout=30)
        if preflight.returncode:
            return {'runtime_ready': False, 'groups': {}, 'status': 'blocked_runtime'}
        if previous:
            import candidate
            old=Path(previous).resolve()
            candidate.archive_payload(old, candidate.OLD)
            env['WORKSPACE_PREVIOUS_RELEASE']=str(old)
            env['WORKSPACE_CANDIDATE_REQUIRE_NATIVE']='1'
        for group in groups:
            rows = []
            for name in GROUPS[group]:
                source = sandbox / 'tests' / name
                digest = hashlib.sha256(source.read_bytes()).hexdigest()
                started = time.monotonic()
                row = {'test': name, 'sha256': digest, 'exit_code': None, 'status': 'blocked'}
                if name == 'lite_db.php' and not lite_source:
                    row['status'] = 'missing_lite_source'
                elif name in ('candidate.py','update_install.py') and not previous:
                    row['status']='missing_previous_release'
                else:
                    cmd = [php if name.endswith('.php') else sys.executable, str(source)]
                    try:
                        proc = subprocess.run(cmd, cwd=sandbox, env=env, capture_output=True, timeout=timeout)
                        skipped=bool(re.search(rb'\bskipped[= ]+[1-9]\d*',proc.stderr+proc.stdout,re.I))
                        row.update(exit_code=proc.returncode, status='failed' if proc.returncode else ('skipped' if skipped else 'passed'))
                        # No arbitrary test output in exported evidence (could contain credentials).
                    except subprocess.TimeoutExpired:
                        row['status'] = 'timeout'
                    except OSError:
                        row['status'] = 'launch_failed'
                row['duration_ms'] = round((time.monotonic() - started) * 1000)
                rows.append(row)
            results[group] = rows
    ok = all(r['status'] == 'passed' for rows in results.values() for r in rows)
    return {'runtime_ready': True, 'groups': results, 'status': 'passed' if ok else 'incomplete'}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--group', action='append', choices=GROUPS, help='repeat to select groups; defaults to all')
    parser.add_argument('--lite-source', default=os.environ.get('WORKSPACE_LITE_TEST_SOURCE'))
    parser.add_argument('--previous', help='verified original full 1.7.2 distribution')
    parser.add_argument('--timeout', type=int, default=180)
    args = parser.parse_args()
    if not 10 <= args.timeout <= 1800:
        parser.error('timeout must be 10–1800 seconds')
    php = shutil.which(os.environ.get('PHP_BIN', 'php'))
    report = {'version': (ROOT / 'VERSION').read_text().strip(), 'mode': 'isolated_regression',
              'live_acceptance': False, 'generated_at': datetime.now(timezone.utc).isoformat()}
    if not php:
        report.update(runtime_ready=False, groups={}, status='blocked_runtime')
    else:
        try:
            report.update(run(list(dict.fromkeys(args.group or GROUPS)), php, args.lite_source, args.timeout, args.previous))
        except (OSError, ValueError, subprocess.TimeoutExpired):
            report.update(runtime_ready=False, groups={}, status='blocked_environment')
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 0 if report['status'] == 'passed' else 1


if __name__ == '__main__':
    sys.exit(main())
