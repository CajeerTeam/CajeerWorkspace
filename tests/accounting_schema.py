"""Preservation of schema 15 and balanced, tenant-bound rows in schema 16."""
import hashlib, sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class AccountingSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,16):self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("""INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'a@example.test','Owner','owner','hash'),(2,'b@example.test','Accountant','accountant','hash'); INSERT INTO organization_members VALUES(1,1,'owner'),(1,2,'accountant'); INSERT INTO organizations(id,name) VALUES(2,'Other'); INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','client',1),(2,'B','client',2); INSERT INTO counterparties(id,organization_id,name) VALUES(1,1,'Buyer'); INSERT INTO legal_entities(id,organization_id,name,registration,address) VALUES(1,1,'Seller','R1','Address'); INSERT INTO invoices(id,organization_id,project_id,entity_id,counterparty_id,currency,due_on,terms,created_by,created_at) VALUES(1,1,1,1,1,'EUR','2026-12-01','Terms',1,1); INSERT INTO cash_entries(id,project_id,direction,amount_minor,currency,booked_on,memo,actor_id) VALUES(1,1,'income',25000,'EUR','2026-01-01','Legacy',1); INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'Contract','contract');""")
        for rev in (1,2):
            pdf=b'%PDF-1.4\n\x00\xff'+str(rev).encode()+b'\n%%EOF';self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(1,?,?,?,1)',(rev,hashlib.sha256(pdf).hexdigest(),pdf))
        self.db.commit();self.sql=(ROOT/'database/016.sql').read_text()
    def tearDown(self):self.db.close()
    def init(self):
        self.db.executescript(self.sql)
        self.db.executescript("""INSERT INTO accounting_accounts(id,organization_id,code,name,kind) VALUES(1,1,'1000','Cash','asset'),(2,1,'4000','Income','income'),(3,2,'1000','Foreign','asset'); INSERT INTO accounting_periods(id,organization_id,starts_on,ends_on) VALUES(1,1,'2026-01-01','2026-12-31'),(2,2,'2026-01-01','2026-12-31');""")
    def test_preservation(self):
        tables=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")];before={t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables};self.db.executescript(self.sql);self.assertEqual(before,{t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables});self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[]);self.assertEqual(self.db.execute('PRAGMA integrity_check').fetchone()[0],'ok')
    def test_pairs_scope_amounts(self):
        self.init()
        def entry(period=1,project=1,debit=1,credit=2,amount=25000,currency='EUR'):
            self.db.execute("INSERT INTO accounting_entries(organization_id,period_id,project_id,booked_on,currency,debit_id,credit_id,amount_minor,kind,memo,actor_id,created_at) VALUES(1,?,?,'2026-01-01',?,?,?,?, 'manual','Memo',1,1)",(period,project,currency,debit,credit,amount))
        entry();
        for args in [(2,1,1,2,1),(1,2,1,2,1),(1,1,3,2,1),(1,1,1,3,1),(1,1,1,1,1),(1,1,1,2,0),(1,1,1,2,-1),(1,1,1,2,1.5),(1,1,1,2,1,'XYZ')]:
            with self.assertRaises(sqlite3.IntegrityError):entry(*args)
        self.assertEqual(self.db.execute('SELECT SUM(amount_minor) FROM accounting_entries').fetchone()[0],25000)
    def test_cash_and_reversal_identity(self):
        self.init();self.db.execute("INSERT INTO accounting_entries(id,organization_id,period_id,project_id,booked_on,currency,debit_id,credit_id,amount_minor,kind,memo,cash_id,actor_id,created_at) VALUES(1,1,1,1,'2026-01-01','EUR',1,2,25000,'cash','Cash',1,1,1)")
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("INSERT INTO accounting_entries(organization_id,period_id,project_id,booked_on,currency,debit_id,credit_id,amount_minor,kind,memo,cash_id,actor_id,created_at) VALUES(1,1,1,'2026-01-01','EUR',1,2,25000,'cash','Duplicate',1,1,1)")
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("INSERT INTO accounting_entries(organization_id,period_id,project_id,booked_on,currency,debit_id,credit_id,amount_minor,kind,memo,actor_id,created_at) VALUES(1,1,1,'2026-01-01','EUR',1,2,25000,'reversal','No original',1,1)")
    def test_independent_approval(self):
        self.init();self.db.execute("INSERT INTO accounting_openings(organization_id,period_id,prepared_by,prepared_at,cash_digest,cash_count,snapshot,pairs,expected,evidence) VALUES(1,1,1,1,?,1,'{}','[]','{}','Statement')",('a'*64,))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("UPDATE accounting_openings SET approved_by=1,approved_at=2,review_note='Self'")
        self.db.execute("UPDATE accounting_openings SET approved_by=2,approved_at=2,review_note='Reviewed'")
    def test_rollback(self):
        self.db.executescript('BEGIN;\n'+self.sql);self.db.rollback();self.assertIsNone(self.db.execute("SELECT name FROM sqlite_master WHERE name='accounting_entries'").fetchone())
if __name__=='__main__':unittest.main()
