<?php

declare(strict_types=1);

/**
 * จัดกลุ่มหมวดงบปีงบ 2570 ให้ตรงกับหนังสือแจ้งงบประมาณของกองแผนงาน (อว 7310/1946 ลงวันที่ 18 ก.ย. 2569 — "หมวดงบประมาณ 70.pdf")
 * เดิมรายการตั้งหมวดตามคำสำคัญตอน import (ยอดรายการตรงทุกบรรทัด แต่ยอดรวมต่อหมวดต่างจากหนังสือ):
 *   - เพิ่มกลุ่มหมวด "ค่าจ้างบุคลากร" (PERSONNEL) และ "ค่าสาธารณูปโภค" (UTILITIES) — หลังรัน จัดการต่อได้ที่เมนู "กลุ่มหมวดงบ"
 *   - ค่าจ้างลูกจ้างรายเดือน/รายวัน           → ค่าจ้างบุคลากร   (609,600)
 *   - ค่าปฏิบัติงานนอกเวลาทำการ (OT)           → ค่าตอบแทน        (หนังสือนับเป็นค่าตอบแทน)
 *   - ประกันสังคม                              → ค่าใช้สอย        (หนังสือ: ค่าเบี้ยประกันสังคม อยู่ในค่าใช้สอย)
 *   - โทรศัพท์, ไปรษณีย์                        → ค่าสาธารณูปโภค   (30,000)
 *   - โครงการแลกเปลี่ยนเรียนรู้ (KM) สรีรวิทยา  → โครงการ          (หนังสือนับเป็นโครงการ)
 * เป็นแค่ป้ายกำกับ (budget_line_items.group_id) — ไม่แตะยอดงบ ยอดโอน หรือรายการเบิกจ่ายใดๆ
 *
 * ก่อนเขียนจริงสคริปต์เทียบยอดรวมต่อหมวดหลังย้ายกับหนังสือ ถ้าไม่ตรงจะไม่เขียนอะไร (ข้ามได้ด้วย --ignore-totals)
 * บันทึก audit_logs (LINE_ITEM_UPDATE) ทุกรายการที่ย้าย
 *
 *   php scripts/regroup-fy2570-budget-groups.php --dry-run   # ดูผลก่อน ไม่เขียนอะไร
 *   php scripts/regroup-fy2570-budget-groups.php             # เขียนจริง
 *
 * ปลอดภัยรันซ้ำได้ (ข้ามรายการที่อยู่ถูกหมวดแล้ว) — production ต้อง backup ก่อน
 */

require_once __DIR__ . '/../src/lib/config.php';
require_once __DIR__ . '/../src/lib/db.php';

$dryRun       = in_array('--dry-run', $argv ?? [], true);
$ignoreTotals = in_array('--ignore-totals', $argv ?? [], true);
$yearBe       = 2570;

// กลุ่มที่ต้องมี (ถ้ายังไม่มีจะสร้าง) — [code, name]
$newGroups = [
    ['PERSONNEL', 'ค่าจ้างบุคลากร'],
    ['UTILITIES', 'ค่าสาธารณูปโภค'],
];

// ชื่อรายการงบ (ตรงตัวทั้งชื่อ ทุกสาขา) => รหัสกลุ่มปลายทาง
$moves = [
    'ค่าจ้างลูกจ้างรายเดือน'                       => 'PERSONNEL',
    'ค่าจ้างลูกจ้างรายวัน'                         => 'PERSONNEL',
    'ค่าปฏิบัติงานนอกเวลาทำการ (OT เจ้าหน้าที่)'   => 'COMPENSATION',
    'ประกันสังคม'                                  => 'OPERATING',
    'โทรศัพท์'                                     => 'UTILITIES',
    'ไปรษณีย์'                                     => 'UTILITIES',
    'แลกเปลี่ยนเรียนรู้ สาขาวิชาสรีรวิทยา'         => 'PROJECT',
];

