<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Dieses Werkzeug darf nur auf der Kommandozeile ausgeführt werden.');
}

require_once __DIR__ . '/migration_lib.php';

$databaseFile = $argv[1] ?? '';
$backupDirectory = $argv[2] ?? null;

if ($databaseFile === '') {
    fwrite(STDERR, "Aufruf: php Install/migrate.php PFAD_ZUR_DATENBANK [BACKUP_ORDNER]\n");
    exit(2);
}

try {
    $result = helferlisteMigrateDatabase($databaseFile, $backupDirectory);
    if ($result['changed']) {
        if ($result['from_version'] !== $result['to_version']) {
            printf(
                "Datenbank von Schema-Version %d auf %d aktualisiert.\n",
                $result['from_version'],
                $result['to_version']
            );
        } else {
            echo "Sichere Admin-Ersteinrichtung vorbereitet.\n";
        }
        if ($result['backup_file'] !== null) {
            echo 'Sicherung: ' . $result['backup_file'] . PHP_EOL;
        }
    } else {
        printf("Datenbank ist bereits auf Schema-Version %d.\n", $result['to_version']);
    }
    if ($result['setup_token'] !== null) {
        echo PHP_EOL;
        echo "Einmaliger Admin-Einrichtungscode:\n";
        echo $result['setup_token'] . PHP_EOL;
        echo "Diesen Code jetzt sicher notieren. Er wird nicht in Klartext gespeichert.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration fehlgeschlagen: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
