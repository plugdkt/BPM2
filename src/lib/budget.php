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

/**
 * SQL expression ป้ายชื่อรายการงบ: ถ้ามีแหล่งเงินจริง (ไม่ใช่ UNSPECIFIED) ต่อท้ายด้วย [ชื่อแหล่งเงิน]
 * เพราะรายการชื่อเดียวกันอยู่ได้หลายแหล่งเงิน (เช่น "ค่าวัสดุ [งบรายได้]" กับ "ค่าวัสดุ [งบแผ่นดิน]")
 */
function bpm_li_label_sql(string $li = 'li', string $fs = 'fs'): string
{
    if (!bpm_multiple_fund_sources()) {
        return "{$li}.name";
    }
    return "IF({$fs}.code = 'UNSPECIFIED', {$li}.name, CONCAT({$li}.name, ' [', {$fs}.name, ']'))";
}

/** มีแหล่งเงินจริง (ไม่นับ UNSPECIFIED) ถูกใช้งานเกิน 1 แหล่งหรือไม่ — ถ้ามีแหล่งเดียว ไม่ต้องต่อท้ายชื่อรายการด้วย [แหล่งเงิน] ให้รก */
function bpm_multiple_fund_sources(): bool
{
    static $multiple = null;
    if ($multiple === null) {
        $multiple = (int) bpm_db()->query('SELECT COUNT(DISTINCT fund_source_id) FROM budget_line_items WHERE is_active = 1 AND fund_source_id <> 1')->fetchColumn() > 1;
    }
    return $multiple;
}

/** ป้ายชื่อรายการงบฝั่ง PHP (คู่กับ bpm_li_label_sql) — ต้องมี fund_source_name/code ติดมากับแถวนั้น */
function bpm_li_label(array $li): string
{
    $code = $li['fund_source_code'] ?? 'UNSPECIFIED';
    return ($code === 'UNSPECIFIED' || !bpm_multiple_fund_sources()) ? (string) $li['name'] : $li['name'] . ' [' . $li['fund_source_name'] . ']';
}

/** รายการงบกลุ่ม "ค่าครุภัณฑ์" (budget_groups.code = EQUIPMENT) เพิ่มรายละเอียดได้ว่าซื้ออะไร จำนวน ราคาต่อหน่วย (line_item_details) */
function bpm_group_supports_details(?int $groupId): bool
{
    static $equipmentGroupIds = null;
    if ($equipmentGroupIds === null) {
        $equipmentGroupIds = array_map('intval', bpm_db()->query("SELECT id FROM budget_groups WHERE code = 'EQUIPMENT'")->fetchAll(PDO::FETCH_COLUMN));
    }
    return $groupId !== null && in_array($groupId, $equipmentGroupIds, true);
}

/** จำนวนรายการย่อยและยอดรวมของรายละเอียด (เฉพาะที่ active) แยกตาม line item — คืน [line_item_id => ['count' => n, 'total' => x]] */
function bpm_line_item_detail_totals(array $lineItemIds): array
{
    if (empty($lineItemIds)) {
        return [];
    }
    $in = implode(',', array_fill(0, count($lineItemIds), '?'));
    $stmt = bpm_db()->prepare("SELECT line_item_id, COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total FROM line_item_details WHERE is_active = 1 AND line_item_id IN ({$in}) GROUP BY line_item_id");
    $stmt->execute(array_values($lineItemIds));
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int) $r['line_item_id']] = ['count' => (int) $r['cnt'], 'total' => (float) $r['total']];
    }
    return $out;
}

/** รายการ line item ที่ยัง active ของสาขา+ปีงบหนึ่ง เรียงตามชื่อ — ใช้ประกอบ dropdown/autocomplete (มี fund_source_name/code ติดมาด้วย) */
function bpm_line_items_for_department(int $departmentId, int $fiscalYearId): array
{
    $stmt = bpm_db()->prepare(
        'SELECT li.*, fs.name AS fund_source_name, fs.code AS fund_source_code
         FROM budget_line_items li
         JOIN fund_sources fs ON fs.id = li.fund_source_id
         WHERE li.department_id = ? AND li.fiscal_year_id = ? AND li.is_active = 1
         ORDER BY li.name, fs.id'
    );
    $stmt->execute([$departmentId, $fiscalYearId]);
    return $stmt->fetchAll();
}

