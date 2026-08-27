<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * บันทึกรายการเบิกจ่าย/รายรับ — ดู spec.md ข้อ 5.2/5.3/6.6
 * รับ POST เท่านั้น จาก public/transactions.php แล้ว redirect กลับเสมอ (PRG pattern)
 */

$user = bpm_require_role('ADMIN', 'DEPT_STAFF');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . bpm_url(''));
    exit;
}

$redirectBack = bpm_url('transactions.php?') . http_build_query(array_filter([
    'fy'   => $_POST['fy'] ?? null,
    'dept' => $_POST['dept'] ?? null,
]));

if (!bpm_csrf_verify($_POST['csrf_token'] ?? null)) {
    bpm_flash_set('danger', 'คำขอไม่ถูกต้องหรือหมดเวลา กรุณาลองใหม่อีกครั้ง');
    header('Location: ' . $redirectBack);
    exit;
}

$lineItemId  = (int) ($_POST['line_item_id'] ?? 0);
$type        = $_POST['type'] ?? '';
$amountRaw   = str_replace(',', '', (string) ($_POST['amount'] ?? ''));
$txnDate     = (string) ($_POST['txn_date'] ?? '');
$description = trim((string) ($_POST['description'] ?? ''));
$referenceNo = trim((string) ($_POST['reference_no'] ?? '')) ?: null;

$instructorName = trim((string) ($_POST['instructor_name'] ?? ''));
$purpose        = trim((string) ($_POST['purpose'] ?? ''));
$installmentNo  = max(1, (int) ($_POST['installment_no'] ?? 1));
$travelRefDoc   = trim((string) ($_POST['travel_ref_doc'] ?? '')) ?: null;

// --- validation พื้นฐาน (ดู spec.md ข้อ 5.3) ---
$errors = [];

if (!in_array($type, ['EXPENSE', 'INCOME'], true)) {
    $errors[] = 'กรุณาเลือกประเภทรายการ';
}
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

$db = bpm_db();

$stmt = $db->prepare('SELECT li.*, d.name AS department_name FROM budget_line_items li JOIN departments d ON d.id = li.department_id WHERE li.id = ?');
$stmt->execute([$lineItemId]);
$lineItem = $stmt->fetch();

if (!$lineItem) {
    $errors[] = 'ไม่พบรายการงบที่เลือก';
} else {
    // ownership: DEPT_STAFF บันทึกได้เฉพาะสาขาตัวเอง (ห้ามเชื่อ dropdown ฝั่ง client — ดู spec.md ข้อ 2)
    if ($user['role'] === 'DEPT_STAFF' && (int) $lineItem['department_id'] !== (int) $user['department_id']) {
        $errors[] = 'ไม่มีสิทธิ์บันทึกรายการของสาขาอื่น';
    }

    $fyStmt = $db->prepare('SELECT * FROM fiscal_years WHERE id = ?');
    $fyStmt->execute([$lineItem['fiscal_year_id']]);
    $fiscalYear = $fyStmt->fetch();

    if ($fiscalYear['status'] === 'CLOSED') {
        $errors[] = 'ปีงบนี้ถูกปิดแล้ว ไม่สามารถบันทึกรายการเพิ่มได้ (ดู spec.md ข้อ 6.5)';
    } elseif ($txnDateObj && ($txnDate < $fiscalYear['start_date'] || $txnDate > $fiscalYear['end_date'])) {
        $errors[] = 'วันที่ทำรายการต้องอยู่ในช่วงปีงบ พ.ศ. ' . $fiscalYear['year_be'];
    }

    if ((int) $lineItem['requires_travel_detail'] === 1) {
        if ($instructorName === '' || $purpose === '') {
            $errors[] = 'รายการนี้ต้องกรอกชื่อผู้เดินทางและรายละเอียดการเดินทางด้วย (ดู spec.md ข้อ 6.6)';
        }
    }
}

if (!empty($errors)) {
    bpm_flash_set('danger', implode(' / ', $errors));
    header('Location: ' . $redirectBack);
    exit;
}

// --- บันทึกจริง ภายใน DB transaction เดียว (ดู spec.md ข้อ 5.2) ---
try {
    $db->beginTransaction();

    // lock แถว line item ไว้ระหว่างคำนวณ กันสองคนบันทึกพร้อมกันแล้วยอดเพี้ยน
    bpm_line_item_balance($lineItemId, true);

    $insertTxn = $db->prepare(
        'INSERT INTO transactions (line_item_id, type, amount, description, reference_no, txn_date, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $insertTxn->execute([
        $lineItemId,
        $type,
        (float) $amountRaw,
        $description,
        $referenceNo,
        $txnDate,
        $user['id'],
    ]);
    $transactionId = (int) $db->lastInsertId();

    if ((int) $lineItem['requires_travel_detail'] === 1) {
        $insertTravel = $db->prepare(
            'INSERT INTO travel_records (transaction_id, instructor_name, purpose, installment_no, ref_doc_no)
             VALUES (?, ?, ?, ?, ?)'
        );
        $insertTravel->execute([$transactionId, $instructorName, $purpose, $installmentNo, $travelRefDoc]);
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('[BPM] create-transaction failed: ' . $e->getMessage());
    bpm_flash_set('danger', 'บันทึกรายการไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
    header('Location: ' . $redirectBack);
    exit;
}

bpm_flash_set('success', 'บันทึกรายการเรียบร้อยแล้ว');
header('Location: ' . $redirectBack);
exit;

