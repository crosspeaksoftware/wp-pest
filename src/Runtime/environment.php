<?php

// Also executed inside a browser sandbox through WP-CLI; keep this self-contained.
return (static function (): array {
    global $wpdb, $wp_version;
    if (! defined('ABSPATH') || ! function_exists('get_option') || ! function_exists('wp_cache_delete') || ! is_object($wpdb)) {
        throw new RuntimeException('WordPress must be bootstrapped before inspecting its runtime environment.');
    }
    require_once ABSPATH.'wp-admin/includes/plugin.php';
    if (! function_exists('get_plugins') || ! function_exists('is_plugin_active')) {
        throw new RuntimeException('WordPress plugin inspection API could not be loaded.');
    }
    wp_cache_delete('plugins', 'plugins');
    $plugins = [];
    foreach (get_plugins() as $file => $data) {
        $directory = dirname($file);
        $slug = $directory === '.' ? basename($file, '.php') : explode('/', $file)[0];
        $active = is_plugin_active($file);
        if (! isset($plugins[$slug]) || ($active && ! $plugins[$slug]['active'])) {
            $plugins[$slug] = ['file' => $file, 'version' => $data['Version'] ?: null, 'active' => $active];
        }
    }
    $pdo = $GLOBALS['@pdo'] ?? null;
    if (is_a($wpdb, 'WP_SQLite_DB') || ($pdo instanceof PDO && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite')) {
        $database = 'sqlite';
        $databaseVersion = $pdo instanceof PDO ? (string) $pdo->query('SELECT sqlite_version()')->fetchColumn() : null;
    } else {
        $server = (string) $wpdb->db_server_info();
        $database = stripos($server, 'mariadb') !== false ? 'mariadb' : 'mysql';
        if ($database === 'mariadb' && str_starts_with($server, '5.5.5-')) {
            $server = substr($server, 6);
        }
        preg_match('/^(\d+(?:\.\d+)+)/', $server, $matches);
        $databaseVersion = $matches[1] ?? (string) $wpdb->db_version();
    }

    return ['php' => PHP_VERSION, 'wordpress' => $wp_version, 'database' => $database, 'database_version' => $databaseVersion, 'plugins' => $plugins];
})();
