<?php
require __DIR__ . '/../app/bootstrap.php';

use BatSignal\Auth;
use BatSignal\Database;
use BatSignal\SolutionProvider;

Auth::requireLogin();
$db = Database::get();

$incidents = $db->query(
    "SELECT i.*, c.name AS check_name, c.url, s.name AS site_name, s.id AS site_id
     FROM incidents i
     JOIN checks c ON c.id = i.check_id
     JOIN sites s ON s.id = c.site_id
     ORDER BY (i.status = 'open') DESC, i.opened_at DESC
     LIMIT 100"
)->fetchAll();

function incident_duration(string $from, ?string $to): string
{
    $seconds = (($to ? strtotime($to) : time()) - strtotime($from));
    if ($seconds < 60) return $seconds . ' s';
    if ($seconds < 3600) return floor($seconds / 60) . ' min';
    if ($seconds < 86400) return floor($seconds / 3600) . ' h ' . floor(($seconds % 3600) / 60) . ' min';
    return floor($seconds / 86400) . ' d ' . floor(($seconds % 86400) / 3600) . ' h';
}

$pageTitle = t('incidents.h1');
require __DIR__ . '/partials/header.php';
?>
<header class="page-header">
    <div>
        <span class="eyebrow"><?= te('incidents.eyebrow') ?></span>
        <h1><?= te('incidents.h1') ?></h1>
        <p class="lead-text"><?= te('incidents.lead') ?></p>
    </div>
</header>

<section class="card-bat">
    <?php if (empty($incidents)): ?>
        <?= empty_state('ph-moon-stars', t('incidents.empty_title'), t('incidents.empty_text')) ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-bat">
            <thead>
            <tr>
                <th scope="col"><?= te('common.status') ?></th>
                <th scope="col"><?= te('common.web_check') ?></th>
                <th scope="col"><?= te('incidents.col_reason') ?></th>
                <th scope="col"><?= te('incidents.col_start') ?></th>
                <th scope="col" class="text-end"><?= te('incidents.col_duration') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($incidents as $i): $solution = SolutionProvider::suggest((string)$i['reason_code']); ?>
                <tr>
                    <td><?= status_badge($i['status']) ?></td>
                    <td>
                        <a class="cell-primary" href="site.php?id=<?= (int)$i['site_id'] ?>"><?= e($i['site_name']) ?></a>
                        <div class="text-subtle small"><?= e(cn($i['check_name'])) ?></div>
                    </td>
                    <td class="cell-detail small">
                        <div class="text-white fw-semibold"><?= e($solution['title']) ?></div>
                        <div class="mb-1"><?= detail_block($i['last_error'], $i['status'] === 'open') ?></div>
                        <details>
                            <summary class="text-muted-bat" style="cursor:pointer"><?= te('incidents.possible_solution') ?></summary>
                            <div class="mt-1"><?= e($solution['suggestion']) ?></div>
                        </details>
                    </td>
                    <td class="mono text-subtle text-nowrap"><?= e(date('d/m/Y H:i', strtotime($i['opened_at']))) ?></td>
                    <td class="mono text-end text-nowrap <?= $i['status'] === 'open' ? 'text-danger' : 'text-muted-bat' ?>"><?= e(incident_duration($i['opened_at'], $i['closed_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
