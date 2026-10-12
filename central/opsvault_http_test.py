"""Full auth/link flows against the single-file artifact, fake provider, temporary SQLite."""
import base64, http.server, json, os, pathlib, re, socket, sqlite3, ssl, subprocess, tempfile, threading, time
import urllib.error, urllib.parse, urllib.request

root = pathlib.Path(__file__).resolve().parent
work = pathlib.Path(tempfile.mkdtemp(prefix='bridge-opsvault-http-'))
env = dict(os.environ, BRIDGE_DATA=str(work), BRIDGE_ADMIN_PASSWORD='recovery-password-123',
           BRIDGE_URL='https://bridge.example.test', BRIDGE_SSO_CLIENT_SECRET='x' * 40,
           BRIDGE_SSO_ENCRYPTION_KEY=base64.b64encode(os.urandom(32)).decode())
subprocess.run(['php', str(root / 'action.php'), 'init'], env=env, check=True, capture_output=True)
fixture = work / 'fixture.php'
fixture.write_text('''<?php
define('BRIDGE_TEST',true);require $argv[1];opsSchema();
foreach(['a','b'] as $id){
 sql('INSERT INTO users VALUES(?,?,?,?,?,?,?)',['buyer_'.$id,'local-'.$id,'LOCAL_'.$id,password_hash('separate-password-123',PASSWORD_DEFAULT),'buyer',1,'epoch_'.$id]);
 saveEntity('tracker_'.$id,'tracker',['name'=>'OWN_TRACKER_'.$id]);sql('UPDATE entities SET owner=? WHERE id=?',['buyer_'.$id,'tracker_'.$id]);
 saveEntity('route_'.$id,'route',['name'=>'MOCK_ROUTE_'.$id]);sql('UPDATE entities SET owner=? WHERE id=?',['buyer_'.$id,'route_'.$id]);
 sql('INSERT INTO leads(id,route,external_id,fingerprint,state,payload,snapshot,created,updated) VALUES(?,?,?,?,?,?,?,?,?)',[
  'mock_lead_'.$id,'route_'.$id,'synthetic_'.$id,'fixture','sent',seal(['lead_id'=>'synthetic_'.$id,'phone'=>'+00000000000']),
  seal(['route'=>['buyer'=>'local-'.$id,'dedupe_buyer'=>'legacy_'.$id,'partner'=>'mock_partner']]),gmdate('c'),gmdate('c')]);
}
setting('sso_client_id','lead-bridge');setting('sso_gate','1');setting('sso_link_ids','buyer_a,buyer_b');
$k=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);openssl_pkey_export($k,$pem);file_put_contents(home().'/test-key',$pem);
''', encoding='utf8')
subprocess.run(['php', str(fixture), str(root / 'action.php')], env=env, check=True)
router = work / 'router.php'
router.write_text('''<?php
define('BRIDGE_TEST',true);require getenv('TEST_ARTIFACT');
function testB64($s){return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
$GLOBALS['ops_http']=function($url,$body,$headers){
 $m=json_decode(file_get_contents(home().'/mock.json'),true);$d=$body?json_decode($body,true):[];
 $response=fn($v,$status=200)=>['code'=>$status,'body'=>json_encode($v)];
 if(str_contains($url,'openid-configuration'))return $response(['issuer'=>OPSVAULT_ISSUER,'authorization_endpoint'=>OPSVAULT_ISSUER.'/authorize/','token_endpoint'=>OPSVAULT_ISSUER.'/token/','jwks_uri'=>OPSVAULT_ISSUER.'/jwks/','code_challenge_methods_supported'=>['S256']]);
 if(str_contains($url,'/token/')){
  parse_str($body,$f);if(testB64(hash('sha256',$f['code_verifier']??'',true))!==($m['challenge']??''))return $response([],400);
  $c=['iss'=>OPSVAULT_ISSUER,'aud'=>'lead-bridge','sub'=>$m['subject'],'preferred_username'=>$m['name'],'sid'=>'family','nonce'=>$m['nonce'],'iat'=>time(),'exp'=>time()+300];
  if(isset($m['claim']))$c[$m['claim']]=$m['value'];$jwt=testB64(json_encode(['alg'=>'RS256','kid'=>'test'])).'.'.testB64(json_encode($c));openssl_sign($jwt,$sig,file_get_contents(home().'/test-key'),OPENSSL_ALGO_SHA256);
  return $response(['id_token'=>$jwt.'.'.testB64($sig),'access_token'=>'access-token']);
 }
 if(str_contains($url,'/jwks/')){$rsa=openssl_pkey_get_details(openssl_pkey_get_private(file_get_contents(home().'/test-key')))['rsa'];return $response(['keys'=>[['kty'=>'RSA','alg'=>'RS256','kid'=>'test','n'=>testB64($rsa['n']),'e'=>testB64($rsa['e'])]]]);}
 if(($m['mode']??'')==='offline')throw new OpsUnavailable('mock unavailable');
 if(($m['mode']??'')==='deny')return $response([],403);
 if(str_contains($url,'/session/'))return $response(['issuer'=>OPSVAULT_ISSUER,'subject'=>$m['subject'],'sid'=>'family','role'=>$m['role'],'lease_token'=>'lease-token','expires_at'=>time()+300]);
 if(str_contains($url,'/credentials/read/')){$v=is_file(home().'/mock-vault.json')?json_decode(file_get_contents(home().'/mock-vault.json'),true):[];return $response(isset($v[$d['subject']])?['found'=>true]+$v[$d['subject']]:['found'=>false]);}
 if(str_contains($url,'/credentials/')){
  if(($m['mode']??'')==='publish-fail')return $response([],409);
  $v=is_file(home().'/mock-vault.json')?json_decode(file_get_contents(home().'/mock-vault.json'),true):[];
  $card=['local_user_id'=>$d['local_user_id'],'username'=>$d['username'],'password'=>$d['password']];
  if(isset($v[$d['subject']])&&$v[$d['subject']]!==$card)return $response([],409);
  $v[$d['subject']]=$card;file_put_contents(home().'/mock-vault.json',json_encode($v));return $response(['saved'=>true,'credential_id'=>1,'credential_url'=>'/vault/accounts/1/']);
 }
 if(str_contains($url,'/jobs/check/'))return $response(['allowed'=>($m['mode']??'')!=='no-import','valid_for'=>60]);
 if(($d['action']??'')==='logout'){$m['mode']='deny';file_put_contents(home().'/mock.json',json_encode($m));}
 return $response(['role'=>$m['role'],'valid_for'=>60]);
};
main();
''', encoding='utf8')
mock = dict(subject='11111111-1111-4111-8111-111111111111', name='PILOT_OPS', role='user', mode='normal')
def configure(**values):
    mock.update(values)
    (work / 'mock.json').write_text(json.dumps(mock))
