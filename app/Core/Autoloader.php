<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Class Autoloader
 * Lightweight PSR-4 compatible autoloader for Lorella Cafe application.
 * Eliminates external dependency requirements on Hostinger/shared environments.
 */
class Autoloader
{
    /**
     * Namespace prefix mapped to base directory.
     */
    protected const NAMESPACE_PREFIX = 'App\\';

    /**
     * Base directory corresponding to the namespace prefix.
     */
    protected static string $baseDir = '';

    /**
     * Register the PSR-4 autoloader.
     */
    public static function register(string $baseDir): void
    {
        self::$baseDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR;

        spl_autoload_register([self::class, 'loadClass']);
    }

    /**
     * Autoload callback.
     */
    public static function loadClass(string $class): bool
    {
        // Does the class use our namespace prefix?
        $len = strlen(self::NAMESPACE_PREFIX);
        if (strncmp(self::NAMESPACE_PREFIX, $class, $len) !== 0) {
            return false;
        }

        // Get relative class name
        $relativeClass = substr($class, $len);

        // Replace namespace separators with directory separators and append .php
        $file = self::$baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

        if (file_exists($file)) {
            require $file;
            return true;
        }

        return false;
    }
}
