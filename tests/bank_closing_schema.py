"""Schema 48 preserves historic bank observations and isolates new evidence."""
import sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class BankClosingSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,48):self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("""INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'a@example.test','Owner','owner','hash'); INSERT INTO organization_members VALUES(1,1,'owner'); INSERT INTO organizations(id,name) VALUES(2,'Other'); INSERT INTO legal_entities(id,organization_id,name,registration,address) VALUES(1,1,'A','A','Address'),(2,2,'B','B','Address'); INSERT INTO bank_accounts(id,organization_id,entity_id,name,account_ref,currency) VALUES(1,1,1,'Bank','A','EUR'),(2,2,2,'Bank','B','EUR'); INSERT INTO bank_statements(id,organization_id,account_id,starts_on,ends_on,opening_minor,closing_minor,digest,actor_id,created_at) VALUES(1,1,1,'2026-01-01','2026-01-01',0,100,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1,1),(2,2,2,'2026-01-01','2026-01-01',0,100,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',1,1); INSERT INTO bank_rows(id,account_id,statement_id,external_ref,booked_on,net_minor,fee_minor,description) VALUES(1,1,1,'A','2026-01-01',100,0,'Payment'),(2,2,2,'B','2026-01-01',100,0,'Other');""")
    def tearDown(self):self.db.close()
    def migrate(self):self.db.executescript((ROOT/'database/048.sql').read_text())
    def test_preservation(self):
        tables=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")];before={t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables};self.migrate();self.assertEqual(before,{t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables});self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[]);self.assertEqual(self.db.execute('PRAGMA integrity_check').fetchone()[0],'ok')
    def test_scope_and_versions(self):
        self.migrate();self.db.execute("INSERT INTO bank_formats VALUES(1,1,'Local',1,'{}',1,1),(2,2,'Local',1,'{}',1,1),(3,1,'Local',2,'{}',1,1)")
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("INSERT INTO bank_formats VALUES(4,1,'Local',1,'{}',1,1)")
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("INSERT INTO bank_import_sources VALUES(1,1,2,'{}',?)",('a'*64,))
        self.db.execute("INSERT INTO bank_import_sources VALUES(1,1,1,'{}',?)",('a'*64,))
        q="INSERT INTO bank_discrepancy_reviews(organization_id,statement_id,row_id,row_version,evidence_digest,decision,note,actor_id,created_at) VALUES(?,?,?,?,?,'approved','Evidence',1,1)"
        for v in [(1,2,2,1,'a'*64),(1,1,2,1,'a'*64),(1,1,1,0,'a'*64),(1,1,1,1,'bad')]:
            with self.assertRaises(sqlite3.IntegrityError):self.db.execute(q,v)
        self.db.execute(q,(1,1,1,1,'a'*64));self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
if __name__=='__main__':unittest.main()
