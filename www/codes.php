<?php

declare(strict_types=1);

require __DIR__ . '/auth.php';

$db = new PDO('sqlite:' . __DIR__ . '/../data/helferliste.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/tracking.php';
$appConfig = appConfig($db);

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}


// Erlaubt E-Mail-Listen mit Komma, Semikolon oder Zeilenumbrüchen.
function normalizeEmails(string $input): array {
    $input = substr(trim($input), 0, 20000);
    $parts = preg_split('/[\s,;]+/', $input) ?: [];
    $emails = [];
    foreach ($parts as $part) {
        $email = strtolower(trim($part));
        if ($email !== '' && strlen($email) <= 254) {
            $emails[$email] = $email;
        }
    }
    return array_slice(array_values($emails), 0, 500);
}

// Verhindert doppelte Zugangscodes.
function codeExists(PDO $db, string $code): bool {
    $stmt = $db->prepare("SELECT 1 FROM access_codes WHERE code = ? LIMIT 1");
    $stmt->execute([$code]);
    return (bool)$stmt->fetchColumn();
}

// Erstellt einen freien vierstelligen Code.
function generateUniqueCode(PDO $db): string {
    for ($i = 0; $i < 200; $i++) {
        $code = (string)random_int(1000, 9999);
        if (!codeExists($db, $code)) {
            return $code;
        }
    }
    throw new RuntimeException('Es konnte kein freier vierstelliger Code gefunden werden.');
}

// Eine E-Mail-Adresse soll im Event nur einmal vorkommen.
function emailAlreadyExists(PDO $db, string $email): bool {
    $stmt = $db->prepare("SELECT 1 FROM access_codes WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    return (bool)$stmt->fetchColumn();
}

// Benutzte Codes werden nicht einzeln gelöscht, damit Rückmeldungen nachvollziehbar bleiben.
function codeIsUsed(PDO $db, int $codeId): bool {
    $stmt = $db->prepare("SELECT 1 FROM entries WHERE access_code_id = ? LIMIT 1");
    $stmt->execute([$codeId]);
    return (bool)$stmt->fetchColumn();
}

$message = '';
$error = '';
$createdCodes = [];
$skippedEmails = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

        // Aus der eingefügten E-Mail-Liste werden gültige, neue Codes erzeugt.
if ($action === 'create_codes') {
        $emails = normalizeEmails((string)($_POST['emails'] ?? ''));

        if (empty($emails)) {
            $error = 'Bitte mindestens eine E-Mail-Adresse eingeben.';
        } else {
            $stmt = $db->prepare("INSERT INTO access_codes (email, code, created_at) VALUES (?, ?, datetime('now'))");

            foreach ($emails as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $skippedEmails[] = $email . ' (ungültig)';
                    continue;
                }

                if (emailAlreadyExists($db, $email)) {
                    $skippedEmails[] = $email . ' (bereits vorhanden)';
                    continue;
                }

                try {
                    $code = generateUniqueCode($db);
                    $stmt->execute([$email, $code]);
                    $createdCodes[] = ['email' => $email, 'code' => $code];
                } catch (Throwable $e) {
                    $skippedEmails[] = $email . ' (Fehler beim Erzeugen)';
                }
            }

            if (!empty($createdCodes)) {
                logEvent($db, 'codes_created', count($createdCodes) . ' Codes erzeugt.');
                $message = count($createdCodes) . ' Code(s) wurden erzeugt.';
            }
            if (empty($createdCodes) && empty($skippedEmails)) {
                $error = 'Es wurden keine Codes erzeugt.';
            }
        }
    }

    if ($action === 'delete_one') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0 && !codeIsUsed($db, $id)) {
            $stmt = $db->prepare("DELETE FROM access_codes WHERE id = ?");
            $stmt->execute([$id]);
            logEvent($db, 'code_deleted', 'Unbenutzter Code #' . $id . ' wurde gelöscht.');
            $message = 'Code wurde gelöscht.';
        } else {
            $error = 'Der Code kann nicht gelöscht werden, weil er bereits verwendet wurde oder nicht existiert.';
        }
    }

        // Massenlöschung nur für unbenutzte Codes und nur mit Bestätigungstext.
