<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * เพิ่ม/แก้ไข/ปิดใช้งาน "รายละเอียดรายการงบ" กลุ่มครุภัณฑ์ (ชื่อ จำนวน หน่วย ราคาต่อหน่วย) — ADMIN เท่านั้น
 * amount = quantity x unit_price คำนวณฝั่ง server เสมอ (ไม่เชื่อค่าที่ส่งมา) — ไม่ลบจริง ใช้ is_active (ไม่ส่ง is_active = ปิดใช้งาน)
 * เป็นข้อมูลรายละเอียดประกอบงบต้นปี ไม่ใช่ธุรกรรมการเงิน ไม่กระทบยอดคงเหลือ
 */

$user = bpm_require_role('ADMIN');

$lineItemId = (int) ($_POST['line_item_id'] ?? 0);
$redirectBack = bpm_url('admin/line-item-details.php?') . http_build_query(array_filter(['item' => $lineItemId ?: null]));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirectBack);
    exit;
}
if (!bpm_csrf_verify($_POST['csrf_token'] ?? null)) {
    bpm_flash_set('danger', 'คำขอไม่ถูกต้องหรือหมดเวลา');
    header('Location: ' . $redirectBack);
    exit;
}

$id        = (int) ($_POST['id'] ?? 0);
$name      = trim((string) ($_POST['name'] ?? ''));
$qtyRaw    = str_replace(',', '', trim((string) ($_POST['quantity'] ?? '1')));
$priceRaw  = str_replace(',', '', trim((string) ($_POST['unit_price'] ?? '0')));
$unit      = trim((string) ($_POST['unit'] ?? '')) ?: null;
$note      = trim((string) ($_POST['note'] ?? '')) ?: null;
$isActive  = isset($_POST['is_active']) ? 1 : 0;

$db = bpm_db();

$itemStmt = $db->prepare(
    'SELECT li.id, li.group_id, fy.status AS fy_status
     FROM budget_line_items li JOIN fiscal_years fy ON fy.id = li.fiscal_year_id WHERE li.id = ?'
);
$itemStmt->execute([$lineItemId]);
$item = $itemStmt->fetch();

$errors = [];
if (!$item) {
    $errors[] = 'ไม่พบรายการงบ';
} else {
    if (!bpm_group_supports_details($item['group_id'] !== null ? (int) $item['group_id'] : null)) {
        $errors[] = 'เพิ่มรายละเอียดได้เฉพาะรายการงบในหมวด "ค่าครุภัณฑ์"';
    }
    if ($item['fy_status'] === 'CLOSED') {
        $errors[] = 'ปีงบนี้ถูกปิดแล้ว ไม่สามารถแก้รายละเอียดได้';
    }
}
if ($name === '') {
    $errors[] = 'กรุณากรอกชื่อรายการ';
}
if (!is_numeric($qtyRaw) || (float) $qtyRaw <= 0) {
    $errors[] = 'จำนวนต้องมากกว่า 0';
}
if (!is_numeric($priceRaw) || (float) $priceRaw < 0) {
    $errors[] = 'ราคาต่อหน่วยต้องเป็นตัวเลขไม่ติดลบ';
}
if (!empty($errors)) {
    bpm_flash_set('danger', implode(' / ', $errors));
    header('Location: ' . $redirectBack);
    exit;
}

$quantity  = round((float) $qtyRaw, 2);
$unitPrice = round((float) $priceRaw, 2);
$amount    = round($quantity * $unitPrice, 2);

try {
    $db->beginTransaction();

    if ($id > 0) {
        $before = $db->prepare('SELECT * FROM line_item_details WHERE id = ? AND line_item_id = ? FOR UPDATE');
        $before->execute([$id, $lineItemId]);
        $old = $before->fetch();
        if (!$old) {
            throw new RuntimeException('ไม่พบรายละเอียดที่ต้องการแก้ไข');
        }

        $db->prepare(
            'UPDATE line_item_details SET name = ?, quantity = ?, unit = ?, unit_price = ?, amount = ?, note = ?, is_active = ? WHERE id = ?'
        )->execute([$name, $quantity, $unit, $unitPrice, $amount, $note, $isActive, $id]);

        $oldVals = [];
        $newVals = [];
        foreach (['name' => $name, 'quantity' => $quantity, 'unit' => $unit, 'unit_price' => $unitPrice, 'amount' => $amount, 'is_active' => $isActive] as $k => $v) {
            $ov = $old[$k];
            if (is_numeric($v) && is_numeric($ov) ? (float) $ov !== (float) $v : $ov !== $v) {
                $oldVals[$k] = is_numeric($ov) ? (float) $ov : $ov;
                $newVals[$k] = $v;
            }
        }
        if (!empty($newVals)) {
            bpm_audit_log((int) $user['id'], 'LINE_ITEM_DETAIL_SAVE', 'line_item_details', $id, $oldVals + ['line_item_id' => $lineItemId], $newVals + ['line_item_id' => $lineItemId]);
        }
    } else {
        $db->prepare(
            'INSERT INTO line_item_details (line_item_id, name, quantity, unit, unit_price, amount, note, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
        )->execute([$lineItemId, $name, $quantity, $unit, $unitPrice, $amount, $note]);
        $newId = (int) $db->lastInsertId();
        bpm_audit_log((int) $user['id'], 'LINE_ITEM_DETAIL_SAVE', 'line_item_details', $newId, null, [
            'line_item_id' => $lineItemId, 'name' => $name, 'quantity' => $quantity, 'unit' => $unit, 'unit_price' => $unitPrice, 'amount' => $amount,
        ]);
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    if (!$e instanceof RuntimeException) {
        error_log('[BPM] save-line-item-detail failed: ' . $e->getMessage());
    }
    bpm_flash_set('danger', $e instanceof RuntimeException ? $e->getMessage() : 'บันทึกไม่สำเร็จ กรุณาลองใหม่');
    header('Location: ' . $redirectBack);
    exit;
}

bpm_flash_set('success', 'บันทึกรายละเอียดเรียบร้อยแล้ว');
header('Location: ' . $redirectBack);
exit;
