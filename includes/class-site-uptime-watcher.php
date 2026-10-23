<?php
if (!defined('ABSPATH')) {
    exit;
}

class Site_Uptime_Watcher {

    const HOOK        = 'suw_check_sites_event';
    const LOCK_KEY    = 'suw_check_lock';
    const LOCK_TTL    = 270;     // < 300 s: el lock caduca antes del siguiente ciclo
    const BATCH_SIZE  = 20;      // peticiones simultáneas por lote
    const INLINE_MAX  = 60;      // máximo de webs comprobadas en línea al importar
    const MAX_BODY    = 262144;  // 256 KB de body son suficientes para las firmas
    const ERROR_PAGE_MAX = 30000; // las páginas de error de WP son pequeñas
    const TIMEOUT         = 20;    // segundos totales por petición (hostings lentos)
    const CONNECT_TIMEOUT = 10;    // segundos para conectar
    const QUEUE_HOOK      = 'suw_check_queue_event';

    private $opt_key   = 'suw_options';
    private $state_key = 'suw_sites_state';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        add_filter('cron_schedules', [__CLASS__, 'register_interval']);
        add_action('init', [__CLASS__, 'ensure_cron']);
        add_action(self::HOOK, [$this, 'check_all_sites']);
        add_action(self::QUEUE_HOOK, [$this, 'check_all_sites']);

