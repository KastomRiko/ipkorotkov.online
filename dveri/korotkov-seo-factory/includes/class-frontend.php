<?php
/**
 * Front-end SEO output for pages created by the plugin:
 * <title>, meta description, Open Graph, FAQPage JSON-LD, search-engine verification tags.
 * Title/description/OG are skipped when another SEO plugin is active (it reads the compat meta written by the publisher).
 */
if (!defined('ABSPATH')) { exit; }

final class KSEF_Frontend {
    public static function register_hooks() {
        add_filter('pre_get_document_title', array(__CLASS__, 'document_title'), 20);
        add_action('wp_head', array(__CLASS__, 'head'), 2);
    }

    private static function other_seo_plugin_active() {
        return defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || defined('SEOPRESS_VERSION') || defined('AIOSEO_VERSION');
    }

    private static function post_meta($key) {
        if (!is_singular()) return '';
        $id = get_queried_object_id();
        return $id ? (string) get_post_meta($id, $key, true) : '';
    }

    public static function document_title($title) {
        if (self::other_seo_plugin_active()) return $title;
        $custom = trim(self::post_meta('_ksef_meta_title'));
        return $custom !== '' ? $custom : $title;
    }

    public static function head() {
        $s = wp_parse_args(get_option(KSEF_OPTION, array()), Korotkov_SEO_Factory::default_settings());

        if (is_front_page()) {
            if (!empty($s['yandex_verification'])) {
                echo '<meta name="yandex-verification" content="' . esc_attr($s['yandex_verification']) . '" />' . "\n";
            }
            if (!empty($s['google_verification'])) {
                echo '<meta name="google-site-verification" content="' . esc_attr($s['google_verification']) . '" />' . "\n";
            }
        }

        if (!is_singular()) return;
        $id = get_queried_object_id();
        if (!$id || (!get_post_meta($id, '_ksef_cluster_id', true) && !get_post_meta($id, '_ksef_kb_id', true))) return;

        if (!self::other_seo_plugin_active()) {
            $desc = trim(self::post_meta('_ksef_meta_description'));
            $title = trim(self::post_meta('_ksef_meta_title'));
            if ($desc !== '') {
                echo '<meta name="description" content="' . esc_attr($desc) . '" />' . "\n";
            }
            if ($title !== '') {
                echo '<meta property="og:type" content="article" />' . "\n";
                echo '<meta property="og:title" content="' . esc_attr($title) . '" />' . "\n";
                if ($desc !== '') echo '<meta property="og:description" content="' . esc_attr($desc) . '" />' . "\n";
                echo '<meta property="og:url" content="' . esc_url(get_permalink($id)) . '" />' . "\n";
                echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '" />' . "\n";
                echo '<meta property="og:locale" content="' . esc_attr(get_locale()) . '" />' . "\n";
                $img = get_the_post_thumbnail_url($id, 'large');
                if ($img) echo '<meta property="og:image" content="' . esc_url($img) . '" />' . "\n";
            }
        }

        $faq = json_decode(self::post_meta('_ksef_faq'), true);
        if (is_array($faq) && $faq) {
            $entities = array();
            foreach ($faq as $item) {
                if (empty($item['q']) || empty($item['a'])) continue;
                $entities[] = array(
                    '@type' => 'Question',
                    'name' => (string) $item['q'],
                    'acceptedAnswer' => array('@type' => 'Answer', 'text' => (string) $item['a']),
                );
            }
            if ($entities) {
                $schema = array('@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities);
                echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . "</script>\n";
            }
        }
    }
}
