<?php

declare(strict_types=1);

/**
 * One-off schema migration: เพิ่มค่า 'DEPT_HEAD' (หัวหน้าสาขา) เข้า users.role ENUM
 * รองรับ role ใหม่ — ดูงบประมาณเฉพาะสาขาตัวเอง + ยื่นคำขอโยกย้ายงบได้ แต่บันทึกเบิกจ่าย/รายรับไม่ได้
 *
 * รันครั้งเดียวจาก command line เท่านั้น — ห้ามวางไว้ใต้ public/
 *   cd /path/to/bpm
 *   php scripts/add-dept-head-role.php
 *
 * ปลอดภัยรันซ้ำได้ (idempotent) — เช็คค่าใน ENUM ก่อนว่ามี DEPT_HEAD อยู่แล้วหรือยัง
 * คำเตือน: แก้ schema production ต้อง backup ก่อนเสมอ (ดู CLAUDE.md)
 */

require_once __DIR__ . '/../src/lib/config.php';
require_once __DIR__ . '/../src/lib/db.php';

$db = bpm_db();

$stmt = $db->prepare(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'"
);
$stmt->execute();
$columnType = (string) $stmt->fetchColumn();

if (str_contains($columnType, 'DEPT_HEAD')) {
    echo "ค่า DEPT_HEAD มีอยู่ใน users.role แล้ว ไม่ต้องทำอะไรเพิ่ม\n";
    exit(0);
}

$db->exec("ALTER TABLE users MODIFY COLUMN role ENUM('ADMIN','DEPT_STAFF','EXECUTIVE_VIEWER','DEPT_HEAD') NULL");

echo "เพิ่มค่า DEPT_HEAD เข้า users.role เรียบร้อยแล้ว\n";
