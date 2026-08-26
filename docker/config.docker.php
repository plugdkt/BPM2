<?php
/**
 * Config สำหรับรันใน docker compose (dev เท่านั้น) — entrypoint.sh คัดลอกไฟล์นี้เป็น config/config.php ให้อัตโนมัติ
 * ถ้าจะแก้ค่า ให้แก้ config/config.php โดยตรงหลังจากมันถูกสร้างแล้ว (ไฟล์นี้เป็นแค่ template ต้นทาง)
 */

return [
    'db' => [
        'host'    => 'db', // ชื่อ service ใน docker-compose.yml (docker internal DNS resolve ให้เอง)
        'port'    => 3306,
        'name'    => 'bpm',
        'user'    => 'bpm_app',
        'pass'    => 'bpm_app_dev_password', // ต้องตรงกับ MARIADB_PASSWORD ใน docker-compose.yml
        'charset' => 'utf8mb4',
    ],

    'sso' => [
        'client_id'     => 'BPM', // ยืนยันแล้วกับ MEDSCI ACC — ดู spec.md ข้อ 3.2
        // ห้ามใส่ client_secret จริงในไฟล์นี้เด็ดขาด (ไฟล์นี้ถูก commit เข้า git) — ใส่ในไฟล์ config/config.php ที่ .gitignore ไว้แล้วเท่านั้น
        'client_secret' => 'CHANGE_ME',
        // ต้องลงทะเบียน URL นี้เพิ่มไว้กับ MEDSCI ACC ด้วย (คั่นด้วย , ร่วมกับ URL production ได้ตามคู่มือ SSO ข้อ 1)
        'redirect_uri'  => 'http://localhost:8080/sso_callback.php',
        'login_url'     => 'https://www.medsci.up.ac.th/msc_acc/sso/login.php',
        'verify_url'    => 'https://www.medsci.up.ac.th/msc_acc/api/verify.php',
        'logout_url'    => 'https://www.medsci.up.ac.th/msc_acc/sso/logout.php',
    ],

    'sso_ssl_verify' => true,

    'session' => [
        'idle_timeout_seconds' => 1800,
    ],

    'app' => [
        'timezone' => 'Asia/Bangkok',
        'debug'    => true, // เปิดบน docker dev เท่านั้น — เห็น stack trace ตรงๆ ช่วย debug เร็วขึ้น ห้ามเปิดบน production
    ],
];
