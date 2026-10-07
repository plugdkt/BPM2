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
$selectedDepartmentId = $departmentId ?: null; // ให้ dropdown สาขาบน topbar ตรงกับแท็บที่เลือก

$groups = bpm_db()->query('SELECT * FROM budget_groups WHERE is_active = 1 ORDER BY id')->fetchAll();
$fundSources = bpm_db()->query('SELECT * FROM fund_sources ORDER BY id')->fetchAll();
// ตัวเลือกแหล่งเงิน: แสดงเฉพาะที่ยังใช้งาน + แหล่งเงินปัจจุบันของรายการนั้น (แม้ถูกปิดแล้ว) กันค่าเดิมหายตอนบันทึก
$renderSourceOptions = static function (array $sources, int $current): void {
    foreach ($sources as $s) {
        if (!$s['is_active'] && (int) $s['id'] !== $current) { continue; }
        echo '<option value="' . (int) $s['id'] . '"' . ((int) $s['id'] === $current ? ' selected' : '') . '>' . htmlspecialchars($s['name'] . ($s['is_active'] ? '' : ' (ปิดใช้งาน)'), ENT_QUOTES) . '</option>';
    }
};
$lineItems = ($departmentId && $fiscalYearId) ? bpm_line_items_for_department($departmentId, $fiscalYearId) : [];

// สรุปของสาขานี้: (1) วงเงินที่ได้รับจากแต่ละแหล่ง เทียบกับยอดที่แบ่งเป็นรายการงบแล้ว (2) ยอดแยกตามหมวดงบ และรายการที่ยังไม่ระบุหมวด
$deptFund = [];
if ($departmentId && $fiscalYearId) {
    foreach (bpm_fund_envelope_overview($fiscalYearId) as $env) {
        foreach ($env['departments'] as $dr) {
            if ((int) $dr['department_id'] === $departmentId && ($dr['budget'] !== null || $dr['allocated'] > 0)) {
                $deptFund[] = ['source' => $env['source'], 'budget' => $dr['budget'], 'allocated' => $dr['allocated'], 'remaining' => $dr['remaining']];
            }
        }
    }
}
$groupNameMap = array_column($groups, 'name', 'id');
$groupSummary = [];
$deptTotal = 0.0;
foreach ($lineItems as $li) {
    $gid = $li['group_id'] !== null ? (int) $li['group_id'] : 0;
    $groupSummary[$gid] ??= ['name' => $gid === 0 ? 'ยังไม่ระบุหมวด' : ($groupNameMap[$gid] ?? 'หมวดที่ปิดใช้งานแล้ว'), 'count' => 0, 'total' => 0.0];
    $groupSummary[$gid]['count']++;
    $groupSummary[$gid]['total'] += (float) $li['starting_amount'];
    $deptTotal += (float) $li['starting_amount'];
}
uksort($groupSummary, static fn ($a, $b) => ($a === 0 ? PHP_INT_MAX : $a) <=> ($b === 0 ? PHP_INT_MAX : $b));

$inactiveLineItems = [];
if ($departmentId && $fiscalYearId) {
    $stmt = bpm_db()->prepare(
        'SELECT * FROM budget_line_items WHERE department_id = ? AND fiscal_year_id = ? AND is_active = 0 ORDER BY name'
    );
    $stmt->execute([$departmentId, $fiscalYearId]);
    $inactiveLineItems = $stmt->fetchAll();
}

// แท็บแหล่งเงินของตารางรายการงบ: แหล่งเงินที่ยังใช้งาน (ไม่นับ "ไม่ระบุ") + แหล่งที่สาขานี้มีรายการอยู่จริง (รวม "ไม่ระบุ" ถ้ายังมีรายการค้าง)
// ซ่อนแถบแท็บถ้ามีแหล่งเดียว; ?source=<id> เลือกแท็บ (ไม่ส่ง = แหล่งแรกที่มีรายการ ไม่มีก็แหล่งแรก)
$itemSourceIds = array_unique(array_map(static fn ($li) => (int) $li['fund_source_id'], array_merge($lineItems, $inactiveLineItems)));
$sourceTabs = [];
foreach ($fundSources as $fs) {
    $isUnspec = $fs['code'] === 'UNSPECIFIED';
    if (($fs['is_active'] && !$isUnspec) || in_array((int) $fs['id'], $itemSourceIds, true)) {
        $sourceTabs[(int) $fs['id']] = $fs['name'];
    }
}
$activeItemSources = array_unique(array_map(static fn ($li) => (int) $li['fund_source_id'], $lineItems));
$selectedSourceId = (int) ($_GET['source'] ?? 0);
if (!isset($sourceTabs[$selectedSourceId])) {
    $withItems = array_values(array_filter(array_keys($sourceTabs), static fn ($id) => in_array($id, $activeItemSources, true)));
    $selectedSourceId = $withItems[0] ?? (array_key_first($sourceTabs) ?? 1);
}
$shownLineItems = array_values(array_filter($lineItems, static fn ($li) => (int) $li['fund_source_id'] === $selectedSourceId));
$shownInactive = array_values(array_filter($inactiveLineItems, static fn ($li) => (int) $li['fund_source_id'] === $selectedSourceId));
$detailTotals = bpm_line_item_detail_totals(array_map('intval', array_column($shownLineItems, 'id'))); // สรุปรายละเอียดครุภัณฑ์ต่อรายการ
$sourceTabQs = static fn (int $sid) => http_build_query(array_filter(['dept' => $departmentId, 'fy' => $fiscalYearId ?: null, 'source' => $sid]));

