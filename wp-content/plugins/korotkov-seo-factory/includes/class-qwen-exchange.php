<?php
/**
 * Manual cluster content exchange with Qwen. No Qwen API or browser automation.
 */
if (!defined('ABSPATH')) { exit; }

final class KSEF_Qwen_Exchange {
    const MAX_ROWS = 30;
    const MAX_FILE_SIZE = 1048576;
    const MIN_ARTICLE_WORDS = 500;
    const DUPLICATE_THRESHOLD = 0.82;

    public static function register_hooks() {
        add_action('admin_post_ksef_qwen_export', array(__CLASS__, 'export_csv'));
        add_action('admin_post_ksef_qwen_import', array(__CLASS__, 'import_csv'));
    }

    private static function columns() {
        return array(
            'keyword_id', 'source_queries', 'serp_competitors',
            'recommended_title', 'h1', 'meta_title', 'meta_description',
            'short_intro', 'service_scope', 'confidence',
            'article_html', 'faq_html', 'internal_links'
        );
    }

    public static function export_csv() {
        if (!current_user_can('manage_options')) wp_die('Недостаточно прав.');
        check_admin_referer('ksef_qwen_export');
        global $wpdb;

        $keywords_table = $wpdb->prefix . 'ksef_keywords';
        $serp_table = $wpdb->prefix . 'ksef_serp_results';
        $clusters_table = $wpdb->prefix . 'ksef_clusters';
        $cluster_ids = $wpdb->get_col(
            "SELECT DISTINCT cluster_id FROM {$keywords_table}
             WHERE cluster_id > 0 ORDER BY cluster_id DESC LIMIT " . self::MAX_ROWS
        );

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=korotkov-qwen-clusters-' . gmdate('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::columns());

