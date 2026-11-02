<?php

/**
 * Copy this file to config/config.php and fill in the real values.
 * config/config.php is ignored by Git: credentials and secrets are never committed.
 */
return [
    'db' => [
        'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=invfox;charset=utf8mb4',
        'user' => 'invfox_app',            // least-privilege user (SELECT, INSERT, UPDATE, DELETE)
        'password' => 'change-me',
    ],
    'jwt' => [
        'secret' => 'generate-with: php -r "echo bin2hex(random_bytes(32));"',
        'access_ttl' => 900,                // 15 minutes (ADR-005)
        'refresh_ttl' => 604800,            // 7 days
    ],
    'cors_origins' => [
        'http://localhost:8100',            // ionic serve
        'http://localhost',                 // Capacitor Android (androidScheme http)
        'https://localhost',                // Capacitor Android (androidScheme https)
        'capacitor://localhost',            // Capacitor iOS
    ],
    'min_app_version' => '0.1.0',
    'receipts_dir' => __DIR__ . '/../storage/receipts',
];
