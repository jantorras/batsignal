<?php
require __DIR__ . '/../app/bootstrap.php';

use BatSignal\Auth;
use BatSignal\Database;
use BatSignal\I18n;
use BatSignal\Mailer;

Auth::requireLogin();
$db = Database::get();

$fields = ['smtp_host', 'smtp_port', 'smtp_secure', 'smtp_username', 'smtp_password', 'smtp_from_email', 'smtp_from_name', 'notify_email', 'mail_lang'];
$message = null;
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    foreach ($fields as $f) {
        $value = trim($_POST[$f] ?? '');
        // Empty password field means "keep the stored one" — it's never echoed back to the page.
        if ($f === 'smtp_password' && $value === '') {
            continue;
        }
        if ($f === 'mail_lang' && !I18n::isValid($value)) {
            continue;
        }
        $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([$f, $value]);
    }

    if ($action === 'test') {
        $mailLang = I18n::isValid($_POST['mail_lang'] ?? null) ? $_POST['mail_lang'] : I18n::DEFAULT;
        $ok = Mailer::send(I18n::t('settings.test_subject', [], $mailLang), '<p>' . htmlspecialchars(I18n::t('settings.test_body', [], $mailLang)) . '</p>');
        $testResult = $ok ? 'success' : 'error';
    } else {
        $message = t('settings.saved');
    }
}

$settings = [];
foreach ($db->query('SELECT setting_key, setting_value FROM settings')->fetchAll() as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$hasPassword = ($settings['smtp_password'] ?? '') !== '';
$smtpConfigured = ($settings['smtp_host'] ?? '') !== '' && ($settings['notify_email'] ?? '') !== '';
$mailLang = I18n::isValid($settings['mail_lang'] ?? null) ? $settings['mail_lang'] : I18n::DEFAULT;

$pageTitle = t('settings.title');
require __DIR__ . '/partials/header.php';
?>
<header class="page-header">
    <div>
        <span class="eyebrow"><?= te('settings.eyebrow') ?></span>
        <h1><?= te('settings.title') ?></h1>
        <p class="lead-text"><?= te('settings.lead') ?></p>
    </div>
    <?= $smtpConfigured ? status_badge('active') : '<span class="status status-warning"><i class="ph ph-warning" aria-hidden="true"></i>' . te('status.mail_off') . '</span>' ?>
</header>

<?php if ($message): ?><div class="alert alert-success" role="status"><i class="ph ph-check-circle" aria-hidden="true"></i><?= e($message) ?></div><?php endif; ?>
<?php if ($testResult === 'success'): ?><div class="alert alert-success" role="status"><i class="ph ph-paper-plane-tilt" aria-hidden="true"></i><?= te('settings.test_ok') ?></div><?php endif; ?>
<?php if ($testResult === 'error'): ?><div class="alert alert-danger" role="alert"><i class="ph ph-x-circle" aria-hidden="true"></i><?= te('settings.test_fail') ?></div><?php endif; ?>

<form method="post">
    <section class="card-bat mb-4">
        <div class="card-header"><span class="card-title-icon"><i class="ph ph-bell-ringing" aria-hidden="true"></i><?= te('settings.recipients') ?></span></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label" for="notify_email"><?= te('settings.recipients_label') ?></label>
                    <input type="text" id="notify_email" name="notify_email" class="form-control" value="<?= e($settings['notify_email'] ?? '') ?>" placeholder="tu@domini.com, altre@domini.com">
                    <div class="form-text"><?= te('settings.recipients_help') ?></div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="mail_lang"><?= te('settings.mail_lang') ?></label>
                    <select id="mail_lang" name="mail_lang" class="form-select">
                        <?php foreach (I18n::LANGS as $code => $label): ?>
                            <option value="<?= $code ?>" lang="<?= $code ?>" <?= $mailLang === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </section>

    <section class="card-bat mb-4">
        <div class="card-header"><span class="card-title-icon"><i class="ph ph-envelope-simple" aria-hidden="true"></i><?= te('settings.smtp_title') ?></span></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="smtp_host"><?= te('settings.host') ?></label>
                    <input type="text" id="smtp_host" name="smtp_host" class="form-control mono" value="<?= e($settings['smtp_host'] ?? '') ?>" placeholder="smtp.domini.com">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label" for="smtp_port"><?= te('settings.port') ?></label>
                    <input type="number" id="smtp_port" name="smtp_port" class="form-control mono" value="<?= e($settings['smtp_port'] ?? '587') ?>">
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label" for="smtp_secure"><?= te('settings.security') ?></label>
                    <select id="smtp_secure" name="smtp_secure" class="form-select">
                        <option value="tls" <?= ($settings['smtp_secure'] ?? '') === 'tls' ? 'selected' : '' ?>>STARTTLS (port 587)</option>
                        <option value="ssl" <?= ($settings['smtp_secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL/TLS (port 465)</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="smtp_username"><?= te('settings.user') ?></label>
                    <input type="text" id="smtp_username" name="smtp_username" class="form-control" autocomplete="off" value="<?= e($settings['smtp_username'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="smtp_password"><?= te('settings.password') ?></label>
                    <input type="password" id="smtp_password" name="smtp_password" class="form-control" autocomplete="new-password"
                           placeholder="<?= $hasPassword ? te('settings.password_kept') : '' ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="smtp_from_email"><?= te('settings.from_email') ?></label>
                    <input type="email" id="smtp_from_email" name="smtp_from_email" class="form-control" value="<?= e($settings['smtp_from_email'] ?? '') ?>" placeholder="alertes@domini.com">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="smtp_from_name"><?= te('settings.from_name') ?></label>
                    <input type="text" id="smtp_from_name" name="smtp_from_name" class="form-control" value="<?= e($settings['smtp_from_name'] ?? 'BatSignal') ?>">
                </div>
            </div>
        </div>
    </section>

    <div class="d-flex flex-wrap gap-2">
        <button type="submit" name="action" value="save" class="btn btn-bat" data-busy-label="<?= te('common.saving') ?>"><i class="ph ph-floppy-disk" aria-hidden="true"></i><?= te('common.save') ?></button>
        <button type="submit" name="action" value="test" class="btn btn-ghost" data-busy-label="<?= te('settings.sending') ?>"><i class="ph ph-paper-plane-tilt" aria-hidden="true"></i><?= te('settings.save_test') ?></button>
    </div>
</form>
<?php require __DIR__ . '/partials/footer.php'; ?>
