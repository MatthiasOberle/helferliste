<?php

declare(strict_types=1);

require __DIR__ . '/auth.php';

$db = new PDO('sqlite:' . __DIR__ . '/../data/helferliste.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/shift_helpers.php';
require_once __DIR__ . '/tracking.php';
$appConfig = appConfig($db);

// HTML-Ausgabe immer escapen, damit Benutzereingaben nicht als HTML ausgeführt werden.
function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function limitText(string $value, int $maxLength): string {
    $value = trim($value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }
    return substr($value, 0, $maxLength);
}

function sortUrl(string $key, string $currentSort, string $currentDir): string {
    $params = $_GET;
    $params['entry_sort'] = $key;
    $params['entry_dir'] = ($currentSort === $key && $currentDir === 'asc') ? 'desc' : 'asc';
    return '?' . http_build_query($params);
}

function renderSortHeader(string $key, string $label, string $currentSort, string $currentDir): void {
    $active = $currentSort === $key;
    $arrow = $active ? ($currentDir === 'asc' ? ' ▲' : ' ▼') : '';
    $class = $active ? 'sort-link active' : 'sort-link';
    echo '<a class="' . $class . '" href="' . h(sortUrl($key, $currentSort, $currentDir)) . '">' . h($label . $arrow) . '</a>';
}

function renderScrollText(string $value): void {
    $value = trim($value);
    if ($value === '') {
        echo '<span class="muted">Keine</span>';
        return;
    }

    echo '<div class="scroll-text">' . nl2br(h($value)) . '</div>';
}

// Zählt, wie viele Personen bereits einer bestimmten Schicht zugeordnet sind.
function countAssignments(PDO $db, string $linkTable, string $column, int $shiftId): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM {$linkTable} WHERE {$column} = ?");
    $stmt->execute([$shiftId]);
    return (int)$stmt->fetchColumn();
}