if ($action === 'delete_unused') {
        $confirm = trim((string)($_POST['confirm'] ?? ''));
        if ($confirm !== 'UNBENUTZTE CODES LOESCHEN') {
            $error = 'Bestätigungstext stimmt nicht.';
        } else {
            $count = (int)$db->query("SELECT COUNT(*) FROM access_codes ac WHERE NOT EXISTS (SELECT 1 FROM entries e WHERE e.access_code_id = ac.id)")->fetchColumn();
            $db->exec("DELETE FROM access_codes WHERE id IN (
                SELECT ac.id FROM access_codes ac
                WHERE NOT EXISTS (SELECT 1 FROM entries e WHERE e.access_code_id = ac.id)
            )");
            logEvent($db, 'unused_codes_deleted', $count . ' unbenutzte Codes gelöscht.');
            $message = $count . ' unbenutzte Code(s) wurden gelöscht.';
        }
    }
}

$baseUrl = appPublicBaseUrl($db);
$limit = limitForList('limit', 10);
$totalRows = (int)$db->query("SELECT COUNT(*) FROM access_codes")->fetchColumn();
$rows = $db->query("SELECT ac.*, e.id AS entry_id, e.name AS entry_name, e.status AS entry_status
    FROM access_codes ac
    LEFT JOIN entries e ON e.access_code_id = ac.id
    ORDER BY ac.created_at DESC, ac.id DESC
    LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC) ?: [];