// ยอดรวมต่อกลุ่มตามหนังสือกองแผนงาน (รวมทั้งสิ้น 8,155,400)
$expected = [
    'PERSONNEL'    => 609600.00,
    'COMPENSATION' => 440000.00,
    'OPERATING'    => 2335800.00,
    'UTILITIES'    => 30000.00,
    'MATERIALS'    => 1081000.00,
    'EQUIPMENT'    => 1111000.00,
    'PROJECT'      => 2548000.00,
    'OTHER'        => 0.00,
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

echo ($dryRun ? '[DRY RUN] ' : '') . "จัดกลุ่มหมวดงบปีงบ {$yearBe} (fiscal_year_id={$fyId}) ตามหนังสือกองแผนงาน\n";

$groupIds = $db->query('SELECT code, id FROM budget_groups')->fetchAll(PDO::FETCH_KEY_PAIR);
$groupNames = $db->query('SELECT id, name FROM budget_groups')->fetchAll(PDO::FETCH_KEY_PAIR);

if (!$dryRun) {
    $db->beginTransaction();
}
try {
    echo "\nกลุ่มหมวดงบ:\n";
    foreach ($newGroups as [$code, $name]) {
        if (isset($groupIds[$code])) {
            echo "  = มีอยู่แล้ว {$code} ({$name})\n";
            continue;
        }
        echo "  + สร้างกลุ่ม {$code} ({$name})\n";
        if (!$dryRun) {
            $db->prepare('INSERT INTO budget_groups (name, code, is_active) VALUES (?, ?, 1)')->execute([$name, $code]);
            $groupIds[$code] = (int) $db->lastInsertId();
        } else {
            $groupIds[$code] = 0; // dry-run: ยังไม่มี id จริง ใช้แค่จำลองการคำนวณยอด
        }
    }
    foreach ($moves as $itemName => $code) {
        if (!isset($groupIds[$code])) {
            throw new RuntimeException("ไม่พบกลุ่มหมวด {$code} (ต้องมีกลุ่มนี้ก่อน)");
        }
    }

    $find = $db->prepare('SELECT li.id, li.group_id, li.starting_amount, d.code AS dept_code FROM budget_line_items li JOIN departments d ON d.id = li.department_id WHERE li.fiscal_year_id = ? AND li.name = ? AND li.is_active = 1 ORDER BY d.code');
    $update = $db->prepare('UPDATE budget_line_items SET group_id = ? WHERE id = ?');

    echo "\nรายการงบที่ย้ายหมวด:\n";
    $moved = 0;
    $skipped = 0;
    $override = []; // id รายการ => group_id ปลายทาง (ใช้คำนวณยอดตรวจสอบตอน dry-run ที่ยังไม่ได้เขียนจริง)
    foreach ($moves as $itemName => $code) {
        $find->execute([$fyId, $itemName]);
        $rows = $find->fetchAll();
        if (empty($rows)) {
            throw new RuntimeException("ไม่พบรายการงบ \"{$itemName}\" ในปีงบ {$yearBe}");
        }
        foreach ($rows as $r) {
            $target = $groupIds[$code];
            $override[(int) $r['id']] = $code;
            if ($target !== 0 && (int) $r['group_id'] === $target) { // $target = 0 เฉพาะ dry-run ของกลุ่มที่ยังไม่ถูกสร้าง — ย่อมยังไม่อยู่กลุ่มนั้น
                $skipped++;
                echo "  = ข้าม (อยู่หมวดนี้แล้ว) {$r['dept_code']} / {$itemName} -> {$code}\n";
                continue;
            }
            $from = $groupNames[$r['group_id']] ?? '(ไม่ระบุ)';
            echo sprintf("  ~ %-9s %-45s %12s : %s -> %s\n", $r['dept_code'], $itemName, number_format((float) $r['starting_amount'], 2), $from, $code);
            if (!$dryRun) {
                $update->execute([$target, (int) $r['id']]);
                if ($actor !== false) {
                    bpm_audit_log((int) $actor, 'LINE_ITEM_UPDATE', 'budget_line_items', (int) $r['id'], ['group_id' => $r['group_id'] !== null ? (int) $r['group_id'] : null], ['group_id' => $target, 'source' => 'regroup-fy2570-budget-groups']);
                }
            }
            $moved++;
        }
    }

    // ตรวจยอดรวมต่อกลุ่ม (หลังย้าย) เทียบหนังสือกองแผนงาน — ใน dry-run ใช้ $override จำลองผลลัพธ์
    echo "\nยอดรวมต่อกลุ่มหมวด หลังย้าย เทียบหนังสือกองแผนงาน:\n";
    $codeById = array_flip($groupIds);
    $sums = array_fill_keys(array_keys($expected), 0.0);
    $stmt = $db->prepare('SELECT id, group_id, starting_amount FROM budget_line_items WHERE fiscal_year_id = ? AND is_active = 1');
    $stmt->execute([$fyId]);
    foreach ($stmt->fetchAll() as $r) {
        $code = $override[(int) $r['id']] ?? ($r['group_id'] !== null ? ($codeById[(int) $r['group_id']] ?? '?') : 'OTHER');
        $sums[$code] = ($sums[$code] ?? 0.0) + (float) $r['starting_amount'];
    }
    $allMatch = true;
    foreach ($expected as $code => $exp) {
        $got = $sums[$code] ?? 0.0;
        $ok = abs($got - $exp) < 0.005;
        $allMatch = $allMatch && $ok;
        printf("  %-13s ได้ %14s | หนังสือ %14s | %s\n", $code, number_format($got, 2), number_format($exp, 2), $ok ? 'ตรง' : 'ไม่ตรง!');
    }
    foreach ($sums as $code => $got) {
        if (!isset($expected[$code]) && abs($got) > 0.005) {
            $allMatch = false;
            printf("  %-13s ได้ %14s | (ไม่อยู่ในหนังสือ) | ไม่ตรง!\n", $code, number_format($got, 2));
        }
    }
    if (!$allMatch && !$ignoreTotals) {
        throw new RuntimeException('ยอดรวมต่อกลุ่มไม่ตรงหนังสือ — ยกเลิก (ยังไม่ได้เขียนอะไร) ตรวจข้อมูลก่อน หรือใช้ --ignore-totals ถ้ายืนยันว่าถูกต้อง');
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

echo "\nเสร็จสิ้น — ย้าย {$moved} รายการ ข้าม {$skipped} รายการ" . ($dryRun ? ' (dry run — ไม่ได้เขียนข้อมูล)' : '') . "\n";
