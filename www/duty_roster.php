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
$db->exec('PRAGMA foreign_keys = ON');
$db->exec('PRAGMA busy_timeout = 5000');

require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/duty_roster_lib.php';

$appConfig = appConfig($db);
$error = '';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if (isset($_GET['logout'])) {
    unset($_SESSION['duty_roster_access_code_id'], $_SESSION['duty_roster_authenticated_at']);
    header('Location: duty_roster.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (!dutyRosterVerifyPublicCsrf()) {
        $error = 'Ungültige oder abgelaufene Formular-Sitzung. Bitte lade die Seite neu und versuche es erneut.';
    } elseif (($remaining = dutyRosterRateLimitRemaining($db)) > 0) {
        $error = 'Zu viele Fehlversuche. Bitte warte noch etwa ' . max(1, (int)ceil($remaining / 60)) . ' Minute(n).';
    } else {
        $access = dutyRosterFindEligibleAccess($db, (string)($_POST['access'] ?? ''));
        if ($access === null) {
            dutyRosterRecordFailure($db);
            $error = 'Der Zugriff ist mit diesen Angaben nicht möglich. Prüfe bitte Code oder E-Mail-Adresse.';
        } else {
            session_regenerate_id(true);
            $_SESSION['duty_roster_access_code_id'] = (int)$access['id'];
            $_SESSION['duty_roster_authenticated_at'] = time();
            dutyRosterClearFailures($db);
            header('Location: duty_roster.php');
            exit;
        }
    }
}

