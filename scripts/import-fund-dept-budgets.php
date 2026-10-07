<?php

declare(strict_types=1);

/**
 * ตั้งค่าแหล่งเงินของปีงบ 2570: ตอนนี้มีแหล่งเงินเดียวคือ "งบรายได้" (ยืนยันจากผู้ใช้ 7 ต.ค. 2569)
 *   - ชีท "งบประมาณที่ได้รับปี 70" ของงานแผนแยกคอลัมน์ "งบประมาณรายได้" กับ "งบประมาณผลิตแพทย์" แต่อยู่ภายใต้หัวข้อ
 *     "งบประมาณรายได้ค่าธรรมเนียมนิสิต" จึงนับทั้งหมด (รวมเงินเพิ่ม AAT49 ~412,000) เป็นงบรายได้ก้อนเดียว
 *   - วงเงินรายสาขา = ยอดรวมต่อสาขาในชีท "รวมงบค่าใช้จ่ายวิทย์แพทย์70" (รวม 8,155,400 ตรงกับระบบ budget ของมหาวิทยาลัย)
 *
 * สคริปต์นี้: สร้างแหล่งเงิน REVENUE ถ้ายังไม่มี, ตั้ง fund_source_budgets + fund_dept_budgets ของปีงบ 2570 (upsert)
 * และถ้าใส่ --assign-items จะย้ายรายการงบของปีงบ 2570 ที่ยังเป็น "ไม่ระบุแหล่งเงิน" ไปเป็นงบรายได้ทั้งหมด
 *
 *   php scripts/import-fund-dept-budgets.php --dry-run                 # ดูผลก่อน ไม่เขียนอะไร
 *   php scripts/import-fund-dept-budgets.php --assign-items --dry-run
 *   php scripts/import-fund-dept-budgets.php --assign-items            # เขียนจริง
 *
 * ต้องรัน add-fund-sources.php และ add-fund-budgets.php ก่อน ปลอดภัยรันซ้ำได้ — production ต้อง backup ก่อน
 */

require_once __DIR__ . '/../src/lib/config.php';
require_once __DIR__ . '/../src/lib/db.php';

$dryRun      = in_array('--dry-run', $argv ?? [], true);
$assignItems = in_array('--assign-items', $argv ?? [], true);
$yearBe      = 2570;

$source = [
    'code'  => 'REVENUE',
    'name'  => 'งบรายได้',
    'depts' => ['OFFICE' => 3062210.00, 'MICRO' => 1961720.00, 'BIOCHEM' => 869080.00, 'NUTRITION' => 858720.00, 'ANATOMY' => 754150.00, 'PHYSIO' => 649520.00],
];
$source['total'] = array_sum($source['depts']);

$db = bpm_db();

$fy = $db->prepare('SELECT id, status FROM fiscal_years WHERE year_be = ?');
$fy->execute([$yearBe]);
$fiscalYear = $fy->fetch();
if (!$fiscalYear || $fiscalYear['status'] === 'CLOSED') {
    fwrite(STDERR, "ไม่พบปีงบ {$yearBe} หรือปิดแล้ว\n");
    exit(1);
}
$fyId = (int) $fiscalYear['id'];
$deptIds = $db->query('SELECT code, id FROM departments')->fetchAll(PDO::FETCH_KEY_PAIR);

echo ($dryRun ? '[DRY RUN] ' : '') . "ปีงบ {$yearBe} (fiscal_year_id={$fyId}) แหล่งเงิน {$source['name']} รวม " . number_format($source['total'], 2) . "\n";

