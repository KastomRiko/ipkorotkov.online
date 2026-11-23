<?php
/**
 * Yandex API transport for Korotkov SEO Factory.
 * No request is sent merely by loading or activating this file.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class KSEF_Yandex_Client {
    const BASE_URL = 'https://searchapi.api.cloud.yandex.net';
    const CACHE_TTL = 30 * DAY_IN_SECONDS;

    private $settings;

    public function __construct() {
        $this->settings = get_option(KSEF_OPTION, Korotkov_SEO_Factory::default_settings());
    }

    /**
     * Wordstat methods are synchronous REST calls.
     * Payloads are deliberately passed by the caller to keep method-specific
     * request schemas explicit and avoid guessing missing fields.
     */
    public function wordstat_top($payload) {
        return $this->request_wordstat('/v2/wordstat/top', $payload, 'GetTop', 0.02);
    }

    public function wordstat_dynamics($payload) {
        return $this->request_wordstat('/v2/wordstat/dynamics', $payload, 'GetDynamics', 0.02);
    }

    public function wordstat_regions($payload) {
        return $this->request_wordstat('/v2/wordstat/regions', $payload, 'GetRegionsDistribution', 0.05);
    }

    public function wordstat_regions_tree($payload = array()) {
        return $this->request_wordstat('/v2/wordstat/regions/tree', $payload, 'GetRegionsTree', 0.0);
    }

    private function request_wordstat($path, $payload, $method, $estimated_cost_per_call) {
        if (!is_array($payload)) {
            return new WP_Error('ksef_invalid_payload', 'Ожидался массив параметров запроса.');
        }

        $key = defined('KSEF_YANDEX_API_KEY') ? KSEF_YANDEX_API_KEY : '';
        $folder_id = defined('KSEF_YANDEX_FOLDER_ID') ? KSEF_YANDEX_FOLDER_ID : (isset($this->settings['folder_id']) ? $this->settings['folder_id'] : '');

        if (!$key || !$folder_id) {
            return new WP_Error('ksef_missing_credentials', 'Не заданы KSEF_YANDEX_API_KEY и/или Folder ID.');
        }

        $cache_key = 'wordstat:' . $method . ':' . hash('sha256', wp_json_encode($payload));
        $cached = $this->get_cache($cache_key);
        if ($cached !== null) {
            Korotkov_SEO_Factory::write_log('info', 'api_cache_hit', 'Wordstat: использован кэшированный ответ.', array('method' => $method));
            return $cached;
        }

        $limit = isset($this->settings['daily_wordstat_limit']) ? absint($this->settings['daily_wordstat_limit']) : 20;
        $interval = isset($this->settings['wordstat_interval']) ? max(5, absint($this->settings['wordstat_interval'])) : 5;

        $reservation = $this->reserve_request('wordstat', $limit, $interval);
        if (is_wp_error($reservation)) {
            return $reservation;
        }

        $response = wp_remote_post(self::BASE_URL . $path, array(
            'timeout' => 25,
            'redirection' => 0,
            'headers' => array(
                'Authorization' => 'Api-Key ' . $key,
                'x-folder-id' => $folder_id,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'body' => wp_json_encode($payload),
            'data_format' => 'body',
        ));

        if (is_wp_error($response)) {
            $this->release_failed_reservation('wordstat');
            Korotkov_SEO_Factory::write_log('error', 'api_transport_error', 'Ошибка соединения с Yandex Wordstat API.', array('method' => $method, 'error' => $response->get_error_code()));
            return new WP_Error('ksef_api_transport', 'Не удалось связаться с Yandex API.');
        }

        delete_transient('ksef_wordstat_request_lock');
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($status === 429 || $status === 503) {
            $this->set_circuit_breaker($status === 429 ? 3600 : 900);
            Korotkov_SEO_Factory::write_log('warning', 'api_circuit_open', 'Очередь Wordstat остановлена после ответа ' . $status . '.', array('method' => $method));
            return new WP_Error('ksef_api_throttled', 'Яндекс временно ограничил запросы. Очередь остановлена.');
        }

        if ($status < 200 || $status >= 300) {
            Korotkov_SEO_Factory::write_log('error', 'api_http_error', 'Yandex Wordstat API вернул HTTP ' . $status . '.', array('method' => $method));
            return new WP_Error('ksef_api_http_' . $status, 'Ошибка ответа Yandex API: HTTP ' . $status . '.');
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            Korotkov_SEO_Factory::write_log('error', 'api_invalid_json', 'Yandex Wordstat API вернул некорректный JSON.', array('method' => $method));
            return new WP_Error('ksef_api_invalid_json', 'Получен некорректный JSON от Yandex API.');
        }

        $this->record_usage($method, $estimated_cost_per_call);
        $this->set_cache($cache_key, $decoded, self::CACHE_TTL);
        Korotkov_SEO_Factory::write_log('info', 'api_request_ok', 'Успешный запрос Wordstat API.', array('method' => $method, 'http_status' => $status));

        return $decoded;
    }

    private function reserve_request($service, $daily_limit, $interval) {
        if ($this->is_circuit_open()) {
            return new WP_Error('ksef_circuit_open', 'Очередь API временно остановлена после ограничения или ошибки.');
        }

        $lock_name = 'ksef_' . $service . '_request_lock';
        if (get_transient($lock_name)) {
            return new WP_Error('ksef_request_busy', 'Предыдущий запрос ещё выполняется. Повторите позже.');
        }

        $usage = $this->get_daily_usage($service);
        if ($daily_limit > 0 && $usage >= $daily_limit) {
            return new WP_Error('ksef_daily_limit', 'Достигнут внутренний дневной лимит Wordstat.');
        }
        if ($daily_limit === 0) {
            return new WP_Error('ksef_daily_limit_disabled', 'Запросы Wordstat отключены дневным лимитом 0.');
        }

        $last = (int) get_option('ksef_' . $service . '_last_request', 0);
        $wait = $interval - (time() - $last);
        if ($wait > 0) {
            return new WP_Error('ksef_rate_limited', 'Соблюдается пауза между запросами. Повторите позже.');
        }

        set_transient($lock_name, 1, 60);
        update_option('ksef_' . $service . '_last_request', time(), false);
        $this->increment_daily_usage($service);

        return true;
    }

    private function release_failed_reservation($service) {
        $this->decrement_daily_usage($service);
        delete_transient('ksef_' . $service . '_request_lock');
    }

    private function get_daily_usage($service) {
        $key = 'ksef_' . $service . '_usage_' . gmdate('Ymd');
        return absint(get_option($key, 0));
    }

    private function increment_daily_usage($service) {
        $key = 'ksef_' . $service . '_usage_' . gmdate('Ymd');
        update_option($key, $this->get_daily_usage($service) + 1, false);
    }

    private function decrement_daily_usage($service) {
        $key = 'ksef_' . $service . '_usage_' . gmdate('Ymd');
        update_option($key, max(0, $this->get_daily_usage($service) - 1), false);
    }

    private function record_usage($method, $estimated_cost) {
        global $wpdb;
        $table = $wpdb->prefix . 'ksef_api_usage';
        $wpdb->insert($table, array(
            'service' => 'wordstat',
            'method' => sanitize_key($method),
            'request_count' => 1,
            'estimated_cost' => (float) $estimated_cost,
            'created_at' => current_time('mysql', true),
        ), array('%s', '%s', '%d', '%f', '%s'));
    }

    private function get_cache($cache_key) {
        global $wpdb;
        $table = $wpdb->prefix . 'ksef_cache';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT response_json, expires_at FROM {$table} WHERE cache_key = %s LIMIT 1",
            $cache_key
        ), ARRAY_A);

        if (!$row) {
            return null;
        }
        if (strtotime($row['expires_at'] . ' UTC') <= time()) {
            $wpdb->delete($table, array('cache_key' => $cache_key), array('%s'));
            return null;
        }

        $decoded = json_decode($row['response_json'], true);
        return is_array($decoded) ? $decoded : null;
    }

    private function set_cache($cache_key, $data, $ttl) {
        global $wpdb;
        $table = $wpdb->prefix . 'ksef_cache';
        $now = time();
        $wpdb->replace($table, array(
            'cache_key' => $cache_key,
            'response_json' => wp_json_encode($data),
            'created_at' => gmdate('Y-m-d H:i:s', $now),
            'expires_at' => gmdate('Y-m-d H:i:s', $now + absint($ttl)),
        ), array('%s', '%s', '%s', '%s'));
    }

    private function set_circuit_breaker($seconds) {
        set_transient('ksef_api_circuit_open', 1, absint($seconds));
    }

    private function is_circuit_open() {
        return (bool) get_transient('ksef_api_circuit_open');
    }
}
