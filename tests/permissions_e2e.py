"""Real HTTP permission and payment acceptance. Optional PostgreSQL transfer + recheck."""
import argparse, os, secrets, time
from pathlib import Path
from tempfile import TemporaryDirectory
from web_e2e import Browser, application, cli, expect, fields, sample_pdf, upload
from tenant_e2e import new_project, add_pdf

def forged(browser,path,act,**values):
    # Derive a valid CSRF token from the logout form; do not rely on hidden action controls.
    form=fields(browser.call('?p=overview')[1],'logout')
    return browser.call(path,form|{'act':act,'request_key':secrets.token_hex(16)}|values)

def assign(owner,pid,uid,role):
    path=f'?p=project&id={pid}'
    expect(owner.post(path,'project.role',project_id=pid,member_id=uid,role=role)[0]==200,'project role '+role)

def run(tmp,pg):
    env=os.environ.copy(); env.pop('WORKSPACE_CONFIG',None); env.update(WORKSPACE_DB='sqlite',WORKSPACE_DATA=str(tmp/'data'))
    cli(env,'init','owner@example.test','Owner',password=True)
    for name in ['creator','approver','reader','auditor','lawyer']:
        cli(env,'user:add',name+'@example.test',name,'viewer',password=True)
    with application(env) as root:
        people={name:Browser(root) for name in ['owner','creator','approver','reader','auditor','lawyer']}
        for name,b in people.items(): b.login(name)
        owner,creator,approver,reader,auditor,lawyer=people.values()
        pid=new_project(owner,'Controlled'); other=new_project(owner,'Private'); path=f'?p=project&id={pid}'
        for uid,role in enumerate(['payment_creator','payment_approver','finance_reader','auditor','legal_reader'],2):
            expect(owner.post(path,'project.add',member_id=uid)[0]==200,'membership')
            assign(owner,pid,uid,role)
        expect(owner.post(path,'payment.policy',dual='1')[0]==200,'enable dual control')
        values={'project_id':pid,'booked_on':time.strftime('%Y-%m-%d',time.gmtime()),'direction':'expense','amount':'9.50','currency':'EUR','memo':'ControlledPayment'}
        form=fields(creator.call('?p=finance')[1],'cash.post')|values
        for _ in range(2): expect(creator.call('?p=finance',form)[0]==200,'idempotent request')
        expect(b'ControlledPayment' not in owner.call('?p=export')[1],'pending absent from ledger')
        expect(forged(creator,'?p=payments','payment.approve',payment_id=1)[0]==403,'self approval denied')
        expect(forged(approver,'?p=finance','cash.post',**values)[0]==403,'approver cannot create')
        expect(forged(reader,'?p=finance','cash.post',**values)[0]==403,'reader cannot create')
        expect(forged(creator,'?p=finance','cash.post',**(values|{'project_id':other}))[0]==404,'closed project write denied')
        approval=fields(approver.call('?p=payments')[1],'payment.approve')
        for _ in range(2): expect(approver.call('?p=payments',approval)[0]==200,'approval replay')
        expect(owner.call('?p=export')[1].count(b'ControlledPayment')==1,'posted exactly once')
        expect(forged(approver,'?p=payments','payment.approve',payment_id=1)[0]==409,'second decision blocked')
        expect(creator.post('?p=finance','cash.reverse')[0]==200,'reversal queued')
        expect(b'ControlledPayment' in owner.call('?p=export')[1],'pending reversal not posted')
        expect(approver.post('?p=payments','payment.approve')[0]==200,'reversal approved')
        expect(creator.post('?p=finance','cash.post',**(values|{'memo':'RevokedPayment'}))[0]==200,'another request')
        assign(owner,pid,2,'viewer')
        expect(creator.call('?p=export')[0]==403,'active session loses finance')
        expect(forged(approver,'?p=payments','payment.approve',payment_id=3)[0]==409,'revoked request cancelled')
        expect(b'RevokedPayment' not in owner.call('?p=export')[1],'revoked request never posted')
        pdf=sample_pdf('legal'); doc=add_pdf(owner,pid,'ReadOnlyContract',pdf); private=add_pdf(owner,other,'SecretContract',pdf)
        expect(lawyer.call(f'?p=download&id={doc}&v=1')[1]==pdf,'read only PDF exact bytes')
        expect(lawyer.call(f'?p=download&id={private}&v=1')[0]==404,'private PDF denied')
        expect('Сохранить версию'.encode() not in lawyer.call(f'?p=document&id={doc}')[1],'no write form')
        expect(forged(lawyer,'?p=documents','document.version',project_id=pid)[0]==403,'document write denied')
        expect(auditor.call('?p=audit')[0]==200 and b'payment.approve' in auditor.call('?p=audit')[1],'project audit visible')
        expect(auditor.call(f'?p=audit&project={other}')[0]==404,'private audit denied')
        expect(reader.call('?p=audit')[0]==403,'audit independent of finance')
        expect(auditor.call('?p=export')[0]==403,'audit does not grant finance')
        expect(b'ControlledPayment' not in auditor.call('?p=audit')[1],'no payment memo in audit')
    if pg:
        import json
        report=json.loads(cli(env,'postgres:import',str(tmp/'source.sqlite')).stdout)
        expect(report['tables']['payment_requests']['rows']==3,'transferred payment requests')
        expect(report['tables']['project_access']['rows']==5,'transferred explicit roles')
        target=env|{'WORKSPACE_DB':'pgsql','WORKSPACE_DATA':str(tmp/'pgdata')}
        with application(target) as root:
            auditor=Browser(root); auditor.login('auditor'); expect(b'payment.approve' in auditor.call('?p=audit')[1],'audit survives PostgreSQL')
            approver=Browser(root); approver.login('approver'); expect(forged(approver,'?p=payments','payment.approve',payment_id=3)[0]==409,'cancelled request remains cancelled')

if __name__=='__main__':
    parser=argparse.ArgumentParser(); parser.add_argument('--postgres',action='store_true'); args=parser.parse_args()
    with TemporaryDirectory(prefix='cws-permissions-') as d: run(Path(d),args.postgres)
    print('OK · permission HTTP checks')
