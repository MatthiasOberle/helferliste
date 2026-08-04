<?php

declare(strict_types=1);

function printListsTestFail(string $message): never
{
    fwrite(STDERR, "FEHLER: {$message}\n");
    exit(1);
}

function printListsTestAssert(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        printListsTestFail($message . ' Erwartet: ' . var_export($expected, true) . ', erhalten: ' . var_export($actual, true));
    }
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/www/print_lists_lib.php';

try {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys = ON');
    $schema = file_get_contents($projectRoot . '/Install/database_schema.sql');
    if ($schema === false) {
        printListsTestFail('Datenbankschema konnte nicht gelesen werden.');
    }
    $db->exec($schema);

    $db->exec("UPDATE shifts SET title='Aufbau', max_slots=3, shift_date='2026-08-14', start_time='16:00', end_time='20:00', location='Festplatz', note='Handschuhe mitbringen' WHERE id=1");
    $db->exec('UPDATE shifts SET active=0 WHERE id IN (2, 3)');
    $db->exec("UPDATE springer_shifts SET title='Springer', max_slots=2, shift_date='2026-08-15', start_time='10:00', end_time='14:00', location='Infopunkt' WHERE id=1");
    $db->exec('UPDATE springer_shifts SET active=0 WHERE id=2');

    $insertEntry = $db->prepare("INSERT INTO entries (name, status, note, created_at) VALUES (?, ?, ?, datetime('now'))");
    $entryIds = [];
    foreach ([
        ['Berta Beispiel', 'help', 'Privater Hinweis'],
        ['Anna Beispiel', 'help', ''],
        ['Carla Absage', 'no_time', ''],
        ['Ohne Schicht', 'help', ''],
        ['Inaktive Schicht', 'help', ''],
    ] as [$name, $status, $note]) {
        $insertEntry->execute([$name, $status, $note]);
        $entryIds[$name] = (int)$db->lastInsertId();
    }

    $normalLink = $db->prepare('INSERT INTO entry_shifts (entry_id, shift_id) VALUES (?, ?)');
    $normalLink->execute([$entryIds['Berta Beispiel'], 1]);
    $normalLink->execute([$entryIds['Anna Beispiel'], 1]);
    $normalLink->execute([$entryIds['Carla Absage'], 1]);
    $normalLink->execute([$entryIds['Inaktive Schicht'], 2]);
    $db->prepare('INSERT INTO entry_springer_shifts (entry_id, springer_shift_id) VALUES (?, 1)')
        ->execute([$entryIds['Berta Beispiel']]);

    $data = printListData($db, 'Feste Dienste', 'Flexible Hilfe');
    printListsTestAssert(2, $data['shift_count'], 'Aktive Schichten wurden nicht korrekt gezählt.');
    printListsTestAssert(3, $data['assignment_count'], 'Einteilungen wurden nicht korrekt gezählt.');
    printListsTestAssert('Feste Dienste', $data['groups'][0]['label'], 'Konfigurierter Schichtname fehlt.');
    printListsTestAssert('Aufbau', $data['groups'][0]['shifts'][0]['title'], 'Normale Schicht fehlt.');
    printListsTestAssert('14.08.2026 · 16:00–20:00 Uhr · Festplatz', $data['groups'][0]['shifts'][0]['schedule_text'], 'Strukturierte Schichtangaben fehlen.');
    printListsTestAssert(['Anna Beispiel', 'Berta Beispiel'], array_column($data['groups'][0]['shifts'][0]['helpers'], 'name'), 'Zugesagte Personen sind nicht alphabetisch oder enthalten Absagen.');
    printListsTestAssert(1, $data['groups'][0]['shifts'][0]['attendance_blank_rows'], 'Freier Platz wurde nicht als Zusatzzeile vorgesehen.');
    printListsTestAssert(['Inaktive Schicht', 'Ohne Schicht'], array_column($data['unassigned_helpers'], 'name'), 'Nicht aktiv eingeteilte Zusagen werden nicht vollständig ausgewiesen.');

    $serialized = json_encode($data, JSON_THROW_ON_ERROR);
    printListsTestAssert(false, str_contains($serialized, 'Privater Hinweis'), 'Private Hinweise sind in die Druckdaten gelangt.');
    printListsTestAssert(false, str_contains($serialized, 'Carla Absage'), 'Absage ist in die Schichteinteilung gelangt.');

    $invalidTypeRejected = false;
    try {
        printListTypeConfig('unbekannt');
    } catch (InvalidArgumentException) {
        $invalidTypeRejected = true;
    }
    printListsTestAssert(true, $invalidTypeRejected, 'Unbekannte Schichtart wurde angenommen.');

    fwrite(STDOUT, "OK: Schichtplan, Anwesenheitslisten und Datenschutzgrenzen funktionieren.\n");
} catch (Throwable $error) {
    printListsTestFail($error->getMessage());
}

