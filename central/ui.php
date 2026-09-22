<?php
// Presentation helpers. Database timestamps remain UTC; sorting is applied before pagination.
function userTimezone(): string {
    $user=currentUser();$zone=$user?setting('timezone_'.$user['id']):'';
    return in_array($zone,['Europe/Moscow','UTC'],true)?$zone:'Europe/Moscow';
}
function displayDate(string $value,?string $timezone=null): string {
    try{return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone($timezone??userTimezone()))->format('H:i:s d.m.Y');}
    catch(Throwable $e){return $value;}
}
function uiIcon(string $name): string {
    $icons=['settings'=>'__ICON_SETTINGS__','help'=>'__ICON_HELP__'];
    $svg=base64_decode($icons[$name]??'');
    // Source checkout and the compiled single-file build both work.
    if(!$svg||!str_contains($svg,'<svg'))$svg=file_get_contents(__DIR__.'/icons/'.($name==='settings'?'settings':'circle-question-mark').'.svg');
    return str_replace('<svg','<svg aria-hidden="true" focusable="false"',$svg);
}
function fieldHelp(string $name,string $label): string {
    if(!in_array($_GET['page']??'', ['route','tracker','partner'],true))return '';
    $tips=[
      'partner_ip_mode'=>'По умолчанию используется реальный Client IP из таблицы. Для Instant Forms без IP выберите согласованный с ПП временный режим генерации и страну. Для каждой новой заявки мост выбирает адрес из диапазонов этой страны и сохраняет его; повторы не меняют IP. Реальный IP имеет приоритет. Режим действует только для Skylead/Cashfactories.',
      'type'=>'Выберите сеть, в которой вы получили токен. Для другой сети создайте отдельный аккаунт партнёрки. Существующие заявки сохраняют прежние настройки.',
      'flow_id'=>'Для Skylead/Cashfactories: числовой ID из раздела Потоки или API flows. Поток должен относиться к выбранному офферу и вашему аккаунту. Для Lemonad поле не используется.',
      'country'=>'Выберите страну покупателей вашего оффера. В API отправляется код ISO, например ZA для ЮАР. В режиме случайного IP страна определяет диапазоны генерации. В обычном режиме IP берётся из таблицы. Для Skylead/Cashfactories рекомендуется указать явно, чтобы ПП не определяла страну по IP. Для Lemonad поле не используется.',
      'currency'=>'Валюта заказа: три латинские буквы, например ZAR. Не сумма выплаты. Для Lemonad поле не используется.',
      'partner_meta_mode'=>'Выберите, что отправлять в Lemonad. Основные ID добавят группу в utm_term и ваш логин в utm_medium. Все метки сложат дополнительные поля в один JSON-текст utm_term. Это не отдельные колонки в ПП; полный набор всегда доступен в карточке лида моста.',
      'name'=>'Название для вас: например «Binom Димы», «Lemonad основной» или «Proman ZA». Придумайте любое понятное имя. Оно не является ключом кампании или API-токеном.',
      'owner'=>'Выберите аккаунт баера, которому принадлежит запись. Баер увидит только свои записи. Трекер, партнёрка и связка должны принадлежать одному владельцу.',
      'version'=>'Выберите установленную версию вашего Binom: v1 или v2. Она определяет способ создания клика. Если не знаете версию, уточните её у администратора трекера.',
      'click_url'=>'Это адрес обработчика кликов Binom. Откройте Settings → Tracking links → Click URL и удалите знак ? и всё после него. v1: https://tracker.com/click.php (имя файла может быть другим). v2: обычно https://tracker.com/click; точный адрес есть в TRACKER_URL_TEMPLATE в Settings → Click API. Вставляйте полный HTTPS-адрес, не адрес входа в панель.',
      'api_key'=>'Binom v1: Settings → API, скопируйте API key. Binom v2: Settings → Click API, найдите API_KEY в показанном PHP-коде. Вставьте только значение без кавычек и без &api_key=. При редактировании оставьте поле пустым, чтобы сохранить действующий ключ.',
      'token'=>'Skylead/Cashfactories: API-токен из Профиля; попросите менеджера включить отправку лидов по API. Lemonad: откройте профиль Lemonad и раздел Global postback and API. Это токен для отправки заявок от имени аккаунта, не ключ Binom и не ID оффера. Вставьте только значение токена. Пустое поле при редактировании сохраняет текущий токен.',
      'tracker'=>'Выберите уже добавленный трекер Binom, в котором находится кампания этой связки. Если список пуст, сначала сохраните трекер в разделе «Трекеры».',
      'partner'=>'Выберите сохранённый аккаунт партнёрки, куда должны поступать заявки. Если список пуст, добавьте его в «Партнёрки». Для новых продуктов того же аккаунта используйте эту же запись: глобальный постбэк уже будет работать.',
      'campaign_key'=>'В Binom откройте ссылку нужной кампании: например https://tracker.com/click?key=ABC123. Скопируйте только ABC123 — значение после key= и до следующего &. Это не Campaign ID TikTok и не номер кампании в списке Binom.',
      'offer_id'=>'Skylead/Cashfactories: числовой offer ID из API/кода оффера. Lemonad: API Offer ID продукта. Возьмите offerId в коде/API-настройках оффера или запросите у менеджера. Обычно это длинный идентификатор с дефисами. Номер в названии вроде [4075] сюда не подходит.',
      'active'=>'Включённая связка принимает новые заявки из подключённой таблицы. Выключенная ставит приём новых заявок на паузу. Уже принятые заявки продолжают обрабатываться, постбэки по старым лидам тоже принимаются.'
    ];
    $tip=$tips[$name]??'';
    if(str_starts_with($name,'slot_'))$tip='Откройте кампанию Binom → её Traffic Source → Use Tokens. Выберите номер строки Token, в которой настроено это поле. Например, Ad ID может быть в Token 3 или Token 7. Номер нужен для проверки; фактическую отправку определяет соседний Parameter. Если поле не используете, оставьте номер неуказанным и Parameter пустым.';
    if(str_starts_with($name,'token_'))$tip='В Traffic Source Binom скопируйте точное значение из колонки Parameter для этого поля, с тем же регистром букв. Например ad_id, adgroup_id или campaign_id. Не копируйте Placeholder вида __AID__ или отображаемое Name. При переносе метки в другую строку обновите и номер Token, и Parameter. Пустое значение отключает передачу поля.';
    return $tip===''?'':' <button type="button" class="field-help" data-tip="'.h($tip).'" aria-label="Подсказка: '.h($label).'">'.uiIcon('help').'</button>';
}
function tableSort(array $allowed): array {
    $key=is_string($_GET['sort']??null)?$_GET['sort']:'';$dir=$_GET['dir']??'';
    return in_array($key,$allowed,true)&&in_array($dir,['asc','desc'],true)?[$key,$dir]:['',''];
}
function sortQuery(): string {
    [$key,$dir]=tableSort(['created','external_id','route','state','click_id']);
    return $key===''?'':'&sort='.rawurlencode($key).'&dir='.$dir;
}
function sortHeading(string $key,string $label,array $allowed): string {
    [$current,$dir]=tableSort($allowed);$selected=$current===$key;
    $q=['page'=>$_GET['page']??'leads'];
    foreach(['state','owner'] as $param)if(isset($_GET[$param])&&is_string($_GET[$param]))$q[$param]=$_GET[$param];
    if(!$selected||$dir!=='desc'){$q['sort']=$key;$q['dir']=$selected?'desc':'asc';}
    $next=!$selected?'по возрастанию':($dir==='asc'?'по убыванию':'исходный порядок');
    return '<th aria-sort="'.($selected?($dir==='asc'?'ascending':'descending'):'none').'"><a class="sort-heading" href="?'.h(http_build_query($q)).'" title="Следующий клик: '.$next.'">'.h($label).'<span aria-hidden="true">'.($selected?($dir==='asc'?'↑':'↓'):'↕').'</span></a></th>';
}
function entityDescription(string $page,array $e,string $id,bool $admin): string {
    return $page==='tracker'?'Binom '.$e['version'].' · '.$e['click_url']:($page==='partner'?(postbackProviders()[$e['type']??'lemonad']??'Unknown'):($admin?'':buyerLogin(ownerOf($id)).' · ').($e['active']?'Активна':'Черновик / пауза'));
}
function uiLower(string $value): string {
    return strtolower(strtr($value,array_combine(preg_split('//u','АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ',-1,PREG_SPLIT_NO_EMPTY),preg_split('//u','абвгдеёжзийклмнопрстуфхцчшщъыьэюя',-1,PREG_SPLIT_NO_EMPTY))));
}
function sortedEntities(array $all,string $page,bool $admin): array {
    [$key,$dir]=tableSort($admin?['name','owner','description']:['name','description']);if($key==='')return $all;
    $values=[];foreach($all as $id=>$e)$values[$id]=match($key){'owner'=>buyerLogin(ownerOf($id)),'description'=>entityDescription($page,$e,$id,$admin),default=>$e['name']};
    uksort($all,function($a,$b)use($values,$dir){$cmp=strnatcasecmp(uiLower($values[$a]),uiLower($values[$b]));return ($dir==='desc'?-$cmp:$cmp)?:strcmp($a,$b);});return $all;
}
function leadOrder(array $routes): string {
    [$key,$dir]=tableSort(['created','external_id','route','state','click_id']);$direction=$dir==='asc'?'ASC':'DESC';
    if($key==='')return 'created DESC,id DESC';
    if($key==='external_id')return 'length(external_id) '.$direction.',external_id '.$direction.',id DESC';
    if($key==='route'){
        uasort($routes,fn($a,$b)=>strnatcasecmp(uiLower($a['name']),uiLower($b['name'])));
        $expr='CASE route';$i=0;foreach($routes as $id=>$r)$expr.=' WHEN '.db()->quote($id).' THEN '.$i++;
        return $i?$expr.' ELSE '.$i.' END '.$direction.',created DESC,id DESC':'created DESC,id DESC';
    }
    return $key.' COLLATE NOCASE '.$direction.',id DESC';
}

