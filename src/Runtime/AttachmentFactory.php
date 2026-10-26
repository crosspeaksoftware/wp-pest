<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Runtime;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class AttachmentFactory extends RecordFactory
{
    public function create(array $attributes = []): int
    {
        if (! function_exists('wp_insert_attachment') || ! function_exists('wp_slash')) {
            throw new RuntimeException('WordPress must be bootstrapped before creating attachments.');
        }
        $id = wp_insert_attachment(wp_slash(array_merge([
            'post_title' => 'WP Pest attachment '.bin2hex(random_bytes(6)),
            'post_status' => 'inherit',
            'post_mime_type' => 'text/plain',
        ], $attributes)), '', (int) ($attributes['post_parent'] ?? 0), true);
        if (is_object($id) && method_exists($id, 'get_error_message')) {
            throw new RuntimeException($id->get_error_message());
        }
        if ((int) $id <= 0) {
            throw new RuntimeException('WordPress did not create the attachment.');
        }

        return (int) $id;
    }

    /** Copy a fixture into uploads; the caller owns cleanup with wp_delete_attachment().
     * @param  array<string, mixed>  $attributes
     */
    public function createFromFile(string $path, array $attributes = []): int
    {
        if (! defined('ABSPATH') || ! function_exists('wp_upload_bits') || ! function_exists('wp_check_filetype') || ! function_exists('update_attached_file')) {
            throw new RuntimeException('WordPress must be bootstrapped before uploading attachments.');
        }
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Attachment fixture is not readable: '.$path);
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Could not read attachment fixture: '.$path);
        }
        $upload = wp_upload_bits(basename($path), null, $contents);
        if ($upload['error']) {
            throw new RuntimeException((string) $upload['error']);
        }
        $id = null;
        try {
            $type = wp_check_filetype($path);
            $id = $this->create(array_merge([
                'post_title' => pathinfo($path, PATHINFO_FILENAME),
                'post_mime_type' => $type['type'] ?: 'application/octet-stream',
                'guid' => $upload['url'],
            ], $attributes));
            update_attached_file($id, $upload['file']);
            require_once ABSPATH.'wp-admin/includes/image.php';
            if (! function_exists('wp_generate_attachment_metadata') || ! function_exists('wp_update_attachment_metadata')) {
                throw new RuntimeException('WordPress attachment metadata API could not be loaded.');
            }
            wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $upload['file']));

            return $id;
        } catch (Throwable $error) {
            if ($id !== null && function_exists('wp_delete_attachment')) {
                wp_delete_attachment($id, true);
            }
            if (is_file($upload['file'])) {
                unlink($upload['file']);
            }
            throw $error;
        }
    }

    protected function get(int $id): object
    {
        if (! function_exists('get_post') || ! is_object($post = get_post($id))) {
            throw new RuntimeException('WordPress could not retrieve the created attachment.');
        }

        return $post;
    }
}
