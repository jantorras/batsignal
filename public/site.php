<?php
require __DIR__ . '/../app/bootstrap.php';

use BatSignal\Auth;
use BatSignal\CheckRunner;
use BatSignal\Database;
use BatSignal\DefaultChecks;

Auth::requireLogin();
$db = Database::get();

$siteId = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM sites WHERE id = ?');
$stmt->execute([$siteId]);
$site = $stmt->fetch();

if (!$site) {
    header('Location: sites.php');
    exit;
}

$error = null;
$lines = fn(string $field) => array_values(array_filter(array_map('trim', explode("\n", $_POST[$field] ?? ''))));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_check') {
        $checkId = (int)($_POST['check_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $type = in_array($_POST['type'] ?? '', ['http', 'ssl', 'crawl'], true) ? $_POST['type'] : 'http';
        $url = trim($_POST['url'] ?? '');
        $frequency = max(1, (int)($_POST['frequency_minutes'] ?? 5));
        $threshold = max(1, (int)($_POST['failure_threshold'] ?? 2));

        if ($name === '' || $url === '') {
            $error = t('sites.err_required');
        } else {
            $config = match ($type) {
                'http' => [
                    'expected_status' => (int)($_POST['expected_status'] ?? 200),
                    'timeout_seconds' => (int)($_POST['timeout_seconds'] ?? 30),
                    'required_keyword' => trim($_POST['required_keyword'] ?? ''),
                    'forbidden_keywords' => $lines('forbidden_keywords'),
                    'warn_ms' => (int)($_POST['warn_ms'] ?? 3000),
                    'critical_ms' => (int)($_POST['critical_ms'] ?? 10000),
                ],
                'crawl' => [
                    'max_pages' => max(1, min(500, (int)($_POST['max_pages'] ?? 100))),
                    'timeout_seconds' => (int)($_POST['timeout_seconds'] ?? 30),
                    'slow_ms' => (int)($_POST['slow_ms'] ?? 8000),
                    'forbidden_keywords' => $lines('forbidden_keywords'),
                ],
                'ssl' => [
                    'warn_days' => (int)($_POST['warn_days'] ?? 30),
                    'critical_days' => (int)($_POST['critical_days'] ?? 14),
                ],
            };

            if ($checkId > 0) {
                $db->prepare(
                    'UPDATE checks SET name=?, type=?, url=?, config=?, frequency_minutes=?, failure_threshold=? WHERE id=? AND site_id=?'
                )->execute([$name, $type, $url, json_encode($config), $frequency, $threshold, $checkId, $siteId]);
            } else {
                $db->prepare(
                    'INSERT INTO checks (site_id, name, type, url, config, frequency_minutes, failure_threshold) VALUES (?,?,?,?,?,?,?)'
                )->execute([$siteId, $name, $type, $url, json_encode($config), $frequency, $threshold]);
            }
            header("Location: site.php?id={$siteId}");
            exit;
        }
    } elseif ($action === 'add_defaults') {
        $added = DefaultChecks::createMissing($siteId, $site['base_url']);
        header("Location: site.php?id={$siteId}&added={$added}");
        exit;
    } elseif ($action === 'run_now') {
        // Release the session lock so other tabs stay usable while a crawl runs.
        session_write_close();
        set_time_limit(600);
        $stmt = $db->prepare('SELECT * FROM checks WHERE site_id = ? AND is_active = 1 ORDER BY (type = \'crawl\')');
        $stmt->execute([$siteId]);
        $ran = 0;
        foreach ($stmt->fetchAll() as $check) {
            CheckRunner::runAndRecord($check);
            $ran++;
        }
        header("Location: site.php?id={$siteId}&ran={$ran}");
        exit;
    } elseif ($action === 'toggle_check') {
        $checkId = (int)($_POST['check_id'] ?? 0);
        $db->prepare('UPDATE checks SET is_active = NOT is_active WHERE id = ? AND site_id = ?')->execute([$checkId, $siteId]);
        header("Location: site.php?id={$siteId}");
        exit;
    } elseif ($action === 'delete_check') {
        $checkId = (int)($_POST['check_id'] ?? 0);
        $db->prepare('DELETE FROM checks WHERE id = ? AND site_id = ?')->execute([$checkId, $siteId]);
        header("Location: site.php?id={$siteId}");
        exit;
    }
}

