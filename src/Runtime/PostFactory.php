<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Runtime;

use RuntimeException;

final class PostFactory extends RecordFactory
{
    public function __construct(private readonly string $postType = 'post') {}

    public function create(array $attributes = []): int
    {
        if (! function_exists('wp_insert_post') || ! function_exists('wp_slash')) {
            throw new RuntimeException('WordPress must be bootstrapped before creating posts.');
        }
        $id = wp_insert_post(wp_slash(array_merge([
            'post_title' => 'WP Pest '.bin2hex(random_bytes(6)),
            'post_type' => $this->postType,
            'post_status' => 'publish',
        ], $attributes)), true);
        if (is_object($id) && method_exists($id, 'get_error_message')) {
            throw new RuntimeException($id->get_error_message());
        }
        if ((int) $id <= 0) {
            throw new RuntimeException('WordPress did not create the post.');
        }

        return (int) $id;
    }

    protected function get(int $id): object
    {
        if (! function_exists('get_post') || ! is_object($post = get_post($id))) {
            throw new RuntimeException('WordPress could not retrieve the created post.');
        }

        return $post;
    }
}
