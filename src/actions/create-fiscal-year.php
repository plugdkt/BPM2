<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$user = bpm_require_role('ADMIN');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . bpm_url(''));
    exit;
}
if (!bpm_csrf_verify($_POST['csrf_token'] ?? null)) {
    bpm_flash_set('danger', 'คำขอไม่ถูกต้องหรือหมดเวลา');
    header('Location: ' . bpm_url(''));
    exit;
}

$yearBe = (int) ($_POST['year_be'] ?? 0);
if ($yearBe < 2500 || $yearBe > 2700) {
    bpm_flash_set('danger', 'ปี พ.ศ. ไม่ถูกต้อง');
    header('Location: ' . bpm_url(''));
    exit;
}

$range = bpm_fiscal_year_range($yearBe);

try {
    $stmt = bpm_db()->prepare("INSERT INTO fiscal_years (year_be, start_date, end_date, status) VALUES (?, ?, ?, 'OPEN')");
    $stmt->execute([$yearBe, $range['start_date'], $range['end_date']]);
} catch (PDOException $e) {
    bpm_flash_set('danger', str_contains($e->getMessage(), 'Duplicate') ? 'ปีงบนี้มีอยู่แล้ว' : 'บันทึกไม่สำเร็จ');
    header('Location: ' . bpm_url(''));
    exit;
}

bpm_flash_set('success', "สร้างปีงบประมาณ พ.ศ. {$yearBe} เรียบร้อยแล้ว (" . bpm_thai_date($range['start_date']) . ' – ' . bpm_thai_date($range['end_date']) . ')');
header('Location: ' . bpm_url(''));
exit;

