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

        if (!headers_sent()) {
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
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }

    /**
     * Regenerate session ID upon authentication to prevent session fixation.
     */
    public static function regenerateSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
    }

    /**
     * Authenticate user with login credentials.
     * Enforces account status, lockout rules, Argon2id rehash detection, and two-stage 2FA state.
     */
    public static function authenticate(
        string $identifier,
        string $password,
        ?\App\Core\Request $request = null,
        ?\App\Repositories\UserRepository $userRepo = null
    ): array {
        $identifier = trim($identifier);
        if ($identifier === '' || $password === '') {
            return [
                'success' => false,
                'status' => 400,
                'message' => 'Email and password are required.',
                'errors' => ['credentials' => 'Email and password are required.'],
            ];
        }

        $userRepo ??= new \App\Repositories\UserRepository();
        $user = $userRepo->findByEmail($identifier);

        // Security: Generic message if account not found (prevents email enumeration)
        if (!$user) {
            AuditService::log('LOGIN_FAILED_UNKNOWN_USER', 'users', null, null, null, [], ['identifier' => $identifier], $request);
            return [
                'success' => false,
                'status' => 401,
                'message' => 'Invalid credentials.',
                'errors' => [],
            ];
        }

        $userId = (int) $user['id'];

        // Check if account is temporarily locked
        if ($userRepo->isAccountLocked($user)) {
            AuditService::log('LOGIN_ATTEMPT_LOCKED_ACCOUNT', 'users', $userId, null, null, [], ['email' => $user['email']], $request);
            return [
                'success' => false,
                'status' => 423,
                'message' => 'Account is temporarily locked due to excessive failed attempts. Please try again later.',
                'errors' => [],
            ];
        }

        // Check account status (active vs inactive / suspended)
        if ($user['status'] !== 'active') {
            AuditService::log('LOGIN_ATTEMPT_INACTIVE_ACCOUNT', 'users', $userId, null, null, [], ['status' => $user['status']], $request);
            return [
                'success' => false,
                'status' => 401,
                'message' => 'Account is inactive or suspended. Please contact administrator.',
                'errors' => [],
            ];
        }

        // Verify password with secure hash algorithm
        if (!self::verifyPassword($password, $user['password_hash'])) {
            $securityConfig = require dirname(__DIR__, 2) . '/config/security.php';
            $maxAttempts = $securityConfig['rate_limit']['login_max_attempts'] ?? 5;
            $lockoutMinutes = $securityConfig['rate_limit']['login_lockout_minutes'] ?? 15;

            $userRepo->incrementFailedAttempts($userId, $lockoutMinutes, $maxAttempts);

            if (($user['failed_attempts'] + 1) >= $maxAttempts) {
                AuditService::log('ACCOUNT_LOCKED', 'users', $userId, null, null, [], ['failed_attempts' => $user['failed_attempts'] + 1], $request);
            } else {
                AuditService::log('LOGIN_FAILED_INVALID_PASSWORD', 'users', $userId, null, null, [], [], $request);
            }

            return [
                'success' => false,
                'status' => 401,
                'message' => 'Invalid credentials.',
                'errors' => [],
            ];
        }

        // Credentials valid: Reset failed attempts & update last login
        $userRepo->resetFailedAttempts($userId);
        $userRepo->updateLastLogin($userId);

        // Dynamic password rehashing
        if (self::needsRehash($user['password_hash'])) {
            $newHash = self::hashPassword($password);
            $userRepo->updatePasswordHash($userId, $newHash);
            AuditService::log('PASSWORD_REHASHED', 'users', $userId, $userId, null, [], [], $request);
        }

        // Initialize and regenerate session to prevent session fixation
        self::startSecureSession();
        self::regenerateSession();

        $roleSlug = $user['role_slug'] ?? 'staff';
        $privilegedRoles = ['manager', 'admin', 'super_admin'];

        // Two-Stage Authentication Preparation
        if (in_array($roleSlug, $privilegedRoles, true)) {
            // Establish 2FA pending state without granting privileged access
            $_SESSION = [];
            $_SESSION['auth_state'] = '2fa_pending';
            $_SESSION['2fa_pending'] = true;
            $_SESSION['pending_user_id'] = $userId;
            $_SESSION['pending_role'] = $roleSlug;
            $_SESSION['login_time'] = time();

            AuditService::log('LOGIN_PASSWORD_VERIFIED_2FA_PENDING', 'users', $userId, $userId, null, [], [], $request);

            return [
                'success' => true,
                'status' => 200,
                'message' => 'Two-factor authentication verification required.',
                'data' => [
                    'requires_2fa' => true,
                    'auth_state' => '2fa_pending',
                ],
            ];
        }

        // Non-privileged Staff: Establish full authenticated session
        $cafeIds = $userRepo->getAssignedCafeIds($userId);

        $_SESSION = [];
        $_SESSION['auth_state'] = 'authenticated';
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_role'] = $roleSlug;
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_cafe_ids'] = $cafeIds;
        $_SESSION['login_time'] = time();

        AuditService::log('LOGIN_SUCCESS', 'users', $userId, $userId, null, [], [], $request);

        return [
            'success' => true,
            'status' => 200,
            'message' => 'Login successful.',
            'data' => [
                'requires_2fa' => false,
                'auth_state' => 'authenticated',
                'user' => [
                    'id' => $userId,
                    'full_name' => $user['full_name'],
                    'email' => $user['email'],
                    'role' => $roleSlug,
                    'status' => $user['status'],
                ],
            ],
        ];
    }

    /**
     * Get safe information about currently authenticated user or 2FA pending state.
     */
    public static function getAuthenticatedUser(?\App\Repositories\UserRepository $userRepo = null): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::startSecureSession();
        }

        // Check if user is in 2FA pending state
        if (!empty($_SESSION['auth_state']) && $_SESSION['auth_state'] === '2fa_pending') {
            return [
                'authenticated' => false,
                'auth_state' => '2fa_pending',
                'requires_2fa' => true,
            ];
        }

        if (empty($_SESSION['user_id']) || empty($_SESSION['auth_state']) || $_SESSION['auth_state'] !== 'authenticated') {
            return null;
        }

        $userId = (int) $_SESSION['user_id'];
        $userRepo ??= new \App\Repositories\UserRepository();
        $user = $userRepo->findById($userId);

        if (!$user || $user['status'] !== 'active') {
            self::logout();
            return null;
        }

        $cafes = $userRepo->getAssignedCafes($userId);

        return [
            'authenticated' => true,
            'auth_state' => 'authenticated',
            'requires_2fa' => false,
            'user' => [
                'id' => (int) $user['id'],
                'full_name' => $user['full_name'],
                'email' => $user['email'],
                'role' => $user['role_slug'],
                'status' => $user['status'],
            ],
            'cafes' => $cafes,
        ];
    }

    /**
     * Destroy active session, clear cookie, and record audit log.
     */
    public static function logout(?\App\Core\Request $request = null): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::startSecureSession();
        }

        $actorId = $_SESSION['user_id'] ?? $_SESSION['pending_user_id'] ?? null;
        if ($actorId !== null) {
            AuditService::log('LOGOUT', 'users', (int) $actorId, (int) $actorId, null, [], [], $request);
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies') && !headers_sent()) {
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

        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }
    }
}
