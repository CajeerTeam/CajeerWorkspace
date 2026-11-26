#!/usr/bin/env python3
"""Bounded read-only HTTP load profile for a separately authorized staging site."""
import argparse
from concurrent.futures import ThreadPoolExecutor
import json
import math
import os
from pathlib import Path
import re
import sys
import time
from urllib import error, parse, request


class NoRedirect(request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs):
        return None


def require(ok,message):
    if not ok: raise ValueError(message)


def prepare(config):
    origin=config['origin']; u=parse.urlsplit(origin)
    require(u.scheme=='https' or (u.scheme=='http' and u.hostname in ('127.0.0.1','localhost','::1')), 'HTTPS required outside loopback')
    require(u.hostname and not u.username and not u.password and not u.query and not u.fragment and u.path in ('','/'), 'Origin only')
    routes=config['routes']; require(isinstance(routes,list) and 1<=len(routes)<=20,'1–20 routes required')
    names=set(); result=[]
    for r in routes:
        name=r['name']; path=r['path']; marker=r['contains']; parsed=parse.urlsplit(path)
        require(re.fullmatch(r'[a-zA-Z0-9_-]{1,40}',name) and name not in names,'Unique route names required');names.add(name)
        require(path.startswith('/') and not path.startswith('//') and not parsed.scheme and not parsed.netloc and not parsed.fragment and '\\' not in path and not any(ord(c)<32 for c in path),'Invalid route')
        # GET-only; allow explicit Workspace read routes, not arbitrary action or webhook endpoints.
        require(parsed.path in ('/','/index.php','/health.php'),'Only Workspace read pages and health.php are allowed')
        q=parse.parse_qs(parsed.query,keep_blank_values=True)
        allowed={'overview','projects','project','task','workload','search','notifications','digest','finance','documents','invoices','contracts','releases','incidents','reliability'}
        require(parsed.path=='/health.php' and not q or parsed.path in ('/','/index.php') and set(q.get('p',[]))<=allowed and len(q.get('p',[]))<=1 and not set(q)&{'act','token','download','callback'},'Unapproved read page')
        require(isinstance(marker,str) and 1<=len(marker)<=300,'Response marker required')
        cookie=None
        if r.get('cookie_file'):
            f=Path(r['cookie_file']);require(f.is_absolute() and f.is_file() and not f.is_symlink() and f.stat().st_mode&0o077==0,'Cookie file must be private')
            require(f.stat().st_size<=8192,'Cookie file too large');cookie=f.read_text().strip()
            require(cookie and not any(ord(c)<32 or ord(c)>126 for c in cookie),'Invalid cookie')
        result.append((name,origin.rstrip('/')+path,marker.encode(),cookie))
    return result


def percentile(values,p):
    return round(sorted(values)[max(0,math.ceil(len(values)*p)-1)],3) if values else None


def run(config):
    routes=prepare(config); count=config['requests']; concurrency=config['concurrency']; timeout=config.get('timeout_seconds',5)
    require(type(count) is int and len(routes)<=count<=100000 and type(concurrency) is int and 1<=concurrency<=min(count,100),'Invalid workload bounds')
    require(isinstance(timeout,(int,float)) and 0.1<=timeout<=30,'Invalid timeout')
    p95_limit=config['p95_limit_ms'];error_limit=config.get('max_error_rate',0)
    require(isinstance(p95_limit,(int,float)) and 0<p95_limit<=30000 and isinstance(error_limit,(int,float)) and 0<=error_limit<=1,'Invalid thresholds')
    def one(i):
        name,url,marker,cookie=routes[i%len(routes)]; started=time.monotonic(); code='ok'
        try:
            opener=request.build_opener(NoRedirect(),request.ProxyHandler({}))
            headers={'Accept':'text/html, application/json','User-Agent':'CajeerWorkspace-Pilot-Load/0.9.1','Cache-Control':'no-cache'}
            if cookie: headers['Cookie']=cookie
            with opener.open(request.Request(url,headers=headers,method='GET'),timeout=timeout) as r:
                body=r.read(4*1024*1024+1)
                if r.status!=200:code='http_'+str(r.status)
                elif len(body)>4*1024*1024:code='oversize'
                elif marker not in body:code='marker_missing'
        except error.HTTPError as e:code='http_'+str(e.code)
        except (OSError,ValueError):code='transport_error'
        return name,code,(time.monotonic()-started)*1000
    started=time.monotonic(); rows=[]
    # Each thread submits a fixed share; no unbounded queue of requests.
    def worker(i):return [one(j) for j in range(i,count,concurrency)]
    with ThreadPoolExecutor(max_workers=concurrency) as pool:
        for result in pool.map(worker,range(concurrency)): rows.extend(result)
    elapsed=time.monotonic()-started
    def summarize(items):
        good=[x[2] for x in items if x[1]=='ok'];errors={}
        for _,code,_ in items:
            if code!='ok':errors[code]=errors.get(code,0)+1
        return {'requests':len(items),'success':len(good),'errors':errors,'error_rate':round((len(items)-len(good))/len(items),6),'p50_ms':percentile(good,.50),'p95_ms':percentile(good,.95),'p99_ms':percentile(good,.99),'max_ms':round(max(good),3) if good else None}
    total=summarize(rows); per={r[0]:summarize([x for x in rows if x[0]==r[0]]) for r in routes}
    passed=all(s['p95_ms'] is not None and s['p95_ms']<=p95_limit and s['error_rate']<=error_limit for s in [total,*per.values()])
    return {'status':'passed' if passed else 'failed','requests':count,'concurrency':concurrency,'duration_seconds':round(elapsed,3),'requests_per_second':round(count/elapsed,2),'thresholds':{'p95_ms':p95_limit,'max_error_rate':error_limit},'total':total,'routes':per}


def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--profile',type=Path,required=True);p.add_argument('--staging',action='store_true',required=True);a=p.parse_args()
    try:
        require(a.profile.stat().st_size<=65536,'Profile too large');r=run(json.loads(a.profile.read_text()));print(json.dumps(r,indent=2));return 0 if r['status']=='passed' else 1
    except (OSError,ValueError,KeyError,TypeError):print('{"status":"invalid_profile_or_environment"}',file=sys.stderr);return 2

if __name__=='__main__':sys.exit(main())
