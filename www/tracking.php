<?php

declare(strict_types=1);

// Ermittelt aus dem User-Agent nur eine grobe Geräteklasse.
function detectDeviceType(): string {
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');

    if ($ua === '') {
        return 'unknown';
    }

    if (preg_match('/ipad|tablet|playbook|silk|kindle/', $ua)) {
        return 'tablet';
    }

    if (preg_match('/mobile|iphone|ipod|android.*mobile|windows phone|blackberry/', $ua)) {
        return 'mobile';
    }

    if (preg_match('/android/', $ua)) {
        return 'tablet';
    }

    return 'desktop';
}

// Hilfsfunktion für alte Datenbanken, falls frühere Spalten noch vorhanden sind.
function tableHasColumn(PDO $db, string $table, string $column): bool {
    try {
        $stmt = $db->query('PRAGMA table_info(' . $table . ')');
        $columns = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($columns as $row) {
            if (($row['name'] ?? '') === $column) {
                return true;
            }
        }
    } catch (Throwable $e) {
        return false;
    }

    return false;
}

// Schreibt einen Statistik-Eintrag. Aufgerufen wird diese Funktion nur von der Hauptseite.
function trackVisit(PDO $db, string $page, ?int $accessCodeId = null): void {
    try {
        if (!empty($_SESSION['admin_logged_in'])) {
            return;
        }

        // Bewusst datensparsam: gespeichert wird nur Seite, Zeitpunkt und grober Gerätetyp.
        // $accessCodeId bleibt nur als kompatibler Parameter erhalten und wird nicht gespeichert.
        $columns = ['visited_at', 'page', 'device_type'];
        $values = ["datetime('now')", '?', '?'];
        $params = [substr($page, 0, 80), detectDeviceType()];

        if (tableHasColumn($db, 'visit_stats', 'user_agent')) {
            $columns[] = 'user_agent';
            $values[] = '?';
            $params[] = '';
        }

        if (tableHasColumn($db, 'visit_stats', 'access_code_id')) {
            $columns[] = 'access_code_id';
            $values[] = '?';
            $params[] = null;
        }

        $sql = 'INSERT INTO visit_stats (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    } catch (Throwable $e) {
        // Statistikfehler sollen die eigentliche Helferliste niemals blockieren.
    }
}

// Einfaches Ereignisprotokoll für Admin-Aktionen und wichtige Nutzeraktionen.
function logEvent(PDO $db, string $action, ?string $detail = null): void {
    try {
        $stmt = $db->prepare("INSERT INTO event_log (created_at, action, detail) VALUES (datetime('now'), ?, ?)");
        $stmt->execute([substr($action, 0, 80), $detail !== null ? substr($detail, 0, 1000) : null]);
    } catch (Throwable $e) {
        // Logging darf niemals die eigentliche Aktion blockieren.
    }
}
