<?php
/**
 * คัดลอกไฟล์นี้เป็น config.php (อยู่ในโฟลเดอร์เดียวกัน) แล้วกรอกค่าจริง
 * config.php ถูก .gitignore ไว้แล้ว ห้าม commit เข้า git เด็ดขาด (มี DB password + SSO client secret)
 */

return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'bpm',
        'user'    => 'bpm_app',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    'sso' => [
        // ได้จากการลงทะเบียนที่ https://www.medsci.up.ac.th/msc_acc/admin/clients.php (ดู spec.md ข้อ 3.2)
        'client_id'     => 'BPM', // ยืนยันแล้วกับ MEDSCI ACC — ดู spec.md ข้อ 3.2
        'client_secret' => 'CHANGE_ME', // ขอค่าจริงจากทีม/ผู้ดูแล — ห้าม commit ค่าจริงเข้า git เด็ดขาด
        'redirect_uri'  => 'https://www.medsci.up.ac.th/bpm/sso_callback.php', // ต้องตรงกับที่ลงทะเบียนไว้เป๊ะ
        'login_url'     => 'https://www.medsci.up.ac.th/msc_acc/sso/login.php',
        'verify_url'    => 'https://www.medsci.up.ac.th/msc_acc/api/verify.php',
        'logout_url'    => 'https://www.medsci.up.ac.th/msc_acc/sso/logout.php', // GET redirect เท่านั้น ไม่ใช่ cURL (ดู spec.md ข้อ 3.7)
    ],

    // ค่าเริ่มต้น true คือค่าที่ถูกต้องแล้ว — ต้องเป็น true บน production เสมอ (ดู spec.md ข้อ 3.6)
    // เปลี่ยนเป็น false ได้เฉพาะเครื่อง dev ที่เจอปัญหา cert เท่านั้น ห้ามลืมเปลี่ยนกลับก่อน deploy จริง
    'sso_ssl_verify' => true,

    'session' => [
        'idle_timeout_seconds' => 1800, // 30 นาที (ดู spec.md ข้อ 9)
    ],

    'app' => [
        'timezone' => 'Asia/Bangkok',
        'debug'    => false, // true เฉพาะ dev — คุม display_errors ผ่านตัวนี้ ไม่ใช้ php.ini ตรงๆ
    ],
];