/** แหล่งเงินทั้งหมด (ยังใช้งานอยู่) เรียงตาม id — ใช้ประกอบ dropdown ในหน้าตั้งค่างบ */
function bpm_all_fund_sources(): array
{
    return bpm_db()->query('SELECT * FROM fund_sources WHERE is_active = 1 ORDER BY id')->fetchAll();
}

/**
 * รายงานแหล่งเงิน → สาขา ของปีงบหนึ่ง: วงเงินที่ได้รับ / แบ่งเป็นรายการงบแล้ว / ยังแบ่งได้อีก / เบิกจ่ายแล้ว / คงเหลือ / % เบิกจ่าย
 * 1 แถวต่อ (แหล่งเงิน × สาขา) ที่มีวงเงินหรือรายการงบ — ถ้า $departmentId ไม่ null จะเหลือเฉพาะสาขานั้น
 * allocated = SUM(starting_amount) ของรายการงบที่ active (โยกย้ายข้ามแหล่งเงินไม่ได้ ภายในสาขา+แหล่งเดียวกันโอนหักล้างกันเอง จึงใช้งบต้นปีได้)
 * spent = เบิกจ่าย − รายรับ; balance = allocated − spent; limit/unallocated = null ถ้ายังไม่ได้ตั้งวงเงินของสาขานั้น
 * แหล่งเงิน UNSPECIFIED แสดงเฉพาะเมื่อมีรายการงบอยู่ในนั้นจริง
 */
function bpm_report_fund_sources(?int $departmentId, int $fiscalYearId): array
{
    $db = bpm_db();
    $deptFilter = $departmentId !== null ? ' AND li.department_id = ?' : '';
    $params = $departmentId !== null ? [$fiscalYearId, $departmentId] : [$fiscalYearId];

    $stmt = $db->prepare(
        "SELECT li.fund_source_id, li.department_id,
            COALESCE(SUM(li.starting_amount), 0) AS allocated,
            COALESCE(SUM(tx_exp.amt), 0) - COALESCE(SUM(tx_inc.amt), 0) AS spent
         FROM budget_line_items li
         LEFT JOIN (SELECT line_item_id AS id, SUM(amount) AS amt FROM transactions WHERE type = 'EXPENSE' GROUP BY line_item_id) tx_exp ON tx_exp.id = li.id
         LEFT JOIN (SELECT line_item_id AS id, SUM(amount) AS amt FROM transactions WHERE type = 'INCOME' GROUP BY line_item_id) tx_inc ON tx_inc.id = li.id
         WHERE li.fiscal_year_id = ? AND li.is_active = 1{$deptFilter}
         GROUP BY li.fund_source_id, li.department_id"
    );
    $stmt->execute($params);
    $cells = [];
    foreach ($stmt->fetchAll() as $r) {
        $cells[(int) $r['fund_source_id']][(int) $r['department_id']] = ['allocated' => (float) $r['allocated'], 'spent' => (float) $r['spent']];
    }

    $limitStmt = $db->prepare('SELECT fund_source_id, department_id, amount FROM fund_dept_budgets WHERE fiscal_year_id = ?' . ($departmentId !== null ? ' AND department_id = ?' : ''));
    $limitStmt->execute($params);
    $limits = [];
    foreach ($limitStmt->fetchAll() as $r) {
        $limits[(int) $r['fund_source_id']][(int) $r['department_id']] = (float) $r['amount'];
    }

    $totalStmt = $db->prepare('SELECT fund_source_id, amount FROM fund_source_budgets WHERE fiscal_year_id = ?');
    $totalStmt->execute([$fiscalYearId]);
    $sourceTotals = $totalStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $deptNames = array_column(bpm_all_departments(), 'name', 'id');
    $result = [];
    foreach ($db->query('SELECT * FROM fund_sources ORDER BY id')->fetchAll() as $src) {
        $sid = (int) $src['id'];
        $deptIds = array_unique(array_merge(array_keys($cells[$sid] ?? []), array_keys($limits[$sid] ?? [])));
        if (empty($deptIds)) {
            continue;
        }
        if (!$src['is_active'] && empty($cells[$sid])) {
            continue;
        }
        usort($deptIds, static fn ($a, $b) => strcmp((string) ($deptNames[$a] ?? ''), (string) ($deptNames[$b] ?? '')));

        $rows = [];
        $tot = ['limit' => null, 'allocated' => 0.0, 'spent' => 0.0];
        foreach ($deptIds as $did) {
            $limit = $limits[$sid][$did] ?? null;
            $alloc = $cells[$sid][$did]['allocated'] ?? 0.0;
            $spent = $cells[$sid][$did]['spent'] ?? 0.0;
            $rows[] = [
                'department_id' => $did, 'name' => $deptNames[$did] ?? '',
                'limit' => $limit, 'allocated' => $alloc, 'unallocated' => $limit === null ? null : $limit - $alloc,
                'spent' => $spent, 'balance' => $alloc - $spent, 'spent_pct' => $alloc > 0 ? ($spent / $alloc) * 100 : 0.0,
            ];
            if ($limit !== null) {
                $tot['limit'] = ($tot['limit'] ?? 0.0) + $limit;
            }
            $tot['allocated'] += $alloc;
            $tot['spent'] += $spent;
        }
        $tot['unallocated'] = $tot['limit'] === null ? null : $tot['limit'] - $tot['allocated'];
        $tot['balance'] = $tot['allocated'] - $tot['spent'];
        $tot['spent_pct'] = $tot['allocated'] > 0 ? ($tot['spent'] / $tot['allocated']) * 100 : 0.0;

        $result[] = ['source' => $src, 'source_total' => isset($sourceTotals[$sid]) ? (float) $sourceTotals[$sid] : null, 'rows' => $rows, 'totals' => $tot];
    }

    return $result;
}

