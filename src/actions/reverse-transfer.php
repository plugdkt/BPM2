<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * โอนงบกลับหมวดเดิม — เฉพาะ ADMIN, ใช้กับคำขอที่ APPROVED แล้วเท่านั้น (เพราะมีแค่สถานะนี้ที่ขยับเงินจริง)
 * สร้างคำขอโยกย้ายงบ "ใหม่" สลับ from/to กับคำขอเดิม แล้วอนุมัติทันทีในตัว (ไม่ต้องรออนุมัติซ้ำ)
 * เพื่อให้ยอดคงเหลือ (คำนวณสดจาก SUM ของ APPROVED) กลับไปเป็นก่อนโยกย้ายทันที — ดู [[Nothing is Deleted]]:
 * ไม่ได้ลบ/แก้คำขอเดิม แค่เพิ่มบรรทัดใหม่ที่หักล้างผลของมัน
 */

$user = bpm_require_role('ADMIN');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . bpm_url(''));
    exit;
}

$redirectBack = bpm_url('transfers.php?') . http_build_query(array_filter([
    'fy'    => $_POST['fy'] ?? null,
    'dept'  => $_POST['dept'] ?? null,
    'group' => $_POST['group'] ?? null,
], static fn ($v) => $v !== null && $v !== ''));

if (!bpm_csrf_verify($_POST['csrf_token'] ?? null)) {
    bpm_flash_set('danger', 'คำขอไม่ถูกต้องหรือหมดเวลา กรุณาลองใหม่อีกครั้ง');
    header('Location: ' . $redirectBack);
    exit;
}

$transferId = (int) ($_POST['transfer_id'] ?? 0);

$db = bpm_db();

try {
    $db->beginTransaction();

    $stmt = $db->prepare('SELECT * FROM budget_transfers WHERE id = ? FOR UPDATE');
    $stmt->execute([$transferId]);
    $original = $stmt->fetch();

    if (!$original) {
        throw new RuntimeException('ไม่พบคำขอโยกย้ายงบนี้');
    }
    if ($original['status'] !== 'APPROVED') {
        throw new RuntimeException('โอนกลับได้เฉพาะคำขอที่อนุมัติแล้วเท่านั้น');
    }

    $alreadyStmt = $db->prepare('SELECT COUNT(*) FROM budget_transfers WHERE reversed_of_transfer_id = ?');
    $alreadyStmt->execute([$transferId]);
    if ((int) $alreadyStmt->fetchColumn() > 0) {
        throw new RuntimeException('คำขอนี้ถูกโอนกลับไปแล้ว ทำซ้ำไม่ได้');
    }

    $fyStmt = $db->prepare('SELECT * FROM fiscal_years WHERE id = ?');
    $fyStmt->execute([$original['fiscal_year_id']]);
    $fiscalYear = $fyStmt->fetch();
    if ($fiscalYear['status'] === 'CLOSED') {
        throw new RuntimeException('ปีงบนี้ถูกปิดแล้ว ไม่สามารถโอนกลับได้');
    }

    // สลับ from/to: หมวดปลายทางเดิม กลายเป็นหมวดต้นทางของการโอนกลับ
    $reverseFromId = (int) $original['to_line_item_id'];
    $reverseToId   = (int) $original['from_line_item_id'];
    $amount        = (float) $original['amount'];

    // lock ทั้งสองหมวด กันคำนวณยอดชนกับรายการอื่นที่กำลังทำพร้อมกัน แล้วเช็คยอดคงเหลือซ้ำ
    // (หมวดปลายทางเดิมอาจถูกใช้เบิกจ่ายไปแล้วบางส่วน ทำให้เงินไม่พอโอนกลับเต็มจำนวน)
    $fromBalance = bpm_line_item_balance($reverseFromId, true)['balance'];
    bpm_line_item_balance($reverseToId, true);
    if ($amount > $fromBalance) {
        $liStmt = $db->prepare('SELECT name FROM budget_line_items WHERE id = ?');
        $liStmt->execute([$reverseFromId]);
        throw new RuntimeException(sprintf(
            'โอนกลับไม่ได้ ยอดคงเหลือของหมวด "%s" เหลือแค่ %s แต่ต้องโอนกลับ %s (คงมีการเบิกจ่ายจากหมวดนี้ไปแล้ว)',
            $liStmt->fetchColumn() ?: '',
            bpm_money($fromBalance),
            bpm_money($amount)
        ));
    }

    $insert = $db->prepare(
        'INSERT INTO budget_transfers
            (fiscal_year_id, department_id, from_line_item_id, to_line_item_id, amount, reason, status, requested_by, approved_by, decided_at, reversed_of_transfer_id)
         VALUES (?, ?, ?, ?, ?, ?, "APPROVED", ?, ?, NOW(), ?)'
    );
    $insert->execute([
        $original['fiscal_year_id'],
        $original['department_id'],
        $reverseFromId,
        $reverseToId,
        $amount,
        'โอนกลับหมวดเดิมของคำขอ #' . $transferId . ' (' . $original['reason'] . ')',
        $user['id'],
        $user['id'],
        $transferId,
    ]);
    $newId = (int) $db->lastInsertId();

    bpm_audit_log(
        (int) $user['id'],
        'TRANSFER_REVERSE',
        'budget_transfers',
        $newId,
        ['reversed_of_transfer_id' => $transferId],
        [
            'from_line_item_id' => $reverseFromId,
            'to_line_item_id'   => $reverseToId,
            'amount'            => $amount,
        ]
    );

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    bpm_flash_set('danger', $e instanceof RuntimeException ? $e->getMessage() : 'โอนกลับไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
    if (!$e instanceof RuntimeException) {
        error_log('[BPM] reverse-transfer failed: ' . $e->getMessage());
    }
    header('Location: ' . $redirectBack);
    exit;
}

bpm_flash_set('success', 'โอนงบกลับหมวดเดิมเรียบร้อยแล้ว');
header('Location: ' . $redirectBack);
exit;
