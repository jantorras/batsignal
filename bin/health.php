<?php
// Health probe for containers and the deploy script.
//   php bin/health.php            exit 0 if the database answers
//   php bin/health.php --runner   also require a runner heartbeat in the last N minutes (default 20)
//   php bin/health.php --json     print details as JSON
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$maxMinutes = (int)(getenv('RUNNER_MAX_SILENCE_MIN') ?: 20);
$out = ['db' => false, 'runner_age_s' => null];

try {
    $db = BatSignal\Database::get();
    $out['db'] = (int)$db->query('SELECT 1')->fetchColumn() === 1;
    $age = $db->query(
        "SELECT TIMESTAMPDIFF(SECOND, setting_value, NOW()) FROM settings WHERE setting_key = 'runner_heartbeat'"
    )->fetchColumn();
    $out['runner_age_s'] = $age === false || $age === null ? null : (int)$age;
} catch (Throwable $e) {
    $out['error'] = $e->getMessage();
}

$ok = $out['db'];
if (in_array('--runner', $argv, true)) {
    $ok = $ok && $out['runner_age_s'] !== null && $out['runner_age_s'] <= $maxMinutes * 60;
}
if (in_array('--json', $argv, true)) {
    echo json_encode($out), "\n";
}
exit($ok ? 0 : 1);
