"""Destructive only to a disposable CI PostgreSQL container explicitly supplied by CI."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import time

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
import recovery

assert os.environ.get('WORKSPACE_RECOVERY_CI')=='1'
assert os.environ.get('PGHOST')=='127.0.0.1' and os.environ.get('PGDATABASE')=='workspace_recovery_src'
assert len(sys.argv)==2 and re.fullmatch(r'[a-f0-9]{12,64}',sys.argv[1])
container=sys.argv[1]
assert subprocess.check_output(['docker','inspect','--format','{{.Config.Image}}',container],text=True).strip()=='postgres:16'
os.umask(0o077);os.environ.update(WORKSPACE_DB='pgsql',WORKSPACE_RECOVERY_OFFLINE='1')


def sql(query):
    return subprocess.check_output(['psql','-X','-v','ON_ERROR_STOP=1','-Atc',query],text=True).strip()


def cli(*args,input=None,ok=True):
    proc=subprocess.run(['php',str(ROOT/'bin/workspace'),*args],input=input,text=True,capture_output=True,timeout=60)
    assert (proc.returncode==0)==ok, (args,proc.returncode)
    return proc.stdout


with tempfile.TemporaryDirectory() as tmp:
    root=Path(tmp);objects=root/'objects';objects.mkdir(mode=0o700);data=root/'data';data.mkdir(mode=0o700)
    os.environ.update(WORKSPACE_OBJECT_DIR=str(objects),WORKSPACE_DATA=str(data))
    cli('init','owner@example.test','Owner',input='test-password-only\n')
    sql("INSERT INTO projects(title,kind,organization_id) VALUES('Committed','internal',1)")
    sql("INSERT INTO cash_entries(project_id,direction,amount_minor,currency,booked_on,memo,actor_id) VALUES(1,'income',1000,'EUR','2026-01-01','fixture',1)")
    sql("INSERT INTO documents(project_id,title,kind) VALUES(1,'Fixture','contract')")
    pdf=b'%PDF-1.4\nfixture\n%%EOF';key='a'*64;(objects/(key+'.pdf')).write_bytes(pdf)
    sql("INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id,object_key,original_size,storage_state) VALUES(1,1,'%s',decode('%s','hex'),1,'%s',%d,'object')"%(hashlib.sha256(pdf).hexdigest(),recovery.MARKER.hex(),key,len(pdf)))
    before=recovery.pg_snapshot(objects)
    b=recovery.backup('pgsql',None,objects,root/'backup')
    # Kill the actual DB server during an uncommitted write; wait for recovery.
    env=os.environ.copy();env['PGAPPNAME']='workspace-recovery-crash'
    with subprocess.Popen(['psql','-X','-v','ON_ERROR_STOP=1','-c',"BEGIN; UPDATE projects SET title='UNCOMMITTED' WHERE id=1; SELECT pg_sleep(60); COMMIT"],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL) as proc:
        try:
            for _ in range(100):
                if sql("SELECT count(*) FROM pg_stat_activity WHERE application_name='workspace-recovery-crash' AND wait_event='PgSleep'")=='1':break
                time.sleep(.1)
            else:raise RuntimeError('transaction never started')
            subprocess.run(['docker','kill','--signal=KILL',container],check=True,timeout=20,stdout=subprocess.DEVNULL)
            subprocess.run(['docker','start',container],check=True,timeout=20,stdout=subprocess.DEVNULL)
            for _ in range(100):
                if subprocess.run(['pg_isready','-q'],check=False).returncode==0:break
                time.sleep(.2)
            else:raise RuntimeError('PostgreSQL did not restart')
            assert proc.wait(timeout=20)!=0
        finally:
            if proc.poll() is None:proc.kill();proc.wait()
    assert recovery.canonical(recovery.pg_snapshot(objects))==recovery.canonical(before)
    # Another database, a new data directory and private object root. No drop/overwrite.
    subprocess.run(['createdb','workspace_recovery_dst'],check=True,timeout=30)
    os.environ['PGDATABASE']='workspace_recovery_dst'
    restored=root/'offsite';result=recovery.restore(root/'backup',b['manifest_sha256'],restored,time.time())
    assert result['scope_and_queue_snapshot_match'] and result['quarantined']
    os.environ.update(WORKSPACE_DATA=str(restored/'data'),WORKSPACE_OBJECT_DIR=str(restored/'objects'))
    cli('reliability:work','1',ok=False)
    cli('recovery:release-quarantine',b['manifest_sha256'],'--reviewed')
    assert not (restored/'data/recovery.lock').exists()
    assert sql('SELECT title FROM projects WHERE id=1')=='Committed'
    print(json.dumps(result,indent=2))
