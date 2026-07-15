<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_name('JOSTUMREPORTSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$configFile = dirname(__DIR__) . '/config.php';

if (! file_exists($configFile)) {
    http_response_code(500);
    echo 'Missing config.php. Copy config.example.php to config.php and update your database settings.';
    exit;
}

$config = require $configFile;

require_once __DIR__ . '/functions.php';
