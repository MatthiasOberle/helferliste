<?php

declare(strict_types=1);

require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/shift_helpers.php';

function setupWizardLimitText(string $value, int $maxLength): string
{
    $value = trim($value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
}

function setupWizardValidateEvent(array $input): array
{
    $values = [
        'app_name' => setupWizardLimitText((string)($input['app_name'] ?? ''), 100),
        'event_organizer' => setupWizardLimitText((string)($input['event_organizer'] ?? ''), 160),
        'event_name' => setupWizardLimitText((string)($input['event_name'] ?? ''), 160),
        'event_start_date' => normalizeOptionalDate((string)($input['event_start_date'] ?? '')),
        'event_end_date' => normalizeOptionalDate((string)($input['event_end_date'] ?? '')),
    ];

    if ($values['app_name'] === '') {
        throw new InvalidArgumentException('Bitte gib einen Namen für die Anwendung ein.');
    }
    if ($values['event_organizer'] === '' || $values['event_organizer'] === appDefaults()['event_organizer']) {
        throw new InvalidArgumentException('Bitte gib die verantwortliche Organisation ein.');
    }
    if ($values['event_name'] === '' || $values['event_name'] === appDefaults()['event_name']) {
        throw new InvalidArgumentException('Bitte gib einen konkreten Veranstaltungsnamen ein.');
    }
    if ($values['event_start_date'] === '') {
        throw new InvalidArgumentException('Bitte gib den Beginn der Veranstaltung ein.');
    }
    if ($values['event_end_date'] !== '' && $values['event_end_date'] < $values['event_start_date']) {
        throw new InvalidArgumentException('Das Veranstaltungsende darf nicht vor dem Beginn liegen.');
    }

    return $values;
}

function setupWizardValidateLegal(array $input): array
{
    $baseUrl = rtrim(trim((string)($input['public_base_url'] ?? '')), '/');
    $values = [
        'public_base_url' => setupWizardLimitText($baseUrl, 500),
        'imprint_name' => setupWizardLimitText((string)($input['imprint_name'] ?? ''), 200),
        'imprint_address' => setupWizardLimitText((string)($input['imprint_address'] ?? ''), 500),
        'imprint_email' => setupWizardLimitText((string)($input['imprint_email'] ?? ''), 254),
        'imprint_phone' => setupWizardLimitText((string)($input['imprint_phone'] ?? ''), 100),
        'imprint_website' => setupWizardLimitText((string)($input['imprint_website'] ?? ''), 500),
        'privacy_contact_name' => setupWizardLimitText((string)($input['privacy_contact_name'] ?? ''), 200),
        'privacy_contact_email' => setupWizardLimitText((string)($input['privacy_contact_email'] ?? ''), 254),
    ];

    if (!filter_var($values['public_base_url'], FILTER_VALIDATE_URL)
        || !preg_match('#^https?://#i', $values['public_base_url'])) {
        throw new InvalidArgumentException('Bitte gib eine vollständige öffentliche Adresse mit http:// oder https:// ein.');
    }
    if ($values['imprint_name'] === '' || $values['imprint_address'] === '') {
        throw new InvalidArgumentException('Bitte vervollständige Name und Anschrift für das Impressum.');
    }
    if (!filter_var($values['imprint_email'], FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Bitte gib eine gültige E-Mail-Adresse für das Impressum ein.');
    }
    if ($values['imprint_website'] !== '' && !filter_var($values['imprint_website'], FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Die optionale Website-Adresse ist ungültig.');
    }
    if (!filter_var($values['privacy_contact_email'], FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Bitte gib eine gültige Datenschutz-Kontaktadresse ein.');
    }

    return $values;
}

function setupWizardValidateShift(array $input): array
{
    $type = (string)($input['type'] ?? 'normal');
    if (!in_array($type, ['normal', 'springer'], true)) {
        throw new InvalidArgumentException('Bitte wähle eine gültige Schichtart.');
    }

    $start = normalizeOptionalTime((string)($input['start_time'] ?? ''));
    $end = normalizeOptionalTime((string)($input['end_time'] ?? ''));
    validateTimeRange($start, $end);
    $capacity = (int)($input['max_slots'] ?? 0);
    $title = setupWizardLimitText((string)($input['title'] ?? ''), 160);

    if ($title === '' || str_starts_with($title, 'Beispiel:')) {
        throw new InvalidArgumentException('Bitte gib einen konkreten Schichttitel ohne „Beispiel:“ ein.');
    }
    if ($capacity < 1 || $capacity > 9999) {
        throw new InvalidArgumentException('Die Kapazität muss zwischen 1 und 9999 liegen.');
    }

    return [
        'type' => $type,
        'title' => $title,
        'shift_date' => normalizeOptionalDate((string)($input['shift_date'] ?? '')),
        'start_time' => $start,
        'end_time' => $end,
        'location' => setupWizardLimitText((string)($input['location'] ?? ''), 160),
        'max_slots' => $capacity,
        'note' => setupWizardLimitText((string)($input['note'] ?? ''), 600),
    ];
}

function setupWizardSaveSettings(PDO $db, array $values): void
{
    $db->beginTransaction();
    try {
        foreach ($values as $key => $value) {
            saveAppSetting($db, (string)$key, (string)$value);
        }
        saveAppSetting($db, 'setup_wizard_completed', '0');
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function setupWizardDeactivateUnusedExamples(PDO $db): void
{
    $db->exec("UPDATE shifts
        SET active = 0
        WHERE active = 1 AND title LIKE 'Beispiel:%'
          AND NOT EXISTS (SELECT 1 FROM entry_shifts WHERE entry_shifts.shift_id = shifts.id)");
    $db->exec("UPDATE springer_shifts
        SET active = 0
        WHERE active = 1 AND title LIKE 'Beispiel:%'
          AND NOT EXISTS (
              SELECT 1 FROM entry_springer_shifts
              WHERE entry_springer_shifts.springer_shift_id = springer_shifts.id
          )");
}

function setupWizardCreateShift(PDO $db, array $values): int
{
    $values = setupWizardValidateShift($values);
    $table = $values['type'] === 'springer' ? 'springer_shifts' : 'shifts';

    $db->beginTransaction();
    try {
        setupWizardDeactivateUnusedExamples($db);
        $sortOrder = (int)$db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM {$table}")->fetchColumn();
        $stmt = $db->prepare("INSERT INTO {$table}
            (title, shift_date, start_time, end_time, location, note, max_slots, sort_order, active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([
            $values['title'],
            $values['shift_date'],
            $values['start_time'],
            $values['end_time'],
            $values['location'],
            $values['note'],
            $values['max_slots'],
            $sortOrder,
        ]);
        saveAppSetting($db, 'setup_wizard_completed', '0');
        $id = (int)$db->lastInsertId();
        $db->commit();
        return $id;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function setupWizardRealShiftCount(PDO $db): int
{
    return (int)$db->query("SELECT
        (SELECT COUNT(*) FROM shifts WHERE active = 1 AND title NOT LIKE 'Beispiel:%')
        + (SELECT COUNT(*) FROM springer_shifts WHERE active = 1 AND title NOT LIKE 'Beispiel:%')")->fetchColumn();
}

function setupWizardFinish(PDO $db, string $status): void
{
    if (!in_array($status, ['draft', 'published'], true)) {
        throw new InvalidArgumentException('Bitte wähle einen gültigen Abschluss.');
    }

    $config = appConfig($db);
    $issues = appPublicationIssues($db, $config);
    if ($issues !== []) {
        throw new RuntimeException('Die Einrichtung ist noch nicht vollständig: ' . implode(', ', $issues) . '.');
    }

    $db->beginTransaction();
    try {
        saveAppSetting($db, 'event_status', $status);
        saveAppSetting($db, 'setup_wizard_completed', '1');
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
