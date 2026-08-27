<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * อนุมัติ/ไม่อนุมัติคำขอโยกย้ายงบ — เฉพาะ ADMIN, เป็นขั้นตอนเดียว/บันทึกผลเพื่อ monitor (ดู spec.md ข้อ 1/6.4)
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

$transferId = (int) ($_POST['transfer_id'] ?? 0);
$decision   = $_POST['decision'] ?? '';

if (!in_array($decision, ['APPROVED', 'REJECTED'], true)) {
    bpm_flash_set('danger', 'คำขอไม่ถูกต้อง');
    header('Location: ' . $redirectBack);
    exit;
}

$db = bpm_db();

try {
    $db->beginTransaction();

    $stmt = $db->prepare('SELECT * FROM budget_transfers WHERE id = ? FOR UPDATE');
    $stmt->execute([$transferId]);
    $transfer = $stmt->fetch();

    if (!$transfer) {
        throw new RuntimeException('ไม่พบคำขอโยกย้ายงบนี้');
    }
    if ($transfer['status'] !== 'PENDING') {
        throw new RuntimeException('คำขอนี้ถูกตัดสินใจไปแล้ว');
    }

    // ตอนอนุมัติต้องเช็คยอดคงเหลือของหมวดต้นทางซ้ำอีกครั้ง เพราะยอดอาจเปลี่ยนไปแล้วระหว่างรออนุมัติ (ดู spec.md ข้อ 6.4)
    if ($decision === 'APPROVED') {
        $fromBalance = bpm_line_item_balance((int) $transfer['from_line_item_id'], true)['balance'];
        if ((float) $transfer['amount'] > $fromBalance) {
            throw new RuntimeException(sprintf(
                'ไม่สามารถอนุมัติได้ ยอดคงเหลือของหมวดต้นทางเหลือแค่ %s แต่ขอโอน %s',
                bpm_money($fromBalance),
                bpm_money((float) $transfer['amount'])
            ));
        }
    }

    $update = $db->prepare('UPDATE budget_transfers SET status = ?, approved_by = ?, decided_at = NOW() WHERE id = ?');
    $update->execute([$decision, $user['id'], $transferId]);

    bpm_audit_log(
        (int) $user['id'],
        $decision === 'APPROVED' ? 'TRANSFER_APPROVE' : 'TRANSFER_REJECT',
        'budget_transfers',
        $transferId,
        ['status' => $transfer['status']],
        ['status' => $decision]
    );

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    bpm_flash_set('danger', $e instanceof RuntimeException ? $e->getMessage() : 'ดำเนินการไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
    if (!$e instanceof RuntimeException) {
        error_log('[BPM] decide-transfer failed: ' . $e->getMessage());
    }
    header('Location: ' . $redirectBack);
    exit;
}

bpm_flash_set('success', $decision === 'APPROVED' ? 'อนุมัติคำขอโยกย้ายงบเรียบร้อยแล้ว' : 'ไม่อนุมัติคำขอโยกย้ายงบแล้ว');
header('Location: ' . $redirectBack);
exit;

