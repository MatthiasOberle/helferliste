<?php

declare(strict_types=1);

require __DIR__ . '/auth.php';

$db = new PDO('sqlite:' . __DIR__ . '/../data/helferliste.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/shift_helpers.php';
$appConfig = appConfig($db);

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Tabs und Zeilenumbrüche entfernen, damit die TSV-Spalten stabil bleiben.
function cleanCell(string $value): string {
    $value = str_replace(["\t", "\r", "\n"], ' ', $value);
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

    // Schützt Excel/LibreOffice vor Formelausführung aus Benutzereingaben.
    if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
        $value = "'" . $value;
    }

    return $value;
}

// Schichttitel werden als kommagetrennte Liste in eine Exportzelle geschrieben.
function getEntryShiftTitles(PDO $db, int $entryId, string $linkTable, string $shiftTable, string $column): string {
    $stmt = $db->prepare("SELECT s.*
        FROM {$linkTable} es
        JOIN {$shiftTable} s ON s.id = es.{$column}
        WHERE es.entry_id = ?
        ORDER BY s.sort_order ASC, s.id ASC");
    $stmt->execute([$entryId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return implode(', ', array_map('shiftDisplayLabel', $rows));
}

function changeRequestStatusLabel(string $status): string {
    return match ($status) {
        'open' => 'offen',
        'done' => 'erledigt',
        'declined' => 'abgelehnt',
        default => $status,
    };
}

function getEntryChangeRequestSummary(PDO $db, int $entryId): string {
    $stmt = $db->prepare("SELECT requested_at, message, status, handled_at, admin_note
        FROM change_requests
        WHERE entry_id = ?
        ORDER BY requested_at ASC, id ASC");
    $stmt->execute([$entryId]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $parts = [];

    foreach ($requests as $request) {
        $part = '[' . changeRequestStatusLabel((string)($request['status'] ?? '')) . '] '
            . (string)($request['requested_at'] ?? '') . ': '
            . (string)($request['message'] ?? '');

        if (!empty($request['handled_at'])) {
            $part .= ' | Bearbeitet: ' . (string)$request['handled_at'];
        }

        if (!empty($request['admin_note'])) {
            $part .= ' | Vermerk: ' . (string)$request['admin_note'];
        }

        $parts[] = $part;
    }

    return implode(' || ', $parts);
}

// Einfache Trennung am ersten Leerzeichen: reicht für diese Helferliste aus.
function splitName(string $name): array {
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

    if ($name === '') {
        return ['', ''];
    }

    $parts = explode(' ', $name);

    if (count($parts) === 1) {
        return [$parts[0], ''];
    }

    $firstName = array_shift($parts);
    $lastName = implode(' ', $parts);

    return [$firstName, $lastName];
}

// Excel mag diesen Export zuverlässig als UTF-16LE mit BOM.
function outputRow(array $cells): void {
    $line = implode("\t", array_map(fn($cell) => cleanCell((string)$cell), $cells)) . "\r\n";
    echo iconv('UTF-8', 'UTF-16LE//IGNORE', $line);
}

function startTsvDownload(string $filename): void {
    header('Content-Type: text/tab-separated-values; charset=UTF-16LE');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "\xFF\xFE";
}

function downloadEntriesExport(PDO $db): void {
    startTsvDownload('helferliste.tsv');

    outputRow(['Zeitpunkt', 'E-Mail', 'Code', 'Name', 'Status', 'Normale Schichten', 'Springer-Schichten', 'Hinweis', 'Änderungswünsche']);

    $stmt = $db->query("SELECT e.*, ac.email, ac.code
        FROM entries e
        LEFT JOIN access_codes ac ON ac.id = e.access_code_id
        ORDER BY e.created_at ASC, e.id ASC");
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($entries as $entry) {
        outputRow([
            $entry['created_at'] ?? '',
            $entry['email'] ?? '',
            $entry['code'] ?? '',
            $entry['name'] ?? '',
            ($entry['status'] ?? '') === 'help' ? 'Hilft' : 'Keine Zeit',
            getEntryShiftTitles($db, (int)$entry['id'], 'entry_shifts', 'shifts', 'shift_id'),
            getEntryShiftTitles($db, (int)$entry['id'], 'entry_springer_shifts', 'springer_shifts', 'springer_shift_id'),
            $entry['note'] ?? '',
            getEntryChangeRequestSummary($db, (int)$entry['id']),
        ]);
    }
}

function downloadNotesExport(PDO $db): void {
    startTsvDownload('rueckmeldungen_hinweise.tsv');

    outputRow(['Vorname', 'Nachname', 'Wunsch / Hinweis']);

    $stmt = $db->query("SELECT name, note
        FROM entries
        WHERE TRIM(COALESCE(note, '')) != ''
        ORDER BY name COLLATE NOCASE ASC, id ASC");
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($entries as $entry) {
        [$firstName, $lastName] = splitName((string)($entry['name'] ?? ''));

        outputRow([
            $firstName,
            $lastName,
            $entry['note'] ?? '',
        ]);
    }
}

$download = (string)($_GET['download'] ?? '');

if ($download === 'entries') {
    downloadEntriesExport($db);
    exit;
}

if ($download === 'notes') {
    downloadNotesExport($db);
    exit;
}

$totalEntries = (int)$db->query("SELECT COUNT(*) FROM entries")->fetchColumn();
$entriesWithNotes = (int)$db->query("SELECT COUNT(*) FROM entries WHERE TRIM(COALESCE(note, '')) != ''")->fetchColumn();
$totalCodes = (int)$db->query("SELECT COUNT(*) FROM access_codes")->fetchColumn();

?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="<?= h(adminViewportContent()) ?>">
    <title>Export - <?= h(appTitle($appConfig)) ?></title>
    <style>
        :root { --primary: #b00020; --border: #ddd; --bg: #f5f5f5; --good: #dff3e4; --bad: #f8d7da; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: var(--bg); color: #222; line-height: 1.45; }
        .wrap { width: min(1680px, calc(100% - 28px)); margin: 0 auto; padding: 20px 0 40px; }
        .card { background: white; border: 1px solid var(--border); border-radius: 14px; padding: 16px; margin-bottom: 16px; box-shadow: 0 1px 8px rgba(0,0,0,.04); }
        h1, h2, h3 { margin-top: 0; }
        .btn, button { display: inline-block; border: 0; border-radius: 10px; padding: 10px 13px; background: var(--primary); color: white; font-weight: bold; text-decoration: none; cursor: pointer; }
        .btn.secondary, button.secondary { background: #555; }
        .muted { color: #666; font-size: .92rem; }
        .export-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px; }
        .export-box { border: 1px solid var(--border); border-radius: 14px; padding: 16px; background: #fafafa; }
        .export-box h2 { margin-bottom: 6px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin-top: 10px; }
        .stat { background: #fafafa; border: 1px solid var(--border); border-radius: 12px; padding: 12px; }
        .stat strong { display: block; font-size: 1.7rem; }
        .button-row { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
        @media (max-width: 720px) { .button-row .btn { width: 100%; text-align: center; } }
    </style>
</head>
<body class="<?= h(adminBodyClass()) ?>">
<div class="wrap">
    <div class="card">
        <h1>Export</h1>
        <?php if (appSubtitle($appConfig) !== ''): ?><p class="muted"><?= h(appSubtitle($appConfig)) ?></p><?php endif; ?>
        <?php adminNav('export'); ?>
    </div>

    <div class="card">
        <h2>Exportübersicht</h2>
        <p class="muted">Alle Exporte liegen jetzt gesammelt auf dieser Seite. Im Admin-Menü gibt es dadurch nur noch einen Export-Eintrag.</p>
        <div class="stats">
            <div class="stat"><strong><?= $totalCodes ?></strong>Codes gesamt</div>
            <div class="stat"><strong><?= $totalEntries ?></strong>Rückmeldungen</div>
            <div class="stat"><strong><?= $entriesWithNotes ?></strong>Rückmeldungen mit Hinweis</div>
        </div>
    </div>

    <div class="export-grid">
        <div class="export-box">
            <h2>Helferliste komplett</h2>
            <p class="muted">Exportiert Zeitpunkt, E-Mail, Code, Name, Status, Schichten, Hinweise und Änderungswünsche.</p>
            <div class="button-row">
                <a class="btn" href="export.php?download=entries">Helferliste herunterladen</a>
            </div>
        </div>

        <div class="export-box">
            <h2>Hinweise / Wünsche</h2>
            <p class="muted">Exportiert nur Vorname, Nachname und Wunsch beziehungsweise Hinweis. Praktisch für separate Besprechungen.</p>
            <div class="button-row">
                <a class="btn secondary" href="export.php?download=notes">Hinweise herunterladen</a>
            </div>
        </div>

        <div class="export-box">
            <h2>Drucklisten</h2>
            <p class="muted">Erstellt einen kompakten Schichtplan oder getrennte Anwesenheitslisten ohne E-Mail-Adressen, Codes und private Hinweise.</p>
            <div class="button-row">
                <a class="btn secondary" href="print_lists.php">Drucklisten öffnen</a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
