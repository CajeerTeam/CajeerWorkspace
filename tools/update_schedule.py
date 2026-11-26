#!/usr/bin/env python3
"""Opt-in release orchestration. Run as the trusted update operator, never from FPM."""
import argparse
import base64
import datetime as dt
import json
import os
from pathlib import Path
import re
import secrets
import stat
import subprocess
import sys
import tempfile
import time
from types import SimpleNamespace
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

import recovery
import update_install as ui
import update_package as pkg

require = ui.require
UTC = dt.timezone.utc
ACTIONS = ('quiesce', 'start', 'health', 'open')

def now():
    return dt.datetime.now(UTC)

def stamp():
    return now().isoformat()

def trusted_file(path, executable=False):
    p = Path(path)
    require(p.is_absolute() and p.resolve() == p and p.is_file() and not p.is_symlink(), 'Use canonical trusted file paths')
    s = p.stat()
    require(s.st_uid in (0, os.geteuid()) and not s.st_mode & 0o022, 'File must be owned by root/operator and not writable by others')
    for parent in p.parents:
        mode = parent.stat()
        require(mode.st_uid in (0, os.geteuid()) and (not mode.st_mode & 0o022 or (mode.st_uid == 0 and mode.st_mode & stat.S_ISVTX)), 'Untrusted parent directory')
    if executable:
        require(os.access(p, os.X_OK), 'Hook must be executable')
    return p

def minute(value):
    require(isinstance(value, str) and re.fullmatch(r'(?:[01][0-9]|2[0-3]):[0-5][0-9]', value), 'Expected HH:MM')
    return int(value[:2])*60 + int(value[3:])

def config(raw):
    fields = {'root','archive_dir','channel','role','timezone','days','start','end','hooks','approval_dir','approval_key','approval_pin','stop_units','pg_restore_database'}
    require(isinstance(raw, dict) and set(raw) == fields, 'Invalid schedule configuration fields')
    require(raw['channel'] in ('stable','preview') and raw['role'] in ('pilot','follower'), 'Invalid channel or rollout role')
    require(isinstance(raw['timezone'],str) and len(raw['timezone'])<=128, 'Invalid timezone'); ZoneInfo(raw['timezone'])
    days = raw['days']; require(isinstance(days,list) and 0<len(days)<=7 and all(type(d) is int and 1<=d<=7 for d in days) and len(set(days))==len(days), 'Days are ISO weekdays 1–7')
    require(minute(raw['start']) != minute(raw['end']), 'Maintenance window must not be empty or 24h')
    for name in ('root','archive_dir','approval_dir'):
        require(isinstance(raw[name],str), 'Invalid directory'); ui.directory(Path(raw[name]))
    hooks=raw['hooks']; require(isinstance(hooks,dict) and set(hooks) in (set(ACTIONS),set(ACTIONS)|{'notify'}), 'Require quiesce/start/health/open hooks; notify optional')
    for v in hooks.values(): trusted_file(v, True)
    key=trusted_file(raw['approval_key']); pkg.regular(key,16384)
    require(isinstance(raw['approval_pin'],str) and pkg.HEX.fullmatch(raw['approval_pin']) and recovery.digest(key)==raw['approval_pin'], 'Untrusted pilot approval key')
    require(isinstance(raw['stop_units'],list) and len(raw['stop_units'])<=32 and all(isinstance(u,str) and not u.startswith('-') and re.fullmatch(r'[A-Za-z0-9_.@:-]+\.(?:service|timer|socket|path)',u) for u in raw['stop_units']), 'Invalid systemd units')
    pg=raw['pg_restore_database']; require(pg is None or isinstance(pg,str) and re.fullmatch(r'[A-Za-z0-9_.-]+',pg), 'Invalid restore-test database')
    return raw

def in_window(c, instant):
    local=instant.astimezone(ZoneInfo(c['timezone'])); m=local.hour*60+local.minute
    a,b=minute(c['start']),minute(c['end']); day=local.isoweekday()
    if a<b: return day in c['days'] and a<=m<b
    return (day in c['days'] and m>=a) or ((day-2)%7+1 in c['days'] and m<b)

