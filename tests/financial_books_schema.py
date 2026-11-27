"""Additive migration, entity and request boundaries on actual SQLite schema."""
import sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class BooksSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:'); self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,47): self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("""INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'a@example.test','Owner','owner','hash'); INSERT INTO organization_members VALUES(1,1,'owner'); INSERT INTO organizations(id,name) VALUES(2,'Other'); INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','client',1),(2,'B','client',1),(3,'Other','client',2); INSERT INTO legal_entities(id,organization_id,name,registration,address) VALUES(1,1,'A','A','Address'),(2,1,'B','B','Address'),(3,2,'C','C','Address'); INSERT INTO counterparties(id,organization_id,name) VALUES(1,1,'Supplier'),(2,2,'Foreign'); INSERT INTO accounting_accounts(id,organization_id,code,name,kind) VALUES(1,1,'5000','Expense','expense'),(2,1,'2000','Liability','liability'); INSERT INTO accounting_periods(id,organization_id,starts_on,ends_on) VALUES(1,1,'2026-01-01','2026-12-31'); INSERT INTO accounting_entries(id,organization_id,period_id,project_id,booked_on,currency,debit_id,credit_id,amount_minor,kind,memo,actor_id,created_at) VALUES(1,1,1,1,'2026-01-01','EUR',1,2,10000,'manual','Accrual',1,1),(2,1,1,2,'2026-01-01','EUR',1,2,10000,'manual','Accrual',1,1);""")
        self.db.commit()
    def tearDown(self): self.db.close()
    def migrate(self): self.db.executescript((ROOT/'database/047.sql').read_text())
    def test_preserves_existing_rows(self):
        tables=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")];before={t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables};self.migrate();self.assertEqual(before,{t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables});self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[]);self.assertEqual(self.db.execute('PRAGMA integrity_check').fetchone()[0],'ok')
    def test_boundaries(self):
        self.migrate();self.db.execute('INSERT INTO finance_book_projects VALUES(1,1,1,1,1)');self.db.execute('INSERT INTO finance_book_projects VALUES(2,1,2,1,1)')
        for args in [(3,2,1,1,1),(3,1,1,1,1),(1,1,2,1,1)]:
            with self.assertRaises(sqlite3.IntegrityError):self.db.execute('INSERT INTO finance_book_projects VALUES(?,?,?,?,?)',args)
        self.db.execute("INSERT INTO finance_book_periods(organization_id,entity_id,starts_on,ends_on) VALUES(1,1,'2026-01-01','2026-01-31'),(1,2,'2026-01-01','2026-01-31')")
        self.db.execute('UPDATE finance_book_periods SET closed_by=1,closed_at=1 WHERE entity_id=1');self.assertIsNone(self.db.execute('SELECT closed_at FROM finance_book_periods WHERE entity_id=2').fetchone()[0])
        sql="INSERT INTO supplier_bills(organization_id,project_id,entity_id,supplier_id,reference,booked_on,due_on,currency,amount_minor,entry_id,actor_id,created_at) VALUES(1,?,?,?,'ref','2026-01-01','2026-01-31','EUR',?,?,1,1)"
        for args in [(1,2,1,100,1),(1,1,2,100,1),(1,1,1,0,1),(1,1,1,1.5,1)]:
            with self.assertRaises(sqlite3.IntegrityError):self.db.execute(sql,args)
        self.db.execute(sql,(1,1,1,10000,1));self.db.execute(sql,(2,2,1,10000,2))
        self.db.execute("INSERT INTO payment_requests(id,project_id,direction,amount_minor,currency,booked_on,memo,created_by,dual_control,status) VALUES(1,1,'expense',100,'EUR','2026-01-02','Memo',1,1,'pending'),(2,2,'expense',100,'EUR','2026-01-02','Memo',1,1,'pending')")
        self.db.execute("INSERT INTO supplier_payments VALUES(1,1,1,'ref','payment',NULL)")
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("INSERT INTO supplier_payments VALUES(2,1,2,'ref2','payment',NULL)")
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("INSERT INTO supplier_payments VALUES(2,2,2,'ref2','refund',1)")
        self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
if __name__=='__main__':unittest.main()
