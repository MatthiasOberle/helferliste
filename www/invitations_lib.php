<?php

declare(strict_types=1);

require_once __DIR__ . '/app_config.php';

const INVITATION_SUBJECT_MAX_LENGTH = 180;
const INVITATION_BODY_MAX_LENGTH = 4000;

function invitationTextLength(string $value): int
{
    $result = preg_match_all('/./us', $value, $matches);
    return $result === false ? strlen($value) : $result;
}

/** @return array<string,string> */
function invitationTemplateValues(array $config, string $link, string $code): array
{
    return [
        '{veranstaltung}' => trim((string)($config['event_name'] ?? '')),
        '{organisation}' => trim((string)($config['event_organizer'] ?? '')),
        '{zeitraum}' => appEventDateRange($config),
        '{link}' => $link,
        '{code}' => $code,
    ];
}

function invitationRenderTemplate(string $template, array $config, string $link, string $code): string
{
    return strtr(str_replace(["\r\n", "\r"], "\n", trim($template)), invitationTemplateValues($config, $link, $code));
}

function invitationValidateTemplate(string $subject, string $body): void
{
    $subject = trim($subject);
    $body = trim($body);
    if ($subject === '' || $body === '') {
        throw new InvalidArgumentException('Betreff und Nachricht dürfen nicht leer sein.');
    }
    if (invitationTextLength($subject) > INVITATION_SUBJECT_MAX_LENGTH || invitationTextLength($body) > INVITATION_BODY_MAX_LENGTH) {
        throw new InvalidArgumentException('Betreff oder Nachricht ist zu lang.');
    }
    if (!str_contains($body, '{link}') && !str_contains($body, '{code}')) {
        throw new InvalidArgumentException('Die Nachricht muss mindestens {link} oder {code} enthalten.');
    }
}

function invitationNormalizeFilter(string $filter): string
{
    return in_array($filter, ['pending', 'help', 'no_time', 'all'], true) ? $filter : 'pending';
}

function invitationStatusLabel(?string $status): string
{
    return match ($status) {
        'help' => 'Zusage',
        'no_time' => 'Keine Zeit',
        default => 'Noch keine Rückmeldung',
    };
}

/** @return array{pending:int,help:int,no_time:int,all:int} */
function invitationCounts(PDO $db): array
{
    $row = $db->query("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN e.id IS NULL THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN e.status = 'help' THEN 1 ELSE 0 END) AS help,
        SUM(CASE WHEN e.status = 'no_time' THEN 1 ELSE 0 END) AS no_time
        FROM access_codes ac
        LEFT JOIN entries e ON e.access_code_id = ac.id")->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'pending' => (int)($row['pending'] ?? 0),
        'help' => (int)($row['help'] ?? 0),
        'no_time' => (int)($row['no_time'] ?? 0),
        'all' => (int)($row['total'] ?? 0),
    ];
}

/** @return list<array<string,mixed>> */
function invitationRows(PDO $db, string $filter, int $limit = 10): array
{
    $filter = invitationNormalizeFilter($filter);
    $limit = max(1, min(250, $limit));
    $where = match ($filter) {
        'pending' => 'WHERE e.id IS NULL',
        'help' => "WHERE e.status = 'help'",
        'no_time' => "WHERE e.status = 'no_time'",
        default => '',
    };
    $rows = $db->query("SELECT ac.id, ac.email, ac.code, ac.created_at,
            e.id AS entry_id, e.name AS entry_name, e.status AS entry_status, e.created_at AS responded_at
        FROM access_codes ac
        LEFT JOIN entries e ON e.access_code_id = ac.id
        {$where}
        ORDER BY ac.created_at DESC, ac.id DESC
        LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'email' => (string)$row['email'],
        'code' => (string)$row['code'],
        'created_at' => (string)($row['created_at'] ?? ''),
        'entry_id' => $row['entry_id'] === null ? null : (int)$row['entry_id'],
        'entry_name' => (string)($row['entry_name'] ?? ''),
        'entry_status' => $row['entry_status'] === null ? null : (string)$row['entry_status'],
        'responded_at' => (string)($row['responded_at'] ?? ''),
    ], $rows);
}

/** @return array<string,mixed>|null */
function invitationRowById(PDO $db, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = $db->prepare("SELECT ac.id, ac.email, ac.code, ac.created_at,
            e.id AS entry_id, e.name AS entry_name, e.status AS entry_status, e.created_at AS responded_at
        FROM access_codes ac
        LEFT JOIN entries e ON e.access_code_id = ac.id
        WHERE ac.id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'id' => (int)$row['id'],
        'email' => (string)$row['email'],
        'code' => (string)$row['code'],
        'created_at' => (string)($row['created_at'] ?? ''),
        'entry_id' => $row['entry_id'] === null ? null : (int)$row['entry_id'],
        'entry_name' => (string)($row['entry_name'] ?? ''),
        'entry_status' => $row['entry_status'] === null ? null : (string)$row['entry_status'],
        'responded_at' => (string)($row['responded_at'] ?? ''),
    ];
}

/** @return array{subject:string,body:string,link:string,mailto:string} */
function invitationMessage(array $config, array $row, string $kind, string $baseUrl): array
{
    $kind = $kind === 'reminder' ? 'reminder' : 'invitation';
    $link = rtrim($baseUrl, '/') . '/index.php?code=' . rawurlencode((string)$row['code']);
    $subjectKey = $kind === 'reminder' ? 'reminder_subject' : 'invitation_subject';
    $bodyKey = $kind === 'reminder' ? 'reminder_body' : 'invitation_body';
    $subject = invitationRenderTemplate((string)($config[$subjectKey] ?? ''), $config, $link, (string)$row['code']);
    $body = invitationRenderTemplate((string)($config[$bodyKey] ?? ''), $config, $link, (string)$row['code']);
    $mailto = 'mailto:' . rawurlencode((string)$row['email'])
        . '?subject=' . rawurlencode($subject)
        . '&body=' . rawurlencode($body);

    return ['subject' => $subject, 'body' => $body, 'link' => $link, 'mailto' => $mailto];
}
