import sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class EditorialSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:'); self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,28): self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'a@example.test','A','owner','hash'); INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','client',1),(2,'B','client',1);")
    def tearDown(self): self.db.close()
    def test_migration_preserves(self):
        tables=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")]; before={t:self.db.execute('SELECT * FROM '+t).fetchall() for t in tables}; self.db.executescript((ROOT/'database/028.sql').read_text()); self.assertEqual(before,{t:self.db.execute('SELECT * FROM '+t).fetchall() for t in tables}); self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
    def test_scoped_foreign_keys(self):
        self.db.executescript((ROOT/'database/028.sql').read_text()); self.assertEqual(len(self.db.execute('PRAGMA foreign_key_list(editorial_releases)').fetchall()),15); self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("INSERT INTO editorial_events(project_id,editorial_id,actor_id,step,state,created_at) VALUES(2,1,1,'cms','published',1)")
if __name__=='__main__': unittest.main()
