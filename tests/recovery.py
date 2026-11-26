"""Real SQLite/WAL + private object filesystem recovery and HTTP profile checks."""
import hashlib
from contextlib import closing
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
import recovery
import load


def seed(root, schema=recovery.SCHEMA):
    data=root/'data';data.mkdir(mode=0o700);objects=root/'objects';objects.mkdir(mode=0o700);db=data/'workspace.sqlite'
    with closing(sqlite3.connect(db)) as c, c:
        for p in sorted((ROOT/'database').glob('*.sql')):
            if int(p.stem)<=schema: c.executescript(p.read_text())
        for p in sorted((ROOT/'database').glob('*.sql')):
            if int(p.stem)<=schema: c.execute('INSERT INTO schema_migrations(version,sha256,origin) VALUES(?,?,?)',(int(p.stem),hashlib.sha256(p.read_bytes()).hexdigest(),'applied'))
        c.execute(f'PRAGMA user_version={schema}')
        c.commit()
        assert c.execute('PRAGMA journal_mode=WAL').fetchone()[0]=='wal'
        c.execute("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'owner@example.test','Owner','owner','fixture')")
        c.execute("INSERT INTO organization_members VALUES(1,1,'owner')")
        c.execute("INSERT INTO organizations(id,name) VALUES(2,'Isolated')")
        c.execute("INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'Internal','internal',1),(2,'Client','client',2)")
        c.execute("INSERT INTO cash_entries(project_id,direction,amount_minor,currency,booked_on,memo,actor_id) VALUES(1,'income',20000,'EUR','2026-01-01','fixture',1),(1,'expense',3000,'EUR','2026-01-01','fixture',1),(2,'income',100,'USD','2026-01-01','fixture',1)")
        c.execute("INSERT INTO accounting_accounts(id,organization_id,code,name,kind) VALUES(1,1,'100','Cash','asset'),(2,1,'400','Revenue','income'),(3,2,'100','Cash','asset'),(4,2,'400','Revenue','income')")
        c.execute("INSERT INTO accounting_periods(id,organization_id,starts_on,ends_on) VALUES(1,1,'2026-01-01','2026-01-31'),(2,2,'2026-01-01','2026-01-31')")
        c.execute("INSERT INTO accounting_entries(organization_id,period_id,booked_on,currency,debit_id,credit_id,amount_minor,kind,memo,actor_id,created_at) VALUES(1,1,'2026-01-01','EUR',1,2,17000,'manual','fixture',1,0),(2,2,'2026-01-01','USD',3,4,100,'manual','fixture',1,0)")
        c.execute("INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'Contract','contract')")
        pdf=b'%PDF-1.4\n'+b'x'*1024+b'\n%%EOF';key='a'*64;sha=hashlib.sha256(pdf).hexdigest();(objects/(key+'.pdf')).write_bytes(pdf)
        c.execute("INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id,object_key,original_size,storage_state) VALUES(1,1,?,?,?,?,?,'object')",(sha,recovery.MARKER,1,key,len(pdf)))
        c.execute("INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(1,2,?,?,1)",(sha,pdf))
        c.execute("INSERT INTO integration_jobs(id,organization_id,project_id,host_id,actor_id,actor_session,target_hash,state,available_at,created_at,updated_at) VALUES(1,1,1,1,1,1,?,'pending',0,0,0)",('b'*64,))
    return data,objects,db


