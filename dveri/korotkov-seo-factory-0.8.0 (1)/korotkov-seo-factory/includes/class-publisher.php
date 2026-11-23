<?php
/**
 * Controlled creation of WordPress pages from content that passed the quality gate.
 * Never overwrites or modifies an existing page: a taken slug or title stops the operation.
 */
if (!defined('ABSPATH')) { exit; }

final class KSEF_Publisher {
    const MIN_WORDS = 500;

    public static function register_hooks() {
        add_action('admin_post_ksef_publish_page', array(__CLASS__, 'handle_publish_page'));
        add_action('admin_post_ksef_publish_all', array(__CLASS__, 'handle_publish_all'));
        add_action('ksef_page_ready', array(__CLASS__, 'on_page_ready'));
    }

    /* ---------- Settings helpers ---------- */

    private static function settings() {
        return wp_parse_args(get_option(KSEF_OPTION, array()), Korotkov_SEO_Factory::default_settings());
    }

    private static function target_status() {
        $s = self::settings();
        return (isset($s['publish_status']) && $s['publish_status'] === 'publish') ? 'publish' : 'draft';
    }

    private static function per_day_limit() {
        $s = self::settings();
        return max(1, min(50, absint($s['publish_per_day'] ?? 3)));
    }

    private static function created_today() {
        return absint(get_option('ksef_created_' . gmdate('Ymd'), 0));
    }

    /* ---------- Hooks ---------- */

    public static function on_page_ready($page_id) {
        $s = self::settings();
        if (empty($s['auto_create'])) return;
        $result = self::create_from_page((int) $page_id, null, false);
        if (is_wp_error($result)) {
            Korotkov_SEO_Factory::write_log('warning', 'auto_create_skipped', 'Автосоздание страницы отложено: ' . $result->get_error_message(), array('page_id' => (int) $page_id, 'code' => $result->get_error_code()));
        }
    }

