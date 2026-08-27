<?php

declare(strict_types=1);

/**
 * One-off DEMO data import: อ่าน BPM_report69.xlsx (รายงานจริงปีงบ 2569 ที่ลูกค้าส่งมา)
 * แล้วสร้าง fiscal_year 2569 + budget_line_items + transactions + travel_records ให้ครบ
 * ใช้สำหรับ demo/UAT เท่านั้น (ดู spec.md ข้อ 12.2 — ไม่ import เข้า production)
 *
 * รันครั้งเดียวจาก command line ในเครื่อง dev (Docker) เท่านั้น:
 *   docker exec bpm-web-1 php scripts/import-fy2569-demo.php
 *
 * Idempotent: ลบ transactions/travel_records เก่าของปีงบ 2569 ก่อนเสมอ แล้ว insert ใหม่
 * (budget_line_items ใช้ ON DUPLICATE KEY UPDATE — รันซ้ำได้ปลอดภัย)
 */

require_once __DIR__ . '/../src/lib/config.php';
require_once __DIR__ . '/../src/lib/db.php';
require_once __DIR__ . '/../src/lib/fiscal_year.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

const FY_BE = 2569;
const XLSX_PATH = __DIR__ . '/../BPM_report69.xlsx';

// สาขา => [ชื่อ sheet สรุป, ชื่อ sheet เดินทาง หรือ null ถ้าไม่มี]
const DEPT_SHEETS = [
    'MICRO'     => ['สรุปงบประมาณ จุล 69', 'เดินทางจุล'],
    'BIOCHEM'   => ['สรุปงบประมาณ ชีวเคมี 69', 'ค่าเดินทางชีวเคมี'],
    'NUTRITION' => [' สรุปงบประมาณ โภชนาการ 69', 'ค่าเดินทางโภชนาการ'],
    'ANATOMY'   => ['สรุปงบประมาณ กายวิภาค 69', 'เดินทางกายวิภาค'],
    'PHYSIO'    => ['สรุปงบประมาณ สรีรวิทยา 69', 'เดินทางสรีระ'],
    'OFFICE'    => ['สรุปงบประมาณสำนักงานคณะฯ 69', null],
];

const GROUP_KEYWORDS = [
    'COMPENSATION' => ['ค่าตอบแทน', 'ค่าจ้าง', 'ประจำตำแหน่ง', 'ประกันสังคม'],
    'MATERIALS'    => ['ค่าวัสดุ'],
    'EQUIPMENT'    => ['ค่าครุภัณฑ์'],
    'PROJECT'      => ['โครงการ'],
    'OPERATING'    => ['ค่าใช้สอย', 'ค่าจัดประชุม', 'ค่าซ่อมแซม', 'ค่าเบี้ยเลี้ยง', 'ค่าปฏิบัติงานนอกเวลา', 'ไปรษณีย์', 'โทรศัพท์', 'ค่าใช้จ่ายในการจัดประชุม'],
];

function map_group(string $name): ?string
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

function is_travel_line(string $name): bool
{
    return str_contains($name, 'เบี้ยเลี้ยง');
}

/** header row ที่คอลัมน์แรก (A) = 'ลำดับ' */
function find_header_row($sheet): int
{
    for ($r = 1; $r <= 3; $r++) {
        if (trim((string) $sheet->getCell([1, $r])->getValue()) === 'ลำดับ') {
            return $r;
        }
    }
    throw new RuntimeException('header row not found in sheet: ' . $sheet->getTitle());
}

/** แปลง D/M/YYYY (พ.ศ.) เป็น Y-m-d (ค.ศ.) — คืน null ถ้า parse ไม่ได้ */
function parse_thai_doc_date(?string $raw): ?string
{
    if ($raw === null || trim($raw) === '') {
        return null;
    }
    if (!preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim($raw), $m)) {
        return null;
    }
    [, $d, $mo, $yBe] = $m;
    $yAd = (int) $yBe - BPM_BE_OFFSET;
    if ((int) $mo < 1 || (int) $mo > 12 || (int) $d < 1 || (int) $d > 31) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $yAd, (int) $mo, (int) $d);
}

// ============================================================================

// กันรันผิดเครื่อง — สคริปต์นี้สำหรับ demo/UAT ใน Docker dev เท่านั้น (ดู spec.md ข้อ 12.2 — ตัดสินใจแล้วว่าไม่ import ปี 69 เข้า production)
// production config ต้องตั้ง app.debug = false เสมอ (ดู spec.md ข้อ 9) ใช้ค่านี้เป็นเงื่อนไขกันรันซ้ำ
if (empty(bpm_config()['app']['debug'])) {
    fwrite(STDERR, "ERROR: refusing to run — app.debug is not enabled. This script is for local Docker dev only, never production.\n");
    exit(1);
}

