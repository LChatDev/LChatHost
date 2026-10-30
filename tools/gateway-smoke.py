"""Local-only HTTPS gateway test. Usage: python gateway-smoke.py PHP EXTDIR OPENSSL."""
import http.server, json, os, pathlib, socket, ssl, subprocess, sys, tempfile, threading, time
import requests

php, ext, openssl = sys.argv[1:4]
root = pathlib.Path(__file__).resolve().parent.parent

class Handler(http.server.BaseHTTPRequestHandler):
    backend = None
    def handle_request(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', 0)))
        if self.backend:
            headers = {k:v for k,v in self.headers.items() if k.lower() in ('content-type','cookie','x-csrf-token','x-lchat-gateway')}
            upstream = requests.request(self.command, self.backend+self.path, headers=headers, data=body, allow_redirects=False, timeout=10)
            self.send_response(upstream.status_code)
            for name in ('Content-Type','Set-Cookie','Location'):
                if name in upstream.headers: self.send_header(name,upstream.headers[name])
            self.send_header('Content-Length',str(len(upstream.content))); self.end_headers(); self.wfile.write(upstream.content); return
        response = json.dumps({'path': self.path, 'method': self.command, 'cookie': self.headers.get('Cookie'), 'csrf': self.headers.get('X-CSRF-Token'), 'body': body.decode('latin1'), 'type': self.headers.get('Content-Type')}).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(response)))
        self.send_header('Set-Cookie', 'session=test; Domain=localhost; Path=/sub; HttpOnly; Secure; SameSite=Lax')
        self.end_headers(); self.wfile.write(response)
    do_GET = handle_request
    do_POST = handle_request
    def log_message(self, *args): pass

with tempfile.TemporaryDirectory(prefix='lchat-gateway-') as tmp:
    tmp = pathlib.Path(tmp); cert = tmp/'cert.pem'; key = tmp/'key.pem'
    subprocess.run([openssl,'req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    server = http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); context.load_cert_chain(cert,key); server.socket = context.wrap_socket(server.socket,server_side=True)
    threading.Thread(target=server.serve_forever,daemon=True).start()
    with socket.socket() as sock: sock.bind(('127.0.0.1',0)); port = sock.getsockname()[1]
    cfg = tmp/'config.php'; cfg.write_text("<?php return ['backend'=>['mode'=>'pc','url'=>'https://localhost:"+str(server.server_port)+"/']];",encoding='utf8')
    env = dict(os.environ,LCHAT_CONFIG=str(cfg))
    log = (tmp/'php.log').open('w+',encoding='utf8')
    process = subprocess.Popen([php,'-d','extension_dir='+ext,'-d','extension=curl','-d','extension=mbstring','-d','curl.cainfo='+str(cert),'-S','127.0.0.1:'+str(port),'-t',str(root)],env=env,stdout=subprocess.DEVNULL,stderr=log)
    url = 'http://127.0.0.1:'+str(port)+'/api.php'
    try:
        for _ in range(60):
            try:
                response=requests.get(url,params={'action':'me'},timeout=2); break
            except requests.RequestException:time.sleep(.1)
        else:raise RuntimeError('PHP server did not start')
        if not response.ok:
            log.flush(); log.seek(0); raise AssertionError(response.text+'\n'+log.read())
        assert requests.get('http://127.0.0.1:'+str(port)+'/index.php').status_code == 200
        assert response.json()['path']=='/api.php?action=me'
        cookie=response.headers['Set-Cookie']; assert 'Domain=' not in cookie and 'Path=/' in cookie and 'HttpOnly' in cookie
        response=requests.post(url+'?action=login',headers={'X-CSRF-Token':'csrf-test','Cookie':'session=example'},json={'login':'test'}); data=response.json()
        assert data['csrf']=='csrf-test' and data['cookie']=='session=example' and '"login": "test"' in data['body']
        response=requests.post('http://127.0.0.1:'+str(port)+'/upload.php',data={'chat_id':'7'},files={'file':('test.txt',b'hello gateway','text/plain')});data=response.json()
        assert response.ok and 'multipart/form-data' in data['type'] and 'hello gateway' in data['body'] and 'chat_id' in data['body']
        response=requests.get(url,headers={'X-LChat-Gateway':'1'}); assert response.status_code==508
        with socket.socket() as sock: sock.bind(('127.0.0.1',0)); backend_port = sock.getsockname()[1]
        backend_cfg = tmp/'backend.php'; backend_cfg.write_text("<?php return ['database'=>['dsn'=>'sqlite:"+(tmp/'db.sqlite').as_posix()+"'],'install_key'=>'"+'x'*40+"','backend'=>['mode'=>'host']];",encoding='utf8')
        backend_env = dict(os.environ,LCHAT_CONFIG=str(backend_cfg))
        backend = subprocess.Popen([php,'-d','extension_dir='+ext,'-d','extension=mbstring','-d','extension=pdo_sqlite','-S','127.0.0.1:'+str(backend_port),'-t',str(root)],env=backend_env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        try:
            for _ in range(60):
                try: requests.get('http://127.0.0.1:'+str(backend_port)+'/index.php',timeout=1);break
                except requests.RequestException:time.sleep(.1)
            else: raise RuntimeError('Backend did not start')
            Handler.backend='http://127.0.0.1:'+str(backend_port)
            client = requests.Session(); base='http://127.0.0.1:'+str(port)+'/'
            install = client.post(base+'install.php',data={'key':'x'*40}); assert ('Таблицы созданы' in install.text or 'Tables created' in install.text), install.text
            csrf=client.get(base+'api.php?action=me').json()['csrf']
            reg=client.post(base+'api.php?action=register',headers={'X-CSRF-Token':csrf},json={'name':'Проверка','username':'testuser','email':'test@example.test','password':'strong-password-123'}); assert reg.ok,reg.text
            user=client.get(base+'api.php?action=me').json()['user']; assert user['username']=='testuser'
        finally:
            Handler.backend=None;backend.terminate();backend.wait(timeout=10)
        cfg.write_text("<?php return ['backend'=>['mode'=>'pc','url'=>'http://localhost/']];",encoding='utf8')
        response=requests.get(url); assert response.status_code==503
        print('PASS: HTTPS, query, CSRF, cookies, JSON, multipart, loop protection, backend installation, registration, session and insecure URL rejection')
    finally:
        process.terminate();process.wait(timeout=10);server.shutdown();server.server_close();log.close()
