<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$user = bpm_require_role('ADMIN', 'DEPT_STAFF');

// เข้าหน้านี้ครั้งแรกโดยไม่ระบุ ?dept= เลย (ไม่ใช่เลือก "ทั้งหมด" ตั้งใจ) — เด้งไปสาขาแรกให้อัตโนมัติ
// เพื่อให้เห็นสรุปหมวดเงิน/ฟอร์มบันทึกรายการทันทีโดยไม่ต้องคลิกเลือกสาขาเอง (dept="" ว่างๆ ยังคงหมายถึง "ทั้งหมด" ตามเดิม)
if ($user['role'] !== 'DEPT_STAFF' && !isset($_GET['dept'])) {
    $firstDeptId = bpm_db()->query('SELECT id FROM departments WHERE is_active = 1 ORDER BY name LIMIT 1')->fetchColumn();
    if ($firstDeptId) {
        header('Location: ?' . http_build_query(array_merge($_GET, ['dept' => $firstDeptId])));
        exit;
    }
}

$fiscalYear = bpm_resolve_fiscal_year();
$selectedDepartmentId = bpm_resolve_department_filter($user);
$page = max(1, (int) ($_GET['page'] ?? 1));
$search = trim((string) ($_GET['q'] ?? ''));
// group=<id> เจาะจงกลุ่มหมวด, group=0 = เฉพาะที่ยังไม่ระบุกลุ่ม, ไม่ส่ง group เลย = ทุกกลุ่ม (ดู bpm_list_transactions())
$selectedGroupId = isset($_GET['group']) && $_GET['group'] !== '' ? (int) $_GET['group'] : null;
$groups = bpm_db()->query('SELECT * FROM budget_groups WHERE is_active = 1 ORDER BY id')->fetchAll();

$pageTitle = 'บันทึกเบิกจ่าย / รายรับ';
$activeNav = 'transactions';

if ($fiscalYear === null) {
    require __DIR__ . '/../src/partials/layout_start.php';
    echo '<div class="card empty-state">ยังไม่มีปีงบประมาณในระบบ</div>';
    require __DIR__ . '/../src/partials/layout_end.php';
    exit;
}

$listing = bpm_list_transactions($selectedDepartmentId, (int) $fiscalYear['id'], $page, 50, $search, $selectedGroupId);

// สาขาเป้าหมายสำหรับฟอร์มบันทึกใหม่: DEPT_STAFF = สาขาตัวเองเสมอ, ADMIN = ต้องเลือกสาขาเจาะจงก่อน (เลือก "ทั้งหมด" บันทึกไม่ได้)
$formDepartmentId = $user['role'] === 'DEPT_STAFF' ? (int) $user['department_id'] : $selectedDepartmentId;
$lineItems = $formDepartmentId !== null ? bpm_line_items_for_department($formDepartmentId, (int) $fiscalYear['id']) : [];

$balanceMap = [];
$lineItemDetails = [];
foreach ($lineItems as $li) {
    $detail = bpm_line_item_balance((int) $li['id']);
    $balanceMap[(int) $li['id']] = $detail['balance'];
    $lineItemDetails[(int) $li['id']] = $detail;
}

// รายการงบที่อยู่ในหมวดเงินที่กำลังเลือกดู (ใช้ $lineItems/$lineItemDetails ที่คำนวณไว้แล้ว ไม่ query ซ้ำ)
$groupLineItems = [];
if ($selectedGroupId !== null) {
    $groupLineItems = array_values(array_filter($lineItems, static function ($li) use ($selectedGroupId) {
        return $selectedGroupId === 0 ? $li['group_id'] === null : (int) $li['group_id'] === $selectedGroupId;
    }));
}

