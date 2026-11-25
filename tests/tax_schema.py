"""Migration 019, integer VAT invariants and tenant/profile integrity."""
import sqlite3, unittest, hashlib
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class TaxSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:'); self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,19):self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("""INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'a@example.test','Owner','owner','hash'),(2,'b@example.test','Accountant','accountant','hash'); INSERT INTO organization_members VALUES(1,1,'owner'),(1,2,'accountant'); INSERT INTO organizations(id,name) VALUES(2,'Other'); INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','client',1); INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'Contract','contract'); INSERT INTO legal_entities(id,organization_id,name,registration,address) VALUES(1,1,'Seller','REG','Address'); INSERT INTO counterparties(id,organization_id,name) VALUES(1,1,'Buyer'); INSERT INTO invoices(id,organization_id,project_id,entity_id,counterparty_id,currency,due_on,terms,created_by,created_at) VALUES(1,1,1,1,1,'GBP','2026-12-01','Transfer',1,1);""")
        for rev in (1,2):
            pdf=b'%PDF-1.4\n\x00\xff'+str(rev).encode()+b'\n%%EOF'; self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(1,?,?,?,1)',(rev,hashlib.sha256(pdf).hexdigest(),pdf))
        self.db.commit();self.sql=(ROOT/'database/019.sql').read_text()
    def tearDown(self):self.db.close()
    def test_preserve(self):
        tables=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")];before={t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables};self.db.executescript(self.sql);self.assertEqual(before,{t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables});self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[]);self.assertEqual(self.db.execute('PRAGMA integrity_check').fetchone()[0],'ok')
    def test_profiles(self):
        self.db.executescript(self.sql);q='INSERT INTO uk_vat_profiles(entity_id,organization_id,vat_number,proprietor,contact,service_address,effective_on,reviewed_until,entity_version,version,prepared_by,prepared_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)';r=(1,1,'123456789','Person','Contact','Address','2026-11-25','2026-12-25',1,1,1,1)
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute(q,(1,2,*r[2:]))
        self.db.execute(q,r)
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("UPDATE uk_vat_profiles SET approved_by=1,approved_at=2,review_reference='Check'")
        self.db.execute("UPDATE uk_vat_profiles SET approved_by=2,approved_at=2,review_reference='Check'")
    def test_tax_and_credit(self):
        self.db.executescript(self.sql);q='INSERT INTO uk_vat_documents(invoice_id,profile_version,rule_version,tax_point,net_minor,vat_minor,gross_minor,buyer_address,profile_snapshot) VALUES(?,?,?,?,?,?,?,?,?)';r=(1,1,'UK-STD-2026-11-25','2026-11-25',1234,246,1480,'Buyer address','{}')
        for bad in [(1,1,r[2],r[3],1234,247,1481,*r[7:]),(1,1,r[2],r[3],1234.5,246.9,1481.4,*r[7:]),(1,1,'unknown',*r[3:]),(2,*r[1:])]:
            with self.assertRaises(sqlite3.IntegrityError):self.db.execute(q,bad)
        self.db.execute(q,r)
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("UPDATE uk_vat_documents SET credit_on='2026-11-24',credit_reason='x',credit_by=1")
        self.db.execute("UPDATE uk_vat_documents SET credit_on='2026-11-25',credit_reason='Cancellation',credit_by=1")
if __name__=='__main__':unittest.main()
