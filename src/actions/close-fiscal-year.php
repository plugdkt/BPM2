<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/** ปิดปีงบประมาณ — irreversible ในทางปฏิบัติ ดู spec.md ข้อ 6.5 */

$user = bpm_require_role('ADMIN');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/fiscal-years.php');
    exit;
}
if (!bpm_csrf_verify($_POST['csrf_token'] ?? null)) {
    bpm_flash_set('danger', 'คำขอไม่ถูกต้องหรือหมดเวลา');
    header('Location: /admin/fiscal-years.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$db = bpm_db();

try {
    $db->beginTransaction();

    $stmt = $db->prepare('SELECT * FROM fiscal_years WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $fy = $stmt->fetch();

    if (!$fy) {
        throw new RuntimeException('ไม่พบปีงบประมาณนี้');
    }
    if ($fy['status'] === 'CLOSED') {
        throw new RuntimeException('ปีงบนี้ถูกปิดไปแล้ว');
    }

    $update = $db->prepare("UPDATE fiscal_years SET status = 'CLOSED' WHERE id = ?");
    $update->execute([$id]);

    bpm_audit_log((int) $user['id'], 'FISCAL_YEAR_CLOSE', 'fiscal_years', $id, ['status' => 'OPEN'], ['status' => 'CLOSED']);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    bpm_flash_set('danger', $e instanceof RuntimeException ? $e->getMessage() : 'ดำเนินการไม่สำเร็จ');
    if (!$e instanceof RuntimeException) {
        error_log('[BPM] close-fiscal-year failed: ' . $e->getMessage());
    }
    header('Location: /admin/fiscal-years.php');
    exit;
}

bpm_flash_set('success', 'ปิดปีงบประมาณ พ.ศ. ' . $fy['year_be'] . ' เรียบร้อยแล้ว');
header('Location: /admin/fiscal-years.php');
exit;
