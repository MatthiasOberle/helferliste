<?php

declare(strict_types=1);

function eventTestFail(string $message): never
{
    fwrite(STDERR, "FEHLER: {$message}\n");
    exit(1);
}

function eventTestAssert(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        eventTestFail($message . ' Erwartet: ' . var_export($expected, true) . ', erhalten: ' . var_export($actual, true));
    }
}

$projectRoot = dirname(__DIR__);
$testDirectory = sys_get_temp_dir() . '/helferliste-event-' . bin2hex(random_bytes(6));
$databaseFile = $testDirectory . '/helferliste.sqlite';
$backupDirectory = $testDirectory . '/backups';

if (!mkdir($testDirectory, 0770, true) && !is_dir($testDirectory)) {
    eventTestFail('Temporärer Testordner konnte nicht angelegt werden.');
}

try {
    require_once $projectRoot . '/Install/migration_lib.php';
    require_once $projectRoot . '/www/event_lifecycle.php';
    helferlisteMigrateDatabase($databaseFile, $backupDirectory);
    $db = new PDO('sqlite:' . $databaseFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys = ON');

    saveAppSetting($db, 'event_name', 'Testfest 2026');
    saveAppSetting($db, 'event_organizer', 'Beispielverein');
    saveAppSetting($db, 'event_start_date', '2026-07-10');
    saveAppSetting($db, 'event_end_date', '2026-07-12');
    saveAppSetting($db, 'event_status', 'published');
    saveAppSetting($db, 'setup_wizard_completed', '1');
    saveAppSetting($db, 'duty_roster_published', '1');
    saveAppSetting($db, 'text_login_heading', 'Eigene Einladung');
    saveAppSetting($db, 'primary_color', '#123456');
    $db->exec("UPDATE shifts SET shift_date = '2026-07-10', start_time = '16:00', end_time = '20:00', location = 'Festplatz', note = 'Bitte Handschuhe mitbringen' WHERE id = 1");
    $db->exec("INSERT INTO access_codes (email, code, created_at) VALUES ('erika@example.org', '4242', datetime('now'))");
    $codeId = (int)$db->lastInsertId();
    $stmt = $db->prepare("INSERT INTO entries (name, status, note, created_at, access_code_id) VALUES (?, 'help', ?, datetime('now'), ?)");
    $stmt->execute(['Erika Beispiel', 'Vegetarisch', $codeId]);
    $entryId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO entry_shifts (entry_id, shift_id) VALUES (?, 1)')->execute([$entryId]);
    $db->prepare("INSERT INTO change_requests (access_code_id, entry_id, name, message, requested_at, status) VALUES (?, ?, ?, ?, datetime('now'), 'open')")
        ->execute([$codeId, $entryId, 'Erika Beispiel', 'Eine Stunde später']);

    $result = archiveAndStartNextEvent($db, $databaseFile, $backupDirectory, [
        'next_event_name' => 'Testfest 2027',
        'next_event_start_date' => '2027-07-09',
        'next_event_end_date' => '2027-07-11',
        'keep_shifts' => true,
        'keep_texts' => true,
        'keep_design' => true,
        'retain_personal_data' => true,
    ]);
    if (!is_file($result['backup_file'])) {
        eventTestFail('Vor dem Veranstaltungsabschluss wurde keine Sicherung erstellt.');
    }
    eventTestAssert(1, (int)$db->query('SELECT COUNT(*) FROM event_archives')->fetchColumn(), 'Archiv wurde nicht angelegt.');
    eventTestAssert(0, (int)$db->query('SELECT COUNT(*) FROM access_codes')->fetchColumn(), 'Alte Zugangscodes wurden nicht entfernt.');
    eventTestAssert(0, (int)$db->query('SELECT COUNT(*) FROM entries')->fetchColumn(), 'Alte Rückmeldungen wurden nicht entfernt.');
    eventTestAssert('Testfest 2027', eventSetting($db, 'event_name'), 'Nächste Veranstaltung wurde nicht gestartet.');
    eventTestAssert('2027-07-09', eventSetting($db, 'event_start_date'), 'Beginn der nächsten Veranstaltung fehlt.');
    eventTestAssert('draft', eventSetting($db, 'event_status'), 'Neue Veranstaltung wurde nicht sicher als Entwurf gestartet.');
    eventTestAssert('0', eventSetting($db, 'setup_wizard_completed'), 'Neue Veranstaltung wurde fälschlich als vollständig eingerichtet markiert.');
    eventTestAssert('0', eventSetting($db, 'duty_roster_published'), 'Alte Diensteinteilung wurde für die neue Veranstaltung nicht gesperrt.');
    eventTestAssert(3, (int)$db->query('SELECT COUNT(*) FROM shifts')->fetchColumn(), 'Schichtvorlage wurde nicht übernommen.');
    eventTestAssert('Eigene Einladung', eventSetting($db, 'text_login_heading'), 'Seitentexte wurden nicht übernommen.');
    eventTestAssert('#123456', eventSetting($db, 'primary_color'), 'Design wurde nicht übernommen.');

    $archive = $db->query('SELECT * FROM event_archives LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    eventTestAssert(1, (int)$archive['includes_personal_data'], 'Gewählte personenbezogene Archivdaten fehlen.');
    $summary = eventJsonDecode((string)$archive['summary_json']);
    eventTestAssert(1, (int)$summary['responses'], 'Archivierte Rückmeldezahl ist falsch.');
    eventTestAssert(1, (int)$summary['normal_shifts'][0]['assigned'], 'Archivierte Schichtbelegung ist falsch.');
    eventTestAssert('2026-07-10', (string)$summary['event_start_date'], 'Archivierter Veranstaltungsbeginn fehlt.');
    eventTestAssert('Festplatz', (string)$summary['normal_shifts'][0]['location'], 'Strukturierte Schichtangaben fehlen im Archiv.');
    $personal = eventJsonDecode((string)$archive['personal_data_json']);
    eventTestAssert('Erika Beispiel', (string)$personal[0]['name'], 'Archivierte Personendaten sind unvollständig.');
    eventTestAssert(true, str_contains((string)$personal[0]['normal_shifts'][0], 'Festplatz'), 'Personenexport enthält die strukturierten Schichtangaben nicht.');
    eventTestAssert(false, str_contains((string)$archive['personal_data_json'], '4242'), 'Zugangscode wurde unzulässig archiviert.');

    eventTestAssert(true, deleteArchivedPersonalData($db, (int)$archive['id']), 'Personendaten konnten nicht kontrolliert gelöscht werden.');
    eventTestAssert(0, (int)$db->query('SELECT includes_personal_data FROM event_archives')->fetchColumn(), 'Archiv ist nach Löschung noch als personenbezogen markiert.');
    eventTestAssert(null, $db->query('SELECT personal_data_json FROM event_archives')->fetchColumn(), 'Personendaten wurden nicht aus dem Archiv entfernt.');

    archiveAndStartNextEvent($db, $databaseFile, $backupDirectory, [
        'next_event_name' => 'Neustart ohne Vorlage',
        'keep_shifts' => false,
        'keep_texts' => false,
        'keep_design' => false,
        'retain_personal_data' => false,
    ]);
    eventTestAssert(0, (int)$db->query('SELECT COUNT(*) FROM shifts')->fetchColumn(), 'Abgewählte Schichtvorlage wurde dennoch übernommen.');
    eventTestAssert(appDefaults()['text_login_heading'], appSetting($db, 'text_login_heading'), 'Seitentexte wurden nicht auf Standard zurückgesetzt.');
    eventTestAssert(appDefaults()['primary_color'], appSetting($db, 'primary_color'), 'Design wurde nicht auf Standard zurückgesetzt.');
    eventTestAssert(0, (int)$db->query('SELECT includes_personal_data FROM event_archives ORDER BY id DESC LIMIT 1')->fetchColumn(), 'Anonymes Archiv enthält Personendaten.');
    eventTestAssert('ok', (string)$db->query('PRAGMA quick_check')->fetchColumn(), 'Datenbank ist nach Veranstaltungsabschluss nicht integer.');

    $templateResult = startEventFromArchiveTemplate(
        $db,
        $databaseFile,
        $backupDirectory,
        (int)$archive['id'],
        'Aus Archivvorlage 2028'
    );
    eventTestAssert(true, is_file($templateResult['backup_file']), 'Vor Übernahme der Archivvorlage fehlt die Sicherung.');
    eventTestAssert('Aus Archivvorlage 2028', eventSetting($db, 'event_name'), 'Veranstaltungsname aus Vorlagenstart fehlt.');
    eventTestAssert(3, (int)$db->query('SELECT COUNT(*) FROM shifts')->fetchColumn(), 'Archivvorlage hat Schichten nicht wiederhergestellt.');
    eventTestAssert('Eigene Einladung', appSetting($db, 'text_login_heading'), 'Archivvorlage hat Seitentexte nicht wiederhergestellt.');
    eventTestAssert('#123456', appSetting($db, 'primary_color'), 'Archivvorlage hat Design nicht wiederhergestellt.');
    eventTestAssert('Festplatz', (string)$db->query('SELECT location FROM shifts ORDER BY id LIMIT 1')->fetchColumn(), 'Archivvorlage hat strukturierte Schichtangaben nicht wiederhergestellt.');
    eventTestAssert('draft', appSetting($db, 'event_status'), 'Archivvorlage wurde nicht als Entwurf gestartet.');
    eventTestAssert('0', appSetting($db, 'setup_wizard_completed'), 'Archivvorlage wurde fälschlich als vollständig eingerichtet markiert.');
    eventTestAssert('0', appSetting($db, 'duty_roster_published'), 'Archivvorlage hat eine alte Diensteinteilung freigegeben.');

    fwrite(STDOUT, "OK: Veranstaltungsabschluss, Archiv, Vorlage und Datenschutz funktionieren.\n");
} catch (Throwable $error) {
    eventTestFail($error->getMessage());
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
