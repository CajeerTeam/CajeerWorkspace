import sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class MaintenanceSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.addCleanup(self.db.close);self.db.execute('PRAGMA foreign_keys=ON')
        for p in sorted((ROOT/'database').glob('*.sql')):
            if int(p.stem)<41:self.db.executescript(p.read_text())
        self.db.execute("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'owner@example.test','Owner','owner','fixture')")
        self.db.execute("INSERT INTO organization_members VALUES(1,1,'owner')")
        self.db.execute("INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'First','oss',1),(2,'Second','oss',1)")
        for i in (1,2):
            self.db.execute('INSERT INTO oss_projects(project_id,slug,upstream,public_title,description,published) VALUES(?,?,?,?,?,1)',(i,'project-'+str(i),'owner/repo','Public','Description'))
            self.db.execute('INSERT INTO oss_contributors(id,project_id,display_name,token_hash,expires_at,created_by) VALUES(?,?,?,?,1,1)',(i,i,'Author',str(i)*64))
            self.db.execute('INSERT INTO oss_contributions(id,project_id,contributor_id,title,body,upstream_url,created_at) VALUES(?,?,?,?,?,?,1)',(i,i,i,'Title','Body','https://github.com/owner/repo/issues/'+str(i)))
        old=self.db.execute('SELECT * FROM oss_contributions').fetchall(); self.db.executescript((ROOT/'database/041.sql').read_text());self.assertEqual(old,self.db.execute('SELECT * FROM oss_contributions').fetchall())
        self.db.execute("INSERT INTO oss_lines(id,project_id,label,branch) VALUES(1,1,'Main','main'),(2,1,'LTS','release/1.x'),(3,2,'Other','main')")
    def test_multiple_lines(self):
        self.db.execute("INSERT INTO oss_line_fixes(project_id,line_id,contribution_id,state,pr_number,updated_at) VALUES(1,1,1,'ready',7,1),(1,2,1,'ready',8,1)")
        for lid in (1,2):
            self.db.execute('INSERT INTO oss_line_releases(id,project_id,line_id,tag,commit_sha,github_release_id,title,notes,published_at,published_by) VALUES(?,1,?,?,?,?,?,?,1,1)',(lid,lid,'v'+str(lid),'a'*40,lid,'Public','Notes'))
            self.db.execute("UPDATE oss_line_fixes SET state='released',release_id=?,merge_sha=?,public_note='Public note' WHERE line_id=?",(lid,'b'*40,lid))
        self.assertEqual(self.db.execute('SELECT COUNT(DISTINCT contribution_id),COUNT(*) FROM oss_line_fixes').fetchone(),(1,2))
        self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute('UPDATE oss_line_fixes SET release_id=2 WHERE line_id=1')
    def test_cross_project_and_incomplete(self):
        q='INSERT INTO oss_line_fixes(project_id,line_id,contribution_id,state,updated_at) VALUES(1,?,?,?,1)'
        for args in [(3,1,'planned'),(1,2,'planned'),(1,1,'ready'),(1,1,'released')]:
            with self.assertRaises(sqlite3.IntegrityError):self.db.execute(q,args)
if __name__=='__main__':unittest.main()
