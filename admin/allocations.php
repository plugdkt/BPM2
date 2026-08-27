<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$user = bpm_require_role('ADMIN');
$pageTitle = 'ตั้งค่างบประมาณ';
$activeNav = 'admin-allocations';

$departments = bpm_all_departments();
$fiscalYears = bpm_all_fiscal_years();

$departmentId = (int) ($_GET['dept'] ?? ($departments[0]['id'] ?? 0));
$fiscalYear = bpm_resolve_fiscal_year();
$fiscalYearId = (int) ($fiscalYear['id'] ?? 0);

$groups = bpm_db()->query('SELECT * FROM budget_groups WHERE is_active = 1 ORDER BY id')->fetchAll();
$lineItems = ($departmentId && $fiscalYearId) ? bpm_line_items_for_department($departmentId, $fiscalYearId) : [];

$inactiveLineItems = [];
if ($departmentId && $fiscalYearId) {
    $stmt = bpm_db()->prepare(
        'SELECT * FROM budget_line_items WHERE department_id = ? AND fiscal_year_id = ? AND is_active = 0 ORDER BY name'
    );
    $stmt->execute([$departmentId, $fiscalYearId]);
    $inactiveLineItems = $stmt->fetchAll();
}

require __DIR__ . '/../../src/partials/layout_start.php';
?>

  <div class="card">
    <h2>เลือกสาขาและปีงบ</h2>
    <form method="get" style="display:flex; gap:10px; flex-wrap:wrap;">
      <select name="dept" class="filter-chip" onchange="this.form.submit()">
        <?php foreach ($departments as $d): ?>
          <option value="<?= (int) $d['id'] ?>" <?= $departmentId === (int) $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name'], ENT_QUOTES) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="fy" class="filter-chip" onchange="this.form.submit()">
        <?php foreach ($fiscalYears as $fy): ?>
          <option value="<?= (int) $fy['id'] ?>" <?= $fiscalYearId === (int) $fy['id'] ? 'selected' : '' ?>>ปีงบประมาณ พ.ศ. <?= (int) $fy['year_be'] ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php if (!$departmentId || !$fiscalYearId): ?>
    <div class="card empty-state">ยังไม่มีสาขาหรือปีงบประมาณในระบบ</div>
  <?php else:
    $renderToggleForm = static function (array $li, int $departmentId, int $fiscalYearId, string $tfid) {
        ?>
        <form id="<?= $tfid ?>" method="post" action="<?= htmlspecialchars(bpm_url('actions/save-line-item.php'), ENT_QUOTES) ?>">
          <?= bpm_csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $li['id'] ?>">
          <input type="hidden" name="department_id" value="<?= $departmentId ?>">
          <input type="hidden" name="fiscal_year_id" value="<?= $fiscalYearId ?>">
          <input type="hidden" name="name" value="<?= htmlspecialchars($li['name'], ENT_QUOTES) ?>">
          <input type="hidden" name="starting_amount" value="<?= number_format((float) $li['starting_amount'], 2, '.', '') ?>">
          <input type="hidden" name="group_id" value="<?= (int) $li['group_id'] ?>">
          <?php if ($li['requires_travel_detail']): ?><input type="hidden" name="requires_travel_detail" value="1"><?php endif; ?>
          <input type="hidden" name="note" value="<?= htmlspecialchars((string) $li['note'], ENT_QUOTES) ?>">
          <?php if (!$li['is_active']): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
        </form>
        <?php
    }; ?>
    <div class="card">
      <h2>รายการงบ</h2>

      <?php foreach ($lineItems as $li): $fid = 'li-form-' . (int) $li['id']; $tfid = 'li-toggle-' . (int) $li['id']; ?>
        <form id="<?= $fid ?>" method="post" action="<?= htmlspecialchars(bpm_url('actions/save-line-item.php'), ENT_QUOTES) ?>">
          <?= bpm_csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $li['id'] ?>">
          <input type="hidden" name="department_id" value="<?= (int) $departmentId ?>">
          <input type="hidden" name="fiscal_year_id" value="<?= (int) $fiscalYearId ?>">
          <input type="hidden" name="is_active" value="1">
        </form>
        <?php $renderToggleForm($li, $departmentId, $fiscalYearId, $tfid); ?>
      <?php endforeach; ?>
      <form id="li-form-new" method="post" action="<?= htmlspecialchars(bpm_url('actions/save-line-item.php'), ENT_QUOTES) ?>">
        <?= bpm_csrf_field() ?>
        <input type="hidden" name="department_id" value="<?= (int) $departmentId ?>">
        <input type="hidden" name="fiscal_year_id" value="<?= (int) $fiscalYearId ?>">
        <input type="hidden" name="is_active" value="1">
      </form>

      <table class="data-table">
        <thead>
          <tr>
            <th>รายการ</th>
            <th class="num" style="width:150px;">งบต้นปี</th>
            <th style="width:150px;">กลุ่มหมวด</th>
            <th class="center" style="width:90px;">เดินทาง</th>
            <th style="width:160px;">หมายเหตุ</th>
            <th style="width:110px;"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lineItems as $li): $fid = 'li-form-' . (int) $li['id']; $tfid = 'li-toggle-' . (int) $li['id']; ?>
            <tr>
              <td><input type="text" name="name" form="<?= $fid ?>" class="field" value="<?= htmlspecialchars($li['name'], ENT_QUOTES) ?>" required></td>
              <td><input type="text" name="starting_amount" form="<?= $fid ?>" class="field num" value="<?= number_format((float) $li['starting_amount'], 2, '.', '') ?>" required></td>
              <td>
                <select name="group_id" form="<?= $fid ?>" class="field">
                  <option value="">— ไม่ระบุ —</option>
                  <?php foreach ($groups as $g): ?>
                    <option value="<?= (int) $g['id'] ?>" <?= (int) $li['group_id'] === (int) $g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['name'], ENT_QUOTES) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td class="center"><input type="checkbox" name="requires_travel_detail" form="<?= $fid ?>" value="1" <?= $li['requires_travel_detail'] ? 'checked' : '' ?>></td>
              <td><input type="text" name="note" form="<?= $fid ?>" class="field" value="<?= htmlspecialchars((string) $li['note'], ENT_QUOTES) ?>"></td>
              <td style="width:110px; display:flex; gap:6px;">
                <button type="submit" form="<?= $fid ?>" class="btn btn-secondary" style="padding:6px 10px;">บันทึก</button>
                <button type="submit" form="<?= $tfid ?>" class="icon-btn icon-btn-reject" title="ปิดการใช้งาน"
                        data-confirm-name="<?= htmlspecialchars($li['name'], ENT_QUOTES) ?>" onclick="return bpmConfirmDeactivate(this)">
                  <?= bpm_icon('trash', 13) ?>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>

          <tr>
            <td><input type="text" name="name" form="li-form-new" class="field" placeholder="ชื่อรายการใหม่" required></td>
            <td><input type="text" name="starting_amount" form="li-form-new" class="field num" placeholder="0.00" required></td>
            <td>
              <select name="group_id" form="li-form-new" class="field">
                <option value="">— ไม่ระบุ —</option>
                <?php foreach ($groups as $g): ?>
                  <option value="<?= (int) $g['id'] ?>"><?= htmlspecialchars($g['name'], ENT_QUOTES) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td class="center"><input type="checkbox" name="requires_travel_detail" form="li-form-new" value="1"></td>
            <td><input type="text" name="note" form="li-form-new" class="field" placeholder="(ถ้ามี)"></td>
            <td style="width:110px;"><button type="submit" form="li-form-new" class="btn btn-primary" style="padding:6px 10px;"><?= bpm_icon('plus', 14) ?></button></td>
          </tr>
        </tbody>
      </table>

      <?php if (!empty($inactiveLineItems)): ?>
        <details style="margin-top:18px;">
          <summary style="cursor:pointer; color:var(--text-muted); font-size:13px; font-weight:500;">รายการที่ปิดใช้งานแล้ว (<?= count($inactiveLineItems) ?>)</summary>
          <table class="data-table" style="margin-top:10px;">
            <tbody>
              <?php foreach ($inactiveLineItems as $li): $tfid = 'li-toggle-inactive-' . (int) $li['id']; $renderToggleForm($li, $departmentId, $fiscalYearId, $tfid); ?>
                <tr>
                  <td class="text-muted"><?= htmlspecialchars($li['name'], ENT_QUOTES) ?></td>
                  <td class="text-muted num" style="width:150px;"><?= htmlspecialchars(bpm_money((float) $li['starting_amount']), ENT_QUOTES) ?></td>
                  <td style="width:110px;">
                    <button type="submit" form="<?= $tfid ?>" class="icon-btn icon-btn-approve" title="เปิดใช้งานอีกครั้ง"
                            data-confirm-name="<?= htmlspecialchars($li['name'], ENT_QUOTES) ?>" onclick="return bpmConfirmRestore(this)">
                      <?= bpm_icon('restore', 13) ?>
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </details>
      <?php endif; ?>

      <p class="text-muted small" style="margin-top:14px;">
        ติ๊ก "เดินทาง" สำหรับรายการที่ต้องกรอกรายละเอียดผู้เดินทางทุกครั้งที่บันทึกรายจ่าย (เช่น ค่าเบี้ยเลี้ยง ค่าที่พัก และค่าพาหนะ — ดู spec.md ข้อ 6.6)<br>
        ไอคอน <?= bpm_icon('trash', 12) ?> ปิดการใช้งานรายการนั้น (ไม่ลบข้อมูลจริง กู้คืนได้เสมอที่ "รายการที่ปิดใช้งานแล้ว" ด้านบน) — รายการที่ปิดใช้งานจะหายไปจากทุกรายงาน/ภาพรวมทันที แต่ธุรกรรมเก่าที่เคยบันทึกไว้ยังอยู่ครบ
      </p>
    </div>

    <script>
      function bpmConfirmDeactivate(btn) {
        return confirm('ปิดการใช้งาน "' + btn.dataset.confirmName + '"?\n\nข้อมูลจะไม่ถูกลบจริง สามารถกู้คืนได้ภายหลัง');
      }
      function bpmConfirmRestore(btn) {
        return confirm('เปิดใช้งาน "' + btn.dataset.confirmName + '" กลับมาไหม?');
      }
    </script>
  <?php endif; ?>

<?php require __DIR__ . '/../../src/partials/layout_end.php'; ?>
