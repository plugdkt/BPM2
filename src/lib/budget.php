<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * คำนวณยอดคงเหลือ — จุดเดียวที่ทุกหน้าต้องเรียกใช้ (ดู spec.md ข้อ 5.1)
 * ห้าม copy query คำนวณยอดไปเขียนซ้ำที่อื่นเด็ดขาด เพื่อไม่ให้ตัวเลขไม่ตรงกันระหว่างหน้า
 */

/**
 * ยอดคงเหลือของ line item หนึ่งรายการแบบสด (ไม่มี cache)
 * ถ้าเรียกใน context ที่ต้อง lock แถวไว้ก่อน (บันทึกรายการ/อนุมัติโอนย้าย) ให้ส่ง $forUpdate = true
 * และต้องอยู่ใน DB transaction ที่เปิดไว้แล้วเท่านั้น (ดู spec.md ข้อ 5.2)
 */
function bpm_line_item_balance(int $lineItemId, bool $forUpdate = false): array
{
    $db = bpm_db();

    $sql = 'SELECT * FROM budget_line_items WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $db->prepare($sql);
    $stmt->execute([$lineItemId]);
    $item = $stmt->fetch();

    if (!$item) {
        throw new InvalidArgumentException("line item {$lineItemId} not found");
    }

    $transferIn = $db->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM budget_transfers WHERE to_line_item_id = ? AND status = 'APPROVED'"
    );
    $transferIn->execute([$lineItemId]);

    $transferOut = $db->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM budget_transfers WHERE from_line_item_id = ? AND status = 'APPROVED'"
    );
    $transferOut->execute([$lineItemId]);

    $expense = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE line_item_id = ? AND type = 'EXPENSE'");
    $expense->execute([$lineItemId]);

    $income = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE line_item_id = ? AND type = 'INCOME'");
    $income->execute([$lineItemId]);

    $startingAmount = (float) $item['starting_amount'];
    $transferInAmt  = (float) $transferIn->fetchColumn();
    $transferOutAmt = (float) $transferOut->fetchColumn();
    $expenseAmt     = (float) $expense->fetchColumn();
    $incomeAmt      = (float) $income->fetchColumn();

    $totalBudget = $startingAmount + $transferInAmt - $transferOutAmt;
    $balance     = $totalBudget - $expenseAmt + $incomeAmt;

    return [
        'line_item'       => $item,
        'starting_amount' => $startingAmount,
        'transfer_in'     => $transferInAmt,
        'transfer_out'    => $transferOutAmt,
        'total_budget'    => $totalBudget,
        'expense'         => $expenseAmt,
        'income'          => $incomeAmt,
        'balance'         => $balance,
    ];
}

/** รายการ line item ที่ยัง active ของสาขา+ปีงบหนึ่ง เรียงตามชื่อ — ใช้ประกอบ dropdown/autocomplete */
function bpm_line_items_for_department(int $departmentId, int $fiscalYearId): array
{
    $stmt = bpm_db()->prepare(
        'SELECT * FROM budget_line_items
         WHERE department_id = ? AND fiscal_year_id = ? AND is_active = 1
         ORDER BY name'
    );
    $stmt->execute([$departmentId, $fiscalYearId]);
    return $stmt->fetchAll();
}

/**
 * สรุปยอดรวมของสาขาหนึ่ง (หรือทุกสาขาถ้า $departmentId = null) ในปีงบหนึ่ง — ใช้ทำ KPI cards บน dashboard
 * คำนวณจาก SUM ของทุก line item ตรงๆ ไม่ได้เรียก bpm_line_item_balance() วนลูปเพื่อลดจำนวน query
 */
