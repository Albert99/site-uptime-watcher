<?php
/**
 * Plugin Name: Site Uptime Watcher
 * Description: Monitoriza webs externas desde este WordPress y envía alertas por email tras confirmación de caída (anti falsos positivos).
 * Version: 1.1.1
 * Author: Albert
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SUW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SUW_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SUW_VERSION', '1.1.1');

require_once SUW_PLUGIN_DIR . 'includes/class-site-uptime-watcher.php';

// Activación y desactivación
register_activation_hook(__FILE__, ['Site_Uptime_Watcher', 'activate_cron']);
register_deactivation_hook(__FILE__, ['Site_Uptime_Watcher', 'deactivate_cron']);

// Inicialización segura
add_action('plugins_loaded', function () {
    new Site_Uptime_Watcher();
});