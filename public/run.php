<?php
// Runs every active check of a site and streams progress as NDJSON (one JSON object per line),
// so the panel can show a real progress bar and a live log instead of a spinner.
require __DIR__ . '/../app/bootstrap.php';

use BatSignal\Auth;
use BatSignal\CheckRunner;
use BatSignal\Database;
use BatSignal\Msg;

if (!Auth::check() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(Auth::check() ? 405 : 401);
    exit;
}

$siteId = (int)($_POST['site_id'] ?? 0);
$stmt = Database::get()->prepare('SELECT * FROM checks WHERE site_id = ? AND is_active = 1 ORDER BY (type = \'crawl\'), id');
$stmt->execute([$siteId]);
$checks = $stmt->fetchAll();

// Free the session lock, keep going if the tab closes (results must still be recorded).
session_write_close();
ignore_user_abort(true);
set_time_limit(900);

while (ob_get_level() > 0) {
    ob_end_flush();
}
header('Content-Type: application/x-ndjson; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');

$emit = function (array $event): void {
    echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    flush();
};

// Padding line so intermediaries with small buffer thresholds start streaming immediately.
echo str_repeat(' ', 2048), "\n";
flush();

// The crawl takes most of the time, so it weighs more in the overall percentage.
$weightOf = fn(array $c) => $c['type'] === 'crawl' ? 8 : 1;
$totalWeight = max(1, array_sum(array_map($weightOf, $checks)));
$doneWeight = 0;
$pct = fn(float $w) => round(min(100, $w / $totalWeight * 100), 1);

$emit([
    'type' => 'start',
    'total' => count($checks),
    'checks' => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => BatSignal\I18n::checkName($c['name']), 'kind' => $c['type']], $checks),
]);

$summary = ['ok' => 0, 'warning' => 0, 'down' => 0];

foreach ($checks as $i => $check) {
    $weight = $weightOf($check);
    $emit([
        'type' => 'check_start',
        'index' => $i + 1,
        'name' => BatSignal\I18n::checkName($check['name']),
        'kind' => $check['type'],
        'url' => $check['url'],
        'percent' => $pct($doneWeight),
    ]);

    $started = microtime(true);
    $result = CheckRunner::runAndRecord($check, function (int $done, int $estimate, string $url, ?array $problem, int $ms, string $category = '') use ($emit, $pct, $doneWeight, $weight) {
        $emit([
            'type' => 'page',
            'done' => $done,
            'estimate' => $estimate,
            'url' => $url,
            'problem' => $problem ? Msg::renderMsg($problem) : null,
            'ms' => $ms,
            'category' => $category,
            // Capped below 100% of the check: more links may still be discovered.
            'percent' => $pct($doneWeight + $weight * min(0.95, $done / max(1, $estimate + 1))),
        ]);
    });

    $doneWeight += $weight;
    $summary[$result['status']] = ($summary[$result['status']] ?? 0) + 1;
    $emit([
        'type' => 'check_done',
        'name' => BatSignal\I18n::checkName($check['name']),
        'status' => $result['status'],
        'detail' => strtok(Msg::renderMsg($result['detail']), "\n") ?: null,
        'ms' => (int)round((microtime(true) - $started) * 1000),
        'percent' => $pct($doneWeight),
    ]);
}

$emit(['type' => 'done', 'summary' => $summary, 'percent' => 100]);
