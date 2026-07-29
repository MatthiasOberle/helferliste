<?php

declare(strict_types=1);

function helferlisteBeginImmediateTransaction(PDO $db): void
{
    $db->exec('BEGIN IMMEDIATE TRANSACTION');
}

function helferlisteCommitTransaction(PDO $db): void
{
    $db->exec('COMMIT');
}

function helferlisteRollbackTransaction(PDO $db): void
{
    try {
        $db->exec('ROLLBACK');
    } catch (Throwable) {
        // Die ursprüngliche Ausnahme bleibt maßgeblich, auch wenn kein Rollback mehr möglich ist.
    }
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

function helferlisteCreateNamedBackup(PDO $db, string $backupDirectory, string $baseName): string
{
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0770, true) && !is_dir($backupDirectory)) {
        throw new RuntimeException('Backup-Ordner konnte nicht angelegt werden: ' . $backupDirectory);
    }

    $backupFile = rtrim($backupDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $baseName . '.sqlite';
    $suffix = 2;

    while (file_exists($backupFile)) {
        $backupFile = rtrim($backupDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $baseName . '-' . $suffix . '.sqlite';
        $suffix++;
    }

    $db->exec('VACUUM INTO ' . $db->quote($backupFile));
    return $backupFile;
}