def default_state():
    return {'enabled':False,'paused':False,'skipped':[],'pending':None,'healthy':None,'events':[],'status':'disabled','notification_failed':False}

def load(state):
    path=state/'schedule.json'
    if not path.exists() and not path.is_symlink(): return default_state()
    v=ui.read_json(path); require(isinstance(v,dict) and set(v)==set(default_state()),'Invalid scheduler state')
    return v

def load_config(state):
    c=ui.read_json(state/'schedule-config.json',65536)
    require(isinstance(c,dict),'Invalid stored scheduler configuration');return c

def save(state,s,c=None):
    ui.atomic_json(state/'schedule.json',s)
    public={k:s[k] for k in ('enabled','paused','skipped','status','notification_failed')}
    public['events']=s['events'][-20:]; public['pending']=s['pending'] is not None; public['stage']=s['pending'].get('stage') if s['pending'] else None
    if c: public['policy']={k:c[k] for k in ('channel','role','timezone','days','start','end')}
    public['updated_at']=stamp()
    ui.atomic_json(state/'schedule-report.json',public,0o640)

def hook(c,name,info,env=None):
    path=trusted_file(c['hooks'][name],True)
    result=subprocess.run([str(path)],input=recovery.canonical(info),stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,env=env,timeout=120)
    require(result.returncode==0,name+' hook failed')

def event(state,s,c,status,target=None,notify=True):
    prev=s['events'][-1] if s['events'] else None
    s['status']=status
    if not prev or (prev['status'],prev['version'])!=(status,target):
        item={'at':stamp(),'status':status,'version':target}; s['events']=(s['events']+[item])[-100:]
        save(state,s,c)
        # stdout is captured by systemd/journal; optionally deliver a sanitized notification to a trusted hook.
        print(json.dumps(item),flush=True,file=sys.stderr)
        if notify and c and 'notify' in c['hooks']:
            try:
                hook(c,'notify',item,{'PATH':'/usr/bin:/bin','LANG':'C.UTF-8'});s['notification_failed']=False
            except (OSError,ValueError,subprocess.SubprocessError): s['notification_failed']=True
    save(state,s,c)
    return {'status':status,'version':target}

def identity(m):
    return {'version':m['version'],'channel':m['channel'],'commit':m['commit'],'metadata_sha256':recovery.sha(recovery.canonical(m)),'package_sha256':m['package']['sha256'],'release_key_sha256':m['release_key_sha256']}

def approval_path(c,i): return Path(c['approval_dir'])/(i['metadata_sha256']+'.json')

def approval(c,i,instant):
    p=approval_path(c,i)
    if not p.exists() and not p.is_symlink(): return False
    try:
        key=trusted_file(c['approval_key']); require(recovery.digest(key)==c['approval_pin'],'Pilot key changed')
        envelope=ui.read_json(p,65536); require(set(envelope)=={'payload','signature'},'Invalid approval envelope')
        raw=base64.b64decode(envelope['payload'],validate=True); sig=base64.b64decode(envelope['signature'],validate=True)
        require(0<len(raw)<=4096 and 0<len(sig)<=16384,'Invalid approval sizes')
        with tempfile.TemporaryDirectory() as d:
            data=Path(d)/'data'; signature=Path(d)/'sig'; data.write_bytes(raw);signature.write_bytes(sig)
            pkg.verify_signature(data,signature,key)
        a=json.loads(raw); require(raw==recovery.canonical(a) and set(a)=={'format','release','approved_at','pilot_transaction'} and a['format']==1 and a['release']==i and re.fullmatch(r'[a-f0-9]{32}',a['pilot_transaction']),'Approval is not for this exact release')
        approved=dt.datetime.fromisoformat(a['approved_at']); require(approved.tzinfo is not None,'Missing UTC offset')
        return dt.timedelta(seconds=-60)<=instant-approved<=dt.timedelta(days=7)
    except (OSError,ValueError,TypeError,KeyError,subprocess.SubprocessError): return False