    /** Вызывается ежедневным планировщиком: досоздаёт страницы, не уместившиеся в дневной лимит. */
    public static function process_pending() {
        $s = self::settings();
        if (empty($s['auto_create'])) return;
        global $wpdb;
        $ids = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}ksef_pages WHERE status = 'ready_for_publish' AND wp_page_id = 0 ORDER BY id ASC LIMIT 50");
        foreach ($ids as $id) {
            $result = self::create_from_page((int) $id, null, false);
            if (is_wp_error($result) && $result->get_error_code() === 'ksef_publish_daily_limit') break;
        }
    }

    public static function handle_publish_page() {
        if (!current_user_can('manage_options')) wp_die('Недостаточно прав.');
        check_admin_referer('ksef_publish_page');
        $page_id = isset($_POST['page_id']) ? absint($_POST['page_id']) : 0;
        $mode = isset($_POST['mode']) ? sanitize_key($_POST['mode']) : 'draft';

        if ($mode === 'promote') {
            $result = self::promote($page_id);
        } else {
            $result = self::create_from_page($page_id, $mode === 'publish' ? 'publish' : 'draft', true);
        }
        if (is_wp_error($result)) {
            self::notice('error', $result->get_error_message());
        } else {
            self::notice('success', 'Готово: страница #' . (int) $result . ' — ' . get_post_status($result) . '.');
        }
        wp_safe_redirect(admin_url('admin.php?page=korotkov-seo-factory'));
        exit;
    }

    public static function handle_publish_all() {
        if (!current_user_can('manage_options')) wp_die('Недостаточно прав.');
        check_admin_referer('ksef_publish_all');
        global $wpdb;
        $ids = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}ksef_pages WHERE status = 'ready_for_publish' AND wp_page_id = 0 ORDER BY id ASC LIMIT 50");
        $created = 0;
        $last_error = '';
        foreach ($ids as $id) {
            $result = self::create_from_page((int) $id, 'draft', false);
            if (is_wp_error($result)) {
                $last_error = $result->get_error_message();
                if ($result->get_error_code() === 'ksef_publish_daily_limit') break;
            } else {
                $created++;
            }
        }
        $msg = 'Создано черновиков: ' . $created . '.' . ($last_error ? ' Последняя остановка: ' . $last_error : '');
        self::notice($created ? 'success' : 'error', $msg);
        wp_safe_redirect(admin_url('admin.php?page=korotkov-seo-factory'));
        exit;
    }

    /* ---------- Core ---------- */

    /**
     * Создаёт новую страницу WordPress. Возвращает ID записи или WP_Error.
     * $status: 'draft' | 'publish' | null (из настроек). $manual: ручной запуск обходит дневной лимит.
     */
    public static function create_from_page($page_id, $status = null, $manual = false) {
        $page_id = absint($page_id);

        // Блокировка MySQL охватывает и проверку занятого URL, и вставку страницы (иначе возможна гонка).
        $lock = KSEF_Lock::acquire('publisher', 5);
        if (is_wp_error($lock)) {
            return new WP_Error('ksef_publish_busy', 'Другая операция создания страниц ещё выполняется. Повторите позже.');
        }
        try {
            $result = self::do_create($page_id, $status ?: self::target_status(), $manual);
        } finally {
            KSEF_Lock::release($lock);
        }
        return $result;
    }

    private static function do_create($page_id, $status, $manual) {
        global $wpdb;
        $pages = $wpdb->prefix . 'ksef_pages';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pages} WHERE id = %d", $page_id));
        if (!$row) return new WP_Error('ksef_page_missing', 'Карточка страницы не найдена.');
        // Восстановление после сбоя БД: WP-страница могла создаться, а связь в ksef_pages — не сохраниться.
        if ((int) $row->wp_page_id <= 0) {
            $orphan_id = $wpdb->get_var($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE pm.meta_key = '_ksef_page_row_id' AND pm.meta_value = %s
                   AND p.post_type = 'page' AND p.post_status NOT IN ('trash','auto-draft')
                 ORDER BY p.ID DESC LIMIT 1",
                (string) $row->id
            ));
            if ($orphan_id) {
                // При предыдущем сбое страница могла сохраниться, а часть метаданных — нет.
                // Повторно загружаем последний готовый импорт и восстанавливаем служебные поля.
                $recovery_import = $wpdb->get_row($wpdb->prepare(
                    "SELECT qi.payload, qi.status FROM {$wpdb->prefix}ksef_qwen_imports qi
                     INNER JOIN {$wpdb->prefix}ksef_keywords k ON k.id = qi.keyword_id
                     WHERE k.cluster_id = %d ORDER BY qi.id DESC LIMIT 1",
                    (int) $row->cluster_id
                ));
                if (!$recovery_import || $recovery_import->status !== 'ready_for_publish') {
                    return new WP_Error('ksef_recovery_import_missing', 'Страница найдена, но готовый исходный материал для восстановления метаданных отсутствует.');
                }
                $recovery_data = json_decode($recovery_import->payload, true);
                if (!is_array($recovery_data)) {
                    return new WP_Error('ksef_recovery_import_corrupt', 'Исходный материал для восстановления метаданных повреждён.');
                }
                $meta_recovered = self::write_managed_meta((int) $orphan_id, $row, $recovery_data);
                if (is_wp_error($meta_recovered)) return $meta_recovered;

                $recovered = self::reconcile_page_link($row, (int) $orphan_id);
                if (is_wp_error($recovered)) return $recovered;
                return (int) $orphan_id;
            }
        }
        if ((int) $row->wp_page_id > 0 || in_array($row->status, array('draft_created', 'published'), true)) {
            return new WP_Error('ksef_page_exists', 'Для этой карточки страница уже создана; перезапись запрещена.');
        }
        if ($row->status !== 'ready_for_publish') {
            return new WP_Error('ksef_page_not_ready', 'Карточка не имеет статуса ready_for_publish.');
        }

        if (!$manual && self::created_today() >= self::per_day_limit()) {
            return new WP_Error('ksef_publish_daily_limit', 'Достигнут дневной лимит создания страниц (' . self::per_day_limit() . ').');
        }

        // Повторная проверка непосредственно перед созданием: берём свежий импорт кластера.
        $import = $wpdb->get_row($wpdb->prepare(
            "SELECT qi.id, qi.payload, qi.status FROM {$wpdb->prefix}ksef_qwen_imports qi
             INNER JOIN {$wpdb->prefix}ksef_keywords k ON k.id = qi.keyword_id
             WHERE k.cluster_id = %d ORDER BY qi.id DESC LIMIT 1",
            (int) $row->cluster_id
        ));
        if (!$import || $import->status !== 'ready_for_publish') {
            return new WP_Error('ksef_import_not_ready', 'Нет импортированного материала со статусом ready_for_publish.');
        }
        $data = json_decode($import->payload, true);
        if (!is_array($data)) return new WP_Error('ksef_import_corrupt', 'Материал повреждён.');

        $h1 = trim((string) ($data['h1'] ?? ''));
        $meta_title = trim((string) ($data['meta_title'] ?? ''));
        $meta_desc = trim((string) ($data['meta_description'] ?? ''));
        $article = (string) ($data['article_html'] ?? '');
        $faq = (string) ($data['faq_html'] ?? '');
        if ($h1 === '' || $meta_title === '' || $meta_desc === '' || trim(wp_strip_all_tags($article)) === '') {
            return new WP_Error('ksef_fields_missing', 'Не хватает обязательных полей материала.');
        }
        $plain = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($article . ' ' . $faq)));
        preg_match_all('/[\p{L}\p{N}]+/u', $plain, $m);
        if (count($m[0]) < self::MIN_WORDS) {
            return new WP_Error('ksef_too_short', 'Текст короче ' . self::MIN_WORDS . ' слов.');
        }
        if (preg_match('/<\s*(script|iframe|object|embed|form|style)\b/i', $article . $faq)) {
            return new WP_Error('ksef_unsafe_html', 'В материале обнаружены запрещённые HTML-теги.');
        }

        // URL не должен быть занят: не перезаписываем и не создаём «-2».
        $slug = $row->page_slug !== '' ? $row->page_slug : KSEF_Util::slug($data['recommended_title'] ?? $h1, 80);
        if ($slug === '') return new WP_Error('ksef_slug_empty', 'Не удалось сформировать URL.');
        $taken = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type IN ('page','post') AND post_status NOT IN ('trash','auto-draft') LIMIT 1",
            $slug
        ));
        if ($taken) return new WP_Error('ksef_slug_taken', 'URL «' . $slug . '» уже занят существующей записью (ID ' . (int) $taken . ').');
        $same_title = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = 'page' AND post_status NOT IN ('trash','auto-draft') LIMIT 1",
            $h1
        ));
        if ($same_title) return new WP_Error('ksef_title_taken', 'Страница с таким заголовком уже есть (ID ' . (int) $same_title . ').');

        $content = $article;
        if (trim(wp_strip_all_tags($faq)) !== '') {
            $content .= "\n<div class=\"ksef-faq\">\n<h2>Частые вопросы</h2>\n" . $faq . "\n</div>";
        }
        $related = self::related_links_html((string) ($data['internal_links'] ?? ''));
        if ($related !== '') $content .= "\n" . $related;

        $author = get_current_user_id();
        if (!$author) {
            $admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
            $author = $admins ? (int) $admins[0] : 1;
        }

        $post_id = wp_insert_post(wp_slash(array(
            'post_type' => 'page',
            'post_status' => $status === 'publish' ? 'publish' : 'draft',
            'post_title' => $h1,
            'post_name' => $slug,
            'post_content' => $content,
            'post_excerpt' => (string) ($data['short_intro'] ?? ''),
            'post_author' => $author,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        )), true);
        if (is_wp_error($post_id)) return $post_id;
        if (!$post_id) return new WP_Error('ksef_insert_failed', 'WordPress не создал страницу.');

        $meta_result = self::write_managed_meta($post_id, $row, $data);
        if (is_wp_error($meta_result)) {
            Korotkov_SEO_Factory::write_log('critical', 'page_meta_write_failed', 'Страница создана, но обязательные метаполя не прошли проверку чтением. Повторный запуск попробует восстановить метаданные по служебной связи.', array('page_row' => (int) $row->id, 'post_id' => (int) $post_id, 'error' => $meta_result->get_error_message()));
            return new WP_Error('ksef_page_meta_write_failed', 'Страница создана (ID ' . (int) $post_id . '), но обязательные метаданные записались не полностью. Повторите действие для восстановления; дубль создаваться не должен.');
        }

        // WordPress мог скорректировать slug (зарезервированные имена и т.п.) — сохраняем фактический.
        $actual_slug = get_post_field('post_name', $post_id);
        if ($actual_slug) $slug = $actual_slug;
        $now = current_time('mysql', true);
        $final = get_post_status($post_id);
        $linked = $wpdb->update($pages, array(
            'wp_page_id' => (int) $post_id,
            'page_slug' => $slug,
            'page_url' => $final === 'publish' ? get_permalink($post_id) : '',
            'status' => $final === 'publish' ? 'published' : 'draft_created',
            'published_at' => $final === 'publish' ? $now : null,
            'updated_at' => $now,
        ), array('id' => (int) $row->id), array('%d', '%s', '%s', '%s', '%s', '%s'), array('%d'));
        if ($linked === false || $linked === 0) {
            Korotkov_SEO_Factory::write_log('critical', 'page_link_failed', 'Страница WordPress создана, но связь с карточкой не сохранена. Повторный запуск выполнит восстановление по метаполю.', array('page_row' => (int) $row->id, 'post_id' => (int) $post_id, 'db_error' => $wpdb->last_error));
            return new WP_Error('ksef_page_link_failed', 'Страница создана (ID ' . (int) $post_id . '), но связь с карточкой не записалась. Повторите действие: плагин восстановит связь и не создаст дубль.');
        }
        $cluster_updated = $wpdb->update($wpdb->prefix . 'ksef_clusters', array('status' => $final === 'publish' ? 'published' : 'draft_created', 'updated_at' => $now), array('id' => (int) $row->cluster_id), array('%s', '%s'), array('%d'));
        if ($cluster_updated === false) {
            Korotkov_SEO_Factory::write_log('error', 'cluster_status_update_failed', 'Страница привязана, но статус кластера не обновлён.', array('cluster_id' => (int) $row->cluster_id, 'post_id' => (int) $post_id, 'db_error' => $wpdb->last_error));
        }

        if (!$manual) {
            update_option('ksef_created_' . gmdate('Ymd'), self::created_today() + 1, false);
        }
        Korotkov_SEO_Factory::write_log('info', 'page_created', 'Создана страница WordPress.', array('page_row' => (int) $row->id, 'post_id' => (int) $post_id, 'status' => $final));
        return (int) $post_id;
    }

    /**
     * Записывает принадлежащие плагину метаданные и проверяет результат чтением.
     * Повторный вызов безопасен: значения перезаписываются теми же данными.
     */
    private static function write_managed_meta($post_id, $row, $data) {
        $values = array(
            '_ksef_cluster_id' => (string) (int) $row->cluster_id,
            '_ksef_page_row_id' => (string) (int) $row->id,
            '_ksef_meta_title' => (string) ($data['meta_title'] ?? ''),
            '_ksef_meta_description' => (string) ($data['meta_description'] ?? ''),
        );

        $faq = (string) ($data['faq_html'] ?? '');
        $faq_items = KSEF_Util::parse_faq($faq);
        if ($faq_items) {
            $values['_ksef_faq'] = wp_json_encode($faq_items, JSON_UNESCAPED_UNICODE);
        }

        foreach ($values as $key => $value) {
            update_post_meta($post_id, $key, wp_slash($value));
            $stored = get_post_meta($post_id, $key, true);
            if ((string) $stored !== (string) $value) {
                return new WP_Error('ksef_meta_verification_failed', 'Не удалось подтвердить метаполе ' . $key . '.');
            }
        }

        return true;
    }

    /**
     * Идемпотентно восстанавливает связь существующей WP-страницы с карточкой SEO Factory.
     */
    private static function reconcile_page_link($row, $post_id) {
        global $wpdb;
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'page' || (int) get_post_meta($post_id, '_ksef_page_row_id', true) !== (int) $row->id) {
            return new WP_Error('ksef_orphan_mismatch', 'Найдена страница, но её метаданные не подтверждают связь с этой карточкой.');
        }
        $status = get_post_status($post_id);
        $now = current_time('mysql', true);
        $updated = $wpdb->update($wpdb->prefix . 'ksef_pages', array(
            'wp_page_id' => (int) $post_id,
            'page_slug' => $post->post_name,
            'page_url' => $status === 'publish' ? get_permalink($post_id) : '',
            'status' => $status === 'publish' ? 'published' : 'draft_created',
            'published_at' => $status === 'publish' ? $now : null,
            'updated_at' => $now,
        ), array('id' => (int) $row->id), array('%d', '%s', '%s', '%s', '%s', '%s'), array('%d'));
        if ($updated === false || $updated === 0) {
            Korotkov_SEO_Factory::write_log('critical', 'page_link_recovery_failed', 'Не удалось восстановить связь WP-страницы с карточкой SEO Factory.', array('page_row' => (int) $row->id, 'post_id' => (int) $post_id, 'db_error' => $wpdb->last_error));
            return new WP_Error('ksef_page_link_recovery_failed', 'Страница найдена, но восстановление связи завершилось ошибкой БД. Повторите попытку позже.');
        }
        $cluster_updated = $wpdb->update($wpdb->prefix . 'ksef_clusters', array(
            'status' => $status === 'publish' ? 'published' : 'draft_created',
            'updated_at' => $now,
        ), array('id' => (int) $row->cluster_id), array('%s', '%s'), array('%d'));
        if ($cluster_updated === false) {
            Korotkov_SEO_Factory::write_log('error', 'cluster_status_recovery_failed', 'Связь страницы восстановлена, но статус кластера не обновлён.', array('cluster_id' => (int) $row->cluster_id, 'db_error' => $wpdb->last_error));
        }
        Korotkov_SEO_Factory::write_log('warning', 'page_link_recovered', 'Восстановлена связь ранее созданной страницы без создания дубля.', array('page_row' => (int) $row->id, 'post_id' => (int) $post_id));
        return true;
    }

    /** Публикует ранее созданный черновик. Проверяет, что это именно наша страница. */
    public static function promote($page_id) {
        global $wpdb;
        // Используем ту же блокировку, что и создание: публикация и восстановление
        // связи не должны выполняться параллельно для разных запросов.
        $lock = KSEF_Lock::acquire('publisher', 5);
        if (is_wp_error($lock)) {
            return new WP_Error('ksef_publish_busy', 'Другая операция создания или публикации страниц ещё выполняется. Повторите позже.');
        }

        try {
            $pages = $wpdb->prefix . 'ksef_pages';
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pages} WHERE id = %d", absint($page_id)));
            if (!$row || !(int) $row->wp_page_id) {
                return new WP_Error('ksef_not_draft', 'Карточка не является созданным черновиком.');
            }

            $post = get_post((int) $row->wp_page_id);
            if (!$post || $post->post_type !== 'page' || (int) get_post_meta($post->ID, '_ksef_page_row_id', true) !== (int) $row->id) {
                return new WP_Error('ksef_post_mismatch', 'Страница WordPress не найдена или не связана с карточкой.');
            }

            // Восстановление после сбоя: WP уже опубликовал страницу, но запись
            // статуса/URL в таблице плагина могла не сохраниться.
            if ($post->post_status === 'publish') {
                $recovered = self::reconcile_page_link($row, (int) $post->ID);
                if (is_wp_error($recovered)) return $recovered;
                Korotkov_SEO_Factory::write_log('warning', 'page_publish_reconciled', 'Восстановлена связь уже опубликованной страницы с карточкой.', array('page_row' => (int) $row->id, 'post_id' => (int) $post->ID));
                return (int) $post->ID;
            }

            if ($row->status !== 'draft_created' || $post->post_status !== 'draft') {
                return new WP_Error('ksef_not_draft_status', 'Карточка или страница не находятся в состоянии черновика; публикация не выполнена.');
            }

            $ok = wp_update_post(array('ID' => $post->ID, 'post_status' => 'publish'), true);
            if (is_wp_error($ok)) return $ok;
            if (!$ok || get_post_status($post->ID) !== 'publish') {
                return new WP_Error('ksef_publish_failed', 'WordPress не подтвердил публикацию страницы.');
            }

            // Повторяемая синхронизация вместо отдельного UPDATE: если БД временно
            // недоступна, следующий запуск попадёт в ветку восстановления выше.
            $synced = self::reconcile_page_link($row, (int) $post->ID);
            if (is_wp_error($synced)) {
                return new WP_Error('ksef_publish_sync_failed', 'Страница опубликована, но карточка не синхронизирована. Повторите действие для восстановления связи. ' . $synced->get_error_message());
            }

            Korotkov_SEO_Factory::write_log('info', 'page_published', 'Черновик опубликован и карточка синхронизирована.', array('post_id' => (int) $post->ID));
            return (int) $post->ID;
        } finally {
            KSEF_Lock::release($lock);
        }
    }

    private static function write_compat_meta($post_id, $title, $desc) {
        $title = wp_slash($title);
        $desc = wp_slash($desc);
        if (defined('WPSEO_VERSION')) {
            update_post_meta($post_id, '_yoast_wpseo_title', $title);
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $desc);
        }
        if (defined('RANK_MATH_VERSION')) {
            update_post_meta($post_id, 'rank_math_title', $title);
            update_post_meta($post_id, 'rank_math_description', $desc);
        }
        if (defined('SEOPRESS_VERSION')) {
            update_post_meta($post_id, '_seopress_titles_title', $title);
            update_post_meta($post_id, '_seopress_titles_desc', $desc);
        }
        if (defined('AIOSEO_VERSION')) {
            update_post_meta($post_id, '_aioseo_title', $title);
            update_post_meta($post_id, '_aioseo_description', $desc);
        }
    }

    /** Блок «Читайте также» только из URL, которые реально ведут на страницы этого сайта. */
    private static function related_links_html($raw) {
        $tokens = preg_split('/[\s,;]+/u', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
        if (!$tokens) return '';
        $home_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $items = array();
        foreach (array_slice($tokens, 0, 12) as $token) {
            $url = esc_url_raw($token);
            if (!$url) continue;
            if (strpos($url, '/') === 0) $url = home_url($url);
            if (wp_parse_url($url, PHP_URL_HOST) !== $home_host) continue;
            $pid = url_to_postid($url);
            if (!$pid || get_post_status($pid) !== 'publish') continue;
            $items[$pid] = '<li><a href="' . esc_url(get_permalink($pid)) . '">' . esc_html(get_the_title($pid)) . '</a></li>';
        }
        if (!$items) return '';
        return '<div class="ksef-related"><h2>Читайте также</h2><ul>' . implode('', $items) . '</ul></div>';
    }

    /* ---------- Admin UI ---------- */

    private static function notice($status, $message) {
        set_transient('ksef_publish_notice_' . get_current_user_id(), array(
            'status' => sanitize_key($status),
            'message' => sanitize_text_field($message),
        ), 60);
    }

    public static function render_admin_section() {
        global $wpdb;
        $notice = get_transient('ksef_publish_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('ksef_publish_notice_' . get_current_user_id());
            echo '<div class="notice notice-' . esc_attr($notice['status'] === 'error' ? 'error' : 'success') . ' inline"><p>' . esc_html($notice['message']) . '</p></div>';
        }
        $rows = $wpdb->get_results(
            "SELECT id, cluster_id, wp_page_id, page_title, page_slug, status, page_url FROM {$wpdb->prefix}ksef_pages
             WHERE status IN ('ready_for_publish','blocked_review','draft_created','published')
             ORDER BY id DESC LIMIT 30"
        );
        echo '<p>Создаются только новые страницы типа «Страница». Занятый URL или заголовок останавливает операцию; существующие страницы не изменяются. Перед созданием материал проверяется повторно. Создано автоматически сегодня: ' . (int) self::created_today() . ' из ' . (int) self::per_day_limit() . ' (дневной лимит автоматического режима).</p>';
        if (!$rows) {
            echo '<p>Готовых материалов пока нет.</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Страница</th><th>URL</th><th>Статус</th><th>Действие</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td>' . esc_html($r->page_title) . ' <small>#' . (int) $r->id . '</small></td>';
            echo '<td><code>/' . esc_html($r->page_slug) . '/</code></td><td>' . esc_html($r->status) . '</td><td>';
            if ($r->status === 'ready_for_publish') {
                self::action_form($r->id, 'draft', 'Создать черновик', 'secondary');
                self::action_form($r->id, 'publish', 'Опубликовать сразу', 'primary');
            } elseif ($r->status === 'draft_created' && $r->wp_page_id) {
                echo '<a class="button" href="' . esc_url(get_edit_post_link((int) $r->wp_page_id)) . '">Открыть черновик</a> ';
                self::action_form($r->id, 'promote', 'Опубликовать черновик', 'primary');
            } elseif ($r->status === 'published' && $r->wp_page_id) {
                echo '<a class="button" href="' . esc_url(get_permalink((int) $r->wp_page_id)) . '">Открыть</a>';
            } else {
                echo '—';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px">';
        echo '<input type="hidden" name="action" value="ksef_publish_all">';
        wp_nonce_field('ksef_publish_all');
        submit_button('Создать черновики для всех готовых', 'secondary', 'submit', false);
        echo '</form>';
    }

    private static function action_form($page_id, $mode, $label, $class) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        echo '<input type="hidden" name="action" value="ksef_publish_page">';
        echo '<input type="hidden" name="page_id" value="' . (int) $page_id . '">';
        echo '<input type="hidden" name="mode" value="' . esc_attr($mode) . '">';
        wp_nonce_field('ksef_publish_page');
        submit_button($label, $class . ' small', 'submit', false);
        echo '</form> ';
    }
}
