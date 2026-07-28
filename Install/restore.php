<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Dieses Werkzeug darf nur auf der Kommandozeile ausgeführt werden.');
}

require_once __DIR__ . '/migration_lib.php';

$backupFile = $argv[1] ?? '';
$databaseFile = $argv[2] ?? '';
$backupDirectory = $argv[3] ?? null;

if ($backupFile === '' || $databaseFile === '') {
    fwrite(STDERR, "Aufruf: php Install/restore.php SICHERUNG ZIELDATENBANK [BACKUP_ORDNER]\n");
    exit(2);
}

try {
    $result = helferlisteRestoreDatabase($backupFile, $databaseFile, $backupDirectory);
    echo "Wiederherstellung erfolgreich.\n";
    printf("Schema-Version: %d -> %d\n", $result['from_version'], $result['to_version']);
    if ($result['safety_backup'] !== null) {
        echo 'Sicherung des zuvor aktiven Stands: ' . $result['safety_backup'] . PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Wiederherstellung fehlgeschlagen: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
