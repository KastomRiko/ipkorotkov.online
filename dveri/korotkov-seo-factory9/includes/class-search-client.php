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

        if ($query_text === '' || (function_exists('mb_strlen') ? mb_strlen($query_text) : strlen($query_text)) > 400) {
            return new WP_Error('ksef_invalid_search_query', 'Поисковая фраза пуста или слишком длинная.');
        }
        if ($page > 2) {
            return new WP_Error('ksef_page_limit', 'Внутреннее ограничение: доступны только первые три страницы выдачи.');
        }

        $settings = get_option(KSEF_OPTION, Korotkov_SEO_Factory::default_settings());
        $api_key = defined('KSEF_YANDEX_API_KEY') ? KSEF_YANDEX_API_KEY : '';
        $folder_id = defined('KSEF_YANDEX_FOLDER_ID') ? KSEF_YANDEX_FOLDER_ID : (isset($settings['folder_id']) ? $settings['folder_id'] : '');

        if (!$api_key) {
            return new WP_Error('ksef_missing_credentials', 'Не задан KSEF_YANDEX_API_KEY.');
        }

        $cache_key = $this->cache_key($query_text, $page);
        $cached = $this->get_cache($cache_key);
        if ($cached !== null) {
            Korotkov_SEO_Factory::write_log('info', 'search_cache_hit', 'Search API: использован кэшированный ответ.');
            return $cached;
        }

        if (!defined('KSEF_BILLING_VERIFIED') || KSEF_BILLING_VERIFIED !== true) {
            return new WP_Error('ksef_billing_not_verified', 'Платные запросы заблокированы до проверки покрытия бонусами и тарифа Yandex Cloud.');
        }

        $budget = KSEF_Util::check_budget();
        if (is_wp_error($budget)) {
            return $budget;
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
            'responseFormat' => 'FORMAT_XML',
        );
        if ($folder_id) {
            $payload['folderId'] = (string) $folder_id;
        }

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

        if (preg_match('/<(?:[a-z0-9_-]+:)?error\b[^>]*>/i', $raw)) {
            Korotkov_SEO_Factory::write_log('error', 'search_xml_error_node', 'Яндекс вернул в XML узел error; результат не кэшируется.');
            return new WP_Error('ksef_search_xml_error', 'Яндекс вернул ошибку в теле XML-ответа.');
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

    /**
     * Parse XML safely and persist organic result documents for a known keyword.
     * Returns the number of rows stored.
     */
    public function search_and_store($keyword_id, $query_text, $page = 0) {
        global $wpdb;
        $keyword_id = absint($keyword_id);
        if (!$keyword_id) {
            return new WP_Error('ksef_keyword_required', 'Для сохранения выдачи нужен ID поисковой фразы.');
        }

        $result = $this->search($query_text, $page);
        if (is_wp_error($result)) {
            return $result;
        }
        if (!class_exists('DOMDocument')) {
            return new WP_Error('ksef_xml_extension_missing', 'На сервере не включено расширение PHP DOM для разбора XML.');
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadXML($result['raw_data'], LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            $this->purge_cache($query_text, $page);
            Korotkov_SEO_Factory::write_log('error', 'search_xml_invalid', 'Не удалось разобрать XML поисковой выдачи.');
            return new WP_Error('ksef_search_xml_invalid', 'Яндекс вернул XML, который не удалось разобрать.');
        }

        $xpath = new DOMXPath($dom);
        $docs = $xpath->query('//*[local-name()="group"]/*[local-name()="doc"] | //*[local-name()="doc"]');
        if (!$docs) {
            return new WP_Error('ksef_search_no_documents', 'В XML не найдены документы поисковой выдачи.');
        }

        if (!$docs->length) {
            $this->purge_cache($query_text, $page);
            Korotkov_SEO_Factory::write_log('warning', 'search_zero_documents', 'В выдаче нет документов; результат не кэшируется.', array('keyword_id' => $keyword_id));
            return new WP_Error('ksef_search_no_documents', 'В XML не найдены документы поисковой выдачи.');
        }

        $table = $wpdb->prefix . 'ksef_serp_results';
        $stored = 0;
        $position = 0;
        foreach ($docs as $doc) {
            $url_node = $xpath->query('./*[local-name()="url"]', $doc)->item(0);
            $title_node = $xpath->query('./*[local-name()="title"]', $doc)->item(0);
            $passage_nodes = $xpath->query('.//*[local-name()="passage"]', $doc);

            $url = $url_node ? esc_url_raw(trim($url_node->textContent)) : '';
            if (!$url || !wp_http_validate_url($url)) {
                continue;
            }

            $title = $title_node ? sanitize_text_field(wp_strip_all_tags($title_node->textContent)) : '';
            $snippets = array();
            if ($passage_nodes) {
                foreach ($passage_nodes as $passage) {
                    $text = trim(preg_replace('/\\s+/u', ' ', wp_strip_all_tags($passage->textContent)));
                    if ($text !== '') $snippets[] = $text;
                }
            }
            $snippet = implode(' ', array_slice($snippets, 0, 4));
            $position++;

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE keyword_id = %d AND result_url = %s AND checked_at >= %s LIMIT 1",
                $keyword_id,
                $url,
                gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
            ));
            if ($exists) continue;

            $ok = $wpdb->insert($table, array(
                'keyword_id' => $keyword_id,
                'result_url' => $url,
                'result_title' => $title,
                'result_snippet' => $snippet,
                'position_num' => $position,
                'checked_at' => current_time('mysql', true),
            ), array('%d', '%s', '%s', '%s', '%d', '%s'));
            if ($ok) $stored++;
        }

        Korotkov_SEO_Factory::write_log('info', 'serp_results_saved', 'Результаты поисковой выдачи сохранены.', array('keyword_id' => $keyword_id, 'count' => $stored));
        return $stored;
    }

    private function cache_key($query_text, $page) {
        return hash('sha256', 'web-search:ru:225:xml:' . absint($page) . ':' . KSEF_Util::lower($query_text));
    }

    private function purge_cache($query_text, $page) {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'ksef_cache', array('cache_key' => $this->cache_key($query_text, $page)), array('%s'));
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
            // Ориентировочная оценка по публичному прайсу (~$4 за 1000 синхронных запросов); не подтверждённое списание.
            'estimated_cost' => KSEF_Util::SEARCH_COST_ESTIMATE,
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
