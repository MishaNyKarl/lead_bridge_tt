<?php
define('BRIDGE_TEST',true);require __DIR__.'/action.php';
$dir=sys_get_temp_dir().'/bridge-poll-'.bin2hex(random_bytes(6));mkdir($dir,0700);putenv('BRIDGE_DATA='.$dir);file_put_contents($dir.'/master.key',random_bytes(32));
function ok($value,$message){if(!$value)throw new RuntimeException($message);echo "PASS $message\n";}
function fixture($id,$partner='p',$type='skylead',$mode='agency',$token='snapshot-token'){
 $route=['partner'=>$partner,'buyer'=>'buyer-'.$partner];$pp=['type'=>$type,'account_type'=>$mode,'token'=>$token];
 sql('INSERT INTO leads(id,route,external_id,fingerprint,state,payload,snapshot,click_id,partner_id,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$id,'r',$id,'f','queued',seal([]),seal(['route'=>$route,'partner'=>$pp]),'click-'.$id,$id,'now','now']);
 stage($id,'sent');return agencyExternalId($route,$id);
}
function responseFor($ids,$stage='approve',$partner='p'){
 $rows=[];foreach($ids as $id)$rows[]= ['uid'=>(string)$id,'id'=>agencyExternalId(['buyer'=>'buyer-'.$partner],(string)$id),'stage'=>$stage];
 return ['code'=>200,'error'=>'','body'=>json_encode($rows)];
}
try{
 $big='123456789012345678901';fixture($big);fixture('2');fixture('3','other','cashfactories');fixture('4','p','skylead','webmaster');
 saveEntity('p','partner',['type'=>'skylead','account_type'=>'webmaster','token'=>'new-token']);
 $calls=[];$mock=function($url,$body,$headers)use(&$calls){$calls[]=[$url,$body];parse_str($body,$q);return responseFor(explode(',',$q['oid']));};
 pollAgencyStatuses($mock,1000);
 ok(count($calls)===1&&$calls[0][0]==='https://api.skylead.biz/ext/list.json?id=snapshot-token','one read-only request uses snapshot credentials');
 ok(str_contains($calls[0][1],$big),'large PP ID sent intact');
 ok(sql('SELECT status FROM partner_status WHERE lead=?',[$big])->fetchColumn()==='approved','status linked by uid and external id');
 ok(sql("SELECT next_at FROM partner_poll WHERE lead='4'")->fetchColumn()===-1,'webmaster excluded');
 ok(result(sql('SELECT * FROM leads WHERE id=?',[$big])->fetch())['partner_status']==='approved','Sheets receipt exposes API status');
 ok(sql('SELECT next_at FROM partner_poll WHERE lead=?',[$big])->fetchColumn()===4600,'decided leads checked hourly');
 pollAgencyStatuses(function($url,$body)use(&$calls){$calls[]=[$url,$body];ok(str_starts_with($url,'https://cashfactories.com/api/ext/list.json'),'Cashfactories status endpoint');return responseFor(['3'],'wait','other');},1000);
 ok(sql("SELECT status FROM partner_status WHERE lead='3'")->fetchColumn()==='new','accounts kept separate');
 pollAgencyStatuses(function(){throw new RuntimeException('should not call early');},1001);
 ok(sql("SELECT next_at FROM partner_poll WHERE lead='3'")->fetchColumn()===1300,'no early polling');
 pollAgencyStatuses(fn()=>['code'=>0,'error'=>'transport','body'=>''],1300);
 ok(sql("SELECT status FROM partner_status WHERE lead='3'")->fetchColumn()==='new','timeout preserves previous status');
 pollAgencyStatuses(fn()=>['code'=>200,'error'=>'','body'=>'{}'],1600);
 ok(sql("SELECT next_at FROM partner_poll WHERE lead='3'")->fetchColumn()===2200,'missing lead backs off without inventing status');
 pollAgencyStatuses(fn()=>responseFor(['3'],'approve','wrong-owner'),2200);
 ok(sql("SELECT status FROM partner_status WHERE lead='3'")->fetchColumn()==='new','foreign external ID ignored');
 pollAgencyStatuses(fn()=>responseFor(['3'],'mystery','other'),3400);
 ok(sql("SELECT status FROM partner_status WHERE lead='3'")->fetchColumn()==='new','unknown status ignored');
 // Concurrent callback wins over an already in-flight poll.
 sql('UPDATE partner_poll SET next_at=99999');sql('UPDATE partner_poll SET next_at=0 WHERE lead=?',[$big]);
 pollAgencyStatuses(function()use($big){applyPartnerStatus($big,'rejected',$big);return responseFor([$big]);},5000);
 ok(sql('SELECT status FROM partner_status WHERE lead=?',[$big])->fetchColumn()==='rejected','callback during request not overwritten');
 sql('UPDATE partner_poll SET next_at=0 WHERE lead=?',[$big]);pollAgencyStatuses(fn()=>responseFor([$big],'wait'),6000);
 ok(sql('SELECT status FROM partner_status WHERE lead=?',[$big])->fetchColumn()==='rejected','initial status cannot regress a decision');
 sql('UPDATE partner_poll SET next_at=0 WHERE lead=?',[$big]);pollAgencyStatuses(fn()=>responseFor([$big],'approve'),10000);
 ok(sql('SELECT status FROM partner_status WHERE lead=?',[$big])->fetchColumn()==='approved','later decision correction applied');
 ok(sql("SELECT count(*) FROM leads WHERE state<>'sent'")->fetchColumn()===0,'poll never enqueues or resends');
 $events=sql('SELECT count(*) FROM partner_events')->fetchColumn();sql('UPDATE partner_poll SET next_at=0 WHERE lead=?',[$big]);pollAgencyStatuses(fn()=>responseFor([$big]),14000);
 ok(sql('SELECT count(*) FROM partner_events')->fetchColumn()===$events,'unchanged status is idempotent');
 // Batching across a large account never exceeds 100, and progresses through all leads.
 sql('UPDATE partner_poll SET next_at=999999');for($i=100;$i<205;$i++)fixture((string)$i,'batch');
 $sizes=[];for($i=0;$i<2;$i++)pollAgencyStatuses(function($url,$body)use(&$sizes){parse_str($body,$q);$ids=explode(',',$q['oid']);$sizes[]=count($ids);return responseFor($ids,'hold','batch');},15000);
 ok($sizes===[100,5],'bounded batches make progress');
 foreach(['{"status":"error","error":"key"}','<html>bad</html>','[{"uid":1.2,"id":"x","stage":"approve"}]'] as $body){try{agencyStatusRows(['code'=>200,'error'=>'','body'=>$body]);throw new LogicException('accepted invalid response');}catch(RuntimeException $e){ok(true,'reject invalid response');}}
 // Offerstore uses its own endpoint and immutable credentials, isolated from other networks.
 sql('UPDATE partner_poll SET next_at=999999');
 $offerBig='987654321098765432109';fixture($offerBig,'offer','offerstore');fixture('901','offer-wm','offerstore','webmaster');
 saveEntity('offer','partner',['type'=>'offerstore','account_type'=>'webmaster','token'=>'changed']);
 $offerSnapshot=sql('SELECT snapshot FROM leads WHERE id=?',[$offerBig])->fetchColumn();
 $offerReads=0;
 foreach(['wait'=>'new','hold'=>'hold','approve'=>'approved','cancel'=>'rejected','trash'=>'trash'] as $raw=>$expected){
  sql('UPDATE partner_poll SET next_at=0 WHERE lead=?',[$offerBig]);
  pollAgencyStatuses(function($url,$body,$headers)use($offerBig,$raw,&$offerReads){
   $offerReads++;parse_str($body,$params);
   ok($url==='https://api.offer.store/ext/list.json?id=snapshot-token'&&$params['oid']===$offerBig,'Offerstore polls snapshot token and exact internal ID');
   return responseFor([$offerBig],$raw,'offer');
  },20000);
  ok(sql('SELECT status FROM partner_status WHERE lead=?',[$offerBig])->fetchColumn()===$expected,'Offerstore API status '.$raw);
 }
 ok(sql("SELECT next_at FROM partner_poll WHERE lead='901'")->fetchColumn()===-1,'Offerstore webmaster excluded from polling');
 sql('UPDATE partner_poll SET next_at=0 WHERE lead=?',[$offerBig]);
 pollAgencyStatuses(fn()=>responseFor([$offerBig],'approve','foreign'),21000);
 ok(sql('SELECT status FROM partner_status WHERE lead=?',[$offerBig])->fetchColumn()==='trash','Offerstore foreign external ID ignored');
 ok(sql('SELECT snapshot FROM leads WHERE id=?',[$offerBig])->fetchColumn()===$offerSnapshot,'Offerstore poll never changes snapshot');
 ok(sql('SELECT state FROM leads WHERE id=?',[$offerBig])->fetchColumn()==='sent'&&$offerReads===5,'Offerstore polling never resends');
 // Local account state gates polling even when OpsVault is unavailable.
 sql('INSERT INTO users VALUES(?,?,?,?,?,?,?)',['poll_owner','poll-owner','Poll owner','fixture','buyer',0,'fixture']);
 saveEntity('r','route',['name'=>'Polling fixture']);sql('UPDATE entities SET owner=? WHERE id="r"',['poll_owner']);setting('accounts_migrated','1');
 opsSchema();sql('INSERT INTO sso_links VALUES(?,?,?)',['poll_owner',OPSVAULT_ISSUER,'poll-subject']);setting('sso_gate','0');
 $GLOBALS['ops_http']=function(){throw new LogicException('polling must not call OpsVault');};
 sql('UPDATE partner_poll SET next_at=999999');sql('UPDATE partner_poll SET next_at=0 WHERE lead=?',[$big]);$reads=0;
 $pollMock=function()use(&$reads,$big){$reads++;return responseFor([$big],'hold');};
 pollAgencyStatuses($pollMock,16000);ok($reads===0,'disabled local owner prevents polling');
 sql('UPDATE users SET active=1 WHERE id="poll_owner"');pollAgencyStatuses($pollMock,16000);
 ok($reads===1,'linked active owner polls without SSO gate or import grant');
 ok(sql('SELECT state FROM leads WHERE id=?',[$big])->fetchColumn()==='sent','local polling never resends SENT');
 ok(sql('PRAGMA integrity_check')->fetchColumn()==='ok','database integrity');
 echo "ALL POLLING TESTS PASSED\n";
}finally{foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
