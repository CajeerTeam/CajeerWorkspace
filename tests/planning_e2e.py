"""HTTP acceptance for board, backlog, project permissions and workflow."""
import os
from pathlib import Path
from tempfile import TemporaryDirectory
from urllib.parse import urlencode
from web_e2e import Browser, application, cli, expect, fields


def run(tmp):
    env=os.environ.copy(); env.pop('WORKSPACE_CONFIG',None)
    env.update(WORKSPACE_DB='sqlite',WORKSPACE_DATA=str(tmp/'data'))
    cli(env,'init','owner@example.test','Owner',password=True)
    for role in ('developer','viewer'):
        cli(env,'user:add',role+'@example.test',role,role,password=True)
    with application(env) as root:
        owner,dev,viewer=Browser(root),Browser(root),Browser(root)
        owner.login('owner'); dev.login('developer',step_up=False); viewer.login('viewer',step_up=False)
        expect(owner.post('?p=projects','project.create',title='Planning',kind='internal',client='')[0]==200,'project created')
        p='?p=project&id=1'
        for uid in (2,3): expect(owner.post(p,'project.add',member_id=uid)[0]==200,'member added')
        expect(owner.post(p,'milestone.create',title='Delivery',due_on='2030-01-01')[0]==200,'milestone created')
        expect(owner.post(p,'task.create',title='Empty')[0]==422,'metadata required')
        base={'result_customer':'Заказчик Ёлка','acceptance_criteria':'Check 100%','milestone_id':'1','priority':'1'}
        expect(owner.post(p,'task.create',title='Build',due_on='2000-01-01',**base)[0]==200,'first task created')
        expect(owner.post(p,'task.create',title='Deliver',assignee=2,**base)[0]==200,'second task created')
        expect(owner.post('?p=task&id=2','task.dependency.add',prerequisite_id=1)[0]==200,'dependency added')
        expect(owner.post('?p=task&id=1','task.dependency.add',prerequisite_id=2)[0]==409,'cycle rejected')
        expect(dev.post('?p=task&id=2','task.state',state='doing')[0]==409,'blocked task cannot start')
        expect(owner.post(p,'milestone.state',state='closed')[0]==409,'unfinished milestone rejected')
        body=dev.call(p+'&'+urlencode({'view':'backlog','q':'ЁЛКА','blocked':'late','assignee':2}))[1]
        expect(b'Deliver' in body and b'Build' not in body and 'Просроченная блокировка'.encode() in body,'filtered backlog')
        expect('Просроченная блокировка'.encode() in dev.call('?p=overview')[1],'overview blocker')
        expect(b'Check 100%' in viewer.call('?p=task&id=2')[1],'viewer can read criteria')
        forged=fields(owner.call('?p=task&id=2')[1],'task.edit')|{'title':'Forged',**base}
        forged['csrf']=fields(viewer.call('?p=overview')[1],'logout')['csrf']
        expect(viewer.call('?p=task&id=2',forged)[0]==403,'viewer cannot edit')
        expect(owner.post('?p=task&id=1','task.state',state='done')[0]==200,'predecessor completed')
        expect(dev.post('?p=task&id=2','task.state',state='done')[0]==200,'assignee completes task')
        expect(owner.post(p,'milestone.state',state='closed')[0]==200,'milestone closed')
        expect('Закрыта'.encode() in owner.call(p)[1],'closed shown')
        expect(owner.post('?p=projects','project.create',title='Private',kind='internal',client='')[0]==200,'private project created')
        expect(owner.post('?p=project&id=2','task.create',title='PRIVATEPLANNING',**(base|{'milestone_id':''}))[0]==200,'private task created')
        expect(dev.call('?p=task&id=3')[0]==404,'private task direct denied')
        expect(b'p=task&amp;id=3' not in dev.call('?p=search&q=PRIVATEPLANNING')[1],'private task search denied')
        expect(owner.post(p,'project.remove',member_id=2)[0]==200,'membership revoked')
        expect(dev.call('?p=task&id=2')[0]==404,'task denied on active session after revoke')

if __name__=='__main__':
    with TemporaryDirectory(prefix='cws-planning-e2e-') as tmp: run(Path(tmp))
    print('OK · planning HTTP acceptance')
