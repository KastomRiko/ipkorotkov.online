<?php
/**
 * Deterministic SERP-overlap clustering. No external requests.
 */
if (!defined('ABSPATH')) { exit; }

final class KSEF_Clusterer {
    const MAX_KEYWORDS = 5000;
    const MIN_SHARED_URLS = 2;
    const MIN_JACCARD = 0.20;

    public static function register_hooks() {
        add_action('admin_post_ksef_cluster_keywords', array(__CLASS__, 'handle_cluster'));
    }

    public static function handle_cluster() {
        if (!current_user_can('manage_options')) wp_die('Недостаточно прав.');
        check_admin_referer('ksef_cluster_keywords');
        $result = self::cluster_all();
        if (is_wp_error($result)) {
            self::notice('error', $result->get_error_message());
        } else {
            Korotkov_SEO_Factory::write_log('info', 'keywords_clustered', 'Кластеризация завершена.', $result);
            self::notice('success', 'Обработано фраз: ' . $result['keywords'] . '; кластеров: ' . $result['clusters'] . '; подготовлено карточек страниц: ' . $result['pages'] . '.');
        }
        wp_safe_redirect(admin_url('admin.php?page=korotkov-seo-factory'));
        exit;
    }

    public static function cluster_all() {
        global $wpdb;
        $keywords_table = $wpdb->prefix . 'ksef_keywords';
        $serp_table = $wpdb->prefix . 'ksef_serp_results';
        $clusters_table = $wpdb->prefix . 'ksef_clusters';
        $pages_table = $wpdb->prefix . 'ksef_pages';
        $imports_table = $wpdb->prefix . 'ksef_qwen_imports';

        $keywords = $wpdb->get_results(
            "SELECT id, source_phrase FROM {$keywords_table}
             WHERE researched_at IS NOT NULL
             ORDER BY id ASC LIMIT " . self::MAX_KEYWORDS
        );
        if (!$keywords) {
            return new WP_Error('ksef_no_researched_keywords', 'Нет исследованных фраз для кластеризации. Сначала добавь фразы и собери поисковую выдачу.');
        }

        $ids = array();
        $phrases = array();
        foreach ($keywords as $keyword) {
            $id = (int) $keyword->id;
            $ids[] = $id;
            $phrases[$id] = (string) $keyword->source_phrase;
        }

        $id_list = implode(',', array_map('absint', $ids));
        $serp_rows = $wpdb->get_results(
            "SELECT keyword_id, result_url FROM {$serp_table}
             WHERE keyword_id IN ({$id_list}) AND result_url <> '' ORDER BY position_num ASC"
        );

        $url_sets = array();
        $url_to_keywords = array();
        foreach ($ids as $id) $url_sets[$id] = array();
        foreach ($serp_rows as $serp) {
            $id = (int) $serp->keyword_id;
            $url = self::normalize_url($serp->result_url);
            if (!$url || !isset($url_sets[$id])) continue;
            $url_sets[$id][$url] = true;
            if (!isset($url_to_keywords[$url])) $url_to_keywords[$url] = array();
            $url_to_keywords[$url][$id] = true;
        }

        // Build candidate pairs only from phrases sharing at least one SERP URL.
        $candidate_pairs = array();
        foreach ($url_to_keywords as $members) {
            $member_ids = array_keys($members);
            $count = count($member_ids);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = min($member_ids[$i], $member_ids[$j]);
                    $b = max($member_ids[$i], $member_ids[$j]);
                    $candidate_pairs[$a . ':' . $b] = array($a, $b);
                }
            }
        }

        $parent = array();
        foreach ($ids as $id) $parent[$id] = $id;
        foreach ($candidate_pairs as $pair) {
            list($a, $b) = $pair;
            $shared = count(array_intersect_key($url_sets[$a], $url_sets[$b]));
            if ($shared < self::MIN_SHARED_URLS) continue;
            $union = count($url_sets[$a]) + count($url_sets[$b]) - $shared;
            $jaccard = $union > 0 ? $shared / $union : 0;
            if ($jaccard >= self::MIN_JACCARD) {
                self::union_find($parent, $a, $b);
            }
        }

        $groups = array();
        foreach ($ids as $id) {
            $root = self::find_root($parent, $id);
            if (!isset($groups[$root])) $groups[$root] = array();
            $groups[$root][] = $id;
        }

        $now = current_time('mysql', true);
        $cluster_count = 0;
        $page_count = 0;
        $used_cluster_ids = array();
        foreach ($groups as $member_ids) {
            $member_ids = array_map('absint', $member_ids);
            $label = self::choose_label($member_ids, $phrases);
            $cluster_id = self::find_existing_cluster($member_ids, $keywords_table, $clusters_table);
            if ($cluster_id && isset($used_cluster_ids[$cluster_id])) $cluster_id = 0;
            if ($cluster_id) {
                $used_cluster_ids[$cluster_id] = true;
                $wpdb->update($clusters_table, array(
                    'label' => $label,
                    'status' => 'clustered',
                    'updated_at' => $now,
                ), array('id' => $cluster_id), array('%s','%s','%s'), array('%d'));
            } else {
                $wpdb->insert($clusters_table, array(
                    'label' => $label,
                    'status' => 'clustered',
                    'created_at' => $now,
                    'updated_at' => $now,
                ), array('%s','%s','%s','%s'));
                $cluster_id = (int) $wpdb->insert_id;
            }
            if (!$cluster_id) continue;

            $placeholders = implode(',', array_fill(0, count($member_ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$keywords_table} SET cluster_id = %d WHERE id IN ({$placeholders})",
                array_merge(array($cluster_id), $member_ids)
            ));

            $member_placeholders = implode(',', array_fill(0, count($member_ids), '%d'));
            $qwen = $wpdb->get_row($wpdb->prepare(
                "SELECT payload FROM {$imports_table}
                 WHERE keyword_id IN ({$member_placeholders})
                 ORDER BY id DESC LIMIT 1",
                $member_ids
            ));
            $qwen_data = $qwen ? json_decode($qwen->payload, true) : array();
            $page_title = !empty($qwen_data['recommended_title']) ? sanitize_text_field($qwen_data['recommended_title']) : $label;
            $page_status = !empty($qwen_data['h1']) && !empty($qwen_data['meta_title']) ? 'draft_review' : 'needs_qwen';
            $page_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$pages_table} WHERE cluster_id = %d ORDER BY id DESC LIMIT 1",
                $cluster_id
            ));
            if ($page_exists) {
                $wpdb->update($pages_table, array(
                    'page_title' => $page_title,
                    'status' => $page_status,
                    'updated_at' => $now,
                ), array('id' => (int) $page_exists), array('%s','%s','%s'), array('%d'));
            } else {
                $wpdb->insert($pages_table, array(
                    'cluster_id' => $cluster_id,
                    'page_title' => $page_title,
                    'page_slug' => sanitize_title($page_title),
                    'status' => $page_status,
                    'created_at' => $now,
                    'updated_at' => $now,
                ), array('%d','%s','%s','%s','%s','%s'));
                if ($wpdb->insert_id) $page_count++;
            }
            $cluster_count++;
        }

        return array(
            'keywords' => count($ids),
            'clusters' => $cluster_count,
            'pages' => $page_count,
            'unresearched_remaining' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$keywords_table} WHERE researched_at IS NULL"),
        );
    }

    private static function normalize_url($url) {
        $parts = wp_parse_url(trim((string) $url));
        if (empty($parts['host'])) return '';
        $host = strtolower(preg_replace('/^www\./i', '', $parts['host']));
        $path = isset($parts['path']) ? untrailingslashit($parts['path']) : '';
        return $host . $path;
    }

    private static function find_root(&$parent, $id) {
        if ($parent[$id] !== $id) $parent[$id] = self::find_root($parent, $parent[$id]);
        return $parent[$id];
    }

    private static function union_find(&$parent, $a, $b) {
        $ra = self::find_root($parent, $a);
        $rb = self::find_root($parent, $b);
        if ($ra !== $rb) $parent[$rb] = $ra;
    }

    private static function choose_label($member_ids, $phrases) {
        usort($member_ids, function ($a, $b) use ($phrases) {
            $la = function_exists('mb_strlen') ? mb_strlen($phrases[$a]) : strlen($phrases[$a]);
            $lb = function_exists('mb_strlen') ? mb_strlen($phrases[$b]) : strlen($phrases[$b]);
            return $lb <=> $la;
        });
        return sanitize_text_field($phrases[$member_ids[0]]);
    }

    private static function find_existing_cluster($member_ids, $keywords_table, $clusters_table) {
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($member_ids), '%d'));
        $query = $wpdb->prepare(
            "SELECT cluster_id FROM {$keywords_table}
             WHERE id IN ({$placeholders}) AND cluster_id > 0
             GROUP BY cluster_id ORDER BY COUNT(*) DESC LIMIT 1",
            $member_ids
        );
        $id = (int) $wpdb->get_var($query);
        if (!$id) return 0;
        return $wpdb->get_var($wpdb->prepare("SELECT id FROM {$clusters_table} WHERE id = %d", $id)) ? $id : 0;
    }

    private static function notice($status, $message) {
        set_transient('ksef_cluster_notice_' . get_current_user_id(), array(
            'status' => sanitize_key($status),
            'message' => sanitize_text_field($message),
        ), 60);
    }
}
