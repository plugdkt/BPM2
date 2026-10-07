<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/** สร้าง/แก้ไข "รายการงบ" ต่อสาขา+ปีงบ — ดู spec.md ข้อ 5/6.3/7 */

$user = bpm_require_role('ADMIN');

$departmentId = (int) ($_POST['department_id'] ?? 0);
$fiscalYearId = (int) ($_POST['fiscal_year_id'] ?? 0);
$redirectBack = bpm_url('admin/allocations.php?') . http_build_query(array_filter([
    'dept' => $departmentId ?: null,
    'fy'   => $fiscalYearId ?: null,
]));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirectBack);
    exit;
}
if (!bpm_csrf_verify($_POST['csrf_token'] ?? null)) {
    bpm_flash_set('danger', 'คำขอไม่ถูกต้องหรือหมดเวลา');
    header('Location: ' . $redirectBack);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$name = trim((string) ($_POST['name'] ?? ''));
$startingAmountRaw = str_replace(',', '', (string) ($_POST['starting_amount'] ?? '0'));
$groupId = (int) ($_POST['group_id'] ?? 0) ?: null;
$fundSourceId = (int) ($_POST['fund_source_id'] ?? 1) ?: 1; // 1 = UNSPECIFIED (ไม่ระบุแหล่งเงิน)
$requiresTravel = isset($_POST['requires_travel_detail']) ? 1 : 0;
$note = trim((string) ($_POST['note'] ?? '')) ?: null;
$isActive = isset($_POST['is_active']) ? 1 : 0;

$errors = [];
if ($departmentId <= 0 || $fiscalYearId <= 0) {
    $errors[] = 'กรุณาเลือกสาขาและปีงบก่อน';
}
if ($name === '') {
    $errors[] = 'กรุณากรอกชื่อรายการ';
}
if (!is_numeric($startingAmountRaw) || (float) $startingAmountRaw < 0) {
    $errors[] = 'งบต้นปีต้องเป็นตัวเลขไม่ติดลบ';
}

if (!empty($errors)) {
    bpm_flash_set('danger', implode(' / ', $errors));
    header('Location: ' . $redirectBack);
    exit;
}

$db = bpm_db();

$srcStmt = $db->prepare('SELECT COUNT(*) FROM fund_sources WHERE id = ? AND (is_active = 1 OR id = (SELECT fund_source_id FROM budget_line_items WHERE id = ?))');
$srcStmt->execute([$fundSourceId, $id]);
if ((int) $srcStmt->fetchColumn() === 0) {
    bpm_flash_set('danger', 'ไม่พบแหล่งเงินที่เลือก หรือแหล่งเงินนี้ถูกปิดการใช้งานแล้ว');
    header('Location: ' . $redirectBack);
    exit;
}

try {
    if ($id > 0) {
        $before = $db->prepare('SELECT * FROM budget_line_items WHERE id = ?');
        $before->execute([$id]);
        $beforeRow = $before->fetch();

        // โยกย้ายงบข้ามแหล่งเงินไม่ได้ — ถ้ารายการนี้เคยมีคำขอโยกย้าย (รออนุมัติ/อนุมัติแล้ว) การเปลี่ยนแหล่งเงินจะทำให้ข้อมูลเก่าข้ามแหล่งเงินย้อนหลัง จึงห้าม
        if ($beforeRow && (int) $beforeRow['fund_source_id'] !== $fundSourceId) {
            $tr = $db->prepare("SELECT COUNT(*) FROM budget_transfers WHERE (from_line_item_id = ? OR to_line_item_id = ?) AND status IN ('PENDING','APPROVED')");
            $tr->execute([$id, $id]);
            if ((int) $tr->fetchColumn() > 0) {
                bpm_flash_set('danger', 'เปลี่ยนแหล่งเงินไม่ได้ เพราะรายการนี้มีคำขอโยกย้ายงบที่รออนุมัติหรืออนุมัติแล้ว (โยกย้ายข้ามแหล่งเงินไม่ได้) — ให้ลบ/โอนกลับคำขอเหล่านั้นก่อน หรือสร้างรายการใหม่ในแหล่งเงินที่ต้องการแทน');
                header('Location: ' . $redirectBack);
                exit;
            }
        }

        $stmt = $db->prepare(
            'UPDATE budget_line_items SET name = ?, starting_amount = ?, group_id = ?, fund_source_id = ?, requires_travel_detail = ?, note = ?, is_active = ?
             WHERE id = ?'
        );
        $stmt->execute([$name, (float) $startingAmountRaw, $groupId, $fundSourceId, $requiresTravel, $note, $isActive, $id]);

        $oldVals = [];
        $newVals = [];
        if ($beforeRow && (float) $beforeRow['starting_amount'] !== (float) $startingAmountRaw) {
            $oldVals['starting_amount'] = (float) $beforeRow['starting_amount'];
            $newVals['starting_amount'] = (float) $startingAmountRaw;
        }
        if ($beforeRow && (int) $beforeRow['fund_source_id'] !== $fundSourceId) {
            $oldVals['fund_source_id'] = (int) $beforeRow['fund_source_id'];
            $newVals['fund_source_id'] = $fundSourceId;
        }
        if (!empty($newVals)) {
            bpm_audit_log((int) $user['id'], 'LINE_ITEM_UPDATE', 'budget_line_items', $id, $oldVals, $newVals);
        }
    } else {
        $stmt = $db->prepare(
            'INSERT INTO budget_line_items (department_id, fiscal_year_id, group_id, fund_source_id, name, starting_amount, requires_travel_detail, note, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([$departmentId, $fiscalYearId, $groupId, $fundSourceId, $name, (float) $startingAmountRaw, $requiresTravel, $note]);
    }
} catch (PDOException $e) {
    bpm_flash_set('danger', str_contains($e->getMessage(), 'Duplicate') ? 'สาขานี้มีรายการชื่อนี้ในแหล่งเงินนี้ในปีงบนี้อยู่แล้ว' : 'บันทึกไม่สำเร็จ กรุณาลองใหม่');
    header('Location: ' . $redirectBack);
    exit;
}

bpm_flash_set('success', 'บันทึกรายการงบเรียบร้อยแล้ว');
header('Location: ' . $redirectBack);
exit;

