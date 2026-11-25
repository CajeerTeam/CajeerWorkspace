import sqlite3, unittest, hashlib
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class EngineSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,24): self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'a@example.test','A','owner','hash'); INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','client',1),(2,'B','client',1); INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'A','contract');")
        self.pdf=b'%PDF-1.4\n\x00\xff\n%%EOF'; self.sha=hashlib.sha256(self.pdf).hexdigest()
        self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(1,1,?,?,1)',(self.sha,self.pdf));self.db.commit()
    def tearDown(self):self.db.close()
    def migrate(self):self.db.executescript((ROOT/'database/024.sql').read_text())
    def test_preservation(self):
        tables=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")];before={t:self.db.execute('SELECT * FROM '+t).fetchall() for t in tables}
        self.migrate();self.assertEqual(before,{t:self.db.execute('SELECT * FROM '+t).fetchall() for t in tables});self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
    def test_isolation(self):
        self.migrate();s="INSERT INTO engine_sites(id,organization_id,project_id,connection_key,target_hash,config_hash,name,created_by,created_at) VALUES(?,1,?,?,?,?,?,1,1)";self.db.execute(s,(1,1,'engine','a'*64,'b'*64,'site'))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute(s,(2,2,'alias','a'*64,'c'*64,'alias'))
        q="INSERT INTO engine_publications(project_id,site_id,content_type,entry_id,revision,snapshot,snapshot_hash,created_by,created_at,updated_at) VALUES(?,1,'pages','entry',1,'{}',?,1,1,1)"
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute(q,(2,'d'*64))
        self.db.execute(q,(1,'d'*64))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute(q,(1,'e'*64))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("UPDATE engine_publications SET state='published'")
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("UPDATE engine_publications SET state='published',result_revision=3")
        self.db.execute("UPDATE engine_publications SET state='published',result_revision=2,confirmed_by=1,attempted_at=1")
        self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
if __name__=='__main__':unittest.main()
