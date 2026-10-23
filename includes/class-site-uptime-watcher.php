<?php
if (!defined('ABSPATH')) {
    exit;
}

class Site_Uptime_Watcher {

    const HOOK        = 'suw_check_sites_event';
    const HEALTH_OPT  = 'suw_health';    // estado del último ciclo
    const BATCH_WORST = 65;      // peor caso de un lote: petición + reintento SSL + reintento
    const MASS_MIN    = 5;       // mínimo de webs caídas para sospechar de la propia red
    const MASS_RATIO  = 0.5;     // proporción de webs caídas que dispara la comprobación de red
    const REMIND_AFTER = DAY_IN_SECONDS; // recordatorio de una caída que sigue abierta
    const DIGEST_MIN  = 3;       // desde N avisos en un ciclo se envía un único correo resumen
    const STALE_AFTER = 900;     // s sin ciclos para avisar de que el monitor está parado
    const BATCH_SIZE  = 20;      // peticiones simultáneas por lote
    const INLINE_MAX  = 60;      // máximo de webs comprobadas en línea al importar
    const QUEUE_HOOK  = 'suw_check_queue_event';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        add_filter('cron_schedules', [__CLASS__, 'register_interval']);
        add_action('init', [__CLASS__, 'ensure_cron']);
        add_action(self::HOOK, [$this, 'check_all_sites']);
        add_action(self::QUEUE_HOOK, [$this, 'check_all_sites']);

        foreach (['add_site', 'bulk_add', 'manual_check', 'delete_sites', 'save_settings', 'check_update', 'test_email', 'toggle_pause'] as $action) {
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
        delete_option(self::LOCK_OPT);
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

    /**
     * Añade webs a la lista con estado "en cola" y comprueba las primeras en paralelo.
     * Devuelve el número de webs nuevas.
     */
    private function add_sites(array $raw_urls) {
        $list   = Site_Uptime_Store::get_sites_list();
        $states = Site_Uptime_Store::get_states();
        $known  = [];
        $added  = [];
        $dupes  = 0;
        $bad    = 0;

        foreach ($list as $existing) {
            $known[Site_Uptime_Store::url_key($existing)] = true;
        }

        foreach ($raw_urls as $raw) {
            $clean = Site_Uptime_Store::sanitize_target_url($raw);

            if (!$clean) {
                $bad++;
                continue;
            }

            $key = Site_Uptime_Store::url_key($clean);
            if (isset($known[$key])) {
                $dupes++;
                continue;
            }

            $known[$key]    = true;
            $list[]         = $clean;
            $states[$clean] = Site_Uptime_Store::new_state();
            $added[]        = $clean;
        }

        if ($added) {
            Site_Uptime_Store::save_sites_list($list);
            Site_Uptime_Store::save_states($states);

            if (function_exists('set_time_limit')) {
                @set_time_limit(120);
            }

            // Comprobación en línea con presupuesto de tiempo y lock; si el cron está
            // ejecutándose o no da tiempo, lo pendiente queda en cola.
            if (Site_Uptime_Store::acquire_lock()) {
                try {
                    $this->run_checks(array_slice($added, 0, self::INLINE_MAX), time() + 60);
                } finally {
                    Site_Uptime_Store::release_lock();
                }
            }

            $after = Site_Uptime_Store::get_states();
            foreach ($added as $url) {
                if (empty($after[$url]['last_ts'])) {
                    wp_schedule_single_event(time() + 5, self::QUEUE_HOOK);
                    break;
                }
            }
        }

        return ['added' => count($added), 'duplicates' => $dupes, 'invalid' => $bad];
    }

    private function remove_sites(array $urls_to_remove) {
        $list    = Site_Uptime_Store::get_sites_list();
        $to_drop = array_values(array_intersect($urls_to_remove, $list));

        if (!$to_drop) {
            return 0;
        }

        $states = Site_Uptime_Store::get_states();
        foreach ($to_drop as $url) {
            unset($states[$url]);
        }

        Site_Uptime_Store::save_sites_list(array_diff($list, $to_drop));
        Site_Uptime_Store::save_states($states);

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
            $this->flash('No se pudo consultar GitHub o todavía no hay ninguna Release publicada. Inténtalo de nuevo en unos minutos.', 'error');
        }
        if (version_compare($remote['version'], SUW_VERSION, '>')) {
            $this->flash(sprintf('Hay una nueva versión disponible: %s (tienes la %s).', $remote['version'], SUW_VERSION));
        }
        $this->flash(sprintf('Estás en la última versión (%s).', SUW_VERSION));
    }

