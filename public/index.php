<?php
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/partials/ui.php';

use BatSignal\Auth;
use BatSignal\Database;

Auth::requireLogin();
$db = Database::get();

// Optional group filter (?group=ID): everything on the dashboard is scoped to its member sites.
$groupId = null;
if (isset($_GET['group'])) {
    $stmt = $db->prepare('SELECT id FROM site_groups WHERE id = ?');
    $stmt->execute([(int)$_GET['group']]);
    $groupId = $stmt->fetchColumn() ? (int)$_GET['group'] : null;
}
$inGroup = $groupId ? ' AND s.id IN (SELECT site_id FROM site_group_members WHERE group_id = ' . $groupId . ')' : '';

$checks = $db->query(
    "SELECT c.id, c.name AS check_name, c.type, c.url, s.name AS site_name, s.id AS site_id,
            cr.status, cr.reason_code, cr.detail, cr.run_at, cr.response_time_ms
     FROM checks c
     JOIN sites s ON s.id = c.site_id
     LEFT JOIN check_runs cr ON cr.id = (
         SELECT id FROM check_runs WHERE check_id = c.id ORDER BY run_at DESC LIMIT 1
     )
     WHERE c.is_active = 1 AND s.is_active = 1{$inGroup}
     ORDER BY FIELD(COALESCE(cr.status, 'unknown'), 'down', 'degraded', 'warning', 'unknown', 'ok'), s.name, c.name"
)->fetchAll();

$counts = ['ok' => 0, 'warning' => 0, 'degraded' => 0, 'down' => 0, 'unknown' => 0];
foreach ($checks as $c) {
    $counts[$c['status'] ?? 'unknown']++;
}

$openIncidents = (int)$db->query(
    "SELECT COUNT(*) FROM incidents i JOIN checks c ON c.id = i.check_id JOIN sites s ON s.id = c.site_id
     WHERE i.status = 'open'{$inGroup}"
)->fetchColumn();
$totalSites = (int)$db->query("SELECT COUNT(*) FROM sites s WHERE 1{$inGroup}")->fetchColumn();
$attention = $counts['down'] + $counts['degraded'] + $counts['warning'];
$allClear = $attention === 0 && $openIncidents === 0;
$groupsBySite = groups_by_site();

$pageTitle = t('dash.title');
require __DIR__ . '/partials/header.php';
?>
<header class="page-header">
    <div>
        <span class="eyebrow"><?= te('dash.eyebrow') ?></span>
        <h1><?= te('dash.h1') ?></h1>
        <p class="lead-text" data-typewriter>
            <?php if (empty($checks)): ?>
                <?= te('dash.lead_empty') ?>
            <?php elseif ($allClear): ?>
                <?= te('dash.lead_ok') ?>
            <?php else: ?>
                <?= te('dash.lead_attention', ['n' => $attention]) ?>
            <?php endif; ?>
        </p>
    </div>
    <a href="sites.php" class="btn btn-bat"><i class="ph ph-plus" aria-hidden="true"></i><?= te('dash.add_site') ?></a>
</header>

<?= group_filter('index.php', $groupId) ?>

<section class="row g-3 mb-4" aria-label="<?= te('dash.summary_aria') ?>">
    <div class="col-6 col-lg-3">
        <div class="card-bat stat-card">
            <div class="stat-icon brand"><i class="ph ph-globe" aria-hidden="true"></i></div>
            <div><div class="stat-value" data-count="<?= $totalSites ?>"><?= $totalSites ?></div><div class="stat-label"><?= te('dash.stat_sites') ?></div></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card-bat stat-card">
            <div class="stat-icon ok"><i class="ph ph-check-circle" aria-hidden="true"></i></div>
            <div><div class="stat-value" data-count="<?= $counts['ok'] ?>"><?= $counts['ok'] ?></div><div class="stat-label"><?= te('dash.stat_ok') ?></div></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card-bat stat-card">
            <div class="stat-icon warning"><i class="ph ph-warning" aria-hidden="true"></i></div>
            <div><div class="stat-value" data-count="<?= $counts['warning'] ?>"><?= $counts['warning'] ?></div><div class="stat-label"><?= te('dash.stat_warning') ?></div></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <a href="incidents.php" class="text-decoration-none">
            <div class="card-bat stat-card <?= $openIncidents > 0 ? 'is-alert' : '' ?>">
                <div class="stat-icon down"><i class="ph ph-siren" aria-hidden="true"></i></div>
                <div><div class="stat-value" data-count="<?= $openIncidents ?>"><?= $openIncidents ?></div><div class="stat-label"><?= te('dash.stat_incidents') ?></div></div>
            </div>
        </a>
    </div>
</section>

<section class="card-bat">
    <div class="card-header">
        <span class="card-title-icon"><i class="ph ph-pulse" aria-hidden="true"></i><?= te('dash.checks_title') ?></span>
        <?php if (!empty($checks)): ?><span class="text-subtle small fw-normal"><?= te('dash.n_active', ['n' => count($checks)]) ?></span><?php endif; ?>
    </div>
    <?php if (empty($checks)): ?>
        <?= empty_state('ph-binoculars', t('dash.empty_title'), t('dash.empty_text'), 'sites.php', t('dash.empty_cta')) ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-bat table-hover">
            <thead>
            <tr>
                <th scope="col"><?= te('common.status') ?></th>
                <th scope="col"><?= te('common.web_check') ?></th>
                <th scope="col" class="d-none d-md-table-cell"><?= te('common.type') ?></th>
                <th scope="col"><?= te('common.detail') ?></th>
                <th scope="col" class="text-end"><?= te('common.response') ?></th>
                <th scope="col" class="text-end d-none d-md-table-cell"><?= te('dash.col_last') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($checks as $c): ?>
                <tr>
                    <td><?= status_badge($c['status']) ?></td>
                    <td>
                        <a class="cell-primary" href="site.php?id=<?= (int)$c['site_id'] ?>"><?= e($c['site_name']) ?></a>
                        <div class="text-subtle small"><?= e(cn($c['check_name'])) ?></div>
                        <?php if (!$groupId && !empty($groupsBySite[(int)$c['site_id']])): ?><div class="chips"><?php foreach ($groupsBySite[(int)$c['site_id']] as $g) echo group_chip($g); ?></div><?php endif; ?>
                    </td>
                    <td class="d-none d-md-table-cell"><span class="type-tag"><?= e(type_label($c['type'])) ?></span></td>
                    <td class="cell-detail small"><?= e(first_line($c['detail']) ?: '—') ?></td>
                    <td class="text-end mono text-muted-bat"><?= $c['response_time_ms'] !== null ? (int)$c['response_time_ms'] . ' ms' : '—' ?></td>
                    <td class="text-end mono text-subtle d-none d-md-table-cell"><?= $c['run_at'] ? e(date('d/m H:i', strtotime($c['run_at']))) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
