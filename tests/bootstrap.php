<?php
/**
 * Entorno mínimo que simula las funciones de WordPress que usa el plugin.
 * Sin dependencias: se ejecuta con `php tests/run.php`.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

define('ABSPATH', '/');
define('WPINC', 'wp-includes');
define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);
define('SUW_VERSION', '1.12.0');
define('SUW_PLUGIN_DIR', dirname(__DIR__) . '/');
define('SUW_PLUGIN_FILE', SUW_PLUGIN_DIR . 'site-uptime-watcher.php');

$GLOBALS['opts']       = [];
$GLOBALS['site_t']     = [];
$GLOBALS['mails']      = [];
$GLOBALS['mail_ok']    = true;
$GLOBALS['net_ok']     = true;
$GLOBALS['down_hosts'] = [];
$GLOBALS['pings']      = [];
$GLOBALS['api']        = 'release';
$GLOBALS['api_calls']  = [];
$GLOBALS['unsafe']     = [];

class WP_Error_Stub {
    public $m;
    function __construct($m) { $this->m = $m; }
    function get_error_message() { return $this->m; }
}

function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['opts']) ? $GLOBALS['opts'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['opts'][$k] = $v; return true; }
function get_site_transient($k) { return $GLOBALS['site_t'][$k] ?? false; }
function set_site_transient($k, $v, $t = 0) { $GLOBALS['site_t'][$k] = $v; return true; }
function delete_site_transient($k) { unset($GLOBALS['site_t'][$k]); }
function get_transient($k) { return false; }
function delete_transient($k) {}
function wp_parse_args($a, $d) { return array_merge($d, (array) $a); }
function current_time($t) { return '2026-10-23 17:00:00'; }
function add_action() {}
function remove_action() {}
function add_filter($tag, $fn) { if ($tag === 'http_allowed_safe_ports') { $GLOBALS['port_filter'] = $fn; } }
function remove_filter($tag, $fn) { if ($tag === 'http_allowed_safe_ports') { unset($GLOBALS['port_filter']); } }
function apply_filters($t, $v) { return $v; }
function admin_url($p = '') { return 'https://m.test/wp-admin/' . $p; }
function self_admin_url($p = '') { return 'https://m.test/wp-admin/' . $p; }
function home_url($p = '') { return 'https://m.test' . $p; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function esc_html($s) { return htmlspecialchars((string) $s); }
function esc_attr($s) { return htmlspecialchars((string) $s); }
function esc_url($s) { return htmlspecialchars((string) $s); }
function esc_url_raw($s, $protocols = null) { $s = trim((string) $s); return preg_match('#^https?://#i', $s) ? $s : ''; }
function wp_unslash($s) { return $s; }
function wp_nonce_url($u, $a) { return $u . '&_wpnonce=abc'; }
function wp_nonce_field($a) { echo '<input type="hidden" name="_wpnonce" value="x">'; }
function wp_date($f, $t) { return date($f, $t); }
function human_time_diff($a, $b) { return abs($b - $a) >= 3600 ? round(abs($b - $a) / 3600) . ' horas' : round(abs($b - $a) / 60) . ' minutos'; }
function current_user_can($c) { return true; }
function get_current_user_id() { return 1; }
function wp_next_scheduled($h) { return time() + 240; }
function plugin_basename($f) { return 'site-uptime-watcher/site-uptime-watcher.php'; }
function selected($a, $b = true, $e = true) { $r = ((string) $a === (string) $b) ? " selected='selected'" : ''; if ($e) echo $r; return $r; }
function checked($a, $b = true, $e = true) { $r = ((string) $a === (string) $b) ? " checked='checked'" : ''; if ($e) echo $r; return $r; }
function is_wp_error($x) { return $x instanceof WP_Error_Stub; }

/** Réplica mínima de wp_http_validate_url(): rechaza hosts internos y puertos no permitidos. */
function wp_http_validate_url($url) {
    $p = parse_url($url);
    if (empty($p['host']) || !in_array($p['scheme'] ?? '', ['http', 'https'], true)) return false;
    if (isset($p['user']) || isset($p['pass'])) return false;
    if (in_array($p['host'], $GLOBALS['unsafe'], true) || preg_match('/^(127\.|10\.|192\.168\.|169\.254\.)/', $p['host'])) return false;
    if (isset($p['port'])) {
        $allowed = [80, 443, 8080];
        if (isset($GLOBALS['port_filter'])) $allowed = ($GLOBALS['port_filter'])($allowed);
        if (!in_array((int) $p['port'], $allowed, true)) return false;
    }
    return $url;
}

function wp_mail($to, $s, $m, $h = []) { $GLOBALS['mails'][] = ['to' => $to, 'subject' => $s, 'html' => $m]; return $GLOBALS['mail_ok']; }

