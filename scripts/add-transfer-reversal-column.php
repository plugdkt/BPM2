<?php

declare(strict_types=1);

/**
 * One-off schema migration: เพิ่มคอลัมน์ budget_transfers.reversed_of_transfer_id
 * เพื่อรองรับปุ่ม "โอนกลับหมวดเดิม" ในหน้าคำขอโยกย้ายงบ
 *
 * รันครั้งเดียวจาก command line เท่านั้น — ห้ามวางไว้ใต้ public/
 *   cd /path/to/bpm
 *   php scripts/add-transfer-reversal-column.php
 *
 * ปลอดภัยรันซ้ำได้ (idempotent) — เช็ค information_schema ก่อนว่ามีคอลัมน์อยู่แล้วหรือยัง
 * คำเตือน: แก้ schema production ต้อง backup ก่อนเสมอ (ดู CLAUDE.md)
 */

require_once __DIR__ . '/../src/lib/config.php';
require_once __DIR__ . '/../src/lib/db.php';

$db = bpm_db();

$stmt = $db->prepare(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'budget_transfers' AND COLUMN_NAME = 'reversed_of_transfer_id'"
);
$stmt->execute();
if ((int) $stmt->fetchColumn() > 0) {
    echo "คอลัมน์ reversed_of_transfer_id มีอยู่แล้ว ไม่ต้องทำอะไรเพิ่ม\n";
    exit(0);
}

$db->exec(
    "ALTER TABLE budget_transfers
     ADD COLUMN reversed_of_transfer_id INT UNSIGNED NULL AFTER decided_at,
     ADD CONSTRAINT fk_transfer_reversed_of FOREIGN KEY (reversed_of_transfer_id) REFERENCES budget_transfers(id)"
);

echo "เพิ่มคอลัมน์ reversed_of_transfer_id เรียบร้อยแล้ว\n";
