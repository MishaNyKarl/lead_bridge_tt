<?php
declare(strict_types=1);
define('BRIDGE_TEST',true);require __DIR__.'/action.php';
$dir=sys_get_temp_dir().'/bridge-partner-privacy-'.bin2hex(random_bytes(8));mkdir($dir,0700);putenv('BRIDGE_DATA='.$dir);file_put_contents($dir.'/master.key',random_bytes(32));
function ok($value,$label){if(!$value)throw new RuntimeException($label);echo "PASS $label\n";}
try{
    setting('base_url','https://leadform.example.com');
    $tracker=['version'=>'v2','click_url'=>'https://tracker.example/click','api_key'=>'fixture'];saveEntity('t','tracker',$tracker);
    $route=['buyer'=>'buyer-tiktok-leadform','dedupe_buyer'=>'legacy-instant-form','tracker'=>'t','partner'=>'p','offer_id'=>'123','flow_id'=>'456','country'=>'ZA','currency'=>'ZAR','active'=>true,'campaign_key'=>'instant-form-key','tokens'=>defaultTokens(),'partner_meta_mode'=>'all'];
    $payload=['lead_id'=>'123456789012345678901','phone'=>'+27840000000','name'=>'Test contact','ip'=>'8.8.8.8','campaign_id'=>'923456789012345678901','ad_id'=>'823456789012345678901','adgroup_id'=>'723456789012345678901','advertiser_id'=>'623456789012345678901','form_id'=>'523456789012345678901','placement'=>'TikTok Lead Form','campaign_name'=>'Instant Forms campaign','adgroup_name'=>'leadform group','ad_name'=>'TikTok Instant Form ad','adid_v2'=>'423456789012345678901','adid_v2_name'=>'lead-form creative'];
    $forbidden=[$route['buyer'],$route['dedupe_buyer'],$route['campaign_key'],'leadform.example.com'];
    foreach($payload as $key=>$value)if(!in_array($key,['phone','name','ip'],true))$forbidden[]=$value;
    foreach(['lemonad','skylead','cashfactories','offerstore'] as $type){
        foreach($type==='lemonad'?['webmaster']:['webmaster','agency'] as $mode){
            $partner=['type'=>$type,'account_type'=>$mode,'token'=>'fixture-token'];saveEntity('p','partner',$partner);saveEntity('r','route',$route);
            [$url,$json,$headers]=partnerRequest($partner,$route,$payload,'https://leadform.example.com','binom-click');$body=json_decode($json,true,512,JSON_THROW_ON_ERROR);
            ok($body['utm_source']==='tiktok','TikTok source allowed '.$type.' '.$mode);
            foreach($forbidden as $value)ok(!str_contains($json,$value),'source detail withheld '.$type.' '.$mode);
            ok(!preg_match('/lead.?form|instant.?form|placement|form_id|advertiser_id/i',$json),'no form identifiers or descriptive markers '.$type.' '.$mode);
            $keys=$type==='lemonad'?['offerId','name','phone','clickid','utm_source','utm_campaign','utm_content','utm_medium','utm_term']:['offer','ip','name','phone','subid','utm_source','utm_campaign','utm_content','utm_term','utm_medium','uuid','country','currency',...($mode==='agency'?['extu','exts']:['flow'])];
            sort($keys);$actual=array_keys($body);sort($actual);ok($actual===$keys,'outgoing fields allowlist '.$type.' '.$mode);
            // Grouping must be stable for the same buyer and separate across buyers/fields.
            $changed=$payload;$changed['ad_name']='another instant form';$changed['lead_id']='223456789012345678901';
            $next=json_decode(partnerRequest($partner,$route,$changed,'https://another.example.com','binom-click')[1],true);
            ok($body['utm_campaign']===$next['utm_campaign']&&$body['utm_content']===$next['utm_content'],'stable campaign/ad grouping '.$type.' '.$mode);
            if($type!=='lemonad')ok($body['uuid']!==$next['uuid'],'distinct opaque lead aliases '.$type.' '.$mode);
            $other=$route;$other['dedupe_buyer']='other-buyer';$otherBody=json_decode(partnerRequest($partner,$other,$payload,'https://leadform.example.com','binom-click')[1],true);
            ok($body['utm_campaign']!==$otherBody['utm_campaign'],'buyer alias isolation '.$type.' '.$mode);
            $same=$payload;$same['ad_id']=$same['campaign_id'];$sameBody=json_decode(partnerRequest($partner,$route,$same,'https://leadform.example.com','binom-click')[1],true);
            ok($sameBody['utm_campaign']!==$sameBody['utm_content'],'field alias separation '.$type.' '.$mode);
            // Exercise the actual worker with an old snapshot containing identifying metadata.
            $snapshot=['route'=>$route,'tracker'=>$tracker,'partner'=>$partner,'base_url'=>'https://leadform.example.com'];
            $id=$type.'-'.$mode;
            sql('INSERT INTO leads(id,route,external_id,fingerprint,state,payload,snapshot,click_id,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?)',[$id,'r',$payload['lead_id'],'fixture','click_ready',seal($payload),seal($snapshot),'binom-click','now','now']);
            $frozen=sql('SELECT snapshot FROM leads WHERE id=?',[$id])->fetchColumn();$calls=0;
            processLead($id,function($requestUrl,$requestBody,$requestHeaders)use(&$calls,$json,$type){
                $calls++;ok($requestBody===$json,'legacy snapshot uses safe outgoing adapter');
                ok(!preg_match('/lead.?form|instant.?form|referer/i',implode(' ',$requestHeaders)),'partner headers reveal no form');
                return ['code'=>200,'error'=>'','body'=>$type==='lemonad'?'{"result":"ok","localClickId":"partner-ref"}':'{"status":"ok","id":1234}'];
            });
            ok($calls===1&&sql('SELECT state FROM leads WHERE id=?',[$id])->fetchColumn()==='sent','safe legacy delivery '.$type.' '.$mode);
            ok(sql('SELECT snapshot FROM leads WHERE id=?',[$id])->fetchColumn()===$frozen,'legacy snapshot unchanged '.$type.' '.$mode);
        }
    }
    foreach(['v1','v2'] as $version){
        $tracker['version']=$version;
        trackerClick($tracker,$route,$payload,'https://bridge.example.com',function($url,$body,$headers){
            ok(!str_contains(implode(' ',$headers).($body??''),'InstantForm'),'tracker user agent has no form marker');
            parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
            ok($query['campaign_id']==='923456789012345678901','Binom still receives original attribution');
            return ['code'=>200,'error'=>'','body'=>'{}'];
        });
    }
    echo "ALL PARTNER PRIVACY TESTS PASSED\n";
}finally{foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
