<?php

require __DIR__ . '/auth.php';

$db = new PDO('sqlite:' . __DIR__ . '/../data/helferliste.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once __DIR__ . '/app_config.php';
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

$message = '';
$error = '';

// Alle Änderungen an Schichten laufen über diese POST-Verarbeitung.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update';
    $type = $_POST['type'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $title = limitText((string)($_POST['title'] ?? ''), 160);
    $maxSlots = (int)($_POST['max_slots'] ?? 0);
    $sortOrder = (int)($_POST['sort_order'] ?? 0);

    if (!in_array($type, ['normal', 'springer'], true)) {
        $error = 'Ungültiger Schichttyp.';
    } else {
        try {
                        // Bestehende Schicht aktualisieren: Titel, maximale Plätze und Reihenfolge.
if ($action === 'update') {
                if ($id <= 0) {
                    throw new RuntimeException('Ungültige Schicht.');
                }

                if ($title === '') {
                    throw new RuntimeException('Der Titel darf nicht leer sein.');
                }

                if ($maxSlots <= 0) {
                    throw new RuntimeException('Die maximale Anzahl muss mindestens 1 sein.');
                }

                if ($sortOrder <= 0) {
                    throw new RuntimeException('Die Reihenfolge muss mindestens 1 sein.');
                }

                if ($type === 'normal') {
                    $stmt = $db->prepare("
                        UPDATE shifts
                        SET title = :title,
                            max_slots = :max_slots,
                            sort_order = :sort_order
                        WHERE id = :id
                    ");
                } else {
                    $stmt = $db->prepare("
                        UPDATE springer_shifts
                        SET title = :title,
                            max_slots = :max_slots,
                            sort_order = :sort_order
                        WHERE id = :id
                    ");
                }

                $stmt->execute([
                    ':title' => $title,
                    ':max_slots' => $maxSlots,
                    ':sort_order' => $sortOrder,
                    ':id' => $id,
                ]);

                $message = 'Schicht wurde gespeichert.';
            }

                        // Neue Schichten werden automatisch ans Ende der aktuellen Sortierung gehängt.
if ($action === 'create') {
                if ($title === '') {
                    throw new RuntimeException('Der Titel darf nicht leer sein.');
                }

                if ($maxSlots <= 0) {
                    throw new RuntimeException('Die maximale Anzahl muss mindestens 1 sein.');
                }

                if ($type === 'normal') {
                    $newSortOrder = (int)$db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM shifts")->fetchColumn();

                    $stmt = $db->prepare("
                        INSERT INTO shifts (title, max_slots, sort_order, active)
                        VALUES (:title, :max_slots, :sort_order, 1)
                    ");
                } else {
                    $newSortOrder = (int)$db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM springer_shifts")->fetchColumn();

                    $stmt = $db->prepare("
                        INSERT INTO springer_shifts (title, max_slots, sort_order, active)
                        VALUES (:title, :max_slots, :sort_order, 1)
                    ");
                }

                $stmt->execute([
                    ':title' => $title,
                    ':max_slots' => $maxSlots,
                    ':sort_order' => $newSortOrder,
                ]);

                $message = 'Neue Schicht wurde angelegt.';
            }

                        // Schichten werden deaktiviert statt hart gelöscht, damit alte Einträge lesbar bleiben.
if ($action === 'deactivate') {
                if ($id <= 0) {
                    throw new RuntimeException('Ungültige Schicht.');
                }

                if ($type === 'normal') {
                    $stmt = $db->prepare("UPDATE shifts SET active = 0 WHERE id = :id");
                } else {
                    $stmt = $db->prepare("UPDATE springer_shifts SET active = 0 WHERE id = :id");
                }

                $stmt->execute([':id' => $id]);

                $message = 'Schicht wurde deaktiviert.';
            }

        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$normalShifts = $db->query("
    SELECT
        s.id,
        s.title,
        s.max_slots,
        s.sort_order,
        COUNT(es.entry_id) AS used_slots
    FROM shifts s
    LEFT JOIN entry_shifts es ON es.shift_id = s.id
    WHERE s.active = 1
    GROUP BY s.id
    ORDER BY s.sort_order ASC, s.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$springerShifts = $db->query("
    SELECT
        s.id,
        s.title,
        s.max_slots,
        s.sort_order,
        COUNT(es.entry_id) AS used_slots
    FROM springer_shifts s
    LEFT JOIN entry_springer_shifts es ON es.springer_shift_id = s.id
    WHERE s.active = 1
    GROUP BY s.id
    ORDER BY s.sort_order ASC, s.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Rendert die Formularzeilen für normale und Springer-Schichten.
function renderShiftEditor(array $shifts, string $type, string $slotsLabel): void {
    foreach ($shifts as $shift) {
        ?>
        <div class="shift-row">
            <form method="post" class="edit-form">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="type" value="<?= h($type) ?>">
                <input type="hidden" name="id" value="<?= (int)$shift['id'] ?>">

                <div class="field title-field">
                    <label>Titel</label>
                    <input type="text" name="title" maxlength="160" value="<?= h($shift['title']) ?>" required>
                </div>

                <div class="field used-field">
                    <label>Belegt</label>
                    <div class="used-box">
                        <?= (int)$shift['used_slots'] ?> von <?= (int)$shift['max_slots'] ?>
                    </div>
                </div>

                <div class="field slots-field">
                    <label><?= h($slotsLabel) ?></label>
                    <input type="number" name="max_slots" min="1" value="<?= (int)$shift['max_slots'] ?>" required>
                </div>

                <div class="field order-field">
                    <label>Reihenfolge</label>
                    <input type="number" name="sort_order" min="1" value="<?= (int)$shift['sort_order'] ?>" required>
                </div>

                <div class="field action-field">
                    <label>&nbsp;</label>
                    <button type="submit">Speichern</button>
                </div>
            </form>

            <form method="post" class="delete-form" onsubmit="return confirm('Diese Schicht wirklich deaktivieren? Sie verschwindet dann aus der öffentlichen Auswahl. Bestehende Einträge bleiben erhalten.');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="deactivate">
                <input type="hidden" name="type" value="<?= h($type) ?>">
                <input type="hidden" name="id" value="<?= (int)$shift['id'] ?>">
                <button type="submit" class="delete-button">Deaktivieren</button>
            </form>
        </div>
        <?php
    }
}

?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Schichten bearbeiten - <?= h(appTitle($appConfig)) ?></title>
    <meta name="viewport" content="<?= h(adminViewportContent()) ?>">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
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
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.08);
        }

        h1,
        h2 {
            margin-top: 0;
        }

        h2 {
            margin-bottom: 18px;
        }

        .button,
        button {
            display: inline-block;
            padding: 11px 16px;
            background: #b91c1c;
            color: white;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 700;
            border: 0;
            cursor: pointer;
            font-size: 14px;
            line-height: 1.2;
        }

        .button.secondary {
            background: #374151;
        }

        .admin-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            margin-top: 12px;
        }

        .admin-nav a {
            display: inline-block;
            border-radius: 999px;
            padding: 9px 12px;
            background: #e5e7eb;
            color: #1f2937;
            font-weight: 700;
            text-decoration: none;
            line-height: 1.2;
        }

        .admin-nav a.active {
            background: #b91c1c;
            color: white;
        }

        .admin-nav a.right {
            margin-left: auto;
        }

        .delete-button {
            background: #6b7280;
            width: 100%;
        }

        .delete-button:hover {
            background: #4b5563;
        }

        .success {
            padding: 12px;
            border-radius: 10px;
            background: #dcfce7;
            color: #166534;
            font-weight: 700;
            margin-top: 16px;
        }

        .error {
            padding: 12px;
            border-radius: 10px;
            background: #fee2e2;
            color: #991b1b;
            font-weight: 700;
            margin-top: 16px;
        }

        .create-form {
            display: grid;
            grid-template-columns: minmax(260px, 1fr) 150px 230px;
            gap: 14px;
            align-items: end;
            padding: 0 0 20px 0;
            margin-bottom: 14px;
            border-bottom: 2px solid #e5e7eb;
        }

        .shift-row {
            display: grid;
            grid-template-columns: 1fr 140px;
            gap: 12px;
            align-items: end;
            padding: 14px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .shift-row:last-child {
            border-bottom: 0;
        }

        .edit-form {
            display: grid;
            grid-template-columns: minmax(260px, 1fr) 130px 130px 120px 130px;
            gap: 14px;
            align-items: end;
        }

        .delete-form {
            align-self: end;
        }

        .field label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #4b5563;
            margin-bottom: 6px;
        }

        input[type="text"],
        input[type="number"] {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            font-size: 15px;
            background: white;
        }

        .used-box {
            min-height: 42px;
            display: flex;
            align-items: center;
            padding: 11px 12px;
            border-radius: 10px;
            background: #f9fafb;
            color: #374151;
            font-size: 14px;
            white-space: nowrap;
        }

        .action-field button {
            width: 100%;
        }

        @media (max-width: 1050px) {
            .shift-row {
                grid-template-columns: 1fr;
                gap: 10px;
                padding: 16px;
                margin-bottom: 14px;
                border: 1px solid #e5e7eb;
                border-radius: 14px;
                background: #ffffff;
            }

            .edit-form {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .create-form {
                grid-template-columns: 1fr;
                padding: 16px;
                border: 1px solid #e5e7eb;
                border-radius: 14px;
                background: #f9fafb;
            }

            .field label {
                margin-top: 0;
            }

            .used-box {
                white-space: normal;
            }

            .action-field label {
                display: none;
            }

            .action-field button,
            .delete-button {
                width: 100%;
                margin-top: 4px;
            }
        }
    </style>
</head>
<body class="<?= h(adminBodyClass()) ?>">

<div class="container">
    <div class="card">
        <h1>Schichten bearbeiten</h1>

        <?php adminNav('shifts'); ?>

        <?php if ($message !== ''): ?>
            <div class="success"><?= h($message) ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="error"><?= h($error) ?></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Normale Schichten</h2>

        <form method="post" class="create-form">
                <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="type" value="normal">

            <div class="field">
                <label>Neue normale Schicht</label>
                <input type="text" name="title" maxlength="160" placeholder="z. B. Samstag 12:00 – 15:00 Uhr">
            </div>

            <div class="field">
                <label>Max. Plätze</label>
                <input type="number" name="max_slots" min="1" value="10">
            </div>

            <div class="field">
                <label>&nbsp;</label>
                <button type="submit">Neue Schicht anlegen</button>
            </div>
        </form>

        <?php if (empty($normalShifts)): ?>
            <p>Keine aktiven normalen Schichten vorhanden.</p>
        <?php else: ?>
            <?php renderShiftEditor($normalShifts, 'normal', 'Max. Plätze'); ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Springer-Schichten</h2>

        <form method="post" class="create-form">
                <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="type" value="springer">

            <div class="field">
                <label>Neue Springer-Schicht</label>
                <input type="text" name="title" maxlength="160" placeholder="z. B. Samstag 12:00 – 15:00 Uhr">
            </div>

            <div class="field">
                <label>Max. Springer</label>
                <input type="number" name="max_slots" min="1" value="5">
            </div>

            <div class="field">
                <label>&nbsp;</label>
                <button type="submit">Neue Springer-Schicht anlegen</button>
            </div>
        </form>

        <?php if (empty($springerShifts)): ?>
            <p>Keine aktiven Springer-Schichten vorhanden.</p>
        <?php else: ?>
            <?php renderShiftEditor($springerShifts, 'springer', 'Max. Springer'); ?>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
