<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Runtime;

use CrossPeak\WpPest\AjaxResponse;
use CrossPeak\WpPest\WpDieException;
use RuntimeException;

use function CrossPeak\WpPest\captureOutput;

final class ResponseCapture
{
    private static int $ajaxDepth = 0;

    private const DIE_HOOKS = [
        'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler',
        'wp_die_jsonp_handler', 'wp_die_xmlrpc_handler', 'wp_die_xml_handler',
    ];

    public static function intercept(callable $callback): mixed
    {
        if (! function_exists('add_filter') || ! function_exists('wp_parse_args')) {
            throw new RuntimeException('WordPress must be bootstrapped before intercepting wp_die().');
        }

        $saved = self::snapshot(self::DIE_HOOKS);
        $filter = static function (callable $handler): callable {
            return static function ($message = '', $title = '', $arguments = []) use ($handler): void {
                $arguments = wp_parse_args($arguments);
                if (($arguments['exit'] ?? true) === false) {
                    $handler($message, $title, $arguments);

                    return;
                }

                throw new WpDieException($message, (string) $title, $arguments);
            };
        };

        try {
            foreach (self::DIE_HOOKS as $hook) {
                add_filter($hook, $filter, PHP_INT_MAX);
            }

            return $callback();
        } finally {
            self::restore(self::DIE_HOOKS, $saved);
        }
    }

    public static function ajax(callable $callback): AjaxResponse
    {
        if (! function_exists('add_filter')) {
            throw new RuntimeException('WordPress must be bootstrapped before capturing AJAX responses.');
        }

        $hooks = ['wp_doing_ajax', 'status_header'];
        $saved = self::snapshot($hooks);
        $status = 200;
        $termination = null;
        $previousStatus = http_response_code();
        $headers = headers_list();
        $depth = ++self::$ajaxDepth;

        try {
            add_filter('wp_doing_ajax', static fn (): bool => true, PHP_INT_MAX);
            add_filter('status_header', static function (string $header, int $code) use (&$status, $depth): string {
                if (self::$ajaxDepth === $depth) {
                    $status = $code;
                }

                return $header;
            }, PHP_INT_MAX, 2);

            $output = captureOutput(static function () use ($callback, &$termination, &$status): void {
                try {
                    self::intercept($callback);
                } catch (WpDieException $error) {
                    $termination = $error;
                    if (isset($error->arguments['response'])) {
                        $status = (int) $error->arguments['response'];
                    }
                    // Match the AJAX die handler's scalar body (including -1 and 0).
                    echo is_scalar($error->wpMessage) ? (string) $error->wpMessage : '0';
                }
            });

            return new AjaxResponse($output, $status, $termination);
        } finally {
            self::$ajaxDepth--;
            self::restore($hooks, $saved);
            if (! headers_sent()) {
                header_remove();
                foreach ($headers as $header) {
                    header($header, false);
                }
                // PHP 8.5 warns when http_response_code() follows a protocol
                // header. WordPress uses protocol headers in status_header().
                $restoreCode = is_int($previousStatus) ? $previousStatus : 200;
                header('HTTP/1.1 '.$restoreCode, true, $restoreCode);
            }
        }
    }

    /** @param list<string> $hooks
     * @return array<string, mixed>
     */
    private static function snapshot(array $hooks): array
    {
        $saved = [];
        foreach ($hooks as $hook) {
            if (isset($GLOBALS['wp_filter'][$hook])) {
                $saved[$hook] = clone $GLOBALS['wp_filter'][$hook];
            }
        }

        return $saved;
    }

    /** @param list<string> $hooks
     * @param  array<string, mixed>  $saved
     */
    private static function restore(array $hooks, array $saved): void
    {
        foreach ($hooks as $hook) {
            if (array_key_exists($hook, $saved)) {
                $GLOBALS['wp_filter'][$hook] = $saved[$hook];
            } else {
                unset($GLOBALS['wp_filter'][$hook]);
            }
        }
    }
}
