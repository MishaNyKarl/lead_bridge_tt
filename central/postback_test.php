<?php
define('BRIDGE_TEST',true);require __DIR__.'/action.php';
$dir=sys_get_temp_dir().'/bridge-postback-'.bin2hex(random_bytes(6));mkdir($dir,0700);putenv('BRIDGE_DATA='.$dir);file_put_contents($dir.'/master.key',random_bytes(32));
function ok($v,$msg){if(!$v)throw new RuntimeException($msg);echo "PASS $msg\n";}
$r=['secret'=>str_repeat('a',64)];saveEntity('r','route',$r);saveEntity('other','route',$r);
sql("INSERT INTO leads(id,route,external_id,fingerprint,state,payload,snapshot,click_id,created,updated) VALUES('l','r','123','f','partner_pending','','','click-1','now','now')");
$a=['route'=>'r','key'=>postbackKey('r',$r),'clickid'=>'click-1','status'=>'new','leadid'=>'pp-1'];
ok(receivePostback($a)['applied'],'postback arrives while PP request pending');
try{receivePostback(array_replace($a,['key'=>str_repeat('b',64)]));throw new RuntimeException('bad key accepted');}catch(InvalidArgumentException $e){ok($e->getCode()===401,'invalid key rejected');}
ok(receivePostback(array_replace($a,['route'=>'other','key'=>postbackKey('other',$r)]))['status']==='ignored','other route cannot update lead');
ok(receivePostback($a)['duplicate'],'duplicate idempotent');
ok(receivePostback(array_replace($a,['status'=>'approve']))['applied'],'approve normalized');
ok(!receivePostback($a)['applied'],'delayed new ignored');
ok(receivePostback(array_replace($a,['status'=>'reject']))['applied'],'decision correction accepted');
ok(receivePostback(array_replace($a,['status'=>'paid']))['applied'],'paid accepted');
ok(!receivePostback(array_replace($a,['status'=>'approved']))['applied'],'paid not regressed');
try{receivePostback(array_replace($a,['status'=>'garbage']));throw new RuntimeException('bad status accepted');}catch(InvalidArgumentException $e){ok(true,'unknown status rejected');}
try{receivePostback(array_replace($a,['leadid'=>'different']));throw new RuntimeException('bad lead accepted');}catch(InvalidArgumentException $e){ok($e->getCode()===409,'partner lead conflict rejected');}
$l=sql("SELECT * FROM leads WHERE id='l'")->fetch();ok($l['state']==='partner_pending','postback does not alter delivery state');ok(result($l)['partner_status']==='paid','status exposed to receipt polling');
ok(postbackKey('r',['secret'=>str_repeat('c',64)])!==$a['key'],'key rotation invalidates old postback key');
ok(sql('PRAGMA integrity_check')->fetchColumn()==='ok','database integrity');

// Account-wide matching must use the immutable partner, never the current route.
saveEntity('p1','partner',['name'=>'Account 1']);saveEntity('p2','partner',['name'=>'Account 2']);
$base=['route'=>['partner'=>'p1']];
sql("UPDATE leads SET snapshot=? WHERE id='l'",[seal($base)]);
saveEntity('r','route',['secret'=>str_repeat('a',64),'partner'=>'p2']);
$account=['partner'=>'p1','key'=>partnerPostbackKey('p1'),'clickid'=>'click-1','status'=>'paid','leadid'=>'pp-1'];
ok(receivePostback($account)['duplicate'],'account matches original partner after route edit');
ok(receivePostback(array_replace($account,['partner'=>'p2','key'=>partnerPostbackKey('p2')]))['status']==='ignored','other account cannot update lead');
ok(receivePostback(array_replace($account,['clickid'=>'landing-only-click']))['status']==='ignored','ordinary landing lead ignored');
ok(receivePostback(array_replace($account,['clickid'=>'']))['status']==='ignored','ordinary lead without click ID ignored');
try{receivePostback(array_replace($account,['partner'=>'p2']));throw new RuntimeException('wrong key accepted');}catch(InvalidArgumentException $e){ok($e->getCode()===401,'account keys isolated');}
sql("INSERT INTO leads(id,route,external_id,fingerprint,state,payload,snapshot,click_id,created,updated) VALUES('l2','other','456','f','sent','',?,'click-2','now','now')",[seal($base)]);
ok(receivePostback(array_replace($account,['clickid'=>'click-2','leadid'=>'pp-2','status'=>'new']))['applied'],'one account URL handles another route');
sql("INSERT INTO leads(id,route,external_id,fingerprint,state,payload,snapshot,click_id,created,updated) VALUES('l3','other','789','f','sent','',?,'click-1','now','now')",[seal($base)]);
ok(receivePostback($account)['status']==='ignored','ambiguous click does not update arbitrary lead');
ok((int)sql('SELECT count(*) FROM postback_conflicts')->fetchColumn()===1,'ambiguous callback stored for review');
ok(!sql("SELECT 1 FROM partner_status WHERE lead='l3'")->fetchColumn(),'collision does not write status');

// Skylead/Cashfactories stage values normalize without changing delivery or snapshots.
$sky=array_replace($account,['postback'=>'skylead','clickid'=>'click-2','leadid'=>'pp-2']);
$before=sql("SELECT snapshot FROM leads WHERE id='l2'")->fetchColumn();
foreach(['hold'=>'hold','approve'=>'approved','cancel'=>'rejected','trash'=>'trash'] as $raw=>$expected){
    receivePostback(array_replace($sky,['status'=>$raw]));
    ok(sql("SELECT status FROM partner_status WHERE lead='l2'")->fetchColumn()===$expected,'Skylead maps '.$raw);
    if($raw==='approve'){
        ok(!receivePostback(array_replace($sky,['status'=>'hold']))['applied'],'late hold cannot undo approval');
        ok(!receivePostback(array_replace($sky,['status'=>'wait']))['applied'],'late wait cannot undo approval');
    }
}
$cash=array_replace($sky,['postback'=>'cashfactories','status'=>'approve']);
ok(receivePostback($cash)['applied'],'Cashfactories uses same mapping');
ok(receivePostback($cash)['duplicate'],'Cashfactories duplicate idempotent');
try{receivePostback(array_replace($sky,['status'=>'unknown']));throw new RuntimeException('unknown stage accepted');}catch(InvalidArgumentException $e){ok(true,'unknown Skylead stage rejected');}
try{receivePostback(array_replace($sky,['postback'=>'lemonad','status'=>'hold']));throw new RuntimeException('Lemonad accepted hold');}catch(InvalidArgumentException $e){ok(true,'Lemonad status rules unchanged');}
ok(sql("SELECT snapshot FROM leads WHERE id='l2'")->fetchColumn()===$before,'postback leaves snapshot unchanged');
ok(sql("SELECT state FROM leads WHERE id='l2'")->fetchColumn()==='sent','postback leaves delivery unchanged');
setting('base_url','https://bridge.example.com');
ok(str_contains(partnerPostbackUrl('p1','skylead'),'clickid={subid}&status={stage}&leadid={id}'),'Skylead macros exact');
ok(str_contains(partnerPostbackUrl('p1','cashfactories'),'postback=cashfactories'),'Cashfactories URL selector');
ok(str_contains(partnerPostbackUrl('p1','lemonad'),'clickid={clickid}&status={status}&leadid={leadid}'),'Lemonad URL unchanged');
