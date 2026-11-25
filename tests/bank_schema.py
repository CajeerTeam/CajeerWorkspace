"""Schema 17 preservation and bank uniqueness / tenant FK checks."""
import hashlib, sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class BankSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,17):self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("""INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'a@example.test','Owner','owner','hash'); INSERT INTO organization_members VALUES(1,1,'owner'); INSERT INTO organizations(id,name) VALUES(2,'Other'); INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','client',1),(2,'B','client',2); INSERT INTO legal_entities(id,organization_id,name,registration,address) VALUES(1,1,'Seller','R1','Address'),(2,2,'Foreign','R2','Address'); INSERT INTO cash_entries(id,project_id,direction,amount_minor,currency,booked_on,memo,actor_id) VALUES(1,1,'income',25000,'EUR','2026-01-01','Legacy',1),(2,2,'income',25000,'EUR','2026-01-01','Foreign',1); INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'Contract','contract');""")
        for rev in (1,2):
            pdf=b'%PDF-1.4\n\x00\xff'+str(rev).encode()+b'\n%%EOF';self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(1,?,?,?,1)',(rev,hashlib.sha256(pdf).hexdigest(),pdf))
        self.db.commit();self.sql=(ROOT/'database/017.sql').read_text()
    def tearDown(self):self.db.close()
    def init(self):
        self.db.executescript(self.sql)
        self.db.executescript("""INSERT INTO bank_accounts(id,organization_id,entity_id,name,account_ref,currency) VALUES(1,1,1,'Bank','A','EUR'),(2,2,2,'Bank','B','EUR'); INSERT INTO bank_statements(id,organization_id,account_id,starts_on,ends_on,opening_minor,closing_minor,digest,actor_id,created_at) VALUES(1,1,1,'2026-01-01','2026-01-01',0,25000,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1,1),(2,2,2,'2026-01-01','2026-01-01',0,25000,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',1,1); INSERT INTO bank_rows(id,account_id,statement_id,external_ref,booked_on,net_minor,fee_minor,description) VALUES(1,1,1,'TX','2026-01-01',25000,0,'Payment'),(2,2,2,'TX','2026-01-01',25000,0,'Foreign');""")
    def test_preserve(self):
        tables=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")];before={t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables};self.db.executescript(self.sql);self.assertEqual(before,{t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables});self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[]);self.assertEqual(self.db.execute('PRAGMA integrity_check').fetchone()[0],'ok')
    def test_account_and_duplicate(self):
        self.init()
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("INSERT INTO bank_accounts(organization_id,entity_id,name,account_ref,currency) VALUES(1,2,'Bad','C','EUR')")
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("INSERT INTO bank_rows(account_id,statement_id,external_ref,booked_on,net_minor,fee_minor,description) VALUES(1,1,'TX','2026-01-01',1,0,'Duplicate')")
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("INSERT INTO bank_rows(account_id,statement_id,external_ref,booked_on,net_minor,fee_minor,description) VALUES(2,1,'Cross','2026-01-01',1,0,'Foreign')")
    def test_allocations(self):
        self.init();sql="INSERT INTO bank_allocations(organization_id,statement_id,row_id,component,project_id,cash_id,equivalent_minor,variance_minor,actor_id,created_at) VALUES(?,?,?,?,?,?,?,?,1,1)"
        for bad in [(1,1,2,'principal',1,1,25000,0),(1,1,1,'principal',2,2,25000,0),(1,1,1,'principal',1,1,25000,1),(1,1,1,'fee',1,1,25000,0)]:
            with self.assertRaises(sqlite3.IntegrityError):self.db.execute(sql,bad)
        self.db.execute(sql,(1,1,1,'principal',1,1,25000,0))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute(sql,(1,1,1,'fee',1,1,-25000,0))
    def test_rollback(self):
        self.db.executescript('BEGIN;\n'+self.sql);self.db.rollback();self.assertIsNone(self.db.execute("SELECT name FROM sqlite_master WHERE name='bank_rows'").fetchone())
if __name__=='__main__':unittest.main()
