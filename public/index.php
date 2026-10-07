<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$user = bpm_require_role('ADMIN', 'DEPT_STAFF', 'EXECUTIVE_VIEWER', 'DEPT_HEAD');

$fiscalYear = bpm_resolve_fiscal_year();
$selectedDepartmentId = bpm_resolve_department_filter($user);

$pageTitle = 'ภาพรวม';
$activeNav = 'dashboard';

if ($fiscalYear === null) {
    require __DIR__ . '/../src/partials/layout_start.php';
    echo '<div class="card empty-state">ยังไม่มีปีงบประมาณในระบบ — ให้ ADMIN สร้างที่หน้าตั้งค่าปีงบประมาณก่อน</div>';
    require __DIR__ . '/../src/partials/layout_end.php';
    exit;
}

$summary = bpm_department_summary($selectedDepartmentId, (int) $fiscalYear['id']);
$groups  = bpm_group_comparison($selectedDepartmentId, (int) $fiscalYear['id']);
$quarters = bpm_quarterly_spend($selectedDepartmentId, (int) $fiscalYear['id']);
$recent  = bpm_recent_transactions($selectedDepartmentId, (int) $fiscalYear['id'], 8);
$fundSourceRows = bpm_fund_source_summary($selectedDepartmentId, (int) $fiscalYear['id']);

$maxGroupAmount = 0.0;
foreach ($groups as $g) {
    $maxGroupAmount = max($maxGroupAmount, (float) $g['allocated'], (float) $g['spent']);
}

$maxQuarter = max(array_map('abs', $quarters)) ?: 1.0;

// สำหรับ ADMIN/EXECUTIVE_VIEWER เท่านั้น — DEPT_STAFF/DEPT_HEAD ถูกบังคับสาขาตัวเองเสมอจาก bpm_resolve_department_filter() อยู่แล้ว
$canSeeAllDepartments = !in_array($user['role'], ['DEPT_STAFF', 'DEPT_HEAD'], true);

require __DIR__ . '/../src/partials/layout_start.php';

