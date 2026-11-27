"""Release signature, frozen schema and offline switch/rollback acceptance."""
from contextlib import closing
import json
import os
from pathlib import Path
import runpy
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
import zipfile

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
import candidate
import release
import recovery

class CandidateTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory(); cls.addClassCleanup(cls.temp.cleanup)
        cls.base=Path(cls.temp.name)
        cls.new=release.build(cls.base/'dist')
        cls.key=cls.base/'test.pem'; cls.pub=cls.base/'test.pub'; cls.sig=cls.base/'test.sig'
        subprocess.run(['openssl','genpkey','-algorithm','RSA','-pkeyopt','rsa_keygen_bits:2048','-out',str(cls.key)],check=True,capture_output=True)
        cls.key.chmod(0o600)
        subprocess.run(['openssl','pkey','-in',str(cls.key),'-pubout','-out',str(cls.pub)],check=True,capture_output=True)
        cls.keysha=recovery.digest(cls.pub); candidate.sign(cls.new,cls.key,cls.sig)
        cls.old=Path(os.environ['WORKSPACE_PREVIOUS_RELEASE']).resolve() if os.getenv('WORKSPACE_PREVIOUS_RELEASE') else None
        env=dict(os.environ,WORKSPACE_DB='sqlite'); cls.native=subprocess.run(['php',str(ROOT/'bin/workspace'),'doctor'],env=env,capture_output=True).returncode==0
        if os.getenv('WORKSPACE_CANDIDATE_REQUIRE_NATIVE')=='1' and (not cls.native or not cls.old): raise RuntimeError('Native PHP runtime and actual 1.3.0 release required')

    def setUp(self):
        self.t=tempfile.TemporaryDirectory(dir=self.base); self.addCleanup(self.t.cleanup); self.root=Path(self.t.name)

    def test_signature_and_key_pin(self):
        candidate.verify_signature(self.new,self.sig,self.pub,self.keysha)
        with self.assertRaises(ValueError): candidate.verify_signature(self.new,self.sig,self.pub,'0'*64)
        bad=self.root/self.new.name; bad.write_bytes(self.new.read_bytes()+b'x')
        with self.assertRaises(ValueError): candidate.verify_signature(bad,self.sig,self.pub,self.keysha)
        with self.assertRaises(ValueError): candidate.sign(self.new,self.key,self.sig)

    def test_frozen_sql_even_with_recomputed_manifest(self):
        raw=candidate.archive_payload(self.new,candidate.NEW); raw.pop('SHA256SUMS')
        for name in ('database/001.sql','database/postgresql/036.sql','database/037.sql','database/postgresql/037.sql'):
            changed=dict(raw); changed[name]+=b'\n-- unauthorized change\n'
            with self.assertRaises(ValueError): release.validate_payload(changed)
        self.assertEqual(len(candidate.FREEZE['migrations']),74)
        tampered=dict(raw); tampered['database/001.sql']+=b'\n-- change\n'
        freeze=json.loads(tampered['database/schema-freeze.json']); freeze['migrations']['database/001.sql']=release.sha(tampered['database/001.sql'])
        tampered['database/schema-freeze.json']=json.dumps(freeze).encode()
        with self.assertRaises(ValueError): release.validate_payload(tampered)

    def test_clean_sqlite_schema(self):
        path=self.root/'clean.sqlite'
        with closing(sqlite3.connect(path)) as db, db:
            db.execute('PRAGMA foreign_keys=ON')
            for i in range(1,candidate.SCHEMA+1):
                p=ROOT/'database'/f'{i:03}.sql'; db.executescript(p.read_text())
                if i==3:
                    for j in (1,2): db.execute('INSERT INTO schema_migrations(version,sha256,origin) VALUES(?,?,?)',(j,recovery.digest(ROOT/'database'/f'{j:03}.sql'),'applied'))
                if i>=3: db.execute('INSERT INTO schema_migrations(version,sha256,origin) VALUES(?,?,?)',(i,recovery.digest(p),'applied'))
            db.execute(f'PRAGMA user_version={candidate.SCHEMA}'); self.assertEqual(db.execute('PRAGMA integrity_check').fetchone()[0],'ok'); self.assertEqual(db.execute('PRAGMA foreign_key_check').fetchall(),[])
            self.assertEqual(db.execute('SELECT COUNT(*) FROM schema_migrations').fetchone()[0],candidate.SCHEMA)

    def test_native_clean_install(self):
        if not self.native: self.skipTest('Native PDO/cURL runtime unavailable')
        env=dict(os.environ,WORKSPACE_DB='sqlite',WORKSPACE_DATA=str(self.root/'native'))
        subprocess.run(['php',str(ROOT/'bin/workspace'),'init','candidate@example.test','Candidate owner'],input=b'Candidate-test-only-123!\n',env=env,check=True,capture_output=True)
        candidate.smoke(ROOT,candidate.NEW,env,upgrade=True)

    def fixture(self):
        if not self.old: self.skipTest('Actual previous release not supplied')
        # Seed real SQLite schema + money, PDF/object, queue and 2 organizations using existing fixture.
        seed=runpy.run_path(str(ROOT/'tests/recovery.py'))['seed']
        data,objects,db=seed(self.root,candidate.OLD_SCHEMA)
        managed=self.root/'managed'
        candidate.prepare(managed,self.old,recovery.digest(self.old),self.new,self.sig,self.pub,self.keysha)
        self.assertEqual(candidate.pointer(managed),candidate.OLD)
        self.assertEqual(candidate.command(['php',managed/'current/bin/workspace','version']).decode().strip(),candidate.OLD)
        backup=self.root/'backup'
        env=dict(os.environ,WORKSPACE_RECOVERY_OFFLINE='1',WORKSPACE_DB='sqlite')
        report=json.loads(candidate.command(['python3',managed/'current/tools/recovery.py','backup','--backend','sqlite','--data',data,'--objects',objects,'--destination',backup],env))
        return managed,data,objects,db,backup,report['manifest_sha256']

    def test_switch_and_rollback(self):
        managed,data,objects,db,backup,sha=self.fixture()
        def migrate(folder,version,env,upgrade=False):
            if upgrade and version==candidate.NEW:
                with closing(sqlite3.connect(db)) as c,c:
                    if c.execute('PRAGMA user_version').fetchone()[0]==candidate.OLD_SCHEMA:
                        sql=ROOT/'database/037.sql';c.executescript(sql.read_text());c.execute('INSERT INTO schema_migrations(version,sha256,origin) VALUES(?,?,?)',(37,recovery.digest(sql),'applied'));c.execute('PRAGMA user_version=37')
        with patch.dict(os.environ,{'WORKSPACE_RECOVERY_OFFLINE':'1','WORKSPACE_DB':'sqlite'}),patch.object(candidate,'smoke',side_effect=migrate):
            result=candidate.switch(managed,candidate.NEW,backup,sha,data,objects)
            self.assertTrue(result['snapshot_match']); self.assertEqual(candidate.pointer(managed),candidate.NEW)
            # A separately reviewed restore is required before old code can be selected.
            with closing(sqlite3.connect(backup/'workspace.sqlite')) as src, closing(sqlite3.connect(db)) as dst: src.backup(dst)
            candidate.switch(managed,candidate.OLD,backup,sha,data,objects)
            self.assertEqual(candidate.pointer(managed),candidate.OLD)
            candidate.switch(managed,candidate.NEW,backup,sha,data,objects)
            with closing(sqlite3.connect(db)) as c, c: c.execute("UPDATE projects SET title='New committed work' WHERE id=1")
            with self.assertRaises(ValueError): candidate.switch(managed,candidate.NEW,backup,sha,data,objects)
            with self.assertRaises(ValueError): candidate.switch(managed,candidate.OLD,backup,sha,data,objects)
            self.assertEqual(candidate.pointer(managed),candidate.NEW)
            with closing(sqlite3.connect(db)) as c: self.assertEqual(c.execute('SELECT title FROM projects WHERE id=1').fetchone()[0],'New committed work')

    def test_reject_modified_release_and_smoke_failure(self):
        managed,data,objects,db,backup,sha=self.fixture()
        with patch.dict(os.environ,{'WORKSPACE_RECOVERY_OFFLINE':'1','WORKSPACE_DB':'sqlite'}):
            with patch.object(candidate,'smoke',side_effect=ValueError('failed smoke')):
                with self.assertRaises(ValueError): candidate.switch(managed,candidate.NEW,backup,sha,data,objects)
            self.assertEqual(candidate.pointer(managed),candidate.OLD)
            p=managed/'releases'/candidate.NEW/'README.md'; p.chmod(0o600); p.write_bytes(p.read_bytes()+b'changed')
            with self.assertRaises(ValueError): candidate.switch(managed,candidate.NEW,backup,sha,data,objects)
            self.assertEqual(candidate.pointer(managed),candidate.OLD)

    def test_native_previous_upgrade_rollback(self):
        if not self.native: self.skipTest('Native PDO/cURL runtime unavailable')
        managed,data,objects,db,backup,sha=self.fixture()
        with patch.dict(os.environ,{'WORKSPACE_RECOVERY_OFFLINE':'1','WORKSPACE_DB':'sqlite'}):
            candidate.switch(managed,candidate.NEW,backup,sha,data,objects)
            with closing(sqlite3.connect(backup/'workspace.sqlite')) as src, closing(sqlite3.connect(db)) as dst: src.backup(dst)
            candidate.switch(managed,candidate.OLD,backup,sha,data,objects)
            self.assertEqual(candidate.pointer(managed),candidate.OLD)

if __name__=='__main__': unittest.main(verbosity=2)
