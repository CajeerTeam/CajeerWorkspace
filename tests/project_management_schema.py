import sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class ProjectManagementSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.addCleanup(self.db.close);self.db.execute('PRAGMA foreign_keys=ON')
        for p in sorted((ROOT/'database').glob('*.sql')):
            if int(p.stem)<44:self.db.executescript(p.read_text())
        self.db.execute("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'owner@example.test','Owner','owner','fixture')")
        for i in [1,2]:self.db.execute('INSERT INTO projects(id,title,kind,organization_id) VALUES(?,?,?,1)',(i,'Project '+str(i),'internal'))
        for i,p in [(1,1),(2,1),(3,2)]:self.db.execute('INSERT INTO milestones(id,project_id,title,due_on) VALUES(?,?,?,?)',(i,p,'Milestone '+str(i),'2027-01-01'))
        self.before=self.db.execute('SELECT * FROM milestones ORDER BY id').fetchall();self.db.executescript((ROOT/'database/044.sql').read_text())
        self.db.execute("INSERT INTO pm_scope_versions VALUES(1,1,'Scope','{}',1,1)")
    def test_existing_rows(self):
        self.assertEqual(self.before,self.db.execute('SELECT * FROM milestones ORDER BY id').fetchall())
        self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
    def test_edges(self):
        self.db.execute('INSERT INTO pm_milestone_dependencies VALUES(1,2,1)')
        for row in [(1,2,1),(1,1,1),(1,2,3),(2,2,3)]:
            with self.assertRaises(sqlite3.IntegrityError):self.db.execute('INSERT INTO pm_milestone_dependencies VALUES(?,?,?)',row)
    def test_requests(self):
        def add(n,baseline,m,status,amount=0):
            self.db.execute('INSERT INTO pm_changes(project_id,number,baseline_version,milestone_id,title,proposed_scope,reason,calendar_before,calendar_after,amount_minor,status,actor_id,created_at) VALUES(1,?,?,?,?,?,?,?,?,?,?,1,1)',(n,baseline,m,'Change','New','Reason','{}','{}',amount,status))
        add(1,1,None,'pending');add(2,1,2,'pending')
        for n,b,m,s,a in [(1,1,2,'pending',0),(3,2,2,'pending',0),(3,1,3,'pending',0),(3,1,2,'accepted',0),(3,1,2,'applied',0),(3,1,2,'pending',1)]:
            with self.assertRaises(sqlite3.IntegrityError):add(n,b,m,s,a)
if __name__=='__main__':unittest.main()
