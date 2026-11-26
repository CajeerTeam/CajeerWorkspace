"""Migration 036 adds only empty evidence storage; previous data stays intact."""
import sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class EvidenceSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:')
        self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,36): self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.execute("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'owner@example.test','Owner','owner','hash')")
        self.before={n:self.db.execute('SELECT * FROM '+n).fetchall() for (n,) in self.db.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")}
        self.db.executescript((ROOT/'database/036.sql').read_text())
    def tearDown(self): self.db.close()
    def test_preserve(self):
        for n,rows in self.before.items(): self.assertEqual(rows,self.db.execute('SELECT * FROM '+n).fetchall())
        self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
    def test_constraints(self):
        sql='INSERT INTO auth_scenario_evidence VALUES(?,?,?,?,?,?,?)'; good=['staff_login','a'*64,'b'*64,1,1,'required',100]
        self.db.execute(sql,good)
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute(sql,good)
        for i,v in [(0,'manual-pass'),(1,'short'),(2,'short'),(3,0),(4,0),(5,'local'),(6,0)]:
            row=good.copy(); row[1]='c'*64; row[i]=v
            with self.assertRaises(sqlite3.IntegrityError): self.db.execute(sql,row)
if __name__=='__main__': unittest.main(verbosity=2)
