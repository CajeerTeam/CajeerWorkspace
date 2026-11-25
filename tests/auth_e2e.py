"""HTTP acceptance for local step-up and session revocation (real PHP server)."""
import os
import secrets
from pathlib import Path
from tempfile import TemporaryDirectory
from web_e2e import Browser, application, cli, expect, fields
from tenant_e2e import new_project

def run(tmp):
    env=os.environ.copy()
    for var in ('WORKSPACE_CONFIG','WORKSPACE_OIDC_CLIENT_ID','WORKSPACE_OIDC_CLIENT_SECRET','WORKSPACE_EMERGENCY_LOCAL'):
        env.pop(var,None)
    env.update(WORKSPACE_DB='sqlite',WORKSPACE_DATA=str(tmp/'data'))
    cli(env,'init','owner@example.test','Owner',password=True)
    with application(env) as root:
        owner=Browser(root); owner.login('owner',step_up=False)
        pid=new_project(owner,'MFA check')
        payload={'project_id':pid,'direction':'income','amount':'1.00','currency':'EUR','booked_on':'2026-01-01','memo':'MFA required'}
        form=fields(owner.call('?p=finance')[1],'cash.post') | payload
        expect(owner.call('?p=finance',form)[0]==403,'payment without TOTP denied')
        expect(owner.call('?p=export')[0]==403,'CSV without TOTP denied')
        code=owner.step_up('owner')
        expect(owner.call('?p=finance',form)[0]==200,'payment after TOTP')
        expect(b'MFA required' in owner.call('?p=export')[1],'ledger available after TOTP')
        expect(owner.post('?p=security','mfa.verify',code=code)[0]==403,'TOTP replay denied')
        second=Browser(root); second.login('owner',step_up=False)
        expect(second.call('?p=export')[0]==403,'MFA is bound to browser session')
        cli(env,'auth:mfa-reset','owner@example.test')
        expect(owner.call('?p=overview')[3].endswith('?p=login'),'MFA reset revokes sessions')
        expect(second.call('?p=overview')[3].endswith('?p=login'),'MFA reset revokes other sessions')
if __name__=='__main__':
    with TemporaryDirectory(prefix='cws-auth-http-') as d: run(Path(d))
    print('OK · MFA HTTP checks')
