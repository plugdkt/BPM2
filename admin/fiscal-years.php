<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$user = bpm_require_role('ADMIN');
$pageTitle = 'ตั้งค่าปีงบประมาณ';
$activeNav = 'admin';

$rows = bpm_db()->query('SELECT * FROM fiscal_years ORDER BY year_be DESC')->fetchAll();

require __DIR__ . '/../../src/partials/layout_start.php';
?>

  <div class="card">
    <h2>ปีงบประมาณ</h2>
    <table class="data-table">
      <thead>
        <tr><th>ปีงบประมาณ</th><th>ช่วงวันที่</th><th class="center">สถานะ</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $fy): ?>
          <tr>
            <td>พ.ศ. <?= (int) $fy['year_be'] ?></td>
            <td><?= htmlspecialchars(bpm_thai_date($fy['start_date']), ENT_QUOTES) ?> – <?= htmlspecialchars(bpm_thai_date($fy['end_date']), ENT_QUOTES) ?></td>
            <td class="center">
              <span class="pill <?= $fy['status'] === 'OPEN' ? 'pill-success' : 'pill-neutral' ?>"><?= $fy['status'] === 'OPEN' ? 'เปิดใช้งาน' : 'ปิดแล้ว' ?></span>
            </td>
            <td>
              <?php if ($fy['status'] === 'OPEN'): ?>
                <form method="post" action="<?= htmlspecialchars(bpm_url('actions/close-fiscal-year.php'), ENT_QUOTES) ?>"
                      onsubmit="return confirm('ปิดปีงบประมาณ พ.ศ. <?= (int) $fy['year_be'] ?>? หลังปิดแล้วจะบันทึก/แก้ไขรายการของปีงบนี้ไม่ได้อีก');">
                  <?= bpm_csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $fy['id'] ?>">
                  <button type="submit" class="btn btn-secondary" style="padding:6px 12px;">ปิดปีงบ</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card" style="max-width:360px;">
    <h2>สร้างปีงบประมาณใหม่</h2>
    <form method="post" action="<?= htmlspecialchars(bpm_url('actions/create-fiscal-year.php'), ENT_QUOTES) ?>" class="field-group">
      <?= bpm_csrf_field() ?>
      <div>
        <label class="field-label" for="year_be">ปี พ.ศ.</label>
        <input type="number" name="year_be" id="year_be" class="field" placeholder="เช่น 2571" required>
      </div>
      <p class="text-muted small">ระบบคำนวณช่วงวันที่ให้อัตโนมัติ (1 ต.ค. ปีก่อนหน้า – 30 ก.ย. ของปีนี้)</p>
      <button type="submit" class="btn btn-primary">สร้างปีงบประมาณ</button>
    </form>
  </div>

<?php require __DIR__ . '/../../src/partials/layout_end.php'; ?>