    /** Pausa o reanuda la vigilancia de una web (mantenimientos, webs en construcción). */
    public function handle_toggle_pause() {
        $this->guard('suw_toggle_pause');

        $url    = isset($_GET['url']) ? esc_url_raw(wp_unslash($_GET['url'])) : '';
        $states = Site_Uptime_Store::get_states();

        if ($url === '' || !in_array($url, Site_Uptime_Store::get_sites_list(), true)) {
            $this->flash('No se encontró esa web en la lista.', 'error');
        }

        $state = $states[$url] ?? Site_Uptime_Store::new_state();

        if (!empty($state['paused'])) {
            // Al reanudar vuelve a la cola y se comprueba la primera.
            $state = array_merge(Site_Uptime_Store::new_state(), ['php_version' => $state['php_version'] ?? '']);
            $msg   = 'Vigilancia reanudada.';
        } else {
            $state['paused']               = true;
            $state['status']               = 'PAUSED';
            $state['consecutive_failures'] = 0;
            $state['alert_sent']           = false;
            $state['alert_ts']             = 0;
            $state['first_failure_ts']     = 0;
            $state['last_code']            = 'En pausa';
            $msg                           = 'Web en pausa: no se comprobará ni enviará avisos hasta que la reanudes.';
        }

        $states[$url] = $state;
        Site_Uptime_Store::save_states($states);

        $this->flash($msg);
    }

    public function handle_test_email() {
        $this->guard('suw_test_email');

        $opts = Site_Uptime_Store::get_options();
        $to   = $opts['notify_email'];
        $demo = home_url('/');

        $ok_down = Site_Uptime_Mailer::send_down($demo, 'HTTP 503', $to, (int) $opts['max_failures'], (int) $opts['max_failures'], time() - 12 * MINUTE_IN_SECONDS);
        $ok_up   = Site_Uptime_Mailer::send_recovery($demo, $to, time() - 12 * MINUTE_IN_SECONDS, 'HTTP 200');

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

        $opts                 = Site_Uptime_Store::get_options();
        $opts['notify_email'] = $email;
        $opts['max_failures'] = $max;
        $opts['verify_ssl']   = empty($_POST['verify_ssl']) ? 0 : 1;

        $ignored               = isset($_POST['ignored_codes']) ? wp_unslash($_POST['ignored_codes']) : '';
        $opts['ignored_codes'] = implode(', ', Site_Uptime_Store::parse_ignored_codes($ignored));

        $heartbeat = isset($_POST['heartbeat_url']) ? trim((string) wp_unslash($_POST['heartbeat_url'])) : '';
        if ($heartbeat !== '') {
            $heartbeat = esc_url_raw($heartbeat, ['http', 'https']);
            if ($heartbeat === '' || !wp_http_validate_url($heartbeat)) {
                $this->flash('La URL de heartbeat no es válida.', 'error');
            }
        }
        $opts['heartbeat_url'] = $heartbeat;

        update_option(Site_Uptime_Store::OPT_KEY, $opts);

        $this->flash('Preferencias guardadas.');
    }

    /* ------------------------------------------------------------------
     * Comprobación
     * ------------------------------------------------------------------ */

