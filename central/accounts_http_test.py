"""Authorization regression tests; isolated DB and localhost server, no external requests."""
import os,pathlib,tempfile,subprocess,socket,time,re,json,urllib.request,urllib.error,urllib.parse,sqlite3
root=pathlib.Path(__file__).resolve().parent
work=pathlib.Path(tempfile.mkdtemp(prefix='bridge-accounts-test-'))
env=dict(os.environ,BRIDGE_DATA=str(work),BRIDGE_ADMIN_PASSWORD='admin-test-password-123',BRIDGE_URL='https://bridge.example.com')
subprocess.run(['php',str(root/'action.php'),'init'],env=env,check=True,capture_output=True)
fixture=work/'fixture.php'
fixture.write_text('''<?php
define('BRIDGE_TEST',true);require $argv[1];
foreach(['a','b'] as $k){
 $uid='buyer_'.$k;sql('INSERT INTO users VALUES(?,?,?,?,?,?,?)',[$uid,'buyer-'.$k,'Buyer '.$k,password_hash('buyer-password-123',PASSWORD_DEFAULT),'buyer',1,'epoch'.$k]);
 saveEntity('tracker_'.$k,'tracker',['name'=>'TRACKER_'.$k,'version'=>'v2','click_url'=>'https://example.invalid/click','api_key'=>'SECRET_'.$k]);
 saveEntity('partner_'.$k,'partner',['name'=>'PARTNER_'.$k,'type'=>'lemonad','token'=>'TOKEN_'.$k]);
 $r=['name'=>'ROUTE_'.$k,'buyer'=>$uid,'tracker'=>'tracker_'.$k,'partner'=>'partner_'.$k,'campaign_key'=>'CAMPAIGN_'.$k,'offer_id'=>'OFFER_'.$k,'active'=>true,'secret'=>str_repeat($k,64),'tokens'=>defaultTokens()];
 saveEntity('route_'.$k,'route',$r);
 foreach(['tracker','partner','route'] as $kind)sql('UPDATE entities SET owner=? WHERE id=?',[$uid,$kind.'_'.$k]);
 $lead=enqueue('route_'.$k,$r,['lead_id'=>$k==='a'?'111111111':'222222222','phone'=>'+254700000001','name'=>'CONTACT_'.$k]);
 stage($lead['receipt'],'sent',['click_id'=>'CLICK_'.$k,'partner_id'=>'REF_'.$k,'response'=>seal(['body'=>'PRIVATE_RESPONSE_'.$k])]);
}
''',encoding='utf8')
subprocess.run(['php',str(fixture),str(root/'action.php')],env=env,check=True)
sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
log=open(work/'server.log','w');server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],env=env,stdout=log,stderr=log)
base=f'http://127.0.0.1:{port}/action.php'
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args):return None
class Client:
 def __init__(self):self.cookie='';self.csrf='';self.cookies=[];self.opener=urllib.request.build_opener(NoRedirect)
 def req(self,path='',data=None,headers=None):
  hd={'Cookie':self.cookie};hd.update(headers or {})
  if isinstance(data,dict):data=urllib.parse.urlencode(data).encode();hd['Content-Type']='application/x-www-form-urlencoded'
  request=urllib.request.Request(base+path,data=data,headers=hd)
  try:r=self.opener.open(request,timeout=10)
  except urllib.error.HTTPError as e:r=e
  self.cookies.extend(r.headers.get_all('Set-Cookie') or [])
  if r.headers.get('Set-Cookie'):self.cookie=r.headers['Set-Cookie'].split(';')[0]
  text=r.read().decode();token=re.search(r'name="csrf" value="([^"]+)"',text)
  if token:self.csrf=token.group(1)
  if r.status==303:return self.req(r.headers['Location'].replace('/action.php',''))
  return r.status,text
 def post(self,**data):return self.req(data=dict(csrf=self.csrf,**data))
 def login(self,name,password):self.req();return self.post(op='login',login=name,password=password)
def assert_ok(ok,label):
 assert ok,label
 print('PASS',label)
