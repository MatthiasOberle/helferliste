<?php

declare(strict_types=1);

require __DIR__ . '/auth.php';

$databaseFile = __DIR__ . '/../data/helferliste.sqlite';
$db = new PDO('sqlite:' . $databaseFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec('PRAGMA busy_timeout = 5000');

require_once __DIR__ . '/event_lifecycle.php';
require_once __DIR__ . '/tracking.php';
$appConfig = appConfig($db);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function archiveTsvCell(mixed $value): string
{
    $value = str_replace(["\r", "\n", "\t"], ' ', trim((string)$value));
    if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
        $value = "'" . $value;
    }
    return $value;
}

$downloadId = (int)($_GET['download'] ?? 0);
if ($downloadId > 0) {
    $stmt = $db->prepare('SELECT event_name, personal_data_json FROM event_archives WHERE id = ? AND includes_personal_data = 1');
    $stmt->execute([$downloadId]);
    $archive = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$archive || trim((string)$archive['personal_data_json']) === '') {
        http_response_code(404);
        exit('Für dieses Archiv sind keine personenbezogenen Exportdaten vorhanden.');
    }
    $rows = eventJsonDecode((string)$archive['personal_data_json']);
    $filename = preg_replace('/[^a-z0-9_-]+/i', '-', (string)$archive['event_name']) ?: 'veranstaltung';
    header('Content-Type: text/tab-separated-values; charset=UTF-8');
    header('Content-Disposition: attachment; filename="archiv-' . strtolower(trim($filename, '-')) . '.tsv"');
    echo "\xEF\xBB\xBF";
    echo implode("\t", ['Name', 'E-Mail', 'Status', 'Normale Schichten', 'Flexible Schichten', 'Hinweis', 'Rückmeldung am']) . "\n";
    foreach ($rows as $row) {
        echo implode("\t", array_map('archiveTsvCell', [
            $row['name'] ?? '',
            $row['email'] ?? '',
            ($row['status'] ?? '') === 'help' ? 'Hilft' : 'Keine Zeit',
            implode(', ', $row['normal_shifts'] ?? []),
            implode(', ', $row['flexible_shifts'] ?? []),
            $row['note'] ?? '',
            $row['created_at'] ?? '',
        ])) . "\n";
    }
    exit;
}

