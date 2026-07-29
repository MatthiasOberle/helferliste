<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/www/version.php';
require_once dirname(__DIR__) . '/www/database_tools.php';

function helferlisteDatabaseVersion(PDO $db): int
{
    return (int)$db->query('PRAGMA user_version')->fetchColumn();
}

function helferlisteCreateMigrationBackup(PDO $db, string $backupDirectory, int $fromVersion): string
{
    return helferlisteCreateNamedBackup(
        $db,
        $backupDirectory,
        sprintf(
            'helferliste-schema-v%d-vor-v%d-%s',
            $fromVersion,
            HELFERLISTE_SCHEMA_VERSION,
            date('Ymd-His')
        )
    );
}

/**
 * Stellt eine SQLite-Sicherung atomar wieder her. Eine vorhandene Zieldatenbank
 * wird zuvor ihrerseits gesichert; anschließend läuft die normale Migration.
 *
 * @return array{safety_backup:?string,from_version:int,to_version:int,migrated:bool}
 */
function helferlisteRestoreDatabase(string $backupFile, string $databaseFile, ?string $backupDirectory = null): array
{
    if (!is_file($backupFile) || filesize($backupFile) === 0) {
        throw new RuntimeException('Die ausgewählte Sicherung existiert nicht oder ist leer.');
    }
    if (realpath($backupFile) !== false && realpath($backupFile) === realpath($databaseFile)) {
        throw new RuntimeException('Sicherung und Zieldatenbank dürfen nicht dieselbe Datei sein.');
    }

    $databaseDirectory = dirname($databaseFile);
    if (!is_dir($databaseDirectory) && !mkdir($databaseDirectory, 0770, true) && !is_dir($databaseDirectory)) {
        throw new RuntimeException('Zielordner konnte nicht angelegt werden: ' . $databaseDirectory);
    }
    $backupDirectory ??= $databaseDirectory . DIRECTORY_SEPARATOR . 'backups';

    $source = new PDO('sqlite:' . $backupFile);
    $source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $source->exec('PRAGMA foreign_keys = ON');
    helferlisteAssertDatabaseIntegrity($source);
    $fromVersion = helferlisteDatabaseVersion($source);

    $temporaryFile = $databaseDirectory . DIRECTORY_SEPARATOR . '.helferliste-restore-' . bin2hex(random_bytes(8)) . '.sqlite';
    $source->exec('VACUUM INTO ' . $source->quote($temporaryFile));
    $source = null;

    $temporaryDb = new PDO('sqlite:' . $temporaryFile);
    $temporaryDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $temporaryDb->exec('PRAGMA foreign_keys = ON');
    helferlisteAssertDatabaseIntegrity($temporaryDb);
    $temporaryDb = null;

    $safetyBackup = null;
    $swapFile = null;
    try {
        if (is_file($databaseFile) && filesize($databaseFile) > 0) {
            $current = new PDO('sqlite:' . $databaseFile);
            $current->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $current->exec('PRAGMA foreign_keys = ON');
            helferlisteAssertDatabaseIntegrity($current);
            $current->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            $journalMode = strtolower((string)$current->query('PRAGMA journal_mode = DELETE')->fetchColumn());
            if ($journalMode !== 'delete') {
                throw new RuntimeException('Die Zieldatenbank ist noch aktiv. Bitte den Webzugriff vor der Wiederherstellung vollständig stoppen.');
            }
            $safetyBackup = helferlisteCreateNamedBackup(
                $current,
                $backupDirectory,
                'helferliste-vor-wiederherstellung-' . date('Ymd-His')
            );
            $current = null;

            $swapFile = $databaseFile . '.vor-wiederherstellung-' . bin2hex(random_bytes(4));
            if (!rename($databaseFile, $swapFile)) {
                throw new RuntimeException('Die vorhandene Datenbank konnte nicht für die Wiederherstellung vorbereitet werden.');
            }
        }

        if (!rename($temporaryFile, $databaseFile)) {
            if ($swapFile !== null && is_file($swapFile)) {
                rename($swapFile, $databaseFile);
            }
            throw new RuntimeException('Die wiederhergestellte Datenbank konnte nicht aktiviert werden.');
        }
        if ($swapFile !== null && is_file($swapFile)) {
            unlink($swapFile);
        }
    } catch (Throwable $error) {
        if (is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
        throw $error;
    }

    $migration = helferlisteMigrateDatabase($databaseFile, $backupDirectory);
    return [
        'safety_backup' => $safetyBackup,
        'from_version' => $fromVersion,
        'to_version' => $migration['to_version'],
        'migrated' => $migration['from_version'] !== $migration['to_version'],
    ];
}

function helferlisteApplyMigration(PDO $db, int $version): void
{
    $migrationFiles = [
        1 => __DIR__ . '/migrations/001_baseline.sql',
        2 => __DIR__ . '/migrations/002_secure_admin_setup.sql',
        3 => __DIR__ . '/migrations/003_event_archives.sql',
        4 => __DIR__ . '/migrations/004_event_schedule_and_shift_fields.sql',
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

function helferlisteMigrationSetting(PDO $db, string $key): ?string
{
    $stmt = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value !== false && trim((string)$value) !== '' ? (string)$value : null;
}

function helferlisteCreateAdminSetupToken(PDO $db): ?string
{
    if (helferlisteMigrationSetting($db, 'admin_password_hash') !== null) {
        return null;
    }
    if (helferlisteMigrationSetting($db, 'admin_setup_token_hash') !== null) {
        return null;
    }

    $token = strtoupper(bin2hex(random_bytes(16)));
    $stmt = $db->prepare("INSERT OR REPLACE INTO app_settings (setting_key, setting_value, updated_at)
        VALUES (?, ?, datetime('now'))");
    $stmt->execute(['admin_setup_token_hash', password_hash($token, PASSWORD_DEFAULT)]);
    $stmt->execute(['admin_setup_created_at', gmdate('c')]);

    return implode('-', str_split($token, 4));
}

function helferlisteEnsureAdminAuthGeneration(PDO $db): bool
{
    if (helferlisteMigrationSetting($db, 'admin_password_hash') === null) {
        return false;
    }
    if (helferlisteMigrationSetting($db, 'admin_auth_generation') !== null) {
        return false;
    }

    $stmt = $db->prepare("INSERT OR REPLACE INTO app_settings (setting_key, setting_value, updated_at)
        VALUES ('admin_auth_generation', ?, datetime('now'))");
    $stmt->execute([bin2hex(random_bytes(16))]);
    return true;
}

/**
 * @return array{backup_file:string,setup_token:string}
 */
function helferlisteResetAdminAccess(string $databaseFile, ?string $backupDirectory = null): array
{
    if (!is_file($databaseFile) || filesize($databaseFile) === 0) {
        throw new RuntimeException('Die angegebene Datenbank existiert nicht oder ist leer.');
    }

    $db = new PDO('sqlite:' . $databaseFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');
    helferlisteAssertDatabaseIntegrity($db);

    $schemaVersion = helferlisteDatabaseVersion($db);
    if ($schemaVersion !== HELFERLISTE_SCHEMA_VERSION) {
        throw new RuntimeException('Vor dem Admin-Reset muss die Datenbank auf die aktuelle Schema-Version migriert werden.');
    }

    $backupDirectory ??= dirname($databaseFile) . DIRECTORY_SEPARATOR . 'backups';
    $backupFile = helferlisteCreateNamedBackup(
        $db,
        $backupDirectory,
        'helferliste-vor-admin-reset-' . date('Ymd-His')
    );

    helferlisteBeginImmediateTransaction($db);
    try {
        $stmt = $db->prepare('DELETE FROM app_settings WHERE setting_key IN (?, ?, ?, ?)');
        $stmt->execute(['admin_password_hash', 'admin_setup_token_hash', 'admin_setup_created_at', 'admin_auth_generation']);
        $setupToken = helferlisteCreateAdminSetupToken($db);
        if ($setupToken === null) {
            throw new RuntimeException('Neuer Einrichtungscode konnte nicht erzeugt werden.');
        }
        helferlisteCommitTransaction($db);
    } catch (Throwable $error) {
        helferlisteRollbackTransaction($db);
        throw $error;
    }

    helferlisteAssertDatabaseIntegrity($db);
    return ['backup_file' => $backupFile, 'setup_token' => $setupToken];
}

/**
 * @return array{from_version:int,to_version:int,backup_file:?string,changed:bool,setup_token:?string}
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
        $setupToken = null;
        $hasPassword = helferlisteMigrationSetting($db, 'admin_password_hash') !== null;
        $hasSetupToken = helferlisteMigrationSetting($db, 'admin_setup_token_hash') !== null;
        $needsAdminState = (!$hasPassword && !$hasSetupToken)
            || ($hasPassword && helferlisteMigrationSetting($db, 'admin_auth_generation') === null);

        if ($needsAdminState) {
            $backupFile = $hadExistingData
                ? helferlisteCreateMigrationBackup(
                    $db,
                    $backupDirectory ?? $databaseDirectory . DIRECTORY_SEPARATOR . 'backups',
                    $fromVersion
                )
                : null;
            helferlisteBeginImmediateTransaction($db);
            try {
                $setupToken = helferlisteCreateAdminSetupToken($db);
                helferlisteEnsureAdminAuthGeneration($db);
                helferlisteCommitTransaction($db);
            } catch (Throwable $error) {
                helferlisteRollbackTransaction($db);
                throw $error;
            }
            helferlisteAssertDatabaseIntegrity($db);

            return [
                'from_version' => $fromVersion,
                'to_version' => $fromVersion,
                'backup_file' => $backupFile,
                'changed' => true,
                'setup_token' => $setupToken,
            ];
        }

        helferlisteAssertDatabaseIntegrity($db);
        return [
            'from_version' => $fromVersion,
            'to_version' => $fromVersion,
            'backup_file' => null,
            'changed' => false,
            'setup_token' => null,
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

    $setupToken = null;
    helferlisteBeginImmediateTransaction($db);
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            version INTEGER PRIMARY KEY,
            app_version TEXT NOT NULL,
            applied_at TEXT NOT NULL
        )");

        for ($version = $fromVersion + 1; $version <= HELFERLISTE_SCHEMA_VERSION; $version++) {
            helferlisteApplyMigration($db, $version);
            $db->exec('PRAGMA user_version = ' . $version);
            if ($version === 2) {
                $setupToken = helferlisteCreateAdminSetupToken($db);
                helferlisteEnsureAdminAuthGeneration($db);
            }
            $stmt = $db->prepare("INSERT OR REPLACE INTO schema_migrations (version, app_version, applied_at)
                VALUES (?, ?, datetime('now'))");
            $stmt->execute([$version, HELFERLISTE_VERSION]);
        }

        if (!$hadExistingData) {
            $initialSetting = $db->prepare("INSERT OR REPLACE INTO app_settings
                (setting_key, setting_value, updated_at) VALUES (?, ?, datetime('now'))");
            $initialSetting->execute(['event_status', 'draft']);
            $initialSetting->execute(['setup_wizard_completed', '0']);
        }

        helferlisteCommitTransaction($db);
    } catch (Throwable $error) {
        helferlisteRollbackTransaction($db);
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
        'setup_token' => $setupToken,
    ];
}
