<?php
declare(strict_types=1);
// Lead Bridge: PHP 8.2+, curl, pdo_sqlite, sodium. The only public application file.
const BRIDGE_VERSION = '1.4.0';
function home(): string { return getenv('BRIDGE_DATA') ?: '/var/lib/lead-bridge'; }
function db(): PDO {
    static $db, $pid;
    if ($db && $pid===getmypid()) return $db;
    $pid=getmypid();
    if (!is_dir(home())) throw new RuntimeException('Private storage not installed');
    $db = new PDO('sqlite:'.home().'/bridge.sqlite', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=10000; PRAGMA foreign_keys=ON');
    $db->exec('CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY,v TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS entities (id TEXT PRIMARY KEY,kind TEXT NOT NULL,data TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS leads (id TEXT PRIMARY KEY,route TEXT NOT NULL,external_id TEXT NOT NULL,fingerprint TEXT NOT NULL,state TEXT NOT NULL,payload TEXT NOT NULL,snapshot TEXT NOT NULL,click_id TEXT NOT NULL DEFAULT "",partner_id TEXT NOT NULL DEFAULT "",message TEXT NOT NULL DEFAULT "",response TEXT NOT NULL DEFAULT "",created TEXT NOT NULL,updated TEXT NOT NULL);
      CREATE INDEX IF NOT EXISTS leads_state ON leads(state,created);
      CREATE INDEX IF NOT EXISTS leads_route ON leads(route,created);
      CREATE INDEX IF NOT EXISTS leads_external ON leads(external_id);
      CREATE INDEX IF NOT EXISTS leads_click_route ON leads(route,click_id);
      CREATE INDEX IF NOT EXISTS leads_click ON leads(click_id);
      CREATE TABLE IF NOT EXISTS postback_conflicts (id INTEGER PRIMARY KEY,partner TEXT NOT NULL,data TEXT NOT NULL,created TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS partner_status (lead TEXT PRIMARY KEY,status TEXT NOT NULL,partner_lead_id TEXT NOT NULL,updated TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS partner_events (id INTEGER PRIMARY KEY,lead TEXT NOT NULL,status TEXT NOT NULL,received TEXT NOT NULL,applied INTEGER NOT NULL);

      CREATE TABLE IF NOT EXISTS audit (id INTEGER PRIMARY KEY,created TEXT NOT NULL,event TEXT NOT NULL,subject TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS users (id TEXT PRIMARY KEY,login TEXT UNIQUE NOT NULL,name TEXT NOT NULL,password TEXT NOT NULL,role TEXT NOT NULL,active INTEGER NOT NULL,epoch TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS attempts (ip TEXT PRIMARY KEY,n INTEGER NOT NULL,until_at INTEGER NOT NULL)');
    $columns=$db->query('PRAGMA table_info(entities)')->fetchAll(PDO::FETCH_COLUMN,1);
    if(!in_array('owner',$columns,true)){
        $db->exec('BEGIN IMMEDIATE');
        try { if(!in_array('owner',$db->query('PRAGMA table_info(entities)')->fetchAll(PDO::FETCH_COLUMN,1),true))$db->exec("ALTER TABLE entities ADD COLUMN owner TEXT NOT NULL DEFAULT 'admin'");$db->exec('COMMIT'); }
        catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
    }
    if(!in_array('actor',$db->query('PRAGMA table_info(audit)')->fetchAll(PDO::FETCH_COLUMN,1),true)){
        $db->exec('BEGIN IMMEDIATE');
        try {if(!in_array('actor',$db->query('PRAGMA table_info(audit)')->fetchAll(PDO::FETCH_COLUMN,1),true))$db->exec("ALTER TABLE audit ADD COLUMN actor TEXT NOT NULL DEFAULT 'admin'");$db->exec('COMMIT');}
        catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
    }
    return $db;
}
function sql(string $s,array $p=[]): PDOStatement { $q=db()->prepare($s); $q->execute($p); return $q; }
function setting(string $k, ?string $v=null): string { if ($v!==null) sql('INSERT INTO settings VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v',[$k,$v]); return (string)(sql('SELECT v FROM settings WHERE k=?',[$k])->fetchColumn() ?: ''); }
function seal(array $a): string { $n=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); return base64_encode($n.sodium_crypto_secretbox(json_encode($a,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$n,file_get_contents(home().'/master.key'))); }
function unseal(string $s): array { $b=base64_decode($s,true); if ($b===false) throw new RuntimeException('Invalid encrypted data'); $v=sodium_crypto_secretbox_open(substr($b,24),substr($b,0,24),file_get_contents(home().'/master.key')); if ($v===false) throw new RuntimeException('Cannot decrypt data'); return json_decode($v,true,512,JSON_THROW_ON_ERROR); }
function entities(string $kind): array { $r=[]; foreach(sql('SELECT id,data FROM entities WHERE kind=? ORDER BY rowid DESC',[$kind]) as $v) $r[$v['id']]=unseal($v['data']); return $r; }
function entity(string $id,string $kind): array { $v=sql('SELECT data FROM entities WHERE id=? AND kind=?',[$id,$kind])->fetchColumn(); if (!$v) throw new InvalidArgumentException('Запись не найдена'); return unseal($v); }
function saveEntity(string $id,string $kind,array $a): void { sql('INSERT INTO entities(id,kind,data) VALUES(?,?,?) ON CONFLICT(id) DO UPDATE SET data=excluded.data WHERE entities.kind=excluded.kind',[$id,$kind,seal($a)]); }
function audit(string $event,string $id=''): void { sql('INSERT INTO audit(created,event,subject,actor) VALUES(?,?,?,?)',[gmdate('c'),$event,$id,$_SESSION['uid']??'system']); }
require_once __DIR__.'/accounts.php'; // __ACCOUNTS_MODULE__
require_once __DIR__.'/ui.php'; // __UI_MODULE__
function clean(mixed $v,int $max=250): string { if (!is_string($v)||strlen($v)>$max||preg_match('/[\x00-\x1F\x7F]/',$v)) throw new InvalidArgumentException('Некорректное текстовое поле'); return trim($v); }
function required(array $a,string $k,int $max=250): string { $v=clean($a[$k]??'',$max); if($v==='') throw new InvalidArgumentException('Заполните поле: '.$k); return $v; }
function publicUrl(string $url): string {
    $url=clean($url,1500); $p=parse_url($url);
    if (!$p||($p['scheme']??'')!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||!in_array($p['port']??443,[443],true)) throw new InvalidArgumentException('Нужен HTTPS URL без логина, пароля и нестандартного порта');
    return $url;
}
function httpRequest(string $url,?string $body,array $headers): array {
    publicUrl($url); $host=parse_url($url,PHP_URL_HOST);
    $ips=filter_var($host,FILTER_VALIDATE_IP)?[$host]:(gethostbynamel($host)?:[]);
    if (!$ips) return ['code'=>0,'error'=>'dns','body'=>''];
    foreach ($ips as $ip) if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('Tracker must resolve to a public address');
    $ch=curl_init($url); $result='';
    curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>40,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_RESOLVE=>[$host.':443:'.$ips[0]],CURLOPT_WRITEFUNCTION=>function($c,$chunk)use(&$result){if(strlen($result)+strlen($chunk)>1048576)return 0;$result.=$chunk;return strlen($chunk);}]);
    if($body!==null)curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body]);
    curl_exec($ch); $r=['code'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),'error'=>curl_errno($ch)?'transport':'','body'=>$result]; curl_close($ch); return $r;
}
function normalize(array $a): array {
    $r=['lead_id'=>required($a,'lead_id',40),'phone'=>required($a,'phone',20),'name'=>clean($a['name']??'Customer',200)?:'Customer'];
    if(!preg_match('/^[0-9]{1,40}$/D',$r['lead_id']))throw new InvalidArgumentException('Lead ID должен быть строкой цифр');
    if(!preg_match('/^\+[1-9][0-9]{6,14}$/D',$r['phone']))throw new InvalidArgumentException('Телефон нужен в международном формате +...');
    foreach(['campaign_id','ad_id','adgroup_id','advertiser_id','form_id'] as $k){$r[$k]=clean($a[$k]??'',40);if($r[$k]!==''&&!preg_match('/^[0-9]+$/D',$r[$k]))throw new InvalidArgumentException('Некорректный '.$k);}
    return $r;
}
function result(array $l): array { $pp=sql('SELECT status,updated FROM partner_status WHERE lead=?',[$l['id']])->fetch();return ['partner_status'=>$pp?$pp['status']:'','partner_status_updated'=>$pp?$pp['updated']:'','status'=>$l['state']==='sent'?'success':(in_array($l['state'],['queued','click_ready','binom_pending','partner_pending'])?'processing':'review'),'stage'=>$l['state'],'lead_id'=>$l['external_id'],'click_id'=>$l['click_id'],'partner_reference_id'=>$l['partner_id'],'message'=>$l['message'],'receipt'=>$l['id']]; }
function enqueue(string $routeId,array $route,array $input): array {
    db()->exec('BEGIN IMMEDIATE');
    try{$out=enqueueUnlocked($routeId,$route,$input);db()->exec('COMMIT');return $out;}catch(Throwable $e){db()->exec('ROLLBACK');throw $e;}
}
function enqueueUnlocked(string $routeId,array $route,array $input): array {
    $p=normalize($input); $id=hash('sha256',($route['dedupe_buyer']??$route['buyer']).'|tiktok|'.$p['lead_id']);
    $fp=hash('sha256',json_encode($p,JSON_THROW_ON_ERROR));
    $old=sql('SELECT * FROM leads WHERE id=?',[$id])->fetch();
    if ($old) { if($old['route']!==$routeId)throw new InvalidArgumentException('Этот Lead ID уже принадлежит другой связке этого баера'); if($old['fingerprint']!==$fp)throw new InvalidArgumentException('Данные существующего Lead ID изменились'); return result($old); }
    // Legacy routes may have a manually chosen namespace. Account-wide lookup prevents
    // a new login-derived route bypassing their dedupe history. Serialized with insert.
    $owner=ownerOf($routeId);
    if($owner&&sql('SELECT 1 FROM leads l JOIN entities e ON e.id=l.route WHERE e.owner=? AND l.external_id=? LIMIT 1',[$owner,$p['lead_id']])->fetchColumn())throw new InvalidArgumentException('Этот Lead ID уже принят в другой связке вашего аккаунта');
    if(empty($route['active']))throw new InvalidArgumentException('Связка приостановлена');
    $tracker=entity($route['tracker'],'tracker'); $partner=entity($route['partner'],'partner');
    $snapshot=['route'=>$route,'tracker'=>$tracker,'partner'=>$partner,'base_url'=>setting('base_url')];
    sql('INSERT OR IGNORE INTO leads(id,route,external_id,fingerprint,state,payload,snapshot,created,updated) VALUES(?,?,?,?,?,?,?,?,?)',[$id,$routeId,$p['lead_id'],$fp,'queued',seal($p),seal($snapshot),gmdate('c'),gmdate('c')]);
    $saved=sql('SELECT * FROM leads WHERE id=?',[$id])->fetch();
    if($saved['route']!==$routeId||$saved['fingerprint']!==$fp)throw new InvalidArgumentException('Конфликт Lead ID');
    return result($saved);
}
function stage(string $id,string $state,array $fields=[]): void {
    $fields=array_merge($fields,['state'=>$state,'updated'=>gmdate('c')]); $set=[];$args=[];
    foreach($fields as $k=>$v){if(!in_array($k,['state','updated','message','response','click_id','partner_id'],true))throw new LogicException('Invalid state field');$set[]=$k.'=?';$args[]=$v;}$args[]=$id;
    sql('UPDATE leads SET '.implode(',',$set).' WHERE id=?',$args);
}
function extractClickId(array $data,string $version): string {
    $valid=fn($v)=>is_string($v)&&preg_match('/^[a-zA-Z0-9_-]{1,200}$/D',$v);
    if($version==='v2'){$id=$data['click_info']['id']??'';return $valid($id)?$id:'';}
    $args=[];$offer=$data['offer']['url']??'';
    if(is_string($offer))parse_str((string)parse_url($offer,PHP_URL_QUERY),$args);
    // v1 returns conversion clickid explicitly. uclick is a different identifier.
    $id=$data['clickid']??'';
    if($id!==''){
        if(!$valid($id))return '';
        foreach(['binom_click_id','clickid'] as $key)if(isset($args[$key])&&$args[$key]!==$id)return '';
        return $id;
    }
    $id=$args['binom_click_id']??'';return $valid($id)?$id:'';
}
function trackerClick(array $t,array $route,array $p,string $base,?callable $http=null): array {
    $http??='httpRequest';$tokens=[];foreach($route['tokens'] as $field=>$param)if($param!=='')$tokens[$param]=$p[$field]??'';
    $params=array_merge($tokens,['key'=>$route['campaign_key'],'api_key'=>$t['api_key']]);
    if($t['version']==='v1') {
        $params['lp_type']='click_info';$params['__capiurl']=$base.'/action.php';
        $body=http_build_query(['ClickDataHeaders'=>json_encode(['HTTP_USER_AGENT'=>'LeadBridge/1.0 InstantForm','HTTP_CONTENT_TYPE'=>'application/json'])]);
        $headers=['Content-Type: application/x-www-form-urlencoded'];
    }elseif($t['version']==='v2'){$params['lpbcid']='1';$body=null;$headers=['User-Agent: LeadBridge/1.0 InstantForm','Accept: application/json'];}
    else throw new RuntimeException('Unknown tracker adapter');
    $r=$http($t['click_url'].'?'.http_build_query($params),$body,$headers);$d=json_decode($r['body'],true,512,JSON_BIGINT_AS_STRING);$click='';
    $click=is_array($d)?extractClickId($d,$t['version']):'';
    $ok=!$r['error']&&$r['code']===200&&is_string($click)&&preg_match('/^[a-zA-Z0-9_-]{1,200}$/D',$click)&&empty($d['error'])&&empty($d['errors'])&&($d['status']??'')!=='error';
    return ['ok'=>(bool)$ok,'click_id'=>$ok?$click:'','response'=>$r];
}
function processLead(string $id,?callable $http=null): void {
    $http??='httpRequest';
    $lock=fopen(home().'/lead-'.$id.'.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return;
    try {
        $l=sql('SELECT * FROM leads WHERE id=?',[$id])->fetch(); if(!$l||!in_array($l['state'],['queued','click_ready'],true))return;
        $p=unseal($l['payload']);$s=unseal($l['snapshot']);$route=$s['route'];$t=$s['tracker'];
        if($l['state']==='queued') {
            stage($id,'binom_pending',['message'=>'Создание клика']);
            $clickResult=trackerClick($t,$route,$p,$s['base_url'],$http);$r=$clickResult['response'];$click=$clickResult['click_id'];
            if(!$clickResult['ok']) {stage($id,'binom_review',['message'=>'Клик не подтверждён. ПП не вызывалась. HTTP '.$r['code'],'response'=>seal($r)]);return;}
            stage($id,'click_ready',['click_id'=>$click,'message'=>'Клик создан','response'=>'']);$l['click_id']=$click;
        }
        stage($id,'partner_pending',['message'=>'Отправка в ПП']);
        $body=['offerId'=>$route['offer_id'],'name'=>$p['name'],'phone'=>$p['phone'],'domain'=>parse_url($s['base_url'],PHP_URL_HOST),'clickid'=>$l['click_id'],'utm_campaign'=>$p['campaign_id'],'utm_content'=>$p['ad_id'],'utm_source'=>'tiktok'];
        $r=$http('https://sendmelead.com/api/v3/lead/add',json_encode($body,JSON_THROW_ON_ERROR),['Content-Type: application/json','X-Token: '.$s['partner']['token']]);
        $d=json_decode($r['body'],true,512,JSON_BIGINT_AS_STRING);
        $ok=!$r['error']&&$r['code']>=200&&$r['code']<300&&is_array($d)&&($d['result']??'')==='ok'&&!empty($d['localClickId'])&&is_string($d['localClickId'])&&empty($d['error'])&&empty($d['errors']);
        stage($id,$ok?'sent':'partner_review',['partner_id'=>$ok?$d['localClickId']:'','message'=>$ok?'ПП подтвердила приём':'Приём не подтверждён. Сверьте заявку в ПП. HTTP '.$r['code'],'response'=>seal($r)]);
    } catch(Throwable $e) { $state=sql('SELECT state FROM leads WHERE id=?',[$id])->fetchColumn(); stage($id,$state==='partner_pending'?'partner_review':'binom_review',['message'=>'Обработка прервана; нужна сверка','response'=>seal(['error'=>$e->getMessage()])]); }
    finally { flock($lock,LOCK_UN);fclose($lock); }
}
function worker(bool $once=false): void {
    $lock=fopen(home().'/worker.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Worker already running');
    // A previous process may have died after the remote service accepted its request.
    sql("UPDATE leads SET state=CASE WHEN state='partner_pending' THEN 'partner_review' ELSE 'binom_review' END,message='Процесс прервался. Сверьте результат перед повтором',updated=? WHERE state IN ('binom_pending','partner_pending')",[gmdate('c')]);
    do {setting('worker_heartbeat',(string)time());$ids=sql("SELECT id FROM leads WHERE state IN ('queued','click_ready') ORDER BY created LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);foreach($ids as $id){if(is_file(home().'/deploy.pause'))break;$dispatch=fopen(home().'/dispatch.lock','c');flock($dispatch,LOCK_EX);try{if(!is_file(home().'/deploy.pause'))processLead($id);}finally{flock($dispatch,LOCK_UN);fclose($dispatch);}setting('worker_heartbeat',(string)time());}if(!$once)sleep(2);}while(!$once);
}
function postbackKey(string $rid,array $route): string {
    return hash_hmac('sha256','lemonad-postback|'.$rid.'|'.$route['secret'],file_get_contents(home().'/master.key'));
}
function partnerPostbackKey(string $pid): string {
    return hash_hmac('sha256','lemonad-account-postback|'.$pid,file_get_contents(home().'/master.key'));
}
function receivePostback(array $a): array {
    $key=required($a,'key',128);$pid=clean($a['partner']??'',80);$rid='';
    if($pid!==''){
        try{entity($pid,'partner');}catch(InvalidArgumentException $e){throw new InvalidArgumentException('Unauthorized',401);}
        if(!hash_equals(partnerPostbackKey($pid),$key))throw new InvalidArgumentException('Unauthorized',401);
    }else{
        $rid=required($a,'route',80);
        try{$route=entity($rid,'route');}catch(InvalidArgumentException $e){throw new InvalidArgumentException('Unauthorized',401);}
        if(!hash_equals(postbackKey($rid,$route),$key))throw new InvalidArgumentException('Unauthorized',401);
    }
    $click=clean($a['clickid']??'',200);if($pid!==''&&!preg_match('/^[a-zA-Z0-9_-]{1,200}$/D',$click))return ['status'=>'ignored','message'=>'No matching bridge click'];$raw=strtolower(required($a,'status',30));$partnerLead=clean($a['leadid']??'',100);
    if(!preg_match('/^[a-zA-Z0-9_-]{1,200}$/D',$click))throw new InvalidArgumentException('Invalid clickid');
    $map=['new'=>'new','lead'=>'new','approve'=>'approved','approved'=>'approved','confirmed'=>'approved','reject'=>'rejected','rejected'=>'rejected','trash'=>'trash','paid'=>'paid','payout'=>'paid'];
    if(!isset($map[$raw]))throw new InvalidArgumentException('Use status: new, approved, rejected, trash, paid');
    $status=$map[$raw];db()->exec('BEGIN IMMEDIATE');
    try{
        if($pid!==''){
            $leads=[];
            foreach(sql('SELECT id,snapshot FROM leads WHERE click_id=?',[$click]) as $candidate){
                $snapshot=unseal($candidate['snapshot']);
                if(($snapshot['route']['partner']??'')===$pid)$leads[]=$candidate['id'];
            }
            if(count($leads)>1){
                sql('INSERT INTO postback_conflicts(partner,data,created) VALUES(?,?,?)',[$pid,seal(['click_id'=>$click,'status'=>$status,'partner_lead_id'=>$partnerLead,'receipts'=>$leads]),gmdate('c')]);
                audit('postback_ambiguous',$pid);
            }
        }else{$leads=sql('SELECT id FROM leads WHERE route=? AND click_id=?',[$rid,$click])->fetchAll(PDO::FETCH_COLUMN);}
        if(count($leads)!==1){db()->exec('COMMIT');return ['status'=>'ignored','message'=>'No unique matching lead'];}
        $id=$leads[0];$old=sql('SELECT * FROM partner_status WHERE lead=?',[$id])->fetch();
        if($old&&$old['partner_lead_id']!==''&&$partnerLead!==''&&$old['partner_lead_id']!==$partnerLead)throw new InvalidArgumentException('Partner lead ID mismatch',409);
        if($old&&$old['status']===$status){db()->exec('COMMIT');return ['status'=>'ok','duplicate'=>true];}
        // A delayed initial event must not undo a decision; payment is terminal.
        $apply=!$old||($status!=='new'&&$old['status']!=='paid');$now=gmdate('c');
        sql('INSERT INTO partner_events(lead,status,received,applied) VALUES(?,?,?,?)',[$id,$status,$now,(int)$apply]);
        if($apply)sql('INSERT INTO partner_status VALUES(?,?,?,?) ON CONFLICT(lead) DO UPDATE SET status=excluded.status,partner_lead_id=excluded.partner_lead_id,updated=excluded.updated',[$id,$status,$partnerLead?:($old['partner_lead_id']??''),$now]);
        db()->exec('COMMIT');return ['status'=>'ok','applied'=>$apply];
    }catch(Throwable $e){db()->exec('ROLLBACK');throw $e;}
}
function postback(): never {
    if($_SERVER['REQUEST_METHOD']!=='GET')jsonReply(['message'=>'GET required'],405);
    try{jsonReply(receivePostback($_GET));}
    catch(InvalidArgumentException $e){$code=in_array($e->getCode(),[401,409])?$e->getCode():400;jsonReply(['status'=>'error','message'=>$e->getMessage()],$code);}
    catch(Throwable $e){error_log('Bridge postback: '.get_class($e));jsonReply(['status'=>'error','message'=>'Temporary error'],503);}
}
function postbackGuide(string $pid): void {
    $url=setting('base_url').'/action.php?postback=lemonad&partner='.rawurlencode($pid).'&key='.partnerPostbackKey($pid).'&clickid={clickid}&status={status}&leadid={leadid}';
    echo '<div class="card"><h2>Статусы ПП → таблица → TikTok</h2><p>Добавьте этот GET-URL один раз в глобальные постбэки аккаунта Lemonad. Он обслуживает все его текущие и будущие связки в мосте. Существующий постбэк в Binom сохраните.</p><pre id="partner-postback">'.h($url).'</pre><button type="button" data-copy="partner-postback">Скопировать URL постбэка</button><p>Включите все пять статусов и задайте им значения:</p><table><tr><th>Статус Lemonad</th><th>Значение</th></tr><tr><td>Новый лид</td><td>new</td></tr><tr><td>Подтверждён</td><td>approved</td></tr><tr><td>Отклонён</td><td>rejected</td></tr><tr><td>Треш</td><td>trash</td></tr><tr><td>Оплачен</td><td>paid</td></tr></table><p>Постбэки по лидам, которых нет в мосте, игнорируются с ответом 200 OK. Поиск учитывает аккаунт ПП на момент отправки заявки. Неоднозначные совпадения сохраняются для проверки администратором. Ключ даёт право передавать статусы этого аккаунта: не публикуйте ссылку. Изменение кампаний и ключей связок не меняет этот URL. Старые ссылки постбэков отдельных связок также работают.</p><p>Обновите Apps Script из раздела «Подключение» и выполните «Подготовить и проверить». Он добавит <code>TikTok Lead Status</code> и <code>Partner Status Updated</code>. Статусы уже отправленных заявок проверяются пакетами по 100 строк за запуск на лист; новые лиды повторно не отправляются.</p><p>В TikTok Signal postback сопоставьте Lead status → TikTok Lead Status и настройте события для этих значений. Пока постбэк не получен, статус пустой. SENT означает только приём заявки. Старые статусы появятся после повторной отправки постбэка из ПП.</p><p class="muted">Повтор одинакового текущего статуса ничего не меняет. Запоздалый new не отменяет решение; paid — окончательный статус. Для approved/rejected/trash применяется последний полученный статус, так как ПП не передаёт время события в этом шаблоне.</p><p id="copy-status" role="status" aria-live="polite"></p></div>';
}
function jsonReply(array $a,int $code=200): never { http_response_code($code);header('Content-Type: application/json; charset=utf-8');echo json_encode($a,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit; }
function api(): never {
    if((int)($_SERVER['CONTENT_LENGTH']??0)>65536)jsonReply(['message'=>'Request too large'],413);
    try {
        $raw=file_get_contents('php://input',false,null,0,65537);if(strlen($raw)>65536)jsonReply(['message'=>'Request too large'],413);
        $a=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($a))throw new InvalidArgumentException('JSON object required');
        $rid=clean($a['route_id']??'',80);$secret=$_SERVER['HTTP_X_BRIDGE_SECRET']??'';
        try{$r=entity($rid,'route');}catch(Throwable $e){jsonReply(['message'=>'Unauthorized'],401);}
        if(!is_string($secret)||strlen($secret)<32||!hash_equals($r['secret'],$secret))jsonReply(['message'=>'Unauthorized'],401);
        if(setting('accounts_migrated')==='1'&&!sql('SELECT 1 FROM users WHERE id=? AND active=1',[ownerOf($rid)])->fetchColumn())jsonReply(['message'=>'Account disabled'],403);
        if(($a['op']??'')==='check')jsonReply(['status'=>'ok','active'=>(bool)$r['active'],'route'=>$r['name'],'version'=>BRIDGE_VERSION]);
        if(($a['op']??'')==='status') {
            $ids=$a['receipts']??[];if(!is_array($ids)||count($ids)>100)throw new InvalidArgumentException('Up to 100 receipts');$out=[];
            foreach($ids as $id){$l=sql('SELECT * FROM leads WHERE id=? AND route=?',[clean($id,64),$rid])->fetch();if($l)$out[$id]=result($l);}jsonReply(['results'=>$out]);
        }
        $res=enqueue($rid,$r,$a);jsonReply($res,$res['stage']==='sent'?200:($res['status']==='review'?409:202));
    }catch(InvalidArgumentException|JsonException $e){jsonReply(['status'=>'error','message'=>$e->getMessage()],400);}catch(Throwable $e){error_log('Bridge API: '.get_class($e));jsonReply(['status'=>'error','message'=>'Server error; retry only with the same Lead ID'],503);}
}
function h(mixed $s): string {return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function csrf(): string {return '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">';}
function redirect(string $url): never {header('Location: '.$url, true,303);exit;}
function input(string $name,string $label,string $value='',string $type='text',bool $req=true): void {echo '<label for="field_'.h($name).'">'.h($label).fieldHelp($name,$label).'<input id="field_'.h($name).'" type="'.h($type).'" name="'.h($name).'" value="'.h($value).'" '.($req?'required':'').' autocomplete="'.($type==='password'?'new-password':'off').'"></label>';}
function options(string $name,string $label,array $values,string $selected='',bool $required=true): void {echo '<label for="field_'.h($name).'">'.h($label).fieldHelp($name,$label).'<select id="field_'.h($name).'" name="'.h($name).'" '.($required?'required':'').'>';foreach($values as $id=>$v)echo '<option value="'.h($id).'" '.((string)$id===$selected?'selected':'').'>'.h(is_array($v)?$v['name']:$v).'</option>';echo '</select></label>';}
function defaultTokens(): array {return ['campaign_id'=>'campaign_id','adgroup_id'=>'adgroup_id','ad_id'=>'ad_id','lead_id'=>'lead_id','form_id'=>'','advertiser_id'=>''];}
function buyerLogin(string $owner): string {
    $login=sql('SELECT login FROM users WHERE id=?',[$owner])->fetchColumn();if(!$login)throw new InvalidArgumentException('Владелец не найден');return (string)$login;
}
function tokenLabels(): array {return ['campaign_id'=>'Campaign ID — кампания TikTok','adgroup_id'=>'Ad Group ID — группа объявлений','ad_id'=>'Ad ID — объявление','lead_id'=>'Lead ID — лид TikTok','form_id'=>'Form ID — форма','advertiser_id'=>'Advertiser ID — рекламный аккаунт'];}
function parseMapping(array $post,string $version): array {
    $params=[];$slots=[];$max=$version==='v1'?10:30;
    foreach(defaultTokens() as $field=>$default){
        $param=clean($post['token_'.$field]??'',80);$slot=clean($post['slot_'.$field]??'',2);
        if($param!==''&&!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,79}$/D',$param))throw new InvalidArgumentException('Некорректный Parameter: '.$field);
        if(in_array(strtolower($param),['key','lp_type','api_key','__capiurl','lpbcid','bcid','lp','ttclid'],true))throw new InvalidArgumentException('Служебный параметр нельзя использовать для меток');
        if($slot!==''&&(!ctype_digit($slot)||(int)$slot<1||(int)$slot>$max))throw new InvalidArgumentException('Для Binom '.$version.' допустимы Token 1–'.$max);
        if($param===''&&$slot!=='')throw new InvalidArgumentException('Для выбранного Token заполните Parameter: '.$field);
        $params[$field]=$param;$slots[$field]=$slot===''?'':(string)(int)$slot;
    }
    foreach([$params,$slots] as $values){$nonempty=array_values(array_filter($values,fn($v)=>$v!==''));if(count($nonempty)!==count(array_unique($nonempty)))throw new InvalidArgumentException('Один Parameter или номер Token нельзя назначать нескольким полям');}
    return [$params,$slots];
}
function mappingForm(array $v,string $version='v2'): void {
    echo '<details><summary>Сопоставление меток с Binom</summary><p class="muted">Для каждого поля укажите его строку Token и точное значение колонки Parameter в Traffic Source. Порядок может быть любым. Placeholder и Name копировать не нужно.</p><div class="scroll"><table><tr><th>Данные из таблицы</th><th>Строка в Binom</th><th>Parameter в Binom</th></tr>';
    foreach(tokenLabels() as $field=>$label){
        echo '<tr><td>'.h($label).'</td><td>'; $choices=[''=>'Номер не указан'];for($i=1;$i<=($version==='v1'?10:30);$i++)$choices[(string)$i]='Token '.$i;
        options('slot_'.$field,'Номер токена',$choices,(string)($v['token_slots'][$field]??''),false);
        echo '</td><td>';input('token_'.$field,'Parameter',$v['tokens'][$field]??defaultTokens()[$field],'text',false);echo '</td></tr>';
    }
    echo '</table></div><p class="muted">Например: Ad ID → Token 3 → ad_id. Если перенесли Ad ID в Token 7, укажите Token 7 и Parameter именно этой строки. Отправку определяет Parameter; номер используется для сверки. Пустой Parameter и неуказанный номер отключают поле. v1: до 10 токенов, v2: до 30.</p></details>';
}
function mappingChecks(array $route,array $sent,array $received,string $version): array {
    $checks=[];foreach($route['tokens'] as $field=>$param){if($param==='')continue;$slot=$route['token_slots'][$field]??'';
        $canCheck=$version==='v2'&&$slot!=='';$actual=$canCheck?(string)($received['token_'.$slot]??''):'';
        $checks[$field]=['parameter'=>$param,'token'=>$slot?:'не указан','result'=>$canCheck?($actual===(string)$sent[$field]?'совпадает':'не совпадает'):'проверьте в отчёте Binom','expected'=>$sent[$field]??'','actual'=>$actual];
    }return $checks;
}
function diagnose(string $id): array {
    $r=uiEntity($id,'route');$t=uiEntity($r['tracker'],'tracker');
    $p=['lead_id'=>'9000000000'.time(),'campaign_id'=>'900000001','adgroup_id'=>'900000002','ad_id'=>'900000003','form_id'=>'900000004','advertiser_id'=>'900000005'];
    $test=trackerClick($t,$r,$p,setting('base_url'));$d=json_decode($test['response']['body'],true,512,JSON_BIGINT_AS_STRING);$tokens=[];
    if($t['version']==='v2'){foreach(($d['click_info']??[]) as $k=>$v)if(str_starts_with($k,'token_')&&$v!=='')$tokens[$k]=$v;}
    else {$tokens=is_array($d)?($d['campaign']['tokens']??[]):[];}
    $out=['created_at'=>gmdate('c'),'ok'=>$test['ok'],'click_id'=>$test['click_id'],'http_code'=>$test['response']['code'],'traffic_source'=>$d['click_info']['traffic_source_name']??'','tokens'=>$tokens,'sent_values'=>$p,'message'=>$test['ok']?'Клик создан. ПП не вызывалась. Сверьте метки ниже.':'Клик не подтверждён. Проверьте URL, ключ и кампанию. ПП не вызывалась.'];
    $out['mapping_checks']=mappingChecks($r,$p,$tokens,$t['version']);
    $r['diagnostic']=$out;saveEntity($id,'route',$r);audit('test_click',$id);return $out;
}
function mutate(): void {
    $me=requireUser();$op=$_POST['op']??'';
    if($op==='user_save'){
        db()->exec('BEGIN IMMEDIATE');try{accountMutation($op);db()->exec('COMMIT');}catch(Throwable $e){db()->exec('ROLLBACK');throw $e;}return;
    }
    if(accountMutation($op))return;
    if($op==='logout'){session_destroy();redirect('?');}
    if($op==='diagnose'){diagnose(required($_POST,'id'));return;}
    if($op==='save'){
        $kind=required($_POST,'kind');if(!in_array($kind,['tracker','partner','route'],true))throw new InvalidArgumentException('Invalid type');
        $id=clean($_POST['id']??'',80);$old=$id?uiEntity($id,$kind):[];
        $owner=$id?ownerOf($id):(isAdmin()?clean($_POST['owner']??$me['id'],80):$me['id']);validOwner($owner);
        if(isset($_POST['owner'])&&$_POST['owner']!==$owner)throw new InvalidArgumentException('Недопустимый владелец');
        $id=$id?:$kind.'_'.bin2hex(random_bytes(6));$v=['name'=>required($_POST,'name')];
        if($kind==='tracker'){
            $v['version']=required($_POST,'version');if(!in_array($v['version'],['v1','v2']))throw new InvalidArgumentException('Invalid version');
            $v['click_url']=publicUrl(required($_POST,'click_url',1500));if(parse_url($v['click_url'],PHP_URL_QUERY))throw new InvalidArgumentException('Click URL укажите без параметров');
            $v['api_key']=clean($_POST['api_key']??'',500)?:($old['api_key']??'');if(!$v['api_key'])throw new InvalidArgumentException('Введите API key');
        }elseif($kind==='partner'){$v['type']='lemonad';$v['token']=clean($_POST['token']??'',500)?:($old['token']??'');if(!$v['token'])throw new InvalidArgumentException('Введите токен ПП');}
        else {
            $v['buyer']=buyerLogin($owner);$v['dedupe_buyer']=$old['dedupe_buyer']??$old['buyer']??$v['buyer'];
            $v['tracker']=required($_POST,'tracker');$v['partner']=required($_POST,'partner');$t=uiEntity($v['tracker'],'tracker');uiEntity($v['partner'],'partner');
            if(ownerOf($v['tracker'])!==$owner||ownerOf($v['partner'])!==$owner)throw new InvalidArgumentException('Связка, трекер и аккаунт ПП должны принадлежать одному пользователю');
            $v['campaign_key']=required($_POST,'campaign_key');$v['offer_id']=required($_POST,'offer_id');$v['active']=isset($_POST['active']);
            $v['secret']=$old['secret']??bin2hex(random_bytes(32));[$v['tokens'],$v['token_slots']]=parseMapping($_POST,$t['version']);
        }
        saveEntity($id,$kind,$v);sql('UPDATE entities SET owner=? WHERE id=?',[$owner,$id]);audit('save_'.$kind,$id);redirect('?page='.$kind.($kind==='route'?'&edit='.$id:''));
    }
    if($op==='duplicate'){$id=required($_POST,'id');$r=uiEntity($id,'route');$r['name'].=' — копия';$r['active']=false;$r['secret']=bin2hex(random_bytes(32));$new='route_'.bin2hex(random_bytes(6));saveEntity($new,'route',$r);sql('UPDATE entities SET owner=? WHERE id=?',[ownerOf($id),$new]);audit('duplicate_route',$new);redirect('?page=route&edit='.$new);}
    if($op==='rotate'){$id=required($_POST,'id');$r=uiEntity($id,'route');$r['secret']=bin2hex(random_bytes(32));saveEntity($id,'route',$r);audit('rotate_secret',$id);return;}
    if($op==='reconcile'){
        $id=required($_POST,'id',64);uiLead($id);$lock=fopen(home().'/lead-'.$id.'.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB))throw new InvalidArgumentException('Лид сейчас обрабатывается');
        try{$l=uiLead($id);if(!$l||!in_array($l['state'],['binom_review','partner_review']))throw new InvalidArgumentException('Недоступно для этого статуса');if(($_POST['verified']??'')!=='yes')throw new InvalidArgumentException('Нужна сверка в трекере/ПП');
            $action=required($_POST,'decision');$ref=clean($_POST['reference']??'',200);
            if($action==='sent'){if(!$l['click_id']||!$ref)throw new InvalidArgumentException('Нужны clickid и номер принятой заявки');stage($id,'sent',['partner_id'=>$ref,'message'=>'Приём подтверждён администратором']);}
            elseif($action==='resume'){if(!preg_match('/^[A-Za-z0-9_-]{1,200}$/D',$ref))throw new InvalidArgumentException('Введите проверенный clickid');stage($id,'click_ready',['click_id'=>$ref,'message'=>'Администратор подтвердил отсутствие заявки в ПП']);}
            elseif($action==='requeue'){if($l['state']!=='binom_review')throw new InvalidArgumentException('Повтор клика недоступен после вызова ПП');stage($id,'queued',['message'=>'Администратор подтвердил отсутствие клика и заявки']);}
            else throw new InvalidArgumentException('Invalid decision');audit('reconcile_'.$action,$id);
        }finally{flock($lock,LOCK_UN);fclose($lock);}return;
    }
    throw new InvalidArgumentException('Unknown operation');
}
function connectionGuide(?array $conf=null): void {
    $headers="Lead ID\tName\tPhone\tCampaign ID\tAd Group ID\tAd ID\tAdvertiser ID\tForm ID";
    echo '<h2>Подключение таблицы</h2><h3>1. Создайте таблицу и добавьте шапку</h3><p>Создайте Google-таблицу для этой связки. Скопируйте шапку ниже и вставьте в ячейку A1: названия распределятся по столбцам.</p><pre id="sheet-headers">'.h($headers).'</pre><button type="button" class="secondary" data-copy="sheet-headers">Скопировать шапку</button><p>Обязательные колонки — <code>Lead ID</code> и <code>Phone</code>. <code>Name</code> — имя; остальные колонки — рекламные метки. Порядок столбцов можно менять, названия должны совпадать. Вместо Lead ID поддерживается TikTok Lead ID.</p><p>До поступления лидов выделите столбцы с ID и телефоном и выберите <strong>Формат → Числа → Обычный текст</strong>, чтобы сохранить длинные ID и код страны. Настройте интеграцию TikTok на запись данных в соответствующие колонки. Служебные колонки статусов скрипт добавит сам.</p>';
    echo '<h3>2. Установите скрипт в Apps Script</h3><div class="actions"><button type="button" data-copy="apps-script">Скопировать скрипт</button><a class="button secondary" data-download href="?page=setup&amp;download=script">Скачать LeadBridge.gs</a></div><textarea id="apps-script" hidden>'.h(base64_decode('__SCRIPT_BASE64__')).'</textarea><p>В таблице откройте <strong>Расширения → Apps Script</strong>. В файле <strong>Код.gs / Code.gs</strong> замените стандартный код скопированным скриптом и сохраните. Если скачали файл, откройте его как текст и вставьте содержимое в редактор. Вернитесь в таблицу и обновите страницу — появится меню <strong>Lead Bridge</strong>.</p>';
    echo '<h3>3. Подключите связку через JSON</h3>';
    if($conf){echo '<pre id="bridge-json">'.h(json_encode($conf,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre><button type="button" data-copy="bridge-json">Скопировать JSON</button><p class="muted">JSON содержит секрет отправки этой связки. Передавайте его только тем, кто подключает её таблицу.</p>';}
    else{echo '<p>Откройте <a href="?page=route">Связки</a> → нужная связка → «Подключение таблицы» и нажмите <strong>Скопировать JSON</strong>. У каждой связки свой JSON.</p>';}
    echo '<p>В самой Google-таблице откройте <strong>Lead Bridge → Настроить</strong>, вставьте JSON целиком в появившееся окно и нажмите OK. JSON вводится в это окно, а не в код Apps Script.</p><h3>4. Проверьте подключение и включите отправку</h3><p>Откройте <strong>Lead Bridge → Подготовить и проверить</strong> и предоставьте разрешения Google, если появится запрос. Скрипт проверит подключение и добавит служебные колонки. Убедитесь, что связка активна.</p><p>Перед включением удалите старые триггеры отправки этой таблицы, включая триггеры другого владельца. Затем выберите <strong>Lead Bridge → Включить отправку раз в минуту</strong>. Будет создан один минутный триггер текущего аккаунта. Строки со статусом SENT повторно не отправляются.</p><p id="copy-status" role="status" aria-live="polite"></p>';
}
function buyerManual(): void {
    echo <<<'HTML'
<button type="button" id="help-open" class="help-fab" aria-label="Открыть руководство баера" aria-haspopup="dialog" aria-controls="buyer-help" title="Руководство баера">?</button>
<dialog id="buyer-help" aria-labelledby="help-title">
  <div class="help-header"><div><h2 id="help-title">Руководство баера</h2><span class="muted">От подключения трекера до первого лида</span></div><button type="button" id="help-close" class="secondary" aria-label="Закрыть руководство" autofocus>✕</button></div>
  <div class="help-content">
    <p>Настройте трекер и аккаунт Lemonad один раз. Для нового продукта создайте связку и отдельную Google-таблицу. Логин для входа выдаёт администратор; Buyer ID определяется автоматически по владельцу связки.</p>
    <h3>1. Добавьте трекер</h3>
    <p>Откройте <a href="?page=tracker">Трекеры</a> → Добавить. Укажите название, версию Binom, полный Click URL и API key.</p>
    <ul><li><strong>Binom v1:</strong> ключ находится в <strong>Settings → API</strong>. Click URL возьмите из Settings → Tracking links → Click URL. Удалите всё начиная с <code>?</code>: например, <code>https://tracker.com/abc.php?lp=1</code> → <code>https://tracker.com/abc.php</code>. Если файл переименован, сохраните его настоящее имя.</li>
    <li><strong>Binom v2:</strong> откройте <strong>Settings → Click API</strong>. В показанном PHP-коде найдите <code>API_KEY</code> и <code>TRACKER_URL_TEMPLATE</code>. Вставьте их значения без кавычек; адрес обычно заканчивается на <code>/click</code>. Нужен ключ из Click API; раздел Public API / пользовательский API предназначен для других операций.</li></ul>
    <p>В поле ключа вставляйте только значение, без <code>&amp;api_key=</code>. Сохраните трекер. <a href="https://docs.binom.org/click-api.php" target="_blank" rel="noopener noreferrer">Документация Binom v1</a> · <a href="https://docs.binom.org/click-api-v2.php" target="_blank" rel="noopener noreferrer">Binom v2</a>.</p>
    <h3>2. Подключите аккаунт Lemonad</h3>
    <p>Откройте <a href="?page=partner">Партнёрки</a> → Добавить. Укажите понятное название аккаунта и <strong>Webmaster token</strong> из профиля Lemonad. Это токен аккаунта, а не ID оффера. Сохраните. Используйте эту же запись партнёрки во всех своих связках данного аккаунта.</p>
    <p><a href="https://docs.limonad.com/doc/en-webmaster-token" target="_blank" rel="noopener noreferrer">Где взять токен Lemonad</a>.</p>
    <h3>3. Добавьте глобальный постбэк в Lemonad</h3>
    <p>В сохранённой партнёрке найдите «Статусы ПП → таблица → TikTok» и нажмите <strong>Скопировать URL постбэка</strong>. В Lemonad откройте <strong>Профиль → Global postback and API → Add postback</strong>, вставьте ссылку целиком и включите все пять статусов:</p>
    <div class="scroll"><table><tr><th>Статус Lemonad</th><th>Значение</th></tr><tr><td>Новый лид</td><td>new</td></tr><tr><td>Подтверждён</td><td>approved</td></tr><tr><td>Отклонён</td><td>rejected</td></tr><tr><td>Треш</td><td>trash</td></tr><tr><td>Оплачен</td><td>paid</td></tr></table></div>
    <p>Эта ссылка работает для всех связок, использующих выбранную запись партнёрки. Постбэки по лидам, которых нет в мосте, игнорируются. Сохраните существующий постбэк <strong>Lemonad → Binom</strong>: он нужен для зачёта конверсий в трекере. Постбэк на мост обновляет статусы в таблице. <a href="https://docs.limonad.com/doc/en-postback" target="_blank" rel="noopener noreferrer">Инструкция Lemonad</a>.</p>
    <h3>4. Создайте связку</h3>
    <p>В Binom подготовьте кампанию и выберите нужный Traffic Source. В мосте откройте <a href="?page=route">Связки</a> → Добавить, выберите трекер и партнёрку. Вставьте <strong>ключ кампании Binom</strong> — значение параметра <code>key</code> в ссылке кампании — и <strong>API Offer ID Lemonad</strong> из настроек интеграции оффера или полученный у менеджера. Число в названии оффера не заменяет API Offer ID.</p>
    <p>Раскройте «Сопоставление меток с Binom» и укажите точные названия <strong>Parameter</strong> из Traffic Source для Campaign ID, Ad Group ID, Ad ID и Lead ID. Порядок токенов может быть любым. Placeholder и Name сюда не копируются. Сохраните связку и включите «Связка активна», когда она готова к отправке.</p>
    <p>При необходимости раскройте «Проверка трекера»: кнопка создаст один технический клик, но не отправит заявку в ПП.</p>
    <h3>5. Подключите Google-таблицу</h3>
    <ol><li>В карточке связки найдите «Подключение таблицы». Создайте таблицу, скопируйте шапку и вставьте в A1. Обязательны <code>Lead ID</code> (либо <code>TikTok Lead ID</code>) и <code>Phone</code>; имя — <code>Name</code>. Метки — <code>Campaign ID</code>, <code>Ad Group ID</code>, <code>Ad ID</code>, <code>Advertiser ID</code>, <code>Form ID</code>.</li>
    <li>До поступления данных задайте столбцам ID и телефона формат «Обычный текст». В телефоне должен быть код страны.</li>
    <li>Нажмите «Скопировать скрипт» или скачайте LeadBridge.gs. В таблице откройте <strong>Расширения → Apps Script</strong>, вставьте код в Code.gs / Код.gs и сохраните. Обновите страницу таблицы.</li>
    <li>Скопируйте JSON из этой связки. В самой таблице откройте <strong>Lead Bridge → Настроить</strong> и вставьте JSON в окно. Его не нужно вставлять в исходный код.</li>
    <li>Выполните <strong>Lead Bridge → Подготовить и проверить</strong>, предоставьте запрошенные разрешения Google. Скрипт добавит служебные колонки и проверит подключение.</li></ol>
    <h3>6. Подключите форму TikTok и включите отправку</h3>
    <p>В TikTok Leads Center откройте Connect CRM → Google Sheets. В <strong>Lead export</strong> выберите нужную форму, таблицу и лист; сопоставьте поля формы с колонками. В поле Lead ID должен попадать исходный ID лида TikTok.</p>
    <p>Удалите прежние триггеры отправки этой таблицы, включая триггеры другого владельца, затем выберите <strong>Lead Bridge → Включить отправку раз в минуту</strong>. Новые заполненные строки будут отправляться в ПП. Если вы лишь обновили наш скрипт, существующий триггер bridgeTick и JSON можно сохранить — выполните «Подготовить и проверить».</p>
    <p>Для обратных статусов настройте <strong>Signal postback</strong>: Lead status → <code>TikTok Lead Status</code>, TikTok lead ID → колонка с исходным ID TikTok. В Events Manager сопоставьте значения статусов с подходящими этапами воронки. Binom Click ID не является TikTok Click ID. <a href="https://ads.tiktok.com/resources/help/article/how-to-set-up-one-click-crm-integration-for-google-sheets?lang=en" target="_blank" rel="noopener noreferrer">Инструкция TikTok</a>.</p>
    <h3>7. Проверьте цепочку на тестовом лиде</h3>
    <p>Отправьте тест из TikTok и проверьте строку в таблице, <a href="?page=leads">журнал лидов</a>, клик в Binom и заявку в ПП. Тест действительно отправляется в партнёрку. После постбэка Lemonad и очередного запуска скрипта должен заполниться TikTok Lead Status. В предпросмотре формы рекламные метки могут отсутствовать.</p>
    <ul><li><strong>QUEUED</strong> — заявка сохранена, ожидает обработки.</li><li><strong>SENT</strong> — ПП подтвердила приём. Это ещё не апрув.</li><li><strong>REVIEW</strong> — нужна сверка ошибки в журнале; не создавайте новую строку с другим ID для повторной отправки.</li><li><strong>RETRY</strong> — повторная проверка с тем же Lead ID; при сохранённой квитанции проверяется уже существующая заявка.</li></ul>
    <p>Если TikTok Lead Status пустой — проверьте доставку постбэка на мост и версию Apps Script. Строки SENT получают статусы ПП без повторной отправки заявки. Для старых лидов запросите повтор постбэка в ПП. Если заявка есть в ПП, а конверсии нет в Binom — проверьте отдельный постбэк ПП → Binom.</p>
  </div>
</dialog>
HTML;
}
function panel(): void {
    ini_set('session.use_strict_mode','1');session_name('leadbridge');session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict','path'=>'/']);session_start();$_SESSION['csrf']??=bin2hex(random_bytes(24));
    migrateAccounts();$error='';$notice='';$auth=currentUser()!==null;
    if($_SERVER['REQUEST_METHOD']==='POST'){
        try {if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??'')))throw new InvalidArgumentException('Сессия формы истекла. Обновите страницу');
            if(($_POST['op']??'')==='login'){
                $ip=$_SERVER['REMOTE_ADDR']??'unknown';$attempt=sql('SELECT * FROM attempts WHERE ip=?',[$ip])->fetch();if($attempt&&$attempt['n']>=10&&$attempt['until_at']>time())throw new InvalidArgumentException('Слишком много попыток. Подождите 15 минут');
                $login=strtolower(clean($_POST['login']??'',50));$user=sql('SELECT * FROM users WHERE login=?',[$login])->fetch();
                $passwordOk=password_verify((string)($_POST['password']??''),$user['password']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
                if(!$user||!$user['active']||!$passwordOk){$n=($attempt&&$attempt['until_at']>time())?$attempt['n']+1:1;sql('INSERT OR REPLACE INTO attempts VALUES(?,?,?)',[$ip,$n,time()+900]);throw new InvalidArgumentException('Неверный логин или пароль');}
                sql('DELETE FROM attempts WHERE ip=?',[$ip]);session_regenerate_id(true);$_SESSION['uid']=$user['id'];$_SESSION['last']=time();$_SESSION['epoch']=$user['epoch'];$_SESSION['csrf']=bin2hex(random_bytes(24));audit('login');redirect('?');
            }
            if(!$auth)throw new InvalidArgumentException('Войдите в панель');mutate();$notice='Сохранено';
        }catch(InvalidArgumentException $e){$error=$e->getMessage();}catch(Throwable $e){$error='Не удалось выполнить действие';error_log('Bridge panel: '.get_class($e));}
    }
    if($auth)$_SESSION['last']=time();
    $page=$_GET['page']??'overview';$titles=['overview'=>'Обзор','route'=>'Связки','tracker'=>'Трекеры','partner'=>'Партнёрки','leads'=>'Журнал лидов','setup'=>'Подключение','settings'=>'Настройки'];
    if($auth&&isAdmin())$titles['users']='Аккаунты';
    if($auth&&$page==='users'&&!isAdmin()){http_response_code(403);echo 'Доступ запрещён';return;}
    if(!isset($titles[$page]))$page='overview';
    if($auth){try{
        if(in_array($page,['tracker','partner','route'])&&!empty($_GET['edit']))uiEntity(clean($_GET['edit'],80),$page);
        if($page==='leads'&&!empty($_GET['id']))uiLead(clean($_GET['id'],64));
    }catch(InvalidArgumentException $e){http_response_code(404);echo 'Запись не найдена или недоступна';return;}}

    if($auth&&$page==='setup'&&($_GET['download']??'')==='script'){header('Content-Type: text/plain; charset=utf-8');header('Content-Disposition: attachment; filename="LeadBridge.gs"');echo base64_decode('__SCRIPT_BASE64__');return;}
    ?><!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Lead Bridge · <?=h($titles[$page])?></title><style>
    [hidden]{display:none!important}#apps-script{min-height:260px}*{box-sizing:border-box}body{margin:0;background:#f5f7fa;color:#15223a;font:15px/1.55 system-ui,-apple-system,Segoe UI,sans-serif}a{color:#285ddd;text-decoration:none}aside{position:fixed;inset:0 auto 0 0;width:228px;background:#13243c;color:#fff;padding:30px 22px}.brand{font-size:23px;font-weight:750;letter-spacing:-.7px}.brand span{color:#70d8c5}.sub{color:#9eb0c8;font-size:12px;margin:3px 0 34px}nav a{display:block;padding:11px 14px;border-radius:9px;color:#bdc9d9;margin:5px 0}nav a.active{background:#254363;color:#fff}main{margin-left:228px;padding:36px 44px;max-width:1500px}h1{font-size:30px;letter-spacing:-.8px;margin:0 0 5px}h2{font-size:19px;margin:0 0 18px}.muted{color:#718096}.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px}.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:24px;margin-bottom:20px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.stat strong{display:block;font-size:32px;margin:8px 0}.stat{margin-bottom:24px}label{display:block;font-size:13px;font-weight:600;margin-bottom:16px}input,select,textarea{display:block;width:100%;font:inherit;color:#15223a;border:1px solid #cdd7e3;border-radius:8px;padding:11px 12px;margin-top:7px;background:white}input[type=checkbox]{display:inline;width:auto;margin:0 8px 0 0}button,.button{display:inline-block;border:0;border-radius:8px;background:#265edb;color:white;padding:11px 17px;font:600 14px system-ui;cursor:pointer}.secondary{background:#edf2fa;color:#28518b}.danger{background:#f9e4e4;color:#ab3838}table{width:100%;border-collapse:collapse;font-size:13px}td,th{text-align:left;padding:14px 10px;border-bottom:1px solid #edf0f5;vertical-align:top}th{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#6d7e95}.badge{display:inline-block;border-radius:20px;background:#edf2f9;color:#5d6c83;padding:4px 10px;font-size:12px}.good{background:#e1f5ed;color:#147454}.warn{background:#fff1d6;color:#946418}.error{background:#ffe9e8;color:#a73333;padding:14px;border-radius:8px;margin-bottom:20px}.success{background:#e1f5ed;padding:14px;border-radius:8px;margin-bottom:20px}code,pre{font:12px/1.6 ui-monospace,Consolas,monospace}pre{white-space:pre-wrap;word-break:break-word;background:#f3f6fa;padding:16px;border-radius:8px}.scroll{overflow:auto}.login{max-width:440px;margin:12vh auto;padding:0}.login h1{margin-bottom:22px}.actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.actions form{margin:0}details{margin:12px 0}summary{cursor:pointer;color:#285ddd}.foot{font-size:12px;color:#8795a9;margin-top:30px}@media(max-width:900px){aside{position:static;width:auto;padding:18px}nav{display:flex;flex-wrap:wrap}.sub{margin:0}nav a{padding:6px 9px}main{margin:0;padding:20px}.stats,.grid{grid-template-columns:1fr 1fr}}@media(max-width:560px){.stats,.grid{grid-template-columns:1fr}.top{display:block}.card{padding:17px}}

    [data-copy],[data-download]{position:relative;isolation:isolate;transition:transform .15s ease,background-color .2s ease;white-space:nowrap}
    [data-copy]:active,[data-download]:active{transform:scale(.97)}
    [data-copy]:focus-visible,[data-download]:focus-visible{outline:3px solid #93b4fa;outline-offset:4px}
    .button-wave::before,.button-wave::after{content:"";position:absolute;inset:0;border:2px solid #5486ec;border-radius:inherit;pointer-events:none;animation:button-wave .65s ease-out both}
    .button-wave::after{animation-delay:.12s}
    .copy-done{background:#dff4e9!important;color:#176649!important}
    @keyframes button-wave{from{opacity:.55;transform:scale(1)}to{opacity:0;transform:scale(1.12,1.5)}}
    @media(prefers-reduced-motion:reduce){[data-copy],[data-download]{transition:none}[data-copy]:active,[data-download]:active{transform:none}.button-wave::before,.button-wave::after{animation:none;display:none}}

    .help-fab{position:fixed;right:24px;bottom:24px;z-index:50;width:52px;height:52px;padding:0;border-radius:50%;font-size:25px;box-shadow:0 5px 22px #15223a30}
    .help-fab:hover{background:#174cc3}.help-fab:focus-visible{outline:3px solid #91b5ff;outline-offset:4px}
    #buyer-help{width:min(860px,calc(100vw - 32px));max-height:86vh;max-height:86dvh;padding:0;border:1px solid #dce4ee;border-radius:18px;color:#15223a;box-shadow:0 20px 90px #0c1e3f40}
    #buyer-help::backdrop{background:rgba(12,27,48,.55)}
    .help-header{position:sticky;top:0;z-index:1;display:flex;justify-content:space-between;align-items:center;gap:16px;padding:22px 28px;background:white;border-bottom:1px solid #e2e8f0}
    .help-header h2{margin:0 0 3px}.help-header button{flex-shrink:0}.help-content{padding:8px 28px 28px}.help-content h3{margin-top:28px;color:#214f94}.help-content li{margin-bottom:10px}.help-content code{overflow-wrap:anywhere}
    body.help-visible{overflow:hidden}@media(max-width:560px){.help-fab{right:16px;bottom:16px}.help-header{padding:16px}.help-content{padding:6px 18px 22px}}

    .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
    .sort-heading{display:inline-flex;gap:8px;align-items:center;color:inherit;white-space:nowrap}.sort-heading:hover{color:#265edb}.sort-heading span{font-size:15px;color:#8fa0b9}th[aria-sort=ascending] span,th[aria-sort=descending] span{color:#265edb}
    .settings-link{display:inline-flex;padding:7px;border-radius:8px;color:#5c7191}.settings-link:hover{background:#eaf0fc;color:#265edb}.settings-link svg{width:19px;height:19px}
    .field-help{display:inline-flex;vertical-align:middle;padding:2px;margin-left:4px;background:none;color:#8293ae;border-radius:50%;line-height:1}.field-help svg{width:15px;height:15px}.field-help:hover,.field-help:focus-visible{color:#265edb;background:#eaf0fc}
    #field-tooltip{position:fixed;z-index:1000;width:min(360px,calc(100vw - 24px));padding:15px 17px;background:#152b48;color:white;border-radius:12px;font:14px/1.55 system-ui;box-shadow:0 8px 28px #15223a35;pointer-events:auto}
    </style><?php if(!$auth):?><main class="login"><div class="card"><div class="brand">Lead <span>Bridge</span></div><p class="muted">Управление отправкой лидов</p><h1>Вход в панель</h1><?php if($error)echo '<div class="error">'.h($error).'</div>';?><form method="post"><?=csrf()?><input type="hidden" name="op" value="login"><?php input('login','Логин');input('password','Пароль','','password');?><button>Войти</button></form></div></main></html><?php return;endif;?>
    <aside><div class="brand">Lead <span>Bridge</span></div><div class="sub">INSTANT FORMS · CONTROL PANEL</div><nav><?php foreach($titles as $k=>$v)echo '<a class="'.($page===$k?'active':'').'" href="?page='.$k.'">'.h($v).'</a>';?></nav><div class="foot">v<?=BRIDGE_VERSION?><br>Время в журнале — UTC</div></aside>
    <main><div class="top"><div><h1><?=h($titles[$page])?></h1><div class="muted"><?=h(currentUser()['name'])?> · <?=isAdmin()?'Администратор':'Баер'?></div></div><form method="post"><?=csrf()?><input type="hidden" name="op" value="logout"><button class="secondary">Выйти</button></form></div>
    <?php if($error)echo '<div class="error">'.h($error).'</div>';if($notice)echo '<div class="success">'.h($notice).'</div>';
    if($page==='overview'){
        [$scope,$params]=leadScope();$counts=[];foreach(sql('SELECT state,count(*) n FROM leads WHERE '.$scope.' GROUP BY state',$params) as $c)$counts[$c['state']]=(int)$c['n'];$pending=($counts['queued']??0)+($counts['click_ready']??0)+($counts['binom_pending']??0)+($counts['partner_pending']??0);$review=($counts['binom_review']??0)+($counts['partner_review']??0);
        echo '<div class="stats">';foreach(['Всего лидов'=>array_sum($counts),'Принято ПП'=>$counts['sent']??0,'В обработке'=>$pending,'Нужна проверка'=>$review] as $k=>$n)echo '<div class="card stat"><span class="muted">'.h($k).'</span><strong>'.$n.'</strong></div>';echo '</div>';
        $alive=time()-(int)setting('worker_heartbeat')<120;echo '<div class="card"><h2>Обработка лидов</h2><span class="badge '.($alive?'good':'warn').'">'.($alive?'Обработчик работает':'Нет свежего сигнала обработчика').'</span><p class="muted">Таблица → сохранение в очередь → клик в Binom → отправка в партнёрку. SENT означает подтверждённый приём, а не апрув.</p></div>';
        echo '<div class="card"><h2>Управление связками</h2><p>Добавьте трекер и аккаунт Lemonad, затем создайте связку с ключом кампании и ID оффера. Настройки таблицы доступны внутри связки.</p><div class="actions"><a class="button" href="?page=tracker&new=1">Добавить трекер</a><a class="button secondary" href="?page=route&new=1">Создать связку</a></div></div>';
    }elseif(in_array($page,['tracker','partner','route'],true)){
        $all=uiEntities($page);$edit=clean($_GET['edit']??'',80);$v=$edit?($all[$edit]??[]):[];$form=isset($_GET['new'])||$edit!=='';
        if(!$form){
            $showOwner=isAdmin();$all=sortedEntities($all,$page,$showOwner);$sortColumns=$showOwner?['name','owner','description']:['name','description'];
            echo '<div class="actions" style="margin-bottom:20px"><a class="button" href="?page='.$page.'&new=1">Добавить</a></div><div class="card scroll"><table><tr>'.sortHeading('name','Название',$sortColumns).($showOwner?sortHeading('owner','Пользователь',$sortColumns):'').sortHeading('description','Параметры',$sortColumns).'<th><span class="sr-only">Настройки</span></th></tr>';
            foreach($all as $id=>$e){
                $desc=entityDescription($page,$e,$id,$showOwner);
                echo '<tr><td>'.h($e['name']).'</td>'.($showOwner?'<td>'.h(buyerLogin(ownerOf($id))).'</td>':'').'<td>'.h($desc).'</td><td><a class="settings-link" aria-label="Настроить '.h($e['name']).'" title="Настроить" href="?page='.$page.'&edit='.h($id).'">'.uiIcon('settings').'</a></td></tr>';
            }
            if(!$all)echo '<tr><td colspan="'.($showOwner?4:3).'" class="muted">Пока нет записей</td></tr>';
            echo '</table></div>';
        }
        else{echo '<div class="card"><h2>'.($edit?'Редактирование':'Новая запись').'</h2><form method="post">'.csrf().'<input type="hidden" name="op" value="save"><input type="hidden" name="kind" value="'.$page.'"><input type="hidden" name="id" value="'.h($edit).'">';input('name','Название',$v['name']??'');if(isAdmin()){if(!$edit)options('owner','Владелец',accountOptions(),currentUser()['id']);else echo '<p class="muted">Владелец: '.h(accountOptions()[ownerOf($edit)]??'').'</p>';}
            if($page==='tracker'){options('version','Версия',['v1'=>'Binom v1','v2'=>'Binom v2'],$v['version']??'v1');input('click_url','Полный Click URL без параметров (например https://tracker.com/click.php)',$v['click_url']??'','url');input('api_key','API key — оставьте пустым, чтобы сохранить текущий','','password',!$edit);echo '<p class="muted">Для v1: используется clickid из ответа Click API; старый параметр binom_click_id в URL оффера также поддерживается. Для v2: обычно используется /click. Ключ для v1: Settings → API; для v2: API_KEY из Settings → Click API. Проверка связки создаёт технический клик без отправки в ПП.</p>';}
            elseif($page==='partner'){echo '<p class="muted">Интеграция Lemonad · sendmelead.com</p>';input('token','Токен аккаунта — пустое поле сохраняет текущий','','password',!$edit);}
            else{echo '<p class="muted">Buyer ID определяется автоматически по логину владельца'.($edit?': <strong>'.h(buyerLogin(ownerOf($edit))).'</strong>':' после сохранения').'.</p>';options('tracker','Трекер',uiEntities('tracker'),$v['tracker']??'');options('partner','Аккаунт ПП',uiEntities('partner'),$v['partner']??'');echo '<div class="grid">';input('campaign_key','Ключ кампании Binom',$v['campaign_key']??'');input('offer_id','ID оффера Lemonad',$v['offer_id']??'');echo '</div>';mappingForm($v,!empty($v['tracker'])?uiEntity($v['tracker'],'tracker')['version']:'v2');echo '<label><input type="checkbox" name="active" '.(!empty($v['active'])?'checked':'').'>Связка активна'.fieldHelp('active','Связка активна').'</label>';}
            echo '<div class="actions"><button>Сохранить</button><a href="?page='.$page.'">К списку</a></div></form></div>';
            if($page==='partner'&&$edit)postbackGuide($edit);
            if($page==='route'&&$edit){
                echo '<div class="card"><details><summary>Проверка трекера</summary><p>Создаёт один технический клик с тестовыми метками. Заявка в партнёрку не отправляется.</p><form method="post">'.csrf().'<input type="hidden" name="op" value="diagnose"><input type="hidden" name="id" value="'.h($edit).'"><button class="secondary">Проверить: создать тестовый клик</button></form>';
                if(!empty($v['diagnostic']))echo '<pre>'.h(json_encode($v['diagnostic'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre>';echo '</details></div>';
                echo '<div class="card"><h2>Статусы ПП → таблица → TikTok</h2><p>Один глобальный постбэк на весь аккаунт Lemonad. <a href="?page=partner&amp;edit='.h($v['partner']).'">Открыть URL и инструкцию в настройках партнёрки →</a></p></div>';$conf=['BRIDGE_URL'=>setting('base_url').'/action.php','ROUTE_ID'=>$edit,'BRIDGE_SECRET'=>$v['secret']];echo '<div class="card">';connectionGuide($conf);echo '<p class="muted">При изменении кампании уже принятые лиды сохраняют прежние настройки.</p><div class="actions"><form method="post">'.csrf().'<input type="hidden" name="op" value="duplicate"><input type="hidden" name="id" value="'.h($edit).'"><button class="secondary">Дублировать как черновик</button></form></div><details><summary>Заменить ключ отправки</summary><p>После замены обновите настройки всех подключённых к этой связке таблиц.</p><form method="post">'.csrf().'<input type="hidden" name="op" value="rotate"><input type="hidden" name="id" value="'.h($edit).'"><button class="danger">Выпустить новый секрет</button></form></details></div>';}
        }
    }elseif($page==='leads'){
        $id=clean($_GET['id']??'',64);
        if($id){$l=uiLead($id);if($l){$p=unseal($l['payload']);$s=unseal($l['snapshot']);echo '<div class="card"><h2>Лид '.h($l['external_id']).'</h2><p>'.h($s['route']['name']).' · <span class="badge">'.h($l['state']).'</span></p><pre>'.h(json_encode(['lead'=>$p,'click_id'=>$l['click_id'],'partner_reference_id'=>$l['partner_id'],'partner_status'=>result($l)['partner_status'],'partner_status_updated'=>result($l)['partner_status_updated'],'message'=>$l['message'],'campaign_key'=>$s['route']['campaign_key'],'offer_id'=>$s['route']['offer_id']],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre>';if(isAdmin()&&$l['response'])echo '<details><summary>Сохранённый ответ сервиса</summary><pre>'.h(json_encode(unseal($l['response']),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre></details>';echo '</div>';
                if(in_array($l['state'],['binom_review','partner_review'])){echo '<div class="card"><h2>Действие после сверки</h2><p>При таймауте сервис мог принять запрос. Сначала проверьте трекер и партнёрку.</p><form method="post">'.csrf().'<input type="hidden" name="op" value="reconcile"><input type="hidden" name="id" value="'.h($id).'">';$choices=['sent'=>'ПП уже приняла — указать номер заявки','resume'=>'Заявки в ПП нет — отправить с проверенным clickid'];if($l['state']==='binom_review')$choices['requeue']='Клика и заявки точно нет — начать заново';options('decision','Результат проверки',$choices);input('reference','Номер заявки или проверенный clickid','','text',false);echo '<label><input type="checkbox" name="verified" value="yes" required>Я сверил результат в трекере и ПП</label><button>Применить</button></form></div>';}}
        }else{$filter=clean($_GET['state']??'',40);$offset=max(0,(int)($_GET['offset']??0));
            $owner=isAdmin()?clean($_GET['owner']??'',80):'';
            $ownerQuery=$owner!==''?'&owner='.rawurlencode($owner):'';$sortQuery=sortQuery();
            if(isAdmin()){
                echo '<form method="get" class="card"><input type="hidden" name="page" value="leads"><input type="hidden" name="state" value="'.h($filter).'"><label>Пользователь<select name="owner" data-user-filter><option value="">Все пользователи</option>';
                $users=accountOptions();
                if($owner!==''&&!isset($users[$owner]))echo '<option value="'.h($owner).'" selected>Пользователь не найден</option>';
                foreach($users as $uid=>$label)echo '<option value="'.h($uid).'" '.($owner===$uid?'selected':'').'>'.h($label).'</option>';
                echo '</select></label>';[$sortKey,$sortDir]=tableSort(['created','external_id','route','state','click_id']);echo '<input type="hidden" name="sort" value="'.h($sortKey).'"><input type="hidden" name="dir" value="'.h($sortDir).'"><noscript><button>Показать</button></noscript></form>';
            }
            echo '<div class="actions" style="margin-bottom:20px"><a class="button secondary" href="?page=leads'.h($ownerQuery.$sortQuery).'">Все</a><a class="button secondary" href="?page=leads&state=review'.h($ownerQuery.$sortQuery).'">Нужна проверка</a><a class="button secondary" href="?page=leads&state=sent'.h($ownerQuery.$sortQuery).'">Принятые</a></div><div class="card scroll"><table><tr>'.sortHeading('created','Время / дата · UTC',['created','external_id','route','state','click_id']).sortHeading('external_id','Lead ID',['created','external_id','route','state','click_id']).sortHeading('route','Связка',['created','external_id','route','state','click_id']).sortHeading('state','Статус',['created','external_id','route','state','click_id']).sortHeading('click_id','Click ID',['created','external_id','route','state','click_id']).'</tr>';$where=$filter==='review'?"WHERE state IN ('binom_review','partner_review')":($filter==='sent'?"WHERE state='sent'":'');[$scope,$params]=leadScope();if($owner!==''){$scope.=" AND route IN (SELECT id FROM entities WHERE kind='route' AND owner=?)";$params[]=$owner;}$where.=($where?' AND ':'WHERE ').$scope;$routes=uiEntities('route');$rows=sql('SELECT * FROM leads '.$where.' ORDER BY '.leadOrder($routes).' LIMIT 50 OFFSET '.$offset,$params)->fetchAll();foreach($rows as $l)echo '<tr><td>'.h(displayDate($l['created'])).'</td><td><a href="?page=leads&id='.h($l['id']).'">'.h($l['external_id']).'</a></td><td>'.h($routes[$l['route']]['name']??$l['route']).'</td><td><span class="badge '.($l['state']==='sent'?'good':(str_contains($l['state'],'review')?'warn':'')).'">'.h($l['state']).'</span></td><td>'.h($l['click_id']).'</td></tr>';if(!$rows)echo '<tr><td colspan="5" class="muted">Лидов пока нет</td></tr>';echo '</table></div><div class="actions">';if($offset)echo '<a href="?page=leads&state='.h($filter).h($ownerQuery.$sortQuery).'&offset='.max(0,$offset-50).'">← Назад</a>';if(count($rows)===50)echo '<a href="?page=leads&state='.h($filter).h($ownerQuery.$sortQuery).'&offset='.($offset+50).'">Далее →</a>';echo '</div>';}
    }elseif($page==='setup'){echo '<div class="card">';connectionGuide();echo '</div><div class="card"><h2>Как читать статусы</h2><p>QUEUED — сохранено на сервере; SENT — ПП подтвердила приём; REVIEW — нужна проверка; RETRY — повторно сверить заявку с тем же Lead ID. После разрыва соединения повтор с тем же ID не создаёт новую заявку.</p><p>Обязательные колонки: Lead ID (или TikTok Lead ID), Phone. Метки: Campaign ID, Ad ID, Ad Group ID, Advertiser ID, Form ID. ID и телефон должны поступать текстом.</p><p>При сортировке таблицы выполните «Пересканировать строки». Скрипт работает с добавлением строк; старые SENT не отправляются повторно.</p></div>';}
    elseif($page==='users'){accountsPage();}
    elseif($page==='settings'){
        echo '<div class="card"><h2>Мой пароль</h2><form method="post">'.csrf().'<input type="hidden" name="op" value="password">';input('current_password','Текущий пароль','','password');input('password','Новый пароль, 14–72 символа','','password');echo '<button>Изменить пароль</button></form></div>';
        if(isAdmin()){echo '<div class="card"><h2>Установка</h2><p>Адрес: '.h(setting('base_url')).'</p><p class="muted">Резервные копии хранятся вне сайта. Для переноса нужны база и ключ шифрования.</p></div><div class="card scroll"><h2>Последние действия</h2><table>';foreach(sql('SELECT audit.*,coalesce(users.login,audit.actor) AS actor_login FROM audit LEFT JOIN users ON users.id=audit.actor ORDER BY audit.id DESC LIMIT 30') as $a)echo '<tr><td>'.h(displayDate($a['created'])).'</td><td>'.h($a['actor_login']).'</td><td>'.h($a['event']).'</td><td>'.h($a['subject']).'</td></tr>';echo '</table></div>';}
    }
    ?></main><div id="field-tooltip" role="tooltip" hidden></div><?php buyerManual();?><script nonce="<?=h($GLOBALS['bridge_nonce'])?>">
    var tip=document.getElementById('field-tooltip'),tipOwner=null,tipTimer;
    function closeTip(){clearTimeout(tipTimer);tip.hidden=true;if(tipOwner)tipOwner.removeAttribute('aria-describedby');tipOwner=null;}
    function showTip(button){clearTimeout(tipTimer);if(tipOwner&&tipOwner!==button)tipOwner.removeAttribute('aria-describedby');tipOwner=button;tip.textContent=button.dataset.tip;tip.hidden=false;button.setAttribute('aria-describedby','field-tooltip');var r=button.getBoundingClientRect(),w=tip.offsetWidth,h=tip.offsetHeight;tip.style.left=Math.max(12,Math.min(r.left,innerWidth-w-12))+'px';tip.style.top=Math.max(12,r.bottom+h+10<innerHeight?r.bottom+8:r.top-h-8)+'px';}
    document.querySelectorAll('[data-tip]').forEach(function(button){button.addEventListener('mouseenter',function(){showTip(button);});button.addEventListener('focus',function(){showTip(button);});button.addEventListener('mouseleave',function(){tipTimer=setTimeout(closeTip,120);});button.addEventListener('blur',closeTip);button.addEventListener('click',function(event){event.preventDefault();event.stopPropagation();showTip(button);});});
    tip.addEventListener('mouseenter',function(){clearTimeout(tipTimer);});tip.addEventListener('mouseleave',closeTip);document.addEventListener('keydown',function(event){if(event.key==='Escape')closeTip();});window.addEventListener('resize',closeTip);window.addEventListener('scroll',closeTip,true);document.addEventListener('click',function(event){if(!event.target.closest('[data-tip]')&&!tip.contains(event.target))closeTip();});
    var helpDialog=document.getElementById('buyer-help'),helpOpen=document.getElementById('help-open');
    helpOpen.addEventListener('click',function(){helpDialog.showModal();helpDialog.scrollTop=0;document.body.classList.add('help-visible');});
    document.getElementById('help-close').addEventListener('click',function(){helpDialog.close();});
    helpDialog.addEventListener('close',function(){document.body.classList.remove('help-visible');helpOpen.focus();});
    helpDialog.addEventListener('click',function(event){if(event.target!==helpDialog)return;var box=helpDialog.getBoundingClientRect();if(event.clientX<box.left||event.clientX>box.right||event.clientY<box.top||event.clientY>box.bottom)helpDialog.close();});
    document.querySelectorAll('[data-user-filter]').forEach(function(select){select.addEventListener('change',function(){select.form.requestSubmit();});});
    function feedback(button,label,success){
        clearTimeout(button.feedbackTimer);
        button.textContent=label;
        button.classList.toggle('copy-done',success);
        button.feedbackTimer=setTimeout(function(){button.textContent=button.originalLabel;button.classList.remove('copy-done');},2000);
    }
    document.querySelectorAll('[data-copy],[data-download]').forEach(function(button){
        button.originalLabel=button.textContent;
        button.style.minWidth=Math.ceil(button.getBoundingClientRect().width)+'px';
        button.addEventListener('click',function(){
            button.classList.remove('button-wave');void button.offsetWidth;button.classList.add('button-wave');
            clearTimeout(button.waveTimer);button.waveTimer=setTimeout(function(){button.classList.remove('button-wave');},800);
            if(button.hasAttribute('data-download'))feedback(button,'Скачивание…',false);
        });
    });
    document.querySelectorAll('[data-copy]').forEach(function(button){
        button.addEventListener('click',async function(){
            var source=document.getElementById(button.dataset.copy), text=source.tagName==='TEXTAREA'?source.value:source.textContent;
            var status=document.getElementById('copy-status');
            try {await navigator.clipboard.writeText(text);status.textContent='Скопировано: '+button.originalLabel.replace(/^Скопировать /,'')+'.';feedback(button,'✓ Скопировано',true);}
            catch(error){source.hidden=false;if(source.tagName==='TEXTAREA'){source.focus();source.select();}else{var range=document.createRange();range.selectNodeContents(source);var selection=window.getSelection();selection.removeAllRanges();selection.addRange(range);}feedback(button,'Нажмите Ctrl+C',false);status.textContent='Браузер не разрешил автоматическое копирование. Текст выделен — нажмите Ctrl+C (на Mac — Cmd+C).';}
        });
    });
    </script></html><?php
}
function main(): void {
    umask(0077);
    if(PHP_SAPI==='cli'){
        global $argv;$cmd=$argv[1]??'';
        if($cmd==='init'){
            if(!is_dir(home()))mkdir(home(),0700,true);if(!is_file(home().'/master.key'))file_put_contents(home().'/master.key',random_bytes(32));
            if(setting('password')||sql('SELECT count(*) FROM users')->fetchColumn())throw new RuntimeException('Already initialized');$p=getenv('BRIDGE_ADMIN_PASSWORD');if(!$p||strlen($p)<14)throw new RuntimeException('Provide BRIDGE_ADMIN_PASSWORD, 14+ characters');
            setting('password',password_hash($p,PASSWORD_DEFAULT));setting('session_epoch',bin2hex(random_bytes(16)));setting('base_url',rtrim(publicUrl(getenv('BRIDGE_URL')?:''),'/'));migrateAccounts();echo "Initialized\n";
        }elseif($cmd==='migrate'){migrateAccounts();migrateBuyerLogins();echo "Accounts ready\n";}elseif($cmd==='worker'){worker(in_array('--once',$argv,true));}else{fwrite(STDERR,"Commands: init, worker [--once]\n");exit(1);}return;
    }
    $GLOBALS['bridge_nonce']=base64_encode(random_bytes(18));
    header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header("Content-Security-Policy: default-src 'none'; script-src 'nonce-{$GLOBALS['bridge_nonce']}'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");header('X-Frame-Options: DENY');
    if(isset($_GET['postback']))postback();
    if(isset($_GET['health']))jsonReply(['status'=>'online','version'=>BRIDGE_VERSION]);
    if($_SERVER['REQUEST_METHOD']==='POST'&&str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json'))api();
    if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'])){http_response_code(405);return;}panel();
}
if(!defined('BRIDGE_TEST'))main();

