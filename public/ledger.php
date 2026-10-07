<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

/**
 * สมุดรายการ (อ่านอย่างเดียว) — หน้าเจาะลึกจากรายงานสรุป/ภาพรวม เปิดให้ทุก role รวมผู้บริหาร (EXECUTIVE_VIEWER) ดูได้
 *   ?item=ID            → สมุดของรายการงบเดียว: ยอดต้นปี/โอน/เบิกจ่ายเรียงตามวันที่ พร้อมคงเหลือสะสมทีละบรรทัด
 *   ไม่มี item          → รายการเบิกจ่ายตามตัวกรองหลายมิติ (สาขา แหล่งเงิน หมวดเงิน ไตรมาส ประเภท ค้นหา) + แจกแจงยอดตามมิติที่เลือก (by=)
 * DEPT_STAFF/DEPT_HEAD เห็นเฉพาะสาขาตัวเองเสมอ (ล็อกจาก bpm_resolve_department_filter / ตรวจสาขาของรายการงบ)
 * ไม่มีฟอร์มบันทึก/แก้ไขใดๆ ในหน้านี้ — ไม่กระทบยอดหรือข้อมูล
 */

$user = bpm_require_role('ADMIN', 'DEPT_STAFF', 'EXECUTIVE_VIEWER', 'DEPT_HEAD');
$deptScoped = in_array($user['role'], ['DEPT_STAFF', 'DEPT_HEAD'], true);

$pageTitle = 'สมุดรายการ';
$activeNav = 'reports';

$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$money = static fn (float $v): string => $h(bpm_money($v));
$reportsUrl = static fn (array $q): string => bpm_url('reports.php') . '?' . http_build_query(array_filter($q, static fn ($v) => $v !== null && $v !== ''));

$itemId = (int) ($_GET['item'] ?? 0);

