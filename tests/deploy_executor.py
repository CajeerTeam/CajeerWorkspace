"""Real ZIP, filesystem, symlink, local HTTP readiness and crash journal tests."""
import hashlib
import http.server
import importlib.util
import json
import subprocess
import os
from pathlib import Path
import tempfile
import threading
import time
import unittest
import zipfile

spec=importlib.util.spec_from_file_location('executor',Path(__file__).resolve().parents[1]/'tools/deploy.py')
d=importlib.util.module_from_spec(spec);spec.loader.exec_module(d)

class ExecutorTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.base=Path(self.tmp.name);self.root=self.base/'root';self.spool=self.base/'spool';self.root.mkdir();self.spool.mkdir();self.cfg=self.base/'config.json';self.identity={'site_id':1,'project_id':1,'provider':'aapanel'};self.bad=set();self.rootref=self.root;outer=self
        class Health(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                meta=d.current(outer.rootref);payload=json.dumps({'ready':meta['commit'] not in outer.bad,'commit_sha':meta['commit']}).encode();self.send_response(200);self.end_headers();self.wfile.write(payload)
            def log_message(self,*args): pass
        self.server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Health);self.thread=threading.Thread(target=self.server.serve_forever,daemon=True);self.thread.start();self.url='http://127.0.0.1:'+str(self.server.server_port)+'/health'
        self.cfg.write_text(json.dumps({'targets':{'1':{'identity':self.identity,'root':str(self.root),'spool':str(self.spool),'health_url':self.url}}}));d.target(self.cfg,self.identity)
        self.old=self.artifact('a'*40);d.stage(self.root,self.spool,self.old['digest'],self.old['commit'],self.old['size']);d.switch(self.root,self.old['digest'])
    def tearDown(self):
        self.server.shutdown();self.server.server_close();self.thread.join();self.tmp.cleanup()
    def artifact(self,commit,name='index.php'):
        p=self.spool/'new.zip'
        with zipfile.ZipFile(p,'w') as z:z.writestr(name,commit)
        digest=hashlib.sha256(p.read_bytes()).hexdigest();size=p.stat().st_size;p.rename(self.spool/(digest+'.zip'));return {'commit':commit,'digest':digest,'size':size}
    def job(self,commit='b'*40,name='index.php'):
        return dict(self.artifact(commit,name),key='1'*64,target=self.identity,deadline=int(time.time())+60)
    def test_success_and_replay(self):
        job=self.job();r=d.execute(self.cfg,job);self.assertEqual(r['state'],'succeeded');self.assertEqual(d.current(self.root)['commit'],job['commit']);self.assertEqual(d.execute(self.cfg,job),r);self.assertTrue((self.root/'releases'/self.old['digest']).is_dir())
    def test_php_executor_bridge(self):
        job=self.job();root=Path(__file__).resolve().parents[1];code="require 'app/bootstrap.php'; echo json_encode(Workspace\\deploymentExecute($argv[1],json_decode(stream_get_contents(STDIN),true),false));"
        proc=subprocess.run(['php','-r',code,str(self.cfg)],cwd=root,input=json.dumps(job),text=True,capture_output=True,check=True,env={**os.environ,'WORKSPACE_DEPLOY_PYTHON':__import__('sys').executable})
        self.assertEqual(json.loads(proc.stdout)['state'],'succeeded');self.assertEqual(d.current(self.root)['commit'],job['commit'])
    def test_tampered_staging(self):
        job=self.job();d.stage(self.root,self.spool,job['digest'],job['commit'],job['size']);(self.root/'releases'/job['digest']/'index.php').write_text('tampered');r=d.execute(self.cfg,job);self.assertEqual(r['state'],'failed');self.assertEqual(d.current(self.root)['commit'],self.old['commit'])
    def test_failed_health_rollback(self):
        job=self.job();self.bad.add(job['commit']);r=d.execute(self.cfg,job);self.assertEqual(r['state'],'rolled_back');self.assertEqual(d.current(self.root)['commit'],self.old['commit']);self.assertEqual(r['before'],r['after'])
    def test_corrupt_and_traversal(self):
        job=self.job(name='../escape');self.assertEqual(d.execute(self.cfg,job)['state'],'failed');self.assertFalse((self.root/'escape').exists());self.assertEqual(d.current(self.root)['commit'],self.old['commit'])
        job=self.job(commit='c'*40);job['key']='2'*64;p=self.spool/(job['digest']+'.zip');p.write_bytes(b'x'*job['size']);self.assertEqual(d.execute(self.cfg,job)['state'],'failed')
    def test_window_and_scope(self):
        job=self.job();job['deadline']=0;self.assertEqual(d.execute(self.cfg,job)['state'],'failed');job['target']={'site_id':1,'project_id':2,'provider':'aapanel'}
        with self.assertRaises(d.DeploymentError):d.execute(self.cfg,job)
    def test_crash_after_switch(self):
        job=self.job();d.stage(self.root,self.spool,job['digest'],job['commit'],job['size']);d.write_json(self.root/'journal'/(job['key']+'.json'),{'key':job['key'],'target':job['target'],'before':d.current(self.root),'after':{'commit':job['commit'],'digest':job['digest']}});d.switch(self.root,job['digest']);r=d.execute(self.cfg,job,True);self.assertEqual(r['state'],'rolled_back');self.assertEqual(d.current(self.root)['commit'],self.old['commit'])
    def test_missing_journal_and_lock(self):
        job=self.job();self.assertEqual(d.execute(self.cfg,job,True)['state'],'recovery_required')
        with d.lock(self.root):
            with self.assertRaises(d.DeploymentError):d.execute(self.cfg,job)
    def test_rollback_failure_blocks(self):
        job=self.job();d.stage(self.root,self.spool,job['digest'],job['commit'],job['size']);d.write_json(self.root/'journal'/(job['key']+'.json'),{'key':job['key'],'target':job['target'],'before':d.current(self.root),'after':{'commit':job['commit'],'digest':job['digest']}});d.switch(self.root,job['digest']);self.bad.add(self.old['commit']);self.assertEqual(d.execute(self.cfg,job,True)['state'],'recovery_required');self.bad.clear();self.assertEqual(d.execute(self.cfg,job,True)['state'],'rolled_back')

if __name__=='__main__':unittest.main()
