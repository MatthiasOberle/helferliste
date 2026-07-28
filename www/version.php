<?php

declare(strict_types=1);

const HELFERLISTE_VERSION = '1.1.0-dev';
const HELFERLISTE_SCHEMA_VERSION = 1;

function helferlisteRequireCurrentSchema(PDO $db): void
{
    $currentVersion = (int)$db->query('PRAGMA user_version')->fetchColumn();
    if ($currentVersion === HELFERLISTE_SCHEMA_VERSION) {
        return;
    }

    $message = $currentVersion < HELFERLISTE_SCHEMA_VERSION
        ? 'Die Datenbank muss vor der weiteren Nutzung aktualisiert werden.'
        : 'Die Datenbank ist neuer als diese Anwendungsversion.';

    if (PHP_SAPI === 'cli') {
        throw new RuntimeException($message);
    }

    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Helferliste wird aktualisiert</title></head><body style="font-family:system-ui,sans-serif;max-width:680px;margin:60px auto;padding:20px">';
    echo '<h1>Helferliste vorübergehend nicht verfügbar</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p>Bitte den Migrationslauf aus der Installationsanleitung ausführen. Vorhandene Daten werden dabei gesichert.</p></body></html>';
    exit;
}
