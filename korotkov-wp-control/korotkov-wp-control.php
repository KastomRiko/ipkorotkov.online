<?php
/**
 * Plugin Name: Korotkov WP Control
 * Description: Защищённый API для удалённого управления файлами WordPress и создания ZIP-архивов.
 * Version: 0.1.0
 * Author: Korotkov
 * Requires PHP: 8.1
 */

if (!defined('ABSPATH')) { exit; }

final class Korotkov_WP_Control {
    private const VERSION = '0.1.0';
    private const NS = 'kwc/v1';
    private const MAX_READ = 262144;
    private const MAX_ARCHIVE_FILES = 5000;
    private const MAX_ARCHIVE_BYTES = 268435456;

    public static function boot(): void {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('init', [__CLASS__, 'schedule_cleanup']);
        add_action('kwc_cleanup_archives', [__CLASS__, 'cleanup_archives']);
    }

    public static function schedule_cleanup(): void {
        if (!wp_next_scheduled('kwc_cleanup_archives')) {
            wp_schedule_event(time() + 300, 'hourly', 'kwc_cleanup_archives');
        }
    }

    public static function cleanup_archives(): void {
        $dir = self::private_dir();
        if (is_wp_error($dir) || !is_dir($dir)) return;
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.zip') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - HOUR_IN_SECONDS) @unlink($file);
        }
    }

    public static function routes(): void {
        register_rest_route(self::NS, '/status', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'status'], 'permission_callback' => [__CLASS__, 'authorize'],
        ]);
        register_rest_route(self::NS, '/files', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'files'], 'permission_callback' => [__CLASS__, 'authorize'],
            'args' => ['path' => ['type' => 'string', 'required' => false]],
        ]);
        register_rest_route(self::NS, '/read', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'read_file'], 'permission_callback' => [__CLASS__, 'authorize'],
            'args' => ['path' => ['type' => 'string', 'required' => true]],
        ]);
        register_rest_route(self::NS, '/archive', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'create_archive'], 'permission_callback' => [__CLASS__, 'authorize'],
            'args' => ['path' => ['type' => 'string', 'required' => true]],
        ]);
        register_rest_route(self::NS, '/download/(?P<id>[a-f0-9]{32})', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'download_archive'], 'permission_callback' => [__CLASS__, 'authorize'],
        ]);
    }

    public static function authorize(WP_REST_Request $request) {
        if (!defined('KWC_API_TOKEN') || !is_string(KWC_API_TOKEN) || strlen(KWC_API_TOKEN) < 40) {
            return new WP_Error('kwc_not_configured', 'KWC_API_TOKEN не задан или слишком короткий. API отключён.', ['status' => 503]);
        }
        $provided = (string) $request->get_header('x-kwc-token');
        if ($provided === '' || !hash_equals(KWC_API_TOKEN, $provided)) {
            return new WP_Error('kwc_forbidden', 'Неверный токен доступа.', ['status' => 403]);
        }
        if (!current_user_can('manage_options')) {
            return new WP_Error('kwc_forbidden_role', 'Требуются права администратора WordPress.', ['status' => 403]);
        }
        return true;
    }

    private static function roots(): array {
        return [
            'plugins' => WP_PLUGIN_DIR,
            'themes' => get_theme_root(),
            'uploads' => wp_upload_dir()['basedir'],
            'mu-plugins' => WPMU_PLUGIN_DIR,
        ];
    }

    private static function resolve(string $relative, bool $must_exist = true) {
        $relative = str_replace(chr(92), '/', trim($relative));
        if ($relative === '' || str_contains($relative, "\0") || preg_match('~(^|/)\.\.?(/|$)~', $relative) || str_starts_with($relative, '/')) {
            return new WP_Error('kwc_bad_path', 'Недопустимый путь.', ['status' => 400]);
        }
        $parts = explode('/', $relative);
        $root_key = array_shift($parts);
        $roots = self::roots();
        if (!isset($roots[$root_key])) {
            return new WP_Error('kwc_forbidden_path', 'Разрешены только plugins, themes, uploads и mu-plugins.', ['status' => 403]);
        }
        $root = realpath($roots[$root_key]);
        if (!$root || !is_dir($root)) {
            return new WP_Error('kwc_root_missing', 'Каталог недоступен.', ['status' => 404]);
        }
        $candidate = $root;
        foreach ($parts as $part) {
            $candidate .= DIRECTORY_SEPARATOR . $part;
            if (is_link($candidate)) {
                return new WP_Error('kwc_symlink', 'Символические ссылки запрещены.', ['status' => 403]);
            }
        }
        $real = realpath($candidate);
        if ($must_exist && $real === false) {
            return new WP_Error('kwc_not_found', 'Файл или каталог не найден.', ['status' => 404]);
        }
        if ($real !== false) {
            $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if ($real !== $root && !str_starts_with($real . (is_dir($real) ? DIRECTORY_SEPARATOR : ''), $prefix)) {
                return new WP_Error('kwc_path_escape', 'Путь выходит за пределы разрешённого каталога.', ['status' => 403]);
            }
            $candidate = $real;
        }
        return ['root' => $root, 'path' => $candidate, 'relative' => $relative];
    }

    public static function status(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response([
            'product' => 'Korotkov WP Control', 'version' => self::VERSION,
            'wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION,
            'site' => home_url('/'), 'ziparchive' => class_exists('ZipArchive'),
            'capabilities' => ['status', 'list_files', 'read_file', 'create_zip', 'download_zip'],
            'write_operations' => 'disabled_in_0.1.0',
        ]);
    }

    public static function files(WP_REST_Request $request) {
        $path = (string) ($request->get_param('path') ?: 'plugins');
        $resolved = self::resolve($path);
        if (is_wp_error($resolved)) return $resolved;
        if (!is_dir($resolved['path'])) return new WP_Error('kwc_not_directory', 'Указан не каталог.', ['status' => 400]);
        $items = [];
        try {
            $iterator = new DirectoryIterator($resolved['path']);
            foreach ($iterator as $item) {
                if ($item->isDot()) continue;
                $name = $item->getFilename();
                if ($item->isLink() || str_starts_with($name, '.') || in_array($name, ['.git', 'node_modules', 'vendor', 'cache', 'kwc-private'], true)) continue;
                $items[] = ['name' => $name, 'type' => $item->isDir() ? 'directory' : 'file', 'size' => $item->isFile() ? $item->getSize() : null];
                if (count($items) >= 500) break;
            }
        } catch (Throwable $e) {
            return new WP_Error('kwc_list_failed', 'Не удалось прочитать каталог.', ['status' => 500]);
        }
        usort($items, static function ($a, $b) { $type = ($a['type'] === 'directory' ? 0 : 1) <=> ($b['type'] === 'directory' ? 0 : 1); return $type !== 0 ? $type : strcasecmp($a['name'], $b['name']); });
        return new WP_REST_Response(['path' => $path, 'items' => $items, 'limit' => 500]);
    }

    public static function read_file(WP_REST_Request $request) {
        $resolved = self::resolve((string) $request->get_param('path'));
        if (is_wp_error($resolved)) return $resolved;
        if (!is_file($resolved['path']) || !is_readable($resolved['path'])) return new WP_Error('kwc_not_file', 'Это не читаемый файл.', ['status' => 400]);
        $size = filesize($resolved['path']);
        if ($size === false || $size > self::MAX_READ) return new WP_Error('kwc_too_large', 'Чтение ограничено 256 КБ.', ['status' => 413]);
        $name = basename($resolved['path']);
        if (preg_match('/^(wp-config\.php|\.env|.*\.pem|.*\.key|.*\.sql)$/i', $name) || str_contains($resolved['relative'], '/.')) {
            return new WP_Error('kwc_sensitive_file', 'Чтение этого типа файла запрещено.', ['status' => 403]);
        }
        $content = file_get_contents($resolved['path']);
        if ($content === false || preg_match('//u', $content) !== 1) return new WP_Error('kwc_binary_file', 'Файл не является UTF-8 текстом.', ['status' => 415]);
        return new WP_REST_Response(['path' => $resolved['relative'], 'size' => $size, 'content' => $content]);
    }

    private static function private_dir() {
        $tmp = realpath(sys_get_temp_dir());
        if (!$tmp || !is_dir($tmp) || !is_writable($tmp)) {
            return new WP_Error('kwc_temp_unavailable', 'Системный временный каталог недоступен.', ['status' => 500]);
        }
        $tmp = rtrim($tmp, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $docroot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
        $wp_root = realpath(ABSPATH);
        foreach ([$docroot, $wp_root] as $public_root) {
            if ($public_root) {
                $public_prefix = rtrim($public_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                if (str_starts_with($tmp, $public_prefix) || rtrim($tmp, DIRECTORY_SEPARATOR) === $public_root) {
                    return new WP_Error('kwc_temp_public', 'Системный временный каталог находится внутри публичной папки сайта. Создание архива остановлено.', ['status' => 500]);
                }
            }
        }
        $dir = $tmp . 'kwc-' . substr(hash('sha256', ABSPATH), 0, 16);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return new WP_Error('kwc_temp_create_failed', 'Не удалось создать закрытый временный каталог.', ['status' => 500]);
        }
        @chmod($dir, 0700);
        return is_dir($dir) && is_writable($dir) ? $dir : new WP_Error('kwc_temp_permissions', 'Нет доступа к временному каталогу.', ['status' => 500]);
    }

    public static function create_archive(WP_REST_Request $request) {
        if (!class_exists('ZipArchive')) return new WP_Error('kwc_zip_missing', 'На сервере не включено расширение PHP ZipArchive.', ['status' => 501]);
        $resolved = self::resolve((string) $request->get_param('path'));
        if (is_wp_error($resolved)) return $resolved;
        if (!is_dir($resolved['path'])) return new WP_Error('kwc_not_directory', 'Для архива нужен каталог.', ['status' => 400]);
        $id = bin2hex(random_bytes(16));
        $dir = self::private_dir();
        if (is_wp_error($dir)) return $dir;
        $file = $dir . '/' . $id . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::EXCL) !== true) return new WP_Error('kwc_zip_create_failed', 'Не удалось создать ZIP.', ['status' => 500]);
        $count = 0; $bytes = 0; $base = $resolved['path'];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isLink() || !$entry->isFile()) continue;
            $name = $entry->getFilename();
            $full = $entry->getPathname();
            $relative = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($full, strlen($base))), '/');
            if (preg_match('~(^|/)(\.git|node_modules|vendor|cache|kwc-private)(/|$)~i', $relative)) continue;
            if (preg_match('~(^|/)(wp-config\.php|\.env|.*\.pem|.*\.key|.*\.sql)$~i', $relative)) continue;
            $size = $entry->getSize();
            if (++$count > self::MAX_ARCHIVE_FILES || ($bytes += $size) > self::MAX_ARCHIVE_BYTES) {
                $zip->close(); @unlink($file);
                return new WP_Error('kwc_archive_limit', 'Превышен лимит архива: 5000 файлов или 256 МБ.', ['status' => 413]);
            }
            if (!$zip->addFile($full, $relative)) {
                $zip->close(); @unlink($file);
                return new WP_Error('kwc_zip_add_failed', 'Ошибка добавления файла в ZIP.', ['status' => 500]);
            }
        }
        $zip->close();
        set_transient('kwc_archive_' . $id, ['file' => $file, 'name' => sanitize_file_name(basename($base)) . '.zip', 'created' => time()], HOUR_IN_SECONDS);
        return new WP_REST_Response([
            'archive_id' => $id, 'files' => $count, 'bytes_uncompressed' => $bytes,
            'download_url' => rest_url(self::NS . '/download/' . $id), 'expires_in' => 3600,
        ], 201);
    }

    public static function download_archive(WP_REST_Request $request) {
        $id = (string) $request['id'];
        $data = get_transient('kwc_archive_' . $id);
        if (!$data || empty($data['file']) || !is_file($data['file'])) return new WP_Error('kwc_archive_expired', 'Архив не найден или срок хранения истёк.', ['status' => 404]);
        $file = $data['file'];
        $dir = self::private_dir();
        if (is_wp_error($dir) || dirname($file) !== $dir) return new WP_Error('kwc_archive_invalid', 'Путь к архиву недействителен.', ['status' => 403]);
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: attachment; filename="' . rawurlencode($data['name']) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        @unlink($file);
        delete_transient('kwc_archive_' . $id);
        exit;
    }
}

Korotkov_WP_Control::boot();
