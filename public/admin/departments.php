<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$user = bpm_require_role('ADMIN');
$pageTitle = 'ตั้งค่าสาขาวิชา';
$activeNav = 'admin';

$rows = bpm_db()->query('SELECT * FROM departments ORDER BY is_active DESC, name')->fetchAll();

require __DIR__ . '/../../src/partials/layout_start.php';
?>

  <div class="card">
    <h2>สาขาวิชา / หน่วยงาน</h2>

    <?php foreach ($rows as $r): $fid = 'dept-form-' . (int) $r['id']; ?>
      <form id="<?= $fid ?>" method="post" action="/actions/save-lookup.php">
        <?= bpm_csrf_field() ?>
        <input type="hidden" name="table" value="departments">
        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
      </form>
    <?php endforeach; ?>
    <form id="dept-form-new" method="post" action="/actions/save-lookup.php">
      <?= bpm_csrf_field() ?>
      <input type="hidden" name="table" value="departments">
      <input type="hidden" name="is_active" value="1">
    </form>

    <table class="data-table">
      <thead>
        <tr><th>ชื่อ</th><th>รหัส</th><th class="center">สถานะ</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): $fid = 'dept-form-' . (int) $r['id']; ?>
          <tr>
            <td><input type="text" name="name" form="<?= $fid ?>" class="field" value="<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>" required></td>
            <td style="width:140px;"><input type="text" name="code" form="<?= $fid ?>" class="field" value="<?= htmlspecialchars($r['code'], ENT_QUOTES) ?>" required></td>
            <td class="center" style="width:110px;">
              <label style="display:flex; align-items:center; justify-content:center; gap:6px; font-size:12.5px;">
                <input type="checkbox" name="is_active" form="<?= $fid ?>" value="1" <?= $r['is_active'] ? 'checked' : '' ?>> ใช้งาน
              </label>
            </td>
            <td style="width:80px;"><button type="submit" form="<?= $fid ?>" class="btn btn-secondary" style="padding:6px 12px;">บันทึก</button></td>
          </tr>
        <?php endforeach; ?>

        <tr>
          <td><input type="text" name="name" form="dept-form-new" class="field" placeholder="ชื่อสาขาวิชาใหม่" required></td>
          <td style="width:140px;"><input type="text" name="code" form="dept-form-new" class="field" placeholder="เช่น MICRO" required></td>
          <td class="center" style="width:110px; color:var(--text-muted); font-size:12.5px;">ใช้งาน</td>
          <td style="width:80px;"><button type="submit" form="dept-form-new" class="btn btn-primary" style="padding:6px 12px;"><?= bpm_icon('plus', 14) ?></button></td>
        </tr>
      </tbody>
    </table>
    <p class="text-muted small" style="margin-top:14px;">ไม่มีปุ่มลบโดยเจตนา — ปิดการใช้งาน (ยกเลิกติ๊ก "ใช้งาน") แทนเสมอ เพราะสาขาที่มีรายการงบผูกอยู่แล้วลบจริงไม่ได้ (ดู spec.md ข้อ 5.3)</p>
  </div>

<?php require __DIR__ . '/../../src/partials/layout_end.php'; ?>
