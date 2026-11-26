<?php
/**
 * Plugin Name: Korotkov Yandex Webmaster
 * Description: Безопасная read-only интеграция с API Яндекс.Вебмастера: проверка OAuth и список подтверждённых сайтов.
 * Version: 1.0.0
 * Author: Korotkov
 * Requires PHP: 8.1
 */

if (!defined('ABSPATH')) { exit; }

final class Korotkov_Yandex_Webmaster {
    private const OPTION = 'kyw_access_token';
    private const PAGE = 'korotkov-yandex-webmaster';
    private const API = 'https://api.webmaster.yandex.net';

    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_kyw_save', [__CLASS__, 'save']);
        add_action('admin_post_kyw_test', [__CLASS__, 'test']);
        add_action('rest_api_init', [__CLASS__, 'register_token_route']);
        add_action('rest_api_init', [__CLASS__, 'register_status_route']);
    }

    public static function menu(): void {
        add_options_page(
            'Яндекс.Вебмастер',
            'Яндекс.Вебмастер',
            'manage_options',
            self::PAGE,
            [__CLASS__, 'render']
        );
    }

    public static function register_token_route(): void {
        register_rest_route('kyw/v1', '/token', [
            'methods' => 'POST',
            'permission_callback' => static function () {
                return current_user_can('manage_options');
            },
            'callback' => static function (WP_REST_Request $request) {
                $token = trim((string) $request->get_param('token'));
                if (strlen($token) < 20 || strlen($token) > 4096) {
                    return new WP_Error('kyw_token_invalid', 'Токен имеет недопустимую длину.', ['status' => 400]);
                }
                update_option(self::OPTION, $token, false);
                delete_transient('kyw_last_result');
                return new WP_REST_Response(['saved' => true], 200);
            },
            'args' => [
                'token' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public static function register_status_route(): void {
        register_rest_route('kyw/v1', '/status', [
            'methods' => 'GET',
            'permission_callback' => static function () {
                return current_user_can('manage_options');
            },
            'callback' => static function () {
                $result = self::inspect(self::token());
                if (is_wp_error($result)) {
                    return new WP_Error('kyw_api_check_failed', $result->get_error_message(), ['status' => 502]);
                }
                return new WP_REST_Response([
                    'connected' => true,
                    'user_id' => $result['user_id'],
                    'hosts' => $result['hosts'],
                    'checked_at' => $result['checked_at'],
                ], 200);
            },
        ]);
    }

    private static function token(): string {
        if (defined('KOROTKOV_YANDEX_WEBMASTER_TOKEN') && is_string(KOROTKOV_YANDEX_WEBMASTER_TOKEN)) {
            return trim(KOROTKOV_YANDEX_WEBMASTER_TOKEN);
        }
        return (string) get_option(self::OPTION, '');
    }

    private static function api(string $path, string $token) {
        if (!str_starts_with($path, '/v4/')) {
            return new WP_Error('kyw_bad_path', 'Недопустимый API-маршрут.');
        }
        $response = wp_remote_get(self::API . $path, [
            'timeout' => 20,
            'redirection' => 0,
            'headers' => [
                'Authorization' => 'OAuth ' . $token,
                'Accept' => 'application/json',
            ],
        ]);
        if (is_wp_error($response)) return $response;
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300) {
            $message = 'Яндекс.Вебмастер вернул HTTP ' . $status . '.';
            if (is_array($body)) {
                $api_code = $body['error_code'] ?? '';
                $api_message = $body['error_message'] ?? ($body['message'] ?? '');
                if ($api_code !== '') $message .= ' [' . sanitize_key((string) $api_code) . ']';
                if ($api_message !== '') $message .= ' ' . sanitize_text_field((string) $api_message);
            }
            return new WP_Error('kyw_api_error', $message, ['status' => $status]);
        }
        if (!is_array($body)) return new WP_Error('kyw_bad_json', 'API вернул некорректный JSON.');
        return $body;
    }

    private static function inspect(string $token) {
        if (strlen($token) < 20 || strlen($token) > 4096) {
            return new WP_Error('kyw_token_invalid', 'Токен пустой или имеет недопустимую длину.');
        }
        $user = self::api('/v4/user', $token);
        if (is_wp_error($user)) return $user;
        $user_id = isset($user['user_id']) ? (int) $user['user_id'] : 0;
        if ($user_id <= 0) return new WP_Error('kyw_no_user_id', 'Ответ API не содержит user_id.');
        $hosts = self::api('/v4/user/' . $user_id . '/hosts', $token);
        if (is_wp_error($hosts)) return $hosts;
        $items = [];
        foreach (($hosts['hosts'] ?? []) as $host) {
            if (!is_array($host)) continue;
            $items[] = [
                'host_id' => sanitize_text_field((string) ($host['host_id'] ?? '')),
                'ascii_host_url' => esc_url_raw((string) ($host['ascii_host_url'] ?? '')),
                'verified' => !empty($host['verified']),
                'permissions' => sanitize_text_field((string) ($host['permission'] ?? '')),
            ];
        }
        return [
            'user_id' => $user_id,
            'hosts' => $items,
            'checked_at' => current_time('mysql'),
        ];
    }

    public static function save(): void {
        if (!current_user_can('manage_options')) wp_die('Недостаточно прав.');
        check_admin_referer('kyw_save');
        $token = isset($_POST['kyw_token']) ? trim(sanitize_text_field(wp_unslash($_POST['kyw_token']))) : '';
        if ($token === '') {
            delete_option(self::OPTION);
            delete_transient('kyw_last_result');
            wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'kyw_saved' => 'empty'], admin_url('options-general.php')));
            exit;
        }
        if (strlen($token) < 20 || strlen($token) > 4096) {
            wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'kyw_error' => 'length'], admin_url('options-general.php')));
            exit;
        }
        update_option(self::OPTION, $token, false);
        delete_transient('kyw_last_result');
        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'kyw_saved' => '1'], admin_url('options-general.php')));
        exit;
    }

    public static function test(): void {
        if (!current_user_can('manage_options')) wp_die('Недостаточно прав.');
        check_admin_referer('kyw_test');
        $token = self::token();
        $result = self::inspect($token);
        if (is_wp_error($result)) {
            set_transient('kyw_last_result', ['ok' => false, 'message' => $result->get_error_message()], 5 * MINUTE_IN_SECONDS);
        } else {
            set_transient('kyw_last_result', ['ok' => true, 'data' => $result], 5 * MINUTE_IN_SECONDS);
        }
        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'kyw_tested' => '1'], admin_url('options-general.php')));
        exit;
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) return;
        $constant_mode = defined('KOROTKOV_YANDEX_WEBMASTER_TOKEN');
        $stored = self::token() !== '';
        $result = get_transient('kyw_last_result');
        echo '<div class="wrap"><h1>Яндекс.Вебмастер — подключение API</h1>';
        echo '<p>Интеграция работает только на чтение: проверяет OAuth-доступ и получает список сайтов аккаунта. Она не отправляет URL на индексацию и не меняет настройки сайта.</p>';
        if (isset($_GET['kyw_saved'])) {
            echo '<div class="notice notice-success"><p>' . (sanitize_key($_GET['kyw_saved']) === 'empty' ? 'Токен удалён.' : 'Токен сохранён.') . '</p></div>';
        }
        if (isset($_GET['kyw_error'])) echo '<div class="notice notice-error"><p>Токен имеет недопустимую длину.</p></div>';
        if ($constant_mode) {
            echo '<div class="notice notice-info"><p>Используется токен из константы wp-config.php. Поле ниже отключено.</p></div>';
        } else {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="kyw_save">';
            wp_nonce_field('kyw_save');
            echo '<table class="form-table"><tr><th><label for="kyw_token">OAuth-токен</label></th><td><input type="password" autocomplete="new-password" spellcheck="false" id="kyw_token" name="kyw_token" class="regular-text" value="" placeholder="' . ($stored ? 'Токен уже сохранён; введите новый для замены' : 'Вставьте токен Яндекс OAuth') . '"><p class="description">Токен не отображается после сохранения и не передаётся в GitHub. Доступ к настройке есть только у администратора.</p></td></tr></table>';
            submit_button('Сохранить токен');
            echo '</form>';
        }
        echo '<hr><h2>Проверка подключения</h2>';
        echo '<p>Запросит GET /v4/user и GET /v4/user/{user_id}/hosts. Проверка не запускается автоматически.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="kyw_test">';
        wp_nonce_field('kyw_test');
        submit_button('Проверить доступ к API', 'secondary', 'submit', false, ['disabled' => !$stored]);
        echo '</form>';
        if (is_array($result)) {
            if (!empty($result['ok']) && !empty($result['data'])) {
                $data = $result['data'];
                echo '<div class="notice notice-success"><p><strong>API доступен.</strong> user_id: ' . esc_html((string) $data['user_id']) . '; проверено: ' . esc_html($data['checked_at']) . '</p></div>';
                echo '<h3>Сайты аккаунта</h3>';
                if (empty($data['hosts'])) {
                    echo '<p>API доступен, но список сайтов пуст.</p>';
                } else {
                    echo '<table class="widefat striped"><thead><tr><th>Адрес</th><th>Подтверждение</th><th>Права</th></tr></thead><tbody>';
                    foreach ($data['hosts'] as $host) {
                        echo '<tr><td>' . esc_html($host['ascii_host_url']) . '</td><td>' . (!empty($host['verified']) ? 'Подтверждён' : 'Не подтверждён') . '</td><td>' . esc_html($host['permissions']) . '</td></tr>';
                    }
                    echo '</tbody></table>';
                }
            } else {
                echo '<div class="notice notice-error"><p><strong>Проверка не пройдена:</strong> ' . esc_html((string) ($result['message'] ?? 'Неизвестная ошибка')) . '</p></div>';
            }
        }
        echo '<p class="description">Токен хранится в базе WordPress в закрытой настройке. Для более строгого хранения задайте константу KOROTKOV_YANDEX_WEBMASTER_TOKEN в wp-config.php; тогда поле настроек будет отключено.</p>';
        echo '</div>';
    }
}

Korotkov_Yandex_Webmaster::boot();
