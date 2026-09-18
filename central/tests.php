<?php
declare(strict_types=1);
define('BRIDGE_TEST',true);
require __DIR__.'/action.php';
if(($argv[1]??'')==='child'){
    putenv('BRIDGE_DATA='.$argv[2]);$r=entity('r','route');$r['active']=true;
    enqueue('r',$r,['lead_id'=>'8','name'=>'Test','phone'=>'+254700000001','campaign_id'=>'12345678901234567890','adgroup_id'=>'3','ad_id'=>'4']);exit;
}
$dir=sys_get_temp_dir().'/bridge-test-'.bin2hex(random_bytes(8));putenv('BRIDGE_DATA='.$dir);mkdir($dir,0700);file_put_contents($dir.'/master.key',random_bytes(32));
function check($ok,$label){if(!$ok)throw new RuntimeException('FAIL: '.$label);echo 'PASS '.$label."\n";}
function throws(callable $f,string $label){try{$f();}catch(InvalidArgumentException $e){check(true,$label);return;}throw new RuntimeException('FAIL no exception: '.$label);}
setting('base_url','https://bridge.example.com');
saveEntity('t','tracker',['name'=>'test','version'=>'v1','click_url'=>'https://tracker.example.com/click.php','api_key'=>'API_SECRET']);
saveEntity('p','partner',['name'=>'test','type'=>'lemonad','token'=>'PARTNER_SECRET']);
$r=['name'=>'route','buyer'=>'buyer1','tracker'=>'t','partner'=>'p','campaign_key'=>'campaign_A','offer_id'=>'offer_A','active'=>true,'secret'=>str_repeat('a',64),'tokens'=>defaultTokens()];saveEntity('r','route',$r);
$p=['lead_id'=>'7686145442513699090','name'=>'Test','phone'=>'+254700000001','campaign_id'=>'12345678901234567890','adgroup_id'=>'3','ad_id'=>'4'];
$calls=[];$mock=function($url,$body,$headers)use(&$calls){$calls[]=[$url,$body,$headers];return ['code'=>200,'error'=>'','body'=>str_contains($url,'sendmelead')?' {"result":"ok","localClickId":"partner-1"}':'{"offer":{"url":"https://example.invalid/?binom_click_id=click-1"}}'];};
$a=enqueue('r',$r,$p);check($a['stage']==='queued','durable enqueue');
processLead($a['receipt'],$mock);$l=sql('SELECT * FROM leads WHERE id=?',[$a['receipt']])->fetch();check($l['state']==='sent'&&$l['click_id']==='click-1','success');check(count($calls)===2,'one request per service');check(str_contains($calls[0][0],'campaign_id=12345678901234567890'),'ID precision');check(str_contains($calls[0][0],'adgroup_id=3')&&str_contains($calls[0][0],'ad_id=4'),'token mapping');
enqueue('r',$r,$p);processLead($a['receipt'],$mock);check(count($calls)===2,'duplicate does not send');
$r['offer_id']='offer_B';enqueue('r',$r,$p);check(count($calls)===2,'offer edit cannot bypass dedupe');
throws(fn()=>enqueue('other',$r,$p),'same buyer other route rejected');
$changed=$p;$changed['phone']='+254700000002';throws(fn()=>enqueue('r',$r,$changed),'changed input rejected');
$p['lead_id']='2';$a=enqueue('r',$r,$p);$r['campaign_key']='campaign_B';saveEntity('r','route',$r);processLead($a['receipt'],$mock);check(str_contains($calls[2][0],'key=campaign_A'),'immutable snapshot');
$p['lead_id']='3';$a=enqueue('r',$r,$p);$timeout=function($url,$body,$headers)use($mock){if(str_contains($url,'sendmelead'))return ['code'=>0,'error'=>'timeout','body'=>''];return $mock($url,$body,$headers);};processLead($a['receipt'],$timeout);$l=sql('SELECT * FROM leads WHERE id=?',[$a['receipt']])->fetch();check($l['state']==='partner_review','uncertain partner result stops');$before=count($calls);processLead($a['receipt'],$mock);check(count($calls)===$before,'no blind retry');
$p['lead_id']='4';$a=enqueue('r',$r,$p);$bad=function($url,$body,$headers)use($mock){return str_contains($url,'sendmelead')?['code'=>200,'error'=>'','body'=>'{"result":"error","localClickId":"x"}']:$mock($url,$body,$headers);};processLead($a['receipt'],$bad);check(sql('SELECT state FROM leads WHERE id=?',[$a['receipt']])->fetchColumn()==='partner_review','HTTP 200 is not acceptance');
$p['lead_id']='5';$a=enqueue('r',$r,$p);$callsBefore=count($calls);processLead($a['receipt'],fn()=>['code'=>200,'error'=>'','body'=>'{"uclick":"not-a-click-id"}']);check(sql('SELECT state FROM leads WHERE id=?',[$a['receipt']])->fetchColumn()==='binom_review','uclick not used as conversion id');check(count($calls)===$callsBefore,'partner not called after invalid click');
$p['lead_id']='6';$a=enqueue('r',$r,$p);stage($a['receipt'],'partner_pending');worker(true);check(sql('SELECT state FROM leads WHERE id=?',[$a['receipt']])->fetchColumn()==='partner_review','crash recovery holds uncertain request');
$r['active']=false;$p['lead_id']='7';throws(fn()=>enqueue('r',$r,$p),'paused route rejects new leads');
$p['lead_id']=7686145442513699090;throws(fn()=>normalize($p),'numeric lead ID rejected');
throws(fn()=>publicUrl('http://tracker.example.com'),'plaintext rejected');throws(fn()=>publicUrl('https://user:password@tracker.example.com'),'URL credentials rejected');
check(!str_contains(file_get_contents($dir.'/bridge.sqlite'),'PARTNER_SECRET'),'secrets encrypted at rest');
// Multiple API processes insert the same lead concurrently.
$r['active']=true;$p['lead_id']='8';$kids=[];
for($i=0;$i<4;$i++)$kids[]=proc_open([PHP_BINARY,__FILE__,'child',$dir],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>STDERR],$pipes);
foreach($kids as $kid)check(proc_close($kid)===0,'concurrent insert exits');
$fresh=new PDO('sqlite:'.$dir.'/bridge.sqlite');check((int)$fresh->query("SELECT count(*) FROM leads WHERE external_id='8'")->fetchColumn()===1,'concurrent dedupe');
$t=entity('t','tracker');$t['version']='v2';$t['click_url']='https://tracker.example.com/click';
$v2=trackerClick($t,$r,$p,'https://bridge.example.com',function($url,$body,$headers){check($body===null,'v2 uses GET');check(str_contains($url,'lpbcid=1'),'v2 parameter');return ['code'=>200,'error'=>'','body'=>'{"click_info":{"id":"v2click"},"uclick_value":"wrong"}'];});check($v2['click_id']==='v2click','v2 extracts click_info.id');
check(sql('PRAGMA integrity_check')->fetchColumn()==='ok','database integrity');
echo "ALL TESTS PASSED\n";
check(extractClickId(['clickid'=>'nativeV1click123','uclick'=>'shortUclick','offer'=>['url'=>'https://offer.example/?clickid=nativeV1click123']],'v1')==='nativeV1click123','v1 native clickid and standard offer parameter');
check(extractClickId(['clickid'=>'native-id'],'v1')==='native-id','v1 native clickid without service offer URL');
check(extractClickId(['clickid'=>'native-id','offer'=>['url'=>'https://offer.example/?binom_click_id=other']],'v1')==='','conflicting conversion IDs rejected');
check(extractClickId(['uclick'=>'short-id'],'v1')==='','uclick never used as conversion ID');
check(extractClickId(['clickid'=>12345],'v1')==='','numeric IDs not guessed');
