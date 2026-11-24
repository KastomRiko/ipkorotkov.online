<?php
/**
 * Small, resumable queue. Up to five SERP jobs are processed per cron tick.
 * Wordstat and publishing are intentionally not scheduled by this class.
 */
if (!defined('ABSPATH')) { exit; }

final class KSEF_Queue {
    public static function enqueue_phrases($text) {
        global $wpdb;
        $phrases = preg_split('/[\\r\\n,;]+/u', (string) $text);
        $phrases = array_values(array_unique(array_filter(array_map(function ($phrase) {
            $phrase = trim(sanitize_text_field($phrase));
            return $phrase !== '' ? $phrase : null;
        }, $phrases))));

        if (!$phrases) return new WP_Error('ksef_no_phrases', 'Не найдено ни одной поисковой фразы.');
        if (count($phrases) > 30) return new WP_Error('ksef_batch_too_large', 'Одна партия ограничена 30 фразами.');

        $keywords = $wpdb->prefix . 'ksef_keywords';
        $jobs = $wpdb->prefix . 'ksef_jobs';
        // Сериализуем все операции добавления через один MySQL lock: SELECT-проверка
        // и последующая вставка больше не могут одновременно пройти в двух PHP workers.
        $lock = KSEF_Lock::acquire('queue_enqueue', 0);
        if (is_wp_error($lock)) return $lock;

        $added = 0;
        $failed = 0;
        try {
            foreach ($phrases as $phrase) {
                $normalized = function_exists('mb_strtolower') ? mb_strtolower($phrase) : strtolower($phrase);
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$keywords} WHERE normalized_phrase = %s LIMIT 1",
                    $normalized
                ));
                if ($wpdb->last_error !== '') {
                    $failed++;
                    continue;
                }
                if ($exists) continue;

                // Ключевая фраза и её задание создаются как одна транзакция.
                if ($wpdb->query('START TRANSACTION') === false) {
                    $failed++;
                    continue;
                }

                $now = current_time('mysql', true);
                $keyword_ok = $wpdb->insert($keywords, array(
                    'source_phrase' => $phrase,
                    'normalized_phrase' => $normalized,
                    'region' => 'Россия',
                    'created_at' => $now,
                ), array('%s','%s','%s','%s'));

                if ($keyword_ok !== 1 || (int) $wpdb->insert_id < 1) {
                    $wpdb->query('ROLLBACK');
                    $failed++;
                    continue;
                }
                $keyword_id = (int) $wpdb->insert_id;

                $payload = wp_json_encode(array('keyword_id' => $keyword_id, 'page' => 0));
                $job_ok = $wpdb->insert($jobs, array(
                    'job_type' => 'serp_search',
                    'status' => 'new',
                    'payload' => $payload,
                    'created_at' => $now,
                    'updated_at' => $now,
                ), array('%s','%s','%s','%s','%s'));

                if ($job_ok !== 1 || (int) $wpdb->insert_id < 1) {
                    $wpdb->query('ROLLBACK');
                    $failed++;
                    continue;
                }
                $job_id = (int) $wpdb->insert_id;

                $linked = $wpdb->update(
                    $keywords,
                    array('job_id' => $job_id),
                    array('id' => $keyword_id),
                    array('%d'),
                    array('%d')
                );
                if ($linked !== 1) {
                    $wpdb->query('ROLLBACK');
                    $failed++;
                    continue;
                }

