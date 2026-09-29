<?php
// Values come from environment variables (Docker sets them from .env);
// the fallbacks are for a local MySQL/MariaDB with no auth configured.
$env = fn(string $key, string $default) => ($v = getenv($key)) !== false && $v !== '' ? $v : $default;

return [
    'db' => [
        'host' => $env('DB_HOST', '127.0.0.1'),
        'name' => $env('DB_NAME', 'batsignal'),
        'user' => $env('DB_USER', 'root'),
        'pass' => $env('DB_PASS', ''),
        'charset' => 'utf8mb4',
    ],
];
