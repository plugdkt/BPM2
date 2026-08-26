<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$user = bpm_require_role('ADMIN');
$pageTitle = 'ตั้งค่ากลุ่มหมวดงบ';
$activeNav = 'admin';

$rows = bpm_db()->query('SELECT * FROM budget_groups ORDER BY is_active DESC, id')->fetchAll();

require __DIR__ . '/../../src/partials/layout_start.php';
?>

  <div class="card">
    <h2>กลุ่มหมวดงบ</h2>
    <p class="text-muted small" style="margin-top:-8px; margin-bottom:16px;">
      ใช้แค่จัดกลุ่ม "รายการงบ" เพื่อทำกราฟสรุปภาพรวมบน dashboard เท่านั้น ไม่ใช่ตัวกำหนดการจัดสรร (ดู spec.md ข้อ 6.3)
    </p>

    <?php foreach ($rows as $r): $fid = 'grp-form-' . (int) $r['id']; ?>
      <form id="<?= $fid ?>" method="post" action="/actions/save-lookup.php">
        <?= bpm_csrf_field() ?>
        <input type="hidden" name="table" value="budget_groups">
        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
      </form>
    <?php endforeach; ?>
    <form id="grp-form-new" method="post" action="/actions/save-lookup.php">
      <?= bpm_csrf_field() ?>
      <input type="hidden" name="table" value="budget_groups">
      <input type="hidden" name="is_active" value="1">
    </form>

    <table class="data-table">
      <thead>
        <tr><th>ชื่อ</th><th>รหัส</th><th class="center">สถานะ</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): $fid = 'grp-form-' . (int) $r['id']; ?>
          <tr>
            <td><input type="text" name="name" form="<?= $fid ?>" class="field" value="<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>" required></td>
            <td style="width:160px;"><input type="text" name="code" form="<?= $fid ?>" class="field" value="<?= htmlspecialchars($r['code'], ENT_QUOTES) ?>" required></td>
            <td class="center" style="width:110px;">
              <label style="display:flex; align-items:center; justify-content:center; gap:6px; font-size:12.5px;">
                <input type="checkbox" name="is_active" form="<?= $fid ?>" value="1" <?= $r['is_active'] ? 'checked' : '' ?>> ใช้งาน
              </label>
            </td>
            <td style="width:80px;"><button type="submit" form="<?= $fid ?>" class="btn btn-secondary" style="padding:6px 12px;">บันทึก</button></td>
          </tr>
        <?php endforeach; ?>

        <tr>
          <td><input type="text" name="name" form="grp-form-new" class="field" placeholder="ชื่อกลุ่มใหม่" required></td>
          <td style="width:160px;"><input type="text" name="code" form="grp-form-new" class="field" placeholder="เช่น OTHER" required></td>
          <td class="center" style="width:110px; color:var(--text-muted); font-size:12.5px;">ใช้งาน</td>
          <td style="width:80px;"><button type="submit" form="grp-form-new" class="btn btn-primary" style="padding:6px 12px;"><?= bpm_icon('plus', 14) ?></button></td>
        </tr>
      </tbody>
    </table>
  </div>

<?php require __DIR__ . '/../../src/partials/layout_end.php'; ?>
