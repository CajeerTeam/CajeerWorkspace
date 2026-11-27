import sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class JurisdictionSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,50):self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'owner@example.test','Owner','owner','hash'); INSERT INTO legal_entities(id,organization_id,name,registration,address) VALUES(1,1,'Seller','S','Address');")
        self.db.commit()
    def tearDown(self):self.db.close()
    def migrate(self):self.db.executescript((ROOT/'database/050.sql').read_text())
    def test_preserve(self):
        tables=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")];before={t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables};self.migrate();self.assertEqual(before,{t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables});self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
    def test_scope_and_uniqueness(self):
        self.migrate();sql='INSERT INTO uk_exchange_batches(organization_id,entity_id,from_on,to_on,format,payload_base64,payload_sha256,byte_count,created_by,created_at,request_key) VALUES(?,?,?,?,?,?,?,?,?,?,?)';args=(1,1,'2026-11-01','2026-11-27','json-v1','e30=','a'*64,2,1,1,'b'*32);self.db.execute(sql,args)
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute(sql,args)
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute(sql,(2,*args[1:-1],'c'*32))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute('INSERT INTO uk_exchange_receipts VALUES(1,1,?,?,?,?,?)',('accepted','check','f'*64,'c'*32,1))
        self.db.execute('INSERT INTO uk_exchange_receipts VALUES(1,1,?,?,?,?,?)',('accepted','check','a'*64,'c'*32,1))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute('INSERT INTO uk_exchange_receipts VALUES(1,1,?,?,?,?,?)',('rejected','check','a'*64,'d'*32,2))
    def test_document_rule(self):
        self.db.executescript("INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','client',1); INSERT INTO counterparties(id,organization_id,name) VALUES(1,1,'Buyer'); INSERT INTO invoices(id,organization_id,project_id,entity_id,counterparty_id,currency,due_on,terms,created_by,created_at) VALUES(1,1,1,1,1,'GBP','2026-12-01','Transfer',1,1),(2,1,1,1,1,'GBP','2026-12-01','Transfer',1,1); INSERT INTO uk_vat_documents(invoice_id,profile_version,rule_version,tax_point,net_minor,vat_minor,gross_minor,buyer_address,profile_snapshot) VALUES(1,1,'UK-STD-2026-11-25','2026-11-25',10000,2000,12000,'Buyer','{}');")
        self.migrate();self.assertEqual(self.db.execute('SELECT COUNT(*) FROM uk_document_rules').fetchone()[0],0)
        self.db.execute("INSERT INTO uk_vat_documents(invoice_id,profile_version,rule_version,tax_point,net_minor,vat_minor,gross_minor,buyer_address,profile_snapshot) VALUES(2,1,'UK-STD-2026-11-25','2026-11-27',10000,2000,12000,'Buyer','{}')")
        self.db.execute("INSERT INTO uk_document_rules VALUES(2,'UK-STD-2026-11-27')")
        self.assertEqual(self.db.execute('SELECT rule_version FROM uk_vat_documents WHERE invoice_id=2').fetchone()[0],'UK-STD-2026-11-25')
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("INSERT INTO uk_document_rules VALUES(1,'unknown')")
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("INSERT INTO uk_document_rules VALUES(3,'UK-STD-2026-11-27')")
if __name__=='__main__':unittest.main()
