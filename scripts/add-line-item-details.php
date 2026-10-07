<?php

declare(strict_types=1);

/**
 * One-off schema migration: เพิ่มตาราง line_item_details (รายละเอียดรายการงบกลุ่มครุภัณฑ์: ชื่อ/จำนวน/หน่วย/ราคาต่อหน่วย/รวม)
 *
 *   cd /path/to/bpm
 *   php scripts/add-line-item-details.php
 *
 * ปลอดภัยรันซ้ำได้ (idempotent) — สร้างเฉพาะถ้ายังไม่มีตาราง ไม่แตะข้อมูลเดิม
 * คำเตือน: แก้ schema production ต้อง backup ก่อนเสมอ (ดู CLAUDE.md)
 */

require_once __DIR__ . '/../src/lib/config.php';
require_once __DIR__ . '/../src/lib/db.php';

$db = bpm_db();

$stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
$stmt->execute(['line_item_details']);
if ((int) $stmt->fetchColumn() > 0) {
    echo "ตาราง line_item_details มีอยู่แล้ว ไม่ต้องทำอะไรเพิ่ม\n";
    exit(0);
}

$db->exec(
    'CREATE TABLE line_item_details (
       id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       line_item_id INT UNSIGNED NOT NULL,
       name         VARCHAR(255) NOT NULL,
       quantity     DECIMAL(12,2) NOT NULL DEFAULT 1,
       unit         VARCHAR(30)  NULL,
       unit_price   DECIMAL(14,2) NOT NULL DEFAULT 0,
       amount       DECIMAL(14,2) NOT NULL DEFAULT 0,
       note         VARCHAR(500) NULL,
       is_active    TINYINT(1)   NOT NULL DEFAULT 1,
       created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
       updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       KEY idx_lid_item (line_item_id),
       CONSTRAINT fk_lid_item FOREIGN KEY (line_item_id) REFERENCES budget_line_items(id)
     ) ENGINE=InnoDB'
);

echo "สร้างตาราง line_item_details แล้ว\n";