def source_archive(c,state,m,version,schema):
    candidates=[x for x in m['supported_from'] if x['version']==version and x['schema']==schema]
    require(len(candidates)==1,'No signed predecessor')
    sha=candidates[0]['archive_sha256']; require(pkg.HEX.fullmatch(sha),'Invalid predecessor digest')
    name='CajeerWorkspace-'+version+'.zip'
    for p in (Path(c['archive_dir'])/name,state/'packages'/sha/name):
        if p.is_file() and not p.is_symlink() and p.resolve()==p and recovery.digest(p)==sha: return p
    raise ValueError('Exact signed predecessor archive unavailable')

def cli(c,env,lockfd,action,*args):
    root=Path(c['root']); command=['php',str(root/'current/bin/workspace'),'updates:'+action,*map(str,args)]
    # Hold the scheduler lease across child execution, even if the parent is killed.
    r=subprocess.run(command,env=env,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=600,pass_fds=(lockfd,))
    require(len(r.stdout)<=131072,'Oversized CLI response'); out=json.loads(r.stdout)
    require(isinstance(out,dict),'Invalid CLI result')
    if r.returncode and out.get('status')!='incompatible': raise ValueError('Update '+action+' failed')
    return out

def active_journal(state):
    path=state/'packages/install.json'
    return ui.read_json(path) if path.exists() or path.is_symlink() else None

def revoke(c,i):
    p=approval_path(c,i)
    if p.exists() or p.is_symlink():
        p.unlink(); recovery.fsync_dir(p.parent)

def quarantine(c,info,env):
    ok=True
    if c['role']=='pilot':
        try: revoke(c,info)
        except (OSError,ValueError): ok=False
    try: hook(c,'quiesce',info,env)
    except (OSError,ValueError,subprocess.SubprocessError): ok=False
    return ok

