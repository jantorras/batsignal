<?php
require __DIR__ . '/../app/bootstrap.php';

use BatSignal\Auth;
use BatSignal\Database;

if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$userCount = (int)Database::get()->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($userCount === 0) {
    header('Location: setup.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (Auth::attempt($username, $password)) {
        header('Location: index.php');
        exit;
    }
    $error = t('auth.bad_credentials');
}

$pageTitle = t('auth.login_title');
require __DIR__ . '/partials/header.php';
?>
<div class="auth-wrapper">
    <div class="card-bat auth-card">
        <div class="auth-head">
            <?= bat_mark('lg') ?>
            <h1>Bat<span style="color: var(--bat-yellow)">Signal</span></h1>
            <p><?= te('auth.tagline') ?></p>
        </div>
        <?php if (isset($_GET['created'])): ?><div class="alert alert-success" role="status"><i class="ph ph-check-circle" aria-hidden="true"></i><?= te('auth.created') ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger" role="alert"><i class="ph ph-x-circle" aria-hidden="true"></i><?= e($error) ?></div><?php endif; ?>
        <form method="post">
            <div class="mb-3">
                <label class="form-label" for="username"><?= te('auth.username') ?></label>
                <input type="text" id="username" name="username" class="form-control" autocomplete="username" required autofocus value="<?= e($_POST['username'] ?? '') ?>">
            </div>
            <div class="mb-4">
                <label class="form-label" for="password"><?= te('auth.password') ?></label>
                <input type="password" id="password" name="password" class="form-control" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-bat w-100" data-busy-label="<?= te('auth.entering') ?>"><i class="ph ph-sign-in" aria-hidden="true"></i><?= te('auth.enter') ?></button>
        </form>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
