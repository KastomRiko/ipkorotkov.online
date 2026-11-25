<?php
/**
 * Yandex Wordstat API (Yandex Search API v2) transport for Korotkov SEO Factory.
 * No request is sent merely by loading or activating this file.
 *
 * Эндпоинты сверены с документацией Yandex Search API:
 *   POST /v2/wordstat/topRequests    — GetTop
 *   POST /v2/wordstat/dynamics       — GetDynamics
 *   POST /v2/wordstat/regions        — GetRegionsDistribution
 *   POST /v2/wordstat/getRegionsTree — GetRegionsTree
 * folderId передаётся полем тела запроса (не заголовком).
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
     * GetTop. Параметры: phrase (обяз., до 400 симв.), numPhrases (1..2000), regions[], devices[].
     */
    public function wordstat_top($payload) {
        return $this->request_wordstat('/v2/wordstat/topRequests', $payload, 'GetTop', 0.02);
    }

    /**
     * GetDynamics. Параметры: phrase, period (PERIOD_MONTHLY|WEEKLY|DAILY), fromDate (RFC 3339), toDate, regions[], devices[].
     */
    public function wordstat_dynamics($payload) {
        return $this->request_wordstat('/v2/wordstat/dynamics', $payload, 'GetDynamics', 20.00);
    }

    /**
     * GetRegionsDistribution. Параметры: phrase, region (REGION_ALL|REGION_CITIES|REGION_REGIONS), devices[].
     */
    public function wordstat_regions($payload) {
        return $this->request_wordstat('/v2/wordstat/regions', $payload, 'GetRegionsDistribution', 50.00);
    }

    public function wordstat_regions_tree($payload = array()) {
        return $this->request_wordstat('/v2/wordstat/getRegionsTree', $payload, 'GetRegionsTree', 0.0);
    }

    private function request_wordstat($path, $payload, $method, $estimated_cost_per_call) {
        if (!is_array($payload)) {
            return new WP_Error('ksef_invalid_payload', 'Ожидался массив параметров запроса.');
        }
        if (isset($payload['phrase'])) {
            $payload['phrase'] = trim((string) $payload['phrase']);
            $len = function_exists('mb_strlen') ? mb_strlen($payload['phrase']) : strlen($payload['phrase']);
            if ($payload['phrase'] === '' || $len > 400) {
                return new WP_Error('ksef_invalid_phrase', 'Фраза Wordstat пуста или длиннее 400 символов.');
            }
        }

        $key = defined('KSEF_YANDEX_API_KEY') ? KSEF_YANDEX_API_KEY : '';
        $folder_id = defined('KSEF_YANDEX_FOLDER_ID') ? KSEF_YANDEX_FOLDER_ID : (isset($this->settings['folder_id']) ? $this->settings['folder_id'] : '');

        if (!$key) {
            return new WP_Error('ksef_missing_credentials', 'Не задан KSEF_YANDEX_API_KEY.');
        }

        // Кэш-ключ не зависит от folderId, чтобы смена каталога не обнуляла кэш.
        $cache_key = hash('sha256', 'wordstat:' . $method . ':' . wp_json_encode($payload));
        $cached = $this->get_cache($cache_key);
        if ($cached !== null) {
            Korotkov_SEO_Factory::write_log('info', 'api_cache_hit', 'Wordstat: использован кэшированный ответ.', array('method' => $method));
            return $cached;
        }

        $limit = isset($this->settings['daily_wordstat_limit']) ? absint($this->settings['daily_wordstat_limit']) : 20;
        $interval = isset($this->settings['wordstat_interval']) ? max(5, absint($this->settings['wordstat_interval'])) : 5;

        $reservation = $this->reserve_request('wordstat', $limit, $interval, $estimated_cost_per_call);
        if (is_wp_error($reservation)) {
            return $reservation;
        }

        try {
        // folderId — поле тела запроса; для сервисного аккаунта необязателен.
        $body_payload = $payload;
        if ($folder_id) {
            $body_payload['folderId'] = (string) $folder_id;
        }

        // Резервируем оценку до сетевого вызова: запрос мог тарифицироваться даже при тайм-ауте.
        if (!$this->record_usage($method, $estimated_cost_per_call)) {
            return new WP_Error('ksef_billing_reserve_failed', 'Не удалось сохранить резерв стоимости запроса. Внешний запрос не отправлен.');
        }
        $response = wp_remote_post(self::BASE_URL . $path, array(
            'timeout' => 25,
            'redirection' => 0,
            'headers' => array(
                'Authorization' => 'Api-Key ' . $key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'body' => wp_json_encode($body_payload),
            'data_format' => 'body',
        ));

        if (is_wp_error($response)) {
            Korotkov_SEO_Factory::write_log('error', 'api_transport_error', 'Ошибка соединения с Yandex Wordstat API.', array('method' => $method, 'error' => $response->get_error_code()));
            return new WP_Error('ksef_api_transport', 'Не удалось связаться с Yandex API.');
        }

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

        $this->set_cache($cache_key, $decoded, self::CACHE_TTL);
        Korotkov_SEO_Factory::write_log('info', 'api_request_ok', 'Успешный запрос Wordstat API.', array('method' => $method, 'http_status' => $status));

        return $decoded;
        } finally {
            KSEF_Lock::release($reservation);
        }
    }

    private function reserve_request($service, $daily_limit, $interval, $next_cost = 0.0) {
        if ($this->is_circuit_open()) {
            return new WP_Error('ksef_circuit_open', 'Очередь API временно остановлена после ограничения или ошибки.');
        }
        if ($daily_limit === 0) {
            return new WP_Error('ksef_daily_limit_disabled', 'Запросы Wordstat отключены дневным лимитом 0.');
        }

        $lock = KSEF_Lock::acquire('paid_api_budget', 0);
        if (is_wp_error($lock)) {
            return new WP_Error('ksef_request_busy', 'Другой запрос Wordstat уже выполняется. Повторите позже.');
        }

        $budget = KSEF_Util::check_budget($next_cost);
        if (is_wp_error($budget)) {
            KSEF_Lock::release($lock);
            return $budget;
        }

        $usage = $this->get_daily_usage($service);
        if ($usage >= $daily_limit) {
            KSEF_Lock::release($lock);
            return new WP_Error('ksef_daily_limit', 'Достигнут внутренний дневной лимит Wordstat.');
        }

        $last = (int) get_option('ksef_' . $service . '_last_request', 0);
        $wait = $interval - (time() - $last);
        if ($wait > 0) {
            KSEF_Lock::release($lock);
            return new WP_Error('ksef_rate_limited', 'Соблюдается пауза между запросами. Повторите позже.');
        }

        update_option('ksef_' . $service . '_last_request', time(), false);
        $this->increment_daily_usage($service);
        return $lock;
    }

    private function get_daily_usage($service) {
        $key = 'ksef_' . $service . '_usage_' . gmdate('Ymd');
        return absint(get_option($key, 0));
    }

    private function increment_daily_usage($service) {
        $key = 'ksef_' . $service . '_usage_' . gmdate('Ymd');
        update_option($key, $this->get_daily_usage($service) + 1, false);
    }

    private function record_usage($method, $estimated_cost) {
        global $wpdb;
        $table = $wpdb->prefix . 'ksef_api_usage';
        return $wpdb->insert($table, array(
            'service' => 'wordstat',
            'method' => sanitize_key($method),
            'request_count' => 1,
            'estimated_cost' => (float) $estimated_cost,
            'created_at' => current_time('mysql', true),
        ), array('%s', '%s', '%d', '%f', '%s')) !== false;
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
