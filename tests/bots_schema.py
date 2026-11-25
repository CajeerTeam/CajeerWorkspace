import sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class BotsSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,30):self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'a@example.test','A','owner','hash'); INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','client',1),(2,'B','client',1)")
    def tearDown(self):self.db.close()
    def migrate(self):self.db.executescript((ROOT/'database/030.sql').read_text())
    def bind(self):self.db.execute("INSERT INTO bots_bindings VALUES(1,1,'https://bots.example.test','c1','a1','telegram','-100123456789','key',?,1,1)",('a'*64,))
    def message(self,project=1):self.db.execute("INSERT INTO bots_messages(project_id,organization_id,request_key,remote_id,identity_hash,target_revision,message,message_hash,preview_hash,creator_id,available_at,created_at,updated_at) VALUES(?,1,?,?,?,?,?,?,?,?,1,1,1)",(project,'a'*32,'workspace-'+'b'*32,'a'*64,'2026-11-25T00:00:00.000Z','x','c'*64,'d'*64,1))
    def test_migration_preserves_prior_records(self):
        tables=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")];before={t:self.db.execute('SELECT * FROM '+t).fetchall() for t in tables};self.migrate();self.assertEqual(before,{t:self.db.execute('SELECT * FROM '+t).fetchall() for t in tables});self.assertEqual(self.db.execute('SELECT COUNT(*) FROM bots_messages').fetchone(),(0,))
    def test_target_uniqueness_and_scope(self):
        self.migrate();self.bind()
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("INSERT INTO bots_bindings SELECT 2,organization_id,origin,community_id,account_id,provider,channel_id,credential_id,identity_hash,active,updated_at FROM bots_bindings")
        with self.assertRaises(sqlite3.IntegrityError):self.message(2)
    def test_claim_receipt_and_history_constraints(self):
        self.migrate();self.bind();self.message()
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("UPDATE bots_messages SET state='processing'")
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("UPDATE bots_messages SET state='sent'")
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("INSERT INTO bots_events(project_id,message_id,actor_id,state,detail,created_at) VALUES(2,1,1,'draft','',1)")
        self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
if __name__=='__main__':unittest.main()
