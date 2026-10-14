const vm=require('node:vm'),fs=require('node:fs'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/LeadBridge.gs','utf8');
function env(rows,fetch,extraSheets=[]) {
  const props={BRIDGE_URL:'https://bridge.example.com/action.php',ROUTE_ID:'route_abc',BRIDGE_SECRET:'a'.repeat(64)};
  const formats=[];
  const sheets=[rows,...extraSheets].map((rows,index)=>({getSheetId:()=>index+1,getName:()=>"Form "+(index+1),getLastColumn:()=>rows[0].length,getLastRow:()=>rows.length,getMaxRows:()=>100,getRangeList(ranges){return {setNumberFormat(format){formats.push({sheet:index+1,ranges:[...ranges],format});return this;}};},getRange(r,c,n=1,m=1){return {getValues:()=>Array.from({length:n},(_,i)=>Array.from({length:m},(_,j)=>rows[r-1+i]?.[c-1+j]??'')),setValue(v){rows[r-1]??=[];rows[r-1][c-1]=v;return this;},setNumberFormat(){return this;}};}}));
  sheets.forEach(sheet=>props['sheet_config_'+sheet.getSheetId()]=JSON.stringify({BRIDGE_URL:props.BRIDGE_URL,ROUTE_ID:props.ROUTE_ID,BRIDGE_SECRET:props.BRIDGE_SECRET}));
  props.sheet_config_version='1';
  const ctx={console,Date,Number,JSON,Error,String,Object,Array,PropertiesService:{getScriptProperties:()=>({getProperty:k=>props[k],setProperty:(k,v)=>props[k]=v,getProperties:()=>props,deleteProperty:k=>delete props[k]})},LockService:{getScriptLock:()=>({tryLock:()=>true,releaseLock(){}})},SpreadsheetApp:{getActiveSpreadsheet:()=>({getSheets:()=>sheets,getActiveSheet:()=>sheets[0]}),flush(){}},UrlFetchApp:{fetch:(url,o)=>{let r=fetch(JSON.parse(o.payload),o,url);return {getResponseCode:()=>r.code,getContentText:()=>JSON.stringify(r.data)};}}};vm.createContext(ctx);vm.runInContext(source,ctx);return {ctx,props,sheets,formats};
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

// Format the entire available column range before exports, including empty tabs and aliases.
const precisionHeaders=['Lead ID','Phone','Form ID','Adset ID','ADID_V2','Notes'];
e=env([precisionHeaders.slice()],()=>{throw Error('Empty tabs must not send');},[[...['Lead ID','Phone']]]);
delete e.props.sheet_config_2;e.ctx.bridgeTick();
assert.deepEqual(e.formats,[{sheet:1,ranges:['A1:A100','B1:B100','C1:C100','D1:D100','E1:E100','H1:H100','I1:I100','K1:K100'],format:'@'}]);
const damaged=[precisionHeaders.slice(),['9000000000000000001','+254700000001',9000000000000000002,'1','2','']];
e=env(damaged,()=>{throw Error('Formatting must not hide lost precision');});e.ctx.bridgeTick();
assert.equal(damaged[1][damaged[0].indexOf('Webhook_Status')],'REVIEW');
assert.match(damaged[1][damaged[0].indexOf('Webhook_Message')],/Form ID: ID округлён/);
assert.equal(typeof damaged[1][2],'number');
e=env([['Lead ID','Phone']],()=>({code:200,data:{status:'ok'}}));
e.ctx.bridgeSaveSheetConfig(e.sheets[0],{BRIDGE_URL:e.props.BRIDGE_URL,ROUTE_ID:'route_abc',BRIDGE_SECRET:'a'.repeat(64)});
assert.ok(e.formats.some(f=>f.ranges.includes('A1:A100')&&f.ranges.includes('B1:B100')));
e.sheets[0].getMaxRows=()=>200;e.formats.length=0;e.ctx.bridgeTick();
assert.ok(e.formats[0].ranges.includes('A1:A200'));
const wide=Array.from({length:28},(_,i)=>'Notes '+i);wide[0]='Lead ID';wide[1]='Phone';wide[26]='Form ID';wide[27]='ad_group_id';
e=env([wide],()=>{throw Error('Empty tab must not send');});e.ctx.bridgeTick();
assert.ok(e.formats[0].ranges.includes('AA1:AA100'));assert.ok(e.formats[0].ranges.includes('AB1:AB100'));
console.log('PASS: text columns prepared on configure and empty ticks, aliases and columns after Z, capacity growth, unconfigured tabs skipped, rounded numbers still rejected with field name');

// Enabling the single trigger prepares every configured tab before scheduling any sends.
e=env([['Lead ID','Phone']],()=>{throw Error('Enabling must not send');},[[ 'Lead ID','Phone' ],['Notes']].map(h=>[h]));
delete e.props.sheet_config_3;
let created=0;
e.ctx.LockService.getScriptLock=()=>({waitLock(){},releaseLock(){}});
e.ctx.SpreadsheetApp.getUi=()=>({Button:{YES:'yes'},ButtonSet:{YES_NO:'yes_no'},alert:()=> 'yes'});
e.ctx.ScriptApp={getProjectTriggers:()=>[],newTrigger(name){assert.equal(name,'bridgeTick');assert.equal(e.formats.length,2);return {timeBased(){return this;},everyMinutes(n){assert.equal(n,1);return this;},create(){created++;}};}};
e.ctx.bridgeEnable();assert.equal(created,1);assert.deepEqual(e.formats.map(f=>f.sheet),[1,2]);
console.log('PASS: enabling prepares all configured sheets before creating one shared trigger, no lead submissions');

// A sheet's explicitly pinned original Form ID can replace a number supplied by the connector.
const exactForm='9000000000000000123';
rows=[['Lead ID','Phone','Form ID'],['9000000000000000001','+254700000001',Number(exactForm)]];
let sentForm=[];e=env(rows,body=>{sentForm.push(body.form_id);return {code:202,data:{status:'processing',receipt:'r-form'}};});
e.ctx.bridgePrepareSheet(e.sheets[0]);e.ctx.bridgeSetForm(e.sheets[0],exactForm);e.ctx.bridgeTick();
assert.deepEqual(sentForm,[exactForm]);assert.equal(rows[1][2],exactForm);assert.equal(typeof rows[1][2],'string');
assert.throws(()=>e.ctx.bridgeSetForm(e.sheets[0],'9000000000000000124'),/нельзя переключать/);
rows=[['Lead ID','Phone','Form ID'],['9000000000000000002','+254700000001','9000000000000000124']];
e=env(rows,()=>{throw Error('Conflicting form must not send');});e.ctx.bridgePrepareSheet(e.sheets[0]);
assert.throws(()=>e.ctx.bridgeSetForm(e.sheets[0],exactForm),/другой точный Form ID/);assert.equal(e.props.sheet_form_id_1,undefined);
rows=[['Lead ID','Phone','Form ID'],['9000000000000000003','+254700000001','9,0000E+18']];
e=env(rows,body=>{assert.equal(body.form_id,exactForm);return {code:202,data:{status:'processing',receipt:'r-exp'}};});
e.props.sheet_form_id_1=exactForm;e.ctx.bridgeTick();assert.equal(rows[1][2],exactForm);
rows=[['Lead ID','Phone','Form ID'],[Number('9000000000000000003'),'+254700000001',Number(exactForm)]];
e=env(rows,()=>{throw Error('Pinned Form ID never permits unsafe Lead ID');});e.props.sheet_form_id_1=exactForm;e.ctx.bridgeTick();
assert.match(rows[1][rows[0].indexOf('Webhook_Message')],/округлён/);assert.equal(typeof rows[1][2],'number');
rows=[['Lead ID','Phone','Form ID','Webhook_Status','Bridge Receipt'],['9000000000000000004','+254700000001',Number(exactForm),'SENT','r-sent']];
e=env(rows,body=>{assert.equal(body.op,'status');return {code:200,data:{results:{}}};});e.props.sheet_form_id_1=exactForm;e.ctx.bridgeTick();
assert.equal(rows[1][3],'SENT');assert.equal(typeof rows[1][2],'number');
console.log('PASS: exact per-sheet Form ID overrides connector numbers/exponents only by explicit setting, rejects conflicting forms, never bypasses Lead ID checks or rewrites SENT');