// สรุปยอดรวมต่อหมวดเงิน (ใช้ตอน tab "ทั้งหมด") — รวมทุกรายการงบตามกลุ่ม ไม่ query ซ้ำเช่นกัน
$groupRollup = [];
if ($selectedGroupId === null && $formDepartmentId !== null) {
    $groupNameMapAll = array_column($groups, 'name', 'id');
    foreach ($lineItems as $li) {
        $gid = $li['group_id'] !== null ? (int) $li['group_id'] : 'none';
        if (!isset($groupRollup[$gid])) {
            $groupRollup[$gid] = [
                'id'           => $gid,
                'name'         => $gid === 'none' ? 'ไม่ระบุกลุ่ม' : ($groupNameMapAll[$gid] ?? ''),
                'total_budget' => 0.0,
                'spent'        => 0.0,
                'balance'      => 0.0,
            ];
        }
        $d = $lineItemDetails[(int) $li['id']];
        $groupRollup[$gid]['total_budget'] += $d['total_budget'];
        $groupRollup[$gid]['spent']        += $d['expense'] - $d['income'];
        $groupRollup[$gid]['balance']      += $d['balance'];
    }
    // เรียงตามลำดับ id ของ budget_groups ก่อน แล้วค่อย "ไม่ระบุกลุ่ม" ท้ายสุด
    uksort($groupRollup, static fn ($a, $b) => ($a === 'none' ? PHP_INT_MAX : $a) <=> ($b === 'none' ? PHP_INT_MAX : $b));
}

$preselectLineItemId = (int) ($_GET['li'] ?? 0);

// ค่าเริ่มต้นของช่องวันที่ต้องอยู่ในช่วงปีงบเสมอ (ไม่ใช่ "วันนี้" เฉยๆ) เพราะวันนี้อาจอยู่นอกช่วงปีงบที่กำลังดูอยู่
// เช่น เปิดดูปีงบที่ยังไม่เริ่ม (start_date อยู่ในอนาคต) ค่า default เป็นวันนี้จะ invalid ทันทีเพราะน้อยกว่า min
$todayStr = (new DateTimeImmutable())->format('Y-m-d');
$defaultTxnDate = max($fiscalYear['start_date'], min($todayStr, $fiscalYear['end_date']));

// --- แก้ไขรายการ (ถ้ามี ?edit=ID และมีสิทธิ์) — ไม่ผูกกับ $formDepartmentId/$lineItems เพราะแก้ line_item เดิมเสมอ ไม่ให้ย้ายสาขา ---
$editingTxn = null;
$editingTravel = null;
$editingFiscalYear = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = bpm_db()->prepare(
        'SELECT t.*, li.name AS line_item_name, li.requires_travel_detail, d.name AS department_name
         FROM transactions t
         JOIN budget_line_items li ON li.id = t.line_item_id
         JOIN departments d ON d.id = li.department_id
         WHERE t.id = ?'
    );
    $stmt->execute([$editId]);
    $candidate = $stmt->fetch();

    if ($candidate && ($user['role'] === 'ADMIN' || (int) $candidate['created_by'] === (int) $user['id'])) {
        $editingTxn = $candidate;
        if ((int) $editingTxn['requires_travel_detail'] === 1) {
            $tStmt = bpm_db()->prepare('SELECT * FROM travel_records WHERE transaction_id = ?');
            $tStmt->execute([$editId]);
            $editingTravel = $tStmt->fetch() ?: null;
        }
        $fyStmt = bpm_db()->prepare('SELECT * FROM fiscal_years WHERE id = (SELECT fiscal_year_id FROM budget_line_items WHERE id = ?)');
        $fyStmt->execute([$editingTxn['line_item_id']]);
        $editingFiscalYear = $fyStmt->fetch();
    } else {
        bpm_flash_set('danger', $candidate ? 'ไม่มีสิทธิ์แก้ไขรายการนี้' : 'ไม่พบรายการที่ต้องการแก้ไข');
    }
}

require __DIR__ . '/../src/partials/layout_start.php';

