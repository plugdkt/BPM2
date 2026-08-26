<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

// ถ้า login อยู่แล้วไม่ต้องเห็นหน้านี้ซ้ำ
if (bpm_current_user() !== null) {
    header('Location: /index.php');
    exit;
}

// กดปุ่ม "เข้าสู่ระบบด้วยบัญชี UP Account" -> ?start=1 -> สร้าง state แล้ว redirect ไป MEDSCI ACC ทันที
// (แยกจาก GET ธรรมดาที่แค่แสดงหน้า เพื่อไม่ให้การเปิด/reload หน้า login เฉยๆ ไปสุ่ม state ทิ้งโดยไม่จำเป็น)
if (isset($_GET['start'])) {
    header('Location: ' . bpm_sso_login_redirect_url());
    exit;
}

$reason = $_GET['reason'] ?? null;
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>เข้าสู่ระบบ — BPM</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="page-login">
  <div class="login-card">
    <h1>เข้าสู่ระบบ BPM</h1>
    <p class="text-muted">ใช้บัญชี UP Account เดียวกับระบบอื่นของมหาวิทยาลัย ไม่ต้องตั้งรหัสผ่านชุดใหม่</p>

    <?php if ($reason === 'idle_timeout'): ?>
      <p class="alert alert-warning">เซสชันหมดอายุเนื่องจากไม่มีการใช้งาน กรุณาเข้าสู่ระบบใหม่อีกครั้ง</p>
    <?php endif; ?>

    <a class="btn btn-primary btn-block" href="/login.php?start=1">
      เข้าสู่ระบบด้วยบัญชี UP Account
    </a>

    <p class="text-muted small">ยืนยันตัวตนผ่านระบบกลางของคณะ (MEDSCI ACC)</p>
  </div>
</body>
</html>
