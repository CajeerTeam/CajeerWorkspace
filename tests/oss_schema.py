"""SQLite 009 -> 010: preservation and project-scoped foreign keys."""
import hashlib
import sqlite3
import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

class OssSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:'); self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,10): self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("""
        INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'owner@example.test','Owner','owner','hash');
        INSERT INTO organization_members VALUES(1,1,'owner');
        INSERT INTO organizations(id,name) VALUES(2,'Foreign');
        INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'OSS','oss',1),(2,'Foreign','oss',2),(3,'Client','client',1);
        INSERT INTO tasks(id,project_id,title) VALUES(1,1,'Task'),(2,2,'Foreign');
        INSERT INTO milestones(id,project_id,title) VALUES(1,1,'Milestone'),(2,2,'Other');
        INSERT INTO documents(id,project_id,title,kind) VALUES(1,3,'Contract','contract');
        INSERT INTO cash_entries(project_id,direction,amount_minor,currency,booked_on,memo,actor_id) VALUES(3,'income',12345,'EUR','2026-01-01','Old',1);
        """)
        for i in (1,2):
            pdf=b'%PDF-1.4\n\x00\xff'+bytes([i])+b'\n%%EOF'
            self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(1,?,?,?,1)',(i,hashlib.sha256(pdf).hexdigest(),pdf))
        self.db.commit()
        self.sql=(ROOT/'database/010.sql').read_text()
    def tearDown(self): self.db.close()
    def apply(self): self.db.executescript(self.sql)
    def profiles(self):
        self.apply(); self.db.executescript("""
        INSERT INTO oss_projects(project_id,slug,public_title,description,upstream) VALUES(1,'first','First','Public','a/a'),(2,'second','Second','Public','b/b');
        INSERT INTO oss_milestones(project_id,milestone_id,public_title,description,state) VALUES(1,1,'Public','Scope','closed'),(2,2,'Other','Scope','closed');
        INSERT INTO oss_contributors(id,project_id,display_name,token_hash,expires_at,created_by) VALUES(1,1,'Alice','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',100,1),(2,2,'Bob','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',100,1);
        INSERT INTO oss_task_links VALUES(1,1,'https://github.com/a/a/pull/1'),(2,2,'https://github.com/b/b/pull/1');
        INSERT INTO oss_contributions(id,project_id,contributor_id,title,body,upstream_url,state,task_id,public_note,created_at) VALUES(1,1,1,'One','Private','https://github.com/a/a/pull/1','accepted',1,'Note',1),(2,2,2,'Two','Private','https://github.com/b/b/pull/1','accepted',2,'Note',1);
        INSERT INTO oss_releases(id,project_id,milestone_id,tag,title,notes,published_at,published_by) VALUES(1,1,1,'v1','Release','Notes',1,1),(2,2,2,'v1','Release','Notes',1,1);
        """)
    def test_preservation_and_default_private(self):
        tables=[x[0] for x in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")]
        old={t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables}
        self.apply()
        self.assertEqual(old,{t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables})
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM oss_projects').fetchone()[0],0)
        self.assertEqual(self.db.execute('PRAGMA integrity_check').fetchone()[0],'ok')
        self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
    def test_cross_project_keys(self):
        self.profiles()
        for sql in ["INSERT INTO oss_milestones(project_id,milestone_id,public_title,description,state) VALUES(1,2,'x','x','open')", "UPDATE oss_task_links SET project_id=2 WHERE task_id=1", "UPDATE oss_contributions SET contributor_id=2 WHERE id=1", "UPDATE oss_contributions SET task_id=2 WHERE id=1", "INSERT INTO oss_comments(project_id,contribution_id,author_kind,body,created_at) VALUES(1,2,'maintainer','x',1)", "UPDATE oss_releases SET milestone_id=2 WHERE id=1", "INSERT INTO oss_release_items VALUES(1,1,2,'x','https://github.com/b/b/pull/1')", "INSERT INTO oss_release_items VALUES(2,1,1,'x','https://github.com/a/a/pull/1')"]:
            with self.subTest(sql=sql),self.assertRaises(sqlite3.IntegrityError): self.db.execute(sql)
    def test_unique_and_state_constraints(self):
        self.profiles(); self.db.execute("INSERT INTO oss_release_items VALUES(1,1,1,'Note','https://github.com/a/a/pull/1')")
        for sql in ["UPDATE oss_projects SET slug='second' WHERE project_id=1", "UPDATE oss_contributors SET accepted_at=5 WHERE id=1", "UPDATE oss_contributions SET task_id=NULL WHERE id=1", "UPDATE oss_contributions SET public_note='' WHERE id=1", "UPDATE oss_contributions SET state='invalid' WHERE id=1", "INSERT INTO oss_contributions(project_id,contributor_id,title,body,upstream_url,created_at) VALUES(1,1,'x','x','https://github.com/a/a/pull/1',1)", "INSERT INTO oss_release_items VALUES(1,1,1,'x','https://github.com/a/a/pull/1')"]:
            with self.subTest(sql=sql),self.assertRaises(sqlite3.IntegrityError): self.db.execute(sql)
    def test_transaction_rollback(self):
        self.db.executescript('BEGIN;\n'+self.sql); self.db.rollback()
        self.assertIsNone(self.db.execute("SELECT name FROM sqlite_master WHERE name='oss_projects'").fetchone())
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM document_versions').fetchone()[0],2)

if __name__=='__main__': unittest.main()