function wp_remote_get($url, $args = []) {
    $host = parse_url($url, PHP_URL_HOST);
    $GLOBALS['api_calls'][] = $url;
    if (strpos($url, 'api.github.com') !== false) {
        if ($GLOBALS['api'] === 'release') {
            return ['code' => 200, 'body' => json_encode([
                'tag_name' => 'v1.13.0', 'html_url' => 'https://github.com/Albert99/site-uptime-watcher/releases/tag/v1.13.0',
                'body' => 'notas', 'zipball_url' => 'https://api.github.com/zipball/v1.13.0',
                'assets' => [['name' => 'site-uptime-watcher.zip', 'browser_download_url' => 'https://github.com/dl/site-uptime-watcher.zip']],
            ])];
        }
        if ($GLOBALS['api'] === 'norelease') return ['code' => 404, 'body' => '{}'];
        return ['code' => 403, 'body' => 'rate limit'];
    }
    if (in_array($host, ['www.google.com', 'www.cloudflare.com', 'www.wikipedia.org'], true)) {
        return $GLOBALS['net_ok'] ? ['code' => 204, 'body' => '', 'headers' => []] : new WP_Error_Stub('28 Connection time-out');
    }
    if (in_array($host, $GLOBALS['down_hosts'], true)) return new WP_Error_Stub('28 Connection time-out');
    return ['code' => 200, 'body' => '<html>ok</html>', 'headers' => ['x-powered-by' => '']];
}
function wp_safe_remote_get($url, $a = []) { $GLOBALS['pings'][] = $url; return ['code' => 200, 'body' => '', 'headers' => []]; }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function wp_remote_retrieve_headers($r) { return $r['headers']; }

class FakeWpdb {
    public $options = 'wp_options';
    public $rows = [];
    function prepare($q, ...$args) { $i = 0; return preg_replace_callback('/%s/', function () use (&$i, $args) { return "'" . addslashes($args[$i++]) . "'"; }, $q); }
    function query($sql) {
        if (preg_match("/INSERT IGNORE INTO .* VALUES \('([^']*)', '([^']*)', 'no'\)/", $sql, $m)) { if (isset($this->rows[$m[1]])) return 0; $this->rows[$m[1]] = $m[2]; return 1; }
        if (preg_match("/SET option_value = '([^']*)' WHERE option_name = '([^']*)' AND option_value = '([^']*)'/", $sql, $m)) { if (($this->rows[$m[2]] ?? null) === $m[3]) { $this->rows[$m[2]] = $m[1]; return 1; } return 0; }
        throw new Exception('SQL no soportado: ' . $sql);
    }
    function get_var($sql) { if (preg_match("/option_name = '([^']*)'/", $sql, $m)) return $this->rows[$m[1]] ?? null; return null; }
    function delete($t, $w) { unset($this->rows[$w['option_name']]); return 1; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

foreach (['class-suw-php-info', 'class-suw-store', 'class-suw-checker', 'class-suw-mailer', 'class-site-uptime-watcher', 'class-suw-updater'] as $file) {
    require SUW_PLUGIN_DIR . 'includes/' . $file . '.php';
}

/* ---- Utilidades de test ---- */
$GLOBALS['t_fails'] = 0;
$GLOBALS['t_count'] = 0;

function ok($cond, $msg) {
    $GLOBALS['t_count']++;
    if ($cond) { echo "  PASS  $msg\n"; } else { $GLOBALS['t_fails']++; echo "  FAIL  $msg\n"; }
}

function setup($sites, $states = [], $extra = []) {
    $GLOBALS['opts'] = ['suw_options' => array_merge(['notify_email' => 'a@b.c', 'sites_list' => implode("\n", $sites), 'max_failures' => 2, 'verify_ssl' => 1, 'ignored_codes' => '', 'heartbeat_url' => ''], $extra), 'suw_sites_state' => $states];
    $GLOBALS['mails'] = []; $GLOBALS['pings'] = []; $GLOBALS['mail_ok'] = true; $GLOBALS['net_ok'] = true;
    $GLOBALS['down_hosts'] = []; $GLOBALS['unsafe'] = []; $GLOBALS['wpdb']->rows = []; $GLOBALS['site_t'] = []; $GLOBALS['api_calls'] = [];
}

function urls($n) { $u = []; for ($i = 1; $i <= $n; $i++) $u[] = "https://site$i.test"; return $u; }

function st($urls, $fail = 1, $status = 'PENDING', $alert = false) {
    $s = [];
    foreach ($urls as $u) {
        $s[$u] = ['status' => $status, 'consecutive_failures' => $fail, 'last_code' => 'x', 'last_check' => '2026-10-23 16:00:00', 'last_ts' => time() - 300, 'alert_sent' => $alert, 'first_failure_ts' => time() - 600];
    }
    return $s;
}

function states() { return $GLOBALS['opts']['suw_sites_state']; }

function call($o, $name, ...$a) { $m = new ReflectionMethod($o, $name); $m->setAccessible(true); return $m->invoke($o, ...$a); }
