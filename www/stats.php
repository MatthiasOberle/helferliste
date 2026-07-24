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

// Prozentwert mit Schutz gegen Division durch null.
function pct(int $value, int $total): float {
    return $total > 0 ? round(($value / $total) * 100, 1) : 0.0;
}

function countAssignments(PDO $db, string $linkTable, string $column, int $shiftId): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM {$linkTable} WHERE {$column} = ?");
    $stmt->execute([$shiftId]);
    return (int)$stmt->fetchColumn();
}

$totalCodes = (int)$db->query("SELECT COUNT(*) FROM access_codes")->fetchColumn();
$totalEntries = (int)$db->query("SELECT COUNT(*) FROM entries")->fetchColumn();
$totalHelp = (int)$db->query("SELECT COUNT(*) FROM entries WHERE status = 'help'")->fetchColumn();
$totalNoTime = (int)$db->query("SELECT COUNT(*) FROM entries WHERE status = 'no_time'")->fetchColumn();
$totalNoResponse = max(0, $totalCodes - $totalEntries);

// Geräteauswertung zählt nur die öffentliche Hauptseite; Adminseiten bleiben außen vor.
$deviceRows = $db->query("SELECT device_type, COUNT(*) AS amount FROM visit_stats WHERE page IN ('start_logged_in', 'start_guest') GROUP BY device_type ORDER BY amount DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$totalVisits = 0;
foreach ($deviceRows as $row) {
    $totalVisits += (int)$row['amount'];
}

$normalShifts = $db->query("SELECT * FROM shifts WHERE active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$springerShifts = $db->query("SELECT * FROM springer_shifts WHERE active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

function occupancyClass(int $value, int $total): string {
    if ($total <= 0 || $value >= $total) {
        return 'full';
    }

    $ratio = $value / $total;
    if ($ratio >= 0.8) {
        return 'high';
    }
    if ($ratio >= 0.5) {
        return 'medium';
    }

    return 'low';
}

// Kleine HTML-Balkenanzeige für Quoten und Auslastungen.
function bar(string $label, int $value, int $total, string $class = ''): string {
    $percent = pct($value, $total);
    $classAttr = h($class);
    return '<div class="bar-row"><div class="bar-label"><span>' . h($label) . '</span><strong>' . $value . ' · ' . $percent . '%</strong></div><div class="bar"><span class="' . $classAttr . '" style="width:' . $percent . '%"></span></div></div>';
}

?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="<?= h(adminViewportContent()) ?>">
    <title>Statistik - <?= h(appTitle($appConfig)) ?></title>
    <style>
        :root { --primary: #b00020; --border: #ddd; --bg: #f5f5f5; --bar-low: <?= h(appDesignColor($appConfig, 'occupancy_color_low')) ?>; --bar-medium: <?= h(appDesignColor($appConfig, 'occupancy_color_medium')) ?>; --bar-high: <?= h(appDesignColor($appConfig, 'occupancy_color_high')) ?>; --bar-full: <?= h(appDesignColor($appConfig, 'occupancy_color_full')) ?>; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: var(--bg); color: #222; line-height: 1.45; }
        .wrap { width: min(1680px, calc(100% - 28px)); margin: 0 auto; padding: 20px 0 40px; }
        .admin-nav { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 12px; }
        .admin-nav a { display: inline-block; border-radius: 999px; padding: 9px 12px; background: #eee; color: #222; font-weight: bold; text-decoration: none; }
        .admin-nav a.active { background: var(--primary, #b00020); color: white; }
        .admin-nav a.right { margin-left: auto; }
        .card { background: white; border: 1px solid var(--border); border-radius: 14px; padding: 16px; margin-bottom: 16px; box-shadow: 0 1px 8px rgba(0,0,0,.04); }
        h1, h2 { margin-top: 0; }
        .btn { display: inline-block; border-radius: 10px; padding: 10px 13px; background: #555; color: white; font-weight: bold; text-decoration: none; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 10px; }
        .stat { background: #fafafa; border: 1px solid var(--border); border-radius: 12px; padding: 12px; }
        .stat strong { display: block; font-size: 1.8rem; }
        .bar-row { margin: 13px 0; }
        .bar-label { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 5px; }
        .bar { height: 17px; border-radius: 999px; background: #eee; overflow: hidden; border: 1px solid #ddd; }
        .bar span { display: block; height: 100%; background: var(--primary); min-width: 2px; }
        .bar span.low { background: var(--bar-low); }
        .bar span.medium { background: var(--bar-medium); }
        .bar span.high { background: var(--bar-high); }
        .bar span.full { background: var(--bar-full); }
        .muted { color: #666; font-size: .92rem; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 9px 8px; border-bottom: 1px solid #e6e6e6; text-align: left; }
        th { background: #fafafa; }
        .table-wrap { overflow-x: auto; }
    </style>
</head>
<body class="<?= h(adminBodyClass()) ?>">
<div class="wrap">
    <div class="card">
        <h1>Statistik</h1>
        <?php adminNav('stats'); ?>
    </div>

    <div class="card">
        <h2>Rückmeldungen</h2>
        <div class="grid">
            <div class="stat"><strong><?= $totalCodes ?></strong>eingeladene Codes</div>
            <div class="stat"><strong><?= $totalEntries ?></strong>Rückmeldungen</div>
            <div class="stat"><strong><?= $totalHelp ?></strong>Zusagen</div>
            <div class="stat"><strong><?= $totalNoTime ?></strong>Absagen</div>
            <div class="stat"><strong><?= $totalNoResponse ?></strong>noch offen</div>
        </div>
        <?= bar('Zugesagt', $totalHelp, max(1, $totalCodes)) ?>
        <?= bar('Abgesagt', $totalNoTime, max(1, $totalCodes)) ?>
        <?= bar('Noch keine Rückmeldung', $totalNoResponse, max(1, $totalCodes)) ?>    </div>

    <div class="card">
        <h2>Aufrufe nach Gerät</h2>
        <?php if ($totalVisits === 0): ?>
            <p class="muted">Noch keine Aufrufe erfasst.</p>
        <?php else: ?>
            <?php foreach ($deviceRows as $row): ?>
                <?= bar((string)$row['device_type'], (int)$row['amount'], $totalVisits) ?>
            <?php endforeach; ?>
            <p class="muted">Gezählt werden Seitenaufrufe, keine eindeutig identifizierten Personen.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Normale Schichten</h2>
        <?php foreach ($normalShifts as $shift): ?>
            <?php
                $used = countAssignments($db, 'entry_shifts', 'shift_id', (int)$shift['id']);
                $max = max(1, (int)$shift['max_slots']);
            ?>
            <?= bar((string)$shift['title'], $used, $max, occupancyClass($used, $max)) ?>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h2>Springer-Schichten</h2>
        <?php foreach ($springerShifts as $shift): ?>
            <?php
                $used = countAssignments($db, 'entry_springer_shifts', 'springer_shift_id', (int)$shift['id']);
                $max = max(1, (int)$shift['max_slots']);
            ?>
            <?= bar((string)$shift['title'], $used, $max, occupancyClass($used, $max)) ?>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h2>Nicht-Reagierer</h2>
        <?php
            $stmt = $db->query("SELECT email, code, created_at
                FROM access_codes ac
                WHERE NOT EXISTS (SELECT 1 FROM entries e WHERE e.access_code_id = ac.id)
                ORDER BY email ASC");
            $missing = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        ?>
        <?php if (empty($missing)): ?>
            <p class="muted">Alle vorhandenen Codes haben eine Rückmeldung.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>E-Mail</th><th>Code</th><th>Erstellt</th></tr></thead>
                    <tbody>
                    <?php foreach ($missing as $row): ?>
                        <tr>
                            <td><?= h((string)$row['email']) ?></td>
                            <td><?= h((string)$row['code']) ?></td>
                            <td><?= h((string)$row['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