$db = bpm_db();

echo "== 1) resolve fiscal year " . FY_BE . " ==\n";
$stmt = $db->prepare('SELECT * FROM fiscal_years WHERE year_be = ?');
$stmt->execute([FY_BE]);
$fy = $stmt->fetch();
if (!$fy) {
    $range = bpm_fiscal_year_range(FY_BE);
    $db->prepare("INSERT INTO fiscal_years (year_be, start_date, end_date, status) VALUES (?, ?, ?, 'OPEN')")
        ->execute([FY_BE, $range['start_date'], $range['end_date']]);
    $fyId = (int) $db->lastInsertId();
    echo "  created fiscal_year id={$fyId} ({$range['start_date']} - {$range['end_date']})\n";
} else {
    $fyId = (int) $fy['id'];
    echo "  exists fiscal_year id={$fyId}\n";
}

echo "== 2) resolve admin user for created_by ==\n";
$admin = $db->query("SELECT id FROM users WHERE role = 'ADMIN' AND is_active = 1 ORDER BY id LIMIT 1")->fetch();
if (!$admin) {
    fwrite(STDERR, "ERROR: no ADMIN user found — login once via dev bypass and set role=ADMIN first.\n");
    exit(1);
}
$adminId = (int) $admin['id'];
echo "  created_by user id={$adminId}\n";

echo "== 3) resolve department/group id maps ==\n";
$deptIds = [];
foreach ($db->query('SELECT id, code FROM departments') as $row) {
    $deptIds[$row['code']] = (int) $row['id'];
}
$groupIds = [];
foreach ($db->query('SELECT id, code FROM budget_groups') as $row) {
    $groupIds[$row['code']] = (int) $row['id'];
}

echo "== 4) clean previous import (idempotent re-run) ==\n";
$lineItemIds = $db->prepare('SELECT id FROM budget_line_items WHERE fiscal_year_id = ?');
$lineItemIds->execute([$fyId]);
$existingLineItemIds = array_column($lineItemIds->fetchAll(), 'id');
if (!empty($existingLineItemIds)) {
    $in = implode(',', array_fill(0, count($existingLineItemIds), '?'));
    $db->prepare("DELETE tr FROM travel_records tr JOIN transactions t ON t.id = tr.transaction_id WHERE t.line_item_id IN ({$in})")
        ->execute($existingLineItemIds);
    $db->prepare("DELETE FROM transactions WHERE line_item_id IN ({$in})")->execute($existingLineItemIds);
    echo '  cleared transactions/travel_records for ' . count($existingLineItemIds) . " existing line items\n";
}

$spreadsheet = IOFactory::load(XLSX_PATH);

