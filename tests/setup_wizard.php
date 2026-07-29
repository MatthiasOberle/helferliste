<?php

declare(strict_types=1);

function setupTestFail(string $message): never
{
    fwrite(STDERR, "FEHLER: {$message}\n");
    exit(1);
}

function setupTestAssert(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        setupTestFail($message . ' Erwartet: ' . var_export($expected, true) . ', erhalten: ' . var_export($actual, true));
    }
}

require_once dirname(__DIR__) . '/www/setup_wizard_lib.php';

try {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $schema = file_get_contents(dirname(__DIR__) . '/Install/database_schema.sql');
    if ($schema === false) {
        setupTestFail('Datenbankschema konnte nicht gelesen werden.');
    }
    $db->exec($schema);

    $event = setupWizardValidateEvent([
        'app_name' => 'Helferliste Stadtfest',
        'event_organizer' => 'Beispielverein',
        'event_name' => 'Stadtfest 2026',
        'event_start_date' => '2026-08-14',
        'event_end_date' => '2026-08-16',
    ]);
    setupWizardSaveSettings($db, $event + ['event_status' => 'draft']);
    setupTestAssert('Stadtfest 2026', appSetting($db, 'event_name'), 'Veranstaltung wurde nicht gespeichert.');

    $invalidPeriodRejected = false;
    try {
        setupWizardValidateEvent([
            'app_name' => 'Helferliste',
            'event_organizer' => 'Beispielverein',
            'event_name' => 'Stadtfest',
            'event_start_date' => '2026-08-16',
            'event_end_date' => '2026-08-14',
        ]);
    } catch (InvalidArgumentException) {
        $invalidPeriodRejected = true;
    }
    setupTestAssert(true, $invalidPeriodRejected, 'Ungültiger Veranstaltungszeitraum wurde angenommen.');

    setupWizardSaveSettings($db, setupWizardValidateLegal([
        'public_base_url' => 'https://helfer.example.org/',
        'imprint_name' => 'Beispielverein e. V.',
        'imprint_address' => 'Musterweg 1, 12345 Musterstadt',
        'imprint_email' => 'kontakt@example.org',
        'privacy_contact_email' => 'datenschutz@example.org',
    ]));
    setupTestAssert('https://helfer.example.org', appSetting($db, 'public_base_url'), 'Basis-URL wurde nicht normalisiert.');

    $shiftId = setupWizardCreateShift($db, [
        'type' => 'normal',
        'title' => 'Aufbau Festhalle',
        'shift_date' => '2026-08-14',
        'start_time' => '16:00',
        'end_time' => '20:00',
        'location' => 'Festhalle',
        'max_slots' => '8',
        'note' => 'Arbeitshandschuhe mitbringen',
    ]);
    setupTestAssert(true, $shiftId > 0, 'Erste Schicht wurde nicht angelegt.');
    setupTestAssert(1, setupWizardRealShiftCount($db), 'Konkrete Schicht wurde nicht erkannt.');
    setupTestAssert(0, (int)$db->query("SELECT COUNT(*) FROM shifts WHERE active = 1 AND title LIKE 'Beispiel:%'")->fetchColumn(), 'Beispielschichten wurden nicht deaktiviert.');
    setupTestAssert([], appPublicationIssues($db, appConfig($db)), 'Vollständige Assistentenangaben sind nicht veröffentlichungsbereit.');

    setupWizardFinish($db, 'draft');
    setupTestAssert('1', appSetting($db, 'setup_wizard_completed'), 'Assistent wurde nicht als abgeschlossen markiert.');
    setupTestAssert('draft', appSetting($db, 'event_status'), 'Entwurfsstatus wurde nicht beibehalten.');

    setupWizardFinish($db, 'published');
    setupTestAssert('published', appSetting($db, 'event_status'), 'Veranstaltung wurde nicht veröffentlicht.');

    fwrite(STDOUT, "OK: Geführte Ersteinrichtung, Pflichtangaben und erste Schicht funktionieren.\n");
} catch (Throwable $error) {
    setupTestFail($error->getMessage());
}
