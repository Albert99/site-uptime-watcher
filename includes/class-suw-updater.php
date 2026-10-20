<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Actualizador desde GitHub (repositorio público).
 *
 * Orden de consulta:
 *  1. Última Release (tag vX.Y.Z; si tiene un .zip adjunto se usa ese, si no el zipball).
 *  2. Si no hay Releases: cabecera "Version:" de site-uptime-watcher.php en la rama por defecto.
 *
 * Integra la actualización en el sistema nativo de WordPress (Escritorio > Actualizaciones,
 * lista de Plugins, actualizaciones automáticas) y añade accesos visibles.
 */
class Site_Uptime_Updater {

    const REPO      = 'Albert99/site-uptime-watcher';
    const CACHE_KEY = 'suw_remote_release';
    const CACHE_TTL = 21600; // 6 h
    const ERROR_TTL = 1800;  // 30 min si GitHub no responde

    public static function init() {
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'inject_update']);
        add_filter('plugins_api', [__CLASS__, 'plugin_info'], 20, 3);
        add_filter('upgrader_source_selection', [__CLASS__, 'fix_source_dir'], 10, 4);
        add_action('upgrader_process_complete', [__CLASS__, 'clear_cache'], 10, 2);
        add_filter('plugin_row_meta', [__CLASS__, 'row_meta'], 10, 2);
        add_filter('plugin_action_links_' . self::basename(), [__CLASS__, 'action_links']);
    }

    /* ------------------------------------------------------------------
     * Identidad del plugin
     * ------------------------------------------------------------------ */

    public static function basename() {
        return plugin_basename(SUW_PLUGIN_FILE);
    }

    public static function slug() {
        return dirname(self::basename());
    }

    public static function repo_url() {
        return 'https://github.com/' . self::REPO;
    }

    /* ------------------------------------------------------------------
     * Consulta a GitHub (con caché)
     * ------------------------------------------------------------------ */

    /**
     * @return array|null ['version','package','url','notes','source'] o null si no se pudo consultar.
     */
    public static function get_remote($force = false) {
        if (!$force) {
            $cached = get_site_transient(self::CACHE_KEY);
            if (is_array($cached)) {
                return empty($cached['version']) ? null : $cached;
            }
        }

        $info = self::fetch_release();
        if (!$info) {
            $info = self::fetch_branch_header();
        }

        set_site_transient(self::CACHE_KEY, $info ?: ['version' => ''], $info ? self::CACHE_TTL : self::ERROR_TTL);

        return $info;
    }

    private static function request($url, array $headers = []) {
        $response = wp_remote_get($url, [
            'timeout' => 8,
            'headers' => array_merge(['User-Agent' => 'SiteUptimeWatcher/' . SUW_VERSION], $headers),
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        return wp_remote_retrieve_body($response);
    }

    private static function fetch_release() {
        $body = self::request(
            'https://api.github.com/repos/' . self::REPO . '/releases/latest',
            ['Accept' => 'application/vnd.github+json']
        );
        if (!$body) {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['tag_name'])) {
            return null;
        }

        $version = ltrim((string) $data['tag_name'], 'vV');
        if (!preg_match('/^\d+(\.\d+){1,3}/', $version)) {
            return null;
        }

        $package = !empty($data['zipball_url']) ? $data['zipball_url'] : '';
        foreach ((array) ($data['assets'] ?? []) as $asset) {
            $name = (string) ($asset['name'] ?? '');
            if (!empty($asset['browser_download_url']) && substr($name, -4) === '.zip' && stripos($name, 'site-uptime-watcher') === 0) {
                $package = $asset['browser_download_url'];
                break;
            }
        }

        if ($package === '') {
            return null;
        }

        return [
            'version' => $version,
            'package' => $package,
            'url'     => !empty($data['html_url']) ? $data['html_url'] : self::repo_url(),
            'notes'   => (string) ($data['body'] ?? ''),
            'source'  => 'release',
        ];
    }

    private static function fetch_branch_header() {
        $body = self::request('https://raw.githubusercontent.com/' . self::REPO . '/HEAD/site-uptime-watcher.php');
        if (!$body || !preg_match('/^[ \t\/*#@]*Version:\s*([0-9][0-9.]*)/mi', $body, $m)) {
            return null;
        }

        return [
            'version' => $m[1],
            'package' => self::repo_url() . '/archive/HEAD.zip',
            'url'     => self::repo_url(),
            'notes'   => '',
            'source'  => 'branch',
        ];
    }

    /**
     * Devuelve la info remota solo si hay una versión más nueva que la instalada.
     */
    public static function available() {
        $remote = self::get_remote();
        return ($remote && version_compare($remote['version'], SUW_VERSION, '>')) ? $remote : null;
    }

    /**
     * Fuerza una consulta nueva a GitHub y refresca el sistema de updates de WordPress.
     */
    public static function force_check() {
        delete_site_transient(self::CACHE_KEY);
        delete_site_transient('update_plugins');

        if (!function_exists('wp_update_plugins')) {
            require_once ABSPATH . 'wp-includes/update.php';
        }
        wp_update_plugins();

        return self::get_remote();
    }

    /* ------------------------------------------------------------------
     * URLs de acción
     * ------------------------------------------------------------------ */

    public static function check_url() {
        return wp_nonce_url(admin_url('admin-post.php?action=suw_check_update'), 'suw_check_update');
    }

    public static function update_url() {
        $basename = self::basename();
        return wp_nonce_url(
            self_admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode($basename)),
            'upgrade-plugin_' . $basename
        );
    }

    /* ------------------------------------------------------------------
     * Integración con WordPress
     * ------------------------------------------------------------------ */

    private static function update_item($version, $package = '') {
        return (object) [
            'id'            => 'github.com/' . self::REPO,
            'slug'          => self::slug(),
            'plugin'        => self::basename(),
            'new_version'   => $version,
            'url'           => self::repo_url(),
            'package'       => $package,
            'requires'      => '5.8',
            'requires_php'  => '7.4',
        ];
    }

    public static function inject_update($transient) {
        if (!is_object($transient)) {
            return $transient;
        }

        $basename = self::basename();
        $remote   = self::get_remote();

        if ($remote && version_compare($remote['version'], SUW_VERSION, '>')) {
            $transient->response[$basename] = self::update_item($remote['version'], $remote['package']);
            if (isset($transient->no_update[$basename])) {
                unset($transient->no_update[$basename]);
            }
        } else {
            // Entrada en no_update: habilita el interruptor de actualizaciones automáticas.
            $transient->no_update[$basename] = self::update_item(SUW_VERSION);
            if (isset($transient->response[$basename])) {
                unset($transient->response[$basename]);
            }
        }

        return $transient;
    }

    public static function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== self::slug()) {
            return $result;
        }

        $remote  = self::get_remote();
        $version = $remote ? $remote['version'] : SUW_VERSION;
        $notes   = $remote && $remote['notes'] !== '' ? nl2br(esc_html($remote['notes'])) : 'Consulta el historial de cambios en GitHub.';

        return (object) [
            'name'          => 'Site Uptime Watcher',
            'slug'          => self::slug(),
            'version'       => $version,
            'author'        => '<a href="https://github.com/Albert99">Albert</a>',
            'homepage'      => self::repo_url(),
            'requires'      => '5.8',
            'requires_php'  => '7.4',
            'download_link' => $remote ? $remote['package'] : '',
            'sections'      => [
                'description' => 'Monitor de uptime para WordPress con prevención de falsos positivos y detección de errores críticos (PHP, MySQL, WSOD, mantenimiento).',
                'changelog'   => $notes,
            ],
        ];
    }

    /**
     * Los ZIP de GitHub traen una carpeta tipo "Albert99-site-uptime-watcher-abc123".
     * La renombramos a la carpeta real del plugin para que WordPress lo reemplace
     * en su sitio y no lo instale como un plugin nuevo.
     */
    public static function fix_source_dir($source, $remote_source, $upgrader, $hook_extra) {
        global $wp_filesystem;

        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== self::basename()) {
            return $source;
        }

        if (!$wp_filesystem || !$wp_filesystem->exists(trailingslashit($source) . 'site-uptime-watcher.php')) {
            return $source;
        }

        $target = trailingslashit($remote_source) . self::slug() . '/';
        if (untrailingslashit($source) === untrailingslashit($target)) {
            return $source;
        }

        if ($wp_filesystem->move($source, $target, true)) {
            return $target;
        }

        return new WP_Error('suw_rename_failed', 'No se pudo preparar la carpeta del plugin para actualizar.');
    }

    public static function clear_cache($upgrader, $options) {
        if (($options['type'] ?? '') !== 'plugin' || ($options['action'] ?? '') !== 'update') {
            return;
        }

        $updated = (array) ($options['plugins'] ?? []);
        if (!empty($options['plugin'])) {
            $updated[] = $options['plugin'];
        }

        if (in_array(self::basename(), $updated, true)) {
            delete_site_transient(self::CACHE_KEY);
        }
    }

    /* ------------------------------------------------------------------
     * Accesos visibles en la lista de Plugins
     * ------------------------------------------------------------------ */

    public static function row_meta($links, $file) {
        if ($file !== self::basename()) {
            return $links;
        }

        $links[] = '<a href="' . esc_url(self::check_url()) . '">Buscar actualizaciones</a>';

        return $links;
    }

    public static function action_links($links) {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=suw-settings')) . '">Abrir</a>');

        if (self::available()) {
            array_unshift($links, '<a href="' . esc_url(self::update_url()) . '" style="font-weight:600;color:#1a73e8;">Actualizar ahora</a>');
        }

        return $links;
    }
}