$insertLineItem = $db->prepare(
    'INSERT INTO budget_line_items (department_id, fiscal_year_id, group_id, name, starting_amount, requires_travel_detail, is_active)
     VALUES (?, ?, ?, ?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE group_id = VALUES(group_id), starting_amount = VALUES(starting_amount), requires_travel_detail = VALUES(requires_travel_detail)'
);
$insertTxn = $db->prepare(
    'INSERT INTO transactions (line_item_id, type, amount, description, reference_no, txn_date, created_by)
     VALUES (?, "EXPENSE", ?, ?, ?, ?, ?)'
);
$insertTravel = $db->prepare(
    'INSERT INTO travel_records (transaction_id, instructor_name, purpose, installment_no, ref_doc_no)
     VALUES (?, ?, ?, ?, ?)'
);

$totalLineItems = 0;
$totalTxns = 0;
$totalTravel = 0;

foreach (DEPT_SHEETS as $deptCode => [$summarySheetName, $travelSheetName]) {
    echo "== dept {$deptCode} ==\n";
    if (!isset($deptIds[$deptCode])) {
        echo "  SKIP: department code {$deptCode} not found in DB\n";
        continue;
    }
    $deptId = $deptIds[$deptCode];

    $sheet = $spreadsheet->getSheetByName($summarySheetName);
    if (!$sheet) {
        echo "  SKIP: sheet '{$summarySheetName}' not found\n";
        continue;
    }

    $headerRow = find_header_row($sheet);
    $highestCol = Coordinate::columnIndexFromString($sheet->getHighestColumn());

    $nameCol = $startCol = $outCol = $inCol = $totalCol = null;
    $monthCols = []; // col => ['month' => int, 'yearAd' => int]
    $fyRange = bpm_fiscal_year_range(FY_BE);
    $adEndYear = (int) substr($fyRange['end_date'], 0, 4);
    $sawTotalAfterTransfer = false;

    for ($c = 1; $c <= $highestCol; $c++) {
        $cell = $sheet->getCell([$c, $headerRow]);
        $val = $cell->getValue();

        if (is_numeric($val) && ExcelDate::isDateTime($cell)) {
            $m = (int) ExcelDate::excelToDateTimeObject($val)->format('n');
            $yearAd = $m >= 10 ? $adEndYear - 1 : $adEndYear;
            $monthCols[$c] = ['month' => $m, 'yearAd' => $yearAd];
            continue;
        }

        $text = trim((string) $val);
        if ($text === 'รายการ') {
            $nameCol = $c;
        } elseif ($text === 'โอนลด') {
            $outCol = $c;
        } elseif ($text === 'โอนเพิ่ม') {
            $inCol = $c;
        } elseif (($text === 'งบประมาณรวม' || $text === 'งบประมาณ') && !$sawTotalAfterTransfer && $outCol !== null) {
            $totalCol = $c;
            $sawTotalAfterTransfer = true;
        } elseif ($nameCol !== null && $startCol === null && $text === '') {
            // คอลัมน์ว่าง header แต่ position ถัดจาก 'รายการ' ตรงๆ = คอลัมน์งบต้นปีที่ตั้งชื่อเป็นชื่อสาขา (มักไม่ว่าง แต่กันไว้)
        } elseif ($nameCol !== null && $startCol === null && $outCol === null && $c === $nameCol + 1) {
            $startCol = $c; // คอลัมน์ถัดจาก 'รายการ' เสมอคืองบต้นปี (ชื่อคอลัมน์ = ชื่อสาขา ไม่ตรง keyword ตายตัว)
        }
    }

    if ($nameCol === null || $startCol === null) {
        echo "  SKIP: could not locate รายการ/starting-amount columns\n";
        continue;
    }

    $travelLineItemId = null;

    for ($r = $headerRow + 1; $r <= $sheet->getHighestRow(); $r++) {
        $name = trim((string) $sheet->getCell([$nameCol, $r])->getValue());
        if ($name === '' || str_starts_with($name, 'รวม')) {
            continue;
        }

        $startAmt = (float) ($sheet->getCell([$startCol, $r])->getCalculatedValue() ?: 0);
        $outAmt   = $outCol   ? (float) ($sheet->getCell([$outCol, $r])->getCalculatedValue() ?: 0) : 0;
        $inAmt    = $inCol    ? (float) ($sheet->getCell([$inCol, $r])->getCalculatedValue() ?: 0) : 0;
        $totalAmt = $totalCol ? (float) ($sheet->getCell([$totalCol, $r])->getCalculatedValue() ?: 0) : ($startAmt - $outAmt + $inAmt);

        $requiresTravel = is_travel_line($name);
        $groupCode = map_group($name);
        $groupId = $groupIds[$groupCode] ?? null;

        $insertLineItem->execute([$deptId, $fyId, $groupId, mb_substr($name, 0, 255), $totalAmt, $requiresTravel ? 1 : 0]);
        $lineItemId = (int) $db->lastInsertId();
        if ($lineItemId === 0) {
            // ON DUPLICATE KEY UPDATE ไม่คืน lastInsertId ใหม่เสมอไปใน MariaDB บางเวอร์ชัน — query ซ้ำเพื่อความชัวร์
            $find = $db->prepare('SELECT id FROM budget_line_items WHERE department_id = ? AND fiscal_year_id = ? AND name = ?');
            $find->execute([$deptId, $fyId, mb_substr($name, 0, 255)]);
            $lineItemId = (int) $find->fetchColumn();
        }
        $totalLineItems++;

        if ($requiresTravel) {
            $travelLineItemId = $lineItemId;
            if ($travelSheetName !== null) {
                continue; // มี sheet เดินทางแยก — รายจ่ายมาจากตรงนั้นแทน กันนับซ้ำ
            }
            // ไม่มี sheet เดินทางแยก (เช่น OFFICE) — ใช้ตัวเลขรายเดือนจาก summary sheet ตามปกติ ไม่ข้าม
        }

        foreach ($monthCols as $c => $info) {
            $amt = (float) ($sheet->getCell([$c, $r])->getCalculatedValue() ?: 0);
            if ($amt <= 0) {
                continue;
            }
            $txnDate = sprintf('%04d-%02d-15', $info['yearAd'], $info['month']);
            $thaiMonth = BPM_THAI_MONTHS_SHORT[$info['month']];
            $insertTxn->execute([
                $lineItemId,
                $amt,
                mb_substr("รายจ่ายเดือน {$thaiMonth} " . bpm_ad_year_to_be($info['yearAd']) . ' (นำเข้าจากรายงานปี 69)', 0, 500),
                null,
                $txnDate,
                $adminId,
            ]);
            $totalTxns++;
        }
    }

    // --- travel sheet ---
    if ($travelSheetName !== null && $travelLineItemId !== null) {
        $tSheet = $spreadsheet->getSheetByName($travelSheetName);
        if (!$tSheet) {
            echo "  WARN: travel sheet '{$travelSheetName}' not found, skipping travel import\n";
        } else {
            $tHeaderRow = find_header_row($tSheet);

            // คอลัมน์: 1=ลำดับ 2=ชื่ออาจารย์ 3=รายการเดินทาง 4-6=งวด1-3 7=รวมงบที่ใช้ 8=งบคงเหลือ 9=ref 10=วันที่
            for ($r = $tHeaderRow + 1; $r <= $tSheet->getHighestRow(); $r++) {
                $seq = $tSheet->getCell([1, $r])->getValue();
                if (!is_numeric($seq)) {
                    continue; // ข้ามแถว label/งบต้นปี ที่ไม่ใช่ลำดับตัวเลข
                }
                $instructor = trim((string) $tSheet->getCell([2, $r])->getValue());
                $purpose = trim((string) $tSheet->getCell([3, $r])->getValue());
                if ($instructor === '') {
                    continue;
                }

                $inst1 = $tSheet->getCell([4, $r])->getCalculatedValue();
                $inst2 = $tSheet->getCell([5, $r])->getCalculatedValue();
                $inst3 = $tSheet->getCell([6, $r])->getCalculatedValue();
                $installments = array_filter([$inst1, $inst2, $inst3], static fn ($v) => is_numeric($v) && (float) $v > 0);
                $installmentNo = max(1, count($installments));

                $totalUsedRaw = $tSheet->getCell([7, $r])->getCalculatedValue();
                $amount = is_numeric($totalUsedRaw) ? (float) $totalUsedRaw : array_sum($installments);
                if ($amount <= 0) {
                    continue;
                }

                $refDoc = trim((string) $tSheet->getCell([9, $r])->getValue()) ?: null;
                $dateRaw = $tSheet->getCell([10, $r])->getValue();
                $dateStr = $dateRaw instanceof \DateTimeInterface
                    ? $dateRaw->format('Y-m-d')
                    : parse_thai_doc_date($dateRaw !== null ? (string) $dateRaw : null);
                if ($dateStr === null || $dateStr < $fyRange['start_date'] || $dateStr > $fyRange['end_date']) {
                    $dateStr = $fyRange['start_date']; // parse ไม่ได้/นอกช่วงปีงบ — fallback วันแรกของปีงบ
                }

                $insertTxn->execute([
                    $travelLineItemId,
                    $amount,
                    mb_substr($purpose !== '' ? $purpose : 'ค่าเดินทาง/พัฒนาตนเอง (นำเข้าจากรายงานปี 69)', 0, 500),
                    $refDoc !== null ? mb_substr($refDoc, 0, 100) : null,
                    $dateStr,
                    $adminId,
                ]);
                $txnId = (int) $db->lastInsertId();
                $totalTxns++;

                $insertTravel->execute([
                    $txnId,
                    mb_substr($instructor, 0, 150),
                    mb_substr($purpose !== '' ? $purpose : 'ไม่ระบุ', 0, 1000),
                    $installmentNo,
                    $refDoc !== null ? mb_substr($refDoc, 0, 150) : null,
                ]);
                $totalTravel++;
            }
        }
    }
}

echo "\n== DONE ==\n";
echo "fiscal_year_id: {$fyId}\n";
echo "line items: {$totalLineItems}\n";
echo "transactions: {$totalTxns}\n";
echo "travel records: {$totalTravel}\n";
echo "\nดูผลได้ที่ /index.php?fy={$fyId} หรือเลือกปีงบ พ.ศ. 2569 จาก dropdown บนแอป\n";