$stmt = $db->prepare(
    "SELECT c.*, cr.status AS last_status, cr.detail AS last_detail, cr.run_at AS last_run
     FROM checks c
     LEFT JOIN check_runs cr ON cr.id = (SELECT id FROM check_runs WHERE check_id = c.id ORDER BY run_at DESC LIMIT 1)
     WHERE c.site_id = ?
     ORDER BY FIELD(c.type, 'http', 'crawl', 'ssl'), c.name"
);
$stmt->execute([$siteId]);
$checks = $stmt->fetchAll();

$missingDefaults = DefaultChecks::missing($siteId, $site['base_url']);

$editCheck = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM checks WHERE id = ? AND site_id = ?');
    $stmt->execute([(int)$_GET['edit'], $siteId]);
    $editCheck = $stmt->fetch() ?: null;
}
$editConfig = $editCheck ? (json_decode((string)$editCheck['config'], true) ?: []) : [];
$isEditing = $editCheck !== null;
$formType = $editCheck['type'] ?? 'http';
$formOpen = $isEditing || $error !== null;
$cfg = fn(string $key, $default) => e((string)($editConfig[$key] ?? $default));

$stmt = $db->prepare(
    "SELECT cr.*, c.name AS check_name FROM check_runs cr
     JOIN checks c ON c.id = cr.check_id
     WHERE c.site_id = ? ORDER BY cr.run_at DESC LIMIT 20"
);
$stmt->execute([$siteId]);
$recentRuns = $stmt->fetchAll();

$stmt = $db->prepare('SELECT g.id, g.name, g.color FROM site_groups g JOIN site_group_members m ON m.group_id = g.id WHERE m.site_id = ? ORDER BY g.name');
$stmt->execute([$siteId]);
$siteGroups = $stmt->fetchAll();

$pageTitle = $site['name'];
require __DIR__ . '/partials/header.php';
$kinds = [
    'http' => ['ph-browser', 'site.kind_http', 'site.kind_http_help'],
    'crawl' => ['ph-tree-structure', 'site.kind_crawl', 'site.kind_crawl_help'],
    'ssl' => ['ph-lock-key', 'site.kind_ssl', 'site.kind_ssl_help'],
];
?>
<nav aria-label="breadcrumb" class="mt-4">
    <a href="sites.php" class="small text-muted-bat"><i class="ph ph-arrow-left" aria-hidden="true"></i> <?= te('site.back') ?></a>
</nav>
<header class="page-header mt-2">
    <div>
        <span class="eyebrow"><?= te('site.eyebrow') ?></span>
        <h1><?= e($site['name']) ?></h1>
        <?php if ($siteGroups): ?><div class="chips mb-2" aria-label="<?= te('site.groups') ?>"><?php foreach ($siteGroups as $g) echo group_chip($g); ?></div><?php endif; ?>
        <p class="lead-text mono"><a href="<?= e($site['base_url']) ?>" target="_blank" rel="noopener noreferrer" class="text-muted-bat"><?= e($site['base_url']) ?> <i class="ph ph-arrow-square-out" aria-hidden="true"></i></a></p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <?= status_badge($site['is_active'] ? 'active' : 'paused') ?>
        <?php if ($checks): ?>
        <form method="post" data-run-site="<?= $siteId ?>" data-site-name="<?= e($site['name']) ?>">
            <input type="hidden" name="action" value="run_now">
            <button type="submit" class="btn btn-bat" data-busy-label="<?= te('site.running') ?>"><i class="ph ph-lightning" aria-hidden="true"></i><?= te('site.run_now') ?></button>
        </form>
        <?php endif; ?>
    </div>
</header>

<?php if ($error): ?><div class="alert alert-danger" role="alert"><i class="ph ph-x-circle" aria-hidden="true"></i><?= e($error) ?></div><?php endif; ?>
<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success" role="status"><i class="ph ph-check-circle" aria-hidden="true"></i><div><?= te('site.flash_created') ?></div></div>
<?php endif; ?>
<?php if (isset($_GET['added'])): ?>
    <div class="alert alert-success" role="status"><i class="ph ph-check-circle" aria-hidden="true"></i><?= te('site.flash_added', ['n' => (int)$_GET['added']]) ?></div>
<?php endif; ?>
<?php if (isset($_GET['ran'])): ?>
    <div class="alert alert-success" role="status"><i class="ph ph-lightning" aria-hidden="true"></i><?= te('site.flash_ran', ['n' => (int)$_GET['ran']]) ?></div>
<?php endif; ?>

