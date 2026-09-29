<?php
use BatSignal\Auth;
use BatSignal\I18n;

require_once __DIR__ . '/ui.php';
$current = basename($_SERVER['SCRIPT_NAME']);
$nav = [
    'index.php' => ['ph-squares-four', 'nav.dashboard'],
    'sites.php' => ['ph-globe', 'nav.sites'],
    'groups.php' => ['ph-folders', 'nav.groups'],
    'incidents.php' => ['ph-siren', 'nav.incidents'],
    'settings.php' => ['ph-gear-six', 'nav.settings'],
];
$activeKey = $current === 'site.php' ? 'sites.php' : $current;
$alarm = Auth::check()
    && (int)BatSignal\Database::get()->query("SELECT COUNT(*) FROM incidents WHERE status = 'open'")->fetchColumn() > 0;
// Where the language switcher sends us back to (current page, same query).
$langBack = $current . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
?>
<!doctype html>
<html lang="<?= I18n::get() ?>" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' · BatSignal' : 'BatSignal' ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css" rel="stylesheet">
    <link href="assets/css/style.css?v=8" rel="stylesheet">
</head>
<body class="<?= Auth::check() ? '' : 'auth-page' ?>">
<?php if (Auth::check()): ?>
<nav class="navbar navbar-expand-lg navbar-bat" aria-label="<?= te('nav.aria') ?>">
    <div class="container">
        <a class="brand me-4" href="index.php"><?= bat_mark($alarm ? 'alarm' : '') ?><span>Bat<b>Signal</b></span></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu" aria-controls="navMenu" aria-expanded="false" aria-label="<?= te('nav.open_menu') ?>">
            <i class="ph ph-list" style="font-size:1.3rem" aria-hidden="true"></i>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav me-auto">
                <?php foreach ($nav as $href => [$icon, $labelKey]): $isActive = $activeKey === $href; ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $isActive ? 'active' : '' ?>" href="<?= $href ?>" <?= $isActive ? 'aria-current="page"' : '' ?>>
                            <i class="ph <?= $icon ?>" aria-hidden="true"></i><?= te($labelKey) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="navbar-user d-flex align-items-center gap-2 gap-lg-3">
                <?= lang_switcher($langBack) ?>
                <span class="user-chip"><span class="avatar" aria-hidden="true"><?= e(mb_substr((string)Auth::username(), 0, 1)) ?></span><?= e(Auth::username()) ?></span>
                <a class="btn btn-ghost btn-sm" href="logout.php"><i class="ph ph-sign-out" aria-hidden="true"></i><?= te('nav.logout') ?></a>
            </div>
        </div>
    </div>
</nav>
<main class="container pb-4">
<?php else: ?>
<div class="auth-lang"><?= lang_switcher($langBack) ?></div>
<main>
<?php endif; ?>