class RecoveryTest(unittest.TestCase):
    def setUp(self):
        self.t=tempfile.TemporaryDirectory();self.addCleanup(self.t.cleanup);self.root=Path(self.t.name);self.data,self.objects,self.db=seed(self.root)
    def create(self):
        dest=self.root/'backup';result=recovery.backup('sqlite',self.data,self.objects,dest);return dest,result['manifest_sha256']
    def test_backup_offsite_exact(self):
        dest,h=self.create();m,_,_=recovery.verify(dest,h)
        self.assertEqual(m['snapshot']['cash'],[[1,'EUR',17000],[2,'USD',100]])
        self.assertEqual(m['snapshot']['ledger'],[[1,'EUR',1,17000],[1,'EUR',2,-17000],[2,'USD',3,100],[2,'USD',4,-100]])
        self.assertEqual(m['snapshot']['tables']['integration_jobs']['rows'],1)
        self.assertEqual(m['snapshot']['tables']['organizations']['rows'],2)
        self.assertEqual(len(m['snapshot']['objects']),1)
        out=self.root/'other-site';r=recovery.restore(dest,h,out,time.time());self.assertTrue(r['quarantined']);self.assertTrue(r['cash_balances_match']);self.assertGreaterEqual(r['backup_age_at_outage_seconds'],0)
        self.assertEqual((out/'data/recovery.lock').read_text().strip(),h)
        print('Offline restore fixture:',json.dumps({k:r[k] for k in ('duration_seconds','backup_age_at_outage_seconds','outage_to_verified_restore_seconds','tables','objects')}))
        self.assertEqual(recovery.canonical(recovery.sqlite_snapshot(out/'data/workspace.sqlite',out/'objects')),recovery.canonical(m['snapshot']))
        with self.assertRaises(FileExistsError):recovery.restore(dest,h,out)
        with self.assertRaises(FileExistsError):recovery.backup('sqlite',self.data,self.objects,dest)
    def test_committed_wal_pages_in_backup(self):
        with closing(sqlite3.connect(self.db)) as c:
            c.execute("UPDATE projects SET title='WAL commit' WHERE id=1");c.commit()
            self.assertTrue(Path(str(self.db)+'-wal').stat().st_size>0)
            dest,h=self.create();recovery.verify(dest,h)
            with closing(sqlite3.connect(dest/'workspace.sqlite')) as copy:self.assertEqual(copy.execute("SELECT title FROM projects WHERE id=1").fetchone()[0],'WAL commit')
    def test_tampered_manifest_or_object(self):
        dest,h=self.create()
        with self.assertRaises(ValueError):recovery.verify(dest,'0'*64)
        p=next((dest/'objects').iterdir());p.write_bytes(b'x'*p.stat().st_size)
        with self.assertRaises(ValueError):recovery.restore(dest,h,self.root/'never')
        self.assertFalse((self.root/'never').exists())
    def test_corrupt_inline_and_missing_object(self):
        with closing(sqlite3.connect(self.db)) as c, c:c.execute("UPDATE document_versions SET bytes=? WHERE revision=2",(b'bad payload',))
        with self.assertRaises(ValueError):self.create()
    def test_actual_writer_kill_and_reopen(self):
        # Commit one change, leave another uncommitted, kill the real DB client.
        dest,h=self.create()
        code="import sqlite3,sys,time; c=sqlite3.connect(sys.argv[1]);c.execute(\"UPDATE projects SET title='committed' WHERE id=1\");c.commit();c.execute(\"UPDATE projects SET title='uncommitted' WHERE id=2\");print('READY',flush=True);time.sleep(60)"
        with subprocess.Popen([sys.executable,'-c',code,str(self.db)],stdout=subprocess.PIPE,text=True) as child:
            try:self.assertEqual(child.stdout.readline().strip(),'READY');child.kill();child.wait(timeout=5)
            finally:
                if child.poll() is None:child.kill();child.wait()
        with closing(sqlite3.connect(self.db)) as c, c:self.assertEqual(c.execute('SELECT title FROM projects ORDER BY id').fetchall(),[('committed',),('Client',)])
        r=recovery.restore(dest,h,self.root/'restored',time.time());self.assertTrue(r['scope_and_queue_snapshot_match'])
        with closing(sqlite3.connect(self.root/'restored/data/workspace.sqlite')) as c, c:self.assertEqual(c.execute('SELECT title FROM projects ORDER BY id').fetchall(),[('Internal',),('Client',)])
    def test_wrong_migration_ledger(self):
        with closing(sqlite3.connect(self.db)) as c, c:c.execute("UPDATE schema_migrations SET sha256=? WHERE version=1",('0'*64,))
        with self.assertRaises(ValueError):self.create()
    def test_cross_organization_fk(self):
        with closing(sqlite3.connect(self.db)) as c, c:c.execute("INSERT INTO project_members(organization_id,project_id,user_id) VALUES(1,2,1)")
        with self.assertRaises(ValueError):self.create()
    def test_no_symlink_objects(self):
        p=next(self.objects.iterdir());copy=self.root/'outside.pdf';p.rename(copy);p.symlink_to(copy)
        with self.assertRaises(ValueError):self.create()


class LoadTest(unittest.TestCase):
    def test_profile_and_rejection(self):
        class Handler(BaseHTTPRequestHandler):
            def do_GET(self):
                if self.path=='/health.php': self.send_response(200);body=b'{"status":"ready"}'
                elif self.headers.get('Cookie')=='session=fixture': self.send_response(200);body=b'project marker'
                else:self.send_response(302);self.send_header('Location','/health.php');body=b''
                self.end_headers();self.wfile.write(body)
            def log_message(self,*args):pass
        with tempfile.TemporaryDirectory() as tmp, ThreadingHTTPServer(('127.0.0.1',0),Handler) as server:
            thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
            try:
                cookie=Path(tmp)/'cookie';cookie.write_text('session=fixture');cookie.chmod(0o600)
                cfg={'origin':f'http://127.0.0.1:{server.server_port}','requests':40,'concurrency':4,'p95_limit_ms':5000,'routes':[{'name':'health','path':'/health.php','contains':'ready'},{'name':'project','path':'/?p=project&id=1','contains':'project marker','cookie_file':str(cookie)}]}
                r=load.run(cfg);self.assertEqual(r['status'],'passed');self.assertEqual(r['total']['success'],40);self.assertIsNotNone(r['total']['p95_ms']);print('HTTP fixture profile:',json.dumps(r['total']))
                del cfg['routes'][1]['cookie_file'];r=load.run(cfg);self.assertEqual(r['status'],'failed');self.assertEqual(r['routes']['project']['errors'],{'http_302':20})
                cfg['routes'][1]['path']='/index.php?p=sign.webhook';self.assertRaises(ValueError,load.run,cfg)
            finally:server.shutdown();thread.join()

if __name__=='__main__': unittest.main()
