<?php

declare(strict_types=1);

/**
 * Read-only diagnostic: เทียบ budget_line_items ของปีงบ 2570 ในระบบ กับตัวเลขจริงใน BPM_70.xlsx
 * ไม่แก้ไขข้อมูลใดๆ ทั้งสิ้น — แค่รายงานว่าอะไรตรง/ไม่ตรง/เกินมา/ขาดไป
 *
 * รัน:
 *   docker exec bpm-web-1 php scripts/check-fy2570-diff.php   (dev)
 *   php scripts/check-fy2570-diff.php                          (production)
 */

require_once __DIR__ . '/../src/lib/config.php';
require_once __DIR__ . '/../src/lib/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

const FY_BE = 2570;
const XLSX_PATH = __DIR__ . '/../BPM_70.xlsx';
const SHEET_NAME = 'รวมงบค่าใช้จ่ายวิทย์แพทย์70';

const DEPT_COLS = [
    3 => 'OFFICE', 4 => 'MICRO', 5 => 'BIOCHEM', 6 => 'NUTRITION', 7 => 'ANATOMY', 8 => 'PHYSIO',
];

// --- 1) อ่านค่า "ที่ควรจะเป็น" จาก Excel ---
$spreadsheet = IOFactory::load(XLSX_PATH);
$sheet = $spreadsheet->getSheetByName(SHEET_NAME);
if (!$sheet) {
    fwrite(STDERR, 'ERROR: sheet not found' . "\n");
    exit(1);
}

$expected = []; // "DEPT|name" => amount
$highestRow = $sheet->getHighestRow();
for ($r = 2; $r <= $highestRow; $r++) {
    $seq = $sheet->getCell([1, $r])->getValue();
    $name = trim((string) $sheet->getCell([2, $r])->getValue());
    if ($name === '' || !is_numeric($seq)) {
        continue;
    }
    foreach (DEPT_COLS as $col => $deptCode) {
        $amt = $sheet->getCell([$col, $r])->getCalculatedValue();
        if (!is_numeric($amt) || (float) $amt <= 0) {
            continue;
        }
        $expected["{$deptCode}|{$name}"] = (float) $amt;
    }
}
$expectedTotal = array_sum($expected);

// --- 2) อ่านค่า "ที่มีอยู่จริง" ในระบบ ---
$db = bpm_db();
$fy = $db->query('SELECT id FROM fiscal_years WHERE year_be = ' . FY_BE)->fetch();
if (!$fy) {
    fwrite(STDERR, 'ERROR: fiscal_year ' . FY_BE . " not found\n");
    exit(1);
}
$rows = $db->query(
    "SELECT d.code AS dept, li.name, li.starting_amount
     FROM budget_line_items li JOIN departments d ON d.id = li.department_id
     WHERE li.fiscal_year_id = {$fy['id']}"
)->fetchAll();

$actual = [];
foreach ($rows as $row) {
    $actual["{$row['dept']}|{$row['name']}"] = (float) $row['starting_amount'];
}
$actualTotal = array_sum($actual);

// --- 3) เทียบ ---
echo "=== สรุปยอดรวม ===\n";
echo 'Excel:  ' . number_format($expectedTotal, 2) . "\n";
echo 'ระบบ:   ' . number_format($actualTotal, 2) . "\n";
echo 'ต่าง:   ' . number_format($actualTotal - $expectedTotal, 2) . "\n\n";

$extraInSystem = array_diff_key($actual, $expected);
$missingInSystem = array_diff_key($expected, $actual);
$mismatched = [];
foreach ($expected as $key => $amt) {
    if (isset($actual[$key]) && abs($actual[$key] - $amt) > 0.001) {
        $mismatched[$key] = [$amt, $actual[$key]];
    }
}

if (!empty($extraInSystem)) {
    echo "=== รายการที่มีในระบบ แต่ไม่มีในไฟล์ Excel (ตัวการที่ทำให้ยอดเกิน) ===\n";
    foreach ($extraInSystem as $key => $amt) {
        [$dept, $name] = explode('|', $key, 2);
        echo "  [{$dept}] {$name}: " . number_format($amt, 2) . "\n";
    }
    echo "\n";
}

if (!empty($missingInSystem)) {
    echo "=== รายการที่มีในไฟล์ Excel แต่ยังไม่มีในระบบ ===\n";
    foreach ($missingInSystem as $key => $amt) {
        [$dept, $name] = explode('|', $key, 2);
        echo "  [{$dept}] {$name}: " . number_format($amt, 2) . "\n";
    }
    echo "\n";
}

if (!empty($mismatched)) {
    echo "=== รายการที่ชื่อตรงกัน แต่ยอดไม่ตรง ===\n";
    foreach ($mismatched as $key => [$exp, $act]) {
        [$dept, $name] = explode('|', $key, 2);
        echo "  [{$dept}] {$name}: Excel=" . number_format($exp, 2) . " ระบบ=" . number_format($act, 2) . "\n";
    }
    echo "\n";
}

if (empty($extraInSystem) && empty($missingInSystem) && empty($mismatched)) {
    echo "ตรงกันทุกรายการ ไม่พบความแตกต่าง\n";
}
