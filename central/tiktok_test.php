<?php
declare(strict_types=1);
define('BRIDGE_TEST',true);
require __DIR__.'/action.php';

function check(bool $ok,string $label): void {
    if(!$ok)throw new RuntimeException('FAIL '.$label);
    echo "PASS $label\n";
}
function rejects(callable $call,string $label): void {
    try{$call();}catch(InvalidArgumentException $e){check(true,$label);return;}
    check(false,$label);
}
function reply(array $data): array {
    return ['code'=>200,'error'=>'','body'=>json_encode(['code'=>0,'data'=>$data],JSON_THROW_ON_ERROR)];
}
$tmp=sys_get_temp_dir().'/bridge-tiktok-'.bin2hex(random_bytes(6));
mkdir($tmp,0700);
putenv('BRIDGE_DATA='.$tmp);
file_put_contents($tmp.'/master.key',random_bytes(32));
register_shutdown_function(static function()use($tmp){
    foreach(glob($tmp.'/*') as $file)unlink($file);
    rmdir($tmp);
});
setting('accounts_migrated','1');
setting('base_url','https://bridge.example.com');
foreach(['a','b'] as $owner){
    sql('INSERT INTO users VALUES(?,?,?,?,?,?,?)',[$owner,'buyer-'.$owner,$owner,'stub','buyer',1,'epoch']);
}
$config=['app_id'=>'1234','secret'=>'TEST_APP_SECRET','revision'=>'rev'];
setting('tiktok_config',seal($config));
$_SESSION=['uid'=>'a','epoch'=>'epoch','last'=>time(),'csrf'=>'csrf-test'];
$_GET=['state'=>'correct','auth_code'=>'provider-code','id'=>'999'];
$attempt=['state'=>'correct','owner'=>'a','revision'=>'rev','expires'=>time()+600];
$_SESSION['tiktok_attempt']=$attempt;
$a='9223372036854775808';
$b='9223372036854775809';
$c='9223372036854775810';

$exchange=static function(array $ids,string $token): callable {
    return static function($url,$body,$headers)use($ids,$token){
        check($url===TIKTOK_API.'/v2.0/oauth2/access_token/','token endpoint');
        check($headers===['Content-Type: application/json'],'JSON token request');
        check(json_decode($body,true)===['app_id'=>'1234','secret'=>'TEST_APP_SECRET','auth_code'=>'provider-code','is_long_term'=>true,'return_advertiser_ids'=>true],'token request uses the verified auth_code and requests all accounts');
        return reply(['access_token'=>$token,'advertiser_ids'=>$ids]);
    };
};

$_GET['state']='wrong';
rejects(fn()=>tiktokFinish($exchange([$a],'token-one')),'wrong state rejected before exchange');
$_GET['state']='correct';
tiktokFinish($exchange([$a,$b,$a],'token-one'));
check(tiktokGrants('a',$config)===[['ids'=>[$a,$b],'token'=>'token-one']],'imports and deduplicates string IDs in one authorization');
rejects(fn()=>tiktokFinish($exchange([$a],'token-replay')),'authorization state cannot be reused');
$_SESSION['tiktok_attempt']=$attempt;
tiktokFinish($exchange([$b,$c],'token-two'));
$grants=tiktokGrants('a',$config);
check($grants===[['ids'=>[$a],'token'=>'token-one'],['ids'=>[$b,$c],'token'=>'token-two']],'adds accounts and updates overlapping grants');
check(tiktokGrants('b',$config)===[],'a separate buyer receives no grants');
check(tiktokGrants('a',['app_id'=>'5678'])===[],'other apps cannot reuse grants');
check(!str_contains(sql('SELECT data FROM tiktok_grants WHERE owner=?',['a'])->fetchColumn(),'token-two'),'stored grants are encrypted');

$fetch=static function($url,$body,$headers)use($b){
    parse_str(parse_url($url,PHP_URL_QUERY),$query);
    check($query===['advertiser_id'=>$b,'business_type'=>'LEAD_GEN','status'=>'PUBLISHED','page'=>'2','page_size'=>'50'],'form list uses a string account ID and pagination');
    check($body===null&&$headers===['Access-Token: token-two'],'correct owner grant is used for form list');
    return reply(['list'=>[['page_id'=>'9223372036854775999','title'=>'Test form']],'page_info'=>['total_page'=>3]]);
};
check(tiktokForms($grants,$b,2,$fetch)['list'][0]['page_id']==='9223372036854775999','large form ID survives intact');
rejects(fn()=>tiktokForms(tiktokGrants('b',$config),$b,2,$fetch),'foreign ID rejected before any request');
rejects(fn()=>tiktokForms($grants,$b,2,fn()=>['code'=>200,'error'=>'','body'=>'{}']),'HTTP 200 without a success envelope is rejected');
rejects(fn()=>tiktokForms($grants,$b,2,fn()=>['code'=>200,'error'=>'transport','body'=>reply([])['body']]),'transport failure is rejected');

$_SESSION['tiktok_attempt']=$attempt;
rejects(fn()=>tiktokFinish($exchange([],'unused')),'empty grant is rejected');
check(tiktokGrants('a',$config)===$grants,'failed reauthorization preserves previous grants');
check(tiktokCallbackUrl()==='https://bridge.example.com/?tiktok=callback','callback uses configured bridge origin');