def run(state,s,c,lockfd,token):
    if s['pending']:
        s['paused']=True; v=s['pending']['version']
        if not quarantine(c,s['pending'],dict(os.environ)): return event(state,s,c,'recovery_quiesce_failed',v)
        return event(state,s,c,'recovery_required',v)
    if not s['enabled']: return event(state,s,c,'disabled')
    if s['paused']: return event(state,s,c,'paused')
    if not in_window(c,now()): return event(state,s,c,'outside_window',notify=False)
    j=active_journal(state)
    if j and j['phase'] not in ui.FINAL:
        s['paused']=True; return event(state,s,c,'recovery_required',j['target'])
    env=dict(os.environ,WORKSPACE_UPDATE_CHANNEL=c['channel'],WORKSPACE_UPDATE_SCHEDULE_TOKEN=token)
    for name in ('WORKSPACE_UPDATE_MAINTENANCE_TOKEN','WORKSPACE_UPDATE_EXPECTED_METADATA','WORKSPACE_GITHUB_RELEASE_TOKEN'): env.pop(name,None)
    info=None; began=False
    try:
        config(c)
        r=cli(c,env,lockfd,'check'); status=r['status']
        if status!='compatible': return event(state,s,c,status,r.get('release',{}).get('version'))
        m=r['release']; info=identity(m); v=info['version']
        require(pkg.VERSION.fullmatch(v) and (m['channel']=='stable' or c['channel']=='preview' and m['channel']=='preview'),'Channel mismatch')
        if v in s['skipped']: return event(state,s,c,'skipped',v)
        if c['role']=='follower' and not approval(c,info,now()): return event(state,s,c,'waiting_for_pilot',v)
        previous=source_archive(c,state,m,r['installed_version'],r['installed_schema'])
        env['WORKSPACE_UPDATE_EXPECTED_METADATA']=info['metadata_sha256']
        require(cli(c,env,lockfd,'download')['status']=='downloaded','Download failed')
        p=cli(c,env,lockfd,'plan',previous)
        require(p['status']=='planned' and identity(p['release'])==info and p['package_result']['metadata_sha256']==info['metadata_sha256'],'Plan changed')
        if not in_window(c,now()): return event(state,s,c,'outside_window',v,notify=False)
        if c['role']=='follower' and not approval(c,info,now()): return event(state,s,c,'waiting_for_pilot',v)
        args=SimpleNamespace(action='install',root=Path(c['root']),previous=previous,data=Path(os.environ['WORKSPACE_DATA']),objects=Path(os.environ['WORKSPACE_OBJECT_DIR']),stop_unit=c['stop_units'],offline_confirmed=True,pg_restore_database=c['pg_restore_database'])
        # Persist intent BEFORE calling any hook that might stop services. A restart is not allowed to guess.
        s['pending']={**info,'stage':'quiesce'};s['healthy']=None; began=True;event(state,s,c,'installing',v,notify=False)
        if c['role']=='pilot': revoke(c,info)
        hook(c,'quiesce',info,env)
        s['pending']['stage']='install';save(state,s,c)
        result=ui.install(args); require(result['status']=='switched' and result['current']==v,'Install did not reach verified switch')
        tx=result['transaction'];s['pending']['stage']='finish';save(state,s,c);args.action='finish';result=ui.install(args)
        require(result['status']=='completed' and result['current']==v and result['transaction']==tx,'Installer did not complete')
        # Finish releases the DB marker. Keep ingress shut; never roll back old data after this point.
        for stage in ('start','health','open'):
            s['pending']['stage']=stage;save(state,s,c);hook(c,stage,info,env)
        s['healthy']={'release':info,'transaction':tx,'at':stamp()};s['pending']=None
        return event(state,s,c,'pilot_healthy' if c['role']=='pilot' else 'updated',v)
    except (OSError,ValueError,KeyError,TypeError,RuntimeError,subprocess.SubprocessError) as e:
        if began:
            s['paused']=True
            if not quarantine(c,info,env): return event(state,s,c,'recovery_quiesce_failed',info['version'])
            return event(state,s,c,'recovery_required',info['version'])
        # No writers were stopped; next scheduled tick may retry.
        return event(state,s,c,'check_failed',info['version'] if info else None)

def approve(state,s,c,private):
    require(c['role']=='pilot' and s['enabled'] and not s['paused'] and not s['pending'] and s['healthy'],'No healthy pilot to approve')
    h=s['healthy'];i=h['release'];j=active_journal(state)
    require(j and j['phase']=='completed' and j['id']==h['transaction'] and j['metadata_sha256']==i['metadata_sha256'] and j['package_sha256']==i['package_sha256'] and j['target']==i['version'] and ui.current(Path(c['root']))==i['version'],'Pilot installation evidence changed')
    require(dt.timedelta(0)<=now()-dt.datetime.fromisoformat(h['at'])<=dt.timedelta(days=7),'Pilot evidence expired')
    require(os.environ.get('WORKSPACE_UPDATE_KEY_SHA256')==i['release_key_sha256'],'Release trust changed')
    try: hook(c,'health',i,dict(os.environ))
    except (OSError,ValueError,subprocess.SubprocessError):
        s['paused']=True;s['pending']={**i,'stage':'pilot_recheck'};save(state,s,c)
        if not quarantine(c,i,dict(os.environ)): return event(state,s,c,'recovery_quiesce_failed',i['version'])
        return event(state,s,c,'pilot_failed',i['version'])
    key=trusted_file(private);require(not key.stat().st_mode & 0o077,'Private pilot key must be private')
    payload=recovery.canonical({'format':1,'release':i,'approved_at':stamp(),'pilot_transaction':h['transaction']})
    sig=subprocess.run(['openssl','dgst','-sha256','-sign',str(key)],input=payload,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,check=True,timeout=30).stdout
    # Verify signature against the configured public key before publishing atomically.
    with tempfile.TemporaryDirectory() as d:
        data=Path(d)/'data';signature=Path(d)/'sig';data.write_bytes(payload);signature.write_bytes(sig);pkg.verify_signature(data,signature,Path(c['approval_key']))
    ui.atomic_json(approval_path(c,i),{'payload':base64.b64encode(payload).decode(),'signature':base64.b64encode(sig).decode()},0o640)
    return event(state,s,c,'pilot_approved',i['version'])

