"""HTTP webhook/CSRF/MFA acceptance; no real GitHub credentials required."""
import hashlib
import hmac
import json
import os
import sqlite3
import uuid
from pathlib import Path
from tempfile import TemporaryDirectory
from urllib import request, error
from web_e2e import Browser, application, cli, expect, fields


def run(tmp):
    env=os.environ.copy()
    for var in ('WORKSPACE_CONFIG','WORKSPACE_OIDC_CLIENT_ID','WORKSPACE_OIDC_CLIENT_SECRET','WORKSPACE_OIDC_REQUIRED','WORKSPACE_EMERGENCY_LOCAL'):
        env.pop(var,None)
    secret='s'*64
    env.update(WORKSPACE_DB='sqlite',WORKSPACE_DATA=str(tmp/'data'),WORKSPACE_GITHUB_WEBHOOK_SECRET=secret)
    cli(env,'init','owner@example.test','Owner',password=True)
    dbfile=tmp/'data'/'workspace.sqlite'
    with application(env) as root:
        owner, plain, public=Browser(root),Browser(root),Browser(root)
        owner.login('owner');plain.login('owner',step_up=False)
        expect(owner.post('?p=projects','project.create',title='GH project',kind='oss',client='')[0]==200,'project created')
        project='?p=project&id=1'; task='?p=task&id=1'; page='?p=github&project=1'
        expect(owner.post(project,'task.create',title='PR task',result_customer='Owner',acceptance_criteria='Checks')[0]==200,'task created')
        # The verified operator binding itself is covered using a REST fixture in github_db.php.
        with sqlite3.connect(dbfile) as db:
            db.execute("INSERT INTO github_repositories(project_id,repository_id,installation_id,full_name) VALUES(1,40,30,'owner/example')")
        expect(plain.post(task,'github.link',kind='pull',number=8)[0]==403,'link requires MFA')
        expect(owner.post(task,'github.link',kind='pull',number=8)[0]==200,'link saved in task')
        expect(public.call(page)[3].endswith('?p=login'),'anonymous GitHub page denied')
        def send(event, payload, ident=None, sign=True):
            raw=json.dumps(payload,separators=(',',':')).encode()
            sig='sha256='+hmac.new(secret.encode(),raw,hashlib.sha256).hexdigest() if sign else 'sha256='+'0'*64
            req=request.Request(root+'?p=github.webhook',data=raw,headers={'Content-Type':'application/json','X-Hub-Signature-256':sig,'X-GitHub-Delivery':ident or str(uuid.uuid4()),'X-GitHub-Event':event})
            try:r=request.urlopen(req,timeout=5)
            except error.HTTPError as e:r=e
            with r:return r.status,r.read()
        payload={'action':'synchronize','installation':{'id':30},'repository':{'id':40},'pull_request':{'number':8}}
        ident=str(uuid.uuid4())
        expect(send('pull_request',payload,ident,False)[0]==403,'bad HMAC denied without session')
        status,raw=send('pull_request',payload,ident);expect(status==202 and json.loads(raw)['status']=='accepted','signed raw HTTP accepted without session')
        status,raw=send('pull_request',payload,ident);expect(status==202 and json.loads(raw)['status']=='duplicate','duplicate HTTP delivery')
        expect(send('pull_request',dict(payload,action='opened'),ident)[0]==409,'delivery conflict')
        with sqlite3.connect(dbfile) as db:
            expect(db.execute('SELECT count(*) FROM github_deliveries').fetchone()[0]==1,'only valid delivery persisted')
            expect(db.execute("SELECT count(*) FROM github_jobs WHERE state='pending'").fetchone()[0]==2,'durable targeted job and health')
        expect(owner.post(page,'github.sync')[0]==200,'manual sync form')
        expect(send('installation_repositories',{'action':'removed','installation':{'id':30},'repositories_removed':[{'id':40}]})[0]==202,'revocation webhook')
        with sqlite3.connect(dbfile) as db:
            expect(db.execute('SELECT active FROM github_repositories').fetchone()[0]==0,'repository disabled')
            expect(db.execute("SELECT count(*) FROM github_jobs WHERE state='pending'").fetchone()[0]==0,'queue revoked')
        expect('Отключено'.encode() in owner.call(page)[1],'disabled state visible')

if __name__=='__main__':
    with TemporaryDirectory() as temp:run(Path(temp))
    print('OK · GitHub HTTP webhook and project UI')
