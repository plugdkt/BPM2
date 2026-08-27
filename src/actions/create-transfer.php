<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/** ยื่นคำขอโยกย้ายงบ — ดู spec.md ข้อ 5.3/6.4 */

$user = bpm_require_role('ADMIN', 'DEPT_STAFF');

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

$fromId      = (int) ($_POST['from_line_item_id'] ?? 0);
$toId        = (int) ($_POST['to_line_item_id'] ?? 0);
$amountRaw   = str_replace(',', '', (string) ($_POST['amount'] ?? ''));
$reason      = trim((string) ($_POST['reason'] ?? ''));
$refMemoNo   = trim((string) ($_POST['ref_memo_no'] ?? '')) ?: null;

$errors = [];

if ($fromId === $toId) {
    $errors[] = 'หมวดต้นทางและปลายทางต้องไม่ใช่รายการเดียวกัน';
}
if (!is_numeric($amountRaw) || (float) $amountRaw <= 0) {
    $errors[] = 'จำนวนเงินต้องมากกว่า 0';
}
if ($reason === '') {
    $errors[] = 'กรุณาระบุเหตุผลการโยกย้าย';
}

$db = bpm_db();
$stmt = $db->prepare('SELECT * FROM budget_line_items WHERE id IN (?, ?)');
$stmt->execute([$fromId, $toId]);
$items = $stmt->fetchAll(PDO::FETCH_UNIQUE);
$fromItem = $items[$fromId] ?? null;
$toItem   = $items[$toId] ?? null;

if (!$fromItem || !$toItem) {
    $errors[] = 'ไม่พบรายการงบที่เลือก';
} else {
    // ต้องอยู่สาขา+ปีงบเดียวกันเท่านั้น (ห้ามโอนข้ามสาขา/ข้ามปีงบ — ดู spec.md ข้อ 5.3)
    if ((int) $fromItem['department_id'] !== (int) $toItem['department_id']
        || (int) $fromItem['fiscal_year_id'] !== (int) $toItem['fiscal_year_id']) {
        $errors[] = 'ต้องโอนย้ายภายในสาขาและปีงบเดียวกันเท่านั้น';
    }

    if ($user['role'] === 'DEPT_STAFF' && (int) $fromItem['department_id'] !== (int) $user['department_id']) {
        $errors[] = 'ไม่มีสิทธิ์ยื่นคำขอของสาขาอื่น';
    }

    $fyStmt = $db->prepare('SELECT * FROM fiscal_years WHERE id = ?');
    $fyStmt->execute([$fromItem['fiscal_year_id']]);
    $fiscalYear = $fyStmt->fetch();
    if ($fiscalYear['status'] === 'CLOSED') {
        $errors[] = 'ปีงบนี้ถูกปิดแล้ว ไม่สามารถยื่นคำขอโยกย้ายงบได้';
    }

    // เช็คยอดคงเหลือของหมวดต้นทาง ณ ตอนยื่นคำขอ (เช็คซ้ำอีกครั้งตอนอนุมัติ เพราะยอดอาจเปลี่ยนระหว่างรอ)
    if (empty($errors)) {
        $fromBalance = bpm_line_item_balance($fromId)['balance'];
        if ((float) $amountRaw > $fromBalance) {
            $errors[] = sprintf('ยอดที่ขอโอน (%s) เกินยอดคงเหลือของหมวดต้นทาง (%s)', bpm_money((float) $amountRaw), bpm_money($fromBalance));
        }
    }
}

if (!empty($errors)) {
    bpm_flash_set('danger', implode(' / ', $errors));
    header('Location: ' . $redirectBack);
    exit;
}

$insert = $db->prepare(
    'INSERT INTO budget_transfers (fiscal_year_id, department_id, from_line_item_id, to_line_item_id, amount, reason, ref_memo_no, status, requested_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, "PENDING", ?)'
);
$insert->execute([
    $fromItem['fiscal_year_id'],
    $fromItem['department_id'],
    $fromId,
    $toId,
    (float) $amountRaw,
    $reason,
    $refMemoNo,
    $user['id'],
]);

bpm_flash_set('success', 'ยื่นคำขอโยกย้ายงบเรียบร้อยแล้ว รอ ADMIN อนุมัติ');
header('Location: ' . $redirectBack);
exit;

