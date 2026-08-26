<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

/**
 * หน้า error กลางสำหรับ SSO flow (ดู spec.md ข้อ 3.8) — แทนที่ die()/echo ดิบๆ ในโค้ดตัวอย่างของคู่มือ SSO
 * ทุก error ที่มาถึงหน้านี้ต้อง log รายละเอียดจริงไว้แล้วตั้งแต่ต้นทาง (sso_callback.php/auth.php)
 * ที่นี่มีหน้าที่แค่แสดงข้อความที่เข้าใจง่ายให้ผู้ใช้ ไม่ใช่จุด log
 */

$messages = [
    'state_invalid'      => 'คำขอเข้าสู่ระบบไม่ถูกต้องหรือหมดเวลา กรุณาเข้าสู่ระบบใหม่อีกครั้ง',
    'token_missing'      => 'คำขอเข้าสู่ระบบไม่ถูกต้องหรือหมดเวลา กรุณาเข้าสู่ระบบใหม่อีกครั้ง',
    'verify_unreachable' => 'ไม่สามารถเชื่อมต่อระบบยืนยันตัวตนกลางได้ในขณะนี้ กรุณาลองใหม่ภายหลัง หรือแจ้งผู้ดูแลระบบ',
];

$type = $_GET['type'] ?? '';

if ($type === 'not_authorized' && !empty($_GET['message'])) {
    $displayMessage = $_GET['message']; // มาจาก MEDSCI ACC โดยตรง — escape ตอน output ด้านล่างด้วย htmlspecialchars เสมอ
} else {
    $displayMessage = $messages[$type] ?? 'เกิดข้อผิดพลาดในการเข้าสู่ระบบ กรุณาลองใหม่อีกครั้ง';
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>เข้าสู่ระบบไม่สำเร็จ — BPM</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="page-login">
  <div class="login-card">
    <h1>เข้าสู่ระบบไม่สำเร็จ</h1>
    <p class="alert alert-danger"><?= htmlspecialchars($displayMessage, ENT_QUOTES) ?></p>
    <a class="btn btn-primary btn-block" href="/login.php">ลองเข้าสู่ระบบใหม่</a>
  </div>
</body>
</html>
