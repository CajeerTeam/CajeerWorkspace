"""Migration 009: preservation, organization and project constraints, and immutable decision metadata."""
import sqlite3
import hashlib
import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

class ClientsSchema(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:'); self.db.execute('PRAGMA foreign_keys=ON')
        for i in range(1,9): self.db.executescript((ROOT/f'database/{i:03}.sql').read_text())
        self.db.executescript("""
        INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'owner@example.test','Owner','owner','hash'),(2,'dev@example.test','Dev','developer','hash');
        INSERT INTO organization_members VALUES(1,1,'owner'),(1,2,'developer');
        INSERT INTO organizations(id,name) VALUES(2,'Foreign');
        INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'Client','client',1),(2,'Foreign','client',2),(3,'Other','client',1);
        INSERT INTO tasks(id,project_id,title) VALUES(1,1,'Task'),(2,2,'Foreign');
        INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'Contract','contract'),(2,2,'Other','contract');
        INSERT INTO cash_entries(project_id,direction,amount_minor,currency,booked_on,memo,actor_id) VALUES(1,'income',12345,'EUR','2026-01-01','Old',1);
        INSERT INTO timesheets(id,project_id,user_id,week_on) VALUES(1,1,2,'2024-12-30');
        INSERT INTO time_entries(id,project_id,sheet_id,task_id,worked_on,minutes,note) VALUES(1,1,1,1,'2024-12-30',60,'Old');
        """)
        for doc in (1,2):
            for v in (1,2):
                b=b'%PDF-1.4\n\x00\xff'+str(v).encode()+b'\n%%EOF'
                self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(?,?,?,?,1)',(doc,v,hashlib.sha256(b).hexdigest(),b))
        self.db.execute('INSERT INTO guest_invites(id,organization_id,project_id,document_id,revision,email,token_hash,created_by,expires_at) VALUES(1,1,1,1,1,?,?,1,100)',('a@example.test','a'*64))
        self.db.commit()
        self.old={t:self.db.execute('SELECT * FROM '+t).fetchall() for t in ('users','projects','tasks','documents','document_versions','cash_entries','timesheets','time_entries','guest_invites')}
        self.sql=(ROOT/'database/009.sql').read_text()
    def tearDown(self): self.db.close()
    def setup_orders(self):
        self.db.executescript(self.sql)
        self.db.executescript("""
        INSERT INTO counterparties(id,organization_id,name) VALUES(1,1,'Client'),(2,2,'Foreign');
        INSERT INTO counterparty_contacts(id,organization_id,counterparty_id,name,email) VALUES(1,1,1,'Alice','a@example.test'),(2,2,2,'Other','b@example.test');
        INSERT INTO client_orders(id,organization_id,project_id,counterparty_id,contact_id,title,request_text,client_name,contact_name,contact_email,created_by,created_at) VALUES(1,1,1,1,1,'Request','Description','Client','Alice','a@example.test',1,1),(2,2,2,2,2,'Private','Description','Foreign','Other','b@example.test',1,1);
        INSERT INTO client_offers(id,order_id,revision,scope,currency) VALUES(1,1,1,'Scope','EUR'),(2,2,1,'Private','EUR');
        INSERT INTO client_stages(id,order_id,offer_id,project_id,title,scope,due_on,amount_minor) VALUES(1,1,1,1,'Stage','Scope','2026-01-01',100),(2,2,2,2,'Other','Scope','2026-01-01',100);
        """)
    def test_preservation(self):
        self.db.executescript(self.sql)
        for t,rows in self.old.items(): self.assertEqual(rows,self.db.execute('SELECT * FROM '+t).fetchall())
        self.assertEqual([],self.db.execute('PRAGMA foreign_key_check').fetchall())
        self.assertEqual([('ok',)],self.db.execute('PRAGMA integrity_check').fetchall())
    def test_isolation(self):
        self.setup_orders()
        for sql in ["INSERT INTO counterparty_contacts(organization_id,counterparty_id,name,email) VALUES(1,2,'X','x@example.test')", "INSERT INTO client_orders(organization_id,project_id,counterparty_id,contact_id,title,request_text,client_name,contact_name,contact_email,created_by,created_at) VALUES(1,2,1,1,'X','X','X','X','x@example.test',1,1)","INSERT INTO client_stages(order_id,offer_id,project_id,title,scope,due_on,amount_minor) VALUES(1,2,1,'X','X','2026-01-01',100)","INSERT INTO client_stage_tasks VALUES(1,1,2)","INSERT INTO client_order_guests VALUES(1,2,2)","INSERT INTO client_deliveries(stage_id,project_id,revision,result_text,document_id,document_revision,submitted_by,submitted_at) VALUES(1,1,1,'Result',2,1,1,1)"]:
            with self.assertRaises(sqlite3.IntegrityError,msg=sql): self.db.execute(sql)
        self.db.execute('INSERT INTO client_order_guests VALUES(1,1,1)')
        self.db.execute('INSERT INTO client_stage_tasks VALUES(1,1,1)')
        self.assertEqual([],self.db.execute('PRAGMA foreign_key_check').fetchall())
    def test_decisions_and_money(self):
        self.setup_orders()
        for sql in ["UPDATE client_offers SET status='accepted' WHERE id=1", "INSERT INTO client_offers(order_id,revision,scope,currency) VALUES(1,2,'Other','EUR')", "UPDATE client_stages SET amount_minor=1.25 WHERE id=1", "UPDATE client_stages SET amount_minor=0 WHERE id=1"]:
            with self.assertRaises(sqlite3.IntegrityError,msg=sql): self.db.execute(sql)
        self.db.execute("INSERT INTO client_deliveries(id,stage_id,project_id,revision,result_text,submitted_by,submitted_at) VALUES(1,1,1,1,'Result',1,1)")
        for sql in ["UPDATE client_deliveries SET status='accepted' WHERE id=1", "INSERT INTO client_deliveries(stage_id,project_id,revision,result_text,submitted_by,submitted_at) VALUES(1,1,2,'Again',1,2)","INSERT INTO client_stage_hours VALUES(1,1,1441)"]:
            with self.assertRaises(sqlite3.IntegrityError,msg=sql): self.db.execute(sql)
    def test_transaction_rollback(self):
        with self.assertRaises(sqlite3.Error): self.db.executescript('BEGIN IMMEDIATE;\n'+self.sql+'\nSELECT * FROM missing_table;')
        self.db.rollback()
        self.assertIsNone(self.db.execute("SELECT name FROM sqlite_master WHERE name='client_orders'").fetchone())
        for t,rows in self.old.items(): self.assertEqual(rows,self.db.execute('SELECT * FROM '+t).fetchall())

if __name__=='__main__': unittest.main()