    /**
     * Ciclo completo (cron y botón manual). Devuelve false si ya hay otro en curso.
     */
    public function check_all_sites() {
        if (!Site_Uptime_Store::acquire_lock()) {
            return false;
        }

        $started = time();

        if (function_exists('set_time_limit')) {
            @set_time_limit(Site_Uptime_Store::LOCK_TTL);
        }

        try {
            $listed = array_values(array_filter(Site_Uptime_Store::get_sites_list(), function ($url) {
                return (bool) filter_var($url, FILTER_VALIDATE_URL);
            }));

            // Poda estados huérfanos y crea los que falten.
            $states = Site_Uptime_Store::get_states();
            $states = array_intersect_key($states, array_flip($listed));
            foreach ($listed as $url) {
                if (!isset($states[$url])) {
                    $states[$url] = Site_Uptime_Store::new_state();
                }
            }
            Site_Uptime_Store::save_states($states);

            // Las webs en pausa no se comprueban.
            $urls = array_values(array_filter($listed, function ($url) use ($states) {
                return empty($states[$url]['paused']);
            }));

            // Las nunca comprobadas y las más antiguas primero: nada se queda sin turno.
            // Se ordena por timestamp (no por texto) para que el cambio de hora no altere el orden.
            usort($urls, function ($a, $b) use ($states) {
                return (int) ($states[$a]['last_ts'] ?? 0) <=> (int) ($states[$b]['last_ts'] ?? 0);
            });

            // Margen para que el último lote, en el peor caso, termine antes de caducar el lock.
            $outcome = $this->run_checks($urls, $started + Site_Uptime_Store::LOCK_TTL - self::BATCH_WORST - 10);

            $this->record_health($started, count($urls), $outcome);
            $this->send_heartbeat();
        } finally {
            Site_Uptime_Store::release_lock();
        }

        return true;
    }

    /* ------------------------------------------------------------------
     * Lock atómico (mismo patrón que WP_Upgrader::create_lock)
     * ------------------------------------------------------------------ */

    /* ------------------------------------------------------------------
     * Comprobación por ciclos
     * ------------------------------------------------------------------ */

    /**
     * Comprueba las URLs por lotes en paralelo y, con todos los resultados en la mano,
     * decide si el ciclo es fiable antes de tocar el estado o enviar nada.
     *
     * @return array{checked:int,failed:int,discarded:bool}
     */
    private function run_checks(array $urls, $deadline = 0) {
        $outcome = ['checked' => 0, 'failed' => 0, 'discarded' => false];

        if (!$urls) {
            return $outcome;
        }

        $opts    = Site_Uptime_Store::get_options();
        $max     = max(1, (int) $opts['max_failures']);
        $verify  = !empty($opts['verify_ssl']);
        $ignored = Site_Uptime_Store::parse_ignored_codes($opts['ignored_codes']);
        $prior   = Site_Uptime_Store::get_states();
        $evals   = [];

        foreach (array_chunk($urls, self::BATCH_SIZE) as $chunk) {
            if ($deadline && time() > $deadline) {
                break; // el resto se comprueba en el siguiente ciclo
            }

            // Reintento inmediato solo en el primer fallo: filtra microcortes
            // sin alargar el ciclo para webs que ya están en ventana de confirmación.
            $retryable = array_values(array_filter($chunk, function ($url) use ($prior) {
                return empty($prior[$url]['consecutive_failures']);
            }));

            $evals += Site_Uptime_Checker::probe($chunk, $retryable, $verify, $ignored);
        }

        $outcome['checked'] = count($evals);
        if (!$evals) {
            return $outcome;
        }

        $down = count(array_filter($evals, function ($eval) {
            return $eval['is_down'];
        }));
        $outcome['failed'] = $down;

        // Si media lista cae a la vez y el propio servidor no sale a internet,
        // el fallo es nuestro: se descarta el ciclo sin tocar estados ni enviar alertas.
        if ($this->looks_like_own_outage(count($evals), $down)) {
            $outcome['discarded'] = true;
            return $outcome;
        }

        // Estado y lista se releen justo ahora: una web borrada mientras corría el ciclo no resucita.
        $current = Site_Uptime_Store::get_sites_list();
        $states  = Site_Uptime_Store::get_states();
        $outbox  = ['down' => [], 'up' => [], 'remind' => []];

        foreach ($evals as $url => $eval) {
            if (!in_array($url, $current, true) || !empty($states[$url]['paused'])) {
                continue; // borrada o pausada mientras corría el ciclo
            }
            $states[$url] = $this->apply_result($url, $states[$url] ?? Site_Uptime_Store::new_state(), $eval, $max, $outbox);
        }

        // La alerta solo se da por enviada si el correo salió; si no, se reintenta en el siguiente ciclo.
        $sent = $this->flush_outbox($outbox, $opts['notify_email'], $max);
        foreach ($sent['down'] as $url) {
            if (isset($states[$url])) {
                $states[$url]['alert_sent'] = true;
                $states[$url]['alert_ts']   = time();
            }
        }
        foreach ($sent['remind'] as $url) {
            if (isset($states[$url])) {
                $states[$url]['alert_ts'] = time();
            }
        }

        Site_Uptime_Store::save_states($states);

        return $outcome;
    }

