<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

/**
 * รับ callback จาก MEDSCI ACC หลัง login สำเร็จ — ดู flow เต็มใน spec.md ข้อ 3.3/3.4
 * ผู้ใช้ไม่เห็นหน้านี้ค้างอยู่เลย (ทุก path จบด้วย redirect ทันที — PRG pattern กัน token ค้างใน browser history)
 */

// 1) ตรวจ state ก่อนทำอะไรทั้งสิ้น (ป้องกัน login CSRF — ดู spec.md ข้อ 3.6)
$stateFromRequest = $_GET['state'] ?? null;
if (!bpm_sso_validate_and_consume_state($stateFromRequest)) {
    header('Location: ' . bpm_url('error.php?type=state_invalid'));
    exit;
}

// 2) ต้องมี token แนบมา
$token = trim($_GET['token'] ?? '');
if ($token === '') {
    header('Location: ' . bpm_url('error.php?type=token_missing'));
    exit;
}

// 3) verify token กับ MEDSCI ACC (POST เดียว ห้าม retry เพราะ token ใช้ได้ครั้งเดียว — ดู spec.md ข้อ 3.6)
$result = bpm_sso_verify_token($token);

if ($result['network_error']) {
    header('Location: ' . bpm_url('error.php?type=verify_unreachable'));
    exit;
}

if (!$result['ok']) {
    header('Location: ' . bpm_url('error.php?type=not_authorized&message=' . urlencode((string) $result['message'])));
    exit;
}

// 4) จับคู่/สร้างผู้ใช้ local แล้วเริ่ม session (session_regenerate_id ทำอยู่ข้างในนี้ — ดู spec.md ข้อ 3.3)
$user = bpm_sso_provision_user($result['user']);
bpm_start_authenticated_session((int) $user['id']);

// 5) พาไปหน้าที่เหมาะสมตามสิทธิ์ (role ยังไม่ถูกกำหนด -> รอสิทธิ์)
if ($user['role'] === null) {
    header('Location: ' . bpm_url('pending-access.php'));
    exit;
}

header('Location: ' . bpm_url('index.php'));
exit;
