<?php
/**
 * Удаление плагина. Данные (таблицы ksef_*, страницы WordPress) НЕ удаляются,
 * если в wp-config.php не задано define('KSEF_REMOVE_DATA_ON_UNINSTALL', true).
 * Созданные плагином страницы WordPress в любом случае остаются нетронутыми.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

wp_clear_scheduled_hook('ksef_daily_tick');
wp_clear_scheduled_hook('ksef_queue_tick');

if (defined('KSEF_REMOVE_DATA_ON_UNINSTALL') && KSEF_REMOVE_DATA_ON_UNINSTALL === true) {
    global $wpdb;
    foreach (array('jobs','keywords','clusters','serp_results','pages','qwen_imports','api_usage','cache','logs') as $t) {
        $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ksef_' . $t);
    }
    delete_option('ksef_settings');
    delete_option('ksef_db_version');
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ksef\\_%'");
}
