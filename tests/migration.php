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
    $schema = file_get_contents($projectRoot . '/Install/database_schema.sql');
    if ($schema === false) {
        migrationFail('Datenbankschema konnte nicht gelesen werden.');
    }

    // Simuliert eine vorhandene 1.0.x-Datenbank ohne formale Schema-Version.
    $legacySchema = str_replace('PRAGMA user_version = 1;', 'PRAGMA user_version = 0;', $schema);
    $legacyDb = new PDO('sqlite:' . $databaseFile);
    $legacyDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $legacyDb->exec('PRAGMA foreign_keys = ON');
    $legacyDb->exec($legacySchema);
    $legacyDb->exec("INSERT INTO access_codes (email, code, created_at) VALUES ('helfer@example.org', '4242', datetime('now'))");
    $codeId = (int)$legacyDb->lastInsertId();
    $stmt = $legacyDb->prepare("INSERT INTO entries (name, status, created_at, access_code_id) VALUES (?, 'help', datetime('now'), ?)");
    $stmt->execute(['Erika Beispiel', $codeId]);
    $legacyDb->exec("INSERT OR REPLACE INTO app_settings (setting_key, setting_value, updated_at) VALUES ('event_name', 'Testfest', datetime('now'))");

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
    migrationAssert(1, (int)$migratedDb->query('SELECT COUNT(*) FROM schema_migrations WHERE version = 1')->fetchColumn(), 'Migration wurde nicht protokolliert.');
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