// ---------------------------------------------------------------------------------------------
// โหมด 1: สมุดของรายการงบเดียว
// ---------------------------------------------------------------------------------------------
if ($itemId > 0) {
    $ledger = bpm_line_item_ledger($itemId);
    $denied = $ledger !== null && $deptScoped && (int) $ledger['item']['department_id'] !== (int) $user['department_id'];

    if ($ledger !== null && !$denied) {
        $fyStmt = bpm_db()->prepare('SELECT * FROM fiscal_years WHERE id = ?');
        $fyStmt->execute([(int) $ledger['item']['fiscal_year_id']]);
        $fiscalYear = $fyStmt->fetch() ?: null;
        $selectedDepartmentId = (int) $ledger['item']['department_id'];
    }

    if ($ledger === null || $denied) {
        http_response_code($ledger === null ? 404 : 403); // ต้องตั้งก่อนมี output ใดๆ (layout_start ส่ง HTML ทันที)
    }
    require __DIR__ . '/../src/partials/layout_start.php';

    if ($ledger === null || $denied) {
        echo '<div class="card empty-state">' . ($ledger === null ? 'ไม่พบรายการงบที่ต้องการ' : 'ไม่มีสิทธิ์ดูรายการงบของสาขาอื่น') . '</div>';
        require __DIR__ . '/../src/partials/layout_end.php';
        exit;
    }

    $it = $ledger['item'];
    $b = $ledger['balance'];
    $label = bpm_li_label($it + ['fund_source_code' => $it['fund_source_code'], 'fund_source_name' => $it['fund_source_name']]);
    $spent = $b['expense'] - $b['income'];
    $pct = $b['total_budget'] > 0 ? ($spent / $b['total_budget']) * 100 : 0.0;
    $fy = (int) $it['fiscal_year_id'];
    $listUrl = bpm_url('ledger.php') . '?' . http_build_query(['fy' => $fy, 'dept' => (int) $it['department_id']]);
    ?>
  <div class="card">
    <div class="text-muted small" style="margin-bottom:10px; display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
      <a href="<?= $h($reportsUrl(['fy' => $fy, 'view' => 'table', 'dept' => (int) $it['department_id']])) ?>">รายงานสรุป</a> ›
      <a href="<?= $h($reportsUrl(['fy' => $fy, 'view' => 'table', 'dept' => (int) $it['department_id'], 'group' => $it['group_id'] !== null ? (int) $it['group_id'] : 0])) ?>"><?= $h($it['department_name']) ?> / <?= $h($it['group_name'] ?? 'ไม่ระบุกลุ่ม') ?></a> ›
      <span>รายการงบ</span>
    </div>
    <h2 style="margin-bottom:6px; font-size:18px;"><?= $h($label) ?></h2>
    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
      <span class="pill pill-neutral"><?= $h($it['department_name']) ?></span>
      <span class="pill pill-neutral">ปีงบ พ.ศ. <?= (int) $it['year_be'] ?><?= $it['fy_status'] === 'CLOSED' ? ' (ปิดแล้ว)' : '' ?></span>
      <?php if ($it['fund_source_code'] !== 'UNSPECIFIED'): ?><span class="pill pill-neutral">แหล่งเงิน: <?= $h($it['fund_source_name']) ?></span><?php endif; ?>
      <?php if ($it['group_name'] !== null): ?><span class="pill pill-neutral">หมวด: <?= $h($it['group_name']) ?></span><?php endif; ?>
      <?php if (!(int) $it['is_active']): ?><span class="pill pill-warning">ปิดใช้งานแล้ว</span><?php endif; ?>
    </div>
  </div>

  <div class="kpi-row">
    <div class="kpi-card"><div class="label">งบต้นปี</div><div class="value" style="font-size:20px;"><?= $money($b['starting_amount']) ?></div></div>
    <div class="kpi-card"><div class="label">โอนเข้า</div><div class="value" style="font-size:20px;">+<?= $money($b['transfer_in']) ?></div></div>
    <div class="kpi-card"><div class="label">โอนออก</div><div class="value" style="font-size:20px;">−<?= $money($b['transfer_out']) ?></div></div>
    <div class="kpi-card"><div class="label">งบรวม</div><div class="value" style="font-size:20px;"><?= $money($b['total_budget']) ?></div></div>
    <div class="kpi-card"><div class="label">เบิกจ่ายสุทธิ</div><div class="value" style="font-size:20px;"><?= $money($spent) ?></div><div class="sub"><?= number_format($pct, 1) ?>% ของงบรวม</div></div>
    <div class="kpi-card <?= $b['balance'] >= 0 ? 'highlight' : '' ?>"><div class="label">คงเหลือ</div><div class="value" style="font-size:20px;<?= $b['balance'] < 0 ? ' color:var(--status-danger-text);' : '' ?>"><?= $money($b['balance']) ?></div></div>
  </div>

  <div class="card">
    <h2>ความเคลื่อนไหวของรายการนี้</h2>
    <div style="overflow-x:auto;">
      <table class="data-table">
        <thead>
          <tr>
            <th class="center">วันที่</th>
            <th>รายการ</th>
            <th>เลขที่อ้างอิง</th>
            <th class="num">เพิ่ม / ลด</th>
            <th class="num">คงเหลือสะสม</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ledger['events'] as $e): ?>
            <tr>
              <td class="center"><?= $e['kind'] === 'START' ? '<span class="text-muted">—</span>' : $h(bpm_thai_date($e['date'])) ?></td>
              <td>
                <?php if ($e['kind'] === 'TRANSFER'): ?><span class="pill pill-warning" style="margin-right:6px;">โอนงบ</span><?php endif; ?>
                <?php if ($e['kind'] === 'TXN'): ?><span class="pill <?= $e['type'] === 'EXPENSE' ? 'pill-neutral' : 'pill-success' ?>" style="margin-right:6px;"><?= $e['type'] === 'EXPENSE' ? 'รายจ่าย' : 'รายรับ' ?></span><?php endif; ?>
                <?= $h($e['label']) ?>
                <?php if (!empty($e['note'])): ?><div class="text-muted small"><?= $h($e['note']) ?></div><?php endif; ?>
              </td>
              <td class="text-muted"><?= $h($e['ref'] ?? '-') ?></td>
              <td class="num" style="<?= $e['delta'] > 0 && $e['kind'] !== 'START' ? 'color: var(--status-success-text);' : '' ?>"><?= $e['delta'] >= 0 ? '+' : '−' ?><?= $money(abs($e['delta'])) ?></td>
              <td class="num" style="font-weight:600;<?= $e['running'] < 0 ? ' color:var(--status-danger-text);' : '' ?>"><?= $money($e['running']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (count($ledger['events']) === 1): ?><p class="empty-state" style="margin-top:12px;">ยังไม่มีการโอนหรือเบิกจ่ายในรายการนี้</p><?php endif; ?>
    <?php if ($ledger['pending_transfers'] > 0): ?>
      <p class="text-muted small" style="margin-top:12px;">มีคำขอโอนงบที่รอพิจารณา <?= (int) $ledger['pending_transfers'] ?> รายการ — ยังไม่นับรวมในยอดด้านบน</p>
    <?php endif; ?>
    <?php if (!$ledger['running_matches']): ?>
      <p style="margin-top:12px;"><span class="pill pill-danger">ยอดสะสมไม่ตรงกับยอดคงเหลือที่คำนวณ — แจ้งผู้ดูแลระบบ</span></p>
    <?php endif; ?>
    <p class="small" style="margin-top:12px;"><a href="<?= $h($listUrl) ?>">ดูรายการเบิกจ่ายทั้งหมดของสาขานี้ →</a></p>
  </div>

  <?php if (!empty($ledger['details'])): ?>
    <div class="card">
      <h2>รายละเอียดครุภัณฑ์</h2>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>ชื่อครุภัณฑ์</th><th class="num">จำนวน</th><th>หน่วย</th><th class="num">ราคาต่อหน่วย</th><th class="num">รวม</th></tr></thead>
          <tbody>
            <?php $detailSum = 0.0; foreach ($ledger['details'] as $d): $detailSum += (float) $d['amount']; ?>
              <tr>
                <td><?= $h($d['name']) ?><?php if (!empty($d['note'])): ?><div class="text-muted small"><?= $h($d['note']) ?></div><?php endif; ?></td>
                <td class="num"><?= $h(rtrim(rtrim(number_format((float) $d['quantity'], 2), '0'), '.')) ?></td>
                <td><?= $h($d['unit'] ?? '') ?></td>
                <td class="num"><?= $money((float) $d['unit_price']) ?></td>
                <td class="num"><?= $money((float) $d['amount']) ?></td>
              </tr>
            <?php endforeach; ?>
            <tr style="border-top:2px solid var(--border-subtle); font-weight:600;">
              <td colspan="4">รวมรายละเอียด</td><td class="num"><?= $money($detailSum) ?></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

    <?php
    require __DIR__ . '/../src/partials/layout_end.php';
    exit;
}

