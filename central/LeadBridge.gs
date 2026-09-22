// Lead Bridge central connector. IDs must arrive as text, before TikTok writes them.
function onOpen() {
  SpreadsheetApp.getUi().createMenu('Lead Bridge')
    .addItem('Настроить', 'bridgeConfigure')
    .addItem('Подготовить и проверить', 'bridgeSetup')
    .addItem('Подключённые листы', 'bridgeConnections')
    .addItem('Включить отправку раз в минуту', 'bridgeEnable')
    .addItem('Выключить мой триггер', 'bridgeDisable')
    .addItem('Пересканировать строки', 'bridgeRescan').addToUi();
}
function bridgeValidateConfig(c) {
  if (!c || !c.BRIDGE_URL || !/^https:\/\/[^\s]+$/.test(c.BRIDGE_URL) || !/^route_[a-f0-9]+$/.test(c.ROUTE_ID) || !/^[a-f0-9]{64}$/.test(c.BRIDGE_SECRET)) throw Error('Некорректные настройки');
  return {BRIDGE_URL:c.BRIDGE_URL,ROUTE_ID:c.ROUTE_ID,BRIDGE_SECRET:c.BRIDGE_SECRET};
}
// Called under the script lock. Only previously processed legacy tabs inherit the old route.
// Copies have new sheet IDs and no cursor, so they must be explicitly configured.
function bridgeMigrateSheets() {
  var p=PropertiesService.getScriptProperties();
  if(p.getProperty('sheet_config_version'))return;
  var legacy=null;
  if(p.getProperty('ROUTE_ID'))legacy=bridgeValidateConfig({BRIDGE_URL:p.getProperty('BRIDGE_URL'),ROUTE_ID:p.getProperty('ROUTE_ID'),BRIDGE_SECRET:p.getProperty('BRIDGE_SECRET')});
  if(legacy)SpreadsheetApp.getActiveSpreadsheet().getSheets().forEach(function(s){
    var id=s.getSheetId(),key='sheet_config_'+id;
    if(!p.getProperty(key) && (p.getProperty('cursor_'+id) || p.getProperty('partner_cursor_'+id)))p.setProperty(key,JSON.stringify(legacy));
  });
  p.setProperty('sheet_config_version','1');
}
function bridgeConfig(sheet) {
  var raw=PropertiesService.getScriptProperties().getProperty('sheet_config_'+sheet.getSheetId());
  return raw?bridgeValidateConfig(JSON.parse(raw)):null;
}
function bridgeSaveSheetConfig(sheet,c) {
  c=bridgeValidateConfig(c);
  var old=bridgeConfig(sheet),changed=old && (old.ROUTE_ID!==c.ROUTE_ID || old.BRIDGE_URL!==c.BRIDGE_URL);
  var h=sheet.getLastColumn()?sheet.getRange(1,1,1,sheet.getLastColumn()).getValues()[0].map(function(v){return String(v).trim();}):[];
  if(h.indexOf('Phone')<0 || (h.indexOf('Lead ID')<0 && h.indexOf('TikTok Lead ID')<0))throw Error('Добавьте шапку Phone и Lead ID / TikTok Lead ID на выбранный лист');
  if(sheet.getLastRow()>1){
    var columns=changed?['Lead ID','TikTok Lead ID','Phone','Webhook_Status','Bridge Receipt']:(!old?['Webhook_Status','Bridge Receipt','Binom Click ID','Partner Reference ID','TikTok Lead Status']:[]);
    columns.forEach(function(k){var i=h.indexOf(k);if(i>=0 && sheet.getRange(2,i+1,sheet.getLastRow()-1,1).getValues().some(function(row){return String(row[0]||'').trim()!=='';}))throw Error('На листе есть лиды или история обработки. Для другой кампании создайте пустой лист; не копируйте старые строки.');});
  }
  var check=bridgeFetch(c,{op:'check'});
  if(check.code!==200 || check.data.status!=='ok')throw Error('Не удалось проверить подключение');
  var p=PropertiesService.getScriptProperties(),id=sheet.getSheetId();
  p.setProperty('sheet_config_'+id,JSON.stringify(c));
  if(!old || changed)['cursor_','work_cursor_','partner_cursor_'].forEach(function(prefix){p.deleteProperty(prefix+id);});
}
function bridgeConfigure() {
  var ui=SpreadsheetApp.getUi(),sheet=SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
  var r=ui.prompt('Настройка листа «'+sheet.getName()+'»', 'Вставьте JSON связки для кампании этого листа. Другие листы сохранят свои настройки.', ui.ButtonSet.OK_CANCEL);
  if(r.getSelectedButton()!==ui.Button.OK)return;
  var c=bridgeValidateConfig(JSON.parse(r.getResponseText()));
  var lock=LockService.getScriptLock();lock.waitLock(10000);
  try {bridgeMigrateSheets();bridgeSaveSheetConfig(sheet,c);} finally {lock.releaseLock();}
  ui.alert('Лист «'+sheet.getName()+'» подключён. Выполните «Подготовить и проверить». Один триггер обслуживает все подключённые листы.');
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
  if(prepare) ['Webhook_Status','Binom Click ID','Partner Reference ID','Webhook_Message','Bridge Receipt','TikTok Lead Status','Partner Status Updated'].forEach(function(k){if(h.indexOf(k)<0){h.push(k);sheet.getRange(1,h.length,sheet.getMaxRows(),1).setNumberFormat('@');sheet.getRange(1,h.length).setValue(k);}});
  if(h.indexOf('Webhook_Status')<0 || h.indexOf('Bridge Receipt')<0)throw Error('Сначала выполните подготовку таблицы');
  return h;
}
function bridgePrepareSheet(s) {
  var h=bridgeHeaders(s,true);if(!h)throw Error('На выбранном листе нужны Phone и Lead ID / TikTok Lead ID');
  ['Lead ID','TikTok Lead ID','Phone','Campaign ID','Ad ID','Ad Group ID','Advertiser ID','Form ID','ADID_V2','Binom Click ID','Partner Reference ID','Bridge Receipt'].forEach(function(k){var i=h.indexOf(k);if(i>=0)s.getRange(1,i+1,s.getMaxRows(),1).setNumberFormat('@');});
}
function bridgeSetup() {
  var s=SpreadsheetApp.getActiveSpreadsheet().getActiveSheet(),lock=LockService.getScriptLock();lock.waitLock(10000);
  var r;
  try {
    bridgeMigrateSheets();var c=bridgeConfig(s);
    if(!c)throw Error('Этот лист ещё не подключён. Откройте Lead Bridge → Настроить и вставьте JSON его связки');
    r=bridgeFetch(c,{op:'check'});
    if(r.code!==200 || r.data.status!=='ok')throw Error('Ошибка подключения');
    bridgePrepareSheet(s);
  } finally {lock.releaseLock();}
  SpreadsheetApp.getUi().alert('Лист «'+s.getName()+'» подготовлен. Связка '+(r.data.active?'активна':'на паузе')+'. Лиды не отправлялись. Остальные листы настраиваются отдельно; триггер общий.');
}
function bridgeConnections() {
  var lock=LockService.getScriptLock();lock.waitLock(10000);var lines;
  try {bridgeMigrateSheets();lines=SpreadsheetApp.getActiveSpreadsheet().getSheets().map(function(s){var c=bridgeConfig(s);return s.getName()+' → '+(c?c.ROUTE_ID:'не подключён');});} finally {lock.releaseLock();}
  SpreadsheetApp.getUi().alert('Подключённые листы',lines.join('\n'),SpreadsheetApp.getUi().ButtonSet.OK);
}
function bridgeDisable() {ScriptApp.getProjectTriggers().forEach(function(t){if(t.getHandlerFunction()==='bridgeTick')ScriptApp.deleteTrigger(t);});}
function bridgeEnable() {
  var legacy=ScriptApp.getProjectTriggers().some(function(t){return t.getHandlerFunction()==='sendLeadsToLeadPhp';});
  if(legacy)throw Error('Сначала удалите старый триггер sendLeadsToLeadPhp');
  var ui=SpreadsheetApp.getUi();
  if(ui.alert('Включить отправку?', 'Один триггер будет отправлять лиды со всех подключённых листов, каждый в свою связку. Новые копии листов нужно подключать отдельно. Убедитесь, что старые триггеры других владельцев отключены.',ui.ButtonSet.YES_NO)!==ui.Button.YES)return;
  var lock=LockService.getScriptLock();lock.waitLock(10000);
  try {
    bridgeMigrateSheets();
    if(!SpreadsheetApp.getActiveSpreadsheet().getSheets().some(function(s){return !!bridgeConfig(s);}))throw Error('Сначала настройте хотя бы один лист через Lead Bridge → Настроить');
    bridgeDisable();ScriptApp.newTrigger('bridgeTick').timeBased().everyMinutes(1).create();
  } finally {lock.releaseLock();}
}
function bridgeRescan(){
  var lock=LockService.getScriptLock();lock.waitLock(10000);
  try {bridgeMigrateSheets();var p=PropertiesService.getScriptProperties();Object.keys(p.getProperties()).forEach(function(k){if(k.indexOf('cursor_')===0 || k.indexOf('work_cursor_')===0)p.deleteProperty(k);});} finally {lock.releaseLock();}
  SpreadsheetApp.getUi().alert('Следующий запуск пересканирует строки подключённых листов. SENT остаются нетронутыми.');
}
function bridgeId(raw) {
  if(raw===''||raw===null||raw===undefined)return '';
  if(typeof raw==='number' && (!Number.isSafeInteger(raw)||raw<0))throw Error('ID округлён: восстановите исходное значение как текст');
  var s=String(raw).trim();if(!/^\d{1,40}$/.test(s))throw Error('ID должен быть строкой цифр');return s;
}
function bridgePhone(raw){var s=String(raw||'').trim();if(/[eE]/.test(s))throw Error('Телефон повреждён');var d=s.replace(/[^0-9]/g,'').replace(/^00/,'');if(!/^[1-9][0-9]{6,14}$/.test(d))throw Error('Телефон должен содержать код страны');return '+'+d;}
// Alias matching is case-insensitive and ignores spaces/underscores; conflicting values fail closed.
function bridgeMetaValue(h,row,names,id) {
  var normalize=function(v){return String(v).trim().toLowerCase().replace(/[ _-]/g,'');};
  var accepted=names.map(normalize),values=[];
  h.forEach(function(header,i){if(accepted.indexOf(normalize(header))>=0 && row[i]!=='' && row[i]!==null && row[i]!==undefined){
    var value=id?bridgeId(row[i]):String(row[i]).trim();
    if(value && values.indexOf(value)<0)values.push(value);
  }});
  if(values.length>1)throw Error('Разные значения в колонках '+names.join(' / '));
  return values[0]||'';
}
function bridgeMetadata(h,row) {
  var fields={ip:['Client IP','IP Address','IP'],campaign_id:['Campaign ID'],adgroup_id:['Ad Group ID','Adgroup ID','Adset ID'],ad_id:['Ad ID'],advertiser_id:['Advertiser ID'],form_id:['Form ID'],placement:['Placement'],campaign_name:['Campaign Name'],adgroup_name:['Ad Group Name','Adgroup Name','Adset Name','AID_NAME'],ad_name:['Ad Name','CID_NAME'],adid_v2:['ADID_V2'],adid_v2_name:['ADID_V2_NAME']};
  var out={};Object.keys(fields).forEach(function(k){var v=bridgeMetaValue(h,row,fields[k],['campaign_id','adgroup_id','ad_id','advertiser_id','form_id','adid_v2'].indexOf(k)>=0);if(v!=='')out[k]=v;});return out;
}
function bridgeTick() {
  var lock=LockService.getScriptLock();if(!lock.tryLock(1000))return;
  var start=Date.now(),deadline=start+220000,p=PropertiesService.getScriptProperties();
  try {
    bridgeMigrateSheets();
    var sheets=SpreadsheetApp.getActiveSpreadsheet().getSheets();
    // Rotate the first sheet so a time limit never permanently starves later tabs.
    var first=Number(p.getProperty('sheet_cursor')||0);
    if(!Number.isInteger(first)||first<0||first>=sheets.length)first=0;
    for(var si=0;si<sheets.length && Date.now()<deadline;si++){
      var index=(first+si)%sheets.length,s=sheets[index];
      p.setProperty('sheet_cursor',String((index+1)%sheets.length));
      try {
      // Unconfigured copies never inherit the route of their source tab.
      var c=bridgeConfig(s);if(!c)continue;
      var h=bridgeHeaders(s,true);if(!h||s.getLastRow()<2)continue;
      if(h.indexOf('TikTok Lead Status')>=0){try{bridgeSyncPartnerStatuses(c,s,h,p);}catch(syncError){console.error('Статусы ПП: '+syncError.message);}}
      var statusCol=h.indexOf('Webhook_Status')+1, receiptCol=h.indexOf('Bridge Receipt')+1;
      // Read a single status column to recover unfinished rows without rereading every lead's PII.
      var statuses=s.getRange(2,statusCol,s.getLastRow()-1,1).getValues();
      var cursor=Math.max(2,Number(p.getProperty('cursor_'+s.getSheetId())||2)), rows=[];
      for(var j=0;j<statuses.length;j++){var state=String(statuses[j][0]||'').trim();if(['QUEUED','PROCESSING','RETRY'].indexOf(state)>=0 || (!state && j+2>=cursor))rows.push(j+2);}
      // Bound work per sheet, rotating rows too: pending receipts must not block new leads.
      var workKey='work_cursor_'+s.getSheetId(),workStart=Number(p.getProperty(workKey)||2);
      var split=rows.findIndex(function(n){return n>=workStart;});
      if(split>0)rows=rows.slice(split).concat(rows.slice(0,split));
      for(var ri=0;ri<rows.length && ri<25 && Date.now()<deadline;ri++){
        var rowNum=rows[ri], row=s.getRange(rowNum,1,1,h.length).getValues()[0];
        p.setProperty(workKey,String(rowNum+1));
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
            var body={lead_id:a||b,name:String(val('Name')||'Customer'),phone:bridgePhone(val('Phone'))};
            var metadata=bridgeMetadata(h,row);Object.keys(metadata).forEach(function(k){body[k]=metadata[k];});
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
      } catch(sheetError) {
        console.error('Lead Bridge: ошибка листа '+s.getSheetId()+': '+sheetError.message);
      }
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
