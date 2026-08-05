<?php

declare(strict_types=1);

require __DIR__ . '/auth.php';

$db = new PDO('sqlite:' . __DIR__ . '/../data/helferliste.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/duty_roster_lib.php';

appConfig($db);
dutyRosterSendPdf($db, isset($_GET['download']));
