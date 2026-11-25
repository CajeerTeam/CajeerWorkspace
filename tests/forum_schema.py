import sqlite3, unittest, hashlib
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class ForumSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,26):self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'a@example.test','A','owner','hash'),(2,'b@example.test','B','developer','hash'),(3,'c@example.test','C','developer','hash'); INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','client',1),(2,'B','client',1); INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'A','contract');")
        self.pdf=b'%PDF-1.4\n\x00\xff\n%%EOF';self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(1,1,?,?,1)',(hashlib.sha256(self.pdf).hexdigest(),self.pdf));self.db.commit()
    def tearDown(self):self.db.close()
    def migrate(self):self.db.executescript((ROOT/'database/026.sql').read_text())
    def test_preservation(self):
        names=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")];before={t:self.db.execute('SELECT * FROM '+t).fetchall() for t in names};self.migrate();self.assertEqual(before,{t:self.db.execute('SELECT * FROM '+t).fetchall() for t in names});self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
    def test_isolation(self):
        self.migrate();self.db.execute("INSERT INTO tasks(id,project_id,title) VALUES(1,1,'A'),(2,2,'B')")
        self.db.execute("INSERT INTO forum_sources(id,organization_id,project_id,connection_key,config_hash,board_id,created_by,created_at) VALUES(1,1,1,'forum',?,?,1,1)",('a'*64,'b'*36))
        q="INSERT INTO forum_topics(id,project_id,source_id,task_id,thread_id,state,actor_id,forum_actor,created_at) VALUES(1,?,1,?,?,'linked',1,?,1)"
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute(q,(2,2,'c'*36,'d'*36))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute(q,(1,2,'c'*36,'d'*36))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute(q,(1,1,None,'d'*36))
        self.db.execute(q,(1,1,'c'*36,'d'*36))
        self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
if __name__=='__main__':unittest.main()
