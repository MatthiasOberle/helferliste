<?php

declare(strict_types=1);

require_once __DIR__ . '/database_tools.php';

const CONTACT_IMPORT_MAX_BYTES = 2_097_152;
const CONTACT_IMPORT_MAX_ROWS = 1_000;
const CONTACT_IMPORT_PREVIEW_ROWS = 100;
const CONTACT_IMPORT_SESSION_TTL = 1_800;

/**
 * @return list<string>
 */
function normalizeEmailList(string $input): array
{
    $input = substr(trim($input), 0, 20_000);
    $parts = preg_split('/[\s,;]+/', $input) ?: [];
    $emails = [];

    foreach ($parts as $part) {
        $email = strtolower(trim($part));
        if ($email !== '' && strlen($email) <= 254) {
            $emails[$email] = $email;
        }
    }

    return array_slice(array_values($emails), 0, 500);
}

function accessCodeExists(PDO $db, string $code): bool
{
    $stmt = $db->prepare('SELECT 1 FROM access_codes WHERE code = ? LIMIT 1');
    $stmt->execute([$code]);
    return (bool)$stmt->fetchColumn();
}

function generateUniqueAccessCode(PDO $db): string
{
    for ($attempt = 0; $attempt < 500; $attempt++) {
        $code = (string)random_int(1000, 9999);
        if (!accessCodeExists($db, $code)) {
            return $code;
        }
    }

    throw new RuntimeException('Es konnte kein freier vierstelliger Code gefunden werden.');
}

function accessCodeEmailExists(PDO $db, string $email): bool
{
    $stmt = $db->prepare('SELECT 1 FROM access_codes WHERE LOWER(email) = LOWER(?) LIMIT 1');
    $stmt->execute([$email]);
    return (bool)$stmt->fetchColumn();
}

/**
 * @param list<string> $emails
 * @return array{created:list<array{email:string,code:string}>,skipped:list<string>}
 */
function createAccessCodes(PDO $db, array $emails): array
{
    $created = [];
    $skipped = [];
    $uniqueEmails = [];

    foreach ($emails as $email) {
        $normalized = strtolower(trim((string)$email));
        if ($normalized === '' || strlen($normalized) > 254 || !filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            $skipped[] = $normalized !== '' ? $normalized . ' (ungültig)' : '(leere Adresse)';
            continue;
        }
        if (isset($uniqueEmails[$normalized])) {
            $skipped[] = $normalized . ' (in der Auswahl doppelt)';
            continue;
        }
        $uniqueEmails[$normalized] = true;
    }

    helferlisteBeginImmediateTransaction($db);

    try {
        $insert = $db->prepare("INSERT INTO access_codes (email, code, created_at) VALUES (?, ?, datetime('now'))");

        foreach (array_keys($uniqueEmails) as $email) {
            if (accessCodeEmailExists($db, $email)) {
                $skipped[] = $email . ' (bereits vorhanden)';
                continue;
            }

            $code = generateUniqueAccessCode($db);
            $insert->execute([$email, $code]);
            $created[] = ['email' => $email, 'code' => $code];
        }

        helferlisteCommitTransaction($db);
    } catch (Throwable $error) {
        helferlisteRollbackTransaction($db);
        throw $error;
    }

    return ['created' => $created, 'skipped' => $skipped];
}

function contactImportNormalizeUtf8(string $content): string
{
    if (str_starts_with($content, "\xEF\xBB\xBF")) {
        $content = substr($content, 3);
    }

    if (str_contains($content, "\0")) {
        throw new InvalidArgumentException('Die Datei scheint UTF-16 zu verwenden. Bitte als CSV UTF-8 speichern und erneut hochladen.');
    }

    if (preg_match('//u', $content) === 1) {
        return $content;
    }

    if (function_exists('iconv')) {
        $converted = iconv('Windows-1252', 'UTF-8//IGNORE', $content);
        if (is_string($converted) && preg_match('//u', $converted) === 1) {
            return $converted;
        }
    }

    throw new InvalidArgumentException('Die Zeichenkodierung konnte nicht gelesen werden. Bitte als CSV UTF-8 speichern.');
}

/**
 * @return array{delimiter:string,label:string}
 */
