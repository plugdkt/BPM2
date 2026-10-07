<?php

declare(strict_types=1);

/**
 * One-off schema migration: เพิ่มคอลัมน์ transactions.requester_user_id ("ผู้ขอใช้" — ผู้ที่ขอใช้งบสำหรับรายการเบิกจ่ายนั้น)
 * ชี้ไปที่ users.id (คนที่อยู่ในระบบ เลือกจากรายชื่อตอนบันทึก) — nullable เพราะรายการเก่าก่อนมีช่องนี้ไม่มีข้อมูล
 * เพิ่ม KEY idx_txn_requester ไว้สำหรับกรอง/แจกแจงยอดตามผู้ขอใช้ในหน้าสมุดรายการ
 *
 * รันครั้งเดียวจาก command line เท่านั้น — ห้ามวางไว้ใต้ public/
 *   cd /path/to/bpm
 *   php scripts/add-transaction-requester.php
 *
 * ปลอดภัยรันซ้ำได้ (idempotent) — เช็ค information_schema ก่อน ไม่แตะข้อมูลรายการเดิม
 * คำเตือน: แก้ schema production ต้อง backup ก่อนเสมอ (ดู CLAUDE.md) และต้องรันสคริปต์นี้ก่อน pull โค้ดใหม่
 * (โค้ดใหม่ query คอลัมน์นี้)
 */

require_once __DIR__ . '/../src/lib/config.php';
require_once __DIR__ . '/../src/lib/db.php';

$db = bpm_db();

$stmt = $db->prepare(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'requester_user_id'"
);
$stmt->execute();
if ((int) $stmt->fetchColumn() > 0) {
    echo "คอลัมน์ requester_user_id มีอยู่แล้ว ไม่ต้องทำอะไรเพิ่ม\n";
    exit(0);
}

$db->exec(
    "ALTER TABLE transactions
     ADD COLUMN requester_user_id INT UNSIGNED NULL AFTER reference_no,
     ADD KEY idx_txn_requester (requester_user_id),
     ADD CONSTRAINT fk_txn_requester FOREIGN KEY (requester_user_id) REFERENCES users(id)"
);

echo "เพิ่มคอลัมน์ transactions.requester_user_id เรียบร้อยแล้ว\n";
