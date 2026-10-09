<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'default' => 'mysql',

    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => Env::get('DB_HOST', '127.0.0.1'),
            'port' => (int) Env::get('DB_PORT', 3306),
            'database' => Env::get('DB_NAME', 'lorella_attendance'),
            'username' => Env::get('DB_USER', 'root'),
            'password' => Env::get('DB_PASS', ''),
            'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
            'collation' => 'utf8mb4_unicode_ci',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => 5,
            ],
        ],
    ],
];