// ---------------------------------------------------------------------------------------------
// โหมด 2: รายการเบิกจ่ายตามตัวกรองหลายมิติ + แจกแจงยอดตามมิติ
// ---------------------------------------------------------------------------------------------
$fiscalYear = bpm_resolve_fiscal_year();
$selectedDepartmentId = bpm_resolve_department_filter($user);

if ($fiscalYear === null) {
    require __DIR__ . '/../src/partials/layout_start.php';
    echo '<div class="card empty-state">ยังไม่มีปีงบประมาณในระบบ</div>';
    require __DIR__ . '/../src/partials/layout_end.php';
    exit;
}

$sourceId = isset($_GET['source']) && (int) $_GET['source'] > 0 ? (int) $_GET['source'] : null;
$groupId = isset($_GET['group']) && $_GET['group'] !== '' ? max(0, (int) $_GET['group']) : null;
$quarter = isset($_GET['quarter']) && isset(BPM_QUARTER_LABELS[(int) $_GET['quarter']]) ? (int) $_GET['quarter'] : null;
$type = in_array($_GET['type'] ?? '', ['EXPENSE', 'INCOME'], true) ? $_GET['type'] : null;
$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$byOptions = ['dept' => 'สาขา', 'group' => 'หมวดเงิน', 'source' => 'แหล่งเงิน', 'quarter' => 'ไตรมาส', 'item' => 'รายการงบ'];
if ($deptScoped || $selectedDepartmentId !== null) {
    // เลือกสาขาแล้ว แจกแจงตามสาขาไม่มีความหมาย — ตัดตัวเลือกออก
    unset($byOptions['dept']);
}
$by = (string) ($_GET['by'] ?? '');
if (!isset($byOptions[$by])) {
    $by = array_key_first($byOptions);
}

