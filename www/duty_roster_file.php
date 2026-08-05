<?php

declare(strict_types=1);

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit('Methode nicht erlaubt.');
}

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
require_once __DIR__ . '/duty_roster_lib.php';

appConfig($db);
if (!dutyRosterSessionIsAuthorized($db)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Für diese Diensteinteilung besteht keine gültige Zugriffsberechtigung.');
}

dutyRosterSendPdf($db, isset($_GET['download']));
