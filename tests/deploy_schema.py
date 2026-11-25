"""Preserve all existing records during 013 -> 014, isolate projects and active jobs."""
import hashlib, sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class DeploySchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,14): self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("""INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'a@example.test','Owner','owner','hash'); INSERT INTO organization_members VALUES(1,1,'owner'); INSERT INTO organizations(id,name) VALUES(2,'Other'); INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','oss',1),(2,'B','oss',2); INSERT INTO github_repositories(id,project_id,repository_id,installation_id,full_name) VALUES(1,1,40,30,'owner/a'),(2,2,41,30,'owner/b'); INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'Contract','contract');""")
        pdf=b'%PDF-1.4\n\x00\xff\n%%EOF';self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(1,1,?,?,1)',(hashlib.sha256(pdf).hexdigest(),pdf))
        self.db.execute("INSERT INTO infra_hosts(id,organization_id,name,panel_key,panel_target,panel_hash,panel_api,servers_key,servers_target,servers_hash,workspace_uuid,asset_uuid) VALUES(1,1,'Host','panel',?,?,'v2','servers',?,?,?,?)",('a'*64,'b'*64,'c'*64,'d'*64,'e'*36,'f'*36))
        self.db.executescript("INSERT INTO infra_environments(id,organization_id,project_id,kind,name) VALUES(1,1,1,'prod','Production'); INSERT INTO infra_sites(id,organization_id,project_id,environment_id,host_id,remote_id,site_name) VALUES(1,1,1,1,1,1,'site');")
        for i in (1,2):self.db.execute('INSERT INTO project_releases(id,project_id,repository_binding,repository_generation,tag,commit_sha,changelog,artifact_name,artifact_sha256,required_checks,responsible_id,creator_id,request_key,created_at) VALUES(?,?,?,1,?,?,?,?,?,?,1,1,?,1)',(i,i,i,'v1','a'*40,'notes','app.zip','b'*64,'["Build"]','key'))
        self.db.commit();self.sql=(ROOT/'database/014.sql').read_text()
    def tearDown(self):self.db.close()
    def test_preservation(self):
        tables=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")];old={t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables};self.db.executescript(self.sql);self.assertEqual(old,{t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables});self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[]);self.assertEqual(self.db.execute('PRAGMA integrity_check').fetchone()[0],'ok')
    def insert(self,project=1,release=1,key='key',token='a'*64,start=1,end=2):
        self.db.execute("INSERT INTO deployments(project_id,site_id,release_id,actor_id,actor_session,provider,target_snapshot,release_hash,request_key,execution_key,window_start,window_end,created_at) VALUES(?,1,?,1,1,'aapanel','{}',?,?,?,?,?,1)", (project,release,'c'*64,key,token,start,end))
    def test_scope_and_queue(self):
        self.db.executescript(self.sql);self.insert()
        for kwargs in ({'project':2,'release':2},{'release':2},{'key':'second','token':'b'*64},{'start':1,'end':86402}):
            with self.subTest(kwargs=kwargs),self.assertRaises(sqlite3.IntegrityError):self.insert(**kwargs)
        self.db.execute("UPDATE deployments SET state='recovery_required'")
        with self.assertRaises(sqlite3.IntegrityError):self.insert(key='second',token='b'*64)
        self.db.execute("UPDATE deployments SET state='rolled_back'");self.insert(key='second',token='b'*64)
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute('DELETE FROM infra_sites WHERE id=1')
    def test_rollback(self):
        self.db.executescript('BEGIN;\n'+self.sql);self.db.rollback();self.assertIsNone(self.db.execute("SELECT name FROM sqlite_master WHERE name='deployments'").fetchone())
if __name__=='__main__':unittest.main()
