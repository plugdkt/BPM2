<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * ตั้ง/แก้/ยกเลิกวงเงินแหล่งเงินต่อปีงบ — ระดับแหล่งเงินทั้งก้อน (group_id = 0) หรือระดับหมวดงบภายใต้แหล่งนั้น
 * amount ว่าง = ยกเลิกวงเงินที่ตั้งไว้ (เก็บ snapshot ลง audit_logs) — เป็นข้อมูลวางแผน ไม่ใช่ธุรกรรมการเงิน
 * วงเงินใช้เทียบกับยอดที่จัดสรรให้สาขาและเตือนเมื่อเกิน ไม่ block การบันทึกรายการงบ
 */

$user = bpm_require_role('ADMIN');

$fiscalYearId = (int) ($_POST['fiscal_year_id'] ?? 0);
$redirectBack = bpm_url('admin/fund-budgets.php?') . http_build_query(array_filter(['fy' => $fiscalYearId ?: null, 'source' => (int) ($_POST['fund_source_id'] ?? 0) ?: null]));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirectBack);
    exit;
}
if (!bpm_csrf_verify($_POST['csrf_token'] ?? null)) {
    bpm_flash_set('danger', 'คำขอไม่ถูกต้องหรือหมดเวลา');
    header('Location: ' . $redirectBack);
    exit;
}

$fundSourceId = (int) ($_POST['fund_source_id'] ?? 0);
$groupId      = (int) ($_POST['group_id'] ?? 0); // 0 = วงเงินทั้งแหล่งเงิน
$departmentId = (int) ($_POST['department_id'] ?? 0); // > 0 = วงเงินที่สาขานั้นได้รับจากแหล่งเงินนี้ (ไม่สนใจ group_id)
$amountRaw    = trim(str_replace(',', '', (string) ($_POST['amount'] ?? '')));

$db = bpm_db();

$fyStmt = $db->prepare('SELECT status FROM fiscal_years WHERE id = ?');
$fyStmt->execute([$fiscalYearId]);
$fyStatus = $fyStmt->fetchColumn();

$srcStmt = $db->prepare("SELECT COUNT(*) FROM fund_sources WHERE id = ? AND code <> 'UNSPECIFIED'");
$srcStmt->execute([$fundSourceId]);

$grpOk = true;
if ($departmentId > 0) {
    $dp = $db->prepare('SELECT COUNT(*) FROM departments WHERE id = ?');
    $dp->execute([$departmentId]);
    $grpOk = (int) $dp->fetchColumn() > 0;
    $groupId = 0;
} elseif ($groupId > 0) {
    $g = $db->prepare('SELECT COUNT(*) FROM budget_groups WHERE id = ?');
    $g->execute([$groupId]);
    $grpOk = (int) $g->fetchColumn() > 0;
}

if ($fyStatus === false || (int) $srcStmt->fetchColumn() === 0 || !$grpOk) {
    bpm_flash_set('danger', 'ข้อมูลไม่ถูกต้อง (ไม่พบปีงบ/แหล่งเงิน/หมวดงบ — แหล่งเงิน "ไม่ระบุ" ตั้งวงเงินไม่ได้)');
    header('Location: ' . $redirectBack);
    exit;
}
if ($fyStatus === 'CLOSED') {
    bpm_flash_set('danger', 'ปีงบนี้ถูกปิดแล้ว ไม่สามารถแก้วงเงินได้');
    header('Location: ' . $redirectBack);
    exit;
}
if ($amountRaw !== '' && (!is_numeric($amountRaw) || (float) $amountRaw < 0)) {
    bpm_flash_set('danger', 'วงเงินต้องเป็นตัวเลขไม่ติดลบ (เว้นว่างเพื่อยกเลิกวงเงิน)');
    header('Location: ' . $redirectBack);
    exit;
}

if ($departmentId > 0) {
    [$table, $keyCols, $keyVals] = ['fund_dept_budgets', 'fiscal_year_id = ? AND fund_source_id = ? AND department_id = ?', [$fiscalYearId, $fundSourceId, $departmentId]];
} elseif ($groupId > 0) {
    [$table, $keyCols, $keyVals] = ['fund_group_budgets', 'fiscal_year_id = ? AND fund_source_id = ? AND group_id = ?', [$fiscalYearId, $fundSourceId, $groupId]];
} else {
    [$table, $keyCols, $keyVals] = ['fund_source_budgets', 'fiscal_year_id = ? AND fund_source_id = ?', [$fiscalYearId, $fundSourceId]];
}

try {
    $db->beginTransaction();

    $cur = $db->prepare("SELECT id, amount FROM {$table} WHERE {$keyCols} FOR UPDATE");
    $cur->execute($keyVals);
    $existing = $cur->fetch();
    $target = ['fiscal_year_id' => $fiscalYearId, 'fund_source_id' => $fundSourceId, 'group_id' => $groupId, 'department_id' => $departmentId];

    if ($amountRaw === '') {
        if ($existing) {
            $db->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$existing['id']]);
            bpm_audit_log((int) $user['id'], 'FUND_BUDGET_CLEAR', $table, (int) $existing['id'], $target + ['amount' => (float) $existing['amount']], null);
        }
    } elseif ($existing) {
        if ((float) $existing['amount'] !== (float) $amountRaw) {
            $db->prepare("UPDATE {$table} SET amount = ? WHERE id = ?")->execute([(float) $amountRaw, $existing['id']]);
            bpm_audit_log((int) $user['id'], 'FUND_BUDGET_SET', $table, (int) $existing['id'], ['amount' => (float) $existing['amount']], ['amount' => (float) $amountRaw]);
        }
    } else {
        if ($departmentId > 0) {
            $db->prepare('INSERT INTO fund_dept_budgets (fiscal_year_id, fund_source_id, department_id, amount) VALUES (?, ?, ?, ?)')
               ->execute([$fiscalYearId, $fundSourceId, $departmentId, (float) $amountRaw]);
        } elseif ($groupId > 0) {
            $db->prepare('INSERT INTO fund_group_budgets (fiscal_year_id, fund_source_id, group_id, amount) VALUES (?, ?, ?, ?)')
               ->execute([$fiscalYearId, $fundSourceId, $groupId, (float) $amountRaw]);
        } else {
            $db->prepare('INSERT INTO fund_source_budgets (fiscal_year_id, fund_source_id, amount) VALUES (?, ?, ?)')
               ->execute([$fiscalYearId, $fundSourceId, (float) $amountRaw]);
        }
        bpm_audit_log((int) $user['id'], 'FUND_BUDGET_SET', $table, (int) $db->lastInsertId(), null, $target + ['amount' => (float) $amountRaw]);
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('[BPM] save-fund-budget failed: ' . $e->getMessage());
    bpm_flash_set('danger', 'บันทึกวงเงินไม่สำเร็จ กรุณาลองใหม่');
    header('Location: ' . $redirectBack);
    exit;
}

$warnings = bpm_fund_overage_warnings($fiscalYearId, $fundSourceId, $groupId > 0 ? $groupId : null, $departmentId > 0 ? $departmentId : null);
if (!empty($warnings)) {
    bpm_flash_set('warning', 'บันทึกวงเงินแล้ว แต่ ' . implode(' / ', $warnings));
} else {
    bpm_flash_set('success', 'บันทึกวงเงินเรียบร้อยแล้ว');
}
header('Location: ' . $redirectBack);
exit;
