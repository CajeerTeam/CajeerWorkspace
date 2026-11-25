"""020 migration preserves previous records and pins documents within a project."""
import sqlite3, unittest, hashlib
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class ContractsSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,20):self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("""INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'a@example.test','Owner','owner','hash'); INSERT INTO organization_members VALUES(1,1,'owner'); INSERT INTO organizations(id,name) VALUES(2,'Other'); INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','client',1),(2,'B','client',2); INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'Contract','contract'),(2,2,'Foreign','contract'); INSERT INTO legal_entities(id,organization_id,name,registration,address) VALUES(1,1,'Seller','REG','Address'); INSERT INTO counterparties(id,organization_id,name) VALUES(1,1,'Buyer');""")
        for doc,rev in ((1,1),(1,2),(2,1)):
            b=b'%PDF-1.4\n\x00\xff'+str(rev).encode()+b'\n%%EOF';self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(?,?,?,?,1)',(doc,rev,hashlib.sha256(b).hexdigest(),b))
        self.db.commit();self.sql=(ROOT/'database/020.sql').read_text()
    def tearDown(self):self.db.close()
    def test_preserve(self):
        tables=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")];before={t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables};self.db.executescript(self.sql);self.assertEqual(before,{t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables});self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[]);self.assertEqual(self.db.execute('PRAGMA integrity_check').fetchone()[0],'ok')
    def test_scope(self):
        self.db.executescript(self.sql)
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("INSERT INTO contracts(id,organization_id,project_id,entity_id,counterparty_id,reference,created_by,created_at) VALUES(1,1,2,1,1,'A',1,1)")
        self.db.execute("INSERT INTO contracts(id,organization_id,project_id,entity_id,counterparty_id,reference,created_by,created_at) VALUES(1,1,1,1,1,'A',1,1)")
        q='INSERT INTO contract_revisions(contract_id,project_id,revision,title,party_snapshot,responsible_id,seller_signer,seller_authority,buyer_signer,buyer_authority,currency,amount_minor,signed_on,starts_on,ends_on,notice_on,renewal_terms,document_id,document_revision,document_hash,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        row=(1,1,1,'Title','{}',1,'S','Authority','B','Authority','EUR',100,'2026-01-01','2026-01-01','2026-12-31','2026-12-01','Renew',1,1,'a'*64,1,1)
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute(q,(*row[:17],2,*row[18:]))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute(q,(*row[:18],3,*row[19:]))
        self.db.execute(q,row)
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("UPDATE contract_revisions SET activated_at=1")
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("INSERT INTO contract_attachments(project_id,contract_id,revision_id,document_id,document_revision,document_hash,label) VALUES(1,1,1,2,1,?,'Foreign')",('a'*64,))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("UPDATE contract_revisions SET amount_minor=1.5")
    def test_obligation(self):
        self.db.executescript(self.sql);self.db.execute("INSERT INTO contracts(id,organization_id,project_id,entity_id,counterparty_id,reference,created_by,created_at) VALUES(1,1,1,1,1,'A',1,1)");self.db.execute("INSERT INTO contract_revisions(id,contract_id,project_id,revision,title,party_snapshot,responsible_id,seller_signer,seller_authority,buyer_signer,buyer_authority,currency,amount_minor,signed_on,starts_on,ends_on,notice_on,renewal_terms,document_id,document_revision,document_hash,created_by,created_at) VALUES(1,1,1,1,'Title','{}',1,'S','Auth','B','Auth','EUR',100,'2026-01-01','2026-01-01','2026-12-31','2026-12-01','Renew',1,1,?,1,1)",('a'*64,));self.db.execute("INSERT INTO contract_obligations(project_id,contract_id,revision_id,title,due_on,created_by,created_at) VALUES(1,1,1,'Work','2026-12-01',1,1)")
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("UPDATE contract_obligations SET state='completed'")
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("UPDATE contract_obligations SET invoice_id=99")
if __name__=='__main__':unittest.main()
