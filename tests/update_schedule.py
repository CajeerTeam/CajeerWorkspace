#!/usr/bin/env python3
"""Scheduler policy, signed rollout gates and real persisted orchestration state."""
import datetime as dt
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
import update_schedule as s
import recovery

class ScheduleTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.t=tempfile.TemporaryDirectory();cls.addClassCleanup(cls.t.cleanup);cls.base=Path(cls.t.name)
        cls.key=cls.base/'key';cls.pub=cls.base/'pub';
        subprocess.run(['openssl','genpkey','-algorithm','RSA','-pkeyopt','rsa_keygen_bits:2048','-out',cls.key],check=True,capture_output=True)
        subprocess.run(['openssl','pkey','-in',cls.key,'-pubout','-out',cls.pub],check=True,capture_output=True)
        cls.key.chmod(0o600)
    def setUp(self):
        self.t2=tempfile.TemporaryDirectory(dir=self.base);self.addCleanup(self.t2.cleanup);self.state=Path(self.t2.name)
        self.c={'root':str(self.state),'archive_dir':str(self.state),'approval_dir':str(self.state),'approval_key':str(self.pub),'approval_pin':recovery.digest(self.pub),'role':'pilot','channel':'stable','timezone':'Europe/Warsaw','days':[1,2,3,4,5,6,7],'start':'00:00','end':'23:59','hooks':{n:'/usr/bin/true' for n in s.ACTIONS},'stop_units':[],'pg_restore_database':None}
        self.st=s.default_state();self.st['enabled']=True
        self.m={'version':'1.2.4','channel':'stable','commit':'a'*40,'package':{'sha256':'b'*64},'release_key_sha256':'c'*64,'supported_from':[]}
        self.i=s.identity(self.m);self.tx='d'*32;self.calls=[];self.phases=[]
        self.patches=[patch.object(s,'in_window',return_value=True),patch.object(s,'cli',side_effect=self.cli),patch.object(s,'source_archive',return_value=self.state/'previous.zip'),patch.object(s,'hook',side_effect=self.hook),patch.object(s.ui,'install',side_effect=self.install),patch.dict(os.environ,{'WORKSPACE_DATA':str(self.state/'data'),'WORKSPACE_OBJECT_DIR':str(self.state/'objects'),'WORKSPACE_UPDATE_KEY_SHA256':'c'*64})]
    def cli(self,c,env,fd,action,*args):
        self.calls.append(action)
        return {'status':{'check':'compatible','download':'downloaded','plan':'planned'}[action],'release':self.m,'installed_version':'1.2.3','installed_schema':36,'package_result':{'metadata_sha256':self.i['metadata_sha256']}}
    def hook(self,c,name,info,env=None): self.calls.append(name)
    def install(self,args):
        self.phases.append(args.action);return {'status':'switched' if args.action=='install' else 'completed','current':self.m['version'],'transaction':self.tx}
    def start_patches(self):
        for p in self.patches: p.start();self.addCleanup(p.stop)
    def run_tick(self): return s.run(self.state,self.st,self.c,1,'e'*64)['status']
    def test_config_opt_in(self):
        self.assertFalse(s.default_state()['enabled']);self.assertEqual(s.config(self.c),self.c)
        for changes in ({'channel':'other'},{'days':[0]},{'start':'24:00'},{'end':'00:00'},{'timezone':'No/Zone'},{'hooks':{}},{'approval_pin':'0'*64}):
            with self.assertRaises((ValueError,KeyError)):s.config({**self.c,**changes})
    def test_window_midnight_dst(self):
        c={**self.c,'days':[6],'start':'23:00','end':'03:30'}
        # Warsaw DST fall-back: both local 02:15 instants belong to Saturday's overnight window.
        for h in (0,1):self.assertTrue(s.in_window(c,dt.datetime(2026,10,25,h,15,tzinfo=dt.timezone.utc)))
        self.assertFalse(s.in_window(c,dt.datetime(2026,10,25,2,30,tzinfo=dt.timezone.utc)))
        c={**self.c,'days':[7],'start':'02:00','end':'03:00'}
        self.assertFalse(s.in_window(c,dt.datetime(2026,3,29,1,0,tzinfo=dt.timezone.utc)))
    def test_disabled_paused_skip(self):
        self.start_patches();self.st['enabled']=False;self.assertEqual(self.run_tick(),'disabled');self.st['enabled']=True;self.st['paused']=True;self.assertEqual(self.run_tick(),'paused');self.assertEqual(self.calls,[])
        self.st['paused']=False;self.st['skipped']=['1.2.4'];self.assertEqual(self.run_tick(),'skipped');self.assertEqual(self.calls,['check'])
    def test_follower_blocked(self):
        self.start_patches();self.c['role']='follower';self.assertEqual(self.run_tick(),'waiting_for_pilot');self.assertEqual(self.calls,['check']);self.assertFalse(self.phases)
    def test_pilot_install_and_signed_approval(self):
        self.start_patches();self.assertEqual(self.run_tick(),'pilot_healthy');self.assertEqual(self.calls,['check','download','plan','quiesce','start','health','open']);self.assertEqual(self.phases,['install','finish']);self.assertFalse(self.st['pending'])
        j={'phase':'completed','id':self.tx,'metadata_sha256':self.i['metadata_sha256'],'package_sha256':self.i['package_sha256'],'target':self.i['version']}
        with patch.object(s,'active_journal',return_value=j),patch.object(s.ui,'current',return_value=self.i['version']):
            self.assertEqual(s.approve(self.state,self.st,self.c,self.key)['status'],'pilot_approved')
        self.assertTrue(s.approval(self.c,self.i,s.now()));self.assertFalse(s.approval(self.c,self.i,s.now()+dt.timedelta(days=8)))
        self.assertFalse(s.approval(self.c,{**self.i,'package_sha256':'e'*64},s.now()))
        self.c['role']='follower';self.calls.clear();self.phases.clear();self.assertEqual(self.run_tick(),'updated');self.assertEqual(self.phases,['install','finish'])
        s.revoke(self.c,self.i);self.assertFalse(s.approval(self.c,self.i,s.now()))
    def test_failure_after_finish_quiesces_no_rollback(self):
        self.start_patches()
        def fail(c,n,i,env=None):
            self.calls.append(n)
            if n=='health':raise ValueError('health failed')
        with patch.object(s,'hook',side_effect=fail):self.assertEqual(self.run_tick(),'recovery_required')
        self.assertTrue(self.st['paused']);self.assertEqual(self.st['pending']['stage'],'health');self.assertEqual(self.phases,['install','finish']);self.assertEqual(self.calls[-2:],['health','quiesce']);self.assertFalse(s.approval(self.c,self.i,s.now()))
        self.assertEqual(s.load(self.state)['pending']['stage'],'health');self.assertEqual(self.run_tick(),'recovery_required')
    def test_failure_during_install_never_finish(self):
        self.start_patches()
        with patch.object(s.ui,'install',side_effect=ValueError('migration failed')):self.assertEqual(self.run_tick(),'recovery_required')
        self.assertTrue(self.st['paused']);self.assertEqual(self.st['pending']['stage'],'install');self.assertEqual(self.calls[-1],'quiesce');self.assertNotIn('start',self.calls)
    def test_changed_plan_no_writes(self):
        self.start_patches()
        original=self.cli
        def changed(c,e,f,a,*args):
            r=original(c,e,f,a,*args)
            if a=='plan':r['package_result']['metadata_sha256']='0'*64
            return r
        with patch.object(s,'cli',side_effect=changed):self.assertEqual(self.run_tick(),'check_failed')
        self.assertNotIn('quiesce',self.calls);self.assertIsNone(self.st['pending'])
    def test_outside_window_and_lock(self):
        self.start_patches()
        with patch.object(s,'in_window',side_effect=[True,False]):self.assertEqual(self.run_tick(),'outside_window')
        self.assertFalse(self.phases)
        with s.ui.exclusive(self.state/'schedule.lock'):
            with self.assertRaises(BlockingIOError):
                with s.ui.exclusive(self.state/'schedule.lock'):pass
    def test_notification_and_public_report(self):
        self.c['hooks']['notify']='/usr/bin/false'
        s.event(self.state,self.st,self.c,'enabled')
        self.assertTrue(self.st['notification_failed']);p=self.state/'schedule-report.json';self.assertEqual(p.stat().st_mode&0o777,0o640);self.assertNotIn('hooks',p.read_text());self.assertNotIn('approval_key',p.read_text());self.assertEqual(len(self.st['events']),1)
        s.event(self.state,self.st,self.c,'enabled');self.assertEqual(len(self.st['events']),1)

if __name__=='__main__':unittest.main()
