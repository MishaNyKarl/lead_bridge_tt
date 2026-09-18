<?php
define('BRIDGE_TEST',true);require __DIR__.'/action.php';
$dir=sys_get_temp_dir().'/bridge-mapping-'.bin2hex(random_bytes(8));mkdir($dir,0700);putenv('BRIDGE_DATA='.$dir);file_put_contents($dir.'/master.key',random_bytes(32));
function ok($v,$label){if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
function bad(callable $f,$label){try{$f();}catch(InvalidArgumentException $e){ok(true,$label);return;}throw new RuntimeException($label);}
sql('INSERT INTO users VALUES(?,?,?,?,?,?,?)',['admin','admin','Admin','hash','admin',1,'epoch']);
sql('INSERT INTO users VALUES(?,?,?,?,?,?,?)',['user1','buyer.a-01','Buyer','hash','buyer',1,'epoch']);
setting('base_url','https://bridge.example.com');
saveEntity('t','tracker',['version'=>'v1','click_url'=>'https://example.invalid/click.php','api_key'=>'key']);saveEntity('p','partner',['type'=>'lemonad','token'=>'token']);
$route=['name'=>'legacy','buyer'=>'legacy_buyer','tracker'=>'t','partner'=>'p','campaign_key'=>'campaign','offer_id'=>'offer','active'=>true,'secret'=>str_repeat('a',64),'tokens'=>defaultTokens()];
saveEntity('r','route',$route);sql('UPDATE entities SET owner=?',['user1']);
$input=['lead_id'=>'12345678901234567890','phone'=>'+254700000001','name'=>'Test'];$res=enqueue('r',$route,$input);stage($res['receipt'],'sent',['click_id'=>'already','partner_id'=>'already']);
migrateBuyerLogins();$m=entity('r','route');ok($m['buyer']==='buyer.a-01','buyer comes from owner login');ok($m['dedupe_buyer']==='legacy_buyer','legacy dedupe namespace frozen');
ok(enqueue('r',$m,$input)['stage']==='sent','migration preserves duplicate result');
$new=$m;unset($new['dedupe_buyer']);saveEntity('new','route',$new);sql('UPDATE entities SET owner=? WHERE id=?',['user1','new']);bad(fn()=>enqueue('new',$new,$input),'account-wide duplicate blocked across legacy namespaces');
$post=['token_campaign_id'=>'CAMPAIGN','slot_campaign_id'=>'8','token_ad_id'=>'creative','slot_ad_id'=>'2','token_adgroup_id'=>'group','slot_adgroup_id'=>'4','token_lead_id'=>'lead','slot_lead_id'=>'10'];
[$params,$slots]=parseMapping($post,'v1');ok($slots['ad_id']==='2'&&$slots['campaign_id']==='8','arbitrary slot permutation');
$new['tokens']=$params;$new['token_slots']=$slots;$data=['campaign_id'=>'101','ad_id'=>'102','adgroup_id'=>'103','lead_id'=>'104'];
foreach(['v1','v2'] as $version){$t=entity('t','tracker');$t['version']=$version;trackerClick($t,$new,$data,'https://bridge.example.com',function($url,$body,$headers)use($version){parse_str(parse_url($url,PHP_URL_QUERY),$q);ok($q['CAMPAIGN']==='101'&&$q['creative']==='102'&&$q['group']==='103'&&$q['lead']==='104','custom parameters transmitted '.$version);ok(!isset($q['campaign_id'])&&!isset($q['ad_id']),'no old parameters '.$version);return ['code'=>200,'error'=>'','body'=>'{}'];});}
$checks=mappingChecks($new,$data,['token_8'=>'101','token_2'=>'102','token_4'=>'103','token_10'=>'104'],'v2');ok($checks['ad_id']['result']==='совпадает','v2 slot check');
$checks=mappingChecks($new,$data,['token_2'=>'wrong'],'v2');ok($checks['ad_id']['result']==='не совпадает','v2 mismatch visible');
bad(fn()=>parseMapping(array_merge($post,['slot_ad_id'=>'8']),'v1'),'duplicate slots rejected');
bad(fn()=>parseMapping(array_merge($post,['slot_ad_id'=>'08']),'v1'),'slot numbers canonicalized');
bad(fn()=>parseMapping(array_merge($post,['token_ad_id'=>'CAMPAIGN']),'v1'),'duplicate parameter rejected');
bad(fn()=>parseMapping(array_merge($post,['slot_ad_id'=>'11']),'v1'),'v1 range enforced');
bad(fn()=>parseMapping(array_merge($post,['token_ad_id'=>'api_key']),'v1'),'service parameter rejected');
bad(fn()=>parseMapping(array_merge($post,['token_ad_id'=>'']),'v1'),'slot requires parameter');
[$params,$slots]=parseMapping(array_merge($post,['token_ad_id'=>'','slot_ad_id'=>'']),'v1');ok($params['ad_id']===''&&$slots['ad_id']==='','disabled field supported');
ob_start();mappingForm($new);$html=ob_get_clean();ok(str_contains($html,'value="2" selected'),'numeric slot remains selected after reload');ok(!str_contains($html,'name="slot_ad_id" required'),'optional slot selection supports legacy configurations');
ok(sql('PRAGMA integrity_check')->fetchColumn()==='ok','database integrity');
echo "ALL MAPPING TESTS PASSED\n";
