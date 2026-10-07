<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * แก้ไขรายการเบิกจ่ายที่บันทึกไปแล้ว — ให้ ADMIN หรือคนที่บันทึกรายการนั้นเองเท่านั้น
 * ประเภทรายการ (รายจ่าย/รายรับ) แก้ไม่ได้ — คงตามที่บันทึกไว้เดิม (ระบบนี้ไม่มีรายรับแล้ว เหลือเฉพาะข้อมูลเก่าถ้ามี)
 * ผู้ขอใช้: เลือกจากผู้ใช้ในระบบ — รายการเก่าที่ยังไม่มีผู้ขอใช้เว้นว่างต่อได้ แต่ถ้ามีแล้วต้องไม่ลบออก
 * line_item_id แก้ไม่ได้ (ตั้งใจ — ถ้าเลือกรายการงบผิดทั้งหมด ให้บันทึกใหม่แทนแก้ไขข้าม line item)
 * ดู spec.md ข้อ 5.2/5.3 (audit trail) และ src/actions/create-transaction.php (validation แบบเดียวกัน)
 */

$user = bpm_require_role('ADMIN', 'DEPT_STAFF');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . bpm_url(''));
    exit;
}

$redirectBack = bpm_url('transactions.php?') . http_build_query(array_filter([
    'fy'    => $_POST['fy'] ?? null,
    'dept'  => $_POST['dept'] ?? null,
    'group' => $_POST['group'] ?? null,
], static fn ($v) => $v !== null && $v !== ''));

if (!bpm_csrf_verify($_POST['csrf_token'] ?? null)) {
    bpm_flash_set('danger', 'คำขอไม่ถูกต้องหรือหมดเวลา กรุณาลองใหม่อีกครั้ง');
    header('Location: ' . $redirectBack);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);

$db = bpm_db();
$stmt = $db->prepare(
    'SELECT t.*, li.department_id, li.fiscal_year_id, li.requires_travel_detail
     FROM transactions t JOIN budget_line_items li ON li.id = t.line_item_id
     WHERE t.id = ?'
);
$stmt->execute([$id]);
$txn = $stmt->fetch();

if (!$txn) {
    bpm_flash_set('danger', 'ไม่พบรายการที่ต้องการแก้ไข');
    header('Location: ' . $redirectBack);
    exit;
}

// สิทธิ์แก้ไข: ADMIN แก้ได้ทุกรายการ, คนอื่นแก้ได้เฉพาะรายการที่ตัวเองบันทึกไว้เท่านั้น
if ($user['role'] !== 'ADMIN' && (int) $txn['created_by'] !== (int) $user['id']) {
    bpm_flash_set('danger', 'ไม่มีสิทธิ์แก้ไขรายการนี้ (แก้ได้เฉพาะรายการที่ตัวเองบันทึกไว้)');
    header('Location: ' . $redirectBack);
    exit;
}

$type        = $txn['type']; // ไม่รับค่าประเภทจากฟอร์ม
$requesterId = (int) ($_POST['requester_user_id'] ?? 0);
$amountRaw   = str_replace(',', '', (string) ($_POST['amount'] ?? ''));
$txnDate     = (string) ($_POST['txn_date'] ?? '');
$description = trim((string) ($_POST['description'] ?? ''));
$referenceNo = trim((string) ($_POST['reference_no'] ?? '')) ?: null;

$instructorName = trim((string) ($_POST['instructor_name'] ?? ''));
$purpose        = trim((string) ($_POST['purpose'] ?? ''));
$installmentNo  = max(1, (int) ($_POST['installment_no'] ?? 1));
$travelRefDoc   = trim((string) ($_POST['travel_ref_doc'] ?? '')) ?: null;

$errors = [];

if (!is_numeric($amountRaw) || (float) $amountRaw <= 0) {
    $errors[] = 'จำนวนเงินต้องมากกว่า 0';
}
if ($description === '') {
    $errors[] = 'กรุณากรอกรายละเอียดรายการ';
}
$txnDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $txnDate) ?: null;
if (!$txnDateObj) {
    $errors[] = 'วันที่ทำรายการไม่ถูกต้อง';
}

