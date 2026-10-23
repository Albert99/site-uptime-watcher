<?php
/** Actualizador (solo Releases) y renderizado del panel. */

echo "U1 hay Release => se usa el ZIP adjunto\n";
$GLOBALS['api']='release'; $r=Site_Uptime_Updater::get_remote(true);
ok($r&&$r['version']==='1.13.0','versión 1.13.0 desde el tag'); ok($r['package']==='https://github.com/dl/site-uptime-watcher.zip','paquete = asset .zip');
ok(!array_filter($GLOBALS['api_calls'],fn($u)=>strpos($u,'raw.githubusercontent')!==false),'nunca consulta la rama main');
ok(Site_Uptime_Updater::available()!==null,'1.12.0 < 1.13.0: hay actualización');

echo "U2 GitHub falla => última Release conocida, jamás main\n";
$GLOBALS['site_t']=[]; $GLOBALS['api']='error'; $GLOBALS['api_calls']=[]; $r=Site_Uptime_Updater::get_remote(true);
ok($r&&$r['version']==='1.13.0','sigue ofreciendo la última Release vista'); ok(!array_filter($GLOBALS['api_calls'],fn($u)=>strpos($u,'raw.githubusercontent')!==false),'no cae a main');

echo "U3 sin Releases y sin histórico => nada que ofrecer (aunque main tenga otra versión)\n";
$GLOBALS['opts']=[]; $GLOBALS['site_t']=[]; $GLOBALS['api']='norelease'; $GLOBALS['api_calls']=[]; $r=Site_Uptime_Updater::get_remote(true);
ok($r===null,'sin Release no se ofrece nada'); ok(Site_Uptime_Updater::available()===null,'available() null'); ok(!array_filter($GLOBALS['api_calls'],fn($u)=>strpos($u,'raw.githubusercontent')!==false),'no se consulta main');

echo "V1 el panel se renderiza\n";
$GLOBALS['opts']=['suw_options'=>['notify_email'=>'a@b.c','sites_list'=>"https://a.test\nhttps://b.test",'max_failures'=>2,'verify_ssl'=>1,'ignored_codes'=>'403','heartbeat_url'=>'https://hc.test/x'],
 'suw_sites_state'=>['https://a.test'=>['status'=>'UP','consecutive_failures'=>0,'last_code'=>'HTTP 200','last_check'=>'2026-10-23 16:59:00','alert_sent'=>false],'https://b.test'=>['status'=>'DOWN','consecutive_failures'=>3,'last_code'=>'Timeout','last_check'=>'2026-10-23 16:59:00','alert_sent'=>true]],
 'suw_health'=>['last_cycle'=>time()-1800,'duration'=>20,'checked'=>2,'total'=>2,'guard'=>['time'=>time()-3600,'failed'=>9,'total'=>12]]];
$GLOBALS['site_t']=[]; $GLOBALS['api']='release'; $GLOBALS['opts']['suw_last_release']=null;
$o=new Site_Uptime_Watcher(); ob_start(); $o->admin_page_html(); $html=ob_get_clean();
ok(strlen($html)>5000,'HTML generado ('.strlen($html).' bytes)'); ok(strpos($html,'Última comprobación')!==false&&strpos($html,'hace 30 minutos')!==false,'muestra "Última comprobación hace 30 minutos"');
ok(strpos($html,'demasiado sin comprobar')!==false,'aviso de monitor parado (>15 min)'); ok(strpos($html,'se descartó un ciclo')!==false&&strpos($html,'9 de 12')!==false,'aviso de ciclo descartado');
ok(strpos($html,'name="heartbeat_url"')!==false&&strpos($html,'https://hc.test/x')!==false,'campo heartbeat con su valor'); ok(strpos($html,'Nueva versión disponible')!==false,'banner de actualización');
$GLOBALS['opts']['suw_health']=['last_cycle'=>time()-60,'guard'=>null]; ob_start(); $o->admin_page_html(); $h2=ob_get_clean();
ok(strpos($h2,'demasiado sin comprobar')===false&&strpos($h2,'se descartó')===false,'sin avisos cuando todo va bien');
