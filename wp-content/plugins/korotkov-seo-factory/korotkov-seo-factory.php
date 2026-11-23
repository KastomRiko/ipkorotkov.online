<?php
/**
 * Plugin Name: Korotkov SEO Factory
 * Description: Основа системы исследования поисковых запросов, кластеризации и контролируемой SEO-публикации.
 * Version: 0.7.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Korotkov
 * Text Domain: korotkov-seo-factory
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KSEF_VERSION', '0.7.0');
require_once __DIR__ . '/includes/class-yandex-client.php';
require_once __DIR__ . '/includes/class-search-client.php';
require_once __DIR__ . '/includes/class-queue.php';
require_once __DIR__ . '/includes/class-qwen-exchange.php';
require_once __DIR__ . '/includes/class-clusterer.php';
define('KSEF_OPTION', 'ksef_settings');

final class Korotkov_SEO_Factory {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('ksef_daily_tick', array($this, 'daily_tick'));
        add_action('ksef_queue_tick', array('KSEF_Queue', 'process_one'));
        add_filter('cron_schedules', array($this, 'cron_schedules'));
        add_action('admin_post_ksef_enqueue_phrases', array($this, 'handle_enqueue_phrases'));
        KSEF_Qwen_Exchange::register_hooks();
        KSEF_Clusterer::register_hooks();
    }

    public static function activate() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'ksef_';

        $sql = array();
        $sql[] = "CREATE TABLE {$prefix}jobs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_type varchar(40) NOT NULL DEFAULT 'research',
            status varchar(24) NOT NULL DEFAULT 'new',
            payload longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY job_type (job_type)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}keywords (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source_phrase text NOT NULL,
            normalized_phrase varchar(500) NOT NULL DEFAULT '',
            region varchar(100) NOT NULL DEFAULT 'Россия',
            intent varchar(40) NOT NULL DEFAULT '',
            cluster_id bigint(20) unsigned NOT NULL DEFAULT 0,
            wordstat_data longtext NULL,
            researched_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY job_id (job_id),
            KEY cluster_id (cluster_id),
            KEY normalized_phrase (normalized_phrase(191))
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}clusters (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            label varchar(500) NOT NULL DEFAULT '',
            status varchar(24) NOT NULL DEFAULT 'new',
            competition_score decimal(5,2) NULL,
            commercial_score decimal(5,2) NULL,
            qwen_payload longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}serp_results (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            keyword_id bigint(20) unsigned NOT NULL DEFAULT 0,
            result_url text NOT NULL,
            result_title text NULL,
            result_snippet longtext NULL,
            position_num smallint(5) unsigned NOT NULL DEFAULT 0,
            checked_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY keyword_id (keyword_id),
            KEY checked_at (checked_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}pages (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            cluster_id bigint(20) unsigned NOT NULL DEFAULT 0,
            wp_page_id bigint(20) unsigned NOT NULL DEFAULT 0,
            page_title text NOT NULL,
            page_slug varchar(200) NOT NULL DEFAULT '',
            page_url text NULL,
            status varchar(24) NOT NULL DEFAULT 'prepared',
            published_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY cluster_id (cluster_id),
            KEY wp_page_id (wp_page_id),
            KEY status (status)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}qwen_imports (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            keyword_id bigint(20) unsigned NOT NULL DEFAULT 0,
            payload longtext NOT NULL,
            status varchar(24) NOT NULL DEFAULT 'draft_review',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY keyword_id (keyword_id),
            KEY status (status)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}api_usage (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            service varchar(32) NOT NULL,
            method varchar(64) NOT NULL,
            request_count int(10) unsigned NOT NULL DEFAULT 1,
            estimated_cost decimal(12,6) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY service (service),
            KEY created_at (created_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}cache (
            cache_key char(64) NOT NULL,
            response_json longtext NOT NULL,
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY  (cache_key),
            KEY expires_at (expires_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}logs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(16) NOT NULL DEFAULT 'info',
            event varchar(100) NOT NULL DEFAULT '',
            message text NOT NULL,
            context longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY level (level),
            KEY event (event),
            KEY created_at (created_at)
        ) {$charset};";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        if (!get_option(KSEF_OPTION)) {
            add_option(KSEF_OPTION, self::default_settings(), '', false);
        }

        if (!wp_next_scheduled('ksef_queue_tick')) {
            wp_schedule_event(time() + 600, 'ksef_five_minutes', 'ksef_queue_tick');
        }

        if (!wp_next_scheduled('ksef_daily_tick')) {
            wp_schedule_event(time() + 300, 'daily', 'ksef_daily_tick');
        }

        self::write_log('info', 'plugin_activated', 'Плагин активирован. Внешние API-запросы пока отключены.');
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('ksef_daily_tick');
        wp_clear_scheduled_hook('ksef_queue_tick');
    }

    public static function default_settings() {
        return array(
            'region' => 'Россия',
            'daily_wordstat_limit' => 20,
            'wordstat_interval' => 5,
            'search_interval' => 2,
            'budget_total' => 4000,
            'budget_stop' => 3800,
            'automation_enabled' => 0,
            'folder_id' => '',
            'api_key' => '',
        );
    }

    public static function write_log($level, $event, $message, $context = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'ksef_logs';
        $wpdb->insert(
            $table,
            array(
                'level' => sanitize_key($level),
                'event' => sanitize_key($event),
                'message' => sanitize_text_field($message),
                'context' => wp_json_encode($context),
                'created_at' => current_time('mysql', true),
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
    }

    public function cron_schedules($schedules) {
        if (!isset($schedules['ksef_five_minutes'])) {
            $schedules['ksef_five_minutes'] = array('interval' => 300, 'display' => 'Korotkov SEO Factory: каждые 5 минут');
        }
        return $schedules;
    }

    public function handle_enqueue_phrases() {
        if (!current_user_can('manage_options')) wp_die('Недостаточно прав.');
        check_admin_referer('ksef_enqueue_phrases');
        $text = isset($_POST['ksef_phrases']) ? wp_unslash($_POST['ksef_phrases']) : '';
        $result = KSEF_Queue::enqueue_phrases($text);
        if (is_wp_error($result)) {
            self::write_log('warning', 'queue_enqueue_failed', $result->get_error_message());
            $redirect = add_query_arg('ksef_queue_result', 'error', admin_url('admin.php?page=korotkov-seo-factory'));
        } else {
            self::write_log('info', 'queue_enqueued', 'Фразы добавлены в очередь.', $result);
            $redirect = add_query_arg(array('ksef_queue_result' => 'added', 'ksef_queue_count' => absint($result['added'])), admin_url('admin.php?page=korotkov-seo-factory'));
        }
        wp_safe_redirect($redirect);
        exit;
    }

    public function register_settings() {
        register_setting(
            'ksef_settings_group',
            KSEF_OPTION,
            array('sanitize_callback' => array($this, 'sanitize_settings'))
        );
    }

    public function sanitize_settings($input) {
        $old = get_option(KSEF_OPTION, self::default_settings());
        $clean = self::default_settings();

        $clean['region'] = isset($input['region']) ? sanitize_text_field($input['region']) : 'Россия';
        $clean['daily_wordstat_limit'] = isset($input['daily_wordstat_limit']) ? min(20, max(0, absint($input['daily_wordstat_limit']))) : 20;
        $clean['wordstat_interval'] = isset($input['wordstat_interval']) ? min(60, max(5, absint($input['wordstat_interval']))) : 5;
        $clean['search_interval'] = isset($input['search_interval']) ? min(60, max(2, absint($input['search_interval']))) : 2;
        $clean['budget_total'] = 4000;
        $clean['budget_stop'] = 3800;
        $clean['automation_enabled'] = !empty($input['automation_enabled']) ? 1 : 0;
        $clean['folder_id'] = isset($input['folder_id']) ? sanitize_text_field($input['folder_id']) : '';
        $new_key = isset($input['api_key']) ? trim((string) $input['api_key']) : '';
        $clean['api_key'] = ($new_key !== '') ? sanitize_text_field($new_key) : (isset($old['api_key']) ? $old['api_key'] : '');

        self::write_log('info', 'settings_updated', 'Настройки плагина обновлены. Лимиты проверены.');
        return $clean;
    }

    public function admin_menu() {
        add_menu_page(
            'Korotkov SEO Factory',
            'SEO Factory',
            'manage_options',
            'korotkov-seo-factory',
            array($this, 'render_page'),
            'dashicons-chart-area',
            58
        );
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = get_option(KSEF_OPTION, self::default_settings());
        global $wpdb;
        $logs_table = $wpdb->prefix . 'ksef_logs';
        $last_logs = $wpdb->get_results("SELECT level, event, message, created_at FROM {$logs_table} ORDER BY id DESC LIMIT 8");
        $jobs_table = $wpdb->prefix . 'ksef_jobs';
        $queue_counts = $wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$jobs_table} WHERE job_type = 'serp_search' GROUP BY status");
        ?>
        <div class="wrap">
            <h1>Korotkov SEO Factory</h1>
            <p>Управление исследованием запросов и ограничениями API. Регион исследования — вся Россия.</p>
            <div class="notice notice-warning inline"><p><strong>Безопасный режим.</strong> Клиент Wordstat добавлен, но сам по себе не запускается. Запросы выполняются только при вызове метода из очереди; автоматический запуск и публикация пока отключены.</p></div>
            <form method="post" action="options.php">
                <?php settings_fields('ksef_settings_group'); ?>
                <h2>Исследование и ограничения</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Регион</th>
                        <td><input type="text" class="regular-text" value="Вся Россия" disabled><p class="description">Фиксирован для текущего проекта.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Внутренний лимит Wordstat в сутки</th>
                        <td><input type="number" min="0" max="20" name="<?php echo esc_attr(KSEF_OPTION); ?>[daily_wordstat_limit]" value="<?php echo esc_attr($settings['daily_wordstat_limit']); ?>"> <p class="description">Не более 20 обращений в сутки суммарно по всем методам. Это наш внутренний предохранитель, а не квота Яндекса.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Интервал Wordstat, секунд</th>
                        <td><input type="number" min="5" max="60" name="<?php echo esc_attr(KSEF_OPTION); ?>[wordstat_interval]" value="<?php echo esc_attr($settings['wordstat_interval']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Интервал Search API, секунд</th>
                        <td><input type="number" min="2" max="60" name="<?php echo esc_attr(KSEF_OPTION); ?>[search_interval]" value="<?php echo esc_attr($settings['search_interval']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Общий бюджет</th>
                        <td><strong>4 000 ₽</strong><p class="description">Бюджет зафиксирован. Перед включением платных запросов потребуется проверить покрытие бонусами каждого метода и фактический тариф.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Порог остановки</th>
                        <td><strong>3 800 ₽</strong><p class="description">Оставшиеся 200 ₽ — резерв. Порог будет применяться после подключения подтверждённого учёта расходов.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Folder ID Yandex Cloud</th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(KSEF_OPTION); ?>[folder_id]" value="<?php echo esc_attr($settings['folder_id']); ?>" autocomplete="off"><p class="description">Можно задать константой KSEF_YANDEX_FOLDER_ID в wp-config.php.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">API-ключ сервисного аккаунта</th>
                        <td><code>KSEF_YANDEX_API_KEY</code> в wp-config.php<p class="description">Ключ читается только из константы wp-config.php и не сохраняется в настройках WordPress. Не вставляйте его в чат или GitHub.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Автоматическое исследование</th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(KSEF_OPTION); ?>[automation_enabled]" value="1" <?php checked(!empty($settings['automation_enabled'])); ?>> Разрешить обработку очереди Search API</label><p class="description">По умолчанию выключено. При включении обрабатывается не более одного задания за 5 минут. Для реальных платных запросов дополнительно требуется KSEF_BILLING_VERIFIED=true в wp-config.php после проверки тарифа и покрытия бонусами. Wordstat и публикация этим переключателем не запускаются.</p></td>
                    </tr>
                </table>
                <?php submit_button('Сохранить настройки'); ?>
            </form>
            <hr>
            <h2>Очередь исследования</h2>
            <p>Поставь до 30 фраз в одну партию. Разделители: новая строка, запятая или точка с запятой. Повторные фразы не добавляются.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ksef_enqueue_phrases">
                <?php wp_nonce_field('ksef_enqueue_phrases'); ?>
                <textarea name="ksef_phrases" rows="6" class="large-text" placeholder="настройка VPS для VPN&#10;администрирование Windows Server&#10;настройка терминального сервера"></textarea>
                <?php submit_button('Добавить фразы в очередь', 'secondary', 'submit', false); ?>
            </form>
            <?php if (isset($_GET['ksef_queue_result'])) : ?>
                <div class="notice notice-info inline"><p><?php echo esc_html($_GET['ksef_queue_result'] === 'added' ? 'Добавлено новых фраз: ' . absint($_GET['ksef_queue_count'] ?? 0) : 'Не удалось добавить фразы. Проверь журнал.'); ?></p></div>
            <?php endif; ?>
            <p><strong>Состояние очереди:</strong> <?php if ($queue_counts) : foreach ($queue_counts as $count) : echo esc_html($count->status . ': ' . $count->total . ' · '); endforeach; else : ?>пока пуста<?php endif; ?></p>
            <hr>
            <h2>Кластеризация поисковых фраз</h2>
            <p>Система сравнивает URL органической выдачи. Фразы объединяются, если совпадают минимум два URL и доля пересечения составляет не менее 20%. Для кластеризации используются только фразы с собранной выдачей; внешние API-запросы не выполняются.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ksef_cluster_keywords">
                <?php wp_nonce_field('ksef_cluster_keywords'); ?>
                <?php submit_button('Сгруппировать исследованные фразы', 'secondary', 'submit', false); ?>
            </form>
            <?php
            $cluster_notice = get_transient('ksef_cluster_notice_' . get_current_user_id());
            if ($cluster_notice) :
                delete_transient('ksef_cluster_notice_' . get_current_user_id());
            ?>
                <div class="notice notice-<?php echo esc_attr($cluster_notice['status'] === 'error' ? 'error' : 'success'); ?> inline"><p><?php echo esc_html($cluster_notice['message']); ?></p></div>
            <?php endif; ?>
            <?php
            $cluster_rows = $wpdb->get_results(
                "SELECT c.id, c.label, c.status, COUNT(DISTINCT k.id) AS keyword_count,
                        p.page_title, p.status AS page_status
                 FROM {$wpdb->prefix}ksef_clusters c
                 LEFT JOIN {$wpdb->prefix}ksef_keywords k ON k.cluster_id = c.id
                 LEFT JOIN {$wpdb->prefix}ksef_pages p ON p.cluster_id = c.id
                 GROUP BY c.id, c.label, c.status, p.page_title, p.status
                 ORDER BY c.id DESC LIMIT 20"
            );
            ?>
            <h3>Кластеры и заготовки страниц</h3>
            <?php if ($cluster_rows) : ?>
                <table class="widefat striped">
                    <thead><tr><th>Кластер</th><th>Фраз</th><th>Заголовок страницы</th><th>Статус</th></tr></thead>
                    <tbody>
                    <?php foreach ($cluster_rows as $cluster_row) : ?>
                        <tr>
                            <td><?php echo esc_html($cluster_row->label); ?> <small>#<?php echo absint($cluster_row->id); ?></small></td>
                            <td><?php echo absint($cluster_row->keyword_count); ?></td>
                            <td><?php echo esc_html($cluster_row->page_title); ?></td>
                            <td><?php echo esc_html($cluster_row->page_status ? $cluster_row->page_status : $cluster_row->status); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p>Кластеры ещё не созданы.</p>
            <?php endif; ?>
            <hr>
            <h2>Ручной обмен с Qwen</h2>
            <p>Экспортирует до 30 кластеров: связанные поисковые фразы и собранные URL конкурентов. В Qwen нужно подготовить полноценную статью, FAQ и рекомендации внутренних ссылок, затем импортировать CSV обратно. Qwen API и автоматизация браузера не используются.</p>
            <p><strong>Порядок работы:</strong> экспорт CSV → обработка в chat.qwen.ai → сохранение результата в CSV с теми же заголовками и порядком столбцов → импорт.</p>
            <p><a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ksef_qwen_export'), 'ksef_qwen_export')); ?>">Экспортировать задания для Qwen</a></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="ksef_qwen_import">
                <?php wp_nonce_field('ksef_qwen_import'); ?>
                <input type="file" name="ksef_qwen_csv" accept=".csv,text/csv" required>
                <?php submit_button('Импортировать CSV из Qwen', 'secondary', 'submit', false); ?>
                <p class="description">Лимит файла — 1 МБ, до 30 кластеров. Обязательны SEO-поля и article_html (не менее 500 слов). Проверяются дубли H1/meta title, URL-slug и близость текста к другим материалам. При ошибках материал получает blocked_review; прошедший проверки — ready_for_publish. Создание и публикация страниц WordPress пока отключены.</p>
            </form>
            <?php
            $qwen_notice = get_transient('ksef_qwen_notice_' . get_current_user_id());
            if ($qwen_notice) :
                delete_transient('ksef_qwen_notice_' . get_current_user_id());
            ?>
                <div class="notice notice-<?php echo esc_attr($qwen_notice['status'] === 'error' ? 'error' : 'success'); ?> inline"><p><?php echo esc_html($qwen_notice['message']); ?></p></div>
            <?php endif; ?>
            <h3>Последние импортированные материалы</h3>
            <?php
            $qwen_rows = $wpdb->get_results(
                "SELECT qi.id, qi.keyword_id, qi.payload, qi.status, qi.updated_at, k.source_phrase
                 FROM {$wpdb->prefix}ksef_qwen_imports qi
                 LEFT JOIN {$wpdb->prefix}ksef_keywords k ON k.id = qi.keyword_id
                 ORDER BY qi.id DESC LIMIT 10"
            );
            ?>
            <?php if ($qwen_rows) : ?>
                <table class="widefat striped">
                    <thead><tr><th>Фраза</th><th>Рекомендуемый заголовок</th><th>H1</th><th>Статус</th><th>Обновлено (UTC)</th></tr></thead>
                    <tbody>
                    <?php foreach ($qwen_rows as $qwen_row) :
                        $qwen_data = json_decode($qwen_row->payload, true);
                    ?>
                        <tr>
                            <td><?php echo esc_html($qwen_row->source_phrase ? $qwen_row->source_phrase : ('ID ' . $qwen_row->keyword_id)); ?></td>
                            <td><?php echo esc_html($qwen_data['recommended_title'] ?? ''); ?></td>
                            <td><?php echo esc_html($qwen_data['h1'] ?? ''); ?></td>
                            <td><?php echo esc_html($qwen_row->status); ?></td>
                            <td><?php echo esc_html($qwen_row->updated_at); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p>Импортированных материалов пока нет.</p>
            <?php endif; ?>
            <h2>Search API</h2>
            <p>Синхронный поиск по России (регион Яндекса 225), XML-ответ, кэш 7 дней. Внутренний предел — 100 запросов в сутки, интервал — не менее 2 секунд. Поисковый клиент пока вызывается только из кода очереди; автоматические запросы не запускаются.</p>
            <h2>Последние события</h2>
            <?php if ($last_logs) : ?>
                <table class="widefat striped"><thead><tr><th>Время (UTC)</th><th>Уровень</th><th>Событие</th><th>Сообщение</th></tr></thead><tbody>
                <?php foreach ($last_logs as $log) : ?>
                    <tr><td><?php echo esc_html($log->created_at); ?></td><td><?php echo esc_html($log->level); ?></td><td><?php echo esc_html($log->event); ?></td><td><?php echo esc_html($log->message); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php else : ?>
                <p>Событий пока нет.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function daily_tick() {
        self::write_log('info', 'daily_tick', 'Ежедневный планировщик сработал. Очередь Search API обрабатывается отдельным событием каждые 5 минут.');
    }
}

register_activation_hook(__FILE__, array('Korotkov_SEO_Factory', 'activate'));
register_deactivation_hook(__FILE__, array('Korotkov_SEO_Factory', 'deactivate'));
Korotkov_SEO_Factory::instance();
