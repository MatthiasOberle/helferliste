<?php

declare(strict_types=1);

require __DIR__ . '/auth.php';

$db = new PDO('sqlite:' . __DIR__ . '/../data/helferliste.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/print_lists_lib.php';

$appConfig = appConfig($db);
$view = (string)($_GET['view'] ?? 'overview');
if (!in_array($view, ['overview', 'schedule', 'attendance'], true)) {
    $view = 'overview';
}

$printData = printListData(
    $db,
    appText($appConfig, 'text_normal_shifts_heading'),
    appText($appConfig, 'text_flexible_shifts_heading')
);
$eventName = trim((string)$appConfig['event_name']);
$eventOrganizer = trim((string)$appConfig['event_organizer']);
$eventDateRange = appEventDateRange($appConfig);
$createdAt = (new DateTimeImmutable('now'))->format('d.m.Y, H:i');

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function printListCountLabel(int $count, string $singular, string $plural): string
{
    return $count . ' ' . ($count === 1 ? $singular : $plural);
}

function printListDocumentTitle(string $view, string $eventName): string
{
    $prefix = $view === 'attendance' ? 'Anwesenheitslisten' : 'Schichtplan';
    return $prefix . ($eventName !== '' ? ' – ' . $eventName : '');
}

?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="<?= h(adminViewportContent()) ?>">
    <title><?= h($view === 'overview' ? 'Drucklisten – ' . appTitle($appConfig) : printListDocumentTitle($view, $eventName)) ?></title>
    <style>
        :root { --primary: <?= h(appDesignColor($appConfig, 'primary_color')) ?>; --border: #d7dce2; --muted: #5f6b78; --bg: #f5f6f8; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #1f2933; background: var(--bg); line-height: 1.42; }
        .wrap { width: min(1180px, calc(100% - 28px)); margin: 0 auto; padding: 20px 0 44px; }
        .card, .sheet { background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 18px; margin-bottom: 16px; box-shadow: 0 2px 10px rgba(15, 23, 42, .05); }
        h1, h2, h3 { margin-top: 0; }
        h1 { margin-bottom: 8px; }
        h2 { margin-bottom: 10px; }
        p { margin-top: 0; }
        .muted { color: var(--muted); }
        .notice { padding: 13px 15px; border: 1px solid #e5c46b; border-radius: 12px; background: #fff8dd; }
        .choice-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(270px, 1fr)); gap: 14px; }
        .choice { border: 1px solid var(--border); border-radius: 14px; padding: 18px; background: #fbfcfd; }
        .choice h2 { font-size: 1.25rem; }
        .btn, button { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; border: 0; border-radius: 10px; padding: 10px 15px; background: var(--primary); color: #fff; font: inherit; font-weight: 800; text-decoration: none; cursor: pointer; }
        .btn.secondary, button.secondary { background: #4b5563; }
        .button-row { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
        .document-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 22px; padding-bottom: 14px; margin-bottom: 18px; border-bottom: 2px solid #1f2933; }
        .document-header p { margin-bottom: 3px; }
        .document-meta { text-align: right; color: var(--muted); font-size: .9rem; }
        .summary { display: flex; flex-wrap: wrap; gap: 10px 22px; margin: 0 0 18px; padding: 11px 13px; border: 1px solid var(--border); border-radius: 10px; background: #f8fafc; }
        .group { margin-top: 24px; }
        .group > h2 { padding-bottom: 6px; border-bottom: 1px solid #aeb7c2; }
        .shift-block { padding: 13px 0; border-bottom: 1px solid #e4e7eb; break-inside: avoid; page-break-inside: avoid; }
        .shift-block:last-child { border-bottom: 0; }
        .shift-heading { display: flex; justify-content: space-between; gap: 18px; align-items: baseline; }
        .shift-heading h3 { margin-bottom: 4px; }
        .occupancy { white-space: nowrap; font-weight: 800; }
        .shift-details, .shift-note { margin-bottom: 6px; color: var(--muted); }
        .helper-list { margin: 8px 0 0; padding-left: 22px; columns: 2 250px; column-gap: 36px; }
        .helper-list li { padding: 2px 0; break-inside: avoid; }
        .empty { color: var(--muted); font-style: italic; }
        .unassigned { margin-top: 24px; padding: 13px; border: 2px solid #9b1c1c; border-radius: 10px; break-inside: avoid; }
        .unassigned h2 { font-size: 1.12rem; margin-bottom: 6px; }
        .attendance-sheet { min-height: 250mm; break-after: page; page-break-after: always; }
        .attendance-sheet:last-child { break-after: auto; page-break-after: auto; }
        .attendance-title { margin-bottom: 4px; }
        .attendance-subtitle { margin-bottom: 4px; color: var(--muted); }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        thead { display: table-header-group; }
        th, td { border: 1px solid #9aa4af; padding: 8px 7px; text-align: left; vertical-align: middle; }
        th { background: #eef1f4; font-size: .9rem; }
        td { height: 38px; }
        .col-number { width: 42px; text-align: center; }
        .col-present { width: 78px; text-align: center; }
        .col-time { width: 84px; }
        .col-note { width: 28%; }
        .check-box { display: inline-block; width: 18px; height: 18px; border: 1.8px solid #111; vertical-align: middle; }
        .blank-name { display: inline-block; width: 100%; min-height: 1em; border-bottom: 1px dotted #777; }
        @media (max-width: 720px) {
            .wrap { width: calc(100% - 16px); padding-top: 8px; }
            .card, .sheet { padding: 14px; border-radius: 12px; }
            .button-row { display: grid; grid-template-columns: 1fr; }
            .button-row .btn, .button-row button { width: 100%; }
            .document-header, .shift-heading { display: block; }
            .document-meta { text-align: left; margin-top: 9px; }
            .helper-list { columns: 1; }
            .table-wrap { overflow-x: auto; }
            table { min-width: 690px; }
        }
        @page { size: A4 portrait; margin: 12mm; }
        @media print {
            body { background: #fff; color: #000; font-size: 10.5pt; }
            .no-print { display: none !important; }
            .wrap, body.admin-mobile .wrap { width: 100% !important; margin: 0 !important; padding: 0 !important; }
            .sheet { border: 0; border-radius: 0; padding: 0; margin: 0; box-shadow: none; }
            .document-header { margin-bottom: 12px; padding-bottom: 9px; }
            .summary { background: #fff; }
            .group { margin-top: 16px; }
            .shift-block { padding: 9px 0; }
            .attendance-sheet { min-height: 0; }
            a { color: inherit; text-decoration: none; }
            th { background: #eee !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            body.admin-mobile table { display: table !important; width: 100% !important; min-width: 0 !important; border-collapse: collapse !important; }
            body.admin-mobile thead { display: table-header-group !important; }
            body.admin-mobile tbody { display: table-row-group !important; }
            body.admin-mobile tr { display: table-row !important; width: auto !important; margin: 0 !important; padding: 0 !important; border: 0 !important; border-radius: 0 !important; box-shadow: none !important; }
            body.admin-mobile th, body.admin-mobile td { display: table-cell !important; width: auto !important; padding: 8px 7px !important; border: 1px solid #9aa4af !important; }
            body.admin-mobile td::before { display: none !important; content: none !important; }
            body.admin-mobile .col-number { width: 42px !important; text-align: center !important; }
            body.admin-mobile .col-present { width: 78px !important; text-align: center !important; }
            body.admin-mobile .col-time { width: 84px !important; }
            body.admin-mobile .col-note { width: 28% !important; }
        }
    </style>
</head>
<body class="<?= h(adminBodyClass()) ?>">
<div class="wrap">
    <section class="card no-print">
        <h1>Drucklisten</h1>
        <?php adminNav('print'); ?>
    </section>

    <?php if ($view === 'overview'): ?>
        <section class="card">
            <h2>Was soll gedruckt werden?</h2>
            <p class="muted">Beide Ansichten verwenden die aktuelle Veranstaltung und ausschließlich aktive Schichten.</p>
            <div class="choice-grid">
                <article class="choice">
                    <h2>Schichtplan</h2>
                    <p>Zeigt alle aktiven Schichten mit Zeiten, Orten, Kapazitäten und den eingeteilten Namen in einer kompakten Übersicht.</p>
                    <a class="btn" href="print_lists.php?view=schedule">Schichtplan öffnen</a>
                </article>
                <article class="choice">
                    <h2>Anwesenheitslisten</h2>
                    <p>Erstellt je Schicht eine eigene Seite mit Namen sowie leeren Feldern für Anwesenheit, Beginn, Ende und Bemerkung.</p>
                    <a class="btn" href="print_lists.php?view=attendance">Anwesenheitslisten öffnen</a>
                </article>
            </div>
        </section>
        <aside class="notice">
            <strong>Personenbezogene Ausdrucke:</strong> Die Listen enthalten Namen. Ausdrucke nur berechtigten Personen geben und nach dem festgelegten Zweck sicher aufbewahren oder vernichten.
        </aside>
    <?php else: ?>
        <section class="card no-print">
            <div class="button-row">
                <button type="button" onclick="window.print()">Jetzt drucken</button>
                <a class="btn secondary" href="print_lists.php">Andere Druckliste wählen</a>
            </div>
            <p class="muted" style="margin:12px 0 0">In der Druckvorschau kann auch als PDF gespeichert werden. E-Mail-Adressen, Zugangscodes und private Hinweise sind nicht enthalten.</p>
        </section>

        <?php if ($view === 'schedule'): ?>
            <main class="sheet" aria-labelledby="document-title">
                <header class="document-header">
                    <div>
                        <h1 id="document-title">Schichtplan</h1>
                        <p><strong><?= h($eventName !== '' ? $eventName : appTitle($appConfig)) ?></strong></p>
                        <?php if ($eventOrganizer !== ''): ?><p><?= h($eventOrganizer) ?></p><?php endif; ?>
                        <?php if ($eventDateRange !== ''): ?><p><?= h($eventDateRange) ?></p><?php endif; ?>
                    </div>
                    <div class="document-meta">Erstellt am <?= h($createdAt) ?></div>
                </header>

                <div class="summary">
                    <span><strong><?= (int)$printData['shift_count'] ?></strong> aktive Schichten</span>
                    <span><strong><?= (int)$printData['assignment_count'] ?></strong> Einteilungen</span>
                </div>

                <?php if ((int)$printData['shift_count'] === 0): ?>
                    <p class="empty">Es sind keine aktiven Schichten vorhanden.</p>
                <?php endif; ?>

                <?php foreach ($printData['groups'] as $group): ?>
                    <?php if ($group['shifts'] === []) { continue; } ?>
                    <section class="group" aria-labelledby="group-<?= h($group['key']) ?>">
                        <h2 id="group-<?= h($group['key']) ?>"><?= h($group['label']) ?></h2>
                        <?php foreach ($group['shifts'] as $shift): ?>
                            <article class="shift-block">
                                <div class="shift-heading">
                                    <h3><?= h((string)$shift['title']) ?></h3>
                                    <span class="occupancy"><?= (int)$shift['assigned_count'] ?> / <?= (int)$shift['max_slots'] ?> eingeteilt</span>
                                </div>
                                <?php if ($shift['schedule_text'] !== ''): ?><p class="shift-details"><?= h((string)$shift['schedule_text']) ?></p><?php endif; ?>
                                <?php if (trim((string)$shift['note']) !== ''): ?><p class="shift-note">Hinweis: <?= h(trim((string)$shift['note'])) ?></p><?php endif; ?>
                                <?php if ($shift['helpers'] === []): ?>
                                    <p class="empty">Noch niemand eingeteilt.</p>
                                <?php else: ?>
                                    <ol class="helper-list">
                                        <?php foreach ($shift['helpers'] as $helper): ?><li><?= h($helper['name']) ?></li><?php endforeach; ?>
                                    </ol>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>

                <?php if ($printData['unassigned_helpers'] !== []): ?>
                    <aside class="unassigned">
                        <h2>Zugesagt, aber keiner aktiven Schicht zugeordnet</h2>
                        <p class="muted">Diese Einträge sollten vor dem Einsatz geprüft werden.</p>
                        <ul>
                            <?php foreach ($printData['unassigned_helpers'] as $helper): ?><li><?= h($helper['name']) ?></li><?php endforeach; ?>
                        </ul>
                    </aside>
                <?php endif; ?>
            </main>
        <?php else: ?>
            <main aria-label="Anwesenheitslisten">
                <?php if ((int)$printData['shift_count'] === 0): ?>
                    <section class="sheet">
                        <h1>Anwesenheitslisten</h1>
                        <p class="empty">Es sind keine aktiven Schichten vorhanden.</p>
                    </section>
                <?php endif; ?>

                <?php foreach ($printData['groups'] as $group): ?>
                    <?php foreach ($group['shifts'] as $shift): ?>
                        <section class="sheet attendance-sheet">
                            <header class="document-header">
                                <div>
                                    <p class="muted"><?= h($group['label']) ?></p>
                                    <h1 class="attendance-title"><?= h((string)$shift['title']) ?></h1>
                                    <p class="attendance-subtitle"><strong><?= h($eventName !== '' ? $eventName : appTitle($appConfig)) ?></strong><?= $eventDateRange !== '' ? ' · ' . h($eventDateRange) : '' ?></p>
                                    <?php if ($shift['schedule_text'] !== ''): ?><p class="attendance-subtitle"><?= h((string)$shift['schedule_text']) ?></p><?php endif; ?>
                                </div>
                                <div class="document-meta">
                                    <?= h(printListCountLabel((int)$shift['assigned_count'], 'Person eingeteilt', 'Personen eingeteilt')) ?><br>
                                    Kapazität: <?= (int)$shift['max_slots'] ?>
                                </div>
                            </header>

                            <div class="table-wrap">
                                <table>
                                    <thead>
                                    <tr>
                                        <th class="col-number">Nr.</th>
                                        <th>Name</th>
                                        <th class="col-present">Anwesend</th>
                                        <th class="col-time">Beginn</th>
                                        <th class="col-time">Ende</th>
                                        <th class="col-note">Bemerkung</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php $rowNumber = 0; ?>
                                    <?php foreach ($shift['helpers'] as $helper): ?>
                                        <?php $rowNumber++; ?>
                                        <tr>
                                            <td class="col-number"><?= $rowNumber ?></td>
                                            <td><?= h($helper['name']) ?></td>
                                            <td class="col-present"><span class="check-box" aria-label="Anwesenheit auf Papier markieren"></span></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php for ($blank = 0; $blank < (int)$shift['attendance_blank_rows']; $blank++): ?>
                                        <?php $rowNumber++; ?>
                                        <tr>
                                            <td class="col-number"><?= $rowNumber ?></td>
                                            <td><span class="blank-name" aria-hidden="true"></span></td>
                                            <td class="col-present"><span class="check-box" aria-hidden="true"></span></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                        </tr>
                                    <?php endfor; ?>
                                    <?php if ($rowNumber === 0): ?>
                                        <tr><td colspan="6" class="empty">Noch niemand eingeteilt.</td></tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="muted" style="margin-top:12px">Ausdruck erstellt am <?= h($createdAt) ?>. Nach dem Einsatz entsprechend der festgelegten Aufbewahrung sicher verwahren oder vernichten.</p>
                        </section>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </main>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
