<?php

declare(strict_types=1);

function contactImportTestFail(string $message): never
{
    fwrite(STDERR, "FEHLER: {$message}\n");
    exit(1);
}

function contactImportTestAssert(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        contactImportTestFail($message . ' Erwartet: ' . var_export($expected, true) . ', erhalten: ' . var_export($actual, true));
    }
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/www/contact_import_lib.php';

try {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $schema = file_get_contents($projectRoot . '/Install/database_schema.sql');
    if ($schema === false) {
        contactImportTestFail('Datenbankschema konnte nicht gelesen werden.');
    }
    $db->exec($schema);
    $db->exec("INSERT INTO access_codes (email, code, created_at) VALUES ('vorhanden@example.org', '1111', datetime('now'))");

    $semicolonCsv = "\xEF\xBB\xBFE-Mail;Name;Gruppe\r\n"
        . "NEU@example.org;Erika Beispiel;Aufbau\r\n"
        . "vorhanden@example.org;Schon da;Küche\r\n"
        . "neu@example.org;Doppelt;Küche\r\n"
        . "keine-adresse;Ungültig;Küche\r\n"
        . ";Leer;Küche\r\n";
    $preview = contactImportParse($semicolonCsv, $db, 'kontakte.csv');

    contactImportTestAssert('Semikolon', $preview['delimiter'], 'Semikolon wurde nicht erkannt.');
    contactImportTestAssert(['Name', 'Gruppe'], $preview['ignored_headers'], 'Zusätzliche Spalten wurden nicht korrekt ausgewiesen.');
    contactImportTestAssert(5, $preview['counts']['total'], 'Datenzeilen wurden nicht vollständig gezählt.');
    contactImportTestAssert(1, $preview['counts']['ready'], 'Neue Adresse wurde nicht erkannt.');
    contactImportTestAssert(1, $preview['counts']['existing'], 'Vorhandene Adresse wurde nicht erkannt.');
    contactImportTestAssert(1, $preview['counts']['duplicate'], 'Doppelte Adresse wurde nicht erkannt.');
    contactImportTestAssert(1, $preview['counts']['invalid'], 'Ungültige Adresse wurde nicht erkannt.');
    contactImportTestAssert(1, $preview['counts']['empty'], 'Leere Adresse wurde nicht erkannt.');
    contactImportTestAssert(['neu@example.org'], $preview['ready_emails'], 'E-Mail-Adresse wurde nicht normalisiert.');

    $commaCsv = "email,Notiz\n\"quoted@example.org\",\"Hinweis, mit Komma\"\n";
    $commaPreview = contactImportParse($commaCsv, $db, 'komma.csv');
    contactImportTestAssert('Komma', $commaPreview['delimiter'], 'Komma wurde nicht erkannt.');
    contactImportTestAssert(['quoted@example.org'], $commaPreview['ready_emails'], 'Zitierte CSV-Zeile wurde nicht gelesen.');

    $excelCsv = "sep=;\nE-Mail;Organisation\nexcel@example.org;Beispielverein\n";
    $excelPreview = contactImportParse($excelCsv, $db, 'excel.csv');
    contactImportTestAssert(['excel@example.org'], $excelPreview['ready_emails'], 'Excel-Trennzeichenhinweis wurde nicht verarbeitet.');

    $tabCsv = "Mail\tBereich\ntab@example.org\tAufbau\n";
    $tabPreview = contactImportParse($tabCsv, $db, 'tab.csv');
    contactImportTestAssert('Tabulator', $tabPreview['delimiter'], 'Tabulator wurde nicht erkannt.');
    contactImportTestAssert(['tab@example.org'], $tabPreview['ready_emails'], 'Tabulatorgetrennte Datei wurde nicht gelesen.');

    $windowsCsv = "E-Mail;Notiz\r\nwindows@example.org;Gr\xFC\xDFe\r\n";
    $windowsPreview = contactImportParse($windowsCsv, $db, 'windows.csv');
    contactImportTestAssert(['windows@example.org'], $windowsPreview['ready_emails'], 'Windows-1252-Datei wurde nicht gelesen.');

    $missingHeaderRejected = false;
    try {
        contactImportParse("Name;Telefon\nErika;01234\n", $db, 'ohne-email.csv');
    } catch (InvalidArgumentException) {
        $missingHeaderRejected = true;
    }
    contactImportTestAssert(true, $missingHeaderRejected, 'Eine Datei ohne E-Mail-Spalte wurde angenommen.');

    $utf16Rejected = false;
    try {
        contactImportParse("E\0-\0M\0a\0i\0l\0", $db, 'utf16.csv');
    } catch (InvalidArgumentException) {
        $utf16Rejected = true;
    }
    contactImportTestAssert(true, $utf16Rejected, 'Eine nicht unterstützte UTF-16-Datei wurde angenommen.');

    $result = createAccessCodes($db, [
        'neu@example.org',
        'zweite@example.org',
        'VORHANDEN@example.org',
        'keine-adresse',
    ]);
    contactImportTestAssert(2, count($result['created']), 'Neue Kontakte wurden nicht vollständig importiert.');
    contactImportTestAssert(2, count($result['skipped']), 'Vorhandene und ungültige Kontakte wurden nicht übersprungen.');
    contactImportTestAssert(3, (int)$db->query('SELECT COUNT(*) FROM access_codes')->fetchColumn(), 'Die Datenbank enthält nach dem Import eine falsche Kontaktzahl.');
    contactImportTestAssert(3, (int)$db->query('SELECT COUNT(DISTINCT code) FROM access_codes')->fetchColumn(), 'Zugangscodes sind nicht eindeutig.');
    contactImportTestAssert(3, (int)$db->query("SELECT COUNT(*) FROM access_codes WHERE code GLOB '[1-9][0-9][0-9][0-9]'")->fetchColumn(), 'Zugangscodes sind nicht vierstellig.');

    fwrite(STDOUT, "OK: CSV-Vorschau, Prüfung, Dublettenbehandlung und Kontaktimport funktionieren.\n");
} catch (Throwable $error) {
    contactImportTestFail($error->getMessage());
}
