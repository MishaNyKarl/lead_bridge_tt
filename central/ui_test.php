<?php
define('BRIDGE_TEST',true);require __DIR__.'/action.php';
$dir=sys_get_temp_dir().'/bridge-ui-'.bin2hex(random_bytes(6));mkdir($dir,0700);putenv('BRIDGE_DATA='.$dir);file_put_contents($dir.'/master.key',random_bytes(32));
function test($value,$label){if(!$value)throw new RuntimeException($label);echo "PASS $label\n";}
test(displayDate('2026-09-18T18:12:21+00:00','UTC')==='18:12:21 18.09.2026','date format with UTC preserved');
test(displayDate('2026-09-18T21:12:21+03:00','UTC')==='18:12:21 18.09.2026','timezone conversion explicit');
test(displayDate('2026-09-18T22:12:21+00:00')==='01:12:21 19.09.2026','Moscow default crosses midnight');
$_GET=['page'=>'leads','owner'=>'u','state'=>'sent'];$cols=['created'];
test(str_contains(sortHeading('created','Date',$cols),'dir=asc'),'first sort ascending');
$_GET['sort']='created';$_GET['dir']='asc';test(str_contains(sortHeading('created','Date',$cols),'dir=desc'),'second sort descending');
$_GET['dir']='desc';$header=sortHeading('created','Date',$cols);test(!str_contains($header,'dir=')&&str_contains($header,'owner=u'),'third sort resets and retains filters');
$_GET=['sort'=>'created; DROP TABLE leads','dir'=>'asc'];test(leadOrder([])==='created DESC,id DESC','sort allowlist');
foreach(['2','10000000000000000001','10'] as $i)sql('INSERT INTO leads(id,route,external_id,fingerprint,state,payload,snapshot,created,updated) VALUES(?,?,?,?,?,?,?,?,?)',[$i,'r',$i,'f','sent','','','2026-09-18','now']);
$_GET=['sort'=>'external_id','dir'=>'asc'];$ids=sql('SELECT external_id FROM leads ORDER BY '.leadOrder([]))->fetchAll(PDO::FETCH_COLUMN);test($ids===['2','10','10000000000000000001'],'large lead IDs sort without float precision loss');
$_GET=['page'=>'route'];test(str_contains(fieldHelp('campaign_key','Campaign'),'key='),'field instructions included');test(str_contains(uiIcon('settings'),'<svg'),'Lucide icon embedded');
$rows=['b'=>['name'=>'Бета'],'a'=>['name'=>'альфа']];$_GET=['sort'=>'name','dir'=>'asc'];test(array_keys(sortedEntities($rows,'partner',false))===['a','b'],'Cyrillic sorting');

if(countryOptions()["ZA"]!=="ЮАР"||countryOptions("AU")["AU"]!=="AU")throw new RuntimeException("Country selector must preserve existing ISO codes");
