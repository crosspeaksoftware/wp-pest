<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Runtime;

use RuntimeException;

final class UserFactory extends RecordFactory
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes = []): int
    {
        if (! function_exists('wp_insert_user')) {
            throw new RuntimeException('WordPress must be bootstrapped before creating users.');
        }

        $login = 'wp_pest_'.bin2hex(random_bytes(8));
        $attributes = array_merge([
            'user_login' => $login,
            'user_email' => $login.'@example.test',
            'user_pass' => bin2hex(random_bytes(16)),
            'role' => 'subscriber',
        ], $attributes);

        $userId = wp_insert_user($attributes);
        if (function_exists('is_wp_error') && is_wp_error($userId)) {
            throw new RuntimeException($userId->get_error_message());
        }

        return (int) $userId;
    }

    protected function get(int $id): object
    {
        if (! function_exists('get_user_by') || ! is_object($user = get_user_by('id', $id))) {
            throw new RuntimeException('WordPress could not retrieve the created user.');
        }

        return $user;
    }
}
