<?php

declare(strict_types=1);

require __DIR__ . '/auth.php';

$db = new PDO('sqlite:' . __DIR__ . '/../data/helferliste.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once __DIR__ . '/app_config.php';
$appConfig = appConfig($db);

require_once __DIR__ . '/tracking.php';

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$error = '';

// Der Reset verlangt einen festen Bestätigungstext, damit kein versehentlicher Klick alles löscht.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirm = trim((string)($_POST['confirm'] ?? ''));

    if ($confirm !== 'EVENTDATEN LOESCHEN') {
        $error = 'Der Bestätigungstext stimmt nicht.';
    } else {
        $db->beginTransaction();
        try {
                        // Reihenfolge ist wichtig: zuerst abhängige Tabellen leeren, danach die Hauptdaten.
$db->exec('DELETE FROM change_requests');
            $db->exec('DELETE FROM entry_springer_shifts');
            $db->exec('DELETE FROM entry_shifts');
            $db->exec('DELETE FROM entries');
            $db->exec('DELETE FROM access_codes');
            $db->exec('DELETE FROM visit_stats');
            $db->exec('DELETE FROM event_log');
                        // Nach dem Löschen wird wieder ein Logeintrag geschrieben, damit der Reset sichtbar bleibt.
$stmt = $db->prepare("INSERT INTO event_log (created_at, action, detail) VALUES (datetime('now'), ?, ?)");
            $stmt->execute(['event_reset', 'Eventdaten wurden gelöscht. Schichten wurden behalten.']);
            $db->commit();
            $message = 'Eventdaten wurden gelöscht. Schichten und Springer-Schichten wurden behalten.';
        } catch (Throwable $e) {
            $db->rollBack();
            $error = 'Die Eventdaten konnten nicht gelöscht werden.';
        }
    }
}

?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="<?= h(adminViewportContent()) ?>">
    <title>Eventdaten löschen - <?= h(appTitle($appConfig)) ?></title>
    <style>
        :root { --primary: #b00020; --border: #ddd; --bg: #f5f5f5; --bad: #f8d7da; --ok: #dff3e4; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: var(--bg); color: #222; line-height: 1.45; }
        .wrap { width: min(1680px, calc(100% - 28px)); margin: 0 auto; padding: 20px 0 40px; }
        .admin-nav { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 12px; }
        .admin-nav a { display: inline-block; border-radius: 999px; padding: 9px 12px; background: #eee; color: #222; font-weight: bold; text-decoration: none; }
        .admin-nav a.active { background: var(--primary, #b00020); color: white; }
        .admin-nav a.right { margin-left: auto; }
        .card { background: white; border: 1px solid var(--border); border-radius: 14px; padding: 16px; margin-bottom: 16px; box-shadow: 0 1px 8px rgba(0,0,0,.04); }
        h1 { margin-top: 0; }
        .btn, button { display: inline-block; border: 0; border-radius: 10px; padding: 10px 13px; background: #555; color: white; font-weight: bold; text-decoration: none; cursor: pointer; margin-top: 10px; }
        button.danger { background: #8b0000; }
        input[type="text"] { width: 100%; border: 1px solid #bbb; border-radius: 10px; padding: 10px; font-size: 1rem; }
        .notice { padding: 12px; border-radius: 10px; margin-bottom: 14px; }
        .success { background: var(--ok); border: 1px solid #9ed3a8; }
        .error { background: var(--bad); border: 1px solid #e2a0a0; }
        .danger-box { background: var(--bad); border: 1px solid #d99; border-radius: 12px; padding: 14px; }
        code { background: #f3f3f3; padding: 2px 5px; border-radius: 4px; }
    </style>
</head>
<body class="<?= h(adminBodyClass()) ?>">
<div class="wrap">
    <div class="card">
        <h1>Eventdaten löschen</h1>
        <?php adminNav('clear'); ?>
    </div>

    <?php if ($message !== ''): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

    <div class="card danger-box">
        <h2>Was wird gelöscht?</h2>
        <ul>
            <li>alle Rückmeldungen</li>
            <li>alle Zuordnungen zu normalen und Springer-Schichten</li>
            <li>alle Zugangscodes und E-Mail-Adressen</li>
            <li>alle Änderungswünsche</li>
            <li>alle Aufrufstatistiken und E-Mail-Öffnungsdaten</li>
            <li>das interne Event-Protokoll</li>
        </ul>
        <p><strong>Was bleibt erhalten?</strong> Normale Schichten und Springer-Schichten bleiben erhalten.</p>
    </div>

    <div class="card">
        <form method="post" onsubmit="return confirm('Eventdaten wirklich unwiderruflich löschen?');">
                <?= csrfField() ?>
            <label for="confirm">Zur Bestätigung exakt eingeben: <code>EVENTDATEN LOESCHEN</code></label><br><br>
            <input type="text" id="confirm" name="confirm" autocomplete="off">
            <button class="danger" type="submit">Eventdaten endgültig löschen</button>
        </form>
    </div>
</div>
</body>
</html>
