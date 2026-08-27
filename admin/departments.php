<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$user = bpm_require_role('ADMIN');
$pageTitle = 'ตั้งค่าสาขาวิชา';
$activeNav = 'admin-departments';

$rows = bpm_db()->query('SELECT * FROM departments ORDER BY is_active DESC, name')->fetchAll();
$activeRows = array_values(array_filter($rows, static fn ($r) => (int) $r['is_active'] === 1));
$inactiveRows = array_values(array_filter($rows, static fn ($r) => (int) $r['is_active'] === 0));

require __DIR__ . '/../../src/partials/layout_start.php';
?>

  <div class="card">
    <h2>สาขาวิชา / หน่วยงาน</h2>

    <?php foreach ($rows as $r): $fid = 'dept-form-' . (int) $r['id']; $tfid = 'dept-toggle-' . (int) $r['id']; ?>
      <form id="<?= $fid ?>" method="post" action="<?= htmlspecialchars(bpm_url('actions/save-lookup.php'), ENT_QUOTES) ?>">
        <?= bpm_csrf_field() ?>
        <input type="hidden" name="table" value="departments">
        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <input type="hidden" name="is_active" value="<?= (int) $r['is_active'] ?>">
      </form>
      <form id="<?= $tfid ?>" method="post" action="<?= htmlspecialchars(bpm_url('actions/save-lookup.php'), ENT_QUOTES) ?>">
        <?= bpm_csrf_field() ?>
        <input type="hidden" name="table" value="departments">
        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <input type="hidden" name="name" value="<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>">
        <input type="hidden" name="code" value="<?= htmlspecialchars($r['code'], ENT_QUOTES) ?>">
        <?php if (!$r['is_active']): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
      </form>
    <?php endforeach; ?>
    <form id="dept-form-new" method="post" action="<?= htmlspecialchars(bpm_url('actions/save-lookup.php'), ENT_QUOTES) ?>">
      <?= bpm_csrf_field() ?>
      <input type="hidden" name="table" value="departments">
      <input type="hidden" name="is_active" value="1">
    </form>

    <table class="data-table">
      <thead>
        <tr><th>ชื่อ</th><th>รหัส</th><th style="width:110px;"></th></tr>
      </thead>
      <tbody>
        <?php foreach ($activeRows as $r): $fid = 'dept-form-' . (int) $r['id']; $tfid = 'dept-toggle-' . (int) $r['id']; ?>
          <tr>
            <td><input type="text" name="name" form="<?= $fid ?>" class="field" value="<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>" required></td>
            <td style="width:140px;"><input type="text" name="code" form="<?= $fid ?>" class="field" value="<?= htmlspecialchars($r['code'], ENT_QUOTES) ?>" required></td>
            <td style="width:110px; display:flex; gap:6px;">
              <button type="submit" form="<?= $fid ?>" class="btn btn-secondary" style="padding:6px 12px;">บันทึก</button>
              <button type="submit" form="<?= $tfid ?>" class="icon-btn icon-btn-reject" title="ปิดการใช้งาน"
                      data-confirm-name="<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>" onclick="return bpmConfirmDeactivate(this)">
                <?= bpm_icon('trash', 13) ?>
              </button>
            </td>
          </tr>
        <?php endforeach; ?>

        <tr>
          <td><input type="text" name="name" form="dept-form-new" class="field" placeholder="ชื่อสาขาวิชาใหม่" required></td>
          <td style="width:140px;"><input type="text" name="code" form="dept-form-new" class="field" placeholder="เช่น MICRO" required></td>
          <td style="width:110px;"><button type="submit" form="dept-form-new" class="btn btn-primary" style="padding:6px 12px;"><?= bpm_icon('plus', 14) ?></button></td>
        </tr>
      </tbody>
    </table>

    <?php if (!empty($inactiveRows)): ?>
      <details style="margin-top:18px;">
        <summary style="cursor:pointer; color:var(--text-muted); font-size:13px; font-weight:500;">รายการที่ปิดใช้งานแล้ว (<?= count($inactiveRows) ?>)</summary>
        <table class="data-table" style="margin-top:10px;">
          <tbody>
            <?php foreach ($inactiveRows as $r): $tfid = 'dept-toggle-' . (int) $r['id']; ?>
              <tr>
                <td class="text-muted"><?= htmlspecialchars($r['name'], ENT_QUOTES) ?></td>
                <td class="text-muted" style="width:140px;"><?= htmlspecialchars($r['code'], ENT_QUOTES) ?></td>
                <td style="width:110px;">
                  <button type="submit" form="<?= $tfid ?>" class="icon-btn icon-btn-approve" title="เปิดใช้งานอีกครั้ง"
                          data-confirm-name="<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>" onclick="return bpmConfirmRestore(this)">
                    <?= bpm_icon('restore', 13) ?>
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </details>
    <?php endif; ?>

    <p class="text-muted small" style="margin-top:14px;">ไม่มีปุ่มลบข้อมูลถาวรโดยเจตนา — ไอคอน <?= bpm_icon('trash', 12) ?> "ปิดการใช้งาน" จะซ่อนรายการออกจากทุกหน้าแต่เก็บประวัติไว้ครบ กู้คืนได้เสมอที่ "รายการที่ปิดใช้งานแล้ว" ด้านล่าง (ดู spec.md ข้อ 5.3)</p>
  </div>

  <script>
    function bpmConfirmDeactivate(btn) {
      return confirm('ปิดการใช้งาน "' + btn.dataset.confirmName + '"?\n\nข้อมูลจะไม่ถูกลบจริง สามารถกู้คืนได้ภายหลัง');
    }
    function bpmConfirmRestore(btn) {
      return confirm('เปิดใช้งาน "' + btn.dataset.confirmName + '" กลับมาไหม?');
    }
  </script>

<?php require __DIR__ . '/../../src/partials/layout_end.php'; ?>
