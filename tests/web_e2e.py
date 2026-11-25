"""End-to-end checks on a disposable installation. Uses PHP CLI, OpenSSL and Python's standard library."""
from concurrent.futures import ThreadPoolExecutor
from contextlib import contextmanager
from hashlib import md5, sha256
from html.parser import HTMLParser
from http.cookiejar import CookieJar
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from tempfile import TemporaryDirectory
from urllib import error, parse, request
import json
import os
import secrets
import shutil
import socket
import sqlite3
import ssl
import subprocess
import threading
import time

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BIN") or shutil.which("php")
PASSWORD = "test-password-only"
CHECKS = 0


def expect(value, name):
    global CHECKS
    if not value:
        raise AssertionError(name)
    CHECKS += 1


def cli(env, *args, password=False, success=True, extra=()):
    proc = subprocess.run([PHP, *extra, "bin/workspace", *args], cwd=ROOT, env=env,
                          input=(PASSWORD+"\n") if password else "", text=True, capture_output=True)
    if success and proc.returncode:
        raise AssertionError((args, proc.stdout, proc.stderr))
    return proc


class Fields(HTMLParser):
    def __init__(self):
        super().__init__()
        self.forms, self.current = [], None

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "form":
            self.current = {}
            self.forms.append(self.current)
        elif tag == "input" and self.current is not None and attrs.get("type") == "hidden":
            self.current[attrs["name"]] = attrs.get("value", "")

    def handle_endtag(self, tag):
        if tag == "form":
            self.current = None


def fields(body, action):
    parser = Fields()
    parser.feed(body.decode())
    return next(form for form in parser.forms if form.get("act") == action)


@contextmanager
def application(env):
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    root = f"http://127.0.0.1:{port}/"
    with subprocess.Popen([PHP, "-S", f"127.0.0.1:{port}", "-t", "public"], cwd=ROOT, env=env,
                          stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL) as proc:
        try:
            for _ in range(100):
                if proc.poll() is not None:
                    raise RuntimeError("PHP HTTP server exited")
                try:
                    with request.urlopen(root+"health.php", timeout=1) as response:
                        if response.status == 200:
                            break
                except OSError:
                    time.sleep(.035)
            else:
                raise RuntimeError("Workspace did not become ready")
            yield root
        finally:
            proc.terminate()
            proc.wait(timeout=5)


class Browser:
    def __init__(self, root):
        self.root = root
        self.opener = request.build_opener(request.HTTPCookieProcessor(CookieJar()))

    def call(self, path="", data=None, content_type=None):
        if isinstance(data, dict):
            data = parse.urlencode(data).encode()
        req = request.Request(self.root+path, data=data)
        if content_type:
            req.add_header("Content-Type", content_type)
        try:
            response = self.opener.open(req, timeout=5)
        except error.HTTPError as ex:
            response = ex
        with response:
            return response.status, response.read(), response.headers, response.geturl()

    def login(self, role):
        page = self.call("?p=login")[1]
        form = fields(page, "login") | {"email":role+"@example.test","password":PASSWORD}
        status, _, headers, url = self.call(data=form)
        expect(status == 200 and url.endswith("?p=overview"), role+" login")

    def post(self, path, action, **values):
        form = fields(self.call(path)[1], action)
        return self.call(path, form | values)


def upload(form, pdf):
    boundary = "workspace-"+secrets.token_hex(12)
    data = bytearray()
    for name, value in form.items():
        data.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
    data.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="pdf"; filename="contract.pdf"\r\nContent-Type: application/pdf\r\n\r\n'.encode())
    data.extend(pdf)
    data.extend(f'\r\n--{boundary}--\r\n'.encode())
    return bytes(data), "multipart/form-data; boundary="+boundary


def sample_pdf(label):
    # A minimal one-page PDF with an actual xref table.
    objs = [b'<< /Type /Catalog /Pages 2 0 R >>', b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] >>', f'<< /Title ({label}) >>'.encode()]
    buf = bytearray(b"%PDF-1.4\n")
    offsets = []
    for index, value in enumerate(objs, 1):
        offsets.append(len(buf))
        buf.extend(f"{index} 0 obj\n".encode()+value+b"\nendobj\n")
    start = len(buf)
    buf.extend(b"xref\n0 5\n0000000000 65535 f \n")
    for offset in offsets:
        buf.extend(f"{offset:010d} 00000 n \n".encode())
    buf.extend(f"trailer\n<< /Size 5 /Root 1 0 R /Info 4 0 R >>\nstartxref\n{start}\n%%EOF\n".encode())
    return bytes(buf)