        foreach (['add_site', 'bulk_add', 'manual_check', 'delete_sites', 'save_settings', 'check_update', 'test_email'] as $action) {
            add_action('admin_post_suw_' . $action, [$this, 'handle_' . $action]);
        }
    }

    /* ------------------------------------------------------------------
     * Cron
     * ------------------------------------------------------------------ */

    public static function register_interval($schedules) {
        if (!isset($schedules['five_minutes'])) {
            $schedules['five_minutes'] = [
                'interval' => 300,
                'display'  => 'Cada 5 minutos',
            ];
        }
        return $schedules;
    }

    public static function ensure_cron() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, 'five_minutes', self::HOOK);
        }
    }

    public static function activate_cron() {
        // En la petición de activación el filtro del constructor aún no existe.
        add_filter('cron_schedules', [__CLASS__, 'register_interval']);
        self::ensure_cron();
    }

    public static function deactivate_cron() {
        wp_clear_scheduled_hook(self::HOOK);
        wp_clear_scheduled_hook(self::QUEUE_HOOK);
        delete_transient(self::LOCK_KEY);
    }

    /* ------------------------------------------------------------------
     * Assets y menú
     * ------------------------------------------------------------------ */

    public function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_suw-settings') {
            return;
        }

        $css_file = SUW_PLUGIN_DIR . 'assets/css/admin.css';
        $js_file  = SUW_PLUGIN_DIR . 'assets/js/admin.js';

        $css_ver = file_exists($css_file) ? filemtime($css_file) : SUW_VERSION;
        $js_ver  = file_exists($js_file) ? filemtime($js_file) : SUW_VERSION;

        wp_enqueue_style('suw-admin-style', SUW_PLUGIN_URL . 'assets/css/admin.css', [], $css_ver);
        wp_enqueue_script('suw-admin-script', SUW_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], $js_ver, true);
    }

    public function add_menu() {
        add_menu_page(
            'Uptime Watcher',
            'Uptime Watcher',
            'manage_options',
            'suw-settings',
            [$this, 'admin_page_html'],
            'dashicons-visibility',
            31
        );
    }

    /* ------------------------------------------------------------------
     * Datos: opciones, lista y estados
     * ------------------------------------------------------------------ */

    private function get_options() {
        return wp_parse_args(get_option($this->opt_key, []), [
            'notify_email' => get_option('admin_email'),
            'sites_list'   => '',
            'max_failures' => 2,
            'verify_ssl'   => 1,
            'ignored_codes' => '',
        ]);
    }

    /** Códigos HTTP 4xx/5xx que no deben contar como caída (p. ej. 403 de un WAF). */
    private function parse_ignored_codes($raw) {
        $parts = preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        $codes = [];
        foreach ($parts ?: [] as $part) {
            $code = (int) $part;
            if ($code >= 400 && $code <= 599) {
                $codes[] = $code;
            }
        }
        return array_values(array_unique($codes));
    }

    private function log_incident($url, $type, $code) {
        $log = get_option('suw_incidents', []);
        $log = is_array($log) ? $log : [];

        array_unshift($log, [
            'url'  => $url,
            'type' => $type,
            'code' => $code,
            'time' => current_time('mysql'),
        ]);

        update_option('suw_incidents', array_slice($log, 0, 100), false);
    }

    private function get_sites_list() {
        $opts = $this->get_options();
        $urls = array_map('trim', explode("\n", (string) $opts['sites_list']));
        return array_values(array_unique(array_filter($urls)));
    }

    private function save_sites_list(array $urls) {
        $opts = $this->get_options();
        $opts['sites_list'] = implode("\n", array_values(array_unique(array_filter($urls))));
        update_option($this->opt_key, $opts);
    }

    private function get_states() {
        $states = get_option($this->state_key, []);
        return is_array($states) ? $states : [];
    }

    private function save_states(array $states) {
        update_option($this->state_key, $states, false);
    }

    private function new_state() {
        return [
            'status'               => 'PENDING',
            'consecutive_failures' => 0,
            'last_code'            => 'En cola',
            'last_check'           => '',
            'alert_sent'           => false,
        ];
    }

    private function sanitize_target_url($raw_url) {
        $url = trim((string) $raw_url);
        if ($url === '') {
            return false;
        }

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $clean = esc_url_raw($url);
        if (!filter_var($clean, FILTER_VALIDATE_URL) || !parse_url($clean, PHP_URL_HOST)) {
            return false;
        }

        return $clean;
    }

    /**
     * Añade webs a la lista con estado "en cola" y comprueba las primeras en paralelo.
     * Devuelve el número de webs nuevas.
     */
    private function add_sites(array $raw_urls) {
        $list   = $this->get_sites_list();
        $states = $this->get_states();
        $known  = [];
        $added  = [];
        $dupes  = 0;
        $bad    = 0;

        foreach ($list as $existing) {
            $known[$this->url_key($existing)] = true;
        }

        foreach ($raw_urls as $raw) {
            $clean = $this->sanitize_target_url($raw);

            if (!$clean) {
                $bad++;
                continue;
            }

            $key = $this->url_key($clean);
            if (isset($known[$key])) {
                $dupes++;
                continue;
            }

            $known[$key]    = true;
            $list[]         = $clean;
            $states[$clean] = $this->new_state();
            $added[]        = $clean;
        }

        if ($added) {
            $this->save_sites_list($list);
            $this->save_states($states);

            if (function_exists('set_time_limit')) {
                @set_time_limit(120);
            }

            // Comprobación en línea con presupuesto de tiempo; lo que no dé tiempo queda en cola.
            $this->run_checks(array_slice($added, 0, self::INLINE_MAX), time() + 60);

            $after = $this->get_states();
            foreach ($added as $url) {
                if (empty($after[$url]['last_check'])) {
                    wp_schedule_single_event(time() + 5, self::QUEUE_HOOK);
                    break;
                }
            }
        }

        return ['added' => count($added), 'duplicates' => $dupes, 'invalid' => $bad];
    }

    /**
     * Clave de comparación: ignora http/https, "www.", mayúsculas en el dominio,
     * puertos por defecto y la barra final, para que ejemplo.com y
     * https://www.ejemplo.com/ cuenten como la misma web.
     */
    private function url_key($url) {
        $parts = wp_parse_url($url);
        if (empty($parts['host'])) {
            return strtolower(trim((string) $url));
        }

        $host  = preg_replace('#^www\.#i', '', strtolower($parts['host']));
        $port  = (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true)) ? ':' . $parts['port'] : '';
        $path  = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return $host . $port . $path . $query;
    }

    private function remove_sites(array $urls_to_remove) {
        $list    = $this->get_sites_list();
        $to_drop = array_values(array_intersect($urls_to_remove, $list));

        if (!$to_drop) {
            return 0;
        }

        $states = $this->get_states();
        foreach ($to_drop as $url) {
            unset($states[$url]);
        }

        $this->save_sites_list(array_diff($list, $to_drop));
        $this->save_states($states);

        return count($to_drop);
    }

    /* ------------------------------------------------------------------
     * Manejadores admin-post (patrón POST → redirect → GET)
     * ------------------------------------------------------------------ */

    private function guard($nonce_action) {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para realizar esta acción.', 403);
        }
        check_admin_referer($nonce_action);
    }

    private function flash($message, $type = 'success') {
        set_transient('suw_notice_' . get_current_user_id(), [$message, $type], 60);
        wp_safe_redirect(admin_url('admin.php?page=suw-settings'));
        exit;
    }

    public function handle_add_site() {
        $this->guard('suw_add_site');
        $raw    = isset($_POST['suw_new_url']) ? wp_unslash($_POST['suw_new_url']) : '';
        $result = $this->add_sites([$raw]);

        if ($result['added'] > 0) {
            $this->flash('Web agregada y comprobada.');
        }
        if ($result['duplicates'] > 0) {
            $this->flash('Esa web ya está en la lista, no se ha añadido de nuevo.', 'error');
        }
        $this->flash('La URL no es válida.', 'error');
    }

    public function handle_bulk_add() {
        $this->guard('suw_bulk_add');
        $raw    = isset($_POST['suw_bulk_urls']) ? wp_unslash($_POST['suw_bulk_urls']) : '';
        $lines  = preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        $result = $this->add_sites($lines ?: []);

        $parts = [];
        if ($result['added'] > 0) {
            $parts[] = sprintf('Se han añadido %d web(s).', $result['added']);
        }
        if ($result['duplicates'] > 0) {
            $parts[] = sprintf('%d ya estaban en la lista y se han omitido.', $result['duplicates']);
        }
        if ($result['invalid'] > 0) {
            $parts[] = sprintf('%d no eran URLs válidas.', $result['invalid']);
        }

        if (!$parts) {
            $parts[] = 'No se ha indicado ninguna URL.';
        }

        $this->flash(implode(' ', $parts), $result['added'] > 0 ? 'success' : 'error');
    }

    public function handle_manual_check() {
        $this->guard('suw_manual_check');

        if (!$this->check_all_sites()) {
            $this->flash('Ya hay una comprobación en curso. Inténtalo de nuevo en unos segundos.', 'error');
        }
        $this->flash('Comprobación manual realizada.');
    }

    public function handle_delete_sites() {
        $this->guard('suw_delete_sites');
        $posted  = isset($_POST['selected_urls']) ? (array) wp_unslash($_POST['selected_urls']) : [];
        $posted  = array_map('strval', $posted);
        $deleted = $this->remove_sites($posted);

        $this->flash(sprintf('%d web(s) eliminada(s).', $deleted));
    }

    public function handle_check_update() {
        $this->guard('suw_check_update');

        $remote = Site_Uptime_Updater::force_check();

        if (!$remote) {
            $this->flash('No se pudo consultar GitHub. Inténtalo de nuevo en unos minutos.', 'error');
        }
        if (version_compare($remote['version'], SUW_VERSION, '>')) {
            $this->flash(sprintf('Hay una nueva versión disponible: %s (tienes la %s).', $remote['version'], SUW_VERSION));
        }
        $this->flash(sprintf('Estás en la última versión (%s).', SUW_VERSION));
    }

    public function handle_test_email() {
        $this->guard('suw_test_email');

        $opts = $this->get_options();
        $to   = $opts['notify_email'];
        $demo = home_url('/');

        $ok_down = $this->send_down_email($demo, 'HTTP 503', $to, (int) $opts['max_failures'], (int) $opts['max_failures'], time() - 12 * MINUTE_IN_SECONDS);
        $ok_up   = $this->send_recovery_email($demo, $to, time() - 12 * MINUTE_IN_SECONDS, 'HTTP 200');

        if ($ok_down && $ok_up) {
            $this->flash(sprintf('Enviados 2 correos de prueba (caída y recuperación) a %s.', $to));
        }
        $this->flash('No se pudieron enviar los correos de prueba. Revisa la configuración de correo de WordPress (SMTP).', 'error');
    }

    public function handle_save_settings() {
        $this->guard('suw_save_settings');

        $email = isset($_POST['notify_email']) ? sanitize_email(wp_unslash($_POST['notify_email'])) : '';
        if (!is_email($email)) {
            $this->flash('El email de alerta no es válido.', 'error');
        }

        $max = isset($_POST['max_failures']) ? (int) $_POST['max_failures'] : 2;
        $max = min(5, max(2, $max));

        $opts                 = $this->get_options();
        $opts['notify_email'] = $email;
        $opts['max_failures'] = $max;
        $opts['verify_ssl']   = empty($_POST['verify_ssl']) ? 0 : 1;

        $ignored               = isset($_POST['ignored_codes']) ? wp_unslash($_POST['ignored_codes']) : '';
        $opts['ignored_codes'] = implode(', ', $this->parse_ignored_codes($ignored));

        update_option($this->opt_key, $opts);

        $this->flash('Preferencias guardadas.');
    }

    /* ------------------------------------------------------------------
     * Comprobación
     * ------------------------------------------------------------------ */

    /**
     * Ciclo completo (cron y botón manual). Devuelve false si ya hay otro en curso.
     */
    public function check_all_sites() {
        if (get_transient(self::LOCK_KEY)) {
            return false;
        }
        set_transient(self::LOCK_KEY, time(), self::LOCK_TTL);

        if (function_exists('set_time_limit')) {
            @set_time_limit(self::LOCK_TTL);
        }

        try {
            $urls = array_values(array_filter($this->get_sites_list(), function ($url) {
                return (bool) filter_var($url, FILTER_VALIDATE_URL);
            }));

            // Poda estados huérfanos y crea los que falten.
            $states = $this->get_states();
            $states = array_intersect_key($states, array_flip($urls));
            foreach ($urls as $url) {
                if (!isset($states[$url])) {
                    $states[$url] = $this->new_state();
                }
            }
            $this->save_states($states);

            // Las nunca comprobadas y las más antiguas primero: nada se queda sin turno.
            usort($urls, function ($a, $b) use ($states) {
                return strcmp((string) ($states[$a]['last_check'] ?? ''), (string) ($states[$b]['last_check'] ?? ''));
            });

            $this->run_checks($urls, time() + self::LOCK_TTL - 30);
        } finally {
            delete_transient(self::LOCK_KEY);
        }

        return true;
    }

    /**
     * Comprueba las URLs por lotes en paralelo. Tras cada lote relee lista y
     * estados para no resucitar webs borradas mientras corría el ciclo.
     */
    private function run_checks(array $urls, $deadline = 0) {
        if (!$urls) {
            return;
        }

        $opts   = $this->get_options();
        $max    = max(1, (int) $opts['max_failures']);
        $verify  = !empty($opts['verify_ssl']);
        $ignored = $this->parse_ignored_codes($opts['ignored_codes']);

        foreach (array_chunk($urls, self::BATCH_SIZE) as $chunk) {
            if ($deadline && time() > $deadline) {
                break; // el resto se comprueba en el siguiente ciclo
            }

            $prior = $this->get_states();

            // Reintento inmediato solo en el primer fallo: filtra microcortes
            // sin alargar el ciclo para webs que ya están en ventana de confirmación.
            $retryable = array_values(array_filter($chunk, function ($url) use ($prior) {
                return empty($prior[$url]['consecutive_failures']);
            }));

            $results = $this->probe($chunk, $retryable, $verify, $ignored);

            $current = $this->get_sites_list();
            $states  = $this->get_states();

            foreach ($results as $url => $eval) {
                if (!in_array($url, $current, true)) {
                    continue;
                }
                $states[$url] = $this->apply_result(
                    $url,
                    $states[$url] ?? $this->new_state(),
                    $eval,
                    $max,
                    $opts['notify_email']
                );
            }

            $this->save_states($states);
        }
    }

    private function apply_result($url, array $state, array $eval, $max, $recipient) {
        $state['last_code']  = $eval['code'];
        $state['last_check'] = current_time('mysql');
        $state['last_detail'] = isset($eval['detail']) ? $eval['detail'] : '';

        if (!empty($eval['php'])) {
            $state['php_version'] = $eval['php'];
        }

        if ($eval['is_down']) {
            $state['consecutive_failures'] = (int) ($state['consecutive_failures'] ?? 0) + 1;
            if ($state['consecutive_failures'] === 1 || empty($state['first_failure_ts'])) {
                $state['first_failure_ts'] = time();
            }

            if ($state['consecutive_failures'] >= $max) {
                if (($state['status'] ?? '') !== 'DOWN') {
                    $this->log_incident($url, 'down', $eval['code']);
                }
                $state['status'] = 'DOWN';
                if (empty($state['alert_sent'])) {
                    // Si el correo falla, no se marca como enviado y se reintenta en el siguiente ciclo.
                    $state['alert_sent'] = $this->send_down_email($url, $eval['code'], $recipient, (int) $state['consecutive_failures'], (int) $max, (int) $state['first_failure_ts'], isset($eval['detail']) ? $eval['detail'] : '');
                }
            } elseif (($state['status'] ?? '') !== 'DOWN') {
                $state['status'] = 'PENDING';
            }

            return $state;
        }

        if (($state['status'] ?? '') === 'DOWN') {
            $this->log_incident($url, 'recovered', $eval['code']);
        }

        if (!empty($state['alert_sent'])) {
            $this->send_recovery_email($url, $recipient, (int) ($state['first_failure_ts'] ?? 0), $eval['code']);
        }

        $state['status']               = 'UP';
        $state['consecutive_failures'] = 0;
        $state['alert_sent']           = false;
        $state['first_failure_ts']      = 0;

        return $state;
    }

    private function probe(array $urls, array $retryable, $verify, array $ignored = []) {
        $responses = $this->fetch_many($urls, $verify);
        $evals     = [];
        $lenient   = [];

        foreach ($urls as $url) {
            $evals[$url] = $this->inspect_site_response($responses[$url], $ignored);

            if ($verify && $evals[$url]['is_down'] && $this->is_incomplete_chain($responses[$url]['error'])) {
                $lenient[] = $url;
            }
        }

        // Cadena de certificados incompleta: los navegadores la toleran (descargan el
        // intermedio) pero cURL no. Si la web responde sin verificar, está operativa.
        if ($lenient) {
            $second = $this->fetch_many($lenient, false);
            foreach ($lenient as $url) {
                $eval = $this->inspect_site_response($second[$url], $ignored);
                if (!$eval['is_down']) {
                    $eval['code']  .= ' · SSL incompleto';
                    $eval['detail'] = 'El servidor no envía la cadena completa de certificados. Los navegadores lo toleran, pero conviene corregirlo.';
                }
                $evals[$url] = $eval;
            }
        }

        // Reintento inmediato solo en el primer fallo.
        $failed = [];
        foreach ($urls as $url) {
            if ($evals[$url]['is_down'] && in_array($url, $retryable, true) && !in_array($url, $lenient, true)) {
                $failed[] = $url;
            }
        }

        if ($failed) {
            sleep(2);
            $second = $this->fetch_many($failed, $verify);
            foreach ($failed as $url) {
                $evals[$url] = $this->inspect_site_response($second[$url], $ignored);
            }
        }

        return $evals;
    }

    private function is_incomplete_chain($error) {
        if (!is_string($error) || $error === '') {
            return false;
        }

        foreach (['unable to get local issuer certificate', 'unable to get issuer certificate', 'unable to verify the first certificate'] as $needle) {
            if (stripos($error, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Traduce los errores de cURL a una etiqueta corta y legible. */
    private function friendly_error($message) {
        $message = trim((string) $message);

        if (preg_match('/^(?:cURL error )?(\d+)\b/i', $message, $m)) {
            $map = [
                6  => 'DNS no resuelve',
                7  => 'Conexión rechazada',
                28 => 'Timeout',
                35 => 'Error SSL',
                51 => 'Error SSL',
                52 => 'Respuesta vacía',
                56 => 'Conexión interrumpida',
                58 => 'Error SSL',
                60 => 'Error SSL',
                77 => 'Error SSL',
            ];
            $n = (int) $m[1];
            return isset($map[$n]) ? $map[$n] : 'Error de red (' . $n . ')';
        }

        if (stripos($message, 'timed out') !== false || stripos($message, 'time-out') !== false) {
            return 'Timeout';
        }

        return function_exists('mb_substr') ? mb_substr($message, 0, 40) : substr($message, 0, 40);
    }

    /**
     * GET en paralelo con la librería Requests de WordPress.
     * Devuelve url => ['code' => int, 'body' => string, 'error' => ?string].
     */
    private function fetch_many(array $urls, $verify) {
        $user_agent = 'Mozilla/5.0 (compatible; SiteUptimeWatcher/' . SUW_VERSION . '; +' . home_url() . ')';
        $out        = [];

        $requests_class = class_exists('\WpOrg\Requests\Requests')
            ? '\WpOrg\Requests\Requests'
            : (class_exists('Requests') ? 'Requests' : '');

        if ($requests_class && method_exists($requests_class, 'request_multiple')) {
            $ca_bundle = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
            $batch     = [];

            foreach ($urls as $url) {
                $batch[$url] = [
                    'url'     => $url,
                    'type'    => 'GET',
                    'headers' => ['User-Agent' => $user_agent],
                    'options' => [
                        'timeout'          => self::TIMEOUT,
                        'connect_timeout'  => self::CONNECT_TIMEOUT,
                        'follow_redirects' => true,
                        'redirects'        => 5,
                        'verify'           => $verify ? (file_exists($ca_bundle) ? $ca_bundle : true) : false,
                        'verifyname'       => (bool) $verify,
                        'max_bytes'        => self::MAX_BODY,
                    ],
                ];
            }

            try {
                $responses = $requests_class::request_multiple($batch);
            } catch (\Throwable $e) {
                $responses = [];
            }

            foreach ($urls as $url) {
                $r = $responses[$url] ?? null;
                if (is_object($r) && isset($r->status_code)) {
                    $out[$url] = [
                        'code'    => (int) $r->status_code,
                        'body'    => substr((string) $r->body, 0, self::MAX_BODY),
                        'error'   => null,
                        'headers' => $this->pick_headers(isset($r->headers) ? $r->headers : null),
                    ];
                } else {
                    $out[$url] = [
                        'code'  => 0,
                        'body'  => '',
                        'error' => (is_object($r) && method_exists($r, 'getMessage')) ? $r->getMessage() : 'Sin respuesta',
                    ];
                }
            }

            return $out;
        }

        // Fallback secuencial.
        foreach ($urls as $url) {
            $response = wp_remote_get($url, [
                'timeout'             => self::TIMEOUT,
                'redirection'         => 5,
                'sslverify'           => (bool) $verify,
                'user-agent'          => $user_agent,
                'limit_response_size' => self::MAX_BODY,
            ]);

            if (is_wp_error($response)) {
                $out[$url] = ['code' => 0, 'body' => '', 'error' => $response->get_error_message()];
            } else {
                $out[$url] = [
                    'code'    => (int) wp_remote_retrieve_response_code($response),
                    'body'    => (string) wp_remote_retrieve_body($response),
                    'error'   => null,
                    'headers' => $this->pick_headers(wp_remote_retrieve_headers($response)),
                ];
            }
        }

        return $out;
    }

    /** Solo guardamos las dos cabeceras que pueden delatar la versión de PHP. */
    private function pick_headers($headers) {
        $out = ['x-powered-by' => '', 'server' => ''];

        if (!is_array($headers) && !($headers instanceof ArrayAccess)) {
            return $out;
        }

        foreach (array_keys($out) as $key) {
            $value     = isset($headers[$key]) ? $headers[$key] : '';
            $out[$key] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return $out;
    }

    private function inspect_site_response(array $r, array $ignored = []) {
        $eval        = $this->inspect_core($r, $ignored);
        $eval['php'] = Site_Uptime_Php_Info::detect(isset($r['headers']) ? $r['headers'] : []);

        return $eval;
    }

    private function inspect_core(array $r, array $ignored = []) {
        if ($r['error'] !== null) {
            $detail = function_exists('mb_substr') ? mb_substr($r['error'], 0, 160) : substr($r['error'], 0, 160);
            return ['is_down' => true, 'code' => $this->friendly_error($r['error']), 'detail' => $detail];
        }

        $http_code = (int) $r['code'];
        $body      = (string) $r['body'];

        // Las firmas solo se buscan en páginas pequeñas: una página de error de WP
        // pesa pocos KB y así un artículo que hable de errores no dispara falsos positivos.
        if ($body !== '' && strlen($body) < self::ERROR_PAGE_MAX) {
            $signatures = [
                'error crítico en este sitio web'               => 'WP Error Crítico PHP',
                'critical error on this website'                => 'WP Critical Error',
                'Error establishing a database connection'      => 'Error MySQL WP',
                'Error al establecer una conexión con la base'  => 'Error MySQL WP',
                'No disponible por mantenimiento programado'    => 'Mantenimiento WP',
                'Briefly unavailable for scheduled maintenance' => 'WP Maintenance',
            ];

            foreach ($signatures as $needle => $label) {
                if (stripos($body, $needle) !== false) {
                    return ['is_down' => true, 'code' => $label];
                }
            }
        }

        if ($http_code === 0) {
            return ['is_down' => true, 'code' => 'Sin respuesta'];
        }

        if ($http_code >= 400) {
            // Código ignorado por configuración (WAF, protección anti-bot...): la web responde.
            return [
                'is_down' => !in_array($http_code, $ignored, true),
                'code'    => 'HTTP ' . $http_code,
            ];
        }

        if (trim($body) === '') {
            return ['is_down' => true, 'code' => 'WP WSOD (Página blanca)'];
        }

        return ['is_down' => false, 'code' => 'HTTP ' . $http_code];
    }

    /* ------------------------------------------------------------------
     * Correos (HTML con versión de texto alternativa)
     * ------------------------------------------------------------------ */

    private function host_of($url) {
        return preg_replace('#^www\.#i', '', (string) (parse_url($url, PHP_URL_HOST) ?: $url));
    }

    private function send_html_mail($to, $subject, $html, $text) {
        $alt = function ($phpmailer) use ($text) {
            $phpmailer->AltBody = $text;
        };

        add_action('phpmailer_init', $alt);
        $sent = wp_mail($to, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
        remove_action('phpmailer_init', $alt);

        return (bool) $sent;
    }

    private function send_down_email($url, $code, $recipient, $failures = 0, $max = 0, $first_ts = 0, $detail = '') {
        $host = $this->host_of($url);
        $rows = ['Diagnóstico' => $code, 'Detectada' => current_time('mysql')];

        if ($detail !== '') {
            $rows['Detalle'] = $detail;
        }

        if ($first_ts > 0) {
            $rows['Primer fallo'] = wp_date('Y-m-d H:i:s', $first_ts);
        }
        if ($failures > 0) {
            $rows['Comprobaciones fallidas'] = $max > 0 ? sprintf('%d seguidas (umbral: %d)', $failures, $max) : (string) $failures;
        }

        $mail = $this->render_email([
            'kind'      => 'down',
            'badge'     => 'CAÍDA CONFIRMADA',
            'host'      => $host,
            'url'       => $url,
            'intro'     => 'La web no responde correctamente. El aviso se envía tras reconfirmar el fallo varias veces para evitar falsas alarmas.',
            'rows'      => $rows,
            'preheader' => $host . ' no responde: ' . $code,
        ]);

        return $this->send_html_mail($recipient, '🔴 [ALERTA] Web caída: ' . $host, $mail['html'], $mail['text']);
    }

    private function send_recovery_email($url, $recipient, $first_ts = 0, $code = '') {
        $host = $this->host_of($url);
        $rows = ['Estado' => 'Operativa', 'Recuperada' => current_time('mysql')];

        if ($first_ts > 0) {
            $rows['Tiempo sin servicio'] = human_time_diff($first_ts, time());
        }
        if ($code !== '') {
            $rows['Respuesta'] = $code;
        }

        $mail = $this->render_email([
            'kind'      => 'up',
            'badge'     => 'RECUPERADA',
            'host'      => $host,
            'url'       => $url,
            'intro'     => 'La web vuelve a responder con normalidad.',
            'rows'      => $rows,
            'preheader' => $host . ' vuelve a estar operativa',
        ]);

        return $this->send_html_mail($recipient, '🟢 [RECUPERADA] Web online: ' . $host, $mail['html'], $mail['text']);
    }

    /**
     * Plantilla basada en tablas y estilos en línea para que se vea igual en Gmail,
     * Outlook y clientes móviles. Devuelve ['html' => ..., 'text' => ...].
     */
    private function render_email(array $a) {
        $is_down = ($a['kind'] === 'down');
        $accent  = $is_down ? '#ea4335' : '#34a853';
        $soft    = $is_down ? '#fce8e6' : '#e6f4ea';
        $ink     = $is_down ? '#c5221f' : '#137333';
        $panel   = admin_url('admin.php?page=suw-settings');
        $site    = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $font    = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

        $rows_html = '';
        $text      = strip_tags($a['badge']) . ' - ' . $a['host'] . "\n" . $a['url'] . "\n\n" . $a['intro'] . "\n\n";

        foreach ($a['rows'] as $label => $value) {
            $rows_html .= '<tr>'
                . '<td style="padding:11px 0;border-bottom:1px solid #edeef2;font-size:13px;color:#5f6368;">' . esc_html($label) . '</td>'
                . '<td align="right" style="padding:11px 0;border-bottom:1px solid #edeef2;font-size:14px;font-weight:600;color:#1f1f1f;">' . esc_html($value) . '</td>'
                . '</tr>';
            $text .= $label . ': ' . $value . "\n";
        }
        $text .= "\nPanel: " . $panel . "\n";

        $btn = 'display:inline-block;font-family:' . $font . ';font-size:14px;font-weight:600;text-decoration:none;padding:12px 22px;border-radius:999px;';

        $html = '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light"><title>' . esc_html($a['host']) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f1f3f4;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . esc_html($a['preheader']) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f1f3f4;"><tr><td align="center" style="padding:28px 12px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;font-family:' . $font . ';">'

            . '<tr><td style="padding:0 6px 14px 6px;font-size:13px;font-weight:700;letter-spacing:.02em;color:#5f6368;">Uptime Watcher</td></tr>'

            . '<tr><td style="background:#ffffff;border:1px solid #e3e3e8;border-radius:20px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'

            . '<tr><td style="background:' . $soft . ';border-top-left-radius:20px;border-top-right-radius:20px;border-bottom:4px solid ' . $accent . ';padding:28px 28px 24px 28px;">'
            . '<span style="display:inline-block;background:' . $accent . ';color:#ffffff;font-size:11px;font-weight:700;letter-spacing:.06em;padding:6px 12px;border-radius:999px;">' . esc_html($a['badge']) . '</span>'
            . '<div style="font-size:28px;line-height:1.2;font-weight:700;color:#1f1f1f;margin-top:16px;word-break:break-word;">' . esc_html($a['host']) . '</div>'
            . '<div style="margin-top:6px;font-size:13px;word-break:break-all;"><a href="' . esc_url($a['url']) . '" style="color:' . $ink . ';text-decoration:none;">' . esc_html($a['url']) . '</a></div>'
            . '</td></tr>'

            . '<tr><td style="padding:24px 28px 8px 28px;font-size:15px;line-height:1.55;color:#3c4043;">' . esc_html($a['intro']) . '</td></tr>'

            . '<tr><td style="padding:8px 28px 4px 28px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows_html . '</table>'
            . '</td></tr>'

            . '<tr><td style="padding:22px 28px 30px 28px;">'
            . '<a href="' . esc_url($a['url']) . '" style="' . $btn . 'background:#1a73e8;color:#ffffff;">Abrir la web</a>'
            . '&nbsp;&nbsp;'
            . '<a href="' . esc_url($panel) . '" style="' . $btn . 'background:#f1f3f4;color:#1f1f1f;">Ver panel</a>'
            . '</td></tr>'

            . '</table></td></tr>'

            . '<tr><td align="center" style="padding:18px 8px 0 8px;font-size:12px;line-height:1.5;color:#80868b;">'
            . 'Aviso automático enviado por Site Uptime Watcher desde ' . esc_html($site) . '.'
            . '</td></tr>'

            . '</table></td></tr></table></body></html>';

        return ['html' => $html, 'text' => $text];
    }

    /* ------------------------------------------------------------------
     * Vista
     * ------------------------------------------------------------------ */

    private function pull_notice() {
        $key    = 'suw_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if ($notice) {
            delete_transient($key);
        }
        return is_array($notice) ? $notice : null;
    }

    private function build_rows() {
        $states = $this->get_states();
        $rows   = [];

        foreach ($this->get_sites_list() as $url) {
            $data = $states[$url] ?? $this->new_state();
            $host = preg_replace('#^www\.#i', '', (string) (parse_url($url, PHP_URL_HOST) ?: $url));

            $rows[] = [
                'url'    => $url,
                'host'   => $host,
                'status' => strtolower($data['status'] ?? 'PENDING'),
                'data'   => $data,
            ];
        }

        $weight = ['down' => 0, 'pending' => 1, 'up' => 2];
        usort($rows, function ($a, $b) use ($weight) {
            $wa = $weight[$a['status']] ?? 3;
            $wb = $weight[$b['status']] ?? 3;
            return $wa === $wb ? strcmp($a['host'], $b['host']) : $wa <=> $wb;
        });

        return $rows;
    }

    public function admin_page_html() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $notice = $this->pull_notice();
        $update = Site_Uptime_Updater::available();
        $opts   = $this->get_options();
        $rows   = $this->build_rows();
        $max    = max(1, (int) $opts['max_failures']);
        $post   = esc_url(admin_url('admin-post.php'));

        // Las columnas de PHP solo aparecen si alguna web publica su versión.
        $has_php = false;
        foreach ($rows as $row) {
            if (!empty($row['data']['php_version'])) {
                $has_php = true;
                break;
            }
        }

        $total_up = $total_down = $total_pending = 0;
        foreach ($rows as $row) {
            if ($row['status'] === 'up') {
                $total_up++;
            } elseif ($row['status'] === 'down') {
                $total_down++;
            } else {
                $total_pending++;
            }
        }

        $next_run = wp_next_scheduled(self::HOOK);
        ?>
        <div class="suw-container">

            <header class="suw-header-bar">
                <div class="suw-header-text">
                    <h1>Uptime Watcher</h1>
                    <p>Monitor de estado y caídas de sitios web.</p>
                </div>
                <div class="suw-header-actions">
                    <a href="<?php echo esc_url(Site_Uptime_Updater::check_url()); ?>" class="suw-btn suw-btn-tonal" title="Consultar si hay una versión nueva en GitHub">
                        <svg class="suw-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        <span>Buscar actualizaciones <em class="suw-version-chip">v<?php echo esc_html(SUW_VERSION); ?></em></span>
                    </a>
                    <button type="button" class="suw-btn suw-btn-tonal" id="suw-btn-open-bulk">
                        <svg class="suw-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12" y2="12"></line><line x1="9" y1="15" x2="15" y2="15"></line></svg>
                        <span>Subir muchas webs</span>
                    </button>

                    <form method="post" action="<?php echo $post; ?>" id="suw-manual-check-form" style="margin:0;">
                        <?php wp_nonce_field('suw_manual_check'); ?>
                        <input type="hidden" name="action" value="suw_manual_check">
                        <button type="submit" class="suw-btn suw-btn-tonal" id="suw-btn-check">
                            <svg class="suw-svg suw-sync-target" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
                            <span>Comprobar ahora</span>
                        </button>
                    </form>
                </div>
            </header>

            <?php if ($update): ?>
                <div class="suw-update-banner">
                    <div class="suw-update-text">
                        <strong>Nueva versión disponible: v<?php echo esc_html($update['version']); ?></strong>
                        <span>Tienes instalada la v<?php echo esc_html(SUW_VERSION); ?>. La actualización conserva tus webs y ajustes.</span>
                    </div>
                    <div class="suw-update-actions">
                        <a href="<?php echo esc_url($update['url']); ?>" target="_blank" rel="noopener noreferrer" class="suw-btn suw-btn-tonal">Ver novedades</a>
                        <a href="<?php echo esc_url(Site_Uptime_Updater::update_url()); ?>" class="suw-btn suw-btn-primary">Actualizar ahora</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($notice): ?>
                <div class="suw-toast <?php echo $notice[1] === 'error' ? 'is-error' : 'is-success'; ?>">
                    <span><?php echo esc_html($notice[0]); ?></span>
                </div>
            <?php endif; ?>

            <div class="suw-modal" id="suw-bulk-modal" style="display: none;">
                <div class="suw-modal-backdrop"></div>
                <div class="suw-modal-dialog">
                    <div class="suw-modal-head">
                        <h3>Subir lote de webs</h3>
                        <button type="button" class="suw-modal-close" id="suw-btn-close-bulk">&times;</button>
                    </div>
                    <form method="post" action="<?php echo $post; ?>">
                        <?php wp_nonce_field('suw_bulk_add'); ?>
                        <input type="hidden" name="action" value="suw_bulk_add">

                        <p class="suw-modal-hint">Copia y pega la lista de webs aquí (una por línea, o separadas por comas). Si falta <code>https://</code> se pondrá automáticamente.</p>

                        <textarea name="suw_bulk_urls" class="suw-modal-textarea" rows="8" placeholder="midominio1.es&#10;https://tiendaonline.com&#10;cliente3.org" required></textarea>

                        <div class="suw-modal-foot">
                            <button type="button" class="suw-btn suw-btn-tonal" id="suw-btn-cancel-bulk">Cancelar</button>
                            <button type="submit" class="suw-btn suw-btn-primary">Añadir todas y verificar</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="suw-add-bar">
                <form method="post" action="<?php echo $post; ?>" class="suw-add-form">
                    <?php wp_nonce_field('suw_add_site'); ?>
                    <input type="hidden" name="action" value="suw_add_site">
                    <div class="suw-input-wrap">
                        <svg class="suw-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                        <input type="text" name="suw_new_url" class="suw-input-url" placeholder="https://ejemplo.com o ejemplo.com" required autocomplete="off">
                    </div>
                    <button type="submit" class="suw-btn suw-btn-primary">
                        <svg class="suw-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        <span>Añadir web</span>
                    </button>
                </form>
            </div>

            <div class="suw-filter-tabs">
                <button type="button" class="suw-pill-tab is-active" data-filter="all">
                    <span>Todos los sitios</span>
                    <span class="suw-pill-chip"><?php echo (int) count($rows); ?></span>
                </button>
                <button type="button" class="suw-pill-tab" data-filter="up">
                    <span class="suw-dot is-up"></span>
                    <span>Operativos</span>
                    <span class="suw-pill-chip is-up"><?php echo (int) $total_up; ?></span>
                </button>
                <button type="button" class="suw-pill-tab" data-filter="down">
                    <span class="suw-dot is-down"></span>
                    <span>Caídos</span>
                    <span class="suw-pill-chip is-down"><?php echo (int) $total_down; ?></span>
                </button>
                <button type="button" class="suw-pill-tab" data-filter="pending">
                    <span class="suw-dot is-pending"></span>
                    <span>Verificando</span>
                    <span class="suw-pill-chip is-pending"><?php echo (int) $total_pending; ?></span>
                </button>
            </div>

            <div class="suw-layout">
                <div class="suw-main-col">
                    <form method="post" action="<?php echo $post; ?>" id="suw-bulk-form">
                        <?php wp_nonce_field('suw_delete_sites'); ?>
                        <input type="hidden" name="action" value="suw_delete_sites">

                        <div class="suw-list-toolbar">
                            <label class="suw-select-all-label">
                                <input type="checkbox" id="suw-select-all" class="suw-check">
                                <span>Seleccionar todo</span>
                            </label>
                            <label class="suw-sort">
                                <span>Ordenar</span>
                                <select id="suw-sort" class="suw-control suw-select suw-sort-select">
                                    <option value="status">Estado (caídas primero)</option>
                                    <option value="name-asc">Nombre A → Z</option>
                                    <option value="name-desc">Nombre Z → A</option>
                                </select>
                            </label>
                            <button type="submit" id="suw-btn-delete-selected" class="suw-btn suw-btn-danger" disabled>
                                <svg class="suw-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                <span>Eliminar seleccionadas</span>
                            </button>
                        </div>

                        <div class="suw-list-stack">
                            <?php if (empty($rows)): ?>
                                <div class="suw-empty-card">
                                    <p>No hay webs agregadas en la lista.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($rows as $row_index => $row):
                                    $url      = $row['url'];
                                    $host     = $row['host'];
                                    $status   = $row['status'];
                                    $data     = $row['data'];
                                    $failures = (int) ($data['consecutive_failures'] ?? 0);
                                    $queued   = ($status === 'pending' && $failures === 0);
                                    $code     = !empty($data['last_code']) ? $data['last_code'] : '—';
                                    $initial  = function_exists('mb_substr') ? mb_strtoupper(mb_substr($host, 0, 1)) : strtoupper(substr($host, 0, 1));
                                    $code_cls = $status === 'up' ? 'is-good' : ($status === 'down' ? 'is-bad' : 'is-wait');
                                    $php      = Site_Uptime_Php_Info::assess(isset($data['php_version']) ? $data['php_version'] : '');
                                ?>
                                    <div class="suw-row-card is-<?php echo esc_attr($status); ?>" data-status="<?php echo esc_attr($status); ?>" data-host="<?php echo esc_attr(strtolower($host)); ?>" data-order="<?php echo (int) $row_index; ?>">
                                        <div class="suw-row-select">
                                            <input type="checkbox" name="selected_urls[]" value="<?php echo esc_attr($url); ?>" class="suw-check suw-site-checkbox">
                                        </div>

                                        <div class="suw-row-favicon" aria-hidden="true">
                                            <span class="suw-row-initial"><?php echo esc_html($initial); ?></span>
                                        </div>

                                        <div class="suw-row-site">
                                            <div class="suw-row-title">
                                                <span class="suw-host-name"><?php echo esc_html($host); ?></span>
                                                <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer" class="suw-ext-link">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                                                </a>
                                            </div>
                                            <span class="suw-full-url"><?php echo esc_html($url); ?></span>
                                        </div>

                                        <div class="suw-row-status">
                                            <?php if ($status === 'up'): ?>
                                                <span class="suw-badge is-up"><span class="suw-dot is-up"></span>Online</span>
                                            <?php elseif ($status === 'down'): ?>
                                                <span class="suw-badge is-down"><span class="suw-dot is-down"></span>Caída</span>
                                            <?php elseif ($queued): ?>
                                                <span class="suw-badge is-pending"><span class="suw-dot is-pending"></span>En cola</span>
                                            <?php else: ?>
                                                <span class="suw-badge is-pending"><span class="suw-dot is-pending"></span>Verificando <?php echo (int) $failures; ?>/<?php echo (int) $max; ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="suw-row-code">
                                            <span class="suw-code-tag <?php echo esc_attr($code_cls); ?>" title="<?php echo esc_attr(trim($code . ' ' . (isset($data['last_detail']) ? $data['last_detail'] : ''))); ?>"><?php echo esc_html($code); ?></span>
                                        </div>

                                        <?php if ($has_php): ?>
                                        <div class="suw-row-php">
                                            <?php if ($php['version'] !== ''): ?>
                                                <span class="suw-php-version">PHP <?php echo esc_html($php['version']); ?></span>
                                            <?php else: ?>
                                                <span class="suw-php-version is-empty" title="<?php echo esc_attr($php['reason']); ?>">PHP —</span>
                                            <?php endif; ?>
                                            <span class="suw-php-rec">Recomendada <?php echo esc_html($php['recommended']); ?></span>
                                        </div>

                                        <div class="suw-row-risk">
                                            <span class="suw-risk is-<?php echo esc_attr($php['risk']); ?>" title="<?php echo esc_attr($php['reason']); ?>"><?php echo esc_html($php['label']); ?></span>
                                        </div>

                                        <?php endif; ?>

                                        <div class="suw-row-meta">
                                            <span class="suw-meta-failures">Fallos: <strong><?php echo (int) $failures; ?></strong></span>
                                            <span class="suw-meta-time"><?php echo !empty($data['last_check']) ? esc_html($data['last_check']) : '—'; ?></span>
                                        </div>

                                        <div class="suw-row-actions">
                                            <button type="button" class="suw-icon-btn suw-delete-single" data-url="<?php echo esc_attr($url); ?>" title="Eliminar web">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="suw-side-col">
                    <div class="suw-settings-box">
                        <h3>Ajustes</h3>
                        <p class="suw-settings-desc">Alertas por email y sensibilidad de corte.</p>

                        <form method="post" action="<?php echo $post; ?>">
                            <?php wp_nonce_field('suw_save_settings'); ?>
                            <input type="hidden" name="action" value="suw_save_settings">

                            <div class="suw-field">
                                <label for="suw_email">Email de alerta</label>
                                <input type="email" id="suw_email" name="notify_email" value="<?php echo esc_attr($opts['notify_email']); ?>" class="suw-control" required>
                                <small>Receptor de avisos de corte y recuperación.</small>
                            </div>

                            <div class="suw-field">
                                <label for="suw_max_failures">Sensibilidad anti falsos positivos</label>
                                <select id="suw_max_failures" name="max_failures" class="suw-control suw-select">
                                    <?php foreach ([2 => 10, 3 => 15, 4 => 20, 5 => 25] as $n => $minutes): ?>
                                        <option value="<?php echo (int) $n; ?>" <?php selected($max, $n); ?>><?php echo (int) $n; ?> comprobaciones (<?php echo (int) $minutes; ?> min)</option>
                                    <?php endforeach; ?>
                                </select>
                                <small>Fallos consecutivos antes de enviar el correo. Cada primer fallo se reconfirma al instante.</small>
                            </div>

                            <div class="suw-field suw-field-check">
                                <label for="suw_verify_ssl">
                                    <input type="checkbox" id="suw_verify_ssl" name="verify_ssl" value="1" class="suw-check" <?php checked(!empty($opts['verify_ssl'])); ?>>
                                    <span>Verificar certificado SSL</span>
                                </label>
                                <small>Recomendado: un certificado caducado cuenta como caída para el visitante.</small>
                            </div>

                            <div class="suw-field">
                                <label for="suw_ignored_codes">Códigos HTTP a ignorar</label>
                                <input type="text" id="suw_ignored_codes" name="ignored_codes" value="<?php echo esc_attr($opts['ignored_codes']); ?>" class="suw-control" placeholder="403, 429">
                                <small>Opcional. Útil si una web está tras un WAF que bloquea bots: respondería 403 estando operativa.</small>
                            </div>

                            <button type="submit" class="suw-btn suw-btn-primary suw-btn-full">
                                Guardar preferencias
                            </button>
                        </form>

                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=suw_test_email'), 'suw_test_email')); ?>" class="suw-btn suw-btn-tonal suw-btn-full">
                            Enviar correo de prueba
                        </a>

                        <p class="suw-cron-status">
                            <?php if ($next_run): ?>
                                Próxima comprobación: <strong><?php echo esc_html(wp_date('H:i:s', $next_run)); ?></strong>
                            <?php else: ?>
                                <strong class="suw-cron-warn">WP-Cron no está programado.</strong> Recarga la página o reactiva el plugin.
                            <?php endif; ?>
                        </p>
                    </div>

                    <?php $incidents = array_slice((array) get_option('suw_incidents', []), 0, 8); ?>
                    <div class="suw-settings-box suw-incidents-box">
                        <h3>Últimos incidentes</h3>
                        <?php if (empty($incidents)): ?>
                            <p class="suw-settings-desc">Sin incidentes registrados.</p>
                        <?php else: ?>
                            <ul class="suw-incident-list">
                                <?php foreach ($incidents as $inc):
                                    $is_down = ($inc['type'] ?? '') === 'down';
                                    $inc_host = preg_replace('#^www\.#i', '', (string) (parse_url($inc['url'] ?? '', PHP_URL_HOST) ?: ($inc['url'] ?? '')));
                                ?>
                                    <li class="suw-incident <?php echo $is_down ? 'is-down' : 'is-up'; ?>">
                                        <span class="suw-dot <?php echo $is_down ? 'is-down' : 'is-up'; ?>"></span>
                                        <span class="suw-incident-text">
                                            <strong><?php echo esc_html($inc_host); ?></strong>
                                            <?php echo $is_down ? 'cayó' : 'recuperada'; ?> · <?php echo esc_html($inc['code'] ?? ''); ?>
                                            <em><?php echo esc_html($inc['time'] ?? ''); ?></em>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }
}
