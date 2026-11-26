import sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class DiscoverySchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,32):self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'a@example.test','A','owner','hash'),(2,'b@example.test','B','viewer','hash'); INSERT INTO organization_members VALUES(1,1,'owner'),(1,2,'viewer'); INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','internal',1); INSERT INTO tasks(project_id,title,due_on) VALUES(1,'Old task','2026-12-01');")
    def tearDown(self):self.db.close()
    def migrate(self):self.db.executescript((ROOT/'database/032.sql').read_text())
    def test_preserves_old_data(self):
        tables=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")];before={t:self.db.execute('SELECT * FROM '+t).fetchall() for t in tables};self.migrate();self.assertEqual(before,{t:self.db.execute('SELECT * FROM '+t).fetchall() for t in tables});self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
    def test_constraints_and_cascade(self):
        self.migrate();self.db.execute("INSERT INTO reminder_subscriptions VALUES(1,1,1,2,'tasks',7,1,1)");self.db.execute("INSERT INTO reminder_reads VALUES(1,1,10,'2026-12-01',1)")
        for sql in ["INSERT INTO reminder_subscriptions VALUES(2,1,1,2,'tasks',7,1,1)","INSERT INTO reminder_subscriptions VALUES(2,1,1,2,'milestones',7,1,1)","INSERT INTO reminder_subscriptions VALUES(2,1,1,2,'milestones',91,0,1)","INSERT INTO reminder_subscriptions VALUES(2,9,1,2,'tasks',7,0,1)","INSERT INTO reminder_reads VALUES(2,1,10,'2026-12-01',1)"]:
            with self.assertRaises(sqlite3.IntegrityError):self.db.execute(sql)
        self.db.execute('DELETE FROM organization_members WHERE organization_id=1 AND user_id=2');self.assertEqual(self.db.execute('SELECT COUNT(*) FROM reminder_subscriptions').fetchone()[0],0);self.assertEqual(self.db.execute('SELECT COUNT(*) FROM reminder_reads').fetchone()[0],0)
if __name__=='__main__':unittest.main()
