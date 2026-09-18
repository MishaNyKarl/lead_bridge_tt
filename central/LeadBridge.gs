// Lead Bridge central connector. IDs must arrive as text, before TikTok writes them.
function onOpen() {
  SpreadsheetApp.getUi().createMenu('Lead Bridge')
    .addItem('Настроить', 'bridgeConfigure')
    .addItem('Подготовить и проверить', 'bridgeSetup')
    .addItem('Включить отправку раз в минуту', 'bridgeEnable')
    .addItem('Выключить мой триггер', 'bridgeDisable')
    .addItem('Пересканировать строки', 'bridgeRescan').addToUi();
}
function bridgeConfig() {
  var p = PropertiesService.getScriptProperties();
  var c = {BRIDGE_URL:p.getProperty('BRIDGE_URL'), ROUTE_ID:p.getProperty('ROUTE_ID'), BRIDGE_SECRET:p.getProperty('BRIDGE_SECRET')};
  if (!c.BRIDGE_URL || !/^https:\/\/[^\s]+$/.test(c.BRIDGE_URL) || !c.ROUTE_ID || !c.BRIDGE_SECRET) throw Error('Откройте Lead Bridge → Настроить');
  return c;
}
function bridgeConfigure() {
  var ui = SpreadsheetApp.getUi();
  var r = ui.prompt('Настройка Lead Bridge', 'Вставьте JSON из карточки связки. Не меняйте связку в таблице с незавершёнными лидами.', ui.ButtonSet.OK_CANCEL);
  if (r.getSelectedButton() !== ui.Button.OK) return;
  var c = JSON.parse(r.getResponseText());
  if (!c.BRIDGE_URL || !/^https:\/\/[^\s]+$/.test(c.BRIDGE_URL) || !/^route_[a-f0-9]+$/.test(c.ROUTE_ID) || !/^[a-f0-9]{64}$/.test(c.BRIDGE_SECRET)) throw Error('Некорректные настройки');
  var lock=LockService.getScriptLock();lock.waitLock(10000);
  try {
    var props=PropertiesService.getScriptProperties(), old=props.getProperty('ROUTE_ID');
    if (old && old!==c.ROUTE_ID) throw Error('Для другой связки создайте отдельную таблицу; так не смешаются старые и новые лиды');
    var check=bridgeFetch(c,{op:'check'});
    if(check.code!==200 || check.data.status!=='ok')throw Error('Не удалось проверить подключение');
    props.setProperties({BRIDGE_URL:c.BRIDGE_URL, ROUTE_ID:c.ROUTE_ID, BRIDGE_SECRET:c.BRIDGE_SECRET});
    ui.alert('Настройки сохранены. Теперь выполните «Подготовить и проверить».');
  } finally { lock.releaseLock(); }
}
function bridgeFetch(c, body) {
  body.route_id=c.ROUTE_ID;
  var r=UrlFetchApp.fetch(c.BRIDGE_URL,{method:'post',contentType:'application/json',headers:{'X-Bridge-Secret':c.BRIDGE_SECRET},payload:JSON.stringify(body),muteHttpExceptions:true,followRedirects:false});
  return {code:r.getResponseCode(),data:JSON.parse(r.getContentText())};
}
function bridgeHeaders(sheet, prepare) {
  if (!sheet.getLastColumn()) return null;
  var h=sheet.getRange(1,1,1,sheet.getLastColumn()).getValues()[0].map(function(x){return String(x).trim();});
  if(h.indexOf('Phone')<0 || (h.indexOf('Lead ID')<0 && h.indexOf('TikTok Lead ID')<0))return null;
  if(prepare) ['Webhook_Status','Binom Click ID','Partner Reference ID','Webhook_Message','Bridge Receipt','TikTok Lead Status','Partner Status Updated'].forEach(function(k){if(h.indexOf(k)<0){h.push(k);sheet.getRange(1,h.length).setValue(k);}});
  if(h.indexOf('Webhook_Status')<0 || h.indexOf('Bridge Receipt')<0)throw Error('Сначала выполните подготовку таблицы');
  return h;
}
function bridgeSetup() {
  var c=bridgeConfig(), r=bridgeFetch(c,{op:'check'});
  if(r.code!==200 || r.data.status!=='ok')throw Error('Ошибка подключения');
  SpreadsheetApp.getActiveSpreadsheet().getSheets().forEach(function(s){var h=bridgeHeaders(s,true);if(!h)return;
    ['Lead ID','TikTok Lead ID','Phone','Campaign ID','Ad ID','Ad Group ID','Advertiser ID','Form ID','Binom Click ID','Partner Reference ID','Bridge Receipt'].forEach(function(k){var i=h.indexOf(k);if(i>=0)s.getRange(1,i+1,s.getMaxRows(),1).setNumberFormat('@');});
  });
  SpreadsheetApp.getUi().alert('Подключение проверено. Связка '+(r.data.active?'активна':'на паузе')+'. Подготовлены колонки. Лиды не отправлялись. Перед включением удалите прежний триггер отправки.');
}
function bridgeDisable() {ScriptApp.getProjectTriggers().forEach(function(t){if(t.getHandlerFunction()==='bridgeTick')ScriptApp.deleteTrigger(t);});}
function bridgeEnable() {
  bridgeConfig();
  var legacy=ScriptApp.getProjectTriggers().some(function(t){return t.getHandlerFunction()==='sendLeadsToLeadPhp';});
  if(legacy)throw Error('Сначала удалите старый триггер sendLeadsToLeadPhp');
  var ui=SpreadsheetApp.getUi();
  if(ui.alert('Включить отправку?', 'Все новые строки будут отправляться в ПП. Убедитесь, что старые триггеры других владельцев отключены.',ui.ButtonSet.YES_NO)!==ui.Button.YES)return;
  bridgeDisable();ScriptApp.newTrigger('bridgeTick').timeBased().everyMinutes(1).create();
}
function bridgeRescan(){var p=PropertiesService.getScriptProperties();Object.keys(p.getProperties()).forEach(function(k){if(k.indexOf('cursor_')===0)p.deleteProperty(k);});SpreadsheetApp.getUi().alert('Следующий запуск пересканирует строки. SENT остаются нетронутыми.');}
function bridgeId(raw) {
  if(raw===''||raw===null||raw===undefined)return '';
  if(typeof raw==='number' && (!Number.isSafeInteger(raw)||raw<0))throw Error('ID округлён: восстановите исходное значение как текст');
  var s=String(raw).trim();if(!/^\d{1,40}$/.test(s))throw Error('ID должен быть строкой цифр');return s;
}
function bridgePhone(raw){var s=String(raw||'').trim();if(/[eE]/.test(s))throw Error('Телефон повреждён');var d=s.replace(/[^0-9]/g,'').replace(/^00/,'');if(!/^[1-9][0-9]{6,14}$/.test(d))throw Error('Телефон должен содержать код страны');return '+'+d;}
function bridgeTick() {
  var lock=LockService.getScriptLock();if(!lock.tryLock(1000))return;
  var start=Date.now(),deadline=start+220000,p=PropertiesService.getScriptProperties();
  try {
    var c=bridgeConfig(),sheets=SpreadsheetApp.getActiveSpreadsheet().getSheets();
    for(var si=0;si<sheets.length && Date.now()<deadline;si++){
      var s=sheets[si],h=bridgeHeaders(s,false);if(!h||s.getLastRow()<2)continue;
      if(h.indexOf('TikTok Lead Status')>=0){try{bridgeSyncPartnerStatuses(c,s,h,p);}catch(syncError){console.error('Статусы ПП: '+syncError.message);}}
      var statusCol=h.indexOf('Webhook_Status')+1, receiptCol=h.indexOf('Bridge Receipt')+1;
      // Read a single status column to recover unfinished rows without rereading every lead's PII.
      var statuses=s.getRange(2,statusCol,s.getLastRow()-1,1).getValues();
      var cursor=Math.max(2,Number(p.getProperty('cursor_'+s.getSheetId())||2)), rows=[];
      for(var j=0;j<statuses.length;j++){var state=String(statuses[j][0]||'').trim();if(['QUEUED','PROCESSING','RETRY'].indexOf(state)>=0 || (!state && j+2>=cursor))rows.push(j+2);}
      for(var ri=0;ri<rows.length && Date.now()<deadline;ri++){
        var rowNum=rows[ri], row=s.getRange(rowNum,1,1,h.length).getValues()[0];
        var val=function(k){var idx=h.indexOf(k);return idx<0?'':row[idx];};
        var write=function(k,v){var idx=h.indexOf(k);if(idx>=0)s.getRange(rowNum,idx+1).setValue(v);};
        var apply=function(result){
          if(result.click_id)write('Binom Click ID',String(result.click_id));
          if(result.partner_reference_id)write('Partner Reference ID',String(result.partner_reference_id));
          if(result.receipt)write('Bridge Receipt',result.receipt);
          var sent=result.status==='success'&&result.stage==='sent'&&result.click_id&&result.partner_reference_id;
          write('Webhook_Status',sent?'SENT':(result.status==='processing'?'QUEUED':'REVIEW'));
          write('Webhook_Message',String(result.message||result.stage||'Неизвестный ответ'));
        };
        if(!val('Phone'))continue; // Incomplete rows must never be skipped by the cursor.
        try {
          var receipt=String(val('Bridge Receipt')||'');
          if(receipt){var poll=bridgeFetch(c,{op:'status',receipts:[receipt]});if(poll.code!==200||!poll.data.results||!poll.data.results[receipt])throw Error('Запись сервера не найдена: проверьте перенос/связку');apply(poll.data.results[receipt]);}
          else {
            var a=bridgeId(val('Lead ID')),b=bridgeId(val('TikTok Lead ID'));if(a&&b&&a!==b)throw Error('Колонки Lead ID расходятся');if(!a&&!b)continue;
            var body={lead_id:a||b,name:String(val('Name')||'Customer'),phone:bridgePhone(val('Phone')),campaign_id:bridgeId(val('Campaign ID')),ad_id:bridgeId(val('Ad ID')),adgroup_id:bridgeId(val('Ad Group ID')),advertiser_id:bridgeId(val('Advertiser ID')),form_id:bridgeId(val('Form ID'))};
            write('Webhook_Status','PROCESSING');SpreadsheetApp.flush();
            var r=bridgeFetch(c,body);
            if([200,202,409].indexOf(r.code)<0)throw Error(r.data.message||'HTTP '+r.code);
            apply(r.data);
          }
        }catch(e){write('Webhook_Status','REVIEW');write('Webhook_Message',String(e.message).slice(0,500)+'; RETRY повторит проверку с тем же Lead ID');}
      }
      // Advance only across finalized/nonblank rows; late-populated blank rows remain discoverable.
      statuses=s.getRange(2,statusCol,s.getLastRow()-1,1).getValues();
      var next=2;while(next-2<statuses.length&&String(statuses[next-2][0]||'').trim()!=='')next++;
      p.setProperty('cursor_'+s.getSheetId(),String(next));
    }
  } finally {lock.releaseLock();}
}

