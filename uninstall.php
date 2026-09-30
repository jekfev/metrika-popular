<?php
// Удаление плагина: чистим настройки, данные и кэш блоков.
defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('mpop_options');
delete_option('mpop_data');
delete_option('mpop_v');
wp_clear_scheduled_hook('mpop_refresh');
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_mpop\_%' OR option_name LIKE '\_transient\_timeout\_mpop\_%'");
