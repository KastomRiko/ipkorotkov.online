<?php
/**
 * Small pure helpers: slug transliteration, FAQ parsing, budget guard.
 */
if (!defined('ABSPATH')) { exit; }

final class KSEF_Util {
    /** Консервативный резерв одного дневного синхронного Search API запроса, ₽ (488 ₽ / 1000; не фактическое списание). */
    const SEARCH_COST_ESTIMATE = 0.488;

    /**
     * Русский заголовок -> латинский slug (ГОСТ 7.79 «система Б», упрощённо).
     */
    public static function slug($text, $max = 80) {
        $text = function_exists('mb_strtolower') ? mb_strtolower((string) $text, 'UTF-8') : strtolower((string) $text);
        $map = array(
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y',
            'к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f',
            'х'=>'h','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        );
        $text = strtr($text, $map);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim((string) $text, '-');
        if (strlen($text) > $max) {
            $text = substr($text, 0, $max);
            $cut = strrpos($text, '-');
            if ($cut !== false && $cut > 20) {
                $text = substr($text, 0, $cut);
            }
            $text = trim($text, '-');
        }
        return $text;
    }

    /**
     * Разбор faq_html на пары вопрос/ответ: h2/h3 = вопрос, следующие p/ul/ol до следующего заголовка = ответ.
     * Возвращает массив array('q' => string, 'a' => string) в виде простого текста.
     */
    public static function parse_faq($html) {
        $html = trim((string) $html);
        if ($html === '' || !class_exists('DOMDocument')) return array();
        $prev = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="ksef-root">' . $html . '</div>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $dom->getElementById('ksef-root');
        if (!$root) {
            $xp = new DOMXPath($dom);
            $nodes = $xp->query('//div[@id="ksef-root"]');
            $root = $nodes && $nodes->length ? $nodes->item(0) : null;
        }
        if (!$root) return array();

        $items = array();
        $q = null;
        $a = array();
        foreach ($root->childNodes as $node) {
            if (!($node instanceof DOMElement)) continue;
            $name = strtolower($node->nodeName);
            if ($name === 'h2' || $name === 'h3' || $name === 'h4') {
                if ($q !== null && $a) {
                    $items[] = array('q' => $q, 'a' => trim(implode(' ', $a)));
                }
                $q = trim(preg_replace('/\s+/u', ' ', $node->textContent));
                $a = array();
            } elseif ($q !== null) {
                $t = trim(preg_replace('/\s+/u', ' ', $node->textContent));
                if ($t !== '') $a[] = $t;
            }
        }
        if ($q !== null && $a) {
            $items[] = array('q' => $q, 'a' => trim(implode(' ', $a)));
        }
        return array_values(array_filter($items, function ($i) { return $i['q'] !== '' && $i['a'] !== ''; }));
    }

    /**
     * Локальный предохранитель бюджета без ручной сверки Billing.
     * Суммирует все зарезервированные оценки запросов и учитывает стоимость
     * следующего запроса до его отправки. Это внутренний лимит, не баланс Yandex.
     */
    public static function check_budget($next_cost = 0.0) {
        global $wpdb;
        $table = $wpdb->prefix . 'ksef_api_usage';
        $used = $wpdb->get_var("SELECT COALESCE(SUM(estimated_cost), 0) FROM {$table}");
        if ($wpdb->last_error !== '') {
            return new WP_Error('ksef_budget_read_failed', 'Не удалось прочитать локальный учёт расходов; запрос не отправлен.');
        }
        $used = max(0, (float) $used);
        $next_cost = max(0, (float) $next_cost);
        $stop = 3800.0;
        if (($used + $next_cost) >= $stop) {
            return new WP_Error(
                'ksef_local_budget_stop',
                'Достигнут внутренний предел оценочных расходов: ' .
                number_format_i18n($used, 2) . ' ₽ + следующий запрос ' .
                number_format_i18n($next_cost, 2) . ' ₽. Порог остановки — 3 800 ₽.'
            );
        }
        return true;
    }

    public static function lower($s) {
        return function_exists('mb_strtolower') ? mb_strtolower((string) $s, 'UTF-8') : strtolower((string) $s);
    }
}
