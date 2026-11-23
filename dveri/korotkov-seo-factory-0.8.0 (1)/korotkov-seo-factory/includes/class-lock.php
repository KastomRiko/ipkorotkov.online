<?php
/**
 * MySQL advisory locks shared by all PHP workers connected to the same MySQL server.
 */
if (!defined('ABSPATH')) { exit; }

final class KSEF_Lock {
    private static function name($key) {
        global $wpdb;
        // MySQL GET_LOCK() limits lock names to 64 characters.
        return 'ksef:' . substr(hash('sha256', DB_NAME . ':' . $wpdb->prefix . ':' . sanitize_key($key)), 0, 58);
    }

    public static function acquire($key, $timeout = 0) {
        global $wpdb;
        $name = self::name($key);
        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, max(0, (int) $timeout)));
        if ((string) $result !== '1') {
            return new WP_Error('ksef_lock_unavailable', 'Не удалось получить атомарную блокировку: ' . sanitize_key($key) . '.');
        }
        return $name;
    }

    public static function release($name) {
        global $wpdb;
        if (!is_string($name) || $name === '') return false;
        $result = $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        if ((string) $result !== '1') {
            if (class_exists('Korotkov_SEO_Factory')) {
                Korotkov_SEO_Factory::write_log('error', 'mysql_lock_release_failed', 'Не удалось штатно освободить MySQL-блокировку.');
            }
            return false;
        }
        return true;
    }
}
