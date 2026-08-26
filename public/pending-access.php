<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$user = bpm_require_login(); // ต้อง login แล้ว แค่ role ยังเป็น NULL — bpm_require_login() พอ ไม่ใช่ bpm_require_role()

// ถ้า ADMIN กำหนดสิทธิ์ให้แล้วตั้งแต่รอบก่อน ไม่ต้องมาเห็นหน้านี้อีก
if ($user['role'] !== null) {
    header('Location: /index.php');
    exit;
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>รอกำหนดสิทธิ์การใช้งาน — BPM</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="page-login">
  <div class="login-card">
    <h1>รอผู้ดูแลระบบกำหนดสิทธิ์การใช้งาน</h1>
    <p class="text-muted">
      บัญชี <strong><?= htmlspecialchars($user['name'], ENT_QUOTES) ?></strong> เข้าสู่ระบบสำเร็จแล้ว
      แต่ยังไม่ได้รับการกำหนดสิทธิ์ใช้งานในระบบ BPM
    </p>
    <p class="text-muted">กรุณาติดต่อผู้ดูแลระบบเพื่อขอกำหนดสิทธิ์ (บทบาท + สาขาวิชา) ก่อนเข้าใช้งาน</p>
    <a class="btn btn-secondary btn-block" href="/logout.php">ออกจากระบบ</a>
  </div>
</body>
</html>
