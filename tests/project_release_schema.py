"""Migration 011 -> 012 and project boundaries."""
import sqlite3, unittest, hashlib
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class Releases(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,12):self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("""
        INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'a@example.test','Owner','owner','hash');
        INSERT INTO organization_members VALUES(1,1,'owner');
        INSERT INTO organizations(id,name) VALUES(2,'Other');
        INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','oss',1),(2,'B','oss',2);
        INSERT INTO tasks(id,project_id,title,state) VALUES(1,1,'PR','done'),(2,2,'Foreign','done');
        INSERT INTO github_repositories(id,project_id,repository_id,installation_id,full_name) VALUES(1,1,40,30,'owner/a'),(2,2,41,30,'owner/b');
        INSERT INTO github_task_links(task_id,project_id,repository_binding,kind,number) VALUES(1,1,1,'pull',7);
        INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'Contract','contract');
        """)
        pdf=b'%PDF-1.4\n\x00\xff\n%%EOF';self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(1,1,?,?,1)',(hashlib.sha256(pdf).hexdigest(),pdf));self.db.commit()
        self.sql=(ROOT/'database/012.sql').read_text()
    def tearDown(self):self.db.close()
    def insert(self):
        self.db.executescript(self.sql);self.db.execute('INSERT INTO project_releases(id,project_id,repository_binding,repository_generation,tag,commit_sha,changelog,artifact_name,artifact_sha256,required_checks,responsible_id,creator_id,request_key,created_at) VALUES(1,1,1,1,?,?,?,?,?,?,1,1,?,1)',('v1','a'*40,'notes','app.zip','b'*64,'["Build"]','key'));self.db.execute('INSERT INTO project_release_tasks VALUES(1,1,1,1,1,7)')
    def test_preservation(self):
        tables=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")];old={t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables};self.db.executescript(self.sql);self.assertEqual(old,{t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables});self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[]);self.assertEqual(self.db.execute('PRAGMA integrity_check').fetchone()[0],'ok')
    def test_boundaries(self):
        self.insert()
        for stmt in ['UPDATE project_releases SET repository_binding=2 WHERE id=1','INSERT INTO project_release_tasks VALUES(1,1,2,1,1,8)','INSERT INTO project_release_tasks VALUES(1,2,2,1,1,8)','UPDATE project_releases SET state=\'confirmed\' WHERE id=1','UPDATE project_releases SET state=\'verified\' WHERE id=1']:
            with self.subTest(stmt=stmt),self.assertRaises(sqlite3.IntegrityError):self.db.execute(stmt)
    def test_rollback(self):
        self.db.executescript('BEGIN;\n'+self.sql);self.db.rollback();self.assertIsNone(self.db.execute("SELECT name FROM sqlite_master WHERE name='project_releases'").fetchone())
if __name__=='__main__':unittest.main()
