<?php
define('BRIDGE_TEST',true);require __DIR__.'/action.php';
$dir=sys_get_temp_dir().'/bridge-sso-'.bin2hex(random_bytes(8));mkdir($dir,0700);putenv('BRIDGE_DATA='.$dir);file_put_contents($dir.'/master.key',random_bytes(32));
putenv('BRIDGE_SSO_ENCRYPTION_KEY='.base64_encode(random_bytes(32)));putenv('BRIDGE_SSO_CLIENT_SECRET='.str_repeat('x',40));
setting('sso_client_id','lead-bridge');setting('sso_gate','1');setting('base_url','https://bridge.example.test');opsSchema();
function ok(bool $v,string $label): void {if(!$v)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function denied(callable $fn,string $label,string $class=Throwable::class): void {try{$fn();}catch(Throwable $e){ok($e instanceof $class,$label.' ('.get_class($e).')');return;}throw new RuntimeException('FAIL accepted '.$label);}
function b64(string $v): string {return rtrim(strtr(base64_encode($v),'+/','-_'),'=');}
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);$rsa=openssl_pkey_get_details($key)['rsa'];
$claims=['iss'=>OPSVAULT_ISSUER,'sub'=>'11111111-1111-4111-8111-111111111111','aud'=>'lead-bridge','nonce'=>'nonce','sid'=>'family','iat'=>time(),'exp'=>time()+300,'preferred_username'=>'mock-user'];
$jwt=function(array $c,string $alg='RS256')use($key){$s=b64(json_encode(['alg'=>$alg,'kid'=>'test'])).'.'.b64(json_encode($c));openssl_sign($s,$sig,$key,OPENSSL_ALGO_SHA256);return $s.'.'.b64($sig);};
$token=$jwt($claims);$role='user';$mode='normal';$calls=[];$credentials=[];
$GLOBALS['ops_http']=function($url,$body,$headers)use(&$token,$rsa,&$role,&$mode,&$calls,&$credentials){
    $calls[]=$url;$data=$body?json_decode($body,true):[];
    if(str_contains($url,'openid-configuration'))return ['code'=>200,'body'=>json_encode(['issuer'=>OPSVAULT_ISSUER,'authorization_endpoint'=>OPSVAULT_ISSUER.'/authorize/','token_endpoint'=>OPSVAULT_ISSUER.'/token/','jwks_uri'=>OPSVAULT_ISSUER.'/jwks/','code_challenge_methods_supported'=>['S256'],'token_endpoint_auth_methods_supported'=>['client_secret_basic']])];
    if(str_contains($url,'/jwks/'))$d=['keys'=>[['kty'=>'RSA','kid'=>'test','alg'=>'RS256','use'=>'sig','n'=>b64($rsa['n']),'e'=>b64($rsa['e'])]]];
    elseif(str_contains($url,'/token/')){parse_str($body,$form);ok(($form['code_verifier']??'')==='verifier','PKCE verifier sent');$d=['id_token'=>$token,'access_token'=>'access-token'];}
    elseif(str_contains($url,'/credentials/read/'))$d=$credentials?['found'=>true]+$credentials:['found'=>false];
    elseif(str_contains($url,'/credentials/')){
        if($mode==='publish-fail')return ['code'=>409,'body'=>'{}'];
        if($mode==='revoke-after-publish')$mode='deny';
        if($mode==='promote-after-publish')$role='admin';
        $credentials=['local_user_id'=>$data['local_user_id'],'username'=>$data['username'],'password'=>$data['password']];$d=['saved'=>$mode!=='saved-false','credential_id'=>1,'credential_url'=>'/vault/accounts/1/'];
    }elseif(str_contains($url,'/session/'))$d=['issuer'=>OPSVAULT_ISSUER,'subject'=>'11111111-1111-4111-8111-111111111111','sid'=>$mode==='sid'?'wrong':'family','role'=>$role,'lease_token'=>'lease-token','expires_at'=>time()+300.5];
    elseif(str_contains($url,'/jobs/check/'))$d=['allowed'=>$mode!=='no-import','valid_for'=>60];
    else {
        if($mode==='deny')return ['code'=>403,'body'=>'{}'];if($mode==='offline')throw new OpsUnavailable('offline');
        $d=['role'=>$mode==='unknown-role'?'owner':$role,'valid_for'=>$mode==='ttl'?61:60];
    }
    return ['code'=>200,'body'=>json_encode($d)];
};
function clientComplete(): object {
    $c=opsClient();$c->attempt+=['openid_connect_state'=>'state','openid_connect_nonce'=>'nonce','openid_connect_code_verifier'=>'verifier'];$_REQUEST=['code'=>'code','state'=>'state'];$c->authenticate();return $c;
}
$client=clientComplete();$result=opsResult($client);ok($result['subject']===$claims['sub'],'real RS256 library verifies identity and signed sid');
$count=count($calls);opsClient($client->attempt['bridge_discovery']);ok(count($calls)===$count,'callback reuses validated discovery without another network request');
$badDiscovery=$client->attempt['bridge_discovery'];$badDiscovery['token_endpoint']='https://evil.test/token';denied(fn()=>opsClient($badDiscovery),'cached discovery still pins endpoint origin',OpsUnavailable::class);
ok(!str_contains($result['lease'],'lease-token'),'lease encrypted under separate key');
foreach(['iss'=>'https://evil.test/sso','aud'=>'other','nonce'=>'bad','exp'=>time()-1,'iat'=>time()+600,'azp'=>'other'] as $field=>$value){$token=$jwt(array_merge($claims,[$field=>$value]));denied('clientComplete','reject '.$field);}
foreach(['nonce','exp','iat','sid'] as $field){$bad=$claims;unset($bad[$field]);$token=$jwt($bad);denied('clientComplete','mandatory '.$field);}
$token=$jwt(array_merge($claims,['aud'=>['lead-bridge','other']]));denied('clientComplete','multiple audiences require azp');
$token=$jwt($claims,'HS256');denied('clientComplete','algorithm allowlist');
$token=$jwt($claims);$token=substr($token,0,-10).'AAAAAAAAAA';denied('clientComplete','bad signature');$token=$jwt($claims);
$mode='sid';denied(fn()=>opsResult(clientComplete()),'policy sid mismatch');$mode='normal';
foreach(['https://evil.test/','//evil.test','/\\evil.test',"/\nfoo",'/%5cevil.test'] as $path)denied(fn()=>opsSafePath($path),'unsafe return path');
foreach(['http://portalwiki.world/sso','https://evil.test/jwks','https://user@portalwiki.world/sso','https://portalwiki.world:444/sso'] as $url)denied(fn()=>opsOrigin($url),'pinned provider origin');
ok(opsSafePath('/?page=route')==='/?page=route','local return path');
$user=['id'=>'buyer_old','login'=>'buyer-old','name'=>'Buyer','password'=>password_hash('separate-password-123',PASSWORD_DEFAULT),'role'=>'admin','active'=>1,'epoch'=>'epoch'];
sql('INSERT INTO users VALUES(?,?,?,?,?,?,?)',array_values($user));sql('INSERT INTO sso_links VALUES(?,?,?)',[$user['id'],OPSVAULT_ISSUER,$claims['sub']]);
denied(fn()=>opsRequireRecovery($user['id']),'administrator link requires a separate recovery account',OpsDenied::class);
sql('INSERT INTO users VALUES(?,?,?,?,?,?,?)',['admin','recovery','Recovery',password_hash('recovery-test-password',PASSWORD_DEFAULT),'admin',1,'epoch_admin']);
opsRequireRecovery($user['id']);ok(true,'active unlinked emergency account satisfies recovery guard');
denied(fn()=>opsRequireRecovery('admin'),'last emergency administrator cannot link',OpsDenied::class);
sql('DELETE FROM users WHERE id="admin"');
$_SESSION=['uid'=>$user['id'],'epoch'=>'epoch','last'=>time(),'ops'=>opsPasswordSession($user)];
ok(currentUser()['role']==='buyer','local-linked role comes from grant, not stored admin');
$count=count($calls);currentUser();ok(count($calls)===$count,'read cache bounded to same identity');
$role='admin';$_SERVER['REQUEST_METHOD']='POST';unset($GLOBALS['ops_request_id']);ok(currentUser()['role']==='admin','fresh write observes central promotion');
$role='user';denied('opsRecheck','in-flight admin demotion aborts commit',OpsDenied::class);ok(currentUser()['role']==='buyer','fresh decision demotes central admin');
$mode='offline';$_SERVER['REQUEST_METHOD']='GET';$_SESSION['ops']['cache']['until']=0;denied('currentUser','outage after expiry fails closed',OpsUnavailable::class);
ok($_SESSION['ops']['cache']['until']===0,'failed check never extends cache');
$mode='deny';denied('currentUser','revoked grant closes local-linked session',OpsDenied::class);ok(!isset($_SESSION['uid']),'session invalidated on denial');
$mode='normal';$_SESSION=['uid'=>$user['id'],'epoch'=>'epoch','last'=>time(),'ops'=>opsPasswordSession($user)];setting('sso_gate','0');denied('currentUser','gate off denies linked password',OpsDenied::class);bridgeBackground($user['id']);ok(true,'gate off does not deny local background');setting('sso_gate','1');
$expired=opsOpen($result['lease']);$expired['expires']=time()-1;
$_SESSION=['uid'=>$user['id'],'epoch'=>'epoch','last'=>time(),'ops'=>['type'=>'oidc','user_id'=>$user['id'],'issuer'=>OPSVAULT_ISSUER,'subject'=>$result['subject'],'lease'=>opsSeal($expired),'cache'=>['until'=>time()+60,'role'=>'admin']]];
$count=count($calls);denied('currentUser','expired lease requests a new login even with cached grant',OpsLoginRequired::class);ok(!isset($_SESSION['uid'])&&count($calls)===$count,'expired session closes before network access');
$expired['subject']='other';denied(fn()=>opsPolicy(['subject'=>$result['subject']],'oidc',['lease'=>opsSeal($expired)]),'identity mismatch stays denied',OpsDenied::class);
$mode='ttl';denied(fn()=>opsPasswordSession($user),'invalid policy ttl',OpsUnavailable::class);$mode='unknown-role';denied(fn()=>opsPasswordSession($user),'unknown role fails closed',OpsUnavailable::class);$mode='normal';
setting('accounts_migrated','1');
$count=count($calls);foreach(['no-import','deny','offline'] as $mode)bridgeBackground($user['id']);ok(count($calls)===$count,'background never calls OpsVault even without grant or during outage');
denied(fn()=>bridgeBackground('missing'),'missing local owner denied',BackgroundDenied::class);$mode='normal';
saveEntity('sso_t','tracker',['name'=>'Mock','version'=>'v2','click_url'=>'https://tracker.example.test/click','api_key'=>'test']);
saveEntity('sso_p','partner',['name'=>'Mock','type'=>'lemonad','token'=>'test']);
$r=['name'=>'Mock','buyer'=>$user['login'],'dedupe_buyer'=>'legacy_namespace','tracker'=>'sso_t','partner'=>'sso_p','campaign_key'=>'test','offer_id'=>'1','active'=>true,'secret'=>str_repeat('a',64),'tokens'=>defaultTokens()];saveEntity('sso_r','route',$r);
sql('UPDATE entities SET owner=? WHERE id IN ("sso_t","sso_p","sso_r")',[$user['id']]);
$receipt=enqueue('sso_r',$r,['lead_id'=>'900000000000000001','phone'=>'+254700000001'])['receipt'];$sent=0;
$mockSend=function($url,$body,$headers)use(&$sent){$sent++;return ['code'=>200,'error'=>'','body'=>str_contains($url,'tracker')?'{"click_info":{"id":"test_click"}}':'{"result":"ok","localClickId":"123"}'];};
sql('UPDATE users SET active=0 WHERE id=?',[$user['id']]);processLead($receipt,$mockSend);ok($sent===0&&sql('SELECT state FROM leads WHERE id=?',[$receipt])->fetchColumn()==='queued','disabled local account preserves queue without external requests');
sql('UPDATE users SET active=1 WHERE id=?',[$user['id']]);$mode='no-import';$count=count($calls);processLead($receipt,$mockSend);ok($sent===2&&sql('SELECT state FROM leads WHERE id=?',[$receipt])->fetchColumn()==='sent'&&count($calls)===$count,'missing import grant does not block confirmed click and partner send');
processLead($receipt,$mockSend);ok($sent===2,'SENT never automatically replayed');
$other=enqueue('sso_r',$r,['lead_id'=>'900000000000000002','phone'=>'+254700000002'])['receipt'];
$disableDuringClick=function($url,$body,$headers)use(&$sent,$user){$sent++;sql('UPDATE users SET active=0 WHERE id=?',[$user['id']]);return ['code'=>200,'error'=>'','body'=>'{"click_info":{"id":"test_click_2"}}'];};
processLead($other,$disableDuringClick);ok($sent===3&&sql('SELECT state FROM leads WHERE id=?',[$other])->fetchColumn()==='binom_review','local disable during click stops partner and requires reconciliation');
sql('UPDATE users SET active=1 WHERE id=?',[$user['id']]);processLead($other,$mockSend);ok($sent===3,'uncertain external result never automatically replayed');
sql('DELETE FROM leads WHERE id=?',[$other]);$mode='normal';
sql('DELETE FROM leads WHERE id=?',[$receipt]);
denied(fn()=>sql('INSERT INTO sso_links VALUES(?,?,?)',['buyer_old',OPSVAULT_ISSUER,'other']),'one identity per local user');
sql('DELETE FROM sso_links');
setting('sso_jit','0');denied(fn()=>opsProvision($result),'JIT closed for migration',OpsDenied::class);
setting('sso_jit','1');$mode='publish-fail';denied(fn()=>opsProvision($result),'publication failure before local commit',OpsUnavailable::class);ok((int)sql('SELECT count(*) FROM users')->fetchColumn()===1,'failed publication creates no user');
sql('INSERT INTO sso_reservations VALUES(?,?,?)',[$user['id'],OPSVAULT_ISSUER,$result['subject']]);
denied(fn()=>opsProvision($result),'JIT never duplicates an explicitly reserved existing account',OpsDenied::class);
opsValidateReservation($user['id'],$result['subject']);
denied(fn()=>opsValidateReservation('other_user',$result['subject']),'reservation protects local owner',OpsDenied::class);
denied(fn()=>opsValidateReservation($user['id'],'22222222-2222-4222-8222-222222222222'),'reservation requires expected central subject',OpsDenied::class);
sql('DELETE FROM sso_reservations');
$count=count($calls);$badResult=$result;$lease=opsOpen($result['lease']);$lease['subject']='other';$badResult['lease']=opsSeal($lease);
denied(fn()=>opsProvision($badResult),'unrelated lease denied before vault access',OpsDenied::class);ok(count($calls)===$count,'unrelated lease sends no credentials request');
$lease['subject']=$result['subject'];$lease['expires']=time()-1;$badResult['lease']=opsSeal($lease);
denied(fn()=>opsProvision($badResult),'expired lease denied before vault access',OpsLoginRequired::class);ok(count($calls)===$count,'expired lease sends no credentials request');
foreach(['deny','offline','unknown-role'] as $mode){$start=count($calls);denied(fn()=>opsProvision($result),'invalid policy prevents JIT publication');ok(count($calls)===$start+1,'policy failure never reads or publishes credentials');}
$mode='revoke-after-publish';denied(fn()=>opsProvision($result),'revocation during publication rolls local creation back',OpsDenied::class);
ok((int)sql('SELECT count(*) FROM users')->fetchColumn()===1,'revocation leaves no local account');
$mode='normal';$canonical=$credentials;$resumed=opsProvision($result);ok($resumed['id']===$canonical['local_user_id']&&password_verify($canonical['password'],$resumed['password']),'retry reads published canonical credentials after policy rollback');
sql('DELETE FROM sso_links WHERE user_id=?',[$resumed['id']]);sql('DELETE FROM users WHERE id=?',[$resumed['id']]);$credentials=[];
$mode='saved-false';denied(fn()=>opsProvision($result),'saved false rejected',OpsUnavailable::class);$credentials=[];$mode='promote-after-publish';
$new=opsProvision(array_merge($result,['name'=>$user['login']]));$stored=$credentials;ok(password_verify($stored['password'],$new['password']),'JIT saves distinct password hash and vault card');
ok($new['role']==='admin'&&sql('SELECT role FROM users WHERE id=?',[$new['id']])->fetchColumn()==='admin','JIT stores fresh service grant role after publication');
ok($new['id']!==$user['id']&&$new['login']!==$user['login'],'matching display nickname never claims an existing account');
$role='user';$mode='normal';$again=opsProvision(array_merge($result,['name'=>'renamed']));ok($again['id']===$new['id'],'nickname change reuses issuer subject binding');
sql('UPDATE users SET active=0 WHERE id=?',[$new['id']]);$inactive=opsProvision($result);ok(!$inactive['active'],'repeat OIDC never reactivates a disabled local account');sql('UPDATE users SET active=1 WHERE id=?',[$new['id']]);
sql('DELETE FROM sso_links WHERE user_id=?',[$new['id']]);sql('DELETE FROM users WHERE id=?',[$new['id']]);$recovered=opsProvision($result);ok($recovered['id']===$new['id']&&password_verify($stored['password'],$recovered['password']),'publish plus rollback recovers canonical ID and password');ok($recovered['role']==='buyer','recovery uses current user grant without preserving former admin role');
sql('DELETE FROM sso_links WHERE user_id=?',[$new['id']]);denied(fn()=>opsProvision($result),'canonical ID collision never claims another account',OpsDenied::class);
$_COOKIE=['bridge_oidc'=>str_repeat('a',64)];$browser=opsBrowser();sql('INSERT INTO sso_attempts VALUES(?,?,?)',[$browser,opsSeal(['kind'=>'attempt']),time()+100]);ok(opsTakeAttempt()['kind']==='attempt','browser bound attempt consumed');denied('opsTakeAttempt','callback replay denied',OpsDenied::class);
sql('INSERT INTO sso_attempts VALUES(?,?,?)',[$browser,opsSeal(['kind'=>'attempt']),time()-1]);denied('opsTakeAttempt','expired callback denied',OpsDenied::class);
ok(sql('PRAGMA integrity_check')->fetchColumn()==='ok','SSO DB integrity');echo "ALL OPSVAULT TESTS PASSED\n";
