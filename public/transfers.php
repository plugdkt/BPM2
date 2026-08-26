<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$user = bpm_require_role('ADMIN', 'DEPT_STAFF');

$fiscalYear = bpm_resolve_fiscal_year();
$selectedDepartmentId = bpm_resolve_department_filter($user);

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

$formDepartmentId = $user['role'] === 'DEPT_STAFF' ? (int) $user['department_id'] : $selectedDepartmentId;
$lineItems = $formDepartmentId !== null ? bpm_line_items_for_department($formDepartmentId, (int) $fiscalYear['id']) : [];

$balanceMap = [];
foreach ($lineItems as $li) {
    $balanceMap[(int) $li['id']] = bpm_line_item_balance((int) $li['id'])['balance'];
}

$statusPill = [
    'PENDING'  => ['pill-warning', 'รออนุมัติ'],
    'APPROVED' => ['pill-success', 'อนุมัติแล้ว'],
    'REJECTED' => ['pill-danger', 'ไม่อนุมัติ'],
];

require __DIR__ . '/../src/partials/layout_start.php';
?>

  <?php if ($pendingCount > 0): ?>
    <p><span class="pill pill-warning">รออนุมัติ <?= $pendingCount ?> รายการ</span></p>
  <?php endif; ?>

  <div class="two-col">
    <div class="card main-panel">
      <h2>รายการคำขอทั้งหมด</h2>

      <?php if (empty($transfers)): ?>
        <p class="empty-state">ยังไม่มีคำขอโยกย้ายงบในปีงบนี้</p>
      <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th class="center">วันที่ยื่น</th>
              <?php if ($selectedDepartmentId === null): ?><th>สาขา</th><?php endif; ?>
              <th>หมวดที่โยกย้าย</th>
              <th class="num">จำนวนเงิน</th>
              <th class="center">สถานะ</th>
              <th>การดำเนินการ</th>
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
                      <form method="post" action="/actions/decide-transfer.php">
                        <?= bpm_csrf_field() ?>
                        <input type="hidden" name="transfer_id" value="<?= (int) $t['id'] ?>">
                        <input type="hidden" name="decision" value="APPROVED">
                        <input type="hidden" name="fy" value="<?= (int) $fiscalYear['id'] ?>">
                        <?php if ($selectedDepartmentId !== null): ?><input type="hidden" name="dept" value="<?= (int) $selectedDepartmentId ?>"><?php endif; ?>
                        <button type="submit" class="icon-btn icon-btn-approve" title="อนุมัติ"><?= bpm_icon('check', 13) ?></button>
                      </form>
                      <form method="post" action="/actions/decide-transfer.php">
                        <?= bpm_csrf_field() ?>
                        <input type="hidden" name="transfer_id" value="<?= (int) $t['id'] ?>">
                        <input type="hidden" name="decision" value="REJECTED">
                        <input type="hidden" name="fy" value="<?= (int) $fiscalYear['id'] ?>">
                        <?php if ($selectedDepartmentId !== null): ?><input type="hidden" name="dept" value="<?= (int) $selectedDepartmentId ?>"><?php endif; ?>
                        <button type="submit" class="icon-btn icon-btn-reject" title="ไม่อนุมัติ"><?= bpm_icon('x', 13) ?></button>
                      </form>
                    </div>
                  <?php elseif ($t['status'] !== 'PENDING'): ?>
                    <span class="text-muted small">โดย <?= htmlspecialchars($t['approved_by_name'] ?? '-', ENT_QUOTES) ?> · <?= htmlspecialchars(bpm_thai_date($t['decided_at']), ENT_QUOTES) ?></span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="card side-panel">
      <h2>ยื่นคำขอโยกย้ายงบใหม่</h2>

      <?php if ($formDepartmentId === null): ?>
        <p class="empty-state">เลือกสาขาที่ต้องการยื่นคำขอจาก dropdown ด้านบนก่อน</p>
      <?php elseif (count($lineItems) < 2): ?>
        <p class="empty-state">สาขานี้ต้องมีอย่างน้อย 2 รายการงบถึงจะโยกย้ายได้</p>
      <?php else: ?>
        <form method="post" action="/actions/create-transfer.php" id="transfer-form" class="field-group">
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
            <a href="/transfers.php" class="btn btn-secondary">ยกเลิก</a>
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
  </div>

<?php require __DIR__ . '/../src/partials/layout_end.php'; ?>
