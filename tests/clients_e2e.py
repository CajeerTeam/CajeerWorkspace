"""HTTP acceptance of the owner workflow and cross-tenant/role restrictions."""
import os
from pathlib import Path
from tempfile import TemporaryDirectory
from web_e2e import Browser, application, cli, expect, fields
from permissions_e2e import forged


def run(tmp):
    env=os.environ.copy()
    for var in ('WORKSPACE_CONFIG','WORKSPACE_OIDC_CLIENT_ID','WORKSPACE_OIDC_CLIENT_SECRET','WORKSPACE_OIDC_REQUIRED','WORKSPACE_EMERGENCY_LOCAL'):
        env.pop(var,None)
    env.update(WORKSPACE_DB='sqlite',WORKSPACE_DATA=str(tmp/'data'))
    cli(env,'init','owner@example.test','Owner',password=True)
    cli(env,'user:add','developer@example.test','Developer','developer',password=True)
    with application(env) as root:
        owner,dev,plain=[Browser(root) for _ in range(3)]
        owner.login('owner'); dev.login('developer',step_up=False); plain.login('owner',step_up=False)
        expect(owner.post('?p=projects','project.create',title='Client work',kind='client',client='')[0]==200,'client project')
        path='?p=clients'
        expect(plain.call(path)[0]==403,'MFA protected directory')
        expect(dev.call(path)[0]==403,'commercial role denied')
        f=fields(owner.call(path)[1],'client.party')|{'name':'ACME','registration':'REG','notes':'PRIVATE'}
        expect(owner.call(path,f)[0]==200,'counterparty')
        expect(owner.call(path,f)[0]==200,'same submission idempotent')
        expect(owner.post(path,'client.contact',name='Alice',email='alice@example.test',phone='')[0]==200,'contact')
        expect(owner.post(path,'client.request',project_id=1,contact_id=1,title='Request',request_text='Build API')[0]==200,'request')
        order=path+'&id=1'
        expect(owner.post(order,'client.offer',scope='Offer',currency='EUR')[0]==200,'proposal')
        expect(owner.post(order,'client.publish')[0]==409,'empty estimate denied')
        expect(owner.post(order,'client.stage',title='Implementation',scope='Working API',due_on='2026-12-01',amount='100.50')[0]==200,'estimate stage')
        expect(owner.post(order,'client.publish')[0]==200,'proposal published')
        expect(b'100,50 EUR' in owner.call(order)[1],'estimate amount visible')
        expect(forged(dev,order,'client.publish',order_id=1,offer_id=1,version=3)[0]==403,'forged publish denied')
        expect(owner.post('?p=team','org.create',name='Other')[0]==200,'second organization')
        expect(owner.call(order)[0]==404,'other organization cannot read order')
        expect(b'PRIVATE' not in owner.call(path)[1],'directory isolated')

if __name__=='__main__':
    with TemporaryDirectory(prefix='cws-clients-http-') as tmp: run(Path(tmp))
    print('OK · client HTTP acceptance')
