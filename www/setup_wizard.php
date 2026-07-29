<?php

declare(strict_types=1);

require __DIR__ . '/auth.php';

$db = adminDb();
require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/setup_wizard_lib.php';
require_once __DIR__ . '/tracking.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function wizardUrl(int $step): string
{
    return 'setup_wizard.php?step=' . max(1, min(4, $step));
}

$step = max(1, min(4, (int)($_GET['step'] ?? 1)));
$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save_event') {
            setupWizardSaveSettings($db, setupWizardValidateEvent($_POST) + ['event_status' => 'draft']);
            logEvent($db, 'setup_event_saved', 'Veranstaltungsangaben im Einrichtungsassistenten gespeichert.');
            header('Location: ' . wizardUrl(2));
            exit;
        }
        if ($action === 'save_legal') {
            setupWizardSaveSettings($db, setupWizardValidateLegal($_POST));
            logEvent($db, 'setup_legal_saved', 'Öffentliche Pflichtangaben im Einrichtungsassistenten gespeichert.');
            header('Location: ' . wizardUrl(3));
            exit;
        }
        if ($action === 'create_shift') {
            setupWizardCreateShift($db, $_POST);
            logEvent($db, 'setup_shift_created', 'Erste Schicht im Einrichtungsassistenten angelegt.');
            header('Location: ' . wizardUrl(4));
            exit;
        }
        if ($action === 'continue_with_shifts') {
            if (setupWizardRealShiftCount($db) < 1) {
                throw new RuntimeException('Bitte lege zuerst mindestens eine konkrete Schicht an.');
            }
            header('Location: ' . wizardUrl(4));
            exit;
        }
        if ($action === 'finish') {
            $status = (string)($_POST['event_status'] ?? 'draft');
            setupWizardFinish($db, $status);
            logEvent($db, 'setup_completed', 'Einrichtungsassistent abgeschlossen. Status: ' . $status);
            $_SESSION['admin_flash'] = $status === 'published'
                ? 'Die Einrichtung ist abgeschlossen und die Veranstaltung ist veröffentlicht.'
                : 'Die Einrichtung ist abgeschlossen. Die Veranstaltung bleibt zunächst im Entwurf.';
            header('Location: admin.php');
            exit;
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$config = appConfig($db);
$issues = appPublicationIssues($db, $config);
$realShiftCount = setupWizardRealShiftCount($db);
$stepNames = [1 => 'Veranstaltung', 2 => 'Pflichtangaben', 3 => 'Erste Schicht', 4 => 'Prüfen'];

?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="<?= h(adminViewportContent()) ?>">
    <title>Einrichtungsassistent – <?= h(appTitle($config)) ?></title>
    <style>
        :root { --primary:<?= h(appDesignColor($config, 'primary_color')) ?>; --bg:#f2f4f7; --border:#d8dee8; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:system-ui,sans-serif; background:var(--bg); color:#1f2937; }
        .wrap { width:min(920px,calc(100% - 28px)); margin:0 auto; padding:18px 0 42px; }
        .card { background:#fff; border:1px solid var(--border); border-radius:17px; padding:22px; margin-bottom:18px; box-shadow:0 7px 24px rgba(15,23,42,.07); }
        h1,h2 { margin-top:0; }
        .muted { color:#64748b; }
        .progress { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; padding:0; list-style:none; margin:20px 0 0; }
        .progress li { border:1px solid var(--border); border-radius:12px; padding:10px; text-align:center; color:#64748b; font-weight:750; }
        .progress li.active { background:var(--primary); border-color:var(--primary); color:#fff; }
        .progress li.done { background:#dcfce7; border-color:#86d8a2; color:#166534; }
        .grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:15px; }
        .field.wide { grid-column:1 / -1; }
        label { display:block; font-weight:800; margin-bottom:6px; }
        input,select,textarea { width:100%; border:1px solid #b8c1cd; border-radius:10px; padding:12px; font:inherit; background:#fff; }
        textarea { resize:vertical; }
        .hint { display:block; color:#64748b; margin-top:5px; font-size:.9rem; }
        .actions { display:flex; flex-wrap:wrap; gap:10px; justify-content:space-between; margin-top:22px; }
        button,.button { display:inline-flex; align-items:center; justify-content:center; min-height:46px; border:0; border-radius:10px; padding:12px 17px; background:var(--primary); color:#fff; text-decoration:none; font-weight:800; cursor:pointer; }
        .secondary { background:#475569; }
        .quiet { background:#e8edf3; color:#263241; }
        .notice { padding:13px 15px; border-radius:11px; margin-bottom:16px; font-weight:700; }
        .notice.error { background:#fee2e2; color:#991b1b; }
        .notice.success { background:#dcfce7; color:#166534; }
        .checklist { display:grid; gap:9px; padding:0; list-style:none; }
        .checklist li { border:1px solid var(--border); border-radius:10px; padding:11px 13px; }
        .checklist .ok { background:#f0fdf4; color:#166534; }
        .checklist .missing { background:#fff7ed; color:#9a3412; }
        .choice { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
        .choice label { border:2px solid var(--border); border-radius:14px; padding:15px; cursor:pointer; }
        .choice input { width:auto; margin-right:8px; }
        @media(max-width:680px) {
            .wrap { width:calc(100% - 16px); padding-top:8px; }
            .card { padding:15px; }
            .progress { grid-template-columns:1fr 1fr; }
            .grid,.choice { grid-template-columns:1fr; }
            .field.wide { grid-column:auto; }
            .actions { display:grid; }
            button,.button { width:100%; }
        }
    </style>
</head>
<body class="<?= h(adminBodyClass()) ?>">
<main class="wrap">
    <section class="card">
        <h1>Helferliste einrichten</h1>
        <p class="muted">Vier übersichtliche Schritte führen durch die Angaben, die vor einer Veröffentlichung benötigt werden.</p>
        <ol class="progress" aria-label="Fortschritt">
            <?php foreach ($stepNames as $number => $name): ?>
                <li class="<?= $number === $step ? 'active' : ($number < $step ? 'done' : '') ?>">
                    <?= $number ?>. <?= h($name) ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
    <?php if ($message !== ''): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>

    <?php if ($step === 1): ?>
        <section class="card">
            <h2>1. Veranstaltung</h2>
            <p class="muted">Diese Angaben erscheinen im Kopf der öffentlichen Helferliste.</p>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_event">
                <div class="grid">
                    <div class="field wide"><label for="app_name">Name der Anwendung</label><input id="app_name" name="app_name" maxlength="100" value="<?= h((string)$config['app_name']) ?>" required><span class="hint">Zum Beispiel „Helferliste Sommerfest“.</span></div>
                    <div class="field"><label for="event_organizer">Organisation</label><input id="event_organizer" name="event_organizer" maxlength="160" value="<?= h((string)$config['event_organizer']) ?>" required></div>
                    <div class="field"><label for="event_name">Veranstaltung</label><input id="event_name" name="event_name" maxlength="160" value="<?= h((string)$config['event_name']) ?>" required></div>
                    <div class="field"><label for="event_start_date">Beginn</label><input id="event_start_date" name="event_start_date" type="date" value="<?= h((string)$config['event_start_date']) ?>" required></div>
                    <div class="field"><label for="event_end_date">Ende optional</label><input id="event_end_date" name="event_end_date" type="date" value="<?= h((string)$config['event_end_date']) ?>"></div>
                </div>
                <div class="actions"><a class="button quiet" href="admin.php">Später fortsetzen</a><button type="submit">Speichern und weiter</button></div>
            </form>
        </section>
    <?php elseif ($step === 2): ?>
        <section class="card">
            <h2>2. Öffentliche Pflichtangaben</h2>
            <p class="muted">Hier werden Betreiber- und Kontaktdaten gepflegt. MOWST bleibt davon getrennt als Entwickler der Anwendung genannt.</p>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_legal">
                <div class="grid">
                    <div class="field wide"><label for="public_base_url">Öffentliche Adresse der Helferliste</label><input id="public_base_url" name="public_base_url" type="url" maxlength="500" value="<?= h((string)$config['public_base_url']) ?>" placeholder="https://helfer.example.org" required></div>
                    <div class="field"><label for="imprint_name">Verantwortlicher Name / Organisation</label><input id="imprint_name" name="imprint_name" maxlength="200" value="<?= h((string)$config['imprint_name']) ?>" required></div>
                    <div class="field"><label for="imprint_email">E-Mail für das Impressum</label><input id="imprint_email" name="imprint_email" type="email" maxlength="254" value="<?= h((string)$config['imprint_email']) ?>" required></div>
                    <div class="field wide"><label for="imprint_address">Vollständige Anschrift</label><textarea id="imprint_address" name="imprint_address" maxlength="500" rows="3" required><?= h((string)$config['imprint_address']) ?></textarea></div>
                    <div class="field"><label for="imprint_phone">Telefon optional</label><input id="imprint_phone" name="imprint_phone" maxlength="100" value="<?= h((string)$config['imprint_phone']) ?>"></div>
                    <div class="field"><label for="imprint_website">Website optional</label><input id="imprint_website" name="imprint_website" type="url" maxlength="500" value="<?= h((string)$config['imprint_website']) ?>"></div>
                    <div class="field"><label for="privacy_contact_name">Datenschutz-Ansprechpartner optional</label><input id="privacy_contact_name" name="privacy_contact_name" maxlength="200" value="<?= h((string)$config['privacy_contact_name']) ?>"></div>
                    <div class="field"><label for="privacy_contact_email">Datenschutz-E-Mail</label><input id="privacy_contact_email" name="privacy_contact_email" type="email" maxlength="254" value="<?= h((string)$config['privacy_contact_email']) ?>" required></div>
                </div>
                <div class="actions"><a class="button quiet" href="<?= h(wizardUrl(1)) ?>">Zurück</a><button type="submit">Speichern und weiter</button></div>
            </form>
        </section>
    <?php elseif ($step === 3): ?>
        <section class="card">
            <h2>3. Erste konkrete Schicht</h2>
            <?php if ($realShiftCount > 0): ?>
                <div class="notice success"><?= $realShiftCount ?> konkrete Schicht<?= $realShiftCount === 1 ? '' : 'en' ?> vorhanden. Du kannst eine weitere anlegen oder direkt zur Prüfung gehen.</div>
            <?php else: ?>
                <p class="muted">Die mitgelieferten Beispielschichten zählen nicht als fertige Einrichtung. Beim Anlegen der ersten echten Schicht werden unbenutzte Beispiele automatisch deaktiviert.</p>
            <?php endif; ?>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create_shift">
                <div class="grid">
                    <div class="field"><label for="type">Schichtart</label><select id="type" name="type"><option value="normal">Normale Schicht</option><option value="springer">Springer-Schicht</option></select></div>
                    <div class="field"><label for="title">Titel</label><input id="title" name="title" maxlength="160" placeholder="Aufbau Festhalle" required></div>
                    <div class="field"><label for="shift_date">Datum</label><input id="shift_date" name="shift_date" type="date" value="<?= h((string)$config['event_start_date']) ?>"></div>
                    <div class="field"><label for="location">Ort</label><input id="location" name="location" maxlength="160" placeholder="Festhalle"></div>
                    <div class="field"><label for="start_time">Beginn</label><input id="start_time" name="start_time" type="time"></div>
                    <div class="field"><label for="end_time">Ende</label><input id="end_time" name="end_time" type="time"></div>
                    <div class="field"><label for="max_slots">Benötigte Personen</label><input id="max_slots" name="max_slots" type="number" min="1" max="9999" value="5" required></div>
                    <div class="field wide"><label for="note">Öffentlicher Hinweis optional</label><textarea id="note" name="note" maxlength="600" rows="3" placeholder="Zum Beispiel: Arbeitshandschuhe mitbringen"></textarea></div>
                </div>
                <div class="actions"><a class="button quiet" href="<?= h(wizardUrl(2)) ?>">Zurück</a><button type="submit">Schicht anlegen und prüfen</button></div>
            </form>
            <?php if ($realShiftCount > 0): ?>
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="continue_with_shifts">
                    <div class="actions"><span></span><button class="secondary" type="submit">Ohne weitere Schicht zur Prüfung</button></div>
                </form>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="card">
            <h2>4. Einrichtung prüfen</h2>
            <ul class="checklist">
                <li class="ok">✓ <?= h(appSubtitle($config)) ?><?= appEventDateRange($config) !== '' ? ' · ' . h(appEventDateRange($config)) : '' ?></li>
                <li class="<?= trim((string)$config['public_base_url']) !== '' ? 'ok' : 'missing' ?>"><?= trim((string)$config['public_base_url']) !== '' ? '✓' : '!' ?> Öffentliche Adresse: <?= h((string)$config['public_base_url']) ?></li>
                <li class="<?= trim((string)$config['imprint_email']) !== '' ? 'ok' : 'missing' ?>"><?= trim((string)$config['imprint_email']) !== '' ? '✓' : '!' ?> Impressum und Datenschutzkontakt</li>
                <li class="<?= $realShiftCount > 0 ? 'ok' : 'missing' ?>"><?= $realShiftCount > 0 ? '✓' : '!' ?> <?= $realShiftCount ?> konkrete Schicht<?= $realShiftCount === 1 ? '' : 'en' ?></li>
            </ul>
            <?php if ($issues !== []): ?>
                <div class="notice error">Noch offen: <?= h(implode(', ', $issues)) ?>.</div>
                <div class="actions"><a class="button quiet" href="<?= h(wizardUrl(1)) ?>">Angaben korrigieren</a></div>
            <?php else: ?>
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="finish">
                    <div class="choice">
                        <label><input type="radio" name="event_status" value="draft" checked><strong>Im Entwurf lassen</strong><span class="hint">Die öffentliche Seite bleibt gesperrt. Empfohlen für eine letzte Kontrolle.</span></label>
                        <label><input type="radio" name="event_status" value="published"><strong>Jetzt veröffentlichen</strong><span class="hint">Codes und Rückmeldungen sind anschließend öffentlich nutzbar.</span></label>
                    </div>
                    <div class="actions"><a class="button quiet" href="<?= h(wizardUrl(3)) ?>">Zurück</a><button type="submit">Einrichtung abschließen</button></div>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
