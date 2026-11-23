<?php
/**
 * Conservative synchronous web-search client for Yandex Search API.
 * No requests are sent on plugin load or activation.
 */
if (!defined('ABSPATH')) {
    exit;
}

final class KSEF_Search_Client {
    const ENDPOINT = 'https://searchapi.api.cloud.yandex.net/v2/web/search';
    const DAILY_LIMIT = 100;
    const MIN_INTERVAL = 2;
    const CACHE_TTL = 7 * DAY_IN_SECONDS;

    public function search($query_text, $page = 0) {
        $query_text = trim(sanitize_text_field($query_text));
        $page = absint($page);

        if ($query_text === '' || mb_strlen($query_text) > 400) {
            return new WP_Error('ksef_invalid_search_query', 'Поисковая фраза пуста или слишком длинная.');
        }
        if ($page > 2) {
            return new WP_Error('ksef_page_limit', 'Внутреннее ограничение: доступны только первые три страницы выдачи.');
        }

        $settings = get_option(KSEF_OPTION, Korotkov_SEO_Factory::default_settings());
        $api_key = defined('KSEF_YANDEX_API_KEY') ? KSEF_YANDEX_API_KEY : '';
        $folder_id = defined('KSEF_YANDEX_FOLDER_ID') ? KSEF_YANDEX_FOLDER_ID : (isset($settings['folder_id']) ? $settings['folder_id'] : '');

        if (!$api_key || !$folder_id) {
            return new WP_Error('ksef_missing_credentials', 'Не заданы API-ключ и Folder ID.');
        }

        $cache_key = hash('sha256', 'web-search:ru:225:xml:' . $page . ':' . mb_strtolower($query_text));
        $cached = $this->get_cache($cache_key);
        if ($cached !== null) {
            Korotkov_SEO_Factory::write_log('info', 'search_cache_hit', 'Search API: использован кэшированный ответ.');
            return $cached;
        }

        $reservation = $this->reserve_search_slot();
        if (is_wp_error($reservation)) {
            return $reservation;
        }

        $payload = array(
            'query' => array(
                'searchType' => 'SEARCH_TYPE_RU',
                'queryText' => $query_text,
                'familyMode' => 'FAMILY_MODE_STRICT',
                'page' => (string) $page,
                'fixTypoMode' => 'FIX_TYPO_MODE_OFF',
            ),
            'groupSpec' => array('groupsOnPage' => '10'),
            'region' => '225',
            'l10n' => 'LOCALIZATION_RU',
            'folderId' => $folder_id,
            'responseFormat' => 'FORMAT_XML',
        );

        $response = wp_remote_post(self::ENDPOINT, array(
            'timeout' => 30,
            'redirection' => 0,
            'headers' => array(
                'Authorization' => 'Api-Key ' . $api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'body' => wp_json_encode($payload),
            'data_format' => 'body',
        ));

        if (is_wp_error($response)) {
            $this->release_search_slot();
            Korotkov_SEO_Factory::write_log('error', 'search_transport_error', 'Ошибка соединения с Yandex Search API.');
            return new WP_Error('ksef_search_transport', 'Не удалось связаться с Yandex Search API.');
        }

        delete_transient('ksef_search_request_lock');
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($status === 429 || $status === 503) {
            set_transient('ksef_api_circuit_open', 1, $status === 429 ? HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS);
            Korotkov_SEO_Factory::write_log('warning', 'search_circuit_open', 'Search API приостановлен после HTTP ' . $status . '.');
            return new WP_Error('ksef_search_throttled', 'Яндекс ограничил запросы. Очередь остановлена.');
        }
        if ($status < 200 || $status >= 300) {
            Korotkov_SEO_Factory::write_log('error', 'search_http_error', 'Search API вернул HTTP ' . $status . '.');
            return new WP_Error('ksef_search_http_' . $status, 'Ошибка Search API: HTTP ' . $status . '.');
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || empty($decoded['rawData'])) {
            Korotkov_SEO_Factory::write_log('error', 'search_empty_response', 'Search API не вернул поле rawData.');
            return new WP_Error('ksef_search_empty', 'В ответе Search API отсутствуют результаты.');
        }

        $raw = base64_decode($decoded['rawData'], true);
        if ($raw === false) {
            return new WP_Error('ksef_search_decode', 'Не удалось декодировать результаты Search API.');
        }

        $result = array(
            'query' => $query_text,
            'page' => $page,
            'region_id' => 225,
            'format' => 'xml',
            'raw_data' => $raw,
            'request_id' => sanitize_text_field(wp_remote_retrieve_header($response, 'x-request-id')),
            'retrieved_at' => current_time('mysql', true),
        );

        $this->set_cache($cache_key, $result, self::CACHE_TTL);
        $this->record_search_usage();
        Korotkov_SEO_Factory::write_log('info', 'search_request_ok', 'Успешно получена выдача Яндекса.', array('page' => $page));
        return $result;
    }

    private function reserve_search_slot() {
        if (get_transient('ksef_api_circuit_open')) {
            return new WP_Error('ksef_search_circuit_open', 'Все запросы к Яндексу временно остановлены.');
        }
        if (get_transient('ksef_search_request_lock')) {
            return new WP_Error('ksef_search_busy', 'Предыдущий поисковый запрос ещё выполняется.');
        }

        $day_key = 'ksef_search_usage_' . gmdate('Ymd');
        $used = absint(get_option($day_key, 0));
        if ($used >= self::DAILY_LIMIT) {
            return new WP_Error('ksef_search_daily_limit', 'Достигнут внутренний лимит Search API — 100 запросов в сутки.');
        }

        $last = absint(get_option('ksef_search_last_request', 0));
        if (time() - $last < self::MIN_INTERVAL) {
            return new WP_Error('ksef_search_rate_limit', 'Соблюдается двухсекундный интервал между запросами.');
        }

        set_transient('ksef_search_request_lock', 1, 60);
        update_option('ksef_search_last_request', time(), false);
        update_option($day_key, $used + 1, false);
        return true;
    }

    private function release_search_slot() {
        delete_transient('ksef_search_request_lock');
        $day_key = 'ksef_search_usage_' . gmdate('Ymd');
        update_option($day_key, max(0, absint(get_option($day_key, 0)) - 1), false);
    }

    private function record_search_usage() {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'ksef_api_usage', array(
            'service' => 'search',
            'method' => 'WebSearch',
            'request_count' => 1,
            // Intentionally zero until the exact billing mode and bonus coverage are verified.
            'estimated_cost' => 0,
            'created_at' => current_time('mysql', true),
        ), array('%s', '%s', '%d', '%f', '%s'));
    }

    private function get_cache($key) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT response_json, expires_at FROM ' . $wpdb->prefix . 'ksef_cache WHERE cache_key = %s LIMIT 1',
            $key
        ), ARRAY_A);
        if (!$row) return null;
        if (strtotime($row['expires_at'] . ' UTC') <= time()) {
            $wpdb->delete($wpdb->prefix . 'ksef_cache', array('cache_key' => $key), array('%s'));
            return null;
        }
        $data = json_decode($row['response_json'], true);
        return is_array($data) ? $data : null;
    }

    private function set_cache($key, $data, $ttl) {
        global $wpdb;
        $now = time();
        $wpdb->replace($wpdb->prefix . 'ksef_cache', array(
            'cache_key' => $key,
            'response_json' => wp_json_encode($data),
            'created_at' => gmdate('Y-m-d H:i:s', $now),
            'expires_at' => gmdate('Y-m-d H:i:s', $now + $ttl),
        ), array('%s', '%s', '%s', '%s'));
    }
}
