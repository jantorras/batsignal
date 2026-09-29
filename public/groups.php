<?php
require __DIR__ . '/../app/bootstrap.php';

use BatSignal\Auth;
use BatSignal\Database;

Auth::requireLogin();
$db = Database::get();
require_once __DIR__ . '/partials/ui.php';

$error = null;
$form = null; // group being created/edited: ['id', 'name', 'color', 'members' => [site_id => true]]

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_group') {
        $id = (int)($_POST['group_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $color = in_array($_POST['color'] ?? '', GROUP_COLORS, true) ? $_POST['color'] : 'yellow';
        $members = array_map('intval', (array)($_POST['members'] ?? []));

        $dup = $db->prepare('SELECT COUNT(*) FROM site_groups WHERE name = ? AND id <> ?');
        $dup->execute([$name, $id]);

        if ($name === '') {
            $error = t('groups.err_name');
        } elseif ((int)$dup->fetchColumn() > 0) {
            $error = t('groups.err_dup');
        } else {
            $db->beginTransaction();
            if ($id > 0) {
                $db->prepare('UPDATE site_groups SET name = ?, color = ? WHERE id = ?')->execute([$name, $color, $id]);
            } else {
                $db->prepare('INSERT INTO site_groups (name, color) VALUES (?, ?)')->execute([$name, $color]);
                $id = (int)$db->lastInsertId();
            }
            $db->prepare('DELETE FROM site_group_members WHERE group_id = ?')->execute([$id]);
            $add = $db->prepare('INSERT IGNORE INTO site_group_members (group_id, site_id) SELECT ?, id FROM sites WHERE id = ?');
            foreach ($members as $siteId) {
                $add->execute([$id, $siteId]);
            }
            $db->commit();
            header('Location: groups.php?saved=1#group-' . $id);
            exit;
        }
        $form = ['id' => $id, 'name' => $name, 'color' => $color, 'members' => array_fill_keys($members, true)];
    } elseif ($action === 'delete_group') {
        $db->prepare('DELETE FROM site_groups WHERE id = ?')->execute([(int)($_POST['group_id'] ?? 0)]);
        header('Location: groups.php?deleted=1');
        exit;
    }
}

if ($form === null && isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM site_groups WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    if ($g = $stmt->fetch()) {
        $m = $db->prepare('SELECT site_id FROM site_group_members WHERE group_id = ?');
        $m->execute([$g['id']]);
        $form = ['id' => (int)$g['id'], 'name' => $g['name'], 'color' => $g['color'], 'members' => array_fill_keys($m->fetchAll(PDO::FETCH_COLUMN), true)];
    }
}
if ($form === null && isset($_GET['new'])) {
    $form = ['id' => 0, 'name' => '', 'color' => 'yellow', 'members' => []];
}

$sites = $db->query('SELECT id, name, base_url, is_active FROM sites ORDER BY name')->fetchAll();

// Worst current status per site (latest run of each active check).
$rank = ['ok' => 0, 'unknown' => 1, 'warning' => 2, 'degraded' => 3, 'down' => 4];
$siteStatus = [];
$rows = $db->query(
    "SELECT c.site_id, cr.status
     FROM checks c JOIN sites s ON s.id = c.site_id
     LEFT JOIN check_runs cr ON cr.id = (SELECT id FROM check_runs WHERE check_id = c.id ORDER BY run_at DESC LIMIT 1)
     WHERE c.is_active = 1 AND s.is_active = 1"
)->fetchAll();
foreach ($rows as $r) {
    $st = $r['status'] ?? 'unknown';
    $cur = $siteStatus[(int)$r['site_id']] ?? 'ok';
    $siteStatus[(int)$r['site_id']] = $rank[$st] > $rank[$cur] ? $st : $cur;
}
$openBySite = [];
foreach ($db->query("SELECT c.site_id, COUNT(*) n FROM incidents i JOIN checks c ON c.id = i.check_id WHERE i.status = 'open' GROUP BY c.site_id") as $r) {
    $openBySite[(int)$r['site_id']] = (int)$r['n'];
}