if (!$dryRun) {
    $db->beginTransaction();
}
try {
    $row = $db->prepare('SELECT id FROM fund_sources WHERE code = ?');
    $row->execute([$source['code']]);
    $sourceId = $row->fetchColumn();
    if ($sourceId === false) {
        echo "  + สร้างแหล่งเงิน {$source['code']} ({$source['name']})\n";
        if (!$dryRun) {
            $db->prepare('INSERT INTO fund_sources (name, code) VALUES (?, ?)')->execute([$source['name'], $source['code']]);
            $sourceId = $db->lastInsertId();
        } else {
            $sourceId = 0;
        }
    }
    $sourceId = (int) $sourceId;

    if (!$dryRun) {
        $db->prepare(
            'INSERT INTO fund_source_budgets (fiscal_year_id, fund_source_id, amount) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount)'
        )->execute([$fyId, $sourceId, $source['total']]);
    }
    foreach ($source['depts'] as $deptCode => $amount) {
        if (!isset($deptIds[$deptCode])) {
            throw new RuntimeException("ไม่พบสาขา {$deptCode}");
        }
        echo "    - {$deptCode}: " . number_format($amount, 2) . "\n";
        if (!$dryRun) {
            $db->prepare(
                'INSERT INTO fund_dept_budgets (fiscal_year_id, fund_source_id, department_id, amount) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE amount = VALUES(amount)'
            )->execute([$fyId, $sourceId, (int) $deptIds[$deptCode], $amount]);
        }
    }

    $pending = $db->prepare('SELECT COUNT(*) FROM budget_line_items WHERE fiscal_year_id = ? AND fund_source_id = 1');
    $pending->execute([$fyId]);
    $pendingCount = (int) $pending->fetchColumn();
    if ($assignItems) {
        echo "  รายการงบที่ยังเป็น \"ไม่ระบุแหล่งเงิน\" {$pendingCount} รายการ → ย้ายไปเป็น {$source['name']}\n";
        if (!$dryRun && $pendingCount > 0) {
            $db->prepare('UPDATE budget_line_items SET fund_source_id = ? WHERE fiscal_year_id = ? AND fund_source_id = 1')->execute([$sourceId, $fyId]);
            bpm_audit_log_system($db, $fyId, $sourceId, $pendingCount);
        }
    } else {
        echo "  (รายการงบที่ยังเป็น \"ไม่ระบุแหล่งเงิน\" {$pendingCount} รายการ — ใส่ --assign-items เพื่อย้ายเป็น {$source['name']})\n";
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

echo "\nเทียบ วงเงินที่ตั้งให้สาขา vs ผลรวมรายการงบของสาขา:\n";
$items = $db->prepare('SELECT department_id, SUM(starting_amount) FROM budget_line_items WHERE fiscal_year_id = ? AND is_active = 1 GROUP BY department_id');
$items->execute([$fyId]);
$itemSum = $items->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($deptIds as $code => $id) {
    $got = $source['depts'][$code] ?? 0.0;
    $alloc = (float) ($itemSum[$id] ?? 0);
    printf("  %-10s วงเงิน %14s | รายการงบ %14s | ส่วนต่าง %s\n", $code, number_format($got, 2), number_format($alloc, 2), abs($alloc - $got) < 0.005 ? '0.00 (ตรง)' : number_format($alloc - $got, 2));
}
echo "\nเสร็จสิ้น" . ($dryRun ? ' (dry run — ไม่ได้เขียนข้อมูล)' : '') . "\n";

/** บันทึกการย้ายรายการงบเป็นแหล่งเงินแบบกลุ่มลง audit_logs (actor = ADMIN คนแรก เพราะรันจาก CLI ไม่มี session) */
function bpm_audit_log_system(PDO $db, int $fyId, int $sourceId, int $count): void
{
    $actor = $db->query("SELECT id FROM users WHERE role = 'ADMIN' ORDER BY id LIMIT 1")->fetchColumn();
    if ($actor === false) {
        return;
    }
    $db->prepare('INSERT INTO audit_logs (actor_id, action, target_table, target_id, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([
           (int) $actor, 'LINE_ITEM_UPDATE', 'budget_line_items', 0,
           json_encode(['fund_source_id' => 1, 'note' => "bulk assign {$count} items (fiscal_year_id={$fyId})"]),
           json_encode(['fund_source_id' => $sourceId]),
       ]);
}
