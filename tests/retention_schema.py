import hashlib, sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class RetentionSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:'); self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,23): self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'a@example.test','A','owner','hash'); INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','client',1); INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'A','contract');")
        self.pdf=b'%PDF-1.4\n\x00\xff\n%%EOF'; self.sha=hashlib.sha256(self.pdf).hexdigest()
        self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(1,1,?,?,1)',(self.sha,self.pdf)); self.db.commit()
        self.migration=(ROOT/'database/023.sql').read_text()
    def tearDown(self): self.db.close()
    def test_migration_preserves_all_old_columns(self):
        tables=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")]
        cols={t:[r[1] for r in self.db.execute(f'PRAGMA table_info({t})')] for t in tables}
        before={t:self.db.execute('SELECT '+','.join(cols[t])+' FROM '+t).fetchall() for t in tables}
        self.db.executescript(self.migration)
        self.assertEqual(before,{t:self.db.execute('SELECT '+','.join(cols[t])+' FROM '+t).fetchall() for t in tables})
        self.assertEqual(self.db.execute('SELECT storage_state,object_key,bytes,sha256 FROM document_versions').fetchone(),('inline',None,self.pdf,self.sha))
        self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
    def test_object_and_links_constraints(self):
        self.db.executescript(self.migration)
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("UPDATE document_versions SET storage_state='object'")
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("UPDATE document_versions SET storage_state='purged'")
        self.db.execute("UPDATE document_versions SET storage_state='object',object_key=?,original_size=?,bytes=?",('a'*64,len(self.pdf),b'CW-OBJECT-1'))
        sql='INSERT INTO retention_links(document_id,revision,token_hash,issuer_id,request_key,created_at,expires_at,max_uses) VALUES(1,1,?,1,?,1,?,?)'
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute(sql,('b'*64,'a'*32,1,1))
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute(sql,('b'*64,'a'*32,2,21))
        self.db.execute(sql,('b'*64,'a'*32,2,1))
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute('UPDATE retention_links SET uses=2')
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute(sql,('c'*64,'a'*32,2,1))
    def test_policy_and_hold_and_audit(self):
        self.db.executescript(self.migration)
        self.db.execute("INSERT INTO organizations(id,name) VALUES(2,'Other')")
        self.db.execute("INSERT INTO retention_policies(id,organization_id,country,kind,days,basis,created_by,created_at) VALUES(1,1,'GB','contract',1,'basis',1,1)")
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("INSERT INTO retention_documents VALUES(1,2,1,1,1,'2020-01-01')")
        self.db.execute("INSERT INTO retention_documents VALUES(1,1,1,1,1,'2020-01-01')")
        self.db.execute("INSERT INTO retention_holds(document_id,reason,created_by,created_at) VALUES(1,'dispute',1,1)")
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute('UPDATE retention_holds SET released_at=2')
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("INSERT INTO retention_deliveries(document_id,revision,channel,payload_sha256,bytes_size,issued_at) VALUES(1,1,'document',?,1,1)",(self.sha,))
        self.db.execute("INSERT INTO retention_deliveries(document_id,revision,actor_id,channel,payload_sha256,bytes_size,issued_at) VALUES(1,1,1,'document',?,1,1)",(self.sha,))
        self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
if __name__=='__main__': unittest.main()
