"""SQLite 008 migration: preservation, composite FKs, time/rate constraints and rollback."""
import hashlib
import sqlite3
import unittest
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]

class WorkloadSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:')
        self.db.execute('PRAGMA foreign_keys=ON')
        for n in range(1,8): self.db.executescript((ROOT/f'database/{n:03}.sql').read_text())
        self.db.executescript("""
        INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'o@example.test','Owner','owner','hash'),(2,'d@example.test','Dev','developer','hash');
        INSERT INTO organization_members VALUES(1,1,'owner'),(1,2,'developer');
        INSERT INTO organizations(id,name) VALUES(2,'Other');
        INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'One','client',1),(2,'Other','internal',2);
        INSERT INTO tasks(id,project_id,title,assignee_id,result_customer,acceptance_criteria) VALUES(1,1,'Existing',2,'Customer','Criteria'),(2,2,'Foreign',NULL,'Customer','Criteria');
        INSERT INTO milestones(id,project_id,title) VALUES(1,1,'Release');
        INSERT INTO task_milestones VALUES(1,1,1);
        INSERT INTO cash_entries(project_id,direction,amount_minor,currency,booked_on,memo,actor_id) VALUES(1,'income',12345,'PLN','2026-01-01','Payment',1);
        INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'Contract','contract');
        """)
        for rev in (1,2):
            b=b'%PDF-1.4\n\x00\xff'+str(rev).encode()+b'\n%%EOF'
            self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(1,?,?,?,1)',(rev,hashlib.sha256(b).hexdigest(),b))
        self.db.commit()
        self.old={t:self.db.execute('SELECT * FROM '+t).fetchall() for t in ['users','projects','tasks','milestones','task_milestones','cash_entries','documents','document_versions','organization_members']}
        self.sql=(ROOT/'database/008.sql').read_text()
    def tearDown(self): self.db.close()
    def upgrade(self): self.db.executescript(self.sql)
    def test_preservation(self):
        self.upgrade()
        for t,rows in self.old.items(): self.assertEqual(rows,self.db.execute('SELECT * FROM '+t).fetchall())
        self.assertEqual([],self.db.execute('PRAGMA foreign_key_check').fetchall())
        self.assertEqual([('ok',)],self.db.execute('PRAGMA integrity_check').fetchall())
    def test_project_links_and_unique_entries(self):
        self.upgrade()
        self.db.executescript("""INSERT INTO sprints(id,project_id,title,starts_on,ends_on) VALUES(1,1,'Sprint','2024-12-30','2025-01-05'),(2,2,'Foreign','2024-12-30','2025-01-05');
        INSERT INTO timesheets(id,project_id,user_id,week_on) VALUES(1,1,2,'2024-12-30');
        INSERT INTO sprint_tasks(sprint_id,project_id,task_id,estimate_minutes) VALUES(1,1,1,60);
        INSERT INTO time_entries(project_id,sheet_id,task_id,worked_on,minutes,note) VALUES(1,1,1,'2024-12-30',60,'');""")
        for sql in ["INSERT INTO sprint_tasks(sprint_id,project_id,task_id,estimate_minutes) VALUES(1,1,2,60)","INSERT INTO sprint_tasks(sprint_id,project_id,task_id,estimate_minutes) VALUES(2,1,1,60)","INSERT INTO time_entries(project_id,sheet_id,task_id,worked_on,minutes,note) VALUES(1,1,2,'2024-12-31',60,'')","INSERT INTO time_entries(project_id,sheet_id,task_id,worked_on,minutes,note) VALUES(2,1,2,'2024-12-31',60,'')","INSERT INTO time_entries(project_id,sheet_id,task_id,worked_on,minutes,note) VALUES(1,1,1,'2024-12-30',60,'')","INSERT INTO timesheets(project_id,user_id,week_on) VALUES(1,2,'2024-12-30')"]:
            with self.assertRaises(sqlite3.IntegrityError,msg=sql): self.db.execute(sql)
    def test_rate_snapshot_and_decision_constraints(self):
        self.upgrade()
        self.db.execute("INSERT INTO timesheets(id,project_id,user_id,week_on) VALUES(1,1,2,'2024-12-30')")
        for sql in ["INSERT INTO project_rates VALUES(1,'hourly','EUR',0,1)","INSERT INTO project_rates VALUES(1,'fixed','JPY',100,1)","INSERT INTO project_availability VALUES(1,2,'2024-12-30',10081,1)","UPDATE timesheets SET status='submitted' WHERE id=1","UPDATE timesheets SET status='submitted',submitted_at=100,total_minutes=60,rate_mode='hourly',rate_currency='EUR',rate_minor=100,rate_version=1 WHERE id=1","UPDATE timesheets SET status='submitted',submitted_at=100,total_minutes=60,cost_minor=100 WHERE id=1"]:
            with self.assertRaises(sqlite3.IntegrityError,msg=sql): self.db.execute(sql)
        self.db.execute("UPDATE timesheets SET status='submitted',submitted_at=100,total_minutes=60,rate_mode='hourly',rate_currency='EUR',rate_minor=100,rate_version=1,cost_minor=100 WHERE id=1")
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("UPDATE timesheets SET status='approved',decided_at=101,decided_by=2 WHERE id=1")
        self.db.execute("UPDATE timesheets SET status='approved',decided_at=101,decided_by=1 WHERE id=1")
        self.assertEqual(('approved',100),self.db.execute('SELECT status,cost_minor FROM timesheets WHERE id=1').fetchone())
    def test_rollback(self):
        self.db.executescript('BEGIN;'+self.sql+'ROLLBACK;')
        self.assertIsNone(self.db.execute("SELECT name FROM sqlite_master WHERE name='timesheets'").fetchone())
        self.assertEqual(self.old['document_versions'],self.db.execute('SELECT * FROM document_versions').fetchall())

if __name__=='__main__': unittest.main(verbosity=2)
