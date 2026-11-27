#!/usr/bin/env python3
"""Offline release admission and signed production bundle. No provider credentials in artifacts."""
import argparse
import datetime as dt
import json
import math
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import zipfile
import candidate
import recovery
import release

ROLES = ('product_owner', 'team_lead', 'accountant', 'lawyer', 'on_call')
KEYS = (*ROLES, 'release')
REPORTS = ('topology.json', 'readiness.json', 'load.json', 'restore.json', 'review.json')
VERSION = '1.7.1'
PREVIOUS = '1.7.0'
NAME = f'CajeerWorkspace-{VERSION}.zip'
SCENARIOS = ('client', 'delivery', 'oss', 'legal_finance')
CHECKS = ('clean_install', 'upgrade', 'rollback', 'auth_policy', 'identity_sessions', 'realm_acceptance', 'rbac', 'localization', 'worker_loss', 'database_restart', 'offsite_restore', 'key_rotation', 'assistant_readonly', 'assistant_role_policy', 'assistant_confirmed_drafts', 'team_lead_review', 'team_lead_decisions', 'team_lead_dependencies', 'oss_release_lines', 'oss_backport_changelog', 'oss_contributor_notifications', 'service_operations', 'owner_portfolio', 'budget_versions', 'collection_forecast', 'team_capacity', 'capacity_substitution', 'queue_forecast', 'freelance_package', 'freelance_advance_and_recurring', 'freelance_client_acceptance', 'entity_books', 'supplier_accruals', 'supplier_payments_and_refunds', 'bank_format_versions', 'bank_independent_review', 'bank_closing_export', 'contract_signer_authority', 'contract_terms_revision', 'contract_deadline_tasks', 'jurisdiction_rule_versions', 'jurisdiction_exchange_receipts', 'sign_test_live_isolation', 'release_trace_identity', 'release_trace_logs', 'release_trace_incident', 'editorial_versioned_compare', 'editorial_revocation', 'editorial_interrupted_recovery')
CI = ('verify', 'postgres', 'bots-compatibility', 'recovery-postgres', 'release-rehearsal', 'package')
MAX_JSON = 1024 * 1024
require = recovery.require


def read(path, limit=MAX_JSON):
    require(path.is_file() and not path.is_symlink() and 0 < path.stat().st_size <= limit, 'Missing, unsafe or oversized file: '+path.name)
    data = path.read_bytes()
    require(0 < len(data) <= limit, 'Invalid file size')
    return data


def decode(data):
    def pairs(items):
        result = {}
        for key, value in items:
            require(key not in result, 'Duplicate JSON key')
            result[key] = value
        return result
    return json.loads(data, object_pairs_hook=pairs, parse_constant=lambda _: (_ for _ in ()).throw(ValueError('Non-finite JSON')))


def exact(obj, fields):
    require(isinstance(obj, dict) and set(obj) == set(fields), 'Unexpected/missing fields')
    return obj


def text(value, limit=160):
    require(isinstance(value, str) and 0 < len(value) <= limit and value.strip()==value and not any(ord(c)<32 or ord(c)==127 for c in value), 'Invalid operator reference')
    return value


def number(value, low, high):
    require(type(value) in (int,float) and math.isfinite(value) and low<=value<=high, 'Invalid numeric evidence')
    return value


def digest(value):
    require(isinstance(value,str) and recovery.HEX.fullmatch(value), 'Invalid SHA-256')
    return value


def timestamp(value):
    t=dt.datetime.fromisoformat(text(value, 40).replace('Z','+00:00'))
    require(t.tzinfo is not None, 'Timestamp must be offset-aware')
    return t


def matrix(archive):
    payload=candidate.archive_payload(archive, VERSION)
    m=decode(payload['config/compatibility.json'])
    require(m['version']==VERSION and m['schema']==50, 'Compatibility matrix mismatch')
    return m, recovery.sha(payload['config/compatibility.json'])