<?php if ($missingDefaults): ?>
<section class="card-bat mb-4" style="border-color: rgba(245,197,24,.45)">
    <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex gap-3 align-items-start">
            <div class="stat-icon brand"><i class="ph ph-shield-warning" aria-hidden="true"></i></div>
            <div>
                <div class="text-white fw-semibold"><?= te('site.missing_title') ?></div>
                <div class="text-muted-bat small"><?= te('site.missing_list', ['list' => implode(', ', array_column($missingDefaults, 'name'))]) ?></div>
            </div>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="add_defaults">
            <button type="submit" class="btn btn-bat"><i class="ph ph-magic-wand" aria-hidden="true"></i><?= te('site.enable_all') ?></button>
        </form>
    </div>
</section>
<?php endif; ?>

<section class="card-bat mb-4">
    <div class="card-header">
        <span class="card-title-icon"><i class="ph ph-list-checks" aria-hidden="true"></i><?= te('site.watched_title') ?></span>
        <span class="text-subtle small fw-normal"><?= te('site.n_checks', ['n' => count($checks)]) ?></span>
    </div>
    <?php if (empty($checks)): ?>
        <?= empty_state('ph-list-checks', t('site.empty_title'), t('site.empty_text')) ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-bat table-hover">
            <thead>
            <tr>
                <th scope="col"><?= te('common.status') ?></th>
                <th scope="col"><?= te('common.check') ?></th>
                <th scope="col" class="d-none d-lg-table-cell"><?= te('site.col_last_result') ?></th>
                <th scope="col" class="text-end"><span class="visually-hidden"><?= te('common.actions') ?></span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($checks as $c): $pauseKey = $c['is_active'] ? 'pause' : 'resume'; ?>
                <tr class="<?= $c['is_active'] ? '' : 'opacity-50' ?>">
                    <td><?= $c['is_active'] ? status_badge($c['last_status']) : status_badge('paused') ?></td>
                    <td style="min-width: 280px;">
                        <div class="d-flex align-items-center gap-2">
                            <span class="cell-primary"><?= e(cn($c['name'])) ?></span>
                            <span class="type-tag"><?= e(type_label($c['type'])) ?></span>
                        </div>
                        <div class="text-subtle small mt-1"><?= e(describe_check($c)) ?></div>
                    </td>
                    <td class="cell-detail small d-none d-lg-table-cell">
                        <?php if ($c['last_run']): ?>
                            <div><?= $c['last_detail'] ? detail_block($c['last_detail'], in_array($c['last_status'], ['down', 'degraded', 'warning'], true)) : te('site.all_ok') ?></div>
                            <div class="mono text-subtle"><?= e(date('d/m H:i', strtotime($c['last_run']))) ?></div>
                        <?php else: ?>
                            <span class="text-subtle"><?= te('site.never_ran') ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="cell-actions">
                        <div class="actions">
                            <a href="site.php?id=<?= $siteId ?>&edit=<?= (int)$c['id'] ?>#check-form" class="btn btn-ghost btn-sm btn-icon" aria-label="<?= te('common.edit_x', ['name' => cn($c['name'])]) ?>" title="<?= te('common.edit') ?>"><i class="ph ph-pencil-simple" aria-hidden="true"></i></a>
                            <form method="post">
                                <input type="hidden" name="action" value="toggle_check">
                                <input type="hidden" name="check_id" value="<?= (int)$c['id'] ?>">
                                <button class="btn btn-ghost btn-sm btn-icon" type="submit" aria-label="<?= te('common.' . $pauseKey . '_x', ['name' => cn($c['name'])]) ?>" title="<?= te('common.' . $pauseKey) ?>">
                                    <i class="ph <?= $c['is_active'] ? 'ph-pause' : 'ph-play' ?>" aria-hidden="true"></i>
                                </button>
                            </form>
                            <form method="post" onsubmit="return confirm(<?= e(json_encode(t('site.confirm_delete_check'), JSON_UNESCAPED_UNICODE)) ?>);">
                                <input type="hidden" name="action" value="delete_check">
                                <input type="hidden" name="check_id" value="<?= (int)$c['id'] ?>">
                                <button class="btn btn-danger-ghost btn-sm btn-icon" type="submit" aria-label="<?= te('common.delete_x', ['name' => cn($c['name'])]) ?>" title="<?= te('common.delete') ?>">
                                    <i class="ph ph-trash" aria-hidden="true"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<section class="card-bat mb-4" id="check-form">
    <div class="card-header">
        <span class="card-title-icon"><i class="ph <?= $isEditing ? 'ph-pencil-simple' : 'ph-sliders-horizontal' ?>" aria-hidden="true"></i><?= $isEditing ? te('site.edit_title', ['name' => cn($editCheck['name'])]) : te('site.custom_title') ?></span>
        <?php if (!$isEditing): ?>
            <button class="btn btn-ghost btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#checkFormBody" aria-expanded="<?= $formOpen ? 'true' : 'false' ?>" aria-controls="checkFormBody">
                <i class="ph ph-plus" aria-hidden="true"></i><?= te('site.add') ?>
            </button>
        <?php endif; ?>
    </div>
    <?php if (!$formOpen): ?>
        <div class="card-body pt-3 pb-3 text-muted-bat small"><?= te('site.custom_help') ?></div>
    <?php endif; ?>
    <div class="collapse <?= $formOpen ? 'show' : '' ?>" id="checkFormBody">
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="action" value="save_check">
            <input type="hidden" name="check_id" value="<?= $isEditing ? (int)$editCheck['id'] : 0 ?>">

            <span class="form-label d-block" id="ck-type-label"><?= te('site.what_check') ?></span>
            <div class="row g-2 mb-3" role="radiogroup" aria-labelledby="ck-type-label">
                <?php foreach ($kinds as $value => [$icon, $labelKey, $helpKey]): ?>
                <div class="col-md-4">
                    <input type="radio" class="btn-check" name="type" id="type-<?= $value ?>" value="<?= $value ?>" <?= $formType === $value ? 'checked' : '' ?>>
                    <label class="btn btn-ghost w-100 h-100 d-block text-start p-3" for="type-<?= $value ?>">
                        <span class="d-flex align-items-center gap-2"><i class="ph <?= $icon ?>" aria-hidden="true"></i><?= te($labelKey) ?></span>
                        <span class="d-block small fw-normal text-muted-bat mt-1"><?= te($helpKey) ?></span>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label" for="ck-name"><?= te('common.name') ?></label>
                    <input type="text" id="ck-name" name="name" class="form-control" required
                           value="<?= e($editCheck['name'] ?? '') ?>" placeholder="<?= te('site.name_ph') ?>">
                </div>
                <div class="col-md-7">
                    <label class="form-label" for="ck-url"><?= te('site.url') ?> <span class="text-subtle fw-normal" data-for="crawl"><?= te('site.url_start') ?></span></label>
                    <input type="url" id="ck-url" name="url" class="form-control mono" required value="<?= e($editCheck['url'] ?? $site['base_url']) ?>">
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label" for="ck-freq"><?= te('site.every_minutes') ?></label>
                    <input type="number" id="ck-freq" min="1" name="frequency_minutes" class="form-control" value="<?= e((string)($editCheck['frequency_minutes'] ?? 5)) ?>">
                </div>
                <div class="col-sm-6 col-md-4">
                    <label class="form-label" for="ck-thr"><?= te('site.threshold') ?></label>
                    <input type="number" id="ck-thr" min="1" name="failure_threshold" class="form-control" value="<?= e((string)($editCheck['failure_threshold'] ?? 2)) ?>">
                </div>
            </div>

            <fieldset data-for="http">
                <div class="form-section-title"><?= te('site.sec_response') ?></div>
                <div class="row g-3">
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label" for="ck-status"><?= te('site.expected_status') ?></label>
                        <input type="number" id="ck-status" name="expected_status" class="form-control" value="<?= $cfg('expected_status', 200) ?>">
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label" for="ck-timeout"><?= te('site.timeout') ?></label>
                        <input type="number" id="ck-timeout" name="timeout_seconds" class="form-control" value="<?= $cfg('timeout_seconds', 30) ?>">
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label" for="ck-warn"><?= te('site.warn_ms') ?></label>
                        <input type="number" id="ck-warn" name="warn_ms" class="form-control" value="<?= $cfg('warn_ms', 3000) ?>">
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label" for="ck-crit"><?= te('site.crit_ms') ?></label>
                        <input type="number" id="ck-crit" name="critical_ms" class="form-control" value="<?= $cfg('critical_ms', 10000) ?>">
                    </div>
                    <div class="col-12 form-text mt-1"><?= te('site.slow_help') ?></div>
                    <div class="col-12">
                        <label class="form-label" for="ck-req"><?= te('site.required') ?> <span class="text-subtle fw-normal"><?= te('common.optional') ?></span></label>
                        <input type="text" id="ck-req" name="required_keyword" class="form-control" placeholder="<?= te('site.required_ph') ?>" value="<?= $cfg('required_keyword', '') ?>">
                        <div class="form-text"><?= te('site.required_help') ?></div>
                    </div>
                </div>
            </fieldset>

            <fieldset data-for="crawl">
                <div class="form-section-title"><?= te('site.sec_crawl') ?></div>
                <div class="row g-3">
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label" for="ck-max"><?= te('site.max_pages') ?></label>
                        <input type="number" id="ck-max" min="1" max="500" name="max_pages" class="form-control" value="<?= $cfg('max_pages', 100) ?>">
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label" for="ck-ctimeout"><?= te('site.page_timeout') ?></label>
                        <input type="number" id="ck-ctimeout" name="timeout_seconds" class="form-control" value="<?= $cfg('timeout_seconds', 30) ?>">
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label" for="ck-slow"><?= te('site.slow_ms') ?></label>
                        <input type="number" id="ck-slow" name="slow_ms" class="form-control" value="<?= $cfg('slow_ms', 8000) ?>">
                    </div>
                    <div class="col-12 form-text mt-1"><?= te('site.max_pages_help') ?> <?= te('site.slow_help') ?></div>
                </div>
            </fieldset>

            <fieldset data-for="http crawl">
                <div class="form-section-title"><?= te('site.sec_content') ?></div>
                <label class="form-label" for="ck-forb"><?= te('site.forbidden') ?> <span class="text-subtle fw-normal"><?= te('common.optional') ?></span></label>
                <textarea id="ck-forb" name="forbidden_keywords" class="form-control mono" rows="3" placeholder="<?= te('site.forbidden_ph') ?>"><?= e(implode("\n", $editConfig['forbidden_keywords'] ?? [])) ?></textarea>
                <div class="form-text"><?= te('site.forbidden_help') ?></div>
            </fieldset>

            <fieldset data-for="ssl">
                <div class="form-section-title"><?= te('site.sec_expiry') ?></div>
                <div class="row g-3">
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label" for="ck-wd"><?= te('site.warn_days') ?></label>
                        <input type="number" id="ck-wd" name="warn_days" class="form-control" value="<?= $cfg('warn_days', 30) ?>">
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label" for="ck-cd"><?= te('site.crit_days') ?></label>
                        <input type="number" id="ck-cd" name="critical_days" class="form-control" value="<?= $cfg('critical_days', 14) ?>">
                    </div>
                </div>
            </fieldset>

            <div class="d-flex flex-wrap gap-2 mt-4">
                <button type="submit" class="btn btn-bat" data-busy-label="<?= te('common.saving') ?>"><i class="ph ph-floppy-disk" aria-hidden="true"></i><?= te($isEditing ? 'site.save_changes' : 'site.add_check') ?></button>
                <?php if ($isEditing): ?><a href="site.php?id=<?= $siteId ?>" class="btn btn-ghost"><?= te('common.cancel') ?></a><?php endif; ?>
            </div>
        </form>
    </div>
    </div>
