<?php
// OpsVault 0.1.2 policy adapter. OIDC/PKCE/signatures use the pinned Jumbojett library.
const OPSVAULT_ISSUER = 'https://portalwiki.world/sso';
class OpsDenied extends RuntimeException {}
class OpsUnavailable extends RuntimeException {}
function opsSchema(): void {
    static $done=false;if($done)return;
    db()->exec('CREATE TABLE IF NOT EXISTS sso_links (user_id TEXT NOT NULL REFERENCES users(id),issuer TEXT NOT NULL,subject TEXT NOT NULL,PRIMARY KEY(issuer,subject),UNIQUE(user_id,issuer));
      CREATE TABLE IF NOT EXISTS sso_reservations (user_id TEXT NOT NULL REFERENCES users(id),issuer TEXT NOT NULL,subject TEXT NOT NULL,PRIMARY KEY(issuer,subject),UNIQUE(user_id,issuer));
      CREATE TABLE IF NOT EXISTS sso_attempts (browser TEXT PRIMARY KEY,data TEXT NOT NULL,expires INTEGER NOT NULL)');sql('DELETE FROM sso_attempts WHERE expires<=?',[time()]);$done=true;
}
function opsLink(string $id): ?array {opsSchema();return sql('SELECT * FROM sso_links WHERE user_id=? AND issuer=?',[$id,OPSVAULT_ISSUER])->fetch()?:null;}
function opsReserved(string $subject): ?array {opsSchema();return sql('SELECT * FROM sso_reservations WHERE issuer=? AND subject=?',[OPSVAULT_ISSUER,$subject])->fetch()?:null;}
function opsValidateReservation(string $user,string $subject): void {
    $r=opsReserved($subject);$own=sql('SELECT subject FROM sso_reservations WHERE issuer=? AND user_id=?',[OPSVAULT_ISSUER,$user])->fetchColumn();
    if(($r&&$r['user_id']!==$user)||($own!==false&&$own!==$subject))throw new OpsDenied('Идентичность OpsVault зарезервирована для другого локального аккаунта');
}
function opsGate(): bool {return setting('sso_gate')==='1';}
function opsReady(): bool {return setting('sso_client_id')!==''&&strlen(getenv('BRIDGE_SSO_CLIENT_SECRET')?:'')>=32&&strlen(opsKey())===32;}
function opsKey(): string {$key=base64_decode(strtr(getenv('BRIDGE_SSO_ENCRYPTION_KEY')?:'','-_','+/'),true);return $key===false?'':$key;}
function opsSeal(array $data): string {
    $key=opsKey();if(strlen($key)!==32)throw new OpsUnavailable('Не установлен серверный ключ OpsVault');
    $nonce=random_bytes(24);return base64_encode($nonce.sodium_crypto_secretbox(json_encode($data,JSON_THROW_ON_ERROR),$nonce,$key));
}
function opsOpen(string $value): array {
    $raw=base64_decode($value,true);$key=opsKey();
    if($raw===false||strlen($raw)<40||strlen($key)!==32)throw new OpsUnavailable('Данные OpsVault недоступны');
    $plain=sodium_crypto_secretbox_open(substr($raw,24),substr($raw,0,24),$key);
    if($plain===false)throw new OpsUnavailable('Данные OpsVault недоступны');return json_decode($plain,true,32,JSON_THROW_ON_ERROR);
}
function opsIds(string $name): array {return array_values(array_filter(array_map('trim',explode(',',setting($name)))));}
function opsEmergency(string $id): bool {
    // Unlinked, server-selected recovery accounts only; a linked account never bypasses policy.
    return !opsLink($id)&&in_array($id,array_filter(array_map('trim',explode(',',getenv('BRIDGE_SSO_EMERGENCY_IDS')?:'admin'))),true);
}
function opsRequireRecovery(string $id): void {
    if(sql('SELECT role FROM users WHERE id=?',[$id])->fetchColumn()!=='admin')return;
    foreach(sql('SELECT id FROM users WHERE active=1 AND role="admin" AND id<>?',[$id]) as $candidate)if(opsEmergency($candidate['id']))return;
    throw new OpsDenied('Сначала создайте отдельного аварийного администратора и настройте его серверный ID');
}
function opsSafePath(string $path): string {
    if($path===''||$path[0]!=='/'||str_starts_with($path,'//')||preg_match('/[\\\\\x00-\x20\x7f]/',$path)||str_contains(rawurldecode($path),'\\')||preg_match('/[\x00-\x20\x7f]/',rawurldecode($path))||parse_url($path,PHP_URL_HOST)!==null)throw new InvalidArgumentException('Небезопасный адрес возврата');return $path;
}
function opsCallbackUrl(): string {return rtrim(setting('base_url'),'/').'/?opsvault=callback';}
function opsOrigin(string $url): void {
    $p=parse_url($url);if(!$p||($p['scheme']??'')!=='https'||($p['host']??'')!=='portalwiki.world'||isset($p['user'])||isset($p['pass'])||(isset($p['port'])&&$p['port']!==443)||isset($p['fragment']))throw new OpsUnavailable('Некорректный адрес провайдера');
}
function opsHttp(string $url,?string $body,array $headers=[]): array {
    opsOrigin($url);
    // Injection is possible only inside the isolated test harness, never through env or HTTP.
    if(defined('BRIDGE_TEST')&&isset($GLOBALS['ops_http']))return ($GLOBALS['ops_http'])($url,$body,$headers);
    $ch=curl_init($url);$response='';
    curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>$headers,CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$response){if(strlen($response)+strlen($chunk)>262144)return 0;$response.=$chunk;return strlen($chunk);}]);
    if($body!==null)curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body]);
    $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($ok===false)throw new OpsUnavailable('OpsVault временно недоступен');return ['code'=>$code,'body'=>$response];
}
function opsApi(string $path,array $data): array {
    if(!opsGate())throw new OpsDenied('Вход OpsVault выключен');if(!opsReady())throw new OpsUnavailable('OpsVault ещё не настроен');
    $body=json_encode($data,JSON_THROW_ON_ERROR);if(strlen($body)>4096)throw new OpsUnavailable('Превышен размер запроса OpsVault');
    $auth=base64_encode(setting('sso_client_id').':'.getenv('BRIDGE_SSO_CLIENT_SECRET'));
    $r=opsHttp(OPSVAULT_ISSUER.'/'.$path.'/',$body,['Content-Type: application/json','Authorization: Basic '.$auth]);
    if(in_array($r['code'],[401,403],true))throw new OpsDenied('Доступ OpsVault отозван или не выдан');
    if($r['code']!==200)throw new OpsUnavailable('OpsVault не подтвердил операцию');
    try{$d=json_decode($r['body'],true,32,JSON_THROW_ON_ERROR);}catch(Throwable $e){throw new OpsUnavailable('Некорректный ответ OpsVault');}
    if(!is_array($d)||array_is_list($d))throw new OpsUnavailable('Некорректный ответ OpsVault');return $d;
}
function opsRole(array $d): string {
    return match($d['role']??null){'admin'=>'admin','user'=>'buyer',default=>throw new OpsUnavailable('Неизвестная роль OpsVault')};
}
function opsPolicy(array $link,string $type,array $session=[],bool $fresh=false): array {
    if(!opsGate())throw new OpsDenied('Вход OpsVault выключен');
    $start=microtime(true);$cache=$session['cache']??[];
    if($type==='oidc'){
        $lease=opsOpen($session['lease']??'');
        if(($lease['subject']??'')!==$link['subject']||($lease['expires']??0)<=microtime(true))throw new OpsDenied('Сессия OpsVault истекла');
    }
    if(!$fresh&&($cache['until']??0)>$start&&isset($cache['role']))return $cache;
    if($type==='oidc'){
        $d=opsApi('access',['lease_token'=>$lease['token']]);
    }elseif($type==='local-linked')$d=opsApi('local-access',['subject'=>$link['subject'],'local_user_id'=>$link['user_id']]);
    else throw new OpsDenied('Войдите заново через OpsVault или отдельный пароль');
    $role=opsRole($d);$ttl=$d['valid_for']??null;
    if((!is_int($ttl)&&!is_float($ttl))||!is_finite((float)$ttl)||$ttl<=0||$ttl>60)throw new OpsUnavailable('Некорректный срок разрешения OpsVault');
    if($start+$ttl<=microtime(true))throw new OpsUnavailable('Проверка OpsVault истекла');
    return ['role'=>$role,'until'=>min($start+$ttl,$lease['expires']??PHP_INT_MAX)];
}
function opsAuthorize(array $user,bool $fresh=false): array {
    $link=opsLink($user['id']);
    if(!$link){
        if(opsGate()&&!opsEmergency($user['id'])){
            if(!in_array($user['id'],opsIds('sso_link_ids'),true))throw new OpsDenied('Аккаунт ещё не связан с OpsVault');
            $user['role']='buyer';$user['sso_migration']=true;
        }
        return $user;
    }
    $s=$_SESSION['ops']??[];
    if(($s['subject']??'')!==$link['subject']||($s['issuer']??'')!==OPSVAULT_ISSUER||($s['user_id']??'')!==$user['id'])throw new OpsDenied('Войдите заново');
    // One fresh decision per short request; long operations recheck immediately before commit.
    if($fresh&&($s['cache']['checked_request']??'')===($GLOBALS['ops_request_id']??=bin2hex(random_bytes(16))))$fresh=false;
    try{$cache=opsPolicy($link,$s['type']??'',$s,$fresh);}
    catch(OpsDenied $e){unset($_SESSION['uid'],$_SESSION['ops']);throw $e;}
    $cache['checked_request']=$GLOBALS['ops_request_id']??'';
    $_SESSION['ops']['cache']=$cache;$user['role']=$cache['role'];return $user;
}
function opsRecheck(): void {
    $u=currentLocalUser();if(!$u)return;$link=opsLink($u['id']);if(!$link)return;
    $s=$_SESSION['ops']??[];$before=$s['cache']['role']??null;
    $cache=opsPolicy($link,$s['type']??'',$s,true);$_SESSION['ops']['cache']=$cache;
    if($before==='admin'&&$cache['role']!=='admin')throw new OpsDenied('Роль изменилась. Повторите действие с актуальными правами');
}
function opsPasswordSession(array $user): array {
    $link=opsLink($user['id']);if(!$link){opsAuthorize($user,true);return [];}
    $s=['type'=>'local-linked','user_id'=>$user['id'],'issuer'=>OPSVAULT_ISSUER,'subject'=>$link['subject']];
    $s['cache']=opsPolicy($link,'local-linked',[],true);return $s;
}
function opsBackground(string $owner): void {
    $link=opsLink($owner);if(!$link)return;
    if(!sql('SELECT 1 FROM users WHERE id=? AND active=1',[$owner])->fetchColumn())throw new OpsDenied('Аккаунт отключён');
    $d=opsApi('jobs/check',['subject'=>$link['subject']]);
    if(($d['allowed']??null)!==true||!is_int($d['valid_for']??null)||$d['valid_for']<1||$d['valid_for']>60)throw new OpsDenied('Нет фонового разрешения OpsVault');
}
function opsLoadLibrary(): void {
    static $loaded=false;if($loaded)return;$loaded=true;
    $encoded='__OIDC_LIBRARY_BASE64__';
    if(str_starts_with($encoded,'__')){
        $map=json_decode(file_get_contents(__DIR__.'/oidc-classmap.json'),true,512,JSON_THROW_ON_ERROR);
        spl_autoload_register(static function($name)use($map){if(isset($map[$name]))require_once __DIR__.'/oidc-vendor/'.$map[$name];});
        require_once __DIR__.'/oidc-vendor/phpseclib/phpseclib/phpseclib/bootstrap.php';
    }else{
        // PHP's phar:// reads the embedded ZIP without extraction and preserves library __DIR__.
        $bundle=json_decode(gzdecode(base64_decode($encoded,true)),true,512,JSON_THROW_ON_ERROR);
        $dir=home().'/oidc-library';if(is_link($dir))throw new OpsUnavailable('Небезопасный каталог библиотек');
        if(!is_dir($dir)&&!mkdir($dir,0700)&&!is_dir($dir))throw new OpsUnavailable('Не удалось сохранить библиотеку');
        $path=$dir.'/'.$bundle['sha256'].'.zip';
        if(!file_exists($path)){
            $tmp=$dir.'/'.bin2hex(random_bytes(16)).'.tmp';$f=fopen($tmp,'x');if(!$f)throw new OpsUnavailable('Не удалось сохранить библиотеку');chmod($tmp,0600);
            try{$bytes=base64_decode($bundle['zip'],true);if(fwrite($f,$bytes)!==strlen($bytes))throw new OpsUnavailable('Не удалось сохранить библиотеку');fclose($f);$f=null;if(!rename($tmp,$path))throw new OpsUnavailable('Не удалось сохранить библиотеку');}
            finally{if(is_resource($f))fclose($f);if(file_exists($tmp))unlink($tmp);}
        }
        if(is_link($path)||!hash_equals($bundle['sha256'],hash_file('sha256',$path)))throw new OpsUnavailable('Контрольная сумма библиотеки не совпадает');
        $base='phar://'.$path.'/';
        spl_autoload_register(static function($name)use($bundle,$base){if(isset($bundle['map'][$name]))require_once $base.$bundle['map'][$name];});
        require_once $base.'phpseclib/phpseclib/phpseclib/bootstrap.php';
    }
    class BridgeOIDC extends \Jumbojett\OpenIDConnectClient {
        public array $attempt=[];
        protected function getSessionKey(string $key){return $this->attempt[$key]??null;}
        protected function setSessionKey(string $key,$value){$this->attempt[$key]=$value;}
        protected function unsetSessionKey(string $key){unset($this->attempt[$key]);}
        protected function startSession(){}
        protected function commitSession(){}
        protected function fetchURL(string $url,string $post_body=null,array $headers=[]){
            $r=opsHttp($url,$post_body,$headers);$this->responseCode=$r['code'];
            if($r['code']!==200)throw new OpsUnavailable('OIDC не подтвердил запрос');return $r['body'];
        }
        public function redirect(string $url){throw new BridgeOIDCRedirect($url);}
        protected function verifyJWTClaims($claims,string $accessToken=null): bool {
            // Strict mandatory claims in addition to the library's signature/aud/at_hash checks.
            $id=setting('sso_client_id');$aud=$claims->aud??null;$now=time();
            if($aud!==$id&&(!is_array($aud)||!in_array($id,$aud,true)))return false;
            if(isset($claims->nbf)&&(!is_int($claims->nbf)||$claims->nbf>$now+30))return false;
            if(!is_string($claims->iss??null)||$claims->iss!==OPSVAULT_ISSUER||!is_string($claims->sub??null)||!preg_match('/^[a-f0-9-]{36}$/Di',$claims->sub)||!is_string($claims->sid??null)||$claims->sid===''||!is_string($claims->nonce??null)||!hash_equals((string)$this->getNonce(),$claims->nonce)||!is_int($claims->exp??null)||$claims->exp<=$now||!is_int($claims->iat??null)||$claims->iat>$now+30||$claims->iat>$claims->exp||(!is_string($aud)&&!is_array($aud))||(is_array($aud)&&count($aud)>1&&($claims->azp??null)!==$id)||(isset($claims->azp)&&$claims->azp!==$id))return false;
            return parent::verifyJWTClaims($claims,$accessToken);
        }
        public function verifyJWTSignature(string $jwt): bool {
            // Decode using the library; restrict algorithm before any verification or key fetch.
            $header=$this->decodeJWT($jwt);if(($header->alg??'')!=='RS256'||isset($header->enc))return false;
            return parent::verifyJWTSignature($jwt);
        }
        protected function handleJweResponse(string $jwe): string {throw new OpsDenied('Зашифрованные JWT не поддерживаются');}
    }
}
class BridgeOIDCRedirect extends RuntimeException {}
function opsClient(): object {
    if(!opsGate())throw new OpsDenied('Вход OpsVault выключен');if(!opsReady())throw new OpsUnavailable('OpsVault ещё не настроен');
    opsLoadLibrary();$c=new BridgeOIDC(OPSVAULT_ISSUER,setting('sso_client_id'),getenv('BRIDGE_SSO_CLIENT_SECRET'));
    $r=opsHttp(OPSVAULT_ISSUER.'/.well-known/openid-configuration',null);if($r['code']!==200)throw new OpsUnavailable('Discovery недоступен');
    $d=json_decode($r['body'],true,32,JSON_THROW_ON_ERROR);
    if(($d['issuer']??'')!==OPSVAULT_ISSUER||!in_array('S256',$d['code_challenge_methods_supported']??[],true))throw new OpsUnavailable('Некорректная конфигурация OIDC');
    foreach(['authorization_endpoint','token_endpoint','jwks_uri'] as $key){if(!is_string($d[$key]??null))throw new OpsUnavailable('Некорректная конфигурация OIDC');opsOrigin($d[$key]);}
    $c->providerConfigParam($d);$c->setRedirectURL(opsCallbackUrl());$c->setCodeChallengeMethod('S256');$c->setTokenEndpointAuthMethodsSupported(['client_secret_basic']);$c->addScope(['profile']);return $c;
}
function opsBrowserCookie(string $value,int $expires): void {setcookie('bridge_oidc',$value,['expires'=>$expires,'secure'=>true,'httponly'=>true,'samesite'=>'Lax','path'=>'/']);}
function opsBrowser(): string {$v=$_COOKIE['bridge_oidc']??'';if(!is_string($v)||!preg_match('/^[a-f0-9]{64}$/D',$v))throw new OpsDenied('Попытка входа истекла');return hash('sha256',$v);}
function opsBegin(?array $user=null,string $password=''): never {
    opsSchema();$client=opsClient();$attempt=['return'=>'/','kind'=>'attempt','expires'=>time()+300];
    if($user){
        if(opsLink($user['id'])||!in_array($user['id'],opsIds('sso_link_ids'),true))throw new OpsDenied('Аккаунт не включён в пилот связывания');
        opsRequireRecovery($user['id']);
        if(!password_verify($password,$user['password']))throw new InvalidArgumentException('Неверный текущий пароль');
        $attempt+=['uid'=>$user['id'],'panel_sid'=>session_id(),'epoch'=>$user['epoch'],'password_hash'=>$user['password'],'password'=>$password];
    }else $attempt['return']=opsSafePath((string)($_GET['next']??'/'));
    // Library uses REQUEST; do not let arbitrary query parameters switch this to callback.
    $request=$_REQUEST;$_REQUEST=[];
    try{$client->authenticate();throw new OpsUnavailable('OIDC не вернул перенаправление');}
    catch(BridgeOIDCRedirect $r){$url=$r->getMessage();}
    finally{$_REQUEST=$request;}
    $attempt['oidc']=$client->attempt;$cookie=bin2hex(random_bytes(32));
    sql('DELETE FROM sso_attempts WHERE expires<=?',[time()]);
    if(isset($_COOKIE['bridge_oidc'])&&is_string($_COOKIE['bridge_oidc']))sql('DELETE FROM sso_attempts WHERE browser=?',[hash('sha256',$_COOKIE['bridge_oidc'])]);
    sql('INSERT INTO sso_attempts VALUES(?,?,?)',[hash('sha256',$cookie),opsSeal($attempt),$attempt['expires']]);opsBrowserCookie($cookie,$attempt['expires']);redirect($url);
}
function opsTakeAttempt(): array {
    $browser=opsBrowser();db()->exec('BEGIN IMMEDIATE');
    try{$row=sql('SELECT * FROM sso_attempts WHERE browser=?',[$browser])->fetch();sql('DELETE FROM sso_attempts WHERE browser=?',[$browser]);db()->exec('COMMIT');}
    catch(Throwable $e){db()->exec('ROLLBACK');throw $e;}
    if(!$row||$row['expires']<=time())throw new OpsDenied('Попытка входа истекла');return opsOpen($row['data']);
}
function opsResult(object $client): array {
    $claims=$client->getVerifiedClaims();$d=opsApi('session',['access_token'=>$client->getAccessToken()]);
    if(($d['issuer']??'')!==OPSVAULT_ISSUER||($d['subject']??'')!==$claims->sub||($d['sid']??'')!==$claims->sid||!is_string($d['lease_token']??null)||strlen($d['lease_token'])<1||strlen($d['lease_token'])>1024||(!is_int($d['expires_at']??null)&&!is_float($d['expires_at']??null))||!is_finite((float)$d['expires_at'])||$d['expires_at']<=time())throw new OpsDenied('Сессия OpsVault не подтверждена');
    return ['subject'=>$claims->sub,'name'=>(string)($claims->preferred_username??$claims->name??$claims->sub),'role'=>opsRole($d),'lease'=>opsSeal(['token'=>$d['lease_token'],'subject'=>$claims->sub,'expires'=>$d['expires_at']])];
}
function opsLease(array $result): string {return opsOpen($result['lease'])['token'];}
function opsSaveCredentials(array $result,array $user,string $password): void {
    $d=opsApi('credentials',['lease_token'=>opsLease($result),'subject'=>$result['subject'],'local_user_id'=>$user['id'],'username'=>$user['login'],'password'=>$password]);
    if(($d['saved']??null)!==true||!is_int($d['credential_id']??null)||$d['credential_id']<1||!is_string($d['credential_url']??null)||!str_starts_with($d['credential_url'],'/vault/accounts/')||str_starts_with($d['credential_url'],'//'))throw new OpsUnavailable('OpsVault не сохранил реквизиты');
}
function opsNewSession(array $user,array $result): void {
    if(!$user['active'])throw new OpsDenied('Аккаунт отключён');
    $s=['type'=>'oidc','user_id'=>$user['id'],'issuer'=>OPSVAULT_ISSUER,'subject'=>$result['subject'],'lease'=>$result['lease']];
    $s['cache']=opsPolicy(['user_id'=>$user['id'],'subject'=>$result['subject']],'oidc',$s,true);
    session_regenerate_id(true);$_SESSION=['uid'=>$user['id'],'epoch'=>$user['epoch'],'last'=>time(),'csrf'=>bin2hex(random_bytes(24)),'ops'=>$s];refreshPanelSession();
}
function opsProvision(array $result): array {
    $row=sql('SELECT users.* FROM sso_links JOIN users ON users.id=sso_links.user_id WHERE issuer=? AND subject=?',[OPSVAULT_ISSUER,$result['subject']])->fetch();if($row)return $row;
    if(opsReserved($result['subject']))throw new OpsDenied('У вас уже есть аккаунт Lead Bridge. Войдите по его отдельному паролю и свяжите OpsVault в Настройках — данные сохранятся');
    if(setting('sso_jit')!=='1')throw new OpsDenied('Сначала свяжите существующий аккаунт в Настройках');
    $d=opsApi('credentials/read',['lease_token'=>opsLease($result),'subject'=>$result['subject']]);
    if(($d['found']??null)===true){
        foreach(['local_user_id','username','password'] as $field)if(!is_string($d[$field]??null)||$d[$field]==='')throw new OpsUnavailable('Некорректные сохранённые реквизиты');
        $uid=$d['local_user_id'];$login=$d['username'];$password=$d['password'];
    }elseif(($d['found']??null)===false){$uid='user_'.bin2hex(random_bytes(16));$login='ov_'.bin2hex(random_bytes(12));$password=bin2hex(random_bytes(24));}
    else throw new OpsUnavailable('OpsVault не подтвердил поиск реквизитов');
    if(strlen($uid)>80||!preg_match('/^[a-z0-9][a-z0-9_.-]{2,49}$/D',$login)||strlen($password)<14||strlen($password)>72)throw new OpsUnavailable('Реквизиты не подходят сервису');
    $name=$result['name']??$login;if(!is_string($name)||$name===''||strlen($name)>200||preg_match('/[\x00-\x1F\x7F]/',$name))$name=$login;
    $u=['id'=>$uid,'login'=>$login,'password'=>password_hash($password,PASSWORD_DEFAULT),'name'=>$name,'role'=>'buyer','active'=>1,'epoch'=>bin2hex(random_bytes(16))];
    // Canonical IDs never claim an unrelated pre-existing account after a rollback.
    if(sql('SELECT 1 FROM users WHERE id=? OR login=?',[$uid,$login])->fetchColumn())throw new OpsDenied('Коллизия аккаунта: требуется явная связь');
    if($d['found']===false)opsSaveCredentials($result,$u,$password);
    opsPolicy(['user_id'=>$uid,'subject'=>$result['subject']],'oidc',['lease'=>$result['lease']],true);
    db()->exec('BEGIN IMMEDIATE');
    try{
        // Reservation may have appeared while credentials were being published.
        if(opsReserved($result['subject']))throw new OpsDenied('Аккаунт требует явной связи');
        sql('INSERT INTO users VALUES(?,?,?,?,?,?,?)',[$uid,$login,$name,$u['password'],'buyer',1,$u['epoch']]);sql('INSERT INTO sso_links VALUES(?,?,?)',[$uid,OPSVAULT_ISSUER,$result['subject']]);db()->exec('COMMIT');
    }
    catch(Throwable $e){db()->exec('ROLLBACK');throw new OpsUnavailable('Не удалось создать аккаунт; повторный вход восстановит реквизиты');}
    return $u;
}
function opsReturnToPanel(string $target): never {
    // Commit a same-origin document before navigating: a 303 after a provider page
    // still has a cross-site initiator, so browsers omit the restored Strict cookie.
    // Targets are local application paths, never provider input or callback parameters.
    if($target!=='?page=settings')$target=opsSafePath($target);
    header('Content-Type: text/html; charset=utf-8');
    $url=json_encode($target,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
    ?><!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Возврат в Lead Bridge</title><body><p>Вход подтверждён. Возвращаемся в Lead Bridge…</p><a id="opsvault-return" href="<?=h($target)?>">Продолжить в Lead Bridge</a><script nonce="<?=h($GLOBALS['bridge_nonce'])?>">history.replaceState(null,'',<?= $url ?>);location.replace(<?= $url ?>);</script></body></html><?php
    exit;
}
function opsCallback(): never {
    $a=opsTakeAttempt();
    if(isset($_GET['iss'])&&$_GET['iss']!==OPSVAULT_ISSUER)throw new OpsDenied('Провайдер авторизации не совпадает');
    if(($a['kind']??'')!=='attempt'||!is_string($_GET['state']??null)||!hash_equals((string)($a['oidc']['openid_connect_state']??''),$_GET['state'])||!is_string($_GET['code']??null)||strlen($_GET['code'])>2048)throw new OpsDenied('Попытка входа не подтверждена');
    $c=opsClient();$c->attempt=$a['oidc'];$request=$_REQUEST;$_REQUEST=['state'=>$_GET['state'],'code'=>$_GET['code']];
    try{if(!$c->authenticate())throw new OpsDenied('OIDC не подтвердил вход');}
    catch(\Jumbojett\OpenIDConnectClientException $e){throw new OpsDenied('OIDC не подтвердил вход');}
    finally{$_REQUEST=$request;}
    $result=opsResult($c);
    if($a['expires']<=time())throw new OpsDenied('Попытка входа истекла');
    if(isset($a['uid'])){
        // Lax transaction cookie authenticates the browser; restore its original Strict session.
        session_write_close();session_id($a['panel_sid']);session_start();
        $u=currentLocalUser();if(!$u||$u['id']!==$a['uid']||$u['epoch']!==$a['epoch']||$u['password']!==$a['password_hash'])throw new OpsDenied('Локальная сессия изменилась');
        $a['kind']='pending';$a['result']=$result;unset($a['oidc']);$a['expires']=min($a['expires'],time()+300);
        sql('INSERT INTO sso_attempts VALUES(?,?,?)',[opsBrowser(),opsSeal($a),$a['expires']]);refreshPanelSession();opsReturnToPanel('?page=settings');
    }
    $u=opsProvision($result);opsNewSession($u,$result);opsBrowserCookie('',time()-3600);opsReturnToPanel($a['return']);
}
function opsConfirm(): void {
    $u=requireUser();$a=opsTakeAttempt();
    if(($a['kind']??'')!=='pending'||($a['uid']??'')!==$u['id']||($a['panel_sid']??'')!==session_id()||($a['epoch']??'')!==$u['epoch']||($a['password_hash']??'')!==$u['password']||!in_array($u['id'],opsIds('sso_link_ids'),true)||opsLink($u['id']))throw new OpsDenied('Подтверждение связи истекло или аккаунт изменился');
    $result=$a['result'];opsPolicy(['user_id'=>$u['id'],'subject'=>$result['subject']],'oidc',['lease'=>$result['lease']],true);
    db()->exec('BEGIN IMMEDIATE');
    try{
        $latest=sql('SELECT * FROM users WHERE id=?',[$u['id']])->fetch();
        opsValidateReservation($u['id'],$result['subject']);
        opsRequireRecovery($u['id']);
        if(!$latest['active']||$latest['epoch']!==$a['epoch']||$latest['password']!==$a['password_hash']||sql('SELECT 1 FROM sso_links WHERE issuer=? AND (subject=? OR user_id=?)',[OPSVAULT_ISSUER,$result['subject'],$u['id']])->fetchColumn())throw new OpsDenied('Связь конфликтует с существующим аккаунтом');
        opsSaveCredentials($result,$latest,$a['password']);
        if($a['expires']<=time())throw new OpsDenied('Подтверждение связи истекло');
        opsPolicy(['user_id'=>$u['id'],'subject'=>$result['subject']],'oidc',['lease'=>$result['lease']],true);
        $u['epoch']=bin2hex(random_bytes(16));sql('INSERT INTO sso_links VALUES(?,?,?)',[$u['id'],OPSVAULT_ISSUER,$result['subject']]);sql('UPDATE users SET epoch=? WHERE id=?',[$u['epoch'],$u['id']]);
        audit('opsvault_linked',$u['id']);db()->exec('COMMIT');
    }catch(Throwable $e){db()->exec('ROLLBACK');opsBrowserCookie('',time()-3600);throw $e;}
    opsBrowserCookie('',time()-3600);opsNewSession($u,$result);redirect('?page=settings');
}
function opsMutation(string $op): bool {
    if($op==='ops_unreserve'){
        requireAdmin();$user=required($_POST,'sso_local_user',80);
        if(opsLink($user))throw new InvalidArgumentException('Подтверждённую связь нельзя снять этим действием');
        sql('DELETE FROM sso_reservations WHERE user_id=? AND issuer=?',[$user,OPSVAULT_ISSUER]);audit('opsvault_reservation_removed',$user);return true;
    }
    if($op==='ops_reserve'){
        requireAdmin();$user=required($_POST,'sso_local_user',80);validOwner($user);$subject=required($_POST,'sso_subject',36);
        if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di',$subject))throw new InvalidArgumentException('Укажите подтверждённый UUID Identity OpsVault');
        $subject=strtolower($subject);db()->exec('BEGIN IMMEDIATE');
        try{
            $link=opsLink($user);if($link&&$link['subject']!==$subject)throw new InvalidArgumentException('Аккаунт уже связан с другой идентичностью');
            if(sql('SELECT user_id FROM sso_links WHERE issuer=? AND subject=? AND user_id<>?',[OPSVAULT_ISSUER,$subject,$user])->fetchColumn())throw new InvalidArgumentException('Идентичность уже связана с другим аккаунтом');
            opsValidateReservation($user,$subject);
            sql('INSERT INTO sso_reservations VALUES(?,?,?) ON CONFLICT(issuer,subject) DO NOTHING',[$user,OPSVAULT_ISSUER,$subject]);
            $ids=opsIds('sso_link_ids');if(!in_array($user,$ids,true)){$ids[]=$user;setting('sso_link_ids',implode(',',$ids));}audit('opsvault_reserved',$user);opsRecheck();db()->exec('COMMIT');
        }catch(Throwable $e){db()->exec('ROLLBACK');throw $e;}return true;
    }
    if($op==='ops_link_start'){
        $u=requireUser();if(($_SESSION['ops']['type']??'')==='oidc'||isset($_SESSION['ops']['lease']))throw new OpsDenied('Связывание начинается из локального входа');
        if(($_POST['vault_consent']??'')!=='yes')throw new InvalidArgumentException('Подтвердите сохранение отдельного пароля в OpsVault');opsBegin($u,(string)($_POST['ops_password']??''));
    }
    if($op==='ops_link_confirm'){if(($_POST['confirm_accounts']??'')!=='yes')throw new InvalidArgumentException('Подтвердите оба аккаунта');opsConfirm();}
    if($op==='ops_cancel'){
        requireUser();sql('DELETE FROM sso_attempts WHERE browser=?',[opsBrowser()]);opsBrowserCookie('',time()-3600);return true;
    }
    if($op==='ops_logout_all'){
        requireUser();$s=$_SESSION['ops']??[];if(($s['type']??'')!=='oidc')throw new InvalidArgumentException('Общий выход доступен только для OIDC-сессии');
        opsApi('access',['lease_token'=>opsOpen($s['lease'])['token'],'action'=>'logout']);endPanelSession();redirect('?');
    }
    if($op==='ops_config'){
        requireAdmin();$id=clean($_POST['sso_client_id']??'',128);if($id!==''&&!preg_match('/^[a-zA-Z0-9_.-]+$/D',$id))throw new InvalidArgumentException('Некорректный client ID');
        if(sql('SELECT 1 FROM sso_links LIMIT 1')->fetchColumn()&&$id!==setting('sso_client_id'))throw new InvalidArgumentException('У связанного сервиса нельзя менять client ID');
        $ids=clean($_POST['sso_link_ids']??'',1500);foreach(array_filter(array_map('trim',explode(',',$ids))) as $uid)validOwner($uid);
        if(isset($_POST['sso_gate'])&&($id===''||strlen(opsKey())!==32||strlen(getenv('BRIDGE_SSO_CLIENT_SECRET')?:'')<32))throw new InvalidArgumentException('Сначала установите client ID, серверный secret и ключ шифрования');
        setting('sso_client_id',$id);setting('sso_link_ids',$ids);setting('sso_jit',isset($_POST['sso_jit'])?'1':'0');setting('sso_gate',isset($_POST['sso_gate'])?'1':'0');audit('opsvault_configuration');redirect('?page=settings');
    }
    return false;
}
function opsSettings(): void {
    $u=requireUser();$link=opsLink($u['id']);
    echo '<div class="card"><h2>OpsVault</h2>';
    if($link){echo '<p>Аккаунт связан с OpsVault. Отдельный пароль и логин сохранены в хранилище. Права обоих способов входа проверяются централизованно.</p><a class="button secondary" href="https://portalwiki.world/vault/accounts/" rel="noreferrer">Мои реквизиты в OpsVault</a>';
        if(($_SESSION['ops']['type']??'')==='oidc')echo '<form method="post" style="margin-top:16px">'.csrf().'<input type="hidden" name="op" value="ops_logout_all"><button class="secondary">Выйти из OpsVault на этом устройстве</button></form>';
    }else{
        $pending=null;
        if(isset($_COOKIE['bridge_oidc']))try{$row=sql('SELECT * FROM sso_attempts WHERE browser=? AND expires>?',[opsBrowser(),time()])->fetch();if($row){$a=opsOpen($row['data']);if(($a['kind']??'')==='pending'&&$a['uid']===$u['id']&&$a['panel_sid']===session_id())$pending=$a;}}catch(Throwable $e){}
        if($pending){echo '<p>Локальный аккаунт: <strong>'.h($u['login']).'</strong><br>OpsVault: <strong>'.h($pending['result']['name']).'</strong><br>Идентификатор OpsVault: '.h($pending['result']['subject']).'</p><form method="post">'.csrf().'<input type="hidden" name="op" value="ops_link_confirm"><label><input type="checkbox" name="confirm_accounts" value="yes" required>Оба аккаунта мои. Подтверждаю связь и сохранение отдельного пароля в OpsVault.</label><button>Подтвердить связь</button></form><form method="post" style="margin-top:12px">'.csrf().'<input type="hidden" name="op" value="ops_cancel"><button class="secondary">Отменить</button></form>';
        }elseif(opsGate()&&opsReady()&&in_array($u['id'],opsIds('sso_link_ids'),true)){
            echo '<p>Связь сохраняет ваш локальный аккаунт и его данные. Пароль OpsVault здесь не нужен.</p><form method="post">'.csrf().'<input type="hidden" name="op" value="ops_link_start">';input('ops_password','Текущий отдельный пароль Lead Bridge','','password');echo '<label><input type="checkbox" name="vault_consent" value="yes" required>Согласен сохранить этот отдельный логин и пароль в зашифрованном хранилище OpsVault</label><button>Связать OpsVault</button></form>';
        }else echo '<p>Аккаунт ещё не связан. Администратор должен настроить OpsVault и добавить ваш локальный ID в пилот.</p>';
    }
    echo '</div>';
    if(isAdmin()){
        echo '<div class="card"><h2>Подключение OpsVault</h2><p>Callback: <code>'.h(opsCallbackUrl()).'</code><br>Выход: <code>'.h(rtrim(setting('base_url'),'/').'/').'</code></p><p>Серверные секреты: '.(opsReady()?'установлены':'не установлены полностью').'. Для связанных аккаунтов выключение входа закрывает и парольный доступ.</p><form method="post">'.csrf().'<input type="hidden" name="op" value="ops_config">';input('sso_client_id','Client ID',setting('sso_client_id')?:'lead-bridge');input('sso_link_ids','Пилот: локальные ID через запятую',setting('sso_link_ids'),'text',false);echo '<label><input type="checkbox" name="sso_gate" '.(opsGate()?'checked':'').'>Разрешить вход OpsVault</label><label><input type="checkbox" name="sso_jit" '.(setting('sso_jit')==='1'?'checked':'').'>Разрешить создание новых аккаунтов (после приёмки)</label><button>Сохранить</button></form><p class="muted">Пилот: только связь существующих аккаунтов. Непривязанные пилотные пользователи получают доступ только к Настройкам для связи. Аварийные аккаунты выбираются на сервере.</p></div>';
        echo '<div class="card"><h2>Существующие аккаунты</h2><p>Зарезервируйте подтверждённую идентичность OpsVault для прежнего аккаунта. Тогда первый вход предложит явную связь и не создаст пустой дубликат. UUID выдаёт оператор OpsVault; имя и email для связи не используются.</p><form method="post">'.csrf().'<input type="hidden" name="op" value="ops_reserve">';options('sso_local_user','Локальный аккаунт',accountOptions());input('sso_subject','UUID Identity OpsVault');echo '<button>Зарезервировать связь</button></form><div class="scroll"><table><tr><th>Локальный аккаунт</th><th>Identity OpsVault</th><th>Связь</th></tr>';
        foreach(sql('SELECT r.user_id,r.subject,u.login FROM sso_reservations r JOIN users u ON u.id=r.user_id WHERE r.issuer=? ORDER BY u.login',[OPSVAULT_ISSUER]) as $r){echo '<tr><td>'.h($r['login']).'</td><td>'.h($r['subject']).'</td><td>'.(opsLink($r['user_id'])?'Подтверждена':'Ожидает владельца');if(!opsLink($r['user_id']))echo '<form method="post">'.csrf().'<input type="hidden" name="op" value="ops_unreserve"><input type="hidden" name="sso_local_user" value="'.h($r['user_id']).'"><button class="secondary">Снять резерв</button></form>';echo '</td></tr>';}echo '</table></div></div>';
    }
}
