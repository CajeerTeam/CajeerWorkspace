"""Migration 018 keeps existing data and validates financial planning constraints."""
import sqlite3, unittest, hashlib
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class EconomicsSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:'); self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,18):self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("""INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'a@example.test','Owner','owner','hash'); INSERT INTO organization_members VALUES(1,1,'owner'); INSERT INTO organizations(id,name) VALUES(2,'Other'); INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'A','client',1),(2,'B','client',2); INSERT INTO cash_entries(id,project_id,direction,amount_minor,currency,booked_on,memo,actor_id) VALUES(1,1,'expense',25000,'EUR','2026-01-01','Legacy',1),(2,2,'expense',25000,'EUR','2026-01-01','Foreign',1); INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'Contract','contract');""")
        for rev in (1,2):
            pdf=b'%PDF-1.4\n\x00\xff'+str(rev).encode()+b'\n%%EOF';self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(1,?,?,?,1)',(rev,hashlib.sha256(pdf).hexdigest(),pdf))
        self.db.commit();self.sql=(ROOT/'database/018.sql').read_text()
    def tearDown(self): self.db.close()
    def test_preserve(self):
        tables=[r[0] for r in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")];before={t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables};self.db.executescript(self.sql);self.assertEqual(before,{t:self.db.execute(f'SELECT * FROM {t}').fetchall() for t in tables});self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[]);self.assertEqual(self.db.execute('PRAGMA integrity_check').fetchone()[0],'ok')
    def test_budget(self):
        self.db.executescript(self.sql);q='INSERT INTO economics_budgets(project_id,month_on,currency,revenue_minor,labor_minor,direct_minor,version,actor_id,changed_at) VALUES(?,?,?,?,?,?,?,?,?)';self.db.execute(q,(1,'2026-01-01','EUR',100,0,0,1,1,1))
        for row in [(1,'2026-01-01','EUR',100,0,0,1,1,1),(1,'2026-02-01','EUR',-100,0,0,1,1,1),(1,'2026-02-01','JPY',100,0,0,1,1,1)]:
            with self.assertRaises(sqlite3.IntegrityError):self.db.execute(q,row)
    def test_cash_scope(self):
        self.db.executescript(self.sql);self.db.execute("INSERT INTO economics_plans(id,project_id,direction,currency,amount_minor,due_on,title,actor_id,created_at) VALUES(1,1,'expense','EUR',30000,'2026-01-01','Plan',1,1)")
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute("INSERT INTO economics_cash_classes VALUES(2,1,'direct','bad',1,1,1)")
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute('INSERT INTO economics_plan_cash VALUES(1,1,2,1,1)')
        self.db.execute('INSERT INTO economics_plan_cash VALUES(1,1,1,1,1)')
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute('INSERT INTO economics_plan_cash VALUES(1,1,1,1,1)')
    def test_rates(self):
        self.db.executescript(self.sql);q='INSERT INTO economics_labor_rates(project_id,user_id,effective_on,currency,hourly_minor,actor_id,created_at) VALUES(?,?,?,?,?,?,?)';self.db.execute(q,(1,1,'2026-01-01','EUR',100,1,1))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute(q,(1,1,'2026-01-01','EUR',200,1,1))
        with self.assertRaises(sqlite3.IntegrityError):self.db.execute(q,(1,1,'2026-02-01','EUR',-1,1,1))
if __name__=='__main__':unittest.main()
