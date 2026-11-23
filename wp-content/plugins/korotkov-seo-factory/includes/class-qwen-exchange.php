<?php
/**
 * Manual CSV exchange with Qwen. No Qwen API or browser automation.
 */
if (!defined('ABSPATH')) { exit; }

final class KSEF_Qwen_Exchange {
    const MAX_ROWS = 30;
    const MAX_FILE_SIZE = 1048576;

    public static function register_hooks() {
        add_action('admin_post_ksef_qwen_export', array(__CLASS__, 'export_csv'));
        add_action('admin_post_ksef_qwen_import', array(__CLASS__, 'import_csv'));
    }

    private static function columns() {
        return array(
            'keyword_id', 'source_queries', 'serp_competitors',
            'recommended_title', 'h1', 'meta_title', 'meta_description',
            'short_intro', 'service_scope', 'confidence'
        );
    }

    public static function export_csv() {
        if (!current_user_can('manage_options')) wp_die('Недостаточно прав.');
        check_admin_referer('ksef_qwen_export');
        global $wpdb;

        $keywords_table = $wpdb->prefix . 'ksef_keywords';
        $serp_table = $wpdb->prefix . 'ksef_serp_results';
        $rows = $wpdb->get_results(
            "SELECT id, source_phrase, region FROM {$keywords_table}
             WHERE researched_at IS NOT NULL ORDER BY researched_at DESC, id DESC LIMIT " . self::MAX_ROWS
        );

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=korotkov-qwen-tasks-' . gmdate('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        // UTF-8 BOM for Excel compatibility.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::columns());

        foreach ($rows as $row) {
            $competitors = $wpdb->get_results($wpdb->prepare(
                "SELECT result_url, result_title, result_snippet, position_num
                 FROM {$serp_table} WHERE keyword_id = %d ORDER BY position_num ASC LIMIT 10",
                (int) $row->id
            ), ARRAY_A);
            $safe_competitors = array();
            foreach ($competitors as $competitor) {
                $safe_competitors[] = array(
                    'position' => (int) $competitor['position_num'],
                    'url' => (string) $competitor['result_url'],
                    'title' => (string) $competitor['result_title'],
                    'snippet' => (string) $competitor['result_snippet'],
                );
            }
            fputcsv($out, array(
                (int) $row->id,
                self::csv_safe($row->source_phrase . ' | Регион: ' . $row->region),
                self::csv_safe(wp_json_encode($safe_competitors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                '', '', '', '', '', '', ''
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
            self::redirect('error', 'Столбцы не совпадают с шаблоном Korotkov SEO Factory. Экспортируйте новый CSV и сохраните порядок столбцов.');
        }

        global $wpdb;
        $keywords_table = $wpdb->prefix . 'ksef_keywords';
        $imports_table = $wpdb->prefix . 'ksef_qwen_imports';
        $imported = 0;
        $skipped = 0;
        $line = 1;

        while (($data = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;
            if (count($data) !== count($required)) { $skipped++; continue; }
            if (count(array_filter($data, 'strlen')) === 0) continue;
            if ($line > self::MAX_ROWS + 1) { $skipped++; continue; }

            $item = array_combine($required, $data);
            $keyword_id = absint($item['keyword_id']);
            if (!$keyword_id || !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$keywords_table} WHERE id = %d", $keyword_id))) {
                $skipped++;
                continue;
            }

            $title = sanitize_text_field($item['recommended_title']);
            $h1 = sanitize_text_field($item['h1']);
            $meta_title = sanitize_text_field($item['meta_title']);
            $meta_description = sanitize_textarea_field($item['meta_description']);
            $short_intro = sanitize_textarea_field($item['short_intro']);
            $service_scope = sanitize_textarea_field($item['service_scope']);
            $confidence = sanitize_text_field($item['confidence']);

            // Do not accept empty or incomplete AI rows as ready content.
            if ($title === '' || $h1 === '' || $meta_title === '' || $meta_description === '' || $short_intro === '') {
                $skipped++;
                continue;
            }

            $payload = array(
                'source_queries' => sanitize_textarea_field($item['source_queries']),
                'serp_competitors' => wp_kses_post($item['serp_competitors']),
                'recommended_title' => $title,
                'h1' => $h1,
                'meta_title' => $meta_title,
                'meta_description' => $meta_description,
                'short_intro' => $short_intro,
                'service_scope' => $service_scope,
                'confidence' => $confidence,
            );
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$imports_table} WHERE keyword_id = %d ORDER BY id DESC LIMIT 1",
                $keyword_id
            ));
            $now = current_time('mysql', true);
            if ($existing_id) {
                $ok = $wpdb->update($imports_table, array(
                    'payload' => wp_json_encode($payload),
                    'status' => 'draft_review',
                    'updated_at' => $now,
                ), array('id' => (int) $existing_id), array('%s','%s','%s'), array('%d'));
            } else {
                $ok = $wpdb->insert($imports_table, array(
                    'keyword_id' => $keyword_id,
                    'payload' => wp_json_encode($payload),
                    'status' => 'draft_review',
                    'created_at' => $now,
                    'updated_at' => $now,
                ), array('%d','%s','%s','%s','%s'));
            }
            if ($ok !== false) $imported++; else $skipped++;
        }
        fclose($handle);
        self::redirect('imported', 'Импортировано: ' . $imported . '; пропущено: ' . $skipped . '. Материалы сохранены как черновики для проверки; публикации нет.');
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
