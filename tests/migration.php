<?php

declare(strict_types=1);

function migrationFail(string $message): never
{
    fwrite(STDERR, "FEHLER: {$message}\n");
    exit(1);
}

function migrationAssert(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        migrationFail($message . ' Erwartet: ' . var_export($expected, true) . ', erhalten: ' . var_export($actual, true));
    }
}

$projectRoot = dirname(__DIR__);
$testDirectory = sys_get_temp_dir() . '/helferliste-migration-' . bin2hex(random_bytes(6));
$backupDirectory = $testDirectory . '/backups';
$databaseFile = $testDirectory . '/helferliste.sqlite';

if (!mkdir($testDirectory, 0770, true) && !is_dir($testDirectory)) {
    migrationFail('Temporärer Testordner konnte nicht angelegt werden.');
}

try {
    // Simuliert eine vorhandene 1.0.x-Datenbank ohne formale Schema-Version.
    $legacySchema = file_get_contents($projectRoot . '/Install/migrations/001_baseline.sql');
    if ($legacySchema === false) {
        migrationFail('Altes Testschema konnte nicht gelesen werden.');
    }
    $legacyDb = new PDO('sqlite:' . $databaseFile);
    $legacyDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $legacyDb->exec('PRAGMA foreign_keys = ON');
    $legacyDb->exec($legacySchema);
    $legacyDb->exec("INSERT INTO access_codes (email, code, created_at) VALUES ('helfer@example.org', '4242', datetime('now'))");
    $codeId = (int)$legacyDb->lastInsertId();
    $stmt = $legacyDb->prepare("INSERT INTO entries (name, status, created_at, access_code_id) VALUES (?, 'help', datetime('now'), ?)");
    $stmt->execute(['Erika Beispiel', $codeId]);
    $legacyDb->exec("INSERT OR REPLACE INTO app_settings (setting_key, setting_value, updated_at) VALUES ('event_name', 'Testfest', datetime('now'))");
    $existingPasswordHash = password_hash('Bestehendes-sicheres-Passwort-2026', PASSWORD_DEFAULT);
    $passwordStmt = $legacyDb->prepare("INSERT OR REPLACE INTO app_settings (setting_key, setting_value, updated_at) VALUES ('admin_password_hash', ?, datetime('now'))");
    $passwordStmt->execute([$existingPasswordHash]);

    require_once $projectRoot . '/www/version.php';
    $outdatedSchemaRejected = false;
    try {
        helferlisteRequireCurrentSchema($legacyDb);
    } catch (RuntimeException) {
        $outdatedSchemaRejected = true;
    }
    migrationAssert(true, $outdatedSchemaRejected, 'Eine veraltete Datenbank wurde von der Anwendung nicht gestoppt.');
    $legacyDb = null;

    require_once $projectRoot . '/Install/migration_lib.php';
    $firstRun = helferlisteMigrateDatabase($databaseFile, $backupDirectory);
    migrationAssert(0, $firstRun['from_version'], 'Ausgangsversion wurde falsch erkannt.');
    migrationAssert(HELFERLISTE_SCHEMA_VERSION, $firstRun['to_version'], 'Zielversion wurde nicht erreicht.');
    migrationAssert(true, $firstRun['changed'], 'Bestandsdatenbank wurde nicht migriert.');
    migrationAssert(null, $firstRun['setup_token'], 'Vorhandenes Admin-Passwort wurde durch eine neue Ersteinrichtung ersetzt.');

    $backupFile = $firstRun['backup_file'];
    if (!is_string($backupFile) || !is_file($backupFile)) {
        migrationFail('Vor der Migration wurde keine Sicherung erstellt.');
    }

    $backupDb = new PDO('sqlite:' . $backupFile);
    migrationAssert(0, (int)$backupDb->query('PRAGMA user_version')->fetchColumn(), 'Sicherung enthält nicht den unveränderten Ausgangsstand.');
    migrationAssert('Erika Beispiel', (string)$backupDb->query('SELECT name FROM entries LIMIT 1')->fetchColumn(), 'Sicherung enthält die Testperson nicht.');
    $backupDb = null;

    $migratedDb = new PDO('sqlite:' . $databaseFile);
    $migratedDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    migrationAssert(HELFERLISTE_SCHEMA_VERSION, (int)$migratedDb->query('PRAGMA user_version')->fetchColumn(), 'Migrierte Datenbank hat die falsche Version.');
    migrationAssert('Erika Beispiel', (string)$migratedDb->query('SELECT name FROM entries LIMIT 1')->fetchColumn(), 'Vorhandene Rückmeldung ging verloren.');
    migrationAssert('4242', (string)$migratedDb->query('SELECT code FROM access_codes LIMIT 1')->fetchColumn(), 'Vorhandener Zugangscode ging verloren.');
    migrationAssert('Testfest', (string)$migratedDb->query("SELECT setting_value FROM app_settings WHERE setting_key = 'event_name'")->fetchColumn(), 'Vorhandene Einstellung ging verloren.');
    migrationAssert($existingPasswordHash, (string)$migratedDb->query("SELECT setting_value FROM app_settings WHERE setting_key = 'admin_password_hash'")->fetchColumn(), 'Vorhandenes Admin-Passwort wurde verändert.');
    migrationAssert(1, (int)$migratedDb->query("SELECT COUNT(*) FROM app_settings WHERE setting_key = 'admin_auth_generation' AND setting_value <> ''")->fetchColumn(), 'Vorhandener Adminzugang erhielt keine Sitzungs-Generation.');
    migrationAssert(1, (int)$migratedDb->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'event_archives'")->fetchColumn(), 'Archiv-Tabelle wurde durch die Migration nicht angelegt.');
    $shiftColumns = $migratedDb->query('PRAGMA table_info(shifts)')->fetchAll(PDO::FETCH_COLUMN, 1);
    foreach (['shift_date', 'start_time', 'end_time', 'location', 'note'] as $column) {
        migrationAssert(true, in_array($column, $shiftColumns, true), 'Strukturiertes Schichtfeld fehlt nach der Migration: ' . $column);
    }
    migrationAssert(HELFERLISTE_SCHEMA_VERSION, (int)$migratedDb->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn(), 'Migrationen wurden nicht vollständig protokolliert.');
    $migratedDb = null;

    $secondRun = helferlisteMigrateDatabase($databaseFile, $backupDirectory);
    migrationAssert(false, $secondRun['changed'], 'Aktuelle Datenbank wurde unnötig erneut migriert.');
    migrationAssert(null, $secondRun['backup_file'], 'Ohne Migration wurde unnötig eine Sicherung erstellt.');

    fwrite(STDOUT, "OK: Bestandsdaten, Sicherung und wiederholte Migration funktionieren.\n");
} catch (Throwable $error) {
    migrationFail($error->getMessage());
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
