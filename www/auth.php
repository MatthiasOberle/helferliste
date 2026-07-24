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

const ADMIN_USER = 'admin';
// Fallback-Hash für das Standardpasswort. Wird genutzt, wenn noch kein eigenes Passwort gespeichert ist.
const DEFAULT_ADMIN_PASSWORD_HASH = '$2y$12$GN/TbJDoE81yS.UaeIzENu3MYZPyr/y6Q2qKIYdjgSD5eO4liLNmy';

// Öffnet die zentrale SQLite-Verbindung für die Admin-Funktionen und erstellt fehlende Settings automatisch.
function adminDb(): PDO {
    static $db = null;

    if ($db instanceof PDO) {
        return $db;
    }

    $db = new PDO('sqlite:' . __DIR__ . '/../data/helferliste.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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

// Holt den aktuell gültigen Passwort-Hash; ohne gespeicherten Wert gilt das Standardpasswort.
function getAdminPasswordHash(): string {
    try {
        return getSetting(adminDb(), 'admin_password_hash', DEFAULT_ADMIN_PASSWORD_HASH) ?: DEFAULT_ADMIN_PASSWORD_HASH;
    } catch (Throwable $e) {
        // Falls die Settings-Tabelle nicht erreichbar ist, bleibt der bekannte Fallback nutzbar.
        return DEFAULT_ADMIN_PASSWORD_HASH;
    }
}

// Setzt ein neues Admin-Passwort. Gespeichert wird nur der Hash, nicht das Klartextpasswort.
function setAdminPassword(string $password): void {
    if (strlen($password) < 6) {
        throw new RuntimeException('Das Passwort muss mindestens 6 Zeichen lang sein.');
    }

    setSetting(adminDb(), 'admin_password_hash', password_hash($password, PASSWORD_DEFAULT));
}

function adminPasswordIsDefault(): bool {
    return hash_equals(getAdminPasswordHash(), DEFAULT_ADMIN_PASSWORD_HASH);
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

// Passwort-vergessen setzt nichts automatisch zurück. Der Reset erfolgt bewusst nur direkt am Server.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'forgot_password') {
    $error = 'Passwort vergessen? Bitte wende dich an den Systemadministrator. Das Passwort kann bei Bedarf per SSH oder SFTP manuell zurückgesetzt werden.';
}

// Login mit einfacher Sperre nach mehreren Fehlversuchen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_user'], $_POST['login_password'])) {
    $now = time();
    $lockedUntil = (int)($_SESSION['admin_login_locked_until'] ?? 0);

    if ($lockedUntil > $now) {
        $error = 'Zu viele Fehlversuche. Bitte kurz warten und erneut versuchen.';
    } elseif ($_POST['login_user'] === ADMIN_USER && password_verify((string)$_POST['login_password'], getAdminPasswordHash())) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_login_attempts'] = 0;
        unset($_SESSION['admin_login_locked_until']);
        csrfToken();
        header('Location: admin.php');
        exit;
    } else {
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
        <title>Admin Login</title>
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
                <h1>Admin Login</h1>

                <?php if ($error !== ''): ?>
                    <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($success !== ''): ?>
                    <div class="success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <form method="post">
                    <label for="login_user">Benutzername</label>
                    <input id="login_user" name="login_user" type="text" required>

                    <label for="login_password">Passwort</label>
                    <input id="login_password" name="login_password" type="password" required>

                    <button type="submit">Einloggen</button>
                </form>

                <form method="post">
                    <input type="hidden" name="action" value="forgot_password">
                    <button type="submit" class="secondary">Passwort vergessen</button>
                </form>
                <p class="hint">Hinweis: Ein vergessenes Passwort wird nicht online zurückgesetzt. Bitte den Systemadministrator ansprechen; der Reset erfolgt direkt am Server per SSH oder SFTP.</p>
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
