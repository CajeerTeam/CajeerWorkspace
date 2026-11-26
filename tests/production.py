"""Exercise the full admission/signature gate with disposable keys and synthetic reports.
Fixtures never constitute actual production approval. No live provider is called.
"""
import copy
import datetime as dt
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
import production as prod
import release
import recovery
import candidate

class ProductionTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp=tempfile.TemporaryDirectory(); cls.addClassCleanup(cls.tmp.cleanup); cls.base=Path(cls.tmp.name)
        cls.archive=release.build(cls.base/'dist');cls.matrix=prod.matrix(cls.archive)[0]
        cls.registry=cls.base/'registry';cls.registry.mkdir(mode=0o700);cls.keys=cls.base/'keys';cls.keys.mkdir(mode=0o700)
        registry={}
        for role in prod.KEYS:
            key=cls.keys/(role+'.pem');pub=cls.registry/(role+'.pub.pem')
            subprocess.run(['openssl','genpkey','-algorithm','RSA','-pkeyopt','rsa_keygen_bits:2048','-out',str(key)],check=True,capture_output=True);key.chmod(0o600)
            subprocess.run(['openssl','pkey','-in',str(key),'-pubout','-out',str(pub)],check=True,capture_output=True)
            registry[role]={'identity':'Test '+role,'sha256':recovery.digest(pub)}
        (cls.registry/'trust.json').write_bytes(recovery.canonical(registry));cls.pin=recovery.digest(cls.registry/'trust.json');cls.prev='a'*64

    def setUp(self):
        self.tmp2=tempfile.TemporaryDirectory(dir=self.base);self.addCleanup(self.tmp2.cleanup);self.root=Path(self.tmp2.name);self.evidence=self.root/'evidence';self.evidence.mkdir(mode=0o700)
        now=dt.datetime.now(dt.timezone.utc).isoformat();m=self.matrix
        t={'version':prod.VERSION,'schema':34,'topology_ready':True,'guest_ready':True,'issues':[],'projects':{'internal':1,'client':2,'oss':3},'staff':{str(i):i for i in range(8)},'teams':{'a':1,'b':2},'guest_active_ids':[1],'checked_at':now}
        p={'version':prod.VERSION,'mode':'live_readiness','ready':True,'connections':{}}
        v={'version':prod.VERSION,'archive_sha256':recovery.digest(self.archive),'previous_sha256':self.prev,'checked_at':now,'stand':'Synthetic test fixture','tax_profile':'management_cash_only','signature_provider':'dropbox_sign_v3','backend':'sqlite','php_version':'8.4.24','database_version':'3.40.1','owners':{name:'Test operator '+name for name in ('workspace','release','security','database','finance','legal',*m['integrations'])},'integrations':{},'scenarios':{k:{'passed':True,'evidence_ref':'synthetic scenario'} for k in prod.SCENARIOS},'checks':{k:{'passed':True,'evidence_ref':'synthetic check'} for k in prod.CHECKS},'ci':{'run':'synthetic-run','commit':'b'*40,'jobs':{k:'success' for k in prod.CI}},'thresholds':{'minimum_requests':100,'minimum_concurrency':5,'p95_ms':100,'error_rate':0,'rto_seconds':60,'rpo_seconds':300},'open_critical_defects':0}
        for k,s in m['integrations'].items():
            version=s['version'] or '7.0.0';row={'ready':True,'status':'ready','observed_version':version,'declared_version':version,'target_hash':'c'*64,'checked_at':now}
            evidence='observed'
            if k=='github':row.update(repository='test/oss',default_branch='main',observed_version=None,declared_version=None);evidence='api_contract'
            if k=='lite':row.update(observed_version=None,status='declared_version_only');evidence='operator_attested'
            p['connections'][k]=row
            v['integrations'][k]={'version':version,'version_evidence':evidence,'target_hash':row['target_hash'],'capabilities':{x:True for x in s['capabilities']},'evidence_ref':'synthetic provider evidence'}
        sample={'requests':100,'success':100,'errors':{},'p95_ms':15,'error_rate':0}
        l={'status':'passed','requests':100,'concurrency':5,'total':sample,'routes':{'overview':copy.deepcopy(sample)}}
        r={'status':'restored_verified_offline','backend':'sqlite','cash_balances_match':True,'ledger_balances_match':True,'scope_and_queue_snapshot_match':True,'quarantined':True,'service_ready':False,'manifest_sha256':'d'*64,'outage_to_verified_restore_seconds':25,'backup_age_at_outage_seconds':20}
        self.data=dict(zip(prod.REPORTS,(t,p,l,r,v)))

    def reports(self):return {n:recovery.canonical(x) for n,x in self.data.items()}
    def assemble(self):
        reports=self.reports()
        for n,b in reports.items():(self.evidence/n).write_bytes(b)
        item=prod.statement(self.archive,self.prev,reports,self.pin);(self.evidence/'acceptance.json').write_bytes(recovery.canonical(item));return item
    def sign_all(self,skip=None):
        for role in prod.ROLES:
            if role!=skip:prod.sign_file(self.evidence/'acceptance.json',self.keys/(role+'.pem'),self.evidence/(role+'.sig'))
    def publish(self,key='release'):
        return prod.publish(self.archive,self.evidence,self.registry,self.pin,self.keys/(key+'.pem'),self.root/'bundle')
    def cli(self,*args,ok=True):
        r=subprocess.run([sys.executable,str(ROOT/'tools/production.py'),*map(str,args),'--trust',str(self.registry),'--trust-sha256',self.pin],capture_output=True,text=True)
        self.assertEqual(r.returncode==0,ok,r.stdout+r.stderr)
        return json.loads(r.stdout) if ok else None
    def test_draft_and_cli_approvals(self):
        for n,b in self.reports().items():
            if n!='review.json':(self.evidence/n).write_bytes(b)
        args=['--archive',self.archive,'--evidence',self.evidence]
        self.assertEqual(self.cli('draft',*args,'--previous-sha256',self.prev)['status'],'draft_not_accepted')
        self.cli('assemble',*args,'--previous-sha256',self.prev,ok=False)
        (self.evidence/'review.json').write_bytes(self.reports()['review.json'])
        self.cli('assemble',*args,'--previous-sha256',self.prev)
        self.cli('approve',*args,'--role','lawyer','--private-key',self.keys/'accountant.pem',ok=False)
        self.assertFalse((self.evidence/'lawyer.sig').exists())
        for role in prod.ROLES:self.cli('approve',*args,'--role',role,'--private-key',self.keys/(role+'.pem'))
        self.cli('publish',*args,'--private-key',self.keys/'release.pem','--output',self.root/'bundle')
        self.assertEqual(self.cli('verify','--bundle',self.root/'bundle')['status'],'signed_accepted')
    def test_actual_predecessor_prepare(self):
        old=os.environ.get('WORKSPACE_PREVIOUS_RELEASE')
        if not old:self.skipTest('Actual previous package not supplied')
        old=Path(old);self.prev=recovery.digest(old);self.data['review.json']['previous_sha256']=self.prev
        self.assemble();self.sign_all();self.publish()
        result=self.cli('prepare','--bundle',self.root/'bundle','--root',self.root/'managed','--previous',old)
        self.assertEqual(result['current'],prod.PREVIOUS)
        self.assertEqual(candidate.pointer(self.root/'managed'),prod.PREVIOUS)
        self.assertEqual(result['candidate_sha256'],recovery.digest(self.archive))
    def test_expired_review_blocks_publish(self):
        old=(dt.datetime.now(dt.timezone.utc)-dt.timedelta(days=8)).isoformat()
        self.data['review.json']['checked_at']=self.data['topology.json']['checked_at']=old
        for r in self.data['readiness.json']['connections'].values():r['checked_at']=old
        self.assemble();self.sign_all()
        with self.assertRaises(ValueError):self.publish()
    def test_full_signed_bundle(self):
        item=self.assemble();self.sign_all();report=self.publish();self.assertEqual(report['status'],'signed_accepted');self.assertEqual(prod.verify(self.root/'bundle',self.registry,self.pin),item)
        with self.assertRaises(ValueError):self.publish()
        with self.assertRaises(ValueError):prod.verify(self.root/'bundle',self.registry,'0'*64)
        (self.root/'bundle'/'review.json').write_bytes(b'{}')
        with self.assertRaises((ValueError,KeyError)):prod.verify(self.root/'bundle',self.registry,self.pin)
    def test_missing_or_wrong_approval(self):
        self.assemble();self.sign_all('lawyer')
        with self.assertRaises(ValueError):self.publish()
        self.assertFalse((self.root/'bundle').exists())
        prod.sign_file(self.evidence/'acceptance.json',self.keys/'accountant.pem',self.evidence/'lawyer.sig')
        with self.assertRaises(ValueError):self.publish()
    def test_wrong_release_key(self):
        self.assemble();self.sign_all()
        with self.assertRaises(ValueError):self.publish('lawyer')
        self.assertFalse((self.root/'bundle').exists())
    def test_changed_reports_after_signing(self):
        self.assemble();self.sign_all();self.data['review.json']['stand']='other stand';(self.evidence/'review.json').write_bytes(self.reports()['review.json'])
        with self.assertRaises(ValueError):self.publish()
    def test_gate_rejects_bad_evidence(self):
        cases=[('topology.json','guest_ready',False),('readiness.json','ready',False),('review.json','open_critical_defects',1),('review.json','archive_sha256','0'*64),('review.json','tax_profile','unsupported'),('load.json','status','failed'),('restore.json','cash_balances_match',False),('restore.json','outage_to_verified_restore_seconds',61)]
        for name,key,bad in cases:
            with self.subTest(name=name,key=key):
                old=self.data[name][key];self.data[name][key]=bad
                with self.assertRaises(ValueError):prod.statement(self.archive,self.prev,self.reports(),self.pin)
                self.data[name][key]=old
        self.data['review.json']['integrations']['bots']['capabilities']['workspace_delivery_v1']=False
        with self.assertRaises(ValueError):prod.statement(self.archive,self.prev,self.reports(),self.pin)
    def test_incompatible_version_target_and_ci(self):
        v=self.data['review.json'];p=self.data['readiness.json']['connections'];p['engine']['observed_version']='3.0.0';p['engine']['declared_version']='3.0.0';v['integrations']['engine']['version']='3.0.0'
        with self.assertRaises(ValueError):prod.statement(self.archive,self.prev,self.reports(),self.pin)
        p['engine']['observed_version']=p['engine']['declared_version']=v['integrations']['engine']['version']='2.1.0'
        v['integrations']['engine']['target_hash']='0'*64
        with self.assertRaises(ValueError):prod.statement(self.archive,self.prev,self.reports(),self.pin)
        v['integrations']['engine']['target_hash']='c'*64;v['ci']['jobs']['postgres']='failure'
        with self.assertRaises(ValueError):prod.statement(self.archive,self.prev,self.reports(),self.pin)
    def test_distinct_reviewers(self):
        registry=self.root/'registry';registry.mkdir(mode=0o700)
        for p in self.registry.iterdir():(registry/p.name).write_bytes(p.read_bytes())
        registry_data=json.loads((registry/'trust.json').read_bytes());registry_data['lawyer']['identity']=registry_data['accountant']['identity'];(registry/'trust.json').write_bytes(recovery.canonical(registry_data))
        with self.assertRaises(ValueError):prod.trust(registry,recovery.digest(registry/'trust.json'))
        registry_data['lawyer']['identity']='Different lawyer';(registry/'lawyer.pub.pem').write_bytes((registry/'accountant.pub.pem').read_bytes());registry_data['lawyer']['sha256']=recovery.digest(registry/'lawyer.pub.pem');(registry/'trust.json').write_bytes(recovery.canonical(registry_data))
        with self.assertRaises(ValueError):prod.trust(registry,recovery.digest(registry/'trust.json'))
    def test_archive_mutation_and_duplicate_json(self):
        self.assemble();self.sign_all();self.publish();bundle=self.root/'bundle';a=bundle/prod.NAME;a.write_bytes(a.read_bytes()+b'x')
        with self.assertRaises(ValueError):prod.verify(bundle,self.registry,self.pin)
        with self.assertRaises(ValueError):prod.decode(b'{"x":1,"x":2}')

if __name__=='__main__':unittest.main(verbosity=2)
