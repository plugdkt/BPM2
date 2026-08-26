<?php

declare(strict_types=1);

/**
 * เรียก require ที่บรรทัดแรกสุดของทุกไฟล์ใน public/*.php (ก่อนมี output ใดๆ ทั้งสิ้น)
 * ทำหน้าที่: ตั้ง timezone, เริ่ม session ด้วย cookie policy ที่ถูกต้อง, โหลด lib กลาง, ตั้ง global error handler
 */

date_default_timezone_set('Asia/Bangkok'); // ต้องตั้งจุดเดียวตรงนี้ ห้ามกระจายไปตั้งในแต่ละไฟล์ (ดู spec.md ข้อ 4)

require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/fiscal_year.php';
require_once __DIR__ . '/lib/budget.php';

$bpmConfig = bpm_config();

// session cookie params ต้องตั้งก่อน session_start() เท่านั้น — ห้ามแก้ SameSite เป็น Strict เด็ดขาด
// เพราะ sso_callback.php ต้องรับ cross-site redirect (302) จากโดเมน MEDSCI ACC (ดู spec.md ข้อ 9)
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Global error/exception handler (ดู spec.md ข้อ 9 — แยกจาก public/error.php ที่ครอบเฉพาะ SSO flow)
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e) use ($bpmConfig): void {
    error_log('[BPM] Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    if (!headers_sent()) {
        http_response_code(500);
    }

    if (!empty($bpmConfig['app']['debug'])) {
        echo '<pre>' . htmlspecialchars((string) $e, ENT_QUOTES) . '</pre>';
        return;
    }

    echo 'เกิดข้อผิดพลาดที่ไม่คาดคิด กรุณาลองใหม่อีกครั้ง หรือแจ้งผู้ดูแลระบบหากยังพบปัญหา';
});
