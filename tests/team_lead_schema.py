"""Additive lead workspace migration and integrity constraints on actual SQLite."""
import sqlite3
import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class LeadSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.addCleanup(self.db.close);self.db.execute('PRAGMA foreign_keys=ON')
        for p in sorted((ROOT/'database').glob('*.sql')):
            if int(p.stem)<40:self.db.executescript(p.read_text())
        self.db.execute("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'owner@example.test','Owner','owner','fixture')")
        self.db.execute("INSERT INTO organization_members VALUES(1,1,'owner')")
        self.db.execute("INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'First','internal',1),(2,'Second','internal',1)")
        self.db.execute("INSERT INTO tasks(id,project_id,title,state) VALUES(1,1,'Task','todo'),(2,1,'Prerequisite','todo'),(3,2,'Other','todo')")
        self.db.execute("INSERT INTO github_repositories(id,project_id,repository_id,installation_id,full_name) VALUES(1,1,40,30,'owner/one'),(2,2,41,30,'owner/two')")
        before=self.db.execute('SELECT * FROM tasks ORDER BY id').fetchall();self.db.executescript((ROOT/'database/040.sql').read_text());self.assertEqual(self.db.execute('SELECT * FROM tasks ORDER BY id').fetchall(),before)
    def test_wait_intervals(self):
        q='INSERT INTO lead_waits(project_id,task_id,prerequisite_id,started_at,observed_only) VALUES(1,1,?,100,0)';self.db.execute(q,(2,))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute(q,(2,))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute(q,(3,))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute('UPDATE lead_waits SET ended_at=99')
        self.db.execute("UPDATE lead_waits SET ended_at=110,end_reason='completed'");self.db.execute(q,(2,));self.assertEqual(self.db.execute('SELECT COUNT(*) FROM lead_waits').fetchone()[0],2)
    def test_review_and_decision_chain(self):
        q="INSERT INTO lead_review_events(project_id,task_id,repository_binding,generation,link_version,pr_number,head_sha,reviewer_id,actor_id,previous_id,operation,state,note,created_at) VALUES(1,1,?,1,1,7,?,1,1,?,'assign','pending','Note',1)"
        self.db.execute(q,(1,'a'*40,None));self.db.execute(q,(1,'a'*40,1))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute(q,(1,'a'*40,1))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute(q,(2,'a'*40,None))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("INSERT INTO lead_decisions(project_id,task_id,actor_id,title,context,resolution,state,note,created_at) VALUES(1,3,1,'ADR','Context','Resolution','open','',1)")
        self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
        self.assertEqual(self.db.execute('PRAGMA integrity_check').fetchone()[0],'ok')
if __name__=='__main__':unittest.main()
