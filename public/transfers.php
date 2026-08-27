<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$user = bpm_require_role('ADMIN', 'DEPT_STAFF');

// เข้าหน้านี้ครั้งแรกโดยไม่ระบุ ?dept= เลย (ไม่ใช่เลือก "ทั้งหมด" ตั้งใจ) — เด้งไปสาขาแรกให้อัตโนมัติ
// (dept="" ว่างๆ ยังคงหมายถึง "ทั้งหมด" ตามเดิม — ดู tab "ทั้งหมด" ด้านล่าง)
if ($user['role'] !== 'DEPT_STAFF' && !isset($_GET['dept'])) {
    $firstDeptId = bpm_db()->query('SELECT id FROM departments WHERE is_active = 1 ORDER BY name LIMIT 1')->fetchColumn();
    if ($firstDeptId) {
        header('Location: ?' . http_build_query(array_merge($_GET, ['dept' => $firstDeptId])));
        exit;
    }
}

$fiscalYear = bpm_resolve_fiscal_year();
$selectedDepartmentId = bpm_resolve_department_filter($user);
$selectedGroupId = isset($_GET['group']) && $_GET['group'] !== '' ? (int) $_GET['group'] : null;
$groups = bpm_db()->query('SELECT * FROM budget_groups WHERE is_active = 1 ORDER BY id')->fetchAll();

$pageTitle = 'คำขอโยกย้ายงบประมาณ';
$activeNav = 'transfers';

if ($fiscalYear === null) {
    require __DIR__ . '/../src/partials/layout_start.php';
    echo '<div class="card empty-state">ยังไม่มีปีงบประมาณในระบบ</div>';
    require __DIR__ . '/../src/partials/layout_end.php';
    exit;
}

$transfers = bpm_list_transfers($selectedDepartmentId, (int) $fiscalYear['id']);
$pendingCount = count(array_filter($transfers, static fn ($t) => $t['status'] === 'PENDING'));
// เก็บ id ของคำขอที่ "ถูกโอนกลับไปแล้ว" ไว้ ใช้ซ่อนปุ่มโอนกลับซ้ำ + โชว์ป้ายกำกับ
$reversedIds = array_filter(array_column($transfers, 'reversed_of_transfer_id'));

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

$statusPill = [
    'PENDING'  => ['pill-warning', 'รออนุมัติ'],
    'APPROVED' => ['pill-success', 'อนุมัติแล้ว'],
    'REJECTED' => ['pill-danger', 'ไม่อนุมัติ'],
];

