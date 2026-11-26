"""Run the actual Workspace read profile on a disposable native PHP/SQLite installation."""
import json
import os
from pathlib import Path
import sys
import tempfile
from web_e2e import application, Browser, cli, fields

sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'tools'))
from load import run

with tempfile.TemporaryDirectory() as tmp:
    root=Path(tmp);env=os.environ.copy();env.update(WORKSPACE_DB='sqlite',WORKSPACE_DATA=str(root/'data'))
    for k in list(env):
        if k.startswith('WORKSPACE_OIDC_') or k=='WORKSPACE_PUBLIC_URL':env.pop(k)
    cli(env,'init','owner@example.test','Owner',password=True)
    with application(env) as origin:
        user=Browser(origin);user.login('owner')
        form=fields(user.call('?p=projects')[1],'project.create')|{'title':'RecoveryLoadProject','kind':'internal','client':''}
        assert user.call('?p=projects',form)[0]==200
        jar=user.opener.handlers
        cookie=next(h.cookiejar for h in jar if hasattr(h,'cookiejar'))
        f=root/'cookie';f.write_text('; '.join(f'{c.name}={c.value}' for c in cookie));f.chmod(0o600)
        cfg={'origin':origin.rstrip('/'),'requests':120,'concurrency':4,'p95_limit_ms':5000,'routes':[
            {'name':'health','path':'/health.php','contains':'"status":"ready"'},
            {'name':'projects','path':'/?p=projects','contains':'RecoveryLoadProject','cookie_file':str(f)},
            {'name':'project','path':'/?p=project&id=1','contains':'RecoveryLoadProject','cookie_file':str(f)}]}
        result=run(cfg);print(json.dumps(result,indent=2));assert result['status']=='passed'
