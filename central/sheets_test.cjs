const vm=require('node:vm'),fs=require('node:fs'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/LeadBridge.gs','utf8');
function env(rows,fetch,extraSheets=[]) {
  const props={BRIDGE_URL:'https://bridge.example.com/action.php',ROUTE_ID:'route_abc',BRIDGE_SECRET:'a'.repeat(64)};
  const outputs=['Webhook_Status','Binom Click ID','Partner Reference ID','Webhook_Message','Bridge Receipt','TikTok Lead Status','Partner Status Updated','Bridge Warning'];
  const sheets=[rows,...extraSheets].map((rows,index)=>({
    getSheetId:()=>index+1,getName:()=>"Form "+(index+1),getLastColumn:()=>rows[0].length,getLastRow:()=>rows.length,maxRows:100,
    getMaxRows(){return this.maxRows;},fontColors:{},
    insertRowsAfter(){throw Error('Connector must not allocate input rows');},
    getRangeList(){throw Error('Connector must not format input ranges');},
    getRange(r,c,n=1,m=1){return {
      getValues:()=>Array.from({length:n},(_,i)=>Array.from({length:m},(_,j)=>rows[r-1+i]?.[c-1+j]??'')),
      setValue(v){assert.ok(outputs.includes(r===1?v:rows[0][c-1]),'Only output columns may be written');rows[r-1]??=[];rows[r-1][c-1]=v;return this;},
      setNumberFormat(){throw Error('Connector must not change cell formats');},
      setFontColor(v){assert.ok(['Lead ID','TikTok Lead ID'].includes(rows[0][c-1]),'Only lead IDs may be highlighted');sheets[index].fontColors[r+':'+c]=v;return this;}
    };}
  }));
  sheets.forEach(sheet=>props['sheet_config_'+sheet.getSheetId()]=JSON.stringify({BRIDGE_URL:props.BRIDGE_URL,ROUTE_ID:props.ROUTE_ID,BRIDGE_SECRET:props.BRIDGE_SECRET}));
  props.sheet_config_version='1';
  const ctx={console,Date,Number,JSON,Error,String,Object,Array,PropertiesService:{getScriptProperties:()=>({getProperty:k=>props[k],setProperty:(k,v)=>props[k]=v,getProperties:()=>props,deleteProperty:k=>delete props[k]})},LockService:{getScriptLock:()=>({tryLock:()=>true,releaseLock(){}})},SpreadsheetApp:{getActiveSpreadsheet:()=>({getSheets:()=>sheets,getActiveSheet:()=>sheets[0]}),flush(){}},UrlFetchApp:{fetch:(url,o)=>{let r=fetch(JSON.parse(o.payload),o,url);return {getResponseCode:()=>r.code,getContentText:()=>JSON.stringify(r.data)};}}};vm.createContext(ctx);vm.runInContext(source,ctx);return {ctx,props,sheets};
}
const headers=['Lead ID','Phone','Name','Campaign ID','Ad ID','Ad Group ID','Webhook_Status','Binom Click ID','Partner Reference ID','Webhook_Message','Bridge Receipt'];
let calls=0,rows=[headers.slice(),['9000000000000000001','+254700000001','Test','9000000000000000002','3','4','','','','','']];
let e=env(rows,body=>{calls++;assert.equal(body.lead_id,'9000000000000000001');assert.equal(body.campaign_id,'9000000000000000002');return {code:202,data:{status:'processing',stage:'queued',receipt:'receipt-1'}};});e.ctx.bridgeTick();assert.equal(rows[1][6],'QUEUED');assert.equal(rows[1][10],'receipt-1');assert.equal(calls,1);
e=env(rows,body=>{assert.equal(body.op,'status');return {code:200,data:{results:{'receipt-1':{status:'success',stage:'sent',click_id:'click',partner_reference_id:'partner',receipt:'receipt-1'}}}};});e.ctx.bridgeTick();assert.equal(rows[1][6],'SENT');assert.equal(rows[1][7],'click');
e=env(rows,body=>{assert.equal(body.op,'status');return {code:200,data:{results:{}}};});e.ctx.bridgeTick();
rows=[headers.slice(),['9000000000000000003','','Test','','','','','','','',''],['9000000000000000004','+254700000002','Test','','','','SENT','','','','']];e=env(rows,()=>{throw Error('No ready lead');});e.ctx.bridgeTick();assert.equal(e.props.cursor_1,'2');
rows[1][1]='+254700000001';e=env(rows,()=>({code:202,data:{status:'processing',stage:'queued',receipt:'receipt-2'}}));e.ctx.bridgeTick();assert.equal(rows[1][6],'QUEUED');
rows=[headers.slice(),[9000000000000000001,'+254700000001','Test','','','','','','','','']];e=env(rows,()=>{throw Error('Unsafe ID must not send');});e.ctx.bridgeTick();assert.equal(rows[1][6],'REVIEW');assert.match(rows[1][9],/округлён/);
rows=[headers.slice(),['9000000000000000001','+254700000001','Test','','','','PROCESSING','','','','']];e=env(rows,body=>{assert.equal(body.lead_id,'9000000000000000001');return {code:200,data:{status:'success',stage:'sent',click_id:'existing',partner_reference_id:'existing'}};});e.ctx.bridgeTick();assert.equal(rows[1][6],'SENT');
console.log('PASS: queued receipt, status confirmation, no SENT replay, late row completion, precision rejection, crash replay with same ID');
// Existing sent rows receive PP statuses in bounded batches and never re-enqueue.
rows=[headers.concat(['TikTok Lead Status','Partner Status Updated'])];
for(let i=0;i<105;i++)rows.push([String(i+100),'', 'Test','','','','SENT','click','partner','','receipt-'+i,'','']);
let polled=[];
e=env(rows,body=>{assert.equal(body.op,'status');assert.ok(body.receipts.length<=100);polled.push(...body.receipts);return {code:200,data:{results:Object.fromEntries(body.receipts.map(id=>[id,{partner_status:'approved',partner_status_updated:'2026-09-17T00:00:00Z'}]))}};});
e.ctx.bridgeTick();assert.equal(polled.length,100);assert.equal(rows[1][11],'approved');assert.equal(rows[1][6],'SENT');assert.equal(rows[105][11],'');
e.ctx.bridgeTick();assert.ok(polled.includes('receipt-104'));assert.equal(rows[105][11],'approved');assert.equal(rows[105][6],'SENT');
console.log('PASS: batch bound, rotating cursor, SENT status sync without phone or resubmission');

const extraHeaders=['PLACEMENT','CAMPAIGN_NAME','AID_NAME','CID_NAME','ADID_V2','ADID_V2_NAME'];
rows=[headers.concat(extraHeaders),['9000000000000000005','+254700000001','Test','11','22','33','','','','','', 'TikTok','Campaign','Group','Ad','9000000000000000999','Separate']];
e=env(rows,body=>{assert.equal(body.placement,'TikTok');assert.equal(body.campaign_name,'Campaign');assert.equal(body.adgroup_name,'Group');assert.equal(body.ad_name,'Ad');assert.equal(body.adid_v2,'9000000000000000999');assert.equal(body.adid_v2_name,'Separate');assert.notEqual(body.ad_id,body.adid_v2);return {code:202,data:{status:'processing',stage:'queued',receipt:'extra'}};});e.ctx.bridgeTick();assert.equal(rows[1][6],'QUEUED');
assert.throws(()=>e.ctx.bridgeMetadata(['Campaign ID','CAMPAIGN_ID'],['11','22']),/Разные значения/);
assert.equal(e.ctx.bridgeMetadata(['Client IP'],['8.8.8.8']).ip,'8.8.8.8');
assert.equal(e.ctx.bridgeMetadata(['IP Address'],['2001:4860:4860::8888']).ip,'2001:4860:4860::8888');
assert.throws(()=>e.ctx.bridgeMetadata(['ADID_V2'],[9000000000000000999]),/округлён/);
console.log('PASS all optional metadata, independent V2 values, conflicting aliases and unsafe V2 ID rejected');

// Forms use different routes/secrets and keep independent receipts and status cursors.
const form=id=>[['Lead ID','Phone','Form ID'],[id,'+254700000001',id+'0']];
const one=form('101'),two=form('102'),ignored=[['Notes'],['not a lead']];
let submitted=[],statusCalls=[];
e=env(one,(body,options)=>{
  const second=body.lead_id==='102'||body.receipts?.includes('r102');
  assert.equal(body.route_id,second?'route_def':'route_abc');
  assert.equal(options.headers['X-Bridge-Secret'],(second?'b':'a').repeat(64));
  if(body.op==='status'){
    statusCalls.push(...body.receipts);
    return {code:200,data:{results:Object.fromEntries(body.receipts.map(id=>[id,{partner_status:id==='r101'?'approved':'rejected',partner_status_updated:'2026-09-21T00:00:00Z'}]))}};
  }
  submitted.push(body.lead_id);assert.equal(body.form_id,body.lead_id+'0');
  return {code:200,data:{status:'success',stage:'sent',click_id:'c'+body.lead_id,partner_reference_id:'p'+body.lead_id,receipt:'r'+body.lead_id}};
},[two,ignored]);
e.props.sheet_config_2=JSON.stringify({BRIDGE_URL:e.props.BRIDGE_URL,ROUTE_ID:'route_def',BRIDGE_SECRET:'b'.repeat(64)});
e.ctx.bridgeTick();assert.deepEqual(submitted,['101','102']);
assert.equal(one[1][one[0].indexOf('Bridge Receipt')],'r101');assert.equal(two[1][two[0].indexOf('Bridge Receipt')],'r102');
// Reordering/renaming tabs doesn't change their IDs or mix up status writes.
e.sheets.reverse();e.ctx.bridgeTick();assert.equal(submitted.length,2);
assert.equal(one[1][one[0].indexOf('TikTok Lead Status')],'approved');assert.equal(two[1][two[0].indexOf('TikTok Lead Status')],'rejected');
assert.deepEqual(statusCalls.sort(),['r101','r102']);assert.equal(ignored[0].length,1);
assert.equal(e.props.cursor_1,'3');assert.equal(e.props.cursor_2,'3');
console.log('PASS: configured tabs auto-prepare, independent routes and secrets, independent receipts/statuses, reorder, unrelated tabs skipped');

// A protected/broken tab must not prevent later forms from sending.
let errors=[];submitted=[];
e=env(form('201'),body=>{submitted.push(body.lead_id);return {code:202,data:{status:'processing',receipt:'r'+body.lead_id}};},[form('202')]);
e.ctx.console={error:m=>errors.push(m)};e.sheets[0].getRange=()=>{throw Error('Protected sheet');};
e.ctx.bridgeTick();assert.deepEqual(submitted,['202']);assert.equal(errors.length,1);

// Busy first tab cannot monopolize a tick; pending rows cannot starve new rows.
const busy=[['Lead ID','Phone']];for(let i=1;i<=30;i++)busy.push([String(300+i),'+254700000001']);
submitted=[];
e=env(busy,body=>{
  if(body.op==='status')return {code:200,data:{results:Object.fromEntries(body.receipts.map(id=>[id,{status:'processing'}]))}};
  submitted.push(body.lead_id);return {code:202,data:{status:'processing',receipt:'r'+body.lead_id}};
},[form('401')]);
e.ctx.bridgeTick();assert.equal(submitted.length,26);assert.ok(submitted.includes('401'));assert.ok(!submitted.includes('330'));
e.ctx.bridgeTick();assert.ok(submitted.includes('330'));assert.equal(submitted.length,31);

// When a fetch consumes the time budget, the next run starts on the next tab.
let clock=0;submitted=[];
e=env(form('501'),body=>{submitted.push(body.lead_id);clock+=230000;return {code:202,data:{status:'processing',receipt:'r'+body.lead_id}};},[form('502')]);
e.ctx.Date={now:()=>clock};e.ctx.bridgeTick();assert.deepEqual(submitted,['501']);
e.ctx.bridgeTick();assert.deepEqual(submitted,['501','502']);
console.log('PASS: sheet error isolation, per-sheet work bound, row fairness and deadline rotation');

// Legacy migration only preserves tabs with a known processing cursor, never fresh copies.
e=env(form('601'),()=>{throw Error('Migration must not send');},[form('602')]);
Object.keys(e.props).filter(k=>k.startsWith('sheet_config')).forEach(k=>delete e.props[k]);
e.props.cursor_1='2';e.ctx.bridgeMigrateSheets();
assert.equal(e.ctx.bridgeConfig(e.sheets[0]).ROUTE_ID,'route_abc');assert.equal(e.ctx.bridgeConfig(e.sheets[1]),null);
e.props.cursor_2='2';e.ctx.bridgeMigrateSheets();assert.equal(e.ctx.bridgeConfig(e.sheets[1]),null);
// Explicit configuration of fresh tab uses check only and resets stale cursors.
let configChecks=0;
e=env(form('701'),body=>{assert.equal(body.op,'check');configChecks++;return {code:200,data:{status:'ok',active:true}};});
const config={BRIDGE_URL:e.props.BRIDGE_URL,ROUTE_ID:'route_def',BRIDGE_SECRET:'b'.repeat(64)};
assert.throws(()=>e.ctx.bridgeSaveSheetConfig(e.sheets[0],config),/На листе есть/);
delete e.props.sheet_config_1;e.props.cursor_1='100';e.ctx.bridgeSaveSheetConfig(e.sheets[0],config);
assert.equal(e.ctx.bridgeConfig(e.sheets[0]).ROUTE_ID,'route_def');assert.equal(e.props.cursor_1,undefined);assert.equal(configChecks,1);
// Secret rotation is allowed without changing route or clearing progress.
e.props.cursor_1='7';e.ctx.bridgeSaveSheetConfig(e.sheets[0],{...config,BRIDGE_SECRET:'c'.repeat(64)});assert.equal(e.props.cursor_1,'7');
const history=[headers.slice(),['801','+254700000001','','','','','SENT','click','partner','','receipt']];
e=env(history,()=>{throw Error('Copied history must not call server');});delete e.props.sheet_config_1;
assert.throws(()=>e.ctx.bridgeSaveSheetConfig(e.sheets[0],config),/На листе есть/);
// Missing config is skipped even if the copied sheet contains queued receipts.
e.ctx.bridgeTick();assert.equal(history[1][6],'SENT');
// Fresh empty tab can change campaign before any lead arrives.
e=env([['Lead ID','Phone']],()=>({code:200,data:{status:'ok'}}));e.ctx.bridgeSaveSheetConfig(e.sheets[0],config);assert.equal(e.ctx.bridgeConfig(e.sheets[0]).ROUTE_ID,'route_def');
console.log('PASS: legacy migration, unconfigured copies skipped, explicit per-sheet setup, history protection, secret rotation');

// Damaged optional Form ID does not block delivery; source values/formats stay untouched.
const precisionHeaders=['Lead ID','Phone','Form ID','Adset ID','ADID_V2','Notes'];
e=env([precisionHeaders.slice()],()=>{throw Error('Empty tabs must not send');},[[...['Lead ID','Phone']]]);
delete e.props.sheet_config_2;e.ctx.bridgeTick();
assert.equal(e.sheets[0].maxRows,100);
const damaged=[precisionHeaders.slice(),['9000000000000000001','+254700000001',9000000000000000002,'1','2','']];
let damagedSends=0;
e=env(damaged,body=>{damagedSends++;assert.ok(!('form_id' in body));return {code:202,data:{status:'processing',receipt:'damaged-form'}};});e.ctx.bridgeTick();
assert.equal(damagedSends,1);
assert.equal(damaged[1][damaged[0].indexOf('Webhook_Status')],'QUEUED');
assert.match(damaged[1][damaged[0].indexOf('Bridge Warning')],/Form ID: ID округлён/);
assert.equal(e.sheets[0].fontColors['2:1'],'#b91c1c');
assert.equal(typeof damaged[1][2],'number');
e.ctx.UrlFetchApp.fetch=(url,o)=>{const body=JSON.parse(o.payload);assert.equal(body.op,'status');return {getResponseCode:()=>200,getContentText:()=>JSON.stringify({results:{'damaged-form':{status:'success',stage:'sent',click_id:'c',partner_reference_id:'p',receipt:'damaged-form'}}})};};
e.ctx.bridgeTick();assert.equal(damaged[1][damaged[0].indexOf('Webhook_Status')],'SENT');
assert.match(damaged[1][damaged[0].indexOf('Bridge Warning')],/Form ID/);
e.ctx.bridgeTick();assert.equal(damagedSends,1);
e=env([['Lead ID','Phone']],()=>({code:200,data:{status:'ok'}}));
e.ctx.bridgeSaveSheetConfig(e.sheets[0],{BRIDGE_URL:e.props.BRIDGE_URL,ROUTE_ID:'route_abc',BRIDGE_SECRET:'a'.repeat(64)});
assert.equal(e.sheets[0].maxRows,100);e.ctx.bridgeTick();
const wide=Array.from({length:28},(_,i)=>'Notes '+i);wide[0]='Lead ID';wide[1]='Phone';wide[26]='Form ID';wide[27]='ad_group_id';
e=env([wide],()=>{throw Error('Empty tab must not send');});e.ctx.bridgeTick();
assert.equal(wide[26],'Form ID');assert.equal(wide[27],'ad_group_id');
console.log('PASS: damaged Form ID omitted, red Lead ID and persistent warning through QUEUED/SENT, no replay, source values/formats unchanged');

// Missing IDs are normal; exponent strings, unsafe numbers and conflicting aliases warn.
for(const value of ['7.69907E+18','invalid',-1,9000000000000000123]){
  const input=[['TikTok Lead ID','Phone','Form ID'],['9000000000000000001','+254700000001',value]];
  e=env(input,body=>{assert.equal(body.lead_id,input[1][0]);assert.ok(!('form_id' in body));return {code:200,data:{status:'success',stage:'sent',click_id:'c',partner_reference_id:'p',receipt:'warn'}};});
  e.ctx.bridgeTick();assert.equal(input[1][input[0].indexOf('Webhook_Status')],'SENT');assert.equal(input[1][2],value);assert.equal(e.sheets[0].fontColors['2:1'],'#b91c1c');
}
assert.equal(e.ctx.bridgeFormId(['Form ID'],['']).warning,'');
assert.equal(e.ctx.bridgeFormId(['Lead ID'],['1']).warning,'');
assert.match(e.ctx.bridgeFormId(['Form ID','FORM_ID'],['1','2']).warning,/Разные значения/);
assert.equal(e.ctx.bridgeMetadata(['Form ID','FORM_ID'],['1','2']).form_id,undefined);
assert.equal(e.ctx.bridgeMetadata(['Form ID'],['9000000000000000123']).form_id,'9000000000000000123');
// Corrected pending rows clear only the warning/highlight; polling never re-enqueues.
const corrected=[['Lead ID','Phone','Form ID','Bridge Receipt','Webhook_Status','Bridge Warning'],['1','+254700000001','123','saved','QUEUED','old warning']];
e=env(corrected,body=>{assert.equal(body.op,'status');return {code:200,data:{results:{saved:{status:'processing',receipt:'saved'}}}};});
e.ctx.bridgeTick();assert.equal(corrected[1][5],'');assert.equal(e.sheets[0].fontColors['2:1'],null);
// Protected font formatting must not turn a successful send into REVIEW.
const protectedRow=[['Lead ID','Phone','Form ID'],['1','+254700000001','7.69907E+18']];
e=env(protectedRow,()=>({code:202,data:{status:'processing',receipt:'protected'}}));
const originalRange=e.sheets[0].getRange;
e.sheets[0].getRange=function(...args){const range=originalRange.apply(this,args);range.setFontColor=()=>{throw Error('Protected');};return range;};
e.ctx.console={error(){}};e.ctx.bridgeTick();assert.equal(protectedRow[1][protectedRow[0].indexOf('Webhook_Status')],'QUEUED');
assert.match(protectedRow[1][protectedRow[0].indexOf('Bridge Warning')],/Form ID/);
// Unsafe required Lead ID and other metadata IDs still block, even with a bad Form ID.
for(const bad of ['Lead ID','Campaign ID','Ad ID','Ad Group ID','Advertiser ID','ADID_V2']){
  const input=[['Lead ID','Phone','Form ID',...(bad==='Lead ID'?[]:[bad])],['1','+254700000001','7.69907E+18',...(bad==='Lead ID'?[]:[9000000000000000123])]];
  if(bad==='Lead ID')input[1][0]=9000000000000000123;
  e=env(input,()=>{throw Error('Unsafe identity must not send');});e.ctx.bridgeTick();assert.equal(input[1][input[0].indexOf('Webhook_Status')],'REVIEW');
}
console.log('PASS: optional malformed/conflicting/missing/exact Form IDs, warning cleanup, protected styling, required and other ID checks remain strict');

// New text-column names support deployed test sheets and take explicit per-row priority.
for(const name of ['Form ID text','Form ID text test','FORM_ID_TEXT']){
  const input=[['Lead ID','Phone','Form ID',name],['1','+254700000001',9000000000000000123,'9000000000000000123']];
  e=env(input,body=>{assert.equal(body.form_id,'9000000000000000123');return {code:202,data:{status:'processing',receipt:'text-column'}};});
  e.ctx.bridgeTick();assert.equal(input[1][input[0].indexOf('Webhook_Status')],'QUEUED');assert.equal(input[1][input[0].indexOf('Bridge Warning')],'');assert.equal(typeof input[1][2],'number');
}
assert.equal(e.ctx.bridgeFormId(['Form ID','Form ID text'],['123','']).value,'123');
assert.equal(e.ctx.bridgeFormId(['Form ID','Form ID text'],['123','456']).value,'456');
assert.match(e.ctx.bridgeFormId(['Form ID','Form ID text'],['123','7.69907E+18']).warning,/Form ID text/);
assert.equal(e.ctx.bridgeFormId(['Form ID','Form ID text'],['123','7.69907E+18']).value,'');
assert.match(e.ctx.bridgeFormId(['Form ID text','Form ID text test'],['123','456']).warning,/Разные значения/);
assert.equal(e.ctx.bridgeFormId(['Form ID text','Form ID text test'],['123','123']).value,'123');
console.log('PASS: text header aliases, exact outgoing form_id, legacy fallback only for empty text fields, bad/conflicting text fields warn without guessing');

// Enabling the single trigger prepares every configured tab before scheduling any sends.
e=env([['Lead ID','Phone']],()=>{throw Error('Enabling must not send');},[[ 'Lead ID','Phone' ],['Notes']].map(h=>[h]));
delete e.props.sheet_config_3;
let created=0;
e.ctx.LockService.getScriptLock=()=>({waitLock(){},releaseLock(){}});
e.ctx.SpreadsheetApp.getUi=()=>({Button:{YES:'yes'},ButtonSet:{YES_NO:'yes_no'},alert:()=> 'yes'});
e.ctx.ScriptApp={getProjectTriggers:()=>[],newTrigger(name){assert.equal(name,'bridgeTick');assert.ok(e.sheets[0].getLastColumn()>2);assert.ok(e.sheets[1].getLastColumn()>2);assert.equal(e.sheets[2].getLastColumn(),1);return {timeBased(){return this;},everyMinutes(n){assert.equal(n,1);return this;},create(){created++;}};}};
e.ctx.bridgeEnable();assert.equal(created,1);assert.ok(e.sheets.every(s=>s.maxRows===100));
console.log('PASS: enabling prepares all configured sheets before creating one shared trigger, no lead submissions');


// Preparation adds output headers without touching existing source values or cell formats.
const sourceRows=[['Lead ID','Phone','Form ID'],['9000000000000000001','+254700000001','9000000000000000123'],['9000000000000000002','+254700000002',9000000000000000123]];
e=env(sourceRows,()=>{throw Error('Preparation must never submit');});
const before=sourceRows.map(r=>r.slice(0,3));e.ctx.bridgePrepareSheet(e.sheets[0]);
assert.deepEqual(sourceRows.map(r=>r.slice(0,3)),before);
assert.equal(e.sheets[0].maxRows,100);
e.props.sheet_form_id_1='1111111111111111111';
assert.equal(e.ctx.bridgeMetadata(['Form ID'],[9000000000000000123]).form_id,undefined);
assert.match(e.ctx.bridgeFormId(['Form ID'],[9000000000000000123]).warning,/округлён/);
assert.equal(typeof e.ctx.bridgeConfigureForm,'undefined');
assert.ok(!source.includes('bridgeApplyForm'));
// Once a connector appends an exact string, forward it unchanged as a string.
const newRows=[['Lead ID','Phone','Form ID']];
e=env(newRows,body=>{assert.equal(body.lead_id,'9000000000000000003');assert.equal(body.form_id,'9000000000000000123');return {code:202,data:{status:'processing',receipt:'future'}};});
e.ctx.bridgePrepareSheet(e.sheets[0]);
newRows.push(['9000000000000000003','+254700000001','9000000000000000123']);
e.ctx.bridgeTick();assert.equal(newRows[1][newRows[0].indexOf('Webhook_Status')],'QUEUED');
assert.equal(newRows[1][0],'9000000000000000003');assert.equal(newRows[1][2],'9000000000000000123');
console.log('PASS: preparation and enqueue preserve exact incoming strings and numeric input; output writes only; no manual Form ID');

// onOpen only creates a menu: opening an input workbook cannot mutate its grid or call the server.
e=env([['Lead ID','Phone','Form ID']],()=>{throw Error('onOpen preparation must never send');},[[['Lead ID','Phone']]]);
const menu={addItem(){return this;},addToUi(){}};
e.ctx.SpreadsheetApp={getUi:()=>({createMenu:()=>menu}),getActiveSpreadsheet(){throw Error('onOpen must not access the input grid');}};
e.ctx.onOpen();
console.log('PASS: onOpen creates menu only, no input access or lead requests');
