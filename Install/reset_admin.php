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
    fwrite(STDERR, "Aufruf: php Install/reset_admin.php PFAD_ZUR_DATENBANK [BACKUP_ORDNER]\n");
    exit(2);
}

try {
    $result = helferlisteResetAdminAccess($databaseFile, $backupDirectory);
    echo "Der bisherige Adminzugang wurde zurückgesetzt.\n";
    echo 'Sicherung: ' . $result['backup_file'] . PHP_EOL;
    echo PHP_EOL;
    echo "Neuer einmaliger Admin-Einrichtungscode:\n";
    echo $result['setup_token'] . PHP_EOL;
    echo "Diesen Code jetzt sicher notieren. Er wird nicht in Klartext gespeichert.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Admin-Reset fehlgeschlagen: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
