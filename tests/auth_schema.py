"""SQLite 006 acceptance, preservation and guest ownership constraints."""
import hashlib
import sqlite3
import unittest
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]

class AuthSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:')
        self.db.execute('PRAGMA foreign_keys=ON')
        for v in range(1,6): self.db.executescript((ROOT/f'database/{v:03}.sql').read_text())
        self.db.executescript("""
          INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'owner@example.test','Owner','owner','hash');
          INSERT INTO organization_members VALUES(1,1,'owner');
          INSERT INTO organizations(id,name) VALUES(2,'Other');
          INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'Client','client',1),(2,'Foreign','client',2);
          INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'Agreed','contract'),(2,2,'Private','contract');
          INSERT INTO cash_entries(project_id,direction,amount_minor,currency,booked_on,memo,actor_id) VALUES(1,'income',1234,'EUR','2026-01-01','Test',1);
        """)
        for doc in (1,2):
            for rev in (1,2):
                pdf=b'%PDF-1.4\n'+bytes([doc,rev,0,255])+b'\n%%EOF'
                self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(?,?,?,?,1)',(doc,rev,hashlib.sha256(pdf).hexdigest(),pdf))
        self.old={t:self.db.execute('SELECT * FROM '+t).fetchall() for t in ('users','organizations','projects','cash_entries','document_versions')}
        self.db.executescript((ROOT/'database/006.sql').read_text())
    def tearDown(self): self.db.close()
    def issue(self,org=1,project=1,doc=1,rev=1,token='a'*64):
        self.db.execute('INSERT INTO guest_invites(organization_id,project_id,document_id,revision,email,token_hash,created_by,expires_at) VALUES(?,?,?,?,?,?,1,1000)',(org,project,doc,rev,'client@example.test',token))
    def test_preservation(self):
        for table,rows in self.old.items(): self.assertEqual(rows,self.db.execute('SELECT * FROM '+table).fetchall())
        self.assertEqual(self.db.execute('PRAGMA integrity_check').fetchone(),('ok',))
        self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
    def test_cross_tenant(self):
        with self.assertRaises(sqlite3.IntegrityError): self.issue(org=2)
        with self.assertRaises(sqlite3.IntegrityError): self.issue(doc=2)
        with self.assertRaises(sqlite3.IntegrityError): self.issue(rev=3)
    def test_token_and_acceptance(self):
        self.issue()
        with self.assertRaises(sqlite3.IntegrityError): self.issue()
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute('UPDATE guest_invites SET accepted_at=1')
        self.db.execute("UPDATE guest_invites SET accepted_at=1,issuer='issuer',subject='sub',access_until=9999")
        self.assertEqual(self.db.execute('SELECT revision FROM guest_invites').fetchone(),(1,))
    def test_identity_session_constraints(self):
        self.db.execute("INSERT INTO oidc_identities VALUES(1,'issuer','sub')")
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("INSERT INTO oidc_identities VALUES(1,'issuer','other')")
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("INSERT INTO oidc_sessions(handle,issuer,subject,created_at,expires_at) VALUES(?,'issuer','sub',1,2)",('a'*64,))
        self.issue()
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("INSERT INTO oidc_sessions(handle,issuer,subject,user_id,guest_id,created_at,expires_at) VALUES(?,'issuer','sub',1,1,1,2)",('a'*64,))
    def test_logout_replay_key(self):
        self.db.execute("INSERT INTO oidc_logout_events VALUES(?,'issuer','sub',NULL,1)",('b'*64,))
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("INSERT INTO oidc_logout_events VALUES(?,'issuer','sub',NULL,1)",('b'*64,))
if __name__=='__main__': unittest.main(verbosity=2)
