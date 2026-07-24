<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$db = new PDO('sqlite:' . __DIR__ . '/../data/helferliste.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/tracking.php';
$appConfig = appConfig($db);

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Einheitliche Längenbegrenzung schützt die Datenbank vor unnötig großen Texteingaben.
function limitText(string $value, int $maxLength): string {
    $value = trim($value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }
    return substr($value, 0, $maxLength);
}

// CSRF-Token für öffentliche Formulare, damit angemeldete Helfer nicht fremde Formulare ungewollt auslösen.
function publicCsrfToken(): string {
    if (empty($_SESSION['public_csrf_token'])) {
        $_SESSION['public_csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['public_csrf_token'];
}

function publicCsrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(publicCsrfToken()) . '">';
}

function verifyPublicCsrfToken(): bool {
    $token = (string)($_POST['csrf_token'] ?? '');
    $sessionToken = (string)($_SESSION['public_csrf_token'] ?? '');
    return $token !== '' && $sessionToken !== '' && hash_equals($sessionToken, $token);
}

// Zugangscodes werden auf Ziffern reduziert, damit Leerzeichen oder Formatierungen nicht stören.
function cleanCode(string $code): string {
    return preg_replace('/\D+/', '', trim($code));
}

// Prüft die aktuelle Belegung einer Schicht direkt aus der Zuordnungstabelle.
function countAssignments(PDO $db, string $linkTable, string $column, int $shiftId): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM {$linkTable} WHERE {$column} = ?");
    $stmt->execute([$shiftId]);
    return (int)$stmt->fetchColumn();
}

// Lädt den Zugangscode aus der Datenbank. Ohne gültigen Code bleibt die Seite im Loginmodus.
function getAccessCode(PDO $db, string $code): ?array {
    $stmt = $db->prepare("SELECT * FROM access_codes WHERE code = ? LIMIT 1");
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// Sucht einen Zugang entweder über den Code oder über die hinterlegte E-Mail-Adresse.
function getAccessCodeByCodeOrEmail(PDO $db, string $input): ?array {
    $input = trim($input);
    if ($input === '') {
        return null;
    }

    if (str_contains($input, '@')) {
        $email = strtolower(limitText($input, 254));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $stmt = $db->prepare("SELECT * FROM access_codes WHERE LOWER(email) = ? LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    $code = cleanCode($input);
    return $code !== '' ? getAccessCode($db, $code) : null;
}

// Pro Zugangscode wird nur die letzte gespeicherte Rückmeldung angezeigt.
function getCodeEntry(PDO $db, int $accessCodeId): ?array {
    $stmt = $db->prepare("SELECT * FROM entries WHERE access_code_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$accessCodeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function getEntryShiftTitles(PDO $db, int $entryId, string $linkTable, string $shiftTable, string $column): array {
    $stmt = $db->prepare("SELECT s.title
        FROM {$linkTable} es
        JOIN {$shiftTable} s ON s.id = es.{$column}
        WHERE es.entry_id = ?
        ORDER BY s.sort_order ASC, s.id ASC");
    $stmt->execute([$entryId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

// Lädt nur aktive Schichten für die öffentliche Auswahl.
function loadShifts(PDO $db, string $table): array {
    $stmt = $db->query("SELECT * FROM {$table} WHERE active = 1 ORDER BY sort_order ASC, id ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// Bereinigt Checkbox-Werte auf eindeutige positive IDs.
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

// Vor dem Speichern wird erneut geprüft, ob die ausgewählte Schicht noch frei ist.
function selectedShiftIsAvailable(PDO $db, string $shiftTable, string $linkTable, string $column, int $shiftId): bool {
    $stmt = $db->prepare("SELECT id, max_slots FROM {$shiftTable} WHERE id = ? AND active = 1");
    $stmt->execute([$shiftId]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$shift) {
        return false;
    }

    $used = countAssignments($db, $linkTable, $column, $shiftId);
    return $used < (int)$shift['max_slots'];
}

function fillPercent(int $used, int $max): int {
    if ($max <= 0) {
        return 0;
    }

    return max(0, min(100, (int)round(($used / $max) * 100)));
}

function fillClass(int $used, int $max): string {
    if ($max <= 0 || $used >= $max) {
        return 'full';
    }

    $ratio = $used / $max;
    if ($ratio >= 0.8) {
        return 'high';
    }
    if ($ratio >= 0.5) {
        return 'medium';
    }

    return 'low';
}

function publicShiftOverviewRows(PDO $db, array $shifts, string $linkTable, string $column): array {
    $rows = [];
    foreach ($shifts as $shift) {
        $used = countAssignments($db, $linkTable, $column, (int)$shift['id']);
        $max = (int)$shift['max_slots'];
        $rows[] = [
            'title' => (string)$shift['title'],
            'used' => $used,
            'max' => $max,
            'free' => max(0, $max - $used),
            'percent' => fillPercent($used, $max),
            'class' => fillClass($used, $max),
        ];
    }
    return $rows;
}

$message = '';
$error = '';
$changeMessage = '';
$loginError = '';
$overviewError = '';

if (isset($_GET['logout'])) {
    unset($_SESSION['access_code'], $_SESSION['can_view_public_stats_for']);
    header('Location: index.php');
    exit;
}

// Direktlinks aus der E-Mail melden den Code einmalig an und entfernen ihn danach aus der URL.
if (isset($_GET['code'])) {
    $getCode = cleanCode((string)$_GET['code']);
    if ($getCode !== '' && getAccessCode($db, $getCode)) {
        $_SESSION['access_code'] = $getCode;
    }
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (!verifyPublicCsrfToken()) {
        $loginError = 'Ungültige oder abgelaufene Formular-Sitzung. Bitte Seite neu laden und erneut versuchen.';
    } else {
    $submittedCode = cleanCode((string)($_POST['code'] ?? ''));
    $access = $submittedCode !== '' ? getAccessCode($db, $submittedCode) : null;

    if ($access) {
        $_SESSION['access_code'] = $submittedCode;
        $stmt = $db->prepare("UPDATE access_codes SET last_login_at = datetime('now') WHERE id = ?");
        $stmt->execute([(int)$access['id']]);
        header('Location: index.php');
        exit;
    }

    $loginError = 'Der Zugangscode wurde nicht gefunden. Bitte prüfe den Code.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'view_public_stats') {
    if (!verifyPublicCsrfToken()) {
        $overviewError = 'Ungültige oder abgelaufene Formular-Sitzung. Bitte Seite neu laden und erneut versuchen.';
    } else {
        $overviewLogin = limitText((string)($_POST['overview_login'] ?? ''), 254);
        $overviewAccess = getAccessCodeByCodeOrEmail($db, $overviewLogin);

        if (!$overviewAccess) {
            $overviewError = 'Code oder E-Mail-Adresse wurde nicht gefunden.';
        } elseif (!getCodeEntry($db, (int)$overviewAccess['id'])) {
            $overviewError = 'Die Helferliste kann erst angezeigt werden, nachdem mit diesem Code eine Rückmeldung abgegeben wurde.';
        } else {
            $_SESSION['can_view_public_stats_for'] = (int)$overviewAccess['id'];
            header('Location: index.php#helferuebersicht');
            exit;
        }
    }
}

$currentCode = cleanCode((string)($_SESSION['access_code'] ?? ''));
$accessCode = $currentCode !== '' ? getAccessCode($db, $currentCode) : null;
$accessCodeId = $accessCode ? (int)$accessCode['id'] : null;

// Geräte-Statistik wird ausschließlich auf der öffentlichen Hauptseite geschrieben.
trackVisit($db, $accessCodeId ? 'start_logged_in' : 'start_guest', $accessCodeId);

if ($accessCodeId) {
    $stmt = $db->prepare("UPDATE access_codes SET last_login_at = datetime('now') WHERE id = ?");
    $stmt->execute([$accessCodeId]);
}

$existingEntry = $accessCodeId ? getCodeEntry($db, $accessCodeId) : null;

$statsSessionAccessCodeId = (int)($_SESSION['can_view_public_stats_for'] ?? 0);
$statsSessionEntry = $statsSessionAccessCodeId > 0 ? getCodeEntry($db, $statsSessionAccessCodeId) : null;
if ($statsSessionAccessCodeId > 0 && !$statsSessionEntry) {
    unset($_SESSION['can_view_public_stats_for']);
    $statsSessionAccessCodeId = 0;
}
$canViewPublicStats = (bool)$existingEntry || $statsSessionAccessCodeId > 0;

// Nach einer gespeicherten Rückmeldung läuft alles Weitere über Änderungswünsche.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accessCodeId && ($_POST['action'] ?? '') === 'request_change') {
    if (!verifyPublicCsrfToken()) {
        $error = 'Ungültige oder abgelaufene Formular-Sitzung. Bitte Seite neu laden und erneut versuchen.';
    } elseif (!$existingEntry) {
        $error = 'Es gibt noch keine gespeicherte Rückmeldung, die geändert werden könnte.';
    } else {
        $changeText = limitText((string)($_POST['change_text'] ?? ''), 1000);
        if ($changeText === '') {
            $error = 'Bitte schreibe kurz, was geändert werden soll.';
        } else {
            $stmt = $db->prepare("INSERT INTO change_requests (access_code_id, entry_id, name, message, requested_at, status)
                VALUES (?, ?, ?, ?, datetime('now'), 'open')");
            $stmt->execute([
                $accessCodeId,
                (int)$existingEntry['id'],
                (string)$existingEntry['name'],
                $changeText,
            ]);
            logEvent($db, 'change_request_created', 'Änderungswunsch von ' . (string)$existingEntry['name']);
            $changeMessage = 'Dein Änderungswunsch wurde gespeichert.';
        }
    }
}

// Speichern der eigentlichen Rückmeldung inklusive normaler und Springer-Schichten.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accessCodeId && ($_POST['action'] ?? '') === 'save_entry') {
    $existingEntry = getCodeEntry($db, $accessCodeId);

    if (!verifyPublicCsrfToken()) {
        $error = 'Ungültige oder abgelaufene Formular-Sitzung. Bitte Seite neu laden und erneut versuchen.';
    } elseif ($existingEntry) {
        $error = 'Mit diesem Code wurde bereits eine Rückmeldung gespeichert. Änderungen bitte über den Änderungswunsch anfragen.';
    } else {
        $name = limitText((string)($_POST['name'] ?? ''), 120);
        $status = (string)($_POST['status'] ?? '');
        $note = limitText((string)($_POST['note'] ?? ''), 1000);
        $selectedShifts = normalizeIds($_POST['shifts'] ?? []);
        $selectedSpringerShifts = normalizeIds($_POST['springer_shifts'] ?? []);

        if ($name === '') {
            $error = 'Bitte gib deinen Namen ein.';
        } elseif (!in_array($status, ['help', 'no_time'], true)) {
            $error = 'Bitte wähle aus, ob du hilfst oder keine Zeit hast.';
        } elseif ($status === 'help' && empty($selectedShifts) && empty($selectedSpringerShifts)) {
            $error = 'Bitte wähle mindestens eine normale Schicht oder eine Springer-Schicht aus.';
        } else {
            foreach ($selectedShifts as $shiftId) {
                if (!selectedShiftIsAvailable($db, 'shifts', 'entry_shifts', 'shift_id', $shiftId)) {
                    $error = 'Eine ausgewählte normale Schicht ist inzwischen voll oder nicht mehr verfügbar.';
                    break;
                }
            }

            if ($error === '') {
                foreach ($selectedSpringerShifts as $shiftId) {
                    if (!selectedShiftIsAvailable($db, 'springer_shifts', 'entry_springer_shifts', 'springer_shift_id', $shiftId)) {
                        $error = 'Eine ausgewählte Springer-Schicht ist inzwischen voll oder nicht mehr verfügbar.';
                        break;
                    }
                }
            }
        }

        if ($error === '') {
            $transactionStarted = false;
            try {
                // BEGIN IMMEDIATE verhindert, dass zwei Helfer gleichzeitig denselben letzten freien Platz speichern.
                // Weil diese Transaktion manuell per SQL gestartet wird, wird sie auch manuell per SQL beendet.
                $db->exec('BEGIN IMMEDIATE TRANSACTION');
                $transactionStarted = true;

                $existingEntry = getCodeEntry($db, $accessCodeId);
                if ($existingEntry) {
                    throw new RuntimeException('Mit diesem Code wurde bereits eine Rückmeldung gespeichert.');
                }

                if ($status === 'help') {
                    foreach ($selectedShifts as $shiftId) {
                        if (!selectedShiftIsAvailable($db, 'shifts', 'entry_shifts', 'shift_id', $shiftId)) {
                            throw new RuntimeException('Eine ausgewählte normale Schicht ist inzwischen voll oder nicht mehr verfügbar.');
                        }
                    }
                    foreach ($selectedSpringerShifts as $shiftId) {
                        if (!selectedShiftIsAvailable($db, 'springer_shifts', 'entry_springer_shifts', 'springer_shift_id', $shiftId)) {
                            throw new RuntimeException('Eine ausgewählte Springer-Schicht ist inzwischen voll oder nicht mehr verfügbar.');
                        }
                    }
                }

                $stmt = $db->prepare("INSERT INTO entries (name, status, note, created_at, access_code_id) VALUES (?, ?, ?, datetime('now'), ?)");
                $stmt->execute([$name, $status, $note, $accessCodeId]);
                $entryId = (int)$db->lastInsertId();

                if ($status === 'help') {
                    $stmtShift = $db->prepare("INSERT INTO entry_shifts (entry_id, shift_id) VALUES (?, ?)");
                    foreach ($selectedShifts as $shiftId) {
                        $stmtShift->execute([$entryId, $shiftId]);
                    }

                    $stmtSpringer = $db->prepare("INSERT INTO entry_springer_shifts (entry_id, springer_shift_id) VALUES (?, ?)");
                    foreach ($selectedSpringerShifts as $shiftId) {
                        $stmtSpringer->execute([$entryId, $shiftId]);
                    }
                }

                $stmt = $db->prepare("UPDATE access_codes SET used_at = datetime('now') WHERE id = ? AND used_at IS NULL");
                $stmt->execute([$accessCodeId]);

                logEvent($db, 'entry_created', 'Rückmeldung von ' . $name);
                $db->exec('COMMIT');
                $transactionStarted = false;

                $message = 'Danke, deine Rückmeldung wurde gespeichert.';
                $existingEntry = getCodeEntry($db, $accessCodeId);
                $_SESSION['can_view_public_stats_for'] = $accessCodeId;
                $canViewPublicStats = true;
            } catch (Throwable $e) {
                if ($transactionStarted) {
                    try {
                        $db->exec('ROLLBACK');
                    } catch (Throwable $rollbackError) {
                        // Wenn SQLite bereits selbst abgebrochen hat, soll der eigentliche Fehler angezeigt werden.
                    }
                }
                $error = $e instanceof RuntimeException ? $e->getMessage() : 'Beim Speichern ist ein Fehler aufgetreten. Bitte versuche es erneut.';
            }
        }
    }
}

$shifts = loadShifts($db, 'shifts');
$springerShifts = loadShifts($db, 'springer_shifts');
$publicNormalShiftRows = publicShiftOverviewRows($db, $shifts, 'entry_shifts', 'shift_id');
$publicSpringerShiftRows = publicShiftOverviewRows($db, $springerShifts, 'entry_springer_shifts', 'springer_shift_id');
$publicLoginInfoText = trim((string)($appConfig['public_login_info_text'] ?? ''));
publicCsrfToken();

?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(appTitle($appConfig)) ?> - <?= h(appSubtitle($appConfig)) ?></title>
    <style>
        :root {
            --fire-red: <?= h(appDesignColor($appConfig, 'primary_color')) ?>;
            --fire-red-dark: <?= h(appDesignColor($appConfig, 'primary_color_dark')) ?>;
            --fire-red-soft: <?= h(appDesignColor($appConfig, 'primary_color_soft')) ?>;
            --hero-start: <?= h(appDesignColor($appConfig, 'hero_color_start')) ?>;
            --hero-end: <?= h(appDesignColor($appConfig, 'hero_color_end')) ?>;
            --occupancy-low: <?= h(appDesignColor($appConfig, 'occupancy_color_low')) ?>;
            --occupancy-medium: <?= h(appDesignColor($appConfig, 'occupancy_color_medium')) ?>;
            --occupancy-high: <?= h(appDesignColor($appConfig, 'occupancy_color_high')) ?>;
            --occupancy-full: <?= h(appDesignColor($appConfig, 'occupancy_color_full')) ?>;
            --ink: <?= h(appDesignColor($appConfig, 'page_text_color')) ?>;
            --muted: <?= h(appDesignColor($appConfig, 'muted_text_color')) ?>;
            --line: #e2e8f0;
            --bg: <?= h(appDesignColor($appConfig, 'page_background_color')) ?>;
            --card: <?= h(appDesignColor($appConfig, 'card_background_color')) ?>;
            --success-bg: #eaf8ee;
            --success-line: #9ed3aa;
            --error-bg: #fdecec;
            --error-line: #e2a0a0;
            --info-bg: #eef5ff;
            --info-line: #a9c8f5;
            --warning-bg: #fff7e0;
            --radius-lg: 24px;
            --radius-md: 16px;
            --shadow: 0 24px 70px rgba(15, 23, 42, .10);
            --shadow-soft: 0 8px 24px rgba(15, 23, 42, .07);
        }

        * { box-sizing: border-box; }

        html { scroll-behavior: smooth; }

        body {
            margin: 0;
            font-family: <?= h(appFontFamily($appConfig)) ?>;
            background:
                radial-gradient(circle at top left, rgba(176, 0, 32, .12), transparent 34rem),
                linear-gradient(145deg, #ffffff 0%, var(--bg) 46%, #eef1f5 100%);
            color: var(--ink);
            line-height: 1.5;
            min-height: 100vh;
        }

        a { color: var(--fire-red); }

        .page {
            width: min(1040px, 100%);
            margin: 0 auto;
            padding: 22px 14px 38px;
        }

        .hero {
            position: relative;
            overflow: hidden;
            border-radius: 28px;
            background:
                linear-gradient(135deg, var(--hero-start), var(--hero-end)),
                linear-gradient(135deg, var(--fire-red), #222);
            color: white;
            box-shadow: var(--shadow);
            margin-bottom: 18px;
        }

        .hero::after {
            content: "";
            position: absolute;
            right: -82px;
            top: -46px;
            width: 340px;
            height: 340px;
            border-radius: 50%;
            background: radial-gradient(circle at 38% 38%, rgba(255,255,255,.15), rgba(255,255,255,.07) 50%, rgba(255,255,255,.025) 68%, rgba(255,255,255,0) 73%);
            pointer-events: none;
        }

        .hero::before {
            content: "";
            position: absolute;
            right: 44px;
            top: 42px;
            width: 210px;
            height: 210px;
            border-radius: 50%;
            border: 1px solid rgba(255,255,255,.08);
            background: radial-gradient(circle at center, rgba(255,255,255,.045), rgba(255,255,255,0) 70%);
            pointer-events: none;
        }

        .hero-inner {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: minmax(0, 1fr) 280px;
            align-items: center;
            gap: 18px;
            padding: 26px 20px;
        }

        .hero-copy {
            min-width: 0;
            padding-right: 8px;
        }

        .hero-visual {
            position: relative;
            min-height: 150px;
            overflow: visible;
        }

        .hero-vehicle {
            position: absolute;
            right: -44px;
            top: -18px;
            width: 310px;
            max-width: none;
            height: auto;
            display: block;
            filter: drop-shadow(0 16px 24px rgba(0,0,0,.32));
            transform: rotate(-3deg);
            pointer-events: none;
            user-select: none;
        }

        .hero h1 {
            margin: 0;
            font-size: clamp(2rem, 8vw, 4.3rem);
            line-height: .98;
            letter-spacing: -0.055em;
        }

        .hero p {
            margin: 0;
            max-width: 700px;
            color: rgba(255,255,255,.88);
            font-size: 1.05rem;
        }


        .main-card {
            background: rgba(255,255,255,.88);
            border: 1px solid rgba(226, 232, 240, .95);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            overflow: hidden;
            backdrop-filter: blur(12px);
        }

        .card-section {
            padding: 22px;
        }

        .card-section + .card-section {
            border-top: 1px solid var(--line);
        }

        .section-head {
            display: flex;
            align-items: flex-start;
            gap: 13px;
            margin-bottom: 16px;
        }

        .step-dot {
            flex: 0 0 auto;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--fire-red);
            color: white;
            font-weight: 800;
            box-shadow: 0 7px 16px rgba(176,0,32,.28);
        }

        h2, h3 { margin-top: 0; }

        h2 {
            margin-bottom: 3px;
            font-size: 1.35rem;
            line-height: 1.2;
            letter-spacing: -.02em;
        }

        h3 {
            font-size: 1.05rem;
            margin: 20px 0 10px;
        }

        .subtext {
            margin: 0;
            color: var(--muted);
        }

        label {
            display: block;
            font-weight: 800;
            margin: 14px 0 7px;
        }

        input[type="text"], textarea {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 14px;
            font-size: 1rem;
            background: white;
            color: var(--ink);
            outline: none;
            transition: border-color .15s ease, box-shadow .15s ease;
        }

        input[type="text"]:focus, textarea:focus {
            border-color: var(--fire-red);
            box-shadow: 0 0 0 4px rgba(176,0,32,.12);
        }

        textarea {
            min-height: 110px;
            resize: vertical;
        }

        .code-input {
            font-size: 1.35rem !important;
            letter-spacing: .18em;
            font-weight: 800;
            text-align: center;
        }

        .button-row {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 14px;
        }

        button, .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 0;
            border-radius: 14px;
            padding: 13px 16px;
            background: var(--fire-red);
            color: white;
            font-weight: 800;
            text-decoration: none;
            cursor: pointer;
            box-shadow: 0 12px 24px rgba(176,0,32,.22);
            transition: transform .12s ease, background .12s ease, box-shadow .12s ease;
            min-height: 48px;
        }

        button:hover, .btn:hover {
            background: var(--fire-red-dark);
            transform: translateY(-1px);
            box-shadow: 0 14px 28px rgba(176,0,32,.28);
        }

        .btn.secondary, button.secondary {
            background: #475569;
            box-shadow: 0 10px 20px rgba(71,85,105,.18);
        }

        .btn.secondary:hover, button.secondary:hover { background: #334155; }

        .notice {
            border-radius: 16px;
            padding: 13px 14px;
            margin: 0 0 14px;
            font-weight: 700;
        }

        .success { background: var(--success-bg); border: 1px solid var(--success-line); color: #166534; }
        .error { background: var(--error-bg); border: 1px solid var(--error-line); color: #991b1b; }
        .info { background: var(--info-bg); border: 1px solid var(--info-line); color: #1e3a8a; }
        .warning { background: var(--warning-bg); border: 1px solid #f2d37c; color: #7a4a00; }

        .choice-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 8px;
        }

        .choice-card {
            position: relative;
            display: flex;
            gap: 12px;
            align-items: flex-start;
            margin: 0;
            padding: 15px;
            border: 2px solid var(--line);
            border-radius: 18px;
            background: white;
            cursor: pointer;
            transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease, background .15s ease;
            min-height: 96px;
        }

        .choice-card:hover {
            transform: translateY(-1px);
            border-color: rgba(176,0,32,.40);
            box-shadow: var(--shadow-soft);
        }

        .choice-card input {
            width: 20px;
            height: 20px;
            margin-top: 2px;
            accent-color: var(--fire-red);
            flex: 0 0 auto;
        }

        .choice-title {
            display: block;
            font-size: 1.04rem;
            color: var(--ink);
            margin-bottom: 3px;
        }

        .choice-desc {
            display: block;
            color: var(--muted);
            font-weight: 500;
            font-size: .92rem;
        }

        .choice-card.is-selected {
            border-color: var(--fire-red);
            background: var(--fire-red-soft);
            box-shadow: 0 12px 28px rgba(176,0,32,.12);
        }

        .shift-area {
            margin-top: 18px;
            padding-top: 2px;
        }

        .shift-intro {
            color: var(--muted);
            margin: -4px 0 14px;
        }

        .shift-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 12px;
        }

        .shift {
            position: relative;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 18px;
            background: white;
            transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease, background .15s ease;
        }

        .shift:not(.full):hover {
            transform: translateY(-1px);
            border-color: rgba(176,0,32,.35);
            box-shadow: var(--shadow-soft);
        }

        .shift label {
            margin: 0;
            display: flex;
            gap: 11px;
            align-items: flex-start;
            font-weight: 500;
            padding: 14px;
            cursor: pointer;
            min-height: 94px;
        }

        .shift input {
            margin-top: 3px;
            width: 20px;
            height: 20px;
            accent-color: var(--fire-red);
            flex: 0 0 auto;
        }

        .shift strong {
            display: block;
            margin-bottom: 4px;
            color: var(--ink);
            font-size: 1.02rem;
        }

        .meta {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 4px 9px;
            background: #f1f5f9;
            color: #475569;
            font-size: .86rem;
            font-weight: 700;
        }

        .shift.is-selected {
            border-color: var(--fire-red);
            background: var(--fire-red-soft);
            box-shadow: 0 12px 28px rgba(176,0,32,.12);
        }

        .shift.full {
            opacity: .68;
            background: #f8fafc;
        }

        .shift.full label { cursor: not-allowed; }

        .summary-box {
            border: 1px solid var(--line);
            background: white;
            border-radius: 18px;
            padding: 16px;
            margin-top: 12px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 12px;
        }

        .summary-item {
            border-radius: 14px;
            background: #f8fafc;
            padding: 12px;
        }

        .summary-label {
            display: block;
            color: var(--muted);
            font-size: .86rem;
            font-weight: 800;
            margin-bottom: 2px;
            text-transform: uppercase;
            letter-spacing: .03em;
        }

        .summary-list {
            margin: 6px 0 0;
            padding-left: 20px;
        }

        .login-primary {
            position: relative;
            background: linear-gradient(180deg, #ffffff 0%, #fff8f9 100%);
            text-align: center;
        }

        .primary-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            border-radius: 999px;
            padding: 7px 11px;
            margin-bottom: 14px;
            background: var(--fire-red-soft);
            color: var(--fire-red);
            font-size: .88rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .035em;
        }

        .login-primary .section-head {
            justify-content: center;
            align-items: center;
        }

        .login-box {
            max-width: 560px;
            margin: 0 auto;
        }

        .login-info-box {
            max-width: 720px;
            margin: 0 auto 18px;
            padding: 14px 16px;
            border: 1px solid var(--info-line);
            border-radius: 18px;
            background: var(--info-bg);
            color: #1e3a8a;
            text-align: left;
            font-weight: 650;
        }

        .login-info-box p:last-child {
            margin-bottom: 0;
        }

        .login-primary label {
            text-align: center;
        }

        .login-primary .button-row {
            justify-content: center;
        }

        .primary-action {
            width: 100%;
            min-height: 58px;
            font-size: 1.08rem;
        }

        .code-help {
            display: block;
            margin-top: 7px;
            color: var(--muted);
            font-size: .93rem;
            font-weight: 600;
        }

        .secondary-panel {
            margin-top: 18px;
            text-align: left;
            border: 1px solid var(--line);
            border-radius: 18px;
            background: #f8fafc;
            overflow: hidden;
        }

        .secondary-panel summary {
            list-style: none;
            cursor: pointer;
            padding: 15px 16px;
            color: #334155;
            font-weight: 900;
        }

        .secondary-panel summary::-webkit-details-marker { display: none; }

        .secondary-panel summary::after {
            content: "öffnen";
            float: right;
            color: var(--muted);
            font-size: .85rem;
            font-weight: 800;
        }

        .secondary-panel[open] summary::after { content: "schließen"; }

        .secondary-panel-body {
            border-top: 1px solid var(--line);
            padding: 16px;
            background: white;
        }

        .overview-input {
            letter-spacing: normal;
            font-weight: 700;
        }

        .public-overview {
            margin-top: 18px;
        }

        .overview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 12px;
        }

        .overview-card {
            border: 1px solid var(--line);
            border-radius: 18px;
            background: white;
            padding: 14px;
            box-shadow: var(--shadow-soft);
        }

        .overview-title {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            align-items: baseline;
            font-weight: 800;
            margin-bottom: 9px;
        }

        .overview-title span {
            color: var(--muted);
            font-size: .9rem;
            white-space: nowrap;
        }

        .fill-bar {
            overflow: hidden;
            height: 12px;
            border-radius: 999px;
            background: #e2e8f0;
        }

        .fill-bar span {
            display: block;
            height: 100%;
            border-radius: inherit;
            background: #94a3b8;
        }

        .fill-bar span.low { background: var(--occupancy-low); }
        .fill-bar span.medium { background: var(--occupancy-medium); }
        .fill-bar span.high { background: var(--occupancy-high); }
        .fill-bar span.full { background: var(--occupancy-full); }

        .overview-note {
            margin: 9px 0 0;
            color: var(--muted);
            font-size: .92rem;
            font-weight: 700;
        }

        .footer {
            text-align: center;
            font-size: .92rem;
            color: var(--muted);
            margin-top: 20px;
        }

        .footer a {
            color: #475569;
            margin: 0 8px;
            font-weight: 700;
            text-decoration: none;
        }

        .footer a:hover { color: var(--fire-red); }

        .hidden { display: none; }

        .mobile-hint {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: rgba(255,255,255,.78);
        }

        @media (min-width: 760px) {
            .hero-inner { padding: 34px; }
            .card-section { padding: 28px; }
        }

        @media (max-width: 680px) {
            .page { padding: 12px 10px 28px; }
            .hero { border-radius: 22px; }
            .main-card { border-radius: 22px; }
            .card-section { padding: 18px; }
            .section-head { gap: 10px; }
            .step-dot { width: 30px; height: 30px; font-size: .9rem; }
            .choice-grid { grid-template-columns: 1fr; }
            .shift-grid { grid-template-columns: 1fr; }
            .button-row { flex-direction: column; align-items: stretch; }
            button, .btn { width: 100%; }
            .hero::after { width: 235px; height: 235px; right: -62px; top: -46px; }
            .hero::before { width: 145px; height: 145px; right: 18px; top: 22px; }
            .hero-inner { grid-template-columns: minmax(0, 1fr) 128px; padding: 22px 18px; gap: 8px; }
            .hero-copy { padding-right: 0; }
            .hero-visual { min-height: 98px; }
            .hero-vehicle { width: 190px; right: -62px; top: -8px; opacity: .94; }
        }
    </style>
</head>
<body>
<div class="page">
    <header class="hero">
        <div class="hero-inner">
            <div class="hero-copy">
                <h1><?= h(appTitle($appConfig)) ?></h1>
                <?php if (appSubtitle($appConfig) !== ''): ?><p><?= h(appSubtitle($appConfig)) ?></p><?php endif; ?>
            </div>

            <div class="hero-visual">
                <img class="hero-vehicle" src="<?= h(appHeroImage($appConfig)) ?>" alt="<?= h(appText($appConfig, 'hero_image_alt')) ?>">
            </div>
        </div>
    </header>

    <?php if ($message !== ''): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>
    <?php if ($changeMessage !== ''): ?><div class="notice success"><?= h($changeMessage) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
    <?php if ($loginError !== ''): ?><div class="notice error"><?= h($loginError) ?></div><?php endif; ?>
    <?php if ($overviewError !== ''): ?><div class="notice error"><?= h($overviewError) ?></div><?php endif; ?>

    <main class="main-card">
        <?php if (!$accessCodeId): ?>
            <section class="card-section login-primary">
                <div class="section-head">
                    <span class="step-dot">1</span>
                    <div>
                        <h2><?= h(appText($appConfig, 'text_login_heading')) ?></h2>
                        <p class="subtext"><?= nl2br(h(appText($appConfig, 'text_login_intro'))) ?></p>
                    </div>
                </div>

                <?php if ($publicLoginInfoText !== ''): ?>
                    <div class="login-info-box"><?= nl2br(h($publicLoginInfoText)) ?></div>
                <?php endif; ?>

                <form method="post" class="login-box">
                    <input type="hidden" name="action" value="login">
                    <?= publicCsrfField() ?>
                    <label for="code"><?= h(appText($appConfig, 'text_code_label')) ?></label>
                    <input class="code-input" type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="4" pattern="[0-9]{4}" placeholder="1234" required>
                    <span class="code-help"><?= nl2br(h(appText($appConfig, 'text_code_help'))) ?></span>
                    <div class="button-row">
                        <button class="primary-action" type="submit"><?= h(appText($appConfig, 'text_login_button')) ?></button>
                    </div>
                </form>

                <details class="secondary-panel">
                    <summary><?= h(appText($appConfig, 'text_overview_summary')) ?></summary>
                    <div class="secondary-panel-body">
                        <p class="subtext"><?= nl2br(h(appText($appConfig, 'text_overview_intro'))) ?></p>
                        <form method="post">
                            <input type="hidden" name="action" value="view_public_stats">
                            <?= publicCsrfField() ?>
                            <label for="overview_login"><?= h(appText($appConfig, 'text_overview_login_label')) ?></label>
                            <input class="overview-input" type="text" id="overview_login" name="overview_login" autocomplete="email" maxlength="254" placeholder="<?= h(appText($appConfig, 'text_overview_login_label')) ?>" required>
                            <div class="button-row">
                                <button class="secondary" type="submit"><?= h(appText($appConfig, 'text_overview_button')) ?></button>
                            </div>
                        </form>
                    </div>
                </details>
            </section>
        <?php elseif ($existingEntry): ?>
            <?php
                $normalTitles = getEntryShiftTitles($db, (int)$existingEntry['id'], 'entry_shifts', 'shifts', 'shift_id');
                $springerTitles = getEntryShiftTitles($db, (int)$existingEntry['id'], 'entry_springer_shifts', 'springer_shifts', 'springer_shift_id');
            ?>
            <section class="card-section">
                <div class="section-head">
                    <span class="step-dot">✓</span>
                    <div>
                        <h2><?= h(appText($appConfig, 'text_saved_heading')) ?></h2>
                        <p class="subtext"><?= nl2br(h(appText($appConfig, 'text_saved_intro'))) ?></p>
                    </div>
                </div>

                <div class="summary-box">
                    <div class="summary-grid">
                        <div class="summary-item">
                            <span class="summary-label"><?= h(appText($appConfig, 'text_summary_name_label')) ?></span>
                            <strong><?= h((string)$existingEntry['name']) ?></strong>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label"><?= h(appText($appConfig, 'text_summary_status_label')) ?></span>
                            <strong><?= h($existingEntry['status'] === 'help' ? appText($appConfig, 'text_help_yes') : appText($appConfig, 'text_help_no')) ?></strong>
                        </div>
                    </div>

                    <?php if ($existingEntry['status'] === 'help'): ?>
                        <div class="summary-grid" style="margin-top:12px;">
                            <div class="summary-item">
                                <span class="summary-label">Normale Schichten</span>
                                <ul class="summary-list">
                                    <?php foreach ($normalTitles as $title): ?><li><?= h((string)$title) ?></li><?php endforeach; ?>
                                    <?php if (empty($normalTitles)): ?><li><?= h(appText($appConfig, 'text_no_normal_shift')) ?></li><?php endif; ?>
                                </ul>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Springer-Schichten</span>
                                <ul class="summary-list">
                                    <?php foreach ($springerTitles as $title): ?><li><?= h((string)$title) ?></li><?php endforeach; ?>
                                    <?php if (empty($springerTitles)): ?><li><?= h(appText($appConfig, 'text_no_flexible_shift')) ?></li><?php endif; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (trim((string)$existingEntry['note']) !== ''): ?>
                        <div class="summary-item" style="margin-top:12px;">
                            <span class="summary-label">Hinweis</span>
                            <?= nl2br(h((string)$existingEntry['note'])) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="notice info" style="margin-top:16px;"><?= nl2br(h(appText($appConfig, 'text_change_info'))) ?></div>

                <form method="post">
                    <input type="hidden" name="action" value="request_change">
                    <?= publicCsrfField() ?>
                    <label for="change_text"><?= h(appText($appConfig, 'text_change_label')) ?></label>
                    <textarea id="change_text" name="change_text" maxlength="1000" placeholder="<?= h(appText($appConfig, 'text_change_placeholder')) ?>"></textarea>
                    <div class="button-row">
                        <button type="submit"><?= h(appText($appConfig, 'text_change_button')) ?></button>
                        <a class="btn secondary" href="index.php?logout=1"><?= h(appText($appConfig, 'text_switch_code')) ?></a>
                    </div>
                </form>
            </section>
        <?php else: ?>
            <form method="post" id="entryForm">
                <input type="hidden" name="action" value="save_entry">
                <?= publicCsrfField() ?>

                <section class="card-section">
                    <div class="section-head">
                        <span class="step-dot">1</span>
                        <div>
                            <h2><?= h(appText($appConfig, 'text_entry_heading')) ?></h2>
                            <p class="subtext"><?= nl2br(h(appText($appConfig, 'text_entry_intro'))) ?></p>
                        </div>
                    </div>

                    <label for="name"><?= h(appText($appConfig, 'text_name_label')) ?></label>
                    <input type="text" id="name" name="name" autocomplete="name" maxlength="120" placeholder="<?= h(appText($appConfig, 'text_name_placeholder')) ?>" required>

                    <label><?= h(appText($appConfig, 'text_choice_label')) ?></label>
                    <div class="choice-grid">
                        <label class="choice-card" data-choice-card>
                            <input type="radio" name="status" value="help" required>
                            <span>
                                <strong class="choice-title"><?= h(appText($appConfig, 'text_help_yes')) ?></strong>
                                <span class="choice-desc"><?= nl2br(h(appText($appConfig, 'text_help_yes_description'))) ?></span>
                            </span>
                        </label>
                        <label class="choice-card" data-choice-card>
                            <input type="radio" name="status" value="no_time" required>
                            <span>
                                <strong class="choice-title"><?= h(appText($appConfig, 'text_help_no')) ?></strong>
                                <span class="choice-desc"><?= nl2br(h(appText($appConfig, 'text_help_no_description'))) ?></span>
                            </span>
                        </label>
                    </div>
                </section>

                <section id="shiftArea" class="card-section shift-area hidden">
                    <div class="section-head">
                        <span class="step-dot">2</span>
                        <div>
                            <h2><?= h(appText($appConfig, 'text_shifts_heading')) ?></h2>
                            <p class="subtext"><?= nl2br(h(appText($appConfig, 'text_shifts_intro'))) ?></p>
                        </div>
                    </div>

                    <h3><?= h(appText($appConfig, 'text_normal_shifts_heading')) ?></h3>
                    <p class="shift-intro"><?= nl2br(h(appText($appConfig, 'text_normal_shifts_intro'))) ?></p>
                    <div class="shift-grid">
                        <?php foreach ($shifts as $shift): ?>
                            <?php
                                $used = countAssignments($db, 'entry_shifts', 'shift_id', (int)$shift['id']);
                                $max = (int)$shift['max_slots'];
                                $full = $used >= $max;
                                $free = max(0, $max - $used);
                            ?>
                            <div class="shift <?= $full ? 'full' : '' ?>" data-shift-card>
                                <label>
                                    <input type="checkbox" name="shifts[]" value="<?= (int)$shift['id'] ?>" <?= $full ? 'disabled' : '' ?>>
                                    <span>
                                        <strong><?= h((string)$shift['title']) ?></strong>
                                        <span class="meta"><?= $full ? h(appText($appConfig, 'text_full')) : $free . ' ' . h(appText($appConfig, 'text_free')) ?> · <?= $used ?> / <?= $max ?> <?= h(appText($appConfig, 'text_occupied')) ?></span>
                                    </span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <h3><?= h(appText($appConfig, 'text_flexible_shifts_heading')) ?></h3>
                    <p class="shift-intro"><?= nl2br(h(appText($appConfig, 'text_flexible_shifts_intro'))) ?></p>
                    <div class="shift-grid">
                        <?php foreach ($springerShifts as $shift): ?>
                            <?php
                                $used = countAssignments($db, 'entry_springer_shifts', 'springer_shift_id', (int)$shift['id']);
                                $max = (int)$shift['max_slots'];
                                $full = $used >= $max;
                                $free = max(0, $max - $used);
                            ?>
                            <div class="shift <?= $full ? 'full' : '' ?>" data-shift-card>
                                <label>
                                    <input type="checkbox" name="springer_shifts[]" value="<?= (int)$shift['id'] ?>" <?= $full ? 'disabled' : '' ?>>
                                    <span>
                                        <strong><?= h((string)$shift['title']) ?></strong>
                                        <span class="meta"><?= $full ? h(appText($appConfig, 'text_full')) : $free . ' ' . h(appText($appConfig, 'text_free')) ?> · <?= $used ?> / <?= $max ?> <?= h(appText($appConfig, 'text_occupied')) ?></span>
                                    </span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <h3><?= h(appText($appConfig, 'text_note_heading')) ?></h3>
                    <p class="shift-intro"><?= nl2br(h(appText($appConfig, 'text_note_intro'))) ?></p>
                    <label for="note"><?= h(appText($appConfig, 'text_note_label')) ?></label>
                    <textarea id="note" name="note" maxlength="1000" placeholder="<?= h(appText($appConfig, 'text_note_placeholder')) ?>"></textarea>
                </section>

                <section class="card-section">
                    <div class="button-row">
                        <button type="submit"><?= h(appText($appConfig, 'text_save_button')) ?></button>
                        <a class="btn secondary" href="index.php?logout=1"><?= h(appText($appConfig, 'text_switch_code')) ?></a>
                    </div>
                    <p class="subtext" style="margin-top:12px;"><?= nl2br(h(appText($appConfig, 'text_after_save_info'))) ?></p>
                </section>
            </form>
        <?php endif; ?>

        <?php if ($canViewPublicStats): ?>
            <section class="card-section public-overview" id="helferuebersicht">
                <div class="section-head">
                    <span class="step-dot">i</span>
                    <div>
                        <h2><?= h(appText($appConfig, 'text_public_overview_heading')) ?></h2>
                        <p class="subtext"><?= nl2br(h(appText($appConfig, 'text_public_overview_intro'))) ?></p>
                    </div>
                </div>

                <h3><?= h(appText($appConfig, 'text_normal_shifts_heading')) ?></h3>
                <div class="overview-grid">
                    <?php foreach ($publicNormalShiftRows as $row): ?>
                        <div class="overview-card">
                            <div class="overview-title">
                                <strong><?= h((string)$row['title']) ?></strong>
                                <span><?= (int)$row['used'] ?> / <?= (int)$row['max'] ?></span>
                            </div>
                            <div class="fill-bar"><span class="<?= h((string)$row['class']) ?>" style="width: <?= (int)$row['percent'] ?>%"></span></div>
                            <p class="overview-note"><?= (int)$row['free'] ?> <?= h(appText($appConfig, 'text_space_free')) ?></p>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($publicNormalShiftRows)): ?>
                        <div class="notice info"><?= h(appText($appConfig, 'text_no_normal_shifts_available')) ?></div>
                    <?php endif; ?>
                </div>

                <h3><?= h(appText($appConfig, 'text_flexible_shifts_heading')) ?></h3>
                <div class="overview-grid">
                    <?php foreach ($publicSpringerShiftRows as $row): ?>
                        <div class="overview-card">
                            <div class="overview-title">
                                <strong><?= h((string)$row['title']) ?></strong>
                                <span><?= (int)$row['used'] ?> / <?= (int)$row['max'] ?></span>
                            </div>
                            <div class="fill-bar"><span class="<?= h((string)$row['class']) ?>" style="width: <?= (int)$row['percent'] ?>%"></span></div>
                            <p class="overview-note"><?= (int)$row['free'] ?> <?= h(appText($appConfig, 'text_space_free')) ?></p>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($publicSpringerShiftRows)): ?>
                        <div class="notice info"><?= h(appText($appConfig, 'text_no_flexible_shifts_available')) ?></div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <footer class="footer">
        <a href="datenschutz.php"><?= h(appText($appConfig, 'text_privacy_link')) ?></a>
        <a href="impressum.php"><?= h(appText($appConfig, 'text_imprint_link')) ?></a>
        <span>© <?= date('Y') ?> <?= h((string)$appConfig['event_organizer']) ?></span>
    </footer>
</div>

<script>
(function () {
    const shiftArea = document.getElementById('shiftArea');
    const radios = document.querySelectorAll('input[name="status"]');
    const choiceCards = document.querySelectorAll('[data-choice-card]');
    const shiftCards = document.querySelectorAll('[data-shift-card]');

    function updateShiftArea() {
        if (!shiftArea) return;
        const selected = document.querySelector('input[name="status"]:checked');
        shiftArea.classList.toggle('hidden', !selected || selected.value !== 'help');
    }

    function updateChoiceCards() {
        choiceCards.forEach(card => {
            const input = card.querySelector('input[type="radio"]');
            card.classList.toggle('is-selected', Boolean(input && input.checked));
        });
    }

    function updateShiftCards() {
        shiftCards.forEach(card => {
            const input = card.querySelector('input[type="checkbox"]');
            card.classList.toggle('is-selected', Boolean(input && input.checked));
        });
    }

    radios.forEach(radio => radio.addEventListener('change', function () {
        updateShiftArea();
        updateChoiceCards();
    }));

    shiftCards.forEach(card => {
        const input = card.querySelector('input[type="checkbox"]');
        if (input) input.addEventListener('change', updateShiftCards);
    });

    updateShiftArea();
    updateChoiceCards();
    updateShiftCards();
})();
</script>
</body>
</html>
