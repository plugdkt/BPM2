<?php

declare(strict_types=1);

/**
 * Import รายละเอียดครุภัณฑ์ปีงบ 2570 (15 รายการ ใน 8 รายการงบ รวม 1,111,000 บาท) ลง line_item_details
 *
 * ที่มา: ไฟล์ "รวมจัดซื้อปี 70.xlsx" (ชีท ครุภัณฑ์การศึกษา/ครุภัณฑ์คอม/ครุภัณฑ์สำนักงาน/ครุภัณฑ์งานบ้านงานครัว — มีสาขา จำนวน หน่วย ราคาต่อหน่วย)
 * ตรวจยอดเทียบกับ PDF "งบลงทุน" จากระบบ budget ของมหาวิทยาลัย (7 ต.ค. 2569) แล้วตรงทุกรายการ ยกเว้น 1 จุด:
 *   - ครุภัณฑ์งานบ้านงานครัว (จุลชีววิทยา): ชีทจัดซื้อลงเตาไมโครเวฟ 2 × 18,000 (รวม 45,000) แต่แผนงบและระบบมหาวิทยาลัยลง 20,000
 *     (ตู้กดน้ำ 9,000 + เตาไมโครเวฟ 11,000) สคริปต์นี้จึงใช้ยอดตามระบบมหาวิทยาลัย: เตาไมโครเวฟ 2 เตา (จำนวนตามแผนงบ) × 5,500 = 11,000
 *     ราคาต่อหน่วย 5,500 คำนวณจากยอดรวม 11,000 ÷ 2 ไม่ได้มาจากใบเสนอราคา — ให้ยืนยันกับงานแผน/พัสดุแล้วแก้ที่หน้า "รายละเอียดครุภัณฑ์"
 *
 *   php scripts/import-fy2570-equipment-details.php --dry-run   # ดูผลก่อน ไม่เขียนอะไร
 *   php scripts/import-fy2570-equipment-details.php             # เขียนจริง
 *
 * ต้องรัน add-line-item-details.php ก่อน ปลอดภัยรันซ้ำได้ (ข้ามรายการที่มีชื่อเดียวกันใน line item เดียวกันอยู่แล้ว) — production ต้อง backup ก่อน
 */

require_once __DIR__ . '/../src/lib/config.php';
require_once __DIR__ . '/../src/lib/db.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$yearBe = 2570;