$groups = $db->query('SELECT * FROM site_groups ORDER BY name')->fetchAll();
$members = [];
foreach ($db->query('SELECT m.group_id, s.id, s.name, s.is_active FROM site_group_members m JOIN sites s ON s.id = m.site_id ORDER BY s.name') as $r) {
    $members[(int)$r['group_id']][] = $r;
}

$pageTitle = t('groups.title');
require __DIR__ . '/partials/header.php';
?>
<header class="page-header">
    <div>
        <span class="eyebrow"><?= te('groups.eyebrow') ?></span>
        <h1><?= te('groups.title') ?></h1>
        <p class="lead-text"><?= te('groups.lead') ?></p>
    </div>
    <?php if ($form === null): ?>
        <a href="groups.php?new=1#group-form" class="btn btn-bat"><i class="ph ph-plus" aria-hidden="true"></i><?= te('groups.new') ?></a>
    <?php endif; ?>
</header>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success" role="status"><i class="ph ph-check-circle" aria-hidden="true"></i><?= te('groups.saved') ?></div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="alert alert-success" role="status"><i class="ph ph-check-circle" aria-hidden="true"></i><?= te('groups.deleted') ?></div><?php endif; ?>

<?php if ($form !== null): ?>
<section class="card-bat mb-4 gc-<?= e($form['color']) ?> group-card" id="group-form">
    <div class="card-header"><span class="card-title-icon"><i class="ph <?= $form['id'] ? 'ph-pencil-simple' : 'ph-folder-plus' ?>" aria-hidden="true"></i><?= te($form['id'] ? 'groups.edit' : 'groups.new') ?></span></div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger" role="alert"><i class="ph ph-x-circle" aria-hidden="true"></i><?= e($error) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="action" value="save_group">
            <input type="hidden" name="group_id" value="<?= (int)$form['id'] ?>">
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label" for="g-name"><?= te('groups.name') ?></label>
                    <input type="text" id="g-name" name="name" class="form-control" maxlength="80" required value="<?= e($form['name']) ?>" placeholder="<?= te('groups.name_ph') ?>" autofocus>
                </div>
                <div class="col-md-6">
                    <span class="form-label d-block" id="g-color-label"><?= te('groups.color') ?></span>
                    <div class="color-swatches" role="radiogroup" aria-labelledby="g-color-label">
                        <?php foreach (GROUP_COLORS as $c): ?>
                            <input type="radio" name="color" id="gc-<?= $c ?>" value="<?= $c ?>" <?= $form['color'] === $c ? 'checked' : '' ?>>
                            <label for="gc-<?= $c ?>" class="gc-<?= $c ?>" title="<?= te('groups.color_' . $c) ?>"><span class="visually-hidden"><?= te('groups.color_' . $c) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="form-section-title"><?= te('groups.members') ?></div>
            <?php if (!$sites): ?>
                <p class="text-muted-bat small"><?= te('groups.no_sites') ?></p>
            <?php else: ?>
                <div class="member-picker">
                    <?php foreach ($sites as $s): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="members[]" value="<?= (int)$s['id'] ?>" id="m-<?= (int)$s['id'] ?>" <?= isset($form['members'][(int)$s['id']]) ? 'checked' : '' ?>>
                            <label class="form-check-label d-block" for="m-<?= (int)$s['id'] ?>">
                                <span class="text-white"><?= e($s['name']) ?></span>
                                <span class="d-block mono text-subtle text-truncate"><?= e($s['base_url']) ?></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="form-text mt-2"><?= te('groups.members_help') ?></div>
            <?php endif; ?>

            <div class="d-flex flex-wrap gap-2 mt-4">
                <button type="submit" class="btn btn-bat" data-busy-label="<?= te('common.saving') ?>"><i class="ph ph-floppy-disk" aria-hidden="true"></i><?= te($form['id'] ? 'groups.save' : 'groups.create') ?></button>
                <a href="groups.php" class="btn btn-ghost"><?= te('common.cancel') ?></a>
            </div>
        </form>
        <?php if ($form['id']): ?>
            <form method="post" class="mt-3 pt-3" style="border-top: 1px dashed var(--bat-border)" onsubmit="return confirm(<?= e(json_encode(t('groups.confirm_delete', ['name' => $form['name']]), JSON_UNESCAPED_UNICODE)) ?>);">
                <input type="hidden" name="action" value="delete_group">
                <input type="hidden" name="group_id" value="<?= (int)$form['id'] ?>">
                <button type="submit" class="btn btn-danger-ghost btn-sm"><i class="ph ph-trash" aria-hidden="true"></i><?= te('common.delete') ?></button>
            </form>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if (!$groups && $form === null): ?>
    <section class="card-bat"><?= empty_state('ph-folders', t('groups.empty_title'), t('groups.empty_text'), 'groups.php?new=1#group-form', t('groups.new')) ?></section>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($groups as $g):
        $gm = $members[(int)$g['id']] ?? [];
        $worst = 'ok'; $counts = ['ok' => 0, 'warning' => 0, 'bad' => 0]; $open = 0;
        foreach ($gm as $m) {
            $st = $m['is_active'] ? ($siteStatus[(int)$m['id']] ?? 'unknown') : 'unknown';
            $worst = $rank[$st] > $rank[$worst] ? $st : $worst;
            if ($st === 'ok') $counts['ok']++; elseif ($st === 'warning') $counts['warning']++; elseif ($st !== 'unknown') $counts['bad']++;
            $open += $openBySite[(int)$m['id']] ?? 0;
        }
        $color = in_array($g['color'], GROUP_COLORS, true) ? $g['color'] : 'yellow';
    ?>
    <div class="col-md-6 col-xl-4" id="group-<?= (int)$g['id'] ?>">
        <section class="card-bat group-card gc-<?= $color ?> h-100">
            <div class="card-body d-flex flex-column gap-3">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <div class="group-name"><span class="dot" aria-hidden="true"></span><?= e($g['name']) ?></div>
                        <div class="text-subtle small mt-1"><?= te('groups.n_members', ['n' => count($gm)]) ?></div>
                    </div>
                    <?= $gm ? status_badge($worst) : '' ?>
                </div>
                <?php if ($gm): ?>
                    <div class="small text-muted-bat">
                        <?= te('groups.summary', $counts) ?>
                        <?php if ($open): ?><span class="text-danger"> · <?= te('groups.open_incidents', ['n' => $open]) ?></span><?php endif; ?>
                    </div>
                    <div class="group-members">
                        <?php foreach ($gm as $m): $st = $m['is_active'] ? ($siteStatus[(int)$m['id']] ?? 'unknown') : 'unknown'; ?>
                            <a class="member-pill" href="site.php?id=<?= (int)$m['id'] ?>"><span class="st <?= e($st) ?>" aria-hidden="true"></span><?= e($m['name']) ?><span class="visually-hidden"> (<?= te('status.' . $st) ?>)</span></a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-subtle small m-0"><?= te('groups.no_members') ?></p>
                <?php endif; ?>
                <div class="d-flex gap-2 mt-auto pt-1">
                    <a href="groups.php?edit=<?= (int)$g['id'] ?>#group-form" class="btn btn-ghost btn-sm"><i class="ph ph-pencil-simple" aria-hidden="true"></i><?= te('common.edit') ?></a>
                    <?php if ($gm): ?><a href="index.php?group=<?= (int)$g['id'] ?>" class="btn btn-ghost btn-sm"><i class="ph ph-squares-four" aria-hidden="true"></i><?= te('nav.dashboard') ?></a><?php endif; ?>
                </div>
            </div>
        </section>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
