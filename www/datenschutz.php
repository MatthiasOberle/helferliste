<?php

declare(strict_types=1);

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$db = new PDO('sqlite:' . __DIR__ . '/../data/helferliste.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
require_once __DIR__ . '/app_config.php';
$appConfig = appConfig($db);

$contactName = (string)$appConfig['privacy_contact_name'];
$contactEmail = (string)$appConfig['privacy_contact_email'];
$appName = appTitle($appConfig);
$eventText = trim(appSubtitle($appConfig)) !== '' ? appSubtitle($appConfig) : 'die jeweilige Veranstaltung';
$privacyReady = trim($contactName) !== '' && trim($contactEmail) !== '';
$responsibleText = trim($contactName) !== '' ? $contactName : 'die betreibende Organisation';

?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Datenschutz - <?= h($appName) ?></title>
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
        <h1>Datenschutzhinweise zu <?= h($appName) ?></h1>
        <p><a class="btn" href="index.php">Zurück zu <?= h($appName) ?></a></p>
    </div>

    <div class="card">
        <h2>1. Verantwortlicher</h2>
        <?php if (!$privacyReady): ?>
            <p class="hint"><strong>Datenschutzkontakt noch nicht eingerichtet.</strong><br>
                Die betreibende Organisation muss Name und Kontaktadresse im Adminbereich unter
                <em>System → Einstellungen → Impressum &amp; Datenschutz</em> ergänzen.</p>
        <?php else: ?>
            <p>Verantwortlich für <?= h($appName) ?> ist <?= h($contactName) ?>.</p>
            <p>Kontakt: <a href="mailto:<?= h($contactEmail) ?>"><?= h($contactEmail) ?></a></p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>2. Zweck der Verarbeitung</h2>
        <p>Diese Helferliste dient ausschließlich der Organisation von Helferinnen und Helfern für <?= h($eventText) ?> beziehungsweise vergleichbare Veranstaltungen.</p>
        <p>Die Daten werden verwendet, um Rückmeldungen zu erfassen, Schichten zu planen, offene Rückmeldungen nachzuverfolgen, eine geschützte Diensteinteilung bereitzustellen und die Veranstaltung intern auszuwerten.</p>
    </div>

    <div class="card">
        <h2>3. Welche Daten werden gespeichert?</h2>
        <p>Gespeichert werden je nach Nutzung:</p>
        <ul>
            <li>E-Mail-Adresse zur Zuordnung eines persönlichen Zugangscodes</li>
            <li>persönlicher Zugangscode</li>
            <li>Name der rückmeldenden Person</li>
            <li>Rückmeldung „Ich helfe“ oder „Ich habe keine Zeit“</li>
            <li>ausgewählte Schichten und Springer-Schichten</li>
            <li>freiwillige Hinweise, zum Beispiel Wunschdienst oder Personenwunsch</li>
            <li>technische Zeitpunkte, zum Beispiel Erstellung des Codes, Login, Rückmeldung oder Änderungswunsch</li>
            <li>bei fehlgeschlagenen Zugriffsversuchen auf die Diensteinteilung ein nicht unmittelbar lesbarer technischer Prüfwert aus IP-Adresse und Browserkennung; die IP-Adresse selbst wird dabei nicht in der Datenbank gespeichert</li>
        </ul>
    </div>

    <div class="card">
        <h2>4. Geschützte Diensteinteilung</h2>
        <p>Eine veröffentlichte Diensteinteilung kann Namen und zugewiesene Dienste enthalten. Sie wird nicht unter einer frei aufrufbaren Dateiadresse bereitgestellt. Zugriff erhalten nur Personen, zu deren persönlichem Code bereits eine Rückmeldung gespeichert ist. Das gilt sowohl für „Ich helfe“ als auch für „Ich habe keine Zeit“.</p>
        <p>Zur Begrenzung wiederholter Fehlversuche wird der technische Prüfwert vorübergehend gespeichert und nach spätestens 24 Stunden automatisch entfernt. Eine erfolgreiche Zugriffsfreigabe gilt höchstens zwei Stunden und wird bei einer Aufhebung der Veröffentlichung sofort unwirksam.</p>
    </div>

    <div class="card">
        <h2>5. Statistische Auswertung</h2>
        <p>Zusätzlich werden einfache technische und statistische Daten gespeichert, um nach der Veranstaltung anonym beziehungsweise zusammengefasst auswerten zu können, wie die Helferliste genutzt wurde.</p>
        <p>Dazu gehört insbesondere, ob die Seite ungefähr mit einem Mobilgerät, Tablet oder Desktop-Computer aufgerufen wurde. E-Mail-Öffnungen werden nicht verfolgt. Der vollständige User-Agent wird nicht gespeichert. Es wird daraus keine öffentliche personenbezogene Auswertung erstellt.</p>
    </div>


    <div class="card">
        <h2>6. Zugriff auf die Daten</h2>
        <p>Die Anwendung läuft auf einem von der betreibenden Organisation gewählten Server. Zugriff auf den Administrationsbereich und die gespeicherten Daten haben nur <?= h($responsibleText) ?> beziehungsweise ausdrücklich berechtigte Administratoren.</p>
        <p>Die Daten werden nicht verkauft und nicht für Werbung verwendet.</p>
    </div>

    <div class="card">
        <h2>7. Löschung der Daten</h2>
        <p>Nach Abschluss der Veranstaltung können die Eventdaten im Adminbereich gelöscht werden. Dabei werden insbesondere Rückmeldungen, E-Mail-Adressen, Zugangscodes, Änderungswünsche und Aufrufstatistiken entfernt. Die betreibende Organisation legt die konkrete Aufbewahrungsdauer fest und dokumentiert sie in ihren Datenschutzhinweisen.</p>
        <p>Die Schichtvorlagen können erhalten bleiben, damit die Helferliste für spätere Veranstaltungen erneut genutzt werden kann.</p>
    </div>

    <div class="card">
        <h2>8. Auskunft und Berichtigung</h2>
        <p>Betroffene Personen können eine Auskunft oder Berichtigung ihrer gespeicherten Daten anfragen. Änderungen an bereits gespeicherten Rückmeldungen erfolgen nicht automatisch, sondern über einen Änderungswunsch oder direkte Rücksprache.</p>
    </div>

    <div class="card">
        <h2>Technische Umsetzung</h2>
        <p>Die Helferliste ist ein Projekt von <a href="<?= h(mowstBrand()['url']) ?>" target="_blank" rel="noopener"><?= h(mowstBrand()['label']) ?></a>. Verantwortlich für die mit dieser Installation verarbeiteten Daten bleibt die oben genannte betreibende Organisation.</p>
    </div>
</div>
</body>
</html>
