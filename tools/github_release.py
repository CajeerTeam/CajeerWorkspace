#!/usr/bin/env python3
"""Publish an admitted Workspace release, without uploading private evidence."""
import argparse
import datetime as dt
import io
import json
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import sys
import urllib.request
import urllib.error
import urllib.parse
import candidate
import production as prod
import recovery
import release

REPO = 'CajeerTeam/CajeerWorkspace'
API = 'repos/' + REPO
META = 'workspace-release.json'
SIG = META + '.sig'
require = prod.require


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def github_request(url, method='GET', body=None, content_type='application/json'):
    token=os.environ.get('WORKSPACE_GITHUB_RELEASE_TOKEN','')
    require(token and not any(c.isspace() for c in token), 'Set WORKSPACE_GITHUB_RELEASE_TOKEN on the trusted publisher')
    request=urllib.request.Request(url, data=body, method=method, headers={
        'Authorization':'Bearer '+token,'Accept':'application/vnd.github+json',
        'X-GitHub-Api-Version':'2022-11-28','User-Agent':'CajeerWorkspace-release',
        'Content-Type':content_type})
    opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect())
    try:
        with opener.open(request,timeout=60) as response:
            raw=response.read(4*1024*1024+1)
    except urllib.error.HTTPError as e:
        raise ValueError(f'GitHub HTTP {e.code}; inspect the draft before retrying') from None
    except (urllib.error.URLError,TimeoutError):
        raise ValueError('GitHub network error; inspect the draft before retrying') from None
    require(len(raw)<=4*1024*1024,'Oversized GitHub response')
    return prod.decode(raw)


def api(path, method='GET', data=None):
    return github_request('https://api.github.com/'+API+path,method,
                          None if data is None else recovery.canonical(data))


def upload_asset(rid, path):
    url='https://uploads.github.com/'+API+'/releases/'+str(rid)+'/assets?'+urllib.parse.urlencode({'name':path.name})
    return github_request(url,'POST',path.read_bytes(),'application/octet-stream')


def check_tag(tag, commit, request=api):
    ref = request('/git/ref/tags/' + tag)
    require(ref.get('ref') == 'refs/tags/' + tag and ref.get('object',{}).get('type')=='commit' and ref.get('object',{}).get('sha')==commit,
            'Remote lightweight tag must point to the accepted commit')


def check_source(archive, source, commit):
    require(candidate.command(['git','-C',source,'rev-parse',commit+'^{commit}']).decode().strip()==commit,'Invalid source commit')
    raw = candidate.command(['git','-C',source,'archive','--format=tar',commit])
    payload = candidate.archive_payload(archive,prod.VERSION)
    payload.pop('SHA256SUMS')
    with tarfile.open(fileobj=io.BytesIO(raw),mode='r:') as tar:
        for name, data in payload.items():
            member=tar.getmember(name)
            require(member.isfile() and tar.extractfile(member).read()==data,'Package differs from accepted Git commit: '+name)


def public_metadata(bundle, item, key_sha):
    archive=bundle/prod.NAME
    m, matrix_hash=prod.matrix(archive)
    runtime=m['runtime']
    return {'format':1,'repository':REPO,'channel':'stable','version':prod.VERSION,'tag':'v'+prod.VERSION,
            'commit':item['summary']['ci_commit'],'schema':item['schema'],
            'supported_from':[{'version':prod.PREVIOUS,'schema':candidate.OLD_SCHEMA,'archive_sha256':item['previous_sha256']}],
            'runtime':{'php_min':runtime['php_min'],'php_max_exclusive':'9.0','architecture':runtime['architecture'],
                       'sqlite_min':runtime['sqlite_min'],'postgres_min':runtime['postgres_min'],
                       'python_min':runtime['python_min'],'extensions':['pdo','curl','session','openssl']},
            'package':{'name':prod.NAME,'size':archive.stat().st_size,'sha256':item['archive_sha256'],
                       'signature_sha256':recovery.digest(bundle/(prod.NAME+'.sig'))},
            'matrix_sha256':matrix_hash,'acceptance_sha256':recovery.digest(bundle/'acceptance.json'),
            'release_key_sha256':key_sha,'accepted_at':prod.timestamp(item['summary']['checked_at']).astimezone(dt.timezone.utc).isoformat()}


