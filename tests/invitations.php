<?php

declare(strict_types=1);

function invitationTestFail(string $message): never
{
    fwrite(STDERR, "FEHLER: {$message}\n");
    exit(1);
}

function invitationTestAssert(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        invitationTestFail($message . ' Erwartet: ' . var_export($expected, true) . ', erhalten: ' . var_export($actual, true));
    }
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/www/invitations_lib.php';

try {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $schema = file_get_contents($projectRoot . '/Install/database_schema.sql');
    if ($schema === false) {
        invitationTestFail('Datenbankschema konnte nicht gelesen werden.');
    }
    $db->exec($schema);

    $db->exec("INSERT INTO access_codes (email, code, created_at) VALUES
        ('offen@example.org', '1111', '2026-08-24 10:00:00'),
        ('zusage@example.org', '2222', '2026-08-24 09:00:00'),
        ('absage@example.org', '3333', '2026-08-24 08:00:00')");
    $helpCode = (int)$db->query("SELECT id FROM access_codes WHERE code='2222'")->fetchColumn();
    $declineCode = (int)$db->query("SELECT id FROM access_codes WHERE code='3333'")->fetchColumn();
    $stmt = $db->prepare("INSERT INTO entries (name, status, created_at, access_code_id) VALUES (?, ?, datetime('now'), ?)");
    $stmt->execute(['Zusage Beispiel', 'help', $helpCode]);
    $stmt->execute(['Absage Beispiel', 'no_time', $declineCode]);

    invitationTestAssert(['pending' => 1, 'help' => 1, 'no_time' => 1, 'all' => 3], invitationCounts($db), 'Rückmeldestatus wurde falsch gezählt.');
    invitationTestAssert(['offen@example.org'], array_column(invitationRows($db, 'pending'), 'email'), 'Filter ohne Rückmeldung ist falsch.');
    invitationTestAssert(['zusage@example.org'], array_column(invitationRows($db, 'help'), 'email'), 'Zusagefilter ist falsch.');
    invitationTestAssert(['absage@example.org'], array_column(invitationRows($db, 'no_time'), 'email'), 'Absagefilter ist falsch.');
    invitationTestAssert(3, count(invitationRows($db, 'all')), 'Gesamtfilter ist unvollständig.');
    invitationTestAssert('pending', invitationNormalizeFilter('unbekannt'), 'Ungültiger Filter wurde nicht sicher zurückgesetzt.');

    $config = appDefaults();
    $config['event_name'] = 'Sommerfest 2026';
    $config['event_organizer'] = 'Beispielverein';
    $config['event_start_date'] = '2026-08-29';
    $config['event_end_date'] = '2026-08-30';
    $pending = invitationRows($db, 'pending')[0];
    $message = invitationMessage($config, $pending, 'reminder', 'https://helfer.example.org');
    invitationTestAssert('Erinnerung: Rückmeldung für Sommerfest 2026', $message['subject'], 'Erinnerungsbetreff wurde nicht ersetzt.');
    invitationTestAssert(true, str_contains($message['body'], 'https://helfer.example.org/index.php?code=1111'), 'Persönlicher Direktlink fehlt.');
    invitationTestAssert(true, str_contains($message['body'], 'Beispielverein'), 'Organisation fehlt in der Nachricht.');
    invitationTestAssert(true, str_starts_with($message['mailto'], 'mailto:offen%40example.org?'), 'Anbieterneutraler E-Mail-Link ist ungültig.');
    invitationTestAssert(false, str_contains($message['mailto'], 'zusage%40example.org'), 'Eine fremde E-Mail-Adresse ist in die Nachricht gelangt.');

    invitationValidateTemplate('Betreff', 'Link: {link}');
    $invalidTemplateRejected = false;
    try {
        invitationValidateTemplate('Betreff', 'Nachricht ohne persönlichen Zugang');
    } catch (InvalidArgumentException) {
        $invalidTemplateRejected = true;
    }
    invitationTestAssert(true, $invalidTemplateRejected, 'Vorlage ohne persönlichen Zugang wurde angenommen.');

    fwrite(STDOUT, "OK: Einladungen, Erinnerungen, Filter und persönliche Direktlinks funktionieren.\n");
} catch (Throwable $error) {
    invitationTestFail($error->getMessage());
}
