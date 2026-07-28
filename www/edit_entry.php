<?php

require __DIR__ . '/auth.php';

$db = new PDO('sqlite:' . __DIR__ . '/../data/helferliste.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/shift_helpers.php';
$appConfig = appConfig($db);

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

function normalizeIds(array $ids): array {
    $clean = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $clean[$id] = $id;
        }
    }
    return array_values($clean);
}

// Die ID kann aus GET oder POST kommen, je nachdem ob die Seite geöffnet oder gespeichert wird.
$entryId = (int)($_GET['id'] ?? $_POST['entry_id'] ?? 0);

if ($entryId <= 0) {
    http_response_code(400);
    echo "Ungültiger Eintrag.";
    exit;
}

$error = '';

// Beim Speichern werden Name, Status, Hinweise und Schichtzuordnungen gemeinsam aktualisiert.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = limitText((string)($_POST['name'] ?? ''), 120);
    $status = (string)($_POST['status'] ?? '');
    $note = limitText((string)($_POST['note'] ?? ''), 1000);
    $selectedShifts = normalizeIds((array)($_POST['shifts'] ?? []));
    $selectedSpringerShifts = normalizeIds((array)($_POST['springer_shifts'] ?? []));

    if ($name === '') {
        $error = 'Bitte gib einen Namen ein.';
    } elseif (!in_array($status, ['help', 'no_time'], true)) {
        $error = 'Bitte wähle einen gültigen Status.';
    } elseif ($status === 'help' && empty($selectedShifts) && empty($selectedSpringerShifts)) {
        $error = 'Bei „Hilft“ muss mindestens eine normale Schicht oder Springer-Schicht ausgewählt sein.';
    } else {
        try {
            $db->exec('BEGIN IMMEDIATE TRANSACTION');

                        // Für Hilfszusagen wird geprüft, ob die gewählten Schichten weiterhin Kapazität haben.
if ($status === 'help') {
                foreach ($selectedShifts as $shiftId) {
                    $stmt = $db->prepare("
                        SELECT
                            s.max_slots,
                            COUNT(es.entry_id) AS used_slots
                        FROM shifts s
                        LEFT JOIN entry_shifts es
                            ON es.shift_id = s.id
                            AND es.entry_id != :entry_id
                        WHERE s.id = :id AND s.active = 1
                        GROUP BY s.id
                    ");
                    $stmt->execute([
                        ':id' => (int)$shiftId,
                        ':entry_id' => $entryId,
                    ]);
                    $shift = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$shift) {
                        throw new RuntimeException('Eine ausgewählte Schicht existiert nicht mehr.');
                    }

                    if ((int)$shift['used_slots'] >= (int)$shift['max_slots']) {
                        throw new RuntimeException('Eine ausgewählte normale Schicht ist voll.');
                    }
                }

                foreach ($selectedSpringerShifts as $springerShiftId) {
                    $stmt = $db->prepare("
                        SELECT
                            s.max_slots,
                            COUNT(es.entry_id) AS used_slots
                        FROM springer_shifts s
                        LEFT JOIN entry_springer_shifts es
                            ON es.springer_shift_id = s.id
                            AND es.entry_id != :entry_id
                        WHERE s.id = :id AND s.active = 1
                        GROUP BY s.id
                    ");
                    $stmt->execute([
                        ':id' => (int)$springerShiftId,
                        ':entry_id' => $entryId,
                    ]);
                    $shift = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$shift) {
                        throw new RuntimeException('Eine ausgewählte Springer-Schicht existiert nicht mehr.');
                    }

                    if ((int)$shift['used_slots'] >= (int)$shift['max_slots']) {
                        throw new RuntimeException('Eine ausgewählte Springer-Schicht ist voll.');
                    }
                }
            }

            $stmt = $db->prepare("
                UPDATE entries
                SET name = :name,
                    status = :status,
                    note = :note
                WHERE id = :id
            ");
            $stmt->execute([
                ':name' => $name,
                ':status' => $status,
                ':note' => $note,
                ':id' => $entryId,
            ]);

                        // Zuordnungen werden zuerst geleert und danach neu geschrieben. Das ist einfacher und sauber nachvollziehbar.
$stmt = $db->prepare("DELETE FROM entry_shifts WHERE entry_id = :entry_id");
            $stmt->execute([':entry_id' => $entryId]);

            $stmt = $db->prepare("DELETE FROM entry_springer_shifts WHERE entry_id = :entry_id");
            $stmt->execute([':entry_id' => $entryId]);

            if ($status === 'help') {
                $stmt = $db->prepare("
                    INSERT INTO entry_shifts (entry_id, shift_id)
                    VALUES (:entry_id, :shift_id)
                ");

                foreach ($selectedShifts as $shiftId) {
                    $stmt->execute([
                        ':entry_id' => $entryId,
                        ':shift_id' => (int)$shiftId,
                    ]);
                }

                $stmt = $db->prepare("
                    INSERT INTO entry_springer_shifts (entry_id, springer_shift_id)
                    VALUES (:entry_id, :springer_shift_id)
                ");

                foreach ($selectedSpringerShifts as $springerShiftId) {
                    $stmt->execute([
                        ':entry_id' => $entryId,
                        ':springer_shift_id' => (int)$springerShiftId,
                    ]);
                }
            }

            $db->commit();

            header('Location: admin.php');
            exit;

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

$stmt = $db->prepare("SELECT * FROM entries WHERE id = :id");
$stmt->execute([':id' => $entryId]);
$entry = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$entry) {
    http_response_code(404);
    echo "Eintrag nicht gefunden.";
    exit;
}

$shifts = $db->query("
    SELECT
        s.*,
        COUNT(es.entry_id) AS used_slots
    FROM shifts s
    LEFT JOIN entry_shifts es ON es.shift_id = s.id
    WHERE s.active = 1
    GROUP BY s.id
    ORDER BY s.sort_order ASC, s.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$springerShifts = $db->query("
    SELECT
        s.*,
        COUNT(es.entry_id) AS used_slots
    FROM springer_shifts s
    LEFT JOIN entry_springer_shifts es ON es.springer_shift_id = s.id
    WHERE s.active = 1
    GROUP BY s.id
    ORDER BY s.sort_order ASC, s.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare("SELECT shift_id FROM entry_shifts WHERE entry_id = :entry_id");
$stmt->execute([':entry_id' => $entryId]);
$currentShifts = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

$stmt = $db->prepare("SELECT springer_shift_id FROM entry_springer_shifts WHERE entry_id = :entry_id");
$stmt->execute([':entry_id' => $entryId]);
$currentSpringerShifts = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Eintrag bearbeiten - <?= h(appTitle($appConfig)) ?></title>
    <meta name="viewport" content="<?= h(adminViewportContent()) ?>">
    <style>
        body {
            margin: 0;
            font-family: system-ui, sans-serif;
            background: #f2f4f7;
            color: #1f2937;
        }

        .container {
            width: min(1680px, calc(100% - 28px));
            margin: 0 auto;
            padding: 16px 0 40px;
        }

        .card {
            background: white;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }

        label {
            display: block;
            font-weight: 700;
            margin-top: 18px;
            margin-bottom: 8px;
        }

        input[type="text"],
        textarea {
            width: 100%;
            padding: 14px;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            font-size: 16px;
        }

        .choice,
        .shift {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 14px;
            border: 1px solid #d1d5db;
            border-radius: 14px;
            background: #f9fafb;
            margin-top: 10px;
        }

        button,
        .button {
            display: inline-block;
            margin-top: 24px;
            padding: 14px 18px;
            border: 0;
            border-radius: 12px;
            background: #b91c1c;
            color: white;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
        }

        .button.secondary {
            background: #374151;
        }

        .error {
            padding: 14px;
            border-radius: 12px;
            background: #fee2e2;
            color: #991b1b;
            font-weight: 700;
            margin-bottom: 18px;
        }

        .hidden {
            display: none;
        }

        .small {
            color: #6b7280;
            font-size: 14px;
        }

        .admin-nav { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 12px 0 18px; }
        .admin-nav a { display: inline-block; border-radius: 999px; padding: 9px 12px; background: #eee; color: #222; font-weight: bold; text-decoration: none; }
        .admin-nav a.active { background: #b91c1c; color: white; }
        .admin-nav a.right { margin-left: auto; }
    </style>
</head>
<body class="<?= h(adminBodyClass()) ?>">

<main class="container">
    <div class="card">
        <h1>Eintrag bearbeiten</h1>
        <?php adminNav('admin'); ?>

        <?php if ($error !== ''): ?>
            <div class="error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post">
                <?= csrfField() ?>
            <input type="hidden" name="entry_id" value="<?= (int)$entryId ?>">

            <label for="name">Name</label>
            <input type="text" id="name" name="name" maxlength="120" required value="<?= h($entry['name']) ?>">

            <label>Status</label>

            <label class="choice">
                <input type="radio" name="status" value="help" <?= $entry['status'] === 'help' ? 'checked' : '' ?>>
                Hilft
            </label>

            <label class="choice">
                <input type="radio" name="status" value="no_time" <?= $entry['status'] === 'no_time' ? 'checked' : '' ?>>
                Keine Zeit
            </label>

            <div id="shiftArea" class="<?= $entry['status'] === 'help' ? '' : 'hidden' ?>">
                <h2><?= h(appText($appConfig, 'text_normal_shifts_heading')) ?></h2>

                <?php foreach ($shifts as $shift): ?>
                    <?php $checked = in_array((int)$shift['id'], $currentShifts, true); ?>
                    <label class="shift">
                        <input
                            type="checkbox"
                            name="shifts[]"
                            value="<?= (int)$shift['id'] ?>"
                            <?= $checked ? 'checked' : '' ?>
                        >
                        <span>
                            <strong><?= h($shift['title']) ?></strong><br>
                            <?php if (shiftScheduleText($shift) !== ''): ?><span class="small"><?= h(shiftScheduleText($shift)) ?></span><br><?php endif; ?>
                            <?php if (trim((string)($shift['note'] ?? '')) !== ''): ?><span class="small"><?= nl2br(h((string)$shift['note'])) ?></span><br><?php endif; ?>
                            <span class="small"><?= (int)$shift['used_slots'] ?> / <?= (int)$shift['max_slots'] ?> belegt</span>
                        </span>
                    </label>
                <?php endforeach; ?>

                <h2><?= h(appText($appConfig, 'text_flexible_shifts_heading')) ?></h2>

                <?php foreach ($springerShifts as $shift): ?>
                    <?php $checked = in_array((int)$shift['id'], $currentSpringerShifts, true); ?>
                    <label class="shift">
                        <input
                            type="checkbox"
                            name="springer_shifts[]"
                            value="<?= (int)$shift['id'] ?>"
                            <?= $checked ? 'checked' : '' ?>
                        >
                        <span>
                            <strong><?= h($shift['title']) ?></strong><br>
                            <?php if (shiftScheduleText($shift) !== ''): ?><span class="small"><?= h(shiftScheduleText($shift)) ?></span><br><?php endif; ?>
                            <?php if (trim((string)($shift['note'] ?? '')) !== ''): ?><span class="small"><?= nl2br(h((string)$shift['note'])) ?></span><br><?php endif; ?>
                            <span class="small"><?= (int)$shift['used_slots'] ?> / <?= (int)$shift['max_slots'] ?> Springer belegt</span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <label for="note">Hinweis</label>
            <textarea id="note" name="note" rows="4" maxlength="1000"><?= h($entry['note'] ?? '') ?></textarea>

            <button type="submit">Änderungen speichern</button>
            <a class="button secondary" href="admin.php">Abbrechen</a>
        </form>
    </div>
</main>

<script>
const radios = document.querySelectorAll('input[name="status"]');
const shiftArea = document.getElementById('shiftArea');

radios.forEach(radio => {
    radio.addEventListener('change', () => {
        if (radio.value === 'help' && radio.checked) {
            shiftArea.classList.remove('hidden');
        }

        if (radio.value === 'no_time' && radio.checked) {
            shiftArea.classList.add('hidden');

            document.querySelectorAll('input[name="shifts[]"]').forEach(cb => {
                cb.checked = false;
            });

            document.querySelectorAll('input[name="springer_shifts[]"]').forEach(cb => {
                cb.checked = false;
            });
        }
    });
});
</script>

</body>
</html>
