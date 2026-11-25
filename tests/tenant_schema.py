"""SQLite migration/data acceptance independent of PHP extension availability."""
import hashlib
import pathlib
import sqlite3
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

class TenantSchema(unittest.TestCase):
    def setUp(self):
        self.db = sqlite3.connect(':memory:')
        self.db.execute('PRAGMA foreign_keys=ON')
        for version in range(1, 4):
            self.db.executescript((ROOT / f'database/{version:03}.sql').read_text())
        self.db.executescript("""
            INSERT INTO users(id,email,name,role,password_hash) VALUES(1,'one@test.example','One','owner','hash'),(2,'two@test.example','Two','developer','hash');
            INSERT INTO projects(id,title,kind) VALUES(5,'Existing','oss');
            INSERT INTO tasks(id,project_id,title,assignee_id) VALUES(1,5,'Task',2);
            INSERT INTO documents(id,project_id,title,kind) VALUES(7,5,'Document','nda');
            INSERT INTO cash_entries(project_id,direction,amount_minor,currency,booked_on,memo,actor_id) VALUES(5,'income',12345,'PLN','2026-01-01','Payment',1);
            INSERT INTO connection_results VALUES('engine','hash',1,'OK',123);
            INSERT INTO project_connections VALUES(5,'engine');
        """)
        for rev in (1, 2):
            data = b'%PDF-1.4\n\x00\xff' + str(rev).encode() + b'\n%%EOF\n'
            self.db.execute('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(7,?,?,?,1)', (rev, hashlib.sha256(data).hexdigest(), data))
        self.db.commit()
        self.original = {t: self.db.execute('SELECT * FROM ' + t).fetchall() for t in ('users','tasks','cash_entries','documents','document_versions','project_connections','connection_results')}
        self.db.executescript((ROOT / 'database/004.sql').read_text())

    def tearDown(self):
        self.db.close()

    def test_preserves_exact_rows_and_pdf(self):
        for table, rows in self.original.items():
            self.assertEqual(rows, self.db.execute('SELECT * FROM ' + table).fetchall(), table)
        self.assertEqual([(5,1)], self.db.execute('SELECT id,organization_id FROM projects').fetchall())
        self.assertEqual([(1,5,1),(1,5,2)], self.db.execute('SELECT * FROM project_members ORDER BY user_id').fetchall())
        self.assertEqual([('engine',1)], self.db.execute('SELECT * FROM organization_connections').fetchall())
        self.assertEqual([],self.db.execute('PRAGMA foreign_key_check').fetchall())
        self.assertEqual([('ok',)],self.db.execute('PRAGMA integrity_check').fetchall())

    def test_cross_tenant_fk(self):
        self.db.executescript("INSERT INTO organizations(id,name) VALUES(2,'Second'); INSERT INTO projects(id,title,kind,organization_id) VALUES(6,'Secret','internal',2); INSERT INTO teams(id,organization_id,name) VALUES(1,2,'Team');")
        for sql in ["INSERT INTO project_members VALUES(1,6,1)","INSERT INTO project_members VALUES(2,6,1)","INSERT INTO team_members VALUES(1,1,1)","INSERT INTO team_members VALUES(2,1,1)","INSERT INTO projects(title,kind,organization_id) VALUES('Invalid','internal',999)","UPDATE projects SET organization_id=2 WHERE id=5"]:
            with self.assertRaises(sqlite3.IntegrityError):
                self.db.execute(sql)
        self.assertEqual(0,self.db.execute('SELECT COUNT(*) FROM project_members WHERE project_id=6').fetchone()[0])

    def test_explicit_membership(self):
        self.db.executescript("INSERT INTO projects(title,kind,organization_id) VALUES('Closed','internal',1); INSERT INTO teams(organization_id,name) VALUES(1,'Group'); INSERT INTO team_members VALUES(1,1,2);")
        closed = self.db.execute("SELECT id FROM projects WHERE title='Closed'").fetchone()[0]
        scope = 'SELECT p.id FROM projects p WHERE p.organization_id=? AND EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id=p.id AND pm.organization_id=p.organization_id AND pm.user_id=?)'
        self.assertEqual([(5,)], self.db.execute(scope,(1,2)).fetchall())
        self.db.execute('INSERT INTO project_members VALUES(1,?,2)', (closed,))
        self.assertEqual({5,closed},{r[0] for r in self.db.execute(scope,(1,2))})
        self.db.execute('DELETE FROM project_members WHERE project_id=? AND user_id=2',(closed,))
        self.assertEqual([(5,)], self.db.execute(scope,(1,2)).fetchall())

if __name__=='__main__':
    unittest.main(verbosity=2)
