<?php

declare(strict_types=1);

require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/database_tools.php';

function eventJsonEncode(array $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function eventJsonDecode(string $value): array
{
    $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    return is_array($decoded) ? $decoded : [];
}

function eventSetting(PDO $db, string $key, string $fallback = ''): string
{
    $stmt = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $fallback : (string)$value;
}

function eventShiftSummary(PDO $db, string $table, string $linkTable, string $linkColumn): array
{
    $sql = "SELECT s.title, s.max_slots, s.sort_order, s.active, COUNT(es.entry_id) AS assigned
        FROM {$table} s
        LEFT JOIN {$linkTable} es ON es.{$linkColumn} = s.id
        GROUP BY s.id
        ORDER BY s.sort_order, s.id";
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static fn(array $row): array => [
        'title' => (string)$row['title'],
        'max_slots' => (int)$row['max_slots'],
        'sort_order' => (int)$row['sort_order'],
        'active' => (int)$row['active'] === 1,
        'assigned' => (int)$row['assigned'],
    ], $rows);
}

function eventSummary(PDO $db): array
{
    return [
        'codes' => (int)$db->query('SELECT COUNT(*) FROM access_codes')->fetchColumn(),
        'responses' => (int)$db->query('SELECT COUNT(*) FROM entries')->fetchColumn(),
        'helps' => (int)$db->query("SELECT COUNT(*) FROM entries WHERE status = 'help'")->fetchColumn(),
        'declines' => (int)$db->query("SELECT COUNT(*) FROM entries WHERE status = 'no_time'")->fetchColumn(),
        'open_change_requests' => (int)$db->query("SELECT COUNT(*) FROM change_requests WHERE status = 'open'")->fetchColumn(),
        'normal_shifts' => eventShiftSummary($db, 'shifts', 'entry_shifts', 'shift_id'),
        'flexible_shifts' => eventShiftSummary($db, 'springer_shifts', 'entry_springer_shifts', 'springer_shift_id'),
    ];
}

function eventTemplate(PDO $db): array
{
    $settings = [];
    foreach (appDefaults() as $key => $fallback) {
        if ($key !== 'event_name') {
            $settings[$key] = appSetting($db, $key, (string)$fallback);
        }
    }

    return [
        'settings' => $settings,
        'normal_shifts' => eventShiftSummary($db, 'shifts', 'entry_shifts', 'shift_id'),
        'flexible_shifts' => eventShiftSummary($db, 'springer_shifts', 'entry_springer_shifts', 'springer_shift_id'),
    ];
}

