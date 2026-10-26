<?php

// Isolated process for the MySQL/MariaDB probe branch, without a server dependency.
define('ABSPATH', $argv[2].'/');
function get_option() {}
function wp_cache_delete() {}
function get_plugins(): array
{
    return [];
}
function is_plugin_active(): bool
{
    return false;
}
$wpdb = new class($argv[3])
{
    public function __construct(private string $server) {}

    public function db_server_info(): string
    {
        return $this->server;
    }

    public function db_version(): string
    {
        return '5.5.5';
    }
};
$wp_version = '6.9.10';
echo json_encode(require $argv[1], JSON_THROW_ON_ERROR);
