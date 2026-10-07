<?php

declare(strict_types=1);

/**
 * Import แผนงบตั้งต้นปีงบ 2570 จริง จาก BPM_70.xlsx sheet "รวมงบค่าใช้จ่ายวิทย์แพทย์70"
 * (แถว=รายการ, คอลัมน์=สาขา) → budget_line_items.starting_amount ของปีงบ 2570 ตรงๆ (ดู spec.md ข้อ 12.2)
 *
 * ใช้ได้ทั้ง Docker dev และ production (fiscal_year 2570 ต้องมีอยู่แล้วในระบบทั้งคู่)
 * รันจาก command line:
 *   docker exec bpm-web-1 php scripts/import-fy2570-plan.php   (dev)
 *   php scripts/import-fy2570-plan.php                          (production, ต้องเข้าถึงเครื่อง server ก่อน)
 *
 * Idempotent: budget_line_items ใช้ ON DUPLICATE KEY UPDATE (match ด้วย department_id+fiscal_year_id+name)
 * ไม่แตะ transactions/travel_records ใดๆ — import แค่ยอดตั้งต้น ไม่แตะรายการที่บันทึกไปแล้ว
 */

require_once __DIR__ . '/../src/lib/config.php';
require_once __DIR__ . '/../src/lib/db.php';
require_once __DIR__ . '/../src/lib/fiscal_year.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

const FY_BE = 2570;
const XLSX_PATH = __DIR__ . '/../BPM_70.xlsx';
const SHEET_NAME = 'รวมงบค่าใช้จ่ายวิทย์แพทย์70';

// คอลัมน์ในชีท (index เริ่มที่ 1) => รหัสสาขา
const DEPT_COLS = [
    3 => 'OFFICE',    // คณะฯ
    4 => 'MICRO',     // จุลชีววิทยา
    5 => 'BIOCHEM',   // ชีวเคมี
    6 => 'NUTRITION', // โภชนาการ
    7 => 'ANATOMY',   // กายวิภาคศาสตร์
    8 => 'PHYSIO',    // สรีรวิทยา
];

// จัดหมวดตามหนังสือแจ้งงบประมาณของกองแผนงาน (อว 7310/1946, 18 ก.ย. 2569) — ต้องตรงกับ scripts/regroup-fy2570-budget-groups.php
// (ไม่งั้นรัน import ซ้ำจะย้ายรายการกลับเป็นหมวดเดิม เพราะ ON DUPLICATE KEY UPDATE ตั้ง group_id ใหม่ทุกครั้ง)
// ลำดับสำคัญ: เช็คจากบนลงล่าง ตัวแรกที่ตรงชนะ
const GROUP_KEYWORDS = [
    'PERSONNEL'    => ['ค่าจ้างลูกจ้าง'],
    'UTILITIES'    => ['ไปรษณีย์', 'โทรศัพท์'],
    'COMPENSATION' => ['ค่าตอบแทน', 'ประจำตำแหน่ง', 'ค่าปฏิบัติงานนอกเวลา'],
    'MATERIALS'    => ['ค่าวัสดุ', 'วัสดุ'],
    'EQUIPMENT'    => ['ครุภัณฑ์'],
    'PROJECT'      => ['โครงการ', 'แลกเปลี่ยนเรียนรู้'],
    'OPERATING'    => ['ค่าใช้สอย', 'ค่าจัดประชุม', 'ค่าซ่อมแซม', 'ค่าเบี้ยเลี้ยง', 'ประกันสังคม', 'ค่าใช้จ่ายในการจัดประชุม', 'ค่าเช่า', 'ค่ารถตู้'],
];

function map_group(string $name): string
{
    foreach (GROUP_KEYWORDS as $code => $keywords) {
        foreach ($keywords as $kw) {
            if (str_contains($name, $kw)) {
                return $code;
            }
        }
    }
    return 'OTHER';
}

