"""Migration 012 -> 013: preservation, cross-tenant constraints, rollback."""
import sqlite3, unittest, hashlib
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class Infrastructure(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:'); self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,13): self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("""INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'a@example.test','Owner','owner','hash'); INSERT INTO organization_members VALUES(1,1,'owner'); INSERT INTO organizations(id,name) VALUES(2,'Other'); INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','internal',1),(2,'B','internal',2); INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'Contract','contract');""")
        pdf=b'%PDF-1.4\n\x00\xff\n%%EOF';self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(1,1,?,?,1)',(hashlib.sha256(pdf).hexdigest(),pdf));self.db.commit();self.sql=(ROOT/'database/013.sql').read_text()
    def tearDown(self):self.db.close()
    def test_preservation(self):
        tables=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")]; old={t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables};self.db.executescript(self.sql);self.assertEqual(old,{t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables});self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[]);self.assertEqual(self.db.execute('PRAGMA integrity_check').fetchone()[0],'ok')
    def test_scope(self):
        self.db.executescript(self.sql)
        self.db.execute("INSERT INTO infra_hosts(id,organization_id,name,panel_key,panel_target,panel_hash,panel_api,servers_key,servers_target,servers_hash,workspace_uuid,asset_uuid) VALUES(1,1,'Host','panel',?,?,'v2','servers',?,?,?,?)",('a'*64,'b'*64,'c'*64,'d'*64,'e'*36,'f'*36))
        self.db.executescript("INSERT INTO infra_environments(id,organization_id,project_id,kind,name) VALUES(1,1,1,'prod','Production'),(2,2,2,'dev','Development'); INSERT INTO infra_sites(id,organization_id,project_id,environment_id,host_id,remote_id,site_name) VALUES(1,1,1,1,1,1,'site'); INSERT INTO infra_operation_owners VALUES(1,'site.deploy','aapanel',1);")
        for sql in ["INSERT INTO infra_sites VALUES(2,2,2,2,1,2,'other')","INSERT INTO infra_sites VALUES(2,1,1,1,1,1,'same')","INSERT INTO infra_sites VALUES(2,1,1,2,1,2,'foreign')","INSERT INTO infra_operation_owners VALUES(1,'site.deploy','servers',1)","INSERT INTO infra_observations(host_id,source,attempt_at,success_at) VALUES(1,'servers',1,1)","DELETE FROM infra_hosts WHERE id=1"]:
            with self.subTest(sql=sql),self.assertRaises(sqlite3.IntegrityError):self.db.execute(sql)
    def test_rollback(self):
        self.db.executescript('BEGIN;\n'+self.sql);self.db.rollback();self.assertIsNone(self.db.execute("SELECT name FROM sqlite_master WHERE name='infra_hosts'").fetchone())
if __name__=='__main__':unittest.main()
