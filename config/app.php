<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'name' => Env::get('APP_NAME', 'Lorella Speciality Cafe'),
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => (bool) Env::get('APP_DEBUG', false),
    'url' => rtrim(Env::get('APP_URL', 'http://localhost'), '/'),
    'key' => Env::get('APP_KEY', ''),
    'timezone' => Env::get('TIMEZONE', 'Asia/Kolkata'),
    'version' => '1.0.0-foundation',

    'storage_path' => dirname(__DIR__) . DIRECTORY_SEPARATOR . (string) Env::get('STORAGE_PATH', 'storage'),
    'upload_max_bytes' => (int) Env::get('UPLOAD_MAX_BYTES', 5242880), // 5MB default

    'allowed_origins' => explode(',', (string) Env::get('ALLOWED_ORIGINS', '')),
];
