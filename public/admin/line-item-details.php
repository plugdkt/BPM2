<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$user = bpm_require_role('ADMIN');
$pageTitle = 'รายละเอียดรายการงบ';
$activeNav = 'admin-allocations';

// ห้ามใช้ชื่อตัวแปร $item — layout_start.php ใช้ $item เป็นตัวแปร loop ของเมนู จะทับค่าตอนเรนเดอร์ sidebar
$itemId = (int) ($_GET['item'] ?? 0);
$db = bpm_db();

$stmt = $db->prepare(
    'SELECT li.*, d.name AS department_name, fy.year_be, fy.status AS fy_status,
            fs.name AS fund_source_name, fs.code AS fund_source_code, g.name AS group_name
     FROM budget_line_items li
     JOIN departments d ON d.id = li.department_id
     JOIN fiscal_years fy ON fy.id = li.fiscal_year_id
     JOIN fund_sources fs ON fs.id = li.fund_source_id
     LEFT JOIN budget_groups g ON g.id = li.group_id
     WHERE li.id = ?'
);
$stmt->execute([$itemId]);
$lineItem = $stmt->fetch();

$supports = $lineItem && bpm_group_supports_details($lineItem['group_id'] !== null ? (int) $lineItem['group_id'] : null);
$closed = $lineItem && $lineItem['fy_status'] === 'CLOSED';

$rows = [];
if ($lineItem) {
    $d = $db->prepare('SELECT * FROM line_item_details WHERE line_item_id = ? ORDER BY is_active DESC, id');
    $d->execute([$itemId]);
    $rows = $d->fetchAll();
}
$activeRows = array_values(array_filter($rows, static fn ($r) => (int) $r['is_active'] === 1));
$inactiveRows = array_values(array_filter($rows, static fn ($r) => (int) $r['is_active'] === 0));
$detailTotal = array_sum(array_map(static fn ($r) => (float) $r['amount'], $activeRows));

$backUrl = $lineItem ? bpm_url('admin/allocations.php?') . http_build_query(['dept' => (int) $lineItem['department_id'], 'fy' => (int) $lineItem['fiscal_year_id'], 'source' => (int) $lineItem['fund_source_id']]) : bpm_url('admin/allocations.php');
$formAction = htmlspecialchars(bpm_url('actions/save-line-item-detail.php'), ENT_QUOTES);
$qtyFmt = static fn ($q): string => rtrim(rtrim(number_format((float) $q, 2, '.', ''), '0'), '.');
$money = static fn ($v): string => htmlspecialchars(bpm_money((float) $v), ENT_QUOTES);

