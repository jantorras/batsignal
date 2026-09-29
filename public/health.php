<?php
// Unauthenticated liveness endpoint (Docker healthcheck, deploy script, Uptime Kuma).
// Reveals only whether the app, the database and the runner are alive.
require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$out = ['status' => 'ok', 'db' => false, 'runner_age_s' => null];
try {
    $db = BatSignal\Database::get();
    $out['db'] = (int)$db->query('SELECT 1')->fetchColumn() === 1;
    $age = $db->query(
        "SELECT TIMESTAMPDIFF(SECOND, setting_value, NOW()) FROM settings WHERE setting_key = 'runner_heartbeat'"
    )->fetchColumn();
    $out['runner_age_s'] = $age === false || $age === null ? null : (int)$age;
} catch (Throwable $e) {
    $out['status'] = 'error';
}

http_response_code($out['db'] ? 200 : 503);
echo json_encode($out);
