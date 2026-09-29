<?php
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/partials/ui.php';

use BatSignal\Auth;
use BatSignal\Database;
use BatSignal\DefaultChecks;

Auth::requireLogin();
$db = Database::get();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $baseUrl = trim($_POST['base_url'] ?? '');
        if ($name === '' || $baseUrl === '') {
            $error = t('sites.err_required');
        } else {
            $db->prepare('INSERT INTO sites (name, base_url) VALUES (?, ?)')->execute([$name, $baseUrl]);
            $newId = (int)$db->lastInsertId();
            DefaultChecks::createMissing($newId, $baseUrl);
            header('Location: site.php?id=' . $newId . '&created=1');
            exit;
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare('UPDATE sites SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        header('Location: sites.php');
        exit;
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare('DELETE FROM sites WHERE id = ?')->execute([$id]);
        header('Location: sites.php');
        exit;
    }
}

$groupId = isset($_GET['group']) ? (int)$_GET['group'] : null;
$inGroup = $groupId ? ' WHERE s.id IN (SELECT site_id FROM site_group_members WHERE group_id = ' . $groupId . ')' : '';
$groupsBySite = groups_by_site();

$sites = $db->query(
    "SELECT s.*,
            (SELECT COUNT(*) FROM checks c WHERE c.site_id = s.id) AS checks_count,
            (SELECT COUNT(*) FROM incidents i JOIN checks c ON c.id = i.check_id
              WHERE c.site_id = s.id AND i.status = 'open') AS open_incidents
     FROM sites s{$inGroup} ORDER BY s.name"
)->fetchAll();

$pageTitle = t('sites.title');
require __DIR__ . '/partials/header.php';
?>
<header class="page-header">
    <div>
        <span class="eyebrow"><?= te('sites.eyebrow') ?></span>
        <h1><?= te('sites.h1') ?></h1>
        <p class="lead-text"><?= te('sites.lead') ?></p>
    </div>
</header>

<?php if ($error): ?><div class="alert alert-danger" role="alert"><i class="ph ph-x-circle" aria-hidden="true"></i><?= e($error) ?></div><?php endif; ?>

<section class="card-bat mb-4">
    <div class="card-header"><span class="card-title-icon"><i class="ph ph-plus-circle" aria-hidden="true"></i><?= te('sites.add_title') ?></span></div>
    <div class="card-body">
        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="create">
            <div class="col-md-4">
                <label class="form-label" for="site-name"><?= te('common.name') ?></label>
                <input type="text" id="site-name" name="name" class="form-control" placeholder="<?= te('sites.name_ph') ?>" required>
            </div>
            <div class="col-md-5">
                <label class="form-label" for="site-url"><?= te('sites.base_url') ?></label>
                <input type="url" id="site-url" name="base_url" class="form-control mono" placeholder="https://" required>
            </div>
            <div class="col-md-3 d-grid">
                <button type="submit" class="btn btn-bat" data-busy-label="<?= te('sites.adding') ?>"><i class="ph ph-plus" aria-hidden="true"></i><?= te('sites.add_btn') ?></button>
            </div>
        </form>
        <p class="form-text mt-3 mb-0">
            <i class="ph ph-magic-wand" aria-hidden="true"></i>
            <?= te('sites.auto_help') ?>
        </p>
    </div>
</section>

<?= group_filter('sites.php', $groupId) ?>
<section class="card-bat">
    <div class="card-header">
        <span class="card-title-icon"><i class="ph ph-globe" aria-hidden="true"></i><?= te('sites.list_title') ?></span>
        <span class="text-subtle small fw-normal"><?= te('sites.n_sites', ['n' => count($sites)]) ?></span>
    </div>
    <?php if (empty($sites)): ?>
        <?= empty_state('ph-globe-hemisphere-west', t('sites.empty_title'), t('sites.empty_text')) ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-bat table-hover">
            <thead>
            <tr>
                <th scope="col"><?= te('common.web') ?></th>
                <th scope="col"><?= te('common.checks') ?></th>
                <th scope="col"><?= te('common.incidents') ?></th>
                <th scope="col"><?= te('common.status') ?></th>
                <th scope="col" class="text-end"><span class="visually-hidden"><?= te('common.actions') ?></span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($sites as $s): ?>
                <tr>
                    <td>
                        <a class="cell-primary" href="site.php?id=<?= (int)$s['id'] ?>"><?= e($s['name']) ?></a>
                        <div class="mono text-subtle"><?= e($s['base_url']) ?></div>
                        <?php if (!empty($groupsBySite[(int)$s['id']])): ?><div class="chips"><?php foreach ($groupsBySite[(int)$s['id']] as $g) echo group_chip($g); ?></div><?php endif; ?>
                    </td>
                    <td class="mono"><?= (int)$s['checks_count'] ?></td>
                    <td><?= (int)$s['open_incidents'] > 0 ? status_badge('open') : '<span class="text-subtle">—</span>' ?></td>
                    <td><?= status_badge($s['is_active'] ? 'active' : 'paused') ?></td>
                    <td class="cell-actions">
                        <div class="actions">
                            <a href="site.php?id=<?= (int)$s['id'] ?>" class="btn btn-ghost btn-sm"><i class="ph ph-sliders-horizontal" aria-hidden="true"></i><?= te('common.checks') ?></a>
                            <form method="post">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button class="btn btn-ghost btn-sm" type="submit">
                                    <i class="ph <?= $s['is_active'] ? 'ph-pause' : 'ph-play' ?>" aria-hidden="true"></i><?= te($s['is_active'] ? 'common.pause' : 'common.resume') ?>
                                </button>
                            </form>
                            <form method="post" onsubmit="return confirm(<?= e(json_encode(t('sites.confirm_delete', ['name' => $s['name']]), JSON_UNESCAPED_UNICODE)) ?>);">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button class="btn btn-danger-ghost btn-sm btn-icon" type="submit" aria-label="<?= te('common.delete_x', ['name' => $s['name']]) ?>" title="<?= te('common.delete') ?>">
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
<?php require __DIR__ . '/partials/footer.php'; ?>