// ผู้ขอใช้: เลือกจากรายชื่อที่ยังเปิดใช้งาน (หรือคนเดิมของรายการนี้ แม้ถูกระงับไปแล้ว) — เว้นว่างได้เฉพาะรายการเก่าที่ยังไม่เคยมีผู้ขอใช้
if ($requesterId > 0) {
    $requesterStmt = $db->prepare('SELECT id FROM users WHERE id = ? AND (is_active = 1 OR id = ?)');
    $requesterStmt->execute([$requesterId, (int) ($txn['requester_user_id'] ?? 0)]);
    if ($requesterStmt->fetchColumn() === false) {
        $errors[] = 'ผู้ขอใช้ที่เลือกไม่ถูกต้อง กรุณาเลือกจากรายชื่อ';
    }
} elseif ($txn['requester_user_id'] !== null) {
    $errors[] = 'กรุณาเลือกผู้ขอใช้จากรายชื่อ (พิมพ์ชื่อแล้วเลือกจากที่ขึ้นมา)';
}
$newRequester = $requesterId > 0 ? $requesterId : null;

$fyStmt = $db->prepare('SELECT * FROM fiscal_years WHERE id = ?');
$fyStmt->execute([$txn['fiscal_year_id']]);
$fiscalYear = $fyStmt->fetch();

if ($fiscalYear['status'] === 'CLOSED') {
    $errors[] = 'ปีงบนี้ถูกปิดแล้ว ไม่สามารถแก้ไขรายการได้ (ดู spec.md ข้อ 6.5)';
} elseif ($txnDateObj && ($txnDate < $fiscalYear['start_date'] || $txnDate > $fiscalYear['end_date'])) {
    $errors[] = 'วันที่ทำรายการต้องอยู่ในช่วงปีงบ พ.ศ. ' . $fiscalYear['year_be'] . ' (' . bpm_thai_date($fiscalYear['start_date']) . ' – ' . bpm_thai_date($fiscalYear['end_date']) . ') — ย้อนหลังได้ถึงวันเริ่มปีงบ';
}

if ((int) $txn['requires_travel_detail'] === 1 && ($instructorName === '' || $purpose === '')) {
    $errors[] = 'รายการนี้ต้องกรอกชื่อผู้เดินทางและรายละเอียดการเดินทางด้วย (ดู spec.md ข้อ 6.6)';
}

if (!empty($errors)) {
    bpm_flash_set('danger', implode(' / ', $errors));
    header('Location: ' . $redirectBack);
    exit;
}

try {
    $db->beginTransaction();

    // lock แถว line item ไว้ระหว่างคำนวณ กันสองคนบันทึก/แก้พร้อมกันแล้วยอดเพี้ยน
    bpm_line_item_balance((int) $txn['line_item_id'], true);

    $update = $db->prepare(
        'UPDATE transactions SET type = ?, amount = ?, description = ?, reference_no = ?, requester_user_id = ?, txn_date = ? WHERE id = ?'
    );
    $update->execute([$type, (float) $amountRaw, $description, $referenceNo, $newRequester, $txnDate, $id]);

    if ((int) $txn['requires_travel_detail'] === 1) {
        $existingTravel = $db->prepare('SELECT id FROM travel_records WHERE transaction_id = ?');
        $existingTravel->execute([$id]);
        $travelId = $existingTravel->fetchColumn();

        if ($travelId) {
            $db->prepare('UPDATE travel_records SET instructor_name = ?, purpose = ?, installment_no = ?, ref_doc_no = ? WHERE transaction_id = ?')
                ->execute([$instructorName, $purpose, $installmentNo, $travelRefDoc, $id]);
        } else {
            $db->prepare('INSERT INTO travel_records (transaction_id, instructor_name, purpose, installment_no, ref_doc_no) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, $instructorName, $purpose, $installmentNo, $travelRefDoc]);
        }
    }

    bpm_audit_log(
        (int) $user['id'],
        'TRANSACTION_UPDATE',
        'transactions',
        $id,
        ['type' => $txn['type'], 'amount' => (float) $txn['amount'], 'description' => $txn['description'], 'reference_no' => $txn['reference_no'], 'requester_user_id' => $txn['requester_user_id'] !== null ? (int) $txn['requester_user_id'] : null, 'txn_date' => $txn['txn_date']],
        ['type' => $type, 'amount' => (float) $amountRaw, 'description' => $description, 'reference_no' => $referenceNo, 'requester_user_id' => $newRequester, 'txn_date' => $txnDate]
    );

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('[BPM] update-transaction failed: ' . $e->getMessage());
    bpm_flash_set('danger', 'แก้ไขรายการไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
    header('Location: ' . $redirectBack);
    exit;
}

bpm_flash_set('success', 'แก้ไขรายการเรียบร้อยแล้ว');
header('Location: ' . $redirectBack);
exit;
