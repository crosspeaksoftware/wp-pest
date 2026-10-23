<?php

it('can write through the WordPress SQLite adapter inside an isolated test', function () {
    update_option('wp_pest_isolation_probe', 'temporary');
    $id = wp_pest()->factory()->user->create(['role' => 'administrator']);
    wp_set_current_user($id);
    set_current_screen('dashboard');
    $_POST['wp_pest_probe'] = 'temporary';
    add_filter('wp_pest_probe', static fn () => 'temporary');
    remove_all_filters('wp_pest_baseline');
    wp_enqueue_style('wp-pest-probe', 'https://example.test/style.css');
    $file = tempnam(sys_get_temp_dir(), 'wp-pest-cleanup-');
    $this->deferCleanup(static function () use ($file): void {
        unlink($file);
    });

    expect(get_option('wp_pest_isolation_probe'))->toBe('temporary')
        ->and(current_user_can('manage_options'))->toBeTrue()
        ->and($GLOBALS['wpdb']->last_error)->toBe('');

    return [$id, $file];
});

it('restores database and request state before the next test', function (array $created) {
    [$id, $file] = $created;
    expect(get_option('wp_pest_isolation_probe'))->toBeFalse()
        ->and(get_user_by('id', $id))->toBeFalse()
        ->and(get_current_user_id())->toBe(0)
        ->and(get_current_screen())->toBeNull()
        ->and(isset($_POST['wp_pest_probe']))->toBeFalse()
        ->and(has_filter('wp_pest_probe'))->toBeFalse()
        ->and(apply_filters('wp_pest_baseline', 'changed'))->toBe('baseline')
        ->and(wp_style_is('wp-pest-probe', 'enqueued'))->toBeFalse()
        ->and(file_exists($file))->toBeFalse();
})->depends('it can write through the WordPress SQLite adapter inside an isolated test');

it('keeps dependency basenames and URLs outside the plugin under test', function () {
    $dependency = WP_PLUGIN_DIR.'/sqlite-database-integration/load.php';
    expect(is_file($dependency))->toBeTrue()
        ->and(plugin_basename($dependency))->toBe('sqlite-database-integration/load.php')
        ->and(plugins_url('asset.js', $dependency))->toBe(content_url('plugins/sqlite-database-integration/asset.js'))
        ->and(realpath(ABSPATH))->not->toStartWith(realpath(wp_pest()->pluginPath()).DIRECTORY_SEPARATOR);
});
