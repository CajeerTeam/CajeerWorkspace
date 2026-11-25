"""HTTP acceptance for workload with two independent sessions and protected rates."""
import os
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from tempfile import TemporaryDirectory
from web_e2e import Browser, application, cli, expect, fields
from permissions_e2e import forged


def run(tmp):
    env=os.environ.copy(); env.pop('WORKSPACE_CONFIG',None); env.update(WORKSPACE_DB='sqlite',WORKSPACE_DATA=str(tmp/'data'))
    cli(env,'init','owner@example.test','Owner',password=True)
    for role in ('developer','viewer','accountant'): cli(env,'user:add',role+'@example.test',role,role,password=True)
    with application(env) as root:
        owner,dev,dev2,viewer,finance=[Browser(root) for _ in range(5)]
        owner.login('owner'); dev.login('developer',step_up=False); dev2.login('developer',step_up=False); viewer.login('viewer',step_up=False); finance.login('accountant')
        expect(owner.post('?p=projects','project.create',title='Workload',kind='internal',client='')[0]==200,'project')
        p='?p=project&id=1'
        for uid in (2,3,4): expect(owner.post(p,'project.add',member_id=uid)[0]==200,'member')
        expect(owner.post(p,'task.create',title='Build',assignee=2,result_customer='Customer',acceptance_criteria='Criteria',priority='3')[0]==200,'task')
        w='?p=workload&project=1&week=2024-12-30'
        expect(owner.post(w,'sprint.save',title='First sprint',starts_on='2024-12-30',ends_on='2025-01-05')[0]==200,'sprint created')
        expect(owner.post(w,'sprint.task',task_id=1,estimate_minutes=180)[0]==200,'sprint task')
        expect(owner.post(w,'sprint.state',state='active')[0]==200,'sprint started')
        expect(owner.post(w,'rate.save',mode='hourly',currency='EUR',amount='100.01')[0]==200,'hourly rate')
        expect(dev.post(w,'availability.save',minutes=240)[0]==200,'availability')
        vals={'task_id':1,'worked_on':'2024-12-30','minutes':90,'note':'WORKLOG_PRIVATE'}
        a=fields(dev.call(w)[1],'time.save')|vals
        b=fields(dev2.call(w)[1],'time.save')|vals|{'request_key':a['request_key']}
        with ThreadPoolExecutor(2) as pool:
            results=list(pool.map(lambda pair:pair[0].call(w,pair[1])[0],[(dev,a),(dev2,b)]))
        expect(results==[200,200],'two sessions same request succeed once')
        expect(dev.post(w,'time.submit')[0]==200,'submission')
        expect(dev.call(w,a|{'request_key':'f'*32})[0]==409,'stale write rejected')
        expect(forged(dev,w,'time.approve',project_id=1,week='2024-12-30',version=3,sheet_id=1)[0]==403,'approval denied')
        expect(owner.post(w,'rate.save',mode='hourly',currency='EUR',amount='200')[0]==200,'rate revision')
        expect(owner.post(w,'time.approve',decision_note='Accepted')[0]==200,'approval')
        body=owner.call(w)[1]
        expect(b'150,02' in body and b'WORKLOG_PRIVATE' in body,'old rate applied')
        body=finance.call(w)[1]; expect(b'150,02' in body and b'WORKLOG_PRIVATE' not in body,'finance totals only')
        body=dev.call(w)[1]; expect(b'150,02' not in body and b'WORKLOG_PRIVATE' in body,'own time without rate')
        expect(viewer.call(w)[0]==403,'viewer denied')
        expect(owner.post(w,'sprint.state',state='closed')[0]==200,'sprint closed')
        expect(owner.post(p,'project.remove',member_id=2)[0]==200,'revocation')
        expect(dev.call(w)[0]==404,'revoked direct read')

if __name__=='__main__':
    with TemporaryDirectory(prefix='cws-workload-e2e-') as tmp: run(Path(tmp))
    print('OK · workload HTTP acceptance')