$deptTabQs = static fn ($deptId) => http_build_query(array_filter([
    'fy' => $_GET['fy'] ?? null, 'dept' => $deptId,
], static fn ($v) => $v !== null && $v !== ''));
// ลิงก์เจาะลึก: สมุดรายการ (อ่านอย่างเดียว) และรายงานสรุป — ใส่ fy/dept ของหน้านี้ติดไปด้วย
$drillQs = static fn (array $q): string => http_build_query(array_filter(array_merge(['fy' => $_GET['fy'] ?? null, 'dept' => $selectedDepartmentId], $q), static fn ($v) => $v !== null && $v !== ''));
$ledgerUrl = static fn (array $q = []): string => htmlspecialchars(bpm_url('ledger.php') . '?' . $drillQs($q), ENT_QUOTES);
$reportUrl = static fn (array $q = []): string => htmlspecialchars(bpm_url('reports.php') . '?' . $drillQs($q), ENT_QUOTES);
?>

  <?php if ($canSeeAllDepartments): ?>
    <div class="card">
      <div style="display:flex; gap:8px; flex-wrap:wrap; overflow-x:auto;">
        <a href="?<?= $deptTabQs('') ?>" class="filter-chip" style="<?= $selectedDepartmentId === null ? 'background:var(--accent); color:#fff;' : '' ?>">ทั้งหมด</a>
        <?php foreach (bpm_all_departments() as $d): ?>
          <a href="?<?= $deptTabQs((int) $d['id']) ?>" class="filter-chip" style="<?= $selectedDepartmentId === (int) $d['id'] ? 'background:var(--accent); color:#fff;' : '' ?>"><?= htmlspecialchars($d['name'], ENT_QUOTES) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="kpi-row">
    <div class="kpi-card">
      <div class="label">งบจัดสรรรวม</div>
      <div class="value"><?= htmlspecialchars(bpm_money($summary['total_budget']), ENT_QUOTES) ?></div>
      <div class="sub">ปีงบประมาณ พ.ศ. <?= (int) $fiscalYear['year_be'] ?><?= $selectedDepartmentId === null ? ' · ทุกสาขา' : '' ?></div>
    </div>
    <div class="kpi-card">
      <div class="label">เบิกจ่ายแล้ว</div>
      <div class="value"><a href="<?= $ledgerUrl() ?>" style="color:inherit;" title="ดูรายการเบิกจ่าย"><?= htmlspecialchars(bpm_money($summary['spent']), ENT_QUOTES) ?></a></div>
      <div class="sub">คิดเป็น <?= number_format($summary['spent_pct'], 1) ?>% ของงบจัดสรร</div>
    </div>
    <div class="kpi-card highlight">
      <div class="label">งบคงเหลือ</div>
      <div class="value"><?= htmlspecialchars(bpm_money($summary['balance']), ENT_QUOTES) ?></div>
      <div class="sub"><?= $summary['balance'] >= 0 ? 'เพียงพอตามยอดคงเหลือปัจจุบัน' : 'ใช้เกินยอดจัดสรร — ตรวจสอบรายการ' ?></div>
    </div>
    <div class="kpi-card">
      <div class="label">% เบิกจ่าย</div>
      <div class="value"><?= number_format($summary['spent_pct'], 1) ?>%</div>
      <div class="kpi-progress"><span style="width: <?= min(100, max(0, $summary['spent_pct'])) ?>%;"></span></div>
    </div>
  </div>

  <?php if ($canSeeAllDepartments && $selectedDepartmentId === null): ?>
    <div class="card">
      <h2>เปรียบเทียบงบตามสาขา</h2>
      <table class="data-table">
        <thead>
          <tr>
            <th>สาขา</th>
            <th class="num">จัดสรร</th>
            <th class="num">เบิกจ่ายแล้ว</th>
            <th class="num">คงเหลือ</th>
            <th class="num">% เบิกจ่าย</th>
            <th class="center">สถานะ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (bpm_all_departments() as $d):
            $ds = bpm_department_summary((int) $d['id'], (int) $fiscalYear['id']);
            if ($ds['balance'] < 0) {
                [$statusPill, $statusLabel] = ['pill-danger', 'เกินงบ'];
            } elseif ($ds['spent_pct'] >= 90) {
                [$statusPill, $statusLabel] = ['pill-warning', 'ใกล้เต็มงบ'];
            } else {
                [$statusPill, $statusLabel] = ['pill-success', 'ปกติ'];
            } ?>
            <tr>
              <td><a href="?<?= $deptTabQs((int) $d['id']) ?>"><?= htmlspecialchars($d['name'], ENT_QUOTES) ?></a></td>
              <td class="num"><?= htmlspecialchars(bpm_money($ds['total_budget']), ENT_QUOTES) ?></td>
              <td class="num"><a href="<?= htmlspecialchars(bpm_url('ledger.php') . '?' . $drillQs(['dept' => (int) $d['id']]), ENT_QUOTES) ?>" title="ดูรายการเบิกจ่ายของสาขานี้"><?= htmlspecialchars(bpm_money($ds['spent']), ENT_QUOTES) ?></a></td>
              <td class="num" style="<?= $ds['balance'] < 0 ? 'color: var(--status-danger-text);' : '' ?>"><?= htmlspecialchars(bpm_money($ds['balance']), ENT_QUOTES) ?></td>
              <td class="num"><?= number_format($ds['spent_pct'], 1) ?>%</td>
              <td class="center"><span class="pill <?= $statusPill ?>"><?= $statusLabel ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="font-weight:600;">
            <td>รวมทั้งหมด</td>
            <td class="num"><?= htmlspecialchars(bpm_money($summary['total_budget']), ENT_QUOTES) ?></td>
            <td class="num"><?= htmlspecialchars(bpm_money($summary['spent']), ENT_QUOTES) ?></td>
            <td class="num" style="<?= $summary['balance'] < 0 ? 'color: var(--status-danger-text);' : '' ?>"><?= htmlspecialchars(bpm_money($summary['balance']), ENT_QUOTES) ?></td>
            <td class="num"><?= number_format($summary['spent_pct'], 1) ?>%</td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
  <?php endif; ?>

  <?php if (!empty($fundSourceRows)): ?>
    <div class="card">
      <h2>สรุปตามแหล่งเงิน<?= $selectedDepartmentId !== null ? '' : ' — ทุกสาขา' ?></h2>
      <table class="data-table">
        <thead>
          <tr>
            <th>แหล่งเงิน</th>
            <th class="num">วงเงินที่ได้รับ</th>
            <th class="num">แบ่งเป็นรายการงบแล้ว</th>
            <th class="num">เบิกจ่ายแล้ว</th>
            <th class="num">คงเหลือ</th>
            <th class="num">% เบิกจ่าย</th>
            <th class="center">การแบ่งวงเงิน</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($fundSourceRows as $fr): ?>
            <tr>
              <td><a href="<?= $reportUrl(['view' => 'sources']) ?>" title="ดูรายงานแยกตามแหล่งเงิน"><?= htmlspecialchars($fr['name'], ENT_QUOTES) ?></a></td>
              <td class="num"><?= $fr['limit'] === null ? '<span class="text-muted">—</span>' : htmlspecialchars(bpm_money((float) $fr['limit']), ENT_QUOTES) ?></td>
              <td class="num"><?= htmlspecialchars(bpm_money((float) $fr['allocated']), ENT_QUOTES) ?></td>
              <td class="num"><a href="<?= $ledgerUrl(['source' => (int) $fr['id']]) ?>" title="ดูรายการเบิกจ่าย"><?= htmlspecialchars(bpm_money((float) $fr['spent']), ENT_QUOTES) ?></a></td>
              <td class="num" style="<?= $fr['balance'] < 0 ? 'color: var(--status-danger-text);' : '' ?>"><?= htmlspecialchars(bpm_money($fr['balance']), ENT_QUOTES) ?></td>
              <td class="num"><?= number_format($fr['spent_pct'], 1) ?>%</td>
              <td class="center">
                <?php if ($fr['unallocated'] === null): ?><span class="text-muted">—</span>
                <?php elseif (abs($fr['unallocated']) < 0.005): ?><span class="pill pill-success">แบ่งครบ</span>
                <?php elseif ($fr['unallocated'] > 0): ?><span class="pill pill-warning">เหลือแบ่งได้ <?= htmlspecialchars(bpm_money($fr['unallocated']), ENT_QUOTES) ?></span>
                <?php else: ?><span class="pill pill-danger">แบ่งเกิน <?= htmlspecialchars(bpm_money(-$fr['unallocated']), ENT_QUOTES) ?></span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (count($fundSourceRows) === 1 && $fundSourceRows[0]['code'] === 'UNSPECIFIED'): ?>
        <p class="text-muted small" style="margin-top:10px;">ยังไม่ได้ระบุแหล่งเงินให้รายการงบ — ADMIN ตั้งค่าได้ที่เมนู "แหล่งเงิน" แล้วเลือกแหล่งเงินของแต่ละรายการที่หน้า "ตั้งค่างบ"</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="two-col">
    <div class="card main-panel" style="flex: 1.5;">
      <h2>เปรียบเทียบงบตามกลุ่มหมวด</h2>
      <?php if (empty($groups)): ?>
        <p class="empty-state">ยังไม่มีรายการงบที่ตั้งกลุ่มหมวดไว้ในปีงบนี้</p>
      <?php else: ?>
        <div style="display:flex; align-items:flex-end; gap:24px; height:180px; padding:0 8px; border-bottom:1px solid var(--border-subtle);">
          <?php foreach ($groups as $g): ?>
            <div style="display:flex; align-items:flex-end; gap:6px; flex:1; height:100%; justify-content:center;">
              <div style="width:26px; height:<?= $maxGroupAmount > 0 ? round(((float) $g['allocated'] / $maxGroupAmount) * 170) : 0 ?>px; background:#E2E8F0; border-radius:4px 4px 0 0;" title="จัดสรร <?= htmlspecialchars(bpm_money((float) $g['allocated']), ENT_QUOTES) ?>"></div>
              <div style="width:26px; height:<?= $maxGroupAmount > 0 ? round((max(0, (float) $g['spent']) / $maxGroupAmount) * 170) : 0 ?>px; background:var(--accent); border-radius:4px 4px 0 0;" title="เบิกจ่ายแล้ว <?= htmlspecialchars(bpm_money((float) $g['spent']), ENT_QUOTES) ?>"></div>
            </div>
          <?php endforeach; ?>
        </div>
        <div style="display:flex; gap:24px; padding:0 8px;">
          <?php foreach ($groups as $g): ?>
            <div style="flex:1; text-align:center; font-size:12px; color:var(--text-secondary); padding-top:8px;"><a href="<?= $reportUrl(['view' => 'table', 'group' => (int) $g['id']]) ?>" style="color:inherit;" title="ดูรายการงบในหมวดนี้"><?= htmlspecialchars($g['name'], ENT_QUOTES) ?></a></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card main-panel" style="flex:1;">
      <h2>สรุปยอดตามไตรมาส</h2>
      <div style="display:flex; flex-direction:column; gap:16px;">
        <?php foreach (BPM_QUARTER_LABELS as $q => $meta): $amt = $quarters[$q]; $pct = $maxQuarter > 0 ? min(100, (abs($amt) / $maxQuarter) * 100) : 0; ?>
          <div>
            <div style="display:flex; justify-content:space-between; font-size:12.5px; color:var(--text-secondary); margin-bottom:5px;">
              <span><a href="<?= $ledgerUrl(['quarter' => $q, 'by' => 'group']) ?>" style="color:inherit;" title="ดูรายการเบิกจ่ายของไตรมาสนี้"><?= $meta['label'] ?> <span style="color:var(--text-muted);">(<?= $meta['months'] ?>)</span></a></span>
              <span style="font-variant-numeric:tabular-nums; color:var(--text-primary); font-weight:500;"><?= htmlspecialchars(bpm_money($amt), ENT_QUOTES) ?></span>
            </div>
            <div class="kpi-progress"><span style="width:<?= $pct ?>%; background: <?= $amt < 0 ? 'var(--status-success-text)' : 'var(--accent)' ?>;"></span></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>รายการล่าสุด <a href="<?= $ledgerUrl() ?>" class="small" style="font-weight:400; margin-left:8px;">ดูทั้งหมด →</a></h2>
    <?php if (empty($recent)): ?>
      <p class="empty-state">ยังไม่มีรายการเบิกจ่าย/รายรับในปีงบนี้</p>
    <?php else: ?>
      <table class="data-table">
        <thead>
          <tr>
            <th class="center">วันที่</th>
            <th>สาขา</th>
            <th>รายการ</th>
            <th class="center">ประเภท</th>
            <th class="num">จำนวนเงิน</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $t): ?>
            <tr>
              <td class="center"><?= htmlspecialchars(bpm_thai_date($t['txn_date']), ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($t['department_name'], ENT_QUOTES) ?></td>
              <td><a href="<?= htmlspecialchars(bpm_url('ledger.php') . '?item=' . (int) $t['line_item_id'], ENT_QUOTES) ?>" title="ดูสมุดของรายการนี้"><?= htmlspecialchars($t['line_item_name'], ENT_QUOTES) ?></a> — <?= htmlspecialchars($t['description'], ENT_QUOTES) ?></td>
              <td class="center">
                <span class="pill <?= $t['type'] === 'EXPENSE' ? 'pill-neutral' : 'pill-success' ?>">
                  <?= $t['type'] === 'EXPENSE' ? 'รายจ่าย' : 'รายรับ' ?>
                </span>
              </td>
              <td class="num" style="<?= $t['type'] === 'INCOME' ? 'color: var(--status-success-text);' : '' ?>">
                <?= $t['type'] === 'EXPENSE' ? '-' : '+' ?><?= htmlspecialchars(bpm_money((float) $t['amount']), ENT_QUOTES) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

<?php require __DIR__ . '/../src/partials/layout_end.php'; ?>