class Provider(BaseHTTPRequestHandler):
    expected = {"engine/health/ready":"ready","forum/health/ready":"ready","book/health/ready":"ok",
                "lite/health/ready":"ready","logs/ready":"ok","bots/api/health":"ok","servers/api/v1/ready":"ready"}
    fail_book = False
    panel_key = b"fixture-panel-key"
    fail_version = False

    def log_message(self, *args):
        pass

    def reply(self, code, data, mime="application/json"):
        self.send_response(code)
        self.send_header("Content-Type", mime)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        if self.fail_book and self.path == "/book/health/ready":
            return self.reply(503,b'{"status":"unavailable"}')
        status = self.expected.get(self.path.lstrip("/"))
        if status:
            return self.reply(200,json.dumps({"status":status,"version":"1.2.3"}).encode())
        if self.path == "/book/version":
            if self.fail_version:
                return self.reply(503,b'{}')
            return self.reply(200,b'{"build":{"release":"2.3.4"}}')
        if self.path == "/wrong/health/ready":
            return self.reply(200,b'{"status":"ready"}',"text/html")
        if self.path == "/large/health/ready":
            return self.reply(200,b"x"*131073)
        if self.path == "/redirect/health/ready":
            self.send_response(302)
            self.send_header("Location","/engine/health/ready")
            self.send_header("Content-Length","0")
            self.end_headers()
            return
        self.reply(404,b"{}")

    def do_POST(self):
        if self.path != "/system?action=GetSystemTotal":
            return self.reply(404,b"{}")
        form = parse.parse_qs(self.rfile.read(int(self.headers["Content-Length"])).decode())
        now = form["request_time"][0]
        signature = md5((now+md5(self.panel_key).hexdigest()).encode()).hexdigest()
        if abs(int(now)-time.time())>60 or form["request_token"][0] != signature:
            return self.reply(403,b'{"status":false}')
        self.reply(200,b'{"version":"7.0","cpuNum":4,"memTotal":4096}')