function contactImportDetectDelimiter(string $content): array
{
    $sampleLines = preg_split('/\r\n|\n|\r/', $content, 8) ?: [];
    $scores = [',' => 0, ';' => 0, "\t" => 0];

    foreach ($sampleLines as $line) {
        if (trim($line) === '' || preg_match('/^sep\s*=\s*[,;\t]\s*$/i', trim($line))) {
            continue;
        }
        foreach (array_keys($scores) as $delimiter) {
            $cells = str_getcsv($line, $delimiter, '"', '');
            $scores[$delimiter] = max($scores[$delimiter], count($cells));
        }
    }

    arsort($scores);
    $delimiter = (string)array_key_first($scores);
    if (($scores[$delimiter] ?? 0) < 1) {
        $delimiter = ';';
    }

    return [
        'delimiter' => $delimiter,
        'label' => match ($delimiter) {
            ';' => 'Semikolon',
            "\t" => 'Tabulator',
            default => 'Komma',
        },
    ];
}

function contactImportNormalizeHeader(string $value): string
{
    $value = strtolower(trim($value));
    $value = str_replace([' ', '_'], '-', $value);
    return trim((string)preg_replace('/-+/', '-', $value), '-');
}

function contactImportIsEmailHeader(string $value): bool
{
    return in_array(contactImportNormalizeHeader($value), [
        'email',
        'e-mail',
        'mail',
        'emailadresse',
        'e-mail-adresse',
        'email-address',
    ], true);
}

/**
 * @return array{
 *   filename:string,
 *   delimiter:string,
 *   headers:list<string>,
 *   ignored_headers:list<string>,
 *   rows:list<array{line:int,email:string,status:string,reason:string}>,
 *   counts:array{total:int,ready:int,existing:int,duplicate:int,invalid:int,empty:int},
 *   ready_emails:list<string>,
 *   truncated_preview:bool
 * }
 */
function contactImportParse(string $content, PDO $db, string $filename = 'kontakte.csv'): array
{
    if ($content === '') {
        throw new InvalidArgumentException('Die CSV-Datei ist leer.');
    }
    if (strlen($content) > CONTACT_IMPORT_MAX_BYTES) {
        throw new InvalidArgumentException('Die CSV-Datei ist größer als 2 MB.');
    }

    $content = contactImportNormalizeUtf8($content);
    $lineNumber = 0;
    if (preg_match('/\Asep\s*=\s*([,;\t])\s*(?:\r\n|\n|\r)/i', $content, $separatorMatch) === 1) {
        $declaredDelimiter = (string)$separatorMatch[1];
        $content = substr($content, strlen((string)$separatorMatch[0]));
        $lineNumber = 1;
        $delimiterInfo = [
            'delimiter' => $declaredDelimiter,
            'label' => match ($declaredDelimiter) {
                ';' => 'Semikolon',
                "\t" => 'Tabulator',
                default => 'Komma',
            },
        ];
    } else {
        $delimiterInfo = contactImportDetectDelimiter($content);
    }
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('Die CSV-Vorschau konnte nicht vorbereitet werden.');
    }

    fwrite($stream, $content);
    rewind($stream);

    $header = null;
    $emailColumn = null;

    while (($row = fgetcsv($stream, null, $delimiterInfo['delimiter'], '"', '')) !== false) {
        $lineNumber++;
        if (count($row) > 50) {
            fclose($stream);
            throw new InvalidArgumentException('Die CSV-Datei enthält mehr als 50 Spalten.');
        }
        if (count($row) === 1 && trim((string)($row[0] ?? '')) === '') {
            continue;
        }
        $header = array_map(static fn(mixed $cell): string => trim((string)$cell), $row);
        foreach ($header as $index => $cell) {
            if (contactImportIsEmailHeader($cell)) {
                $emailColumn = $index;
                break;
            }
        }
        break;
    }

    if ($header === null) {
        fclose($stream);
        throw new InvalidArgumentException('Die CSV-Datei enthält keine Kopfzeile.');
    }
    if ($emailColumn === null) {
        fclose($stream);
        throw new InvalidArgumentException('In der Kopfzeile fehlt eine Spalte E-Mail.');
    }

    $existingEmails = [];
    $query = $db->query('SELECT LOWER(email) FROM access_codes');
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) ?: [] as $existingEmail) {
        $existingEmails[(string)$existingEmail] = true;
    }

    $seen = [];
    $previewRows = [];
    $readyEmails = [];
    $counts = ['total' => 0, 'ready' => 0, 'existing' => 0, 'duplicate' => 0, 'invalid' => 0, 'empty' => 0];

    while (($row = fgetcsv($stream, null, $delimiterInfo['delimiter'], '"', '')) !== false) {
        $lineNumber++;
        if (count($row) > 50) {
            fclose($stream);
            throw new InvalidArgumentException('Die CSV-Datei enthält mehr als 50 Spalten.');
        }
        if (count($row) === 1 && trim((string)($row[0] ?? '')) === '') {
            continue;
        }

        $counts['total']++;
        if ($counts['total'] > CONTACT_IMPORT_MAX_ROWS) {
            fclose($stream);
            throw new InvalidArgumentException('Eine CSV-Datei darf höchstens 1.000 Kontakte enthalten.');
        }

        $email = strtolower(trim((string)($row[$emailColumn] ?? '')));
        $status = 'ready';
        $reason = 'wird neu angelegt';

        if ($email === '') {
            $status = 'empty';
            $reason = 'keine E-Mail-Adresse';
        } elseif (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $status = 'invalid';
            $reason = 'ungültige E-Mail-Adresse';
        } elseif (isset($seen[$email])) {
            $status = 'duplicate';
            $reason = 'in der Datei doppelt';
        } elseif (isset($existingEmails[$email])) {
            $status = 'existing';
            $reason = 'bereits vorhanden';
        }

        if ($email !== '') {
            $seen[$email] = true;
        }
        $counts[$status]++;

        if ($status === 'ready') {
            $readyEmails[] = $email;
        }
        if (count($previewRows) < CONTACT_IMPORT_PREVIEW_ROWS) {
            $previewRows[] = [
                'line' => $lineNumber,
                'email' => $email,
                'status' => $status,
                'reason' => $reason,
            ];
        }
    }

    fclose($stream);

    $ignoredHeaders = [];
    foreach ($header as $index => $cell) {
        if ($index !== $emailColumn && $cell !== '') {
            $ignoredHeaders[] = $cell;
        }
    }

    return [
        'filename' => basename($filename),
        'delimiter' => $delimiterInfo['label'],
        'headers' => $header,
        'ignored_headers' => $ignoredHeaders,
        'rows' => $previewRows,
        'counts' => $counts,
        'ready_emails' => $readyEmails,
        'truncated_preview' => $counts['total'] > count($previewRows),
    ];
}

