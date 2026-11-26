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
        'https://panenka-booking.web.app',  // PWA on Firebase Hosting (use your Firebase project id)
        'https://panenka-booking.firebaseapp.com',
    ],
    'min_app_version' => '0.1.0',
    'receipts_dir' => __DIR__ . '/../storage/receipts',
    // Online payments (Wompi). The keys are written in InvFox (Configuración → Pagos en línea) and stored
    // encrypted; pnk-api decrypts them with the same installation key: copy 'app' => ['key' => …] of the
    // InvFox config/config.php here (64 hex characters). Never commit it.
    'invfox_app_key' => '',
    // Public address of this API (Cloudflare or ngrok). Wompi sends the player back to
    // <public_url>/api/v1/payments/wompi/return after paying. Empty = Wompi shows its own result page.
    'public_url' => '',
];
