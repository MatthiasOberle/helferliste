<?php

declare(strict_types=1);

const DUTY_ROSTER_MAX_BYTES = 10 * 1024 * 1024;
const DUTY_ROSTER_SESSION_TTL = 7200;
const DUTY_ROSTER_RATE_WINDOW = 900;
const DUTY_ROSTER_RATE_MAX_FAILURES = 5;
const DUTY_ROSTER_RATE_BLOCK_SECONDS = 300;

function dutyRosterDirectory(): string
{
    return dirname(__DIR__) . '/data/duty-roster';
}

function dutyRosterFilePath(): string
{
    return dutyRosterDirectory() . '/current.pdf';
}

function dutyRosterHasFile(): bool
{
    $file = dutyRosterFilePath();
    return is_file($file) && filesize($file) > 0;
}

function dutyRosterIsPublished(PDO $db): bool
{
    return appSetting($db, 'event_status', 'draft') === 'closed'
        && appSetting($db, 'duty_roster_published', '0') === '1'
        && dutyRosterHasFile();
}

function dutyRosterAssertEventClosed(PDO $db): void
{
    if (appSetting($db, 'event_status', 'draft') !== 'closed') {
        throw new InvalidArgumentException('Die Diensteinteilung kann erst veröffentlicht werden, wenn die Veranstaltung abgeschlossen und die Rückmeldung gesperrt ist.');
    }
}

/** @return array{filename:string,size:int,sha256:string,uploaded_at:string,published:bool}|null */
function dutyRosterMetadata(PDO $db): ?array
{
    if (!dutyRosterHasFile()) {
        return null;
    }

    $file = dutyRosterFilePath();
    return [
        'filename' => appSetting($db, 'duty_roster_filename', 'diensteinteilung.pdf'),
        'size' => (int)appSetting($db, 'duty_roster_size', (string)filesize($file)),
        'sha256' => appSetting($db, 'duty_roster_sha256', (string)hash_file('sha256', $file)),
        'uploaded_at' => appSetting($db, 'duty_roster_uploaded_at', ''),
        'published' => dutyRosterIsPublished($db),
    ];
}

function dutyRosterDisplayFilename(string $originalName): string
{
    $name = trim(basename(str_replace('\\', '/', $originalName)));
    if ($name === '' || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
        return 'diensteinteilung.pdf';
    }
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 180);
    } else {
        $name = substr($name, 0, 180);
    }
    return preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'diensteinteilung.pdf';
}

