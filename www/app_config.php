<?php

declare(strict_types=1);

require_once __DIR__ . '/version.php';

// Zentrale Projekt-Einstellungen. Fehlende Werte werden automatisch mit sinnvollen Standardwerten belegt.
function ensureAppSettings(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
}

function appDefaults(): array {
    return [
        'app_name' => 'Helferliste',
        'event_organizer' => 'Meine Organisation',
        'event_name' => 'Meine Veranstaltung',
        'event_start_date' => '',
        'event_end_date' => '',
        'event_status' => 'published',
        'setup_wizard_completed' => '0',
        'public_base_url' => '',
        'public_login_info_text' => '',
        'hero_image' => 'assets/hlf20.png',
        'hero_image_alt' => 'Einsatzfahrzeug',
        'primary_color' => '#b00020',
        'primary_color_dark' => '#8d001a',
        'primary_color_soft' => '#fff1f3',
        'hero_color_start' => '#232323',
        'hero_color_end' => '#410a12',
        'page_background_color' => '#f5f6f8',
        'page_text_color' => '#1f2933',
        'card_background_color' => '#ffffff',
        'muted_text_color' => '#64748b',
        'font_family' => 'Arial, Helvetica, sans-serif',
        'occupancy_color_low' => '#22c55e',
        'occupancy_color_medium' => '#f59e0b',
        'occupancy_color_high' => '#b00020',
        'occupancy_color_full' => '#b00020',
        'text_login_heading' => 'Rückmeldung eintragen',
        'text_event_draft_notice' => 'Diese Helferliste ist noch nicht freigegeben.',
        'text_event_closed_notice' => 'Die Rückmeldung für diese Veranstaltung ist beendet.',
        'text_login_intro' => 'Bitte gib zuerst deinen persönlichen vierstelligen Code aus der Einladung ein.',
        'text_code_label' => 'Dein Zugangscode',
        'text_code_help' => 'Den Code findest du in der Einladung. Danach kannst du dich eintragen.',
        'text_login_button' => 'Jetzt eintragen',
        'text_overview_summary' => 'Bereits eingetragen? Helferliste ansehen',
        'text_overview_intro' => 'Die anonyme Helferübersicht ist erst sichtbar, wenn du deine eigene Rückmeldung bereits abgegeben hast.',
        'text_overview_login_label' => 'Code oder E-Mail-Adresse',
        'text_overview_button' => 'Helferliste einsehen',
        'text_saved_heading' => 'Deine Rückmeldung ist gespeichert',
        'text_saved_intro' => 'Mit diesem Code wurde bereits eine verbindliche Rückmeldung abgegeben.',
        'text_change_info' => 'Wenn etwas geändert werden soll, kannst du hier einen Änderungswunsch senden. Die Änderung wird nicht automatisch übernommen.',
        'text_change_label' => 'Änderungswunsch',
        'text_change_placeholder' => 'Zum Beispiel: Ich kann doch nur Samstag 19–22 Uhr.',
        'text_change_button' => 'Änderungswunsch senden',
        'text_entry_heading' => 'Deine Rückmeldung',
        'text_entry_intro' => 'Sag uns bitte kurz, ob du bei der Veranstaltung helfen kannst.',
        'text_name_label' => 'Dein Name',
        'text_name_placeholder' => 'Vorname Nachname',
        'text_choice_label' => 'Auswahl',
        'text_summary_name_label' => 'Name',
        'text_summary_status_label' => 'Status',
        'text_help_yes' => 'Ich helfe',
        'text_help_yes_description' => 'Danach kannst du eine oder mehrere Schichten auswählen.',
        'text_help_no' => 'Ich habe keine Zeit',
        'text_help_no_description' => 'Deine Rückmeldung wird trotzdem gespeichert.',
        'text_shifts_heading' => 'Schichten auswählen',
        'text_shifts_intro' => 'Bitte wähle mindestens eine normale Schicht oder eine Springer-Schicht.',
        'text_normal_shifts_heading' => 'Normale Schichten',
        'text_normal_shifts_intro' => 'Für feste Dienste mit konkreter Einteilung.',
        'text_flexible_shifts_heading' => 'Springer-Schichten',
        'text_flexible_shifts_intro' => 'Für flexible Unterstützung, falls irgendwo Hilfe gebraucht wird.',
        'text_no_normal_shift' => 'Keine normale Schicht gewählt',
        'text_no_flexible_shift' => 'Keine Springer-Schicht gewählt',
        'text_full' => 'voll',
        'text_free' => 'frei',
        'text_occupied' => 'belegt',
        'text_space_free' => 'Platz/Plätze frei',
        'text_no_normal_shifts_available' => 'Es sind aktuell keine normalen Schichten angelegt.',
        'text_no_flexible_shifts_available' => 'Es sind aktuell keine Springer-Schichten angelegt.',
        'text_note_heading' => 'Hinweis',
        'text_note_intro' => 'Optional: Wunschdienst, Personenwunsch oder sonstige Hinweise.',
        'text_note_label' => 'Hinweis / Wunschdienst / Personenwunsch',
        'text_note_placeholder' => 'Optional: Wünsche, Hinweise oder Personenwunsch',
        'text_save_button' => 'Rückmeldung verbindlich speichern',
        'text_switch_code' => 'Code wechseln',
        'text_after_save_info' => 'Nach dem Speichern kannst du deine Rückmeldung nicht direkt selbst ändern. Änderungen gehen danach über einen Änderungswunsch.',
        'text_public_overview_heading' => 'Anonyme Helferübersicht',
        'text_public_overview_intro' => 'Hier siehst du nur die aktuelle Belegung der Schichten. Namen, E-Mail-Adressen und Codes werden nicht angezeigt.',
        'text_privacy_link' => 'Datenschutz',
        'text_imprint_link' => 'Impressum',
        'imprint_name' => '',
        'imprint_address' => '',
        'imprint_email' => '',
        'imprint_phone' => '',
        'imprint_website' => '',
        'privacy_contact_name' => '',
        'privacy_contact_email' => '',
    ];
}

