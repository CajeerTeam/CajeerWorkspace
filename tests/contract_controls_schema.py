"""Additive migration and composite scope tests on the actual SQLite schema."""
import sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class ContractControlsSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:'); self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,49): self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("""INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'owner@example.test','Owner','owner','hash'); INSERT INTO organization_members VALUES(1,1,'owner'); INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','client',1),(2,'B','client',1); INSERT INTO legal_entities(id,organization_id,name,registration,address) VALUES(1,1,'Seller','S','Address'); INSERT INTO counterparties(id,organization_id,name) VALUES(1,1,'Buyer'); INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'Contract','contract'),(2,2,'Contract B','contract');""")
        for i in (1,2):
            self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(?,1,?,?,1)',(i,'a'*64,b'%PDF-1.4 test'))
            self.db.execute('INSERT INTO contracts(id,organization_id,project_id,entity_id,counterparty_id,reference,created_by,created_at) VALUES(?,1,?,1,1,?,1,1)',(i,i,f'C{i}'))
            self.db.execute('INSERT INTO contract_revisions(id,contract_id,project_id,revision,title,party_snapshot,responsible_id,seller_signer,seller_authority,buyer_signer,buyer_authority,currency,amount_minor,signed_on,starts_on,ends_on,notice_on,renewal_terms,document_id,document_revision,document_hash,created_by,created_at) VALUES(?,?,?,1,\'Title\',\'{}\',1,\'A\',\'Basis\',\'B\',\'Basis\',\'EUR\',100,\'2026-01-01\',\'2026-01-01\',\'2026-12-31\',\'2026-12-01\',\'None\',?,1,?,1,1)',(i,i,i,i,'a'*64))
            self.db.execute("INSERT INTO contract_obligations(id,project_id,contract_id,revision_id,title,due_on,created_by,created_at) VALUES(?,?,?,?,'Work','2026-12-01',1,1)",(i,i,i,i))
            self.db.execute("INSERT INTO tasks(id,project_id,title,priority,result_customer,acceptance_criteria) VALUES(?,?,'Control',2,'Legal','Check')",(i,i))
        self.db.commit()
    def tearDown(self): self.db.close()
    def migrate(self): self.db.executescript((ROOT/'database/049.sql').read_text())
    def test_preserve(self):
        tables=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")];before={t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables};self.migrate();self.assertEqual(before,{t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables});self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[]);self.assertEqual(self.db.execute('PRAGMA integrity_check').fetchone()[0],'ok')
    def test_scope_and_uniqueness(self):
        self.migrate()
        for i in (1,2): self.db.execute("INSERT INTO contract_authorities(id,project_id,contract_id,side,signer,basis,party_digest,valid_from,valid_until,verified_by,verified_at) VALUES(?,?,?,'seller','A','Basis',?,'2026-01-01','2026-12-31',1,1)",(i,i,i,'a'*64))
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("INSERT INTO contract_activation_evidence VALUES(1,1,1,1,2,NULL,'{}','{}')")
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("INSERT INTO contract_controls VALUES(2,1,1,1,1,'obligation','2026-12-01',1,1)")
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("INSERT INTO contract_controls VALUES(1,1,1,1,2,'obligation','2026-12-01',1,1)")
        self.db.execute("INSERT INTO contract_controls VALUES(1,1,1,1,1,'obligation','2026-12-01',1,1)")
        self.db.execute("INSERT INTO tasks(id,project_id,title,priority,result_customer,acceptance_criteria) VALUES(3,1,'Duplicate',2,'Legal','Check')")
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("INSERT INTO contract_controls VALUES(3,1,1,1,1,'obligation','2026-12-01',1,1)")
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("UPDATE contract_authorities SET revoked_at=1 WHERE id=1")
if __name__=='__main__': unittest.main()
