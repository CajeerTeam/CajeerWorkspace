"""Offline signed package/download/plan tests. Network is mocked, signatures are real."""
import base64
import copy
import hashlib
import io
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tempfile
import unittest
import urllib.error
import zipfile

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
import release
import update_package as u

class Updates(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp=tempfile.TemporaryDirectory(); cls.addClassCleanup(cls.tmp.cleanup)
        cls.base=Path(cls.tmp.name); cls.previous=release.build(cls.base/'previous')
        cls.current=(ROOT/'VERSION').read_text().strip(); cls.version='1.6.2'; cls.schema=48
        cls.key=cls.base/'key'; cls.pub=cls.base/'pub';
        subprocess.run(['openssl','genpkey','-algorithm','RSA','-pkeyopt','rsa_keygen_bits:2048','-out',str(cls.key)],check=True,capture_output=True)
        subprocess.run(['openssl','pkey','-in',str(cls.key),'-pubout','-out',str(cls.pub)],check=True,capture_output=True)
        with zipfile.ZipFile(cls.previous) as z: cls.files={n:z.read(n) for n in z.namelist() if n!='SHA256SUMS'}
        cls.files['VERSION']=(cls.version+'\n').encode(); cls.files['app/Core.php']=cls.files['app/Core.php'].replace(f"const VERSION = '{cls.current}'".encode(),f"const VERSION = '{cls.version}'".encode())
        matrix=json.loads(cls.files['config/compatibility.json']);matrix['version']=cls.version;cls.files['config/compatibility.json']=json.dumps(matrix).encode()
        cls.target=cls.base/f'CajeerWorkspace-{cls.version}.zip';cls.make_zip(cls.target,cls.files)
        cls.signature=cls.base/'package.sig';cls.sign(cls.target,cls.signature)
        cls.metadata={'repository':u.REPO,'version':cls.version,'tag':'v'+cls.version,'channel':'stable','schema':48,'release_key_sha256':release.file_sha(cls.pub), 'matrix_sha256':release.sha(cls.files['config/compatibility.json']),'package':{'name':cls.target.name,'size':cls.target.stat().st_size,'sha256':release.file_sha(cls.target),'signature_sha256':release.file_sha(cls.signature)},'supported_from':[{'version':cls.current,'schema':48,'archive_sha256':release.file_sha(cls.previous)}],'runtime':{k:matrix['runtime'][k] for k in ('php_min','sqlite_min','postgres_min','python_min','architecture')}}

    @classmethod
    def make_zip(cls,path,files):
        with zipfile.ZipFile(path,'w') as z:
            for n,b in files.items(): release.zip_entry(z,n,b)
            release.zip_entry(z,'SHA256SUMS',''.join(f'{release.sha(b)}  {n}\n' for n,b in sorted(files.items())).encode())

    @classmethod
    def sign(cls,path,sig):
        subprocess.run(['openssl','dgst','-sha256','-sign',str(cls.key),'-out',str(sig),str(path)],check=True,capture_output=True)

    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(dir=self.base);self.addCleanup(self.temp.cleanup);self.path=Path(self.temp.name);self.store=self.path/'store';self.store.mkdir(mode=0o700)
        self.meta=copy.deepcopy(self.metadata);self.archive=self.target;self.sig=self.signature
        self.calls=[]

    def envelope(self):
        path=self.path/'metadata';path.write_text(json.dumps(self.meta,separators=(',',':'),sort_keys=True));sig=self.path/'metadata.sig';self.sign(path,sig)
        return {'metadata':base64.b64encode(path.read_bytes()).decode(),'signature':base64.b64encode(sig.read_bytes()).decode(),'key':base64.b64encode(self.pub.read_bytes()).decode(),'signature_size':self.sig.stat().st_size,'environment':{'version':self.current,'schema':48}}

    def fetch(self,url,dest,size,digest):
        self.calls.append(url);src=self.sig if url.endswith('.sig') else self.archive
        self.assertTrue(u.allowed(url,True));self.assertEqual(src.stat().st_size,size);self.assertEqual(release.file_sha(src),digest);shutil.copyfile(src,dest)

    def run_download(self,e=None): return u.run('download',self.store,e or self.envelope(),downloader=self.fetch)
    def run_plan(self,e,previous=None,root=ROOT): return u.run('plan',self.store,e,previous or self.previous,downloader=self.fetch,root=root)

    def test_download_plan_reuse(self):
        e=self.envelope();self.assertEqual(self.run_download(e)['status'],'downloaded');self.assertEqual(len(self.calls),2)
        self.assertEqual(self.run_download(e)['status'],'downloaded');self.assertEqual(len(self.calls),2)
        p=self.run_plan(e);self.assertEqual(p['status'],'planned');self.assertEqual(p['changed'],3);self.assertEqual(p['pending_migrations'],[]);self.assertFalse(p['installation_performed']);self.assertEqual(len(self.calls),2)

    def test_php_process_plan(self):
        e=self.envelope();self.run_download(e)
        code="require 'app/bootstrap.php'; require 'app/WorkspaceUpdateCli.php'; echo json_encode(\\Workspace\\updatePackageProcess('plan',$argv[1],json_decode(stream_get_contents(STDIN),true),$argv[2]));"
        result=subprocess.run(['php','-r',code,str(self.store),str(self.previous)],cwd=ROOT,input=json.dumps(e),text=True,capture_output=True,timeout=30)
        self.assertEqual(result.returncode,0,result.stderr);self.assertEqual(json.loads(result.stdout)['status'],'planned')

    def test_wrong_predecessor_and_modified_tree(self):
        e=self.envelope();self.run_download(e);wrong=self.path/'wrong.zip';wrong.write_bytes(b'wrong')
        with self.assertRaisesRegex(ValueError,'Predecessor SHA-256'):self.run_plan(e,wrong)
        root=self.path/'modified';shutil.copytree(ROOT,root,ignore=shutil.ignore_patterns('__pycache__','.git'));(root/'VERSION').write_text('modified')
        with self.assertRaisesRegex(ValueError,'Installed distribution differs'):self.run_plan(e,root=root)

    def test_bad_metadata_signature(self):
        e=self.envelope();e['signature']=base64.b64encode(b'wrong').decode()
        with self.assertRaisesRegex(ValueError,'signature'):self.run_download(e)
        self.assertEqual(list(self.store.iterdir()),[])

    def test_corrupt_package_signature(self):
        self.sig=self.path/'bad.sig';self.sig.write_bytes(b'wrong');self.meta['package']['signature_sha256']=release.file_sha(self.sig)
        with self.assertRaisesRegex(ValueError,'signature'):self.run_download()
        self.assertEqual(list(self.store.iterdir()),[])

    def test_interrupted_retry(self):
        e=self.envelope()
        def interrupted(url,dest,size,digest):dest.write_bytes(b'partial');raise ValueError('interrupted')
        with self.assertRaisesRegex(ValueError,'interrupted'):u.run('download',self.store,e,downloader=interrupted)
        self.assertEqual(list(self.store.iterdir()),[]);self.assertEqual(self.run_download(e)['status'],'downloaded')

    def test_cached_corruption(self):
        e=self.envelope();self.run_download(e);folder=self.store/self.meta['package']['sha256'];(folder/self.target.name).write_bytes(b'bad')
        with self.assertRaisesRegex(ValueError,'size or hash'):self.run_plan(e)
        with self.assertRaisesRegex(ValueError,'size or hash'):self.run_download(e)

    def test_unsafe_zip_even_if_signed(self):
        self.archive=self.path/self.target.name;files=dict(self.files);files['../escape']=b'bad';self.make_zip(self.archive,files);self.sig=self.path/'unsafe.sig';self.sign(self.archive,self.sig)
        self.meta['package'].update(size=self.archive.stat().st_size,sha256=release.file_sha(self.archive),signature_sha256=release.file_sha(self.sig))
        with self.assertRaisesRegex(ValueError,'Unsafe ZIP'):self.run_download()
        self.assertEqual(list(self.store.iterdir()),[])

    def test_matrix_mismatch_and_python(self):
        self.meta['matrix_sha256']='0'*64
        with self.assertRaisesRegex(ValueError,'matrix'):self.run_download()
        self.meta=copy.deepcopy(self.metadata);self.meta['runtime']['python_min']='99.0'
        with self.assertRaisesRegex(ValueError,'Python version'):self.run_download()

    def test_allowlist_and_stream(self):
        url=f'https://github.com/{u.REPO}/releases/download/v{self.version}/{self.target.name}'
        for bad in ['http://github.com/x','https://github.com.evil/x','https://github.com:443/x','https://evil@github.com/x','https://github.com/else/repo/releases/download/v1.2.4/CajeerWorkspace-1.2.4.zip','https://release-assets.githubusercontent.com/x']:
            self.assertFalse(u.allowed(bad,True),bad)
        class Response(io.BytesIO):
            status=200;headers={}
        class Opener:
            def __init__(self,data):self.data=data
            def open(self,req,timeout):return Response(self.data)
        data=b'abc';dest=self.path/'bytes';u.fetch(url,dest,len(data),release.sha(data),Opener(data));self.assertEqual(dest.read_bytes(),data)
        for i,b in enumerate([b'ab',b'abcd',b'xxx']):
            with self.assertRaises(ValueError):u.fetch(url,self.path/f'bad{i}',len(data),release.sha(data),Opener(b))
        class Redirect:
            def open(self,req,timeout):raise urllib.error.HTTPError(req.full_url,302,'redirect',{'Location':'https://evil.example/x'},io.BytesIO())
        with self.assertRaisesRegex(ValueError,'redirect'):u.fetch(url,self.path/'redirect',3,release.sha(data),Redirect())

if __name__=='__main__':unittest.main(verbosity=2)
