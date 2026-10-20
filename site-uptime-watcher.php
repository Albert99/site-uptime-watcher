<?php
/**
 * Plugin Name: Site Uptime Watcher
 * Plugin URI: https://github.com/Albert99/site-uptime-watcher
 * Description: Monitor de uptime profesional para WordPress con prevención de falsos positivos, detección de errores críticos (PHP/MySQL) e importador masivo de URLs.
 * Version: 1.9.1
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Albert
 * Author URI: https://github.com/Albert99
 * License: GPL v2 or later
 * Text Domain: site-uptime-watcher
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SUW_PLUGIN_FILE', __FILE__);
define('SUW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SUW_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SUW_VERSION', '1.9.1');

require_once SUW_PLUGIN_DIR . 'includes/class-site-uptime-watcher.php';
require_once SUW_PLUGIN_DIR . 'includes/class-suw-updater.php';

register_activation_hook(__FILE__, ['Site_Uptime_Watcher', 'activate_cron']);
register_deactivation_hook(__FILE__, ['Site_Uptime_Watcher', 'deactivate_cron']);

add_action('plugins_loaded', function () {
    Site_Uptime_Updater::init();
    new Site_Uptime_Watcher();
});
