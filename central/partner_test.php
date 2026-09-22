<?php
declare(strict_types=1);
define('BRIDGE_TEST',true);require __DIR__.'/action.php';
$dir=sys_get_temp_dir().'/bridge-partner-'.bin2hex(random_bytes(8));mkdir($dir,0700);putenv('BRIDGE_DATA='.$dir);file_put_contents($dir.'/master.key',random_bytes(32));
function ck($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label."\n";}
function rejects($f,$label){try{$f();}catch(InvalidArgumentException $e){ck(true,$label);return;}throw new RuntimeException($label);}
try{
setting('base_url','https://bridge.example');
$t=['version'=>'v2','click_url'=>'https://tracker.example/click','api_key'=>'test'];saveEntity('t','tracker',$t);
$r=['name'=>'Test','buyer'=>'test','tracker'=>'t','partner'=>'p','campaign_key'=>'test','offer_id'=>'123','flow_id'=>'456','country'=>'ZA','currency'=>'ZAR','active'=>true,'tokens'=>[]];saveEntity('r','route',$r);
$p=['lead_id'=>'12345678901234567890','phone'=>'+27840000000','name'=>'Test','ip'=>'8.8.8.8','campaign_id'=>'12345678901234567890','adgroup_id'=>'2','ad_id'=>'3','adid_v2'=>'4','adid_v2_name'=>'Separate'];
foreach(['skylead'=>'https://api.skylead.biz/wm/push.json','cashfactories'=>'https://cashfactories.com/api/wm/push.json'] as $type=>$endpoint){
 $partner=['type'=>$type,'token'=>'mock&token'];saveEntity('p','partner',$partner);
 [$url,$body,$headers]=partnerRequest($partner,$r,$p,'https://bridge.example','click1');$b=json_decode($body,true);
 ck($url===$endpoint.'?id=mock%26token','fixed endpoint '.$type);
 ck($b['flow']==='456'&&$b['offer']==='123'&&$b['ip']==='8.8.8.8'&&$b['phone']==='27840000000'&&$b['country']==='ZA','required body '.$type);
 ck($b['subid']==='click1'&&$b['uuid']===$p['lead_id']&&$b['utm_campaign']===$p['campaign_id']&&$b['sub4']==='4'&&$b['sub5']==='Separate','attribution precision '.$type);
 $missing=$p;unset($missing['ip']);rejects(fn()=>enqueue('r',$r,$missing),'no IP rejected before enqueue '.$type);
 $long=$p;$long['ad_name']=str_repeat('x',256);rejects(fn()=>partnerRequest($partner,$r,$long,'https://bridge.example','click1'),'long tag not truncated');
 foreach(['127.0.0.1','10.0.0.1','bad'] as $ip){$bad=$p;$bad['ip']=$ip;rejects(fn()=>validatePartnerLead($partner,$r,$bad),'invalid customer IP');}
 $responses=['{"status":"ok","id":1234}'=>'sent','{"status":"ok","id":123456789012345678901}'=>'sent','{"status":"ok"}'=>'partner_review','{"status":"error","error":"duplicate","id":1234}'=>'partner_review','{"status":"ok","id":true}'=>'partner_review','<html>error</html>'=>'partner_review','{"status":"ok","id":1.2}'=>'partner_review','timeout'=>'partner_review'];
 foreach($responses as $body=>$expected){$p['lead_id']=(string)((int)($n??0)+1);$n=(int)$p['lead_id'];$a=enqueue('r',$r,$p);$calls=[];
 $mock=function($url,$request,$headers)use(&$calls,$body){$calls[]=$url;if(str_contains($url,'tracker.example'))return ['code'=>200,'error'=>'','body'=>'{"click_info":{"id":"confirmed-click"}}'];return ['code'=>$body==='timeout'?0:200,'error'=>$body==='timeout'?'transport':'','body'=>$body];};
 processLead($a['receipt'],$mock);$l=sql('SELECT * FROM leads WHERE id=?',[$a['receipt']])->fetch();ck($l['state']===$expected&&count($calls)===2,'acceptance '.$type.' '.$body);
 if(str_contains($body,'123456789012345678901'))ck($l['partner_id']==='123456789012345678901','big partner ID preserved');
 processLead($a['receipt'],$mock);ck(count($calls)===2,'no blind repeat '.$type);
 }
 $p['lead_id']=(string)++$n;$a=enqueue('r',$r,$p);$calls=0;processLead($a['receipt'],function()use(&$calls){$calls++;return ['code'=>200,'error'=>'','body'=>'{}'];});ck($calls===1,'invalid tracker never calls partner');
}
}finally{foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
