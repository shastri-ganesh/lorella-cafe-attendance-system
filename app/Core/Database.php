<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Class Database
 * Centralized PDO connection manager with transaction management.
 */
class Database
{
    private static ?PDO $instance = null;
    private static array $config = [];

    /**
     * Set database configuration.
     */
    public static function setConfig(array $config): void
    {
        self::$config = $config;
    }

    /**
     * Set explicit PDO instance (e.g. for testing).
     */
    public static function setConnection(PDO $pdo): void
    {
        self::$instance = $pdo;
    }

    /**
     * Get or create PDO singleton connection.
     */
    public static function getConnection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        if (empty(self::$config)) {
            $configPath = dirname(__DIR__, 2) . '/config/database.php';
            if (file_exists($configPath)) {
                $dbConfig = require $configPath;
                $defaultConnection = $dbConfig['default'] ?? 'mysql';
                self::$config = $dbConfig['connections'][$defaultConnection] ?? [];
            }
        }

        $host = self::$config['host'] ?? '127.0.0.1';
        $port = self::$config['port'] ?? 3306;
        $db = self::$config['database'] ?? '';
        $charset = self::$config['charset'] ?? 'utf8mb4';
        $username = self::$config['username'] ?? 'root';
        $password = self::$config['password'] ?? '';
        $options = self::$config['options'] ?? [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";

        try {
            self::$instance = new PDO($dsn, $username, $password, $options);
            return self::$instance;
        } catch (PDOException $e) {
            throw new RuntimeException("Database connection failed: " . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Execute a callback inside an atomic transaction.
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::getConnection();
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Reset connection (useful for long-running scripts / tests).
     */
    public static function disconnect(): void
    {
        self::$instance = null;
    }
}
