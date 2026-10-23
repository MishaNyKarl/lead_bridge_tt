<?php
declare(strict_types=1);
define('BRIDGE_TEST',true);require __DIR__.'/action.php';
$tmp=sys_get_temp_dir().'/bridge-google-'.bin2hex(random_bytes(6));mkdir($tmp,0700);putenv('BRIDGE_DATA='.$tmp);file_put_contents($tmp.'/master.key',random_bytes(32));
function test($ok,$label){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function fails(callable $f,$label){try{$f();}catch(InvalidArgumentException|BackgroundDenied $e){test(true,$label);return;}throw new RuntimeException('FAIL '.$label);}
function cell($v){return ['userEnteredValue'=>[is_string($v)?'stringValue':'numberValue'=>$v],'effectiveValue'=>[is_string($v)?'stringValue':'numberValue'=>$v]];}
function row(array $r){return ['values'=>array_map('cell',$r)];}
setting('base_url','https://bridge.example.com');setting('accounts_migrated','1');
foreach(['a','b'] as $u)sql('INSERT INTO users VALUES(?,?,?,?,?,?,?)',[$u,'buyer-'.$u,'Buyer '.$u,password_hash('test-password-only',PASSWORD_DEFAULT),'buyer',1,'epoch']);
$_SESSION=['uid'=>'a','epoch'=>'epoch','last'=>time(),'csrf'=>'csrf-test'];
saveEntity('tracker','tracker',['name'=>'test','version'=>'v1','click_url'=>'https://tracker.example.com/click.php','api_key'=>'stub']);saveEntity('partner','partner',['name'=>'test','type'=>'lemonad','token'=>'stub']);
$route=['name'=>'route','buyer'=>'a','tracker'=>'tracker','partner'=>'partner','campaign_key'=>'campaign','offer_id'=>'offer','active'=>true,'secret'=>str_repeat('a',64),'tokens'=>defaultTokens()];saveEntity('route','route',$route);sql('UPDATE entities SET owner="a"');
googleSaveAccount('a',['access_token'=>'TEST_ACCESS','refresh_token'=>'TEST_REFRESH','expires'=>time()+3600]);googleSchema();
$book='spreadsheet_test_123';$sheet='7';$title='Лист';$grid=[row(['Lead ID','Name','Phone','Form ID']),row(['1000000000000000001','Old','+254700000001',9.123e18])];$writes=[];$readHook=null;$failWrite=false;
$http=function($url,$body,$headers)use(&$grid,&$title,&$writes,&$readHook,&$failWrite,$book,$sheet){
    test(str_starts_with($url,'https://sheets.googleapis.com/v4/spreadsheets/'.$book),'Google stub never calls tracker/partner');
    test(in_array('Authorization: Bearer TEST_ACCESS',$headers,true),'authenticated Google requests');
    if(str_contains($url,':getByDataFilter')){
        $range=json_decode($body,true)['dataFilters'][0]['gridRange'];if($readHook)$readHook($range);
        $rows=array_slice($grid,$range['startRowIndex'],$range['endRowIndex']-$range['startRowIndex']);
        $data=['sheets'=>[['properties'=>['sheetId'=>(int)$sheet],'data'=>[['startRow'=>$range['startRowIndex'],'rowData'=>$rows]]]]];
    }elseif(str_contains($url,':batchUpdate')){
        $req=json_decode($body,true)['requests'];if($failWrite){$failWrite=false;return ['code'=>0,'error'=>'timeout','body'=>''];}
        foreach($req as $r)if(isset($r['updateCells'])){$u=$r['updateCells'];$rr=$u['range']['startRowIndex'];$cc=$u['range']['startColumnIndex'];$v=$u['rows'][0]['values'][0]['userEnteredValue']['stringValue'];$writes[]=[$rr,$cc,$v];$grid[$rr]['values'][$cc]=cell($v);ksort($grid[$rr]['values']);}
        $data=['spreadsheetId'=>$book,'replies'=>array_fill(0,count($req),new stdClass())];
    }else $data=['spreadsheetId'=>$book,'properties'=>['title'=>'Test book'],'sheets'=>[['properties'=>['sheetId'=>(int)$sheet,'title'=>$title,'gridProperties'=>['rowCount'=>1000,'columnCount'=>30]]]]];
    return ['code'=>200,'error'=>'','body'=>json_encode($data)];
};
function source($id){return sql('SELECT * FROM sheet_sources WHERE id=?',[$id])->fetch();}
$id=googleConnectSource('a',$book,$sheet,'route',false,$http);test(!source($id)['active'],'prepared source remains paused');test(!sql('SELECT 1 FROM leads')->fetchColumn(),'preparation never enqueues');
test(count($writes)===count(GOOGLE_COLUMNS),'only service headers written');test(!str_contains(file_get_contents($tmp.'/bridge.sqlite'),'TEST_REFRESH'),'refresh token encrypted');
fails(fn()=>googleConnectSource('a',$book,$sheet,'route',true,$http),'duplicate source cannot change binding');
fails(fn()=>googleConnectSource('b',$book,'8','route',true,$http),'foreign route rejected before Google call');
sql('UPDATE sheet_sources SET active=1 WHERE id=?',[$id]);googleSyncSource(source($id),$http);test((int)sql('SELECT count(*) FROM leads')->fetchColumn()===0,'existing IDs skipped by default');test(unseal(source($id)['data'])['cursor']===1,'empty reserved grid does not delay new lead polling');
$grid[]=row(['1000000000000000002','New','+254700000002',9.123e18]);googleSyncSource(source($id),$http);
$lead=sql('SELECT * FROM leads WHERE external_id=?',['1000000000000000002'])->fetch();test($lead&&$lead['state']==='queued','new lead durably queued');$payload=unseal($lead['payload']);test($payload['form_id']===''&&$payload['lead_id']==='1000000000000000002','damaged optional form excluded, lead ID exact');
$h=googleHeaders($grid[0]);$receiptCol=array_search('Bridge Receipt',$h,true);test(googleCell($grid[2]['values'][$receiptCol])===$lead['id'],'receipt written');
stage($lead['id'],'sent',['click_id'=>'CLICK_STUB','partner_id'=>'REF_STUB']);sql('INSERT INTO partner_status VALUES(?,?,?,?)',[$lead['id'],'approved','REF_STUB',gmdate('c')]);
$title='Переименован';googleSyncSource(source($id),$http);test(googleCell($grid[2]['values'][array_search('Webhook_Status',$h,true)])==='SENT','SENT written');test(googleCell($grid[2]['values'][array_search('TikTok Lead Status',$h,true)])==='approved','partner status written separately');
$before=count($writes);googleSyncSource(source($id),$http);test(count($writes)===$before,'unchanged statuses do not consume writes');test(sql('SELECT count(*) FROM leads')->fetchColumn()==1,'polling never duplicates leads');test(unseal(source($id)['data'])['title']===$title,'rename preserves binding');
// A failed Sheets write must not lose the server receipt or create a second enqueue.
$grid[]=row(['1000000000000000003','Write failure','+254700000003','']);$failWrite=true;fails(fn()=>googleSyncSource(source($id),$http),'uncertain Sheets write stops the pass');test(sql('SELECT count(*) FROM leads')->fetchColumn()==2,'enqueue survives lost write');googleSyncSource(source($id),$http);test(sql('SELECT count(*) FROM leads')->fetchColumn()==2,'lost receipt recovered without resend');
// Simulate sorting between the initial read and the identity recheck.
$grid[]=row(['1000000000000000004','Moving row','+254700000004','']);$moved=false;$readHook=function($range)use(&$grid,&$moved){if(!$moved&&$range['startRowIndex']===4){$grid[4]=row(['1000000000000000005','Replacement','+254700000005','']);$moved=true;}};
// Move occurs after the batch read but before the row identity recheck.
$writeBefore=count($writes);fails(fn()=>googleSyncSource(source($id),$http),'moved row blocks stale status write');$readHook=null;test(count($writes)===$writeBefore,'no status written to replacement row');
test($moved,'row move scenario exercised');
// Foreign receipt cannot read a lead from another route or owner.
$grid[4]=row(['1000000000000000005','Replacement','+254700000005','']);$grid[4]['values'][$receiptCol]=cell($lead['id']);googleSyncSource(source($id),$http);test(googleCell($grid[4]['values'][array_search('Webhook_Status',$h,true)])==='REVIEW','foreign receipt rejected');
$bad=row([9.123456789e18,'Bad','+254700000006','']);fails(fn()=>googlePayload($h,$bad),'numeric Lead ID rejected without rounding');
[$p,$warning]=googlePayload(['Lead ID','Phone','Form ID'],row(['9000000000000000001','+254700000001','9000000000000000123']));test($p['form_id']==='9000000000000000123'&&$warning==='','exact form preserved');
test(normalize(['lead_id'=>'1','phone'=>'+254700000001','form_id'=>9.123e18])['form_id']==='','API accepts lead without valid optional form');
$_SESSION['uid']='b';fails(fn()=>googleSource($id),'buyer cannot inspect foreign source');$_POST=['source'=>$id];fails(fn()=>googleMutation('google_toggle'),'buyer cannot enable foreign source');
$_SESSION['uid']='a';sql('UPDATE entities SET owner="b" WHERE id="route"');fails(fn()=>googleSyncSource(source($id),$http),'route reassignment blocks old connection');sql('UPDATE entities SET owner="a" WHERE id="route"');
sql('UPDATE users SET active=0 WHERE id="a"');fails(fn()=>googleSyncSource(source($id),$http),'disabled account cannot sync');sql('UPDATE users SET active=1 WHERE id="a"');
fails(fn()=>googleJson('https://sheets.googleapis.com/test',http:fn()=>['code'=>200,'error'=>'','body'=>'OK']),'HTTP 200 without JSON rejected');
$_SESSION['google_attempt']=['state'=>'expected','owner'=>'a','expires'=>time()+60];$_GET=['state'=>'wrong','code'=>'bad'];fails(fn()=>googleFinish(fn()=>throw new RuntimeException('must not call')),'OAuth state enforced');test(!isset($_SESSION['google_attempt']),'OAuth attempt consumed once');
setting('google_config',seal(['client_id'=>'stub','client_secret'=>'CLIENT_SECRET','project'=>'123','picker_key'=>'stub']));googleSaveAccount('a',['access_token'=>'expired','refresh_token'=>'TEST_REFRESH','expires'=>0]);
$refresh=function($url,$body,$headers){parse_str($body,$p);test($url==='https://oauth2.googleapis.com/token'&&$p['grant_type']==='refresh_token'&&$p['refresh_token']==='TEST_REFRESH','refresh uses fixed endpoint and stored grant');return ['code'=>200,'error'=>'','body'=>'{"access_token":"renewed","expires_in":3600}'];};test(googleToken('a',$refresh)==='renewed','background refresh works');
googleSaveAccount('a',['access_token'=>'expired','refresh_token'=>'TEST_REFRESH','expires'=>0]);
fails(fn()=>googleToken('a',function(){sql('DELETE FROM google_accounts WHERE owner="a"');return ['code'=>200,'error'=>'','body'=>'{"access_token":"late","expires_in":3600}'];}),'late refresh cannot undo disconnect');test(!sql('SELECT 1 FROM google_accounts WHERE owner="a"')->fetchColumn(),'disconnected grant remains deleted');
$_SESSION['google_attempt']=['state'=>'valid-state','owner'=>'a','expires'=>time()+60,'verifier'=>'stub-verifier','config'=>setting('google_config')];$_GET=['state'=>'valid-state','code'=>'stub-code'];
googleFinish(function($url,$body,$headers){parse_str($body,$p);test($p['code_verifier']==='stub-verifier'&&$p['grant_type']==='authorization_code','OAuth code uses original PKCE verifier');return ['code'=>200,'error'=>'','body'=>json_encode(['access_token'=>'oauth-stub','refresh_token'=>'oauth-refresh','expires_in'=>3600,'scope'=>GOOGLE_FILE_SCOPE])];});
test(googleToken('a')==='oauth-stub','OAuth grant stored for current owner');fails(fn()=>googleFinish(),'OAuth callback replay rejected');
googleSaveAccount('b',['access_token'=>'buyer-b-token','refresh_token'=>'buyer-b-refresh','expires'=>time()+3600]);
test(googleToken('a')==='oauth-stub'&&googleToken('b')==='buyer-b-token','buyers use separate Google grants');
ob_start();googlePersonalSettings();$personal=ob_get_clean();test(str_contains($personal,'Мой Google-аккаунт')&&str_contains($personal,'Ваш Google-аккаунт подключён.'),'buyer sees own connected Google settings');test(!str_contains($personal,'oauth-stub')&&!str_contains($personal,'buyer-b-token')&&!str_contains($personal,'CLIENT_SECRET'),'personal settings expose no tokens or app secret');
sql('INSERT INTO sheet_sources(id,owner,spreadsheet,sheet,route,data,active) VALUES(?,?,?,?,?,?,1)',['other-source','b','other-book','7','other-route',seal(['title'=>'other'])]);
googleMutation('google_disconnect');test(!sql('SELECT 1 FROM google_accounts WHERE owner="a"')->fetchColumn()&&googleToken('b')==='buyer-b-token','disconnect removes only current buyer grant');test(source($id)['active']==0&&source('other-source')['active']==1,'disconnect pauses only current buyer sources');
setting('google_config','');ob_start();googlePersonalSettings();$personal=ob_get_clean();test(str_contains($personal,'Подключить мой Google-аккаунт')&&str_contains($personal,' disabled')&&str_contains($personal,'каждый войдёт в свой Google'),'unconfigured app keeps personal connection visible with explanation');
test(sql('PRAGMA integrity_check')->fetchColumn()==='ok','Google connector database integrity');
