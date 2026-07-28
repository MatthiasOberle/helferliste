<?php

declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, "FEHLER: {$message}\n");
    exit(1);
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . ' Erwartet: ' . var_export($expected, true) . ', erhalten: ' . var_export($actual, true));
    }
}

$projectRoot = dirname(__DIR__);
$schemaFile = $projectRoot . '/Install/database_schema.sql';
$databaseFile = tempnam(sys_get_temp_dir(), 'helferliste-smoke-');

if ($databaseFile === false) {
    fail('Temporäre Datenbank konnte nicht angelegt werden.');
}

try {
    $schema = file_get_contents($schemaFile);
    if ($schema === false || trim($schema) === '') {
        fail('Datenbankschema konnte nicht gelesen werden.');
    }

    $db = new PDO('sqlite:' . $databaseFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys = ON');

    // Das Schema muss sowohl eine Neuinstallation als auch einen erneuten Lauf aushalten.
    $db->exec($schema);
    $db->exec($schema);

    $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table'")
        ->fetchAll(PDO::FETCH_COLUMN);
    $requiredTables = [
        'access_codes',
        'entries',
        'shifts',
        'springer_shifts',
        'entry_shifts',
        'entry_springer_shifts',
        'change_requests',
        'visit_stats',
        'event_log',
        'app_settings',
    ];

    foreach ($requiredTables as $table) {
        if (!in_array($table, $tables, true)) {
            fail("Erwartete Tabelle fehlt: {$table}");
        }
    }

    assertSameValue(3, (int)$db->query('SELECT COUNT(*) FROM shifts')->fetchColumn(), 'Beispielschichten wurden mehrfach oder unvollständig angelegt.');
    assertSameValue(2, (int)$db->query('SELECT COUNT(*) FROM springer_shifts')->fetchColumn(), 'Beispiel-Springerschichten wurden mehrfach oder unvollständig angelegt.');
    assertSameValue(1, (int)$db->query("SELECT COUNT(*) FROM event_log WHERE action = 'installed'")->fetchColumn(), 'Installationsprotokoll wurde mehrfach oder nicht angelegt.');
    assertSameValue([], $db->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC), 'Fremdschlüsselprüfung ist fehlgeschlagen.');

    require_once $projectRoot . '/www/app_config.php';
    $defaults = appConfig($db);
    assertSameValue('Helferliste', $defaults['app_name'] ?? null, 'Standardkonfiguration ist nicht verfügbar.');

    saveAppSetting($db, 'event_organizer', 'Beispielverein');
    assertSameValue('Beispielverein', appSetting($db, 'event_organizer'), 'Einstellung konnte nicht gespeichert und gelesen werden.');

    fwrite(STDOUT, "OK: Installation, Schema und Einstellungen funktionieren.\n");
} catch (Throwable $error) {
    fail($error->getMessage());
} finally {
    if (is_file($databaseFile)) {
        unlink($databaseFile);
    }
}