function dutyRosterValidatePdfUpload(mixed $upload): array
{
    if (!is_array($upload)
        || !isset($upload['error'], $upload['tmp_name'], $upload['name'])
        || is_array($upload['error'])
    ) {
        throw new InvalidArgumentException('Bitte eine PDF-Datei auswählen.');
    }

    $error = (int)$upload['error'];
    if ($error !== UPLOAD_ERR_OK) {
        $message = match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die PDF ist größer als die erlaubten 10 MB.',
            UPLOAD_ERR_PARTIAL => 'Die PDF wurde nur teilweise übertragen. Bitte erneut versuchen.',
            UPLOAD_ERR_NO_FILE => 'Bitte eine PDF-Datei auswählen.',
            default => 'Die PDF konnte nicht hochgeladen werden.',
        };
        throw new InvalidArgumentException($message);
    }

    $temporaryFile = (string)$upload['tmp_name'];
    $size = is_file($temporaryFile) ? (int)filesize($temporaryFile) : 0;
    if ($size <= 0 || $size > DUTY_ROSTER_MAX_BYTES) {
        throw new InvalidArgumentException('Die PDF muss zwischen 1 Byte und 10 MB groß sein.');
    }

    $originalName = dutyRosterDisplayFilename((string)$upload['name']);
    if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pdf') {
        throw new InvalidArgumentException('Es sind ausschließlich PDF-Dateien erlaubt.');
    }

    if (!class_exists('finfo')) {
        throw new RuntimeException('Die serverseitige Dateitypprüfung ist nicht verfügbar.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryFile);
    if (!in_array($mime, ['application/pdf', 'application/x-pdf'], true)) {
        throw new InvalidArgumentException('Die ausgewählte Datei wurde nicht als PDF erkannt.');
    }

    $stream = fopen($temporaryFile, 'rb');
    if ($stream === false) {
        throw new RuntimeException('Die hochgeladene PDF konnte nicht geprüft werden.');
    }
    $header = fread($stream, 5);
    $tailLength = min(4096, $size);
    fseek($stream, -$tailLength, SEEK_END);
    $tail = fread($stream, $tailLength);
    fclose($stream);
    if ($header !== '%PDF-' || !is_string($tail) || !str_contains($tail, '%%EOF')) {
        throw new InvalidArgumentException('Die Datei besitzt keine vollständige PDF-Signatur.');
    }

    return [
        'tmp_name' => $temporaryFile,
        'filename' => $originalName,
        'size' => $size,
    ];
}

function dutyRosterUpload(PDO $db, mixed $upload, bool $publish): array
{
    if ($publish) {
        dutyRosterAssertEventClosed($db);
    }
    $validated = dutyRosterValidatePdfUpload($upload);
    $directory = dutyRosterDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('Der geschützte PDF-Ordner konnte nicht angelegt werden.');
    }

    $target = dutyRosterFilePath();
    $temporaryTarget = $directory . '/.upload-' . bin2hex(random_bytes(8)) . '.tmp';
    $previousTarget = $directory . '/.previous-' . bin2hex(random_bytes(8)) . '.pdf';
    if (!move_uploaded_file($validated['tmp_name'], $temporaryTarget)) {
        throw new RuntimeException('Die PDF konnte nicht in den geschützten Ordner verschoben werden.');
    }
    chmod($temporaryTarget, 0640);

    $hadPrevious = is_file($target);
    if ($hadPrevious && !rename($target, $previousTarget)) {
        unlink($temporaryTarget);
        throw new RuntimeException('Die bisherige PDF konnte nicht sicher vorbereitet werden.');
    }
    if (!rename($temporaryTarget, $target)) {
        if ($hadPrevious) {
            rename($previousTarget, $target);
        }
        unlink($temporaryTarget);
        throw new RuntimeException('Die neue PDF konnte nicht aktiviert werden.');
    }

    $sha256 = (string)hash_file('sha256', $target);
    try {
        $db->beginTransaction();
        saveAppSetting($db, 'duty_roster_filename', $validated['filename']);
        saveAppSetting($db, 'duty_roster_size', (string)$validated['size']);
        saveAppSetting($db, 'duty_roster_sha256', $sha256);
        saveAppSetting($db, 'duty_roster_uploaded_at', gmdate('c'));
        saveAppSetting($db, 'duty_roster_published', $publish ? '1' : '0');
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        unlink($target);
        if ($hadPrevious) {
            rename($previousTarget, $target);
        }
        throw $error;
    }

    if ($hadPrevious && is_file($previousTarget)) {
        unlink($previousTarget);
    }
    chmod($target, 0640);
    return dutyRosterMetadata($db) ?? throw new RuntimeException('PDF-Metadaten konnten nicht gelesen werden.');
}

function dutyRosterSetPublished(PDO $db, bool $published): void
{
    if ($published) {
        dutyRosterAssertEventClosed($db);
    }
    if ($published && !dutyRosterHasFile()) {
        throw new RuntimeException('Vor der Veröffentlichung muss eine PDF hochgeladen werden.');
    }
    saveAppSetting($db, 'duty_roster_published', $published ? '1' : '0');
}

