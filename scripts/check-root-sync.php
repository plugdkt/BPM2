<?php

declare(strict_types=1);

/**
 * ตรวจว่าไฟล์ที่ IIS ใช้ (root เป็น site root) ครบและตรงกับ public/ — ก่อน commit/deploy ควรรันทุกครั้งที่เพิ่ม/แก้หน้าหรือ CSS
 * (เคยพลาด 2 ครั้ง: ลืม wrapper → 404 บน production, และสำเนา assets/css/app.css ที่ root ไม่ตรงกับ public/ → หน้าตาเพี้ยน)
 *
 *   1) public/*.php และ public/admin/*.php ต้องมี wrapper ที่ root (`require __DIR__ . '/public/...'`) ที่ชี้ไฟล์เดียวกัน
 *   2) src/actions/*.php ต้องมี public/actions/*.php และ actions/*.php (wrapper ซ้อนสองชั้น)
 *   3) assets/ ที่ root ต้องเป็นสำเนาตรงกับ public/assets/ (ไฟล์เดียวกันเป๊ะ — ต้อง cp ทุกครั้งที่แก้ CSS)
 *
 *   php scripts/check-root-sync.php          # รายงานอย่างเดียว (exit 1 ถ้ามีปัญหา)
 *   php scripts/check-root-sync.php --fix    # คัดลอก assets ที่ไม่ตรงให้ (wrapper ที่ขาดต้องสร้างเอง — รายงานชื่อไฟล์ให้)
 */

$root = realpath(__DIR__ . '/..');
$fix = in_array('--fix', $argv ?? [], true);
$problems = [];

$wrapperOk = static function (string $wrapper, string $target) use ($root): ?string {
    $path = $root . '/' . $wrapper;
    if (!is_file($path)) {
        return "ไม่มี wrapper {$wrapper}";
    }
    if (!str_contains((string) file_get_contents($path), $target)) {
        return "{$wrapper} ไม่ได้ชี้ไปที่ {$target}";
    }
    return null;
};

// 1) หน้า public/*.php → root
foreach (glob($root . '/public/*.php') ?: [] as $f) {
    $name = basename($f);
    if ($err = $wrapperOk($name, "/public/{$name}")) {
        $problems[] = $err;
    }
}
foreach (glob($root . '/public/admin/*.php') ?: [] as $f) {
    $name = basename($f);
    if ($err = $wrapperOk("admin/{$name}", "/public/admin/{$name}")) {
        $problems[] = $err;
    }
}

// 2) actions: src/actions → public/actions → actions
foreach (glob($root . '/src/actions/*.php') ?: [] as $f) {
    $name = basename($f);
    if ($err = $wrapperOk("public/actions/{$name}", "/../../src/actions/{$name}")) {
        $problems[] = $err;
    }
    if ($err = $wrapperOk("actions/{$name}", "/../public/actions/{$name}")) {
        $problems[] = $err;
    }
}

// 3) assets: root ต้องตรง public
$assetFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/public/assets', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->isFile()) {
        $assetFiles[] = substr($file->getPathname(), strlen($root . '/public/'));
    }
}
foreach ($assetFiles as $rel) {
    $src = $root . '/public/' . $rel;
    $dst = $root . '/' . $rel;
    $same = is_file($dst) && md5_file($src) === md5_file($dst)
        // ไม่สนใจต่างกันแค่ CRLF/LF
        || (is_file($dst) && md5(str_replace("\r\n", "\n", (string) file_get_contents($src))) === md5(str_replace("\r\n", "\n", (string) file_get_contents($dst))));
    if (!$same) {
        if ($fix) {
            @mkdir(dirname($dst), 0775, true);
            copy($src, $dst);
            echo "  คัดลอก public/{$rel} -> {$rel}\n";
        } else {
            $problems[] = "{$rel} ที่ root ไม่ตรงกับ public/{$rel} (รัน --fix หรือ cp ทับ)";
        }
    }
}

if (empty($problems)) {
    echo "OK — wrapper และ assets ที่ root ครบและตรงกับ public/\n";
    exit(0);
}
echo "พบปัญหา " . count($problems) . " รายการ:\n  - " . implode("\n  - ", $problems) . "\n";
exit(1);
