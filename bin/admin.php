<?php
// User management from the command line (used by the deploy script).
//   php bin/admin.php list
//   php bin/admin.php create <username>   password from BS_PASSWORD env var or stdin
//   php bin/admin.php reset  <username>   same
//   php bin/admin.php count
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$db = BatSignal\Database::get();
$cmd = $argv[1] ?? '';
$username = trim($argv[2] ?? '');

$password = function (): string {
    $pw = getenv('BS_PASSWORD');
    if ($pw === false || $pw === '') {
        $pw = rtrim((string)fgets(STDIN), "\r\n");
    }
    if (strlen($pw) < 8) {
        fwrite(STDERR, "La contrasenya ha de tenir almenys 8 caràcters.\n");
        exit(2);
    }
    return $pw;
};

switch ($cmd) {
    case 'count':
        echo (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn(), "\n";
        break;

    case 'list':
        foreach ($db->query('SELECT username, IFNULL(lang, "-") lang, created_at FROM users ORDER BY id') as $u) {
            printf("%-24s %-4s %s\n", $u['username'], $u['lang'], $u['created_at']);
        }
        break;

    case 'create':
        if ($username === '') {
            fwrite(STDERR, "Cal indicar el nom d'usuari.\n");
            exit(2);
        }
        $exists = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $exists->execute([$username]);
        if ((int)$exists->fetchColumn() > 0) {
            fwrite(STDERR, "L'usuari «{$username}» ja existeix.\n");
            exit(3);
        }
        $db->prepare('INSERT INTO users (username, password_hash, lang) VALUES (?, ?, ?)')
            ->execute([$username, password_hash($password(), PASSWORD_DEFAULT), 'ca']);
        echo "Usuari «{$username}» creat.\n";
        break;

    case 'reset':
        $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE username = ?');
        $stmt->execute([password_hash($password(), PASSWORD_DEFAULT), $username]);
        if ($stmt->rowCount() === 0) {
            fwrite(STDERR, "No existeix l'usuari «{$username}».\n");
            exit(3);
        }
        echo "Contrasenya de «{$username}» canviada.\n";
        break;

    default:
        fwrite(STDERR, "Ús: php bin/admin.php list|count|create <usuari>|reset <usuari>\n");
        exit(2);
}