def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('action',choices=['configure','enable','disable','pause','resume','skip','unskip','status','run','approve','revoke','ack-recovery']);p.add_argument('value',nargs='?');a=p.parse_args();os.umask(0o077)
    try:
        state=ui.directory(Path(os.environ['WORKSPACE_UPDATE_STATE_DIR']))
        require(os.geteuid()!=0,'Run scheduler as dedicated non-root update operator')
        with ui.exclusive(state/'schedule.lock') as fd:
            token=secrets.token_hex(32);os.ftruncate(fd,0);os.write(fd,token.encode());os.fsync(fd)
            try:
                s=load(state); c=load_config(state) if (state/'schedule-config.json').exists() else None
                require((a.value is not None)==(a.action in ('configure','skip','unskip','approve','revoke')),'Invalid command arguments')
                if a.action=='configure':
                    require(not s['pending'],'Recover pending installation first');c=config(ui.read_json(trusted_file(a.value),65536));s['enabled']=False;s['healthy']=None;ui.atomic_json(state/'schedule-config.json',c);result=event(state,s,c,'disabled')
                elif a.action=='status': result={k:v for k,v in s.items() if k not in ('pending','healthy')};result['pending']=bool(s['pending']);result['policy']={k:c[k] for k in ('channel','role','timezone','days','start','end')} if c else None
                elif a.action=='run' and not c: result=event(state,s,None,'disabled')
                else:
                    require(c is not None,'Configure scheduler first')
                    if a.action=='run': result=run(state,s,c,fd,token)
                    elif a.action=='approve': result=approve(state,s,config(c),a.value)
                    elif a.action=='revoke':
                        require(c['role']=='pilot' and pkg.HEX.fullmatch(a.value),'Pilot metadata SHA-256 required');revoke(c,{'metadata_sha256':a.value});s['paused']=True;result=event(state,s,c,'pilot_revoked')
                    elif a.action=='ack-recovery':
                        j=active_journal(state);marker=Path(os.environ['WORKSPACE_DATA'])/'update-maintenance.json';require(s['pending'] and (not j or j['phase'] in ui.FINAL) and not marker.exists() and not marker.is_symlink(),'Finish reviewed recovery before acknowledgement');s['pending']=None;s['healthy']=None;s['paused']=True;result=event(state,s,c,'recovery_acknowledged')
                    else:
                        if a.action in ('enable','resume'): require(not s['pending'],'Recover pending installation first');config(c);s['enabled']=True;s['paused']=False
                        if a.action=='disable': s['enabled']=False
                        if a.action=='pause': s['paused']=True
                        if a.action in ('skip','unskip'):
                            require(pkg.VERSION.fullmatch(a.value),'Invalid version');values=set(s['skipped']);values.add(a.value) if a.action=='skip' else values.discard(a.value);require(len(values)<=100,'Too many skipped versions');s['skipped']=sorted(values)
                        result=event(state,s,c,{'enable':'enabled','resume':'enabled','disable':'disabled','pause':'paused','skip':'skip_added','unskip':'skip_removed'}[a.action],a.value)
                print(json.dumps(result,ensure_ascii=False));return 1 if result.get('status') in ('recovery_required','recovery_quiesce_failed','check_failed','pilot_failed') else 0
            finally: os.ftruncate(fd,0);os.fsync(fd)
    except (OSError,ValueError,KeyError,TypeError,RuntimeError,ZoneInfoNotFoundError,subprocess.SubprocessError) as e:
        print('Automatic update command failed: '+str(e)[:300],file=sys.stderr);return 1

if __name__=='__main__': sys.exit(main())