def integrations(tmp, env):
    ca, key = tmp/"cert.pem", tmp/"key.pem"
    subprocess.run(["openssl","req","-x509","-newkey","rsa:2048","-nodes","-days","1","-subj","/CN=localhost",
                    "-addext","subjectAltName=DNS:localhost,IP:127.0.0.1","-keyout",str(key),"-out",str(ca)],
                   stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=True)
    server = ThreadingHTTPServer(("127.0.0.1",0), Provider)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(ca,key)
    server.socket = context.wrap_socket(server.socket,server_side=True)
    thread = threading.Thread(target=server.serve_forever,daemon=True)
    thread.start()
    try:
        url = f"https://127.0.0.1:{server.server_port}"
        config = {key.split("/")[0]:{"base_url":url+"/"+key.split("/")[0]} for key in Provider.expected}
        config["aapanel"] = {"base_url":url,"launch_url":url,"owner":"Ops"}
        config["_stand"] = {"name":"Test stand","environment":"staging","owner":"Ops"}
        for name, item in config.items():
            if name != "_stand":
                item.setdefault("owner","Ops")
                item.setdefault("launch_url",url+"/"+name)
        config["book"].update(version_path="/version",version_field="build.release")
        config_path = tmp/"connections.json"
        config_path.write_text(json.dumps(config))
        env = env | {"WORKSPACE_CONFIG":str(config_path),"WORKSPACE_AAPANEL_KEY":"fixture-panel-key"}
        extra = ("-d","curl.cainfo="+str(ca))
        synced = cli(env,"sync",extra=extra)
        expect(synced.stdout.count("\tOK\t")==8 and "4096" in synced.stdout,"8 real TLS adapter requests")
        profile=json.loads(cli(env,"profile").stdout)
        expect(profile["stand"]["name"]=="Test stand" and profile["connections"]["engine"]["latest"]["observed_version"]=="1.2.3","stand profile and actual version")
        expect(profile["connections"]["book"]["latest"]["observed_version"]=="2.3.4","version API")
        expect(cli(env,"profile","--check",success=False).returncode==1,"strict profile detects absent GitHub")
        Provider.fail_book = True
        result = cli(env,"sync",success=False,extra=extra)
        expect(result.returncode==1 and "book\tERROR\tHTTP 503" in result.stdout and "servers\tOK" in result.stdout,"isolated outage")
        profile=json.loads(cli(env,"profile").stdout)
        book=profile["connections"]["book"]
        expect(book["latest"]["http_status"]==503 and book["last_success"]["http_status"]==200 and book["last_version"]["observed_version"]=="2.3.4","outage keeps last known version and success")
        Provider.fail_book = False
        cli(env,"sync","book",extra=extra)
        protocol=json.loads(cli(env,"protocol","book").stdout)["checks"]
        expect([row["transition"] for row in protocol]==["recovered","down","initial"],"outage/recovery protocol")
        expect(all(row["duration_ms"] is not None for row in protocol),"real HTTP timing")
        Provider.fail_version = True
        cli(env,"sync","book",extra=extra)
        book=json.loads(cli(env,"profile").stdout)["connections"]["book"]
        expect(book["latest"]["ok"]==1 and book["latest"]["version_http_status"]==503 and book["last_version"]["observed_version"]=="2.3.4","separate version outage")
        Provider.fail_version = False
        Provider.panel_key=b"rotated-panel-key"
        expect(cli(env,"sync","aapanel",success=False,extra=extra).returncode==1,"old panel key rejected")
        rotated=env|{"WORKSPACE_AAPANEL_KEY":"rotated-panel-key"}
        cli(rotated,"sync","aapanel",extra=extra)
        panel=json.loads(cli(rotated,"protocol","aapanel").stdout)["checks"]
        expect(panel[0]["transition"]=="recovered" and panel[1]["http_status"]==403,"key rotation recovery recorded")
        expect("rotated-panel-key" not in json.dumps(panel) and "fixture-panel-key" not in json.dumps(panel),"credentials excluded")
        Provider.panel_key=b"fixture-panel-key"
        with application(env) as root:
            owner=Browser(root); owner.login("owner")
            body=owner.call("?p=connections&instance=book")[1].decode()
            expect("Test stand" in body and "2.3.4" in body and "Восстановление" in body and "HTTP 503" in body,"profile and protocol web UI")
            viewer=Browser(root); viewer.login("viewer")
            expect(viewer.call("?p=connections&instance=book")[0]==403,"protocol ACL")
        for key, message in (("redirect","302"),("wrong","Content-Type"),("large","128")):
            config["engine"] = {"base_url":url+"/"+key}
            config_path.write_text(json.dumps(config))
            result=cli(env,"sync","engine",success=False,extra=extra)
            expect(result.returncode==1 and message in result.stdout,key)
    finally:
        server.shutdown()
        server.server_close()
        thread.join()