        foreach ($cluster_ids as $cluster_id) {
            $members = $wpdb->get_results($wpdb->prepare(
                "SELECT id, source_phrase, region FROM {$keywords_table}
                 WHERE cluster_id = %d ORDER BY id ASC",
                (int) $cluster_id
            ));
            if (!$members) continue;

            $member_ids = array_map('absint', wp_list_pluck($members, 'id'));
            $placeholders = implode(',', array_fill(0, count($member_ids), '%d'));
            $competitors = $wpdb->get_results($wpdb->prepare(
                "SELECT result_url, result_title, result_snippet, position_num, keyword_id
                 FROM {$serp_table} WHERE keyword_id IN ({$placeholders})
                 ORDER BY position_num ASC LIMIT 30",
                $member_ids
            ), ARRAY_A);

            $safe_competitors = array();
            foreach ($competitors as $competitor) {
                $safe_competitors[] = array(
                    'keyword_id' => (int) $competitor['keyword_id'],
                    'position' => (int) $competitor['position_num'],
                    'url' => (string) $competitor['result_url'],
                    'title' => (string) $competitor['result_title'],
                    'snippet' => (string) $competitor['result_snippet'],
                );
            }

            $queries = array();
            foreach ($members as $member) $queries[] = (string) $member->source_phrase;
            $cluster_label = $wpdb->get_var($wpdb->prepare(
                "SELECT label FROM {$clusters_table} WHERE id = %d",
                (int) $cluster_id
            ));

            fputcsv($out, array(
                (int) $members[0]->id,
                self::csv_safe("Кластер #{$cluster_id}: " . implode(' | ', $queries)),
                self::csv_safe(wp_json_encode(array(
                    'cluster_id' => (int) $cluster_id,
                    'cluster_label' => (string) $cluster_label,
                    'results' => $safe_competitors,
                ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                '', '', '', '', '', '', '', '', '', ''
            ));
        }
        fclose($out);
        exit;
    }

    public static function import_csv() {
        if (!current_user_can('manage_options')) wp_die('Недостаточно прав.');
        check_admin_referer('ksef_qwen_import');

        if (empty($_FILES['ksef_qwen_csv']['tmp_name']) || !is_uploaded_file($_FILES['ksef_qwen_csv']['tmp_name'])) {
            self::redirect('error', 'Файл не загружен.');
        }
        if (!empty($_FILES['ksef_qwen_csv']['size']) && (int) $_FILES['ksef_qwen_csv']['size'] > self::MAX_FILE_SIZE) {
            self::redirect('error', 'Размер CSV не должен превышать 1 МБ.');
        }

        $handle = fopen($_FILES['ksef_qwen_csv']['tmp_name'], 'r');
        if (!$handle) self::redirect('error', 'Не удалось прочитать CSV.');
        $header = fgetcsv($handle, 0, ',', '"', '');
        if (!$header) {
            fclose($handle);
            self::redirect('error', 'CSV пустой или имеет неверный формат.');
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header = array_map('trim', $header);
        $required = self::columns();
        if ($header !== $required) {
            fclose($handle);
            self::redirect('error', 'Столбцы CSV не совпадают. Экспортируй новый шаблон версии 0.7.0.');
        }

        global $wpdb;
        $keywords_table = $wpdb->prefix . 'ksef_keywords';
        $imports_table = $wpdb->prefix . 'ksef_qwen_imports';
        $pages_table = $wpdb->prefix . 'ksef_pages';
        $imported = 0;
        $blocked = 0;
        $skipped = 0;
        $line = 1;

        while (($data = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;
            if (count($data) !== count($required)) { $skipped++; continue; }
            if (count(array_filter($data, 'strlen')) === 0) continue;
            if ($line > self::MAX_ROWS + 1) { $skipped++; continue; }

            $item = array_combine($required, $data);
            $keyword_id = absint($item['keyword_id']);
            $keyword = $wpdb->get_row($wpdb->prepare(
                "SELECT id, cluster_id FROM {$keywords_table} WHERE id = %d",
                $keyword_id
            ));
            if (!$keyword || !$keyword->cluster_id) { $skipped++; continue; }

            $title = sanitize_text_field($item['recommended_title']);
            $h1 = sanitize_text_field($item['h1']);
            $meta_title = sanitize_text_field($item['meta_title']);
            $meta_description = sanitize_textarea_field($item['meta_description']);
            $short_intro = sanitize_textarea_field($item['short_intro']);
            $service_scope = sanitize_textarea_field($item['service_scope']);
            $confidence = sanitize_text_field($item['confidence']);
            $article_html = wp_kses_post($item['article_html']);
            $faq_html = wp_kses_post($item['faq_html']);
            $internal_links = sanitize_textarea_field($item['internal_links']);

            if ($title === '' || $h1 === '' || $meta_title === '' || $meta_description === '' ||
                $short_intro === '' || trim(wp_strip_all_tags($article_html)) === '') {
                $skipped++;
                continue;
            }

            $plain_content = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($article_html . ' ' . $faq_html)));
            $word_count = self::word_count($plain_content);
            $payload = array(
                'cluster_id' => (int) $keyword->cluster_id,
                'source_queries' => sanitize_textarea_field($item['source_queries']),
                'serp_competitors' => sanitize_textarea_field($item['serp_competitors']),
                'recommended_title' => $title,
                'h1' => $h1,
                'meta_title' => $meta_title,
                'meta_description' => $meta_description,
                'short_intro' => $short_intro,
                'service_scope' => $service_scope,
                'confidence' => $confidence,
                'article_html' => $article_html,
                'faq_html' => $faq_html,
                'internal_links' => $internal_links,
                'word_count' => $word_count,
            );

            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$imports_table} WHERE keyword_id = %d ORDER BY id DESC LIMIT 1",
                $keyword_id
            ));
            $checks = self::validate_content($payload, (int) $keyword->cluster_id, $existing_id ? (int) $existing_id : 0);
            $payload['validation'] = $checks;
            $status = !empty($checks['errors']) ? 'blocked_review' : 'ready_for_publish';
            $now = current_time('mysql', true);

            if ($existing_id) {
                $ok = $wpdb->update($imports_table, array(
                    'payload' => wp_json_encode($payload),
                    'status' => $status,
                    'updated_at' => $now,
                ), array('id' => (int) $existing_id), array('%s','%s','%s'), array('%d'));
            } else {
                $ok = $wpdb->insert($imports_table, array(
                    'keyword_id' => $keyword_id,
                    'payload' => wp_json_encode($payload),
                    'status' => $status,
                    'created_at' => $now,
                    'updated_at' => $now,
                ), array('%d','%s','%s','%s','%s'));
            }

            if ($ok === false) { $skipped++; continue; }
            $imported++;
            if (!empty($checks['errors'])) $blocked++;

            $slug = sanitize_title($title);
            $page_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$pages_table} WHERE cluster_id = %d ORDER BY id DESC LIMIT 1",
                (int) $keyword->cluster_id
            ));
            $wpdb->update($pages_table, array(
                'page_title' => $title,
                'page_slug' => $slug,
                'status' => $status,
                'updated_at' => $now,
            ), array('id' => (int) $page_id), array('%s','%s','%s','%s'), array('%d'));
        }

        fclose($handle);
        self::redirect('imported', 'Импортировано материалов: ' . $imported . '; заблокировано проверками: ' . $blocked . '; пропущено: ' . $skipped . '. Страницы WordPress не создавались и не публиковались.');
    }

    private static function validate_content($payload, $cluster_id, $exclude_import_id) {
        global $wpdb;
        $errors = array();
        $warnings = array();

        if ((int) $payload['word_count'] < self::MIN_ARTICLE_WORDS) {
            $errors[] = 'Текст короче минимального порога ' . self::MIN_ARTICLE_WORDS . ' слов';
        }
        if (self::length($payload['meta_title']) < 30 || self::length($payload['meta_title']) > 70) {
            $warnings[] = 'Проверь длину meta title (ориентир 30–70 символов)';
        }
        if (self::length($payload['meta_description']) < 70 || self::length($payload['meta_description']) > 170) {
            $warnings[] = 'Проверь длину meta description (ориентир 70–170 символов)';
        }

        $pages_table = $wpdb->prefix . 'ksef_pages';
        $slug = sanitize_title($payload['recommended_title']);
        $same_slug = $wpdb->get_var($wpdb->prepare(
            "SELECT p.id FROM {$pages_table} p
             WHERE p.page_slug = %s AND p.cluster_id <> %d LIMIT 1",
            $slug, $cluster_id
        ));
        if ($same_slug) $errors[] = 'Такой URL-slug уже назначен другой SEO-странице';

        $imports_table = $wpdb->prefix . 'ksef_qwen_imports';
        $rows = $wpdb->get_results(
            "SELECT id, keyword_id, payload FROM {$imports_table}
             ORDER BY id DESC LIMIT 500"
        );
        $keywords_table = $wpdb->prefix . 'ksef_keywords';
        $candidate_tokens = self::tokens($payload['article_html'] . ' ' . $payload['faq_html']);

        foreach ($rows as $row) {
            if ((int) $row->id === $exclude_import_id) continue;
            $other = json_decode($row->payload, true);
            if (!is_array($other)) continue;
            $other_cluster = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT cluster_id FROM {$keywords_table} WHERE id = %d",
                (int) $row->keyword_id
            ));
            if ($other_cluster === $cluster_id) continue;

            $candidate_meta = self::normalize_text($payload['meta_title']);
            $candidate_h1 = self::normalize_text($payload['h1']);
            $other_meta = self::normalize_text($other['meta_title'] ?? '');
            $other_h1 = self::normalize_text($other['h1'] ?? '');
            if (($candidate_meta !== '' && $candidate_meta === $other_meta) ||
                ($candidate_h1 !== '' && $candidate_h1 === $other_h1)) {
                $errors[] = 'Обнаружен совпадающий H1 или meta title у другой страницы';
                break;
            }

            $other_tokens = self::tokens(($other['article_html'] ?? '') . ' ' . ($other['faq_html'] ?? ''));
            $similarity = self::jaccard($candidate_tokens, $other_tokens);
            if ($similarity >= self::DUPLICATE_THRESHOLD && count($candidate_tokens) >= 30 && count($other_tokens) >= 30) {
                $errors[] = 'Обнаружен близкий дубль текста (сходство ' . round($similarity * 100) . '%)';
                break;
            }
        }

        return array(
            'passed' => empty($errors),
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'checked_at' => current_time('mysql', true),
        );
    }

    private static function word_count($text) {
        preg_match_all('/[\p{L}\p{N}]+/u', (string) $text, $matches);
        return count($matches[0]);
    }

    private static function length($text) {
        return function_exists('mb_strlen') ? mb_strlen((string) $text) : strlen((string) $text);
    }

    private static function normalize_text($text) {
        $text = function_exists('mb_strtolower') ? mb_strtolower((string) $text, 'UTF-8') : strtolower((string) $text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private static function tokens($html) {
        $text = self::normalize_text(wp_strip_all_tags((string) $html));
        $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $stop = array('это','для','или','как','при','что','чтобы','также','если','можно','будет','быть','есть','под','над','его','ее','их','the','and','with','from');
        $set = array();
        foreach ($parts as $part) {
            if (self::length($part) < 3 || in_array($part, $stop, true)) continue;
            $set[$part] = true;
        }
        return $set;
    }

    private static function jaccard($a, $b) {
        if (!$a || !$b) return 0;
        $intersection = count(array_intersect_key($a, $b));
        $union = count($a) + count($b) - $intersection;
        return $union ? $intersection / $union : 0;
    }

    private static function csv_safe($value) {
        $value = (string) $value;
        return preg_match('/^[\s]*[=+@-]/u', $value) ? "'" . $value : $value;
    }

    private static function redirect($status, $message) {
        set_transient('ksef_qwen_notice_' . get_current_user_id(), array(
            'status' => sanitize_key($status),
            'message' => sanitize_text_field($message),
        ), 60);
        wp_safe_redirect(admin_url('admin.php?page=korotkov-seo-factory'));
        exit;
    }
}