def trust(folder, pin):
    folder=recovery.private_dir(folder)
    raw=read(folder/'trust.json'); require(recovery.sha(raw)==digest(pin), 'Untrusted signer registry')
    entries=exact(decode(raw),KEYS); keys={}; names=set(); fingerprints=set()
    for role, item in entries.items():
        exact(item,('identity','sha256')); name=text(item['identity']); path=folder/(role+'.pub.pem')
        require(recovery.sha(read(path,16384))==digest(item['sha256']), 'Untrusted signer key: '+role)
        # DER normalization prevents alternate PEM encodings from satisfying separation of duties.
        der=candidate.command(['openssl','pkey','-pubin','-in',path,'-outform','DER'])
        description=candidate.command(['openssl','pkey','-pubin','-in',path,'-text_pub','-noout']).decode('ascii')
        bits=re.search(r'Public-Key: \((\d+) bit\)',description)
        require(bits and (('Modulus:' in description or 'modulus:' in description) and int(bits[1])>=2048 or re.search(r'ASN1 OID: (?:prime256v1|secp384r1|secp521r1)\s',description) is not None), 'Require RSA >=2048 or approved named EC curve')
        fp=recovery.sha(der)
        require(fp not in fingerprints, 'Signer public keys must be distinct'); fingerprints.add(fp)
        if role!='release':
            require(name.casefold() not in names, 'Five different reviewers required'); names.add(name.casefold())
        keys[role]=path
    return entries, keys


def sign_file(path, key, target):
    read(path, release.MAX_TOTAL); require(key.is_file() and not key.is_symlink() and key.stat().st_mode&0o077==0,'Private key must be private')
    with tempfile.TemporaryDirectory(dir=target.parent) as d:
        sig=Path(d)/'sig'
        subprocess.run(['openssl','dgst','-sha256','-sign',str(key),'-out',str(sig),str(path)],check=True,timeout=120)
        release.publish(target,read(sig,16384))


def check_reports(reports, m, archive_sha, previous_sha):
    t,p,l,r,v=(decode(reports[n]) for n in REPORTS)
    require(t['version']==VERSION and t['schema']==50 and t['topology_ready'] is True and t['guest_ready'] is True and t['issues']==[] and len(t['projects'])==3 and len(t['staff'])==8 and len(t['teams'])==2 and t['guest_active_ids'], 'Pilot topology or guest not accepted')
    require(p['version']==VERSION and p['mode']=='live_readiness' and p['ready'] is True,'Live readiness not accepted')
    exact(p['connections'],m['integrations'])
    exact(v, ('version','archive_sha256','previous_sha256','checked_at','stand','tax_profile','signature_provider','backend','php_version','database_version','owners','integrations','scenarios','checks','ci','thresholds','open_critical_defects'))
    require(v['version']==VERSION and v['archive_sha256']==archive_sha and v['previous_sha256']==previous_sha,'Review is for a different release')
    when=timestamp(v['checked_at']); require(when<=dt.datetime.now(dt.timezone.utc)+dt.timedelta(minutes=5),'Review timestamp in future')
    for value in [t['checked_at'],*(row['checked_at'] for row in p['connections'].values())]:
        require(dt.timedelta(minutes=-5)<=when-timestamp(value)<=dt.timedelta(days=7),'Pilot evidence outside review window')
    text(v['stand']); require(v['tax_profile'] in m['tax_profiles'] and v['signature_provider'] in m['signature_providers'],'Unsupported jurisdiction/profile or signature provider')
    require(v['backend'] in ('sqlite','pgsql') and r['backend']==v['backend'],'Restore backend mismatch')
    php=text(v['php_version']); db=text(v['database_version']); require(re.fullmatch(r'\d+\.\d+\.\d+(?:[-+][A-Za-z0-9._-]+)?',php) and re.fullmatch(r'\d+(?:\.\d+){1,2}',db),'Invalid runtime versions')
    require('.'.join(php.split('.')[:2]) in m['runtime']['php_ci'],'PHP outside acceptance matrix')
    dv=tuple(int(x) for x in db.split('.')); require((v['backend']=='sqlite' and dv>=(3,27,0)) or (v['backend']=='pgsql' and str(dv[0]) in m['runtime']['postgres_ci']),'Database outside acceptance matrix')
    exact(v['owners'],('workspace','release','security','database','finance','legal',*m['integrations']))
    for owner in v['owners'].values(): text(owner)
    exact(v['integrations'],m['integrations'])
    versions={}
    for provider, spec in m['integrations'].items():
        row=p['connections'][provider]; review=exact(v['integrations'][provider],('version','target_hash','version_evidence','capabilities','evidence_ref'))
        require(row['ready'] is True and row['status'] in ('ready','declared_version_only'),'Provider not ready: '+provider)
        require(review['target_hash']==digest(row['target_hash']),'Provider target changed: '+provider)
        version=text(review['version'],80); text(review['evidence_ref'])
        if provider=='github':
            require(version==spec['version'] and row['status']=='ready' and row['repository'] and row['default_branch'] and review['version_evidence']=='api_contract','GitHub contract not accepted')
        else:
            observed=row.get('observed_version'); declared=row.get('declared_version')
            require(version==(observed or declared) and (not observed or not declared or observed==declared),'Provider version mismatch: '+provider)
            require(review['version_evidence']==('observed' if observed else 'operator_attested'),'Version evidence mismatch: '+provider)
            if spec['version'] is not None: require(version==spec['version'],'Unsupported provider version: '+provider)
        exact(review['capabilities'],spec['capabilities'])
        require(all(x is True for x in review['capabilities'].values()),'Unverified capability: '+provider)
        versions[provider]={'version':version,'evidence':review['version_evidence'],'owner':v['owners'][provider],'target_hash':review['target_hash']}
    for field, required in [('scenarios',SCENARIOS),('checks',CHECKS)]:
        for name,item in exact(v[field],required).items():
            exact(item,('passed','evidence_ref'));require(item['passed'] is True,'Failed acceptance item: '+name);text(item['evidence_ref'])
    ci=exact(v['ci'],('run','commit','jobs')); text(ci['run']); require(re.fullmatch(r'[a-f0-9]{40}',ci['commit']),'CI commit required')
    require(all(x=='success' for x in exact(ci['jobs'],CI).values()),'CI not green')
    require(type(v['open_critical_defects']) is int and v['open_critical_defects']==0,'Critical defects block release')
    limits=exact(v['thresholds'],('minimum_requests','minimum_concurrency','p95_ms','error_rate','rto_seconds','rpo_seconds'))
    require(type(limits['minimum_requests']) is int and type(limits['minimum_concurrency']) is int,'Integer load thresholds required')
    number(limits['minimum_requests'],1,100000);number(limits['minimum_concurrency'],1,100)
    require(l['status']=='passed' and number(l['requests'],1,100000)>=limits['minimum_requests'] and number(l['concurrency'],1,100)>=limits['minimum_concurrency'],'Load profile not accepted')
    for x in [l['total'],*l['routes'].values()]:
        require(number(x['p95_ms'],0,30000)<=number(limits['p95_ms'],0.001,30000) and number(x['error_rate'],0,1)<=number(limits['error_rate'],0,1),'Load threshold exceeded')
    require(isinstance(l['routes'],dict) and l['routes'] and l['total']['requests']==l['requests'] and sum(x['requests'] for x in l['routes'].values())==l['requests'],'Incomplete load report')
    for x in [l['total'],*l['routes'].values()]:
        n=x['requests']; good=x['success']
        require(type(n) is int and type(good) is int and 0<good<=n and abs(x['error_rate']-(n-good)/n)<=0.000001 and sum(x['errors'].values())==n-good,'Inconsistent load counts')
    require(r['status']=='restored_verified_offline' and all(r[k] is True for k in ('cash_balances_match','ledger_balances_match','scope_and_queue_snapshot_match','quarantined')) and r['service_ready'] is False,'Recovery evidence failed')
    digest(r['manifest_sha256']); require(number(r['outage_to_verified_restore_seconds'],0,31536000)<=number(limits['rto_seconds'],0.001,31536000) and number(r['backup_age_at_outage_seconds'],0,31536000)<=number(limits['rpo_seconds'],0,31536000),'Recovery objectives exceeded')
    return {'stand':v['stand'],'versions':versions,'owners':v['owners'],'tax_profile':v['tax_profile'],'signature_provider':v['signature_provider'],'ci_commit':ci['commit'],'checked_at':v['checked_at']}


