<?php
// Applies pending db/migrations/*.sql in name order and records them in schema_migrations.
// Safe to run repeatedly (the deploy script and the runner container run it on start).
//   php bin/migrate.php          apply pending migrations
//   php bin/migrate.php --status list applied / pending
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$db = BatSignal\Database::get();
$db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    name VARCHAR(190) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB');

$applied = array_flip($db->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
$files = glob(APP_ROOT . '/db/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

if (in_array('--status', $argv, true)) {
    foreach ($files as $f) {
        echo (isset($applied[basename($f)]) ? '[x] ' : '[ ] ') . basename($f) . "\n";
    }
    exit(0);
}

$count = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) {
        continue;
    }
    // Strip "--" comment lines, then run statement by statement so a failure points at its SQL.
    $sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($file));
    foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $statement) {
        if (trim($statement) === '') {
            continue;
        }
        try {
            $db->exec($statement);
        } catch (PDOException $e) {
            fwrite(STDERR, "ERROR a {$name}: {$e->getMessage()}\nSQL: " . trim($statement) . "\n");
            exit(1);
        }
    }
    $db->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
    echo "Aplicada: {$name}\n";
    $count++;
}
echo $count ? "{$count} migració(ns) aplicada(es).\n" : "Base de dades al dia.\n";
