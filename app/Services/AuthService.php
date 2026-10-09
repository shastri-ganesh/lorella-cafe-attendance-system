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

            $is2faSetup = $userRepo->isTwoFactorEnabled($userId);

            return [
                'success' => true,
                'status' => 200,
                'message' => 'Two-factor authentication verification required.',
                'data' => [
                    'requires_2fa' => true,
                    'auth_state' => '2fa_pending',
                    'is_2fa_setup' => $is2faSetup,
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
            $pendingUserId = (int) ($_SESSION['pending_user_id'] ?? 0);
            $userRepo ??= new \App\Repositories\UserRepository();
            $is2faSetup = $pendingUserId > 0 ? $userRepo->isTwoFactorEnabled($pendingUserId) : false;

            return [
                'authenticated' => false,
                'auth_state' => '2fa_pending',
                'requires_2fa' => true,
                'is_2fa_setup' => $is2faSetup,
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

    /**
     * Initialize TOTP enrollment for privileged user.
     * Generates Base32 secret, saves unconfirmed record, and produces local SVG QR code.
     */
    public static function setupTwoFactor(
        ?\App\Core\Request $request = null,
        ?\App\Repositories\UserRepository $userRepo = null
    ): array {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::startSecureSession();
        }

        $userId = $_SESSION['user_id'] ?? $_SESSION['pending_user_id'] ?? null;
        $userRole = $_SESSION['user_role'] ?? $_SESSION['pending_role'] ?? null;

        if (!$userId || !$userRole) {
            return [
                'success' => false,
                'status' => 401,
                'message' => 'Unauthenticated. Please log in first.',
            ];
        }

        $privilegedRoles = ['manager', 'admin', 'super_admin'];
        if (!in_array($userRole, $privilegedRoles, true)) {
            return [
                'success' => false,
                'status' => 403,
                'message' => 'Forbidden: Two-factor authentication is restricted to privileged roles.',
            ];
        }

        $userId = (int) $userId;
        $userRepo ??= new \App\Repositories\UserRepository();
        $user = $userRepo->findById($userId);

        if (!$user || $user['status'] !== 'active') {
            return [
                'success' => false,
                'status' => 401,
                'message' => 'User account is inactive or not found.',
            ];
        }

        if ($userRepo->isTwoFactorEnabled($userId)) {
            return [
                'success' => false,
                'status' => 400,
                'message' => 'Two-factor authentication is already enabled and confirmed for this account.',
            ];
        }

        $securityConfig = require dirname(__DIR__, 2) . '/config/security.php';
        $secretLength = $securityConfig['totp']['secret_length'] ?? 16;
        $issuer = $securityConfig['totp']['issuer'] ?? 'Lorella Cafe';

        $secret = TotpService::generateSecret($secretLength);
        $userRepo->saveTwoFactorSecret($userId, $secret);

        $otpauthUri = TotpService::getOtpAuthUri($user['email'], $secret, $issuer);
        $qrSvg = TotpService::generateQrCodeSvg($otpauthUri);
        $qrDataUri = TotpService::generateQrCodeDataUri($otpauthUri);

        AuditService::log('2FA_ENROLLMENT_STARTED', 'two_factor_auth', $userId, $userId, null, [], [], $request);

        return [
            'success' => true,
            'status' => 200,
            'message' => 'Two-factor authentication secret generated. Scan QR code or enter secret into authenticator app.',
            'data' => [
                'secret' => $secret,
                'provisioning_uri' => $otpauthUri,
                'qr_code_svg' => $qrSvg,
                'qr_code_data_uri' => $qrDataUri,
            ],
        ];
    }

    /**
     * Verify TOTP code or single-use recovery code.
     * Supports both enrollment confirmation and login verification.
     */
    public static function verifyTwoFactor(
        ?string $code = null,
        ?string $recoveryCode = null,
        ?\App\Core\Request $request = null,
        ?\App\Repositories\UserRepository $userRepo = null
    ): array {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::startSecureSession();
        }

        $userId = $_SESSION['user_id'] ?? $_SESSION['pending_user_id'] ?? null;
        if (!$userId) {
            return [
                'success' => false,
                'status' => 401,
                'message' => 'Unauthenticated. Please log in first.',
            ];
        }

        $userId = (int) $userId;
        $userRepo ??= new \App\Repositories\UserRepository();
        $user = $userRepo->findById($userId);

        if (!$user || $user['status'] !== 'active') {
            return [
                'success' => false,
                'status' => 401,
                'message' => 'Account is inactive or not found.',
            ];
        }

        $twoFactor = $userRepo->getTwoFactorRecord($userId);
        if (!$twoFactor || empty($twoFactor['secret'])) {
            return [
                'success' => false,
                'status' => 400,
                'message' => 'Two-factor authentication is not configured for this account. Please run setup first.',
            ];
        }

        $isConfirmed = ((int) ($twoFactor['is_enabled'] ?? 0) === 1 && !empty($twoFactor['confirmed_at']));
        $securityConfig = require dirname(__DIR__, 2) . '/config/security.php';
        $window = (int) ($securityConfig['totp']['window'] ?? 1);

        $code = ($code !== null) ? trim($code) : null;
        $recoveryCode = ($recoveryCode !== null) ? trim($recoveryCode) : null;

        // Context A: Enrollment Confirmation
        if (!$isConfirmed) {
            if (!$code) {
                return [
                    'success' => false,
                    'status' => 400,
                    'message' => 'A 6-digit verification code is required to complete enrollment.',
                ];
            }

            if (!TotpService::verifyCode($twoFactor['secret'], $code, $window)) {
                AuditService::log('2FA_ENROLLMENT_VERIFICATION_FAILED', 'two_factor_auth', $userId, $userId, null, [], [], $request);
                return [
                    'success' => false,
                    'status' => 400,
                    'message' => 'Invalid two-factor authentication code.',
                ];
            }

            // Valid code: Confirm 2FA, generate and store hashed recovery codes
            $userRepo->confirmTwoFactor($userId);

            $recoveryCount = (int) ($securityConfig['totp']['recovery_codes_count'] ?? 8);
            $recovery = TotpService::generateRecoveryCodes($recoveryCount);
            $userRepo->storeRecoveryCodes($userId, $recovery['hashed']);

            AuditService::log('2FA_ENROLLMENT_CONFIRMED', 'two_factor_auth', $userId, $userId, null, [], [], $request);

            // If user was logging in and awaiting 2FA, upgrade session
            $isLoggedIn = false;
            if (!empty($_SESSION['auth_state']) && $_SESSION['auth_state'] === '2fa_pending') {
                $cafeIds = $userRepo->getAssignedCafeIds($userId);

                $_SESSION = [];
                $_SESSION['auth_state'] = 'authenticated';
                $_SESSION['user_id'] = $userId;
                $_SESSION['user_role'] = $user['role_slug'];
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_cafe_ids'] = $cafeIds;
                $_SESSION['login_time'] = time();

                self::regenerateSession();
                AuditService::log('LOGIN_2FA_SUCCESS', 'users', $userId, $userId, null, [], [], $request);
                $isLoggedIn = true;
            }

            return [
                'success' => true,
                'status' => 200,
                'message' => 'Two-factor authentication enabled successfully. Save your recovery codes in a secure location.',
                'data' => [
                    'authenticated' => $isLoggedIn || ($_SESSION['auth_state'] ?? '') === 'authenticated',
                    'requires_2fa' => false,
                    'recovery_codes' => $recovery['plain'], // Plain codes returned ONLY once here!
                    'user' => [
                        'id' => (int) $user['id'],
                        'full_name' => $user['full_name'],
                        'email' => $user['email'],
                        'role' => $user['role_slug'],
                        'status' => $user['status'],
                    ],
                ],
            ];
        }

        // Context B: Login Verification (2FA is confirmed)
        if ($code !== null && $code !== '') {
            if (!TotpService::verifyCode($twoFactor['secret'], $code, $window)) {
                AuditService::log('LOGIN_2FA_CODE_FAILED', 'users', $userId, $userId, null, [], [], $request);
                return [
                    'success' => false,
                    'status' => 401,
                    'message' => 'Invalid two-factor authentication code.',
                ];
            }

            $cafeIds = $userRepo->getAssignedCafeIds($userId);
            $_SESSION = [];
            $_SESSION['auth_state'] = 'authenticated';
            $_SESSION['user_id'] = $userId;
            $_SESSION['user_role'] = $user['role_slug'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_cafe_ids'] = $cafeIds;
            $_SESSION['login_time'] = time();

            self::regenerateSession();
            AuditService::log('LOGIN_2FA_SUCCESS', 'users', $userId, $userId, null, [], [], $request);

            return [
                'success' => true,
                'status' => 200,
                'message' => 'Authentication successful.',
                'data' => [
                    'authenticated' => true,
                    'requires_2fa' => false,
                    'user' => [
                        'id' => (int) $user['id'],
                        'full_name' => $user['full_name'],
                        'email' => $user['email'],
                        'role' => $user['role_slug'],
                        'status' => $user['status'],
                    ],
                ],
            ];
        }

        if ($recoveryCode !== null && $recoveryCode !== '') {
            $unusedRecords = $userRepo->getUnusedRecoveryCodes($userId);
            $matchedId = TotpService::verifyRecoveryCode($recoveryCode, $unusedRecords);

            if ($matchedId === null) {
                AuditService::log('LOGIN_2FA_RECOVERY_CODE_FAILED', 'users', $userId, $userId, null, [], [], $request);
                return [
                    'success' => false,
                    'status' => 401,
                    'message' => 'Invalid or already used recovery code.',
                ];
            }

            // Mark single-use recovery code as consumed
            $userRepo->markRecoveryCodeUsed($matchedId);

            $cafeIds = $userRepo->getAssignedCafeIds($userId);
            $_SESSION = [];
            $_SESSION['auth_state'] = 'authenticated';
            $_SESSION['user_id'] = $userId;
            $_SESSION['user_role'] = $user['role_slug'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_cafe_ids'] = $cafeIds;
            $_SESSION['login_time'] = time();

            self::regenerateSession();
            AuditService::log('LOGIN_2FA_RECOVERY_CODE_USED', 'users', $userId, $userId, null, [], ['recovery_code_id' => $matchedId], $request);

            return [
                'success' => true,
                'status' => 200,
                'message' => 'Authentication successful via recovery code.',
                'data' => [
                    'authenticated' => true,
                    'requires_2fa' => false,
                    'recovery_code_used' => true,
                    'user' => [
                        'id' => (int) $user['id'],
                        'full_name' => $user['full_name'],
                        'email' => $user['email'],
                        'role' => $user['role_slug'],
                        'status' => $user['status'],
                    ],
                ],
            ];
        }

        return [
            'success' => false,
            'status' => 400,
            'message' => 'A valid verification code or recovery code is required.',
        ];
    }

    /**
     * Safe two-factor authentication status query.
     */
    public static function getTwoFactorStatus(?\App\Repositories\UserRepository $userRepo = null): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::startSecureSession();
        }

        $userId = $_SESSION['user_id'] ?? $_SESSION['pending_user_id'] ?? null;
        $userRole = $_SESSION['user_role'] ?? $_SESSION['pending_role'] ?? 'staff';

        if (!$userId) {
            return [
                'success' => false,
                'status' => 401,
                'message' => 'Unauthenticated.',
            ];
        }

        $userId = (int) $userId;
        $userRepo ??= new \App\Repositories\UserRepository();
        $record = $userRepo->getTwoFactorRecord($userId);

        $enabled = $record !== null && (int) ($record['is_enabled'] ?? 0) === 1;
        $confirmed = $record !== null && !empty($record['confirmed_at']);
        $required = in_array($userRole, ['manager', 'admin', 'super_admin'], true);

        return [
            'success' => true,
            'status' => 200,
            'message' => 'Two-factor authentication status retrieved.',
            'data' => [
                'enabled' => $enabled,
                'required' => $required,
                'confirmed' => $confirmed,
            ],
        ];
    }
}