$filters = [
    'fy' => (int) $fiscalYear['id'], 'dept' => $selectedDepartmentId, 'source' => $sourceId, 'group' => $groupId,
    'quarter' => $quarter, 'type' => $type, 'q' => $search,
];
$listing = bpm_ledger_list($filters, $page, 50);
$breakdown = bpm_ledger_breakdown($filters, $by);

$groups = bpm_db()->query('SELECT id, name FROM budget_groups WHERE is_active = 1 ORDER BY id')->fetchAll();
$groupNames = array_column($groups, 'name', 'id');
$deptNames = array_column(bpm_all_departments(), 'name', 'id');
$sources = bpm_all_fund_sources();

$cur = [
    'fy' => $_GET['fy'] ?? null, 'dept' => $deptScoped ? null : ($selectedDepartmentId ?? ($_GET['dept'] ?? null)),
    'source' => $sourceId, 'group' => $groupId, 'quarter' => $quarter, 'type' => $type, 'q' => $search !== '' ? $search : null, 'by' => $_GET['by'] ?? null,
];
$qs = static fn (array $over = []): string => http_build_query(array_filter(
    array_merge($cur, ['page' => null], $over),
    static fn ($v) => $v !== null && $v !== ''
));
$chipStyle = static fn (bool $on): string => $on ? 'background:var(--accent); color:#fff;' : '';

// มิติที่ตั้งตัวกรองอยู่ — ใช้สรุปหัวเรื่อง
$activeBits = [];
if ($selectedDepartmentId !== null) { $activeBits[] = $deptNames[$selectedDepartmentId] ?? 'สาขา'; }
if ($sourceId !== null) { $activeBits[] = 'แหล่งเงิน ' . (array_column($sources, 'name', 'id')[$sourceId] ?? ''); }
if ($groupId !== null) { $activeBits[] = 'หมวด ' . ($groupId === 0 ? 'ไม่ระบุกลุ่ม' : ($groupNames[$groupId] ?? '')); }
if ($quarter !== null) { $activeBits[] = BPM_QUARTER_LABELS[$quarter]['label']; }
if ($type !== null) { $activeBits[] = $type === 'EXPENSE' ? 'เฉพาะรายจ่าย' : 'เฉพาะรายรับ'; }
if ($search !== '') { $activeBits[] = 'ค้นหา "' . $search . '"'; }

$net = $listing['expense'] - $listing['income'];

