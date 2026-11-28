import re, sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class CapacitySchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.addCleanup(self.db.close);self.db.execute('PRAGMA foreign_keys=ON')
        for p in sorted((ROOT/'database').glob('*.sql')):
            if int(p.stem)<45:self.db.executescript(p.read_text())
        self.db.execute("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'owner@example.test','Owner','owner','fixture')")
        self.db.execute("INSERT INTO organization_members VALUES(1,1,'owner')")
        self.db.execute("INSERT INTO organizations(id,name) VALUES(2,'Other')")
        self.db.execute("INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'P','internal',1),(2,'X','internal',2)")
        self.db.execute("INSERT INTO teams(id,organization_id,name) VALUES(1,1,'A'),(2,2,'X')")
        self.db.execute("INSERT INTO project_availability VALUES(1,1,'2027-01-04',480,1)")
        self.before=self.db.execute('SELECT * FROM project_availability').fetchall()
        self.db.executescript((ROOT/'database/045.sql').read_text())
    def test_additive(self):
        self.assertEqual(self.before,self.db.execute('SELECT * FROM project_availability').fetchall());self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM capacity_weeks').fetchone()[0],0)
    def test_calendar(self):
        row=(1,1,'2027-01-04',480,480,0,480,480,0,0,'Leave',1,1)
        self.db.execute('INSERT INTO capacity_weeks VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',row)
        for r in [row,(1,1,'2027-01-11',1441,*row[4:]),(1,1,'2027-01-11',-1,*row[4:])]:
            with self.assertRaises(sqlite3.IntegrityError):self.db.execute('INSERT INTO capacity_weeks VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',r)
    def test_calendar_production_upsert(self):
        source=(ROOT/'app/Capacity.php').read_text()
        sql=re.search(r"query\(\$db,'(INSERT INTO capacity_weeks[^']+)'",source).group(1)
        # PDO execute(array) binds strings by default; exercise the same input shape.
        first=(1,1,'2027-01-04',60,120,180,240,300,360,420,'Initial',1,100)
        updated=(1,1,'2027-01-04',0,120,180,240,300,360,420,'Leave',1,200)
        for row in (first,updated,updated):
            self.db.execute(sql,tuple(map(str,row)))
            self.assertEqual(self.db.execute('SELECT * FROM capacity_weeks').fetchall(),[row])
        self.assertEqual(self.before,self.db.execute('SELECT * FROM project_availability').fetchall())
        self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
    def test_attribution(self):
        self.db.execute("INSERT INTO capacity_teams VALUES(1,1,1,'2027-01-04',1)")
        for r in [(2,1,1,'2027-01-04',2),(1,1,1,'2027-01-04',2),(1,1,1,'2027-01-11',1),(1,2,1,'2027-01-04',1)]:
            with self.assertRaises(sqlite3.IntegrityError):self.db.execute('INSERT INTO capacity_teams VALUES(?,?,?,?,?)',r)
        self.db.execute('DELETE FROM teams WHERE id=1');self.assertEqual(self.db.execute('SELECT COUNT(*) FROM capacity_teams').fetchone()[0],0);self.assertEqual(self.before,self.db.execute('SELECT * FROM project_availability').fetchall())
if __name__=='__main__':unittest.main()
