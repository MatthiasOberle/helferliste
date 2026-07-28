<?php

declare(strict_types=1);

function validIsoDate(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function validIsoTime(string $value): bool
{
    if (!preg_match('/^\d{2}:\d{2}$/', $value)) {
        return false;
    }
    $time = DateTimeImmutable::createFromFormat('!H:i', $value);
    return $time !== false && $time->format('H:i') === $value;
}

function normalizeOptionalDate(string $value): string
{
    $value = trim($value);
    if ($value !== '' && !validIsoDate($value)) {
        throw new InvalidArgumentException('Das Datum ist ungültig.');
    }
    return $value;
}

function normalizeOptionalTime(string $value): string
{
    $value = trim($value);
    if ($value !== '' && !validIsoTime($value)) {
        throw new InvalidArgumentException('Die Uhrzeit ist ungültig.');
    }
    return $value;
}

function validateTimeRange(string $startTime, string $endTime): void
{
    if ($endTime !== '' && $startTime === '') {
        throw new InvalidArgumentException('Für eine Endzeit muss auch eine Beginnzeit angegeben werden.');
    }
    if ($startTime !== '' && $endTime !== '' && $endTime <= $startTime) {
        throw new InvalidArgumentException('Die Endzeit muss nach der Beginnzeit liegen.');
    }
}

function formatGermanDate(string $value): string
{
    if (!validIsoDate($value)) {
        return $value;
    }
    return DateTimeImmutable::createFromFormat('!Y-m-d', $value)->format('d.m.Y');
}

function shiftScheduleParts(array $shift): array
{
    $parts = [];
    $date = trim((string)($shift['shift_date'] ?? ''));
    $start = trim((string)($shift['start_time'] ?? ''));
    $end = trim((string)($shift['end_time'] ?? ''));
    $location = trim((string)($shift['location'] ?? ''));

    if ($date !== '') {
        $parts[] = formatGermanDate($date);
    }
    if ($start !== '') {
        $parts[] = $end !== '' ? $start . '–' . $end . ' Uhr' : 'ab ' . $start . ' Uhr';
    }
    if ($location !== '') {
        $parts[] = $location;
    }
    return $parts;
}

function shiftScheduleText(array $shift): string
{
    return implode(' · ', shiftScheduleParts($shift));
}

function shiftDisplayLabel(array $shift): string
{
    $title = trim((string)($shift['title'] ?? ''));
    $schedule = shiftScheduleText($shift);
    return $schedule === '' ? $title : $title . ' (' . $schedule . ')';
}