def run(tmp):
    env = os.environ.copy()
    env.pop("WORKSPACE_CONFIG",None)
    env["WORKSPACE_DATA"]=str(tmp/"data")
    cli(env,"init","owner@example.test","Owner",password=True)
    for role in ["accountant","legal","developer","viewer","manager"]:
        cli(env,"user:add",role+"@example.test",role,role,password=True)
    with application(env) as root:
        users = {r:Browser(root) for r in ["owner","accountant","legal","developer","viewer","manager"]}
        for role, browser in users.items():
            browser.login(role)
        owner, viewer, accountant, lawyer = (users[r] for r in ("owner","viewer","accountant","legal"))
        expect("p=login" in Browser(root).call("?p=download&id=1&v=1")[3],"anonymous PDF download")
        expect(owner.call("health.php")[:2]==(200,b'{"status":"ready"}'),"HTTP readiness")
        form = fields(owner.call("?p=projects")[1],"project.create") | {"title":"=Delivery","client":"Example","kind":"client"}
        expect(owner.call("?p=projects",form|{"csrf":"invalid"})[0]==403,"CSRF on POST")
        project_page=owner.call("?p=projects",form)
        project_url=parse.urlsplit(project_page[3]).query
        expect(project_url=="p=project&id=1","project persisted")
        expect(owner.call("?p=projects",form)[3]==project_page[3],"idempotent PRG")
        task_url="?"+project_url
        for uid in range(2,7):
            expect(owner.post(task_url,"project.add",member_id=uid)[0]==200,"grant explicit project membership")
        owner.post(task_url,"task.create",title="Release",assignee="4",due_on="2028-02-29")
        dev=users["developer"]
        task=fields(dev.call(task_url)[1],"task.state")|{"state":"doing"}
        expect(dev.call(task_url,task)[0]==200,"assigned developer updates")
        expect(dev.call(task_url,task|{"request_key":secrets.token_hex(16),"state":"done"})[0]==409,"old task version")
        for role in ["viewer","developer","manager","legal"]:
            expect(users[role].call("?p=export")[0]==403,"CSV ACL "+role)
        for role in ["viewer","developer","manager","accountant"]:
            expect(users[role].call("?p=download&id=1&v=1")[0]==403,"file ACL "+role)
        cash=fields(accountant.call("?p=finance")[1],"cash.post")|{"project_id":1,"booked_on":time.strftime("%Y-%m-%d",time.gmtime()),
            "direction":"income","amount":"2,45","currency":"EUR","memo":"=record"}
        for _ in range(2):
            expect(accountant.call("?p=finance",cash)[0]==200,"cash submit")
        csv = accountant.call("?p=export&project=1")[1].decode("utf-8-sig").splitlines()
        expect(len(csv)==2 and "2,45" in csv[1] and "'=record" in csv[1] and "'=Delivery" in csv[1],"CSV and deduplication")
        income = fields(accountant.call("?p=finance")[1],"cash.reverse")
        expect(accountant.call("?p=finance",income)[0]==200,"reversal")
        pdf1,pdf2=sample_pdf("one"),sample_pdf("two")
        form=fields(lawyer.call("?p=documents")[1],"document.version")|{"project_id":1,"title":"Contract","kind":"contract"}
        doc=lawyer.call("?p=documents",*upload(form,pdf1))
        expect(doc[3].endswith("?p=document&id=1"),"file upload via multipart")
        next_form=fields(doc[1],"document.version")
        expect(lawyer.call("?p=document&id=1",*upload(next_form,pdf2))[0]==200,"new document version")
        expect(lawyer.call("?p=document&id=1",*upload(next_form|{"request_key":secrets.token_hex(16)},pdf1))[0]==409,"concurrent document update")
        for revision, original in [(1,pdf1),(2,pdf2)]:
            status, data, headers, _=lawyer.call(f"?p=download&id=1&v={revision}")
            expect(status==200 and sha256(data).digest()==sha256(original).digest() and "attachment" in headers["Content-Disposition"],"private PDF revision")
        owner.post(task_url,"connection.attach",connection="github")
        expect(b"GitHub" in owner.call(task_url)[1],"project connection")
        expect(users["viewer"].call("?p=connections")[0]==403,"connection ACL")
        expect(cli(env,"user:disable","owner@example.test",success=False).returncode!=0,"last owner preserved")
        cli(env,"user:disable","developer@example.test")
        expect(dev.call(task_url)[3].endswith("?p=login"),"session revoked")
        logout=fields(viewer.call("?p=overview")[1],"logout")
        expect(viewer.call("?p=overview",logout)[3].endswith("?p=login"),"logout")
    integrations(tmp,env)
    backup=tmp/"snapshot.sqlite"
    cli(env,"backup",str(backup))
    expect(cli(env,"backup",str(backup),success=False).returncode!=0,"backup refuses overwrite")
    with sqlite3.connect(backup) as db:
        expect(db.execute("pragma integrity_check").fetchone()==("ok",),"snapshot integrity")
        expect(db.execute("select count(*) from projects").fetchone()==(1,),"snapshot has project")
        expect(db.execute("select count(*) from document_versions").fetchone()==(2,),"snapshot has both PDFs")
        expect(db.execute("select count(*) from connection_checks").fetchone()[0]>10,"snapshot has connection protocol")
    restored=tmp/"restored"
    restored.mkdir()
    shutil.copy2(backup,restored/"workspace.sqlite")
    with application(env|{"WORKSPACE_DATA":str(restored)}) as root:
        lawyer=Browser(root)
        lawyer.login("legal")
        expect(lawyer.call("?p=download&id=1&v=2")[1]==pdf2,"restored PDF and login")
    # Two CLI processes racing to reserve the same address must create exactly one account.
    def register(_):
        return cli(env,"user:add","race@example.test","Race","viewer",password=True,success=False).returncode
    with ThreadPoolExecutor(max_workers=2) as pool:
        expect(sorted(pool.map(register,[1,2]))==[0,2],"concurrent user creation")


if __name__=="__main__":
    if not PHP:
        raise SystemExit("Set PHP_BIN to the PHP CLI executable")
    with TemporaryDirectory(prefix="cajeerws-http-") as d:
        run(Path(d))
    print(f"OK · {CHECKS} HTTP, CLI and integration checks")