require __DIR__ . '/../../src/partials/layout_start.php';
?>

  <p><a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>">&larr; กลับไปตั้งค่างบ</a></p>

  <?php if (!$lineItem): ?>
    <div class="card empty-state">ไม่พบรายการงบที่ต้องการ</div>
  <?php elseif (!$supports): ?>
    <div class="card empty-state">
      รายการ "<?= htmlspecialchars($lineItem['name'], ENT_QUOTES) ?>" ไม่ได้อยู่ในหมวด "ค่าครุภัณฑ์" จึงเพิ่มรายละเอียดไม่ได้<br>
      <span class="text-muted small">ตั้งหมวดของรายการเป็น "ค่าครุภัณฑ์" ที่หน้า "ตั้งค่างบ" ก่อน</span>
    </div>
  <?php else: ?>
    <div class="card">
      <h2><?= htmlspecialchars($lineItem['name'], ENT_QUOTES) ?></h2>
      <p class="text-muted small" style="margin-top:-8px;">
        <?= htmlspecialchars($lineItem['department_name'], ENT_QUOTES) ?> · ปีงบประมาณ พ.ศ. <?= (int) $lineItem['year_be'] ?>
        · หมวด <?= htmlspecialchars((string) $lineItem['group_name'], ENT_QUOTES) ?>
        <?php if ($lineItem['fund_source_code'] !== 'UNSPECIFIED'): ?> · แหล่งเงิน <?= htmlspecialchars($lineItem['fund_source_name'], ENT_QUOTES) ?><?php endif; ?>
        <?php if ($closed): ?> · <strong>ปีงบนี้ปิดแล้ว แก้ไขไม่ได้</strong><?php endif; ?>
      </p>
      <div class="kpi-row" style="margin-bottom:0;">
        <div class="kpi-card"><div class="label">งบต้นปีของรายการ</div><div class="value"><?= $money($lineItem['starting_amount']) ?></div></div>
        <div class="kpi-card"><div class="label">รวมตามรายละเอียด (<?= count($activeRows) ?> รายการ)</div><div class="value"><?= $money($detailTotal) ?></div></div>
        <?php $diff = (float) $lineItem['starting_amount'] - $detailTotal; ?>
        <div class="kpi-card <?= abs($diff) < 0.005 ? 'highlight' : '' ?>">
          <div class="label">ส่วนต่าง</div>
          <div class="value" style="<?= $diff < -0.005 ? 'color: var(--status-danger-text);' : '' ?>"><?= $money($diff) ?></div>
          <div class="sub">
            <?php if (abs($diff) < 0.005): ?>รายละเอียดครบตรงกับงบต้นปี
            <?php elseif ($diff > 0): ?>ยังลงรายละเอียดไม่ครบตามงบต้นปี
            <?php else: ?>รายละเอียดรวมเกินงบต้นปี — ตรวจสอบหรือปรับงบต้นปีที่หน้า "ตั้งค่างบ"<?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <h2>รายการครุภัณฑ์</h2>

      <?php foreach ($activeRows as $r): $fid = 'det-' . (int) $r['id']; $tfid = 'det-off-' . (int) $r['id']; ?>
        <form id="<?= $fid ?>" method="post" action="<?= $formAction ?>">
          <?= bpm_csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <input type="hidden" name="line_item_id" value="<?= $itemId ?>">
          <input type="hidden" name="is_active" value="1">
        </form>
        <form id="<?= $tfid ?>" method="post" action="<?= $formAction ?>">
          <?= bpm_csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <input type="hidden" name="line_item_id" value="<?= $itemId ?>">
          <input type="hidden" name="name" value="<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>">
          <input type="hidden" name="quantity" value="<?= $qtyFmt($r['quantity']) ?>">
          <input type="hidden" name="unit" value="<?= htmlspecialchars((string) $r['unit'], ENT_QUOTES) ?>">
          <input type="hidden" name="unit_price" value="<?= number_format((float) $r['unit_price'], 2, '.', '') ?>">
          <input type="hidden" name="note" value="<?= htmlspecialchars((string) $r['note'], ENT_QUOTES) ?>">
        </form>
      <?php endforeach; ?>
      <form id="det-new" method="post" action="<?= $formAction ?>">
        <?= bpm_csrf_field() ?>
        <input type="hidden" name="line_item_id" value="<?= $itemId ?>">
      </form>

      <div style="overflow-x:auto;">
      <table class="data-table">
        <thead>
          <tr>
            <th>รายการ</th>
            <th class="num" style="width:90px;">จำนวน</th>
            <th style="width:90px;">หน่วย</th>
            <th class="num" style="width:140px;">ราคาต่อหน่วย</th>
            <th class="num" style="width:140px;">รวมเป็นเงิน</th>
            <th style="width:170px;">หมายเหตุ</th>
            <th style="width:110px;"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($activeRows as $r): $fid = 'det-' . (int) $r['id']; $tfid = 'det-off-' . (int) $r['id']; ?>
            <tr data-row>
              <td><input type="text" name="name" form="<?= $fid ?>" class="field" value="<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>" required <?= $closed ? 'disabled' : '' ?>></td>
              <td><input type="text" inputmode="decimal" name="quantity" form="<?= $fid ?>" class="field num js-qty" value="<?= $qtyFmt($r['quantity']) ?>" required <?= $closed ? 'disabled' : '' ?>></td>
              <td><input type="text" name="unit" form="<?= $fid ?>" class="field" value="<?= htmlspecialchars((string) $r['unit'], ENT_QUOTES) ?>" <?= $closed ? 'disabled' : '' ?>></td>
              <td><input type="text" inputmode="decimal" name="unit_price" form="<?= $fid ?>" class="field num js-price" value="<?= number_format((float) $r['unit_price'], 2, '.', '') ?>" required <?= $closed ? 'disabled' : '' ?>></td>
              <td class="num js-total"><?= $money($r['amount']) ?></td>
              <td><input type="text" name="note" form="<?= $fid ?>" class="field" value="<?= htmlspecialchars((string) $r['note'], ENT_QUOTES) ?>" <?= $closed ? 'disabled' : '' ?>></td>
              <td style="display:flex; gap:6px;">
                <?php if (!$closed): ?>
                  <button type="submit" form="<?= $fid ?>" class="btn btn-secondary" style="padding:6px 10px;">บันทึก</button>
                  <button type="submit" form="<?= $tfid ?>" class="icon-btn icon-btn-reject" title="ปิดการใช้งาน"
                          data-confirm-name="<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>" onclick="return bpmConfirmOff(this)"><?= bpm_icon('trash', 13) ?></button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (!$closed): ?>
            <tr data-row>
              <td><input type="text" name="name" form="det-new" class="field" placeholder="ชื่อครุภัณฑ์ใหม่" required></td>
              <td><input type="text" inputmode="decimal" name="quantity" form="det-new" class="field num js-qty" value="1" required></td>
              <td><input type="text" name="unit" form="det-new" class="field" placeholder="เช่น เครื่อง"></td>
              <td><input type="text" inputmode="decimal" name="unit_price" form="det-new" class="field num js-price" placeholder="0.00" required></td>
              <td class="num js-total text-muted">—</td>
              <td><input type="text" name="note" form="det-new" class="field" placeholder="(ถ้ามี)"></td>
              <td><button type="submit" form="det-new" class="btn btn-primary" style="padding:6px 10px;"><?= bpm_icon('plus', 14) ?></button></td>
            </tr>
          <?php endif; ?>
        </tbody>
        <tfoot>
          <tr style="font-weight:600;"><td colspan="4">รวมทั้งหมด</td><td class="num"><?= $money($detailTotal) ?></td><td colspan="2"></td></tr>
        </tfoot>
      </table>
      </div>

      <?php if (!empty($inactiveRows)): ?>
        <details style="margin-top:18px;">
          <summary style="cursor:pointer; color:var(--text-muted); font-size:13px; font-weight:500;">รายการที่ปิดใช้งานแล้ว (<?= count($inactiveRows) ?>)</summary>
          <table class="data-table" style="margin-top:10px;">
            <tbody>
              <?php foreach ($inactiveRows as $r): ?>
                <tr>
                  <td class="text-muted"><?= htmlspecialchars($r['name'], ENT_QUOTES) ?></td>
                  <td class="text-muted num" style="width:120px;"><?= $qtyFmt($r['quantity']) ?> <?= htmlspecialchars((string) $r['unit'], ENT_QUOTES) ?></td>
                  <td class="text-muted num" style="width:140px;"><?= $money($r['amount']) ?></td>
                  <td style="width:60px;">
                    <?php if (!$closed): ?>
                      <form method="post" action="<?= $formAction ?>" onsubmit="return confirm('เปิดใช้งานรายการนี้กลับมาไหม?');">
                        <?= bpm_csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <input type="hidden" name="line_item_id" value="<?= $itemId ?>">
                        <input type="hidden" name="name" value="<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>">
                        <input type="hidden" name="quantity" value="<?= $qtyFmt($r['quantity']) ?>">
                        <input type="hidden" name="unit" value="<?= htmlspecialchars((string) $r['unit'], ENT_QUOTES) ?>">
                        <input type="hidden" name="unit_price" value="<?= number_format((float) $r['unit_price'], 2, '.', '') ?>">
                        <input type="hidden" name="note" value="<?= htmlspecialchars((string) $r['note'], ENT_QUOTES) ?>">
                        <input type="hidden" name="is_active" value="1">
                        <button type="submit" class="icon-btn icon-btn-approve" title="เปิดใช้งานอีกครั้ง"><?= bpm_icon('restore', 13) ?></button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </details>
      <?php endif; ?>

      <p class="text-muted small" style="margin-top:14px;">
        "รวมเป็นเงิน" = จำนวน × ราคาต่อหน่วย (ระบบคำนวณให้) รายละเอียดนี้ใช้เทียบกับงบต้นปีของรายการเท่านั้น ไม่กระทบยอดคงเหลือ/การเบิกจ่าย
        ไอคอน <?= bpm_icon('trash', 12) ?> ปิดการใช้งานรายการ (ไม่ลบจริง กู้คืนได้จาก "รายการที่ปิดใช้งานแล้ว")
      </p>
    </div>

    <script>
      function bpmConfirmOff(btn) {
        return confirm('ปิดการใช้งาน "' + btn.dataset.confirmName + '"?\n\nข้อมูลจะไม่ถูกลบจริง กู้คืนได้ภายหลัง');
      }
      function bpmRecalcRow(tr) {
        const q = parseFloat((tr.querySelector('.js-qty').value || '0').replace(/,/g, '')) || 0;
        const p = parseFloat((tr.querySelector('.js-price').value || '0').replace(/,/g, '')) || 0;
        const cell = tr.querySelector('.js-total');
        cell.textContent = (q > 0 && p > 0) ? '฿' + (Math.round(q * p * 100) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '—';
      }
      document.querySelectorAll('tr[data-row]').forEach(function (tr) {
        tr.querySelectorAll('.js-qty, .js-price').forEach(function (el) { el.addEventListener('input', function () { bpmRecalcRow(tr); }); });
      });
    </script>
  <?php endif; ?>

<?php require __DIR__ . '/../../src/partials/layout_end.php'; ?>
