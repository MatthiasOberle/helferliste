<?php

declare(strict_types=1);

require __DIR__ . '/auth.php';

$db = new PDO('sqlite:' . __DIR__ . '/../data/helferliste.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/tracking.php';
$appConfig = appConfig($db);

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function limitText(string $value, int $maxLength): string {
    $value = trim($value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }
    return substr($value, 0, $maxLength);
}

function normalizeHexColor(string $value, string $fallback): string {
    $value = trim($value);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : $fallback;
}

function heroImageGallery(): array {
    $images = [];
    $directories = [
        __DIR__ . '/assets' => 'assets',
        __DIR__ . '/assets/uploads' => 'assets/uploads',
    ];

    foreach ($directories as $directory => $publicPrefix) {
        if (!is_dir($directory)) {
            continue;
        }
        foreach (scandir($directory) ?: [] as $file) {
            if ($file === '.' || $file === '..' || !preg_match('/\.(?:png|jpe?g|webp|gif)$/i', $file)) {
                continue;
            }
            $fullPath = $directory . '/' . $file;
            if (!is_file($fullPath) || @getimagesize($fullPath) === false) {
                continue;
            }
            $relativePath = $publicPrefix . '/' . $file;
            $images[$relativePath] = [
                'path' => $relativePath,
                'name' => pathinfo($file, PATHINFO_FILENAME),
                'uploaded' => $publicPrefix === 'assets/uploads',
            ];
        }
    }

    ksort($images, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($images);
}

function validGalleryImage(string $path): bool {
    foreach (heroImageGallery() as $image) {
        if (hash_equals((string)$image['path'], $path)) {
            return true;
        }
    }
    return false;
}

function safeImageBaseName(string $originalName): string {
    $base = pathinfo($originalName, PATHINFO_FILENAME);
    $base = function_exists('iconv') ? (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) : $base;
    $base = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '-', $base));
    return trim($base, '-') ?: 'bild';
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'select_hero_image') {
        $selectedImage = trim((string)($_POST['hero_image'] ?? ''));
        if (!validGalleryImage($selectedImage)) {
            $error = 'Das ausgewählte Bild wurde nicht gefunden.';
        } else {
            saveAppSetting($db, 'hero_image', $selectedImage);
            logEvent($db, 'hero_image_selected', 'Kopfbild wurde aus der Galerie ausgewählt.');
            $message = 'Kopfbild wurde ausgewählt.';
            $appConfig = appConfig($db);
        }
    }

    if ($action === 'upload_hero_image') {
        $upload = $_FILES['hero_image_upload'] ?? null;
        if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'Bitte wähle eine Bilddatei aus.';
        } elseif ((int)($upload['size'] ?? 0) > 8 * 1024 * 1024) {
            $error = 'Das Bild ist größer als 8 MB.';
        } elseif (!is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
            $error = 'Der Upload konnte nicht geprüft werden.';
        } else {
            $mimeTypes = [
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            ];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file((string)$upload['tmp_name']);
            $extension = $mimeTypes[$mime] ?? '';

            if ($extension === '' || @getimagesize((string)$upload['tmp_name']) === false) {
                $error = 'Erlaubt sind PNG-, JPG-, WebP- und GIF-Bilder.';
            } else {
                $uploadDirectory = __DIR__ . '/assets/uploads';
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                    $error = 'Der Upload-Ordner konnte nicht angelegt werden.';
                } else {
                    $baseName = safeImageBaseName((string)($upload['name'] ?? 'bild'));
                    $fileName = $baseName . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . $extension;
                    $target = $uploadDirectory . '/' . $fileName;
                    if (!move_uploaded_file((string)$upload['tmp_name'], $target)) {
                        $error = 'Das Bild konnte nicht gespeichert werden.';
                    } else {
                        chmod($target, 0644);
                        $publicPath = 'assets/uploads/' . $fileName;
                        saveAppSetting($db, 'hero_image', $publicPath);
                        logEvent($db, 'hero_image_uploaded', 'Neues Kopfbild wurde hochgeladen.');
                        $message = 'Bild wurde hochgeladen und als Kopfbild ausgewählt.';
                        $appConfig = appConfig($db);
                    }
                }
            }
        }
    }

    // Allgemeine Projekt- und Eventangaben speichern. Diese Werte werden auf der öffentlichen Seite, in Codes, Impressum und Datenschutz genutzt.
    if ($action === 'save_app_settings') {
        $fields = [
            'app_name' => 80,
            'event_organizer' => 120,
            'event_name' => 160,
            'public_base_url' => 200,
            'public_login_info_text' => 1200,
            'hero_image_alt' => 160,
            'text_login_heading' => 160,
            'text_login_intro' => 500,
            'text_code_label' => 160,
            'text_code_help' => 500,
            'text_login_button' => 120,
            'text_overview_summary' => 180,
            'text_overview_intro' => 500,
            'text_overview_login_label' => 160,
            'text_overview_button' => 120,
            'text_saved_heading' => 180,
            'text_saved_intro' => 500,
            'text_change_info' => 600,
            'text_change_label' => 160,
            'text_change_placeholder' => 300,
            'text_change_button' => 120,
            'text_entry_heading' => 160,
            'text_entry_intro' => 500,
            'text_name_label' => 120,
            'text_name_placeholder' => 160,
            'text_choice_label' => 120,
            'text_summary_name_label' => 120,
            'text_summary_status_label' => 120,
            'text_help_yes' => 120,
            'text_help_yes_description' => 300,
            'text_help_no' => 120,
            'text_help_no_description' => 300,
            'text_shifts_heading' => 160,
            'text_shifts_intro' => 500,
            'text_normal_shifts_heading' => 160,
            'text_normal_shifts_intro' => 400,
            'text_flexible_shifts_heading' => 160,
            'text_flexible_shifts_intro' => 400,
            'text_no_normal_shift' => 200,
            'text_no_flexible_shift' => 200,
            'text_full' => 80,
            'text_free' => 80,
            'text_occupied' => 80,
            'text_space_free' => 120,
            'text_no_normal_shifts_available' => 300,
            'text_no_flexible_shifts_available' => 300,
            'text_note_heading' => 160,
            'text_note_intro' => 400,
            'text_note_label' => 180,
            'text_note_placeholder' => 300,
            'text_save_button' => 160,
            'text_switch_code' => 120,
            'text_after_save_info' => 600,
            'text_public_overview_heading' => 180,
            'text_public_overview_intro' => 600,
            'text_privacy_link' => 100,
            'text_imprint_link' => 100,
            'imprint_name' => 120,
            'imprint_address' => 500,
            'imprint_email' => 160,
            'imprint_phone' => 80,
            'imprint_website' => 160,
            'privacy_contact_name' => 120,
            'privacy_contact_email' => 160,
        ];

        foreach ($fields as $field => $maxLength) {
            saveAppSetting($db, $field, limitText((string)($_POST[$field] ?? ''), $maxLength));
        }

        $colorFields = [
            'primary_color' => '#b00020',
            'primary_color_dark' => '#8d001a',
            'primary_color_soft' => '#fff1f3',
            'hero_color_start' => '#232323',
            'hero_color_end' => '#410a12',
            'page_background_color' => '#f5f6f8',
            'page_text_color' => '#1f2933',
            'card_background_color' => '#ffffff',
            'muted_text_color' => '#64748b',
            'occupancy_color_low' => '#22c55e',
            'occupancy_color_medium' => '#f59e0b',
            'occupancy_color_high' => '#b00020',
            'occupancy_color_full' => '#b00020',
        ];
        foreach ($colorFields as $field => $fallback) {
            saveAppSetting($db, $field, normalizeHexColor((string)($_POST[$field] ?? ''), $fallback));
        }

        $font = (string)($_POST['font_family'] ?? '');
        $allowedFonts = [
            'Arial, Helvetica, sans-serif',
            'Verdana, Geneva, sans-serif',
            'Tahoma, Geneva, sans-serif',
            '"Trebuchet MS", Arial, sans-serif',
            'Georgia, "Times New Roman", serif',
            'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
        ];
        saveAppSetting($db, 'font_family', in_array($font, $allowedFonts, true) ? $font : appDefaults()['font_family']);

        logEvent($db, 'app_settings_saved', 'Projekt-Einstellungen wurden geändert.');
        $message = 'Einstellungen wurden gespeichert.';
        $appConfig = appConfig($db);
    }
}

