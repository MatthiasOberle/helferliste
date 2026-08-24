<?php

declare(strict_types=1);

require __DIR__ . '/auth.php';

$db = new PDO('sqlite:' . __DIR__ . '/../data/helferliste.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/tracking.php';
require_once __DIR__ . '/contact_import_lib.php';
$appConfig = appConfig($db);

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function countLabel(int $count, string $singular, string $plural): string {
    return $count . ' ' . ($count === 1 ? $singular : $plural);
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
$importPreview = null;

if (isset($_SESSION['contact_import_preview'])
    && time() - (int)($_SESSION['contact_import_preview']['created_at'] ?? 0) > CONTACT_IMPORT_SESSION_TTL
) {
    unset($_SESSION['contact_import_preview']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Aus der eingefügten E-Mail-Liste werden gültige, neue Codes erzeugt.
    if ($action === 'create_codes') {
        $emails = normalizeEmails((string)($_POST['emails'] ?? ''));

        if (empty($emails)) {
            $error = 'Bitte mindestens eine E-Mail-Adresse eingeben.';
        } else {
            try {
                $result = createAccessCodes($db, $emails);
                $createdCodes = $result['created'];
                $skippedEmails = $result['skipped'];
                if ($createdCodes !== []) {
                    logEvent($db, 'codes_created', count($createdCodes) . ' Codes erzeugt.');
                    $message = countLabel(count($createdCodes), 'Code wurde', 'Codes wurden') . ' erzeugt.';
                } elseif ($skippedEmails === []) {
                    $error = 'Es wurden keine Codes erzeugt.';
                }
            } catch (Throwable) {
                $error = 'Die Codes konnten nicht sicher erzeugt werden. Es wurde nichts gespeichert.';
            }
        }
    }

    if ($action === 'preview_contact_import') {
        try {
            $importPreview = contactImportPreviewUpload($_FILES['contacts_csv'] ?? null, $db);
            $previewToken = bin2hex(random_bytes(24));
            $_SESSION['contact_import_preview'] = [
                'token' => $previewToken,
                'created_at' => time(),
                'filename' => $importPreview['filename'],
                'emails' => $importPreview['ready_emails'],
            ];
        } catch (Throwable $exception) {
            unset($_SESSION['contact_import_preview']);
            $error = $exception instanceof InvalidArgumentException
                ? $exception->getMessage()
                : 'Die CSV-Datei konnte nicht sicher geprüft werden.';
        }
    }

    if ($action === 'confirm_contact_import') {
        $storedPreview = $_SESSION['contact_import_preview'] ?? null;
        $submittedToken = (string)($_POST['import_token'] ?? '');
        $storedToken = is_array($storedPreview) ? (string)($storedPreview['token'] ?? '') : '';
        $createdAt = is_array($storedPreview) ? (int)($storedPreview['created_at'] ?? 0) : 0;

        if ($storedToken === ''
            || $submittedToken === ''
            || !hash_equals($storedToken, $submittedToken)
            || time() - $createdAt > CONTACT_IMPORT_SESSION_TTL
        ) {
            unset($_SESSION['contact_import_preview']);
            $error = 'Die Importvorschau ist abgelaufen. Bitte die CSV-Datei erneut auswählen.';
        } else {
            $emails = array_values(array_filter(
                (array)($storedPreview['emails'] ?? []),
                static fn(mixed $email): bool => is_string($email)
            ));
            $filename = basename((string)($storedPreview['filename'] ?? 'kontakte.csv'));
            unset($_SESSION['contact_import_preview']);

            try {
                $result = createAccessCodes($db, $emails);
                $createdCodes = $result['created'];
                $skippedEmails = $result['skipped'];
                if ($createdCodes !== []) {
                    logEvent($db, 'contacts_imported', count($createdCodes) . ' Kontakte aus ' . $filename . ' importiert.');
                    $message = countLabel(count($createdCodes), 'Kontakt wurde', 'Kontakte wurden') . ' importiert und ' . (count($createdCodes) === 1 ? 'hat' : 'haben') . ' einen Zugangscode erhalten.';
                } else {
                    $error = 'Aus der Vorschau konnten keine neuen Kontakte importiert werden.';
                }
            } catch (Throwable) {
                $error = 'Der CSV-Import konnte nicht sicher abgeschlossen werden. Es wurde nichts gespeichert.';
            }
        }
    }

    if ($action === 'cancel_contact_import') {
        unset($_SESSION['contact_import_preview']);
        $message = 'Die Importvorschau wurde verworfen.';
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
        textarea, input[type="text"], input[type="file"] { width: 100%; border: 1px solid #bbb; border-radius: 10px; padding: 10px; font-size: 1rem; }
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
        .import-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 9px; margin: 14px 0; }
        .import-stat { border: 1px solid var(--border); border-radius: 10px; padding: 10px; background: #fafafa; }
        .import-stat strong { display: block; font-size: 1.45rem; }
        .status-ready { color: #166534; font-weight: bold; }
        .status-skip { color: #8b0000; font-weight: bold; }
        .button-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        .button-row form { margin: 0; }
    </style>
</head>
<body class="<?= h(adminBodyClass()) ?>">
<div class="wrap">
    <div class="card">
        <h1>Codes verwalten</h1>
        <?php adminNav('codes'); ?>
    </div>

    <div class="card">
        <h2>Einladungen vorbereiten</h2>
        <p>Persönliche Einladungs- und Erinnerungstexte, Direktlinks und QR-Codes stehen im eigenen Arbeitsbereich bereit.</p>
        <a class="btn secondary" href="invitations.php">Einladen & erinnern öffnen</a>
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

    <div class="card">
        <h2>Kontakte aus CSV importieren</h2>
        <p>Die Datei braucht eine Kopfzeile mit einer Spalte <strong>E-Mail</strong>. Komma, Semikolon und Tabulator werden automatisch erkannt.</p>
        <p class="muted">Andere Spalten werden nicht gespeichert. Die Datei darf höchstens 1.000 Kontakte und 2 MB enthalten und wird nach der Vorschau nicht dauerhaft abgelegt.</p>
        <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="preview_contact_import">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= CONTACT_IMPORT_MAX_BYTES ?>">
            <label for="contacts_csv">CSV-Datei auswählen</label><br>
            <input id="contacts_csv" name="contacts_csv" type="file" accept=".csv,.txt,text/csv,text/plain" required>
            <button type="submit">Datei prüfen</button>
        </form>
    </div>

    <?php if (is_array($importPreview)): ?>
        <?php $importCounts = $importPreview['counts']; ?>
        <div class="card" id="import-vorschau">
            <h2>Importvorschau</h2>
            <p><strong><?= h((string)$importPreview['filename']) ?></strong> · Trennzeichen: <?= h((string)$importPreview['delimiter']) ?></p>
            <?php if ($importPreview['ignored_headers'] !== []): ?>
                <p class="muted">Diese Spalten werden bewusst nicht übernommen: <?= h(implode(', ', $importPreview['ignored_headers'])) ?></p>
            <?php endif; ?>
            <div class="import-stats">
                <div class="import-stat"><strong><?= (int)$importCounts['total'] ?></strong>Zeilen</div>
                <div class="import-stat"><strong><?= (int)$importCounts['ready'] ?></strong>für Import bereit</div>
                <div class="import-stat"><strong><?= (int)$importCounts['existing'] ?></strong>bereits vorhanden</div>
                <div class="import-stat"><strong><?= (int)$importCounts['duplicate'] ?></strong>doppelt</div>
                <div class="import-stat"><strong><?= (int)$importCounts['invalid'] + (int)$importCounts['empty'] ?></strong>ungültig oder leer</div>
            </div>

            <?php if ($importPreview['rows'] !== []): ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Zeile</th><th>E-Mail</th><th>Ergebnis</th></tr></thead>
                        <tbody>
                        <?php foreach ($importPreview['rows'] as $row): ?>
                            <tr>
                                <td><?= (int)$row['line'] ?></td>
                                <td><?= h($row['email'] !== '' ? (string)$row['email'] : '—') ?></td>
                                <td class="<?= $row['status'] === 'ready' ? 'status-ready' : 'status-skip' ?>"><?= h((string)$row['reason']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($importPreview['truncated_preview']): ?>
                    <p class="muted">Angezeigt werden die ersten <?= CONTACT_IMPORT_PREVIEW_ROWS ?> Datenzeilen. Die Summen beziehen sich auf die vollständige Datei.</p>
                <?php endif; ?>
            <?php endif; ?>

            <div class="button-row">
                <?php if ((int)$importCounts['ready'] > 0): ?>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="confirm_contact_import">
                        <input type="hidden" name="import_token" value="<?= h((string)($_SESSION['contact_import_preview']['token'] ?? '')) ?>">
                        <button type="submit"><?= h(countLabel((int)$importCounts['ready'], 'Kontakt', 'Kontakte')) ?> jetzt importieren</button>
                    </form>
                <?php endif; ?>
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="cancel_contact_import">
                    <button class="secondary" type="submit">Vorschau verwerfen</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

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
