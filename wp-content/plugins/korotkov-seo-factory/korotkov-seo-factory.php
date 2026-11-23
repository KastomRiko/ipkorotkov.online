<?php
/**
 * Plugin Name: Korotkov SEO Factory
 * Description: Основа системы исследования поисковых запросов, кластеризации и контролируемой SEO-публикации.
 * Version: 0.2.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Korotkov
 * Text Domain: korotkov-seo-factory
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KSEF_VERSION', '0.2.0');
require_once __DIR__ . '/includes/class-yandex-client.php';
require_once __DIR__ . '/includes/class-search-client.php';
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

        if (!wp_next_scheduled('ksef_daily_tick')) {
            wp_schedule_event(time() + 300, 'daily', 'ksef_daily_tick');
        }

        self::write_log('info', 'plugin_activated', 'Плагин активирован. Внешние API-запросы пока отключены.');
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('ksef_daily_tick');
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
                        <td><label><input type="checkbox" name="<?php echo esc_attr(KSEF_OPTION); ?>[automation_enabled]" value="1" <?php checked(!empty($settings['automation_enabled'])); ?> disabled> Включить после завершения и проверки API-модуля</label><p class="description">Переключатель пока заблокирован: подключение API ещё не реализовано.</p></td>
                    </tr>
                </table>
                <?php submit_button('Сохранить настройки'); ?>
            </form>
            <hr>
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
        self::write_log('info', 'daily_tick', 'Планировщик сработал. API-запросы не выполнялись: модуль исследования ещё не подключён.');
    }
}

register_activation_hook(__FILE__, array('Korotkov_SEO_Factory', 'activate'));
register_deactivation_hook(__FILE__, array('Korotkov_SEO_Factory', 'deactivate'));
Korotkov_SEO_Factory::instance();
