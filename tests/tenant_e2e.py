"""Two-tenant HTTP acceptance, optionally repeated after real SQLite -> PostgreSQL import."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import sqlite3
from tempfile import TemporaryDirectory
import time
from web_e2e import Browser, application, cli, expect, fields, sample_pdf, upload, PASSWORD


def new_project(browser, title):
    response=browser.post('?p=projects','project.create',title=title,client='',kind='internal')
    expect(response[0]==200 and 'p=project&id=' in response[3], 'project created')
    return int(response[3].split('id=')[-1])


def add_pdf(browser, pid, name, data):
    form=fields(browser.call('?p=documents')[1],'document.version')|{'project_id':pid,'title':name,'kind':'contract'}
    result=browser.call('?p=documents',*upload(form,data))
    expect(result[0]==200 and 'p=document&id=' in result[3], 'PDF uploaded')
    return int(result[3].split('id=')[-1])


def cash(browser,pid,memo):
    result=browser.post('?p=finance','cash.post',project_id=pid,booked_on=time.strftime('%Y-%m-%d',time.gmtime()),direction='income',amount='12.34',currency='PLN',memo=memo)
    expect(result[0]==200,'cash written')


def issue(browser,email,role='viewer'):
    r=browser.post('?p=team','invite.issue',email=email,role=role,hours=1)
    expect(r[0]==200,'invite issued')
    token=re.search(r'aria-label="Одноразовый код" readonly value="([a-f0-9]{64})"',r[1].decode())
    expect(token is not None,'raw code displayed once')
    expect(token.group(1).encode() not in browser.call('?p=team')[1],'raw code not redisplayed')
    return token.group(1)


def register(root,token,name):
    browser=Browser(root)
    return browser.post('?p=invite','invite.accept',token=token,name=name,password=PASSWORD)


def check_boundary(browser, own_project, foreign_project, own_doc, foreign_doc, own_pdf, own_label, foreign_label):
    expect(browser.call(f'?p=project&id={own_project}')[0]==200,'own project visible')
    expect(browser.call(f'?p=project&id={foreign_project}')[0]==404,'foreign project denied')
    expect(browser.call(f'?p=document&id={foreign_doc}')[0]==404,'foreign document metadata denied')
    expect(browser.call(f'?p=download&id={foreign_doc}&v=1')[0]==404,'foreign PDF denied')
    expect(browser.call(f'?p=download&id={own_doc}&v=1')[1]==own_pdf,'own PDF exact bytes')
    expect(browser.call(f'?p=export&project={foreign_project}')[0]==404,'foreign CSV filter denied')
    csv=browser.call('?p=export')[1]
    expect(own_label.encode() in csv and foreign_label.encode() not in csv,'CSV only own tenant')
    expect(f'href="./?p=project&amp;id={foreign_project}"'.encode() not in browser.call('?p=search&q='+foreign_label)[1],'foreign search result denied')
    expect(f'href="./?p=project&amp;id={own_project}"'.encode() in browser.call('?p=search&q='+own_label)[1],'own search works')
    expect(foreign_label.encode() not in browser.call('?p=overview')[1],'overview isolated')
    expect(foreign_label.encode() not in browser.call('?p=projects')[1],'project list isolated')


def run(tmp,postgres):
    env=os.environ.copy(); env.pop('WORKSPACE_CONFIG',None)
    env.update(WORKSPACE_DB='sqlite',WORKSPACE_DATA=str(tmp/'data'))
    cli(env,'init','owner@example.test','Owner',password=True)
    cli(env,'user:add','dev@example.test','Developer','developer',password=True)
    pdf1,pdf2=sample_pdf('first'),sample_pdf('second')
    with application(env) as root:
        owner,dev=Browser(root),Browser(root); owner.login('owner'); dev.login('dev')
        first=new_project(owner,'AlphaPrivate')
        cash(owner,first,'AlphaPrivate'); doc1=add_pdf(owner,first,'AlphaPrivate',pdf1)
        expect(dev.call(f'?p=project&id={first}')[0]==404,'closed project before explicit grant')
        expect(owner.post('?p=team','team.create',name='Developers')[0]==200,'team created')
        expect(owner.post('?p=team','team.add',team_id=1,member_id=2)[0]==200,'team membership added')
        expect(dev.call(f'?p=project&id={first}')[0]==404,'team does not grant project access')
        expect(owner.post(f'?p=project&id={first}','project.add',member_id=2)[0]==200,'project membership granted')
        expect(dev.call(f'?p=project&id={first}')[0]==200,'project membership works')
        expect(owner.post(f'?p=project&id={first}','project.remove',member_id=2)[0]==200,'project membership revoked')
        expect(dev.call(f'?p=project&id={first}')[0]==404,'existing session loses project access')
        expect(owner.post('?p=team','org.create',name='Second organization')[0]==200,'second organization created')
        code=issue(owner,'owner2@example.test','owner')
        expect(register(root,code,'Second owner')[0]==200,'invited owner registered')
        expect(register(root,code,'Replay')[0]==404,'invite single use')
        second_owner=Browser(root); second_owner.login('owner2')
        second=new_project(second_owner,'BetaPrivate'); cash(second_owner,second,'BetaPrivate'); doc2=add_pdf(second_owner,second,'BetaPrivate',pdf2)
        check_boundary(second_owner,second,first,doc2,doc1,pdf2,'BetaPrivate','AlphaPrivate')
        expect(owner.post('?p=overview','org.switch',org=1)[0]==200,'organization switch')
        check_boundary(owner,first,second,doc1,doc2,pdf1,'AlphaPrivate','BetaPrivate')
        invalid=fields(owner.call('?p=finance')[1],'cash.post')|{'project_id':second,'booked_on':time.strftime('%Y-%m-%d',time.gmtime()),'direction':'income','amount':'1','currency':'PLN','memo':'foreign write'}
        expect(owner.call('?p=finance',invalid)[0]==404,'foreign cash POST denied')
        forged=fields(owner.call(f'?p=project&id={first}')[1],'project.add')|{'member_id':3}
        expect(owner.call(f'?p=project&id={first}',forged)[0]==404,'foreign user cannot join project')
        # The existing account must authenticate before accepting an invite for its email.
        code=issue(second_owner,'dev@example.test')
        expect(register(root,code,'Impostor')[0]==403,'existing-account invite cannot replace password')
        expect(dev.post('?p=invite','invite.accept',token=code)[0]==200,'authenticated existing user joins second tenant')
        expect(dev.call(f'?p=project&id={second}')[0]==404,'invitation grants organization but not project')
        revoked=issue(second_owner,'revoked@example.test')
        expect(second_owner.post('?p=team','invite.revoke')[0]==200,'invite revoked')
        expect(register(root,revoked,'Revoked')[0]==404,'revoked code denied')
        expired=issue(second_owner,'expired@example.test')
        # Deterministically simulate time passing without sleeping an hour.
        with sqlite3.connect(Path(env['WORKSPACE_DATA'])/'workspace.sqlite') as conn:
            conn.execute('UPDATE invitations SET expires_at=? WHERE token_hash=?',(int(time.time())-1,hashlib.sha256(expired.encode()).hexdigest()))
        expect(register(root,expired,'Expired')[0]==404,'expired code denied')
    if postgres:
        # A corrupt source must not leave even the PostgreSQL schema behind.
        corrupt=tmp/'corrupt'; corrupt.mkdir()
        cli(env,'backup',str(corrupt/'workspace.sqlite'))
        with sqlite3.connect(corrupt/'workspace.sqlite') as conn:
            conn.execute('UPDATE document_versions SET bytes=? WHERE document_id=?',(b'%PDF-corrupted\n%%EOF',doc1))
        bad=cli(env|{'WORKSPACE_DATA':str(corrupt)},'postgres:import',str(tmp/'bad-snapshot.sqlite'),success=False)
        expect(bad.returncode!=0,'corrupt PDF aborts migration')
        result=cli(env,'postgres:import',str(tmp/'source-snapshot.sqlite'))
        report=json.loads(result.stdout)
        expect(report['tables']['document_versions']['rows']==2 and report['tables']['organizations']['rows']==2,'real import row counts')
        expect(hashlib.sha256((tmp/'source-snapshot.sqlite').read_bytes()).hexdigest()==report['backup']['sha256'],'source snapshot checksum')
        target=env|{'WORKSPACE_DB':'pgsql','WORKSPACE_DATA':str(tmp/'pgdata')}
        expect(cli(env,'postgres:import',str(tmp/'repeat.sqlite'),success=False).returncode!=0,'nonempty target rejected')
        with application(target) as root:
            owner,other=Browser(root),Browser(root); owner.login('owner'); other.login('owner2')
            check_boundary(owner,first,second,doc1,doc2,pdf1,'AlphaPrivate','BetaPrivate')
            check_boundary(other,second,first,doc2,doc1,pdf2,'BetaPrivate','AlphaPrivate')
            third=new_project(other,'AfterImport')
            expect(third>max(first,second),'project sequence restored')
            cash(other,third,'AfterImport')
            third_doc=add_pdf(other,third,'AfterImport',pdf1)
            expect(third_doc>max(doc1,doc2) and other.call(f'?p=download&id={third_doc}&v=1')[1]==pdf1,'document sequence and BYTEA writes')
            expect(b'AfterImport' in other.call('?p=export')[1],'PostgreSQL finance writes')

if __name__=='__main__':
    parser=argparse.ArgumentParser(); parser.add_argument('--postgres',action='store_true'); args=parser.parse_args()
    with TemporaryDirectory(prefix='cws-tenant-') as d: run(Path(d),args.postgres)
    print('OK · tenant HTTP checks'+(' and PostgreSQL migration' if args.postgres else ''))