/** สรุปตามแหล่งเงิน (รวมทุกสาขาที่เลือก) — ใช้ทำการ์ดบน dashboard: วงเงินที่ได้รับ/แบ่งเป็นรายการแล้ว/เบิกจ่าย/คงเหลือ */
function bpm_fund_source_summary(?int $departmentId, int $fiscalYearId): array
{
    $out = [];
    foreach (bpm_report_fund_sources($departmentId, $fiscalYearId) as $blk) {
        $t = $blk['totals'];
        $out[] = [
            'id' => (int) $blk['source']['id'], 'name' => $blk['source']['name'], 'code' => $blk['source']['code'],
            'limit' => $t['limit'], 'unallocated' => $t['unallocated'],
            'allocated' => $t['allocated'], 'spent' => $t['spent'], 'balance' => $t['balance'], 'spent_pct' => $t['spent_pct'],
        ];
    }
    return $out;
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
        "SELECT t.*, " . bpm_li_label_sql() . " AS line_item_name, d.name AS department_name
         FROM transactions t
         JOIN budget_line_items li ON li.id = t.line_item_id
         JOIN fund_sources fs ON fs.id = li.fund_source_id
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
        $searchFilter = ' AND (t.description LIKE ? OR li.name LIKE ? OR t.reference_no LIKE ? OR t.requester_user_id IN (SELECT id FROM users WHERE name LIKE ?))';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $countStmt = $db->prepare(
        "SELECT COUNT(*) FROM transactions t JOIN budget_line_items li ON li.id = t.line_item_id
         WHERE li.fiscal_year_id = ?{$deptFilter}{$groupFilter}{$searchFilter}"
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $rowsStmt = $db->prepare(
        "SELECT t.*, " . bpm_li_label_sql() . " AS line_item_name, li.department_id, li.requires_travel_detail, d.name AS department_name, ru.name AS requester_name
         FROM transactions t
         JOIN budget_line_items li ON li.id = t.line_item_id
         JOIN fund_sources fs ON fs.id = li.fund_source_id
         JOIN departments d ON d.id = li.department_id
         LEFT JOIN users ru ON ru.id = t.requester_user_id
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
            " . bpm_li_label_sql('fromLi', 'fromFs') . " AS from_name, " . bpm_li_label_sql('toLi', 'toFs') . " AS to_name,
            reqUser.name AS requested_by_name, appUser.name AS approved_by_name
         FROM budget_transfers bt
         JOIN departments d ON d.id = bt.department_id
         JOIN budget_line_items fromLi ON fromLi.id = bt.from_line_item_id
         JOIN fund_sources fromFs ON fromFs.id = fromLi.fund_source_id
         JOIN budget_line_items toLi ON toLi.id = bt.to_line_item_id
         JOIN fund_sources toFs ON toFs.id = toLi.fund_source_id
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
        "SELECT li.*, d.name AS department_name, fs.name AS fund_source_name, fs.code AS fund_source_code,
            COALESCE(ti.amt, 0) AS transfer_in, COALESCE(t_out.amt, 0) AS transfer_out,
            COALESCE(tx_exp.amt, 0) AS expense, COALESCE(tx_inc.amt, 0) AS income
         FROM budget_line_items li
         JOIN departments d ON d.id = li.department_id
         JOIN fund_sources fs ON fs.id = li.fund_source_id
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

/**
 * ภาพรวมวงเงินแหล่งเงิน → สาขา ของปีงบหนึ่ง (ใช้ทำหน้า admin/fund-budgets.php)
 * total/budget = null หมายถึงยังไม่ได้ตั้งวงเงิน; allocated = SUM(starting_amount) ของรายการงบที่ active (ยอดที่แบ่งเป็นรายการงบแล้ว)
 * แหล่งเงิน UNSPECIFIED แสดงเฉพาะเมื่อมีรายการงบอยู่ในนั้นจริง เพื่อให้เห็นว่ามียอดที่ยังไม่ได้ระบุแหล่งเท่าไหร่
 */
function bpm_fund_envelope_overview(int $fiscalYearId): array
{
    $db = bpm_db();

    $totals = $db->prepare('SELECT fund_source_id, amount FROM fund_source_budgets WHERE fiscal_year_id = ?');
    $totals->execute([$fiscalYearId]);
    $totalMap = $totals->fetchAll(PDO::FETCH_KEY_PAIR);

    $deptBudgets = $db->prepare('SELECT fund_source_id, department_id, amount FROM fund_dept_budgets WHERE fiscal_year_id = ?');
    $deptBudgets->execute([$fiscalYearId]);
    $deptBudgetMap = [];
    foreach ($deptBudgets->fetchAll() as $b) {
        $deptBudgetMap[(int) $b['fund_source_id']][(int) $b['department_id']] = (float) $b['amount'];
    }

    $deptAlloc = $db->prepare(
        'SELECT fund_source_id, department_id, SUM(starting_amount) AS amt
         FROM budget_line_items WHERE fiscal_year_id = ? AND is_active = 1 GROUP BY fund_source_id, department_id'
    );
    $deptAlloc->execute([$fiscalYearId]);
    $deptAllocMap = [];
    foreach ($deptAlloc->fetchAll() as $a) {
        $deptAllocMap[(int) $a['fund_source_id']][(int) $a['department_id']] = (float) $a['amt'];
    }

    $departments = bpm_all_departments();
    $result = [];
    foreach ($db->query('SELECT * FROM fund_sources WHERE is_active = 1 ORDER BY id')->fetchAll() as $src) {
        $sid = (int) $src['id'];
        $srcAllocated = array_sum($deptAllocMap[$sid] ?? []);
        if ($src['code'] === 'UNSPECIFIED' && $srcAllocated <= 0) {
            continue;
        }

        $deptRows = [];
        foreach ($departments as $d) {
            $did = (int) $d['id'];
            $budget = $deptBudgetMap[$sid][$did] ?? null;
            $al = $deptAllocMap[$sid][$did] ?? 0.0;
            $deptRows[] = ['department_id' => $did, 'name' => $d['name'], 'budget' => $budget, 'allocated' => $al, 'remaining' => $budget === null ? null : $budget - $al];
        }

        $total = isset($totalMap[$sid]) ? (float) $totalMap[$sid] : null;
        $result[] = [
            'source'       => $src,
            'total'        => $total,
            'dept_planned' => array_sum($deptBudgetMap[$sid] ?? []), // ผลรวมวงเงินที่ตั้งให้รายสาขา
            'dept_remaining' => $total === null ? null : $total - array_sum($deptBudgetMap[$sid] ?? []), // วงเงินทั้งก้อนที่ยังไม่ได้แบ่งให้สาขา
            'allocated'    => $srcAllocated,
            'remaining'    => $total === null ? null : $total - $srcAllocated,
            'departments'  => $deptRows,
        ];
    }

    return $result;
}

/**
 * ข้อความเตือนเมื่อยอดที่แบ่งเป็นรายการงบเกินวงเงินที่ตั้งไว้ (ระดับสาขาและระดับแหล่งเงิน) — เตือนอย่างเดียว ไม่ block การบันทึก
 */
function bpm_fund_overage_warnings(int $fiscalYearId, int $fundSourceId, ?int $departmentId = null): array
{
    $warnings = [];
    foreach (bpm_fund_envelope_overview($fiscalYearId) as $env) {
        if ((int) $env['source']['id'] !== $fundSourceId) {
            continue;
        }
        foreach ($env['departments'] as $d) {
            if ($departmentId !== null && (int) $d['department_id'] === $departmentId && $d['remaining'] !== null && $d['remaining'] < 0) {
                $warnings[] = sprintf('"%s" ได้รับ%s เพียง %s แต่แบ่งเป็นรายการงบแล้ว %s (เกิน %s บาท)', $d['name'], $env['source']['name'], number_format((float) $d['budget'], 2), number_format($d['allocated'], 2), number_format(-$d['remaining'], 2));
            }
        }
        if ($env['remaining'] !== null && $env['remaining'] < 0) {
            $warnings[] = sprintf('ยอดที่แบ่งเป็นรายการงบของ "%s" เกินวงเงินแหล่งเงิน %s บาท', $env['source']['name'], number_format(-$env['remaining'], 2));
        }
    }
    return $warnings;
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
    'LINE_ITEM_DETAIL_SAVE' => 'แก้ไขรายละเอียดรายการงบ (ครุภัณฑ์)',
    'FUND_BUDGET_SET'    => 'ตั้ง/แก้วงเงินแหล่งเงิน',
    'FUND_BUDGET_CLEAR'  => 'ยกเลิกวงเงินแหล่งเงิน',
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
    if (in_array($user['role'], ['DEPT_STAFF', 'DEPT_HEAD'], true)) {
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
        'DEPT_HEAD'         => 'หัวหน้าสาขา',
        default             => 'ไม่มีสิทธิ์',
    };
}

// ---------------------------------------------------------------------------
// สมุดรายการ (ledger) — หน้าเจาะลึกแบบอ่านอย่างเดียวสำหรับทุก role (รวมผู้บริหาร) ใช้ที่ /ledger.php
// ---------------------------------------------------------------------------

/** เดือน (ตามปฏิทิน) ของแต่ละไตรมาสปีงบประมาณ — ตรงกับ bpm_fiscal_quarter() */
const BPM_QUARTER_MONTHS = [1 => [10, 11, 12], 2 => [1, 2, 3], 3 => [4, 5, 6], 4 => [7, 8, 9]];

/**
 * สร้างเงื่อนไข WHERE สำหรับรายการเบิกจ่าย (alias: t = transactions, li = budget_line_items)
 * $f: fy (จำเป็น), dept, source, group (null = ทุกกลุ่ม, 0 = ยังไม่ระบุกลุ่ม), quarter (1-4), type (EXPENSE|INCOME), item,
 *     requester (null = ทุกคน, 0 = ยังไม่ระบุผู้ขอใช้, บวก = ผู้ขอใช้คนนั้น), q (ค้นหา)
 * ค่าที่ไม่ใช่ตัวเลข/ไม่อยู่ในช่วงที่ยอมรับจะถูกมองว่า "ไม่กรอง" — bind ทุกค่าด้วย placeholder
 */
function bpm_ledger_where(array $f, array &$params): string
{
    $params = [(int) $f['fy']];
    $sql = ' WHERE li.fiscal_year_id = ?';

    if (!empty($f['dept'])) {
        $sql .= ' AND li.department_id = ?';
        $params[] = (int) $f['dept'];
    }
    if (!empty($f['source'])) {
        $sql .= ' AND li.fund_source_id = ?';
        $params[] = (int) $f['source'];
    }
    if (isset($f['group']) && $f['group'] !== null) {
        if ((int) $f['group'] === 0) {
            $sql .= ' AND li.group_id IS NULL';
        } else {
            $sql .= ' AND li.group_id = ?';
            $params[] = (int) $f['group'];
        }
    }
    if (!empty($f['quarter']) && isset(BPM_QUARTER_MONTHS[(int) $f['quarter']])) {
        $sql .= ' AND MONTH(t.txn_date) IN (' . implode(',', BPM_QUARTER_MONTHS[(int) $f['quarter']]) . ')';
    }
    if (!empty($f['type']) && in_array($f['type'], ['EXPENSE', 'INCOME'], true)) {
        $sql .= ' AND t.type = ?';
        $params[] = $f['type'];
    }
    if (!empty($f['item'])) {
        $sql .= ' AND li.id = ?';
        $params[] = (int) $f['item'];
    }
    if (isset($f['requester']) && $f['requester'] !== null) {
        if ((int) $f['requester'] === 0) {
            $sql .= ' AND t.requester_user_id IS NULL';
        } else {
            $sql .= ' AND t.requester_user_id = ?';
            $params[] = (int) $f['requester'];
        }
    }
    if (isset($f['q']) && $f['q'] !== '') {
        $sql .= ' AND (t.description LIKE ? OR li.name LIKE ? OR t.reference_no LIKE ? OR t.requester_user_id IN (SELECT id FROM users WHERE name LIKE ?))';
        $like = '%' . $f['q'] . '%';
        array_push($params, $like, $like, $like, $like);
    }

    return $sql;
}

/**
 * รายการเบิกจ่าย/รายรับตามตัวกรอง พร้อมยอดรวมของ "ทั้งชุดที่กรอง" (ไม่ใช่เฉพาะหน้าที่แสดง)
 * @return array{rows: array, total: int, page: int, per_page: int, total_pages: int, expense: float, income: float}
 */
function bpm_ledger_list(array $f, int $page = 1, int $perPage = 50): array
{
    $db = bpm_db();
    $page = max(1, $page);
    $perPage = max(1, min(200, $perPage));
    $params = [];
    $where = bpm_ledger_where($f, $params);

    $sum = $db->prepare(
        "SELECT COUNT(*) AS cnt,
            COALESCE(SUM(CASE WHEN t.type = 'EXPENSE' THEN t.amount END), 0) AS expense,
            COALESCE(SUM(CASE WHEN t.type = 'INCOME' THEN t.amount END), 0) AS income
         FROM transactions t JOIN budget_line_items li ON li.id = t.line_item_id{$where}"
    );
    $sum->execute($params);
    $s = $sum->fetch();
    $total = (int) $s['cnt'];

    $offset = ($page - 1) * $perPage;
    $rows = $db->prepare(
        "SELECT t.*, li.id AS li_id, " . bpm_li_label_sql() . " AS line_item_name, d.name AS department_name, ru.name AS requester_name
         FROM transactions t
         JOIN budget_line_items li ON li.id = t.line_item_id
         JOIN fund_sources fs ON fs.id = li.fund_source_id
         JOIN departments d ON d.id = li.department_id
         LEFT JOIN users ru ON ru.id = t.requester_user_id{$where}
         ORDER BY t.txn_date DESC, t.id DESC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $rows->execute($params);

    return [
        'rows' => $rows->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $perPage,
        'total_pages' => (int) max(1, ceil($total / $perPage)),
        'expense' => (float) $s['expense'], 'income' => (float) $s['income'],
    ];
}

/**
 * แจกแจงยอดเบิกจ่ายของชุดที่กรองตามมิติหนึ่ง: dept | group | source | quarter | item | requester (key 0 = ยังไม่ระบุผู้ขอใช้)
 * @return list<array{key: int|string, label: string, expense: float, income: float, net: float, count: int}>
 *   key = ค่าที่ใช้กรองต่อ (group: 0 = ยังไม่ระบุกลุ่ม) — เรียงตามยอดสุทธิมาก→น้อย (quarter เรียงตามไตรมาส)
 */
function bpm_ledger_breakdown(array $f, string $by): array
{
    $db = bpm_db();
    $params = [];
    $where = bpm_ledger_where($f, $params);

    $dims = [
        'dept'    => ['li.department_id', 'd.name'],
        'group'   => ['COALESCE(li.group_id, 0)', "COALESCE(g.name, 'ไม่ระบุกลุ่ม')"],
        'source'  => ['li.fund_source_id', 'fs.name'],
        'quarter' => ['CASE WHEN MONTH(t.txn_date) >= 10 THEN 1 WHEN MONTH(t.txn_date) <= 3 THEN 2 WHEN MONTH(t.txn_date) <= 6 THEN 3 ELSE 4 END', "''"],
        'item'    => ['li.id', bpm_li_label_sql()],
        'requester' => ['COALESCE(t.requester_user_id, 0)', "COALESCE(ru.name, '(ยังไม่ระบุผู้ขอใช้)')"],
    ];
    if (!isset($dims[$by])) {
        return [];
    }
    [$keyExpr, $labelExpr] = $dims[$by];

    $stmt = $db->prepare(
        "SELECT {$keyExpr} AS k, {$labelExpr} AS label,
            COUNT(*) AS cnt,
            COALESCE(SUM(CASE WHEN t.type = 'EXPENSE' THEN t.amount END), 0) AS expense,
            COALESCE(SUM(CASE WHEN t.type = 'INCOME' THEN t.amount END), 0) AS income
         FROM transactions t
         JOIN budget_line_items li ON li.id = t.line_item_id
         JOIN fund_sources fs ON fs.id = li.fund_source_id
         JOIN departments d ON d.id = li.department_id
         LEFT JOIN budget_groups g ON g.id = li.group_id
         LEFT JOIN users ru ON ru.id = t.requester_user_id{$where}
         GROUP BY k, label"
    );
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $label = (string) $r['label'];
        if ($by === 'quarter') {
            $label = BPM_QUARTER_LABELS[(int) $r['k']]['label'] . ' (' . BPM_QUARTER_LABELS[(int) $r['k']]['months'] . ')';
        }
        $out[] = [
            'key' => (int) $r['k'], 'label' => $label, 'count' => (int) $r['cnt'],
            'expense' => (float) $r['expense'], 'income' => (float) $r['income'], 'net' => (float) $r['expense'] - (float) $r['income'],
        ];
    }
    if ($by === 'quarter') {
        usort($out, static fn ($a, $b) => $a['key'] <=> $b['key']);
    } else {
        usort($out, static fn ($a, $b) => $b['net'] <=> $a['net']);
    }

    return $out;
}

/**
 * สมุดรายการของรายการงบหนึ่ง: ข้อมูลรายการ + ยอด (จาก bpm_line_item_balance — แหล่งความจริงเดียว)
 * + ไทม์ไลน์เรียงตามวันที่ (งบต้นปี, โอนเข้า/ออกที่อนุมัติแล้ว, เบิกจ่าย/รายรับ) พร้อมยอดคงเหลือสะสมทีละบรรทัด
 * คืน null ถ้าไม่พบรายการ — 'running_matches' = ยอดสะสมสุดท้ายตรงกับ balance ที่คำนวณโดย bpm_line_item_balance()
 */
function bpm_line_item_ledger(int $lineItemId): ?array
{
    $db = bpm_db();
    $stmt = $db->prepare(
        'SELECT li.*, d.name AS department_name, fs.name AS fund_source_name, fs.code AS fund_source_code,
            g.name AS group_name, g.code AS group_code, fy.year_be, fy.status AS fy_status
         FROM budget_line_items li
         JOIN departments d ON d.id = li.department_id
         JOIN fund_sources fs ON fs.id = li.fund_source_id
         JOIN fiscal_years fy ON fy.id = li.fiscal_year_id
         LEFT JOIN budget_groups g ON g.id = li.group_id
         WHERE li.id = ?'
    );
    $stmt->execute([$lineItemId]);
    $item = $stmt->fetch();
    if (!$item) {
        return null;
    }

    $bal = bpm_line_item_balance($lineItemId);

    $events = [];
    $events[] = ['date' => $item['created_at'] ? substr((string) $item['created_at'], 0, 10) : '', 'order' => 0, 'id' => 0, 'kind' => 'START', 'label' => 'งบต้นปี', 'ref' => null, 'delta' => (float) $item['starting_amount'], 'type' => null];

    $tr = $db->prepare(
        "SELECT bt.*, " . bpm_li_label_sql('fromLi', 'fromFs') . " AS from_name, " . bpm_li_label_sql('toLi', 'toFs') . " AS to_name
         FROM budget_transfers bt
         JOIN budget_line_items fromLi ON fromLi.id = bt.from_line_item_id JOIN fund_sources fromFs ON fromFs.id = fromLi.fund_source_id
         JOIN budget_line_items toLi ON toLi.id = bt.to_line_item_id JOIN fund_sources toFs ON toFs.id = toLi.fund_source_id
         WHERE bt.status = 'APPROVED' AND (bt.from_line_item_id = ? OR bt.to_line_item_id = ?)"
    );
    $tr->execute([$lineItemId, $lineItemId]);
    foreach ($tr->fetchAll() as $t) {
        $in = (int) $t['to_line_item_id'] === $lineItemId;
        $events[] = [
            'date' => substr((string) ($t['decided_at'] ?? $t['created_at']), 0, 10), 'order' => 1, 'id' => (int) $t['id'], 'kind' => 'TRANSFER',
            'label' => ($t['reversed_of_transfer_id'] ? 'โอนกลับ ' : 'โอน') . ($in ? 'เข้าจาก ' . $t['from_name'] : 'ออกไป ' . $t['to_name']),
            'ref' => $t['ref_memo_no'], 'note' => $t['reason'], 'delta' => $in ? (float) $t['amount'] : -(float) $t['amount'], 'type' => $in ? 'TRANSFER_IN' : 'TRANSFER_OUT',
        ];
    }

    $tx = $db->prepare('SELECT t.*, ru.name AS requester_name FROM transactions t LEFT JOIN users ru ON ru.id = t.requester_user_id WHERE t.line_item_id = ?');
    $tx->execute([$lineItemId]);
    foreach ($tx->fetchAll() as $t) {
        $events[] = [
            'date' => (string) $t['txn_date'], 'order' => 2, 'id' => (int) $t['id'], 'kind' => 'TXN', 'label' => (string) $t['description'],
            'ref' => $t['reference_no'], 'note' => $t['requester_name'] !== null ? 'ผู้ขอใช้: ' . $t['requester_name'] : null, 'delta' => $t['type'] === 'EXPENSE' ? -(float) $t['amount'] : (float) $t['amount'], 'type' => $t['type'],
        ];
    }

    // งบต้นปีขึ้นก่อนเสมอ แล้วเรียงตามวันที่ → โอน → เบิกจ่าย → id
    usort($events, static function ($a, $b) {
        if ($a['kind'] === 'START' || $b['kind'] === 'START') {
            return $a['kind'] === 'START' ? -1 : 1;
        }
        return [$a['date'], $a['order'], $a['id']] <=> [$b['date'], $b['order'], $b['id']];
    });
    $running = 0.0;
    foreach ($events as &$e) {
        $running += $e['delta'];
        $e['running'] = $running;
    }
    unset($e);

    $pending = $db->prepare("SELECT COUNT(*) FROM budget_transfers WHERE status = 'PENDING' AND (from_line_item_id = ? OR to_line_item_id = ?)");
    $pending->execute([$lineItemId, $lineItemId]);

    $details = [];
    if ($item['group_code'] === 'EQUIPMENT') {
        $ds = $db->prepare('SELECT * FROM line_item_details WHERE line_item_id = ? AND is_active = 1 ORDER BY id');
        $ds->execute([$lineItemId]);
        $details = $ds->fetchAll();
    }

    return [
        'item' => $item, 'balance' => $bal, 'events' => $events, 'pending_transfers' => (int) $pending->fetchColumn(),
        'details' => $details, 'running_matches' => abs($running - $bal['balance']) < 0.005,
    ];
}

/**
 * ระบบนี้บันทึกแต่รายจ่าย (ไม่มีรายรับ) — ใช้ซ่อนส่วนที่เกี่ยวกับ "รายรับ" ในหน้าสรุป ยกเว้นกรณีมีรายรับเก่าค้างอยู่ในข้อมูลจริง
 * (ยอดคงเหลือยังนับรายรับเก่าตามเดิมเสมอ — ไม่ลบ/ไม่แก้ข้อมูลเก่า)
 */
function bpm_income_exists(): bool
{
    static $exists = null;
    if ($exists === null) {
        $exists = (bool) bpm_db()->query("SELECT 1 FROM transactions WHERE type = 'INCOME' LIMIT 1")->fetchColumn();
    }
    return $exists;
}

/**
 * รายชื่อให้เลือกเป็น "ผู้ขอใช้" ในฟอร์มบันทึกเบิกจ่าย (พิมพ์ไม่กี่ตัวแล้วขึ้นชื่อ) — ผู้ใช้ที่ยังเปิดใช้งานทุกคนในระบบ
 * คืนเฉพาะที่ใช้แสดง/ค้นหา: id, name, pos (ตำแหน่ง), unit (สังกัด), u (username — ใช้ค้นหาเท่านั้น ไม่แสดง)
 */
function bpm_requester_options(): array
{
    $rows = bpm_db()->query('SELECT id, name, pos_name, div_name, sso_username FROM users WHERE is_active = 1 ORDER BY name')->fetchAll();
    return array_map(static fn (array $r): array => [
        'id' => (int) $r['id'], 'name' => (string) $r['name'], 'pos' => (string) ($r['pos_name'] ?? ''), 'unit' => (string) ($r['div_name'] ?? ''), 'u' => (string) $r['sso_username'],
    ], $rows);
}
