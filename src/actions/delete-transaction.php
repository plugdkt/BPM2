<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * ลบรายการเบิกจ่าย/รายรับถาวร — เฉพาะ ADMIN เท่านั้น ใช้เฉพาะกรณีข้อมูลผิดพลาดจริงๆ (ผู้ใช้ร้องขอ)
 * ต่างจากที่อื่นในระบบที่ "ปิดใช้งาน" แทนการลบเสมอ (spec.md ข้อ 5.3) — จุดนี้ยอมให้ลบจริงตามคำขอ
 * แต่ยังคง audit trail ไว้: เก็บ snapshot ค่าทั้งแถวลง audit_logs (old_value) ก่อนลบเสมอ ไม่มี new_value เพราะถูกลบไปแล้ว
 */

$user = bpm_require_role('ADMIN');

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

$id = (int) ($_POST['id'] ?? 0);

$db = bpm_db();
$stmt = $db->prepare(
    'SELECT t.*, li.fiscal_year_id, li.name AS line_item_name
     FROM transactions t JOIN budget_line_items li ON li.id = t.line_item_id
     WHERE t.id = ?'
);
$stmt->execute([$id]);
$txn = $stmt->fetch();

if (!$txn) {
    bpm_flash_set('danger', 'ไม่พบรายการที่ต้องการลบ');
    header('Location: ' . $redirectBack);
    exit;
}

$fyStmt = $db->prepare('SELECT * FROM fiscal_years WHERE id = ?');
$fyStmt->execute([$txn['fiscal_year_id']]);
$fiscalYear = $fyStmt->fetch();

if ($fiscalYear['status'] === 'CLOSED') {
    bpm_flash_set('danger', 'ปีงบนี้ถูกปิดแล้ว ไม่สามารถลบรายการได้ (ดู spec.md ข้อ 6.5)');
    header('Location: ' . $redirectBack);
    exit;
}

try {
    $db->beginTransaction();

    bpm_line_item_balance((int) $txn['line_item_id'], true); // lock กันคำนวณยอดชนกับรายการอื่นที่กำลังบันทึกพร้อมกัน

    $travel = $db->prepare('SELECT * FROM travel_records WHERE transaction_id = ?');
    $travel->execute([$id]);
    $travelRow = $travel->fetch();
    if ($travelRow) {
        $db->prepare('DELETE FROM travel_records WHERE transaction_id = ?')->execute([$id]);
    }

    $db->prepare('DELETE FROM transactions WHERE id = ?')->execute([$id]);

    bpm_audit_log(
        (int) $user['id'],
        'TRANSACTION_DELETE',
        'transactions',
        $id,
        [
            'line_item_name' => $txn['line_item_name'],
            'type'           => $txn['type'],
            'amount'         => (float) $txn['amount'],
            'description'    => $txn['description'],
            'reference_no'   => $txn['reference_no'],
            'txn_date'       => $txn['txn_date'],
            'travel_record'  => $travelRow ?: null,
        ],
        null
    );

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('[BPM] delete-transaction failed: ' . $e->getMessage());
    bpm_flash_set('danger', 'ลบรายการไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
    header('Location: ' . $redirectBack);
    exit;
}

bpm_flash_set('success', 'ลบรายการเรียบร้อยแล้ว');
header('Location: ' . $redirectBack);
exit;
