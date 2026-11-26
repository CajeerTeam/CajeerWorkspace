import sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class ReliabilitySchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,33):self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'a@example.test','A','owner','hash'); INSERT INTO organization_members VALUES(1,1,'owner'); INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','internal',1); INSERT INTO tasks(project_id,title,due_on) VALUES(1,'Old task','2026-12-01');")
    def tearDown(self):self.db.close()
    def migrate(self):self.db.executescript((ROOT/'database/033.sql').read_text())
    def test_preserves_data(self):
        tables=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")];before={t:self.db.execute('SELECT * FROM '+t).fetchall() for t in tables};self.migrate();self.assertEqual(before,{t:self.db.execute('SELECT * FROM '+t).fetchall() for t in tables});self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
    def test_constraints(self):
        self.migrate();self.db.execute("INSERT INTO integration_jobs(organization_id,project_id,host_id,actor_id,actor_session,target_hash,available_at,created_at,updated_at) VALUES(1,1,7,1,1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1,1,1)")
        for sql in ["INSERT INTO integration_jobs(organization_id,project_id,host_id,actor_id,actor_session,target_hash,available_at,created_at,updated_at) VALUES(1,1,7,1,1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1,1,1)","UPDATE integration_jobs SET state='running'", "UPDATE integration_jobs SET attempts=9", "UPDATE integration_jobs SET organization_id=99", "INSERT INTO integration_rates VALUES('logs',1,61,1)", "INSERT INTO integration_rates VALUES('wrong',1,0,1)", "INSERT INTO integration_journal(project_id,provider,object_id,outcome,code,failures,created_at) VALUES(1,'logs',1,'unknown','x',0,1)"]:
            with self.assertRaises(sqlite3.IntegrityError):self.db.execute(sql)
        self.db.execute("UPDATE integration_jobs SET state='dead'");self.db.execute("INSERT INTO integration_jobs(organization_id,project_id,host_id,actor_id,actor_session,target_hash,available_at,created_at,updated_at) VALUES(1,1,7,1,1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1,1,1)")
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM integration_jobs').fetchone()[0],2)
if __name__=='__main__':unittest.main()
