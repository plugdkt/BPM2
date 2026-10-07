<?php

declare(strict_types=1);

/**
 * One-off schema migration: เพิ่มตารางวงเงินแหล่งเงิน (fund_source_budgets, fund_group_budgets, fund_dept_budgets)
 * ชั้นวางแผนด้านบนของ แหล่งเงิน → หมวดงบ → สาขา (รายการงบ) — ดู spec.md ข้อ 6.7
 *
 * ต้องรัน scripts/add-fund-sources.php ก่อน (ตารางนี้อ้าง fund_sources)
 *   cd /path/to/bpm
 *   php scripts/add-fund-budgets.php
 *
 * ปลอดภัยรันซ้ำได้ (idempotent) — สร้างเฉพาะตารางที่ยังไม่มี ไม่แตะข้อมูลเดิม
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

if (!$tableExists('fund_sources')) {
    fwrite(STDERR, "ไม่พบตาราง fund_sources — รัน scripts/add-fund-sources.php ก่อน\n");
    exit(1);
}

if (!$tableExists('fund_source_budgets')) {
    $db->exec(
        'CREATE TABLE fund_source_budgets (
           id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           fiscal_year_id INT UNSIGNED NOT NULL,
           fund_source_id INT UNSIGNED NOT NULL,
           amount         DECIMAL(14,2) NOT NULL,
           updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
           UNIQUE KEY uq_fsb (fiscal_year_id, fund_source_id),
           CONSTRAINT fk_fsb_fy     FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_years(id),
           CONSTRAINT fk_fsb_source FOREIGN KEY (fund_source_id) REFERENCES fund_sources(id)
         ) ENGINE=InnoDB'
    );
    echo "สร้างตาราง fund_source_budgets แล้ว\n";
}

if (!$tableExists('fund_group_budgets')) {
    $db->exec(
        'CREATE TABLE fund_group_budgets (
           id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           fiscal_year_id INT UNSIGNED NOT NULL,
           fund_source_id INT UNSIGNED NOT NULL,
           group_id       INT UNSIGNED NOT NULL,
           amount         DECIMAL(14,2) NOT NULL,
           updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
           UNIQUE KEY uq_fgb (fiscal_year_id, fund_source_id, group_id),
           CONSTRAINT fk_fgb_fy     FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_years(id),
           CONSTRAINT fk_fgb_source FOREIGN KEY (fund_source_id) REFERENCES fund_sources(id),
           CONSTRAINT fk_fgb_group  FOREIGN KEY (group_id)       REFERENCES budget_groups(id)
         ) ENGINE=InnoDB'
    );
    echo "สร้างตาราง fund_group_budgets แล้ว\n";
}

if (!$tableExists('fund_dept_budgets')) {
    $db->exec(
        'CREATE TABLE fund_dept_budgets (
           id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           fiscal_year_id INT UNSIGNED NOT NULL,
           fund_source_id INT UNSIGNED NOT NULL,
           department_id  INT UNSIGNED NOT NULL,
           amount         DECIMAL(14,2) NOT NULL,
           updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
           UNIQUE KEY uq_fdb (fiscal_year_id, fund_source_id, department_id),
           CONSTRAINT fk_fdb_fy     FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_years(id),
           CONSTRAINT fk_fdb_source FOREIGN KEY (fund_source_id) REFERENCES fund_sources(id),
           CONSTRAINT fk_fdb_dept   FOREIGN KEY (department_id)  REFERENCES departments(id)
         ) ENGINE=InnoDB'
    );
    echo "สร้างตาราง fund_dept_budgets แล้ว\n";
}

echo "เสร็จสิ้น — migration วงเงินแหล่งเงินพร้อมใช้งาน\n";
