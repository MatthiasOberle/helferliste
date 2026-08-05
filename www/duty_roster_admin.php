<?php

declare(strict_types=1);

require __DIR__ . '/auth.php';

$db = new PDO('sqlite:' . __DIR__ . '/../data/helferliste.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec('PRAGMA busy_timeout = 5000');

require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/tracking.php';
require_once __DIR__ . '/duty_roster_lib.php';

$appConfig = appConfig($db);
$message = '';
$error = '';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function dutyRosterLimitText(string $value, int $maximum): string
{
    $value = trim($value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $maximum) : substr($value, 0, $maximum);
}

function dutyRosterFormatBytes(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
    }
    return number_format($bytes / 1024, 1, ',', '.') . ' KB';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'upload') {
            $publish = isset($_POST['publish_after_upload']);
            $metadata = dutyRosterUpload($db, $_FILES['duty_roster_pdf'] ?? null, $publish);
            logEvent($db, 'duty_roster_uploaded', 'Diensteinteilung wurde hochgeladen. SHA-256: ' . $metadata['sha256']);
            $message = $publish
                ? 'Die PDF wurde sicher hochgeladen und veröffentlicht.'
                : 'Die PDF wurde sicher hochgeladen und bleibt zunächst unveröffentlicht.';
        } elseif ($action === 'set_publication') {
            $published = (string)($_POST['published'] ?? '0') === '1';
            dutyRosterSetPublished($db, $published);
            logEvent($db, $published ? 'duty_roster_published' : 'duty_roster_unpublished', 'Veröffentlichungsstatus der Diensteinteilung geändert.');
            $message = $published ? 'Die Diensteinteilung ist jetzt veröffentlicht.' : 'Die Diensteinteilung ist jetzt nicht mehr öffentlich erreichbar.';
        } elseif ($action === 'save_public_texts') {
            $heading = dutyRosterLimitText((string)($_POST['duty_roster_public_heading'] ?? ''), 160);
            $intro = dutyRosterLimitText((string)($_POST['duty_roster_public_intro'] ?? ''), 600);
            if ($heading === '' || $intro === '') {
                throw new InvalidArgumentException('Überschrift und Erklärung dürfen nicht leer sein.');
            }
            saveAppSetting($db, 'duty_roster_public_heading', $heading);
            saveAppSetting($db, 'duty_roster_public_intro', $intro);
            logEvent($db, 'duty_roster_texts_saved', 'Öffentliche Texte der Diensteinteilung wurden geändert.');
            $message = 'Die öffentlichen Texte wurden gespeichert.';
        } elseif ($action === 'delete') {
            if (trim((string)($_POST['confirmation'] ?? '')) !== 'DIENSTEINTEILUNG LOESCHEN') {
                throw new InvalidArgumentException('Der Bestätigungstext stimmt nicht.');
            }
            dutyRosterDeleteDocument($db);
            logEvent($db, 'duty_roster_deleted', 'Diensteinteilung wurde gelöscht.');
            $message = 'Die Diensteinteilung wurde gelöscht.';
        }
    } catch (Throwable $exception) {
        $error = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'Die Änderung konnte nicht sicher abgeschlossen werden.';
    }
    $appConfig = appConfig($db);
}