?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="<?= h(adminViewportContent()) ?>">
    <title>Codes verwalten - <?= h(appTitle($appConfig)) ?></title>
    <style>
        :root { --primary: #b00020; --border: #ddd; --bg: #f5f5f5; --ok: #dff3e4; --bad: #f8d7da; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: var(--bg); color: #222; line-height: 1.45; }
        .wrap { width: min(1680px, calc(100% - 28px)); margin: 0 auto; padding: 20px 0 40px; }
        .card { background: white; border: 1px solid var(--border); border-radius: 14px; padding: 16px; margin-bottom: 16px; box-shadow: 0 1px 8px rgba(0,0,0,.04); }
        h1, h2 { margin-top: 0; }
        textarea, input[type="text"] { width: 100%; border: 1px solid #bbb; border-radius: 10px; padding: 10px; font-size: 1rem; }
        textarea { min-height: 130px; resize: vertical; }
        .btn, button { display: inline-block; border: 0; border-radius: 10px; padding: 10px 13px; background: var(--primary); color: white; font-weight: bold; text-decoration: none; cursor: pointer; margin-top: 10px; }
        .btn.secondary, button.secondary { background: #555; }
        .btn.danger, button.danger { background: #8b0000; }
        .notice { padding: 12px; border-radius: 10px; margin-bottom: 14px; }
        .success { background: var(--ok); border: 1px solid #9ed3a8; }
        .error { background: var(--bad); border: 1px solid #e2a0a0; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 9px 8px; border-bottom: 1px solid #e6e6e6; text-align: left; vertical-align: top; }
        th { background: #fafafa; }
        code { background: #f3f3f3; padding: 2px 5px; border-radius: 4px; }
        .muted { color: #666; font-size: .92rem; }
        .mailbox { background: #fafafa; border: 1px dashed #bbb; border-radius: 10px; padding: 10px; white-space: pre-wrap; font-family: Arial, sans-serif; }
        .admin-nav { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 12px; }
        .admin-nav a { display: inline-block; border-radius: 999px; padding: 9px 12px; background: #eee; color: #222; font-weight: bold; text-decoration: none; }
        .admin-nav a.active { background: var(--primary); color: white; }
        .admin-nav a.right { margin-left: auto; }
        .limit-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 10px; }
        .limit-form select { padding: 7px; border-radius: 8px; border: 1px solid #bbb; }
        button.compact { margin-top: 0; padding: 7px 10px; }
    </style>
</head>
<body class="<?= h(adminBodyClass()) ?>">
<div class="wrap">
    <div class="card">
        <h1>Codes verwalten</h1>
        <?php adminNav('codes'); ?>
    </div>

    <?php if ($message !== ''): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

    <?php if (!empty($skippedEmails)): ?>
        <div class="notice error">
            <strong>Übersprungen:</strong><br>
            <?= h(implode(', ', $skippedEmails)) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Neue Codes erzeugen</h2>
        <form method="post">
                <?= csrfField() ?>
            <input type="hidden" name="action" value="create_codes">
            <label for="emails">E-Mail-Adressen einzeln, zeilenweise oder kommagetrennt einfügen</label><br>
            <textarea id="emails" name="emails" maxlength="20000" placeholder="max@example.de, muster@example.de"></textarea>
            <button type="submit">Codes erzeugen</button>
        </form>
    </div>

    <?php if (!empty($createdCodes)): ?>
        <div class="card">
            <h2>Gerade erzeugte Codes</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>E-Mail</th><th>Code</th><th>Direktlink</th></tr></thead>
                    <tbody>
                    <?php foreach ($createdCodes as $row): ?>
                        <?php $link = $baseUrl . '/index.php?code=' . urlencode($row['code']); ?>
                        <tr>
                            <td><?= h($row['email']) ?></td>
                            <td><code><?= h($row['code']) ?></code></td>
                            <td><?= h($link) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Alle Codes</h2>
        <p class="muted">Anzeige: <?= count($rows) ?> von <?= $totalRows ?> Codes. Standardmäßig werden 10 angezeigt.</p>
        <?php renderLimitSelect('limit', $limit); ?>
        <?php if (empty($rows)): ?>
            <p class="muted">Noch keine Codes vorhanden.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>E-Mail</th>
                        <th>Code</th>
                        <th>Status</th>
                        <th>Direktlink</th>
                        <th>Aktion</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                            $code = (string)$row['code'];
                            $link = $baseUrl . '/index.php?code=' . urlencode($code);
                            $used = !empty($row['entry_id']);
                        ?>
                        <tr>
                            <td><?= h((string)$row['email']) ?></td>
                            <td><code><?= h($code) ?></code></td>
                            <td>
                                <?= $used ? 'verwendet' : 'offen' ?><br>
                                <?php if ($used): ?><span class="muted"><?= h((string)$row['entry_name']) ?> · <?= $row['entry_status'] === 'help' ? 'hilft' : 'keine Zeit' ?></span><?php endif; ?>
                            </td>
                            <td>
                                <strong>Link:</strong><br>
                                <span class="muted"><?= h($link) ?></span>
                            </td>
                            <td>
                                <?php if (!$used): ?>
                                    <form method="post" onsubmit="return confirm('Diesen unbenutzten Code wirklich löschen?');">
                <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_one">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <button class="danger" type="submit">Löschen</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">nicht löschbar, solange verwendet</span>
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
        <h2>Alle unbenutzten Codes löschen</h2>
        <p>Damit werden nur Codes gelöscht, zu denen noch keine Rückmeldung gespeichert wurde.</p>
        <form method="post" onsubmit="return confirm('Wirklich alle unbenutzten Codes löschen?');">
                <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_unused">
            <label for="confirm">Zur Bestätigung eingeben: <code>UNBENUTZTE CODES LOESCHEN</code></label><br>
            <input type="text" id="confirm" name="confirm">
            <button class="danger" type="submit">Unbenutzte Codes löschen</button>
        </form>
    </div>
</div>
</body>
</html>
