<?php
/**
 * Knowledge base workflow for Korotkov SEO Factory.
 *
 * Articles are imported as complete CSV records. Auto-publishing is allowed
 * only after content, source provenance and screenshot rights pass validation.
 */
if (!defined('ABSPATH')) { exit; }

final class KSEF_Knowledge_Base {
    const TABLE = 'ksef_knowledge';
    const MIN_WORDS = 700;
    const DAILY_LIMIT = 3;

    public static function register_hooks() {
        add_action('admin_post_ksef_kb_seed', array(__CLASS__, 'handle_seed'));
        add_action('admin_post_ksef_kb_export', array(__CLASS__, 'handle_export'));
        add_action('admin_post_ksef_kb_import', array(__CLASS__, 'handle_import'));
        add_action('ksef_kb_publish_tick', array(__CLASS__, 'publish_one'));
        if (!wp_next_scheduled('ksef_kb_publish_tick')) {
            wp_schedule_event(time() + 900, 'ksef_five_minutes', 'ksef_kb_publish_tick');
        }
    }

    public static function create_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            topic_key varchar(190) NOT NULL,
            title text NOT NULL,
            slug varchar(190) NOT NULL,
            category varchar(100) NOT NULL DEFAULT '',
            tested_versions varchar(255) NOT NULL DEFAULT '',
            verified_on date NULL,
            status varchar(24) NOT NULL DEFAULT 'planned',
            meta_title varchar(255) NOT NULL DEFAULT '',
            meta_description text NULL,
            article_html longtext NULL,
            faq_html longtext NULL,
            sources_json longtext NULL,
            screenshots_json longtext NULL,
            wp_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            last_error text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            published_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY topic_key (topic_key),
            KEY status (status),
            KEY wp_post_id (wp_post_id)
        ) {$charset};";
        dbDelta($sql);
    }

    private static function topics() {
        return array(
            array('windows-rdp-win10-win11', 'Как включить удалённый рабочий стол в Windows 11 и Windows 10', 'Windows'),
            array('connect-remote-windows-pc', 'Как подключиться к удалённому компьютеру Windows', 'Windows'),
            array('windows-network-sharing-folders', 'Как настроить общий доступ к папкам в Windows 11 и 10', 'Windows'),
            array('connect-network-printer-windows', 'Как подключить сетевой принтер в Windows 11 и 10', 'Windows'),
            array('vpn-windows-11-setup', 'Как настроить VPN в Windows 11: встроенное подключение и клиенты', 'Сети и интернет'),
            array('install-windows-11-usb', 'Как установить Windows 11 с флешки: пошаговая инструкция', 'Windows'),
            array('migrate-windows-to-ssd', 'Как перенести Windows на SSD без переустановки системы', 'Компьютерное оборудование'),
            array('local-network-windows-pcs', 'Как настроить локальную сеть между компьютерами Windows', 'Сети и интернет'),
            array('fix-dns-windows', 'Как исправить ошибку DNS в Windows 10 и 11', 'Ошибки и решения'),
            array('rdp-windows-server-2022-2025', 'Как настроить RDP на Windows Server 2022 и 2025', 'Серверы и VPS'),
            array('windows-backup-setup', 'Как настроить резервное копирование Windows 11', 'Windows'),
            array('check-pc-specifications', 'Как узнать характеристики компьютера в Windows', 'Компьютерное оборудование'),
            array('update-windows-drivers', 'Как обновить драйверы в Windows безопасными способами', 'Windows'),
            array('windows-startup-apps', 'Как настроить автозагрузку программ в Windows 10 и 11', 'Windows'),
            array('install-configure-7zip', 'Как установить и настроить 7-Zip в Windows', 'Программы'),
            array('outlook-mail-setup-versions', 'Как настроить почту в Outlook: различия классической и новой версии', 'Программы'),
            array('connect-second-monitor', 'Как подключить второй монитор к компьютеру и настроить изображение', 'Компьютерное оборудование'),
            array('tp-link-router-setup', 'Как настроить роутер TP-Link: вход, Wi-Fi и безопасность', 'Сети и интернет'),
            array('connect-vps-rdp', 'Как подключиться к VPS на Windows Server по RDP', 'Серверы и VPS'),
            array('remote-work-employees-setup', 'Как организовать удалённую работу сотрудников: доступы, сеть и безопасность', 'Серверы и VPS'),
        );
    }

    public static function handle_seed() {
        self::authorize('ksef_kb_seed');
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $added = 0;
        foreach (self::topics() as $topic) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE topic_key = %s", $topic[0]));
            if ($exists) continue;
            $now = current_time('mysql', true);
            $ok = $wpdb->insert($table, array(
                'topic_key' => $topic[0],
                'title' => $topic[1],
                'slug' => $topic[0],
                'category' => $topic[2],
                'status' => 'planned',
                'created_at' => $now,
                'updated_at' => $now,
            ), array('%s','%s','%s','%s','%s','%s','%s'));
            if ($ok) $added++;
        }
        Korotkov_SEO_Factory::write_log('info', 'kb_seeded', 'Начальный план базы знаний подготовлен.', array('added' => $added));
        self::redirect_notice('success', 'Добавлено тем: ' . $added . '. Уже существующие темы не дублировались.');
    }

    public static function handle_export() {
        self::authorize('ksef_kb_export');
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id,title,slug,category FROM {$wpdb->prefix}" . self::TABLE . " WHERE status IN ('planned','needs_content','blocked') ORDER BY id ASC", ARRAY_A);
        if (!$rows) wp_die('Нет тем для экспорта.');
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=ipkorotkov-knowledge-base.csv');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array('kb_id','title','slug','category','tested_versions','verified_on','meta_title','meta_description','article_html','faq_html','sources_json','screenshots_json'));
        foreach ($rows as $row) {
            fputcsv($out, array((int)$row['id'], $row['title'], $row['slug'], $row['category'], '', '', '', '', '[]', '[]'));
        }
        fclose($out);
        exit;
    }

    public static function handle_import() {
        self::authorize('ksef_kb_import');
        if (empty($_FILES['ksef_kb_csv']['tmp_name']) || !is_uploaded_file($_FILES['ksef_kb_csv']['tmp_name'])) {
            self::redirect_notice('error', 'CSV-файл не загружен.');
        }
        if ((int) $_FILES['ksef_kb_csv']['size'] > 8 * MB_IN_BYTES) {
            self::redirect_notice('error', 'Размер CSV ограничен 8 МБ.');
        }
        $fh = fopen($_FILES['ksef_kb_csv']['tmp_name'], 'r');
        if (!$fh) self::redirect_notice('error', 'Не удалось прочитать CSV.');
        $headers = fgetcsv($fh, 0, ',');
        if (!$headers) { fclose($fh); self::redirect_notice('error', 'CSV пустой.'); }
        $headers = array_map('trim', $headers);
        $headers[0] = preg_replace('/^\\xEF\\xBB\\xBF/', '', $headers[0]);
        $required = array('kb_id','title','slug','category','tested_versions','verified_on','meta_title','meta_description','article_html','faq_html','sources_json','screenshots_json');
        if ($headers !== $required) { fclose($fh); self::redirect_notice('error', 'Неверные заголовки CSV. Сначала скачайте актуальный шаблон из панели.'); }

        $updated = 0; $blocked = 0; $errors = array();
        while (($values = fgetcsv($fh, 0, ',')) !== false) {
            if (count($values) !== count($required)) { $blocked++; continue; }
            $data = array_combine($required, $values);
            $id = absint($data['kb_id']);
            $row = self::get_row($id);
            if (!$row || (int)$row->wp_post_id > 0) { $blocked++; continue; }
            $clean = self::sanitize_import($data);
            $validation = self::validate($clean);
            if (is_wp_error($validation)) {
                self::save_blocked($id, $clean, $validation->get_error_message());
                $blocked++;
                $errors[] = '#' . $id . ': ' . $validation->get_error_message();
                continue;
            }
            global $wpdb;
            $wpdb->update($wpdb->prefix . self::TABLE, array(
                'title' => $clean['title'], 'slug' => $clean['slug'], 'category' => $clean['category'],
                'tested_versions' => $clean['tested_versions'], 'verified_on' => $clean['verified_on'],
                'meta_title' => $clean['meta_title'], 'meta_description' => $clean['meta_description'],
                'article_html' => $clean['article_html'], 'faq_html' => $clean['faq_html'],
                'sources_json' => wp_json_encode($clean['sources'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'screenshots_json' => wp_json_encode($clean['screenshots'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'ready_to_publish', 'last_error' => null, 'updated_at' => current_time('mysql', true),
            ), array('id' => $id));
            $updated++;
        }
        fclose($fh);
        Korotkov_SEO_Factory::write_log('info', 'kb_imported', 'Импорт материалов базы знаний завершён.', array('ready' => $updated, 'blocked' => $blocked));
        $message = 'Готовы к автоматической публикации: ' . $updated . '. Заблокировано: ' . $blocked . '.';
        if ($errors) $message .= ' Пример ошибки: ' . $errors[0];
        self::redirect_notice($updated ? 'success' : 'error', $message);
    }

    private static function sanitize_import($d) {
        $sources = json_decode((string)$d['sources_json'], true);
        $screens = json_decode((string)$d['screenshots_json'], true);
        return array(
            'title' => sanitize_text_field($d['title']),
            'slug' => sanitize_title($d['slug']),
            'category' => sanitize_text_field($d['category']),
            'tested_versions' => sanitize_text_field($d['tested_versions']),
            'verified_on' => sanitize_text_field($d['verified_on']),
            'meta_title' => sanitize_text_field($d['meta_title']),
            'meta_description' => sanitize_text_field($d['meta_description']),
            'article_html' => wp_kses_post($d['article_html']),
            'faq_html' => wp_kses_post($d['faq_html']),
            'sources' => is_array($sources) ? $sources : null,
            'screenshots' => is_array($screens) ? $screens : null,
        );
    }

    private static function validate($d) {
        foreach (array('title','slug','category','tested_versions','verified_on','meta_title','meta_description','article_html') as $key) {
            if (empty($d[$key])) return new WP_Error('kb_missing_' . $key, 'Не заполнено обязательное поле: ' . $key);
        }
        preg_match_all('/[\p{L}\p{N}]+/u', wp_strip_all_tags($d['article_html'] . ' ' . $d['faq_html']), $m);
        if (count($m[0]) < self::MIN_WORDS) return new WP_Error('kb_too_short', 'Статья короче ' . self::MIN_WORDS . ' слов.');
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $d['verified_on']) || !checkdate((int)substr($d['verified_on'],5,2), (int)substr($d['verified_on'],8,2), (int)substr($d['verified_on'],0,4))) return new WP_Error('kb_verified_date_invalid', 'Укажите дату проверки в формате ГГГГ-ММ-ДД.');
        if (!is_array($d['sources']) || count($d['sources']) < 2) return new WP_Error('kb_sources_missing', 'Нужно указать минимум два источника в sources_json.');
        if (!is_array($d['screenshots']) || count($d['screenshots']) < 1) return new WP_Error('kb_screenshots_missing', 'Для mixed-формата нужен минимум один скриншот с подтверждённым происхождением.');
        foreach ($d['sources'] as $source) {
            if (empty($source['url']) || empty($source['title'])) return new WP_Error('kb_source_invalid', 'У каждого источника должны быть title и url.');
            $url = esc_url_raw($source['url']);
            if (!$url || !in_array(wp_parse_url($url, PHP_URL_SCHEME), array('http','https'), true)) return new WP_Error('kb_source_url_invalid', 'Источник должен иметь корректный HTTP(S)-адрес.');
        }
        foreach ($d['screenshots'] as $shot) {
            $media_id = absint($shot['media_id'] ?? 0);
            $rights = sanitize_key($shot['rights'] ?? '');
            if (!$media_id || get_post_type($media_id) !== 'attachment') return new WP_Error('kb_screenshot_media_missing', 'Каждый скриншот должен быть загружен в медиатеку WordPress.');
            if (!in_array($rights, array('own','recreated','permission','license','public_domain'), true)) return new WP_Error('kb_screenshot_rights_missing', 'У скриншота не указано допустимое основание использования.');
            if (in_array($rights, array('permission','license','public_domain'), true) && empty($shot['origin_url'])) return new WP_Error('kb_screenshot_origin_missing', 'Для стороннего скриншота укажите исходный URL и основание использования.');
            if (empty($shot['alt'])) return new WP_Error('kb_screenshot_alt_missing', 'Для каждого скриншота заполните alt.');
        }
        return true;
    }

    private static function save_blocked($id, $d, $error) {
        global $wpdb;
        $wpdb->update($wpdb->prefix . self::TABLE, array(
            'title' => $d['title'], 'tested_versions' => $d['tested_versions'], 'verified_on' => $d['verified_on'], 'meta_title' => $d['meta_title'], 'meta_description' => $d['meta_description'],
            'article_html' => $d['article_html'], 'faq_html' => $d['faq_html'],
            'status' => 'blocked', 'last_error' => sanitize_text_field($error), 'updated_at' => current_time('mysql', true),
        ), array('id' => $id));
    }

    public static function publish_one() {
        if (get_transient('ksef_kb_publish_lock')) return;
        $day_key = 'ksef_kb_published_' . wp_date('Ymd');
        if (absint(get_option($day_key, 0)) >= self::DAILY_LIMIT) return;
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $row = $wpdb->get_row("SELECT * FROM {$table} WHERE status = 'ready_to_publish' AND wp_post_id = 0 ORDER BY id ASC LIMIT 1");
        if (!$row) return;
        set_transient('ksef_kb_publish_lock', 1, 90);
        $result = self::publish_row($row);
        delete_transient('ksef_kb_publish_lock');
        if (is_wp_error($result)) {
            $wpdb->update($table, array('status' => 'blocked', 'last_error' => $result->get_error_message(), 'updated_at' => current_time('mysql', true)), array('id' => (int)$row->id));
            Korotkov_SEO_Factory::write_log('warning', 'kb_publish_blocked', 'Автопубликация остановлена проверкой.', array('kb_id' => (int)$row->id, 'reason' => $result->get_error_message()));
        }
    }

    private static function publish_row($row) {
        global $wpdb;
        $data = array(
            'title' => $row->title, 'slug' => $row->slug, 'category' => $row->category,
            'tested_versions' => $row->tested_versions, 'verified_on' => $row->verified_on,
            'meta_title' => $row->meta_title, 'meta_description' => $row->meta_description,
            'article_html' => $row->article_html, 'faq_html' => $row->faq_html,
            'sources' => json_decode($row->sources_json, true), 'screenshots' => json_decode($row->screenshots_json, true),
        );
        $valid = self::validate($data);
        if (is_wp_error($valid)) return $valid;
        $taken = get_page_by_path($row->slug, OBJECT, array('post','page'));
        if ($taken) return new WP_Error('kb_slug_taken', 'URL уже занят существующей записью #' . $taken->ID . '.');
        $same = get_page_by_title($row->title, OBJECT, array('post','page'));
        if ($same) return new WP_Error('kb_title_taken', 'Запись с таким заголовком уже существует #' . $same->ID . '.');

        $content = '<p><strong>Проверено:</strong> ' . esc_html($row->verified_on) . '. <strong>Версии:</strong> ' . esc_html($row->tested_versions) . '.</p>' . "\\n" . $row->article_html;
        foreach ($data['screenshots'] as $shot) {
            $image = wp_get_attachment_image(absint($shot['media_id']), 'large', false, array('alt' => $shot['alt'], 'loading' => 'lazy'));
            if ($image) {
                $caption = !empty($shot['caption']) ? '<figcaption>' . esc_html($shot['caption']) . '</figcaption>' : '';
                $content .= "\n<figure class=\"wp-block-image size-large\">" . $image . $caption . "</figure>\n";
            }
        }
        if (trim(wp_strip_all_tags($row->faq_html)) !== '') $content .= "\n<h2>Частые вопросы</h2>\n" . $row->faq_html;
        $source_links = array();
        foreach ($data['sources'] as $source) {
            $source_links[] = '<li><a href="' . esc_url($source['url']) . '" rel="nofollow noopener" target="_blank">' . esc_html($source['title']) . '</a></li>';
        }
        $content .= "\n<h2>Источники и дополнительные материалы</h2><ul>" . implode('', $source_links) . "</ul>";
        $term = term_exists($row->category, 'category');
        if (!$term) $term = wp_insert_term($row->category, 'category');
        $category_ids = (!is_wp_error($term) && $term) ? array((int)(is_array($term) ? $term['term_id'] : $term)) : array();

        $post_id = wp_insert_post(wp_slash(array(
            'post_type' => 'post', 'post_status' => 'publish',
            'post_title' => $row->title, 'post_name' => $row->slug,
            'post_content' => $content, 'post_excerpt' => $row->meta_description,
            'post_category' => $category_ids, 'comment_status' => 'closed', 'ping_status' => 'closed',
        )), true);
        if (is_wp_error($post_id)) return $post_id;
        update_post_meta($post_id, '_ksef_kb_id', (int)$row->id);
        update_post_meta($post_id, '_ksef_meta_title', wp_slash($row->meta_title));
        update_post_meta($post_id, '_ksef_meta_description', wp_slash($row->meta_description));
        update_post_meta($post_id, '_ksef_kb_sources', wp_slash(wp_json_encode($data['sources'], JSON_UNESCAPED_UNICODE)));
        update_post_meta($post_id, '_ksef_kb_screenshots', wp_slash(wp_json_encode($data['screenshots'], JSON_UNESCAPED_UNICODE)));
        $faq_items = KSEF_Util::parse_faq($row->faq_html);
        if ($faq_items) update_post_meta($post_id, '_ksef_faq', wp_slash(wp_json_encode($faq_items, JSON_UNESCAPED_UNICODE)));
        if (defined('WPSEO_VERSION')) {
            update_post_meta($post_id, '_yoast_wpseo_title', wp_slash($row->meta_title));
            update_post_meta($post_id, '_yoast_wpseo_metadesc', wp_slash($row->meta_description));
        }
        if (defined('RANK_MATH_VERSION')) {
            update_post_meta($post_id, 'rank_math_title', wp_slash($row->meta_title));
            update_post_meta($post_id, 'rank_math_description', wp_slash($row->meta_description));
        }
        $wpdb->update($wpdb->prefix . self::TABLE, array(
            'wp_post_id' => (int)$post_id, 'status' => 'published',
            'published_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true), 'last_error' => null,
        ), array('id' => (int)$row->id));
        $day_key = 'ksef_kb_published_' . wp_date('Ymd');
        update_option($day_key, absint(get_option($day_key, 0)) + 1, false);
        Korotkov_SEO_Factory::write_log('info', 'kb_article_published', 'Опубликована статья базы знаний.', array('kb_id' => (int)$row->id, 'post_id' => (int)$post_id));
        return (int)$post_id;
    }

    private static function get_row($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d", $id));
    }

    private static function authorize($nonce) {
        if (!current_user_can('manage_options')) wp_die('Недостаточно прав.');
        check_admin_referer($nonce);
    }

    private static function redirect_notice($status, $message) {
        set_transient('ksef_kb_notice_' . get_current_user_id(), array('status' => $status, 'message' => $message), 90);
        wp_safe_redirect(admin_url('admin.php?page=korotkov-seo-factory#knowledge-base'));
        exit;
    }

    public static function render_admin_section() {
        global $wpdb;
        $notice = get_transient('ksef_kb_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('ksef_kb_notice_' . get_current_user_id());
            echo '<div class="notice notice-' . esc_attr($notice['status'] === 'error' ? 'error' : 'success') . ' inline"><p>' . esc_html($notice['message']) . '</p></div>';
        }
        $table = $wpdb->prefix . self::TABLE;
        $counts = $wpdb->get_results("SELECT status, COUNT(*) total FROM {$table} GROUP BY status");
        $rows = $wpdb->get_results("SELECT id,title,category,status,wp_post_id,last_error FROM {$table} ORDER BY id ASC");
        echo '<div id="knowledge-base"><p>План первого этапа: 20 материалов. Импорт принимает только полный материал с источниками и загруженными в медиатеку скриншотами. Статьи автоматически публикуются после проверки; максимум ' . self::DAILY_LIMIT . ' в сутки. Существующие URL и заголовки не перезаписываются.</p>';
        echo '<p>';
        echo '<form style="display:inline-block;margin-right:8px" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ksef_kb_seed">';
        wp_nonce_field('ksef_kb_seed'); submit_button('Подготовить 20 тем', 'secondary', 'submit', false); echo '</form>';
        echo '<a class="button button-secondary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=ksef_kb_export'), 'ksef_kb_export')) . '">Скачать CSV-задание</a></p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ksef_kb_import">';
        wp_nonce_field('ksef_kb_import'); echo '<input type="file" name="ksef_kb_csv" accept=".csv,text/csv" required> ';
        submit_button('Импортировать готовые статьи и включить автопубликацию', 'primary', 'submit', false);
        echo '<p class="description">CSV содержит article_html, FAQ, sources_json и screenshots_json. Скриншоты должны быть предварительно загружены в медиатеку WordPress. Чужие изображения допускаются только при наличии разрешения, лицензии или статуса общественного достояния с указанием исходного URL. Скриншоты интерфейсов, созданные самостоятельно или воссозданные, отмечайте own или recreated.</p></form>';
        if ($counts) {
            echo '<p><strong>Состояние:</strong> ';
            foreach ($counts as $c) echo esc_html($c->status . ': ' . (int)$c->total . ' · ');
            echo '</p>';
        }
        if ($rows) {
            echo '<table class="widefat striped"><thead><tr><th>#</th><th>Материал</th><th>Раздел</th><th>Статус</th><th>Ссылка / причина</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                echo '<tr><td>' . (int)$r->id . '</td><td>' . esc_html($r->title) . '</td><td>' . esc_html($r->category) . '</td><td>' . esc_html($r->status) . '</td><td>';
                if ($r->wp_post_id) echo '<a href="' . esc_url(get_permalink($r->wp_post_id)) . '" target="_blank" rel="noopener">Открыть</a>';
                elseif ($r->last_error) echo esc_html($r->last_error);
                else echo '—';
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>Список тем ещё не создан. Нажмите «Подготовить 20 тем».</p>';
        }
        echo '</div>';
    }
}