function eventPersonalResults(PDO $db): array
{
    $entries = $db->query("SELECT e.id, e.name, e.status, e.note, e.created_at, ac.email
        FROM entries e
        LEFT JOIN access_codes ac ON ac.id = e.access_code_id
        ORDER BY e.created_at, e.id")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $normalStmt = $db->prepare("SELECT s.title FROM entry_shifts es
        JOIN shifts s ON s.id = es.shift_id
        WHERE es.entry_id = ? ORDER BY s.sort_order, s.id");
    $flexibleStmt = $db->prepare("SELECT s.title FROM entry_springer_shifts es
        JOIN springer_shifts s ON s.id = es.springer_shift_id
        WHERE es.entry_id = ? ORDER BY s.sort_order, s.id");
    $requestStmt = $db->prepare("SELECT message, requested_at, status, handled_at, admin_note
        FROM change_requests WHERE entry_id = ? ORDER BY requested_at, id");

    $result = [];
    foreach ($entries as $entry) {
        $entryId = (int)$entry['id'];
        $normalStmt->execute([$entryId]);
        $flexibleStmt->execute([$entryId]);
        $requestStmt->execute([$entryId]);
        $result[] = [
            'name' => (string)$entry['name'],
            'email' => (string)($entry['email'] ?? ''),
            'status' => (string)$entry['status'],
            'note' => (string)($entry['note'] ?? ''),
            'created_at' => (string)($entry['created_at'] ?? ''),
            'normal_shifts' => $normalStmt->fetchAll(PDO::FETCH_COLUMN) ?: [],
            'flexible_shifts' => $flexibleStmt->fetchAll(PDO::FETCH_COLUMN) ?: [],
            'change_requests' => $requestStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ];
    }

    return $result;
}

function resetEventSettings(PDO $db, bool $keepTexts, bool $keepDesign): void
{
    $designKeys = [
        'hero_image', 'hero_image_alt', 'primary_color', 'primary_color_dark', 'primary_color_soft',
        'hero_color_start', 'hero_color_end', 'page_background_color', 'page_text_color',
        'card_background_color', 'muted_text_color', 'font_family', 'occupancy_color_low',
        'occupancy_color_medium', 'occupancy_color_high', 'occupancy_color_full',
    ];
    $textKeys = ['public_login_info_text'];
    foreach (array_keys(appDefaults()) as $key) {
        if (str_starts_with($key, 'text_')) {
            $textKeys[] = $key;
        }
    }

    $keysToDelete = [];
    if (!$keepTexts) {
        $keysToDelete = array_merge($keysToDelete, $textKeys);
    }
    if (!$keepDesign) {
        $keysToDelete = array_merge($keysToDelete, $designKeys);
    }
    if ($keysToDelete === []) {
        return;
    }

    $stmt = $db->prepare('DELETE FROM app_settings WHERE setting_key = ?');
    foreach (array_unique($keysToDelete) as $key) {
        $stmt->execute([$key]);
    }
}

/**
 * @param array{next_event_name:string,keep_shifts:bool,keep_texts:bool,keep_design:bool,retain_personal_data:bool} $options
 * @return array{archive_id:int,backup_file:string}
 */
function archiveAndStartNextEvent(PDO $db, string $databaseFile, string $backupDirectory, array $options): array
{
    helferlisteAssertDatabaseIntegrity($db);
    $eventName = trim(eventSetting($db, 'event_name', appDefaults()['event_name']));
    $organizer = trim(eventSetting($db, 'event_organizer', appDefaults()['event_organizer']));
    $nextEventName = trim($options['next_event_name']);
    if ($eventName === '' || $nextEventName === '') {
        throw new InvalidArgumentException('Aktuelle und nächste Veranstaltung benötigen einen Namen.');
    }
    if (strlen($nextEventName) > 160) {
        throw new InvalidArgumentException('Der Name der nächsten Veranstaltung ist zu lang.');
    }

    $summary = eventSummary($db);
    $template = eventTemplate($db);
    $personalResults = $options['retain_personal_data'] ? eventPersonalResults($db) : null;
    $safeEventName = preg_replace('/[^a-z0-9]+/i', '-', $eventName) ?: 'veranstaltung';
    $safeEventName = trim(strtolower($safeEventName), '-');
    $backupFile = helferlisteCreateNamedBackup(
        $db,
        $backupDirectory,
        'helferliste-vor-abschluss-' . substr($safeEventName, 0, 48) . '-' . date('Ymd-His')
    );

    $db->exec('BEGIN IMMEDIATE TRANSACTION');
    try {
        $stmt = $db->prepare("INSERT INTO event_archives
            (event_name, event_organizer, closed_at, summary_json, template_json, personal_data_json, includes_personal_data)
            VALUES (?, ?, datetime('now'), ?, ?, ?, ?)");
        $stmt->execute([
            $eventName,
            $organizer,
            eventJsonEncode($summary),
            eventJsonEncode($template),
            $personalResults === null ? null : eventJsonEncode($personalResults),
            $personalResults === null ? 0 : 1,
        ]);
        $archiveId = (int)$db->lastInsertId();

        foreach (['change_requests', 'entry_springer_shifts', 'entry_shifts', 'entries', 'access_codes', 'visit_stats', 'event_log'] as $table) {
            $db->exec('DELETE FROM ' . $table);
        }
        if (!$options['keep_shifts']) {
            $db->exec('DELETE FROM springer_shifts');
            $db->exec('DELETE FROM shifts');
        }
        resetEventSettings($db, $options['keep_texts'], $options['keep_design']);
        saveAppSetting($db, 'event_name', $nextEventName);
        $logStmt = $db->prepare("INSERT INTO event_log (created_at, action, detail) VALUES (datetime('now'), 'event_started', ?)");
        $logStmt->execute(['Neue Veranstaltung gestartet: ' . $nextEventName . '. Archiv #' . $archiveId . '.']);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    helferlisteAssertDatabaseIntegrity($db);
    return ['archive_id' => $archiveId, 'backup_file' => $backupFile];
}

function deleteArchivedPersonalData(PDO $db, int $archiveId): bool
{
    $stmt = $db->prepare("UPDATE event_archives
        SET personal_data_json = NULL, includes_personal_data = 0
        WHERE id = ? AND includes_personal_data = 1");
    $stmt->execute([$archiveId]);
    return $stmt->rowCount() === 1;
}

/**
 * @return array{backup_file:string}
 */
function startEventFromArchiveTemplate(
    PDO $db,
    string $databaseFile,
    string $backupDirectory,
    int $archiveId,
    string $eventName
): array {
    $eventName = trim($eventName);
    if ($archiveId <= 0 || $eventName === '' || strlen($eventName) > 160) {
        throw new InvalidArgumentException('Archiv und Veranstaltungsname müssen gültig sein.');
    }
    $activeRecords = (int)$db->query('SELECT (SELECT COUNT(*) FROM entries) + (SELECT COUNT(*) FROM access_codes)')->fetchColumn();
    if ($activeRecords > 0) {
        throw new RuntimeException('Eine Archivvorlage kann nur übernommen werden, wenn die aktive Veranstaltung noch keine Codes oder Rückmeldungen enthält.');
    }

    $stmt = $db->prepare('SELECT event_name, template_json FROM event_archives WHERE id = ?');
    $stmt->execute([$archiveId]);
    $archive = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = null;
    if (!$archive) {
        throw new RuntimeException('Die ausgewählte Archivvorlage wurde nicht gefunden.');
    }
    $template = eventJsonDecode((string)$archive['template_json']);
    $backupFile = helferlisteCreateNamedBackup(
        $db,
        $backupDirectory,
        'helferliste-vor-vorlage-' . $archiveId . '-' . date('Ymd-His')
    );

    $db->exec('BEGIN IMMEDIATE TRANSACTION');
    try {
        $db->exec('DELETE FROM springer_shifts');
        $db->exec('DELETE FROM shifts');
        $deleteSetting = $db->prepare('DELETE FROM app_settings WHERE setting_key = ?');
        foreach (array_keys(appDefaults()) as $key) {
            if ($key !== 'event_name') {
                $deleteSetting->execute([$key]);
            }
        }
        foreach (($template['settings'] ?? []) as $key => $value) {
            if ($key !== 'event_name' && array_key_exists($key, appDefaults())) {
                saveAppSetting($db, (string)$key, (string)$value);
            }
        }

        $insertShift = $db->prepare('INSERT INTO shifts (title, max_slots, sort_order, active) VALUES (?, ?, ?, ?)');
        foreach (($template['normal_shifts'] ?? []) as $shift) {
            $insertShift->execute([
                (string)($shift['title'] ?? ''),
                max(1, (int)($shift['max_slots'] ?? 1)),
                max(1, (int)($shift['sort_order'] ?? 1)),
                !empty($shift['active']) ? 1 : 0,
            ]);
        }
        $insertFlexible = $db->prepare('INSERT INTO springer_shifts (title, max_slots, sort_order, active) VALUES (?, ?, ?, ?)');
        foreach (($template['flexible_shifts'] ?? []) as $shift) {
            $insertFlexible->execute([
                (string)($shift['title'] ?? ''),
                max(1, (int)($shift['max_slots'] ?? 1)),
                max(1, (int)($shift['sort_order'] ?? 1)),
                !empty($shift['active']) ? 1 : 0,
            ]);
        }
        saveAppSetting($db, 'event_name', $eventName);
        $logStmt = $db->prepare("INSERT INTO event_log (created_at, action, detail) VALUES (datetime('now'), 'archive_template_applied', ?)");
        $logStmt->execute(['Vorlage aus Archiv #' . $archiveId . ' (' . (string)$archive['event_name'] . ') übernommen.']);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    helferlisteAssertDatabaseIntegrity($db);
    return ['backup_file' => $backupFile];
}