$message = '';
$error = '';
$currentEventName = trim((string)$appConfig['event_name']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'archive_event') {
            $confirmation = trim((string)($_POST['confirmation'] ?? ''));
            if ($confirmation !== $currentEventName) {
                throw new RuntimeException('Der eingegebene Veranstaltungsname stimmt nicht.');
            }
            $result = archiveAndStartNextEvent($db, $databaseFile, dirname($databaseFile) . '/backups', [
                'next_event_name' => trim((string)($_POST['next_event_name'] ?? '')),
                'keep_shifts' => isset($_POST['keep_shifts']),
                'keep_texts' => isset($_POST['keep_texts']),
                'keep_design' => isset($_POST['keep_design']),
                'retain_personal_data' => isset($_POST['retain_personal_data']),
            ]);
            $message = 'Veranstaltung wurde als Archiv #' . $result['archive_id'] . ' abgeschlossen. Vorher wurde automatisch eine Datenbanksicherung angelegt.';
            $appConfig = appConfig($db);
            $currentEventName = trim((string)$appConfig['event_name']);
        }

        if ($action === 'delete_personal_data') {
            $archiveId = (int)($_POST['archive_id'] ?? 0);
            $confirmation = trim((string)($_POST['confirmation'] ?? ''));
            if ($archiveId <= 0 || $confirmation !== 'PERSONENDATEN LOESCHEN') {
                throw new RuntimeException('Der Bestätigungstext stimmt nicht.');
            }
            if (!deleteArchivedPersonalData($db, $archiveId)) {
                throw new RuntimeException('Das Archiv enthält keine personenbezogenen Daten mehr.');
            }
            logEvent($db, 'archive_personal_data_deleted', 'Personenbezogene Daten aus Archiv #' . $archiveId . ' wurden gelöscht.');
            $message = 'Die personenbezogenen Daten wurden aus dem Archiv gelöscht. Summen und Schichtauslastung bleiben erhalten.';
        }

        if ($action === 'apply_template') {
            $archiveId = (int)($_POST['archive_id'] ?? 0);
            $confirmation = trim((string)($_POST['confirmation'] ?? ''));
            if ($confirmation !== 'VORLAGE UEBERNEHMEN') {
                throw new RuntimeException('Der Bestätigungstext stimmt nicht.');
            }
            startEventFromArchiveTemplate(
                $db,
                $databaseFile,
                dirname($databaseFile) . '/backups',
                $archiveId,
                trim((string)($_POST['template_event_name'] ?? ''))
            );
            $message = 'Die Archivvorlage wurde übernommen. Zuvor wurde automatisch eine Datenbanksicherung angelegt.';
            $appConfig = appConfig($db);
            $currentEventName = trim((string)$appConfig['event_name']);
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$currentSummary = eventSummary($db);
$archives = $db->query('SELECT * FROM event_archives ORDER BY closed_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$selectedId = (int)($_GET['id'] ?? 0);
$selectedArchive = null;
foreach ($archives as $archive) {
    if ((int)$archive['id'] === $selectedId) {
        $selectedArchive = $archive;
        break;
    }
}

?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="<?= h(adminViewportContent()) ?>">
    <title>Veranstaltung abschließen - <?= h(appTitle($appConfig)) ?></title>
    <style>
        :root { --primary: <?= h(appDesignColor($appConfig, 'primary_color')) ?>; --border:#d8dee8; --bg:#f5f6f8; --good:#dff3e4; --bad:#f8d7da; --warn:#fff5cf; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:Arial,sans-serif; background:var(--bg); color:#222; line-height:1.5; }
        .wrap { width:min(1480px,calc(100% - 28px)); margin:0 auto; padding:20px 0 40px; }
        .card { background:#fff; border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:16px; box-shadow:0 1px 8px rgba(0,0,0,.04); }
        h1,h2,h3 { margin-top:0; }
        .notice { padding:12px; border-radius:10px; margin-bottom:14px; }
        .success { background:var(--good); border:1px solid #9ed3a8; }
        .error { background:var(--bad); border:1px solid #e2a0a0; }
        .warning { background:var(--warn); border:1px solid #e4cf75; padding:13px; border-radius:10px; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:12px; }
        .stat { border:1px solid var(--border); border-radius:11px; padding:12px; background:#fafafa; }
        .stat strong { display:block; font-size:1.6rem; }
        label { display:block; font-weight:700; margin:13px 0 5px; }
        label.option { display:flex; align-items:flex-start; gap:9px; font-weight:400; margin:10px 0; }
        label.option input { margin-top:5px; }
        input[type="text"] { width:100%; padding:11px; border:1px solid #aeb7c4; border-radius:9px; font:inherit; }
        .btn,button { display:inline-block; border:0; border-radius:10px; padding:11px 14px; background:var(--primary); color:#fff; font-weight:700; text-decoration:none; cursor:pointer; }
        .secondary { background:#475569; }
        .danger { background:#8b0000; }
        .muted { color:#667085; }
        table { width:100%; border-collapse:collapse; }
        th,td { padding:9px 8px; border-bottom:1px solid #e8ebef; text-align:left; vertical-align:top; }
        .table-wrap { overflow-x:auto; }
        .actions { display:flex; flex-wrap:wrap; gap:9px; align-items:center; }
        details { border:1px solid var(--border); border-radius:12px; padding:12px; margin-top:12px; }
        summary { cursor:pointer; font-weight:700; }
        code { background:#f1f3f5; padding:2px 5px; border-radius:4px; }
    </style>
</head>
<body class="<?= h(adminBodyClass()) ?>">
<main class="wrap">
    <div class="card">
        <h1>Veranstaltung abschließen</h1>
        <p class="muted">Aktiv: <?= h($currentEventName) ?></p>
        <?php adminNav('archive'); ?>
    </div>

    <?php if ($message !== ''): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

    <section class="card">
        <h2>Abschließen und nächste Veranstaltung starten</h2>
        <p>Vor jeder Änderung wird automatisch eine vollständige Datenbanksicherung erstellt. Im Archiv bleiben mindestens die anonymen Summen und die Schichtauslastung erhalten. Zugangscodes werden niemals archiviert.</p>
        <div class="grid" style="margin:14px 0;">
            <div class="stat"><strong><?= (int)$currentSummary['codes'] ?></strong>aktive Zugangscodes</div>
            <div class="stat"><strong><?= (int)$currentSummary['responses'] ?></strong>Rückmeldungen</div>
            <div class="stat"><strong><?= (int)$currentSummary['helps'] ?></strong>Zusagen</div>
            <div class="stat"><strong><?= (int)$currentSummary['open_change_requests'] ?></strong>offene Änderungswünsche</div>
        </div>
        <div class="warning"><strong>Wichtig:</strong> Nach dem Abschluss sind die bisherigen Codes und Rückmeldungen nicht mehr in der aktiven Helferliste vorhanden.</div>
        <form method="post" onsubmit="return confirm('Veranstaltung wirklich abschließen und die nächste starten?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="archive_event">
            <label for="next_event_name">Name der nächsten Veranstaltung</label>
            <input id="next_event_name" name="next_event_name" type="text" maxlength="160" required placeholder="Zum Beispiel: Sommerfest 2027">

            <h3 style="margin-top:20px;">Als Vorlage übernehmen</h3>
            <label class="option"><input type="checkbox" name="keep_shifts" checked> <span>Schichten und Kapazitäten behalten</span></label>
            <label class="option"><input type="checkbox" name="keep_texts" checked> <span>Seitentexte behalten</span></label>
            <label class="option"><input type="checkbox" name="keep_design" checked> <span>Design und Kopfbild behalten</span></label>

            <h3 style="margin-top:20px;">Datenschutz im Archiv</h3>
            <label class="option"><input type="checkbox" name="retain_personal_data"> <span>Namen, E-Mail-Adressen, Hinweise und Zuordnungen zusätzlich im Archiv behalten. Ohne Auswahl werden nur anonyme Ergebnisse gespeichert.</span></label>

            <label for="confirmation">Zur Bestätigung den aktuellen Namen exakt eingeben: <code><?= h($currentEventName) ?></code></label>
            <input id="confirmation" name="confirmation" type="text" autocomplete="off" required>
            <button class="danger" type="submit" style="margin-top:14px;">Veranstaltung abschließen</button>
        </form>
    </section>

    <section class="card">
        <h2>Abgeschlossene Veranstaltungen</h2>
        <?php if ($archives === []): ?>
            <p class="muted">Noch keine Veranstaltung archiviert.</p>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Abgeschlossen</th><th>Veranstaltung</th><th>Organisation</th><th>Personendaten</th><th>Aktion</th></tr></thead>
                <tbody>
                <?php foreach ($archives as $archive): ?>
                    <tr>
                        <td><?= h((string)$archive['closed_at']) ?></td>
                        <td><?= h((string)$archive['event_name']) ?></td>
                        <td><?= h((string)$archive['event_organizer']) ?></td>
                        <td><?= (int)$archive['includes_personal_data'] === 1 ? 'enthalten' : 'nicht enthalten' ?></td>
                        <td><a class="btn secondary" href="event_archive.php?id=<?= (int)$archive['id'] ?>">Ansehen</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <?php if ($selectedArchive !== null): ?>
        <?php
            $summary = eventJsonDecode((string)$selectedArchive['summary_json']);
            $personalRows = trim((string)($selectedArchive['personal_data_json'] ?? '')) !== ''
                ? eventJsonDecode((string)$selectedArchive['personal_data_json'])
                : [];
        ?>
        <section class="card">
            <h2>Archiv: <?= h((string)$selectedArchive['event_name']) ?></h2>
            <div class="grid">
                <div class="stat"><strong><?= (int)($summary['responses'] ?? 0) ?></strong>Rückmeldungen</div>
                <div class="stat"><strong><?= (int)($summary['helps'] ?? 0) ?></strong>Zusagen</div>
                <div class="stat"><strong><?= (int)($summary['declines'] ?? 0) ?></strong>Absagen</div>
                <div class="stat"><strong><?= (int)($summary['open_change_requests'] ?? 0) ?></strong>offene Änderungswünsche beim Abschluss</div>
            </div>

            <details open><summary>Schichtauslastung</summary>
                <div class="table-wrap"><table>
                    <thead><tr><th>Art</th><th>Schicht</th><th>Belegt</th><th>Kapazität</th></tr></thead><tbody>
                    <?php foreach (($summary['normal_shifts'] ?? []) as $shift): ?>
                        <tr><td>Normal</td><td><?= h((string)$shift['title']) ?></td><td><?= (int)$shift['assigned'] ?></td><td><?= (int)$shift['max_slots'] ?></td></tr>
                    <?php endforeach; ?>
                    <?php foreach (($summary['flexible_shifts'] ?? []) as $shift): ?>
                        <tr><td>Flexibel</td><td><?= h((string)$shift['title']) ?></td><td><?= (int)$shift['assigned'] ?></td><td><?= (int)$shift['max_slots'] ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
            </details>

            <details>
                <summary>Diese Veranstaltung als Vorlage verwenden</summary>
                <p>Die Vorlage übernimmt Schichten, Texte, Gestaltung und Einstellungen. Das ist nur möglich, solange die aktive Veranstaltung noch keine Zugangscodes oder Rückmeldungen enthält.</p>
                <form method="post" onsubmit="return confirm('Archivvorlage wirklich auf die aktive Veranstaltung anwenden?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="apply_template">
                    <input type="hidden" name="archive_id" value="<?= (int)$selectedArchive['id'] ?>">
                    <label for="template_event_name">Name der neuen Veranstaltung</label>
                    <input id="template_event_name" name="template_event_name" type="text" maxlength="160" required>
                    <label for="template_confirmation">Zur Bestätigung <code>VORLAGE UEBERNEHMEN</code> eingeben</label>
                    <input id="template_confirmation" name="confirmation" type="text" autocomplete="off" required>
                    <button type="submit" style="margin-top:12px;">Vorlage übernehmen</button>
                </form>
            </details>

            <?php if ((int)$selectedArchive['includes_personal_data'] === 1): ?>
                <h3 style="margin-top:20px;">Personenbezogene Archivdaten</h3>
                <div class="actions">
                    <a class="btn secondary" href="event_archive.php?download=<?= (int)$selectedArchive['id'] ?>">TSV exportieren</a>
                </div>
                <div class="table-wrap"><table>
                    <thead><tr><th>Name</th><th>E-Mail</th><th>Status</th><th>Schichten</th><th>Hinweis</th></tr></thead><tbody>
                    <?php foreach ($personalRows as $row): ?>
                        <tr>
                            <td><?= h((string)($row['name'] ?? '')) ?></td>
                            <td><?= h((string)($row['email'] ?? '')) ?></td>
                            <td><?= ($row['status'] ?? '') === 'help' ? 'Hilft' : 'Keine Zeit' ?></td>
                            <td><?= h(implode(', ', array_merge($row['normal_shifts'] ?? [], $row['flexible_shifts'] ?? []))) ?></td>
                            <td><?= nl2br(h((string)($row['note'] ?? ''))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>

                <form method="post" onsubmit="return confirm('Personenbezogene Archivdaten wirklich dauerhaft löschen?');" style="margin-top:20px;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_personal_data">
                    <input type="hidden" name="archive_id" value="<?= (int)$selectedArchive['id'] ?>">
                    <label for="delete_confirmation">Zur Bestätigung <code>PERSONENDATEN LOESCHEN</code> eingeben</label>
                    <input id="delete_confirmation" name="confirmation" type="text" autocomplete="off" required>
                    <button class="danger" type="submit" style="margin-top:12px;">Personendaten aus Archiv löschen</button>
                </form>
            <?php else: ?>
                <p class="muted" style="margin-top:18px;">Dieses Archiv enthält keine Namen, E-Mail-Adressen, Hinweise oder persönlichen Zuordnungen.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
