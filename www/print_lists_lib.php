<?php

declare(strict_types=1);

require_once __DIR__ . '/shift_helpers.php';

/**
 * @return array{shift_table:string,link_table:string,foreign_column:string}
 */
function printListTypeConfig(string $type): array
{
    return match ($type) {
        'normal' => [
            'shift_table' => 'shifts',
            'link_table' => 'entry_shifts',
            'foreign_column' => 'shift_id',
        ],
        'flexible' => [
            'shift_table' => 'springer_shifts',
            'link_table' => 'entry_springer_shifts',
            'foreign_column' => 'springer_shift_id',
        ],
        default => throw new InvalidArgumentException('Unbekannte Schichtart.'),
    };
}

/**
 * @return list<array{id:int,name:string}>
 */
function printListHelpersForShift(PDO $db, string $type, int $shiftId): array
{
    $config = printListTypeConfig($type);
    $stmt = $db->prepare("SELECT e.id, e.name
        FROM {$config['link_table']} link
        JOIN entries e ON e.id = link.entry_id
        WHERE link.{$config['foreign_column']} = ?
          AND e.status = 'help'
        ORDER BY e.name COLLATE NOCASE ASC, e.id ASC");
    $stmt->execute([$shiftId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'name' => trim((string)$row['name']),
    ], $rows);
}

/**
 * @return list<array<string,mixed>>
 */
function printListShifts(PDO $db, string $type): array
{
    $config = printListTypeConfig($type);
    $rows = $db->query("SELECT *
        FROM {$config['shift_table']}
        WHERE active = 1
        ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $shift) use ($db, $type): array {
        $helpers = printListHelpersForShift($db, $type, (int)$shift['id']);
        $capacity = max(1, (int)$shift['max_slots']);
        $remainingCapacity = max(0, $capacity - count($helpers));

        return $shift + [
            'helpers' => $helpers,
            'assigned_count' => count($helpers),
            'attendance_blank_rows' => min(3, $remainingCapacity),
            'schedule_text' => shiftScheduleText($shift),
        ];
    }, $rows);
}

/**
 * @return list<array{id:int,name:string}>
 */
function printListUnassignedHelpers(PDO $db): array
{
    $rows = $db->query("SELECT e.id, e.name
        FROM entries e
        WHERE e.status = 'help'
          AND NOT EXISTS (
              SELECT 1
              FROM entry_shifts es
              JOIN shifts s ON s.id = es.shift_id AND s.active = 1
              WHERE es.entry_id = e.id
          )
          AND NOT EXISTS (
              SELECT 1
              FROM entry_springer_shifts ess
              JOIN springer_shifts ss ON ss.id = ess.springer_shift_id AND ss.active = 1
              WHERE ess.entry_id = e.id
          )
        ORDER BY e.name COLLATE NOCASE ASC, e.id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'name' => trim((string)$row['name']),
    ], $rows);
}

/**
 * @return array{
 *   groups:list<array{key:string,label:string,shifts:list<array<string,mixed>>}>,
 *   unassigned_helpers:list<array{id:int,name:string}>,
 *   shift_count:int,
 *   assignment_count:int
 * }
 */
function printListData(PDO $db, string $normalLabel, string $flexibleLabel): array
{
    $groups = [
        [
            'key' => 'normal',
            'label' => $normalLabel,
            'shifts' => printListShifts($db, 'normal'),
        ],
        [
            'key' => 'flexible',
            'label' => $flexibleLabel,
            'shifts' => printListShifts($db, 'flexible'),
        ],
    ];

    $shiftCount = 0;
    $assignmentCount = 0;
    foreach ($groups as $group) {
        $shiftCount += count($group['shifts']);
        foreach ($group['shifts'] as $shift) {
            $assignmentCount += (int)$shift['assigned_count'];
        }
    }

    return [
        'groups' => $groups,
        'unassigned_helpers' => printListUnassignedHelpers($db),
        'shift_count' => $shiftCount,
        'assignment_count' => $assignmentCount,
    ];
}

