<?php
// Server-side Sheets connector. Credentials and source settings are encrypted at rest.
const GOOGLE_FILE_SCOPE='https://www.googleapis.com/auth/drive.file';
const GOOGLE_COLUMNS=['Webhook_Status','Binom Click ID','Partner Reference ID','Webhook_Message','Bridge Receipt','TikTok Lead Status','Partner Status Updated','Bridge Warning'];
const GOOGLE_INPUT_COLUMNS=['Lead ID','Name','Phone','Campaign ID','Ad Group ID','Ad ID','Advertiser ID','Client IP','Placement','Campaign Name','Ad Group Name','Ad Name','ADID_V2','ADID_V2_NAME'];
function googleSchema(): void {
    db()->exec('CREATE TABLE IF NOT EXISTS google_accounts (owner TEXT PRIMARY KEY,data TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS sheet_sources (id TEXT PRIMARY KEY,owner TEXT NOT NULL,spreadsheet TEXT NOT NULL,sheet TEXT NOT NULL,route TEXT NOT NULL,data TEXT NOT NULL,active INTEGER NOT NULL DEFAULT 0,next_at INTEGER NOT NULL DEFAULT 0,checked TEXT NOT NULL DEFAULT "",error TEXT NOT NULL DEFAULT "",UNIQUE(spreadsheet,sheet));
      CREATE TABLE IF NOT EXISTS sheet_creations (owner TEXT NOT NULL,route TEXT NOT NULL,phase TEXT NOT NULL,data TEXT NOT NULL,PRIMARY KEY(owner,route));');
}
function googleConfig(): array {return setting('google_config')?unseal(setting('google_config')):[];}
function googleReady(): bool {$c=googleConfig();return !empty($c['client_id'])&&!empty($c['client_secret'])&&!empty($c['picker_key'])&&!empty($c['project']);}
function googleCallbackUrl(): string {return rtrim(setting('base_url'),'/').'/?google=callback';}
function googleErrorMessage(int $code,array $data,string $url): string {
    // Only known reason codes/service names may leave the provider response.
    // Its free-text message, metadata, tokens and file/user details remain private.
    $error=$data['error']??null;$reasons=[];$service='Google API';
    if(is_array($error)){
        foreach(array_merge(is_array($error['details']??null)?$error['details']:[],is_array($error['errors']??null)?$error['errors']:[]) as $item){
            if(!is_array($item))continue;if(is_string($item['reason']??null))$reasons[]=$item['reason'];
            $known=['sheets.googleapis.com'=>'Google Sheets API','drive.googleapis.com'=>'Google Drive API','picker.googleapis.com'=>'Google Picker API'];
            $metadata=is_array($item['metadata']??null)?$item['metadata']:[];$s=$metadata['service']??'';if(is_string($s)&&isset($known[$s]))$service=$known[$s];
        }
    }elseif($error==='invalid_grant')return 'Google: разрешение личного Google-аккаунта баера истекло или отозвано. Нажмите «Переподключить мой Google».';
    elseif($error==='invalid_client')return 'Google: не приняты реквизиты общего приложения Lead Bridge. Администратору нужно проверить Client ID и Client secret; смена Google-аккаунта баера это не исправит.';
    if($service==='Google API'&&str_starts_with($url,'https://sheets.googleapis.com/'))$service='Google Sheets API';
    if(array_intersect($reasons,['SERVICE_DISABLED','accessNotConfigured']))return 'Google: '.$service.' не включён в общем Google Cloud проекте Lead Bridge (HTTP '.$code.'). Администратору нужно открыть APIs & Services → Library и включить этот API. Переподключение Google баера не заменяет эту настройку.';
    if(array_intersect($reasons,['ACCESS_TOKEN_SCOPE_INSUFFICIENT','insufficientPermissions']))return 'Google: личному Google-аккаунту баера не выдано нужное разрешение (HTTP '.$code.'). Нажмите «Переподключить мой Google» и предоставьте доступ к файлам Lead Bridge (drive.file).';
    if(array_intersect($reasons,['RATE_LIMIT_EXCEEDED','rateLimitExceeded','userRateLimitExceeded','dailyLimitExceeded'])||$code===429)return 'Google: достигнут лимит API (HTTP '.$code.'). Подождите и повторите действие вручную. Переподключать аккаунт не требуется.';
    if(in_array('storageQuotaExceeded',$reasons,true))return 'Google: в Google Drive баера закончилось свободное место (HTTP '.$code.'). Освободите место или подключите другой свой Google-аккаунт.';
    if(in_array('domainPolicy',$reasons,true))return 'Google: действие запрещено политикой Google Workspace (HTTP '.$code.'). Обратитесь к администратору Google-аккаунта баера.';
    if($code===401)return 'Google: доступ личного Google-аккаунта баера недействителен (HTTP 401). Нажмите «Переподключить мой Google».';
    if($code===403)return 'Google запретил действие (HTTP 403). Используется личный Google-аккаунт баера. Проверьте его права и разрешение drive.file, а также включение Google Sheets API в общем Google Cloud проекте Lead Bridge. При изменении разрешений нажмите «Переподключить мой Google».';
    return 'Google: запрос не подтверждён (HTTP '.$code.'). Если создавали таблицу, проверьте Google Drive перед новой попыткой.';
}
function googleJson(string $url,?array $body=null,?string $token=null,?callable $http=null,bool $form=false): array {
    if($http===null&&defined('BRIDGE_TEST')&&isset($GLOBALS['google_http']))$http=$GLOBALS['google_http'];
    $headers=$token?['Authorization: Bearer '.$token]:[];
    if($body!==null)$headers[]='Content-Type: '.($form?'application/x-www-form-urlencoded':'application/json');
    $r=($http??'httpRequest')($url,$body===null?null:($form?http_build_query($body):json_encode($body,JSON_THROW_ON_ERROR)),$headers);
    $d=json_decode($r['body'],true);
    // Never expose Google's body: it can contain credentials or personal data.
    if($r['error']||$r['code']<200||$r['code']>=300||!is_array($d)||isset($d['error']))throw new InvalidArgumentException(googleErrorMessage((int)$r['code'],is_array($d)?$d:[],$url),(int)$r['code']);
    return $d;
}
function googleSaveAccount(string $owner,array $data): void {googleSchema();sql('INSERT INTO google_accounts VALUES(?,?) ON CONFLICT(owner) DO UPDATE SET data=excluded.data',[$owner,seal($data)]);}
function googleToken(string $owner,?callable $http=null): string {
    googleSchema();bridgeBackground($owner);
    $raw=sql('SELECT data FROM google_accounts WHERE owner=?',[$owner])->fetchColumn();
    if(!$raw)throw new InvalidArgumentException('Сначала подключите Google');$a=unseal($raw);
    if(($a['expires']??0)>time()+60)return $a['access_token'];
    $c=googleConfig();$d=googleJson('https://oauth2.googleapis.com/token',['client_id'=>$c['client_id']??'','client_secret'=>$c['client_secret']??'','refresh_token'=>$a['refresh_token'],'grant_type'=>'refresh_token'],http:$http,form:true);
    if(!is_string($d['access_token']??null)||empty($d['expires_in']))throw new InvalidArgumentException('Google не подтвердил обновление доступа');
    $a['access_token']=$d['access_token'];$a['expires']=time()+min(3600,(int)$d['expires_in']);
    // A concurrent disconnect/reconnect must never be undone by an old refresh response.
    $changed=sql('UPDATE google_accounts SET data=? WHERE owner=? AND data=?',[seal($a),$owner,$raw]);
    if($changed->rowCount()!==1)throw new InvalidArgumentException('Google-подключение изменилось. Обновите страницу.');return $a['access_token'];
}
function googleReturnPath(string $route): string {
    if($route==='')return '?page=setup';$u=requireUser();uiEntity($route,'route');
    if(ownerOf($route)!==$u['id'])throw new InvalidArgumentException('Переподключить Google для связки может только её владелец');
    return '?page=route&edit='.rawurlencode($route);
}
function googleReconnectForm(string $route=''): void {
    echo '<form method="post">'.csrf().'<input type="hidden" name="op" value="google_begin"><input type="hidden" name="route" value="'.h($route).'"><button class="secondary"'.(googleReady()?'':' disabled').'>Переподключить мой Google</button></form>';
}
function googleBegin(): never {
    $u=requireUser();if(!googleReady())throw new InvalidArgumentException('Администратор ещё не настроил подключение Google');
    if(!empty($u['sso_migration']))throw new InvalidArgumentException('Сначала свяжите аккаунт с OpsVault');
    $route=clean($_POST['route']??'',80);googleReturnPath($route);
    $verifier=bin2hex(random_bytes(32));$state=bin2hex(random_bytes(32));$c=googleConfig();
    $_SESSION['google_attempt']=['state'=>$state,'verifier'=>$verifier,'expires'=>time()+600,'owner'=>$u['id'],'config'=>seal($c),'route'=>$route];
    redirect('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id'=>$c['client_id'],'redirect_uri'=>googleCallbackUrl(),'response_type'=>'code','scope'=>GOOGLE_FILE_SCOPE,'access_type'=>'offline','prompt'=>'consent select_account','state'=>$state,'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256']));
}
function googleFinish(?callable $http=null): string {
    $u=requireUser();$a=$_SESSION['google_attempt']??[];unset($_SESSION['google_attempt']);
    if(!empty($u['sso_migration']))throw new InvalidArgumentException('Сначала свяжите аккаунт с OpsVault');
    if(($a['expires']??0)<time()||($a['owner']??'')!==$u['id']||!is_string($_GET['state']??null)||!hash_equals($a['state']??'',$_GET['state']))throw new InvalidArgumentException('Подключение Google истекло. Начните заново.');
    $code=required($_GET,'code',4096);$c=unseal($a['config']);
    $d=googleJson('https://oauth2.googleapis.com/token',['code'=>$code,'client_id'=>$c['client_id'],'client_secret'=>$c['client_secret'],'redirect_uri'=>googleCallbackUrl(),'grant_type'=>'authorization_code','code_verifier'=>$a['verifier']],http:$http,form:true);
    if(!is_string($d['access_token']??null)||!is_string($d['refresh_token']??null)||empty($d['expires_in'])||!in_array(GOOGLE_FILE_SCOPE,explode(' ',$d['scope']??''),true))throw new InvalidArgumentException('Google не предоставил постоянный доступ к выбранным файлам. Подключитесь заново.');
    googleSaveAccount($u['id'],['access_token'=>$d['access_token'],'refresh_token'=>$d['refresh_token'],'expires'=>time()+min(3600,(int)$d['expires_in'])]);audit('google_connected');
    try{return googleReturnPath($a['route']??'');}catch(InvalidArgumentException $e){return '?page=setup';}
}
function googleLanding(): never {
    // Commit an own-origin document before returning to the Strict panel session.
    $args=['page'=>'setup','google'=>'finish'];foreach(['state','code','error'] as $k)if(is_string($_GET[$k]??null)&&strlen($_GET[$k])<=4096)$args[$k]=$_GET[$k];
    $url='?'.http_build_query($args);
    echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Подключение Google</title><p><a href="'.h($url).'">Продолжить в Lead Bridge</a></p><script nonce="'.h($GLOBALS['bridge_nonce']).'">location.replace('.json_encode($url,JSON_HEX_TAG|JSON_HEX_AMP).')</script></html>';exit;
}
function googleApi(string $owner,string $spreadsheet,string $suffix='',?array $body=null,?callable $http=null): array {
    if(!preg_match('/^[a-zA-Z0-9_-]{10,150}$/D',$spreadsheet))throw new InvalidArgumentException('Некорректная Google-таблица');
    return googleJson('https://sheets.googleapis.com/v4/spreadsheets/'.$spreadsheet.$suffix,$body,googleToken($owner,$http),$http);
}
function googleTabs(string $owner,string $spreadsheet,?callable $http=null): array {
    $d=googleApi($owner,$spreadsheet,'?fields=spreadsheetId,properties(title),sheets(properties(sheetId,title,gridProperties))',http:$http);
    if(($d['spreadsheetId']??'')!==$spreadsheet||!isset($d['sheets'],$d['properties']['title']))throw new InvalidArgumentException('Google не вернул структуру таблицы');return $d;
}
function googleTab(array $book,string $sheet): array {foreach($book['sheets'] as $s)if((string)$s['properties']['sheetId']===$sheet)return $s['properties'];throw new InvalidArgumentException('Выбранный лист не найден');}
function googleCell(array $cell): mixed {
    if(isset($cell['userEnteredValue']['formulaValue']))throw new InvalidArgumentException('Формулы в исходных полях не поддерживаются');
    $v=$cell['effectiveValue']??[];if(isset($v['errorValue']))throw new InvalidArgumentException('Ошибка в ячейке Google');
    return $v['stringValue']??$v['numberValue']??$v['boolValue']??'';
}
function googleRows(string $owner,string $book,string $sheet,int $start,int $end,?callable $http=null): array {
    $d=googleApi($owner,$book,':getByDataFilter?fields=sheets(properties(sheetId),data(startRow,rowData(values(userEnteredValue,effectiveValue))))',['dataFilters'=>[['gridRange'=>['sheetId'=>(int)$sheet,'startRowIndex'=>$start,'endRowIndex'=>$end]]],'includeGridData'=>true],$http);
    $s=$d['sheets'][0]??[];if((string)($s['properties']['sheetId']??'')!==$sheet)throw new InvalidArgumentException('Google не подтвердил выбранный лист');
    return $s['data'][0]['rowData']??[];
}
function googleHeaderKey(string $s): string {return strtolower(preg_replace('/[ _-]/','',trim($s)));}
function googleHeaders(array $row): array {
    $h=[];foreach($row['values']??[] as $cell)$h[]=trim((string)googleCell($cell));
    $keys=array_map('googleHeaderKey',$h);if(count(array_filter($keys))!==count(array_unique(array_filter($keys))))throw new InvalidArgumentException('В шапке повторяются названия колонок');
    if(!in_array('phone',$keys,true)||(!in_array('leadid',$keys,true)&&!in_array('tiktokleadid',$keys,true)))throw new InvalidArgumentException('Нужны колонки Lead ID (или TikTok Lead ID) и Phone');return $h;
}
function googleValue(array $h,array $row,array $names,bool $id=false): string {
    $accepted=array_map('googleHeaderKey',$names);$found=[];
    foreach($h as $i=>$name)if(in_array(googleHeaderKey($name),$accepted,true)){
        $v=googleCell($row['values'][$i]??[]);if($v==='')continue;
        if($id&&(!is_string($v)||!preg_match('/^[0-9]{1,40}$/D',trim($v))))throw new InvalidArgumentException($name.': ID должен поступать точной строкой цифр');
        $v=trim((string)$v);if($v!=='')$found[$v]=true;
    }
    if(count($found)>1)throw new InvalidArgumentException('Разные значения в колонках '.implode(' / ',$names));return (string)(array_key_first($found)??'');
}
function googlePayload(array $h,array $row): array {
    $p=['lead_id'=>googleValue($h,$row,['Lead ID','TikTok Lead ID'],true),'phone'=>googleValue($h,$row,['Phone']),'name'=>googleValue($h,$row,['Name'])?:'Customer'];
    if(preg_match('/[eE]/',$p['phone']))throw new InvalidArgumentException('Телефон повреждён');
    $p['phone']='+'.preg_replace('/^00/','',preg_replace('/[^0-9]/','',$p['phone']));
    foreach(['campaign_id'=>['Campaign ID'],'adgroup_id'=>['Ad Group ID','Adgroup ID','Adset ID'],'ad_id'=>['Ad ID'],'advertiser_id'=>['Advertiser ID'],'adid_v2'=>['ADID_V2']] as $k=>$names){$v=googleValue($h,$row,$names,true);if($v!=='')$p[$k]=$v;}
    foreach(['ip'=>['Client IP','IP Address','IP'],'placement'=>['Placement'],'campaign_name'=>['Campaign Name'],'adgroup_name'=>['Ad Group Name','Adgroup Name','Adset Name','AID_NAME'],'ad_name'=>['Ad Name','CID_NAME'],'adid_v2_name'=>['ADID_V2_NAME']] as $k=>$names){$v=googleValue($h,$row,$names);if($v!=='')$p[$k]=$v;}
    $warning='';try{$names=['Form ID text','Form ID text test'];$hasText=googleValue($h,$row,$names)!=='';$v=googleValue($h,$row,$hasText?$names:['Form ID'],true);if($v!=='')$p['form_id']=$v;}catch(InvalidArgumentException $e){$warning='Form ID не передан: значение повреждено или неоднозначно';}
    return [normalize($p),$warning];
}
function googleWrite(string $owner,string $book,string $sheet,array $updates,?callable $http=null): void {
    if(!$updates)return;$requests=[];
    foreach($updates as [$row,$col,$value])$requests[]=['updateCells'=>['range'=>['sheetId'=>(int)$sheet,'startRowIndex'=>$row,'endRowIndex'=>$row+1,'startColumnIndex'=>$col,'endColumnIndex'=>$col+1],'rows'=>[['values'=>[['userEnteredValue'=>['stringValue'=>(string)$value]]]]],'fields'=>'userEnteredValue']];
    $d=googleApi($owner,$book,':batchUpdate',['requests'=>$requests],$http);
    if(($d['spreadsheetId']??'')!==$book||count($d['replies']??[])!==count($requests))throw new InvalidArgumentException('Google не подтвердил запись статусов');
}
function googleSource(string $id): array {
    googleSchema();$u=requireUser();$s=sql('SELECT * FROM sheet_sources WHERE id=?',[$id])->fetch();
    if(!$s||(!isAdmin()&&$s['owner']!==$u['id']))throw new InvalidArgumentException('Подключение недоступно');return $s;
}
function googleCreateForRoute(string $routeId,?callable $http=null): string {
    googleSchema();$u=requireUser();$route=uiEntity($routeId,'route');$owner=$u['id'];
    if(ownerOf($routeId)!==$owner)throw new InvalidArgumentException('Создать таблицу может только владелец связки в своём Google-аккаунте');
    bridgeBackground($owner);$id='sheet_'.substr(hash('sha256','create|'.$owner.'|'.$routeId),0,24);
    $existing=sql('SELECT id FROM sheet_sources WHERE id=? AND owner=? AND route=?',[$id,$owner,$routeId])->fetchColumn();if($existing)return $existing;
    $token=googleToken($owner,$http); // Fail missing authorization before claiming the external operation.
    $claim=sql('INSERT OR IGNORE INTO sheet_creations VALUES(?,?,?,?)',[$owner,$routeId,'pending',seal(['title'=>$route['name']])]);$send=$claim->rowCount()===1;
    if(!$send)$send=sql('UPDATE sheet_creations SET phase="pending" WHERE owner=? AND route=? AND phase="failed"',[$owner,$routeId])->rowCount()===1;
    $op=sql('SELECT * FROM sheet_creations WHERE owner=? AND route=?',[$owner,$routeId])->fetch();$data=unseal($op['data']);
    if($send){
        try{
            $d=googleJson('https://sheets.googleapis.com/v4/spreadsheets',['properties'=>['title'=>$data['title'],'timeZone'=>'Europe/Moscow'],'sheets'=>[['properties'=>['sheetId'=>0,'title'=>'Лиды','gridProperties'=>['rowCount'=>1000,'columnCount'=>count(GOOGLE_INPUT_COLUMNS)+count(GOOGLE_COLUMNS),'frozenRowCount'=>1]]]]],$token,$http);
        }catch(InvalidArgumentException $e){
            // Only explicit rejection can be retried manually. Timeouts/5xx/unknown
            // bodies may have created a file, so the durable pending marker remains.
            if(in_array($e->getCode(),[400,401,403,404,422,429],true))sql('UPDATE sheet_creations SET phase="failed" WHERE owner=? AND route=?',[$owner,$routeId]);
            throw $e;
        }
        $book=$d['spreadsheetId']??'';$sheet=$d['sheets'][0]['properties']['sheetId']??null;
        if(!is_string($book)||!preg_match('/^[a-zA-Z0-9_-]{10,150}$/D',$book)||$sheet!==0)throw new InvalidArgumentException('Создание таблицы не подтверждено. Проверьте Google Drive; автоматически повторять создание нельзя.');
        $data['book']=$book;sql('UPDATE sheet_creations SET phase="created",data=? WHERE owner=? AND route=?',[seal($data),$owner,$routeId]);
    }elseif($op['phase']==='pending')throw new InvalidArgumentException('Предыдущее создание не подтверждено. Проверьте Google Drive: если таблица появилась, подключите её как существующую. Новая копия автоматически не создаётся.');
    $book=$data['book'];$h=array_merge(GOOGLE_INPUT_COLUMNS,GOOGLE_COLUMNS);
    // A known created file can resume initialization, but never overwrite user history.
    $rows=googleRows($owner,$book,'0',0,1000,$http);
    foreach(array_slice($rows,1) as $row)foreach($row['values']??[] as $cell)if(googleCell($cell)!=='')throw new InvalidArgumentException('В созданной таблице уже есть данные. Подключите её как существующую, без повторной подготовки.');
    if(!empty($rows[0]['values'])&&googleHeaders($rows[0])!==$h)throw new InvalidArgumentException('Шапка созданной таблицы изменилась. Подключите её как существующую.');
    $cells=array_map(fn($name)=>['userEnteredValue'=>['stringValue'=>$name],'userEnteredFormat'=>['textFormat'=>['bold'=>true],'backgroundColor'=>['red'=>0.9,'green'=>0.94,'blue'=>1]]],$h);
    $requests=[['updateCells'=>['range'=>['sheetId'=>0,'startRowIndex'=>0,'endRowIndex'=>1,'startColumnIndex'=>0,'endColumnIndex'=>count($h)],'rows'=>[['values'=>$cells]],'fields'=>'userEnteredValue,userEnteredFormat']]];
    foreach(['Lead ID','Phone','Campaign ID','Ad Group ID','Ad ID','Advertiser ID','ADID_V2'] as $name){$col=array_search($name,$h,true);$requests[]=['repeatCell'=>['range'=>['sheetId'=>0,'startRowIndex'=>1,'endRowIndex'=>1000,'startColumnIndex'=>$col,'endColumnIndex'=>$col+1],'cell'=>['userEnteredFormat'=>['numberFormat'=>['type'=>'TEXT']]],'fields'=>'userEnteredFormat.numberFormat']];}
    $d=googleApi($owner,$book,':batchUpdate',['requests'=>$requests],$http);
    if(($d['spreadsheetId']??'')!==$book||count($d['replies']??[])!==count($requests))throw new InvalidArgumentException('Google не подтвердил подготовку. Повторное нажатие продолжит подготовку той же таблицы.');
    // Recheck ownership after external I/O; no incoming leads or clicks are created.
    requireUser();bridgeBackground($owner);if(ownerOf($routeId)!==$owner)throw new InvalidArgumentException('Владелец связки изменился. Подключение не сохранено.');
    sql('INSERT OR IGNORE INTO sheet_sources(id,owner,spreadsheet,sheet,route,data) VALUES(?,?,?,?,?,?)',[$id,$owner,$book,'0',$routeId,seal(['title'=>'Лиды','skip'=>[],'cursor'=>1])]);
    $saved=sql('SELECT id FROM sheet_sources WHERE owner=? AND spreadsheet=? AND sheet="0" AND route=?',[$owner,$book,$routeId])->fetchColumn();
    if(!$saved)throw new InvalidArgumentException('Таблица уже подключена иначе. Проверьте список подключений.');
    sql('UPDATE sheet_creations SET phase="ready" WHERE owner=? AND route=?',[$owner,$routeId]);audit('sheet_created',$saved);return $saved;
}
function googleCreatedSource(array $s): bool {
    $op=sql('SELECT phase,data FROM sheet_creations WHERE owner=? AND route=?',[$s['owner'],$s['route']])->fetch();
    return $op&&$op['phase']==='ready'&&$s['sheet']==='0'&&(unseal($op['data'])['book']??'')===$s['spreadsheet'];
}
function googleSourceControls(array $s): void {
    $created=googleCreatedSource($s);
    echo '<p role="status">'.($s['active']?'Отправка включена. Сервер забирает строки автоматически.':'Отправка на паузе.').'</p><p class="muted">Проверено: '.h($s['checked']?displayDate($s['checked']):'ещё не проверялось').'</p>';
    if($s['error'])echo '<p role="alert">'.h($s['error']).'</p>';
    echo '<form method="post">'.csrf().'<input type="hidden" name="op" value="google_set_active"><input type="hidden" name="source" value="'.h($s['id']).'"><input type="hidden" name="enabled" value="'.($s['active']?'0':'1').'">';
    if(!$s['active']&&!$created)echo '<label><input type="checkbox" name="old_trigger_disabled" value="1" required> Старый триггер отправки Apps Script отключён, либо его не было</label>';
    echo '<button'.($s['active']?' class="secondary"':'').'>'.($s['active']?'Приостановить':($created?'Добавил таблицу в ТТ — запустить':'Включить отправку')).'</button></form>';
    if(!$s['active'])echo '<p class="muted">После запуска будут обрабатываться новые строки'.($created?', включая уже поступивший тестовый лид TikTok':' согласно выбранному режиму импорта').'. Они могут создать клик Binom и заявку в ПП. Дополнительный триггер Google не нужен.</p>';
    else echo '<p class="muted">Первый проход обычно начинается в течение минуты; очередь таблиц может увеличить ожидание. Обновите страницу, чтобы увидеть время проверки и ошибки. Статусы появятся в служебных колонках таблицы.</p>';
}
function googleRouteControls(string $routeId): void {
    googleSchema();$u=requireUser();uiEntity($routeId,'route');
    echo '<h2>Google-таблица для связки</h2><p><a class="button secondary" href="?page=setup&amp;route='.h($routeId).'">Подключить таблицу автоматически</a></p>';
    if(ownerOf($routeId)!==$u['id']){echo '<p>Новая таблица создаётся владельцем связки в его Google-аккаунте.</p>';return;}
    googleReconnectForm($routeId);echo '<p class="muted">Переподключается ваш личный Google-аккаунт баера. После входа Google вернёт вас в эту связку. Создание таблицы автоматически не повторяется.</p>';
    $op=sql('SELECT * FROM sheet_creations WHERE owner=? AND route=?',[$u['id'],$routeId])->fetch();
    if($op){$d=unseal($op['data']);if(!empty($d['book']))echo '<p><a href="https://docs.google.com/spreadsheets/d/'.h($d['book']).'/edit" target="_blank" rel="noopener noreferrer">Открыть созданную таблицу ↗</a></p>';}
    if($op&&$op['phase']==='ready'){
        $source=sql('SELECT * FROM sheet_sources WHERE owner=? AND route=? AND spreadsheet=? AND sheet="0"',[$u['id'],$routeId,$d['book']??''])->fetch();
        echo '<p>Таблица создана и привязана к этой связке. Выберите её и лист «Лиды» в TikTok, сопоставьте Lead ID, Phone и остальные поля, затем запустите обработку ниже.</p>';
        if($source)googleSourceControls($source);else echo '<p role="alert">Подключение не найдено. Проверьте раздел «Подключение».</p>';return;
    }
    if($op&&$op['phase']==='pending'){echo '<p role="alert">Результат создания не подтверждён. Проверьте Google Drive и подключите появившуюся таблицу как существующую. Автоматического повтора создания нет.</p>';return;}
    $connected=googleReady()&&(bool)sql('SELECT 1 FROM google_accounts WHERE owner=?',[$u['id']])->fetchColumn();
    echo '<form method="post">'.csrf().'<input type="hidden" name="op" value="google_create"><input type="hidden" name="route" value="'.h($routeId).'"><button'.($connected?'':' disabled').'>'.($op&&$op['phase']==='created'?'Завершить подготовку таблицы':'Создать Google-таблицу').'</button></form><p class="muted">Название берётся из связки. Мост создаст лист «Лиды», шапку и служебные колонки и сохранит привязку. Отправка останется на паузе; затем выберите эту таблицу в TikTok.</p>';
    if(!$connected)echo '<p><a href="?page=settings">Сначала подключите свой Google-аккаунт в настройках →</a></p>';
}
function googleConnectSource(string $owner,string $book,string $sheet,string $route,bool $history,?callable $http=null): string {
    googleSchema();if(ownerOf($route)!==$owner)throw new InvalidArgumentException('Таблица и связка должны принадлежать одному баеру');
    $tab=googleTab(googleTabs($owner,$book,$http),$sheet);$rows=googleRows($owner,$book,$sheet,0,1,$http);
    $emptyHeader=empty($rows[0]['values']);$h=$emptyHeader?['Lead ID','Name','Phone','Campaign ID','Ad Group ID','Ad ID','Advertiser ID']:googleHeaders($rows[0]);
    if(sql('SELECT 1 FROM sheet_sources WHERE spreadsheet=? AND sheet=?',[$book,$sheet])->fetchColumn())throw new InvalidArgumentException('Этот лист уже подключён. Его связку нельзя менять; создайте новый лист.');
    $updates=[];if($emptyHeader)foreach($h as $i=>$name)$updates[]=[0,$i,$name];
    foreach(GOOGLE_COLUMNS as $col)if(!in_array(googleHeaderKey($col),array_map('googleHeaderKey',$h),true)){$updates[]=[0,count($h),$col];$h[]=$col;}
    if(count($h)>(int)($tab['gridProperties']['columnCount']??0)){
        googleApi($owner,$book,':batchUpdate',['requests'=>[['appendDimension'=>['sheetId'=>(int)$sheet,'dimension'=>'COLUMNS','length'=>count($h)-(int)$tab['gridProperties']['columnCount']]]]],$http);
    }
    googleWrite($owner,$book,$sheet,$updates,$http);
    $id='sheet_'.bin2hex(random_bytes(12));
    // Default excludes existing IDs, not row positions: TikTok may insert or move rows.
    $skip=[];if(!$history){
        $limit=(int)($tab['gridProperties']['rowCount']??0);
        if($limit>10000)throw new InvalidArgumentException('Для листа более 10 000 строк сначала подготовьте отдельный пустой лист');
        foreach(array_chunk(range(1,max(1,$limit-1)),200) as $chunk){$data=googleRows($owner,$book,$sheet,$chunk[0],end($chunk)+1,$http);foreach($data as $row){try{$v=googleValue($h,$row,['Lead ID','TikTok Lead ID'],true);if($v!=='')$skip[]=hash('sha256',$v);}catch(InvalidArgumentException $e){throw new InvalidArgumentException('В существующих строках есть повреждённый Lead ID. Исправьте его или подключите новый пустой лист.');}}}
    }
    sql('INSERT INTO sheet_sources(id,owner,spreadsheet,sheet,route,data) VALUES(?,?,?,?,?,?)',[$id,$owner,$book,$sheet,$route,seal(['title'=>$tab['title'],'skip'=>$skip,'cursor'=>1])]);
    audit('sheet_connected',$id);return $id;
}
function googleSyncSource(array $s,?callable $http=null): void {
    $deadline=microtime(true)+45;
    bridgeBackground($s['owner']);if(ownerOf($s['route'])!==$s['owner'])throw new InvalidArgumentException('Владелец связки изменился. Подключение остановлено');
    $route=entity($s['route'],'route');$conf=unseal($s['data']);$tab=googleTab(googleTabs($s['owner'],$s['spreadsheet'],$http),$s['sheet']);
    $h=googleHeaders(googleRows($s['owner'],$s['spreadsheet'],$s['sheet'],0,1,$http)[0]??[]);
    foreach(GOOGLE_COLUMNS as $name)if(!in_array(googleHeaderKey($name),array_map('googleHeaderKey',$h),true))throw new InvalidArgumentException('Служебная колонка удалена: '.$name);
    $max=(int)$tab['gridProperties']['rowCount'];$start=(int)($conf['cursor']??1);if($start>=$max)$start=1;
    $rows=googleRows($s['owner'],$s['spreadsheet'],$s['sheet'],$start,min($max,$start+100),$http);
    // Sheets grid size includes empty reserved rows. Return to the beginning at the
    // first empty tail instead of spending minutes scanning an unused grid.
    $written=0;$next=count($rows)<min(100,$max-$start)?1:$start+100;
    foreach($rows as $i=>$row){
        if(microtime(true)>$deadline){$next=$start+$i;break;}
        if(is_file((getenv('BRIDGE_CONTROL')?:home()).'/deploy.pause'))return;
        if(!sql('SELECT 1 FROM sheet_sources WHERE id=? AND active=1',[$s['id']])->fetchColumn())return;
        $n=$start+$i;$writes=[];$put=function($k,$v)use(&$writes,$h,$n){$writes[]=[$n,array_search(googleHeaderKey($k),array_map('googleHeaderKey',$h),true),(string)$v];};
        try{
            $external=googleValue($h,$row,['Lead ID','TikTok Lead ID'],true);if($external==='')continue;
            $receipt=googleValue($h,$row,['Bridge Receipt']);$state=googleValue($h,$row,['Webhook_Status']);
            // Match both owner and route, even when recovering a lost receipt write.
            $l=sql('SELECT l.* FROM leads l JOIN entities e ON e.id=l.route WHERE e.owner=? AND l.route=? AND l.external_id=?',[$s['owner'],$s['route'],$external])->fetch();
            if($receipt&&(!$l||$receipt!==$l['id']))throw new InvalidArgumentException('Квитанция не соответствует Lead ID и связке');
            if(!$l){
                if($state==='SENT'||in_array(hash('sha256',$external),$conf['skip']??[],true))continue;
                if($state!==''&&$state!=='RETRY')continue;
                if(googleValue($h,$row,['Phone'])==='')continue;
                [$p,$warning]=googlePayload($h,$row);$put('Bridge Warning',$warning);
                bridgeBackground($s['owner']);$r=enqueue($s['route'],$route,$p);
            }else $r=result($l); // SENT and uncertain external results are never enqueued again.
            $put('Bridge Receipt',$r['receipt']);$put('Webhook_Status',$r['stage']==='sent'?'SENT':($r['status']==='processing'?'QUEUED':'REVIEW'));
            foreach(['Binom Click ID'=>'click_id','Partner Reference ID'=>'partner_reference_id','Webhook_Message'=>'message','TikTok Lead Status'=>'partner_status','Partner Status Updated'=>'partner_status_updated'] as $k=>$v)$put($k,$r[$v]??'');
        }catch(InvalidArgumentException $e){$put('Webhook_Status','REVIEW');$put('Webhook_Message',$e->getMessage());}
        $writes=array_values(array_filter($writes,fn($w)=>(string)googleCell($row['values'][$w[1]]??[])!==$w[2]));
        if($writes){
            // Recheck row identity immediately before writing, including moved receipts.
            $now=googleRows($s['owner'],$s['spreadsheet'],$s['sheet'],$n,$n+1,$http)[0]??[];
            if(($row['values']??[])!==($now['values']??[]))throw new InvalidArgumentException('Лист изменился во время проверки. Повторное чтение через минуту.');
            googleWrite($s['owner'],$s['spreadsheet'],$s['sheet'],$writes,$http);
            if(++$written>=10){$next=$n+1;break;}
        }
    }
    $conf['cursor']=$next>=$max?1:$next;$conf['title']=$tab['title'];
    sql('UPDATE sheet_sources SET data=?,checked=?,error="",next_at=? WHERE id=?',[seal($conf),gmdate('c'),time()+60,$s['id']]);
}
function googleTick(?callable $http=null): void {
    googleSchema();$s=sql('SELECT * FROM sheet_sources WHERE active=1 AND next_at<=? ORDER BY next_at,id LIMIT 1',[time()])->fetch();if(!$s)return;
    // Share the per-user Sheets quota across all their sources.
    if((int)setting('google_tick_'.$s['owner'])>time()){sql('UPDATE sheet_sources SET next_at=? WHERE id=?',[(int)setting('google_tick_'.$s['owner']),$s['id']]);return;}
    setting('google_tick_'.$s['owner'],(string)(time()+60));
    try{googleSyncSource($s,$http);}catch(Throwable $e){sql('UPDATE sheet_sources SET checked=?,error=?,next_at=? WHERE id=?',[gmdate('c'),$e instanceof InvalidArgumentException?$e->getMessage():'Ошибка синхронизации Google. Повторное чтение через 5 минут.',time()+300,$s['id']]);}
}
function googleWorkerTick(): void {
    $control=getenv('BRIDGE_CONTROL')?:home();if(is_file($control.'/deploy.pause'))return;
    $lock=fopen($control.'/dispatch.lock',getenv('BRIDGE_CONTROL')?'r':'c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Cannot acquire dispatch lock');
    try{if(!is_file($control.'/deploy.pause'))googleTick();}catch(Throwable $e){error_log('Bridge Google polling: '.get_class($e));}finally{flock($lock,LOCK_UN);fclose($lock);}
}
function googlePickerCsp(): string {
    // Google scripts/frames are permitted only on the setup page; other pages retain the original CSP.
    return "Content-Security-Policy: default-src 'none'; script-src 'nonce-".$GLOBALS['bridge_nonce']."' https://apis.google.com https://www.gstatic.com; style-src 'unsafe-inline'; img-src data: https://*.googleusercontent.com https://www.gstatic.com; connect-src https://*.googleapis.com https://apis.google.com https://accounts.google.com; frame-src https://docs.google.com https://drive.google.com https://accounts.google.com; form-action 'self' https://portalwiki.world; frame-ancestors 'none'; base-uri 'none'";
}
function googleMutation(string $op): bool {
    if(!str_starts_with($op,'google_'))return false;$u=requireUser();googleSchema();
    if($op==='google_config'){
        requireAdmin();$c=googleConfig();
        foreach(['client_id','picker_key','project'] as $k)$c[$k]=required($_POST,$k,250);
        if(!preg_match('/^[0-9]+$/D',$c['project'])||!preg_match('/^[a-zA-Z0-9._-]+$/D',$c['client_id'])||!preg_match('/^[a-zA-Z0-9_-]+$/D',$c['picker_key']))throw new InvalidArgumentException('Проверьте настройки Google');
        if(($_POST['client_secret']??'')!=='')$c['client_secret']=required($_POST,'client_secret',500);
        if(empty($c['client_secret']))throw new InvalidArgumentException('Введите Client secret');setting('google_config',seal($c));audit('google_settings');
    }elseif($op==='google_begin')googleBegin();
    elseif($op==='google_create'){
        $routeId=required($_POST,'route',80);googleCreateForRoute($routeId);redirect('?page=route&edit='.rawurlencode($routeId));
    }
    elseif($op==='google_disconnect'){
        sql('UPDATE sheet_sources SET active=0 WHERE owner=?',[$u['id']]);sql('DELETE FROM google_accounts WHERE owner=?',[$u['id']]);unset($_SESSION['google_attempt'],$_SESSION['google_book']);audit('google_disconnected');
    }elseif($op==='google_inspect'){
        $book=required($_POST,'spreadsheet',150);$d=googleTabs($u['id'],$book);$_SESSION['google_book']=['id'=>$book,'book'=>$d];
    }elseif($op==='google_connect'){
        $book=required($_POST,'spreadsheet',150);$route=required($_POST,'route',80);uiEntity($route,'route');
        $id=googleConnectSource($u['id'],$book,required($_POST,'sheet',12),$route,($_POST['history']??'')==='all');unset($_SESSION['google_book']);audit('sheet_prepared',$id);
    }elseif($op==='google_toggle'||$op==='google_set_active'){
        $s=googleSource(required($_POST,'source',80));
        $enabled=$op==='google_toggle'?!$s['active']:required($_POST,'enabled',1);
        if(!in_array($enabled,[true,false,'0','1'],true))throw new InvalidArgumentException('Неизвестное состояние подключения');$enabled=(int)$enabled;
        if($enabled&&!googleCreatedSource($s)&&($_POST['old_trigger_disabled']??'')!=='1')throw new InvalidArgumentException('Подтвердите отключение старого триггера Apps Script');
        if(ownerOf($s['route'])!==$s['owner'])throw new InvalidArgumentException('Владелец связки изменился');
        if($enabled){
            bridgeBackground($s['owner']);
            if(!sql('SELECT 1 FROM google_accounts WHERE owner=?',[$s['owner']])->fetchColumn())throw new InvalidArgumentException('Сначала подключите Google-аккаунт владельца таблицы');
            if(empty(entity($s['route'],'route')['active']))throw new InvalidArgumentException('Сначала включите связку и сохраните её, затем запустите таблицу');
        }
        // Explicit desired state makes a repeated POST harmless; no import or external send in this request.
        if((int)$s['active']!==$enabled){sql('UPDATE sheet_sources SET active=?,next_at=0 WHERE id=?',[$enabled,$s['id']]);audit($enabled?'sheet_enabled':'sheet_paused',$s['id']);}
    }else throw new InvalidArgumentException('Неизвестное действие Google');return true;
}
function googlePersonalSettings(): void {
    googleSchema();$u=requireUser();$connected=(bool)sql('SELECT 1 FROM google_accounts WHERE owner=?',[$u['id']])->fetchColumn();$ready=googleReady();
    echo '<div class="card"><h2>Мой Google-аккаунт</h2><p>Каждый баер подключает свой Google-аккаунт. Его подключение и выбранные таблицы привязаны к его аккаунту Lead Bridge.</p><p role="status">'.($connected?'Ваш Google-аккаунт подключён.':'Вы ещё не подключили свой Google-аккаунт.').'</p>';
    echo '<form method="post">'.csrf().'<input type="hidden" name="op" value="google_begin"><button'.($ready?'':' disabled').'>'.($connected?'Переподключить мой Google':'Подключить мой Google-аккаунт').'</button></form>';
    if(!$ready)echo '<p class="muted">Подключение пока недоступно: администратору нужно настроить Google-приложение Lead Bridge один раз для всех баеров. После этого каждый войдёт в свой Google. Пароль от Google вводится только на странице Google.</p>';
    if($connected)echo '<form method="post">'.csrf().'<input type="hidden" name="op" value="google_disconnect"><button class="secondary">Отключить мой Google и остановить мои таблицы</button></form>';
    echo '<p><a href="?page=setup">Перейти к подключению моих таблиц →</a></p></div>';
}
function googleSettings(): void {
    if(!isAdmin())return;$c=googleConfig();
    echo '<div class="card"><h2>Google-приложение Lead Bridge</h2><p>Общая техническая настройка для всех баеров. Каждый баер подключает свой Google-аккаунт в блоке «Мой Google-аккаунт». Инструкция — по кнопке «?».</p><form method="post">'.csrf().'<input type="hidden" name="op" value="google_config">';
    input('client_id','Google OAuth Client ID',$c['client_id']??'');input('client_secret','Client secret — пустое поле сохраняет текущий','','password',empty($c['client_secret']));input('picker_key','Google Picker API key',$c['picker_key']??'');input('project','Номер Google Cloud project',$c['project']??'');
    echo '<p class="muted">Redirect URI: <code>'.h(googleCallbackUrl()).'</code></p><button>Сохранить Google</button></form></div>';
}
function googleSetupPage(): void {
    googleSchema();$u=requireUser();$connected=(bool)sql('SELECT 1 FROM google_accounts WHERE owner=?',[$u['id']])->fetchColumn();
    echo '<div class="card"><h2>Подключить Google-таблицу</h2><p>Выберите таблицу, лист и связку. Lead Bridge подготовит служебные колонки, будет забирать заявки и возвращать статусы. Form ID необязателен.</p>';
    if(!googleReady())echo '<button type="button" disabled>Подключить мой Google-аккаунт</button><p role="status">Администратору нужно один раз настроить Google-приложение Lead Bridge в разделе «Настройки». Затем каждый баер подключит здесь свой Google-аккаунт и свои таблицы без Apps Script.</p>';
    elseif(!$connected)echo '<form method="post">'.csrf().'<input type="hidden" name="op" value="google_begin"><button>Подключить мой Google-аккаунт</button></form><p class="muted">Войдите в свой Google. Доступ предоставляется только к файлам, выбранным для Lead Bridge.</p>';
    else{
        echo '<button type="button" id="google-picker">Выбрать таблицу</button><p id="google-picker-status" role="status" aria-live="polite"></p><form method="post" id="google-inspect" hidden>'.csrf().'<input type="hidden" name="op" value="google_inspect"><input type="hidden" name="spreadsheet" id="google-book-id"></form>';
        googleReconnectForm((string)($_GET['route']??''));
        $pending=$_SESSION['google_book']??null;
        if($pending){$tabs=[];foreach($pending['book']['sheets'] as $tab)$tabs[(string)$tab['properties']['sheetId']]=$tab['properties']['title'];
            echo '<h3>'.h($pending['book']['properties']['title']).'</h3><form method="post">'.csrf().'<input type="hidden" name="op" value="google_connect"><input type="hidden" name="spreadsheet" value="'.h($pending['id']).'">';options('sheet','Лист',$tabs);options('route','Связка',[''=>'Выберите связку']+array_filter(uiEntities('route'),fn($r,$id)=>ownerOf($id)===$u['id'],ARRAY_FILTER_USE_BOTH),(string)($_GET['route']??''));options('history','Существующие строки',['new'=>'Только новые заявки после подключения','all'=>'Обрабатывать также существующие строки']);echo '<button>Подготовить подключение</button><p class="muted">На этом шаге заявки не отправляются. Уже принятые сервером лиды и строки SENT повторно не отправляются.</p></form>';
        }
        echo '<details><summary>Управление доступом Google</summary><form method="post">'.csrf().'<input type="hidden" name="op" value="google_begin"><button class="secondary">Подключить Google заново</button></form><form method="post">'.csrf().'<input type="hidden" name="op" value="google_disconnect"><button class="secondary">Отключить Google и остановить мои таблицы</button></form></details>';
    }
    echo '</div>';
    $sources=isAdmin()?sql('SELECT * FROM sheet_sources ORDER BY rowid DESC'):sql('SELECT * FROM sheet_sources WHERE owner=? ORDER BY rowid DESC',[$u['id']]);
    foreach($sources as $s){$c=unseal($s['data']);$routeName=ownerOf($s['route'])===$s['owner']?entity($s['route'],'route')['name']:'Связка недоступна';echo '<div class="card"><h2>'.h($c['title']).'</h2><p><a href="https://docs.google.com/spreadsheets/d/'.h($s['spreadsheet']).'/edit#gid='.h($s['sheet']).'" target="_blank" rel="noopener noreferrer">Открыть таблицу ↗</a></p><p>Связка: '.h($routeName).'</p>';
        googleSourceControls($s);echo '</div>';
    }
    if($connected&&googleReady())googlePickerScript();
    echo '<details class="card"><summary>Подключение через Apps Script (прежний способ)</summary>';connectionGuide();echo '</details>';
}
function googlePickerScript(): void {
    // Access token is supplied only to the signed-in owner's file picker, never to another owner.
    try{$token=googleToken(requireUser()['id']);}catch(InvalidArgumentException $e){echo '<p role="alert">'.h($e->getMessage()).'</p>';return;}$c=googleConfig();
    $config=json_encode(['token'=>$token,'key'=>$c['picker_key'],'project'=>$c['project']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    echo '<script nonce="'.h($GLOBALS['bridge_nonce']).'">(function(){const c='.$config.';const b=document.getElementById("google-picker"),s=document.getElementById("google-picker-status");b.addEventListener("click",function(){s.textContent="Открываем выбор таблицы…";b.disabled=true;let script=document.getElementById("google-api-loader");function open(){gapi.load("picker",{callback:function(){b.disabled=false;s.textContent="";const view=new google.picker.DocsView(google.picker.ViewId.SPREADSHEETS);new google.picker.PickerBuilder().addView(view).setOAuthToken(c.token).setDeveloperKey(c.key).setAppId(c.project).setOrigin(location.origin).setCallback(function(data){if(data.action===google.picker.Action.PICKED){document.getElementById("google-book-id").value=data.docs[0].id;document.getElementById("google-inspect").requestSubmit();}}).build().setVisible(true);},onerror:fail,timeout:15000,ontimeout:fail});}function fail(){b.disabled=false;s.textContent="Не удалось открыть Google. Обновите страницу или подключите Google заново.";}if(window.gapi){open();return;}script=document.createElement("script");script.id="google-api-loader";script.src="https://apis.google.com/js/api.js";script.onload=open;script.onerror=fail;document.head.appendChild(script);});})();</script>';
}
function googleManual(): void {
    echo '<h3>Переподключение Google и HTTP 403</h3><p>«Переподключить мой Google» в связке, подключении или личных настройках открывает повторный вход в личный Google-аккаунт баера. Общие реквизиты Google-приложения при этом не меняются. После переподключения из связки вы вернётесь в неё; создание таблицы и отправка заявок автоматически не повторяются. HTTP 403 — отказ Google, а не обязательно истёкший вход. Следуйте указанной причине: недостаточное разрешение требует повторного согласия баера; выключенный Google Sheets API нужно включить администратору в Google Cloud → APIs & Services → Library. Лимиты, место в Drive и политики Workspace требуют отдельного исправления.</p>';
    echo '<h3>Создание таблицы из связки</h3><p>Откройте сохранённую связку и нажмите «Создать Google-таблицу». Таблица появится в подключённом Google-аккаунте владельца и получит название связки. Шапка и служебные колонки создаются автоматически, ID и телефон в новой пустой таблице подготовлены как текст. Form ID не требуется. Привязка к связке сохранится, отправка останется на паузе. Откройте таблицу по ссылке, подключите её лист «Лиды» в TikTok Lead export и Signal postback, затем в карточке связки нажмите «Добавил таблицу в ТТ — запустить». Сервер начнёт читать строки без Apps Script и отдельного триггера. Уже поступивший тестовый лид также обрабатывается и может уйти в ПП. В карточке видны пауза, время последней проверки и ошибка; первый проход обычно начинается в течение минуты. Повторное нажатие не создаёт новую копию. При неподтверждённом создании сначала проверьте Google Drive и подключите найденную таблицу как существующую.</p><h3>Автоматическое подключение таблицы</h3><p>В «Подключение» нажмите «Подключить Google», затем «Выбрать таблицу». Выберите лист и свою связку. По умолчанию обрабатываются только новые заявки; старые строки можно включить явно. Подготовка не отправляет лиды. Отключите старый триггер Apps Script и включите отправку в Lead Bridge. Код, JSON и минутный триггер больше не нужны. Для каждого нового или скопированного листа требуется отдельное подключение; переименование сохраняет привязку. Для другой связки создавайте новый лист.</p><p>Form ID необязателен: повреждённое значение исключается с предупреждением, заявка продолжает обработку. Lead ID и остальные ID должны быть точными строками. Утерянные цифры не восстанавливаются. Исходные значения и форматы мост не меняет. Ошибочные строки получают REVIEW; после исправления задайте RETRY. SENT означает приём ПП; CRM-статус приходит отдельно. При большой таблице проверка всех строк занимает несколько проходов.</p><p>В TikTok отдельно выберите эту таблицу и лист для Lead export, сопоставьте поля. Для Signal postback сопоставьте исходный TikTok Lead ID и TikTok Lead Status. Подключение Google к мосту не настраивает TikTok.</p>';
    if(isAdmin())echo '<h3>Настройка Google для администратора</h3><p>В Google Cloud создайте проект, включите Google Sheets API, Google Drive API и Google Picker API. Настройте OAuth consent screen, разрешение https://www.googleapis.com/auth/drive.file и OAuth client типа Web application. Redirect URI: <code>'.h(googleCallbackUrl()).'</code>. Добавьте origin панели в Authorized JavaScript origins. Создайте API key для Picker, разрешите в API restrictions Google Picker API и Google Drive API, а в Websites — https://bridge.naturalsolution.help/* и https://docs.google.com/*. Все реквизиты должны относиться к одному проекту. В «Настройки → Google Sheets» сохраните Client ID, Client secret, API key и числовой номер проекта. Секрет шифруется на сервере и не показывается в браузере. Для постоянной работы внешнего приложения переведите consent screen в Production; в Testing refresh token для этого разрешения обычно истекает через 7 дней. При необходимости пройдите проверку Google. Google-аккаунт должен иметь право редактировать выбранную таблицу.</p>';
}
