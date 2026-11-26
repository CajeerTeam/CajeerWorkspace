"""Real browser-local HTTP sessions, CSRF, MFA and operator recovery (no live IdP)."""
import os, sqlite3
from pathlib import Path
from tempfile import TemporaryDirectory
from contextlib import closing
from web_e2e import Browser, application, cli, expect, fields, Fields, PASSWORD


def run(tmp):
    env=os.environ.copy()
    env.update(WORKSPACE_DB='sqlite',WORKSPACE_DATA=str(tmp/'data'),WORKSPACE_AUTH_POLICY='local')
    for key in ('WORKSPACE_HTTPS','WORKSPACE_OIDC_CLIENT_ID','WORKSPACE_OIDC_CLIENT_SECRET','WORKSPACE_EMERGENCY_LOCAL'):
        env.pop(key,None)
    cli(env,'init','owner@example.test','Owner',password=True)
    cli(env,'user:add','other@example.test','Other','developer',password=True)
    with application(env) as root:
        a=Browser(root); a.login('owner'); b=Browser(root); b.login('owner',step_up=False); c=Browser(root); c.login('other',step_up=False)
        page=a.call('?p=security')[1]; parser=Fields(); parser.feed(page.decode()); forms=[x for x in parser.forms if x.get('act')=='identity.revoke']
        expect(len(forms)==2,'own two sessions shown')
        with closing(sqlite3.connect(tmp/'data/workspace.sqlite')) as db:
            foreign=db.execute('SELECT handle FROM staff_sessions WHERE user_id=2').fetchone()[0]
        expect(a.call('?p=security',forms[0]|{'handle':foreign})[0]==404,'foreign session refused')
        expect(a.call('?p=security',forms[0]|{'csrf':'invalid'})[0]==403,'CSRF denied')
        expect(b.post('?p=security','identity.revoke',handle=forms[0]['handle'])[0]==403,'MFA required')
        # Determine the other handle from the second browser's current session using its own page.
        bpage=b.call('?p=security')[1]
        import re
        marker='текущая'.encode()
        match=re.search(rb'<strong>[^<]*'+marker+rb'[^<]*</strong>.*?name="handle" value="([a-f0-9]{64})"',bpage,re.S)
        expect(bool(match),'current session identified')
        expect(a.call('?p=security',forms[0]|{'handle':match.group(1).decode()})[0]==200,'other session revoked')
        expect(b.call('?p=overview')[3].endswith('?p=login'),'revoked browser denied')
        expect(a.call('?p=overview')[3].endswith('?p=overview'),'current browser stays signed in')
        expect(c.call('?p=overview')[3].endswith('?p=overview'),'foreign unaffected')
        cli(env,'auth:recover','owner@example.test','verified-subject','TICKET-123')
        expect(a.call('?p=overview')[3].endswith('?p=login'),'recovery invalidates current browser')
        a.login('owner'); page=a.call('?p=security')[1]
        expect('привязан'.encode() in page,'new binding visible')
        expect(a.post('?p=security','identity.unlink',password='wrong-password')[0]==403,'wrong password refused')
        expect(a.post('?p=security','identity.unlink',password=PASSWORD)[3].endswith('?p=login'),'unlink logs out')
        expect('unbind' in cli(env,'auth:history','owner@example.test').stdout,'operator history available')
        expect('verified-subject' not in cli(env,'auth:history','owner@example.test').stdout,'no subject in audit')

if __name__=='__main__':
    with TemporaryDirectory(prefix='cws-identity-http-') as d: run(Path(d))
    print('OK · identity and session HTTP checks')
