"""Real HTTP password login in mixed mode and strict-tenant denial.
The live OIDC provider is not mocked or contacted. Callback principal checks
are covered by auth_db.php; actual IdP acceptance is a staging requirement.
"""
import os
import sqlite3
from contextlib import closing
from pathlib import Path
from tempfile import TemporaryDirectory
from http.cookiejar import CookieJar, DefaultCookiePolicy
from urllib import request
from web_e2e import Browser, application, cli, expect, fields


def run(tmp):
    env=os.environ.copy()
    for name in ('WORKSPACE_CONFIG','WORKSPACE_OIDC_REQUIRED','WORKSPACE_OIDC_CLIENT_ID','WORKSPACE_OIDC_CLIENT_SECRET','WORKSPACE_EMERGENCY_LOCAL','WORKSPACE_HTTPS'):
        env.pop(name,None)
    env.update(WORKSPACE_DB='sqlite',WORKSPACE_DATA=str(tmp/'data'),WORKSPACE_AUTH_POLICY='local')
    cli(env,'init','owner@example.test','Owner',password=True)
    with closing(sqlite3.connect(tmp/'data/workspace.sqlite')) as c,c:
        c.execute("INSERT INTO organizations(id,name) VALUES(2,'Strict')")
        c.execute("INSERT INTO organization_members VALUES(2,1,'owner')")
        c.execute("INSERT INTO organization_auth_policies VALUES(2,'required')")
    with application(env) as root:
        b=Browser(root); status,body,_,_=b.call('?p=login')
        expect(status==200 and b'oidc.begin' not in body and b'value="login"' in body,'local-only form')
        b.login('owner',step_up=False)
        expect(b.post('?p=overview','org.switch',org=2)[0]==403,'local-only cannot bypass strict org')
    env['WORKSPACE_AUTH_POLICY']='mixed'
    with application(env) as root:
        b=Browser(root)
        # The app sets Secure cookies when OIDC is enabled. The isolated HTTP
        # fixture explicitly accepts them; never do this in a real browser.
        b.opener=request.build_opener(request.HTTPCookieProcessor(CookieJar(policy=DefaultCookiePolicy(secure_protocols=('http','https')))))
        status,body,_,_=b.call('?p=login')
        expect(status==200 and b'oidc.begin' in body and b'value="login"' in body,'mixed form exposes both methods')
        b.login('owner',step_up=False)
        expect(b.post('?p=overview','org.switch',org=2)[0]==403,'unbound identity cannot enter strict org')
        with closing(sqlite3.connect(tmp/'data/workspace.sqlite')) as c,c:
            c.execute("INSERT INTO oidc_identities VALUES(1,'https://id.skif4er.ru/realms/skif4er','test-owner')")
        expect(b.post('?p=overview','org.switch',org=2)[0]==503,'linked identity fails closed when IdP configuration unavailable')
        expect(b.call('?p=overview')[0]==200,'original allowed organization still accessible')
        with closing(sqlite3.connect(tmp/'data/workspace.sqlite')) as c,c:
            c.execute("INSERT INTO organization_auth_policies VALUES(1,'required')")
        expect(b.call('?p=overview')[3].endswith('?p=login'),'tightening invalidates protected password session')
        expect(b.call('?p=login')[0]==200,'login remains accessible')
    env['WORKSPACE_AUTH_POLICY']='required'
    with application(env) as root:
        b=Browser(root);b.opener=request.build_opener(request.HTTPCookieProcessor(CookieJar(policy=DefaultCookiePolicy(secure_protocols=('http','https')))))
        status,body,_,_=b.call('?p=login')
        expect(status==200 and b'oidc.begin' in body and b'value="login"' not in body,'required form hides ordinary password')
        form=fields(body,'oidc.begin') | {'act':'login','email':'owner@example.test','password':'test-password-only'}
        expect(b.call(data=form)[0]==401,'forged password POST blocked in required mode')

if __name__=='__main__':
    with TemporaryDirectory(prefix='cws-policy-http-') as folder: run(Path(folder))
    print('OK · authentication policy HTTP checks')