try:
 for i in range(30):
  try:Client().req('?health=1');break
  except urllib.error.URLError:time.sleep(.1)
 admin=Client();a=Client();b=Client()
 assert_ok('Аккаунты' in admin.login('admin','admin-test-password-123')[1],'admin migrated login')
 assert_ok('Вход в панель' not in a.login('buyer-a','buyer-password-123')[1],'buyer login')
 b.login('buyer-b','buyer-password-123')
 # Persistent session cookie and private storage survive inactivity and server restart.
 assert_ok(any('Max-Age=2592000' in v and 'secure' in v.lower() and 'httponly' in v.lower() and 'SameSite=Strict' in v for v in a.cookies),'persistent secure cookie has 30-day lifetime')
 saved_cookie=a.cookie
 session_file=work/'sessions'/('sess_'+saved_cookie.split('=',1)[1])
 assert_ok(session_file.exists() and (session_file.parent.stat().st_mode & 0o777)==0o700,'sessions use private application directory')
 def age_session(days):
  stamp=int(time.time())-days*86400
  session_file.write_text(re.sub(r'last\|i:\d+;',f'last|i:{stamp};',session_file.read_text()))
  os.utime(session_file,(stamp,stamp))
 age_session(2)
 server.terminate();server.wait(timeout=10)
 server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],env=env,stdout=log,stderr=log)
 for i in range(30):
  try:Client().req('?health=1');break
  except urllib.error.URLError:time.sleep(.1)
 reopened=Client();reopened.cookie=saved_cookie
 assert_ok('Вход в панель' not in reopened.req()[1],'saved cookie survives two days and server restart')
 assert_ok(int(re.search(r'last\|i:(\d+);',session_file.read_text()).group(1))>=int(time.time())-5,'activity renews server session')
 assert_ok(any('Max-Age=2592000' in v for v in reopened.cookies),'activity renews browser cookie')
 age_session(31)
 assert_ok('Вход в панель' in reopened.req()[1],'30-day idle limit enforced')
 a.login('buyer-a','buyer-password-123')
 logout=Client();logout.login('buyer-a','buyer-password-123');old_cookie=logout.cookie
 logout.post(op='logout')
 assert_ok(any('Max-Age=0' in v for v in logout.cookies),'logout expires browser cookie')
 replay=Client();replay.cookie=old_cookie
 assert_ok('Вход в панель' in replay.req()[1],'logged-out cookie cannot be reused')
 for kind in ['tracker','partner','route']:
  page=a.req('?page='+kind)[1]
  assert_ok(kind.upper()+'_a' in page and kind.upper()+'_b' not in page,kind+' list scoped')
  assert_ok(a.req('?page='+kind+'&edit='+kind+'_b')[0]==404,kind+' direct foreign GET blocked')
  assert_ok(admin.req('?page='+kind+'&edit='+kind+'_b')[0]==200,'admin sees '+kind)
 assert_ok(a.req('?page=users')[0]==403,'accounts page admin only')
 assert_ok('Последние действия' not in a.req('?page=settings')[1],'global audit hidden')
 conn=sqlite3.connect(work/'bridge.sqlite');ids=dict(conn.execute('SELECT route,id FROM leads'))
 # Postback format is owner-scoped and must not switch outbound integration.
 assert_ok('недоступна' in a.post(op='postback_provider',id='partner_b',postback_provider='skylead')[1],'foreign postback preference blocked')
 assert_ok('Неизвестная' in a.post(op='postback_provider',id='partner_a',postback_provider='bad')[1],'postback provider allowlist')
 a.post(op='postback_provider',id='partner_a',postback_provider='skylead')
 page=a.req('?page=partner&edit=partner_a')[1]
 assert_ok('value="skylead" selected' in page and 'clickid={subid}&amp;status={stage}&amp;leadid={id}' in page,'Skylead preference saved and guide rendered')
 a.post(op='postback_provider',id='partner_a',postback_provider='cashfactories')
 assert_ok('postback=cashfactories' in a.req('?page=partner&edit=partner_a')[1],'Cashfactories guide rendered')
 a.post(op='postback_provider',id='partner_a',postback_provider='lemonad')
 assert_ok('clickid={clickid}' in a.req('?page=partner&edit=partner_a')[1],'Lemonad guide restored')
 # Outbound adapter settings: immutable type, owner enforcement, new account default callback.
 assert_ok('Неизвестная' in a.post(op='save',kind='partner',name='BAD',type='invalid',token='test')[1],'outbound provider allowlist')
 assert_ok('создайте новую' in a.post(op='save',kind='partner',id='partner_a',name='PARTNER_a',type='skylead',token='test')[1],'existing outbound type immutable')
 for provider in ['skylead','cashfactories']:
  a.post(op='save',kind='partner',name='OUT_'+provider,type=provider,token='test-token')
  pid=conn.execute("SELECT id FROM entities WHERE kind='partner' AND owner='buyer_a' ORDER BY rowid DESC LIMIT 1").fetchone()[0]
  page=a.req('?page=partner&edit='+pid)[1]
  assert_ok('value="'+provider+'" selected' in page and 'postback='+provider in page,'new partner adapter and callback '+provider)
  assert_ok(b.req('?page=partner&edit='+pid)[0]==404,'new adapter ownership '+provider)
  invalid=a.post(op='save',kind='route',name='INVALID_FLOW',tracker='tracker_a',partner=pid,campaign_key='key',offer_id='123',flow_id='')[1]
  assert_ok('числовые ID' in invalid,'missing flow blocked '+provider)
  data=dict(op='save',kind='route',name='IP_ROUTE',tracker='tracker_a',partner=pid,campaign_key='key',offer_id='123',flow_id='456',partner_ip_mode='country_random')
  assert_ok('Выберите страну' in a.post(**data)[1],'random IP requires country '+provider)
  data['partner_ip_mode']='unknown'
  assert_ok('режим IP' in a.post(**data)[1],'IP mode allowlist '+provider)
  a.post(op='save',kind='tracker',name='IP_TEST',version='v2',click_url='https://example.invalid/click',api_key='test')
  tid=conn.execute("SELECT id FROM entities WHERE kind='tracker' AND owner='buyer_a' ORDER BY rowid DESC LIMIT 1").fetchone()[0]
  data.update(partner_ip_mode='country_random',country='ZA',tracker=tid)
  page=a.post(**data)[1]
  assert_ok('value="country_random" selected' in page and 'value="ZA" selected' in page,'random IP mode saved '+provider)

 # Announcements are admin-published, acknowledged per user and version, never by viewing.
 banner='id="script-update-notice"'
 assert_ok('Уведомить об обновлении Apps Script' in admin.req('?page=settings')[1],'admin has script announcement controls')
 assert_ok('Уведомить об обновлении Apps Script' not in a.req('?page=settings')[1],'buyer has no publish controls')
 assert_ok(banner in admin.req('?page=settings&preview_script_notice=1')[1],'admin preview renders notification')
 assert_ok(banner not in a.req('?preview_script_notice=1')[1],'buyer cannot force preview')
 assert_ok(conn.execute("SELECT count(*) FROM settings WHERE k='script_notice'").fetchone()[0]==0,'preview does not publish')
 assert_ok('только администратору' in a.post(op='script_notice_publish',notice_id='')[1],'buyer cannot publish')
 admin.req(data={'csrf':'wrong','op':'script_notice_publish','notice_id':''})
 assert_ok(conn.execute("SELECT count(*) FROM settings WHERE k='script_notice'").fetchone()[0]==0,'publish requires CSRF')
 admin.post(op='script_notice_publish',notice_id='')
 notice=json.loads(conn.execute("SELECT v FROM settings WHERE k='script_notice'").fetchone()[0]);first_notice=notice['id']
 assert_ok(banner in a.req()[1] and banner in b.req()[1],'publication shown to both buyers')
 assert_ok(banner not in Client().req()[1],'anonymous page hides announcement')
 admin.post(op='script_notice_publish',notice_id='')
 assert_ok(json.loads(conn.execute("SELECT v FROM settings WHERE k='script_notice'").fetchone()[0])['id']==first_notice,'refresh of publish form cannot republish')
 a.post(op='script_notice_ack',notice_id=first_notice,uid='buyer_b')
 assert_ok(banner not in a.req()[1] and banner in b.req()[1],'acknowledgement affects only signed-in user')
 admin.post(op='script_notice_publish',notice_id=first_notice)
 next_notice=json.loads(conn.execute("SELECT v FROM settings WHERE k='script_notice'").fetchone()[0])['id']
 a.post(op='script_notice_ack',notice_id=first_notice)
 assert_ok(banner in a.req()[1],'new publication reappears and old acknowledgement cannot hide it')
 a.post(op='script_notice_withdraw',notice_id=next_notice)
 assert_ok(banner in b.req()[1],'buyer cannot withdraw announcement')
 admin.post(op='script_notice_withdraw',notice_id=next_notice)
 assert_ok(banner not in a.req()[1] and banner not in b.req()[1],'admin withdrawal hides notification for everyone')
 assert_ok('Europe/Moscow (Москва' in a.req('?page=settings')[1], 'timezone settings available to buyer')
 a.post(op='timezone',timezone='UTC',id='buyer_b',owner='buyer_b')
 assert_ok(conn.execute("SELECT v FROM settings WHERE k='timezone_buyer_a'").fetchone()[0]=='UTC','timezone saved for session owner')
 assert_ok(conn.execute("SELECT v FROM settings WHERE k='timezone_buyer_b'").fetchone() is None,'timezone cannot change another user')
 assert_ok('Время — UTC' in a.req('?page=leads')[1] and 'Время — Europe/Moscow' in b.req('?page=leads')[1],'personal timezone displayed independently')
 a.post(op='timezone',timezone='Invalid/Zone')
 assert_ok(conn.execute("SELECT v FROM settings WHERE k='timezone_buyer_a'").fetchone()[0]=='UTC','invalid timezone rejected')
 a.post(op='timezone',timezone='Europe/Moscow')

 assert_ok('111111111' in a.req('?page=leads')[1] and '222222222' not in a.req('?page=leads')[1],'lead list scoped')
 assert_ok(a.req('?page=leads&id='+ids['route_b'])[0]==404,'foreign lead detail blocked')
 assert_ok('PRIVATE_RESPONSE_a' not in a.req('?page=leads&id='+ids['route_a'])[1],'raw external responses admin only')
 for op in ['duplicate','rotate','diagnose']:
  assert_ok('недоступна' in a.post(op=op,id='route_b')[1],op+' foreign route blocked')
 for kind in ['tracker','partner','route']:
  assert_ok('недоступна' in a.post(op='save',kind=kind,id=kind+'_b',name='ATTACK')[1],'foreign '+kind+' update blocked')
 assert_ok('недоступна' in a.post(op='reconcile',id=ids['route_b'],decision='sent',verified='yes',reference='attack')[1],'foreign reconcile blocked')
 assert_ok('только администратору' in a.post(op='user_save',login='hacker',name='ATTACK',role='admin',password='password-long-123',active='on')[1],'buyer cannot create admin')
 assert_ok('только администратору' in a.post(op='assign_route',id='route_b',owner='buyer_a')[1],'buyer cannot transfer route')
 assert_ok('Недопустимый владелец' in a.post(op='save',kind='tracker',id='',name='ATTACK',owner='buyer_b')[1],'forged owner rejected')
 assert_ok('недоступна' in a.post(op='save',kind='route',id='',name='ATTACK',tracker='tracker_b',partner='partner_a')[1],'foreign dependency rejected')
 # Creating own resources assigns the logged-in user even without an owner field.
 own_before=conn.execute("SELECT count(*) FROM entities WHERE owner='buyer_a' AND kind='tracker'").fetchone()[0]
 a.post(op='save',kind='tracker',name='OWN_NEW',version='v1',click_url='https://example.invalid/click.php',api_key='own-key')
 assert_ok(conn.execute("SELECT count(*) FROM entities WHERE owner='buyer_a' AND kind='tracker'").fetchone()[0]==own_before+1,'new resource assigned to session owner')
 # Administrator manages buyer accounts, preserving an omitted password on edit.
 admin.post(op='user_save',login='buyer-c',name='Buyer c',role='buyer',password='buyer-password-123',active='on')
 c=Client();assert_ok('Вход в панель' not in c.login('buyer-c','buyer-password-123')[1],'admin-created account can log in')
 uid=conn.execute("SELECT id FROM users WHERE login='buyer-c'").fetchone()[0]
 admin.post(op='user_save',id=uid,name='Buyer c renamed',role='buyer',password='',active='on')
 assert_ok('Buyer c renamed' in c.req()[1],'empty password edit preserves login')
 # Admin can move a whole isolated route; old API key and old UI access are revoked.
 admin.post(op='assign_route',id='route_a',owner=uid)
 assert_ok(a.req('?page=route&edit=route_a')[0]==404,'transfer revokes prior owner')
 assert_ok('ROUTE_a' in c.req('?page=route')[1],'transfer grants new owner')
 payload=json.dumps({'route_id':'route_a','op':'check'}).encode()
 assert_ok(Client().req(data=payload,headers={'Content-Type':'application/json','X-Bridge-Secret':'a'*64})[0]==401,'transfer rotates API secret')
 assert_ok(c.req('?page=leads&id='+ids['route_a'])[0]==200,'lead history follows route ownership')
 # Password reset and disabling immediately invalidate existing sessions.
 admin.post(op='user_save',id='buyer_b',name='Buyer b',role='buyer',password='reset-password-123',active='on')
 assert_ok('Вход в панель' in b.req()[1],'password reset invalidates session')
 assert_ok('Вход в панель' not in b.login('buyer-b','reset-password-123')[1],'reset password works')
 admin.post(op='user_save',id='buyer_b',name='Buyer b',role='buyer',password='')
 assert_ok('Вход в панель' in b.req()[1],'disable invalidates session')
 assert_ok(Client().req(data=json.dumps({'route_id':'route_b','op':'check'}).encode(),headers={'Content-Type':'application/json','X-Bridge-Secret':'b'*64})[0]==403,'disabled account API blocked')
 assert_ok('Свои права' in admin.post(op='user_save',id='admin',name='Admin',role='buyer',password='')[1],'cannot remove own admin access')
 assert_ok(conn.execute('pragma integrity_check').fetchone()[0]=='ok','integrity after account operations')
 print('ALL ACCOUNT HTTP TESTS PASSED')
finally:
 server.terminate();server.wait(timeout=10);log.close()
