<?php
if (!defined('ABSPATH')) { exit; }

final class KSEF_Title_Audit {
    public static function register_hooks() {
        add_action('admin_post_ksef_audit_titles', array(__CLASS__, 'run'));
    }

    private static function is_informational($value) {
        $value = html_entity_decode(wp_strip_all_tags((string) $value), ENT_QUOTES, 'UTF-8');
        return (bool) preg_match('/(?:^|[\s:—–-])(?:как\s+(?:настроить|установить|подключить|исправить)|инструкция|пошагов\w*|что\s+такое|почему\s+не\s+работает|руководство|обзор)(?:$|[\s:—–-])/iu', $value);
    }

    public static function run() {
        if (!current_user_can('manage_options')) wp_die('Недостаточно прав.');
        check_admin_referer('ksef_audit_titles');
        $pages = get_posts(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_parent' => 92,
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ));
        $rows = array();
        foreach ($pages as $page) {
            $checks = array(
                'Заголовок страницы' => $page->post_title,
                'Yoast SEO title' => get_post_meta($page->ID, '_yoast_wpseo_title', true),
                'Yoast description' => get_post_meta($page->ID, '_yoast_wpseo_metadesc', true),
            );
            foreach ($checks as $field => $value) {
                if ($value !== '' && self::is_informational($value)) {
                    $rows[] = array(
                        'id' => (int) $page->ID,
                        'field' => $field,
                        'value' => (string) $value,
                        'url' => get_permalink($page->ID),
                    );
                }
            }
        }
        set_transient('ksef_title_audit_' . get_current_user_id(), array(
            'count' => count($pages),
            'rows' => $rows,
            'time' => current_time('mysql'),
        ), HOUR_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=korotkov-seo-factory&ksef_title_audit=done'));
        exit;
    }

    public static function render() {
        $result = get_transient('ksef_title_audit_' . get_current_user_id());
        ?>
        <h2>Проверка коммерческих заголовков</h2>
        <p>Проверяет опубликованные услуги в разделе /services/: заголовки страниц, Yoast SEO title и description. Ничего автоматически не переименовывает и не меняет URL.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ksef_audit_titles">
            <?php wp_nonce_field('ksef_audit_titles'); ?>
            <?php submit_button('Проверить коммерческие заголовки', 'secondary', 'submit', false); ?>
        </form>
        <?php if (is_array($result)) : ?>
            <p><strong>Проверено страниц:</strong> <?php echo (int) $result['count']; ?>. <strong>Найдено замечаний:</strong> <?php echo count($result['rows']); ?>. <span class="description"><?php echo esc_html($result['time']); ?></span></p>
            <?php if (!empty($result['rows'])) : ?>
                <table class="widefat striped"><thead><tr><th>ID</th><th>Поле</th><th>Найденный текст</th><th>Страница</th></tr></thead><tbody>
                <?php foreach ($result['rows'] as $row) : ?>
                    <tr><td><?php echo (int) $row['id']; ?></td><td><?php echo esc_html($row['field']); ?></td><td><?php echo esc_html($row['value']); ?></td><td><a href="<?php echo esc_url($row['url']); ?>" target="_blank" rel="noopener">Открыть</a></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php else : ?>
                <p><strong>Информационных формулировок по заданным правилам не найдено.</strong></p>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    }
}
