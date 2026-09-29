<?php
// CLI runner: executes all checks that are due. Meant to be triggered every
// minute by Windows Task Scheduler:
//   C:\xampp\php\php.exe C:\xampp\htdocs\BatSignal\cron\run_checks.php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use BatSignal\CheckRunner;
use BatSignal\Database;

// A full-site crawl can outlast the 1-minute schedule; never run two passes at once.
$lock = fopen(__DIR__ . '/.run.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo '[' . date('Y-m-d H:i:s') . "] BatSignal: una altra execució encara està en marxa, se salta.\n";
    exit(0);
}

// Heartbeat: lets health checks (and the deploy script) tell whether the runner is alive.
$heartbeat = fn() => Database::get()->exec(
    "INSERT INTO settings (setting_key, setting_value) VALUES ('runner_heartbeat', NOW())
     ON DUPLICATE KEY UPDATE setting_value = NOW()"
);
$heartbeat();

// Fast checks first so a long crawl doesn't delay "is the site up?" checks.
$dueChecks = Database::get()->query(
    "SELECT c.*
     FROM checks c
     JOIN sites s ON s.id = c.site_id
     WHERE c.is_active = 1 AND s.is_active = 1
       AND (c.last_run_at IS NULL OR c.last_run_at <= (NOW() - INTERVAL c.frequency_minutes MINUTE))
     ORDER BY (c.type = 'crawl'), c.last_run_at IS NOT NULL, c.last_run_at"
)->fetchAll();

foreach ($dueChecks as $check) {
    CheckRunner::runAndRecord($check);
    $heartbeat();
}

echo '[' . date('Y-m-d H:i:s') . '] BatSignal: ' . count($dueChecks) . " check(s) processats.\n";
