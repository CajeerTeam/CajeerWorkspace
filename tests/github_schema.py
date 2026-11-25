"""Migration 010 -> 011: preserve data/PDF and enforce binding/queue boundaries."""
import hashlib
import sqlite3
import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

class GitHubSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:'); self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,11): self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("""
        INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'owner@example.test','Owner','owner','hash');
        INSERT INTO organization_members VALUES(1,1,'owner');
        INSERT INTO organizations(id,name) VALUES(2,'Other');
        INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'First','oss',1),(2,'Other','oss',2);
        INSERT INTO tasks(id,project_id,title) VALUES(1,1,'Issue'),(2,2,'Foreign');
        INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'Contract','contract');
        INSERT INTO cash_entries(project_id,direction,amount_minor,currency,booked_on,memo,actor_id) VALUES(1,'income',98765,'PLN','2026-01-01','Old',1);
        """)
        for i in (1,2):
            pdf=b'%PDF-1.4\n\x00\xff'+bytes([i])+b'\n%%EOF'
            self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(1,?,?,?,1)',(i,hashlib.sha256(pdf).hexdigest(),pdf))
        self.db.commit(); self.sql=(ROOT/'database/011.sql').read_text()
    def tearDown(self):self.db.close()
    def apply(self):self.db.executescript(self.sql)
    def repos(self):
        self.apply();self.db.executescript("""INSERT INTO github_repositories(id,project_id,repository_id,installation_id,full_name) VALUES(1,1,40,30,'owner/first'),(2,2,41,30,'owner/other'); INSERT INTO github_task_links(task_id,project_id,repository_binding,kind,number) VALUES(1,1,1,'issue',7);""")
    def test_preservation(self):
        tables=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")];before={t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables}
        self.apply();self.assertEqual(before,{t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables});self.assertEqual(self.db.execute('SELECT count(*) FROM github_repositories').fetchone()[0],0);self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[]);self.assertEqual(self.db.execute('PRAGMA integrity_check').fetchone()[0],'ok')
    def test_binding_constraints(self):
        self.repos()
        for stmt in ["UPDATE github_repositories SET repository_id=41 WHERE id=1","UPDATE github_task_links SET repository_binding=2 WHERE task_id=1","INSERT INTO github_task_links(task_id,project_id,repository_binding,kind,number) VALUES(2,1,1,'pull',8)","UPDATE github_task_links SET kind='other' WHERE task_id=1","UPDATE github_task_links SET number=0 WHERE task_id=1"]:
            with self.subTest(stmt=stmt),self.assertRaises(sqlite3.IntegrityError):self.db.execute(stmt)
    def test_job_and_delivery_constraints(self):
        self.repos();self.db.execute("INSERT INTO github_jobs(repository_binding,generation,task_id,link_version,available_at,created_at) VALUES(1,1,1,1,1,1)")
        for stmt in ["UPDATE github_jobs SET state='running'","UPDATE github_jobs SET state='other'","UPDATE github_jobs SET lease_until=1","UPDATE github_jobs SET link_version=NULL","UPDATE github_jobs SET attempts=-1"]:
            with self.subTest(stmt=stmt),self.assertRaises(sqlite3.IntegrityError):self.db.execute(stmt)
        self.db.execute("INSERT INTO github_deliveries VALUES('id','hash','issues',30,1)")
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("INSERT INTO github_deliveries VALUES('id','different','issues',30,1)")
    def test_transaction_rollback(self):
        self.db.executescript('BEGIN;\n'+self.sql);self.db.rollback();self.assertIsNone(self.db.execute("SELECT name FROM sqlite_master WHERE name='github_jobs'").fetchone());self.assertEqual(self.db.execute('SELECT count(*) FROM document_versions').fetchone()[0],2)

if __name__=='__main__':unittest.main()
