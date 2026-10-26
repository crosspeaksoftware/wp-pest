<?php

use function CrossPeak\WpPest\actingAs;
use function CrossPeak\WpPest\actingAsAdministrator;
use function CrossPeak\WpPest\actingAsGuest;
use function CrossPeak\WpPest\actingAsRole;
use function CrossPeak\WpPest\factory;

it('creates posts pages and bulk records with literal content and metadata', function () {
    $content = '{"path":"C:\\fixtures\\quoted","title":"東京"}';
    $post = factory()->post->createAndGet(['post_content' => $content, 'meta_input' => ['probe' => 'value']]);
    $page = factory()->page->createAndGet(['post_title' => 'Test page']);
    expect($post->post_type)->toBe('post')->and($post->post_status)->toBe('publish')
        ->and($post->post_content)->toBe($content)->and(get_post_meta($post->ID, 'probe', true))->toBe('value')
        ->and($page->post_type)->toBe('page')
        ->and(factory()->post->createMany(3, ['post_status' => 'draft']))->toHaveCount(3)
        ->and(factory()->page->createMany(0))->toBe([])
        ->and(fn () => factory()->user->createMany(-1))->toThrow(InvalidArgumentException::class);
});

it('creates taxonomy terms and surfaces WordPress errors', function () {
    $term = factory()->term->createAndGet(['name' => 'A category']);
    expect($term->taxonomy)->toBe('category')->and($term->name)->toBe('A category')
        ->and(factory()->term->createMany(2, ['taxonomy' => 'post_tag']))->toHaveCount(2)
        ->and(fn () => factory()->term->create(['taxonomy' => 'not_registered']))->toThrow(RuntimeException::class)
        ->and(fn () => factory()->post->create(['ID' => 99999999]))->toThrow(RuntimeException::class);
});

it('creates attachment records and copies file fixtures without modifying the original', function () {
    $post = factory()->post->create();
    $record = factory()->attachment->createAndGet(['post_parent' => $post]);
    expect($record->post_type)->toBe('attachment')->and($record->post_parent)->toBe($post);
    $source = sys_get_temp_dir().'/wp-pest-upload-'.bin2hex(random_bytes(8)).'.txt';
    file_put_contents($source, 'fixture bytes');
    $this->deferCleanup(static fn () => unlink($source));
    $id = factory()->attachment->createFromFile($source, ['post_parent' => $post]);
    $this->deferCleanup(static fn () => wp_delete_attachment($id, true));
    $copy = get_attached_file($id);
    expect($copy)->not->toBe($source)->and(file_get_contents($copy))->toBe('fixture bytes')
        ->and(file_get_contents($source))->toBe('fixture bytes')
        ->and(get_post_mime_type($id))->toBe('text/plain')
        ->and(fn () => factory()->attachment->createFromFile($source.'.missing'))->toThrow(InvalidArgumentException::class);
});

it('authenticates existing users roles and guests without granting extra permissions', function () {
    $reader = factory()->user->createAndGet(['role' => 'subscriber']);
    expect(actingAs($reader))->toBe($reader->ID)->and(current_user_can('manage_options'))->toBeFalse();
    expect(actingAs($reader->ID))->toBe($reader->ID);
    $editor = actingAsRole('editor');
    expect(get_current_user_id())->toBe($editor)->and(current_user_can('edit_posts'))->toBeTrue()
        ->and(current_user_can('manage_options'))->toBeFalse();
    actingAsGuest();
    expect(get_current_user_id())->toBe(0);
    $admin = actingAsAdministrator();
    expect(get_current_user_id())->toBe($admin)->and(current_user_can('manage_options'))->toBeTrue()
        ->and(fn () => actingAsRole('not_registered'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => actingAs(99999999))->toThrow(InvalidArgumentException::class)
        ->and(fn () => actingAs(new stdClass))->toThrow(InvalidArgumentException::class);
});

it('does not reuse an account named admin without the administrator role', function () {
    $admin = get_user_by('login', 'admin');
    $admin->set_role('subscriber');
    $id = actingAsAdministrator();
    expect($id)->not->toBe($admin->ID)->and(current_user_can('manage_options'))->toBeTrue();
});
