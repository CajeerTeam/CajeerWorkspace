"""SQLite 007 migration: legacy rows, cross-project links, integrity and rollback."""
from pathlib import Path
import hashlib
import sqlite3
import unittest
ROOT = Path(__file__).resolve().parents[1]

class PlanningSchema(unittest.TestCase):
    def setUp(self):
        self.db = sqlite3.connect(':memory:')
        self.db.execute('PRAGMA foreign_keys=ON')
        for n in range(1, 7):
            self.db.executescript((ROOT / f'database/{n:03}.sql').read_text())
        self.db.executescript("""
        INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'owner@example.test','Owner','owner','hash');
        INSERT INTO organization_members VALUES(1,1,'owner');
        INSERT INTO organizations(id,name) VALUES(2,'Other');
        INSERT INTO projects(id,title,kind,organization_id) VALUES(1,'One','internal',1),(2,'Two','client',2),(3,'Three','internal',1);
        INSERT INTO tasks(id,project_id,title,assignee_id,due_on,state,version) VALUES(1,1,'Existing',1,'2027-01-01','doing',4),(2,1,'Existing done',NULL,NULL,'done',3),(3,2,'Foreign',NULL,NULL,'todo',1),(4,3,'Other project',NULL,NULL,'todo',1);
        INSERT INTO cash_entries(project_id,direction,amount_minor,currency,booked_on,memo,actor_id) VALUES(1,'income',12345,'PLN','2026-01-01','Payment',1);
        INSERT INTO documents(id,project_id,title,kind) VALUES(1,1,'Contract','contract');
        """)
        for rev in (1, 2):
            data = b'%PDF-1.4\n\x00\xff'+str(rev).encode()+b'\n%%EOF'
            self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(1,?,?,?,1)',(rev,hashlib.sha256(data).hexdigest(),data))
        self.db.commit()
        self.old = {table: self.db.execute('SELECT * FROM '+table).fetchall() for table in ('users','projects','tasks','cash_entries','documents','document_versions','organization_members')}
        self.sql=(ROOT/'database/007.sql').read_text()
    def tearDown(self): self.db.close()
    def upgrade(self): self.db.executescript(self.sql)
    def test_preserves_old_records(self):
        self.upgrade()
        for table, rows in self.old.items():
            select = 'id,project_id,title,assignee_id,due_on,state,version' if table=='tasks' else '*'
            self.assertEqual(rows,self.db.execute(f'SELECT {select} FROM {table}').fetchall(),table)
        self.assertEqual([(3,'','')]*4,self.db.execute('SELECT priority,result_customer,acceptance_criteria FROM tasks ORDER BY id').fetchall())
        self.assertEqual([],self.db.execute('PRAGMA foreign_key_check').fetchall())
        self.assertEqual([('ok',)],self.db.execute('PRAGMA integrity_check').fetchall())
    def test_fk_and_self_dependency(self):
        self.upgrade()
        self.db.executescript("INSERT INTO milestones(id,project_id,title) VALUES(1,1,'M'),(2,2,'Foreign'),(3,3,'Other'); INSERT INTO task_milestones VALUES(1,1,1); INSERT INTO task_dependencies VALUES(1,1,2);")
        for sql in ['INSERT INTO task_dependencies VALUES(1,1,1)','INSERT INTO task_dependencies VALUES(1,1,3)','INSERT INTO task_dependencies VALUES(1,1,4)','INSERT INTO task_dependencies VALUES(2,1,3)','INSERT INTO task_milestones VALUES(2,1,2)','INSERT INTO task_milestones VALUES(2,1,3)','INSERT INTO task_milestones VALUES(3,1,1)','UPDATE task_milestones SET milestone_id=2 WHERE task_id=1','INSERT INTO task_dependencies VALUES(1,1,2)']:
            with self.assertRaises(sqlite3.IntegrityError,msg=sql): self.db.execute(sql)
        self.assertEqual([],self.db.execute('PRAGMA foreign_key_check').fetchall())
    def test_value_constraints(self):
        self.upgrade()
        for sql,args in [('UPDATE tasks SET priority=? WHERE id=1',(0,)),('UPDATE tasks SET priority=? WHERE id=1',(5,)),('UPDATE tasks SET priority=? WHERE id=1',(2.5,)),('UPDATE tasks SET result_customer=? WHERE id=1',('x'*181,)),('UPDATE tasks SET acceptance_criteria=? WHERE id=1',('x'*4001,)),("INSERT INTO milestones(project_id,title,state) VALUES(1,'M','bogus')",())]:
            with self.assertRaises(sqlite3.IntegrityError): self.db.execute(sql,args)
    def test_transactional_ddl(self):
        self.db.executescript('BEGIN;'+self.sql+'ROLLBACK;')
        self.assertEqual(self.old['tasks'],self.db.execute('SELECT * FROM tasks').fetchall())
        self.assertIsNone(self.db.execute("SELECT name FROM sqlite_master WHERE name='milestones'").fetchone())

if __name__=='__main__': unittest.main(verbosity=2)
