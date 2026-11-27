"""Exercise additive migration and database-level delivery identity constraints."""
import sqlite3
import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

class DeliverySchema(unittest.TestCase):
    def test_scope_and_review_identity(self):
        db=sqlite3.connect(':memory:');self.addCleanup(db.close);db.execute('PRAGMA foreign_keys=ON')
        for p in sorted((ROOT/'database').glob('*.sql')):
            if int(p.stem)<39:db.executescript(p.read_text())
        db.execute("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'owner@example.test','Owner','owner','fixture')")
        db.execute("INSERT INTO organization_members VALUES(1,1,'owner')")
        db.execute("INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'First','internal',1),(2,'Second','internal',1)")
        db.execute("INSERT INTO tasks(id,project_id,title,state,acceptance_criteria) VALUES(1,1,'Task','done','Criteria'),(2,2,'Other','done','Other criteria')")
        db.execute("INSERT INTO github_repositories(id,project_id,repository_id,installation_id,full_name) VALUES(1,1,40,30,'owner/one'),(2,2,41,30,'owner/two')")
        for n in (1,2):
            db.execute("INSERT INTO project_releases(id,project_id,repository_binding,repository_generation,tag,commit_sha,changelog,artifact_name,artifact_sha256,required_checks,responsible_id,creator_id,request_key,created_at) VALUES(?,?,?,1,'v1',?,'Notes','app.zip',?,'[]',1,1,?,1)",(n,n,n,'a'*40,'b'*64,str(n)))
            db.execute('INSERT INTO project_release_tasks VALUES(?,?,?,1,1,7)',(n,n,n))
        db.execute("INSERT INTO infra_hosts(id,organization_id,name,panel_key,panel_target,panel_hash,panel_api,servers_key,servers_target,servers_hash,workspace_uuid,asset_uuid) VALUES(1,1,'Host','panel',?,?,'v2','servers',?,?,?,?)",('a'*64,'b'*64,'c'*64,'d'*64,'e'*36,'f'*36))
        for n in (1,2):
            db.execute("INSERT INTO infra_environments(id,organization_id,project_id,kind,name) VALUES(?,1,?,'staging','Staging')",(n,n))
            db.execute("INSERT INTO infra_sites(id,organization_id,project_id,environment_id,host_id,remote_id,site_name) VALUES(?,1,?,?,1,?,?)",(n,n,n,n,f'site{n}'))
            db.execute("INSERT INTO deployments(id,project_id,site_id,release_id,actor_id,actor_session,provider,target_snapshot,release_hash,request_key,execution_key,window_start,window_end,created_at) VALUES(?,?,?,?,1,1,'aapanel','{}',?,?,?,1,2,1)",(n,n,n,n,'c'*64,str(n),str(n)*64))
        before=db.execute('SELECT * FROM project_release_tasks').fetchall();db.executescript((ROOT/'database/039.sql').read_text());self.assertEqual(db.execute('SELECT * FROM project_release_tasks').fetchall(),before)
        db.execute("INSERT INTO delivery_task_scope VALUES(1,1,1,'Task','Criteria','[8]')")
        with self.assertRaises(sqlite3.IntegrityError):db.execute("INSERT INTO delivery_task_scope VALUES(1,2,1,'Other','Other','[]')")
        sql="INSERT INTO delivery_reviews(project_id,release_id,task_id,deployment_id,actor_id,request_key,fingerprint,outcome,notes,created_at) VALUES(1,1,1,?,1,?,?,'passed','QA',1)"
        db.execute(sql,(1,'first','d'*64))
        with self.assertRaises(sqlite3.IntegrityError):db.execute(sql,(2,'cross','d'*64))
        with self.assertRaises(sqlite3.IntegrityError):db.execute(sql,(1,'first','d'*64))
        self.assertEqual(db.execute('PRAGMA foreign_key_check').fetchall(),[])
        self.assertEqual(db.execute('PRAGMA integrity_check').fetchone()[0],'ok')

if __name__=='__main__':unittest.main()
