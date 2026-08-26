<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/** สร้าง/แก้ไข "รายการงบ" ต่อสาขา+ปีงบ — ดู spec.md ข้อ 5/6.3/7 */

$user = bpm_require_role('ADMIN');

$departmentId = (int) ($_POST['department_id'] ?? 0);
$fiscalYearId = (int) ($_POST['fiscal_year_id'] ?? 0);
$redirectBack = '/admin/allocations.php?' . http_build_query(array_filter([
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

try {
    if ($id > 0) {
        $before = $db->prepare('SELECT * FROM budget_line_items WHERE id = ?');
        $before->execute([$id]);
        $beforeRow = $before->fetch();

        $stmt = $db->prepare(
            'UPDATE budget_line_items SET name = ?, starting_amount = ?, group_id = ?, requires_travel_detail = ?, note = ?, is_active = ?
             WHERE id = ?'
        );
        $stmt->execute([$name, (float) $startingAmountRaw, $groupId, $requiresTravel, $note, $isActive, $id]);

        if ($beforeRow && (float) $beforeRow['starting_amount'] !== (float) $startingAmountRaw) {
            bpm_audit_log(
                (int) $user['id'],
                'LINE_ITEM_UPDATE',
                'budget_line_items',
                $id,
                ['starting_amount' => (float) $beforeRow['starting_amount']],
                ['starting_amount' => (float) $startingAmountRaw]
            );
        }
    } else {
        $stmt = $db->prepare(
            'INSERT INTO budget_line_items (department_id, fiscal_year_id, group_id, name, starting_amount, requires_travel_detail, note, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([$departmentId, $fiscalYearId, $groupId, $name, (float) $startingAmountRaw, $requiresTravel, $note]);
    }
} catch (PDOException $e) {
    bpm_flash_set('danger', str_contains($e->getMessage(), 'Duplicate') ? 'สาขานี้มีรายการชื่อนี้ในปีงบนี้อยู่แล้ว' : 'บันทึกไม่สำเร็จ กรุณาลองใหม่');
    header('Location: ' . $redirectBack);
    exit;
}

bpm_flash_set('success', 'บันทึกรายการงบเรียบร้อยแล้ว');
header('Location: ' . $redirectBack);
exit;
