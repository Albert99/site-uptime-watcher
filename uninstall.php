<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

wp_clear_scheduled_hook('suw_check_sites_event');
wp_clear_scheduled_hook('suw_check_queue_event');

delete_option('suw_options');
delete_option('suw_sites_state');
delete_option('suw_incidents');
delete_transient('suw_check_lock');
delete_site_transient('suw_remote_release');

global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_suw\_notice\_%'
        OR option_name LIKE '\_transient\_timeout\_suw\_notice\_%'"
);
