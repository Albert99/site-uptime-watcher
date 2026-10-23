<?php
/** Ciclo de comprobación: guardia de caída masiva, resumen, reintentos, lock, salud y heartbeat. */
$o = new Site_Uptime_Watcher();

echo "S1 caída masiva + sin internet => ciclo descartado\n";
$u=urls(6); setup($u,st($u,1)); $GLOBALS['down_hosts']=array_map(fn($x)=>parse_url($x,PHP_URL_HOST),$u); $GLOBALS['net_ok']=false;
$r=call($o,'run_checks',$u);
ok($r['discarded']===true,'outcome.discarded'); ok(count($GLOBALS['mails'])===0,'0 correos'); ok(states()[$u[0]]['consecutive_failures']===1,'estados intactos');

echo "S2 caída masiva + internet OK => se procesa y UN correo resumen\n";
setup($u,st($u,1)); $GLOBALS['down_hosts']=array_map(fn($x)=>parse_url($x,PHP_URL_HOST),$u);
$r=call($o,'run_checks',$u);
ok($r['discarded']===false,'no descartado'); ok(count($GLOBALS['mails'])===1,'1 solo correo'); ok(strpos($GLOBALS['mails'][0]['subject'],'6 webs caídas')!==false,'asunto resumen');
ok(strpos($GLOBALS['mails'][0]['html'],'Abrir la web')===false,'resumen sin botón "Abrir la web"'); ok(strpos($GLOBALS['mails'][0]['html'],'site1.test')!==false,'lista las webs');
$all=true; foreach($u as $x){ $all=$all&&states()[$x]['alert_sent']===true&&states()[$x]['status']==='DOWN'; } ok($all,'las 6: DOWN y alert_sent');

echo "S3 2 caídas (bajo el umbral del resumen) => 2 correos individuales\n";
$u=urls(6); setup($u,st($u,1)); $GLOBALS['down_hosts']=['site1.test','site2.test']; $GLOBALS['net_ok']=false;
$r=call($o,'run_checks',$u);
ok($r['discarded']===false,'sin guard (33 %)'); ok(count($GLOBALS['mails'])===2,'2 correos'); ok(strpos($GLOBALS['mails'][0]['subject'],'Web caída')!==false,'asunto individual');

echo "S4 3 recuperadas => 1 correo resumen\n";
$u=urls(4); setup($u,st($u,3,'DOWN',true));
$r=call($o,'run_checks',$u);
ok(count($GLOBALS['mails'])===1,'1 correo'); ok(strpos($GLOBALS['mails'][0]['subject'],'RECUPERADAS')!==false,'asunto recuperadas'); ok(states()[$u[0]]['alert_sent']===false&&states()[$u[0]]['status']==='UP','estado UP y alert_sent=false');

echo "S5 correo falla => no se marca enviado y se reintenta\n";
$u=urls(2); setup($u,st($u,1)); $GLOBALS['down_hosts']=$u?['site1.test','site2.test']:[]; $GLOBALS['mail_ok']=false;
call($o,'run_checks',$u); ok(states()[$u[0]]['alert_sent']===false,'alert_sent=false tras fallo de correo');
$GLOBALS['mail_ok']=true; $GLOBALS['mails']=[]; call($o,'run_checks',$u);
ok(count($GLOBALS['mails'])===2,'reintento envía 2'); ok(states()[$u[0]]['alert_sent']===true,'ahora alert_sent=true');

echo "S6 umbral de la guardia: pocas webs / menos del 50 %\n";
$u=urls(4); setup($u,st($u,1)); $GLOBALS['down_hosts']=array_map(fn($x)=>parse_url($x,PHP_URL_HOST),$u); $GLOBALS['net_ok']=false;
ok(call($o,'run_checks',$u)['discarded']===false,'4 webs (<5): sin guard');
$u=urls(12); setup($u,st($u,1)); $GLOBALS['down_hosts']=['site1.test','site2.test','site3.test','site4.test','site5.test']; $GLOBALS['net_ok']=false;
ok(call($o,'run_checks',$u)['discarded']===false,'5 de 12 (<50 %): sin guard');
$u=urls(10); setup($u,st($u,1)); $GLOBALS['down_hosts']=['site1.test','site2.test','site3.test','site4.test','site5.test']; $GLOBALS['net_ok']=false;
ok(call($o,'run_checks',$u)['discarded']===true,'5 de 10 (=50 %) sin internet: guard');

echo "S7 web borrada durante el ciclo no resucita\n";
$u=urls(3); setup(array_slice($u,0,2),st(array_slice($u,0,2),0));
call($o,'run_checks',$u); ok(!isset(states()[$u[2]]),'la web que ya no está en la lista no se crea');

echo "S8 lock atómico\n";
setup([]);
ok(Site_Uptime_Store::acquire_lock()===true,'A adquiere'); ok(Site_Uptime_Store::acquire_lock()===false,'B no puede mientras A lo tiene');
Site_Uptime_Store::release_lock(); ok(Site_Uptime_Store::acquire_lock()===true,'se puede readquirir tras liberar'); Site_Uptime_Store::release_lock();
$GLOBALS['wpdb']->rows['suw_lock']=(string)(time()-300);
ok(Site_Uptime_Store::acquire_lock()===true,'lock caducado: relevo'); ok(Site_Uptime_Store::acquire_lock()===false,'tras el relevo vuelve a estar tomado');
$GLOBALS['wpdb']->rows['suw_lock']=(string)(time()-300); $stale=(string)(time()-300);
$GLOBALS['wpdb']->rows['suw_lock']=(string)time(); // otro proceso ya hizo el relevo
$cas=$GLOBALS['wpdb']->query($GLOBALS['wpdb']->prepare("UPDATE `wp_options` SET option_value = %s WHERE option_name = %s AND option_value = %s",(string)time(),'suw_lock',$stale));
ok($cas===0,'CAS con valor obsoleto no gana');

echo "S9 check_all_sites: ciclo completo, lock, salud y heartbeat\n";
$u=urls(3); setup($u,st($u,0,'UP'),['heartbeat_url'=>'https://hc.test/ping/abc']);
$r=$o->check_all_sites(); ok($r===true,'ciclo completo devuelve true'); ok(!isset($GLOBALS['wpdb']->rows['suw_lock']),'lock liberado');
ok(count($GLOBALS['pings'])===1&&$GLOBALS['pings'][0]==='https://hc.test/ping/abc','heartbeat enviado'); 
$h=$GLOBALS['opts']['suw_health']; ok($h['last_cycle']>time()-5&&$h['checked']===3&&$h['guard']===null,'salud registrada');
$GLOBALS['wpdb']->rows['suw_lock']=(string)time(); ok($o->check_all_sites()===false,'con lock tomado: devuelve false');
setup($u,st($u,0,'UP')); $o->check_all_sites(); ok(count($GLOBALS['pings'])===0,'sin URL: no hay heartbeat');

echo "S10 salud: el evento de guardia se conserva\n";
setup([]); call($o,'record_health',time()-1,10,['checked'=>10,'failed'=>9,'discarded'=>true]);
ok($GLOBALS['opts']['suw_health']['guard']['failed']===9,'guardia registrada'); call($o,'record_health',time()-1,10,['checked'=>10,'failed'=>0,'discarded'=>false]);
ok($GLOBALS['opts']['suw_health']['guard']['failed']===9,'se conserva en ciclos posteriores');

