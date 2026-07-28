<?php

declare(strict_types=1);

require_once __DIR__ . '/version.php';

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

const ADMIN_USER = 'admin';

// Öffnet die zentrale SQLite-Verbindung für die Admin-Funktionen und erstellt fehlende Settings automatisch.
function adminDb(): PDO {
    static $db = null;

    if ($db instanceof PDO) {
        return $db;
    }

    $db = new PDO('sqlite:' . __DIR__ . '/../data/helferliste.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');
    helferlisteRequireCurrentSchema($db);
    ensureAdminSettingsTable($db);

    return $db;
}

function ensureAdminSettingsTable(PDO $db): void {
    // Die Settings-Tabelle speichert aktuell vor allem den Hash des Admin-Passworts.
    $db->exec("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
}

// Liest einen einzelnen Einstellungswert aus der Datenbank, mit optionalem Rückfallwert.
function getSetting(PDO $db, string $key, ?string $fallback = null): ?string {
    $stmt = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value !== false ? (string)$value : $fallback;
}

// Speichert oder aktualisiert einen Einstellungswert per Upsert.
function setSetting(PDO $db, string $key, string $value): void {
    $stmt = $db->prepare("INSERT INTO app_settings (setting_key, setting_value, updated_at)
        VALUES (?, ?, datetime('now'))
        ON CONFLICT(setting_key) DO UPDATE SET
            setting_value = excluded.setting_value,
            updated_at = excluded.updated_at");
    $stmt->execute([$key, $value]);
}

function deleteSetting(PDO $db, string $key): void {
    $stmt = $db->prepare('DELETE FROM app_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
}

function getAdminPasswordHash(): ?string {
    $hash = trim((string)getSetting(adminDb(), 'admin_password_hash', ''));
    return $hash !== '' ? $hash : null;
}

function adminPasswordIsConfigured(): bool {
    return getAdminPasswordHash() !== null;
}

function getAdminAuthGeneration(): ?string {
    $generation = trim((string)getSetting(adminDb(), 'admin_auth_generation', ''));
    return $generation !== '' ? $generation : null;
}

// Setzt ein neues Admin-Passwort. Gespeichert wird nur der Hash, nicht das Klartextpasswort.
function setAdminPassword(string $password): void {
    if (strlen($password) < 12) {
        throw new RuntimeException('Das Passwort muss mindestens 12 Zeichen lang sein.');
    }

    $db = adminDb();
    setSetting($db, 'admin_password_hash', password_hash($password, PASSWORD_DEFAULT));
    setSetting($db, 'admin_auth_generation', bin2hex(random_bytes(16)));
}

function normalizeAdminSetupToken(string $token): string {
    return strtoupper((string)preg_replace('/[^A-F0-9]/i', '', $token));
}

function completeAdminSetup(string $token, string $password): bool {
    $db = adminDb();
    $setupHash = trim((string)getSetting($db, 'admin_setup_token_hash', ''));
    $normalizedToken = normalizeAdminSetupToken($token);

    if ($setupHash === '' || strlen($normalizedToken) !== 32 || !password_verify($normalizedToken, $setupHash)) {
        return false;
    }
    if (strlen($password) < 12) {
        throw new RuntimeException('Das Passwort muss mindestens 12 Zeichen lang sein.');
    }

    $db->beginTransaction();
    try {
        setAdminPassword($password);
        deleteSetting($db, 'admin_setup_token_hash');
        deleteSetting($db, 'admin_setup_created_at');
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    return true;
}

// CSRF-Token für Admin-Formulare, damit Formulare nicht ungefragt von außen abgeschickt werden.
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function verifyCsrfToken(): void {
    $token = (string)($_POST['csrf_token'] ?? '');
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');

    if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        http_response_code(403);
        echo 'Ungültige oder abgelaufene Formular-Sitzung. Bitte zurückgehen, Seite neu laden und erneut versuchen.';
        exit;
    }
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
    header('Location: admin.php');
    exit;
}

$error = '';
$success = '';
$adminPasswordConfigured = adminPasswordIsConfigured();

if (($_SESSION['admin_logged_in'] ?? false) === true) {
    $sessionGeneration = (string)($_SESSION['admin_auth_generation'] ?? '');
    $currentGeneration = (string)(getAdminAuthGeneration() ?? '');
    if ($sessionGeneration === '' || $currentGeneration === '' || !hash_equals($currentGeneration, $sessionGeneration)) {
        unset($_SESSION['admin_logged_in'], $_SESSION['admin_auth_generation']);
    }
}

if (!$adminPasswordConfigured && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_setup') {
    verifyCsrfToken();
    $setupToken = (string)($_POST['setup_token'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $repeatPassword = (string)($_POST['repeat_password'] ?? '');
    $now = time();
    $lockedUntil = (int)($_SESSION['admin_setup_locked_until'] ?? 0);

    if ($lockedUntil > $now) {
        $error = 'Zu viele Fehlversuche. Bitte kurz warten und erneut versuchen.';
    } elseif (strlen($newPassword) < 12) {
        $error = 'Das neue Passwort muss mindestens 12 Zeichen lang sein.';
    } elseif ($newPassword !== $repeatPassword) {
        $error = 'Die Wiederholung stimmt nicht mit dem neuen Passwort überein.';
    } elseif (completeAdminSetup($setupToken, $newPassword)) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_auth_generation'] = getAdminAuthGeneration();
        unset($_SESSION['admin_setup_attempts'], $_SESSION['admin_setup_locked_until']);
        csrfToken();
        header('Location: admin.php');
        exit;
    } else {
        $_SESSION['admin_setup_attempts'] = (int)($_SESSION['admin_setup_attempts'] ?? 0) + 1;
        if ($_SESSION['admin_setup_attempts'] >= 5) {
            $_SESSION['admin_setup_locked_until'] = $now + 60;
            $_SESSION['admin_setup_attempts'] = 0;
        }
        $error = 'Der Einrichtungscode ist ungültig.';
    }
}

// Passwort-vergessen setzt nichts automatisch zurück. Der Reset erfolgt bewusst nur direkt am Server.
if ($adminPasswordConfigured && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'forgot_password') {
    $error = 'Das Passwort kann nur durch eine berechtigte Person direkt auf dem Server zurückgesetzt werden. Dabei wird ein neuer einmaliger Einrichtungscode erzeugt.';
}

// Login mit einfacher Sperre nach mehreren Fehlversuchen.
if ($adminPasswordConfigured && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_user'], $_POST['login_password'])) {
    $now = time();
    $lockedUntil = (int)($_SESSION['admin_login_locked_until'] ?? 0);

    if ($lockedUntil > $now) {
        $error = 'Zu viele Fehlversuche. Bitte kurz warten und erneut versuchen.';
    } else {
        $passwordHash = getAdminPasswordHash();
        $loginValid = $_POST['login_user'] === ADMIN_USER
            && $passwordHash !== null
            && password_verify((string)$_POST['login_password'], $passwordHash);
        if ($loginValid) {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_auth_generation'] = getAdminAuthGeneration();
            $_SESSION['admin_login_attempts'] = 0;
            unset($_SESSION['admin_login_locked_until']);
            csrfToken();
            header('Location: admin.php');
            exit;
        }

        $_SESSION['admin_login_attempts'] = (int)($_SESSION['admin_login_attempts'] ?? 0) + 1;
        if ($_SESSION['admin_login_attempts'] >= 5) {
            $_SESSION['admin_login_locked_until'] = $now + 30;
            $_SESSION['admin_login_attempts'] = 0;
        }
        $error = 'Benutzername oder Passwort falsch.';
    }
}

if (!($_SESSION['admin_logged_in'] ?? false)) {
    ?>
    <!doctype html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <title><?= $adminPasswordConfigured ? 'Admin Login' : 'Admin einrichten' ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            * { box-sizing: border-box; }
            body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: system-ui, sans-serif; background: #f2f4f7; color: #1f2937; }
            .container { width: min(420px, 100%); margin: 0 auto; padding: 16px; }
            .card { width: 100%; background: white; border-radius: 16px; padding: 24px; box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
            h1 { margin-top: 0; text-align: center; }
            label { display: block; font-weight: 700; margin-top: 16px; margin-bottom: 6px; }
            input { display: block; width: 100%; max-width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 10px; font-size: 16px; }
            button { width: 100%; margin-top: 20px; padding: 14px; border: 0; border-radius: 12px; background: #b91c1c; color: white; font-size: 17px; font-weight: 700; cursor: pointer; }
            button.secondary { background: #555; }
            .error { background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 10px; font-weight: 700; margin-bottom: 16px; }
            .success { background: #dcfce7; color: #166534; padding: 12px; border-radius: 10px; font-weight: 700; margin-bottom: 16px; }
            .hint { color: #6b7280; font-size: .92rem; margin-top: 10px; }
        </style>
    </head>
    <body>
        <main class="container">
            <div class="card">
                <h1><?= $adminPasswordConfigured ? 'Admin Login' : 'Sichere Ersteinrichtung' ?></h1>

                <?php if ($error !== ''): ?>
                    <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($success !== ''): ?>
                    <div class="success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <?php if (!$adminPasswordConfigured): ?>
                    <p>Gib den einmaligen Einrichtungscode aus dem Installations- oder Migrationslauf ein und lege dein persönliches Admin-Passwort fest.</p>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="complete_setup">
                        <label for="setup_token">Einrichtungscode</label>
                        <input id="setup_token" name="setup_token" type="text" autocomplete="one-time-code" maxlength="39" required>

                        <label for="new_password">Neues Admin-Passwort</label>
                        <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="12" required>

                        <label for="repeat_password">Passwort wiederholen</label>
                        <input id="repeat_password" name="repeat_password" type="password" autocomplete="new-password" minlength="12" required>

                        <button type="submit">Adminzugang einrichten</button>
                    </form>
                    <p class="hint">Der Einrichtungscode ist nur einmal verwendbar und wird nicht im Klartext gespeichert.</p>
                <?php else: ?>
                    <form method="post">
                        <label for="login_user">Benutzername</label>
                        <input id="login_user" name="login_user" type="text" autocomplete="username" required>

                        <label for="login_password">Passwort</label>
                        <input id="login_password" name="login_password" type="password" autocomplete="current-password" required>

                        <button type="submit">Einloggen</button>
                    </form>

                    <form method="post">
                        <input type="hidden" name="action" value="forgot_password">
                        <button type="submit" class="secondary">Passwort vergessen</button>
                    </form>
                    <p class="hint">Ein vergessenes Passwort wird nicht per E-Mail zurückgesetzt. Eine berechtigte Person erzeugt direkt auf dem Server einen neuen einmaligen Einrichtungscode.</p>
                <?php endif; ?>
            </div>
        </main>
    </body>
    </html>
    <?php
    exit;
}

csrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
}
