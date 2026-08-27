<?php

declare(strict_types=1);

/**
 * One-off repair script: แก้ชื่อสาขา/กลุ่มหมวดที่เสียเป็น "?" ถาวรใน DB
 * (เกิดจากตอน import sql/schema.sql ครั้งแรก client ไม่ได้ตั้ง charset=utf8mb4)
 *
 * รันครั้งเดียวจาก command line บนเครื่อง server เท่านั้น — ห้ามวางไว้ใต้ public/
 *   cd /path/to/bpm
 *   php scripts/fix-thai-encoding.php
 *
 * match ด้วย `code` (เป็น ASCII ไม่เสีย) ไม่ใช่ id เพื่อความชัวร์ว่าอัปเดตแถวถูกต้อง
 * ปลอดภัยรันซ้ำได้ (idempotent) — แค่ UPDATE ทับค่าที่ถูกต้องอยู่แล้วก็ไม่เป็นไร
 */

require_once __DIR__ . '/../src/lib/config.php';
require_once __DIR__ . '/../src/lib/db.php';

$departments = [
    'MICRO'     => 'สาขาวิชาจุลชีววิทยา',
    'BIOCHEM'   => 'สาขาวิชาชีวเคมี',
    'NUTRITION' => 'สาขาวิชาโภชนาการและการกำหนดอาหาร',
    'ANATOMY'   => 'สาขาวิชากายวิภาคศาสตร์',
    'PHYSIO'    => 'สาขาวิชาสรีรวิทยา',
    'OFFICE'    => 'งานบริหาร คณะวิทยาศาสตร์การแพทย์',
];

$budgetGroups = [
    'COMPENSATION' => 'ค่าตอบแทน',
    'OPERATING'    => 'ค่าใช้สอย',
    'MATERIALS'    => 'ค่าวัสดุ',
    'EQUIPMENT'    => 'ค่าครุภัณฑ์',
    'PROJECT'      => 'โครงการ',
    'OTHER'        => 'อื่นๆ',
];

$db = bpm_db();

$updateDept = $db->prepare('UPDATE departments SET name = ? WHERE code = ?');
$deptFixed = 0;
foreach ($departments as $code => $name) {
    $updateDept->execute([$name, $code]);
    $deptFixed += $updateDept->rowCount();
    echo "departments.{$code} -> {$name} (" . $updateDept->rowCount() . " row)\n";
}

$updateGroup = $db->prepare('UPDATE budget_groups SET name = ? WHERE code = ?');
$groupFixed = 0;
foreach ($budgetGroups as $code => $name) {
    $updateGroup->execute([$name, $code]);
    $groupFixed += $updateGroup->rowCount();
    echo "budget_groups.{$code} -> {$name} (" . $updateGroup->rowCount() . " row)\n";
}

echo "\nDone. departments updated: {$deptFixed}, budget_groups updated: {$groupFixed}\n";
echo "ตรวจสอบผลได้ที่ /admin/departments.php และ /admin/budget-groups.php\n";
