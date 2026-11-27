import sqlite3, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class FreelanceSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:');self.addCleanup(self.db.close);self.db.execute('PRAGMA foreign_keys=ON')
        for p in sorted((ROOT/'database').glob('*.sql')):
            if int(p.stem)<46:self.db.executescript(p.read_text())
        self.db.execute("INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'owner@example.test','Owner','owner','fixture')")
        self.db.execute("INSERT INTO organization_members VALUES(1,1,'owner')")
        self.db.execute("INSERT INTO organizations(id,name) VALUES(2,'Other')")
        self.db.execute("INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'P','client',1),(2,'X','client',2)")
        self.db.execute("INSERT INTO counterparties(id,organization_id,name) VALUES(1,1,'Client'),(2,2,'Foreign')")
        self.db.execute("INSERT INTO counterparty_contacts(id,organization_id,counterparty_id,name,email) VALUES(1,1,1,'Client','client@example.test'),(2,2,2,'X','x@example.test')")
        self.db.execute("INSERT INTO client_orders(id,organization_id,project_id,counterparty_id,contact_id,title,request_text,client_name,contact_name,contact_email,created_by,created_at) VALUES(1,1,1,1,1,'Order','Request','Client','Client','client@example.test',1,1),(2,2,2,2,2,'X','X','X','X','x@example.test',1,1)")
        self.db.execute("INSERT INTO client_offers(id,order_id,revision,scope,currency) VALUES(1,1,1,'Scope','EUR'),(2,2,1,'X','EUR')")
        self.before=self.db.execute('SELECT * FROM client_orders').fetchall();self.db.executescript((ROOT/'database/046.sql').read_text())
        self.package=(1,1,'Package','Scope','EUR',10000,3000,1000,'Service',14,1,1)
    def test_additive(self):
        self.assertEqual(self.before,self.db.execute('SELECT * FROM client_orders').fetchall());self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
        for name in ['freelance_packages','freelance_agreements','freelance_bills']:self.assertEqual(self.db.execute('SELECT COUNT(*) FROM '+name).fetchone()[0],0)
    def test_package(self):
        self.db.execute('INSERT INTO freelance_packages VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',self.package)
        for change in [{1:2,6:10001},{1:2,7:0},{1:2,9:91}]:
            v=list(self.package)
            for k,x in change.items():v[k]=x
            with self.assertRaises(sqlite3.IntegrityError):self.db.execute('INSERT INTO freelance_packages VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',v)
    def test_tenant(self):
        self.db.execute('INSERT INTO freelance_packages VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',self.package)
        self.db.execute("INSERT INTO freelance_agreements(offer_id,order_id,project_id,organization_id,package_number,first_service_on) VALUES(1,1,1,1,1,'2027-01-31')")
        for row in [(2,2,2,1,1),(2,2,2,2,1),(2,1,1,1,1)]:
            with self.assertRaises(sqlite3.IntegrityError):self.db.execute('INSERT INTO freelance_agreements(offer_id,order_id,project_id,organization_id,package_number) VALUES(?,?,?,?,?)',row)
        self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(),[])
if __name__=='__main__':unittest.main()
