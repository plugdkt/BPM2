<?php

declare(strict_types=1);

/**
 * เปลี่ยนชื่อรายการงบปีงบ 2570 ให้ตรงกับหนังสือแจ้งงบประมาณของกองแผนงาน (อว 7310/1946 ลงวันที่ 18 ก.ย. 2569)
 *   - "โครงการพัฒนาศักยภาพบุคลากรสายวิชาการ คณะวิทยาศาสตร์การแพทย์" (งานบริหาร ฿50,000; ชื่อจากไฟล์ Excel ของงานแผน มีตัวขึ้นบรรทัดใหม่ติดมา)
 *     → "โครงการสนับสนุนงานวิจัยแนวหน้า และนวัตกรรมเชิงพาณิชย์" (รหัส 704104038 ในระบบมหาวิทยาลัย ยอด ฿50,000 เท่าเดิม)
 *
 * ไม่แตะ "ค่าวัสดุก่อสร้าง ฿10,000" โดยเจตนา: หนังสือรวมไว้ใน "ค่าวัสดุการเกษตร ฿24,000" แต่ผู้ใช้ยืนยันว่างานแผนกรอกในระบบมหาวิทยาลัยผิด
 * ระบบเราคงแยก "ค่าวัสดุก่อสร้าง" ไว้เป็นรายการของตัวเองตามที่ถูกต้อง (ยอดรวมหมวดค่าวัสดุยังตรงหนังสือ)
 *
 * เปลี่ยนแค่ชื่อ (budget_line_items.name) — ยอดงบ ยอดโอน และรายการเบิกจ่ายผูกกับ id จึงไม่กระทบ บันทึก audit_logs (LINE_ITEM_UPDATE) ทุกรายการ
 *
 *   php scripts/rename-fy2570-line-items.php --dry-run   # ดูผลก่อน ไม่เขียนอะไร
 *   php scripts/rename-fy2570-line-items.php             # เขียนจริง
 *
 * ปลอดภัยรันซ้ำได้ (ถ้าชื่อใหม่มีอยู่แล้วและไม่เหลือชื่อเก่า = ข้าม) — production ต้อง backup ก่อน
 */

require_once __DIR__ . '/../src/lib/config.php';
require_once __DIR__ . '/../src/lib/db.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$yearBe = 2570;

// [รหัสสาขา, ขึ้นต้นชื่อเดิม (ตัดช่องว่าง/บรรทัดใหม่ท้ายชื่อออกแล้วเทียบ), ชื่อใหม่]
$renames = [
    ['OFFICE', 'โครงการพัฒนาศักยภาพบุคลากรสายวิชาการ', 'โครงการสนับสนุนงานวิจัยแนวหน้า และนวัตกรรมเชิงพาณิชย์'],
];

$db = bpm_db();

$fy = $db->prepare('SELECT id, status FROM fiscal_years WHERE year_be = ?');
$fy->execute([$yearBe]);
$fiscalYear = $fy->fetch();
if (!$fiscalYear || $fiscalYear['status'] === 'CLOSED') {
    fwrite(STDERR, "ไม่พบปีงบ {$yearBe} หรือปิดแล้ว\n");
    exit(1);
}
$fyId = (int) $fiscalYear['id'];
$actor = $db->query("SELECT id FROM users WHERE role = 'ADMIN' ORDER BY id LIMIT 1")->fetchColumn();

$find = $db->prepare(
    'SELECT li.id, li.name, li.starting_amount, li.fund_source_id
     FROM budget_line_items li JOIN departments d ON d.id = li.department_id
     WHERE d.code = ? AND li.fiscal_year_id = ? AND li.name LIKE ?'
);
$clash = $db->prepare('SELECT COUNT(*) FROM budget_line_items WHERE department_id = (SELECT id FROM departments WHERE code = ?) AND fiscal_year_id = ? AND fund_source_id = ? AND name = ? AND id <> ?');
$update = $db->prepare('UPDATE budget_line_items SET name = ? WHERE id = ?');

echo ($dryRun ? '[DRY RUN] ' : '') . "เปลี่ยนชื่อรายการงบปีงบ {$yearBe} (fiscal_year_id={$fyId})\n";

$renamed = 0;
$skipped = 0;
if (!$dryRun) {
    $db->beginTransaction();
}
try {
    foreach ($renames as [$deptCode, $oldPrefix, $newName]) {
        $find->execute([$deptCode, $fyId, $oldPrefix . '%']);
        $rows = $find->fetchAll();

        if (empty($rows)) {
            $find->execute([$deptCode, $fyId, $newName]);
            if ($find->fetch()) {
                $skipped++;
                echo "  = ข้าม (ใช้ชื่อใหม่อยู่แล้ว) {$deptCode} / {$newName}\n";
                continue;
            }
            throw new RuntimeException("ไม่พบรายการงบ {$deptCode} / \"{$oldPrefix}…\" ในปีงบ {$yearBe}");
        }
        if (count($rows) > 1) {
            throw new RuntimeException("พบรายการงบ {$deptCode} / \"{$oldPrefix}…\" มากกว่า 1 แถว — ตรวจสอบก่อน (ไม่เขียนอะไร)");
        }

        $r = $rows[0];
        $clash->execute([$deptCode, $fyId, (int) $r['fund_source_id'], $newName, (int) $r['id']]);
        if ((int) $clash->fetchColumn() > 0) {
            throw new RuntimeException("มีรายการชื่อ \"{$newName}\" อยู่แล้วในสาขา {$deptCode} (ชนกับ unique key) — ตรวจสอบก่อน");
        }

        echo "  ~ {$deptCode} (id={$r['id']}, ฿" . number_format((float) $r['starting_amount'], 2) . "): \"" . str_replace(["\r", "\n"], ['', '\n'], $r['name']) . "\" -> \"{$newName}\"\n";
        if (!$dryRun) {
            $update->execute([$newName, (int) $r['id']]);
            if ($actor !== false) {
                bpm_audit_log((int) $actor, 'LINE_ITEM_UPDATE', 'budget_line_items', (int) $r['id'], ['name' => $r['name']], ['name' => $newName, 'source' => 'rename-fy2570-line-items']);
            }
        }
        $renamed++;
    }

    if (!$dryRun) {
        $db->commit();
    }
} catch (Throwable $e) {
    if (!$dryRun && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'ผิดพลาด: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "\nเสร็จสิ้น — เปลี่ยนชื่อ {$renamed} รายการ ข้าม {$skipped} รายการ" . ($dryRun ? ' (dry run — ไม่ได้เขียนข้อมูล)' : '') . "\n";
