<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * เพิ่มผู้ใช้ล่วงหน้าโดยระบุแค่ username บัญชี UP Account (ยังไม่เคย login เข้าระบบเลยก็ได้)
 * กำหนดสิทธิ์ไว้ล่วงหน้าได้ทันที — พอคนนั้น login ผ่าน SSO ครั้งแรกจริงๆ bpm_sso_provision_user()
 * จะเจอแถวนี้อยู่แล้ว (match ด้วย sso_username) แล้วแค่ sync ชื่อ/อีเมลจริงทับ ไม่แตะ role ที่ตั้งไว้
 * (ดู src/lib/auth.php bpm_sso_provision_user — ไม่ต้องแก้ฟังก์ชันนั้นเลย ออกแบบรองรับ pre-provision อยู่แล้ว)
 *
 * name/email ที่ insert ตรงนี้เป็นแค่ placeholder ชั่วคราว จะถูกทับด้วยข้อมูลจริงจาก SSO อัตโนมัติ
 */

$user = bpm_require_role('ADMIN');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . bpm_url('admin/users.php'));
    exit;
}
if (!bpm_csrf_verify($_POST['csrf_token'] ?? null)) {
    bpm_flash_set('danger', 'คำขอไม่ถูกต้องหรือหมดเวลา');
    header('Location: ' . bpm_url('admin/users.php'));
    exit;
}

$ssoUsername = trim((string) ($_POST['sso_username'] ?? ''));
$role = trim((string) ($_POST['role'] ?? ''));
$role = $role === '' ? null : $role;
$departmentId = (int) ($_POST['department_id'] ?? 0) ?: null;

$errors = [];
if ($ssoUsername === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $ssoUsername)) {
    $errors[] = 'กรุณากรอก username บัญชี UP Account ให้ถูกต้อง (ตัวอักษร ตัวเลข จุด ขีด เท่านั้น เช่น wittaya.su)';
}
if ($role !== null && !in_array($role, ['ADMIN', 'DEPT_STAFF', 'EXECUTIVE_VIEWER', 'DEPT_HEAD'], true)) {
    $errors[] = 'สิทธิ์ไม่ถูกต้อง';
}
if (in_array($role, ['DEPT_STAFF', 'DEPT_HEAD'], true) && $departmentId === null) {
    $errors[] = 'เจ้าหน้าที่สาขา/หัวหน้าสาขาต้องระบุสาขาด้วย';
}
if (!in_array($role, ['DEPT_STAFF', 'DEPT_HEAD'], true)) {
    $departmentId = null;
}

if (!empty($errors)) {
    bpm_flash_set('danger', implode(' / ', $errors));
    header('Location: ' . bpm_url('admin/users.php'));
    exit;
}

$db = bpm_db();

try {
    $stmt = $db->prepare(
        'INSERT INTO users (sso_username, name, email, role, department_id, is_active)
         VALUES (?, ?, ?, ?, ?, 1)'
    );
    $stmt->execute([
        $ssoUsername,
        "รอข้อมูลจาก SSO ({$ssoUsername})",
        $ssoUsername . '@pending.local',
        $role,
        $departmentId,
    ]);
    $newId = (int) $db->lastInsertId();

    bpm_audit_log(
        (int) $user['id'],
        'USER_PRE_PROVISION',
        'users',
        $newId,
        null,
        ['sso_username' => $ssoUsername, 'role' => $role, 'department_id' => $departmentId]
    );
} catch (PDOException $e) {
    bpm_flash_set('danger', str_contains($e->getMessage(), 'Duplicate') ? 'มีบัญชีนี้อยู่ในระบบแล้ว — แก้สิทธิ์ได้จากตารางด้านล่าง' : 'บันทึกไม่สำเร็จ กรุณาลองใหม่');
    header('Location: ' . bpm_url('admin/users.php'));
    exit;
}

bpm_flash_set('success', "เพิ่มบัญชี {$ssoUsername} เรียบร้อยแล้ว — พอเขา login ครั้งแรกจะได้สิทธิ์ที่ตั้งไว้ทันที ไม่ต้องรอ ADMIN กำหนดซ้ำ");
header('Location: ' . bpm_url('admin/users.php'));
exit;