// Sammelt die Schichttitel eines Eintrags für die Admin-Tabelle.
function getEntryShiftTitles(PDO $db, int $entryId, string $linkTable, string $shiftTable, string $column): string {
    $stmt = $db->prepare("SELECT s.title
        FROM {$linkTable} es
        JOIN {$shiftTable} s ON s.id = es.{$column}
        WHERE es.entry_id = ?
        ORDER BY s.sort_order ASC, s.id ASC");
    $stmt->execute([$entryId]);
    $titles = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    return implode(', ', $titles);
}

function getEntryShiftBadges(PDO $db, int $entryId, string $linkTable, string $shiftTable, string $column, string $prefix): array {
    $stmt = $db->prepare("SELECT s.*, (
            SELECT COUNT(*)
            FROM {$shiftTable} s2
            WHERE s2.sort_order < s.sort_order
               OR (s2.sort_order = s.sort_order AND s2.id <= s.id)
        ) AS shift_number
        FROM {$linkTable} es
        JOIN {$shiftTable} s ON s.id = es.{$column}
        WHERE es.entry_id = ?
        ORDER BY s.sort_order ASC, s.id ASC");
    $stmt->execute([$entryId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(fn($row) => [
        'label' => $prefix . (int)$row['shift_number'],
        'title' => shiftDisplayLabel($row),
    ], $rows);
}

function renderShiftBadges(array $badges): void {
    if (empty($badges)) {
        echo '<span class="muted">Keine</span>';
        return;
    }

    echo '<div class="shift-badges">';
    foreach ($badges as $badge) {
        echo '<span class="shift-badge" title="' . h((string)$badge['title']) . '">' . h((string)$badge['label']) . '</span>';
    }
    echo '</div>';
}

function changeRequestStatusLabel(string $status): string {
    return match ($status) {
        'open' => 'offen',
        'done' => 'erledigt',
        'declined' => 'abgelehnt',
        default => $status,
    };
}

function getEntryChangeRequests(PDO $db, int $entryId): array {
    $stmt = $db->prepare("SELECT *
        FROM change_requests
        WHERE entry_id = ?
        ORDER BY requested_at DESC, id DESC");
    $stmt->execute([$entryId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// Legt die farbliche Auslastungsklasse für die Fortschrittsanzeige fest.
function fillClass(int $used, int $max): string {
    if ($max <= 0) {
        return 'full';
    }

    $ratio = $used / $max;

    if ($used >= $max) {
        return 'full';
    }

    if ($ratio >= 0.8) {
        return 'high';
    }

    if ($ratio >= 0.5) {
        return 'medium';
    }

    return 'low';
}

function fillPercent(int $used, int $max): int {
    if ($max <= 0) {
        return 0;
    }

    return max(0, min(100, (int)round(($used / $max) * 100)));
}

$message = '';
$error = '';
$showPasswordForm = isset($_GET['change_password']);

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Projekttexte und Kontaktdaten werden bewusst in settings.php gepflegt.

        // Passwortänderung im Adminbereich: altes Passwort prüfen, neues Passwort validieren, Hash speichern.
if ($action === 'change_admin_password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $repeatPassword = (string)($_POST['repeat_password'] ?? '');

        $currentPasswordHash = getAdminPasswordHash();
        if ($currentPasswordHash === null || !password_verify($currentPassword, $currentPasswordHash)) {
            $error = 'Das aktuelle Passwort stimmt nicht.';
            $showPasswordForm = true;
        } elseif (strlen($newPassword) < 12) {
            $error = 'Das neue Passwort muss mindestens 12 Zeichen lang sein.';
            $showPasswordForm = true;
        } elseif ($newPassword !== $repeatPassword) {
            $error = 'Die Wiederholung stimmt nicht mit dem neuen Passwort überein.';
            $showPasswordForm = true;
        } else {
            try {
                setAdminPassword($newPassword);
                $_SESSION['admin_auth_generation'] = getAdminAuthGeneration();
                logEvent($db, 'admin_password_changed', 'Admin-Passwort wurde geändert.');
                $message = 'Admin-Passwort wurde geändert.';
                $showPasswordForm = false;
            } catch (Throwable $e) {
                $error = 'Das Passwort konnte nicht geändert werden.';
                $showPasswordForm = true;
            }
        }
    }

        // Änderungswünsche werden nicht automatisch umgesetzt, sondern bewusst manuell abgehakt.
if ($action === 'handle_change_request') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $adminNote = limitText((string)($_POST['admin_note'] ?? ''), 500);

        if ($id <= 0 || !in_array($status, ['done', 'declined'], true)) {
            $error = 'Ungültiger Änderungswunsch.';
        } else {
            $stmt = $db->prepare("UPDATE change_requests SET status = ?, handled_at = datetime('now'), admin_note = ? WHERE id = ?");
            $stmt->execute([$status, $adminNote, $id]);
            logEvent($db, 'change_request_' . $status, 'Änderungswunsch #' . $id . ' wurde bearbeitet.');
            $message = 'Änderungswunsch wurde aktualisiert.';
        }
    }

        // Eintrag inklusive aller Schicht-Zuordnungen löschen, damit keine verwaisten Datensätze bleiben.
if ($action === 'delete_entry') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("DELETE FROM entry_shifts WHERE entry_id = ?");
                $stmt->execute([$id]);
                $stmt = $db->prepare("DELETE FROM entry_springer_shifts WHERE entry_id = ?");
                $stmt->execute([$id]);
                $stmt = $db->prepare("DELETE FROM entries WHERE id = ?");
                $stmt->execute([$id]);
                logEvent($db, 'entry_deleted', 'Eintrag #' . $id . ' wurde gelöscht.');
                $db->commit();
                $message = 'Eintrag wurde gelöscht.';
            } catch (Throwable $e) {
                $db->rollBack();
                $error = 'Eintrag konnte nicht gelöscht werden.';
            }
        }
    }
}

$normalShifts = $db->query("SELECT * FROM shifts WHERE active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$springerShifts = $db->query("SELECT * FROM springer_shifts WHERE active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$limit = limitForList('limit', 10);
$entrySort = (string)($_GET['entry_sort'] ?? 'created_at');
$entryDir = strtolower((string)($_GET['entry_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$entrySortOptions = [
    'created_at' => 'e.created_at',
    'name' => 'LOWER(e.name)',
    'status' => 'e.status',
    'code_email' => 'LOWER(COALESCE(ac.code, \'\') || \' \' || COALESCE(ac.email, \'\'))',
];
if (!array_key_exists($entrySort, $entrySortOptions)) {
    $entrySort = 'created_at';
}
$entryOrderBy = $entrySortOptions[$entrySort] . ' ' . strtoupper($entryDir) . ', e.id ' . strtoupper($entryDir);
$totalEntryRows = (int)$db->query("SELECT COUNT(*) FROM entries")->fetchColumn();
$totalChangeRequestRows = (int)$db->query("SELECT COUNT(*) FROM change_requests WHERE status = 'open'")->fetchColumn();

// Hauptliste: Rückmeldungen werden zusammen mit E-Mail und Code angezeigt.
$entriesStmt = $db->query("SELECT e.*, ac.email, ac.code
    FROM entries e
    LEFT JOIN access_codes ac ON ac.id = e.access_code_id
    ORDER BY {$entryOrderBy}
    LIMIT {$limit}");
$entries = $entriesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Oben stehen nur offene Änderungswünsche. Bearbeitete Wünsche erscheinen bei der jeweiligen Rückmeldung.
$changeRequestsStmt = $db->query("SELECT cr.*, ac.email, ac.code
    FROM change_requests cr
    LEFT JOIN access_codes ac ON ac.id = cr.access_code_id
    WHERE cr.status = 'open'
    ORDER BY cr.requested_at DESC, cr.id DESC
    LIMIT {$limit}");
$changeRequests = $changeRequestsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totalCodes = (int)$db->query("SELECT COUNT(*) FROM access_codes")->fetchColumn();
$totalEntries = (int)$db->query("SELECT COUNT(*) FROM entries")->fetchColumn();
$totalHelp = (int)$db->query("SELECT COUNT(*) FROM entries WHERE status = 'help'")->fetchColumn();
$totalNoTime = (int)$db->query("SELECT COUNT(*) FROM entries WHERE status = 'no_time'")->fetchColumn();
$openRequests = (int)$db->query("SELECT COUNT(*) FROM change_requests WHERE status = 'open'")->fetchColumn();

?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="<?= h(adminViewportContent()) ?>">
    <title>Admin - <?= h(appTitle($appConfig)) ?></title>
    <style>
        :root { --primary: #b00020; --border: #ddd; --bg: #f5f5f5; --good: #dff3e4; --medium: #fff1c7; --bad: #f8d7da; --bar-low: <?= h(appDesignColor($appConfig, 'occupancy_color_low')) ?>; --bar-medium: <?= h(appDesignColor($appConfig, 'occupancy_color_medium')) ?>; --bar-high: <?= h(appDesignColor($appConfig, 'occupancy_color_high')) ?>; --bar-full: <?= h(appDesignColor($appConfig, 'occupancy_color_full')) ?>; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: var(--bg); color: #222; line-height: 1.45; }
        .wrap { width: min(1680px, calc(100% - 28px)); margin: 0 auto; padding: 20px 0 40px; }
        .card { background: white; border: 1px solid var(--border); border-radius: 14px; padding: 16px; margin-bottom: 16px; box-shadow: 0 1px 8px rgba(0,0,0,.04); }
        h1, h2, h3 { margin-top: 0; }
        .buttons { display: flex; flex-wrap: wrap; gap: 10px; }
        .btn, button { display: inline-block; border: 0; border-radius: 10px; padding: 10px 13px; background: var(--primary); color: white; font-weight: bold; text-decoration: none; cursor: pointer; }
        .btn.secondary, button.secondary { background: #555; }
        .btn.danger, button.danger { background: #8b0000; }
        .notice { padding: 12px; border-radius: 10px; margin-bottom: 14px; }
        .success { background: var(--good); border: 1px solid #9ed3a8; }
        .error { background: var(--bad); border: 1px solid #e2a0a0; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; }
        .stat { background: #fafafa; border: 1px solid var(--border); border-radius: 12px; padding: 12px; }
        .stat strong { display: block; font-size: 1.7rem; }
        .shift-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 10px; }
        .shift { border-radius: 12px; padding: 12px; border: 1px solid var(--border); background: #fafafa; }
        .shift-top { display: flex; justify-content: space-between; gap: 10px; align-items: baseline; margin-bottom: 8px; }
        .shift-count { color: #666; font-size: .92rem; white-space: nowrap; }
        .load-bar { height: 12px; border-radius: 999px; background: #eceff3; overflow: hidden; border: 1px solid #dde2e7; }
        .load-bar span { display: block; height: 100%; border-radius: 999px; min-width: 2px; }
        .shift.low .load-bar span { background: var(--bar-low); }
        .shift.medium .load-bar span { background: var(--bar-medium); }
        .shift.high .load-bar span { background: var(--bar-high); }
        .shift.full .load-bar span { background: var(--bar-full); }
        .shift-badges { display: flex; flex-wrap: wrap; gap: 5px; min-width: 92px; }
        .shift-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 34px; height: 28px; padding: 0 8px; border-radius: 7px; border: 1px solid #cfd5dd; background: #f3f6f9; color: #1f2933; font-weight: 800; font-size: .86rem; line-height: 1; cursor: help; }
        .shift-badge:hover { border-color: var(--primary); background: #fff5f6; color: var(--primary); }
        table { width: 100%; border-collapse: collapse; }
        .entries-table { table-layout: fixed; min-width: 1380px; }
        .entries-table th:nth-child(1), .entries-table td:nth-child(1) { width: 145px; }
        .entries-table th:nth-child(2), .entries-table td:nth-child(2) { width: 150px; }
        .entries-table th:nth-child(3), .entries-table td:nth-child(3) { width: 95px; }
        .entries-table th:nth-child(4), .entries-table td:nth-child(4) { width: 120px; }
        .entries-table th:nth-child(5), .entries-table td:nth-child(5) { width: 105px; }
        .entries-table th:nth-child(6), .entries-table td:nth-child(6) { width: 250px; }
        .entries-table th:nth-child(7), .entries-table td:nth-child(7) { width: 170px; }
        .entries-table th:nth-child(8), .entries-table td:nth-child(8) { width: 215px; }
        .entries-table th:nth-child(9), .entries-table td:nth-child(9) { width: 130px; }
        th, td { padding: 9px 8px; border-bottom: 1px solid #e6e6e6; text-align: left; vertical-align: top; }
        th { background: #fafafa; }
        .table-wrap { overflow-x: auto; }
        .entries-wrap { overflow-x: visible; }
        .sort-link { color: #222; text-decoration: none; font-weight: 800; white-space: nowrap; }
        .sort-link:hover, .sort-link.active { color: var(--primary); text-decoration: underline; }
        .scroll-text { max-height: 6.2em; overflow-y: auto; white-space: normal; padding: 7px 8px; border: 1px solid #dde2e7; border-radius: 8px; background: #fafafa; }
        .action-cell { white-space: nowrap; }
        .action-cell .btn, .action-cell button { min-width: 96px; text-align: center; white-space: nowrap; }
        .muted { color: #666; font-size: .92rem; }
        textarea, input[type="text"], input[type="password"] { width: 100%; border: 1px solid #bbb; border-radius: 8px; padding: 8px; }
        .badge { display: inline-block; padding: 3px 7px; border-radius: 999px; font-size: .85rem; background: #eee; }
        .badge.open { background: #fff1c7; }
        .badge.done { background: #dff3e4; }
        .badge.declined { background: #f8d7da; }
        details.change-history { margin-top: 8px; }
        details.change-history summary { cursor: pointer; font-weight: bold; color: var(--primary); }
        .change-item { margin-top: 8px; padding: 8px; border: 1px solid var(--border); border-radius: 8px; background: #fafafa; }
        .change-item p { margin: 5px 0 0; }
        .admin-nav { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 12px; }
        .admin-nav a { display: inline-block; border-radius: 999px; padding: 9px 12px; background: #eee; color: #222; font-weight: bold; text-decoration: none; }
        .admin-nav a.active { background: var(--primary); color: white; }
        .admin-nav a.right { margin-left: auto; }
        .limit-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 10px; }
        .limit-form select { padding: 7px; border-radius: 8px; border: 1px solid #bbb; }
        button.compact { padding: 7px 10px; }
        .settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; }
        .field label { display: block; font-weight: bold; margin-bottom: 5px; }
        @media (max-width: 1420px) {
            .entries-wrap { overflow-x: visible; }
            .wrap { min-width: 1380px; }
        }
    </style>
</head>
<body class="<?= h(adminBodyClass()) ?>">
<div class="wrap">
    <div class="card">
        <h1>Adminbereich <?= h(appTitle($appConfig)) ?></h1>
        <?php if (appSubtitle($appConfig) !== ''): ?><p class="muted"><?= h(appSubtitle($appConfig)) ?></p><?php endif; ?>
        <p class="muted">Version <?= h(HELFERLISTE_VERSION) ?> · Datenbankschema <?= (int)$db->query('PRAGMA user_version')->fetchColumn() ?></p>
        <?php adminNav('admin'); ?>
    </div>

    <?php if ($message !== ''): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>


    <?php if ($showPasswordForm): ?>
        <div class="card">
            <h2>Admin-Passwort ändern</h2>
            <p class="muted">Das Passwort darf frei gewählt werden und muss mindestens 12 Zeichen lang sein.</p>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="change_admin_password">
                <label>Aktuelles Passwort</label>
                <input type="password" name="current_password" required>
                <label>Neues Passwort</label>
                <input type="password" name="new_password" minlength="12" autocomplete="new-password" required>
                <label>Neues Passwort wiederholen</label>
                <input type="password" name="repeat_password" minlength="12" autocomplete="new-password" required>
                <button type="submit">Passwort speichern</button>
            </form>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Schnellzugriff</h2>
        <p class="muted">Die wichtigsten Bereiche liegen jetzt gebündelt oben im Menü. Texte, Infotext, Impressum und Datenschutz findest du unter „Einstellungen“.</p>
        <div class="buttons">
            <a class="btn secondary" href="codes.php">Zugangscodes verwalten</a>
            <a class="btn secondary" href="edit_shifts.php">Schichten bearbeiten</a>
            <a class="btn secondary" href="export.php">Export öffnen</a>
            <a class="btn secondary" href="settings.php">Einstellungen öffnen</a>
        </div>
    </div>

    <div class="card">
        <h2>Übersicht</h2>
        <div class="stats">
            <div class="stat"><strong><?= $totalCodes ?></strong>Codes gesamt</div>
            <div class="stat"><strong><?= $totalEntries ?></strong>Rückmeldungen</div>
            <div class="stat"><strong><?= $totalHelp ?></strong>Zusagen</div>
            <div class="stat"><strong><?= $totalNoTime ?></strong>Absagen</div>
            <div class="stat"><strong><?= $openRequests ?></strong>offene Änderungswünsche</div>
        </div>
    </div>

    <div class="card">
        <h2>Normale Schichten</h2>
        <div class="shift-grid">
            <?php foreach ($normalShifts as $shift): ?>
                <?php
                    $used = countAssignments($db, 'entry_shifts', 'shift_id', (int)$shift['id']);
                    $max = (int)$shift['max_slots'];
                ?>
                <div class="shift <?= h(fillClass($used, $max)) ?>">
                    <div class="shift-top">
                        <strong><?= h((string)$shift['title']) ?></strong>
                        <?php if (shiftScheduleText($shift) !== ''): ?><span class="muted"><?= h(shiftScheduleText($shift)) ?></span><?php endif; ?>
                        <span class="shift-count"><?= $used ?> von <?= $max ?></span>
                    </div>
                    <div class="load-bar" title="<?= $used ?> von <?= $max ?> Plätzen belegt">
                        <span style="width: <?= fillPercent($used, $max) ?>%"></span>
                    </div>
                    <div class="muted">Plätze belegt</div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <h2>Springer-Schichten</h2>
        <div class="shift-grid">
            <?php foreach ($springerShifts as $shift): ?>
                <?php
                    $used = countAssignments($db, 'entry_springer_shifts', 'springer_shift_id', (int)$shift['id']);
                    $max = (int)$shift['max_slots'];
                ?>
                <div class="shift <?= h(fillClass($used, $max)) ?>">
                    <div class="shift-top">
                        <strong><?= h((string)$shift['title']) ?></strong>
                        <?php if (shiftScheduleText($shift) !== ''): ?><span class="muted"><?= h(shiftScheduleText($shift)) ?></span><?php endif; ?>
                        <span class="shift-count"><?= $used ?> von <?= $max ?></span>
                    </div>
                    <div class="load-bar" title="<?= $used ?> von <?= $max ?> Springer-Plätzen belegt">
                        <span style="width: <?= fillPercent($used, $max) ?>%"></span>
                    </div>
                    <div class="muted">Springer-Plätze belegt</div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <h2>Änderungswünsche</h2>
        <p class="muted">Anzeige: <?= count($changeRequests) ?> von <?= $totalChangeRequestRows ?> offenen Änderungswünschen.</p>
        <?php renderLimitSelect('limit', $limit); ?>
        <?php if (empty($changeRequests)): ?>
            <p class="muted">Keine offenen Änderungswünsche vorhanden.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Zeitpunkt</th>
                        <th>Name / Code</th>
                        <th>Wunsch</th>
                        <th>Status</th>
                        <th>Aktion</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($changeRequests as $request): ?>
                        <tr>
                            <td><?= h((string)$request['requested_at']) ?></td>
                            <td>
                                <strong><?= h((string)$request['name']) ?></strong><br>
                                <span class="muted"><?= h((string)($request['email'] ?? '')) ?><?= !empty($request['code']) ? ' · Code ' . h((string)$request['code']) : '' ?></span>
                            </td>
                            <td><?= nl2br(h((string)$request['message'])) ?></td>
                            <td><span class="badge <?= h((string)$request['status']) ?>"><?= h(changeRequestStatusLabel((string)$request['status'])) ?></span></td>
                            <td>
                                <?php if (($request['status'] ?? '') === 'open'): ?>
                                    <a class="btn" href="edit_entry.php?id=<?= (int)$request['entry_id'] ?>">Eintrag bearbeiten</a>
                                    <form method="post" style="margin-top:8px;">
                <?= csrfField() ?>
                                        <input type="hidden" name="action" value="handle_change_request">
                                        <input type="hidden" name="id" value="<?= (int)$request['id'] ?>">
                                        <input type="text" name="admin_note" maxlength="500" placeholder="interne Notiz optional">
                                        <button type="submit" name="status" value="done">Erledigt</button>
                                        <button class="secondary" type="submit" name="status" value="declined">Ablehnen</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted"><?= h((string)($request['handled_at'] ?? '')) ?></span><br>
                                    <span class="muted"><?= h((string)($request['admin_note'] ?? '')) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Rückmeldungen</h2>
        <p class="muted">Anzeige: <?= count($entries) ?> von <?= $totalEntryRows ?> Rückmeldungen.</p>
        <?php renderLimitSelect('limit', $limit); ?>
        <?php if (empty($entries)): ?>
            <p class="muted">Noch keine Rückmeldungen vorhanden.</p>
        <?php else: ?>
            <div class="table-wrap entries-wrap">
                <table class="entries-table">
                    <thead>
                    <tr>
                        <th><?php renderSortHeader('created_at', 'Zeitpunkt', $entrySort, $entryDir); ?></th>
                        <th><?php renderSortHeader('name', 'Name', $entrySort, $entryDir); ?></th>
                        <th><?php renderSortHeader('status', 'Status', $entrySort, $entryDir); ?></th>
                        <th>Normale Schichten</th>
                        <th>Springer</th>
                        <th>Hinweis</th>
                        <th><?php renderSortHeader('code_email', 'Code/E-Mail', $entrySort, $entryDir); ?></th>
                        <th>Änderungswünsche</th>
                        <th>Aktion</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($entries as $entry): ?>
                        <?php
                            $entryId = (int)$entry['id'];
                            $entryChangeRequests = getEntryChangeRequests($db, $entryId);
                            $normalShiftBadges = getEntryShiftBadges($db, $entryId, 'entry_shifts', 'shifts', 'shift_id', 'N');
                            $springerShiftBadges = getEntryShiftBadges($db, $entryId, 'entry_springer_shifts', 'springer_shifts', 'springer_shift_id', 'S');
                        ?>
                        <tr>
                            <td><?= h((string)$entry['created_at']) ?></td>
                            <td><?= h((string)$entry['name']) ?></td>
                            <td><?= $entry['status'] === 'help' ? 'Hilft' : 'Keine Zeit' ?></td>
                            <td><?php renderShiftBadges($normalShiftBadges); ?></td>
                            <td><?php renderShiftBadges($springerShiftBadges); ?></td>
                            <td><?php renderScrollText((string)$entry['note']); ?></td>
                            <td>
                                <?= !empty($entry['code']) ? 'Code ' . h((string)$entry['code']) . '<br>' : '' ?>
                                <span class="muted"><?= h((string)($entry['email'] ?? '')) ?></span>
                            </td>
                            <td>
                                <?php if (empty($entryChangeRequests)): ?>
                                    <span class="muted">Keine</span>
                                <?php else: ?>
                                    <details class="change-history">
                                        <summary><?= count($entryChangeRequests) ?> Änderungswunsch<?= count($entryChangeRequests) === 1 ? '' : 'e' ?></summary>
                                        <?php foreach ($entryChangeRequests as $request): ?>
                                            <div class="change-item">
                                                <span class="badge <?= h((string)$request['status']) ?>"><?= h(changeRequestStatusLabel((string)$request['status'])) ?></span>
                                                <span class="muted"><?= h((string)($request['requested_at'] ?? '')) ?></span>
                                                <p><?= nl2br(h((string)$request['message'])) ?></p>
                                                <?php if (!empty($request['handled_at']) || !empty($request['admin_note'])): ?>
                                                    <p class="muted">
                                                        <?= !empty($request['handled_at']) ? 'Bearbeitet: ' . h((string)$request['handled_at']) : '' ?>
                                                        <?= !empty($request['admin_note']) ? '<br>Vermerk: ' . h((string)$request['admin_note']) : '' ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td class="action-cell">
                                <a class="btn" href="edit_entry.php?id=<?= (int)$entry['id'] ?>">Bearbeiten</a>
                                <form method="post" onsubmit="return confirm('Diesen Eintrag wirklich löschen?');" style="margin-top:8px;">
                <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_entry">
                                    <input type="hidden" name="id" value="<?= (int)$entry['id'] ?>">
                                    <button class="danger" type="submit">Löschen</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
