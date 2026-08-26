<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/** กำหนด role + สาขาให้ผู้ใช้ที่ login ผ่าน SSO แล้ว — ดู spec.md ข้อ 3.4/5.3 */

$user = bpm_require_role('ADMIN');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/users.php');
    exit;
}
if (!bpm_csrf_verify($_POST['csrf_token'] ?? null)) {
    bpm_flash_set('danger', 'คำขอไม่ถูกต้องหรือหมดเวลา');
    header('Location: /admin/users.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$role = trim((string) ($_POST['role'] ?? ''));
$role = $role === '' ? null : $role;
$departmentId = (int) ($_POST['department_id'] ?? 0) ?: null;
$isActive = isset($_POST['is_active']) ? 1 : 0;

if ($role !== null && !in_array($role, ['ADMIN', 'DEPT_STAFF', 'EXECUTIVE_VIEWER'], true)) {
    bpm_flash_set('danger', 'สิทธิ์ไม่ถูกต้อง');
    header('Location: /admin/users.php');
    exit;
}

if ($role === 'DEPT_STAFF' && $departmentId === null) {
    bpm_flash_set('danger', 'เจ้าหน้าที่สาขาต้องระบุสาขาด้วย');
    header('Location: /admin/users.php');
    exit;
}

// ADMIN/EXECUTIVE_VIEWER ไม่ควรผูกสาขา — เคลียร์ทิ้งอัตโนมัติ (ดู spec.md ข้อ 5.3)
if ($role !== 'DEPT_STAFF') {
    $departmentId = null;
}

$db = bpm_db();

$stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$target = $stmt->fetch();
if (!$target) {
    bpm_flash_set('danger', 'ไม่พบผู้ใช้นี้');
    header('Location: /admin/users.php');
    exit;
}

// กันตัวเองลดสิทธิ์ตัวเองจน ADMIN ไม่เหลือใครเข้าหน้านี้ได้อีก (ไม่ block แต่เตือนไว้เป็น comment สำหรับอนาคต — ยังไม่ implement เพราะ v1 ยังไม่มีเช็คจำนวน ADMIN ขั้นต่ำ)

$db->beginTransaction();
try {
    $update = $db->prepare('UPDATE users SET role = ?, department_id = ?, is_active = ? WHERE id = ?');
    $update->execute([$role, $departmentId, $isActive, $id]);

    bpm_audit_log(
        (int) $user['id'],
        'USER_ROLE_CHANGE',
        'users',
        $id,
        ['role' => $target['role'], 'department_id' => $target['department_id'], 'is_active' => $target['is_active']],
        ['role' => $role, 'department_id' => $departmentId, 'is_active' => $isActive]
    );

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('[BPM] save-user-role failed: ' . $e->getMessage());
    bpm_flash_set('danger', 'บันทึกไม่สำเร็จ กรุณาลองใหม่');
    header('Location: /admin/users.php');
    exit;
}

bpm_flash_set('success', 'บันทึกสิทธิ์ผู้ใช้เรียบร้อยแล้ว');
header('Location: /admin/users.php');
exit;
