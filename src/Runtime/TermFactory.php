<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Runtime;

use RuntimeException;

final class TermFactory extends RecordFactory
{
    public function create(array $attributes = []): int
    {
        if (! function_exists('wp_insert_term')) {
            throw new RuntimeException('WordPress must be bootstrapped before creating terms.');
        }
        $name = $attributes['name'] ?? 'WP Pest term '.bin2hex(random_bytes(6));
        $taxonomy = $attributes['taxonomy'] ?? 'category';
        unset($attributes['name'], $attributes['taxonomy']);
        $result = wp_insert_term($name, $taxonomy, $attributes);
        if (is_object($result) && method_exists($result, 'get_error_message')) {
            throw new RuntimeException($result->get_error_message());
        }

        return (int) $result['term_id'];
    }

    protected function get(int $id): object
    {
        if (! function_exists('get_term') || ! is_object($term = get_term($id)) || is_a($term, 'WP_Error')) {
            throw new RuntimeException('WordPress could not retrieve the created term.');
        }

        return $term;
    }
}