// Poll saved receipts only. This never enqueues a lead or changes delivery status.
function bridgeSyncPartnerStatuses(c,s,h,props) {
  var receiptCol=h.indexOf('Bridge Receipt')+1, statusCol=h.indexOf('TikTok Lead Status')+1, updatedCol=h.indexOf('Partner Status Updated')+1;
  if(!receiptCol||!statusCol||s.getLastRow()<2)return;
  var receipts=s.getRange(2,receiptCol,s.getLastRow()-1,1).getValues();
  var key='partner_cursor_'+s.getSheetId(), start=Number(props.getProperty(key)||0);
  if(!Number.isFinite(start)||start<0||start>=receipts.length)start=0;
  var batch=[],ids=[],checked=0;
  while(checked<receipts.length&&batch.length<100){
    var i=(start+checked)%receipts.length,id=String(receipts[i][0]||'').trim();checked++;
    if(id){batch.push({row:i+2,id:id});ids.push(id);}
  }
  if(!batch.length)return;
  var r=bridgeFetch(c,{op:'status',receipts:ids});
  if(r.code!==200||!r.data.results)throw Error('Не удалось обновить статусы ПП: HTTP '+r.code);
  batch.forEach(function(item){
    var result=r.data.results[item.id];if(!result||!result.partner_status)return;
    // Recheck receipt before writing if someone moved a row while the request ran.
    if(String(s.getRange(item.row,receiptCol).getValues()[0][0])!==item.id)return;
    if(String(s.getRange(item.row,statusCol).getValues()[0][0]||'')!==result.partner_status)s.getRange(item.row,statusCol).setValue(result.partner_status);
    if(updatedCol&&String(s.getRange(item.row,updatedCol).getValues()[0][0]||'')!==result.partner_status_updated)s.getRange(item.row,updatedCol).setValue(result.partner_status_updated||'');
  });
  props.setProperty(key,String((start+checked)%receipts.length));
}
