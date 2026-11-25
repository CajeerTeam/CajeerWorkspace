import sqlite3, unittest, hashlib
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class PrepSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,21):self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        for i in range(1,4):self.db.execute('INSERT INTO users(id,email,name,role,password_hash) VALUES(?,?,?,?,?)',(i,f'{i}@example.test',str(i),'owner','hash'))
        self.db.executescript("INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','client',1),(2,'B','client',1); INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'Act','acceptance'),(2,2,'Foreign','acceptance');")
        for d in (1,2):
            b=b'%PDF-1.4\n\x00\xff\n%%EOF';self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(?,1,?,?,1)',(d,hashlib.sha256(b).hexdigest(),b))
        self.db.commit();self.sql=(ROOT/'database/021.sql').read_text()
    def tearDown(self):self.db.close()
    def test_preserve(self):
        ts=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")];before={t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in ts};self.db.executescript(self.sql);self.assertEqual(before,{t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in ts});self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
    def test_constraints(self):
        self.db.executescript(self.sql);h='a'*64
        self.db.execute("INSERT INTO preparation_templates(id,project_id,kind,revision,body,sha256,created_by,created_at) VALUES(1,1,'acceptance',1,'text',?,1,1),(2,2,'acceptance',1,'text',?,1,1),(3,1,'nda',1,'text',?,1,1)",(h,h,h))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("INSERT INTO preparations(project_id,kind,title,editor_id,lawyer_id,approver_id,created_at) VALUES(1,'acceptance','A',1,1,3,1)")
        self.db.execute("INSERT INTO preparations(id,project_id,kind,title,editor_id,lawyer_id,approver_id,created_at) VALUES(1,1,'acceptance','A',1,2,3,1)")
        q="INSERT INTO preparation_revisions(project_id,preparation_id,kind,revision,template_id,body,document_id,document_revision,document_hash,evidence_hash,created_by,created_at) VALUES(1,1,'acceptance',1,?,'text',?,?,?,?,1,1)"
        for t,d,r,dh in [(2,1,1,h),(3,1,1,h),(1,2,1,h),(1,1,9,h),(1,1,None,h)]:
            with self.assertRaises(sqlite3.IntegrityError):self.db.execute(q,(t,d,r,dh,h))
        self.db.execute(q,(1,1,1,h,h));self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
if __name__=='__main__':unittest.main()