def draft(archive, previous_sha, folder, entries):
    """Populate an explicitly unapproved review from actual reports and trusted role names."""
    require(release.verify(archive)==VERSION,'Invalid release')
    m,_=matrix(archive); folder=recovery.private_dir(folder)
    p=decode(read(folder/'readiness.json')); r=decode(read(folder/'restore.json'))
    exact(p['connections'],m['integrations'])
    role=lambda name:entries[name]['identity']
    owners={'workspace':role('product_owner'),'release':role('release'),'security':role('team_lead'),
            'database':role('on_call'),'finance':role('accountant'),'legal':role('lawyer')}
    owners.update({name:role(spec['owner_role']) for name,spec in m['integrations'].items()})
    integrations={}
    for name,spec in m['integrations'].items():
        row=p['connections'][name]; observed=row.get('observed_version')
        integrations[name]={'version':spec['version'] if name=='github' else observed or row.get('declared_version') or '',
            'target_hash':row['target_hash'],'version_evidence':'api_contract' if name=='github' else 'observed' if observed else 'operator_attested',
            'capabilities':{c:False for c in spec['capabilities']},'evidence_ref':''}
    review={'version':VERSION,'archive_sha256':recovery.digest(archive),'previous_sha256':digest(previous_sha),
            'checked_at':dt.datetime.now(dt.timezone.utc).isoformat(),'stand':'','tax_profile':'','signature_provider':'',
            'backend':r['backend'],'php_version':'','database_version':'','owners':owners,'integrations':integrations,
            'scenarios':{n:{'passed':False,'evidence_ref':''} for n in SCENARIOS},
            'checks':{n:{'passed':False,'evidence_ref':''} for n in CHECKS},
            'ci':{'run':'','commit':'','jobs':{n:'pending' for n in CI}},
            'thresholds':{n:None for n in ('minimum_requests','minimum_concurrency','p95_ms','error_rate','rto_seconds','rpo_seconds')},
            'open_critical_defects':None}
    release.publish(folder/'review.json',(json.dumps(review,ensure_ascii=False,indent=2)+'\n').encode())
    return {'status':'draft_not_accepted','review':str(folder/'review.json')}


