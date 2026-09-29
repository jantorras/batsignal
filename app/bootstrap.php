<?php
declare(strict_types=1);

error_reporting(E_ALL);
// Errors are shown on screen only in local development (APP_DEBUG unset); Docker sets APP_DEBUG=0.
ini_set('display_errors', getenv('APP_DEBUG') === false || getenv('APP_DEBUG') === '1' ? '1' : '0');

define('APP_ROOT', dirname(__DIR__));

// Simple PSR-4-ish autoloader for the BatSignal\ namespace.
spl_autoload_register(function (string $class) {
    $prefix = 'BatSignal\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = APP_ROOT . '/app/lib/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

// PHPMailer (vendored manually, no Composer on this host)
require APP_ROOT . '/vendor/phpmailer/src/Exception.php';
require APP_ROOT . '/vendor/phpmailer/src/PHPMailer.php';
require APP_ROOT . '/vendor/phpmailer/src/SMTP.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

BatSignal\I18n::set(BatSignal\I18n::detect());

function t(string $key, array $params = []): string
{
    return BatSignal\I18n::t($key, $params);
}

/** Translated and HTML-escaped, for templates. */
function te(string $key, array $params = []): string
{
    return htmlspecialchars(BatSignal\I18n::t($key, $params), ENT_QUOTES, 'UTF-8');
}