function dutyRosterDeleteDocument(PDO $db): void
{
    $target = dutyRosterFilePath();
    $pendingDelete = dutyRosterDirectory() . '/.delete-' . bin2hex(random_bytes(8)) . '.pdf';
    $hadFile = is_file($target);
    if ($hadFile && !rename($target, $pendingDelete)) {
        throw new RuntimeException('Die PDF konnte nicht sicher zum Löschen vorbereitet werden.');
    }

    try {
        $db->beginTransaction();
        $stmt = $db->prepare("DELETE FROM app_settings WHERE setting_key IN (?, ?, ?, ?, ?)");
        $stmt->execute([
            'duty_roster_filename',
            'duty_roster_size',
            'duty_roster_sha256',
            'duty_roster_uploaded_at',
            'duty_roster_published',
        ]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($hadFile) {
            rename($pendingDelete, $target);
        }
        throw $error;
    }

    if ($hadFile && is_file($pendingDelete)) {
        unlink($pendingDelete);
    }
}

function dutyRosterFindEligibleAccess(PDO $db, string $input): ?array
{
    $input = trim($input);
    if ($input === '') {
        return null;
    }

    if (str_contains($input, '@')) {
        $email = strtolower(substr($input, 0, 254));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $stmt = $db->prepare("SELECT ac.id, e.id AS entry_id
            FROM access_codes ac
            JOIN entries e ON e.access_code_id = ac.id
            WHERE LOWER(ac.email) = ?
            ORDER BY e.id DESC LIMIT 1");
        $stmt->execute([$email]);
    } else {
        $code = preg_replace('/\D+/', '', $input);
        if (!is_string($code) || strlen($code) !== 4) {
            return null;
        }
        $stmt = $db->prepare("SELECT ac.id, e.id AS entry_id
            FROM access_codes ac
            JOIN entries e ON e.access_code_id = ac.id
            WHERE ac.code = ?
            ORDER BY e.id DESC LIMIT 1");
        $stmt->execute([$code]);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function dutyRosterClientKey(PDO $db): string
{
    $secret = appSetting($db, 'admin_auth_generation', HELFERLISTE_VERSION);
    $address = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 200);
    return hash_hmac('sha256', $address . "\n" . $agent, $secret);
}

function dutyRosterRateLimitRemaining(PDO $db): int
{
    $now = time();
    $db->prepare('DELETE FROM duty_roster_login_attempts WHERE updated_at < ?')->execute([$now - 86400]);
    $stmt = $db->prepare('SELECT blocked_until FROM duty_roster_login_attempts WHERE attempt_key = ?');
    $stmt->execute([dutyRosterClientKey($db)]);
    $blockedUntil = (int)($stmt->fetchColumn() ?: 0);
    return max(0, $blockedUntil - $now);
}

function dutyRosterRecordFailure(PDO $db): void
{
    $now = time();
    $key = dutyRosterClientKey($db);
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT window_started_at, failures FROM duty_roster_login_attempts WHERE attempt_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $windowStartedAt = (int)($row['window_started_at'] ?? $now);
        $failures = (int)($row['failures'] ?? 0);
        if ($row === false || $windowStartedAt < $now - DUTY_ROSTER_RATE_WINDOW) {
            $windowStartedAt = $now;
            $failures = 0;
        }
        $failures++;
        $blockedUntil = $failures >= DUTY_ROSTER_RATE_MAX_FAILURES
            ? $now + DUTY_ROSTER_RATE_BLOCK_SECONDS
            : 0;
        $upsert = $db->prepare("INSERT INTO duty_roster_login_attempts
            (attempt_key, window_started_at, failures, blocked_until, updated_at)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(attempt_key) DO UPDATE SET
                window_started_at = excluded.window_started_at,
                failures = excluded.failures,
                blocked_until = excluded.blocked_until,
                updated_at = excluded.updated_at");
        $upsert->execute([$key, $windowStartedAt, $failures, $blockedUntil, $now]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function dutyRosterClearFailures(PDO $db): void
{
    $stmt = $db->prepare('DELETE FROM duty_roster_login_attempts WHERE attempt_key = ?');
    $stmt->execute([dutyRosterClientKey($db)]);
}

function dutyRosterAuthorizeSession(PDO $db, int $accessCodeId): bool
{
    if (!dutyRosterIsPublished($db) || $accessCodeId <= 0) {
        return false;
    }
    $stmt = $db->prepare('SELECT 1 FROM entries WHERE access_code_id = ? LIMIT 1');
    $stmt->execute([$accessCodeId]);
    return (bool)$stmt->fetchColumn();
}

function dutyRosterSessionIsAuthorized(PDO $db): bool
{
    $accessCodeId = (int)($_SESSION['duty_roster_access_code_id'] ?? 0);
    $authenticatedAt = (int)($_SESSION['duty_roster_authenticated_at'] ?? 0);
    if ($authenticatedAt <= 0 || $authenticatedAt < time() - DUTY_ROSTER_SESSION_TTL) {
        unset($_SESSION['duty_roster_access_code_id'], $_SESSION['duty_roster_authenticated_at']);
        return false;
    }
    if (!dutyRosterAuthorizeSession($db, $accessCodeId)) {
        unset($_SESSION['duty_roster_access_code_id'], $_SESSION['duty_roster_authenticated_at']);
        return false;
    }
    return true;
}

function dutyRosterPublicCsrfToken(): string
{
    if (empty($_SESSION['duty_roster_csrf_token'])) {
        $_SESSION['duty_roster_csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['duty_roster_csrf_token'];
}

function dutyRosterVerifyPublicCsrf(): bool
{
    $submitted = (string)($_POST['csrf_token'] ?? '');
    $stored = (string)($_SESSION['duty_roster_csrf_token'] ?? '');
    return $submitted !== '' && $stored !== '' && hash_equals($stored, $submitted);
}

function dutyRosterAsciiFilename(string $name): string
{
    $name = function_exists('iconv') ? (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : $name;
    $name = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $name) ?: 'diensteinteilung.pdf';
    return trim($name, '-.') ?: 'diensteinteilung.pdf';
}

function dutyRosterSendPdf(PDO $db, bool $download): never
{
    $metadata = dutyRosterMetadata($db);
    if ($metadata === null) {
        http_response_code(404);
        exit('Die Diensteinteilung wurde nicht gefunden.');
    }

    $file = dutyRosterFilePath();
    $filename = $metadata['filename'];
    $disposition = $download ? 'attachment' : 'inline';
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($file));
    header('Content-Disposition: ' . $disposition . '; filename="' . dutyRosterAsciiFilename($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Frame-Options: SAMEORIGIN');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        exit;
    }
    readfile($file);
    exit;
}