function scriptNoticeControls(): void {
    requireAdmin();$notice=json_decode(setting('script_notice'),true)?:[];$id=(string)($notice['id']??'');
    echo '<div class="card"><h2>Обновление Apps Script</h2><p>Опубликуйте уведомление, когда баерам нужно заменить скрипт во всех действующих Google-таблицах. Оно появится справа сверху при входе или переходе на другую страницу и останется до нажатия «Понятно». Повторная публикация покажет его всем заново.</p>';
    echo '<p class="muted">'.(!empty($notice['active'])?'Уведомление опубликовано: '.h(displayDate($notice['created'])):'Сейчас уведомление выключено.').'</p><div class="actions"><form method="post">'.csrf().'<input type="hidden" name="op" value="script_notice_publish"><input type="hidden" name="notice_id" value="'.h($id).'"><button>Уведомить об обновлении Apps Script</button></form><a class="button secondary" href="?page=settings&amp;preview_script_notice=1">Предпросмотр</a>';
    if(!empty($notice['active']))echo '<form method="post">'.csrf().'<input type="hidden" name="op" value="script_notice_withdraw"><input type="hidden" name="notice_id" value="'.h($id).'"><button class="secondary">Снять уведомление</button></form>';
    echo '</div></div>';
}
function scriptNotice(): void {
    $me=currentUser();if(!$me)return;
    $preview=isAdmin()&&($_GET['preview_script_notice']??'')==='1';
    $notice=json_decode(setting('script_notice'),true)?:[];$id=(string)($notice['id']??'');
    if(!$preview&&(empty($notice['active'])||$id===''||setting('script_notice_ack_'.$me['id'])===$id))return;
    echo '<section id="script-update-notice" class="script-update-notice" role="region" aria-labelledby="script-update-title"><div class="script-update-heading"><span class="script-update-icon" aria-hidden="true">↻</span><span class="script-update-eyebrow">'.($preview?'ПРЕДПРОСМОТР · ВИДНО ТОЛЬКО ВАМ':'ОБНОВЛЕНИЕ ПЛАТФОРМЫ').'</span></div><h2 id="script-update-title">Обновился Apps Script</h2><p>Замените Apps Script во <strong>всех действующих Google-таблицах</strong>, подключённых к вашим связкам.</p><p class="script-update-detail">Один скрипт на таблицу — даже если в ней несколько листов. Сохраните JSON и триггер, затем выполните «Подготовить и проверить» на каждом подключённом листе.</p><div class="actions"><a class="button" href="?page=setup">Открыть новый скрипт</a>';
    if($preview)echo '<a class="button secondary" href="?page=settings">Закрыть просмотр</a>';
    else echo '<form method="post">'.csrf().'<input type="hidden" name="op" value="script_notice_ack"><input type="hidden" name="notice_id" value="'.h($id).'"><button class="secondary">Понятно</button></form>';
    echo '</div></section>';
}