</section>

<script>
(function () {
    // Only the fields for the selected type are shown and submitted (disabled fieldsets aren't posted).
    function sync() {
        var type = document.querySelector('input[name="type"]:checked').value;
        document.querySelectorAll('#check-form [data-for]').forEach(function (el) {
            var on = el.dataset.for.split(' ').indexOf(type) !== -1;
            el.hidden = !on;
            if (el.tagName === 'FIELDSET') el.disabled = !on;
        });
    }
    document.querySelectorAll('input[name="type"]').forEach(function (r) { r.addEventListener('change', sync); });
    sync();
})();
</script>

<section class="card-bat">
    <div class="card-header"><span class="card-title-icon"><i class="ph ph-clock-counter-clockwise" aria-hidden="true"></i><?= te('site.history_title') ?></span></div>
    <?php if (empty($recentRuns)): ?>
        <?= empty_state('ph-hourglass', t('site.history_empty_title'), t('site.history_empty_text')) ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-bat">
            <thead><tr><th scope="col"><?= te('common.date') ?></th><th scope="col"><?= te('common.check') ?></th><th scope="col"><?= te('common.status') ?></th><th scope="col"><?= te('common.detail') ?></th><th scope="col" class="text-end"><?= te('common.response') ?></th></tr></thead>
            <tbody>
            <?php foreach ($recentRuns as $r): ?>
                <tr>
                    <td class="mono text-subtle text-nowrap"><?= e(date('d/m H:i:s', strtotime($r['run_at']))) ?></td>
                    <td class="text-nowrap"><?= e(cn($r['check_name'])) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="cell-detail small"><?= $r['detail'] ? detail_block($r['detail']) : '—' ?></td>
                    <td class="text-end mono text-muted-bat text-nowrap"><?= $r['response_time_ms'] !== null ? (int)$r['response_time_ms'] . ' ms' : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