require __DIR__ . '/../src/partials/layout_start.php';
?>
  <div class="card" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
    <div>
      <h2 style="margin:0 0 4px;">รายการเบิกจ่าย — ปีงบ พ.ศ. <?= (int) $fiscalYear['year_be'] ?></h2>
      <div class="text-muted small"><?= empty($activeBits) ? 'ทุกสาขา ทุกแหล่งเงิน ทุกหมวด' : $h(implode(' · ', $activeBits)) ?></div>
    </div>
    <a href="<?= $h(bpm_url('reports.php') . '?' . http_build_query(array_filter(['fy' => $_GET['fy'] ?? null, 'dept' => $selectedDepartmentId]))) ?>" class="filter-chip">← กลับรายงานสรุป</a>
  </div>

  <?php if (!$deptScoped): ?>
    <div class="card">
      <div style="display:flex; gap:8px; flex-wrap:wrap; overflow-x:auto; align-items:center;">
        <span class="text-muted small">สาขา:</span>
        <a href="?<?= $h($qs(['dept' => null])) ?>" class="filter-chip" style="<?= $chipStyle($selectedDepartmentId === null) ?>">ทั้งหมด</a>
        <?php foreach ($deptNames as $did => $dname): ?>
          <a href="?<?= $h($qs(['dept' => (int) $did])) ?>" class="filter-chip" style="<?= $chipStyle($selectedDepartmentId === (int) $did) ?>"><?= $h($dname) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="card" style="display:flex; flex-direction:column; gap:10px;">
    <?php if (bpm_multiple_fund_sources()): ?>
      <div style="display:flex; gap:8px; flex-wrap:wrap; overflow-x:auto; align-items:center;">
        <span class="text-muted small">แหล่งเงิน:</span>
        <a href="?<?= $h($qs(['source' => null])) ?>" class="filter-chip" style="<?= $chipStyle($sourceId === null) ?>">ทั้งหมด</a>
        <?php foreach ($sources as $s): ?>
          <a href="?<?= $h($qs(['source' => (int) $s['id']])) ?>" class="filter-chip" style="<?= $chipStyle($sourceId === (int) $s['id']) ?>"><?= $h($s['name']) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div style="display:flex; gap:8px; flex-wrap:wrap; overflow-x:auto; align-items:center;">
      <span class="text-muted small">หมวดเงิน:</span>
      <a href="?<?= $h($qs(['group' => null])) ?>" class="filter-chip" style="<?= $chipStyle($groupId === null) ?>">ทั้งหมด</a>
      <?php foreach ($groups as $g): ?>
        <a href="?<?= $h($qs(['group' => (int) $g['id']])) ?>" class="filter-chip" style="<?= $chipStyle($groupId === (int) $g['id']) ?>"><?= $h($g['name']) ?></a>
      <?php endforeach; ?>
      <a href="?<?= $h($qs(['group' => 0])) ?>" class="filter-chip" style="<?= $chipStyle($groupId === 0) ?>">ไม่ระบุกลุ่ม</a>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap; overflow-x:auto; align-items:center;">
      <span class="text-muted small">ไตรมาส:</span>
      <a href="?<?= $h($qs(['quarter' => null])) ?>" class="filter-chip" style="<?= $chipStyle($quarter === null) ?>">ทั้งหมด</a>
      <?php foreach (BPM_QUARTER_LABELS as $qn => $meta): ?>
        <a href="?<?= $h($qs(['quarter' => $qn])) ?>" class="filter-chip" style="<?= $chipStyle($quarter === $qn) ?>"><?= $h($meta['label']) ?> <span style="opacity:.75;">(<?= $h($meta['months']) ?>)</span></a>
      <?php endforeach; ?>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap; overflow-x:auto; align-items:center;">
      <span class="text-muted small">ประเภท:</span>
      <a href="?<?= $h($qs(['type' => null])) ?>" class="filter-chip" style="<?= $chipStyle($type === null) ?>">ทั้งหมด</a>
      <a href="?<?= $h($qs(['type' => 'EXPENSE'])) ?>" class="filter-chip" style="<?= $chipStyle($type === 'EXPENSE') ?>">รายจ่าย</a>
      <a href="?<?= $h($qs(['type' => 'INCOME'])) ?>" class="filter-chip" style="<?= $chipStyle($type === 'INCOME') ?>">รายรับ</a>
    </div>
  </div>

  <div class="kpi-row">
    <div class="kpi-card"><div class="label">รายจ่าย</div><div class="value"><?= $money($listing['expense']) ?></div></div>
    <div class="kpi-card"><div class="label">รายรับ (คืนงบ)</div><div class="value"><?= $money($listing['income']) ?></div></div>
    <div class="kpi-card highlight"><div class="label">เบิกจ่ายสุทธิ</div><div class="value"><?= $money($net) ?></div></div>
    <div class="kpi-card"><div class="label">จำนวนรายการ</div><div class="value"><?= number_format($listing['total']) ?></div><div class="sub">ตามตัวกรองที่เลือก</div></div>
  </div>

  <div class="card">
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px;">
      <h2 style="margin:0;">แจกแจงยอดตาม</h2>
      <?php foreach ($byOptions as $bk => $bl): ?>
        <a href="?<?= $h($qs(['by' => $bk])) ?>" class="filter-chip" style="<?= $chipStyle($by === $bk) ?>"><?= $h($bl) ?></a>
      <?php endforeach; ?>
    </div>
    <?php if (empty($breakdown)): ?>
      <p class="empty-state">ไม่มีรายการเบิกจ่ายตามตัวกรองนี้</p>
    <?php else:
      $maxNet = max(array_map(static fn ($r) => abs($r['net']), $breakdown)) ?: 1.0; ?>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead>
            <tr>
              <th><?= $h($byOptions[$by]) ?></th>
              <th class="num">จำนวนรายการ</th>
              <th class="num">รายจ่าย</th>
              <th class="num">รายรับ</th>
              <th class="num">เบิกจ่ายสุทธิ</th>
              <th style="width:22%;"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($breakdown as $r):
              $href = $by === 'item'
                  ? bpm_url('ledger.php') . '?item=' . (int) $r['key']
                  : '?' . $qs([$by => $r['key']]); ?>
              <tr>
                <td><a href="<?= $h($href) ?>"><?= $h($r['label']) ?></a></td>
                <td class="num"><?= number_format($r['count']) ?></td>
                <td class="num"><?= $money($r['expense']) ?></td>
                <td class="num"><?= $money($r['income']) ?></td>
                <td class="num" style="font-weight:600;"><?= $money($r['net']) ?></td>
                <td><div class="kpi-progress"><span style="width:<?= round(min(100, max(0, abs($r['net']) / $maxNet * 100))) ?>%;"></span></div></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="text-muted small" style="margin-top:10px;"><?= $by === 'item' ? 'คลิกชื่อรายการเพื่อดูสมุดของรายการนั้น' : 'คลิกชื่อแถวเพื่อกรองต่อด้วยมิตินั้น' ?></p>
    <?php endif; ?>
  </div>

  <div class="card main-panel">
    <div class="table-toolbar">
      <h2 style="margin:0;">รายการทั้งหมด (<?= number_format($listing['total']) ?>)</h2>
      <form method="get" class="search-box">
        <?php foreach (array_filter(array_merge($cur, ['q' => null]), static fn ($v) => $v !== null && $v !== '') as $k => $v): ?>
          <input type="hidden" name="<?= $h($k) ?>" value="<?= $h($v) ?>">
        <?php endforeach; ?>
        <?= bpm_icon('search', 14) ?>
        <input type="text" name="q" placeholder="ค้นหารายการ / เลขที่อ้างอิง..." value="<?= $h($search) ?>">
      </form>
    </div>
    <?php if (empty($listing['rows'])): ?>
      <p class="empty-state">ไม่มีรายการตามตัวกรองนี้</p>
    <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead>
            <tr>
              <th class="center">วันที่</th>
              <?php if ($selectedDepartmentId === null): ?><th>สาขา</th><?php endif; ?>
              <th>รายการ</th>
              <th>เลขที่อ้างอิง</th>
              <th class="center">ประเภท</th>
              <th class="num">จำนวนเงิน</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($listing['rows'] as $t): ?>
              <tr>
                <td class="center"><?= $h(bpm_thai_date($t['txn_date'])) ?></td>
                <?php if ($selectedDepartmentId === null): ?><td><?= $h($t['department_name']) ?></td><?php endif; ?>
                <td><a href="<?= $h(bpm_url('ledger.php') . '?item=' . (int) $t['li_id']) ?>"><?= $h($t['line_item_name']) ?></a> — <?= $h($t['description']) ?></td>
                <td class="text-muted"><?= $h($t['reference_no'] ?? '-') ?></td>
                <td class="center"><span class="pill <?= $t['type'] === 'EXPENSE' ? 'pill-neutral' : 'pill-success' ?>"><?= $t['type'] === 'EXPENSE' ? 'รายจ่าย' : 'รายรับ' ?></span></td>
                <td class="num" style="<?= $t['type'] === 'INCOME' ? 'color: var(--status-success-text);' : '' ?>"><?= $t['type'] === 'EXPENSE' ? '-' : '+' ?><?= $money((float) $t['amount']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($listing['total_pages'] > 1): ?>
        <div style="display:flex; gap:8px; justify-content:center; margin-top:16px; flex-wrap:wrap;">
          <?php for ($p = 1; $p <= $listing['total_pages']; $p++): ?>
            <a href="?<?= $h($qs(['page' => $p])) ?>" class="filter-chip" style="<?= $chipStyle($p === $listing['page']) ?>"><?= $p ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

<?php require __DIR__ . '/../src/partials/layout_end.php'; ?>
