<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

/**
 * Logout สมบูรณ์ 2 ขั้นตอน (ดู spec.md ข้อ 3.7):
 *   1. เคลียร์ session ฝั่ง BPM ก่อนเสมอ
 *   2. ส่งต่อไป Single Logout endpoint ของ MEDSCI ACC เพื่อเคลียร์ session ฝั่งระบบกลางด้วย
 *      (เป็น browser GET redirect ธรรมดา ไม่ใช่ server-to-server call — ไม่มี error branch ต้องจัดการฝั่งนี้)
 */

$logoutUrl = bpm_sso_logout_redirect_url();

$_SESSION = [];
session_destroy();

header('Location: ' . $logoutUrl);
exit;
