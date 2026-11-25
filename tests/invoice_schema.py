"""SQLite upgrade preserves existing financial/doc records and enforces tenant bindings."""
import hashlib, sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class InvoiceSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,15):self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("""INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'a@example.test','Owner','owner','hash'); INSERT INTO organization_members VALUES(1,1,'owner'); INSERT INTO organizations(id,name) VALUES(2,'Other'); INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','client',1),(2,'B','client',2); INSERT INTO counterparties(id,organization_id,name) VALUES(1,1,'Buyer'),(2,2,'Other'); INSERT INTO cash_entries(id,project_id,direction,amount_minor,currency,booked_on,memo,actor_id) VALUES(1,1,'income',25000,'EUR','2026-01-01','Legacy',1); INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'Contract','contract');""")
        for rev in (1,2):
            pdf=b'%PDF-1.4\n\x00\xff'+str(rev).encode()+b'\n%%EOF';self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(1,?,?,?,1)',(rev,hashlib.sha256(pdf).hexdigest(),pdf))
        self.db.commit();self.sql=(ROOT/'database/015.sql').read_text()
    def tearDown(self):self.db.close()
    def setup_new(self):
        self.db.executescript(self.sql);self.db.executescript("INSERT INTO legal_entities(id,organization_id,name,registration,address) VALUES(1,1,'Seller','R1','Address'),(2,2,'Other','R2','Address');")
    def invoice(self,id=1,org=1,project=1,entity=1,client=1):
        self.db.execute("INSERT INTO invoices(id,organization_id,project_id,entity_id,counterparty_id,currency,due_on,terms,created_by,created_at) VALUES(?,?,?,?,?,'EUR','2026-12-01','Terms',1,1)",(id,org,project,entity,client))
    def issue(self,id=1,seq=1):
        self.db.execute("UPDATE invoices SET state='issued',number_year=2026,number_seq=?,issued_on='2026-11-01',issued_by=1,issuer_snapshot='{}',client_snapshot='{}',total_minor=100000 WHERE id=?",(seq,id))
    def test_preservation(self):
        tables=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")];before={t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables};self.db.executescript(self.sql);self.assertEqual(before,{t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables});self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[]);self.assertEqual(self.db.execute('PRAGMA integrity_check').fetchone()[0],'ok')
    def test_scope_and_numbering(self):
        self.setup_new();self.invoice();self.issue();self.invoice(2)
        for args in [(3,1,2,1,1),(3,1,1,2,1),(3,1,1,1,2)]:
            with self.assertRaises(sqlite3.IntegrityError):self.invoice(*args)
        with self.assertRaises(sqlite3.IntegrityError):self.issue(2)
        self.issue(2,2)
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("INSERT INTO invoice_lines(invoice_id,description,quantity,unit_minor) VALUES(1,'X',100000,99999999999)")
    def test_movements_scope_and_identity(self):
        self.setup_new();self.invoice();self.issue();self.invoice(2);self.issue(2,2)
        self.db.executescript("INSERT INTO payment_requests(id,project_id,direction,amount_minor,currency,booked_on,memo,created_by,dual_control,status) VALUES(1,1,'income',25000,'EUR','2026-11-01','Pay',1,1,'pending'),(2,2,'income',25000,'EUR','2026-11-01','Pay',1,1,'pending'),(3,1,'expense',1000,'EUR','2026-11-01','Refund',1,1,'pending'); INSERT INTO invoice_movements(id,invoice_id,project_id,entity_id,request_id,kind,reference) VALUES(1,1,1,1,1,'receipt','bank');")
        for stmt in ["INSERT INTO invoice_movements(invoice_id,project_id,entity_id,request_id,kind,reference) VALUES(1,1,1,2,'receipt','new')","INSERT INTO invoice_movements(invoice_id,project_id,entity_id,request_id,kind,reference) VALUES(2,1,1,1,'receipt','new')","INSERT INTO invoice_movements(invoice_id,project_id,entity_id,request_id,kind,reference,original_id) VALUES(2,1,1,3,'refund','refund',1)"]:
            with self.assertRaises(sqlite3.IntegrityError):self.db.execute(stmt)
    def test_rollback(self):
        self.db.executescript('BEGIN;\n'+self.sql);self.db.rollback();self.assertIsNone(self.db.execute("SELECT name FROM sqlite_master WHERE name='invoices'").fetchone())
if __name__=='__main__':unittest.main()
