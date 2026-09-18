"""Run on the server against an isolated PHP test process, never external services."""
import tempfile, pathlib, subprocess, os, urllib.request, urllib.error, json, time, re, socket
root=pathlib.Path(__file__).resolve().parent
work=pathlib.Path(tempfile.mkdtemp(prefix='bridge-http-test-'))
env=dict(os.environ,BRIDGE_DATA=str(work),BRIDGE_ADMIN_PASSWORD='test-password-123456',BRIDGE_URL='https://bridge.example.com')
subprocess.run(['php',str(root/'action.php'),'init'],env=env,check=True,capture_output=True)
fixture=work/'fixture.php'
fixture.write_text('''<?php
define('BRIDGE_TEST',true);require $argv[1];
saveEntity('t','tracker',['name'=>'test','version'=>'v2','click_url'=>'https://tracker.example.com/click','api_key'=>'test']);
saveEntity('p','partner',['name'=>'test','type'=>'lemonad','token'=>'test']);
$r=['name'=>'test','buyer'=>'test','tracker'=>'t','partner'=>'p','campaign_key'=>'test','offer_id'=>'test','active'=>true,'secret'=>str_repeat('a',64),'tokens'=>defaultTokens()];saveEntity('route_abc','route',$r);
saveEntity('route_def','route',array_merge($r,['buyer'=>'other','secret'=>str_repeat('b',64)]));
if(($argv[2]??'')==='process')foreach(sql("SELECT id FROM leads WHERE state='queued'")->fetchAll(PDO::FETCH_COLUMN) as $id)processLead($id,fn($url,$body,$headers)=>['code'=>200,'error'=>'','body'=>str_contains($url,'sendmelead')?' {"result":"ok","localClickId":"ref"}':'{"click_info":{"id":"click"}}']);
''')
subprocess.run(['php',str(fixture),str(root/'action.php')],env=env,check=True)
sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
log=open(work/'server.log','w');server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],env=env,stdout=log,stderr=log)
base=f'http://127.0.0.1:{port}/action.php'
def req(body=None,secret='a'*64,path=''):
    headers={'Content-Type':'application/json','X-Bridge-Secret':secret}
    r=urllib.request.Request(base+path,data=json.dumps(body).encode() if body is not None else None,headers=headers)
    try:response=urllib.request.urlopen(r,timeout=10)
    except urllib.error.HTTPError as e:response=e
    content=response.read().decode();return response.status,content,response.headers
try:
    for _ in range(30):
        try:req(path='?health=1');break
        except urllib.error.URLError:time.sleep(.1)
    assert req({'route_id':'route_abc','op':'check'},secret='wrong')[0]==401
    assert req({'route_id':'route_abc','op':'check'})[0]==200
    body={'route_id':'route_abc','lead_id':'900000000000000001','name':'Test','phone':'+254700000001'}
    code,text,_=req(body);assert code==202;receipt=json.loads(text)['receipt']
    assert json.loads(req(body)[1])['receipt']==receipt
    assert req(dict(body,phone='+254700000002'))[0]==400
    other=json.loads(req({'route_id':'route_def','op':'status','receipts':[receipt]},secret='b'*64)[1]);assert not other['results']
    subprocess.run(['php',str(fixture),str(root/'action.php'),'process'],env=env,check=True)
    status=json.loads(req({'route_id':'route_abc','op':'status','receipts':[receipt]})[1]);assert status['results'][receipt]['stage']=='sent'
    assert req(body)[0]==200
    code,text,headers=req(path='?page=route');assert 'Вход в панель' in text and 'campaign_key' not in text
    assert 'no-store' in headers['Cache-Control'];assert "frame-ancestors 'none'" in headers['Content-Security-Policy']
    assert 'secure' in headers['Set-Cookie'].lower() and 'httponly' in headers['Set-Cookie'].lower()
    bad=urllib.request.Request(base,data=b'op=save&kind=tracker&name=oops',headers={'Content-Type':'application/x-www-form-urlencoded'})
    assert 'Сессия формы истекла' in urllib.request.urlopen(bad).read().decode()
    print('PASS HTTP: authentication, enqueue, duplicate, conflict, route isolation, worker completion, UI auth, CSRF, security headers')
finally:
    server.terminate();server.wait(timeout=10);log.close()
