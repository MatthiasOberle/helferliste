<?php

declare(strict_types=1);

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$db = new PDO('sqlite:' . __DIR__ . '/../data/helferliste.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
require_once __DIR__ . '/app_config.php';
$appConfig = appConfig($db);

$name = (string)$appConfig['imprint_name'];
$address = (string)$appConfig['imprint_address'];
$email = (string)$appConfig['imprint_email'];
$phone = (string)$appConfig['imprint_phone'];
$website = (string)$appConfig['imprint_website'];
$imprintReady = trim($name) !== '' && trim($address) !== '' && trim($email) !== '';

?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Impressum - <?= h(appTitle($appConfig)) ?></title>
    <style>
        body { margin: 0; font-family: Arial, sans-serif; background: #f5f5f5; color: #222; line-height: 1.55; }
        .wrap { max-width: 850px; margin: 0 auto; padding: 20px 14px 40px; }
        .card { background: white; border: 1px solid #ddd; border-radius: 14px; padding: 18px; margin-bottom: 16px; }
        h1, h2 { margin-top: 0; }
        .btn { display: inline-block; border-radius: 10px; padding: 10px 13px; background: #555; color: white; font-weight: bold; text-decoration: none; }
        .hint { background: #fff7d6; border: 1px solid #e0c36d; padding: 12px; border-radius: 10px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>Impressum</h1>
        <p><a class="btn" href="index.php">Zurück zu <?= h(appTitle($appConfig)) ?></a></p>
    </div>

    <div class="card">
        <h2>Anbieterangaben / Impressum</h2>
        <?php if (!$imprintReady): ?>
            <p class="hint"><strong>Impressum noch nicht eingerichtet.</strong><br>
                Die betreibende Organisation muss die erforderlichen Angaben im Adminbereich unter
                <em>System → Einstellungen → Impressum &amp; Datenschutz</em> ergänzen.</p>
        <?php else: ?>
            <p>
                <?= h($name) ?><br>
                <?= nl2br(h($address)) ?>
            </p>
        <?php endif; ?>
    </div>

    <?php if ($imprintReady): ?>
        <div class="card">
            <h2>Kontakt</h2>
            <p>
                E-Mail: <a href="mailto:<?= h($email) ?>"><?= h($email) ?></a>
                <?php if (trim($website) !== ''): ?><br>Website: <?= h($website) ?><?php endif; ?>
                <?php if (trim($phone) !== ''): ?><br>Telefon: <?= h($phone) ?><?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Hinweis</h2>
        <p>Diese Seite dient der internen Organisation von Helferinnen und Helfern für Veranstaltungen.</p>
    </div>
</div>
</body>
</html>
