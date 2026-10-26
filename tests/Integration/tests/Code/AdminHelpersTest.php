<?php

use CrossPeak\WpPest\WpDieException;

use function CrossPeak\WpPest\captureAjax;
use function CrossPeak\WpPest\interceptWpDie;
use function CrossPeak\WpPest\onAdminScreen;

it('selects admin context before the plugin loads', function () {
    expect(WP_PEST_TEST_ADMIN_AT_PLUGIN_LOAD)->toBe(wp_pest()->config()->wordpressContext() === 'admin');
});

it('loads admin APIs and restores a scoped screen even after an exception', function () {
    expect(function_exists('submit_button'))->toBeTrue()
        ->and(class_exists('WP_List_Table'))->toBeTrue();
    $before = get_current_screen();
    $typenowExists = array_key_exists('typenow', $GLOBALS);
    $typenow = $GLOBALS['typenow'] ?? null;
    expect(onAdminScreen('dashboard', static fn () => [get_current_screen()->id, is_admin()]))
        ->toBe(['dashboard', true]);
    expect(fn () => onAdminScreen('edit-post', static function () {
        expect(get_current_screen()->post_type)->toBe('post');
        throw new RuntimeException('screen failed');
    }))->toThrow(RuntimeException::class, 'screen failed');
    expect(get_current_screen())->toBe($before)
        ->and(array_key_exists('typenow', $GLOBALS))->toBe($typenowExists)
        ->and($GLOBALS['typenow'] ?? null)->toBe($typenow);
    onAdminScreen('dashboard');
    expect(get_current_screen()->id)->toBe('dashboard');
});

it('captures real JSON responses and restores the AJAX and die filters', function () {
    $before = wp_doing_ajax();
    $handler = static fn () => '_ajax_wp_die_handler';
    add_filter('wp_die_ajax_handler', $handler, 123);
    $response = captureAjax(static fn () => wp_send_json_success(['value' => '東京']));
    expect($response->json())->toBe(['success' => true, 'data' => ['value' => '東京']])
        ->and($response->status)->toBe(200)
        ->and($response->termination)->toBeInstanceOf(WpDieException::class)
        ->and(wp_doing_ajax())->toBe($before)
        ->and(has_filter('wp_die_ajax_handler', $handler))->toBe(123)
        ->and(apply_filters('wp_die_ajax_handler', '_ajax_wp_die_handler'))->toBe('_ajax_wp_die_handler');
});

it('captures plain AJAX die bodies and status codes', function () {
    $previous = http_response_code();
    $response = captureAjax(static fn () => wp_die(-1, '', ['response' => 403]));
    expect($response->output)->toBe('-1')
        ->and($response->status)->toBe(403)
        ->and($response->termination->arguments)->toBe(['response' => 403])
        ->and(http_response_code())->toBe(is_int($previous) ? $previous : 200);
    $response = captureAjax(static function () {
        status_header(201);
        wp_send_json_success(['created' => true]);
    });
    expect($response->status)->toBe(201);
    $response = captureAjax(static fn () => wp_send_json_error(['reason' => 'invalid'], 422));
    expect($response->status)->toBe(422)
        ->and($response->json())->toBe(['success' => false, 'data' => ['reason' => 'invalid']]);
});

it('does not swallow unrelated exceptions and cleans up immediately', function () {
    $before = wp_doing_ajax();
    $level = ob_get_level();
    expect(fn () => captureAjax(static function () {
        ob_start();
        echo 'discard';
        throw new RuntimeException('JSON response');
    }))->toThrow(RuntimeException::class, 'JSON response');
    expect(wp_doing_ajax())->toBe($before)->and(ob_get_level())->toBe($level);
});

it('supports nested response capture and rejects malformed JSON', function () {
    $outer = captureAjax(static function () {
        $inner = captureAjax(static fn () => wp_send_json_error('inner', 422));
        expect($inner->json())->toBe(['success' => false, 'data' => 'inner'])
            ->and($inner->status)->toBe(422);
        wp_send_json_success('outer');
    });
    expect($outer->json())->toBe(['success' => true, 'data' => 'outer'])
        ->and($outer->status)->toBe(200);
    $response = captureAjax(static fn () => print 'unexpected HTML');
    expect($response->termination)->toBeNull()
        ->and(fn () => $response->json())->toThrow(JsonException::class);
});

it('intercepts wp_die with its original details and restores handlers', function () {
    $handlerBefore = apply_filters('wp_die_handler', '_default_wp_die_handler');
    try {
        interceptWpDie(static fn () => wp_die(new WP_Error('denied', 'Access denied'), 'Denied', ['response' => 403]));
        $this->fail('Expected wp_die to terminate.');
    } catch (WpDieException $error) {
        expect($error->getMessage())->toBe('Access denied')
            ->and($error->wpMessage)->toBeInstanceOf(WP_Error::class)
            ->and($error->title)->toBe('Denied')
            ->and($error->arguments)->toBe(['response' => 403]);
    }
    expect(apply_filters('wp_die_handler', '_default_wp_die_handler'))->toBe($handlerBefore);
});

it('honors nonterminating AJAX die calls', function () {
    $response = captureAjax(static function () {
        wp_die('continue', '', ['exit' => false]);
        echo ' after';
    });
    expect($response->termination)->toBeNull()->and($response->output)->toBe('continue after');
});
