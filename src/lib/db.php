<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * PDO connection แบบ singleton ต่อ request
 * PDO::ATTR_EMULATE_PREPARES = false เสมอ (ดู spec.md ข้อ 4) — ป้องกัน SQL injection แบบ native prepared statement จริง
 */
function bpm_db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $db = bpm_config()['db'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            $db['port'],
            $db['name'],
            $db['charset']
        );

        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    return $pdo;
}

/**
 * บันทึกลง audit_logs — เรียกภายใน DB transaction เดียวกับการแก้ข้อมูลจริงเสมอ (ดู spec.md ข้อ 5.2)
 * $oldValue/$newValue เป็น array เฉยๆ ฟังก์ชันนี้ json_encode ให้เอง
 */
function bpm_audit_log(int $actorId, string $action, string $targetTable, int $targetId, ?array $oldValue, ?array $newValue): void
{
    $stmt = bpm_db()->prepare(
        'INSERT INTO audit_logs (actor_id, action, target_table, target_id, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $actorId,
        $action,
        $targetTable,
        $targetId,
        $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
        $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
    ]);
}