def check_assets(row, tag, paths, draft=True):
    require(row.get('tag_name')==tag and row.get('draft') is draft and row.get('prerelease') is False,'Unexpected release state')
    assets=row.get('assets',[])
    require(len(assets)==len(paths) and {a.get('name') for a in assets}=={p.name for p in paths},'Unexpected release assets')
    expected={p.name:p for p in paths}
    for a in assets:
        p=expected[a['name']]
        require(a.get('state')=='uploaded' and a.get('size')==p.stat().st_size and a.get('digest')=='sha256:'+recovery.digest(p),'GitHub asset digest or size mismatch: '+p.name)


def publish(bundle, registry, pin, private, source, request=api, upload=upload_asset):
    bundle=recovery.private_dir(bundle)
    # All public files and the private gate are evaluated on a private immutable-in-use snapshot.
    with tempfile.TemporaryDirectory(prefix='workspace-github-') as d:
        stage=Path(d)/'bundle';stage.mkdir(mode=0o700)
        names={'acceptance.json',*prod.REPORTS,*(r+'.sig' for r in prod.ROLES),prod.NAME,prod.NAME+'.sha256',prod.NAME+'.sig','release.sig'}
        require({p.name for p in bundle.iterdir()}==names,'Unexpected bundle files')
        for n in names: recovery.write(stage/n,prod.read(bundle/n,release.MAX_TOTAL if n==prod.NAME else prod.MAX_JSON))
        item=prod.verify(stage,registry,pin);prod.fresh(item)
        entries,keys=prod.trust(registry,pin)
        meta=public_metadata(stage,item,entries['release']['sha256'])
        check_source(stage/prod.NAME,source,meta['commit'])
        public=Path(d)/'public';public.mkdir(mode=0o700)
        for n in (prod.NAME,prod.NAME+'.sig'): recovery.write(public/n,(stage/n).read_bytes())
        recovery.write(public/META,recovery.canonical(meta))
        prod.sign_file(public/META,private,public/SIG)
        candidate.verify_signature(public/META,public/SIG,keys['release'],entries['release']['sha256'])
        paths=[public/n for n in (prod.NAME,prod.NAME+'.sig',META,SIG)]
        tag=meta['tag'];check_tag(tag,meta['commit'],request)
        # Fail closed on existing drafts or releases; no overwrite or replacement.
        for page in range(1,11):
            rows=request('/releases?per_page=100&page='+str(page))
            require(isinstance(rows,list) and not any(r.get('tag_name')==tag for r in rows),'Release or draft already exists for tag')
            if len(rows)<100: break
        else: raise ValueError('Release inventory too large to safely check for duplicates')
        row=request('/releases','POST',{'tag_name':tag,'target_commitish':meta['commit'],'name':'CajeerWorkspace '+prod.VERSION,'body':'Signed Workspace distribution. Verify workspace-release.json before use.','draft':True,'prerelease':False})
        rid=row.get('id');require(type(rid) is int and rid>0 and row.get('draft') is True and row.get('tag_name')==tag,'Draft not created')
        for path in paths:
            asset=upload(rid,path)
            require(asset.get('name')==path.name and asset.get('state')=='uploaded','Asset upload not confirmed: '+path.name)
        check_assets(request('/releases/'+str(rid)),tag,paths)
        check_tag(tag,meta['commit'],request)
        prod.fresh(item)
        row=request('/releases/'+str(rid),'PATCH',{'draft':False,'prerelease':False,'make_latest':'true'})
        check_assets(row,tag,paths,False)
        check_tag(tag,meta['commit'],request)
        return {'status':'published','version':prod.VERSION,'release_id':rid,'commit':meta['commit'],'archive_sha256':meta['package']['sha256']}


def main():
    p=argparse.ArgumentParser(description=__doc__)
    for name in ('bundle','trust','private-key','source'):p.add_argument('--'+name,type=Path,required=True)
    p.add_argument('--trust-sha256',required=True)
    a=p.parse_args();os.umask(0o077)
    try:
        print(json.dumps(publish(a.bundle,a.trust,a.trust_sha256,a.private_key,a.source)))
    except (ValueError,OSError,KeyError,TypeError,subprocess.SubprocessError,tarfile.TarError) as e:
        print('Publication failed: '+str(e),file=sys.stderr);return 1
    return 0

if __name__=='__main__':sys.exit(main())