$published = dutyRosterIsPublished($db);
$authorized = $published && dutyRosterSessionIsAuthorized($db);
$heading = appSetting($db, 'duty_roster_public_heading');
$intro = appSetting($db, 'duty_roster_public_intro');
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title><?= h($heading) ?> - <?= h(appTitle($appConfig)) ?></title>
    <style>
        :root {
            --primary: <?= h(appDesignColor($appConfig, 'primary_color')) ?>;
            --primary-dark: <?= h(appDesignColor($appConfig, 'primary_color_dark')) ?>;
            --page-bg: <?= h(appDesignColor($appConfig, 'page_background_color')) ?>;
            --text: <?= h(appDesignColor($appConfig, 'page_text_color')) ?>;
            --card: <?= h(appDesignColor($appConfig, 'card_background_color')) ?>;
            --muted: <?= h(appDesignColor($appConfig, 'muted_text_color')) ?>;
        }
        * { box-sizing:border-box; }
        body { margin:0; font-family:<?= h(appFontFamily($appConfig)) ?>; color:var(--text); background:var(--page-bg); line-height:1.5; }
        .page { width:min(820px,calc(100% - 24px)); margin:0 auto; padding:18px 0 38px; }
        .hero { position:relative; overflow:hidden; min-height:190px; border-radius:24px; color:#fff; background:linear-gradient(135deg,<?= h(appDesignColor($appConfig, 'hero_color_start')) ?>,<?= h(appDesignColor($appConfig, 'hero_color_end')) ?>); }
        .hero-inner { display:grid; grid-template-columns:minmax(0,1fr) 210px; align-items:center; min-height:190px; padding:28px; }
        .hero h1 { margin:0 0 7px; font-size:clamp(1.8rem,5vw,3rem); }
        .hero p { margin:4px 0; color:rgba(255,255,255,.82); font-weight:700; }
        .hero img { display:block; width:260px; max-height:180px; object-fit:contain; transform:translateX(20px); }
        .card { margin-top:16px; padding:25px; border:1px solid #d8dee8; border-radius:20px; background:var(--card); box-shadow:0 8px 25px rgba(15,23,42,.07); }
        h2 { margin-top:0; }
        .muted { color:var(--muted); }
        .notice { padding:13px 15px; margin-bottom:16px; border:1px solid; border-radius:12px; }
        .error { color:#991b1b; background:#fff1f2; border-color:#fecaca; }
        .success { color:#166534; background:#ecfdf3; border-color:#86efac; }
        label { display:block; margin:15px 0 7px; font-weight:800; }
        input { width:100%; min-height:48px; padding:11px 12px; border:1px solid #aeb7c2; border-radius:11px; font:inherit; }
        .button-row { display:flex; flex-wrap:wrap; gap:10px; margin-top:17px; }
        .btn, button { display:inline-flex; align-items:center; justify-content:center; min-height:46px; border:0; border-radius:11px; padding:11px 16px; background:var(--primary); color:#fff; font:inherit; font-weight:800; text-decoration:none; cursor:pointer; }
        .secondary { background:#4b5563; }
        .footer { padding:18px 5px 0; text-align:center; color:var(--muted); font-size:.92rem; }
        .footer a { margin:0 8px; color:#475569; font-weight:700; text-decoration:none; }
        @media (max-width:640px) {
            .page { width:calc(100% - 16px); padding-top:8px; }
            .hero-inner { grid-template-columns:minmax(0,1fr) 105px; padding:20px 17px; }
            .hero img { width:175px; transform:translateX(-5px); }
            .card { padding:18px; border-radius:18px; }
            .button-row { display:grid; }
            .button-row > * { width:100%; }
        }
    </style>
</head>
<body>
<div class="page">
    <header class="hero">
        <div class="hero-inner">
            <div>
                <h1><?= h(appTitle($appConfig)) ?></h1>
                <?php if (appSubtitle($appConfig) !== ''): ?><p><?= h(appSubtitle($appConfig)) ?></p><?php endif; ?>
                <?php if (appEventDateRange($appConfig) !== ''): ?><p><?= h(appEventDateRange($appConfig)) ?></p><?php endif; ?>
            </div>
            <img src="<?= h(appHeroImage($appConfig)) ?>" alt="<?= h(appText($appConfig, 'hero_image_alt')) ?>">
        </div>
    </header>

    <main class="card">
        <h2><?= h($heading) ?></h2>
        <?php if (!$published): ?>
            <p>Die Diensteinteilung ist derzeit nicht veröffentlicht.</p>
            <div class="button-row"><a class="btn secondary" href="index.php">Zurück zur Helferliste</a></div>
        <?php elseif ($authorized): ?>
            <div class="notice success">Deine Rückmeldung wurde bestätigt. Du kannst die Diensteinteilung jetzt ansehen oder herunterladen.</div>
            <p class="muted">Die PDF enthält personenbezogene Angaben. Bitte gib sie nur an berechtigte Mitglieder weiter.</p>
            <div class="button-row">
                <a class="btn" href="duty_roster_file.php" target="_blank" rel="noopener">PDF im Browser öffnen</a>
                <a class="btn secondary" href="duty_roster_file.php?download=1">PDF herunterladen</a>
                <a class="btn secondary" href="duty_roster.php?logout=1">Zugang beenden</a>
            </div>
        <?php else: ?>
            <p><?= nl2br(h($intro)) ?></p>
            <p class="muted">Der Zugriff ist nur möglich, wenn mit diesem Code beziehungsweise dieser E-Mail-Adresse bereits eine Rückmeldung abgegeben wurde - unabhängig davon, ob du hilfst oder keine Zeit hattest.</p>
            <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="csrf_token" value="<?= h(dutyRosterPublicCsrfToken()) ?>">
                <label for="access">Persönlicher Code oder hinterlegte E-Mail-Adresse</label>
                <input id="access" name="access" type="text" maxlength="254" autocomplete="username" required>
                <div class="button-row">
                    <button type="submit">Zugriff prüfen</button>
                    <a class="btn secondary" href="index.php">Zurück</a>
                </div>
            </form>
        <?php endif; ?>
    </main>

    <footer class="footer">
        <a href="datenschutz.php"><?= h(appText($appConfig, 'text_privacy_link')) ?></a>
        <a href="impressum.php"><?= h(appText($appConfig, 'text_imprint_link')) ?></a>
        <p>Veranstaltet von <?= h((string)$appConfig['event_organizer']) ?></p>
    </footer>
</div>
</body>
</html>