function bpm_department_summary(?int $departmentId, int $fiscalYearId): array
{
    $db = bpm_db();
    $params = [$fiscalYearId];
    $deptFilter = '';
    if ($departmentId !== null) {
        $deptFilter = ' AND li.department_id = ?';
        $params[] = $departmentId;
    }

    $stmt = $db->prepare(
        "SELECT
            COALESCE(SUM(li.starting_amount), 0) AS starting_total,
            COALESCE(SUM(ti.amt), 0)  AS transfer_in_total,
            COALESCE(SUM(t_out.amt), 0) AS transfer_out_total,
            COALESCE(SUM(tx_exp.amt), 0) AS expense_total,
            COALESCE(SUM(tx_inc.amt), 0) AS income_total
         FROM budget_line_items li
         LEFT JOIN (
            SELECT to_line_item_id AS id, SUM(amount) AS amt FROM budget_transfers WHERE status = 'APPROVED' GROUP BY to_line_item_id
         ) ti ON ti.id = li.id
         LEFT JOIN (
            SELECT from_line_item_id AS id, SUM(amount) AS amt FROM budget_transfers WHERE status = 'APPROVED' GROUP BY from_line_item_id
         ) t_out ON t_out.id = li.id
         LEFT JOIN (
            SELECT line_item_id AS id, SUM(amount) AS amt FROM transactions WHERE type = 'EXPENSE' GROUP BY line_item_id
         ) tx_exp ON tx_exp.id = li.id
         LEFT JOIN (
            SELECT line_item_id AS id, SUM(amount) AS amt FROM transactions WHERE type = 'INCOME' GROUP BY line_item_id
         ) tx_inc ON tx_inc.id = li.id
         WHERE li.fiscal_year_id = ? AND li.is_active = 1{$deptFilter}"
    );
    $stmt->execute($params);
    $row = $stmt->fetch();

    $totalBudget = (float) $row['starting_total'] + (float) $row['transfer_in_total'] - (float) $row['transfer_out_total'];
    $spent       = (float) $row['expense_total'] - (float) $row['income_total'];
    $balance     = $totalBudget - $spent;
    $spentPct    = $totalBudget > 0 ? ($spent / $totalBudget) * 100 : 0.0;

    return [
        'total_budget' => $totalBudget,
        'spent'        => $spent,
        'balance'      => $balance,
        'spent_pct'    => $spentPct,
    ];
}

/** ยอดเบิกจ่ายสุทธิ (EXPENSE - INCOME) แยกตามไตรมาส ของสาขา(หรือทุกสาขา)+ปีงบหนึ่ง — คืน [1=>amt, 2=>amt, 3=>amt, 4=>amt] */
function bpm_quarterly_spend(?int $departmentId, int $fiscalYearId): array
{
    require_once __DIR__ . '/fiscal_year.php';

    $db = bpm_db();
    $params = [$fiscalYearId];
    $deptFilter = '';
    if ($departmentId !== null) {
        $deptFilter = ' AND li.department_id = ?';
        $params[] = $departmentId;
    }

    $stmt = $db->prepare(
        "SELECT t.txn_date, t.type, t.amount
         FROM transactions t
         JOIN budget_line_items li ON li.id = t.line_item_id
         WHERE li.fiscal_year_id = ?{$deptFilter}"
    );
    $stmt->execute($params);

    $result = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0];
    foreach ($stmt->fetchAll() as $row) {
        $q = bpm_fiscal_quarter($row['txn_date']);
        $signed = $row['type'] === 'EXPENSE' ? (float) $row['amount'] : -(float) $row['amount'];
        $result[$q] += $signed;
    }

    return $result;
}

