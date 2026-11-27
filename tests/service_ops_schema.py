import sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class ServiceSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.addCleanup(self.db.close);self.db.execute('PRAGMA foreign_keys=ON')
        for p in sorted((ROOT/'database').glob('*.sql')):
            if int(p.stem)<42:self.db.executescript(p.read_text())
        self.db.execute("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'owner@example.test','Owner','owner','fixture')")
        self.db.execute("INSERT INTO organization_members VALUES(1,1,'owner')")
        self.db.execute("INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'First','internal',1),(2,'Second','internal',1)")
        for i in (1,2):
            self.db.execute("INSERT INTO infra_hosts(id,organization_id,name,panel_key,panel_target,panel_hash,panel_api,servers_key,servers_target,servers_hash,workspace_uuid,asset_uuid) VALUES(?,1,'host',?,?,?,'v2',?,?,?,?,?)",(i,'panel'+str(i),str(i)*64,'a'*64,'server'+str(i),str(i)*64,'b'*64,'c'*36,str(i)*36))
            self.db.execute("INSERT INTO infra_environments(id,organization_id,project_id,kind,name) VALUES(?,1,?,'prod','Production')",(i,i))
            self.db.execute("INSERT INTO infra_sites(id,organization_id,project_id,environment_id,host_id,remote_id,site_name) VALUES(?,1,?,?,?,1,?)",(i,i,i,i,f'site{i}.test'))
            self.db.execute("INSERT INTO incidents(id,organization_id,project_id,source,fingerprint,condition_code,object_id,environment,responsible_id,state,severity,detected_at,first_signal_at,last_signal_at,response_due) VALUES(?,1,?,'servers',?,'servers.offline',?,'prod',1,'open','critical',1,1,1,100)",(i,i,str(i)*64,i))
            self.db.execute("INSERT INTO tasks(id,project_id,title,acceptance_criteria) VALUES(?,?,'Task','criteria')",(i,i))
        before=self.db.execute('SELECT * FROM incidents').fetchall(); self.db.executescript((ROOT/'database/042.sql').read_text());self.assertEqual(before,self.db.execute('SELECT * FROM incidents').fetchall())
        self.db.execute("INSERT INTO ops_services(id,project_id,site_id,name,recovery_minutes,target_bp,created_at) VALUES(1,1,1,'Service A',30,9900,1),(2,2,2,'Service B',60,9500,1)")
        self.db.execute('INSERT INTO ops_service_incidents(project_id,service_id,incident_id,recovery_minutes,linked_at) VALUES(1,1,1,30,1),(2,2,2,60,1)')
    def test_chain(self):
        self.db.execute("INSERT INTO ops_support(project_id,service_id,incident_id,title,description,requester,created_by,created_at) VALUES(1,1,1,'ticket','description','client',1,1)")
        self.db.execute('INSERT INTO ops_followups VALUES(1,1,1,1,1,1)')
        self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute("UPDATE ops_support SET state='closed'")
        with self.assertRaises(sqlite3.IntegrityError): self.db.execute('DELETE FROM infra_sites WHERE id=1')
    def test_cross_project(self):
        for sql in ["INSERT INTO ops_services(project_id,site_id,name,recovery_minutes,target_bp,created_at) VALUES(1,2,'Foreign',30,9900,1)","INSERT INTO ops_service_incidents(project_id,service_id,incident_id,recovery_minutes,linked_at) VALUES(1,1,2,30,1)","INSERT INTO ops_support(project_id,service_id,incident_id,title,description,requester,created_by,created_at) VALUES(1,1,2,'ticket','description','client',1,1)","INSERT INTO ops_followups VALUES(1,1,1,2,1,1)"]:
            with self.assertRaises(sqlite3.IntegrityError): self.db.execute(sql)
    def test_slo_bounds(self):
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute('UPDATE ops_services SET target_bp=10001 WHERE id=1')
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute('UPDATE ops_services SET recovery_minutes=0 WHERE id=1')
if __name__=='__main__':unittest.main()
