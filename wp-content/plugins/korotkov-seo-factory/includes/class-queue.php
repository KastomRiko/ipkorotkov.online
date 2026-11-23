<?php
/**
 * Small, resumable queue. One SERP job is processed per cron tick.
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
        $added = 0;
        foreach ($phrases as $phrase) {
            $normalized = function_exists('mb_strtolower') ? mb_strtolower($phrase) : strtolower($phrase);
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$keywords} WHERE normalized_phrase = %s LIMIT 1",
                $normalized
            ));
            if ($exists) continue;

            $now = current_time('mysql', true);
            $ok = $wpdb->insert($keywords, array(
                'source_phrase' => $phrase,
                'normalized_phrase' => $normalized,
                'region' => 'Россия',
                'created_at' => $now,
            ), array('%s','%s','%s','%s'));
            if (!$ok) continue;
            $keyword_id = (int) $wpdb->insert_id;

            $payload = wp_json_encode(array('keyword_id' => $keyword_id, 'page' => 0));
            $wpdb->insert($jobs, array(
                'job_type' => 'serp_search',
                'status' => 'new',
                'payload' => $payload,
                'created_at' => $now,
                'updated_at' => $now,
            ), array('%s','%s','%s','%s','%s'));
            $job_id = (int) $wpdb->insert_id;
            $wpdb->update($keywords, array('job_id' => $job_id), array('id' => $keyword_id), array('%d'), array('%d'));
            $added++;
        }
        return array('added' => $added, 'received' => count($phrases));
    }

    public static function process_one() {
        global $wpdb;
        if (get_transient('ksef_queue_worker_lock')) return;
        if (get_transient('ksef_api_circuit_open')) return;

        $settings = get_option(KSEF_OPTION, Korotkov_SEO_Factory::default_settings());
        if (empty($settings['automation_enabled'])) return;
        if (!defined('KSEF_BILLING_VERIFIED') || KSEF_BILLING_VERIFIED !== true) return;

        $jobs = $wpdb->prefix . 'ksef_jobs';
        $row = $wpdb->get_row("SELECT id, payload FROM {$jobs} WHERE status = 'new' AND job_type = 'serp_search' ORDER BY id ASC LIMIT 1");
        if (!$row) return;

        set_transient('ksef_queue_worker_lock', 1, 90);
        $wpdb->update($jobs, array('status' => 'processing', 'updated_at' => current_time('mysql', true)), array('id' => (int) $row->id), array('%s','%s'), array('%d'));

        $payload = json_decode($row->payload, true);
        $keyword_id = isset($payload['keyword_id']) ? absint($payload['keyword_id']) : 0;
        $keyword = $wpdb->get_row($wpdb->prepare(
            'SELECT source_phrase FROM ' . $wpdb->prefix . 'ksef_keywords WHERE id = %d',
            $keyword_id
        ));

        if (!$keyword_id || !$keyword) {
            self::finish_job((int) $row->id, 'rejected');
            delete_transient('ksef_queue_worker_lock');
            return;
        }

        $client = new KSEF_Search_Client();
        $result = $client->search_and_store($keyword_id, $keyword->source_phrase, 0);

        if (is_wp_error($result)) {
            $code = $result->get_error_code();
            $status = in_array($code, array('ksef_search_busy','ksef_search_rate_limit','ksef_search_circuit_open','ksef_search_throttled'), true) ? 'new' : 'error';
            self::finish_job((int) $row->id, $status);
            Korotkov_SEO_Factory::write_log('error', 'queue_job_failed', 'Задание поиска не выполнено.', array('job_id' => (int) $row->id, 'error' => $code));
        } else {
            $wpdb->update($wpdb->prefix . 'ksef_keywords', array('researched_at' => current_time('mysql', true)), array('id' => $keyword_id), array('%s'), array('%d'));
            self::finish_job((int) $row->id, 'researched');
        }
        delete_transient('ksef_queue_worker_lock');
    }

    private static function finish_job($id, $status) {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'ksef_jobs', array(
            'status' => sanitize_key($status),
            'updated_at' => current_time('mysql', true),
        ), array('id' => $id), array('%s','%s'), array('%d'));
    }
}