function countryOptions(string $current=''): array {
    $countries=['AT'=>'Австрия','AL'=>'Албания','DZ'=>'Алжир','AR'=>'Аргентина','BD'=>'Бангладеш','BE'=>'Бельгия','BG'=>'Болгария','BO'=>'Боливия','BR'=>'Бразилия','GB'=>'Великобритания','HU'=>'Венгрия','VN'=>'Вьетнам','GT'=>'Гватемала','DE'=>'Германия','GH'=>'Гана','GR'=>'Греция','GE'=>'Грузия','DO'=>'Доминиканская Республика','EG'=>'Египет','ZM'=>'Замбия','ZW'=>'Зимбабве','IL'=>'Израиль','IN'=>'Индия','ID'=>'Индонезия','ES'=>'Испания','IT'=>'Италия','KZ'=>'Казахстан','CM'=>'Камерун','KE'=>'Кения','CY'=>'Кипр','CO'=>'Колумбия','CR'=>'Коста-Рика','CI'=>'Кот-д’Ивуар','LV'=>'Латвия','LT'=>'Литва','MY'=>'Малайзия','MA'=>'Марокко','MX'=>'Мексика','MD'=>'Молдова','NG'=>'Нигерия','NL'=>'Нидерланды','NI'=>'Никарагуа','AE'=>'ОАЭ','PK'=>'Пакистан','PA'=>'Панама','PY'=>'Парагвай','PE'=>'Перу','PL'=>'Польша','PT'=>'Португалия','RU'=>'Россия','RO'=>'Румыния','SA'=>'Саудовская Аравия','SN'=>'Сенегал','RS'=>'Сербия','SK'=>'Словакия','SI'=>'Словения','US'=>'США','TH'=>'Таиланд','TZ'=>'Танзания','TN'=>'Тунис','TR'=>'Турция','UG'=>'Уганда','UZ'=>'Узбекистан','UA'=>'Украина','UY'=>'Уругвай','PH'=>'Филиппины','FR'=>'Франция','HR'=>'Хорватия','CZ'=>'Чешская Республика','CL'=>'Чили','CH'=>'Швейцария','LK'=>'Шри-Ланка','EC'=>'Эквадор','EE'=>'Эстония','ZA'=>'ЮАР'];
    if($current!==''&&!isset($countries[$current]))$countries[$current]=$current;
    return [''=>'Не указана — ПП определит по реальному IP']+$countries;
}

function routePartnerSelect(string $selected): void {
    echo '<label for="field_partner">Аккаунт ПП'.fieldHelp('partner','Аккаунт ПП').'<select id="field_partner" name="partner" required data-route-partner><option value="">Выберите партнёрку</option>';
    foreach(uiEntities('partner') as $id=>$partner){$type=$partner['type']??'lemonad';echo '<option value="'.h($id).'" data-provider="'.h($type).'" '.($id===$selected?'selected':'').'>'.h((postbackProviders()[$type]??$type).' · '.$partner['name']).'</option>';}
    echo '</select></label>';
}