                if ($wpdb->query('COMMIT') === false) {
                    $wpdb->query('ROLLBACK');
                    $failed++;
                    continue;
                }
                $added++;
            }
        } finally {
            KSEF_Lock::release($lock);
        }

        return array('added' => $added, 'received' => count($phrases), 'failed' => $failed);
    }

    /** Коды ошибок, при которых задание возвращается в очередь без расхода попытки. */
    private static function waiting_codes() {
        return array(
            'ksef_search_busy', 'ksef_search_rate_limit', 'ksef_search_circuit_open', 'ksef_search_throttled',
            'ksef_api_circuit_open', 'ksef_automation_disabled', 'ksef_search_daily_limit', 'ksef_budget_stop', 'ksef_billing_data_missing', 'ksef_billing_data_stale', 'ksef_billing_estimate_read_failed', 'ksef_billing_reserve_failed',
        );
    }

    /** Возвращает в очередь задания, зависшие в processing дольше 10 минут. */
    private static function recover_stuck() {
        global $wpdb;
        $jobs = $wpdb->prefix . 'ksef_jobs';
        $wpdb->query($wpdb->prepare(
            "UPDATE {$jobs} SET status = 'new', updated_at = %s
             WHERE status = 'processing' AND job_type = 'serp_search' AND updated_at < %s",
            current_time('mysql', true),
            gmdate('Y-m-d H:i:s', time() - 600)
        ));
    }

    /** Обрабатывает до пяти фраз за один запуск, соблюдая двухсекундный интервал API. */
    public static function process_batch() {
        $settings = get_option(KSEF_OPTION, Korotkov_SEO_Factory::default_settings());
        if (empty($settings['automation_enabled'])) return false;
        if (!defined('KSEF_BILLING_VERIFIED') || KSEF_BILLING_VERIFIED !== true) return false;
        if (get_transient('ksef_api_circuit_open')) return false;

        for ($i = 0; $i < 5; $i++) {
            $processed = self::process_one();
            if (!$processed) break;
            if ($i < 4) sleep(2);
        }
    }

    public static function process_one() {
        global $wpdb;
        if (get_transient('ksef_api_circuit_open')) return false;

        $settings = get_option(KSEF_OPTION, Korotkov_SEO_Factory::default_settings());
        if (empty($settings['automation_enabled'])) return false;
        if (!defined('KSEF_BILLING_VERIFIED') || KSEF_BILLING_VERIFIED !== true) return false;

        $lock = KSEF_Lock::acquire('queue_worker', 0);
        if (is_wp_error($lock)) return false;

        try {
        self::recover_stuck();

        $jobs = $wpdb->prefix . 'ksef_jobs';
        $row = $wpdb->get_row("SELECT id, payload FROM {$jobs} WHERE status = 'new' AND job_type = 'serp_search' ORDER BY id ASC LIMIT 1");
        if (!$row) return false;
        $wpdb->update($jobs, array('status' => 'processing', 'updated_at' => current_time('mysql', true)), array('id' => (int) $row->id), array('%s','%s'), array('%d'));

        $payload = json_decode($row->payload, true);
        if (!is_array($payload)) $payload = array();
        $keyword_id = isset($payload['keyword_id']) ? absint($payload['keyword_id']) : 0;
        $keyword = $wpdb->get_row($wpdb->prepare(
            'SELECT source_phrase FROM ' . $wpdb->prefix . 'ksef_keywords WHERE id = %d',
            $keyword_id
        ));

        if (!$keyword_id || !$keyword) {
            self::finish_job((int) $row->id, 'rejected');
            return true;
        }

        $client = new KSEF_Search_Client();
        $result = $client->search_and_store($keyword_id, $keyword->source_phrase, 0);

        if (is_wp_error($result)) {
            $code = $result->get_error_code();
            if (in_array($code, self::waiting_codes(), true)) {
                $status = 'new';
            } else {
                // Прочие ошибки (в т.ч. HTTP 5xx, пустая выдача): до 3 попыток, затем error.
                $attempts = isset($payload['attempts']) ? absint($payload['attempts']) : 0;
                $retryable = (strpos((string) $code, 'ksef_search_http_5') === 0)
                    || in_array($code, array('ksef_search_transport', 'ksef_search_no_documents', 'ksef_search_xml_error'), true);
                if ($retryable && $attempts < 2) {
                    $payload['attempts'] = $attempts + 1;
                    $wpdb->update($jobs, array('payload' => wp_json_encode($payload)), array('id' => (int) $row->id), array('%s'), array('%d'));
                    $status = 'new';
                } else {
                    $status = 'error';
                }
            }
            self::finish_job((int) $row->id, $status);
            Korotkov_SEO_Factory::write_log('error', 'queue_job_failed', 'Задание поиска не выполнено.', array('job_id' => (int) $row->id, 'error' => $code, 'next_status' => $status));
            return !in_array($code, self::waiting_codes(), true);
        } else {
            $wpdb->update($wpdb->prefix . 'ksef_keywords', array('researched_at' => current_time('mysql', true)), array('id' => $keyword_id), array('%s'), array('%d'));
            self::finish_job((int) $row->id, 'researched');
            return true;
        }
        } finally {
            KSEF_Lock::release($lock);
        }
    }

    private static function finish_job($id, $status) {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'ksef_jobs', array(
            'status' => sanitize_key($status),
            'updated_at' => current_time('mysql', true),
        ), array('id' => $id), array('%s','%s'), array('%d'));
    }
}
