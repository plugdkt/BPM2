<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$user = bpm_require_role('ADMIN', 'DEPT_STAFF');

$fiscalYear = bpm_resolve_fiscal_year();
$selectedDepartmentId = bpm_resolve_department_filter($user);
$page = max(1, (int) ($_GET['page'] ?? 1));
$search = trim((string) ($_GET['q'] ?? ''));

$pageTitle = 'บันทึกเบิกจ่าย / รายรับ';
$activeNav = 'transactions';

if ($fiscalYear === null) {
    require __DIR__ . '/../src/partials/layout_start.php';
    echo '<div class="card empty-state">ยังไม่มีปีงบประมาณในระบบ</div>';
    require __DIR__ . '/../src/partials/layout_end.php';
    exit;
}

$listing = bpm_list_transactions($selectedDepartmentId, (int) $fiscalYear['id'], $page, 50, $search);

// สาขาเป้าหมายสำหรับฟอร์มบันทึกใหม่: DEPT_STAFF = สาขาตัวเองเสมอ, ADMIN = ต้องเลือกสาขาเจาะจงก่อน (เลือก "ทั้งหมด" บันทึกไม่ได้)
$formDepartmentId = $user['role'] === 'DEPT_STAFF' ? (int) $user['department_id'] : $selectedDepartmentId;
$lineItems = $formDepartmentId !== null ? bpm_line_items_for_department($formDepartmentId, (int) $fiscalYear['id']) : [];

$balanceMap = [];
foreach ($lineItems as $li) {
    $balanceMap[(int) $li['id']] = bpm_line_item_balance((int) $li['id'])['balance'];
}

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
    'fy' => $_GET['fy'] ?? null, 'dept' => $_GET['dept'] ?? null, 'page' => $page, 'q' => $search ?: null, 'edit' => $id,
]));
$tabQs = static fn (int $deptId) => http_build_query(array_filter(['fy' => $_GET['fy'] ?? null, 'dept' => $deptId]));
?>

  <?php if ($user['role'] !== 'DEPT_STAFF'): ?>
    <div class="card">
      <div style="display:flex; gap:8px; flex-wrap:wrap; overflow-x:auto;">
        <?php foreach (bpm_all_departments() as $d): ?>
          <a href="?<?= $tabQs((int) $d['id']) ?>" class="filter-chip" style="<?= $selectedDepartmentId === (int) $d['id'] ? 'background:var(--accent); color:#fff;' : '' ?>"><?= htmlspecialchars($d['name'], ENT_QUOTES) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="two-col">
    <div class="card main-panel">
      <div class="table-toolbar">
        <h2 style="margin:0;">รายการทั้งหมด — ปีงบ พ.ศ. <?= (int) $fiscalYear['year_be'] ?></h2>
        <form method="get" class="search-box">
          <?php foreach (['fy' => $_GET['fy'] ?? null, 'dept' => $_GET['dept'] ?? null] as $k => $v): if ($v !== null): ?>
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
                $qs = array_filter(['fy' => $_GET['fy'] ?? null, 'dept' => $_GET['dept'] ?? null, 'q' => $search ?: null, 'page' => $p]); ?>
              <a href="?<?= http_build_query($qs) ?>" class="filter-chip" style="<?= $p === $listing['page'] ? 'background:var(--accent); color:#fff;' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="card side-panel">
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
            <a href="?<?= http_build_query(array_filter(['fy' => $_GET['fy'] ?? null, 'dept' => $_GET['dept'] ?? null])) ?>" class="btn btn-secondary">ยกเลิก</a>
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
                <option value="<?= (int) $li['id'] ?>" data-travel="<?= (int) $li['requires_travel_detail'] ?>">
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
            <a href="/transactions.php" class="btn btn-secondary">ยกเลิก</a>
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
  </div>

  <script>
    function bpmConfirmDeleteTxn(btn) {
      return confirm('ลบรายการนี้ถาวร?\n\n' + btn.dataset.confirmDesc + '\n\nการลบนี้ย้อนกลับไม่ได้ (ต่างจากที่อื่นในระบบที่แค่ปิดใช้งาน) ใช้เฉพาะกรณีข้อมูลผิดพลาดจริงเท่านั้น');
    }
  </script>

<?php require __DIR__ . '/../src/partials/layout_end.php'; ?>