require __DIR__ . '/../../src/partials/layout_start.php';
?>

  <?php if (!empty($departments)): ?>
    <div class="card">
      <div style="display:flex; gap:8px; flex-wrap:wrap; overflow-x:auto;">
        <?php foreach ($departments as $d): ?>
          <a href="?<?= http_build_query(array_filter(['dept' => (int) $d['id'], 'fy' => $fiscalYearId ?: null])) ?>" class="filter-chip" style="<?= $departmentId === (int) $d['id'] ? 'background:var(--accent); color:#fff;' : '' ?>"><?= htmlspecialchars($d['name'], ENT_QUOTES) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

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
          <input type="hidden" name="fund_source_id" value="<?= (int) $li['fund_source_id'] ?>">
          <?php if ($li['requires_travel_detail']): ?><input type="hidden" name="requires_travel_detail" value="1"><?php endif; ?>
          <input type="hidden" name="note" value="<?= htmlspecialchars((string) $li['note'], ENT_QUOTES) ?>">
          <?php if (!$li['is_active']): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
        </form>
        <?php
    }; ?>
    <div class="two-col">
      <div class="card" style="flex:1;">
        <h2>วงเงินที่สาขานี้ได้รับ</h2>
        <?php if (empty($deptFund)): ?>
          <p class="text-muted small">ยังไม่ได้ตั้งวงเงินของสาขานี้ — ตั้งที่ขั้นตอน "2. วงเงินแหล่งเงิน"</p>
        <?php else: ?>
          <table class="data-table">
            <thead><tr><th>แหล่งเงิน</th><th class="num">ได้รับ</th><th class="num">แบ่งเป็นรายการแล้ว</th><th class="num">ยังแบ่งได้อีก</th></tr></thead>
            <tbody>
              <?php foreach ($deptFund as $f): ?>
                <tr>
                  <td><?= htmlspecialchars($f['source']['name'], ENT_QUOTES) ?></td>
                  <td class="num"><?= $f['budget'] === null ? '<span class="text-muted">ยังไม่ตั้ง</span>' : htmlspecialchars(bpm_money((float) $f['budget']), ENT_QUOTES) ?></td>
                  <td class="num"><?= htmlspecialchars(bpm_money($f['allocated']), ENT_QUOTES) ?></td>
                  <td class="num">
                    <?php if ($f['remaining'] === null): ?><span class="text-muted">—</span>
                    <?php else: ?><span style="color: <?= $f['remaining'] < 0 ? 'var(--status-danger-text)' : 'var(--status-success-text)' ?>;"><?= htmlspecialchars(bpm_money($f['remaining']), ENT_QUOTES) ?><?= $f['remaining'] < 0 ? ' (เกิน)' : '' ?></span><?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <div class="card" style="flex:1;">
        <h2>แบ่งตามหมวดงบ<?= count($sourceTabs) > 1 ? ' (ทุกแหล่งเงิน)' : '' ?></h2>
        <?php if (empty($groupSummary)): ?>
          <p class="text-muted small">ยังไม่มีรายการงบของสาขานี้</p>
        <?php else: ?>
          <table class="data-table">
            <thead><tr><th>หมวดงบ</th><th class="num">รายการ</th><th class="num">งบต้นปีรวม</th></tr></thead>
            <tbody>
              <?php foreach ($groupSummary as $gid => $g): ?>
                <tr<?= $gid === 0 ? ' style="background: var(--status-warning-bg);"' : '' ?>>
                  <td><?= htmlspecialchars($g['name'], ENT_QUOTES) ?><?= $gid === 0 ? ' <span class="pill pill-warning">ควรระบุหมวด</span>' : '' ?></td>
                  <td class="num"><?= (int) $g['count'] ?></td>
                  <td class="num"><?= htmlspecialchars(bpm_money($g['total']), ENT_QUOTES) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr style="font-weight:600;"><td>รวมทั้งสาขา</td><td class="num"><?= count($lineItems) ?></td><td class="num"><?= htmlspecialchars(bpm_money($deptTotal), ENT_QUOTES) ?></td></tr>
            </tfoot>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <?php if (count($sourceTabs) > 1): ?>
      <div class="card">
        <div style="display:flex; gap:8px; flex-wrap:wrap; overflow-x:auto; align-items:center;">
          <span class="text-muted small" style="margin-right:2px;">แหล่งเงิน:</span>
          <?php foreach ($sourceTabs as $sid => $sname): ?>
            <a href="?<?= $sourceTabQs($sid) ?>" class="filter-chip" style="<?= $selectedSourceId === $sid ? 'background:var(--accent); color:#fff;' : '' ?>"><?= htmlspecialchars($sname, ENT_QUOTES) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <h2>รายการงบ<?= count($sourceTabs) > 1 ? ' — ' . htmlspecialchars($sourceTabs[$selectedSourceId] ?? '', ENT_QUOTES) : '' ?></h2>

      <?php foreach ($shownLineItems as $li): $fid = 'li-form-' . (int) $li['id']; $tfid = 'li-toggle-' . (int) $li['id']; ?>
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
            <th style="width:150px;">แหล่งเงิน</th>
            <th class="num" style="width:150px;">งบต้นปี</th>
            <th style="width:150px;">กลุ่มหมวด</th>
            <th class="center" style="width:90px;">เดินทาง</th>
            <th style="width:160px;">หมายเหตุ</th>
            <th style="width:110px;"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($shownLineItems as $li): $fid = 'li-form-' . (int) $li['id']; $tfid = 'li-toggle-' . (int) $li['id']; ?>
            <tr>
              <td>
                <input type="text" name="name" form="<?= $fid ?>" class="field" value="<?= htmlspecialchars($li['name'], ENT_QUOTES) ?>" required>
                <?php if (bpm_group_supports_details($li['group_id'] !== null ? (int) $li['group_id'] : null)):
                  $dt = $detailTotals[(int) $li['id']] ?? null; $dtMatch = $dt !== null && abs($dt['total'] - (float) $li['starting_amount']) < 0.005; ?>
                  <a class="small" href="<?= htmlspecialchars(bpm_url('admin/line-item-details.php?item=' . (int) $li['id']), ENT_QUOTES) ?>" style="display:inline-block; margin-top:4px;">
                    <?= $dt === null ? '+ เพิ่มรายละเอียดครุภัณฑ์' : 'รายละเอียดครุภัณฑ์ (' . $dt['count'] . ' รายการ · รวม ' . htmlspecialchars(bpm_money($dt['total']), ENT_QUOTES) . ')' ?>
                  </a>
                  <?php if ($dt !== null): ?><span class="pill <?= $dtMatch ? 'pill-success' : 'pill-warning' ?>" style="font-size:11px;"><?= $dtMatch ? 'ตรงงบต้นปี' : 'ไม่ตรงงบต้นปี' ?></span><?php endif; ?>
                <?php endif; ?>
              </td>
              <td><select name="fund_source_id" form="<?= $fid ?>" class="field"><?php $renderSourceOptions($fundSources, (int) $li['fund_source_id']); ?></select></td>
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
            <td><select name="fund_source_id" form="li-form-new" class="field"><?php $renderSourceOptions($fundSources, $selectedSourceId); ?></select></td>
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

      <?php if (!empty($shownInactive)): ?>
        <details style="margin-top:18px;">
          <summary style="cursor:pointer; color:var(--text-muted); font-size:13px; font-weight:500;">รายการที่ปิดใช้งานแล้ว (<?= count($shownInactive) ?>)</summary>
          <table class="data-table" style="margin-top:10px;">
            <tbody>
              <?php foreach ($shownInactive as $li): $tfid = 'li-toggle-inactive-' . (int) $li['id']; $renderToggleForm($li, $departmentId, $fiscalYearId, $tfid); ?>
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
        "แหล่งเงิน" (งบรายได้/งบแผ่นดิน/งบผลิตแพทย์ ฯลฯ — ตั้งค่าที่เมนู "แหล่งเงิน") 1 รายการมาจากแหล่งเดียว และ<strong>โยกย้ายงบข้ามแหล่งเงินไม่ได้</strong> — ถ้างบก้อนเดียวกันต้องใช้หลายแหล่ง ให้สร้างเป็นหลายรายการชื่อเดียวกันแยกแหล่ง<br>
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