def statement(archive, previous_sha, reports, trust_pin):
    require(release.verify(archive)==VERSION,'Invalid release'); m,msha=matrix(archive); a_sha=recovery.digest(archive)
    summary=check_reports(reports,m,a_sha,digest(previous_sha))
    return {'format':1,'version':VERSION,'schema':50,'archive_sha256':a_sha,'previous_sha256':previous_sha,'matrix_sha256':msha,'trust_sha256':digest(trust_pin),'reports':{n:recovery.sha(reports[n]) for n in REPORTS},'summary':summary}


def fresh(item):
    require(dt.timedelta(minutes=-5)<=dt.datetime.now(dt.timezone.utc)-timestamp(item['summary']['checked_at'])<=dt.timedelta(days=7),'Review must be completed within the last seven days')


def approvals(folder, manifest, keys, entries):
    for role in ROLES:
        candidate.verify_signature(manifest,folder/(role+'.sig'),keys[role],entries[role]['sha256'])


def validate(folder, archive, registry, pin):
    entries,keys=trust(registry,pin)
    manifest=folder/'acceptance.json'; raw=read(manifest); item=decode(raw)
    reports={n:read(folder/n) for n in REPORTS}
    expected=statement(archive,item['previous_sha256'],reports,pin)
    require(raw==recovery.canonical(expected),'Acceptance does not match artifact or reports')
    approvals(folder,manifest,keys,entries)
    return expected, entries, keys


def publish(archive, evidence, registry, pin, private, output):
    evidence=recovery.private_dir(evidence); output=Path(output)
    require(output.is_absolute() and not output.exists() and not output.is_symlink(),'New absolute bundle path required')
    parent=recovery.private_dir(output.parent)
    # Snapshot first; all checks and signatures use the exact bytes to be published.
    with tempfile.TemporaryDirectory(prefix='.production-',dir=parent) as d:
        stage=Path(d); os.chmod(stage,0o700)
        for n in ('acceptance.json',*REPORTS,*(role+'.sig' for role in ROLES)):
            recovery.write(stage/n,read(evidence/n,16384 if n.endswith('.sig') else MAX_JSON))
        recovery.write(stage/NAME,read(archive,release.MAX_TOTAL)); recovery.write(stage/(NAME+'.sha256'),read(archive.with_name(archive.name+'.sha256'),512))
        item,entries,keys=validate(stage,stage/NAME,registry,pin)
        fresh(item)
        candidate.sign(stage/NAME,private,stage/(NAME+'.sig'))
        candidate.verify_signature(stage/NAME,stage/(NAME+'.sig'),keys['release'],entries['release']['sha256'])
        # Bind release authority to the exact acceptance record too.
        sign_file(stage/'acceptance.json',private,stage/'release.sig')
        verify(stage,registry,pin)
        recovery.fsync_dir(stage)
        # Atomic no-clobber publication on POSIX: reserve destination by mkdir, then rename
        # the complete directory over our own empty reservation. Interrupted reserve is not valid.
        output.mkdir(mode=0o700); os.replace(stage,output); recovery.fsync_dir(parent)
    return {'status':'signed_accepted','version':VERSION,'archive_sha256':item['archive_sha256'],'acceptance_sha256':recovery.digest(output/'acceptance.json')}


def verify(folder, registry, pin):
    folder=recovery.private_dir(folder)
    expected={'acceptance.json',*REPORTS,*(role+'.sig' for role in ROLES),NAME,NAME+'.sha256',NAME+'.sig','release.sig'}
    require({p.name for p in folder.iterdir()}==expected,'Unexpected/missing bundle files')
    require(all(p.is_file() and not p.is_symlink() for p in folder.iterdir()),'Unsafe bundle member')
    item,entries,keys=validate(folder,folder/NAME,registry,pin)
    for filename,sig in [(NAME,NAME+'.sig'),('acceptance.json','release.sig')]:
        candidate.verify_signature(folder/filename,folder/sig,keys['release'],entries['release']['sha256'])
    return item


