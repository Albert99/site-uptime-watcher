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

        add_filter('cron_schedules', [$this, 'add_cron_interval']);
        add_action('suw_check_sites_event', [$this, 'check_all_sites']);
    }

    public function enqueue_assets($hook) {
        if (!isset($_GET['page']) || $_GET['page'] !== 'suw-settings') {
            return;
        }

        wp_enqueue_style(
            'suw-font-outfit',
            'https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap',
            [],
            null
        );

        $css_file = SUW_PLUGIN_DIR . 'assets/css/admin.css';
        $js_file  = SUW_PLUGIN_DIR . 'assets/js/admin.js';

        $css_ver = file_exists($css_file) ? filemtime($css_file) : SUW_VERSION;
        $js_ver  = file_exists($js_file) ? filemtime($js_file) : SUW_VERSION;

        wp_enqueue_style('suw-admin-style', SUW_PLUGIN_URL . 'assets/css/admin.css', ['suw-font-outfit'], $css_ver);
        wp_enqueue_script('suw-admin-script', SUW_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], $js_ver, true);
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

    private function sanitize_target_url($raw_url) {
        $url = trim($raw_url);
        if (empty($url)) return false;

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $clean = esc_url_raw($url);
        return filter_var($clean, FILTER_VALIDATE_URL) ? $clean : false;
    }

    private function add_single_site($url) {
        $url = $this->sanitize_target_url($url);
        if (!$url) return false;

        $opts = $this->get_options();
        $urls = array_filter(array_map('trim', explode("\n", $opts['sites_list'])));

        if (!in_array($url, $urls, true)) {
            $urls[] = $url;
            $opts['sites_list'] = implode("\n", array_unique($urls));
            update_option($this->opt_key, $opts);
            $this->check_single_site($url);
            return true;
        }

        return false;
    }

    private function add_bulk_sites($raw_text) {
        $lines = preg_split('/[\r\n,]+/', $raw_text);
        $added = 0;
        $opts = $this->get_options();
        $existing = array_filter(array_map('trim', explode("\n", $opts['sites_list'])));

        foreach ($lines as $line) {
            $clean = $this->sanitize_target_url($line);
            if ($clean && !in_array($clean, $existing, true)) {
                $existing[] = $clean;
                $this->check_single_site($clean);
                $added++;
            }
        }

        if ($added > 0) {
            $opts['sites_list'] = implode("\n", array_unique($existing));
            update_option($this->opt_key, $opts);
        }

        return $added;
    }

    private function remove_sites(array $urls_to_remove) {
        if (empty($urls_to_remove)) return 0;

        $opts = $this->get_options();
        $states = get_option($this->state_key, []);

        $current_lines = array_map('trim', explode("\n", $opts['sites_list']));
        $new_lines = [];

        foreach ($current_lines as $line) {
            if (!in_array($line, $urls_to_remove, true)) {
                $new_lines[] = $line;
            }
        }

        foreach ($urls_to_remove as $u) {
            unset($states[$u]);
        }

        $opts['sites_list'] = implode("\n", array_filter($new_lines));
        update_option($this->opt_key, $opts);
        update_option($this->state_key, $states);

        return count($urls_to_remove);
    }

    public function admin_page_html() {
        if (!current_user_can('manage_options')) return;

        $flash_notice = '';
        $flash_type = 'success';

        if (isset($_POST['suw_add_site_action']) && check_admin_referer('suw_add_site_nonce')) {
            $new_url = isset($_POST['suw_new_url']) ? sanitize_text_field($_POST['suw_new_url']) : '';
            if ($this->add_single_site($new_url)) {
                $flash_notice = 'Web agregada y comprobada.';
            } else {
                $flash_notice = 'URL no válida o ya monitorizada.';
                $flash_type = 'error';
            }
        }

        if (isset($_POST['suw_bulk_add_action']) && check_admin_referer('suw_bulk_add_nonce')) {
            $bulk_text = isset($_POST['suw_bulk_urls']) ? sanitize_textarea_field($_POST['suw_bulk_urls']) : '';
            $added_count = $this->add_bulk_sites($bulk_text);
            if ($added_count > 0) {
                $flash_notice = sprintf('Se han importado y comprobado %d webs correctamente.', $added_count);
            } else {
                $flash_notice = 'No se añadieron nuevas URLs (verifica si ya existían o su formato).';
                $flash_type = 'error';
            }
        }

        if (isset($_POST['suw_run_manual_check']) && check_admin_referer('suw_manual_check_nonce')) {
            $this->check_all_sites();
            $flash_notice = 'Comprobación manual realizada.';
        }

        if (isset($_POST['suw_delete_sites']) && check_admin_referer('suw_delete_sites_nonce')) {
            $urls = isset($_POST['selected_urls']) ? (array)$_POST['selected_urls'] : [];
            $urls = array_map('sanitize_text_field', $urls);
            $deleted_count = $this->remove_sites($urls);
            $flash_notice = sprintf('%d web(s) eliminada(s).', $deleted_count);
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
        <div class="suw-container">

            <header class="suw-header-bar">
                <div class="suw-header-text">
                    <h1>Uptime Watcher</h1>
                    <p>Monitor de estado y caídas de sitios web.</p>
                </div>
                <div class="suw-header-actions">
                    <button type="button" class="suw-btn suw-btn-tonal" id="suw-btn-open-bulk">
                        <svg class="suw-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12" y2="12"></line><line x1="9" y1="15" x2="15" y2="15"></line></svg>
                        <span>Subir muchas webs</span>
                    </button>

                    <form method="post" id="suw-manual-check-form" style="margin:0;">
                        <?php wp_nonce_field('suw_manual_check_nonce'); ?>
                        <input type="hidden" name="suw_run_manual_check" value="1">
                        <button type="submit" class="suw-btn suw-btn-tonal" id="suw-btn-check">
                            <svg class="suw-svg suw-sync-target" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                            <span>Comprobar ahora</span>
                        </button>
                    </form>
                </div>
            </header>

            <?php if (!empty($flash_notice)): ?>
                <div class="suw-toast <?php echo $flash_type === 'error' ? 'is-error' : 'is-success'; ?>">
                    <span><?php echo esc_html($flash_notice); ?></span>
                </div>
            <?php endif; ?>

            <div class="suw-modal" id="suw-bulk-modal" style="display: none;">
                <div class="suw-modal-backdrop"></div>
                <div class="suw-modal-dialog">
                    <div class="suw-modal-head">
                        <h3>Subir lote de webs</h3>
                        <button type="button" class="suw-modal-close" id="suw-btn-close-bulk">&times;</button>
                    </div>
                    <form method="post">
                        <?php wp_nonce_field('suw_bulk_add_nonce'); ?>
                        <input type="hidden" name="suw_bulk_add_action" value="1">

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
                <form method="post" class="suw-add-form">
                    <?php wp_nonce_field('suw_add_site_nonce'); ?>
                    <input type="hidden" name="suw_add_site_action" value="1">
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
                    <span class="suw-pill-chip"><?php echo (int)$total_monitored; ?></span>
                </button>
                <button type="button" class="suw-pill-tab" data-filter="up">
                    <span class="suw-dot is-up"></span>
                    <span>Operativos</span>
                    <span class="suw-pill-chip is-up"><?php echo (int)$total_up; ?></span>
                </button>
                <button type="button" class="suw-pill-tab" data-filter="down">
                    <span class="suw-dot is-down"></span>
                    <span>Caídos</span>
                    <span class="suw-pill-chip is-down"><?php echo (int)$total_down; ?></span>
                </button>
            </div>

            <div class="suw-layout">
                <div class="suw-main-col">
                    <form method="post" id="suw-bulk-form">
                        <?php wp_nonce_field('suw_delete_sites_nonce'); ?>
                        <input type="hidden" name="suw_delete_sites" value="1">

                        <div class="suw-list-toolbar">
                            <label class="suw-select-all-label">
                                <input type="checkbox" id="suw-select-all" class="suw-check">
                                <span>Seleccionar todo</span>
                            </label>
                            <button type="submit" id="suw-btn-delete-selected" class="suw-btn suw-btn-danger" disabled>
                                <svg class="suw-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                <span>Eliminar seleccionadas</span>
                            </button>
                        </div>

                        <div class="suw-list-stack">
                            <?php if (empty($states)): ?>
                                <div class="suw-empty-card">
                                    <p>No hay webs agregadas en la lista.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($states as $url => $data): 
                                    $is_up = (($data['status'] ?? '') === 'UP');
                                    $host = parse_url($url, PHP_URL_HOST) ?: $url;
                                    $favicon = 'https://www.google.com/s2/favicons?domain=' . urlencode($host) . '&sz=64';
                                    $code = $data['last_code'] ?: '—';
                                ?>
                                    <div class="suw-row-card <?php echo $is_up ? 'is-up' : 'is-down'; ?>" data-status="<?php echo $is_up ? 'up' : 'down'; ?>">
                                        <div class="suw-row-select">
                                            <input type="checkbox" name="selected_urls[]" value="<?php echo esc_attr($url); ?>" class="suw-check suw-site-checkbox">
                                        </div>

                                        <div class="suw-row-favicon">
                                            <img src="<?php echo esc_url($favicon); ?>" alt="" onerror="this.style.display='none';">
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
                                            <?php if ($is_up): ?>
                                                <span class="suw-badge is-up"><span class="suw-dot is-up"></span>Online</span>
                                            <?php else: ?>
                                                <span class="suw-badge is-down"><span class="suw-dot is-down"></span>Caída</span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="suw-row-code">
                                            <span class="suw-code-tag <?php echo $is_up ? 'is-good' : 'is-bad'; ?>">
                                                <?php echo esc_html($code); ?>
                                            </span>
                                        </div>

                                        <div class="suw-row-meta">
                                            <span class="suw-meta-failures">Fallos: <strong><?php echo (int)($data['consecutive_failures'] ?? 0); ?></strong></span>
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

                        <form method="post" action="options.php">
                            <?php settings_fields('suw_settings_group'); ?>
                            <input type="hidden" name="<?php echo esc_attr($this->opt_key); ?>[sites_list]" value="<?php echo esc_textarea($opts['sites_list']); ?>">

                            <div class="suw-field">
                                <label for="suw_email">Email de alerta</label>
                                <input type="email" id="suw_email" name="<?php echo esc_attr($this->opt_key); ?>[notify_email]" value="<?php echo esc_attr($opts['notify_email']); ?>" class="suw-control" required>
                                <small>Receptor de avisos de corte y recuperación.</small>
                            </div>

                            <div class="suw-field">
                                <label for="suw_max_failures">Sensibilidad anti falsos positivos</label>
                                <select id="suw_max_failures" name="<?php echo esc_attr($this->opt_key); ?>[max_failures]" class="suw-control suw-select">
                                    <option value="2" <?php selected($opts['max_failures'], 2); ?>>2 comprobaciones (10 min)</option>
                                    <option value="3" <?php selected($opts['max_failures'], 3); ?>>3 comprobaciones (15 min)</option>
                                    <option value="4" <?php selected($opts['max_failures'], 4); ?>>4 comprobaciones (20 min)</option>
                                    <option value="5" <?php selected($opts['max_failures'], 5); ?>>5 comprobaciones (25 min)</option>
                                </select>
                                <small>Número de fallos consecutivos antes de enviar el correo.</small>
                            </div>

                            <button type="submit" class="suw-btn suw-btn-primary suw-btn-full">
                                Guardar preferencias
                            </button>
                        </form>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }

    private function inspect_site_response($response) {
        if (is_wp_error($response)) {
            return [
                'is_down' => true,
                'code'    => $response->get_error_message()
            ];
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $body      = wp_remote_retrieve_body($response);

        if ($http_code >= 400 || $http_code === 0) {
            return [
                'is_down' => true,
                'code'    => 'HTTP ' . ($http_code ?: 'Error')
            ];
        }

        if (empty(trim($body))) {
            return [
                'is_down' => true,
                'code'    => 'WP WSOD (Página blanca)'
            ];
        }

        $signatures = [
            'error crítico en este sitio web'               => 'WP Error Crítico PHP',
            'critical error on this website'                => 'WP Critical Error',
            'Error establishing a database connection'      => 'Error MySQL WP',
            'Error al establecer una conexión con la base'   => 'Error MySQL WP',
            'No disponible por mantenimiento programado'    => 'Mantenimiento WP',
            'Briefly unavailable for scheduled maintenance' => 'WP Maintenance'
        ];

        foreach ($signatures as $needle => $label) {
            if (stripos($body, $needle) !== false) {
                return [
                    'is_down' => true,
                    'code'    => $label
                ];
            }
        }

        return [
            'is_down' => false,
            'code'    => 'HTTP ' . $http_code
        ];
    }

    private function check_single_site($url) {
        $states = get_option($this->state_key, []);

        $response = wp_remote_get($url, [
            'timeout'     => 12,
            'redirection' => 5,
            'sslverify'   => false,
            'user-agent'  => 'Mozilla/5.0 (compatible; SiteUptimeWatcher/1.4; +' . home_url() . ')'
        ]);

        $eval = $this->inspect_site_response($response);

        $states[$url] = [
            'status'               => $eval['is_down'] ? 'DOWN' : 'UP',
            'consecutive_failures' => $eval['is_down'] ? 1 : 0,
            'last_code'            => $eval['code'],
            'last_check'           => current_time('mysql'),
            'alert_sent'           => false
        ];

        update_option($this->state_key, $states);
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
                    'last_code'            => 'HTTP 200',
                    'last_check'           => '',
                    'alert_sent'           => false
                ];
            }

            $response = wp_remote_get($url, [
                'timeout'     => 12,
                'redirection' => 5,
                'sslverify'   => false,
                'user-agent'  => 'Mozilla/5.0 (compatible; SiteUptimeWatcher/1.4; +' . home_url() . ')'
            ]);

            $eval = $this->inspect_site_response($response);
            $states[$url]['last_code'] = $eval['code'];
            $states[$url]['last_check'] = current_time('mysql');

            if ($eval['is_down']) {
                $states[$url]['consecutive_failures']++;

                if ($states[$url]['consecutive_failures'] >= $max_failures && !$states[$url]['alert_sent']) {
                    $states[$url]['status'] = 'DOWN';
                    $states[$url]['alert_sent'] = true;
                    $this->send_down_email($url, $eval['code'], $opts['notify_email']);
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
        $message = "Se ha detectado una caída o error en la siguiente web:\n\n";
        $message .= "URL: " . $url . "\n";
        $message .= "Diagnóstico: " . $code . "\n";
        $message .= "Fecha y hora: " . current_time('mysql') . "\n";

        wp_mail($recipient, $subject, $message);
    }

    private function send_recovery_email($url, $recipient) {
        $subject = "[RECUPERADA] Web Online: " . parse_url($url, PHP_URL_HOST);
        $message = "La web vuelve a estar operativa:\n\n";
        $message .= "URL: " . $url . "\n";
        $message .= "Fecha y hora de recuperación: " . current_time('mysql') . "\n";

        wp_mail($recipient, $subject, $message);
    }
}