// ชื่อในไฟล์ Excel ของงานแผนที่ไม่ตรงกับหนังสือแจ้งงบ/ระบบมหาวิทยาลัย — ใช้ชื่อตามหนังสือ (ขึ้นต้นด้วยคำเหล่านี้ => ชื่อใหม่)
// ต้องตรงกับ scripts/rename-fy2570-line-items.php (ไม่งั้นรัน import ซ้ำจะสร้างรายการชื่อเดิมกลับมาซ้ำ)
const NAME_FIXES = [
    'โครงการพัฒนาศักยภาพบุคลากรสายวิชาการ' => 'โครงการสนับสนุนงานวิจัยแนวหน้า และนวัตกรรมเชิงพาณิชย์',
];

function fix_name(string $name): string
{
    foreach (NAME_FIXES as $prefix => $fixed) {
        if (str_starts_with($name, $prefix)) {
            return $fixed;
        }
    }
    return $name;
}

function is_travel_line(string $name): bool
{
    return str_contains($name, 'เบี้ยเลี้ยง');
}

// ============================================================================

$db = bpm_db();

echo '== resolve fiscal year ' . FY_BE . " ==\n";
$stmt = $db->prepare('SELECT * FROM fiscal_years WHERE year_be = ?');
$stmt->execute([FY_BE]);
$fy = $stmt->fetch();
if (!$fy) {
    fwrite(STDERR, 'ERROR: fiscal_year ' . FY_BE . " not found — สร้างที่ admin/fiscal-years.php ก่อน\n");
    exit(1);
}
$fyId = (int) $fy['id'];
echo "  fiscal_year id={$fyId}\n";

echo "== resolve department/group id maps ==\n";
$deptIds = [];
foreach ($db->query('SELECT id, code FROM departments') as $row) {
    $deptIds[$row['code']] = (int) $row['id'];
}
$groupIds = [];
foreach ($db->query('SELECT id, code FROM budget_groups') as $row) {
    $groupIds[$row['code']] = (int) $row['id'];
}
foreach (DEPT_COLS as $code) {
    if (!isset($deptIds[$code])) {
        fwrite(STDERR, "ERROR: department code {$code} not found in DB\n");
        exit(1);
    }
}

$spreadsheet = IOFactory::load(XLSX_PATH);
$sheet = $spreadsheet->getSheetByName(SHEET_NAME);
if (!$sheet) {
    fwrite(STDERR, 'ERROR: sheet "' . SHEET_NAME . "\" not found in " . XLSX_PATH . "\n");
    exit(1);
}

$insertLineItem = $db->prepare(
    'INSERT INTO budget_line_items (department_id, fiscal_year_id, group_id, name, starting_amount, requires_travel_detail, is_active)
     VALUES (?, ?, ?, ?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE group_id = VALUES(group_id), starting_amount = VALUES(starting_amount), requires_travel_detail = VALUES(requires_travel_detail)'
);

$byDept = [];
$totalRows = 0;
$highestRow = $sheet->getHighestRow();

for ($r = 2; $r <= $highestRow; $r++) {
    $seq = $sheet->getCell([1, $r])->getValue();
    $name = fix_name(trim((string) $sheet->getCell([2, $r])->getValue()));

    if ($name === '' || !is_numeric($seq)) {
        continue; // ข้ามแถวว่าง/แถวรวม ("รวมงบประมาณ" ที่ col A ไม่ใช่ตัวเลข)
    }

    $requiresTravel = is_travel_line($name);
    $groupCode = map_group($name);
    $groupId = $groupIds[$groupCode] ?? null;

    foreach (DEPT_COLS as $col => $deptCode) {
        $amt = $sheet->getCell([$col, $r])->getCalculatedValue();
        if (!is_numeric($amt) || (float) $amt <= 0) {
            continue;
        }

        $deptId = $deptIds[$deptCode];
        $insertLineItem->execute([$deptId, $fyId, $groupId, mb_substr($name, 0, 255), (float) $amt, $requiresTravel ? 1 : 0]);
        $byDept[$deptCode] = ($byDept[$deptCode] ?? 0) + 1;
        $totalRows++;
    }
}

echo "\n== DONE ==\n";
foreach ($byDept as $code => $count) {
    echo "  {$code}: {$count} line items\n";
}
echo "total: {$totalRows} line items upserted\n";
echo "ดูผลได้ที่ /admin/allocations.php (เลือกปีงบ พ.ศ. 2570)\n";