// [รหัสสาขา, ชื่อรายการงบ, ชื่อครุภัณฑ์, จำนวน, หน่วย, ราคาต่อหน่วย, หมายเหตุ]
$rows = [
    ['MICRO', 'ครุภัณฑ์การศึกษา', 'ตู้ควบคุมอุณหภูมิแบบเขย่า (Shaking Incubator)', 1, 'ตู้', 150000, null],
    ['MICRO', 'ครุภัณฑ์การศึกษา', 'เครื่องชั่งไฟฟ้า 2 ตำแหน่ง', 1, 'เครื่อง', 40000, null],
    ['BIOCHEM', 'ครุภัณฑ์การศึกษา', 'ตู้บ่มเชื้ออุณหภูมิต่ำแบบเขย่า (Refrigerated Shaking Incubator)', 1, 'ตู้', 170000, null],
    ['BIOCHEM', 'ครุภัณฑ์การศึกษา', 'เครื่องปั่นเหวี่ยงตกตะกอนความเร็วรอบสูงแบบตั้งโต๊ะ ไม่ควบคุมอุณหภูมิ', 1, 'เครื่อง', 178000, null],
    ['PHYSIO', 'ครุภัณฑ์การศึกษา', 'ตู้แช่ -20 องศาเซลเซียส', 1, 'ตู้', 40000, null],
    ['PHYSIO', 'ครุภัณฑ์การศึกษา', 'ตู้แช่ 2 ประตู', 1, 'ตู้', 40000, null],
    ['ANATOMY', 'ครุภัณฑ์การศึกษา', 'เครื่องนึ่งฆ่าเชื้อ (Autoclave) ขนาดไม่น้อยกว่า 50 ลิตร', 1, 'เครื่อง', 160000, null],
    ['ANATOMY', 'ครุภัณฑ์การศึกษา', 'ตู้เก็บกล้องจุลทรรศน์', 3, 'ตู้', 55000, null],
    ['MICRO', 'ครุภัณฑ์คอมพิวเตอร์', 'เครื่องปริ้น', 1, 'ชุด', 18000, null],
    ['PHYSIO', 'ครุภัณฑ์คอมพิวเตอร์', 'เครื่องคอมพิวเตอร์ตั้งโต๊ะ', 4, 'เครื่อง', 25000, null],
    ['MICRO', 'ครุภัณฑ์สำนักงาน', 'ชุดโซฟา', 1, 'ชุด', 12000, null],
    ['MICRO', 'ครุภัณฑ์สำนักงาน', 'ชุดโต๊ะพร้อมเก้าอี้', 1, 'ชุด', 16000, null],
    ['MICRO', 'ครุภัณฑ์สำนักงาน', 'โต๊ะพับ', 1, 'ตัว', 2000, null],
    ['MICRO', 'ครุภัณฑ์งานบ้านงานครัว', 'ตู้กดน้ำ', 1, 'เครื่อง', 9000, null],
    ['MICRO', 'ครุภัณฑ์งานบ้านงานครัว', 'เตาไมโครเวฟ', 2, 'เตา', 5500, 'ราคาต่อหน่วยคำนวณจากยอดรวม 11,000 ในระบบ budget มหาวิทยาลัย (ชีทจัดซื้อลง 2 × 18,000 ซึ่งไม่ตรงแผนงบ) — รอยืนยัน'],
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

if ((int) $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'line_item_details'")->fetchColumn() === 0) {
    fwrite(STDERR, "ไม่พบตาราง line_item_details — รัน scripts/add-line-item-details.php ก่อน\n");
    exit(1);
}

$actor = $db->query("SELECT id FROM users WHERE role = 'ADMIN' ORDER BY id LIMIT 1")->fetchColumn();

$findItem = $db->prepare(
    'SELECT li.id, li.starting_amount, li.group_id, g.code AS group_code
     FROM budget_line_items li
     JOIN departments d ON d.id = li.department_id
     LEFT JOIN budget_groups g ON g.id = li.group_id
     WHERE d.code = ? AND li.fiscal_year_id = ? AND li.name = ? AND li.is_active = 1'
);
$exists = $db->prepare('SELECT COUNT(*) FROM line_item_details WHERE line_item_id = ? AND name = ?');
$insert = $db->prepare('INSERT INTO line_item_details (line_item_id, name, quantity, unit, unit_price, amount, note, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');

echo ($dryRun ? '[DRY RUN] ' : '') . "Import รายละเอียดครุภัณฑ์ ปีงบ {$yearBe} (fiscal_year_id={$fyId})\n";

$sums = [];
$itemInfo = [];
$added = 0;
$skipped = 0;

if (!$dryRun) {
    $db->beginTransaction();
}
try {
    foreach ($rows as [$deptCode, $itemName, $name, $qty, $unit, $price, $note]) {
        $findItem->execute([$deptCode, $fyId, $itemName]);
        $li = $findItem->fetch();
        if (!$li) {
            throw new RuntimeException("ไม่พบรายการงบ {$deptCode} / {$itemName}");
        }
        if ($li['group_code'] !== 'EQUIPMENT') {
            throw new RuntimeException("รายการงบ {$deptCode} / {$itemName} ไม่ได้อยู่ในหมวดค่าครุภัณฑ์ (EQUIPMENT)");
        }
        $liId = (int) $li['id'];
        $amount = round($qty * $price, 2);
        $key = "{$deptCode} / {$itemName}";
        $sums[$key] = ($sums[$key] ?? 0.0) + $amount;
        $itemInfo[$key] = (float) $li['starting_amount'];

        $exists->execute([$liId, $name]);
        if ((int) $exists->fetchColumn() > 0) {
            $skipped++;
            echo "  = ข้าม (มีอยู่แล้ว) {$key}: {$name}\n";
            continue;
        }
        echo "  + {$key}: {$name} {$qty} {$unit} × " . number_format($price, 2) . ' = ' . number_format($amount, 2) . "\n";
        if (!$dryRun) {
            $insert->execute([$liId, $name, $qty, $unit, $price, $amount, $note]);
            if ($actor !== false) {
                $db->prepare('INSERT INTO audit_logs (actor_id, action, target_table, target_id, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?)')->execute([
                    (int) $actor, 'LINE_ITEM_DETAIL_SAVE', 'line_item_details', (int) $db->lastInsertId(), null,
                    json_encode(['line_item_id' => $liId, 'name' => $name, 'quantity' => $qty, 'unit' => $unit, 'unit_price' => $price, 'amount' => $amount, 'source' => 'import-fy2570-equipment-details'], JSON_UNESCAPED_UNICODE),
                ]);
            }
        }
        $added++;
    }

    echo "\nตรวจยอดรวมรายละเอียด เทียบกับงบต้นปีของแต่ละรายการงบ:\n";
    $allMatch = true;
    foreach ($sums as $key => $sum) {
        $ok = abs($sum - $itemInfo[$key]) < 0.005;
        $allMatch = $allMatch && $ok;
        printf("  %-45s รายละเอียด %12s | งบต้นปี %12s | %s\n", $key, number_format($sum, 2), number_format($itemInfo[$key], 2), $ok ? 'ตรง' : 'ไม่ตรง!');
    }
    if (!$allMatch) {
        throw new RuntimeException('ยอดรวมรายละเอียดไม่ตรงงบต้นปีบางรายการ — ยกเลิกการ import (ยังไม่ได้เขียนอะไร)');
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

echo "\nเสร็จสิ้น — เพิ่ม {$added} รายการ ข้าม {$skipped} รายการ" . ($dryRun ? ' (dry run — ไม่ได้เขียนข้อมูล)' : '') . "\n";