    private function apply_result($url, array $state, array $eval, $max, array &$outbox) {
        $state['last_code']   = $eval['code'];
        $state['last_check']  = current_time('mysql');
        $state['last_ts']     = time();
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
                    Site_Uptime_Store::log_incident($url, 'down', $eval['code']);
                }
                $state['status'] = 'DOWN';

                if (!empty($state['alert_sent'])) {
                    // Caída que sigue abierta: un recordatorio cada REMIND_AFTER (sin ráfaga en estados antiguos).
                    if (empty($state['alert_ts'])) {
                        $state['alert_ts'] = time();
                    } elseif ((time() - (int) $state['alert_ts']) >= self::REMIND_AFTER) {
                        $outbox['remind'][] = [
                            'url'      => $url,
                            'code'     => $eval['code'],
                            'detail'   => isset($eval['detail']) ? $eval['detail'] : '',
                            'first_ts' => (int) ($state['first_failure_ts'] ?? 0),
                        ];
                    }
                } else {
                    $outbox['down'][] = [
                        'url'      => $url,
                        'code'     => $eval['code'],
                        'detail'   => isset($eval['detail']) ? $eval['detail'] : '',
                        'failures' => (int) $state['consecutive_failures'],
                        'first_ts' => (int) $state['first_failure_ts'],
                    ];
                }
            } elseif (($state['status'] ?? '') !== 'DOWN') {
                $state['status'] = 'PENDING';
            }

            return $state;
        }

        if (($state['status'] ?? '') === 'DOWN') {
            Site_Uptime_Store::log_incident($url, 'recovered', $eval['code']);
        }

        if (!empty($state['alert_sent'])) {
            $outbox['up'][] = [
                'url'      => $url,
                'code'     => $eval['code'],
                'first_ts' => (int) ($state['first_failure_ts'] ?? 0),
            ];
        }

        $state['status']               = 'UP';
        $state['consecutive_failures'] = 0;
        $state['alert_sent']           = false;
        $state['alert_ts']             = 0;
        $state['first_failure_ts']     = 0;

        return $state;
    }

    /* ------------------------------------------------------------------
     * Caída masiva: ¿falla el monitor o fallan las webs?
     * ------------------------------------------------------------------ */

    private function looks_like_own_outage($total, $down) {
        if ($total < self::MASS_MIN || $down < self::MASS_MIN || $down < $total * self::MASS_RATIO) {
            return false;
        }

        return !Site_Uptime_Checker::has_internet();
    }

    /**
     * Envía los avisos del ciclo: uno por web, o un único resumen si son muchos.
     *
     * @return array{down:string[],remind:string[]} URLs cuyo aviso (caída o recordatorio) salió correctamente.
     */
    private function flush_outbox(array $outbox, $recipient, $max) {
        $sent = ['down' => [], 'remind' => []];

        if (count($outbox['down']) >= self::DIGEST_MIN) {
            if (Site_Uptime_Mailer::send_digest('down', $outbox['down'], $recipient)) {
                $sent['down'] = array_column($outbox['down'], 'url');
            }
        } else {
            foreach ($outbox['down'] as $item) {
                if (Site_Uptime_Mailer::send_down($item['url'], $item['code'], $recipient, $item['failures'], $max, $item['first_ts'], $item['detail'])) {
                    $sent['down'][] = $item['url'];
                }
            }
        }

        if (count($outbox['remind']) >= self::DIGEST_MIN) {
            if (Site_Uptime_Mailer::send_digest('remind', $outbox['remind'], $recipient)) {
                $sent['remind'] = array_column($outbox['remind'], 'url');
            }
        } else {
            foreach ($outbox['remind'] as $item) {
                if (Site_Uptime_Mailer::send_reminder($item['url'], $item['code'], $recipient, $item['first_ts'], $item['detail'])) {
                    $sent['remind'][] = $item['url'];
                }
            }
        }

        if (count($outbox['up']) >= self::DIGEST_MIN) {
            Site_Uptime_Mailer::send_digest('up', $outbox['up'], $recipient);
        } else {
            foreach ($outbox['up'] as $item) {
                Site_Uptime_Mailer::send_recovery($item['url'], $recipient, $item['first_ts'], $item['code']);
            }
        }

        return $sent;
    }

    /* ------------------------------------------------------------------
     * Salud del propio monitor
     * ------------------------------------------------------------------ */

    private function record_health($started, $total, array $outcome) {
        $prev = get_option(self::HEALTH_OPT, []);
        $prev = is_array($prev) ? $prev : [];

        $health = [
            'last_cycle' => time(),
            'duration'   => time() - $started,
            'checked'    => $outcome['checked'],
            'total'      => $total,
            'guard'      => isset($prev['guard']) ? $prev['guard'] : null,
        ];

        if (!empty($outcome['discarded'])) {
            $health['guard'] = ['time' => time(), 'failed' => $outcome['failed'], 'total' => $outcome['checked']];
        }

        update_option(self::HEALTH_OPT, $health, false);
    }

    /** Latido a un servicio externo (healthchecks.io, UptimeRobot...) que avisa si deja de llegar. */
    private function send_heartbeat() {
        $opts = Site_Uptime_Store::get_options();
        $url  = (string) $opts['heartbeat_url'];

        if ($url === '') {
            return;
        }

        wp_safe_remote_get($url, [
            'timeout'    => 5,
            'redirection' => 2,
            'user-agent' => 'SiteUptimeWatcher/' . SUW_VERSION,
        ]);
    }

    /* ------------------------------------------------------------------
     * Correos (HTML con versión de texto alternativa)
     * ------------------------------------------------------------------ */

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
        $states = Site_Uptime_Store::get_states();
        $rows   = [];

        foreach (Site_Uptime_Store::get_sites_list() as $url) {
            $data = $states[$url] ?? Site_Uptime_Store::new_state();
            $host = preg_replace('#^www\.#i', '', (string) (parse_url($url, PHP_URL_HOST) ?: $url));

            $rows[] = [
                'url'    => $url,
                'host'   => $host,
                'status' => strtolower($data['status'] ?? 'PENDING'),
                'data'   => $data,
            ];
        }

        $weight = ['down' => 0, 'pending' => 1, 'up' => 2, 'paused' => 3];
        usort($rows, function ($a, $b) use ($weight) {
            $wa = $weight[$a['status']] ?? 4;
            $wb = $weight[$b['status']] ?? 4;
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
        $opts   = Site_Uptime_Store::get_options();
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

        $total_up = $total_down = $total_pending = $total_paused = 0;
        foreach ($rows as $row) {
            if ($row['status'] === 'up') {
                $total_up++;
            } elseif ($row['status'] === 'down') {
                $total_down++;
            } elseif ($row['status'] === 'paused') {
                $total_paused++;
            } else {
                $total_pending++;
            }
        }

        $next_run = wp_next_scheduled(self::HOOK);
        $health   = get_option(self::HEALTH_OPT, []);
        $health   = is_array($health) ? $health : [];
        $last_run = isset($health['last_cycle']) ? (int) $health['last_cycle'] : 0;
        $is_stale = $last_run > 0 && (time() - $last_run) > self::STALE_AFTER;
        $guard    = (!empty($health['guard']) && (time() - (int) $health['guard']['time']) < DAY_IN_SECONDS) ? $health['guard'] : null;

        include SUW_PLUGIN_DIR . 'templates/admin-page.php';
    }
}
