<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * บันทึก/แก้ไขตารางแบบ lookup ง่ายๆ ที่มีรูปร่างเดียวกัน (id, name, code, is_active)
 * ใช้ร่วมกันระหว่าง departments และ budget_groups — ตารางอื่นที่โครงสร้างต่างออกไป (fiscal_years, budget_line_items) มี action แยกของตัวเอง
 * ห้าม hard delete เด็ดขาด (ดู spec.md ข้อ 5.3) — มีแค่ toggle is_active
 */

$user = bpm_require_role('ADMIN');

$allowedTables = [
    'departments'   => '/admin/departments.php',
    'budget_groups' => '/admin/budget-groups.php',
    'fund_sources'  => '/admin/fund-sources.php',
];

$table = (string) ($_POST['table'] ?? '');
if (!isset($allowedTables[$table])) {
    http_response_code(400);
    die('invalid table');
}
$redirectBack = $allowedTables[$table];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirectBack);
    exit;
}

if (!bpm_csrf_verify($_POST['csrf_token'] ?? null)) {
    bpm_flash_set('danger', 'คำขอไม่ถูกต้องหรือหมดเวลา กรุณาลองใหม่อีกครั้ง');
    header('Location: ' . $redirectBack);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$name = trim((string) ($_POST['name'] ?? ''));
$code = strtoupper(trim((string) ($_POST['code'] ?? '')));
$isActive = isset($_POST['is_active']) ? 1 : 0;

if ($name === '' || $code === '') {
    bpm_flash_set('danger', 'กรุณากรอกชื่อและรหัสให้ครบ');
    header('Location: ' . $redirectBack);
    exit;
}

$db = bpm_db();

// แหล่งเงินเริ่มต้น "ไม่ระบุ" เป็น DEFAULT ของรายการงบทุกแถว — ห้ามเปลี่ยนรหัสหรือปิดการใช้งาน
if ($table === 'fund_sources' && $id > 0) {
    $cur = $db->prepare('SELECT code FROM fund_sources WHERE id = ?');
    $cur->execute([$id]);
    if ($cur->fetchColumn() === 'UNSPECIFIED' && ($code !== 'UNSPECIFIED' || $isActive !== 1)) {
        bpm_flash_set('danger', 'แหล่งเงิน "ไม่ระบุ" เป็นค่าเริ่มต้นของระบบ เปลี่ยนรหัสหรือปิดการใช้งานไม่ได้ (แก้ชื่อได้)');
        header('Location: ' . $redirectBack);
        exit;
    }
}

try {
    if ($id > 0) {
        $stmt = $db->prepare("UPDATE {$table} SET name = ?, code = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$name, $code, $isActive, $id]);
    } else {
        $stmt = $db->prepare("INSERT INTO {$table} (name, code, is_active) VALUES (?, ?, ?)");
        $stmt->execute([$name, $code, $isActive]);
    }
} catch (PDOException $e) {
    bpm_flash_set('danger', str_contains($e->getMessage(), 'Duplicate') ? 'รหัสนี้ถูกใช้ไปแล้ว' : 'บันทึกไม่สำเร็จ กรุณาลองใหม่');
    header('Location: ' . $redirectBack);
    exit;
}

bpm_flash_set('success', 'บันทึกเรียบร้อยแล้ว');
header('Location: ' . $redirectBack);
exit;
