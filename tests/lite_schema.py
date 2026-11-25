import hashlib, sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class LiteSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,27): self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'a@example.test','A','owner','hash'); INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','client',1),(2,'B','client',1); INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'A','contract');")
        pdf=b'%PDF-1.4\n\x00\xff\n%%EOF';self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(1,1,?,?,1)',(hashlib.sha256(pdf).hexdigest(),pdf))
    def tearDown(self): self.db.close()
    def migrate(self): self.db.executescript((ROOT/'database/027.sql').read_text())
    def test_preservation(self):
        names=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")];before={t:self.db.execute('SELECT * FROM '+t).fetchall() for t in names};self.migrate();self.assertEqual(before,{t:self.db.execute('SELECT * FROM '+t).fetchall() for t in names});self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
    def site(self):
        self.migrate();self.db.execute("INSERT INTO lite_sites(id,organization_id,project_id,target_key,root_hash,config_hash,origin,name,created_by,created_at) VALUES(1,1,1,'site',?,?,'https://test.example','A',1,1)",('a'*64,'b'*64))
    def job(self,project,key,state='queued'):
        self.db.execute("INSERT INTO lite_jobs(project_id,site_id,request_key,kind,path,expected_hash,page_revision,payload,payload_hash,state,actor_id,created_at,updated_at) VALUES(?,1,?,'draft','/page',?,?,'{}',?,?,1,1,1)",(project,key,'a'*64,'b'*64,'c'*64,state))
    def test_isolation_and_exclusive_queue(self):
        self.site()
        with self.assertRaises(sqlite3.IntegrityError):self.job(2,'a'*32)
        self.job(1,'a'*32)
        with self.assertRaises(sqlite3.IntegrityError):self.job(1,'b'*32)
        self.db.execute("UPDATE lite_jobs SET state='uncertain'")
        with self.assertRaises(sqlite3.IntegrityError):self.job(1,'b'*32)
        self.db.execute("UPDATE lite_jobs SET state='aborted'");self.job(1,'b'*32)
        self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
    def test_atomic_evidence(self):
        self.site();self.job(1,'a'*32)
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("UPDATE lite_jobs SET backup_name='site.json'")
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("UPDATE lite_sites SET site_hash=?",('a'*64,))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("UPDATE lite_jobs SET kind='book'")
if __name__=='__main__':unittest.main()