$rowQs = static fn (int $id) => http_build_query(array_filter([
    'fy' => $_GET['fy'] ?? null, 'dept' => $_GET['dept'] ?? null, 'group' => $_GET['group'] ?? null, 'page' => $page, 'q' => $search ?: null, 'edit' => $id,
], static fn ($v) => $v !== null && $v !== ''));
$tabQs = static fn (int $deptId) => http_build_query(array_filter([
    'fy' => $_GET['fy'] ?? null, 'dept' => $deptId, 'group' => $_GET['group'] ?? null,
], static fn ($v) => $v !== null && $v !== ''));
// $groupId: null = tab "ทั้งหมด" (เคลียร์ filter), 0 = tab "ไม่ระบุ", บวก = tab กลุ่มนั้น
$groupTabQs = static fn (?int $groupId) => http_build_query(array_filter([
    'fy' => $_GET['fy'] ?? null, 'dept' => $_GET['dept'] ?? null, 'group' => $groupId,
], static fn ($v) => $v !== null && $v !== ''));
?>

  <?php if ($user['role'] !== 'DEPT_STAFF'):
    // ต้องส่ง dept= (ว่างๆ) แบบตั้งใจ ไม่ใช่ตัด param ทิ้งไปเฉยๆ ไม่งั้นเข้าเงื่อนไข "ไม่ได้ระบุ dept" แล้วโดนเด้งกลับไปสาขาแรกอีกที
    $allDeptParams = array_filter(['fy' => $_GET['fy'] ?? null, 'group' => $_GET['group'] ?? null], static fn ($v) => $v !== null && $v !== '');
    $allDeptParams['dept'] = ''; ?>
    <div class="card">
      <div style="display:flex; gap:8px; flex-wrap:wrap; overflow-x:auto;">
        <a href="?<?= http_build_query($allDeptParams) ?>" class="filter-chip" style="<?= $selectedDepartmentId === null ? 'background:var(--accent); color:#fff;' : '' ?>">ทั้งหมด</a>
        <?php foreach (bpm_all_departments() as $d): ?>
          <a href="?<?= $tabQs((int) $d['id']) ?>" class="filter-chip" style="<?= $selectedDepartmentId === (int) $d['id'] ? 'background:var(--accent); color:#fff;' : '' ?>"><?= htmlspecialchars($d['name'], ENT_QUOTES) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="card">
    <div style="display:flex; gap:8px; flex-wrap:wrap; overflow-x:auto; align-items:center;">
      <span class="text-muted small" style="margin-right:2px;">หมวดเงิน:</span>
      <a href="?<?= $groupTabQs(null) ?>" class="filter-chip" style="<?= $selectedGroupId === null ? 'background:var(--accent); color:#fff;' : '' ?>">ทั้งหมด</a>
      <?php foreach ($groups as $g): ?>
        <a href="?<?= $groupTabQs((int) $g['id']) ?>" class="filter-chip" style="<?= $selectedGroupId === (int) $g['id'] ? 'background:var(--accent); color:#fff;' : '' ?>"><?= htmlspecialchars($g['name'], ENT_QUOTES) ?></a>
      <?php endforeach; ?>
      <a href="?<?= $groupTabQs(0) ?>" class="filter-chip" style="<?= $selectedGroupId === 0 ? 'background:var(--accent); color:#fff;' : '' ?>">ไม่ระบุกลุ่ม</a>
    </div>
  </div>

  <?php if ($selectedGroupId === null && $formDepartmentId !== null): ?>
    <div class="card">
      <h2>สรุปงบตามหมวดเงิน</h2>
      <?php if (empty($groupRollup)): ?>
        <p class="empty-state">สาขานี้ยังไม่มีรายการงบตั้งไว้ในปีงบนี้</p>
      <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>หมวดเงิน</th>
              <th class="num">จัดสรร</th>
              <th class="num">ใช้ไปแล้ว</th>
              <th class="num">คงเหลือ</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($groupRollup as $g): ?>
              <tr>
                <td><a href="?<?= $groupTabQs($g['id'] === 'none' ? 0 : (int) $g['id']) ?>"><?= htmlspecialchars($g['name'], ENT_QUOTES) ?></a></td>
                <td class="num"><?= htmlspecialchars(bpm_money($g['total_budget']), ENT_QUOTES) ?></td>
                <td class="num"><?= htmlspecialchars(bpm_money($g['spent']), ENT_QUOTES) ?></td>
                <td class="num" style="<?= $g['balance'] < 0 ? 'color: var(--status-danger-text);' : 'color: var(--status-success-text);' ?>"><?= htmlspecialchars(bpm_money($g['balance']), ENT_QUOTES) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p class="text-muted small" style="margin-top:12px;">คลิกชื่อหมวดเงินเพื่อดูรายละเอียดแต่ละรายการ</p>
      <?php endif; ?>
    </div>
  <?php elseif ($selectedGroupId !== null && $formDepartmentId !== null): ?>
    <div class="card">
      <?php $groupNameMap = array_column($groups, 'name', 'id'); ?>
      <h2>รายการงบในหมวด "<?= htmlspecialchars($selectedGroupId === 0 ? 'ไม่ระบุกลุ่ม' : ($groupNameMap[$selectedGroupId] ?? ''), ENT_QUOTES) ?>"</h2>
      <?php if (empty($groupLineItems)): ?>
        <p class="empty-state">ไม่มีรายการงบในหมวดนี้สำหรับสาขาที่เลือก</p>
      <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>รายการ</th>
              <th class="num">จัดสรร</th>
              <th class="num">ใช้ไปแล้ว</th>
              <th class="num">คงเหลือ</th>
              <th style="width:100px;"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($groupLineItems as $li):
              $d = $lineItemDetails[(int) $li['id']]; ?>
              <?php $spent = $d['expense'] - $d['income']; ?>
              <tr>
                <td><?= htmlspecialchars($li['name'], ENT_QUOTES) ?></td>
                <td class="num"><?= htmlspecialchars(bpm_money($d['total_budget']), ENT_QUOTES) ?></td>
                <td class="num"><?= htmlspecialchars(bpm_money($spent), ENT_QUOTES) ?></td>
                <td class="num" style="<?= $d['balance'] < 0 ? 'color: var(--status-danger-text);' : 'color: var(--status-success-text);' ?>"><?= htmlspecialchars(bpm_money($d['balance']), ENT_QUOTES) ?></td>
                <td><button type="button" class="btn btn-secondary" style="padding:5px 10px; font-size:12.5px;" onclick="bpmOpenTxnModal(<?= (int) $li['id'] ?>)">เพิ่มรายการ</button></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card main-panel">
      <div class="table-toolbar">
        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
          <h2 style="margin:0;">รายการทั้งหมด — ปีงบ พ.ศ. <?= (int) $fiscalYear['year_be'] ?></h2>
          <?php if ($formDepartmentId !== null && !empty($lineItems)): ?>
            <button type="button" class="btn btn-primary" style="padding:6px 12px; font-size:13px;" onclick="bpmOpenTxnModal(0)"><?= bpm_icon('plus', 13) ?> เพิ่มรายการ</button>
          <?php endif; ?>
        </div>
        <form method="get" class="search-box">
          <?php foreach (['fy' => $_GET['fy'] ?? null, 'dept' => $_GET['dept'] ?? null, 'group' => $_GET['group'] ?? null] as $k => $v): if ($v !== null && $v !== ''): ?>
            <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars((string) $v, ENT_QUOTES) ?>">
          <?php endif; endforeach; ?>
          <?= bpm_icon('search', 14) ?>
          <input type="text" name="q" placeholder="ค้นหารายการ..." value="<?= htmlspecialchars($search, ENT_QUOTES) ?>">
        </form>
      </div>

      <?php if (empty($listing['rows'])): ?>
        <p class="empty-state">ยังไม่มีรายการ<?= $search !== '' ? 'ที่ตรงกับคำค้นหา' : '' ?></p>
      <?php else: ?>
        <?php if ($user['role'] === 'ADMIN'): foreach ($listing['rows'] as $t): ?>
          <form id="del-txn-<?= (int) $t['id'] ?>" method="post" action="<?= htmlspecialchars(bpm_url('actions/delete-transaction.php'), ENT_QUOTES) ?>">
            <?= bpm_csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <input type="hidden" name="fy" value="<?= htmlspecialchars((string) ($_GET['fy'] ?? ''), ENT_QUOTES) ?>">
            <input type="hidden" name="dept" value="<?= htmlspecialchars((string) ($_GET['dept'] ?? ''), ENT_QUOTES) ?>">
            <input type="hidden" name="group" value="<?= htmlspecialchars((string) ($_GET['group'] ?? ''), ENT_QUOTES) ?>">
          </form>
        <?php endforeach; endif; ?>
        <table class="data-table">
          <thead>
            <tr>
              <th class="center">วันที่</th>
              <?php if ($selectedDepartmentId === null): ?><th>สาขา</th><?php endif; ?>
              <th>รายการ</th>
              <th>เลขที่อ้างอิง</th>
              <th class="center">ประเภท</th>
              <th class="num">จำนวนเงิน</th>
              <th style="width:80px;"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($listing['rows'] as $t):
              $canEdit = $user['role'] === 'ADMIN' || (int) $t['created_by'] === (int) $user['id']; ?>
              <tr>
                <td class="center"><?= htmlspecialchars(bpm_thai_date($t['txn_date']), ENT_QUOTES) ?></td>
                <?php if ($selectedDepartmentId === null): ?><td><?= htmlspecialchars($t['department_name'], ENT_QUOTES) ?></td><?php endif; ?>
                <td><?= htmlspecialchars($t['line_item_name'], ENT_QUOTES) ?> — <?= htmlspecialchars($t['description'], ENT_QUOTES) ?></td>
                <td class="text-muted"><?= htmlspecialchars($t['reference_no'] ?? '-', ENT_QUOTES) ?></td>
                <td class="center"><span class="pill <?= $t['type'] === 'EXPENSE' ? 'pill-neutral' : 'pill-success' ?>"><?= $t['type'] === 'EXPENSE' ? 'รายจ่าย' : 'รายรับ' ?></span></td>
                <td class="num" style="<?= $t['type'] === 'INCOME' ? 'color: var(--status-success-text);' : '' ?>">
                  <?= $t['type'] === 'EXPENSE' ? '-' : '+' ?><?= htmlspecialchars(bpm_money((float) $t['amount']), ENT_QUOTES) ?>
                </td>
                <td class="center" style="display:flex; gap:6px; justify-content:center;">
                  <?php if ($canEdit): ?>
                    <a href="?<?= $rowQs((int) $t['id']) ?>" class="icon-btn icon-btn-approve" title="แก้ไขรายการ" style="display:inline-flex;"><?= bpm_icon('edit', 13) ?></a>
                  <?php endif; ?>
                  <?php if ($user['role'] === 'ADMIN'): ?>
                    <button type="submit" form="del-txn-<?= (int) $t['id'] ?>" class="icon-btn icon-btn-reject" title="ลบรายการถาวร"
                            data-confirm-desc="<?= htmlspecialchars($t['line_item_name'] . ' — ' . $t['description'] . ' (' . bpm_money((float) $t['amount']) . ')', ENT_QUOTES) ?>"
                            onclick="return bpmConfirmDeleteTxn(this)">
                      <?= bpm_icon('trash', 13) ?>
                    </button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <?php if ($listing['total_pages'] > 1): ?>
          <div style="display:flex; gap:8px; justify-content:center; margin-top:16px;">
            <?php for ($p = 1; $p <= $listing['total_pages']; $p++):
                $qs = array_filter(['fy' => $_GET['fy'] ?? null, 'dept' => $_GET['dept'] ?? null, 'group' => $_GET['group'] ?? null, 'q' => $search ?: null, 'page' => $p], static fn ($v) => $v !== null && $v !== ''); ?>
              <a href="?<?= http_build_query($qs) ?>" class="filter-chip" style="<?= $p === $listing['page'] ? 'background:var(--accent); color:#fff;' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

  <dialog id="txn-modal" class="modal">
    <div class="modal-inner">
      <?php if ($editingTxn): ?>
        <h2>แก้ไขรายการ</h2>
        <p class="text-muted small" style="margin-top:-8px; margin-bottom:16px;">
          <?= htmlspecialchars($editingTxn['department_name'], ENT_QUOTES) ?> — <?= htmlspecialchars($editingTxn['line_item_name'], ENT_QUOTES) ?><br>
          ไม่สามารถเปลี่ยนรายการงบได้ — ถ้าเลือกรายการผิดทั้งหมด ให้บันทึกรายการใหม่แทนแล้วปิดใช้งานรายการนี้ทีหลัง
        </p>

        <form method="post" action="<?= htmlspecialchars(bpm_url('actions/update-transaction.php'), ENT_QUOTES) ?>" id="edit-txn-form" class="field-group">
          <?= bpm_csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $editingTxn['id'] ?>">
          <input type="hidden" name="fy" value="<?= htmlspecialchars((string) ($_GET['fy'] ?? ''), ENT_QUOTES) ?>">
          <input type="hidden" name="dept" value="<?= htmlspecialchars((string) ($_GET['dept'] ?? ''), ENT_QUOTES) ?>">
          <input type="hidden" name="group" value="<?= htmlspecialchars((string) ($_GET['group'] ?? ''), ENT_QUOTES) ?>">

          <div>
            <label class="field-label">ประเภทรายการ</label>
            <div class="type-toggle">
              <label><input type="radio" name="type" value="EXPENSE" <?= $editingTxn['type'] === 'EXPENSE' ? 'checked' : '' ?>> รายจ่าย</label>
              <label><input type="radio" name="type" value="INCOME" <?= $editingTxn['type'] === 'INCOME' ? 'checked' : '' ?>> รายรับ</label>
            </div>
          </div>

          <div>
            <label class="field-label" for="edit_amount">จำนวนเงิน (บาท)</label>
            <input type="text" inputmode="decimal" name="amount" id="edit_amount" class="field num" value="<?= number_format((float) $editingTxn['amount'], 2, '.', '') ?>" required>
          </div>

          <div>
            <label class="field-label" for="edit_txn_date">วันที่ทำรายการ</label>
            <input type="date" name="txn_date" id="edit_txn_date" class="field" value="<?= htmlspecialchars($editingTxn['txn_date'], ENT_QUOTES) ?>"
                   min="<?= htmlspecialchars($editingFiscalYear['start_date'], ENT_QUOTES) ?>" max="<?= htmlspecialchars($editingFiscalYear['end_date'], ENT_QUOTES) ?>" required>
          </div>

          <div>
            <label class="field-label" for="edit_description">รายละเอียด</label>
            <textarea name="description" id="edit_description" class="field" required><?= htmlspecialchars($editingTxn['description'], ENT_QUOTES) ?></textarea>
          </div>

          <div>
            <label class="field-label" for="edit_reference_no">เลขที่อ้างอิง (ถ้ามี)</label>
            <input type="text" name="reference_no" id="edit_reference_no" class="field" value="<?= htmlspecialchars((string) $editingTxn['reference_no'], ENT_QUOTES) ?>">
          </div>

          <?php if ((int) $editingTxn['requires_travel_detail'] === 1): ?>
            <div class="field-group">
              <div>
                <label class="field-label" for="edit_instructor_name">ชื่อผู้เดินทาง</label>
                <input type="text" name="instructor_name" id="edit_instructor_name" class="field" value="<?= htmlspecialchars((string) ($editingTravel['instructor_name'] ?? ''), ENT_QUOTES) ?>" required>
              </div>
              <div>
                <label class="field-label" for="edit_purpose">รายละเอียดการเดินทาง/ประชุม/อบรม</label>
                <textarea name="purpose" id="edit_purpose" class="field" required><?= htmlspecialchars((string) ($editingTravel['purpose'] ?? ''), ENT_QUOTES) ?></textarea>
              </div>
              <div>
                <label class="field-label" for="edit_installment_no">งวดที่</label>
                <input type="number" name="installment_no" id="edit_installment_no" class="field" value="<?= (int) ($editingTravel['installment_no'] ?? 1) ?>" min="1">
              </div>
              <div>
                <label class="field-label" for="edit_travel_ref_doc">เลขที่เอกสารอ้างอิง</label>
                <input type="text" name="travel_ref_doc" id="edit_travel_ref_doc" class="field" value="<?= htmlspecialchars((string) ($editingTravel['ref_doc_no'] ?? ''), ENT_QUOTES) ?>">
              </div>
            </div>
          <?php endif; ?>

          <div class="btn-row">
            <button type="submit" class="btn btn-primary">บันทึกการแก้ไข</button>
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('txn-modal').close()">ยกเลิก</button>
          </div>
        </form>

      <?php elseif ($formDepartmentId === null): ?>
        <h2>บันทึกรายการใหม่</h2>
        <p class="empty-state">เลือกสาขาที่ต้องการบันทึกรายการจาก dropdown ด้านบนก่อน (ไม่สามารถบันทึกตอนดู "ทั้งหมด" ได้)</p>
      <?php elseif (empty($lineItems)): ?>
        <h2>บันทึกรายการใหม่</h2>
        <p class="empty-state">สาขานี้ยังไม่มีรายการงบตั้งไว้ในปีงบนี้ — ให้ ADMIN สร้างที่หน้าตั้งค่างบประมาณก่อน</p>
      <?php else: ?>
        <h2>บันทึกรายการใหม่</h2>
        <form method="post" action="<?= htmlspecialchars(bpm_url('actions/create-transaction.php'), ENT_QUOTES) ?>" id="txn-form" class="field-group">
          <?= bpm_csrf_field() ?>
          <input type="hidden" name="fy" value="<?= (int) $fiscalYear['id'] ?>">
          <input type="hidden" name="dept" value="<?= (int) $formDepartmentId ?>">

          <div>
            <label class="field-label" for="line_item_id">รายการงบ</label>
            <select name="line_item_id" id="line_item_id" class="field" onchange="bpmUpdateBalancePreview()" required>
              <?php foreach ($lineItems as $li): ?>
                <option value="<?= (int) $li['id'] ?>" data-travel="<?= (int) $li['requires_travel_detail'] ?>" <?= $preselectLineItemId === (int) $li['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($li['name'], ENT_QUOTES) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="field-label">ประเภทรายการ</label>
            <div class="type-toggle">
              <label><input type="radio" name="type" value="EXPENSE" checked onchange="bpmUpdateBalancePreview()"> รายจ่าย</label>
              <label><input type="radio" name="type" value="INCOME" onchange="bpmUpdateBalancePreview()"> รายรับ</label>
            </div>
          </div>

          <div>
            <label class="field-label" for="amount">จำนวนเงิน (บาท)</label>
            <input type="text" inputmode="decimal" name="amount" id="amount" class="field num" placeholder="0.00" oninput="bpmUpdateBalancePreview()" required>
          </div>

          <div>
            <label class="field-label" for="txn_date">วันที่ทำรายการ</label>
            <input type="date" name="txn_date" id="txn_date" class="field" value="<?= htmlspecialchars($defaultTxnDate, ENT_QUOTES) ?>"
                   min="<?= htmlspecialchars($fiscalYear['start_date'], ENT_QUOTES) ?>" max="<?= htmlspecialchars($fiscalYear['end_date'], ENT_QUOTES) ?>" required>
          </div>

          <div>
            <label class="field-label" for="description">รายละเอียด</label>
            <textarea name="description" id="description" class="field" required></textarea>
          </div>

          <div>
            <label class="field-label" for="reference_no">เลขที่อ้างอิง (ถ้ามี)</label>
            <input type="text" name="reference_no" id="reference_no" class="field">
          </div>

          <div id="travel-fields" style="display:none;">
            <div class="field-group">
              <div>
                <label class="field-label" for="instructor_name">ชื่อผู้เดินทาง</label>
                <input type="text" name="instructor_name" id="instructor_name" class="field">
              </div>
              <div>
                <label class="field-label" for="purpose">รายละเอียดการเดินทาง/ประชุม/อบรม</label>
                <textarea name="purpose" id="purpose" class="field"></textarea>
              </div>
              <div>
                <label class="field-label" for="installment_no">งวดที่</label>
                <input type="number" name="installment_no" id="installment_no" class="field" value="1" min="1">
              </div>
              <div>
                <label class="field-label" for="travel_ref_doc">เลขที่เอกสารอ้างอิง</label>
                <input type="text" name="travel_ref_doc" id="travel_ref_doc" class="field">
              </div>
            </div>
          </div>

          <div class="balance-box" id="balance-box">
            <div class="row"><span>ยอดคงเหลือก่อนทำรายการ</span><span id="balance-before">-</span></div>
            <hr>
            <div class="row total"><span>ยอดคงเหลือหลังทำรายการ</span><span id="balance-after">-</span></div>
          </div>

          <div class="btn-row">
            <button type="submit" class="btn btn-primary">บันทึกรายการ</button>
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('txn-modal').close()">ยกเลิก</button>
          </div>
        </form>

        <script>
          const bpmLineItemBalances = <?= json_encode($balanceMap, JSON_NUMERIC_CHECK) ?>;

          function bpmFormatMoney(n) {
            const sign = n < 0 ? '-' : '';
            return sign + '฿' + Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
          }

          function bpmUpdateBalancePreview() {
            const select = document.getElementById('line_item_id');
            const opt = select.options[select.selectedIndex];
            const id = select.value;
            const type = document.querySelector('input[name="type"]:checked').value;
            const amount = parseFloat((document.getElementById('amount').value || '0').replace(/,/g, '')) || 0;

            const before = bpmLineItemBalances[id] ?? 0;
            const after = type === 'EXPENSE' ? before - amount : before + amount;

            document.getElementById('balance-before').textContent = bpmFormatMoney(before);
            document.getElementById('balance-after').textContent = bpmFormatMoney(after);
            document.getElementById('balance-box').classList.toggle('negative', after < 0);

            document.getElementById('travel-fields').style.display = opt.dataset.travel === '1' ? 'block' : 'none';
            document.getElementById('instructor_name').required = opt.dataset.travel === '1';
            document.getElementById('purpose').required = opt.dataset.travel === '1';
          }

          bpmUpdateBalancePreview();
        </script>
      <?php endif; ?>
    </div>
  </dialog>

  <script>
    function bpmConfirmDeleteTxn(btn) {
      return confirm('ลบรายการนี้ถาวร?\n\n' + btn.dataset.confirmDesc + '\n\nการลบนี้ย้อนกลับไม่ได้ (ต่างจากที่อื่นในระบบที่แค่ปิดใช้งาน) ใช้เฉพาะกรณีข้อมูลผิดพลาดจริงเท่านั้น');
    }

    function bpmOpenTxnModal(lineItemId) {
      const select = document.getElementById('line_item_id');
      if (lineItemId > 0 && select) {
        select.value = lineItemId;
        if (typeof bpmUpdateBalancePreview === 'function') bpmUpdateBalancePreview();
      }
      document.getElementById('txn-modal').showModal();
    }

    document.getElementById('txn-modal').addEventListener('click', function (e) {
      if (e.target === this) this.close();
    });

    <?php if ($editingTxn || $preselectLineItemId > 0): ?>
    document.getElementById('txn-modal').showModal();
    <?php endif; ?>
  </script>

<?php require __DIR__ . '/../src/partials/layout_end.php'; ?>
