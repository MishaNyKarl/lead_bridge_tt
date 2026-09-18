const vm=require('node:vm'),fs=require('node:fs'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/LeadBridge.gs','utf8');
function env(rows,fetch) {
  const props={BRIDGE_URL:'https://bridge.example.com/action.php',ROUTE_ID:'route_abc',BRIDGE_SECRET:'a'.repeat(64)};
  const sheet={getSheetId:()=>1,getLastColumn:()=>rows[0].length,getLastRow:()=>rows.length,getMaxRows:()=>100,getRange(r,c,n=1,m=1){return {getValues:()=>Array.from({length:n},(_,i)=>Array.from({length:m},(_,j)=>rows[r-1+i]?.[c-1+j]??'')),setValue(v){rows[r-1]??=[];rows[r-1][c-1]=v;return this;},setNumberFormat(){return this;}};}};
  const ctx={console,Date,Number,JSON,Error,String,Object,Array,PropertiesService:{getScriptProperties:()=>({getProperty:k=>props[k],setProperty:(k,v)=>props[k]=v,getProperties:()=>props})},LockService:{getScriptLock:()=>({tryLock:()=>true,releaseLock(){}})},SpreadsheetApp:{getActiveSpreadsheet:()=>({getSheets:()=>[sheet]}),flush(){}},UrlFetchApp:{fetch:(url,o)=>{let r=fetch(JSON.parse(o.payload));return {getResponseCode:()=>r.code,getContentText:()=>JSON.stringify(r.data)};}}};vm.createContext(ctx);vm.runInContext(source,ctx);return {ctx,props};
}
const headers=['Lead ID','Phone','Name','Campaign ID','Ad ID','Ad Group ID','Webhook_Status','Binom Click ID','Partner Reference ID','Webhook_Message','Bridge Receipt'];
let calls=0,rows=[headers.slice(),['9000000000000000001','+254700000001','Test','9000000000000000002','3','4','','','','','']];
let e=env(rows,body=>{calls++;assert.equal(body.lead_id,'9000000000000000001');assert.equal(body.campaign_id,'9000000000000000002');return {code:202,data:{status:'processing',stage:'queued',receipt:'receipt-1'}};});e.ctx.bridgeTick();assert.equal(rows[1][6],'QUEUED');assert.equal(rows[1][10],'receipt-1');assert.equal(calls,1);
e=env(rows,body=>{assert.equal(body.op,'status');return {code:200,data:{results:{'receipt-1':{status:'success',stage:'sent',click_id:'click',partner_reference_id:'partner',receipt:'receipt-1'}}}};});e.ctx.bridgeTick();assert.equal(rows[1][6],'SENT');assert.equal(rows[1][7],'click');
e=env(rows,()=>{throw Error('SENT must not be sent');});e.ctx.bridgeTick();
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
assert.throws(()=>e.ctx.bridgeMetadata(['ADID_V2'],[9000000000000000999]),/округлён/);
console.log('PASS all optional metadata, independent V2 values, conflicting aliases and unsafe V2 ID rejected');
