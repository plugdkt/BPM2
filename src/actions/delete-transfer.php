<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * ลบคำขอโยกย้ายงบถาวร — เฉพาะ ADMIN เท่านั้น (เหมือน delete-transaction.php)
 * ลบได้ทุกสถานะ (PENDING/APPROVED/REJECTED) — ถ้าลบคำขอที่ APPROVED แล้ว ยอดคงเหลือของทั้งหมวดต้นทาง/ปลายทาง
 * จะเปลี่ยนทันทีเพราะ bpm_line_item_balance() คำนวณจาก SUM(budget_transfers WHERE status='APPROVED') สด
 */

$user = bpm_require_role('ADMIN');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . bpm_url(''));
    exit;
}

$redirectBack = bpm_url('transfers.php?') . http_build_query(array_filter([
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
$stmt = $db->prepare('SELECT * FROM budget_transfers WHERE id = ?');
$stmt->execute([$id]);
$transfer = $stmt->fetch();

if (!$transfer) {
    bpm_flash_set('danger', 'ไม่พบคำขอที่ต้องการลบ');
    header('Location: ' . $redirectBack);
    exit;
}

$fyStmt = $db->prepare('SELECT * FROM fiscal_years WHERE id = ?');
$fyStmt->execute([$transfer['fiscal_year_id']]);
$fiscalYear = $fyStmt->fetch();

if ($fiscalYear['status'] === 'CLOSED') {
    bpm_flash_set('danger', 'ปีงบนี้ถูกปิดแล้ว ไม่สามารถลบคำขอโยกย้ายงบได้ (ดู spec.md ข้อ 6.5)');
    header('Location: ' . $redirectBack);
    exit;
}

try {
    $db->beginTransaction();

    // lock ทั้งหมวดต้นทาง/ปลายทาง กันคำนวณยอดชนกับรายการอื่นที่กำลังทำพร้อมกัน
    bpm_line_item_balance((int) $transfer['from_line_item_id'], true);
    bpm_line_item_balance((int) $transfer['to_line_item_id'], true);

    $db->prepare('DELETE FROM budget_transfers WHERE id = ?')->execute([$id]);

    bpm_audit_log(
        (int) $user['id'],
        'TRANSFER_DELETE',
        'budget_transfers',
        $id,
        [
            'from_line_item_id' => (int) $transfer['from_line_item_id'],
            'to_line_item_id'   => (int) $transfer['to_line_item_id'],
            'amount'            => (float) $transfer['amount'],
            'reason'            => $transfer['reason'],
            'status'            => $transfer['status'],
            'approved_by'       => $transfer['approved_by'],
            'decided_at'        => $transfer['decided_at'],
        ],
        null
    );

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('[BPM] delete-transfer failed: ' . $e->getMessage());
    bpm_flash_set('danger', 'ลบคำขอไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
    header('Location: ' . $redirectBack);
    exit;
}

bpm_flash_set('success', 'ลบคำขอโยกย้ายงบเรียบร้อยแล้ว');
header('Location: ' . $redirectBack);
exit;
