import sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class LogsSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:'); self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,29): self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'a@example.test','A','owner','hash'); INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','client',1),(2,'B','client',1); INSERT INTO audit_events(organization_id,project_id,actor_id,event,object_id,detail) VALUES(1,1,1,'task.create',1,'private')")
    def tearDown(self): self.db.close()
    def migrate(self): self.db.executescript((ROOT/'database/029.sql').read_text())
    def bind(self): self.db.execute("INSERT INTO logs_bindings VALUES(1,1,'https://logs.example.test',?,?,?, ?,?,30,1,1)",('1'*36,'2'*36,'workspace','a'*64,'b'*32))
    def test_preserves_old_tables(self):
        tables=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")]; before={t:self.db.execute('SELECT * FROM '+t).fetchall() for t in tables};self.migrate();self.assertEqual(before,{t:self.db.execute('SELECT * FROM '+t).fetchall() for t in tables});self.assertEqual(self.db.execute('SELECT COUNT(*) FROM logs_outbox').fetchone(),(0,))
    def test_binding_scope_and_unique(self):
        self.migrate();self.bind()
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("INSERT INTO logs_bindings SELECT 2,organization_id,origin,remote_project,remote_source,source_slug,identity_hash,?,retention_days,active,updated_at FROM logs_bindings",('c'*32,))
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("INSERT INTO logs_outbox(project_id,organization_id,event_key,correlation_id,event,object_id,created_at,expires_at,available_at) VALUES(2,1,?,?,'task.create',1,1,2,1)",('a'*32,'b'*32))
    def test_claim_and_receipt_checks(self):
        self.migrate();self.bind();self.db.execute("INSERT INTO logs_outbox(project_id,organization_id,event_key,correlation_id,event,object_id,created_at,expires_at,available_at) VALUES(1,1,?,?,'task.create',1,1,2,1)",('a'*32,'b'*32))
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("UPDATE logs_outbox SET state='sending'")
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("UPDATE logs_outbox SET state='delivered'")
        self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
if __name__=='__main__': unittest.main()