def main():
    p=argparse.ArgumentParser(description=__doc__); sub=p.add_subparsers(dest='action',required=True)
    for name in ('draft','assemble','approve','publish','verify','prepare','upgrade','rollback'):
        q=sub.add_parser(name)
        if name in ('draft','assemble','approve','publish'): q.add_argument('--archive',type=Path,required=True);q.add_argument('--evidence',type=Path,required=True)
        if name in ('draft','assemble'): q.add_argument('--previous-sha256',required=True)
        if name=='approve': q.add_argument('--role',choices=ROLES,required=True)
        if name in ('approve','publish'): q.add_argument('--private-key',type=Path,required=True)
        if name=='publish': q.add_argument('--output',type=Path,required=True)
        if name in ('verify','prepare','upgrade','rollback'): q.add_argument('--bundle',type=Path,required=True)
        if name in ('prepare','upgrade','rollback'): q.add_argument('--root',type=Path,required=True)
        if name=='prepare': q.add_argument('--previous',type=Path,required=True)
        if name in ('upgrade','rollback'):
            q.add_argument('--backup',type=Path,required=True);q.add_argument('--backup-sha256',required=True);q.add_argument('--data',type=Path,required=True);q.add_argument('--objects',type=Path,required=True)
        q.add_argument('--trust',type=Path,required=True);q.add_argument('--trust-sha256',required=True)
    a=p.parse_args(); os.umask(0o077)
    try:
        entries,keys=trust(a.trust,a.trust_sha256)
        if a.action=='draft': result=draft(a.archive,a.previous_sha256,a.evidence,entries)
        elif a.action=='assemble':
            folder=recovery.private_dir(a.evidence); reports={n:read(folder/n) for n in REPORTS}
            item=statement(a.archive,a.previous_sha256,reports,a.trust_sha256)
            fresh(item)
            release.publish(folder/'acceptance.json',recovery.canonical(item)); result={'status':'awaiting_approvals','acceptance_sha256':recovery.digest(folder/'acceptance.json')}
        elif a.action=='approve':
            folder=recovery.private_dir(a.evidence); path=folder/'acceptance.json'; item=decode(read(path))
            reports={n:read(folder/n) for n in REPORTS}
            require(read(path)==recovery.canonical(statement(a.archive,item['previous_sha256'],reports,a.trust_sha256)),'Acceptance differs from current evidence')
            fresh(item)
            # Refuse a wrong private key without leaving a misleading approval file.
            with tempfile.TemporaryDirectory(dir=folder) as d:
                sig=Path(d)/'approval.sig'; sign_file(path,a.private_key,sig)
                candidate.verify_signature(path,sig,keys[a.role],entries[a.role]['sha256']);release.publish(folder/(a.role+'.sig'),sig.read_bytes())
            result={'status':'approved','role':a.role,'acceptance_sha256':recovery.digest(path)}
        elif a.action=='publish': result=publish(a.archive,a.evidence,a.trust,a.trust_sha256,a.private_key,a.output)
        else:
            item=verify(a.bundle,a.trust,a.trust_sha256)
            if a.action=='prepare': result=candidate.prepare(a.root,a.previous,item['previous_sha256'],a.bundle/NAME,a.bundle/(NAME+'.sig'),keys['release'],entries['release']['sha256'],expected_candidate_sha=item['archive_sha256'])
            elif a.action in ('upgrade','rollback'):
                root=candidate.managed_directory(a.root)
                deployment=decode(read(root/'deployment.json'))
                require(deployment=={'previous_sha256':item['previous_sha256'],'candidate_sha256':item['archive_sha256'],'public_key_sha256':entries['release']['sha256']},'Prepared deployment differs from accepted bundle')
                result=candidate.switch(root,VERSION if a.action=='upgrade' else PREVIOUS,a.backup,a.backup_sha256,a.data,a.objects)
            else: result={'status':'signed_accepted','version':VERSION,'archive_sha256':item['archive_sha256'],'summary':item['summary']}
        print(json.dumps(result,ensure_ascii=False,sort_keys=True));return 0
    except (OSError,ValueError,TypeError,KeyError,AttributeError,OverflowError,RuntimeError,subprocess.SubprocessError,zipfile.BadZipFile) as e:
        print('Production gate failed: '+(str(e) if type(e) is ValueError else type(e).__name__),file=sys.stderr);return 1

if __name__=='__main__': sys.exit(main())