// Feste Herstellerangabe der Anwendung. Betreiberangaben bleiben davon getrennt
// und werden weiterhin individuell in den Einstellungen gepflegt.
function mowstBrand(): array {
    return [
        'name' => 'MOWST',
        'label' => 'MOWST — Digitale Werkstatt',
        'url' => 'https://mowst.de',
        'email' => 'hallo@mowst.de',
    ];
}

function appSetting(PDO $db, string $key, ?string $fallback = null): string {
    ensureAppSettings($db);
    $defaults = appDefaults();
    $fallback = $fallback ?? ($defaults[$key] ?? '');
    $stmt = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value !== false ? (string)$value : $fallback;
}

function saveAppSetting(PDO $db, string $key, string $value): void {
    ensureAppSettings($db);
    $stmt = $db->prepare("INSERT INTO app_settings (setting_key, setting_value, updated_at)
        VALUES (?, ?, datetime('now'))
        ON CONFLICT(setting_key) DO UPDATE SET
            setting_value = excluded.setting_value,
            updated_at = excluded.updated_at");
    $stmt->execute([$key, $value]);
}

function appConfig(PDO $db): array {
    helferlisteRequireCurrentSchema($db);
    $config = [];
    foreach (appDefaults() as $key => $fallback) {
        $config[$key] = appSetting($db, $key, $fallback);
    }
    return $config;
}

function appTitle(array $config): string {
    return trim((string)($config['app_name'] ?? 'Helferliste')) ?: 'Helferliste';
}

function appDesignColor(array $config, string $key): string {
    $defaults = appDefaults();
    $fallback = (string)($defaults[$key] ?? '#94a3b8');
    $value = trim((string)($config[$key] ?? $fallback));
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : $fallback;
}

function appText(array $config, string $key): string {
    $defaults = appDefaults();
    return trim((string)($config[$key] ?? ($defaults[$key] ?? '')));
}

function appFontFamily(array $config): string {
    $allowed = [
        'Arial, Helvetica, sans-serif',
        'Verdana, Geneva, sans-serif',
        'Tahoma, Geneva, sans-serif',
        '"Trebuchet MS", Arial, sans-serif',
        'Georgia, "Times New Roman", serif',
        'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
    ];
    $value = (string)($config['font_family'] ?? appDefaults()['font_family']);
    return in_array($value, $allowed, true) ? $value : appDefaults()['font_family'];
}

function appHeroImage(array $config): string {
    $value = trim((string)($config['hero_image'] ?? appDefaults()['hero_image']));
    if (!preg_match('#^assets/(?:uploads/)?[a-zA-Z0-9._-]+\.(?:png|jpe?g|webp|gif)$#i', $value)) {
        return appDefaults()['hero_image'];
    }
    return is_file(__DIR__ . '/' . $value) ? $value : appDefaults()['hero_image'];
}

function appSubtitle(array $config): string {
    $parts = array_filter([
        trim((string)($config['event_organizer'] ?? '')),
        trim((string)($config['event_name'] ?? '')),
    ], fn($value) => $value !== '');
    return implode(' · ', $parts);
}

function appEventStatus(array $config): string {
    $status = (string)($config['event_status'] ?? appDefaults()['event_status']);
    return in_array($status, ['draft', 'published', 'closed'], true) ? $status : 'draft';
}

function appEventStatusLabel(array $config): string {
    return match (appEventStatus($config)) {
        'published' => 'Veröffentlicht',
        'closed' => 'Abgeschlossen',
        default => 'Entwurf',
    };
}

function appEventAcceptsResponses(array $config): bool {
    return appEventStatus($config) === 'published';
}

function appSetupWizardCompleted(array $config): bool {
    return (string)($config['setup_wizard_completed'] ?? '0') === '1';
}

function appEventDateRange(array $config): string {
    require_once __DIR__ . '/shift_helpers.php';
    $start = trim((string)($config['event_start_date'] ?? ''));
    $end = trim((string)($config['event_end_date'] ?? ''));
    if ($start === '') {
        return '';
    }
    if ($end === '' || $end === $start) {
        return formatGermanDate($start);
    }
    return formatGermanDate($start) . '–' . formatGermanDate($end);
}

function appPublicationIssues(PDO $db, array $config): array {
    $issues = [];
    $eventName = trim((string)($config['event_name'] ?? ''));
    $organizer = trim((string)($config['event_organizer'] ?? ''));
    $baseUrl = trim((string)($config['public_base_url'] ?? ''));
    $startDate = trim((string)($config['event_start_date'] ?? ''));

    if ($eventName === '' || $eventName === appDefaults()['event_name']) {
        $issues[] = 'einen konkreten Veranstaltungsnamen';
    }
    if ($organizer === '' || $organizer === appDefaults()['event_organizer']) {
        $issues[] = 'die verantwortliche Organisation';
    }
    if ($startDate === '') {
        $issues[] = 'den Veranstaltungsbeginn';
    }
    if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $baseUrl)) {
        $issues[] = 'eine gültige öffentliche Basis-URL';
    }
    if (trim((string)($config['imprint_name'] ?? '')) === ''
        || trim((string)($config['imprint_address'] ?? '')) === ''
        || !filter_var((string)($config['imprint_email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        $issues[] = 'vollständige Impressumsangaben';
    }
    if (!filter_var((string)($config['privacy_contact_email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        $issues[] = 'eine gültige Datenschutz-Kontaktadresse';
    }

    $activeShifts = (int)$db->query("SELECT
        (SELECT COUNT(*) FROM shifts WHERE active = 1 AND title NOT LIKE 'Beispiel:%')
        + (SELECT COUNT(*) FROM springer_shifts WHERE active = 1 AND title NOT LIKE 'Beispiel:%')")->fetchColumn();
    if ($activeShifts === 0) {
        $issues[] = 'mindestens eine aktive Schicht';
    }
    return $issues;
}

function appPublicBaseUrl(PDO $db): string {
    $configuredBaseUrl = trim(appSetting($db, 'public_base_url', appDefaults()['public_base_url']));
    if ($configuredBaseUrl !== '') {
        return rtrim($configuredBaseUrl, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $scheme . '://' . $host . ($dir === '' || $dir === '/' ? '' : $dir);
}

function limitForList(string $param = 'limit', int $default = 10): int {
    $allowed = [10, 25, 50, 100, 250];
    $value = (int)($_GET[$param] ?? $default);
    return in_array($value, $allowed, true) ? $value : $default;
}

function renderLimitSelect(string $param, int $current, string $label = 'Anzeigen'): void {
    $options = [10, 25, 50, 100, 250];
    echo '<form method="get" class="limit-form">';
    foreach ($_GET as $key => $value) {
        if ($key === $param || is_array($value)) {
            continue;
        }
        echo '<input type="hidden" name="' . htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '">';
    }
    echo '<label>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ' <select name="' . htmlspecialchars($param, ENT_QUOTES, 'UTF-8') . '" onchange="this.form.submit()">';
    foreach ($options as $option) {
        $selected = $option === $current ? ' selected' : '';
        echo '<option value="' . $option . '"' . $selected . '>' . $option . '</option>';
    }
    echo '</select> Ergebnisse</label> <button type="submit" class="secondary compact">Übernehmen</button></form>';
}

function adminViewPreference(): string {
    static $preference = null;
    if ($preference !== null) {
        return $preference;
    }

    $allowed = ['auto', 'mobile', 'desktop'];
    $requested = isset($_GET['admin_view']) ? strtolower((string)$_GET['admin_view']) : '';

    if (in_array($requested, $allowed, true)) {
        if ($requested === 'auto') {
            unset($_SESSION['admin_view']);
            setcookie('admin_view', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            $_SESSION['admin_view'] = $requested;
            setcookie('admin_view', $requested, [
                'expires' => time() + 15552000,
                'path' => '/',
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $preference = $requested;
        return $preference;
    }

    $stored = strtolower((string)($_SESSION['admin_view'] ?? $_COOKIE['admin_view'] ?? 'auto'));
    $preference = in_array($stored, $allowed, true) ? $stored : 'auto';
    return $preference;
}

function adminLooksLikeSmartphone(): bool {
    $userAgent = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if (($_SERVER['HTTP_SEC_CH_UA_MOBILE'] ?? '') === '?1') {
        return true;
    }
    if ($userAgent === '') {
        return false;
    }

    $isMobile = (bool)preg_match('/iphone|ipod|android.*mobile|windows phone|blackberry|opera mini|mobile safari/', $userAgent);
    $isTablet = (bool)preg_match('/ipad|tablet|android(?!.*mobile)/', $userAgent);
    return $isMobile && !$isTablet;
}

function adminViewMode(): string {
    $preference = adminViewPreference();
    if ($preference === 'mobile' || $preference === 'desktop') {
        return $preference;
    }
    return adminLooksLikeSmartphone() ? 'mobile' : 'desktop';
}

function adminBodyClass(): string {
    return adminViewMode() === 'mobile' ? 'admin-mobile' : 'admin-desktop';
}

function adminViewportContent(): string {
    return adminViewPreference() === 'desktop' ? 'width=1440' : 'width=device-width, initial-scale=1';
}

function adminViewUrl(string $view): string {
    $query = $_GET;
    unset($query['admin_view'], $query['logout']);
    $query['admin_view'] = $view;
    $script = basename((string)($_SERVER['PHP_SELF'] ?? 'admin.php'));
    return $script . '?' . http_build_query($query);
}

function adminNav(string $active = ''): void {
    $groups = [
        'Verwaltung' => [
            'codes.php' => ['Zugangscodes', 'codes'],
            'edit_shifts.php' => ['Schichten', 'shifts'],
        ],
        'Auswertung' => [
            'stats.php' => ['Statistik', 'stats'],
            'export.php' => ['Export', 'export'],
            'print_lists.php' => ['Drucklisten', 'print'],
        ],
        'System' => [
            'setup_wizard.php' => ['Einrichtungsassistent', 'setup'],
            'settings.php' => ['Einstellungen', 'settings'],
            'settings.php#kopfbild' => ['Kopfbild & Galerie', 'media'],
            'settings.php#design' => ['Design', 'design'],
            'settings.php#texte' => ['Seitentexte', 'texts'],
            'event_archive.php' => ['Veranstaltung abschließen', 'archive'],
            'admin.php?change_password=1' => ['Passwort ändern', 'password'],
            'admin.php?logout=1' => ['Logout', 'logout'],
        ],
    ];

    echo '<style>
        .admin-nav-shell { display:flex; flex-wrap:wrap; gap:18px; align-items:center; margin-top:18px; padding:14px; border:1px solid #e5e7eb; border-radius:16px; background:#f8fafc; }
        .admin-nav-shell a, .admin-nav-shell summary { font:inherit; }
        .admin-nav-home, .admin-nav-link { display:inline-flex !important; align-items:center; justify-content:center; min-height:42px; border-radius:11px !important; padding:10px 16px !important; background:#ffffff !important; color:#222 !important; border:1px solid #d8dee8 !important; box-shadow:none !important; font-weight:800 !important; text-decoration:none !important; }
        .admin-nav-home.active, .admin-nav-link.active { background:var(--primary, #b00020) !important; color:#fff !important; border-color:var(--primary, #b00020) !important; }
        .admin-nav-groups { display:flex; flex-wrap:wrap; gap:14px; align-items:center; }
        .admin-menu { position:relative; }
        .admin-menu > summary { list-style:none; display:inline-flex; align-items:center; gap:10px; min-height:42px; border-radius:11px; padding:10px 16px; background:#fff; color:#222; border:1px solid #d8dee8; font-weight:800; cursor:pointer; user-select:none; }
        .admin-menu > summary::-webkit-details-marker { display:none; }
        .admin-menu > summary::after { content:"▾"; color:#64748b; font-size:.78rem; }
        .admin-menu.active > summary, .admin-menu[open] > summary, .admin-menu:hover > summary { border-color:var(--primary, #b00020); color:var(--primary, #b00020); background:#fff1f3; }
        .admin-menu:not([open]) .admin-menu-items { display:none; }
        .admin-menu-items { position:absolute; z-index:20; top:calc(100% + 8px); left:0; min-width:240px; display:grid; gap:7px; padding:10px; border:1px solid #d8dee8; border-radius:14px; background:#fff; box-shadow:0 16px 40px rgba(15,23,42,.16); }
        .admin-menu-items a { display:block !important; min-height:0; border-radius:10px !important; padding:11px 13px !important; background:#fff !important; color:#222 !important; border:0 !important; box-shadow:none !important; font-weight:750 !important; text-decoration:none !important; white-space:nowrap; }
        .admin-menu-items a:hover, .admin-menu-items a.active { background:#f1f5f9 !important; color:var(--primary, #b00020) !important; }
        .admin-view-switch { margin-left:auto; display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
        .admin-view-switch a { display:inline-flex !important; align-items:center; justify-content:center; min-height:42px; padding:10px 14px !important; border:2px solid var(--primary, #b00020) !important; border-radius:11px !important; background:#fff !important; color:var(--primary, #b00020) !important; font-weight:800 !important; text-decoration:none !important; }
        .admin-view-switch a.primary { background:var(--primary, #b00020) !important; color:#fff !important; }
        .admin-view-note { width:100%; color:#64748b; font-size:.84rem; text-align:right; }
        body.admin-mobile { overflow-x:hidden; }
        body.admin-mobile .wrap,
        body.admin-mobile .container { width:calc(100% - 16px) !important; min-width:0 !important; max-width:none !important; padding:8px 0 28px !important; }
        body.admin-mobile .card { padding:14px !important; border-radius:14px !important; margin-bottom:12px !important; }
        body.admin-mobile h1 { font-size:1.55rem; line-height:1.2; overflow-wrap:anywhere; }
        body.admin-mobile h2 { font-size:1.25rem; line-height:1.25; overflow-wrap:anywhere; }
        body.admin-mobile .stats,
        body.admin-mobile .grid,
        body.admin-mobile .shift-grid,
        body.admin-mobile .settings-grid,
        body.admin-mobile .export-grid,
        body.admin-mobile .color-preview { grid-template-columns:1fr !important; }
        body.admin-mobile .buttons,
        body.admin-mobile .button-row { display:grid !important; grid-template-columns:1fr !important; }
        body.admin-mobile .btn,
        body.admin-mobile button,
        body.admin-mobile input,
        body.admin-mobile select,
        body.admin-mobile textarea { min-height:48px; font-size:16px; }
        body.admin-mobile .buttons .btn,
        body.admin-mobile .button-row .btn,
        body.admin-mobile form > button { width:100%; text-align:center; }
        body.admin-mobile .table-wrap,
        body.admin-mobile .entries-wrap { overflow:visible !important; }
        body.admin-mobile table,
        body.admin-mobile tbody,
        body.admin-mobile tr,
        body.admin-mobile td { display:block; width:100% !important; min-width:0 !important; }
        body.admin-mobile table { border-collapse:separate; }
        body.admin-mobile thead { display:none; }
        body.admin-mobile tr { margin:0 0 12px; padding:5px 12px; border:1px solid #d8dee8; border-radius:12px; background:#fff; box-shadow:0 2px 8px rgba(15,23,42,.05); }
        body.admin-mobile td { display:grid; grid-template-columns:minmax(105px, 36%) minmax(0, 1fr); gap:10px; padding:10px 0 !important; border-bottom:1px solid #edf0f3; overflow-wrap:anywhere; white-space:normal !important; }
        body.admin-mobile td:last-child { border-bottom:0; }
        body.admin-mobile td::before { content:attr(data-label); font-weight:800; color:#475569; }
        body.admin-mobile td[data-label=""]::before { display:none; }
        body.admin-mobile .entries-table { min-width:0 !important; table-layout:auto !important; }
        body.admin-mobile .entries-table th,
        body.admin-mobile .entries-table td { width:100% !important; }
        body.admin-mobile .action-cell .btn,
        body.admin-mobile .action-cell button,
        body.admin-mobile td form button { width:100%; min-width:0; margin-top:6px; }
        body.admin-mobile .limit-form { align-items:stretch; }
        body.admin-mobile .limit-form label { display:grid; gap:6px; width:100%; }
        body.admin-mobile .limit-form select { width:100%; }
        body.admin-mobile .shift-top,
        body.admin-mobile .bar-label { align-items:flex-start; }
        @media (max-width: 720px) {
            .admin-nav-shell { align-items:stretch; }
            .admin-nav-home { width:100%; }
            .admin-nav-groups { width:100%; display:grid; grid-template-columns:1fr; }
            .admin-menu > summary { width:100%; justify-content:space-between; }
            .admin-menu-items { position:static; margin-top:7px; box-shadow:none; min-width:0; }
            .admin-view-switch { width:100%; margin-left:0; display:grid; grid-template-columns:1fr; }
            .admin-view-note { text-align:left; }
        }
    </style>';

    echo '<nav class="admin-nav admin-nav-shell" aria-label="Admin-Menü">';
    echo '<a class="admin-nav-home ' . ($active === 'admin' ? 'active' : '') . '" href="admin.php">Übersicht</a>';
    echo '<div class="admin-nav-groups">';

    foreach ($groups as $groupLabel => $items) {
        $groupIsActive = false;
        foreach ($items as [$label, $key]) {
            if ($key === $active) {
                $groupIsActive = true;
                break;
            }
        }

        echo '<details class="admin-menu ' . ($groupIsActive ? 'active' : '') . '">';
        echo '<summary>' . htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8') . '</summary>';
        echo '<div class="admin-menu-items">';
        foreach ($items as $href => [$label, $key]) {
            $class = $key === $active ? 'active' : '';
            echo '<a class="' . $class . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
        }
        echo '</div></details>';
    }

    echo '</div>';
    $currentMode = adminViewMode();
    $targetMode = $currentMode === 'mobile' ? 'desktop' : 'mobile';
    $targetLabel = $targetMode === 'mobile' ? 'Mobile Ansicht' : 'Desktop-Ansicht';
    echo '<div class="admin-view-switch" aria-label="Darstellung wechseln">';
    echo '<a class="primary" href="' . htmlspecialchars(adminViewUrl($targetMode), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($targetLabel, ENT_QUOTES, 'UTF-8') . '</a>';
    echo '<a href="' . htmlspecialchars(adminViewUrl('auto'), ENT_QUOTES, 'UTF-8') . '">Automatisch</a>';
    echo '<span class="admin-view-note">Aktiv: ' . ($currentMode === 'mobile' ? 'Mobil' : 'Desktop') . '</span>';
    echo '</div></nav>';
    echo '<script>
        function applyAdminTableLabels() {
            document.querySelectorAll("table").forEach(function(table) {
                const headers = Array.from(table.querySelectorAll("thead th")).map(function(header) {
                    return header.textContent.trim();
                });
                table.querySelectorAll("tbody tr").forEach(function(row) {
                    Array.from(row.children).forEach(function(cell, index) {
                        if (cell.tagName === "TD" && !cell.hasAttribute("data-label")) {
                            cell.setAttribute("data-label", headers[index] || "");
                        }
                    });
                });
            });
        }
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", applyAdminTableLabels);
        } else {
            applyAdminTableLabels();
        }
        document.querySelectorAll(".admin-menu").forEach(function(menu) {
            let closeTimer = null;

            menu.addEventListener("mouseenter", function() {
                if (closeTimer) {
                    clearTimeout(closeTimer);
                }
                menu.setAttribute("open", "open");
            });

            menu.addEventListener("mouseleave", function() {
                closeTimer = setTimeout(function() {
                    menu.removeAttribute("open");
                }, 140);
            });

            menu.addEventListener("focusout", function() {
                setTimeout(function() {
                    if (!menu.contains(document.activeElement)) {
                        menu.removeAttribute("open");
                    }
                }, 140);
            });
        });
    </script>';
}

$adminViewScripts = [
    'admin.php',
    'codes.php',
    'stats.php',
    'export.php',
    'print_lists.php',
    'edit_shifts.php',
    'edit_entry.php',
    'settings.php',
    'clear_event.php',
    'event_archive.php',
];
if (in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), $adminViewScripts, true)) {
    adminViewPreference();
}
