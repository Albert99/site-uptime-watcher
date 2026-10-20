<?php
if (!defined('ABSPATH')) {
    exit;
}

class Site_Uptime_Watcher {

    private $opt_key = 'suw_options';
    private $state_key = 'suw_sites_state';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // Cron
        add_filter('cron_schedules', [$this, 'add_cron_interval']);
        add_action('suw_check_sites_event', [$this, 'check_all_sites']);
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_suw-settings') {
            return;
        }

        wp_enqueue_style(
            'suw-admin-style',
            SUW_PLUGIN_URL . 'assets/css/admin.css',
            [],
            SUW_VERSION
        );

        wp_enqueue_script(
            'suw-admin-script',
            SUW_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            SUW_VERSION,
            true
        );
    }

    public function add_cron_interval($schedules) {
        if (!isset($schedules['five_minutes'])) {
            $schedules['five_minutes'] = [
                'interval' => 300,
                'display'  => 'Cada 5 minutos'
            ];
        }
        return $schedules;
    }

    public static function activate_cron() {
        if (!wp_next_scheduled('suw_check_sites_event')) {
            wp_schedule_event(time(), 'five_minutes', 'suw_check_sites_event');
        }
    }

    public static function deactivate_cron() {
        $timestamp = wp_next_scheduled('suw_check_sites_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'suw_check_sites_event');
        }
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

    public function register_settings() {
        register_setting('suw_settings_group', $this->opt_key);
    }

    private function get_options() {
        return wp_parse_args(get_option($this->opt_key, []), [
            'notify_email' => get_option('admin_email'),
            'sites_list'   => "https://example.com",
            'max_failures' => 2,
        ]);
    }

    public function admin_page_html() {
        if (!current_user_can('manage_options')) return;

        $manual_run_message = false;
        if (isset($_POST['suw_run_manual_check']) && check_admin_referer('suw_manual_check_nonce')) {
            $this->check_all_sites();
            $manual_run_message = true;
        }

        $opts = $this->get_options();
        $states = get_option($this->state_key, []);

        $total_monitored = count($states);
        $total_up = 0;
        $total_down = 0;
        foreach ($states as $s) {
            if (($s['status'] ?? '') === 'UP') {
                $total_up++;
            } else {
                $total_down++;
            }
        }
        ?>
        <div class="suw-wrapper">
            <div class="suw-header">
                <div class="suw-title-wrap">
                    <h1>Uptime Watcher</h1>
                    <p>Monitor de disponibilidad proactivo con prevención de falsos positivos.</p>
                </div>
                <div>
                    <form method="post" id="suw-manual-check-form" style="margin: 0;">
                        <?php wp_nonce_field('suw_manual_check_nonce'); ?>
                        <input type="hidden" name="suw_run_manual_check" value="1">
                        <button type="submit" class="suw-btn suw-btn-secondary" id="suw-btn-check">
                            <span class="dashicons dashicons-update-alt"></span>
                            <span class="suw-btn-text">Comprobar ahora</span>
                        </button>
                    </form>
                </div>
            </div>

            <?php if ($manual_run_message): ?>
                <div class="suw-alert-banner">
                    Comprobación manual ejecutada con éxito.
                </div>
            <?php endif; ?>

            <div class="suw-kpi-grid">
                <div class="suw-kpi-card">
                    <span class="suw-kpi-label">Total Monitorizadas</span>
                    <span class="suw-kpi-val"><?php echo (int)$total_monitored; ?></span>
                </div>
                <div class="suw-kpi-card">
                    <span class="suw-kpi-label">Operativas (UP)</span>
                    <span class="suw-kpi-val is-up"><?php echo (int)$total_up; ?></span>
                </div>
                <div class="suw-kpi-card">
                    <span class="suw-kpi-label">Caídas (DOWN)</span>
                    <span class="suw-kpi-val <?php echo $total_down > 0 ? 'is-down' : ''; ?>"><?php echo (int)$total_down; ?></span>
                </div>
            </div>

            <div class="suw-main-grid">
                <div class="suw-card">
                    <div class="suw-card-header">
                        <h2>Estado de Servicios</h2>
                    </div>
                    <div class="suw-table-wrap">
                        <table class="suw-table">
                            <thead>
                                <tr>
                                    <th>URL</th>
                                    <th>Estado</th>
                                    <th>Fallos</th>
                                    <th>HTTP</th>
                                    <th>Último Test</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($states)): ?>
                                    <tr>
                                        <td colspan="5" class="suw-empty-state">
                                            No hay registros disponibles. Pulsa "Comprobar ahora" o guarda los ajustes.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($states as $url => $data): ?>
                                        <tr>
                                            <td class="suw-url-cell" title="<?php echo esc_attr($url); ?>">
                                                <?php echo esc_html($url); ?>
                                            </td>
                                            <td>
                                                <?php if (($data['status'] ?? '') === 'UP'): ?>
                                                    <span class="suw-pill suw-pill-up"><span class="suw-dot"></span>Online</span>
                                                <?php else: ?>
                                                    <span class="suw-pill suw-pill-down"><span class="suw-dot"></span>Caída</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo (int)($data['consecutive_failures'] ?? 0); ?></td>
                                            <td><code><?php echo esc_html($data['last_code'] ?: 'Err'); ?></code></td>
                                            <td class="suw-time-cell">
                                                <?php echo !empty($data['last_check']) ? esc_html($data['last_check']) : '—'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="suw-card">
                    <div class="suw-card-header">
                        <h2>Configuración</h2>
                    </div>
                    <div class="suw-card-body">
                        <form method="post" action="options.php">
                            <?php settings_fields('suw_settings_group'); ?>
                            
                            <div class="suw-field">
                                <label for="suw_email">Email de alertas</label>
                                <input type="email" id="suw_email" name="<?php echo esc_attr($this->opt_key); ?>[notify_email]" value="<?php echo esc_attr($opts['notify_email']); ?>" class="suw-input" required>
                                <div class="suw-field-desc">Recibe avisos de corte y recuperación.</div>
                            </div>

                            <div class="suw-field">
                                <label for="suw_urls">Webs (una por línea)</label>
                                <textarea id="suw_urls" name="<?php echo esc_attr($this->opt_key); ?>[sites_list]" class="suw-input" rows="5" required><?php echo esc_textarea($opts['sites_list']); ?></textarea>
                                <div class="suw-field-desc">Introduce las URLs completas (https://...).</div>
                            </div>

                            <div class="suw-field">
                                <label for="suw_retries">Confirmaciones mínimas</label>
                                <input type="number" id="suw_retries" min="2" max="5" name="<?php echo esc_attr($this->opt_key); ?>[max_failures]" value="<?php echo esc_attr($opts['max_failures']); ?>" class="suw-input">
                                <div class="suw-field-desc">Reintentos fallidos requeridos antes de avisar.</div>
                            </div>

                            <div class="suw-form-actions">
                                <button type="submit" class="suw-btn suw-btn-primary">Guardar Cambios</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function check_all_sites() {
        $opts = $this->get_options();
        $urls = array_filter(array_map('trim', explode("\n", $opts['sites_list'])));
        $states = get_option($this->state_key, []);
        $max_failures = (int)$opts['max_failures'];

        foreach ($urls as $url) {
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            if (!isset($states[$url])) {
                $states[$url] = [
                    'status'               => 'UP',
                    'consecutive_failures' => 0,
                    'last_code'            => 200,
                    'last_check'           => '',
                    'alert_sent'           => false
                ];
            }

            $response = wp_remote_get($url, [
                'timeout'     => 10,
                'redirection' => 5,
                'sslverify'   => false
            ]);

            $is_down = false;
            $http_code = 0;

            if (is_wp_error($response)) {
                $is_down = true;
                $http_code = $response->get_error_message();
            } else {
                $http_code = wp_remote_retrieve_response_code($response);
                if ($http_code >= 400 || $http_code === 0) {
                    $is_down = true;
                }
            }

            $states[$url]['last_code'] = $http_code;
            $states[$url]['last_check'] = current_time('mysql');

            if ($is_down) {
                $states[$url]['consecutive_failures']++;

                if ($states[$url]['consecutive_failures'] >= $max_failures && !$states[$url]['alert_sent']) {
                    $states[$url]['status'] = 'DOWN';
                    $states[$url]['alert_sent'] = true;
                    $this->send_down_email($url, $http_code, $opts['notify_email']);
                }
            } else {
                if ($states[$url]['alert_sent']) {
                    $this->send_recovery_email($url, $opts['notify_email']);
                }

                $states[$url]['status'] = 'UP';
                $states[$url]['consecutive_failures'] = 0;
                $states[$url]['alert_sent'] = false;
            }
        }

        update_option($this->state_key, $states);
    }

    private function send_down_email($url, $code, $recipient) {
        $subject = "[ALERTA] Web Caída: " . parse_url($url, PHP_URL_HOST);
        $message = "Se ha detectado una caída persistente en la siguiente web:\n\n";
        $message .= "URL: " . $url . "\n";
        $message .= "Motivo / Código HTTP: " . $code . "\n";
        $message .= "Fecha y hora: " . current_time('mysql') . "\n\n";
        $message .= "La alerta se ha enviado tras confirmar el fallo en reintentos consecutivos.";

        wp_mail($recipient, $subject, $message);
    }

    private function send_recovery_email($url, $recipient) {
        $subject = "[RECUPERADA] Web Online: " . parse_url($url, PHP_URL_HOST);
        $message = "La web vuelve a responder con normalidad (HTTP 200):\n\n";
        $message .= "URL: " . $url . "\n";
        $message .= "Fecha y hora de recuperación: " . current_time('mysql') . "\n";

        wp_mail($recipient, $subject, $message);
    }
}