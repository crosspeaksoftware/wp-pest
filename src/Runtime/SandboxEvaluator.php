<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Runtime;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/** @internal */
final class SandboxEvaluator
{
    /** @param Closure(list<string>): string $run */
    public function __construct(private readonly Closure $run) {}

    /** @param array<array-key, mixed> $arguments */
    public function evaluate(string $code, array $arguments = []): mixed
    {
        $argumentsJson = json_encode($arguments, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $encoded = var_export(base64_encode($argumentsJson), true);
        $marker = '__WP_PEST_RESULT_'.bin2hex(random_bytes(12)).'__';
        $start = var_export($marker.'START', true);
        $end = var_export($marker.'END', true);
        $script = '$result = (static function (array $args) { global $wpdb;'."\n".$code."\n"
            .'}) (json_decode(base64_decode('.$encoded.'), true, 512, JSON_THROW_ON_ERROR));'
            .'$json = json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);'
            .'echo '.$start.' . $json . '.$end.';';
        $output = ($this->run)(['eval', $script]);
        $position = strpos($output, $marker.'START');
        $endPosition = $position === false ? false : strpos($output, $marker.'END', $position + strlen($marker.'START'));

        if ($position === false || $endPosition === false) {
            throw new RuntimeException('WP-CLI did not return the expected wpEval result markers. Output: '.$output);
        }

        $position += strlen($marker.'START');

        return json_decode(substr($output, $position, $endPosition - $position), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<array-key, mixed> $arguments */
    public function evaluateFile(string $path, array $arguments, string $pluginPath): mixed
    {
        $file = realpath($path);
        $root = realpath($pluginPath);
        if ($file === false || ! is_file($file) || ! is_readable($file)) {
            throw new InvalidArgumentException('wpEvalFile fixture is not a readable file: '.$path);
        }
        if ($root === false || ! str_starts_with($file, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('wpEvalFile fixtures must be inside the plugin under test: '.$path);
        }

        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1));
        $remote = 'WP_PLUGIN_DIR.'.var_export('/plugin-under-test/'.$relative, true);

        return $this->evaluate('return require '.$remote.';', $arguments);
    }
}
