<?php

declare(strict_types=1);

require __DIR__ . '/auth.php';

$db = new PDO('sqlite:' . __DIR__ . '/../data/helferliste.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');

require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/shift_helpers.php';
require_once __DIR__ . '/tracking.php';
$appConfig = appConfig($db);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function limitText(string $value, int $maxLength): string
{
    $value = trim($value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
}

function shiftTableForType(string $type): string
{
    return match ($type) {
        'normal' => 'shifts',
        'springer' => 'springer_shifts',
        default => throw new InvalidArgumentException('Ungültige Schichtart.'),
    };
}

function shiftLinkInfo(string $type): array
{
    return $type === 'normal'
        ? ['entry_shifts', 'shift_id']
        : ['entry_springer_shifts', 'springer_shift_id'];
}

function postedShiftValues(): array
{
    $title = limitText((string)($_POST['title'] ?? ''), 160);
    $date = normalizeOptionalDate((string)($_POST['shift_date'] ?? ''));
    $start = normalizeOptionalTime((string)($_POST['start_time'] ?? ''));
    $end = normalizeOptionalTime((string)($_POST['end_time'] ?? ''));
    validateTimeRange($start, $end);
    $location = limitText((string)($_POST['location'] ?? ''), 160);
    $note = limitText((string)($_POST['note'] ?? ''), 600);
    $maxSlots = (int)($_POST['max_slots'] ?? 0);
    $sortOrder = (int)($_POST['sort_order'] ?? 0);

    if ($title === '') {
        throw new InvalidArgumentException('Der Titel darf nicht leer sein.');
    }
    if ($maxSlots < 1 || $maxSlots > 9999) {
        throw new InvalidArgumentException('Die Kapazität muss zwischen 1 und 9999 liegen.');
    }

    return [
        'title' => $title,
        'shift_date' => $date,
        'start_time' => $start,
        'end_time' => $end,
        'location' => $location,
        'note' => $note,
        'max_slots' => $maxSlots,
        'sort_order' => $sortOrder,
    ];
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $type = (string)($_POST['type'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    try {
        $table = shiftTableForType($type);

        if ($action === 'create' || $action === 'update') {
            $values = postedShiftValues();
            if ($action === 'create') {
                $values['sort_order'] = (int)$db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM {$table}")->fetchColumn();
                $stmt = $db->prepare("INSERT INTO {$table}
                    (title, shift_date, start_time, end_time, location, note, max_slots, sort_order, active)
                    VALUES (:title, :shift_date, :start_time, :end_time, :location, :note, :max_slots, :sort_order, 1)");
                $stmt->execute($values);
                logEvent($db, 'shift_created', 'Neue Schicht angelegt: ' . $values['title']);
                $message = 'Neue Schicht wurde angelegt.';
            } else {
                if ($id <= 0 || $values['sort_order'] < 1) {
                    throw new InvalidArgumentException('Schicht und Reihenfolge müssen gültig sein.');
                }
                $values['id'] = $id;
                $stmt = $db->prepare("UPDATE {$table} SET
                    title = :title, shift_date = :shift_date, start_time = :start_time,
                    end_time = :end_time, location = :location, note = :note,
                    max_slots = :max_slots, sort_order = :sort_order WHERE id = :id");
                $stmt->execute($values);
                logEvent($db, 'shift_updated', 'Schicht #' . $id . ' wurde aktualisiert.');
                $message = 'Schicht wurde gespeichert.';
            }
        } elseif ($action === 'duplicate') {
            if ($id <= 0) {
                throw new InvalidArgumentException('Ungültige Schicht.');
            }
            $stmt = $db->prepare("SELECT * FROM {$table} WHERE id = ?");
            $stmt->execute([$id]);
            $source = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$source) {
                throw new RuntimeException('Die Schicht wurde nicht gefunden.');
            }
            $sortOrder = (int)$db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM {$table}")->fetchColumn();
            $insert = $db->prepare("INSERT INTO {$table}
                (title, shift_date, start_time, end_time, location, note, max_slots, sort_order, active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
            $insert->execute([
                limitText('Kopie von ' . (string)$source['title'], 160),
                $source['shift_date'], $source['start_time'], $source['end_time'],
                $source['location'], $source['note'], (int)$source['max_slots'], $sortOrder,
            ]);
            logEvent($db, 'shift_duplicated', 'Schicht #' . $id . ' wurde dupliziert.');
            $message = 'Schicht wurde dupliziert und ans Ende gesetzt.';
        } elseif ($action === 'deactivate') {
            if ($id <= 0) {
                throw new InvalidArgumentException('Ungültige Schicht.');
            }
            $stmt = $db->prepare("UPDATE {$table} SET active = 0 WHERE id = ?");
            $stmt->execute([$id]);
            logEvent($db, 'shift_deactivated', 'Schicht #' . $id . ' wurde deaktiviert.');
            $message = 'Schicht wurde deaktiviert.';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

function loadEditableShifts(PDO $db, string $type): array
{
    $table = shiftTableForType($type);
    [$linkTable, $linkColumn] = shiftLinkInfo($type);
    $sql = "SELECT s.*, COUNT(es.entry_id) AS used_slots
        FROM {$table} s
        LEFT JOIN {$linkTable} es ON es.{$linkColumn} = s.id
        WHERE s.active = 1
        GROUP BY s.id
        ORDER BY s.sort_order, s.id";
    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function renderShiftFields(array $shift, bool $includeOrder): void
{
    ?>
    <div class="field wide"><label>Titel</label><input type="text" name="title" maxlength="160" value="<?= h((string)($shift['title'] ?? '')) ?>" required></div>
    <div class="field"><label>Datum</label><input type="date" name="shift_date" value="<?= h((string)($shift['shift_date'] ?? '')) ?>"></div>
    <div class="field"><label>Beginn</label><input type="time" name="start_time" value="<?= h((string)($shift['start_time'] ?? '')) ?>"></div>
    <div class="field"><label>Ende</label><input type="time" name="end_time" value="<?= h((string)($shift['end_time'] ?? '')) ?>"></div>
    <div class="field"><label>Ort</label><input type="text" name="location" maxlength="160" value="<?= h((string)($shift['location'] ?? '')) ?>"></div>
    <div class="field"><label>Kapazität</label><input type="number" name="max_slots" min="1" max="9999" value="<?= (int)($shift['max_slots'] ?? 5) ?>" required></div>
    <?php if ($includeOrder): ?><div class="field"><label>Reihenfolge</label><input type="number" name="sort_order" min="1" value="<?= (int)($shift['sort_order'] ?? 1) ?>" required></div><?php endif; ?>
    <div class="field note"><label>Hinweis (öffentlich sichtbar)</label><textarea name="note" maxlength="600" rows="2"><?= h((string)($shift['note'] ?? '')) ?></textarea></div>
    <?php
}

function renderShiftSection(array $shifts, string $type, string $heading, int $defaultCapacity): void
{
    ?>
    <section class="card">
        <h2><?= h($heading) ?></h2>
        <p class="muted">Titel, Datum, Uhrzeit, Ort, Kapazität und Hinweis können unabhängig gepflegt werden.</p>
        <form method="post" class="shift-form create-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="type" value="<?= h($type) ?>">
            <?php renderShiftFields(['max_slots' => $defaultCapacity], false); ?>
            <div class="form-actions"><button type="submit">Neue Schicht anlegen</button></div>
        </form>

        <?php if ($shifts === []): ?>
            <p class="muted">Keine aktiven Schichten dieser Art vorhanden.</p>
        <?php endif; ?>

        <?php foreach ($shifts as $shift): ?>
            <article class="shift-item">
                <form method="post" class="shift-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="type" value="<?= h($type) ?>">
                    <input type="hidden" name="id" value="<?= (int)$shift['id'] ?>">
                    <?php renderShiftFields($shift, true); ?>
                    <div class="used"><strong><?= (int)$shift['used_slots'] ?> von <?= (int)$shift['max_slots'] ?></strong> Plätzen belegt</div>
                    <div class="form-actions"><button type="submit">Speichern</button></div>
                </form>
                <div class="secondary-actions">
                    <form method="post">
                        <?= csrfField() ?><input type="hidden" name="action" value="duplicate"><input type="hidden" name="type" value="<?= h($type) ?>"><input type="hidden" name="id" value="<?= (int)$shift['id'] ?>">
                        <button class="secondary" type="submit">Duplizieren</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Diese Schicht deaktivieren? Bestehende Zuordnungen bleiben erhalten.');">
                        <?= csrfField() ?><input type="hidden" name="action" value="deactivate"><input type="hidden" name="type" value="<?= h($type) ?>"><input type="hidden" name="id" value="<?= (int)$shift['id'] ?>">
                        <button class="danger" type="submit">Deaktivieren</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
    <?php
}

$normalShifts = loadEditableShifts($db, 'normal');
$flexibleShifts = loadEditableShifts($db, 'springer');

?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="<?= h(adminViewportContent()) ?>">
    <title>Schichten bearbeiten - <?= h(appTitle($appConfig)) ?></title>
    <style>
        :root { --primary:<?= h(appDesignColor($appConfig, 'primary_color')) ?>; --border:#d8dee8; --bg:#f2f4f7; --bad:#8b0000; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:system-ui,sans-serif; background:var(--bg); color:#1f2937; }
        .container { width:min(1680px,calc(100% - 28px)); margin:0 auto; padding:16px 0 40px; }
        .card { background:#fff; border:1px solid var(--border); border-radius:16px; padding:20px; margin-bottom:20px; box-shadow:0 6px 22px rgba(15,23,42,.07); }
        h1,h2 { margin-top:0; }
        .muted { color:#64748b; }
        .notice { padding:12px; border-radius:10px; margin:12px 0; font-weight:700; }
        .success { background:#dcfce7; color:#166534; }
        .error { background:#fee2e2; color:#991b1b; }
        .shift-item { border:1px solid var(--border); border-radius:14px; padding:15px; margin-top:14px; background:#fafbfc; }
        .shift-form { display:grid; grid-template-columns:repeat(6,minmax(120px,1fr)); gap:12px; align-items:end; }
        .create-form { border:1px dashed #9aa6b5; border-radius:14px; padding:15px; margin:14px 0 20px; background:#f8fafc; }
        .field.wide { grid-column:span 2; }
        .field.note { grid-column:span 3; }
        .field label { display:block; font-size:.82rem; font-weight:800; color:#475569; margin-bottom:5px; }
        input,textarea { width:100%; border:1px solid #b8c1cd; border-radius:9px; padding:10px; font:inherit; background:#fff; }
        textarea { resize:vertical; }
        button,.button { display:inline-block; border:0; border-radius:9px; padding:11px 14px; background:var(--primary); color:#fff; font-weight:750; text-decoration:none; cursor:pointer; }
        button.secondary { background:#475569; }
        button.danger { background:var(--bad); }
        .used { align-self:center; color:#475569; }
        .form-actions { align-self:end; }
        .form-actions button { width:100%; }
        .secondary-actions { display:flex; flex-wrap:wrap; gap:9px; justify-content:flex-end; margin-top:10px; }
        @media(max-width:1150px) { .shift-form { grid-template-columns:repeat(2,minmax(0,1fr)); } .field.wide,.field.note { grid-column:span 2; } }
        @media(max-width:680px) { .shift-form { grid-template-columns:1fr; } .field.wide,.field.note { grid-column:span 1; } .secondary-actions { display:grid; } .secondary-actions button { width:100%; } }
    </style>
</head>
<body class="<?= h(adminBodyClass()) ?>">
<main class="container">
    <div class="card">
        <h1>Schichten bearbeiten</h1>
        <p class="muted"><?= h(appEventDateRange($appConfig)) ?> · Status: <?= h(appEventStatusLabel($appConfig)) ?></p>
        <?php adminNav('shifts'); ?>
        <?php if ($message !== ''): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
    </div>
    <?php renderShiftSection($normalShifts, 'normal', appText($appConfig, 'text_normal_shifts_heading'), 10); ?>
    <?php renderShiftSection($flexibleShifts, 'springer', appText($appConfig, 'text_flexible_shifts_heading'), 5); ?>
</main>
</body>
</html>
