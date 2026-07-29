<?php

declare(strict_types=1);

function restoreFail(string $message): never
{
    fwrite(STDERR, "FEHLER: {$message}\n");
    exit(1);
}

function restoreAssert(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        restoreFail($message . ' Erwartet: ' . var_export($expected, true) . ', erhalten: ' . var_export($actual, true));
    }
}

$projectRoot = dirname(__DIR__);
$testDirectory = sys_get_temp_dir() . '/helferliste-restore-' . bin2hex(random_bytes(6));
$databaseFile = $testDirectory . '/helferliste.sqlite';
$backupDirectory = $testDirectory . '/backups';

if (!mkdir($testDirectory, 0770, true) && !is_dir($testDirectory)) {
    restoreFail('Temporärer Testordner konnte nicht angelegt werden.');
}

try {
    require_once $projectRoot . '/Install/migration_lib.php';
    helferlisteMigrateDatabase($databaseFile, $backupDirectory);
    $db = new PDO('sqlite:' . $databaseFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("INSERT INTO access_codes (email, code, created_at) VALUES ('vorher@example.org', '1111', datetime('now'))");
    $sourceBackup = helferlisteCreateNamedBackup($db, $backupDirectory, 'test-wiederherstellung');
    $db->exec("UPDATE access_codes SET email = 'nachher@example.org', code = '2222'");
    $db = null;

    $result = helferlisteRestoreDatabase($sourceBackup, $databaseFile, $backupDirectory);
    restoreAssert(HELFERLISTE_SCHEMA_VERSION, $result['to_version'], 'Wiederhergestellte Datenbank hat die falsche Schema-Version.');
    if (!is_string($result['safety_backup']) || !is_file($result['safety_backup'])) {
        restoreFail('Der zuvor aktive Datenbankstand wurde nicht gesichert.');
    }

    $restored = new PDO('sqlite:' . $databaseFile);
    restoreAssert('vorher@example.org', (string)$restored->query('SELECT email FROM access_codes')->fetchColumn(), 'Der gesicherte Stand wurde nicht wiederhergestellt.');
    restoreAssert('ok', (string)$restored->query('PRAGMA quick_check')->fetchColumn(), 'Wiederhergestellte Datenbank ist nicht integer.');
    $restored = null;

    $safety = new PDO('sqlite:' . $result['safety_backup']);
    restoreAssert('nachher@example.org', (string)$safety->query('SELECT email FROM access_codes')->fetchColumn(), 'Sicherheitskopie enthält nicht den zuvor aktiven Stand.');
    $safety = null;

    fwrite(STDOUT, "OK: Sicherung, Wiederherstellung und Rückfallsicherung funktionieren.\n");
} catch (Throwable $error) {
    restoreFail($error->getMessage());
} finally {
    if (is_dir($backupDirectory)) {
        foreach (scandir($backupDirectory) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                unlink($backupDirectory . '/' . $file);
            }
        }
        rmdir($backupDirectory);
    }
    if (is_file($databaseFile)) {
        unlink($databaseFile);
    }
    if (is_dir($testDirectory)) {
        rmdir($testDirectory);
    }
}
