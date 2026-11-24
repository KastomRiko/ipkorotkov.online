<?php
/**
 * Plugin Name: Korotkov WP Control
 * Description: Защищённый API для удалённого управления файлами WordPress и создания ZIP-архивов.
 * Version: 0.2.0
 * Author: Korotkov
 * Requires PHP: 8.1
 */

if (!defined('ABSPATH')) { exit; }

final class Korotkov_WP_Control {
    private const VERSION = '0.2.0';
    private const NS = 'kwc/v1';
    private const MAX_READ = 262144;
    private const MAX_ARCHIVE_FILES = 5000;
    private const MAX_ARCHIVE_ENTRIES = 20000;
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
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.bak') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - DAY_IN_SECONDS) @unlink($file);
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
        register_rest_route(self::NS, '/write', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'write_file'], 'permission_callback' => [__CLASS__, 'authorize'],
        ]);
        register_rest_route(self::NS, '/restore', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'restore_file'], 'permission_callback' => [__CLASS__, 'authorize'],
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
            'capabilities' => ['status', 'list_files', 'read_file', 'write_file_with_backup', 'restore_file_backup', 'create_zip', 'download_zip'],
            'write_operations' => 'enabled_with_backup_and_sha256_check',
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
        if (str_starts_with($name, '.') || preg_match('/^(wp-config\\.php|.*\\.env.*|.*\\.pem|.*\\.key|.*\\.p12|.*\\.pfx|.*\\.sql|.*\\.bak|.*\\.old|.*\\.orig)$/i', $name) || preg_match('~(^|/)\\.~', $resolved['relative'])) {
            return new WP_Error('kwc_sensitive_file', 'Чтение этого типа файла запрещено.', ['status' => 403]);
        }
        $content = file_get_contents($resolved['path']);
        if ($content === false || preg_match('//u', $content) !== 1) return new WP_Error('kwc_binary_file', 'Файл не является UTF-8 текстом.', ['status' => 415]);
        return new WP_REST_Response(['path' => $resolved['relative'], 'size' => $size, 'sha256' => hash('sha256', $content), 'content' => $content]);
    }

    private static function private_dir() {
        if (!defined('KWC_API_TOKEN') || !is_string(KWC_API_TOKEN) || strlen(KWC_API_TOKEN) < 40) {
            return new WP_Error('kwc_not_configured', 'KWC_API_TOKEN не настроен; закрытое хранилище недоступно.', ['status' => 503]);
        }
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
        $dir = $tmp . 'kwc-' . substr(hash('sha256', ABSPATH . KWC_API_TOKEN), 0, 32);
        if (is_link($dir)) {
            return new WP_Error('kwc_temp_symlink', 'Временный каталог не может быть символической ссылкой.', ['status' => 500]);
        }
        if (!is_dir($dir) && !@mkdir($dir, 0700)) {
            return new WP_Error('kwc_temp_create_failed', 'Не удалось создать закрытый временный каталог.', ['status' => 500]);
        }
        $real_dir = realpath($dir);
        if (!$real_dir || $real_dir !== rtrim($tmp, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($dir) || is_link($dir)) {
            return new WP_Error('kwc_temp_path_invalid', 'Не удалось подтвердить безопасный путь временного каталога.', ['status' => 500]);
        }
        @chmod($real_dir, 0700);
        return is_dir($real_dir) && is_writable($real_dir) ? $real_dir : new WP_Error('kwc_temp_permissions', 'Нет доступа к временному каталогу.', ['status' => 500]);
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
        $count = 0; $bytes = 0; $scanned = 0; $base = $resolved['path'];
        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $entry) {
                if (++$scanned > self::MAX_ARCHIVE_ENTRIES) {
                    throw new LengthException('Превышен лимит обхода каталога.');
                }
                if ($entry->isLink() || !$entry->isFile()) continue;
                $full = $entry->getPathname();
                $relative = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($full, strlen($base))), '/');
                $segments = explode('/', $relative);
                $hidden = false;
                foreach ($segments as $segment) {
                    if ($segment !== '' && str_starts_with($segment, '.')) { $hidden = true; break; }
                }
                if ($hidden || preg_match('~(^|/)(node_modules|vendor|cache|tmp|temp|backups?|kwc-private|updraft|ai1wm-backups|wpvividbackups)(/|$)~i', $relative)) continue;
                if (preg_match('~(^|/)(wp-config\\.php|[^/]*\\.env[^/]*|[^/]*\\.(pem|key|p12|pfx|sql|bak|old|orig|log))$~i', $relative)) continue;
                $size = $entry->getSize();
                if (++$count > self::MAX_ARCHIVE_FILES || ($bytes += $size) > self::MAX_ARCHIVE_BYTES) {
                    throw new LengthException('Превышен лимит размера архива.');
                }
                if (!$zip->addFile($full, $relative)) {
                    throw new RuntimeException('Не удалось добавить файл в ZIP.');
                }
            }
        } catch (LengthException $e) {
            $zip->close(); @unlink($file);
            return new WP_Error('kwc_archive_limit', 'Превышен лимит архива: 5000 файлов, 20000 элементов обхода или 256 МБ.', ['status' => 413]);
        } catch (Throwable $e) {
            $zip->close(); @unlink($file);
            return new WP_Error('kwc_archive_failed', 'Ошибка чтения каталога при создании архива.', ['status' => 500]);
        }
        if (!$zip->close()) {
            @unlink($file);
            return new WP_Error('kwc_zip_close_failed', 'Не удалось корректно завершить ZIP-архив.', ['status' => 500]);
        }
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

    private static function sensitive_path(string $relative): bool {
        $name = basename($relative);
        return str_starts_with($name, '.') ||
            preg_match('/^(wp-config\\.php|.*\\.env.*|.*\\.(pem|key|p12|pfx|sql|bak|old|orig|log))$/i', $name) ||
            preg_match('~(^|/)\\.~', $relative);
    }

    public static function write_file(WP_REST_Request $request) {
        $path = (string) $request->get_param('path');
        $content = $request->get_param('content');
        $expected = (string) $request->get_param('expected_sha256');
        if (!is_string($content) || strlen($content) > 1048576 || preg_match('//u', $content) !== 1) {
            return new WP_Error('kwc_invalid_content', 'Содержимое должно быть UTF-8 текстом размером не более 1 МБ.', ['status' => 400]);
        }
        $resolved = self::resolve($path);
        if (is_wp_error($resolved)) return $resolved;
        if (self::sensitive_path($path)) return new WP_Error('kwc_sensitive_file', 'Изменение этого типа файла запрещено.', ['status' => 403]);
        if (!is_file($resolved['path']) || !is_writable($resolved['path'])) return new WP_Error('kwc_not_writable_file', 'Разрешено изменять только существующие доступные для записи файлы.', ['status' => 400]);
        $old = file_get_contents($resolved['path']);
        if ($old === false || preg_match('//u', $old) !== 1) return new WP_Error('kwc_binary_file', 'Бинарные файлы изменять нельзя.', ['status' => 415]);
        $old_hash = hash('sha256', $old);
        if ($expected !== '' && !hash_equals($old_hash, $expected)) {
            return new WP_Error('kwc_conflict', 'Файл изменился после последнего чтения. Повторно прочитайте его перед записью.', ['status' => 409]);
        }
        $dir = self::private_dir();
        if (is_wp_error($dir)) return $dir;
        $backup_id = bin2hex(random_bytes(16));
        $backup_file = $dir . DIRECTORY_SEPARATOR . $backup_id . '.bak';
        if (file_put_contents($backup_file, $old, LOCK_EX) === false) return new WP_Error('kwc_backup_failed', 'Не удалось создать резервную копию; файл не изменён.', ['status' => 500]);
        @chmod($backup_file, 0600);
        $written = file_put_contents($resolved['path'], $content, LOCK_EX);
        if ($written === false || $written !== strlen($content)) {
            @copy($backup_file, $resolved['path']);
            @unlink($backup_file);
            return new WP_Error('kwc_write_failed', 'Запись не удалась; исходная версия восстановлена из резервной копии.', ['status' => 500]);
        }
        set_transient('kwc_backup_' . $backup_id, ['file' => $backup_file, 'path' => $resolved['relative'], 'hash' => $old_hash], DAY_IN_SECONDS);
        return new WP_REST_Response([
            'success' => true, 'path' => $resolved['relative'], 'bytes_written' => $written,
            'previous_sha256' => $old_hash, 'new_sha256' => hash('sha256', $content),
            'backup_id' => $backup_id, 'backup_expires_in' => DAY_IN_SECONDS,
        ]);
    }

    public static function restore_file(WP_REST_Request $request) {
        $id = (string) $request->get_param('backup_id');
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) return new WP_Error('kwc_bad_backup_id', 'Некорректный идентификатор резервной копии.', ['status' => 400]);
        $backup = get_transient('kwc_backup_' . $id);
        if (!$backup || empty($backup['file']) || empty($backup['path']) || !is_file($backup['file'])) {
            return new WP_Error('kwc_backup_expired', 'Резервная копия не найдена или срок хранения истёк.', ['status' => 404]);
        }
        $resolved = self::resolve((string) $backup['path']);
        if (is_wp_error($resolved)) return $resolved;
        if (self::sensitive_path((string) $backup['path']) || !is_file($resolved['path']) || !is_writable($resolved['path'])) {
            return new WP_Error('kwc_restore_denied', 'Восстановление этого файла запрещено.', ['status' => 403]);
        }
        $current = file_get_contents($resolved['path']);
        if ($current === false) return new WP_Error('kwc_restore_read_failed', 'Не удалось прочитать текущий файл.', ['status' => 500]);
        $rollback_id = bin2hex(random_bytes(16));
        $dir = self::private_dir();
        if (is_wp_error($dir)) return $dir;
        $rollback_file = $dir . DIRECTORY_SEPARATOR . $rollback_id . '.bak';
        if (file_put_contents($rollback_file, $current, LOCK_EX) === false) return new WP_Error('kwc_backup_failed', 'Не удалось сохранить текущую версию перед восстановлением.', ['status' => 500]);
        @chmod($rollback_file, 0600);
        $original = file_get_contents($backup['file']);
        if ($original === false || file_put_contents($resolved['path'], $original, LOCK_EX) !== strlen($original)) {
            @copy($rollback_file, $resolved['path']);
            @unlink($rollback_file);
            return new WP_Error('kwc_restore_failed', 'Восстановление не удалось; предыдущая версия возвращена.', ['status' => 500]);
        }
        set_transient('kwc_backup_' . $rollback_id, ['file' => $rollback_file, 'path' => $resolved['relative']], DAY_IN_SECONDS);
        delete_transient('kwc_backup_' . $id);
        @unlink($backup['file']);
        return new WP_REST_Response(['success' => true, 'path' => $resolved['relative'], 'restored_sha256' => hash('sha256', $original), 'rollback_backup_id' => $rollback_id]);
    }

}

Korotkov_WP_Control::boot();
