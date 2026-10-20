<?php
/**
 * Plugin Name: Site Uptime Watcher
 * Plugin URI: https://github.com/Albert99/site-uptime-watcher
 * Description: Monitor de uptime profesional para WordPress con prevención de falsos positivos, detección de errores críticos (PHP/MySQL) e importador masivo de URLs.
 * Version: 1.4.0
 * Author: Albert
 * Author URI: https://github.com/Albert99
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SUW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SUW_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SUW_VERSION', '1.4.0');

require_once SUW_PLUGIN_DIR . 'includes/class-site-uptime-watcher.php';

register_activation_hook(__FILE__, ['Site_Uptime_Watcher', 'activate_cron']);
register_deactivation_hook(__FILE__, ['Site_Uptime_Watcher', 'deactivate_cron']);

add_action('plugins_loaded', function () {
    new Site_Uptime_Watcher();
});
