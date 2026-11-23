<?php
/**
 * Small pure helpers: slug transliteration, FAQ parsing, budget guard.
 */
if (!defined('ABSPATH')) { exit; }

final class KSEF_Util {
    /** Оценка стоимости одного синхронного запроса Search API, ₽ (ориентир, не подтверждённое списание). */
    const SEARCH_COST_ESTIMATE = 0.4;

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
     * Возвращает последнюю вручную сверенную сумму expense из Yandex Cloud Billing.
     * Данные считаются актуальными не более 24 часов; оценка API из api_usage не используется для допуска запросов.
     */
    /**
     * Сумма cost из Yandex Cloud Billing (до применения кредитов/бонусов).
     * Хранится как ручная сверка; API-оценки после этой даты добавляются консервативно.
     */
    public static function reported_cost() {
        $settings = get_option(KSEF_OPTION, Korotkov_SEO_Factory::default_settings());
        if (!isset($settings['billing_reported_cost']) || $settings['billing_reported_cost'] === '' || !is_numeric($settings['billing_reported_cost'])) {
            return null;
        }
        return (float) $settings['billing_reported_cost'];
    }

    public static function billing_checked_at() {
        $settings = get_option(KSEF_OPTION, Korotkov_SEO_Factory::default_settings());
        return isset($settings['billing_checked_at']) ? (int) $settings['billing_checked_at'] : 0;
    }

    /**
     * Оценка платных попыток после ручной сверки. Учитываем и неуспешные HTTP-вызовы:
     * провайдер мог принять запрос, даже если клиент получил ошибку/тайм-аут.
     */
    public static function pending_estimated_cost($checked_at) {
        global $wpdb;
        $table = $wpdb->prefix . 'ksef_api_usage';
        $since = gmdate('Y-m-d H:i:s', (int) $checked_at);
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(estimated_cost), 0) FROM {$table} WHERE created_at >= %s",
            $since
        ));
        if ($wpdb->last_error !== '') {
            return new WP_Error('ksef_billing_estimate_read_failed', 'Не удалось проверить резерв оценочных расходов; платные запросы заблокированы.');
        }
        return max(0, (float) $value);
    }

    /**
     * Fail-closed. Сверяется gross cost до кредитов; локальные оценки после сверки
     * добавляются как резерв. Это консервативная оценка, а не точное списание.
     */
    public static function check_budget() {
        $settings = get_option(KSEF_OPTION, Korotkov_SEO_Factory::default_settings());
        $stop = isset($settings['budget_stop']) ? (float) $settings['budget_stop'] : 3800.0;
        $spent = self::reported_cost();
        $checked_at = self::billing_checked_at();

        if ($spent === null || $checked_at <= 0) {
            return new WP_Error('ksef_billing_data_missing', 'Платные запросы заблокированы: внесите показатель cost из Yandex Cloud Billing.');
        }
        if ($checked_at < time() - DAY_IN_SECONDS || $checked_at > time() + 300) {
            return new WP_Error('ksef_billing_data_stale', 'Платные запросы заблокированы: сверка cost устарела. Обновите сумму из Yandex Cloud Billing.');
        }
        $pending = self::pending_estimated_cost($checked_at);
        if (is_wp_error($pending)) return $pending;

        if (($spent + $pending) >= $stop) {
            return new WP_Error(
                'ksef_budget_stop',
                'Достигнут порог бюджета: cost ' . number_format_i18n($spent, 2) .
                ' ₽ + резерв запросов ' . number_format_i18n($pending, 2) .
                ' ₽ ≥ ' . number_format_i18n($stop, 2) . ' ₽. Платные запросы отключены.'
            );
        }
        return true;
    }

    public static function lower($s) {
        return function_exists('mb_strtolower') ? mb_strtolower((string) $s, 'UTF-8') : strtolower((string) $s);
    }
}