$metadata = dutyRosterMetadata($db);
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="<?= h(adminViewportContent()) ?>">
    <title>Diensteinteilung - <?= h(appTitle($appConfig)) ?></title>
    <style>
        :root { --primary: <?= h(appDesignColor($appConfig, 'primary_color')) ?>; --border:#d7dce2; --bg:#f5f6f8; --muted:#5f6b78; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:Arial,Helvetica,sans-serif; color:#1f2933; background:var(--bg); line-height:1.45; }
        .wrap { width:min(1100px,calc(100% - 28px)); margin:0 auto; padding:20px 0 44px; }
        .card { background:#fff; border:1px solid var(--border); border-radius:14px; padding:20px; margin-bottom:16px; box-shadow:0 2px 10px rgba(15,23,42,.05); }
        h1,h2 { margin-top:0; }
        .muted { color:var(--muted); }
        .notice { padding:13px 15px; border-radius:12px; margin-bottom:15px; border:1px solid; }
        .success { color:#166534; background:#ecfdf3; border-color:#86efac; }
        .error { color:#991b1b; background:#fff1f2; border-color:#fecaca; }
        .info { padding:14px; border:1px solid var(--border); border-radius:12px; background:#f8fafc; }
        .status { display:inline-flex; padding:5px 9px; border-radius:999px; font-weight:800; background:#fee2e2; color:#991b1b; }
        .status.published { background:#dcfce7; color:#166534; }
        label { display:block; font-weight:800; margin:12px 0 6px; }
        input[type=file], input[type=text], textarea { width:100%; padding:11px; border:1px solid #aeb7c2; border-radius:10px; font:inherit; }
        textarea { min-height:105px; resize:vertical; }
        .check { display:flex; gap:10px; align-items:flex-start; font-weight:700; }
        .check input { margin-top:4px; }
        .button-row { display:flex; flex-wrap:wrap; gap:10px; margin-top:14px; }
        .btn, button { display:inline-flex; align-items:center; justify-content:center; min-height:44px; border:0; border-radius:10px; padding:10px 15px; background:var(--primary); color:#fff; font:inherit; font-weight:800; text-decoration:none; cursor:pointer; }
        .secondary { background:#4b5563; }
        .danger { background:#991b1b; }
        code { overflow-wrap:anywhere; }
        @media (max-width:700px) { .wrap{width:calc(100% - 16px);padding-top:8px}.card{padding:14px}.button-row{display:grid}.button-row>*{width:100%} }
    </style>
</head>
<body class="<?= h(adminBodyClass()) ?>">
<div class="wrap">
    <div class="card">
        <h1>Diensteinteilung</h1>
        <p class="muted">PDF geschützt hochladen und ausschließlich Personen mit bereits gespeicherter Rückmeldung bereitstellen. Eine Veröffentlichung ist erst möglich, wenn die Veranstaltung abgeschlossen und die Rückmeldung gesperrt ist.</p>
        <?php adminNav('duty-roster'); ?>
    </div>

    <?php if ($message !== ''): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

    <div class="card">
        <h2>Aktuelle PDF</h2>
        <?php if ($metadata === null): ?>
            <p>Es wurde noch keine Diensteinteilung hochgeladen.</p>
        <?php else: ?>
            <div class="info">
                <p><span class="status <?= $metadata['published'] ? 'published' : '' ?>"><?= $metadata['published'] ? 'Veröffentlicht' : 'Nicht veröffentlicht' ?></span></p>
                <p><strong>Datei:</strong> <?= h($metadata['filename']) ?><br>
                    <strong>Größe:</strong> <?= h(dutyRosterFormatBytes($metadata['size'])) ?><br>
                    <strong>Hochgeladen:</strong> <?= h($metadata['uploaded_at']) ?><br>
                    <strong>SHA-256:</strong> <code><?= h($metadata['sha256']) ?></code></p>
            </div>
            <div class="button-row">
                <a class="btn secondary" href="duty_roster_admin_file.php" target="_blank" rel="noopener">PDF prüfen</a>
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="set_publication">
                    <input type="hidden" name="published" value="<?= $metadata['published'] ? '0' : '1' ?>">
                    <button type="submit"><?= $metadata['published'] ? 'Veröffentlichung stoppen' : 'Jetzt veröffentlichen' ?></button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2><?= $metadata === null ? 'PDF hochladen' : 'PDF ersetzen' ?></h2>
        <p class="muted">Erlaubt ist genau eine PDF bis 10 MB. Die Datei wird außerhalb des öffentlichen Webordners gespeichert.</p>
        <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= DUTY_ROSTER_MAX_BYTES ?>">
            <label for="duty_roster_pdf">PDF-Datei</label>
            <input id="duty_roster_pdf" name="duty_roster_pdf" type="file" accept="application/pdf,.pdf" required>
            <label class="check"><input type="checkbox" name="publish_after_upload" value="1"><span>PDF nach erfolgreicher Prüfung sofort veröffentlichen</span></label>
            <div class="button-row"><button type="submit"><?= $metadata === null ? 'PDF sicher hochladen' : 'PDF sicher ersetzen' ?></button></div>
        </form>
    </div>

    <div class="card">
        <h2>Öffentliche Texte</h2>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_public_texts">
            <label for="duty_roster_public_heading">Überschrift</label>
            <input id="duty_roster_public_heading" name="duty_roster_public_heading" type="text" maxlength="160" value="<?= h(appSetting($db, 'duty_roster_public_heading')) ?>" required>
            <label for="duty_roster_public_intro">Erklärung</label>
            <textarea id="duty_roster_public_intro" name="duty_roster_public_intro" maxlength="600" required><?= h(appSetting($db, 'duty_roster_public_intro')) ?></textarea>
            <div class="button-row"><button type="submit">Texte speichern</button></div>
        </form>
    </div>

    <?php if ($metadata !== null): ?>
        <div class="card">
            <h2>PDF löschen</h2>
            <p class="muted">Die Veröffentlichung wird dabei ebenfalls beendet. Zum Schutz vor Fehlklicks bitte <strong>DIENSTEINTEILUNG LOESCHEN</strong> eingeben.</p>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <label for="confirmation">Bestätigung</label>
                <input id="confirmation" name="confirmation" type="text" autocomplete="off" required>
                <div class="button-row"><button class="danger" type="submit">PDF endgültig löschen</button></div>
            </form>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
