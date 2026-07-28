<?php

declare(strict_types=1);

function schedulingFail(string $message): never
{
    fwrite(STDERR, "FEHLER: {$message}\n");
    exit(1);
}

function schedulingAssert(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        schedulingFail($message . ' Erwartet: ' . var_export($expected, true) . ', erhalten: ' . var_export($actual, true));
    }
}

require_once dirname(__DIR__) . '/www/shift_helpers.php';
require_once dirname(__DIR__) . '/www/app_config.php';

try {
    schedulingAssert(true, validIsoDate('2026-07-28'), 'Gültiges Datum wurde abgewiesen.');
    schedulingAssert(false, validIsoDate('2026-02-31'), 'Ungültiges Kalenderdatum wurde akzeptiert.');
    schedulingAssert(true, validIsoTime('09:30'), 'Gültige Uhrzeit wurde abgewiesen.');
    schedulingAssert('28.07.2026 · 09:30–12:00 Uhr · Vereinsheim', shiftScheduleText([
        'shift_date' => '2026-07-28',
        'start_time' => '09:30',
        'end_time' => '12:00',
        'location' => 'Vereinsheim',
    ]), 'Strukturierte Schichtangaben werden falsch formatiert.');

    $invalidRangeRejected = false;
    try {
        validateTimeRange('12:00', '09:00');
    } catch (InvalidArgumentException) {
        $invalidRangeRejected = true;
    }
    schedulingAssert(true, $invalidRangeRejected, 'Ungültiger Uhrzeitbereich wurde akzeptiert.');

    $config = appDefaults();
    $config['event_start_date'] = '2026-07-28';
    $config['event_end_date'] = '2026-07-30';
    $config['event_status'] = 'draft';
    schedulingAssert('28.07.2026–30.07.2026', appEventDateRange($config), 'Veranstaltungszeitraum wird falsch formatiert.');
    schedulingAssert(false, appEventAcceptsResponses($config), 'Entwurf nimmt öffentliche Rückmeldungen an.');
    $config['event_status'] = 'published';
    schedulingAssert(true, appEventAcceptsResponses($config), 'Veröffentlichte Veranstaltung nimmt keine Rückmeldungen an.');

    $db = new PDO('sqlite::memory:');
    $schema = file_get_contents(dirname(__DIR__) . '/Install/database_schema.sql');
    if ($schema === false) {
        schedulingFail('Datenbankschema konnte nicht gelesen werden.');
    }
    $db->exec($schema);
    schedulingAssert(true, appPublicationIssues($db, appDefaults()) !== [], 'Unvollständige Standardkonfiguration wurde als veröffentlichungsbereit erkannt.');
    $readyConfig = appDefaults();
    $readyConfig['event_name'] = 'Sommerfest 2026';
    $readyConfig['event_organizer'] = 'Beispielverein';
    $readyConfig['event_start_date'] = '2026-07-28';
    $readyConfig['public_base_url'] = 'https://example.org';
    $readyConfig['imprint_name'] = 'Beispielverein e. V.';
    $readyConfig['imprint_address'] = 'Musterweg 1, 12345 Musterstadt';
    $readyConfig['imprint_email'] = 'kontakt@example.org';
    $readyConfig['privacy_contact_email'] = 'datenschutz@example.org';
    schedulingAssert([], appPublicationIssues($db, $readyConfig), 'Vollständige Konfiguration wurde nicht als veröffentlichungsbereit erkannt.');

    fwrite(STDOUT, "OK: Veranstaltungsstatus, Zeitraum und strukturierte Schichtfelder funktionieren.\n");
} catch (Throwable $error) {
    schedulingFail($error->getMessage());
}