require __DIR__ . '/../src/partials/layout_start.php';

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

  <?php if ($selectedGroupId !== null && $formDepartmentId !== null): ?>
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
                <td>
                  <?php if (count($lineItems) >= 2): ?>
                    <button type="button" class="btn btn-secondary" style="padding:5px 10px; font-size:12.5px;" onclick="bpmOpenTransferModal(<?= (int) $li['id'] ?>)">ยื่นคำขอ</button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($pendingCount > 0): ?>
    <p><span class="pill pill-warning">รออนุมัติ <?= $pendingCount ?> รายการ</span></p>
  <?php endif; ?>

  <div class="card main-panel">
      <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
        <h2 style="margin:0;">รายการคำขอทั้งหมด</h2>
        <?php if ($formDepartmentId !== null && count($lineItems) >= 2): ?>
          <button type="button" class="btn btn-primary" style="padding:6px 12px; font-size:13px;" onclick="bpmOpenTransferModal(0)"><?= bpm_icon('plus', 13) ?> ยื่นคำขอ</button>
        <?php endif; ?>
      </div>

      <?php if (empty($transfers)): ?>
        <p class="empty-state">ยังไม่มีคำขอโยกย้ายงบในปีงบนี้</p>
      <?php else: ?>
        <?php if ($user['role'] === 'ADMIN'): foreach ($transfers as $t): ?>
          <form id="del-transfer-<?= (int) $t['id'] ?>" method="post" action="<?= htmlspecialchars(bpm_url('actions/delete-transfer.php'), ENT_QUOTES) ?>">
            <?= bpm_csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <input type="hidden" name="fy" value="<?= (int) $fiscalYear['id'] ?>">
            <?php if ($selectedDepartmentId !== null): ?><input type="hidden" name="dept" value="<?= (int) $selectedDepartmentId ?>"><?php endif; ?>
            <?php if ($selectedGroupId !== null): ?><input type="hidden" name="group" value="<?= (int) $selectedGroupId ?>"><?php endif; ?>
          </form>
        <?php endforeach; endif; ?>
        <table class="data-table">
          <thead>
            <tr>
              <th class="center">วันที่ยื่น</th>
              <?php if ($selectedDepartmentId === null): ?><th>สาขา</th><?php endif; ?>
              <th>หมวดที่โยกย้าย</th>
              <th class="num">จำนวนเงิน</th>
              <th class="center">สถานะ</th>
              <th>การดำเนินการ</th>
              <?php if ($user['role'] === 'ADMIN'): ?><th style="width:40px;"></th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($transfers as $t): [$pillClass, $pillLabel] = $statusPill[$t['status']]; ?>
              <tr>
                <td class="center"><?= htmlspecialchars(bpm_thai_date($t['created_at']), ENT_QUOTES) ?></td>
                <?php if ($selectedDepartmentId === null): ?><td><?= htmlspecialchars($t['department_name'], ENT_QUOTES) ?></td><?php endif; ?>
                <td><?= htmlspecialchars($t['from_name'], ENT_QUOTES) ?> → <?= htmlspecialchars($t['to_name'], ENT_QUOTES) ?></td>
                <td class="num"><?= htmlspecialchars(bpm_money((float) $t['amount']), ENT_QUOTES) ?></td>
                <td class="center"><span class="pill <?= $pillClass ?>"><?= $pillLabel ?></span></td>
                <td>
                  <?php if ($t['status'] === 'PENDING' && $user['role'] === 'ADMIN'): ?>
                    <div style="display:flex; gap:6px;">
                      <form method="post" action="<?= htmlspecialchars(bpm_url('actions/decide-transfer.php'), ENT_QUOTES) ?>">
                        <?= bpm_csrf_field() ?>
                        <input type="hidden" name="transfer_id" value="<?= (int) $t['id'] ?>">
                        <input type="hidden" name="decision" value="APPROVED">
                        <input type="hidden" name="fy" value="<?= (int) $fiscalYear['id'] ?>">
                        <?php if ($selectedDepartmentId !== null): ?><input type="hidden" name="dept" value="<?= (int) $selectedDepartmentId ?>"><?php endif; ?>
                        <button type="submit" class="icon-btn icon-btn-approve" title="อนุมัติ"><?= bpm_icon('check', 13) ?></button>
                      </form>
                      <form method="post" action="<?= htmlspecialchars(bpm_url('actions/decide-transfer.php'), ENT_QUOTES) ?>">
                        <?= bpm_csrf_field() ?>
                        <input type="hidden" name="transfer_id" value="<?= (int) $t['id'] ?>">
                        <input type="hidden" name="decision" value="REJECTED">
                        <input type="hidden" name="fy" value="<?= (int) $fiscalYear['id'] ?>">
                        <?php if ($selectedDepartmentId !== null): ?><input type="hidden" name="dept" value="<?= (int) $selectedDepartmentId ?>"><?php endif; ?>
                        <button type="submit" class="icon-btn icon-btn-reject" title="ไม่อนุมัติ"><?= bpm_icon('x', 13) ?></button>
                      </form>
                    </div>
                  <?php elseif ($t['status'] !== 'PENDING'): ?>
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                      <span class="text-muted small">โดย <?= htmlspecialchars($t['approved_by_name'] ?? '-', ENT_QUOTES) ?> · <?= htmlspecialchars(bpm_thai_date($t['decided_at']), ENT_QUOTES) ?></span>
                      <?php if ($t['reversed_of_transfer_id']): ?>
                        <span class="pill pill-warning" style="font-size:11px;">โอนกลับคำขอ #<?= (int) $t['reversed_of_transfer_id'] ?></span>
                      <?php elseif (in_array((int) $t['id'], $reversedIds, true)): ?>
                        <span class="pill pill-warning" style="font-size:11px;">ถูกโอนกลับแล้ว</span>
                      <?php elseif ($t['status'] === 'APPROVED' && $user['role'] === 'ADMIN'): ?>
                        <form method="post" action="<?= htmlspecialchars(bpm_url('actions/reverse-transfer.php'), ENT_QUOTES) ?>" style="display:inline;">
                          <?= bpm_csrf_field() ?>
                          <input type="hidden" name="transfer_id" value="<?= (int) $t['id'] ?>">
                          <input type="hidden" name="fy" value="<?= (int) $fiscalYear['id'] ?>">
                          <?php if ($selectedDepartmentId !== null): ?><input type="hidden" name="dept" value="<?= (int) $selectedDepartmentId ?>"><?php endif; ?>
                          <?php if ($selectedGroupId !== null): ?><input type="hidden" name="group" value="<?= (int) $selectedGroupId ?>"><?php endif; ?>
                          <button type="submit" class="icon-btn icon-btn-info" title="โอนกลับหมวดเดิม"
                                  onclick="return confirm('โอนกลับหมวดเดิม?\n\n<?= htmlspecialchars($t['to_name'] . ' → ' . $t['from_name'] . ' (' . bpm_money((float) $t['amount']) . ')', ENT_QUOTES) ?>\n\nระบบจะสร้างคำขอโยกย้ายใหม่ที่สลับหมวดต้นทาง/ปลายทางกัน แล้วอนุมัติให้ทันที')">
                            <?= bpm_icon('restore', 13) ?>
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </td>
                <?php if ($user['role'] === 'ADMIN'): ?>
                  <td class="center">
                    <button type="submit" form="del-transfer-<?= (int) $t['id'] ?>" class="icon-btn icon-btn-reject" title="ลบคำขอถาวร"
                            data-confirm-desc="<?= htmlspecialchars($t['from_name'] . ' → ' . $t['to_name'] . ' (' . bpm_money((float) $t['amount']) . ') สถานะ: ' . $pillLabel, ENT_QUOTES) ?>"
                            onclick="return bpmConfirmDeleteTransfer(this)">
                      <?= bpm_icon('trash', 13) ?>
                    </button>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  <dialog id="transfer-modal" class="modal">
    <div class="modal-inner">
      <h2>ยื่นคำขอโยกย้ายงบใหม่</h2>

      <?php if ($formDepartmentId === null): ?>
        <p class="empty-state">เลือกสาขาที่ต้องการยื่นคำขอจาก dropdown ด้านบนก่อน</p>
      <?php elseif (count($lineItems) < 2): ?>
        <p class="empty-state">สาขานี้ต้องมีอย่างน้อย 2 รายการงบถึงจะโยกย้ายได้</p>
      <?php else: ?>
        <form method="post" action="<?= htmlspecialchars(bpm_url('actions/create-transfer.php'), ENT_QUOTES) ?>" id="transfer-form" class="field-group">
          <?= bpm_csrf_field() ?>
          <input type="hidden" name="fy" value="<?= (int) $fiscalYear['id'] ?>">
          <input type="hidden" name="dept" value="<?= (int) $formDepartmentId ?>">

          <div>
            <label class="field-label" for="from_line_item_id">จากหมวด</label>
            <select name="from_line_item_id" id="from_line_item_id" class="field" onchange="bpmUpdateTransferPreview()" required>
              <?php foreach ($lineItems as $li): ?>
                <option value="<?= (int) $li['id'] ?>"><?= htmlspecialchars($li['name'], ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="field-label" for="to_line_item_id">ไปหมวด</label>
            <select name="to_line_item_id" id="to_line_item_id" class="field" required>
              <?php foreach ($lineItems as $li): ?>
                <option value="<?= (int) $li['id'] ?>"><?= htmlspecialchars($li['name'], ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="field-label" for="amount">จำนวนเงิน (บาท)</label>
            <input type="text" inputmode="decimal" name="amount" id="amount" class="field num" placeholder="0.00" oninput="bpmUpdateTransferPreview()" required>
          </div>

          <div>
            <label class="field-label" for="reason">เหตุผลการโยกย้าย</label>
            <textarea name="reason" id="reason" class="field" required></textarea>
          </div>

          <div>
            <label class="field-label" for="ref_memo_no">เลขที่บันทึกข้อความอ้างอิง (ถ้ามี)</label>
            <input type="text" name="ref_memo_no" id="ref_memo_no" class="field">
          </div>

          <div class="balance-box" id="transfer-balance-box">
            <div class="row total"><span>ยอดคงเหลือหมวดต้นทาง</span><span id="from-balance">-</span></div>
          </div>

          <div class="btn-row">
            <button type="submit" class="btn btn-primary">ยื่นคำขอ</button>
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('transfer-modal').close()">ยกเลิก</button>
          </div>
        </form>

        <script>
          const bpmLineItemBalances2 = <?= json_encode($balanceMap, JSON_NUMERIC_CHECK) ?>;

          function bpmUpdateTransferPreview() {
            const fromId = document.getElementById('from_line_item_id').value;
            const balance = bpmLineItemBalances2[fromId] ?? 0;
            const amount = parseFloat((document.getElementById('amount').value || '0').replace(/,/g, '')) || 0;

            document.getElementById('from-balance').textContent =
              '฿' + balance.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.getElementById('transfer-balance-box').classList.toggle('negative', amount > balance);
          }

          bpmUpdateTransferPreview();
        </script>
      <?php endif; ?>
    </div>
  </dialog>

  <script>
    function bpmConfirmDeleteTransfer(btn) {
      return confirm('ลบคำขอโยกย้ายงบนี้ถาวร?\n\n' + btn.dataset.confirmDesc + '\n\nการลบนี้ย้อนกลับไม่ได้ ถ้าเคยอนุมัติแล้ว ยอดคงเหลือของหมวดที่เกี่ยวข้องจะเปลี่ยนทันที ใช้เฉพาะกรณีข้อมูลผิดพลาดจริงเท่านั้น');
    }

    function bpmOpenTransferModal(lineItemId) {
      const fromSelect = document.getElementById('from_line_item_id');
      if (lineItemId > 0 && fromSelect) {
        fromSelect.value = lineItemId;
        if (typeof bpmUpdateTransferPreview === 'function') bpmUpdateTransferPreview();
      }
      document.getElementById('transfer-modal').showModal();
    }

    const bpmTransferModal = document.getElementById('transfer-modal');
    if (bpmTransferModal) {
      bpmTransferModal.addEventListener('click', function (e) {
        if (e.target === this) this.close();
      });
    }
  </script>

<?php require __DIR__ . '/../src/partials/layout_end.php'; ?>
