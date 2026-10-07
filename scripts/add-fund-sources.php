<?php

declare(strict_types=1);

/**
 * One-off schema migration: เพิ่ม "แหล่งเงิน" (fund sources — งบรายได้/งบแผ่นดิน/งบผลิตแพทย์/งบผลิตพยาบาล ฯลฯ)
 *   1) สร้างตาราง fund_sources + แถวเริ่มต้น UNSPECIFIED ("ไม่ระบุแหล่งเงิน") ซึ่งต้องได้ id = 1
 *   2) เพิ่ม budget_line_items.fund_source_id (NOT NULL DEFAULT 1) — รายการงบเดิมทุกแถวจะเป็น "ไม่ระบุแหล่งเงิน" ไปก่อน
 *   3) เพิ่ม FK และเปลี่ยน unique key เป็น (department_id, fiscal_year_id, fund_source_id, name)
 *      เพื่อให้รายการชื่อเดียวกันอยู่ได้หลายแหล่งเงิน
 *
 * รันครั้งเดียวจาก command line เท่านั้น — ห้ามวางไว้ใต้ public/
 *   cd /path/to/bpm
 *   php scripts/add-fund-sources.php
 *
 * ปลอดภัยรันซ้ำได้ (idempotent) — เช็คสถานะแต่ละขั้นก่อนทำ ไม่แตะข้อมูลงบเดิม
 * คำเตือน: แก้ schema production ต้อง backup ก่อนเสมอ (ดู CLAUDE.md)
 */

require_once __DIR__ . '/../src/lib/config.php';
require_once __DIR__ . '/../src/lib/db.php';

$db = bpm_db();

$tableExists = static function (string $table) use ($db): bool {
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
};
$columnExists = static function (string $table, string $column) use ($db): bool {
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
};

// 1) ตาราง fund_sources + แถว UNSPECIFIED
if (!$tableExists('fund_sources')) {
    $db->exec(
        'CREATE TABLE fund_sources (
           id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           name      VARCHAR(100) NOT NULL,
           code      VARCHAR(30)  NOT NULL UNIQUE,
           is_active TINYINT(1)   NOT NULL DEFAULT 1
         ) ENGINE=InnoDB'
    );
    echo "สร้างตาราง fund_sources แล้ว\n";
}

$unspecifiedId = $db->query("SELECT id FROM fund_sources WHERE code = 'UNSPECIFIED'")->fetchColumn();
if ($unspecifiedId === false) {
    $count = (int) $db->query('SELECT COUNT(*) FROM fund_sources')->fetchColumn();
    if ($count > 0) {
        fwrite(STDERR, "มี fund_sources อยู่แล้วแต่ไม่มีแถว UNSPECIFIED — หยุดไว้ก่อนเพราะ DEFAULT 1 อาจชี้ผิดแหล่ง ให้ตรวจสอบด้วยมือ\n");
        exit(1);
    }
    $db->prepare('INSERT INTO fund_sources (name, code) VALUES (?, ?)')->execute(['ไม่ระบุแหล่งเงิน', 'UNSPECIFIED']);
    $unspecifiedId = $db->lastInsertId();
    echo "เพิ่มแหล่งเงินเริ่มต้น UNSPECIFIED แล้ว\n";
}
if ((int) $unspecifiedId !== 1) {
    fwrite(STDERR, "แถว UNSPECIFIED ต้องมี id = 1 (ตอนนี้เป็น {$unspecifiedId}) — หยุดไว้ก่อน ให้ตรวจสอบด้วยมือ\n");
    exit(1);
}

// 2) คอลัมน์ budget_line_items.fund_source_id
if (!$columnExists('budget_line_items', 'fund_source_id')) {
    $db->exec('ALTER TABLE budget_line_items ADD COLUMN fund_source_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER group_id');
    echo "เพิ่มคอลัมน์ budget_line_items.fund_source_id แล้ว (รายการเดิมทั้งหมด = UNSPECIFIED)\n";
}

// 3) FK
$fk = $db->prepare(
    "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'budget_line_items' AND CONSTRAINT_NAME = 'fk_li_fundsource'"
);
$fk->execute();
if ((int) $fk->fetchColumn() === 0) {
    $db->exec('ALTER TABLE budget_line_items ADD CONSTRAINT fk_li_fundsource FOREIGN KEY (fund_source_id) REFERENCES fund_sources(id)');
    echo "เพิ่ม FK fk_li_fundsource แล้ว\n";
}

// 4) unique key ใหม่ (รวม fund_source_id)
$uq = $db->prepare(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'budget_line_items' AND INDEX_NAME = 'uq_line_item' AND COLUMN_NAME = 'fund_source_id'"
);
$uq->execute();
if ((int) $uq->fetchColumn() === 0) {
    $db->exec(
        'ALTER TABLE budget_line_items
         DROP INDEX uq_line_item,
         ADD UNIQUE KEY uq_line_item (department_id, fiscal_year_id, fund_source_id, name)'
    );
    echo "เปลี่ยน unique key uq_line_item ให้รวม fund_source_id แล้ว\n";
}

echo "เสร็จสิ้น — migration แหล่งเงินพร้อมใช้งาน\n";
