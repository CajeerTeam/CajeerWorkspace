import sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class PortfolioSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.addCleanup(self.db.close);self.db.execute('PRAGMA foreign_keys=ON')
        for p in sorted((ROOT/'database').glob('*.sql')):
            if int(p.stem)<43:self.db.executescript(p.read_text())
        self.db.execute("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'owner@example.test','Owner','owner','fixture')")
        self.db.execute("INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'First','internal',1)")
        self.db.execute("INSERT INTO economics_budgets VALUES(1,1,'2026-01-01','EUR',100,20,10,7,1,1)")
        self.before=self.db.execute('SELECT * FROM economics_budgets').fetchall()
        self.db.executescript((ROOT/'database/043.sql').read_text())
    def test_seed(self):
        self.assertEqual(self.before,self.db.execute('SELECT * FROM economics_budgets').fetchall())
        self.assertEqual(self.db.execute('SELECT * FROM portfolio_budget_versions').fetchall(),[(1,7,100,20,10,1,1)])
        self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
    def test_constraints(self):
        for sql in ['INSERT INTO portfolio_budget_versions VALUES(1,7,0,0,0,1,1)','INSERT INTO portfolio_budget_versions VALUES(9,8,0,0,0,1,1)','INSERT INTO portfolio_budget_versions VALUES(1,8,-1,0,0,1,1)',"INSERT INTO portfolio_decisions VALUES(1,1,'invalid','plan','g','s','e','r',1,1,'2026-01-01','2026-02-01','{}')"]:
            with self.assertRaises(sqlite3.IntegrityError):self.db.execute(sql)
if __name__=='__main__':unittest.main()
