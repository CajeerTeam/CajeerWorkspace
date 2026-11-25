"""HTTP acceptance of public/maintainer/contributor boundaries on disposable SQLite."""
import json
import os
import re
import secrets
from pathlib import Path
from tempfile import TemporaryDirectory
from web_e2e import Browser, application, cli, expect, fields


def run(tmp):
    env=os.environ.copy()
    for var in ('WORKSPACE_CONFIG','WORKSPACE_OIDC_CLIENT_ID','WORKSPACE_OIDC_CLIENT_SECRET','WORKSPACE_OIDC_REQUIRED','WORKSPACE_EMERGENCY_LOCAL'):
        env.pop(var,None)
    env.update(WORKSPACE_DB='sqlite',WORKSPACE_DATA=str(tmp/'data'))
    cli(env,'init','owner@example.test','Owner',password=True)
    cli(env,'user:add','developer@example.test','Developer','developer',password=True)
    with application(env) as root:
        owner,plain,guest,other,public,dev=[Browser(root) for _ in range(6)]
        owner.login('owner'); plain.login('owner',step_up=False); dev.login('developer',step_up=False)
        expect(owner.post('?p=projects','project.create',title='PRIVATE_PROJECT',kind='oss',client='')[0]==200,'OSS project')
        admin='?p=oss&project=1'; pub='?p=oss.public&slug=example-oss'; export='?p=oss.export&slug=example-oss'
        expect(public.call(pub)[0]==404,'default private')
        settings={'slug':'example-oss','upstream':'Example/Repo','public_title':'Public project','description':'Public description','published':'1'}
        expect(plain.post(admin,'oss.settings',**settings)[0]==403,'publication MFA required')
        expect(owner.post(admin,'oss.settings',**settings)[0]==200,'publish profile')
        expect(public.call(pub)[0]==200 and b'PRIVATE_PROJECT' not in public.call(pub)[1],'public project is sanitized')
        expect(dev.call(admin)[0]==404,'nonmember maintainer denied')
        def invite(name):
            r=owner.post(admin,'oss.invite',display_name=name,hours=24)
            expect(r[0]==200,'invite'); token=re.search(rb'readonly value="([a-f0-9]{64})"',r[1]); expect(token is not None,'one-time token'); return token.group(1).decode()
        token=invite('Private pseudonym'); token2=invite('Other pseudonym')
        join='?p=oss.join'; cabin='?p=oss.contributor'
        expect(guest.post(join,'oss.join',token=token)[0]==200,'contributor enters')
        expect(other.post(join,'oss.join',token=token)[0]==404,'replay denied')
        expect(other.post(join,'oss.join',token=token2)[0]==200,'other contributor enters')
        form=fields(guest.call(cabin)[1],'oss.submit')|{'title':'PRIVATE_ISSUE','body':'PRIVATE_DESCRIPTION','upstream_url':'https://github.com/example/repo/pull/7'}
        expect(guest.call(cabin,form)[0]==200,'submit')
        expect(guest.call(cabin,form)[0]==200,'submit replay')
        expect(other.call(cabin+'&id=1')[0]==404,'other contributor cannot read')
        fake=fields(other.call(cabin)[1],'oss.submit')|{'act':'oss.reply','id':1,'body':'forged','request_key':secrets.token_hex(16)}
        expect(other.call(cabin,fake)[0]==404,'other contributor cannot reply')
        expect(guest.call('?p=documents')[3].endswith('?p=oss.contributor'),'internal route isolated')
        fake=fields(guest.call(cabin)[1],'oss.submit')|{'act':'project.create','title':'forged','kind':'oss'}
        expect(guest.call('?p=projects',fake)[0]==403,'internal mutation denied')
        expect(owner.post(admin+'&id=1','oss.comment',body='PRIVATE_REVIEW')[0]==200,'maintainer reply')
        expect(b'PRIVATE_REVIEW' in guest.call(cabin+'&id=1')[1],'contributor sees review')
        expect(b'PRIVATE_REVIEW' not in public.call(pub)[1],'comment not public')
        p='?p=project&id=1'; task='?p=task&id=1'; triage=admin+'&id=1'
        expect(owner.post(p,'milestone.create',title='PRIVATE_MILESTONE',due_on='2030-01-01')[0]==200,'internal milestone')
        expect(owner.post(p,'task.create',title='PRIVATE_TASK',result_customer='PRIVATE_CUSTOMER',acceptance_criteria='PRIVATE_CRITERIA',milestone_id=1)[0]==200,'internal task')
        expect(owner.post(admin,'oss.milestone',public_title='Public milestone',description='Public scope')[0]==200,'public milestone')
        expect(owner.post(task,'oss.link',upstream_url='https://github.com/example/repo/pull/7')[0]==200,'task upstream')
        expect(owner.post(triage,'oss.triage',state='reviewing',task_id=1)[0]==200,'triage reviewing')
        expect(owner.post(triage,'oss.triage',state='accepted',task_id=1,public_note='Public contribution')[0]==409,'unfinished task cannot be accepted')
        expect(owner.post(task,'task.state',state='done')[0]==200,'task complete')
        expect(owner.post(triage,'oss.triage',state='accepted',task_id=1,public_note='Public contribution')[0]==200,'contribution accepted')
        expect(owner.post(p,'milestone.state',state='closed')[0]==200,'milestone closed')
        # Browser.call encodes mapping values as scalars, so submit a single checked contribution via bracket key.
        expect(owner.post(admin,'oss.release',tag='v1',title='Public release',notes='Public notes',**{'contribution_ids[]':'1'})[0]==200,'publish release')
        data=json.loads(public.call(export)[1]); expect(data['project']['public_title']=='Public project' and 'project_id' not in data['project'],'public export')
        expect(data['releases'][0]['items'][0]['public_note']=='Public contribution','accepted contribution in release')
        expect(b'PRIVATE_' not in public.call(export)[1] and b'PRIVATE_' not in public.call(pub)[1],'no private export or HTML')
        expect(owner.post(admin,'oss.revoke')[0]==200,'revoke contributor') # newest contributor in list
        expect(other.call(cabin)[0]==403,'revoked session denied')
        expect(owner.post(admin,'oss.settings',**(settings|{'published':'0'}))[0]==200,'disable publication')
        expect(public.call(pub)[0]==404 and public.call(export)[0]==404,'public read revoked')
        expect(guest.call(cabin)[0]==403,'contributor revoked by disabling profile')

if __name__=='__main__':
    with TemporaryDirectory(prefix='cws-oss-http-') as tmp: run(Path(tmp))
    print('OK · OSS HTTP acceptance')
