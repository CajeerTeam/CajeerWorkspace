"""Exercise migration 005 against actual SQLite constraints, independently of PHP."""
from pathlib import Path
import sqlite3
import unittest
import hashlib
ROOT=Path(__file__).resolve().parents[1]

class PermissionsSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:'); self.db.execute('PRAGMA foreign_keys=ON')
        for v in range(1,4): self.db.executescript((ROOT/f'database/{v:03}.sql').read_text())
        self.db.executescript("""
        INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'owner@test','Owner','owner','hash'),(2,'a@test','A','accountant','hash'),(3,'b@test','B','viewer','hash');
        INSERT INTO projects(id,title,kind) VALUES(1,'Existing','internal');
        INSERT INTO cash_entries(id,project_id,direction,amount_minor,currency,booked_on,memo,actor_id) VALUES(1,1,'income',100,'EUR','2026-01-01','Original',1);
        INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'Contract','contract');
        INSERT INTO action_log(actor_id,request_key,fingerprint,action,object_id) VALUES(1,'old','hash','cash.post',1);
        """)
        pdf=b'%PDF-1.4\n\x00\xff\n%%EOF'; self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(1,1,?,?,1)',(hashlib.sha256(pdf).hexdigest(),pdf))
        self.db.executescript((ROOT/'database/004.sql').read_text())
        self.original={t:self.db.execute('SELECT * FROM '+t).fetchall() for t in ('projects','users','cash_entries','document_versions','project_members','action_log')}
        self.db.executescript((ROOT/'database/005.sql').read_text())
    def tearDown(self): self.db.close()
    def test_preservation(self):
        for t,rows in self.original.items(): self.assertEqual(rows,self.db.execute('SELECT * FROM '+t).fetchall())
        self.assertEqual([(1,1,'cash.post',1)], self.db.execute('SELECT organization_id,project_id,event,object_id FROM audit_events').fetchall())
        self.assertEqual([],self.db.execute('PRAGMA foreign_key_check').fetchall()); self.assertEqual([('ok',)],self.db.execute('PRAGMA integrity_check').fetchall())
    def test_role_constraints_and_removal(self):
        self.db.execute("INSERT INTO project_access VALUES(1,2,'payment_creator')")
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("INSERT INTO project_access VALUES(1,3,'owner')")
        self.db.execute('DELETE FROM project_members WHERE project_id=1 AND user_id=2')
        self.assertEqual([],self.db.execute('SELECT * FROM project_access').fetchall())
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("INSERT INTO project_access VALUES(1,2,'auditor')")
    def pending(self, reversal=None):
        self.db.execute("INSERT INTO payment_requests(project_id,direction,amount_minor,currency,booked_on,memo,reversal_of,created_by,dual_control,status) VALUES(1,'income',?,'EUR','2026-01-01','Test',?,2,1,'pending')",(-100 if reversal else 100,reversal))
    def test_separation(self):
        self.pending()
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("UPDATE payment_requests SET status='posted',decided_by=2,decided_at='now',cash_entry_id=1")
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("UPDATE payment_requests SET status='posted',decided_by=3,decided_at='now'")
        self.db.execute("UPDATE payment_requests SET status='posted',decided_by=3,decided_at='now',cash_entry_id=1")
        self.assertEqual(1,self.db.execute('SELECT COUNT(*) FROM payment_requests WHERE status=\'posted\'').fetchone()[0])
    def test_pending_reversal_unique(self):
        self.pending(1)
        with self.assertRaises(sqlite3.IntegrityError): self.pending(1)
        self.db.execute("UPDATE payment_requests SET status='cancelled',decided_by=1,decided_at='now'")
        self.pending(1)
    def test_audit_organization_fk(self):
        self.db.execute("INSERT INTO organizations(id,name) VALUES(2,'Second')")
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("INSERT INTO audit_events(organization_id,project_id,actor_id,event,object_id) VALUES(2,1,1,'fake',1)")

if __name__=='__main__': unittest.main(verbosity=2)
