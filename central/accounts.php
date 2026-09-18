<?php
// Included in the single-file distribution by build.py.
function migrateAccounts(): void {
    if(setting('accounts_migrated')==='1')return;
    db()->exec('BEGIN IMMEDIATE');
    try {
        if(setting('accounts_migrated')!=='1'){
            $hash=setting('password');if(!$hash)throw new RuntimeException('Initialize the installation first');
            sql('INSERT OR IGNORE INTO users(id,login,name,password,role,active,epoch) VALUES(?,?,?,?,?,?,?)',['admin','admin','Администратор',$hash,'admin',1,bin2hex(random_bytes(16))]);
            setting('accounts_migrated','1');sql("DELETE FROM settings WHERE k IN ('password','session_epoch')");
        }
        db()->exec('COMMIT');
    }catch(Throwable $e){db()->exec('ROLLBACK');throw $e;}
}
function currentUser(): ?array {
    if(empty($_SESSION['uid'])||time()-($_SESSION['last']??0)>=28800)return null;
    $u=sql('SELECT * FROM users WHERE id=?',[$_SESSION['uid']])->fetch();
    return $u&&$u['active']&&hash_equals($u['epoch'],(string)($_SESSION['epoch']??''))?$u:null;
}
function requireUser(): array { $u=currentUser();if(!$u)throw new InvalidArgumentException('Войдите в панель');return $u; }
function isAdmin(): bool {return (currentUser()['role']??'')==='admin';}
function requireAdmin(): void {if(!isAdmin())throw new InvalidArgumentException('Действие доступно только администратору');}
function ownerOf(string $id): string {return (string)sql('SELECT owner FROM entities WHERE id=?',[$id])->fetchColumn();}
function uiEntities(string $kind): array {
    $u=requireUser();$q=isAdmin()?sql('SELECT id,data FROM entities WHERE kind=? ORDER BY rowid DESC',[$kind]):sql('SELECT id,data FROM entities WHERE kind=? AND owner=? ORDER BY rowid DESC',[$kind,$u['id']]);
    $r=[];foreach($q as $row)$r[$row['id']]=unseal($row['data']);return $r;
}
function uiEntity(string $id,string $kind): array {
    $u=requireUser();$q=sql('SELECT data,owner FROM entities WHERE id=? AND kind=?',[$id,$kind])->fetch();
    if(!$q||(!isAdmin()&&$q['owner']!==$u['id']))throw new InvalidArgumentException('Запись не найдена или недоступна');return unseal($q['data']);
}
function leadScope(): array {
    $u=requireUser();return isAdmin()?['1=1',[]]:['route IN (SELECT id FROM entities WHERE kind=\'route\' AND owner=?)',[$u['id']]];
}
function uiLead(string $id): array {
    [$where,$args]=leadScope();$l=sql('SELECT * FROM leads WHERE id=? AND '.$where,array_merge([$id],$args))->fetch();
    if(!$l)throw new InvalidArgumentException('Запись не найдена или недоступна');return $l;
}
function accountOptions(): array {requireAdmin();$r=[];foreach(sql('SELECT id,login,name,active FROM users ORDER BY login') as $u)$r[$u['id']]=$u['name'].' ('.$u['login'].')'.($u['active']?'':' — заблокирован');return $r;}
function validOwner(string $id): void {if(!sql('SELECT 1 FROM users WHERE id=?',[$id])->fetchColumn())throw new InvalidArgumentException('Владелец не найден');}
function migrateBuyerLogins(): void {
    db()->exec('BEGIN IMMEDIATE');
    try{foreach(entities('route') as $id=>$r){$r['dedupe_buyer']=$r['dedupe_buyer']??$r['buyer'];$r['buyer']=buyerLogin(ownerOf($id));saveEntity($id,'route',$r);}db()->exec('COMMIT');}catch(Throwable $e){db()->exec('ROLLBACK');throw $e;}
}
function assignRoute(string $routeId,string $owner): void {
    requireAdmin();validOwner($owner);$r=uiEntity($routeId,'route');
    $deps=[$r['tracker'],$r['partner']];
    foreach(entities('route') as $id=>$other){if($id!==$routeId&&(in_array($other['tracker'],$deps,true)||in_array($other['partner'],$deps,true))&&ownerOf($id)!==$owner)throw new InvalidArgumentException('Подключения используются другими связками. Создайте отдельные трекер/аккаунт ПП перед передачей');}
    foreach(array_merge([$routeId],$deps) as $id)sql('UPDATE entities SET owner=? WHERE id=?',[$owner,$id]);
    // Old holders of this route's secret must not retain API access after reassignment.
    $r['dedupe_buyer']=$r['dedupe_buyer']??$r['buyer'];$r['buyer']=buyerLogin($owner);
    $r['secret']=bin2hex(random_bytes(32));saveEntity($routeId,'route',$r);audit('assign_route',$routeId);
}
function accountMutation(string $op): bool {
    $me=requireUser();
    if($op==='password'){
        if(!password_verify((string)($_POST['current_password']??''),$me['password']))throw new InvalidArgumentException('Неверный текущий пароль');
        $p=required($_POST,'password');if(strlen($p)<14||strlen($p)>72)throw new InvalidArgumentException('Пароль: от 14 до 72 символов');$epoch=bin2hex(random_bytes(16));
        sql('UPDATE users SET password=?,epoch=? WHERE id=?',[password_hash($p,PASSWORD_DEFAULT),$epoch,$me['id']]);$_SESSION['epoch']=$epoch;audit('password_changed',$me['id']);return true;
    }
    if($op==='user_save'){
        requireAdmin();$id=clean($_POST['id']??'',80);$old=$id?sql('SELECT * FROM users WHERE id=?',[$id])->fetch():null;if($id&&!$old)throw new InvalidArgumentException('Аккаунт не найден');
        $login=$old['login']??strtolower(required($_POST,'login',50));if(!preg_match('/^[a-z0-9][a-z0-9_.-]{2,49}$/D',$login))throw new InvalidArgumentException('Логин: 3–50 латинских букв, цифр, _, . или -');
        $name=required($_POST,'name');$role=required($_POST,'role');if(!in_array($role,['admin','buyer'],true))throw new InvalidArgumentException('Неизвестная роль');
        $active=isset($_POST['active'])?1:0;$p=clean($_POST['password']??'');if((!$old||$p!=='')&&(strlen($p)<14||strlen($p)>72))throw new InvalidArgumentException('Пароль: от 14 до 72 символов');
        if($id===$me['id']&&(!$active||$role!=='admin'||$p!==''))throw new InvalidArgumentException('Свои права здесь менять нельзя; пароль меняется в Настройках');
        if(sql('SELECT 1 FROM users WHERE login=? AND id<>?',[$login,$id])->fetchColumn())throw new InvalidArgumentException('Логин уже занят');
        $id=$id?:'user_'.bin2hex(random_bytes(8));$hash=$p!==''?password_hash($p,PASSWORD_DEFAULT):$old['password'];
        $epoch=(!$old||$p!==''||$old['role']!==$role||(int)$old['active']!==$active)?bin2hex(random_bytes(16)):$old['epoch'];
        sql('INSERT INTO users(id,login,name,password,role,active,epoch) VALUES(?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET name=excluded.name,password=excluded.password,role=excluded.role,active=excluded.active,epoch=excluded.epoch',[$id,$login,$name,$hash,$role,$active,$epoch]);audit($old?'update_user':'create_user',$id);return true;
    }
    if($op==='assign_route'){
        requireAdmin();db()->exec('BEGIN IMMEDIATE');try{assignRoute(required($_POST,'id'),required($_POST,'owner'));db()->exec('COMMIT');}catch(Throwable $e){db()->exec('ROLLBACK');throw $e;}return true;
    }
    return false;
}
function accountsPage(): void {
    requireAdmin();$id=clean($_GET['edit_user']??'',80);$u=$id?sql('SELECT id,login,name,role,active FROM users WHERE id=?',[$id])->fetch():[];
    if($id&&!$u){echo '<div class="error">Аккаунт не найден</div>';return;}
    echo '<div class="card"><h2>'.($id?'Редактирование аккаунта':'Создать аккаунт').'</h2><form method="post">'.csrf().'<input type="hidden" name="op" value="user_save"><input type="hidden" name="id" value="'.h($id).'">';
    if($id)echo '<p>Логин: <strong>'.h($u['login']).'</strong></p>';else input('login','Логин');
    input('name','Имя',$u['name']??'');options('role','Роль',['buyer'=>'Баер — только свои данные','admin'=>'Администратор — все данные'],$u['role']??'buyer');
    input('password',$id?'Новый пароль (пустое поле — оставить текущий)':'Пароль (14–72 символа)','','password',!$id);
    echo '<label><input type="checkbox" name="active" '.(!$id||$u['active']?'checked':'').'>Аккаунт активен</label><p class="muted">Блокировка закрывает вход и запросы таблиц. Уже принятые лиды завершают обработку. Смена пароля или роли завершает прежние сессии.</p><button>Сохранить аккаунт</button></form></div>';
    echo '<div class="card scroll"><h2>Аккаунты</h2><table><tr><th>Логин</th><th>Имя</th><th>Роль</th><th>Статус</th><th></th></tr>';
    foreach(sql('SELECT id,login,name,role,active FROM users ORDER BY login') as $a)echo '<tr><td>'.h($a['login']).'</td><td>'.h($a['name']).'</td><td>'.($a['role']==='admin'?'Администратор':'Баер').'</td><td>'.($a['active']?'Активен':'Заблокирован').'</td><td><a href="?page=users&edit_user='.h($a['id']).'">Изменить</a></td></tr>';
    echo '</table></div><div class="card"><h2>Передать существующую связку</h2><p>Связка, её лиды, трекер и аккаунт ПП станут доступны выбранному пользователю. Ключ отправки будет заменён — обновите JSON настройки в таблице.</p><form method="post">'.csrf().'<input type="hidden" name="op" value="assign_route">';options('id','Связка',uiEntities('route'));options('owner','Новый владелец',accountOptions());echo '<button>Передать связку</button></form></div>';
}
