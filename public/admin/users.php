<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$user = bpm_require_role('ADMIN');
$pageTitle = 'จัดการผู้ใช้';
$activeNav = 'admin-users';

$rows = bpm_db()->query('SELECT * FROM users ORDER BY (role IS NULL) DESC, is_active DESC, name')->fetchAll();
$departments = bpm_all_departments();

require __DIR__ . '/../../src/partials/layout_start.php';
?>

  <div class="card">
    <h2>เพิ่มผู้ใช้ล่วงหน้า</h2>
    <p class="text-muted small" style="margin-top:-8px; margin-bottom:16px;">
      กำหนดสิทธิ์ล่วงหน้าโดยที่คนนั้นยังไม่เคย login เข้าระบบเลยก็ได้ — ต้องรู้ username บัญชี UP Account ของเขาให้ถูกต้อง (เช่น <code>wittaya.su</code>)
      พอเขา login ครั้งแรกจริงจะได้สิทธิ์ที่ตั้งไว้ทันที ไม่ต้องรอ ADMIN มากำหนดซ้ำ — ถ้าพิมพ์ username ผิด บัญชีนั้นจะไม่ได้สิทธิ์ (แก้ไขทีหลังได้จากตารางด้านล่าง)
    </p>
    <form method="post" action="<?= htmlspecialchars(bpm_url('actions/create-pending-user.php'), ENT_QUOTES) ?>" id="pending-user-form" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
      <?= bpm_csrf_field() ?>
      <div>
        <label class="field-label" for="new_sso_username">Username บัญชี UP Account</label>
        <input type="text" name="sso_username" id="new_sso_username" class="field" placeholder="เช่น wittaya.su" required>
      </div>
      <div>
        <label class="field-label" for="new_role">สิทธิ์</label>
        <select name="role" id="new_role" class="field" onchange="document.getElementById('new-dept-wrap').style.display = this.value === 'DEPT_STAFF' ? '' : 'none';">
          <option value="">— ยังไม่กำหนด —</option>
          <option value="ADMIN">ผู้ดูแลระบบ (ADMIN)</option>
          <option value="DEPT_STAFF">เจ้าหน้าที่สาขา (DEPT_STAFF)</option>
          <option value="EXECUTIVE_VIEWER">ผู้บริหาร (EXECUTIVE_VIEWER)</option>
        </select>
      </div>
      <div id="new-dept-wrap" style="display:none;">
        <label class="field-label" for="new_department_id">สาขา</label>
        <select name="department_id" id="new_department_id" class="field">
          <?php foreach ($departments as $d): ?>
            <option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['name'], ENT_QUOTES) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary"><?= bpm_icon('plus', 14) ?> เพิ่มผู้ใช้</button>
    </form>
  </div>

  <div class="card">
    <h2>บัญชีที่ล็อกอินผ่าน SSO ทั้งหมด</h2>
    <p class="text-muted small" style="margin-top:-8px; margin-bottom:16px;">
      ไม่มีฟีเจอร์ตั้ง/reset รหัสผ่านที่นี่ เพราะรหัสผ่านอยู่ที่บัญชี UP Account เท่านั้น (ดู spec.md ข้อ 7)
    </p>

    <?php foreach ($rows as $r): $fid = 'user-form-' . (int) $r['id']; ?>
      <form id="<?= $fid ?>" method="post" action="<?= htmlspecialchars(bpm_url('actions/save-user-role.php'), ENT_QUOTES) ?>">
        <?= bpm_csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
      </form>
    <?php endforeach; ?>

    <table class="data-table">
      <thead>
        <tr>
          <th>ชื่อ</th>
          <th>บัญชี UP Account</th>
          <th>ตำแหน่ง/สังกัด (จาก SSO)</th>
          <th>สิทธิ์</th>
          <th>สาขา</th>
          <th class="center">ใช้งาน</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): $fid = 'user-form-' . (int) $r['id']; $selectId = "role-{$r['id']}"; $deptWrapId = "dept-wrap-{$r['id']}"; ?>
          <tr>
            <td>
              <?= htmlspecialchars($r['name'], ENT_QUOTES) ?>
              <?php if ($r['last_login_at'] === null): ?><br><span class="pill pill-neutral">ยังไม่เคย login</span>
              <?php elseif ($r['role'] === null): ?><br><span class="pill pill-warning">รอกำหนดสิทธิ์</span><?php endif; ?>
            </td>
            <td class="text-muted"><?= htmlspecialchars($r['sso_username'], ENT_QUOTES) ?></td>
            <td class="text-muted small"><?= htmlspecialchars(trim(($r['pos_name'] ?? '') . ' · ' . ($r['div_name'] ?? '')), ENT_QUOTES) ?></td>
            <td>
              <select name="role" id="<?= $selectId ?>" form="<?= $fid ?>" class="field" onchange="bpmToggleDeptSelect('<?= $r['id'] ?>')">
                <option value="" <?= $r['role'] === null ? 'selected' : '' ?>>— ยังไม่กำหนด —</option>
                <option value="ADMIN" <?= $r['role'] === 'ADMIN' ? 'selected' : '' ?>>ผู้ดูแลระบบ (ADMIN)</option>
                <option value="DEPT_STAFF" <?= $r['role'] === 'DEPT_STAFF' ? 'selected' : '' ?>>เจ้าหน้าที่สาขา (DEPT_STAFF)</option>
                <option value="EXECUTIVE_VIEWER" <?= $r['role'] === 'EXECUTIVE_VIEWER' ? 'selected' : '' ?>>ผู้บริหาร (EXECUTIVE_VIEWER)</option>
              </select>
            </td>
            <td id="<?= $deptWrapId ?>" style="<?= $r['role'] !== 'DEPT_STAFF' ? 'display:none;' : '' ?>">
              <select name="department_id" form="<?= $fid ?>" class="field">
                <?php foreach ($departments as $d): ?>
                  <option value="<?= (int) $d['id'] ?>" <?= (int) $r['department_id'] === (int) $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name'], ENT_QUOTES) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td class="center">
              <input type="checkbox" name="is_active" form="<?= $fid ?>" value="1" <?= $r['is_active'] ? 'checked' : '' ?>>
            </td>
            <td><button type="submit" form="<?= $fid ?>" class="btn btn-secondary" style="padding:6px 12px;">บันทึก</button></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <script>
    function bpmToggleDeptSelect(userId) {
      const role = document.getElementById('role-' + userId).value;
      document.getElementById('dept-wrap-' + userId).style.display = role === 'DEPT_STAFF' ? '' : 'none';
    }
  </script>

<?php require __DIR__ . '/../../src/partials/layout_end.php'; ?>

