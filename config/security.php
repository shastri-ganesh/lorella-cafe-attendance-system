<?php

declare(strict_types=1);

use App\Core\Env;

return [
    /*
    |--------------------------------------------------------------------------
    | Password Hashing
    |--------------------------------------------------------------------------
    | Prefers Argon2id if available in PHP runtime; falls back to BCRYPT.
    */
    'password' => [
        'algo' => defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT,
        'argon2id_options' => [
            'memory_cost' => 65536, // 64 MB
            'time_cost' => 4,
            'threads' => 2,
        ],
        'bcrypt_options' => [
            'cost' => 12,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Session & Cookie Security
    |--------------------------------------------------------------------------
    */
    'session' => [
        'name' => Env::get('SESSION_COOKIE_NAME', 'lorella_session'),
        'lifetime' => (int) Env::get('SESSION_LIFETIME', 7200), // 2 hours
        'path' => '/',
        'domain' => '',
        'secure' => (bool) Env::get('SESSION_SECURE_COOKIE', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')),
        'httponly' => true,
        'samesite' => 'Lax', // Lax for browser navigation + CSRF protection; use Strict if single-origin only
    ],

    /*
    |--------------------------------------------------------------------------
    | Cross-Site Request Forgery (CSRF) Protection
    |--------------------------------------------------------------------------
    */
    'csrf' => [
        'cookie_name' => Env::get('CSRF_COOKIE_NAME', 'lorella_csrf'),
        'header_name' => 'HTTP_X_CSRF_TOKEN',
        'field_name' => '_csrf_token',
        'token_byte_length' => 32,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting & Account Lockout
    |--------------------------------------------------------------------------
    */
    'rate_limit' => [
        'login_max_attempts' => (int) Env::get('LOGIN_MAX_ATTEMPTS', 5),
        'login_lockout_minutes' => (int) Env::get('LOGIN_LOCKOUT_MINUTES', 15),
        'api_general_limit_per_minute' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Two-Factor Authentication (RFC 6238 TOTP)
    |--------------------------------------------------------------------------
    */
    'totp' => [
        'issuer' => Env::get('TOTP_ISSUER', 'Lorella Cafe'),
        'window' => (int) Env::get('TOTP_WINDOW', 1), // +/- 1 30-sec window drift tolerance
        'secret_length' => 16, // 16 bytes = 128-bit Base32 secret
        'recovery_codes_count' => 8,
        'recovery_code_length' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Attendance Selfie Upload Security
    |--------------------------------------------------------------------------
    */
    'uploads' => [
        'allowed_mime_types' => [
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        'max_file_size' => (int) Env::get('UPLOAD_MAX_BYTES', 5242880), // 5MB
        'attendance_directory' => 'storage/uploads/attendance',
    ],
];
