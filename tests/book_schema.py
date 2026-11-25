import sqlite3, unittest, hashlib
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class BookSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,25):self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'a@example.test','A','owner','hash'),(2,'b@example.test','B','developer','hash'),(3,'c@example.test','C','developer','hash'); INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','client',1),(2,'B','client',1); INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'A','contract');")
        self.pdf=b'%PDF-1.4\n\x00\xff\n%%EOF';self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(1,1,?,?,1)',(hashlib.sha256(self.pdf).hexdigest(),self.pdf));self.db.commit()
    def tearDown(self):self.db.close()
    def migrate(self):self.db.executescript((ROOT/'database/025.sql').read_text())
    def test_preservation(self):
        names=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")];before={t:self.db.execute('SELECT * FROM '+t).fetchall() for t in names};self.migrate();self.assertEqual(before,{t:self.db.execute('SELECT * FROM '+t).fetchall() for t in names});self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
    def test_isolation(self):
        self.migrate();self.db.execute("INSERT INTO book_sources(id,organization_id,project_id,connection_key,config_hash,scope,name,created_by,created_at) VALUES(1,1,1,'book',?,'handbook/1.0','Book',1,1)",('a'*64,))
        q="INSERT INTO book_editions(id,project_id,source_id,title,snapshot,snapshot_hash,selection_hash,author_id,reviewer_a,reviewer_b,request_key,created_at) VALUES(1,?,1,'Book','{}',?,?,1,2,?, ?,1)"
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute(q,(2,'b'*64,'c'*64,3,'d'*32))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute(q,(1,'b'*64,'c'*64,2,'d'*32))
        self.db.execute(q,(1,'b'*64,'c'*64,3,'d'*32))
        q="INSERT INTO book_reviews(project_id,edition_id,reviewer_id,decision,note,snapshot_hash,created_at) VALUES(?,1,2,'approve','OK',?,1)"
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute(q,(2,'b'*64))
        self.db.execute(q,(1,'b'*64))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute(q,(1,'b'*64))
        self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
if __name__=='__main__':unittest.main()
