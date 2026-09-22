<?php
function overviewEmpty(): array {return ['total'=>0,'sent'=>0,'pending'=>0,'review'=>0,'approved'=>0,'rejected'=>0,'trash'=>0,'new'=>0,'hold'=>0,'unknown'=>0];}
function overviewRate(array $v): ?float {$n=$v['approved']+$v['rejected']+$v['trash'];return $n?100*$v['approved']/$n:null;}
function overviewData(array $query,?DateTimeImmutable $now=null): array {
    $me=requireUser();$admin=isAdmin();$zone=new DateTimeZone(userTimezone());$now=($now??new DateTimeImmutable())->setTimezone($zone);
    $days=in_array($query['days']??'',['1','7','30','90'],true)?(int)$query['days']:30;
    $start=$now->setTime(0,0)->modify('-'.($days-1).' days');$end=$now->modify('+1 second');
    $owner=$admin&&is_string($query['owner']??null)?$query['owner']:($admin?'':$me['id']);
    if($admin&&$owner!==''&&!sql('SELECT 1 FROM users WHERE id=?',[$owner])->fetchColumn())throw new InvalidArgumentException('Пользователь не найден');
    $where='l.created>=? AND l.created<?';$args=[$start->setTimezone(new DateTimeZone('UTC'))->format('c'),$end->setTimezone(new DateTimeZone('UTC'))->format('c')];
    if($owner!==''){$where.=' AND e.owner=?';$args[]=$owner;}
    $offset=$zone->getName()==='Europe/Moscow'?'+3 hours':'+0 hours';
    $rows=sql("SELECT date(l.created,'$offset') day,l.route,e.owner,u.login,l.state,COALESCE(p.status,'') crm,COUNT(*) n FROM leads l JOIN entities e ON e.id=l.route AND e.kind='route' LEFT JOIN users u ON u.id=e.owner LEFT JOIN partner_status p ON p.lead=l.id WHERE $where GROUP BY day,l.route,e.owner,u.login,l.state,crm",$args);
    $total=overviewEmpty();$daily=[];$buyers=[];$routes=[];
    for($i=0;$i<$days;$i++)$daily[$start->modify('+'.$i.' days')->format('Y-m-d')]=overviewEmpty();
    foreach($rows as $row){
        $n=(int)$row['n'];$v=overviewEmpty();$v['total']=$n;
        $state=$row['state'];$v[$state==='sent'?'sent':(in_array($state,['queued','click_ready','binom_pending','partner_pending'],true)?'pending':'review')]=$n;
        $status=$row['crm'];$v[in_array($status,['approved','paid'],true)?'approved':(in_array($status,['new','hold','rejected','trash'],true)?$status:'unknown')]=$n;
        if(!isset($buyers[$row['owner']]))$buyers[$row['owner']]=['name'=>$row['login']?:'Без пользователя']+overviewEmpty();
        if(!isset($routes[$row['route']]))$routes[$row['route']]=['owner'=>$row['owner']]+overviewEmpty();
        foreach($v as $k=>$value){$total[$k]+=$value;$daily[$row['day']][$k]+=$value;$buyers[$row['owner']][$k]+=$value;$routes[$row['route']][$k]+=$value;}
    }
    $entities=uiEntities('route');foreach($routes as $id=>&$route)$route['name']=$entities[$id]['name']??'Связка';unset($route);
    $active=0;foreach($entities as $id=>$route)if(($owner===''||ownerOf($id)===$owner)&&!empty($route['active']))$active++;
    $rank=in_array($query['rank']??'',['total','approved','review'],true)?$query['rank']:'total';
    $sort=fn($a,$b)=>($b[$rank]<=>$a[$rank])?:strcmp($a['name'],$b['name']);uasort($buyers,$sort);uasort($routes,$sort);
    return compact('admin','owner','days','start','now','total','daily','buyers','routes','active','rank');
}
function overviewNumber(int $n): string {return number_format($n,0,',',' ');}
function overviewPercent(?float $n): string {return $n===null?'—':number_format($n,1,',',' ').'%';}
function overviewChart(array $daily): void {
    $peak=max(1,...array_column($daily,'total'));$count=count($daily);$step=800/$count;$bar=min(32,$step*.7);$x=50;
    echo '<svg class="ov-chart" viewBox="0 0 900 250" role="img" aria-labelledby="ov-chart-title"><title id="ov-chart-title">Лиды по дате поступления. Зелёный — приняты ПП, синий — в обработке, оранжевый — нужна проверка.</title>';
    for($i=0;$i<=4;$i++){$y=200-$i*42;echo '<line x1="45" x2="860" y1="'.$y.'" y2="'.$y.'" stroke="#e9eef5"/><text x="38" y="'.($y+4).'" text-anchor="end">'.overviewNumber((int)ceil($peak*$i/4)).'</text>';}
    $index=0;foreach($daily as $day=>$v){$y=200;$label=substr($day,8,2).'.'.substr($day,5,2);$tip=$label.': всего '.$v['total'].', принято '.$v['sent'].', в обработке '.$v['pending'].', проверка '.$v['review'];
        echo '<g tabindex="0" role="img" aria-label="'.h($tip).'"><title>'.h($tip).'</title>';
        foreach(['sent'=>'#23a38c','pending'=>'#477de7','review'=>'#e9a23b'] as $key=>$color){$height=168*$v[$key]/$peak;$y-=$height;echo '<rect x="'.($x+($step-$bar)/2).'" y="'.$y.'" width="'.$bar.'" height="'.$height.'" rx="2" fill="'.$color.'"/>';}
        echo '</g>';if($index%max(1,(int)ceil($count/10))===0||$index===$count-1)echo '<text x="'.($x+$step/2).'" y="224" text-anchor="middle">'.$label.'</text>';$x+=$step;$index++;
    }echo '</svg>';
}
function overviewTable(array $rows,bool $buyers,string $owner): void {
    echo '<div class="scroll"><table><thead><tr><th>'.($buyers?'Баер':'Связка').'</th><th>Лиды</th><th>Принято ПП</th><th>Апрувы</th><th>Проверка</th><th>Апрув %</th></tr></thead><tbody>';
    foreach($rows as $id=>$v){$url=$buyers?'?page=overview&days='.(int)($_GET['days']??30).'&owner='.rawurlencode($id):'?page=route&edit='.rawurlencode($id);
        echo '<tr><td><a href="'.h($url).'">'.h($v['name']).'</a></td><td><strong>'.overviewNumber($v['total']).'</strong></td><td>'.overviewNumber($v['sent']).'</td><td class="ov-green">'.overviewNumber($v['approved']).'</td><td>'.($v['review']?'<span class="badge warn">'.$v['review'].'</span>':'0').'</td><td>'.overviewPercent(overviewRate($v)).'</td></tr>';
    }
    if(!$rows)echo '<tr><td colspan="6" class="muted">За выбранный период лидов нет.</td></tr>';echo '</tbody></table></div>';
}
function overviewPanel(): void {
    $d=overviewData($_GET);$v=$d['total'];$alive=time()-(int)setting('worker_heartbeat')<120;
    echo '<style>.ov-toolbar{display:flex;gap:16px;align-items:end;flex-wrap:wrap}.ov-toolbar label{margin:0;min-width:150px;flex:1}.ov-toolbar button{margin-bottom:1px}.ov-caption{font-size:13px;color:#718096;margin:12px 0 24px}.ov-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px;margin-bottom:22px}.ov-kpi{background:white;border:1px solid #e2e8f0;border-radius:14px;padding:20px}.ov-kpi strong{display:block;font-size:32px;letter-spacing:-1px;margin:9px 0}.ov-kpi small{color:#718096}.ov-kpi:first-child{background:#17395b;color:white;border-color:#17395b}.ov-kpi:first-child small{color:#b6cede}.ov-green{color:#168b74}.ov-columns{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(280px,1fr);gap:20px}.ov-columns>.card{min-width:0}.ov-chart{width:100%;height:auto;min-width:540px}.ov-chart text{font:11px system-ui;fill:#718096}.ov-chart g:focus{outline:2px solid #477de7}.ov-legend{display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:#62748c}.ov-dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:5px}.ov-status-row{display:grid;grid-template-columns:125px 1fr 45px;align-items:center;gap:12px;margin:19px 0;font-size:13px}.ov-track{height:8px;border-radius:9px;background:#edf2f7;overflow:hidden}.ov-fill{height:100%;border-radius:9px}.ov-card-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.ov-card-head h2{margin-bottom:8px}.ov-health{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap}.ov-health p{margin:8px 0}.ov-empty{padding:24px 0;color:#718096}@media(max-width:1200px){.ov-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.ov-columns{grid-template-columns:minmax(0,1fr)}}@media(max-width:600px){.ov-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.ov-kpi{padding:16px}.ov-kpi strong{font-size:27px}.ov-toolbar{align-items:stretch}.ov-status-row{grid-template-columns:115px 1fr 36px;gap:8px}}</style>';
    echo '<form class="card ov-toolbar" method="get"><input type="hidden" name="page" value="overview"><label>Период<select name="days">';foreach(['1'=>'Сегодня','7'=>'7 дней','30'=>'30 дней','90'=>'90 дней'] as $value=>$label)echo '<option value="'.$value.'" '.($d['days']==$value?'selected':'').'>'.$label.'</option>';echo '</select></label>';
    if($d['admin']){echo '<label>Пользователь<select name="owner"><option value="">Все пользователи</option>';foreach(accountOptions() as $id=>$label)echo '<option value="'.h($id).'" '.($d['owner']===$id?'selected':'').'>'.h($label).'</option>';echo '</select></label>';}
    echo '<label>Рейтинг по<select name="rank">';foreach(['total'=>'Количеству лидов','approved'=>'Апрувам','review'=>'Требующим проверки'] as $key=>$label)echo '<option value="'.$key.'" '.($d['rank']===$key?'selected':'').'>'.$label.'</option>';echo '</select></label><button>Показать</button></form>';
    echo '<p class="ov-caption">'.$d['start']->format('d.m.Y').' — '.$d['now']->format('d.m.Y').' · '.h(userTimezone()).'. Отбор по дате поступления лида; статусы показаны на текущий момент.</p><div class="ov-kpis">';
    $cards=[['Всего лидов',overviewNumber($v['total']),$d['active'].' активных связок'],['Принято ПП',overviewNumber($v['sent']),overviewPercent($v['total']?100*$v['sent']/$v['total']:null).' от всех лидов'],['Апрувы',overviewNumber($v['approved']),'Включая оплаченные'],['Апрув по решениям',overviewPercent(overviewRate($v)),'Апрувы / (апрувы + отказы + треш)'],['Нужна проверка',overviewNumber($v['review']),'В обработке: '.$v['pending']]];
    foreach($cards as [$title,$value,$note])echo '<div class="ov-kpi"><span>'.h($title).'</span><strong>'.h($value).'</strong><small>'.h($note).'</small></div>';echo '</div><div class="ov-columns"><section class="card"><div class="ov-card-head"><h2>Динамика лидов</h2><span class="muted">По дням</span></div><div class="ov-legend">';
    foreach(['Принято ПП'=>'#23a38c','В обработке'=>'#477de7','Нужна проверка'=>'#e9a23b'] as $label=>$color)echo '<span><i class="ov-dot" style="background:'.$color.'"></i>'.$label.'</span>';echo '</div><div class="scroll">';overviewChart($d['daily']);echo '</div><details><summary>Данные графика</summary>';overviewTableDays($d['daily']);echo '</details></section><section class="card"><h2>Статусы партнёрки</h2><p class="muted">SENT — приём заявки, а не апрув.</p>';
    foreach(['approved'=>['Апрув / оплачен','#23a38c'],'new'=>['Ожидание','#477de7'],'hold'=>['Холд','#9d7be8'],'rejected'=>['Отказ','#e59a42'],'trash'=>['Треш','#d66877'],'unknown'=>['Нет статуса','#aab6c7']] as $key=>[$label,$color])echo '<div class="ov-status-row"><span>'.$label.'</span><div class="ov-track"><div class="ov-fill" style="width:'.($v['total']?100*$v[$key]/$v['total']:0).'%;background:'.$color.'"></div></div><strong>'.overviewNumber($v[$key]).'</strong></div>';
    echo '<small class="muted">«Нет статуса» включает ещё не отправленные заявки и лиды без ответа о CRM-статусе.</small></section></div>';
    if($d['admin']){echo '<section class="card"><h2>Результаты по баерам</h2>';overviewTable($d['buyers'],true,$d['owner']);echo '</section>';}
    echo '<section class="card"><div class="ov-card-head"><h2>Топ-10 связок</h2><a href="?page=route">Все связки →</a></div>';overviewTable(array_slice($d['routes'],0,10,true),false,$d['owner']);echo '<p class="ov-caption">Рейтинг зависит от выбранной метрики. Апрув % рассчитан только по полученным решениям ПП, включая треш.</p></section>';
    echo '<section class="card ov-health"><div><span class="badge '.($alive?'good':'warn').'">'.($alive?'Обработчик работает':'Нет свежего сигнала обработчика').'</span><p class="muted">Обновлено '.h(displayDate('now')).' · Данные обновляются при открытии страницы.</p></div><div class="actions"><a class="button secondary" href="?page=leads'.($d['admin']&&$d['owner']!==''?'&owner='.rawurlencode($d['owner']):'').'">Открыть журнал лидов</a><a class="button" href="?page=route&new=1">Создать связку</a></div></section>';
}
function overviewTableDays(array $daily): void {
    echo '<div class="scroll"><table><tr><th>Дата</th><th>Всего</th><th>Принято</th><th>В обработке</th><th>Проверка</th></tr>';foreach($daily as $date=>$v)echo '<tr><td>'.date('d.m.Y',strtotime($date)).'</td><td>'.$v['total'].'</td><td>'.$v['sent'].'</td><td>'.$v['pending'].'</td><td>'.$v['review'].'</td></tr>';echo '</table></div>';
}
