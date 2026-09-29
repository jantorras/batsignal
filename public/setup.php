<?php
require __DIR__ . '/../app/bootstrap.php';

use BatSignal\Database;
use BatSignal\I18n;

$db = Database::get();
$userCount = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();

if ($userCount > 0) {
    header('Location: login.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    if ($username === '' || $password === '') {
        $error = t('auth.err_required');
    } elseif ($password !== $password2) {
        $error = t('auth.err_mismatch');
    } elseif (strlen($password) < 8) {
        $error = t('auth.err_short');
    } else {
        $stmt = $db->prepare('INSERT INTO users (username, password_hash, email, lang) VALUES (?, ?, ?, ?)');
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $email ?: null, I18n::get()]);
        header('Location: login.php?created=1');
        exit;
    }
}

$pageTitle = t('auth.setup_title');
require __DIR__ . '/partials/header.php';
?>
<div class="auth-wrapper">
    <div class="card-bat auth-card" style="max-width: 460px;">
        <div class="auth-head">
            <?= bat_mark('lg') ?>
            <h1><?= te('auth.welcome') ?></h1>
            <p><?= te('auth.setup_lead') ?></p>
        </div>
        <?php if ($error): ?><div class="alert alert-danger" role="alert"><i class="ph ph-x-circle" aria-hidden="true"></i><?= e($error) ?></div><?php endif; ?>
        <form method="post">
            <div class="mb-3">
                <label class="form-label" for="username"><?= te('auth.username') ?></label>
                <input type="text" id="username" name="username" class="form-control" autocomplete="username" required autofocus value="<?= e($_POST['username'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label" for="email"><?= te('auth.email') ?> <span class="text-subtle fw-normal"><?= te('common.optional') ?></span></label>
                <input type="email" id="email" name="email" class="form-control" autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label" for="password"><?= te('auth.password') ?></label>
                <input type="password" id="password" name="password" class="form-control" autocomplete="new-password" required minlength="8" aria-describedby="pw-help">
                <div class="form-text" id="pw-help"><?= te('auth.min8') ?></div>
            </div>
            <div class="mb-4">
                <label class="form-label" for="password2"><?= te('auth.repeat') ?></label>
                <input type="password" id="password2" name="password2" class="form-control" autocomplete="new-password" required minlength="8">
            </div>
            <button type="submit" class="btn btn-bat w-100" data-busy-label="<?= te('auth.creating') ?>"><i class="ph ph-user-plus" aria-hidden="true"></i><?= te('auth.create') ?></button>
        </form>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
