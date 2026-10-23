<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Acceso a datos: opciones, lista de webs, estados por web, incidentes y lock del ciclo.
 */
class Site_Uptime_Store {

    const OPT_KEY       = 'suw_options';
    const STATE_KEY     = 'suw_sites_state';
    const INCIDENTS_OPT = 'suw_incidents';
    const LOCK_OPT      = 'suw_lock'; // lock atómico en la tabla de opciones
    const LOCK_TTL      = 270;        // < 300 s: el lock caduca antes del siguiente ciclo

    public static function get_options() {
        return wp_parse_args(get_option(self::OPT_KEY, []), [
            'notify_email' => get_option('admin_email'),
            'sites_list'   => '',
            'max_failures' => 2,
            'verify_ssl'   => 1,
            'ignored_codes' => '',
            'heartbeat_url' => '',
        ]);
    }

    /** Códigos HTTP 4xx/5xx que no deben contar como caída (p. ej. 403 de un WAF). */
    public static function parse_ignored_codes($raw) {
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

    public static function log_incident($url, $type, $code) {
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

    public static function get_sites_list() {
        $opts = self::get_options();
        $urls = array_map('trim', explode("\n", (string) $opts['sites_list']));
        return array_values(array_unique(array_filter($urls)));
    }

    public static function save_sites_list(array $urls) {
        $opts = self::get_options();
        $opts['sites_list'] = implode("\n", array_values(array_unique(array_filter($urls))));
        update_option(self::OPT_KEY, $opts);
    }

    public static function get_states() {
        $states = get_option(self::STATE_KEY, []);
        return is_array($states) ? $states : [];
    }

    public static function save_states(array $states) {
        update_option(self::STATE_KEY, $states, false);
    }

    public static function new_state() {
        return [
            'status'               => 'PENDING',
            'consecutive_failures' => 0,
            'last_code'            => 'En cola',
            'last_check'           => '',
            'last_ts'              => 0,
            'alert_sent'           => false,
        ];
    }

    public static function sanitize_target_url($raw_url) {
        $url = trim((string) $raw_url);
        if ($url === '') {
            return false;
        }

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $clean = esc_url_raw($url, ['http', 'https']);
        if (!filter_var($clean, FILTER_VALIDATE_URL) || !parse_url($clean, PHP_URL_HOST)) {
            return false;
        }

        // Rechaza destinos internos (localhost, IPs privadas, metadatos cloud) y esquemas raros.
        // Los puertos personalizados son legítimos al vigilar una web: se permite el de esta URL.
        $port       = (int) wp_parse_url($clean, PHP_URL_PORT);
        $allow_port = static function ($ports) use ($port) {
            if ($port > 0) {
                $ports[] = $port;
            }
            return $ports;
        };

        add_filter('http_allowed_safe_ports', $allow_port);
        $safe = wp_http_validate_url($clean);
        remove_filter('http_allowed_safe_ports', $allow_port);

        return $safe ? $clean : false;
    }

    /**
     * Clave de comparación: ignora http/https, "www.", mayúsculas en el dominio,
     * puertos por defecto y la barra final, para que ejemplo.com y
     * https://www.ejemplo.com/ cuenten como la misma web.
     */
    public static function url_key($url) {
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

    public static function acquire_lock() {
        global $wpdb;

        $now = time();

        // INSERT IGNORE sobre option_name único: solo un proceso puede crear la fila.
        $insert = function () use ($wpdb, $now) {
            return (bool) $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')",
                self::LOCK_OPT,
                (string) $now
            ));
        };

        if ($insert()) {
            return true;
        }

        $held = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s",
            self::LOCK_OPT
        ));

        if ($held === 0) {
            return $insert(); // la fila desapareció entre medias: estaba libre
        }

        if (($now - $held) < self::LOCK_TTL) {
            return false;
        }

        // Lock caducado (un ciclo anterior murió): relevo con compare-and-swap,
        // solo uno de los procesos que compiten consigue modificar la fila.
        return (bool) $wpdb->query($wpdb->prepare(
            "UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = %s AND option_value = %s",
            (string) $now,
            self::LOCK_OPT,
            (string) $held
        ));
    }

    public static function release_lock() {
        global $wpdb;

        $wpdb->delete($wpdb->options, ['option_name' => self::LOCK_OPT]);
    }
}