$heroImages = heroImageGallery();

?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="<?= h(adminViewportContent()) ?>">
    <title>Einstellungen - <?= h(appTitle($appConfig)) ?></title>
    <style>
        :root { --primary: <?= h(appDesignColor($appConfig, 'primary_color')) ?>; --border: #ddd; --bg: #f5f5f5; --good: #dff3e4; --bad: #f8d7da; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: var(--bg); color: #222; line-height: 1.45; }
        .wrap { width: min(1680px, calc(100% - 28px)); margin: 0 auto; padding: 20px 0 40px; }
        .card { background: white; border: 1px solid var(--border); border-radius: 14px; padding: 16px; margin-bottom: 16px; box-shadow: 0 1px 8px rgba(0,0,0,.04); }
        h1, h2, h3 { margin-top: 0; }
        .btn, button { display: inline-block; border: 0; border-radius: 10px; padding: 10px 13px; background: var(--primary); color: white; font-weight: bold; text-decoration: none; cursor: pointer; }
        .btn.secondary, button.secondary { background: #555; }
        .notice { padding: 12px; border-radius: 10px; margin-bottom: 14px; }
        .success { background: var(--good); border: 1px solid #9ed3a8; }
        .error { background: var(--bad); border: 1px solid #e2a0a0; }
        .muted { color: #666; font-size: .92rem; }
        .admin-nav { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 12px; }
        .admin-nav a { display: inline-block; border-radius: 999px; padding: 9px 12px; background: #eee; color: #222; font-weight: bold; text-decoration: none; }
        .admin-nav a.active { background: var(--primary); color: white; }
        .admin-nav a.right { margin-left: auto; }
        .settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; }
        .field label, label.block-label { display: block; font-weight: bold; margin-bottom: 5px; }
        textarea, input[type="text"], select { width: 100%; border: 1px solid #bbb; border-radius: 8px; padding: 8px; font: inherit; background:#fff; }
        input[type="color"] { width: 100%; min-height: 44px; border: 1px solid #bbb; border-radius: 8px; padding: 4px; background: #fff; cursor: pointer; }
        textarea { resize: vertical; }
        .section-divider { border-top: 1px solid #eee; margin-top: 18px; padding-top: 18px; }
        .color-preview { display: grid; grid-template-columns: repeat(4, minmax(120px, 1fr)); gap: 10px; margin-top: 12px; }
        .color-sample { border: 1px solid var(--border); border-radius: 10px; padding: 10px; background: #fafafa; }
        .color-sample span { display: block; height: 12px; border-radius: 999px; margin-top: 8px; }
        .upload-zone { display:grid; place-items:center; min-height:180px; border:2px dashed #aab3c2; border-radius:16px; padding:22px; text-align:center; background:#f8fafc; cursor:pointer; transition:.2s ease; }
        .upload-zone.dragover { border-color:var(--primary); background:#fff1f3; transform:translateY(-1px); }
        .upload-zone strong { display:block; font-size:1.08rem; margin-bottom:6px; }
        .upload-zone input { position:absolute; inline-size:1px; block-size:1px; opacity:0; pointer-events:none; }
        .upload-file-name { display:block; margin-top:10px; color:#334155; font-weight:700; overflow-wrap:anywhere; }
        .upload-actions { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-top:12px; }
        .image-gallery { display:grid; grid-template-columns:repeat(auto-fill, minmax(170px, 1fr)); gap:14px; margin:14px 0; }
        .image-option { position:relative; display:grid; grid-template-rows:150px auto; gap:9px; min-width:0; border:2px solid #d8dee8; border-radius:14px; padding:10px; background:#fff; cursor:pointer; }
        .image-option:has(input:checked) { border-color:var(--primary); box-shadow:0 0 0 3px rgba(176,0,32,.12); }
        .image-option img { width:100%; height:150px; object-fit:contain; border-radius:10px; background:linear-gradient(135deg,#eef2f7,#fff); }
        .image-option input { position:absolute; top:10px; left:10px; width:20px; height:20px; accent-color:var(--primary); }
        .image-option span { display:block; font-weight:800; overflow-wrap:anywhere; }
        .image-option small { display:block; color:#64748b; }
        .text-settings { display:grid; gap:14px; }
        .text-settings-group { border:1px solid #e5e7eb; border-radius:14px; padding:14px; background:#fafafa; }
        .text-settings-group h4 { margin:0 0 12px; }
        @media (max-width: 720px) { .color-preview { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 720px) { .admin-nav a.right { margin-left: 0; } }
    </style>
</head>
<body class="<?= h(adminBodyClass()) ?>">
<div class="wrap">
    <div class="card">
        <h1>Einstellungen</h1>
        <?php if (appSubtitle($appConfig) !== ''): ?><p class="muted"><?= h(appSubtitle($appConfig)) ?></p><?php endif; ?>
        <?php adminNav('settings'); ?>
    </div>

    <?php if ($message !== ''): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

    <div class="card" id="kopfbild">
        <h2>Kopfbild verwalten</h2>
        <p class="muted">Ziehe ein eigenes Bild in das Feld oder wähle ohne technisches Vorwissen eines der vorhandenen Motive aus. PNG, JPG, WebP und GIF bis 8 MB sind erlaubt.</p>

        <form method="post" enctype="multipart/form-data" id="heroUploadForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="upload_hero_image">
            <label class="upload-zone" id="heroDropZone" for="hero_image_upload">
                <span>
                    <strong>Bild hier ablegen</strong>
                    oder klicken und eine Datei auswählen
                    <span class="upload-file-name" id="heroFileName">Noch keine Datei ausgewählt</span>
                </span>
                <input type="file" id="hero_image_upload" name="hero_image_upload" accept="image/png,image/jpeg,image/webp,image/gif" required>
            </label>
            <div class="upload-actions">
                <button type="submit">Bild hochladen und verwenden</button>
                <span class="muted">Empfohlen: quadratisch, transparenter Hintergrund, mindestens 800 × 800 Pixel.</span>
            </div>
        </form>

        <div class="section-divider">
            <h3>Vorhandene Bilder</h3>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="select_hero_image">
                <div class="image-gallery">
                    <?php foreach ($heroImages as $image): ?>
                        <label class="image-option">
                            <input type="radio" name="hero_image" value="<?= h((string)$image['path']) ?>" <?= appHeroImage($appConfig) === $image['path'] ? 'checked' : '' ?>>
                            <img src="<?= h((string)$image['path']) ?>" alt="">
                            <span><?= h((string)$image['name']) ?></span>
                            <small><?= $image['uploaded'] ? 'Eigenes Upload-Bild' : 'Mitgeliefertes Motiv' ?></small>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit">Ausgewähltes Bild verwenden</button>
            </form>
        </div>
    </div>

    <div class="card">
        <h2>Projektangaben, Infotext, Impressum und Datenschutz</h2>
        <p class="muted">Diese Angaben machen die Anwendung wiederverwendbar. Organisation, Eventname, öffentlicher Infotext und Kontaktdaten werden zentral gepflegt.</p>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_app_settings">

            <h3>Allgemein</h3>
            <div class="settings-grid">
                <div class="field"><label>Name der Anwendung</label><input type="text" name="app_name" maxlength="80" value="<?= h((string)$appConfig['app_name']) ?>"></div>
                <div class="field"><label>Organisation / Veranstalter</label><input type="text" name="event_organizer" maxlength="120" value="<?= h((string)$appConfig['event_organizer']) ?>"></div>
                <div class="field"><label>Eventname / Untertitel</label><input type="text" name="event_name" maxlength="160" value="<?= h((string)$appConfig['event_name']) ?>"></div>
                <div class="field"><label>Öffentliche Basis-URL</label><input type="text" name="public_base_url" maxlength="200" value="<?= h((string)$appConfig['public_base_url']) ?>"></div>
            </div>

            <div class="section-divider">
                <h3>Infotext auf der Code-Seite</h3>
                <p class="muted">Dieser Text erscheint auf der öffentlichen Anmeldeseite oberhalb des Code-Feldes. Wenn das Feld leer bleibt, wird dort nichts angezeigt.</p>
                <label class="block-label" for="public_login_info_text">Infotext optional</label>
                <textarea id="public_login_info_text" name="public_login_info_text" maxlength="1200" rows="5" placeholder="Optionaler Hinweis für Helferinnen und Helfer"><?= h((string)$appConfig['public_login_info_text']) ?></textarea>
            </div>

            <div class="section-divider" id="design">
                <h3>Design</h3>
                <p class="muted">Farben, Schrift und Kopfbild wirken direkt auf die öffentliche Seite. Damit kann die Helferliste ohne Änderungen am Programm für Vereine, Hilfsorganisationen, Schulen oder andere Veranstaltungen gestaltet werden.</p>
                <div class="settings-grid">
                    <div class="field"><label>Hauptfarbe</label><input type="color" name="primary_color" value="<?= h(appDesignColor($appConfig, 'primary_color')) ?>"></div>
                    <div class="field"><label>Dunkle Hauptfarbe</label><input type="color" name="primary_color_dark" value="<?= h(appDesignColor($appConfig, 'primary_color_dark')) ?>"></div>
                    <div class="field"><label>Helle Akzentfläche</label><input type="color" name="primary_color_soft" value="<?= h(appDesignColor($appConfig, 'primary_color_soft')) ?>"></div>
                    <div class="field"><label>Kopfbereich links</label><input type="color" name="hero_color_start" value="<?= h(appDesignColor($appConfig, 'hero_color_start')) ?>"></div>
                    <div class="field"><label>Kopfbereich rechts</label><input type="color" name="hero_color_end" value="<?= h(appDesignColor($appConfig, 'hero_color_end')) ?>"></div>
                    <div class="field"><label>Seitenhintergrund</label><input type="color" name="page_background_color" value="<?= h(appDesignColor($appConfig, 'page_background_color')) ?>"></div>
                    <div class="field"><label>Textfarbe</label><input type="color" name="page_text_color" value="<?= h(appDesignColor($appConfig, 'page_text_color')) ?>"></div>
                    <div class="field"><label>Kartenhintergrund</label><input type="color" name="card_background_color" value="<?= h(appDesignColor($appConfig, 'card_background_color')) ?>"></div>
                    <div class="field"><label>Hinweistext</label><input type="color" name="muted_text_color" value="<?= h(appDesignColor($appConfig, 'muted_text_color')) ?>"></div>
                    <div class="field">
                        <label for="font_family">Schriftart</label>
                        <select id="font_family" name="font_family">
                            <?php foreach ([
                                'Arial, Helvetica, sans-serif' => 'Arial',
                                'Verdana, Geneva, sans-serif' => 'Verdana',
                                'Tahoma, Geneva, sans-serif' => 'Tahoma',
                                '"Trebuchet MS", Arial, sans-serif' => 'Trebuchet MS',
                                'Georgia, "Times New Roman", serif' => 'Georgia',
                                'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif' => 'Systemschrift',
                            ] as $fontValue => $fontLabel): ?>
                                <option value="<?= h($fontValue) ?>" <?= appFontFamily($appConfig) === $fontValue ? 'selected' : '' ?>><?= h($fontLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field"><label>Bildbeschreibung für Barrierefreiheit</label><input type="text" name="hero_image_alt" maxlength="160" value="<?= h((string)$appConfig['hero_image_alt']) ?>"></div>
                </div>

                <h4 style="margin-bottom:8px;">Farben der Belegungs-Ampel</h4>
                <div class="settings-grid">
                    <div class="field"><label>Wenig belegt</label><input type="color" name="occupancy_color_low" value="<?= h(appDesignColor($appConfig, 'occupancy_color_low')) ?>"></div>
                    <div class="field"><label>Mittlere Belegung</label><input type="color" name="occupancy_color_medium" value="<?= h(appDesignColor($appConfig, 'occupancy_color_medium')) ?>"></div>
                    <div class="field"><label>Stark belegt</label><input type="color" name="occupancy_color_high" value="<?= h(appDesignColor($appConfig, 'occupancy_color_high')) ?>"></div>
                    <div class="field"><label>Voll belegt</label><input type="color" name="occupancy_color_full" value="<?= h(appDesignColor($appConfig, 'occupancy_color_full')) ?>"></div>
                </div>
                <div class="color-preview" aria-hidden="true">
                    <div class="color-sample">Wenig belegt<span style="background: <?= h(appDesignColor($appConfig, 'occupancy_color_low')) ?>;"></span></div>
                    <div class="color-sample">Mittlere Belegung<span style="background: <?= h(appDesignColor($appConfig, 'occupancy_color_medium')) ?>;"></span></div>
                    <div class="color-sample">Stark belegt<span style="background: <?= h(appDesignColor($appConfig, 'occupancy_color_high')) ?>;"></span></div>
                    <div class="color-sample">Voll belegt<span style="background: <?= h(appDesignColor($appConfig, 'occupancy_color_full')) ?>;"></span></div>
                </div>
            </div>

            <div class="section-divider" id="texte">
                <h3>Texte der öffentlichen Seite</h3>
                <p class="muted">Alle wichtigen Überschriften, Hilfetexte, Feldbeschriftungen und Schaltflächen können hier ohne Programmierkenntnisse geändert werden.</p>
                <div class="text-settings">
                    <div class="text-settings-group">
                        <h4>Code-Eingabe und Helferübersicht</h4>
                        <div class="settings-grid">
                            <div class="field"><label>Überschrift</label><input type="text" name="text_login_heading" value="<?= h(appText($appConfig, 'text_login_heading')) ?>"></div>
                            <div class="field"><label>Einleitung</label><textarea name="text_login_intro" rows="2"><?= h(appText($appConfig, 'text_login_intro')) ?></textarea></div>
                            <div class="field"><label>Code-Feld</label><input type="text" name="text_code_label" value="<?= h(appText($appConfig, 'text_code_label')) ?>"></div>
                            <div class="field"><label>Code-Hilfe</label><textarea name="text_code_help" rows="2"><?= h(appText($appConfig, 'text_code_help')) ?></textarea></div>
                            <div class="field"><label>Anmelde-Schaltfläche</label><input type="text" name="text_login_button" value="<?= h(appText($appConfig, 'text_login_button')) ?>"></div>
                            <div class="field"><label>Übersicht aufklappen</label><input type="text" name="text_overview_summary" value="<?= h(appText($appConfig, 'text_overview_summary')) ?>"></div>
                            <div class="field"><label>Übersicht-Hinweis</label><textarea name="text_overview_intro" rows="2"><?= h(appText($appConfig, 'text_overview_intro')) ?></textarea></div>
                            <div class="field"><label>Übersicht-Anmeldefeld</label><input type="text" name="text_overview_login_label" value="<?= h(appText($appConfig, 'text_overview_login_label')) ?>"></div>
                            <div class="field"><label>Übersicht-Schaltfläche</label><input type="text" name="text_overview_button" value="<?= h(appText($appConfig, 'text_overview_button')) ?>"></div>
                        </div>
                    </div>

                    <div class="text-settings-group">
                        <h4>Rückmeldung</h4>
                        <div class="settings-grid">
                            <div class="field"><label>Überschrift</label><input type="text" name="text_entry_heading" value="<?= h(appText($appConfig, 'text_entry_heading')) ?>"></div>
                            <div class="field"><label>Einleitung</label><textarea name="text_entry_intro" rows="2"><?= h(appText($appConfig, 'text_entry_intro')) ?></textarea></div>
                            <div class="field"><label>Name – Beschriftung</label><input type="text" name="text_name_label" value="<?= h(appText($appConfig, 'text_name_label')) ?>"></div>
                            <div class="field"><label>Name – Beispiel</label><input type="text" name="text_name_placeholder" value="<?= h(appText($appConfig, 'text_name_placeholder')) ?>"></div>
                            <div class="field"><label>Auswahl – Beschriftung</label><input type="text" name="text_choice_label" value="<?= h(appText($appConfig, 'text_choice_label')) ?>"></div>
                            <div class="field"><label>Zusammenfassung – Name</label><input type="text" name="text_summary_name_label" value="<?= h(appText($appConfig, 'text_summary_name_label')) ?>"></div>
                            <div class="field"><label>Zusammenfassung – Status</label><input type="text" name="text_summary_status_label" value="<?= h(appText($appConfig, 'text_summary_status_label')) ?>"></div>
                            <div class="field"><label>Zusage</label><input type="text" name="text_help_yes" value="<?= h(appText($appConfig, 'text_help_yes')) ?>"></div>
                            <div class="field"><label>Zusage – Erklärung</label><textarea name="text_help_yes_description" rows="2"><?= h(appText($appConfig, 'text_help_yes_description')) ?></textarea></div>
                            <div class="field"><label>Absage</label><input type="text" name="text_help_no" value="<?= h(appText($appConfig, 'text_help_no')) ?>"></div>
                            <div class="field"><label>Absage – Erklärung</label><textarea name="text_help_no_description" rows="2"><?= h(appText($appConfig, 'text_help_no_description')) ?></textarea></div>
                            <div class="field"><label>Speichern-Schaltfläche</label><input type="text" name="text_save_button" value="<?= h(appText($appConfig, 'text_save_button')) ?>"></div>
                            <div class="field"><label>Code wechseln</label><input type="text" name="text_switch_code" value="<?= h(appText($appConfig, 'text_switch_code')) ?>"></div>
                            <div class="field"><label>Hinweis nach dem Speichern</label><textarea name="text_after_save_info" rows="3"><?= h(appText($appConfig, 'text_after_save_info')) ?></textarea></div>
                        </div>
                    </div>

                    <div class="text-settings-group">
                        <h4>Schichten und Hinweise</h4>
                        <div class="settings-grid">
                            <div class="field"><label>Schichten – Überschrift</label><input type="text" name="text_shifts_heading" value="<?= h(appText($appConfig, 'text_shifts_heading')) ?>"></div>
                            <div class="field"><label>Schichten – Einleitung</label><textarea name="text_shifts_intro" rows="2"><?= h(appText($appConfig, 'text_shifts_intro')) ?></textarea></div>
                            <div class="field"><label>Normale Schichten</label><input type="text" name="text_normal_shifts_heading" value="<?= h(appText($appConfig, 'text_normal_shifts_heading')) ?>"></div>
                            <div class="field"><label>Normale Schichten – Erklärung</label><textarea name="text_normal_shifts_intro" rows="2"><?= h(appText($appConfig, 'text_normal_shifts_intro')) ?></textarea></div>
                            <div class="field"><label>Springer-Schichten</label><input type="text" name="text_flexible_shifts_heading" value="<?= h(appText($appConfig, 'text_flexible_shifts_heading')) ?>"></div>
                            <div class="field"><label>Springer-Schichten – Erklärung</label><textarea name="text_flexible_shifts_intro" rows="2"><?= h(appText($appConfig, 'text_flexible_shifts_intro')) ?></textarea></div>
                            <div class="field"><label>Keine normale Schicht gewählt</label><input type="text" name="text_no_normal_shift" value="<?= h(appText($appConfig, 'text_no_normal_shift')) ?>"></div>
                            <div class="field"><label>Keine Springer-Schicht gewählt</label><input type="text" name="text_no_flexible_shift" value="<?= h(appText($appConfig, 'text_no_flexible_shift')) ?>"></div>
                            <div class="field"><label>Kennzeichnung „voll“</label><input type="text" name="text_full" value="<?= h(appText($appConfig, 'text_full')) ?>"></div>
                            <div class="field"><label>Kennzeichnung „frei“</label><input type="text" name="text_free" value="<?= h(appText($appConfig, 'text_free')) ?>"></div>
                            <div class="field"><label>Kennzeichnung „belegt“</label><input type="text" name="text_occupied" value="<?= h(appText($appConfig, 'text_occupied')) ?>"></div>
                            <div class="field"><label>Freie Plätze</label><input type="text" name="text_space_free" value="<?= h(appText($appConfig, 'text_space_free')) ?>"></div>
                            <div class="field"><label>Keine normalen Schichten vorhanden</label><input type="text" name="text_no_normal_shifts_available" value="<?= h(appText($appConfig, 'text_no_normal_shifts_available')) ?>"></div>
                            <div class="field"><label>Keine Springer-Schichten vorhanden</label><input type="text" name="text_no_flexible_shifts_available" value="<?= h(appText($appConfig, 'text_no_flexible_shifts_available')) ?>"></div>
                            <div class="field"><label>Hinweise – Überschrift</label><input type="text" name="text_note_heading" value="<?= h(appText($appConfig, 'text_note_heading')) ?>"></div>
                            <div class="field"><label>Hinweise – Einleitung</label><textarea name="text_note_intro" rows="2"><?= h(appText($appConfig, 'text_note_intro')) ?></textarea></div>
                            <div class="field"><label>Hinweisfeld</label><input type="text" name="text_note_label" value="<?= h(appText($appConfig, 'text_note_label')) ?>"></div>
                            <div class="field"><label>Hinweisfeld – Beispiel</label><input type="text" name="text_note_placeholder" value="<?= h(appText($appConfig, 'text_note_placeholder')) ?>"></div>
                        </div>
                    </div>

                    <div class="text-settings-group">
                        <h4>Gespeicherte Rückmeldung, Änderungen und Fußzeile</h4>
                        <div class="settings-grid">
                            <div class="field"><label>Gespeichert – Überschrift</label><input type="text" name="text_saved_heading" value="<?= h(appText($appConfig, 'text_saved_heading')) ?>"></div>
                            <div class="field"><label>Gespeichert – Einleitung</label><textarea name="text_saved_intro" rows="2"><?= h(appText($appConfig, 'text_saved_intro')) ?></textarea></div>
                            <div class="field"><label>Änderungs-Hinweis</label><textarea name="text_change_info" rows="3"><?= h(appText($appConfig, 'text_change_info')) ?></textarea></div>
                            <div class="field"><label>Änderungsfeld</label><input type="text" name="text_change_label" value="<?= h(appText($appConfig, 'text_change_label')) ?>"></div>
                            <div class="field"><label>Änderungsfeld – Beispiel</label><input type="text" name="text_change_placeholder" value="<?= h(appText($appConfig, 'text_change_placeholder')) ?>"></div>
                            <div class="field"><label>Änderung absenden</label><input type="text" name="text_change_button" value="<?= h(appText($appConfig, 'text_change_button')) ?>"></div>
                            <div class="field"><label>Öffentliche Übersicht</label><input type="text" name="text_public_overview_heading" value="<?= h(appText($appConfig, 'text_public_overview_heading')) ?>"></div>
                            <div class="field"><label>Öffentliche Übersicht – Erklärung</label><textarea name="text_public_overview_intro" rows="3"><?= h(appText($appConfig, 'text_public_overview_intro')) ?></textarea></div>
                            <div class="field"><label>Datenschutz-Link</label><input type="text" name="text_privacy_link" value="<?= h(appText($appConfig, 'text_privacy_link')) ?>"></div>
                            <div class="field"><label>Impressum-Link</label><input type="text" name="text_imprint_link" value="<?= h(appText($appConfig, 'text_imprint_link')) ?>"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="section-divider">
                <h3>Impressum</h3>
                <div class="settings-grid">
                    <div class="field"><label>Name</label><input type="text" name="imprint_name" maxlength="120" value="<?= h((string)$appConfig['imprint_name']) ?>"></div>
                    <div class="field"><label>E-Mail</label><input type="text" name="imprint_email" maxlength="160" value="<?= h((string)$appConfig['imprint_email']) ?>"></div>
                    <div class="field"><label>Telefon optional</label><input type="text" name="imprint_phone" maxlength="80" value="<?= h((string)$appConfig['imprint_phone']) ?>"></div>
                    <div class="field"><label>Website</label><input type="text" name="imprint_website" maxlength="160" value="<?= h((string)$appConfig['imprint_website']) ?>"></div>
                </div>
                <label class="block-label" style="margin-top:12px;">Adresse</label>
                <textarea name="imprint_address" maxlength="500" rows="3"><?= h((string)$appConfig['imprint_address']) ?></textarea>
            </div>

            <div class="section-divider">
                <h3>Datenschutz</h3>
                <div class="settings-grid">
                    <div class="field"><label>Ansprechpartner</label><input type="text" name="privacy_contact_name" maxlength="120" value="<?= h((string)$appConfig['privacy_contact_name']) ?>"></div>
                    <div class="field"><label>E-Mail</label><input type="text" name="privacy_contact_email" maxlength="160" value="<?= h((string)$appConfig['privacy_contact_email']) ?>"></div>
                </div>
            </div>

            <button type="submit">Einstellungen speichern</button>
        </form>
    </div>
</div>
<script>
(function () {
    const input = document.getElementById('hero_image_upload');
    const zone = document.getElementById('heroDropZone');
    const name = document.getElementById('heroFileName');
    if (!input || !zone || !name) return;

    function showFile(file) {
        name.textContent = file ? file.name : 'Noch keine Datei ausgewählt';
    }

    input.addEventListener('change', function () {
        showFile(input.files && input.files[0]);
    });

    ['dragenter', 'dragover'].forEach(function (eventName) {
        zone.addEventListener(eventName, function (event) {
            event.preventDefault();
            zone.classList.add('dragover');
        });
    });

    ['dragleave', 'drop'].forEach(function (eventName) {
        zone.addEventListener(eventName, function (event) {
            event.preventDefault();
            zone.classList.remove('dragover');
        });
    });

    zone.addEventListener('drop', function (event) {
        if (!event.dataTransfer || !event.dataTransfer.files.length) return;
        const transfer = new DataTransfer();
        transfer.items.add(event.dataTransfer.files[0]);
        input.files = transfer.files;
        showFile(input.files[0]);
    });
})();
</script>
</body>
</html>
