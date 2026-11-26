"""Migration 035 preserves the 1.1.0 database and enforces session constraints."""
import sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

class IdentitySchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:'); self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,35): self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'owner@example.test','Owner','owner','hash'); INSERT INTO organization_members VALUES(1,1,'owner'); INSERT INTO organization_auth_policies VALUES(1,'required'); INSERT INTO oidc_identities VALUES(1,'issuer','subject');")
        self.before={name:self.db.execute('SELECT * FROM '+name).fetchall() for (name,) in self.db.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'").fetchall()}
        self.db.executescript((ROOT/'database/035.sql').read_text())
    def tearDown(self): self.db.close()
    def test_preserve(self):
        for name,rows in self.before.items(): self.assertEqual(rows,self.db.execute('SELECT * FROM '+name).fetchall())
        self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
    def test_session(self):
        self.db.execute("INSERT INTO staff_sessions VALUES(?,1,'local',1,10,10,20,NULL)",('a'*64,))
        for data in [('x',1,'local',1,10,10,20,None),('b'*64,999,'local',1,10,10,20,None),('c'*64,1,'guest',1,10,10,20,None),('d'*64,1,'local',1,10,9,20,None)]:
            with self.assertRaises(sqlite3.IntegrityError): self.db.execute('INSERT INTO staff_sessions VALUES(?,?,?,?,?,?,?,?)',data)
    def test_history(self):
        self.db.execute("INSERT INTO identity_history VALUES(?,1,'self','bind',10,'')",('e'*64,))
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("INSERT INTO identity_history VALUES(?,1,'other','bind',10,'')",('f'*64,))
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("INSERT INTO identity_history VALUES(?,1,'self','bind',10,'')",('e'*64,))

if __name__=='__main__': unittest.main(verbosity=2)
