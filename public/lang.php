<?php
require __DIR__ . '/../app/bootstrap.php';

use BatSignal\Auth;
use BatSignal\I18n;

$lang = $_GET['set'] ?? '';
if (I18n::isValid($lang)) {
    Auth::saveLanguage($lang);
    setcookie('bs_lang', $lang, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true]);
}

// Only redirect back to one of our own pages (no open redirect).
$back = (string)($_GET['back'] ?? '');
if (!preg_match('/^[a-z_]+\.php(\?[A-Za-z0-9_=&%-]*)?$/', $back)) {
    $back = 'index.php';
}
header('Location: ' . $back);
exit;
