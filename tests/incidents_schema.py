import sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class IncidentSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,31):self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'a@example.test','A','owner','hash'); INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','internal',1),(2,'B','internal',1)")
    def tearDown(self):self.db.close()
    def migrate(self):self.db.executescript((ROOT/'database/031.sql').read_text())
    def insert(self):self.db.execute("INSERT INTO incidents(organization_id,project_id,source,fingerprint,condition_code,object_id,responsible_id,state,severity,detected_at,first_signal_at,last_signal_at,response_due) VALUES(1,1,'servers',?,'servers.offline',1,1,'open','critical',1,1,1,2)",('a'*64,))
    def test_migration_preserves(self):
        tables=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")];before={t:self.db.execute('SELECT * FROM '+t).fetchall() for t in tables};self.migrate();self.assertEqual(before,{t:self.db.execute('SELECT * FROM '+t).fetchall() for t in tables});self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
    def test_active_dedup_and_scope(self):
        self.migrate();self.insert()
        with self.assertRaises(sqlite3.IntegrityError):self.insert()
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("INSERT INTO incident_signals(project_id,incident_id,signal_key,observed_at,evidence) VALUES(2,1,?,1,'{}')",('b'*64,))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("UPDATE incidents SET state='resolved' WHERE id=1")
        self.db.execute("UPDATE incidents SET state='resolved',acknowledged_at=2,resolved_at=3,verification_at=3,verification_mode='source' WHERE id=1");self.insert()
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM incidents').fetchone(),(2,))
if __name__=='__main__':unittest.main()
