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
            "SELECT id, source_phrase, wordstat_data FROM {$keywords_table}
             WHERE researched_at IS NOT NULL
             ORDER BY id ASC LIMIT " . self::MAX_KEYWORDS
        );
        if (!$keywords) {
            return new WP_Error('ksef_no_researched_keywords', 'Нет исследованных фраз для кластеризации. Сначала добавь фразы и собери поисковую выдачу.');
        }

        $ids = array();
        $phrases = array();
        $freq = array();
        foreach ($keywords as $keyword) {
            $id = (int) $keyword->id;
            $ids[] = $id;
            $phrases[$id] = (string) $keyword->source_phrase;
            $ws = $keyword->wordstat_data ? json_decode($keyword->wordstat_data, true) : null;
            $freq[$id] = (is_array($ws) && isset($ws['totalCount'])) ? (int) $ws['totalCount'] : 0;
        }

        $id_list = implode(',', array_map('absint', $ids));
        $serp_rows = $wpdb->get_results(
            "SELECT keyword_id, result_url, checked_at FROM {$serp_table}
             WHERE keyword_id IN ({$id_list}) AND result_url <> '' ORDER BY position_num ASC"
        );

        // Берём только последний снимок выдачи по каждой фразе (±1 сутки от самой свежей записи).
        $latest = array();
        foreach ($serp_rows as $serp) {
            $kid = (int) $serp->keyword_id;
            $ts = strtotime($serp->checked_at . ' UTC');
            if (!isset($latest[$kid]) || $ts > $latest[$kid]) $latest[$kid] = $ts;
        }

        $url_sets = array();
        $url_to_keywords = array();
        foreach ($ids as $id) $url_sets[$id] = array();
        foreach ($serp_rows as $serp) {
            $id = (int) $serp->keyword_id;
            if (strtotime($serp->checked_at . ' UTC') < $latest[$id] - DAY_IN_SECONDS) continue;
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

        // Рёбра: пары фраз, прошедшие пороги. Затем жадное объединение групп по убыванию силы связи;
        // две группы сливаются, только если связана БОЛЬШЕ половины пар между ними (против цепочечного слипания).
        $edges = array();
        $weighted = array();
        foreach ($candidate_pairs as $pair) {
            list($a, $b) = $pair;
            $shared = count(array_intersect_key($url_sets[$a], $url_sets[$b]));
            if ($shared < self::MIN_SHARED_URLS) continue;
            $union = count($url_sets[$a]) + count($url_sets[$b]) - $shared;
            $jaccard = $union > 0 ? $shared / $union : 0;
            if ($jaccard >= self::MIN_JACCARD) {
                $edges[$a . ':' . $b] = true;
                $weighted[] = array($jaccard, $a, $b);
            }
        }
        usort($weighted, function ($x, $y) { return $y[0] <=> $x[0]; });

        $group_of = array();
        $members_of = array();
        foreach ($ids as $id) {
            $group_of[$id] = $id;
            $members_of[$id] = array($id);
        }
        foreach ($weighted as $w) {
            $ga = $group_of[$w[1]];
            $gb = $group_of[$w[2]];
            if ($ga === $gb) continue;
            $linked = 0;
            $total = count($members_of[$ga]) * count($members_of[$gb]);
            foreach ($members_of[$ga] as $x) {
                foreach ($members_of[$gb] as $y) {
                    $k = min($x, $y) . ':' . max($x, $y);
                    if (isset($edges[$k])) $linked++;
                }
            }
            if ($linked * 2 <= $total) continue;
            foreach ($members_of[$gb] as $y) $group_of[$y] = $ga;
            $members_of[$ga] = array_merge($members_of[$ga], $members_of[$gb]);
            unset($members_of[$gb]);
        }

        $groups = array_values($members_of);

        $now = current_time('mysql', true);
        $cluster_count = 0;
        $page_count = 0;
        $used_cluster_ids = array();
        foreach ($groups as $member_ids) {
            $member_ids = array_map('absint', $member_ids);
            $label = self::choose_label($member_ids, $phrases, $freq);
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
            $page_row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, status FROM {$pages_table} WHERE cluster_id = %d ORDER BY id DESC LIMIT 1",
                $cluster_id
            ));
            if ($page_row) {
                // Never downgrade a page that has already passed the content gate.
                $protected_statuses = array('ready_for_publish', 'blocked_review', 'draft_created', 'published');
                $next_status = in_array($page_row->status, $protected_statuses, true) ? $page_row->status : $page_status;
                $wpdb->update($pages_table, array(
                    'page_title' => $page_title,
                    'status' => $next_status,
                    'updated_at' => $now,
                ), array('id' => (int) $page_row->id), array('%s','%s','%s'), array('%d'));
            } else {
                $wpdb->insert($pages_table, array(
                    'cluster_id' => $cluster_id,
                    'page_title' => $page_title,
                    'page_slug' => KSEF_Util::slug($page_title),
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

    /** Головная фраза: по частоте Wordstat, иначе самая короткая. */
    private static function choose_label($member_ids, $phrases, $freq) {
        usort($member_ids, function ($a, $b) use ($phrases, $freq) {
            $fa = isset($freq[$a]) ? $freq[$a] : 0;
            $fb = isset($freq[$b]) ? $freq[$b] : 0;
            if ($fa !== $fb) return $fb <=> $fa;
            $la = function_exists('mb_strlen') ? mb_strlen($phrases[$a]) : strlen($phrases[$a]);
            $lb = function_exists('mb_strlen') ? mb_strlen($phrases[$b]) : strlen($phrases[$b]);
            return $la <=> $lb;
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