configure()
sock = socket.socket(); sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]; sock.close()
env['TEST_ARTIFACT'] = str(root / 'action.php')
log = open(work / 'server.log', 'w')
server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', str(router)], env=env, stdout=log, stderr=log)
base = f'http://127.0.0.1:{port}/'
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args): return None
class Client:
    def __init__(self): self.cookies = {}; self.csrf = ''; self.opener = urllib.request.build_opener(NoRedirect)
    def req(self, path='', data=None, follow=True):
        headers = {'Cookie': '; '.join(f'{k}={v}' for k, v in self.cookies.items())}
        if isinstance(data, dict): data = urllib.parse.urlencode(dict(csrf=self.csrf, **data)).encode(); headers['Content-Type'] = 'application/x-www-form-urlencoded'
        request = urllib.request.Request(base + path, data=data, headers=headers)
        try: response = self.opener.open(request, timeout=20)
        except urllib.error.HTTPError as e: response = e
        for cookie in response.headers.get_all('Set-Cookie') or []:
            k, v = cookie.split(';', 1)[0].split('=', 1); self.cookies[k] = v
        text = response.read().decode(); match = re.search(r'name="csrf" value="([^"]+)"', text)
        if match: self.csrf = match[1]
        if follow and response.status == 303 and not response.headers['Location'].startswith('https://'):
            return self.req(response.headers['Location'].lstrip('/'))
        return response.status, text, response.headers
    def login(self, name, password='separate-password-123'):
        self.req(); return self.req(data=dict(op='login', login=name, password=password))
    def start_link(self):
        return self.req(data=dict(op='ops_link_start', ops_password='separate-password-123', vault_consent='yes'), follow=False)
    def callback(self, headers):
        params = urllib.parse.parse_qs(urllib.parse.urlsplit(headers['Location']).query)
        assert params['code_challenge_method'] == ['S256'] and params['scope'] == ['profile openid']
        configure(nonce=params['nonce'][0], challenge=params['code_challenge'][0])
        path = '?opsvault=callback&' + urllib.parse.urlencode(dict(state=params['state'][0], code='test-code'))
        return path, self.req(path)