/**
 * @param array<string,mixed>|null $upload
 * @return array<string,mixed>
 */
function contactImportPreviewUpload(?array $upload, PDO $db): array
{
    if ($upload === null || !isset($upload['error'])) {
        throw new InvalidArgumentException('Bitte eine CSV-Datei auswählen.');
    }

    $error = (int)$upload['error'];
    if ($error !== UPLOAD_ERR_OK) {
        $message = match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die CSV-Datei ist zu groß.',
            UPLOAD_ERR_NO_FILE => 'Bitte eine CSV-Datei auswählen.',
            default => 'Die CSV-Datei konnte nicht vollständig hochgeladen werden.',
        };
        throw new InvalidArgumentException($message);
    }

    $filename = basename((string)($upload['name'] ?? 'kontakte.csv'));
    $filename = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $filename));
    $filename = substr($filename !== '' ? $filename : 'kontakte.csv', 0, 180);
    if (!preg_match('/\.(csv|txt)$/i', $filename)) {
        throw new InvalidArgumentException('Bitte eine Datei mit der Endung .csv oder .txt auswählen.');
    }

    $size = (int)($upload['size'] ?? 0);
    if ($size <= 0 || $size > CONTACT_IMPORT_MAX_BYTES) {
        throw new InvalidArgumentException('Die CSV-Datei muss zwischen 1 Byte und 2 MB groß sein.');
    }

    $tmpName = (string)($upload['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new InvalidArgumentException('Die hochgeladene Datei konnte nicht sicher gelesen werden.');
    }

    $content = file_get_contents($tmpName, false, null, 0, CONTACT_IMPORT_MAX_BYTES + 1);
    if ($content === false) {
        throw new RuntimeException('Die CSV-Datei konnte nicht gelesen werden.');
    }

    return contactImportParse($content, $db, $filename);
}
