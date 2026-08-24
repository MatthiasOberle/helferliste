<?php

declare(strict_types=1);

require __DIR__ . '/auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: no-referrer');

$db = new PDO('sqlite:' . __DIR__ . '/../data/helferliste.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec('PRAGMA busy_timeout = 5000');

require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/database_tools.php';
require_once __DIR__ . '/tracking.php';
require_once __DIR__ . '/invitations_lib.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function invitationCopyPayload(array $message): string
{
    return base64_encode('Betreff: ' . $message['subject'] . "\n\n" . $message['body']);
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_templates') {
    $invitationSubject = trim((string)($_POST['invitation_subject'] ?? ''));
    $invitationBody = trim((string)($_POST['invitation_body'] ?? ''));
    $reminderSubject = trim((string)($_POST['reminder_subject'] ?? ''));
    $reminderBody = trim((string)($_POST['reminder_body'] ?? ''));
    try {
        invitationValidateTemplate($invitationSubject, $invitationBody);
        invitationValidateTemplate($reminderSubject, $reminderBody);
        helferlisteBeginImmediateTransaction($db);
        try {
            saveAppSetting($db, 'invitation_subject', $invitationSubject);
            saveAppSetting($db, 'invitation_body', $invitationBody);
            saveAppSetting($db, 'reminder_subject', $reminderSubject);
            saveAppSetting($db, 'reminder_body', $reminderBody);
            logEvent($db, 'invitation_templates_updated', 'Einladungs- und Erinnerungstexte wurden aktualisiert.');
            helferlisteCommitTransaction($db);
        } catch (Throwable $exception) {
            helferlisteRollbackTransaction($db);
            throw $exception;
        }
        $message = 'Einladungs- und Erinnerungstexte wurden gespeichert.';
    } catch (Throwable $exception) {
        $error = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'Die Texte konnten nicht sicher gespeichert werden.';
    }
}

$appConfig = appConfig($db);
$baseUrl = appPublicBaseUrl($db);
$filter = invitationNormalizeFilter((string)($_GET['filter'] ?? 'pending'));
$limit = limitForList('limit', 10);
$counts = invitationCounts($db);
$rows = invitationRows($db, $filter, $limit);
$cardId = (int)($_GET['card'] ?? 0);
$cardKind = ($_GET['kind'] ?? '') === 'reminder' ? 'reminder' : 'invitation';
$cardRow = $cardId > 0 ? invitationRowById($db, $cardId) : null;
if ($cardId > 0 && $cardRow === null) {
    http_response_code(404);
    $error = 'Die gewählte Einladung wurde nicht gefunden.';
}

?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="<?= h(adminViewportContent()) ?>">
    <meta name="referrer" content="no-referrer">
    <title>Einladen & erinnern – <?= h(appTitle($appConfig)) ?></title>
    <style>
        :root { --primary:<?= h(appDesignColor($appConfig, 'primary_color')) ?>; --border:#d8dee8; --bg:#f5f6f8; --good:#dff3e4; --bad:#f8d7da; --warn:#fff5cf; --muted:#667085; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:Arial,Helvetica,sans-serif; background:var(--bg); color:#222; line-height:1.5; }
        .wrap { width:min(1500px,calc(100% - 28px)); margin:0 auto; padding:20px 0 44px; }
        .card { background:#fff; border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:16px; box-shadow:0 1px 8px rgba(0,0,0,.04); }
        h1,h2,h3 { margin-top:0; }
        .muted { color:var(--muted); }
        .notice { padding:13px 15px; border-radius:11px; margin-bottom:14px; }
        .success { background:var(--good); border:1px solid #9ed3a8; }
        .error { background:var(--bad); border:1px solid #e2a0a0; }
        .warning { background:var(--warn); border:1px solid #e4cf75; }
        .stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:11px; margin:14px 0; }
        .stat { border:1px solid var(--border); border-radius:12px; padding:13px; background:#fafafa; }
        .stat strong { display:block; font-size:1.7rem; }
        .filters,.button-row,.row-actions { display:flex; flex-wrap:wrap; gap:9px; align-items:center; }
        .btn,button { display:inline-flex; align-items:center; justify-content:center; min-height:43px; border:0; border-radius:10px; padding:10px 13px; background:var(--primary); color:#fff; font:inherit; font-weight:800; text-decoration:none; cursor:pointer; }
        .secondary { background:#475569; }
        .quiet { background:#eef2f6; color:#1f2937; border:1px solid #cfd7e2; }
        .filters a.active { background:var(--primary); color:#fff; border-color:var(--primary); }
        label { display:block; font-weight:800; margin:13px 0 5px; }
        input[type="text"],textarea,select { width:100%; border:1px solid #aeb7c4; border-radius:9px; padding:10px; font:inherit; }
        textarea { min-height:145px; resize:vertical; }
        .template-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
        .template-box { border:1px solid var(--border); border-radius:12px; padding:14px; background:#fafafa; }
        code { background:#f1f3f5; padding:2px 5px; border-radius:4px; overflow-wrap:anywhere; }
        .table-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; }
        th,td { padding:10px 8px; border-bottom:1px solid #e8ebef; text-align:left; vertical-align:top; }
        th { background:#fafafa; }
        .recipient { overflow-wrap:anywhere; }
        .status-pending { color:#9a5b00; font-weight:800; }
        .status-help { color:#166534; font-weight:800; }
        .status-no_time { color:#7f1d1d; font-weight:800; }
        .qr-panel { display:none; margin-top:10px; padding:11px; border:1px solid var(--border); border-radius:10px; background:#fff; }
        .qr-panel.open { display:block; }
        .qr-panel svg,.invitation-card svg { width:min(220px,100%); height:auto; background:#fff; }
        .copy-status { min-height:1.5em; margin-top:10px; color:#166534; font-weight:700; }
        .invitation-card { max-width:780px; margin:0 auto; text-align:center; }
        .invitation-card .link { overflow-wrap:anywhere; }
        .invitation-card .code { font-size:1.7rem; letter-spacing:.15em; }
        .card-message { text-align:left; white-space:pre-wrap; border:1px solid var(--border); border-radius:10px; padding:14px; background:#fafafa; }
        @media (max-width:800px) {
            .template-grid { grid-template-columns:1fr; }
            .row-actions { display:grid; grid-template-columns:1fr; }
            .row-actions .btn,.row-actions button { width:100%; white-space:normal; }
            body.admin-mobile .invitation-table td > .cell-content { grid-column:2; min-width:0; }
        }
        @page { size:A4 portrait; margin:15mm; }
        @media print {
            body { background:#fff; }
            .no-print { display:none !important; }
            .wrap { width:100% !important; margin:0 !important; padding:0 !important; }
            .card { border:0; box-shadow:none; padding:0; }
            .invitation-card { max-width:none; }
        }
    </style>
</head>
<body class="<?= h(adminBodyClass()) ?>">
<main class="wrap">
    <header class="card no-print">
        <h1>Einladen & erinnern</h1>
        <p class="muted">Persönliche Texte und QR-Codes vorbereiten – ohne automatischen Versand und ohne Öffnungstracking.</p>
        <?php adminNav('invitations'); ?>
    </header>

    <div id="copy-status" class="copy-status no-print" role="status" aria-live="polite"></div>
    <?php if ($message !== ''): ?><div class="notice success no-print" role="status"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice error no-print" role="alert"><?= h($error) ?></div><?php endif; ?>

    <?php if ($cardRow !== null): ?>
        <?php $cardMessage = invitationMessage($appConfig, $cardRow, $cardKind, $baseUrl); ?>
        <section class="card invitation-card">
            <div class="no-print button-row" style="justify-content:center;margin-bottom:18px;">
                <button type="button" onclick="window.print()">Karte drucken</button>
                <button class="secondary copy-button" type="button" data-copy64="<?= h(invitationCopyPayload($cardMessage)) ?>">Text kopieren</button>
                <a class="btn quiet" href="invitations.php?filter=<?= h($filter) ?>&limit=<?= $limit ?>">Zur Übersicht</a>
            </div>
            <p class="muted"><?= $cardKind === 'reminder' ? 'Erinnerung' : 'Einladung' ?> für</p>
            <h1><?= h((string)$appConfig['event_name']) ?></h1>
            <?php if (appEventDateRange($appConfig) !== ''): ?><p><?= h(appEventDateRange($appConfig)) ?></p><?php endif; ?>
            <div class="qr-target" data-link="<?= h($cardMessage['link']) ?>"></div>
            <p class="link"><strong>Persönlicher Link:</strong><br><?= h($cardMessage['link']) ?></p>
            <p><strong>Alternativer Zugangscode:</strong><br><span class="code"><?= h((string)$cardRow['code']) ?></span></p>
            <p class="muted">Diese Karte ist persönlich und darf nur an <?= h((string)$cardRow['email']) ?> weitergegeben werden.</p>
            <h2 style="margin-top:28px;"><?= h($cardMessage['subject']) ?></h2>
            <div class="card-message"><?= h($cardMessage['body']) ?></div>
        </section>
    <?php else: ?>
        <?php if (($appConfig['event_status'] ?? '') !== 'published'): ?>
            <div class="notice warning no-print"><strong>Noch nicht versenden:</strong> Die Veranstaltung ist <?= h(appEventStatusLabel($appConfig)) ?>. Persönliche Links können erst nach der Veröffentlichung für Rückmeldungen genutzt werden.</div>
        <?php endif; ?>

        <section class="card no-print">
            <h2>Überblick</h2>
            <div class="stats">
                <div class="stat"><strong><?= $counts['pending'] ?></strong>ohne Rückmeldung</div>
                <div class="stat"><strong><?= $counts['help'] ?></strong>Zusagen</div>
                <div class="stat"><strong><?= $counts['no_time'] ?></strong>keine Zeit</div>
                <div class="stat"><strong><?= $counts['all'] ?></strong>Kontakte insgesamt</div>
            </div>
            <p class="muted">Die Helferliste merkt sich bewusst nicht, ob eine E-Mail geöffnet oder tatsächlich verschickt wurde. Als Status zählt ausschließlich eine gespeicherte Rückmeldung.</p>
        </section>

        <section class="card no-print">
            <h2>Texte anpassen</h2>
            <p>Verwendbare Platzhalter: <code>{veranstaltung}</code>, <code>{organisation}</code>, <code>{zeitraum}</code>, <code>{link}</code> und <code>{code}</code>.</p>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_templates">
                <div class="template-grid">
                    <div class="template-box">
                        <h3>Einladung</h3>
                        <label for="invitation_subject">Betreff</label>
                        <input id="invitation_subject" name="invitation_subject" type="text" maxlength="<?= INVITATION_SUBJECT_MAX_LENGTH ?>" value="<?= h((string)$appConfig['invitation_subject']) ?>" required>
                        <label for="invitation_body">Nachricht</label>
                        <textarea id="invitation_body" name="invitation_body" maxlength="<?= INVITATION_BODY_MAX_LENGTH ?>" required><?= h((string)$appConfig['invitation_body']) ?></textarea>
                    </div>
                    <div class="template-box">
                        <h3>Erinnerung</h3>
                        <label for="reminder_subject">Betreff</label>
                        <input id="reminder_subject" name="reminder_subject" type="text" maxlength="<?= INVITATION_SUBJECT_MAX_LENGTH ?>" value="<?= h((string)$appConfig['reminder_subject']) ?>" required>
                        <label for="reminder_body">Nachricht</label>
                        <textarea id="reminder_body" name="reminder_body" maxlength="<?= INVITATION_BODY_MAX_LENGTH ?>" required><?= h((string)$appConfig['reminder_body']) ?></textarea>
                    </div>
                </div>
                <button type="submit" style="margin-top:14px;">Texte speichern</button>
            </form>
        </section>

        <section class="card no-print">
            <h2>Empfänger auswählen</h2>
            <div class="filters" aria-label="Rückmeldestatus filtern">
                <a class="btn quiet <?= $filter === 'pending' ? 'active' : '' ?>" href="?filter=pending&limit=<?= $limit ?>">Ohne Rückmeldung (<?= $counts['pending'] ?>)</a>
                <a class="btn quiet <?= $filter === 'help' ? 'active' : '' ?>" href="?filter=help&limit=<?= $limit ?>">Zusagen (<?= $counts['help'] ?>)</a>
                <a class="btn quiet <?= $filter === 'no_time' ? 'active' : '' ?>" href="?filter=no_time&limit=<?= $limit ?>">Keine Zeit (<?= $counts['no_time'] ?>)</a>
                <a class="btn quiet <?= $filter === 'all' ? 'active' : '' ?>" href="?filter=all&limit=<?= $limit ?>">Alle (<?= $counts['all'] ?>)</a>
            </div>
            <div style="margin-top:14px;"><?php renderLimitSelect('limit', $limit); ?></div>

            <?php if ($rows === []): ?>
                <p class="muted" style="margin-top:16px;">Für diesen Filter sind keine Kontakte vorhanden.</p>
            <?php else: ?>
                <div class="table-wrap" style="margin-top:16px;">
                    <table class="invitation-table">
                        <thead><tr><th>Empfänger</th><th>Status</th><th>Persönlicher Zugang</th><th>Einladung</th><th>Erinnerung</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                                $invite = invitationMessage($appConfig, $row, 'invitation', $baseUrl);
                                $reminder = invitationMessage($appConfig, $row, 'reminder', $baseUrl);
                                $statusClass = $row['entry_status'] ?? 'pending';
                                $qrId = 'qr-' . (int)$row['id'];
                            ?>
                            <tr>
                                <td class="recipient"><div class="cell-content"><?= h((string)$row['email']) ?><?php if ($row['entry_name'] !== ''): ?><br><span class="muted"><?= h((string)$row['entry_name']) ?></span><?php endif; ?></div></td>
                                <td><div class="cell-content"><span class="status-<?= h((string)$statusClass) ?>"><?= h(invitationStatusLabel($row['entry_status'])) ?></span></div></td>
                                <td>
                                    <div class="cell-content">
                                        <code><?= h((string)$row['code']) ?></code>
                                        <div class="row-actions" style="margin-top:7px;">
                                            <button class="quiet copy-button" type="button" data-copy64="<?= h(base64_encode($invite['link'])) ?>">Link kopieren</button>
                                            <button class="quiet qr-button" type="button" aria-expanded="false" aria-controls="<?= h($qrId) ?>" data-qr-target="<?= h($qrId) ?>">QR anzeigen</button>
                                        </div>
                                        <div id="<?= h($qrId) ?>" class="qr-panel qr-target" data-link="<?= h($invite['link']) ?>"></div>
                                    </div>
                                </td>
                                <td>
                                    <div class="cell-content">
                                        <div class="row-actions">
                                            <a class="btn" href="<?= h($invite['mailto']) ?>">E-Mail öffnen</a>
                                            <button class="secondary copy-button" type="button" data-copy64="<?= h(invitationCopyPayload($invite)) ?>">Text kopieren</button>
                                            <a class="btn quiet" href="?card=<?= (int)$row['id'] ?>&kind=invitation&filter=<?= h($filter) ?>&limit=<?= $limit ?>">Karte</a>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="cell-content">
                                        <?php if ($row['entry_status'] === null): ?>
                                            <div class="row-actions">
                                                <a class="btn" href="<?= h($reminder['mailto']) ?>">E-Mail öffnen</a>
                                                <button class="secondary copy-button" type="button" data-copy64="<?= h(invitationCopyPayload($reminder)) ?>">Text kopieren</button>
                                                <a class="btn quiet" href="?card=<?= (int)$row['id'] ?>&kind=reminder&filter=<?= h($filter) ?>&limit=<?= $limit ?>">Karte</a>
                                            </div>
                                        <?php else: ?>
                                            <span class="muted">Rückmeldung vorhanden</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <aside class="notice warning no-print">
            <strong>Persönlich versenden:</strong> Direktlinks und QR-Codes enthalten den Zugang zur jeweiligen Rückmeldung. Nicht in öffentliche Gruppen, Webseiten oder soziale Netzwerke stellen.
        </aside>
    <?php endif; ?>
</main>
<script src="assets/vendor/qrcodegen.js"></script>
<script>
    function decodeCopyValue(value) {
        const bytes = Uint8Array.from(atob(value), function(character) { return character.charCodeAt(0); });
        return new TextDecoder().decode(bytes);
    }

    async function copyText(value) {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(value);
            return;
        }
        const field = document.createElement('textarea');
        field.value = value;
        field.setAttribute('readonly', 'readonly');
        field.style.position = 'fixed';
        field.style.left = '-9999px';
        document.body.appendChild(field);
        field.select();
        const copied = document.execCommand('copy');
        field.remove();
        if (!copied) throw new Error('Kopieren nicht möglich');
    }

    function announce(text, failed) {
        const status = document.getElementById('copy-status');
        if (!status) return;
        status.textContent = text;
        status.style.color = failed ? '#8b0000' : '#166534';
    }

    document.querySelectorAll('.copy-button').forEach(function(button) {
        button.addEventListener('click', async function() {
            try {
                await copyText(decodeCopyValue(button.dataset.copy64 || ''));
                announce('In die Zwischenablage kopiert.', false);
            } catch (error) {
                announce('Kopieren war nicht möglich. Bitte den Text manuell markieren.', true);
            }
        });
    });

    function renderQr(target) {
        if (!target || target.dataset.rendered === '1') return;
        const qr = qrcodegen.QrCode.encodeText(target.dataset.link || '', qrcodegen.QrCode.Ecc.MEDIUM);
        const border = 4;
        const size = qr.size + border * 2;
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 ' + size + ' ' + size);
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', 'QR-Code für den persönlichen Einladungslink');
        svg.setAttribute('shape-rendering', 'crispEdges');
        const background = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        background.setAttribute('width', '100%');
        background.setAttribute('height', '100%');
        background.setAttribute('fill', '#fff');
        svg.appendChild(background);
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        let data = '';
        for (let y = 0; y < qr.size; y++) {
            for (let x = 0; x < qr.size; x++) {
                if (qr.getModule(x, y)) data += 'M' + (x + border) + ',' + (y + border) + 'h1v1h-1z';
            }
        }
        path.setAttribute('d', data);
        path.setAttribute('fill', '#000');
        svg.appendChild(path);
        target.appendChild(svg);
        target.dataset.rendered = '1';
    }

    document.querySelectorAll('.qr-button').forEach(function(button) {
        button.addEventListener('click', function() {
            const target = document.getElementById(button.dataset.qrTarget || '');
            if (!target) return;
            const opening = !target.classList.contains('open');
            target.classList.toggle('open', opening);
            button.setAttribute('aria-expanded', opening ? 'true' : 'false');
            button.textContent = opening ? 'QR ausblenden' : 'QR anzeigen';
            if (opening) renderQr(target);
        });
    });

    document.querySelectorAll('.invitation-card .qr-target').forEach(renderQr);
</script>
</body>
</html>
