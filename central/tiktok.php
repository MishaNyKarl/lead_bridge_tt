<?php
const TIKTOK_API = 'https://business-api.tiktok.com/open_api';

function tiktokCallbackUrl(): string {
    return rtrim(setting('base_url'),'/').'/?tiktok=callback';
}
function tiktokConfig(): array {
    $stored=setting('tiktok_config');
    return $stored!==''?unseal($stored):[];
}
function tiktokId(mixed $value): bool {
    return is_string($value)&&preg_match('/^[0-9]{1,40}$/D',$value)===1;
}
function tiktokSchema(): void {
    db()->exec('CREATE TABLE IF NOT EXISTS tiktok_grants (owner TEXT PRIMARY KEY, data TEXT NOT NULL)');
}
function tiktokGrants(string $owner,array $config): array {
    tiktokSchema();
    $stored=sql('SELECT data FROM tiktok_grants WHERE owner=?',[$owner])->fetchColumn();
    if(!$stored)return [];
    $data=unseal($stored);
    return $data['app_id']===($config['app_id']??null)?$data['grants']:[];
}
function tiktokCall(string $url,?array $body,array $headers,?callable $http=null): array {
    if($body!==null)$headers[]='Content-Type: application/json';
    $r=($http??'httpRequest')($url,$body===null?null:json_encode($body,JSON_THROW_ON_ERROR),$headers);
    $data=json_decode($r['body'],true,512,JSON_BIGINT_AS_STRING);
    if($r['error']||$r['code']<200||$r['code']>=300||($data['code']??null)!==0||!is_array($data['data']??null)){
        $providerCode=$data['code']??null;
        $suffix=is_int($providerCode)&&$providerCode!==0?' (код TikTok '.$providerCode.')':'';
        throw new InvalidArgumentException('TikTok не подтвердил запрос'.$suffix.'. Проверьте разрешения приложения и рекламного кабинета.');
    }
    return $data['data'];
}
function tiktokHandoff(string $url,string $link): never {
    echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Подключение TikTok</title><p><a href="'.h($url).'">'.h($link).'</a></p><script nonce="'.h($GLOBALS['bridge_nonce']).'">location.replace('.json_encode($url,JSON_HEX_TAG|JSON_HEX_AMP|JSON_THROW_ON_ERROR).')</script></html>';
    exit;
}
function tiktokBegin(array $user): never {
    $config=tiktokConfig();
    if(!$config)throw new InvalidArgumentException('Администратор ещё не настроил приложение TikTok');
    $state=bin2hex(random_bytes(32));
    $_SESSION['tiktok_attempt']=['state'=>$state,'expires'=>time()+600,'owner'=>$user['id'],'revision'=>$config['revision']];
    $url='https://business-api.tiktok.com/portal/auth?'.http_build_query([
        'app_id'=>$config['app_id'],'state'=>$state,'redirect_uri'=>tiktokCallbackUrl()
    ],'','&',PHP_QUERY_RFC3986);
    tiktokHandoff($url,'Продолжить в TikTok');
}
function tiktokLanding(): never {
    // Establish an own-origin document before returning to the Strict panel session.
    $args=['page'=>'tiktok','tiktok'=>'finish'];
    foreach(['state','auth_code','error'] as $field){
        if(is_string($_GET[$field]??null)&&strlen($_GET[$field])<=4096)$args[$field]=$_GET[$field];
    }
    tiktokHandoff('?'.http_build_query($args),'Продолжить в Lead Bridge');
}
function tiktokFinish(?callable $http=null): void {
    $user=requireUser();
    $attempt=$_SESSION['tiktok_attempt']??[];
    $state=$_GET['state']??null;
    if(($attempt['expires']??0)<time()||($attempt['owner']??null)!==$user['id']||!is_string($state)||!hash_equals($attempt['state'],$state)){
        throw new InvalidArgumentException('Подключение TikTok истекло. Начните заново.');
    }
    unset($_SESSION['tiktok_attempt']);
    if(!empty($user['sso_migration']))throw new InvalidArgumentException('Сначала свяжите аккаунт с OpsVault');
    $config=tiktokConfig();
    if(($config['revision']??null)!==$attempt['revision'])throw new InvalidArgumentException('Настройки приложения изменились. Начните подключение заново.');
    if(isset($_GET['error'])||!is_string($_GET['auth_code']??null)||$_GET['auth_code']===''){
        throw new InvalidArgumentException('TikTok не предоставил код авторизации. Начните подключение заново.');
    }
    $data=tiktokCall(TIKTOK_API.'/v2.0/oauth2/access_token/',[
        'app_id'=>$config['app_id'],'secret'=>$config['secret'],'auth_code'=>required($_GET,'auth_code',4096),
        'is_long_term'=>true,'return_advertiser_ids'=>true
    ],[],$http);
    $token=$data['access_token']??null;
    $ids=$data['advertiser_ids']??null;
    if(!is_string($token)||preg_match('/^[!-~]{1,4096}$/D',$token)!==1||!is_array($ids)||!array_is_list($ids)){
        throw new InvalidArgumentException('TikTok вернул неполный ответ авторизации.');
    }
    foreach($ids as $id)if(!tiktokId($id))throw new InvalidArgumentException('TikTok вернул некорректный ID кабинета.');
    $ids=array_values(array_unique($ids,SORT_STRING));
    if(!$ids)throw new InvalidArgumentException('TikTok не предоставил доступ к рекламным кабинетам.');
    tiktokSchema();
    opsRecheck();
    db()->exec('BEGIN IMMEDIATE');
    try{
        if(tiktokConfig()['revision']!==$config['revision'])throw new InvalidArgumentException('Настройки приложения изменились. Начните подключение заново.');
        bridgeBackground($user['id']);
        $grants=[];
        foreach(tiktokGrants($user['id'],$config) as $grant){
            $grant['ids']=array_values(array_diff($grant['ids'],$ids));
            if($grant['ids'])$grants[]=$grant;
        }
        $grants[]=['ids'=>$ids,'token'=>$token];
        sql('INSERT INTO tiktok_grants VALUES(?,?) ON CONFLICT(owner) DO UPDATE SET data=excluded.data',[
            $user['id'],seal(['app_id'=>$config['app_id'],'grants'=>$grants])
        ]);
        audit('tiktok_connect');
        db()->exec('COMMIT');
    }catch(Throwable $e){
        db()->exec('ROLLBACK');
        throw $e;
    }
}
function tiktokForms(array $grants,string $id,int $page,?callable $http=null): array {
    foreach($grants as $grant){
        if(in_array($id,$grant['ids'],true)){
            $data=tiktokCall(TIKTOK_API.'/v1.3/page/get/?'.http_build_query([
                'advertiser_id'=>$id,'business_type'=>'LEAD_GEN','status'=>'PUBLISHED','page'=>$page,'page_size'=>50
            ]),null,['Access-Token: '.$grant['token']],$http);
            if(!is_array($data['list']??null)||!array_is_list($data['list'])||!is_int($data['page_info']['total_page']??null)){
                throw new InvalidArgumentException('TikTok вернул неполный список форм.');
            }
            foreach($data['list'] as $form){
                if(!tiktokId($form['page_id']??null)||!is_string($form['title']??null)){
                    throw new InvalidArgumentException('TikTok вернул некорректные данные формы.');
                }
            }
            return $data;
        }
    }
    throw new InvalidArgumentException('Подключите доступ к этому рекламному кабинету.');
}
function tiktokMutation(string $op,array $user): bool {
    if($op==='tiktok_config'){
        requireAdmin();
        $id=required($_POST,'tiktok_app_id',40);
        if(!tiktokId($id))throw new InvalidArgumentException('Укажите числовой App ID TikTok.');
        $old=tiktokConfig();
        $secret=clean($_POST['tiktok_secret']??'',500);
        if($secret===''&&$id===($old['app_id']??null))$secret=$old['secret'];
        if($secret==='')throw new InvalidArgumentException('Введите App Secret TikTok.');
        opsRecheck();
        setting('tiktok_config',seal(['app_id'=>$id,'secret'=>$secret,'revision'=>bin2hex(random_bytes(16))]));
        audit('tiktok_settings');
        redirect('?page=settings#tiktok-settings');
    }
    if($op==='tiktok_connect')tiktokBegin($user);
    if($op==='tiktok_disconnect'){
        tiktokSchema();
        opsRecheck();
        sql('DELETE FROM tiktok_grants WHERE owner=?',[$user['id']]);
        audit('tiktok_disconnect');
        redirect('?page=tiktok');
    }
    return false;
}
function tiktokSettings(): void {
    if(!isAdmin())return;
    $config=tiktokConfig();
    echo '<div class="card" id="tiktok-settings"><h2>Приложение TikTok</h2><form method="post">'.csrf().'<input type="hidden" name="op" value="tiktok_config">';
    input('tiktok_app_id','App ID',$config['app_id']??'');
    input('tiktok_secret','App Secret'.($config?' — пустое поле сохраняет секрет для текущего App ID':''),'','password',!$config);
    echo '<p>Advertiser redirect URL: <code style="overflow-wrap:anywhere">'.h(tiktokCallbackUrl()).'</code></p><button>Сохранить приложение TikTok</button></form></div>';
}
function tiktokPage(): void {
    $user=requireUser();
    $config=tiktokConfig();
    echo '<div class="card"><h2>Подключение TikTok</h2>';
    if(!$config){
        echo '<p>Администратор настраивает приложение в разделе «Настройки».</p></div>';
        return;
    }
    $grants=tiktokGrants($user['id'],$config);
    echo '<form method="post">'.csrf().'<input type="hidden" name="op" value="tiktok_connect"><button>'.($grants?'Добавить кабинеты или обновить доступ':'Подключить TikTok').'</button></form>';
    if(!$grants){
        echo '</div>';
        return;
    }
    $accounts=array_merge(...array_column($grants,'ids'));
    sort($accounts,SORT_STRING);
    $requested=$_GET['advertiser']??null;
    $id=tiktokId($requested)?$requested:$accounts[0];
    echo '<p>Доступных кабинетов: '.count($accounts).'</p><form method="get"><input type="hidden" name="page" value="tiktok">';
    options('advertiser','Рекламный кабинет',array_combine($accounts,$accounts),$id);
    echo '<button>Загрузить формы</button></form></div>';
    echo '<div class="card"><h2>Опубликованные формы</h2>';
    if(!isset($_GET['advertiser'])){
        echo '<p>Выберите кабинет и нажмите «Загрузить формы».</p>';
    }else{
        try{
            if(!tiktokId($requested))throw new InvalidArgumentException('Некорректный ID кабинета.');
            $page=filter_var($_GET['form_page']??'1',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($page===false)throw new InvalidArgumentException('Некорректный номер страницы.');
            $forms=tiktokForms($grants,$id,$page);
            if(!$forms['list'])echo '<p>Форм на этой странице нет.</p>';
            else{
                echo '<ul>';
                foreach($forms['list'] as $form)echo '<li>'.h($form['title']).' <code>'.h($form['page_id']).'</code></li>';
                echo '</ul>';
            }
            $url='?page=tiktok&amp;advertiser='.rawurlencode($id).'&amp;form_page=';
            if($page>1)echo '<a href="'.$url.($page-1).'">Предыдущая страница</a> ';
            if($page<$forms['page_info']['total_page'])echo '<a href="'.$url.($page+1).'">Следующая страница</a>';
        }catch(InvalidArgumentException $e){echo '<p role="alert">'.h($e->getMessage()).'</p>';}
        catch(Throwable $e){echo '<p role="alert">Не удалось загрузить формы TikTok.</p>';}
    }
    echo '</div><div class="card"><form method="post">'.csrf().'<input type="hidden" name="op" value="tiktok_disconnect"><button class="secondary">Удалить мои подключения TikTok</button></form></div>';
}