def passed(value, label):
    assert value, label
    print('PASS', label)
db = sqlite3.connect(work / 'bridge.sqlite')
def dbset(k, value):
    db.execute('INSERT INTO settings VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v', (k, value)); db.commit()
try:
    for _ in range(50):
        try: urllib.request.urlopen(base + '?health=1'); break
        except urllib.error.URLError: time.sleep(.1)
    a, b = Client(), Client()
    code, text, _ = a.login('local-a'); passed(code == 200 and 'Связать OpsVault' in text, 'pilot login restricted to linking settings')
    passed('OWN_TRACKER_a' not in a.req('?page=tracker')[1], 'unlinked pilot cannot read data')
    passed('Сначала свяжите' in a.req(data=dict(op='save', kind='tracker', name='BAD'))[1], 'unlinked pilot cannot mutate data')
    old_hash, old_epoch = db.execute('SELECT password,epoch FROM users WHERE id="buyer_a"').fetchone()
    owners = db.execute('SELECT id,owner FROM entities ORDER BY id').fetchall()
    lead_snapshots = db.execute('SELECT id,route,external_id,payload,snapshot FROM leads ORDER BY id').fetchall()
    passed('Неверный текущий пароль' in a.req(data=dict(op='ops_link_start', ops_password='wrong', vault_consent='yes'))[1], 'link requires current separate password')
    passed('Подтвердите сохранение' in a.req(data=dict(op='ops_link_start', ops_password='separate-password-123'))[1], 'link requires vault consent')
    code, _, headers = a.start_link(); passed(code == 303, 'link authorization redirect')
    old_session = a.cookies['leadbridge']; path, (code, text, _) = a.callback(headers)
    passed(code == 200 and 'PILOT_OPS' in text and 'Подтвердить связь' in text, 'callback shows both accounts without provision')
    passed(not db.execute('SELECT 1 FROM sso_links').fetchone(), 'callback never auto-links')
    passed('separate-password-123' not in text and 'lease-token' not in text, 'pending secrets never in HTML')
    encrypted = db.execute('SELECT data FROM sso_attempts').fetchone()[0]
    passed('separate-password-123' not in encrypted and 'lease-token' not in encrypted, 'pending credentials encrypted at rest')
    other = Client(); other.login('local-b'); other.cookies['bridge_oidc'] = a.cookies['bridge_oidc']
    passed(other.req(data=dict(op='ops_link_confirm', confirm_accounts='yes'))[0] == 403, 'pending rejects a different local session')
    # The rejected confirmation consumed pending; restart explicitly rather than retrying external writes.
    _, _, headers = a.start_link(); a.callback(headers); configure(mode='publish-fail')
    passed(a.req(data=dict(op='ops_link_confirm', confirm_accounts='yes'))[0] == 503, 'publish 409 leaves link uncommitted')
    passed(not db.execute('SELECT 1 FROM sso_links').fetchone(), 'publish failure preserves unlinked account')
    configure(mode='normal'); a.req('?page=settings'); _, _, headers = a.start_link(); path, _ = a.callback(headers)
    code, text, _ = a.req(data=dict(op='ops_link_confirm', confirm_accounts='yes'))
    passed(code == 200 and 'Аккаунт связан' in text, 'explicit link succeeds')
    passed(a.cookies['leadbridge'] != old_session, 'link rotates panel session ID')
    current_hash, current_epoch = db.execute('SELECT password,epoch FROM users WHERE id="buyer_a"').fetchone()
    passed(current_hash == old_hash and current_epoch != old_epoch, 'link preserves hash and revokes all old session epochs')
    passed(db.execute('SELECT id,owner FROM entities ORDER BY id').fetchall() == owners, 'link preserves all entity owner IDs')
    passed(db.execute('SELECT id,route,external_id,payload,snapshot FROM leads ORDER BY id').fetchall() == lead_snapshots, 'link preserves accepted lead IDs, snapshots and legacy dedupe namespaces')
    passed(a.req(path)[0] == 403, 'callback replay refused')
    a.req('?page=settings'); a.req(data=dict(op='logout')); passed('Вход в панель' in a.req()[1], 'local logout closes bridge')
    passed(a.login('local-a')[0] == 200, 'linked separate password login succeeds with central policy')
    code, text, _ = a.req('?page=settings'); passed('ops_logout_all' not in text and 'op" value="password' not in text, 'local-linked UI omits family logout and password rotation')
    passed('нельзя менять отдельно' in a.req(data=dict(op='password', current_password='separate-password-123', password='new-password-12345'))[1], 'linked password rotation rejected server-side')
    passed('OWN_TRACKER_a' in a.req('?page=tracker')[1] and 'OWN_TRACKER_b' not in a.req('?page=tracker')[1], 'linked buyer SQL owner isolation')
    configure(role='admin'); a.req(data=dict(op='timezone', timezone='UTC'))
    passed('OWN_TRACKER_b' in a.req('?page=tracker')[1], 'central admin grant enables administrator scope')
    subject_b='44444444-4444-4444-8444-444444444444'
    code, text, _ = a.req(data=dict(op='ops_reserve', sso_local_user='buyer_b', sso_subject=subject_b))
    passed(code == 200 and db.execute('SELECT subject FROM sso_reservations WHERE user_id="buyer_b"').fetchone()[0] == subject_b, 'admin reserves an exact central subject in browser without linking')
    passed('только администратору' in other.req(data=dict(op='ops_reserve', sso_local_user='buyer_b', sso_subject=subject_b))[1], 'unlinked buyer cannot configure reservations')
    a.req(data=dict(op='ops_unreserve', sso_local_user='buyer_b'))
    passed(not db.execute('SELECT 1 FROM sso_reservations WHERE user_id="buyer_b"').fetchone(), 'admin can correct an unconfirmed reservation in browser')
    passed('нельзя снять' in a.req(data=dict(op='ops_unreserve', sso_local_user='buyer_a'))[1] and db.execute('SELECT count(*) FROM sso_links').fetchone()[0] == 1, 'reservation removal never unlinks a confirmed identity')
    configure(role='user'); a.req(data=dict(op='timezone', timezone='UTC'))
    passed('OWN_TRACKER_b' not in a.req('?page=tracker')[1], 'fresh write observes admin demotion')
    configure(mode='deny'); passed(a.req(data=dict(op='timezone', timezone='UTC'))[0] == 403, 'revoke grant closes linked session')
    passed(a.login('local-a')[0] == 403, 'revoked password login denied')
    configure(mode='normal'); a.login('local-a'); dbset('sso_gate', '0')
    passed(a.req()[0] == 403 and a.login('local-a')[0] == 403, 'gate off denies existing and new password sessions')
    recovery = Client(); passed(recovery.login('admin', 'recovery-password-123')[0] == 200, 'unlinked emergency administrator recovers settings')
    dbset('sso_gate', '1'); configure(mode='offline'); passed(a.login('local-a')[0] == 503, 'provider outage denies password login with 503')
    configure(mode='normal'); oidc = Client(); oidc.req(); _, _, headers = oidc.req('?opsvault=login', follow=False); _, (code, text, _) = oidc.callback(headers)
    passed(code == 200 and 'LOCAL_a' in text and db.execute('SELECT count(*) FROM users').fetchone()[0] == 3, 'ordinary OIDC login reuses explicit binding')
    oidc.req('?page=settings'); passed(oidc.req(data=dict(op='ops_logout_all'))[0] == 200, 'OIDC family logout closes bridge and provider family')
    passed(oidc.login('local-a')[0] == 403, 'mock revoked grant blocks subsequent separate login')
    passed(db.execute('PRAGMA integrity_check').fetchone()[0] == 'ok', 'HTTP SSO integrity')
    configure(mode='normal', role='user', subject='33333333-3333-4333-8333-333333333333', name='NEW_OPS_USER')
    dbset('sso_jit', '1')
    fresh = Client(); fresh.req(); _, _, h = fresh.req('?opsvault=login', follow=False); _, (code, text, _) = fresh.callback(h)
    passed(code == 200 and 'NEW_OPS_USER' in text, 'first permitted OIDC login creates a new local platform account')
    row = db.execute('SELECT users.id,login,password,name FROM users JOIN sso_links ON user_id=users.id WHERE subject=?', (mock['subject'],)).fetchone()
    passed(row is not None and row[3] == 'NEW_OPS_USER', 'new account uses central display name and separate login')
    new_uid, new_login, new_hash, _ = row
    vault = json.loads((work / 'mock-vault.json').read_text())[mock['subject']]
    passed(vault['local_user_id'] == new_uid and vault['username'] == new_login and vault['password'] != new_hash, 'new account credentials published with canonical local ID before commit')
    fresh.req(data=dict(op='logout')); passed(fresh.login(new_login, vault['password'])[0] == 200, 'generated separate credentials work with central local-access')
    fresh.req(data=dict(op='logout')); _, _, h = fresh.req('?opsvault=login', follow=False); fresh.callback(h)
    passed(db.execute('SELECT count(*) FROM users').fetchone()[0] == 4, 'repeat OIDC login never creates a second local account')
    configure(subject='44444444-4444-4444-8444-444444444444', name='RESERVED_USER')
    db.execute('INSERT INTO sso_reservations VALUES(?,?,?)', ('buyer_b', 'https://portalwiki.world/sso', mock['subject'])); db.commit()
    reserved = Client(); reserved.req(); _, _, h = reserved.req('?opsvault=login', follow=False); _, (code, text, _) = reserved.callback(h)
    passed(code == 403 and 'У вас уже есть аккаунт' in text and db.execute('SELECT count(*) FROM users').fetchone()[0] == 4, 'enabled JIT preserves existing reserved users without username matching')
    db.execute('DELETE FROM sso_reservations'); db.commit()
    if os.environ.get('BRIDGE_BROWSER_NODE'):
        configure(mode='normal', role='user', subject='22222222-2222-4222-8222-222222222222', name='PILOT_OPS_B')
        # A TLS mock proxy handles real browser redirects. Playwright route() does not intercept
        # a redirected URL in the same chain; DNS/network must never reach the production provider.
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                        '-subj', '/CN=portalwiki.world', '-keyout', str(work / 'proxy.key'),
                        '-out', str(work / 'proxy.crt')], check=True, capture_output=True)
        tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); tls.load_cert_chain(work / 'proxy.crt', work / 'proxy.key')
        class MockProxy(http.server.BaseHTTPRequestHandler):
            def log_message(self, *args): pass
            def do_CONNECT(self):
                if self.path != 'portalwiki.world:443': self.send_error(403); return
                self.send_response(200); self.end_headers()
                try: self.connection = tls.wrap_socket(self.connection, server_side=True)
                except ssl.SSLError: return  # Chromium may retry after its initial certificate check.
                self.rfile = self.connection.makefile('rb'); self.wfile = self.connection.makefile('wb')
                self.handle_one_request()
            def do_GET(self):
                parsed = urllib.parse.urlsplit(self.path)
                if parsed.path != '/sso/authorize/': self.send_error(403); return
                params = urllib.parse.parse_qs(parsed.query)
                assert params['code_challenge_method'] == ['S256']
                configure(nonce=params['nonce'][0], challenge=params['code_challenge'][0])
                location = base + '?opsvault=callback&' + urllib.parse.urlencode(dict(code='test-code', state=params['state'][0]))
                self.send_response(302); self.send_header('Location', location); self.send_header('Content-Length', '0'); self.end_headers()
        proxy = http.server.ThreadingHTTPServer(('127.0.0.1', 0), MockProxy)
        threading.Thread(target=proxy.serve_forever, daemon=True).start()
        try:
            subprocess.run([os.environ['BRIDGE_BROWSER_NODE'], os.environ['BRIDGE_BROWSER_SCRIPT'], base,
                            os.environ['BRIDGE_PLAYWRIGHT'], os.environ.get('BRIDGE_BROWSER_CHANNEL', ''),
                            f'http://127.0.0.1:{proxy.server_port}'], check=True)
        finally: proxy.shutdown(); proxy.server_close()
    print('ALL OPSVAULT HTTP TESTS PASSED')
finally:
    server.terminate(); server.wait(timeout=10); log.close(); db.close()
