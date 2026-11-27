"""Signed offline install, real SQLite backup/objects, interruption and access lease tests."""
import json
from contextlib import closing
import os
from pathlib import Path
import runpy
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch
import zipfile

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
import update_install as ui
import update_package as pkg
import recovery
import release

class InstallTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.t=tempfile.TemporaryDirectory(); cls.addClassCleanup(cls.t.cleanup); cls.base=Path(cls.t.name)
        actual=os.getenv('WORKSPACE_PREVIOUS_RELEASE')
        cls.source_schema=36 if actual else 37
        current=(ROOT/'VERSION').read_text().strip()
        cls.previous=Path(actual).resolve() if actual else release.build(cls.base/'previous'); cls.source=cls.previous.stem.removeprefix('CajeerWorkspace-'); cls.target=current if actual else '1.3.2'
        cls.key=cls.base/'key'; cls.pub=cls.base/'pub'; cls.sig=cls.base/'sig'
        subprocess.run(['openssl','genpkey','-algorithm','RSA','-pkeyopt','rsa_keygen_bits:2048','-out',cls.key],check=True,capture_output=True)
        subprocess.run(['openssl','pkey','-in',cls.key,'-pubout','-out',cls.pub],check=True,capture_output=True)
        if actual:
            cls.archive=release.build(cls.base/'target')
            with zipfile.ZipFile(cls.archive) as z: files={n:z.read(n) for n in z.namelist() if n!='SHA256SUMS'}
            matrix=json.loads(files['config/compatibility.json'])
        else:
            with zipfile.ZipFile(cls.previous) as z: files={n:z.read(n) for n in z.namelist() if n!='SHA256SUMS'}
            files['VERSION']=(cls.target+'\n').encode(); files['app/Core.php']=files['app/Core.php'].replace(f"const VERSION = '{cls.source}'".encode(),f"const VERSION = '{cls.target}'".encode())
            matrix=json.loads(files['config/compatibility.json']);matrix['version']=cls.target;files['config/compatibility.json']=recovery.canonical(matrix)
            cls.archive=cls.base/f'CajeerWorkspace-{cls.target}.zip'
            with zipfile.ZipFile(cls.archive,'w') as z:
                for n,b in files.items():release.zip_entry(z,n,b)
                release.zip_entry(z,'SHA256SUMS',''.join(f'{release.sha(b)}  {n}\n' for n,b in sorted(files.items())).encode())
        cls.sign(cls.archive,cls.sig)
        cls.pin=recovery.digest(cls.pub)
        runtime={k:matrix['runtime'][k] for k in ('php_min','sqlite_min','postgres_min','python_min','architecture')};runtime.update(php_max_exclusive='9.0',extensions=['pdo','curl','session','openssl'])
        cls.m={'format':1,'repository':pkg.REPO,'channel':'stable','version':cls.target,'tag':'v'+cls.target,'commit':'a'*40,'schema':37,'supported_from':[{'version':cls.source,'schema':cls.source_schema,'archive_sha256':recovery.digest(cls.previous)}],'runtime':runtime,'package':{'name':cls.archive.name,'size':cls.archive.stat().st_size,'sha256':recovery.digest(cls.archive),'signature_sha256':recovery.digest(cls.sig)},'matrix_sha256':recovery.sha(files['config/compatibility.json']),'acceptance_sha256':'b'*64,'release_key_sha256':cls.pin,'accepted_at':'2026-01-01T00:00:00Z'}
        cls.native=subprocess.run(['php',ROOT/'bin/workspace','doctor'],env=dict(os.environ,WORKSPACE_DB='sqlite'),capture_output=True).returncode==0
    @classmethod
    def sign(cls,p,s):subprocess.run(['openssl','dgst','-sha256','-sign',cls.key,'-out',s,p],check=True,capture_output=True)
    def setUp(self):
        t=tempfile.TemporaryDirectory(dir=self.base);self.addCleanup(t.cleanup); self.p=Path(t.name)
        self.data,self.objects,self.db=runpy.run_path(str(ROOT/'tests/recovery.py'))['seed'](self.p,self.source_schema)
        self.state=self.p/'state';self.state.mkdir(mode=0o700);self.store=self.state/'packages';self.store.mkdir(mode=0o700)
        self.root=self.p/'managed';self.root.mkdir(mode=0o700);rels=self.root/'releases';rels.mkdir(mode=0o700)
        ui.stage_tree(rels,self.previous,self.source,ui.payload(self.previous,self.source,self.source_schema)); ui.switch(self.root,self.source)
        self.folder=self.store/self.m['package']['sha256'];self.folder.mkdir(mode=0o700);shutil.copyfile(self.archive,self.folder/self.archive.name);shutil.copyfile(self.sig,self.folder/(self.archive.name+'.sig'))
        raw=self.folder/pkg.META;raw.write_bytes(recovery.canonical(self.m));self.sign(raw,self.folder/(pkg.META+'.sig'))
        ui.atomic_json(self.state/'report.json',{'status':'planned','operation':'plan','checked_at':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'trust_pin':self.pin,'installed_version':self.source,'installed_schema':self.source_schema,'release':self.m,'package_result':{'status':'planned','previous_sha256':recovery.digest(self.previous),'source_schema':self.source_schema,'target_schema':37}},0o640)
        self.env=dict(WORKSPACE_DB='sqlite',WORKSPACE_DATA=str(self.data),WORKSPACE_OBJECT_DIR=str(self.objects),WORKSPACE_UPDATE_STATE_DIR=str(self.state),WORKSPACE_UPDATE_KEY_SHA256=self.pin,WORKSPACE_UPDATE_PUBLIC_KEY=str(self.pub))
        self.args=type('Args',(),{})();self.args.root=self.root;self.args.previous=self.previous;self.args.data=self.data;self.args.objects=self.objects;self.args.offline_confirmed=True;self.args.stop_unit=[];self.args.pg_restore_database=None
        with patch.object(recovery,'SCHEMA',self.source_schema):self.before=recovery.sqlite_snapshot(self.db,self.objects)
        env=patch.dict(os.environ,self.env);env.start();self.addCleanup(env.stop)
        if not self.native and self._testMethodName!='test_postgres_install':
            # Only PHP DB-dependent execution is substituted; real signed ZIP, journal, locks, backup and snapshot still execute.
            for n in ('environment','smoke','php'):
                x=patch.object(ui,n,return_value=None);x.start();self.addCleanup(x.stop)
            if self.source_schema!=37:
                def migrate(folder,args,env,fds):
                    self.assertEqual(args,['upgrade']);sql=ROOT/'database/037.sql'
                    with closing(sqlite3.connect(self.db)) as db,db:
                        db.executescript(sql.read_text());db.execute('INSERT INTO schema_migrations(version,sha256,origin) VALUES(?,?,?)',(37,recovery.digest(sql),'applied'));db.execute('PRAGMA user_version=37')
                x=patch.object(ui,'php',side_effect=migrate);x.start();self.addCleanup(x.stop)
    def run_action(self,action): self.args.action=action;return ui.install(self.args)
    def journal(self): return ui.read_json(self.store/'install.json')
    def assert_migrated(self):
        after=recovery.sqlite_snapshot(self.db,self.objects)
        if self.source_schema==37:self.assertEqual(after,self.before)
        else:ui.controls(self.before,after,37)
    def test_install_finish(self):
        r=self.run_action('install');self.assertEqual(r['status'],'switched');self.assertEqual(ui.current(self.root),self.target)
        self.assertTrue((self.data/'update-maintenance.json').exists());self.assert_migrated()
        self.assertEqual(self.run_action('finish')['status'],'completed');self.assertFalse((self.data/'update-maintenance.json').exists());self.assertEqual(self.journal()['phase'],'completed')
    def test_scheduler_uses_real_installer(self):
        import update_schedule as sched
        c={'root':str(self.root),'archive_dir':str(self.previous.parent),'approval_dir':str(self.p),'role':'pilot','channel':'stable','timezone':'UTC','days':[1,2,3,4,5,6,7],'start':'00:00','end':'23:59','hooks':{},'stop_units':[],'pg_restore_database':None}
        state=sched.default_state();state['enabled']=True
        report=ui.read_json(self.state/'report.json');report['package_result']['metadata_sha256']=recovery.sha(recovery.canonical(self.m));ui.atomic_json(self.state/'report.json',report,0o640)
        calls=[]
        def cli(c,env,fd,action,*args):
            calls.append(action);return {**report,'status':{'check':'compatible','download':'downloaded','plan':'planned'}[action]}
        def hook(c,name,info,env=None):calls.append(name)
        with patch.object(sched,'config',return_value=c),patch.object(sched,'in_window',return_value=True),patch.object(sched,'cli',side_effect=cli),patch.object(sched,'hook',side_effect=hook):
            with ui.exclusive(self.state/'schedule.lock') as fd:
                self.assertEqual(sched.run(self.state,state,c,fd,'e'*64)['status'],'pilot_healthy')
        self.assertEqual(calls,['check','download','plan','quiesce','start','health','open']);self.assertEqual(ui.current(self.root),self.target)
        self.assertEqual(self.journal()['phase'],'completed');self.assert_migrated()
        self.assertFalse((self.data/'update-maintenance.json').exists());self.assertIsNotNone(sched.load(self.state)['healthy'])
    def test_resume_after_switch_failure(self):
        with patch.object(ui,'switch',side_effect=OSError('injected')):
            with self.assertRaises(OSError):self.run_action('install')
        self.assertEqual(self.journal()['phase'],'migrated');self.assertTrue((self.data/'update-maintenance.json').exists());self.assertEqual(ui.current(self.root),self.source)
        self.assertEqual(self.run_action('install')['status'],'switched');self.assertEqual(self.run_action('rollback')['status'],'rolled_back');self.assertEqual(ui.current(self.root),self.source)
    def test_interrupted_backup(self):
        original=ui.command
        def fail(args,*a,**kw):
            if 'backup' in args:raise OSError('injected')
            return original(args,*a,**kw)
        with patch.object(ui,'command',side_effect=fail):
            with self.assertRaises(OSError):self.run_action('install')
        self.assertEqual(self.journal()['phase'],'backup_started');self.assertEqual(self.run_action('install')['status'],'switched')
    def test_abort_before_backup_with_corrupt_target(self):
        original=ui.command
        def fail(args,*a,**kw):
            if 'backup' in args:raise OSError('injected')
            return original(args,*a,**kw)
        with patch.object(ui,'command',side_effect=fail):
            with self.assertRaises(OSError):self.run_action('install')
        (self.folder/self.archive.name).unlink()
        self.assertEqual(self.run_action('rollback')['status'],'rolled_back');self.assertEqual(ui.current(self.root),self.source)
        self.assertFalse((self.data/'update-maintenance.json').exists())
    def test_rollback_rejects_new_writes(self):
        self.run_action('install')
        with closing(sqlite3.connect(self.db)) as db, db:db.execute("UPDATE projects SET title='new work' WHERE id=1")
        with self.assertRaisesRegex(ValueError,'Rollback requires'):self.run_action('rollback')
        self.assertTrue((self.data/'update-maintenance.json').exists());self.assertEqual(ui.current(self.root),self.target)
    def test_tampering(self):
        with (self.folder/self.archive.name).open('ab') as f:f.write(b'x')
        with self.assertRaisesRegex(ValueError,'size or hash'):self.run_action('install')
        self.assertFalse((self.data/'update-maintenance.json').exists());self.assertEqual(ui.current(self.root),self.source)
    def test_lock(self):
        with ui.exclusive(self.state/'update.lock'):
            with self.assertRaises(BlockingIOError):self.run_action('install')
    def test_lease(self):
        code="require 'app/bootstrap.php'; try{\\Workspace\\updateWriterLease();echo 'ok';}catch(\\Workspace\\Problem $e){exit($e->status===503?3:2);}"
        def php():return subprocess.run(['php','-r',code],cwd=ROOT,capture_output=True)
        self.assertEqual(php().stdout,b'ok')
        with ui.exclusive(self.data/'update-access.lock'):self.assertEqual(php().returncode,3)
        ui.atomic_json(self.data/'update-maintenance.json',{'token':'a'*64});self.assertEqual(php().returncode,3)
        with patch.dict(os.environ,{'WORKSPACE_UPDATE_MAINTENANCE_TOKEN':'a'*64}):self.assertEqual(php().stdout,b'ok')
    def test_stop_units(self):
        calls=[]
        def runner(args,*a,**kw):
            calls.append(args)
            if '--property=LoadState' in args:return b'loaded\n'
            if '--property=ActiveState' in args:return b'inactive\n'
            return b''
        with patch.object(ui,'command',side_effect=runner):
            ui.stop_units(['workspace-worker.service','workspace.timer'],{},())
            self.assertEqual(calls[0],['systemctl','stop','workspace-worker.service','workspace.timer'])
            with self.assertRaisesRegex(ValueError,'Invalid systemd'):ui.stop_units(['--all.service'],{},())
        with patch.object(ui,'command',return_value=b'active'):
            with self.assertRaises(ValueError):ui.stop_units(['workspace-worker.service'],{},())
    def test_postgres_install(self):
        if os.getenv('WORKSPACE_UPDATE_TEST_PG')!='1':self.skipTest('Disposable PostgreSQL CI not requested')
        self.assertEqual(os.getenv('WORKSPACE_RECOVERY_CI'),'1');self.assertEqual(os.getenv('PGHOST'),'127.0.0.1')
        source='workspace_update_src_'+os.urandom(4).hex(); target='workspace_update_dst_'+os.urandom(4).hex()
        for name in (source,target):
            subprocess.run(['createdb',name],check=True,capture_output=True,timeout=30)
            self.addCleanup(subprocess.run,['dropdb','--if-exists',name],check=False,capture_output=True,timeout=30)
        os.environ.update(WORKSPACE_DB='pgsql',PGDATABASE=source,WORKSPACE_RECOVERY_OFFLINE='1')
        self.args.pg_restore_database=target
        # Fresh private data and object directories, no SQLite fixture content.
        self.data=self.p/'pg-data';self.data.mkdir(mode=0o700);self.objects=self.p/'pg-objects';self.objects.mkdir(mode=0o700)
        os.environ.update(WORKSPACE_DATA=str(self.data),WORKSPACE_OBJECT_DIR=str(self.objects));self.args.data=self.data;self.args.objects=self.objects
        subprocess.run(['php',self.root/'current/bin/workspace','init','owner@example.test','Owner'],input=b'test-only-password-123!\n',check=True,capture_output=True,timeout=60)
        subprocess.run(['psql','-X','-v','ON_ERROR_STOP=1','-c',"INSERT INTO projects(title,kind,organization_id) VALUES('Committed','internal',1); INSERT INTO cash_entries(project_id,direction,amount_minor,currency,booked_on,memo,actor_id) VALUES(1,'income',1234,'EUR','2026-01-01','fixture',1)"],check=True,capture_output=True,timeout=30)
        before=recovery.pg_snapshot(self.objects,self.data)
        self.assertEqual(self.run_action('install')['status'],'switched');self.assertTrue(self.journal()['pg_restore_verified'])
        self.assertEqual(self.run_action('finish')['status'],'completed');self.assertEqual(recovery.pg_snapshot(self.objects,self.data),before)
    def test_forward_schema(self):
        # A separately signed future fixture exercises the migration path, not a production schema change in 1.3.1.
        with zipfile.ZipFile(self.archive) as z: files={n:z.read(n) for n in z.namelist() if n!='SHA256SUMS'}
        files['app/Core.php']=files['app/Core.php'].replace(b'const SCHEMA_VERSION = 37;',b'const SCHEMA_VERSION = 38;')
        old_freeze=release.sha(files['database/schema-freeze.json']); freeze=json.loads(files['database/schema-freeze.json']);freeze['schema']=38
        sql=b'CREATE TABLE update_fixture(id INTEGER PRIMARY KEY);\n'
        for name in ('database/038.sql','database/postgresql/038.sql'):
            files[name]=sql;freeze['migrations'][name]=release.sha(sql)
        files['database/schema-freeze.json']=recovery.canonical(freeze)
        files['app/Migrations.php']=files['app/Migrations.php'].replace(old_freeze.encode(),release.sha(files['database/schema-freeze.json']).encode())
        matrix=json.loads(files['config/compatibility.json']);matrix['schema']=38;files['config/compatibility.json']=recovery.canonical(matrix)
        archive=self.p/self.archive.name
        with zipfile.ZipFile(archive,'w') as z:
            for n,b in files.items():release.zip_entry(z,n,b)
            release.zip_entry(z,'SHA256SUMS',''.join(f'{release.sha(b)}  {n}\n' for n,b in sorted(files.items())).encode())
        sig=self.p/'schema.sig';self.sign(archive,sig)
        m=json.loads(json.dumps(self.m));m['schema']=38;m['matrix_sha256']=release.sha(files['config/compatibility.json']);m['package'].update(size=archive.stat().st_size,sha256=recovery.digest(archive),signature_sha256=recovery.digest(sig))
        folder=self.store/m['package']['sha256'];folder.mkdir(mode=0o700);shutil.copyfile(archive,folder/archive.name);shutil.copyfile(sig,folder/(archive.name+'.sig'))
        raw=folder/pkg.META;raw.write_bytes(recovery.canonical(m));self.sign(raw,folder/(pkg.META+'.sig'))
        r=ui.read_json(self.state/'report.json');r['release']=m;r['package_result']['target_schema']=38;ui.atomic_json(self.state/'report.json',r,0o640)
        def fixture_migrate(folder,args,env,fds):
            # Used only when native PHP PDO/cURL is unavailable. Actual PHP upgrade is exercised in CI.
            self.assertEqual(args,['upgrade'])
            with closing(sqlite3.connect(self.db)) as db,db:
                db.execute(sql.decode());db.execute('INSERT INTO schema_migrations(version,sha256,origin) VALUES(?,?,?)',(38,release.sha(sql),'applied'));db.execute('PRAGMA user_version=38')
        if self.native: result=self.run_action('install')
        else:
            with patch.object(ui,'php',side_effect=fixture_migrate):result=self.run_action('install')
        self.assertEqual(result['status'],'switched')
        with closing(sqlite3.connect(self.db)) as db:self.assertEqual(db.execute('PRAGMA user_version').fetchone()[0],38)
        self.assertEqual(self.run_action('finish')['status'],'completed')
    def test_schema_controls(self):
        before=json.loads(json.dumps(self.before));after=json.loads(json.dumps(before));after['schema']=38;after['tables']['new_table']={'rows':0,'sha256':'a'*64}
        ui.controls(before,after,38)
        after['cash']=[]
        with self.assertRaisesRegex(ValueError,'control totals'):ui.controls(before,after,38)

if __name__=='__main__':unittest.main(verbosity=2)
