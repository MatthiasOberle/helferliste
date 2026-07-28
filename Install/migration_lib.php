<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/www/version.php';

function helferlisteDatabaseVersion(PDO $db): int
{
    return (int)$db->query('PRAGMA user_version')->fetchColumn();
}

function helferlisteAssertDatabaseIntegrity(PDO $db): void
{
    $quickCheck = (string)$db->query('PRAGMA quick_check')->fetchColumn();
    if ($quickCheck !== 'ok') {
        throw new RuntimeException('SQLite-Integritätsprüfung fehlgeschlagen: ' . $quickCheck);
    }

    $foreignKeyErrors = $db->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
    if ($foreignKeyErrors !== []) {
        throw new RuntimeException('Die Datenbank enthält ungültige Fremdschlüssel-Beziehungen.');
    }
}

function helferlisteCreateMigrationBackup(PDO $db, string $backupDirectory, int $fromVersion): string
{
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0770, true) && !is_dir($backupDirectory)) {
        throw new RuntimeException('Backup-Ordner konnte nicht angelegt werden: ' . $backupDirectory);
    }

    $baseName = sprintf(
        'helferliste-schema-v%d-vor-v%d-%s',
        $fromVersion,
        HELFERLISTE_SCHEMA_VERSION,
        date('Ymd-His')
    );
    $backupFile = rtrim($backupDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $baseName . '.sqlite';
    $suffix = 2;

    while (file_exists($backupFile)) {
        $backupFile = rtrim($backupDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $baseName . '-' . $suffix . '.sqlite';
        $suffix++;
    }

    $db->exec('VACUUM INTO ' . $db->quote($backupFile));
    return $backupFile;
}

function helferlisteApplyMigration(PDO $db, int $version): void
{
    $migrationFiles = [
        1 => __DIR__ . '/migrations/001_baseline.sql',
    ];
    $migrationFile = $migrationFiles[$version] ?? null;
    if ($migrationFile === null) {
        throw new RuntimeException('Unbekannte Datenbankmigration: ' . $version);
    }

    $sql = file_get_contents($migrationFile);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('Datenbankmigration konnte nicht gelesen werden: ' . $migrationFile);
    }

    $db->exec($sql);
}

/**
 * @return array{from_version:int,to_version:int,backup_file:?string,changed:bool}
 */
function helferlisteMigrateDatabase(string $databaseFile, ?string $backupDirectory = null): array
{
    $databaseDirectory = dirname($databaseFile);
    if (!is_dir($databaseDirectory) && !mkdir($databaseDirectory, 0770, true) && !is_dir($databaseDirectory)) {
        throw new RuntimeException('Datenbankordner konnte nicht angelegt werden: ' . $databaseDirectory);
    }

    $hadExistingData = is_file($databaseFile) && filesize($databaseFile) > 0;
    $db = new PDO('sqlite:' . $databaseFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');

    if ($hadExistingData) {
        helferlisteAssertDatabaseIntegrity($db);
    }

    $fromVersion = helferlisteDatabaseVersion($db);
    if ($fromVersion > HELFERLISTE_SCHEMA_VERSION) {
        throw new RuntimeException(sprintf(
            'Die Datenbank hat Schema-Version %d, diese Anwendung unterstützt nur bis Version %d.',
            $fromVersion,
            HELFERLISTE_SCHEMA_VERSION
        ));
    }

    if ($fromVersion === HELFERLISTE_SCHEMA_VERSION) {
        helferlisteAssertDatabaseIntegrity($db);
        return [
            'from_version' => $fromVersion,
            'to_version' => $fromVersion,
            'backup_file' => null,
            'changed' => false,
        ];
    }

    $backupFile = null;
    if ($hadExistingData) {
        $backupFile = helferlisteCreateMigrationBackup(
            $db,
            $backupDirectory ?? $databaseDirectory . DIRECTORY_SEPARATOR . 'backups',
            $fromVersion
        );
    }

    $db->exec('BEGIN IMMEDIATE TRANSACTION');
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            version INTEGER PRIMARY KEY,
            app_version TEXT NOT NULL,
            applied_at TEXT NOT NULL
        )");

        for ($version = $fromVersion + 1; $version <= HELFERLISTE_SCHEMA_VERSION; $version++) {
            helferlisteApplyMigration($db, $version);
            $db->exec('PRAGMA user_version = ' . $version);
            $stmt = $db->prepare("INSERT OR REPLACE INTO schema_migrations (version, app_version, applied_at)
                VALUES (?, ?, datetime('now'))");
            $stmt->execute([$version, HELFERLISTE_VERSION]);
        }

        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    helferlisteAssertDatabaseIntegrity($db);
    $toVersion = helferlisteDatabaseVersion($db);
    if ($toVersion !== HELFERLISTE_SCHEMA_VERSION) {
        throw new RuntimeException('Die erwartete Schema-Version wurde nach der Migration nicht erreicht.');
    }

    return [
        'from_version' => $fromVersion,
        'to_version' => $toVersion,
        'backup_file' => $backupFile,
        'changed' => true,
    ];
}
