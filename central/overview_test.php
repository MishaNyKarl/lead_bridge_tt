<?php
define('BRIDGE_TEST',true);require __DIR__.'/action.php';
$dir=sys_get_temp_dir().'/bridge-overview-'.bin2hex(random_bytes(6));mkdir($dir,0700);putenv('BRIDGE_DATA='.$dir);file_put_contents($dir.'/master.key',random_bytes(32));
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
foreach(['admin'=>'admin','alice'=>'buyer','bob'=>'buyer'] as $id=>$role)sql('INSERT INTO users VALUES(?,?,?,?,?,?,?)',[$id,$id,$id,'unused',$role,1,'epoch']);
function loginOverview($id){$_SESSION=['uid'=>$id,'last'=>time(),'epoch'=>'epoch'];}
foreach(['a'=>'alice','b'=>'bob'] as $id=>$owner){saveEntity($id,'route',['name'=>$id==='a'?'<script>unsafe</script>':'Bob route','active'=>true]);sql('UPDATE entities SET owner=? WHERE id=?',[$owner,$id]);}
foreach([
 ['1','a','sent','approved','2026-09-21T21:10:00+00:00'],
 ['2','a','sent','rejected','2026-09-22T08:00:00+00:00'],
 ['3','a','queued','','2026-09-22T08:00:00+00:00'],
 ['4','a','partner_review','','2026-09-22T08:00:00+00:00'],
 ['5','b','sent','paid','2026-09-22T08:00:00+00:00'],
 ['6','b','sent','approved','2026-09-22T08:00:00+00:00'],
 ['7','a','sent','trash','2026-08-01T08:00:00+00:00']
] as [$id,$route,$state,$status,$created]){
 sql('INSERT INTO leads(id,route,external_id,fingerprint,state,payload,snapshot,created,updated) VALUES(?,?,?,?,?,?,?,?,?)',[$id,$route,$id,'f',$state,'PRIVATE_PHONE','',''.$created,$created]);
 if($status!=='')sql('INSERT INTO partner_status(lead,status,partner_lead_id,updated) VALUES(?,?,?,?)',[$id,$status,'p'.$id,$created]);
}
$now=new DateTimeImmutable('2026-09-22T12:00:00+00:00');loginOverview('alice');
$d=overviewData(['days'=>'1','owner'=>'bob'],$now);
check($d['owner']==='alice'&&$d['total']['total']===4&&count($d['routes'])===1,'buyer cannot override owner');
check($d['total']['sent']===2&&$d['total']['approved']===1&&$d['total']['pending']===1&&$d['total']['review']===1,'acceptance, approval, pending and review separate');
check(overviewRate($d['total'])===50.0,'approval denominator uses decisions only');
check($d['daily']['2026-09-22']['total']===4,'Moscow midnight bucket includes previous UTC day');
setting('timezone_alice','UTC');check(overviewData(['days'=>'1'],$now)['total']['total']===3,'personal UTC boundary');
check(!str_contains(json_encode($d),'PRIVATE_PHONE')&&!isset($d['buyers']['bob']),'no payload or other owner leaked');
loginOverview('admin');$d=overviewData(['days'=>'7','rank'=>'approved'],$now);
check($d['total']['total']===6&&array_key_first($d['routes'])==='b'&&count($d['daily'])===7,'admin aggregate, ranking and empty daily buckets');
check(overviewData(['days'=>'1','owner'=>'bob'],$now)['total']['total']===2,'admin buyer filter');
check(overviewData(['days'=>'1 OR 1=1','rank'=>'DROP TABLE'],$now)['days']===30,'query allowlists');
$empty=overviewData(['days'=>'1'],new DateTimeImmutable('2027-01-01'));check($empty['total']['total']===0&&overviewRate($empty['total'])===null,'empty period has no invented percent');
ob_start();overviewTable($d['routes'],false,'');$html=ob_get_clean();check(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'),'route labels escaped');
loginOverview('alice');$_GET=[];ob_start();overviewPanel();$html=ob_get_clean();check(!str_contains($html,'Результаты по баерам')&&!str_contains($html,'name="owner"')&&!str_contains($html,'Bob route'),'buyer UI contains own statistics only');
