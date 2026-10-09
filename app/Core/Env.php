<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Class Env
 * Lightweight .env file parser and environment variable loader.
 */
class Env
{
    /**
     * Cache of loaded environment variables.
     */
    protected static array $variables = [];

    /**
     * Load environment file from given file path.
     */
    public static function load(string $filePath): void
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Ignore comments and empty lines
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Key-Value separation
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $val = trim($parts[1]);

            // Strip surrounding single or double quotes
            if (
                (str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))
            ) {
                $val = substr($val, 1, -1);
            }

            // Parse literal booleans and null
            $parsedValue = match (strtolower($val)) {
                'true', '(true)' => true,
                'false', '(false)' => false,
                'null', '(null)', 'empty' => null,
                default => $val,
            };

            self::$variables[$key] = $parsedValue;
            $_ENV[$key] = $parsedValue;
            $_SERVER[$key] = $parsedValue;
            putenv("{$key}={$val}");
        }
    }

    /**
     * Retrieve environment variable with default fallback.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$variables)) {
            return self::$variables[$key];
        }

        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }

        $envVal = getenv($key);
        if ($envVal !== false) {
            return match (strtolower($envVal)) {
                'true', '(true)' => true,
                'false', '(false)' => false,
                'null', '(null)' => null,
                default => $envVal,
            };
        }

        return $default;
    }
}
