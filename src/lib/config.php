<?php

declare(strict_types=1);

/**
 * โหลด config/config.php ครั้งเดียวต่อ request (memoized)
 * ห้าม require config.php ตรงๆ จากที่อื่น — ให้เรียกผ่านฟังก์ชันนี้เท่านั้น
 */
function bpm_config(): array
{
    static $config = null;

    if ($config === null) {
        $path = __DIR__ . '/../../config/config.php';
        if (!file_exists($path)) {
            http_response_code(500);
            die('ไม่พบไฟล์ config/config.php — คัดลอกจาก config/config.example.php แล้วกรอกค่าจริงก่อนใช้งาน');
        }
        $config = require $path;
    }

    return $config;
}

/**
 * คำนวณ Base URL หรือ Path ให้ตรงกับโฟลเดอร์ที่ deploy จริง
 */
function bpm_url(string $path = ''): string
{
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }

    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $scriptDir = str_replace('\\', '/', dirname($scriptName));

    if (str_ends_with($scriptDir, '/admin') || str_ends_with($scriptDir, '/actions')) {
        $scriptDir = str_replace('\\', '/', dirname($scriptDir));
    }

    if ($scriptDir === '/' || $scriptDir === '.') {
        $scriptDir = '';
    }

    $path = '/' . ltrim($path, '/');
    return $scriptDir . $path;
}

