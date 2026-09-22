<?php
// Read-only agency status sync. Never calls the lead submission API.
function applyPartnerStatus(string $id,string $status,string $partnerLead): array {
    $old=sql('SELECT * FROM partner_status WHERE lead=?',[$id])->fetch();
    if($old&&$old['partner_lead_id']!==''&&$partnerLead!==''&&$old['partner_lead_id']!==$partnerLead)throw new InvalidArgumentException('Partner lead ID mismatch',409);
    if($old&&$old['status']===$status)return ['status'=>'ok','duplicate'=>true];
    $apply=!$old||($status!=='new'&&$old['status']!=='paid'&&($status!=='hold'||$old['status']==='new'));$now=gmdate('c');
    sql('INSERT INTO partner_events(lead,status,received,applied) VALUES(?,?,?,?)',[$id,$status,$now,(int)$apply]);
    if($apply)sql('INSERT INTO partner_status VALUES(?,?,?,?) ON CONFLICT(lead) DO UPDATE SET status=excluded.status,partner_lead_id=excluded.partner_lead_id,updated=excluded.updated',[$id,$status,$partnerLead?:($old['partner_lead_id']??''),$now]);
    return ['status'=>'ok','applied'=>$apply];
}
function agencyExternalId(array $route,string $leadId): string {
    return hash('sha256',json_encode([$route['dedupe_buyer']??$route['buyer'],$leadId],JSON_THROW_ON_ERROR));
}
function agencyStatusRows(array $response): array {
    if(($response['error']??'')!==''||($response['code']??0)!==200)throw new RuntimeException('Сетевая ошибка или HTTP '.(int)($response['code']??0));
    $body=json_decode($response['body']??'',true,512,JSON_BIGINT_AS_STRING);
    if(!is_array($body)||isset($body['error'])||isset($body['status']))throw new RuntimeException('API не вернул список статусов; проверьте доступ и токен');
    if(count($body)>100)throw new RuntimeException('API вернул слишком много записей');
    $rows=[];
    foreach($body as $row){
        // ext/list: uid is the internal PP ID; id is our external extu. Do not reverse these.
        if(!is_array($row)||!isset($row['uid'],$row['id'],$row['stage'])||(!is_int($row['uid'])&&!is_string($row['uid']))||!preg_match('/^[1-9][0-9]*$/D',(string)$row['uid'])||!is_string($row['id'])||!is_string($row['stage']))throw new RuntimeException('Некорректный формат статуса API');
        $uid=(string)$row['uid'];if(isset($rows[$uid]))throw new RuntimeException('API вернул неоднозначные ID');$rows[$uid]=$row;
    }
    return $rows;
}
function pollAgencyStatuses(?callable $http=null,?int $now=null): void {
    $now??=time();$http??='httpRequest';
    // Add existing SENT leads once. The DB trigger schedules future confirmations atomically.
    if(setting('partner_poll_seeded')!=='1'){
        db()->exec('BEGIN IMMEDIATE');try{sql("INSERT OR IGNORE INTO partner_poll(lead,next_at) SELECT id,0 FROM leads WHERE state='sent'");setting('partner_poll_seeded','1');db()->exec('COMMIT');}catch(Throwable $e){db()->exec('ROLLBACK');throw $e;}
    }
    $batch=[];$group=null;$partner=[];
    foreach(sql("SELECT p.*,l.snapshot,l.partner_id,l.external_id,l.state FROM partner_poll p JOIN leads l ON l.id=p.lead WHERE p.next_at>=0 AND p.next_at<=? ORDER BY p.next_at,p.lead LIMIT 100",[$now])->fetchAll() as $row){
        $s=unseal($row['snapshot']);$pp=$s['partner']??[];
        if($row['state']!=='sent'||!in_array($pp['type']??'',['skylead','cashfactories'],true)||partnerAccountType($pp)!=='agency'||!preg_match('/^[1-9][0-9]*$/D',$row['partner_id'])){sql('UPDATE partner_poll SET next_at=-1 WHERE lead=?',[$row['lead']]);continue;}
        $pid=$s['route']['partner'];
        $key=hash('sha256',json_encode([$pid,$pp['type'],$pp['token']],JSON_THROW_ON_ERROR));
        if($group!==null&&$key!==$group)continue;
        $group=$key;$partner=$pp;$row['extu']=agencyExternalId($s['route'],$row['external_id']);
        // Event sequence detects a callback arriving while this request is in flight.
        $row['event_seq']=(int)sql('SELECT COALESCE(MAX(id),0) FROM partner_events WHERE lead=?',[$row['lead']])->fetchColumn();
        $batch[]=$row;sql('UPDATE partner_poll SET partner=?,next_at=? WHERE lead=?',[$pid,$now+300,$row['lead']]);
    }
    if(!$batch)return;
    $ids=array_column($batch,'partner_id');$failure='';$rows=[];
    try{
        $base=$partner['type']==='skylead'?'https://api.skylead.biz':'https://cashfactories.com/api';
        $response=$http($base.'/ext/list.json?id='.rawurlencode($partner['token']),http_build_query(['oid'=>implode(',',array_unique($ids))]),['Content-Type: application/x-www-form-urlencoded']);
        $rows=agencyStatusRows($response);
    }catch(Throwable $e){$failure=$e instanceof RuntimeException?$e->getMessage():'Ошибка чтения API';if(strlen($failure)>180)$failure='Ошибка чтения API';}
    $map=['wait'=>'new','hold'=>'hold','approve'=>'approved','cancel'=>'rejected','trash'=>'trash'];
    foreach($batch as $lead){
        $row=$rows[$lead['partner_id']]??null;$error=$failure;
        if(!$error&&(!$row||$row['id']!==$lead['extu']))$error='API не вернул заявку с ожидаемыми ID';
        if(!$error&&!isset($map[$row['stage']]))$error='Неизвестный статус API';
        db()->exec('BEGIN IMMEDIATE');
        try{
            if(!$error){
                $seq=(int)sql('SELECT COALESCE(MAX(id),0) FROM partner_events WHERE lead=?',[$lead['lead']])->fetchColumn();
                if($seq!==$lead['event_seq'])$error='Статус изменился во время запроса; проверка будет повторена';
                else try{applyPartnerStatus($lead['lead'],$map[$row['stage']],$lead['partner_id']);}catch(InvalidArgumentException $e){$error='ID заявки конфликтует с сохранённым статусом';}
            }
            $attempts=$error?min(8,(int)$lead['failures']+1):0;
            $status=sql('SELECT status FROM partner_status WHERE lead=?',[$lead['lead']])->fetchColumn();
            $delay=$error?min(3600,300*(2**($attempts-1))):(in_array($status,['approved','rejected','trash','paid'],true)?3600:300);
            sql('UPDATE partner_poll SET next_at=?,checked=?,last_ok=CASE WHEN ?=0 THEN ? ELSE last_ok END,failures=?,error=? WHERE lead=?',[$now+$delay,$now,$attempts,$now,$attempts,$error,$lead['lead']]);
            db()->exec('COMMIT');
        }catch(Throwable $e){db()->exec('ROLLBACK');throw $e;}
    }
}
function agencyPollInfo(string $lead): ?array {
    $row=sql('SELECT checked,last_ok,error FROM partner_poll WHERE lead=? AND next_at>=0',[$lead])->fetch();
    if(!$row)return null;
    foreach(['checked','last_ok'] as $field)$row[$field]=$row[$field]?displayDate('@'.$row[$field]):'Ещё не выполнялась';
    return $row;
}
function agencyPollGuide(string $pid): void {
    $stats=sql('SELECT COUNT(*) AS total,MAX(checked) AS checked,SUM(CASE WHEN error<>\'\' THEN 1 ELSE 0 END) AS failed FROM partner_poll WHERE partner=?',[$pid])->fetch();
    echo '<div class="card"><h2>Статусы ПП → таблица → TikTok</h2><p><strong>Автоматическая проверка через API агентства включена.</strong> Оставьте постбэк партнёрки на Binom. Ссылку моста в ПП добавлять не нужно.</p><p>Мост проверяет только свои подтверждённые заявки: до 100 за запрос, примерно каждые 5 минут. Заявки с решением проверяются раз в час на случай изменения. При ошибках интервал увеличивается до часа; прежний статус сохраняется.</p><p>Google Sheets забирает результат через действующий Apps Script. Обновлять скрипт не требуется; отправка в таблице должна быть включена.</p><p>Заявок на проверке: '.(int)$stats['total'].'. С ошибкой последней проверки: '.(int)$stats['failed'].'.</p><p class="muted">Подробности проверки доступны в карточке лида. Уже принятые заявки сохраняют способ отправки и токен из своего snapshot. API-проверка не отправляет лиды или конверсии повторно.</p></div>';
}
