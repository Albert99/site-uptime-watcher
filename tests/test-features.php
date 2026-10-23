<?php
/** v1.12: orden por timestamp, pausa, recordatorio de 24 h y validación de URLs. */
$o = new Site_Uptime_Watcher();

echo "F1 el orden de comprobación usa timestamps (las más antiguas primero)\n";
$u = urls(3);
$s = st($u, 0, 'UP');
$s[$u[0]]['last_ts'] = time() - 100;
$s[$u[1]]['last_ts'] = time() - 900;
$s[$u[2]]['last_ts'] = 0; // nunca comprobada
foreach ($u as $x) { $s[$x]['last_check'] = '2026-01-01 00:00:00'; } // el texto ya no manda
setup($u, $s);
$o->check_all_sites();
$order = array_values(array_filter($GLOBALS['api_calls'], function ($c) { return strpos($c, 'site') !== false; }));
ok($order[0] === $u[2] && $order[1] === $u[1] && $order[2] === $u[0], 'orden: nunca comprobada, la más antigua, la más reciente');
ok(states()[$u[0]]['last_ts'] >= time() - 5, 'last_ts se actualiza tras comprobar');

echo "F2 una web en pausa no se comprueba ni avisa\n";
$u = urls(2);
$s = st($u, 0, 'UP');
$s[$u[0]] = ['status' => 'PAUSED', 'paused' => true, 'consecutive_failures' => 0, 'last_code' => 'En pausa', 'last_check' => '', 'last_ts' => 0, 'alert_sent' => false];
setup($u, $s);
$GLOBALS['down_hosts'] = ['site1.test', 'site2.test'];
$o->check_all_sites();
ok(!array_filter($GLOBALS['api_calls'], function ($c) { return strpos($c, 'site1.test') !== false; }), 'la pausada no recibe peticiones');
ok(states()[$u[0]]['status'] === 'PAUSED' && states()[$u[0]]['consecutive_failures'] === 0, 'su estado queda intacto');
ok(states()[$u[1]]['consecutive_failures'] === 1, 'la otra sí se comprueba');

echo "F3 recordatorio de una caída abierta\n";
$u = urls(1);
$mk = function ($alert_ts) use ($u) { $s = st($u, 5, 'DOWN', true); if ($alert_ts !== null) $s[$u[0]]['alert_ts'] = $alert_ts; return $s; };
setup($u, $mk(time() - 100));
$GLOBALS['down_hosts'] = ['site1.test'];
call($o, 'run_checks', $u);
ok(count($GLOBALS['mails']) === 0, 'antes de 24 h: sin correo');
setup($u, $mk(time() - DAY_IN_SECONDS - 60));
$GLOBALS['down_hosts'] = ['site1.test'];
call($o, 'run_checks', $u);
ok(count($GLOBALS['mails']) === 1 && strpos($GLOBALS['mails'][0]['subject'], 'SIGUE CAÍDA') !== false, 'tras 24 h: un recordatorio');
ok(states()[$u[0]]['alert_ts'] >= time() - 5, 'alert_ts se renueva');
$GLOBALS['mails'] = [];
call($o, 'run_checks', $u);
ok(count($GLOBALS['mails']) === 0, 'el ciclo siguiente no repite');
setup($u, $mk(null));
$GLOBALS['down_hosts'] = ['site1.test'];
call($o, 'run_checks', $u);
ok(count($GLOBALS['mails']) === 0 && states()[$u[0]]['alert_ts'] > 0, 'estado antiguo sin alert_ts: se inicializa sin enviar');
setup($u, $mk(time() - DAY_IN_SECONDS - 60));
$GLOBALS['down_hosts'] = ['site1.test'];
$GLOBALS['mail_ok'] = false;
call($o, 'run_checks', $u);
ok(states()[$u[0]]['alert_ts'] < time() - DAY_IN_SECONDS, 'si el correo falla, se reintenta (alert_ts no cambia)');
$u = urls(4);
setup($u, array_map(function ($x) { $x['alert_ts'] = time() - DAY_IN_SECONDS - 60; return $x; }, st($u, 5, 'DOWN', true)));
$GLOBALS['down_hosts'] = array_map(function ($x) { return parse_url($x, PHP_URL_HOST); }, $u);
call($o, 'run_checks', $u);
ok(count($GLOBALS['mails']) === 1 && strpos($GLOBALS['mails'][0]['subject'], 'SIGUEN CAÍDAS') !== false, '4 recordatorios en un ciclo: un único resumen');

echo "F4 al recuperarse se limpia el recordatorio\n";
$u = urls(1);
$s = st($u, 5, 'DOWN', true); $s[$u[0]]['alert_ts'] = time() - 500;
setup($u, $s);
call($o, 'run_checks', $u);
ok(states()[$u[0]]['status'] === 'UP' && states()[$u[0]]['alert_ts'] === 0, 'UP y alert_ts = 0');

echo "F5 validación de URLs al añadir (SSRF)\n";
$GLOBALS['unsafe'] = ['localhost', 'metadata.internal'];
ok(Site_Uptime_Store::sanitize_target_url('https://cliente.es') === 'https://cliente.es', 'URL normal válida');
ok(Site_Uptime_Store::sanitize_target_url('cliente.es') === 'https://cliente.es', 'sin esquema: se añade https://');
ok(Site_Uptime_Store::sanitize_target_url('http://127.0.0.1/admin') === false, 'IP de loopback rechazada');
ok(Site_Uptime_Store::sanitize_target_url('http://169.254.169.254/latest/meta-data') === false, 'metadatos cloud rechazados');
ok(Site_Uptime_Store::sanitize_target_url('http://192.168.1.10') === false, 'IP privada rechazada');
ok(Site_Uptime_Store::sanitize_target_url('http://localhost') === false, 'localhost rechazado');
ok(Site_Uptime_Store::sanitize_target_url('https://user:pass@cliente.es') === false, 'credenciales en la URL rechazadas');
ok(Site_Uptime_Store::sanitize_target_url('https://cliente.es:8443/') === 'https://cliente.es:8443/', 'puerto personalizado permitido');
ok(Site_Uptime_Store::sanitize_target_url('') === false, 'vacía rechazada');
setup([]);
$r = call($o, 'add_sites', ['http://127.0.0.1', 'https://ok.test']);
ok($r['added'] === 1 && $r['invalid'] === 1, 'add_sites: 1 añadida, 1 inválida');

echo "F6 el panel muestra webs en pausa\n";
$u = urls(2);
$s = st($u, 0, 'UP');
$s[$u[1]] = ['status' => 'PAUSED', 'paused' => true, 'consecutive_failures' => 0, 'last_code' => 'En pausa', 'last_check' => '', 'last_ts' => 0, 'alert_sent' => false];
setup($u, $s);
ob_start(); $o->admin_page_html(); $html = ob_get_clean();
ok(strpos($html, 'data-status="paused"') !== false, 'fila con estado paused');
ok(strpos($html, 'data-filter="paused"') !== false, 'pestaña "En pausa"');
ok(strpos($html, 'action=suw_toggle_pause') !== false && strpos($html, 'Reanudar vigilancia') !== false && strpos($html, 'Pausar vigilancia') !== false, 'botones de pausar y reanudar');
setup($u, st($u, 0, 'UP'));
ob_start(); $o->admin_page_html(); $html = ob_get_clean();
ok(strpos($html, 'data-filter="paused"') === false, 'sin pausadas no aparece la pestaña');