/** จำนวนกลุ่มหมวด (budget_groups) เทียบ จัดสรร vs เบิกจ่ายแล้ว ของสาขา(หรือทุกสาขา)+ปีงบหนึ่ง — ใช้ทำกราฟแท่งบน dashboard */
function bpm_group_comparison(?int $departmentId, int $fiscalYearId): array
{
    $db = bpm_db();
    $params = [$fiscalYearId];
    $deptFilter = '';
    if ($departmentId !== null) {
        $deptFilter = ' AND li.department_id = ?';
        $params[] = $departmentId;
    }

    $stmt = $db->prepare(
        "SELECT
            g.id, g.name,
            COALESCE(SUM(li.starting_amount), 0) AS allocated,
            COALESCE(SUM(tx_exp.amt), 0) - COALESCE(SUM(tx_inc.amt), 0) AS spent
         FROM budget_groups g
         JOIN budget_line_items li ON li.group_id = g.id AND li.fiscal_year_id = ?{$deptFilter}
         LEFT JOIN (
            SELECT line_item_id AS id, SUM(amount) AS amt FROM transactions WHERE type = 'EXPENSE' GROUP BY line_item_id
         ) tx_exp ON tx_exp.id = li.id
         LEFT JOIN (
            SELECT line_item_id AS id, SUM(amount) AS amt FROM transactions WHERE type = 'INCOME' GROUP BY line_item_id
         ) tx_inc ON tx_inc.id = li.id
         WHERE g.is_active = 1 AND li.is_active = 1
         GROUP BY g.id, g.name
         ORDER BY g.id"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** รายการเบิกจ่าย/รายรับล่าสุด (เรียงวันที่ใหม่สุดก่อน) — ใช้ทำตาราง "รายการล่าสุด" บน dashboard */
function bpm_recent_transactions(?int $departmentId, int $fiscalYearId, int $limit = 5): array
{
    $db = bpm_db();
    $params = [$fiscalYearId];
    $deptFilter = '';
    if ($departmentId !== null) {
        $deptFilter = ' AND li.department_id = ?';
        $params[] = $departmentId;
    }
    $limit = max(1, min(200, $limit)); // clamp เอง แล้วค่อย interpolate ตรงๆ เพราะ PDO bind LIMIT ไม่เสถียรทุก driver

    $stmt = $db->prepare(
        "SELECT t.*, li.name AS line_item_name, d.name AS department_name
         FROM transactions t
         JOIN budget_line_items li ON li.id = t.line_item_id
         JOIN departments d ON d.id = li.department_id
         WHERE li.fiscal_year_id = ?{$deptFilter}
         ORDER BY t.txn_date DESC, t.id DESC
         LIMIT {$limit}"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * รายการเบิกจ่าย/รายรับแบบมี pagination (ดู spec.md ข้อ 9 — ห้าม query ทั้งตารางมาแสดงในหน้าเดียว)
 * @return array{rows: array, total: int, page: int, per_page: int, total_pages: int}
 */
/**
 * $groupId: null = ทุกกลุ่มหมวด (ไม่กรอง), 0 = เฉพาะรายการที่ยังไม่ระบุกลุ่มหมวด (group_id IS NULL),
 * ค่าบวก = เฉพาะกลุ่มหมวดนั้น (ดู spec.md ข้อ 6.3 — group_id เป็น optional tag)
 */
function bpm_list_transactions(?int $departmentId, int $fiscalYearId, int $page = 1, int $perPage = 50, string $search = '', ?int $groupId = null): array
{
    $db = bpm_db();
    $page = max(1, $page);
    $perPage = max(1, min(200, $perPage));

    $params = [$fiscalYearId];
    $deptFilter = '';
    if ($departmentId !== null) {
        $deptFilter = ' AND li.department_id = ?';
        $params[] = $departmentId;
    }

    $groupFilter = '';
    if ($groupId !== null) {
        if ($groupId === 0) {
            $groupFilter = ' AND li.group_id IS NULL';
        } else {
            $groupFilter = ' AND li.group_id = ?';
            $params[] = $groupId;
        }
    }

    $searchFilter = '';
    if ($search !== '') {
        $searchFilter = ' AND (t.description LIKE ? OR li.name LIKE ? OR t.reference_no LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like);
    }

    $countStmt = $db->prepare(
        "SELECT COUNT(*) FROM transactions t JOIN budget_line_items li ON li.id = t.line_item_id
         WHERE li.fiscal_year_id = ?{$deptFilter}{$groupFilter}{$searchFilter}"
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $rowsStmt = $db->prepare(
        "SELECT t.*, li.name AS line_item_name, li.department_id, li.requires_travel_detail, d.name AS department_name
         FROM transactions t
         JOIN budget_line_items li ON li.id = t.line_item_id
         JOIN departments d ON d.id = li.department_id
         WHERE li.fiscal_year_id = ?{$deptFilter}{$groupFilter}{$searchFilter}
         ORDER BY t.txn_date DESC, t.id DESC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $rowsStmt->execute($params);

    return [
        'rows'        => $rowsStmt->fetchAll(),
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => (int) max(1, ceil($total / $perPage)),
    ];
}

/** รายการคำขอโยกย้ายงบทั้งหมดของสาขา(หรือทุกสาขา)+ปีงบหนึ่ง เรียงใหม่สุดก่อน — ใช้ทำหน้า /transfers.php */
function bpm_list_transfers(?int $departmentId, int $fiscalYearId): array
{
    $db = bpm_db();
    $params = [$fiscalYearId];
    $deptFilter = '';
    if ($departmentId !== null) {
        $deptFilter = ' AND bt.department_id = ?';
        $params[] = $departmentId;
    }

    $stmt = $db->prepare(
        "SELECT bt.*,
            d.name AS department_name,
            fromLi.name AS from_name, toLi.name AS to_name,
            reqUser.name AS requested_by_name, appUser.name AS approved_by_name
         FROM budget_transfers bt
         JOIN departments d ON d.id = bt.department_id
         JOIN budget_line_items fromLi ON fromLi.id = bt.from_line_item_id
         JOIN budget_line_items toLi ON toLi.id = bt.to_line_item_id
         JOIN users reqUser ON reqUser.id = bt.requested_by
         LEFT JOIN users appUser ON appUser.id = bt.approved_by
         WHERE bt.fiscal_year_id = ?{$deptFilter}
         ORDER BY bt.created_at DESC"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * รายงานตารางปกติ: 1 แถว = 1 line item พร้อม จัดสรร/เบิกจ่ายแล้ว/คงเหลือ/% — ใช้ทำ /reports.php มุมมองที่ 1
 * ถ้า $departmentId เป็น null คืนทุกสาขา (มีคอลัมน์สาขากำกับ)
 */
function bpm_report_line_items(?int $departmentId, int $fiscalYearId): array
{
    $db = bpm_db();
    $params = [$fiscalYearId];
    $deptFilter = '';
    if ($departmentId !== null) {
        $deptFilter = ' AND li.department_id = ?';
        $params[] = $departmentId;
    }

    $stmt = $db->prepare(
        "SELECT li.*, d.name AS department_name,
            COALESCE(ti.amt, 0) AS transfer_in, COALESCE(t_out.amt, 0) AS transfer_out,
            COALESCE(tx_exp.amt, 0) AS expense, COALESCE(tx_inc.amt, 0) AS income
         FROM budget_line_items li
         JOIN departments d ON d.id = li.department_id
         LEFT JOIN (SELECT to_line_item_id AS id, SUM(amount) AS amt FROM budget_transfers WHERE status = 'APPROVED' GROUP BY to_line_item_id) ti ON ti.id = li.id
         LEFT JOIN (SELECT from_line_item_id AS id, SUM(amount) AS amt FROM budget_transfers WHERE status = 'APPROVED' GROUP BY from_line_item_id) t_out ON t_out.id = li.id
         LEFT JOIN (SELECT line_item_id AS id, SUM(amount) AS amt FROM transactions WHERE type = 'EXPENSE' GROUP BY line_item_id) tx_exp ON tx_exp.id = li.id
         LEFT JOIN (SELECT line_item_id AS id, SUM(amount) AS amt FROM transactions WHERE type = 'INCOME' GROUP BY line_item_id) tx_inc ON tx_inc.id = li.id
         WHERE li.fiscal_year_id = ? AND li.is_active = 1{$deptFilter}
         ORDER BY d.name, li.name"
    );
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $total = (float) $r['starting_amount'] + (float) $r['transfer_in'] - (float) $r['transfer_out'];
        $spent = (float) $r['expense'] - (float) $r['income'];
        $rows[] = $r + [
            'total_budget' => $total,
            'spent'        => $spent,
            'balance'      => $total - $spent,
            'spent_pct'    => $total > 0 ? ($spent / $total) * 100 : 0.0,
        ];
    }

    return $rows;
}

/**
 * รายงานตารางไขว้ (matrix): แถว = ชื่อรายการ (รวมของทุกสาขาที่ใช้ชื่อเดียวกัน), คอลัมน์ = สาขา, ค่า = งบต้นปี
 * ตรงกับชีท "รวมงบประมาณประจำปี" ที่ฝ่ายการเงินใช้จริง — ใช้ทำ /reports.php มุมมองที่ 2
 * @return array{departments: array, rows: array<string, array{amounts: array<int,float>, total: float}>}
 */
function bpm_report_matrix(int $fiscalYearId): array
{
    $departments = bpm_all_departments();

    $stmt = bpm_db()->prepare(
        'SELECT name, department_id, starting_amount FROM budget_line_items WHERE fiscal_year_id = ? AND is_active = 1'
    );
    $stmt->execute([$fiscalYearId]);

    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $name = $r['name'];
        $deptId = (int) $r['department_id'];
        $rows[$name]['amounts'][$deptId] = ($rows[$name]['amounts'][$deptId] ?? 0.0) + (float) $r['starting_amount'];
    }
    foreach ($rows as $name => &$row) {
        $row['total'] = array_sum($row['amounts']);
    }
    unset($row);
    ksort($rows, SORT_STRING | SORT_FLAG_CASE);

    return ['departments' => $departments, 'rows' => $rows];
}

/** จำนวนคำขอโยกย้ายงบที่ยังรออนุมัติ — ใช้ทำ badge บน sidebar (ดู spec.md ข้อ 7) */
function bpm_pending_transfer_count(?int $departmentId = null): int
{
    $db = bpm_db();
    if ($departmentId === null) {
        return (int) $db->query("SELECT COUNT(*) FROM budget_transfers WHERE status = 'PENDING'")->fetchColumn();
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM budget_transfers WHERE status = 'PENDING' AND department_id = ?");
    $stmt->execute([$departmentId]);
    return (int) $stmt->fetchColumn();
}

const BPM_AUDIT_ACTION_LABELS = [
    'LINE_ITEM_UPDATE'   => 'แก้ไขรายการงบ',
    'TRANSACTION_UPDATE' => 'แก้ไขรายการเบิกจ่าย/รายรับ',
    'TRANSACTION_DELETE' => 'ลบรายการเบิกจ่าย/รายรับ',
    'TRANSFER_APPROVE'   => 'อนุมัติโยกย้ายงบ',
    'TRANSFER_REJECT'    => 'ไม่อนุมัติโยกย้ายงบ',
    'TRANSFER_DELETE'    => 'ลบคำขอโยกย้ายงบ',
    'TRANSFER_REVERSE'   => 'โอนงบกลับหมวดเดิม',
    'USER_PRE_PROVISION' => 'เพิ่มผู้ใช้ล่วงหน้า',
    'USER_ROLE_CHANGE'   => 'เปลี่ยนสิทธิ์ผู้ใช้',
    'FISCAL_YEAR_CLOSE'  => 'ปิดปีงบประมาณ',
];

/** ป้ายชื่อภาษาไทยของ audit_logs.action — คืนค่า action เดิมถ้าไม่รู้จัก (กันพังถ้ามี action ใหม่ในอนาคต) */
function bpm_audit_action_label(string $action): string
{
    return BPM_AUDIT_ACTION_LABELS[$action] ?? $action;
}

/** ประวัติการเปลี่ยนแปลงทั้งหมด (audit trail) — ใช้ทำหน้า admin/audit-log.php เท่านั้น (ADMIN only) */
function bpm_list_audit_logs(?string $action = null, int $page = 1, int $perPage = 50): array
{
    $db = bpm_db();
    $page = max(1, $page);
    $perPage = max(1, min(200, $perPage));

    $params = [];
    $actionFilter = '';
    if ($action !== null && $action !== '') {
        $actionFilter = ' WHERE al.action = ?';
        $params[] = $action;
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM audit_logs al{$actionFilter}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $rowsStmt = $db->prepare(
        "SELECT al.*, u.name AS actor_name
         FROM audit_logs al
         JOIN users u ON u.id = al.actor_id
         {$actionFilter}
         ORDER BY al.created_at DESC, al.id DESC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $rowsStmt->execute($params);

    return [
        'rows'        => $rowsStmt->fetchAll(),
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => (int) max(1, ceil($total / $perPage)),
    ];
}

/** แปลงจำนวนเงินเป็นข้อความ ฿X,XXX.XX ตามมาตรฐาน UI (ดู spec.md ข้อ 8) */
function bpm_money(float $amount): string
{
    return '฿' . number_format($amount, 2);
}

/**
 * สาขาที่กำลังดูอยู่ (null = ทั้งหมด) — DEPT_STAFF ถูกล็อกไว้ที่สาขาตัวเองเสมอ ไม่สนใจ query string
 * (ดู spec.md ข้อ 2 — ownership ต้องเช็คฝั่ง server เสมอ ห้ามเชื่อ client)
 */
function bpm_resolve_department_filter(array $user): ?int
{
    if ($user['role'] === 'DEPT_STAFF') {
        return (int) $user['department_id'];
    }

    return isset($_GET['dept']) && $_GET['dept'] !== '' ? (int) $_GET['dept'] : null;
}

/** รายชื่อสาขาที่ยัง active — ใช้ทำ dropdown filter/เลือกสาขา */
function bpm_all_departments(): array
{
    return bpm_db()->query('SELECT * FROM departments WHERE is_active = 1 ORDER BY name')->fetchAll();
}

/** ตัวย่อ 2 ตัวอักษรจากชื่อ-สกุลไทย (ตัดคำนำหน้าออกก่อน) — ใช้ทำ avatar วงกลม */
function bpm_initials(string $name): string
{
    $titles = ['นาย', 'นาง', 'นางสาว', 'ดร.', 'ผศ.ดร.', 'รศ.ดร.', 'ศ.ดร.', 'ผศ.', 'รศ.', 'ศ.', 'อ.'];
    $parts = array_values(array_filter(
        preg_split('/\s+/', trim($name)) ?: [],
        static fn (string $p): bool => !in_array($p, $titles, true) && $p !== ''
    ));

    if (count($parts) === 0) {
        return '?';
    }

    return mb_substr($parts[0], 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : '');
}

/** ชื่อ role แบบภาษาไทยสำหรับแสดงผล */
function bpm_role_label(?string $role): string
{
    return match ($role) {
        'ADMIN'             => 'ผู้ดูแลระบบ',
        'DEPT_STAFF'        => 'เจ้าหน้าที่สาขา',
        'EXECUTIVE_VIEWER'  => 'ผู้บริหาร',
        default             => 'ไม่มีสิทธิ์',
    };
}
