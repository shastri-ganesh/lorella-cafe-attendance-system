<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;

/**
 * Class AuthService
 * Centralizes password hashing, credential verification, and session lifecycle.
 */
class AuthService
{
    /**
     * Hash password securely using configured algorithm (Argon2id preferred).
     */
    public static function hashPassword(string $plainPassword): string
    {
        $securityConfig = require dirname(__DIR__, 2) . '/config/security.php';
        $algo = $securityConfig['password']['algo'];

        if ($algo === PASSWORD_ARGON2ID) {
            return password_hash($plainPassword, PASSWORD_ARGON2ID, $securityConfig['password']['argon2id_options']);
        }

        return password_hash($plainPassword, PASSWORD_BCRYPT, $securityConfig['password']['bcrypt_options']);
    }

    /**
     * Verify plain password against stored hash.
     */
    public static function verifyPassword(string $plainPassword, string $hash): bool
    {
        return password_verify($plainPassword, $hash);
    }

    /**
     * Check if stored password hash requires rehashing to newer cost or algorithm.
     */
    public static function needsRehash(string $hash): bool
    {
        $securityConfig = require dirname(__DIR__, 2) . '/config/security.php';
        $algo = $securityConfig['password']['algo'];
        $options = ($algo === PASSWORD_ARGON2ID)
            ? $securityConfig['password']['argon2id_options']
            : $securityConfig['password']['bcrypt_options'];

        return password_needs_rehash($hash, $algo, $options);
    }

    /**
     * Start and configure secure session parameters.
     */
    public static function startSecureSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $securityConfig = require dirname(__DIR__, 2) . '/config/security.php';
        $sessionCfg = $securityConfig['session'];

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_set_cookie_params([
            'lifetime' => $sessionCfg['lifetime'],
            'path' => $sessionCfg['path'],
            'domain' => $sessionCfg['domain'],
            'secure' => $sessionCfg['secure'],
            'httponly' => $sessionCfg['httponly'],
            'samesite' => $sessionCfg['samesite'],
        ]);

        session_name($sessionCfg['name']);
        session_start();
    }

    /**
     * Regenerate session ID upon authentication to prevent session fixation.
     */
    public static function regenerateSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Destroy active session and purge cookie.
     */
    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }
}
