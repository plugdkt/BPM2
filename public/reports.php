<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$user = bpm_require_role('ADMIN', 'DEPT_STAFF', 'EXECUTIVE_VIEWER', 'DEPT_HEAD');

$fiscalYear = bpm_resolve_fiscal_year();
$selectedDepartmentId = bpm_resolve_department_filter($user);
$view = (string) ($_GET['view'] ?? 'table');
if (!in_array($view, ['table', 'matrix', 'sources'], true)) {
    $view = 'table';
}
// source=<id> กรองแหล่งเงิน (เฉพาะมุมมอง "ตารางรายการ"); ไม่ส่ง = ทุกแหล่งเงิน
$selectedSourceId = isset($_GET['source']) && $_GET['source'] !== '' ? (int) $_GET['source'] : null;
// group=<id> เจาะจงกลุ่มหมวด, group=0 = เฉพาะที่ยังไม่ระบุกลุ่ม, ไม่ส่ง group เลย = สรุปรวมทุกหมวด (เฉพาะมุมมอง "ตารางรายการ")
$selectedGroupId = isset($_GET['group']) && $_GET['group'] !== '' ? (int) $_GET['group'] : null;
$groups = bpm_db()->query('SELECT * FROM budget_groups WHERE is_active = 1 ORDER BY id')->fetchAll();

$pageTitle = 'รายงานสรุป';
$activeNav = 'reports';

if ($fiscalYear === null) {
    require __DIR__ . '/../src/partials/layout_start.php';
    echo '<div class="card empty-state">ยังไม่มีปีงบประมาณในระบบ</div>';
    require __DIR__ . '/../src/partials/layout_end.php';
    exit;
}

// --- Export (ก่อนเรียก layout — ต้องส่ง header ก่อนมี output ใดๆ) ---
$exportType = $_GET['export'] ?? null;
if ($exportType === 'excel' || $exportType === 'pdf') {
    require_once __DIR__ . '/../src/lib/export.php';

    if ($view === 'matrix') {
        $data = bpm_report_matrix((int) $fiscalYear['id']);
        $headers = ['รายการ'];
        foreach ($data['departments'] as $d) {
            $headers[] = $d['name'];
        }
        $headers[] = 'รวม';

        $rows = [];
        foreach ($data['rows'] as $name => $row) {
            $line = [$name];
            foreach ($data['departments'] as $d) {
                $line[] = number_format($row['amounts'][(int) $d['id']] ?? 0, 2, '.', '');
            }
            $line[] = number_format($row['total'], 2, '.', '');
            $rows[] = $line;
        }
        $filename = 'bpm-matrix-' . $fiscalYear['year_be'];
        $numericFrom = 1; // ทุกคอลัมน์ตั้งแต่ index 1 (หลัง "รายการ") เป็นตัวเลข
    } elseif ($view === 'sources') {
        $headers = ['แหล่งเงิน', 'สาขา', 'วงเงินที่ได้รับ', 'แบ่งเป็นรายการงบ', 'ยังแบ่งได้อีก', 'เบิกจ่ายแล้ว', 'คงเหลือ', '% เบิกจ่าย'];
        $rows = [];
        $fmt = static fn (?float $v): string => $v === null ? '' : number_format($v, 2, '.', '');
        foreach (bpm_report_fund_sources($selectedDepartmentId, (int) $fiscalYear['id']) as $blk) {
            foreach (array_merge($blk['rows'], [['name' => 'รวม'] + $blk['totals']]) as $r) {
                $rows[] = [$blk['source']['name'], $r['name'], $fmt($r['limit']), $fmt($r['allocated']), $fmt($r['unallocated']), $fmt($r['spent']), $fmt($r['balance']), number_format($r['spent_pct'], 1) . '%'];
            }
        }
        $filename = 'bpm-sources-' . $fiscalYear['year_be'];
        $numericFrom = 2; // แหล่งเงิน(0), สาขา(1) เป็นข้อความ ที่เหลือเป็นตัวเลข
    } else {
        $items = bpm_report_line_items($selectedDepartmentId, (int) $fiscalYear['id']);
        if ($selectedSourceId !== null) {
            $items = array_values(array_filter($items, static fn ($it) => (int) $it['fund_source_id'] === $selectedSourceId));
        }
        // ถ้าเลือกดูหมวดเงินเจาะจงอยู่ ให้ export เฉพาะหมวดนั้นตามที่เห็นบนจอ — ถ้าดู "ทั้งหมด" (สรุปรวม) export รายละเอียดเต็มเสมอ มีประโยชน์กว่าสรุปแค่ไม่กี่แถว
        if ($selectedGroupId !== null) {
            $items = array_values(array_filter($items, static function ($it) use ($selectedGroupId) {
                return $selectedGroupId === 0 ? $it['group_id'] === null : (int) $it['group_id'] === $selectedGroupId;
            }));
        }
        $headers = ['สาขา', 'รายการ', 'จัดสรร', 'เบิกจ่ายแล้ว', 'คงเหลือ', '% เบิกจ่าย'];
        $rows = [];
        foreach ($items as $it) {
            $rows[] = [
                $it['department_name'], bpm_li_label($it),
                number_format($it['total_budget'], 2, '.', ''),
                number_format($it['spent'], 2, '.', ''),
                number_format($it['balance'], 2, '.', ''),
                number_format($it['spent_pct'], 1) . '%',
            ];
        }
        $filename = 'bpm-report-' . $fiscalYear['year_be'];
        $numericFrom = 2; // สาขา(0), รายการ(1) เป็นข้อความ ที่เหลือเป็นตัวเลข
    }

    if ($exportType === 'excel') {
        bpm_send_excel($headers, $rows, $filename);
    }

    $bodyHtml = '<h1>รายงานงบประมาณ ปีงบ พ.ศ. ' . (int) $fiscalYear['year_be'] . '</h1><table><thead><tr>';
    foreach ($headers as $h) {
        $bodyHtml .= '<th>' . htmlspecialchars($h, ENT_QUOTES) . '</th>';
    }
    $bodyHtml .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $bodyHtml .= '<tr>';
        foreach ($row as $i => $cell) {
            $bodyHtml .= '<td' . ($i >= $numericFrom ? ' class="num"' : '') . '>' . htmlspecialchars((string) $cell, ENT_QUOTES) . '</td>';
        }
        $bodyHtml .= '</tr>';
    }
    $bodyHtml .= '</tbody></table>';
    bpm_send_pdf($bodyHtml, $filename);
}

// --- แสดงผลปกติ ---
require __DIR__ . '/../src/partials/layout_start.php';

$baseQs = static fn (array $extra = []) => http_build_query(array_filter(
    array_merge(['fy' => $_GET['fy'] ?? null, 'dept' => $_GET['dept'] ?? null, 'group' => $_GET['group'] ?? null, 'source' => $_GET['source'] ?? null], $extra),
    static fn ($v) => $v !== null && $v !== ''
));
$exportQs = $baseQs(['view' => $view]);
// ลิงก์เจาะลึก: $ledgerUrl = หน้าสมุดรายการ (อ่านอย่างเดียว ทุก role), $tableUrl = มุมมองตารางรายการของรายงานนี้
$ledgerUrl = static fn (array $q): string => bpm_url('ledger.php') . '?' . http_build_query(array_filter(array_merge(['fy' => $_GET['fy'] ?? null], $q), static fn ($v) => $v !== null && $v !== ''));
$tableUrl = static fn (array $q): string => '?' . http_build_query(array_filter(array_merge(['fy' => $_GET['fy'] ?? null, 'view' => 'table'], $q), static fn ($v) => $v !== null && $v !== ''));
?>

  <div class="card" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:14px;">
    <div style="display:flex; gap:10px;">
      <a href="?<?= $baseQs(['view' => 'table']) ?>"
         class="filter-chip" style="<?= $view === 'table' ? 'background:var(--accent); color:#fff;' : '' ?>">ตารางรายการ</a>
      <a href="?<?= $baseQs(['view' => 'matrix']) ?>"
         class="filter-chip" style="<?= $view === 'matrix' ? 'background:var(--accent); color:#fff;' : '' ?>">ตารางไขว้ (ตามสาขา)</a>
      <a href="?<?= $baseQs(['view' => 'sources']) ?>"
         class="filter-chip" style="<?= $view === 'sources' ? 'background:var(--accent); color:#fff;' : '' ?>">แยกตามแหล่งเงิน</a>
    </div>
    <div style="display:flex; gap:8px;">
      <a href="?<?= $exportQs ?>&export=excel" class="filter-chip"><?= bpm_icon('download', 14) ?> Export Excel</a>
      <a href="?<?= $exportQs ?>&export=pdf" class="filter-chip"><?= bpm_icon('download', 14) ?> Export PDF</a>
    </div>
  </div>

  <?php if ($view === 'matrix'):
    $data = bpm_report_matrix((int) $fiscalYear['id']); ?>
    <div class="card">
      <h2>ตารางไขว้ — รายการ × สาขา (ปีงบ พ.ศ. <?= (int) $fiscalYear['year_be'] ?>)</h2>
      <?php if (empty($data['rows'])): ?>
        <p class="empty-state">ยังไม่มีรายการงบในปีงบนี้</p>
      <?php else: ?>
        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th>รายการ</th>
                <?php foreach ($data['departments'] as $d): ?><th class="num"><a href="<?= htmlspecialchars($tableUrl(['dept' => (int) $d['id']]), ENT_QUOTES) ?>" title="ดูรายละเอียดของสาขานี้"><?= htmlspecialchars($d['name'], ENT_QUOTES) ?></a></th><?php endforeach; ?>
                <th class="num">รวม</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($data['rows'] as $name => $row): ?>
                <tr>
                  <td><?= htmlspecialchars($name, ENT_QUOTES) ?></td>
                  <?php foreach ($data['departments'] as $d): $amt = $row['amounts'][(int) $d['id']] ?? null; ?>
                    <td class="num"><?= $amt !== null ? htmlspecialchars(bpm_money($amt), ENT_QUOTES) : '<span class="text-muted">-</span>' ?></td>
                  <?php endforeach; ?>
                  <td class="num" style="font-weight:600;"><?= htmlspecialchars(bpm_money($row['total']), ENT_QUOTES) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php elseif ($view === 'sources'):
    $blocks = bpm_report_fund_sources($selectedDepartmentId, (int) $fiscalYear['id']);
    $remCell = static fn (?float $v): string => $v === null
        ? '<span class="text-muted">—</span>'
        : '<span style="color:' . ($v < -0.004 ? 'var(--status-danger-text)' : 'var(--status-success-text)') . ';">' . htmlspecialchars(bpm_money($v), ENT_QUOTES) . ($v < -0.004 ? ' (เกิน)' : '') . '</span>';
    $limitCell = static fn (?float $v): string => $v === null ? '<span class="text-muted">—</span>' : htmlspecialchars(bpm_money($v), ENT_QUOTES); ?>
    <?php if (empty($blocks)): ?>
      <div class="card empty-state">ยังไม่มีข้อมูลแหล่งเงินในปีงบนี้ — ตั้งค่าที่เมนู "ตั้งค่างบประมาณ"</div>
    <?php endif; ?>
    <?php foreach ($blocks as $blk): $t = $blk['totals']; ?>
      <div class="card">
        <h2><?= htmlspecialchars($blk['source']['name'], ENT_QUOTES) ?> — ปีงบ พ.ศ. <?= (int) $fiscalYear['year_be'] ?>
          <?php if (($blk['source']['code'] ?? '') === 'UNSPECIFIED'): ?><span class="pill pill-warning" style="font-weight:400;">ยังไม่ได้ระบุแหล่งเงิน</span><?php endif; ?></h2>
        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th>สาขา/หลักสูตร</th>
                <th class="num">วงเงินที่ได้รับ</th>
                <th class="num">แบ่งเป็นรายการงบ</th>
                <th class="num">ยังแบ่งได้อีก</th>
                <th class="num">เบิกจ่ายแล้ว</th>
                <th class="num">คงเหลือ</th>
                <th class="num">% เบิกจ่าย</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($blk['rows'] as $r): ?>
                <tr>
                  <td><a href="<?= htmlspecialchars($tableUrl(['dept' => (int) $r['department_id'], 'source' => (int) $blk['source']['id']]), ENT_QUOTES) ?>" title="ดูรายการงบของสาขานี้"><?= htmlspecialchars($r['name'], ENT_QUOTES) ?></a></td>
                  <td class="num"><?= $limitCell($r['limit']) ?></td>
                  <td class="num"><?= htmlspecialchars(bpm_money($r['allocated']), ENT_QUOTES) ?></td>
                  <td class="num"><?= $remCell($r['unallocated']) ?></td>
                  <td class="num"><a href="<?= htmlspecialchars($ledgerUrl(['dept' => (int) $r['department_id'], 'source' => (int) $blk['source']['id']]), ENT_QUOTES) ?>" title="ดูรายการเบิกจ่าย"><?= htmlspecialchars(bpm_money($r['spent']), ENT_QUOTES) ?></a></td>
                  <td class="num"><?= $remCell($r['balance']) ?></td>
                  <td class="num"><?= number_format($r['spent_pct'], 1) ?>%</td>
                </tr>
              <?php endforeach; ?>
              <tr style="border-top:2px solid var(--border-subtle); font-weight:600;">
                <td>รวม<?= count($blk['rows']) > 1 ? 'ทั้งหมด' : '' ?></td>
                <td class="num"><?= $limitCell($t['limit']) ?></td>
                <td class="num"><?= htmlspecialchars(bpm_money($t['allocated']), ENT_QUOTES) ?></td>
                <td class="num"><?= $remCell($t['unallocated']) ?></td>
                <td class="num"><a href="<?= htmlspecialchars($ledgerUrl(['dept' => $selectedDepartmentId, 'source' => (int) $blk['source']['id']]), ENT_QUOTES) ?>" title="ดูรายการเบิกจ่าย"><?= htmlspecialchars(bpm_money($t['spent']), ENT_QUOTES) ?></a></td>
                <td class="num"><?= $remCell($t['balance']) ?></td>
                <td class="num"><?= number_format($t['spent_pct'], 1) ?>%</td>
              </tr>
            </tbody>
          </table>
        </div>
        <?php if ($blk['source_total'] !== null && $selectedDepartmentId === null): ?>
          <p class="text-muted small" style="margin-top:10px;">วงเงินทั้งก้อนของแหล่งเงินที่ตั้งไว้ <?= htmlspecialchars(bpm_money($blk['source_total']), ENT_QUOTES) ?></p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <p class="text-muted small">คลิกชื่อสาขาเพื่อดูรายการงบ · คลิกยอดเบิกจ่ายเพื่อดูรายการเบิกจ่าย (แจกแจงตามสาขา/หมวดเงิน/ไตรมาสได้)<br>"แบ่งเป็นรายการงบ" = ผลรวมงบต้นปีของรายการงบที่เปิดใช้งาน · "ยังแบ่งได้อีก" = วงเงินที่ได้รับ − แบ่งเป็นรายการงบ · "คงเหลือ" = แบ่งเป็นรายการงบ − เบิกจ่ายแล้ว</p>
  <?php else:
    $items = bpm_report_line_items($selectedDepartmentId, (int) $fiscalYear['id']);
    if ($selectedSourceId !== null) {
        $items = array_values(array_filter($items, static fn ($it) => (int) $it['fund_source_id'] === $selectedSourceId));
    }
    $tabQs = static fn (?int $deptId) => http_build_query(array_filter(['fy' => $_GET['fy'] ?? null, 'view' => 'table', 'group' => $_GET['group'] ?? null, 'source' => $_GET['source'] ?? null, 'dept' => $deptId], static fn ($v) => $v !== null && $v !== ''));
    $groupTabQs = static fn (?int $groupId) => http_build_query(array_filter(['fy' => $_GET['fy'] ?? null, 'view' => 'table', 'dept' => $_GET['dept'] ?? null, 'source' => $_GET['source'] ?? null, 'group' => $groupId], static fn ($v) => $v !== null && $v !== ''));
    $sourceTabQs = static fn (?int $sid) => http_build_query(array_filter(['fy' => $_GET['fy'] ?? null, 'view' => 'table', 'dept' => $_GET['dept'] ?? null, 'group' => $_GET['group'] ?? null, 'source' => $sid], static fn ($v) => $v !== null && $v !== ''));
    $sourcesInUse = [];
    if (bpm_multiple_fund_sources()) {
        $sq = bpm_db()->prepare('SELECT DISTINCT fs.id, fs.name FROM budget_line_items li JOIN fund_sources fs ON fs.id = li.fund_source_id WHERE li.fiscal_year_id = ? AND li.is_active = 1 ORDER BY fs.id');
        $sq->execute([(int) $fiscalYear['id']]);
        $sourcesInUse = $sq->fetchAll();
    } ?>
    <div class="card">
      <div style="display:flex; gap:8px; flex-wrap:wrap; overflow-x:auto;">
        <?php if (!in_array($user['role'], ['DEPT_STAFF', 'DEPT_HEAD'], true)): // ADMIN/EXECUTIVE_VIEWER ดูรวมทุกสาขาได้ — DEPT_STAFF/DEPT_HEAD ถูกล็อกที่สาขาตัวเอง ?>
          <a href="?<?= $tabQs(null) ?>" class="filter-chip" style="<?= $selectedDepartmentId === null ? 'background:var(--accent); color:#fff;' : '' ?>">ทั้งหมด</a>
        <?php endif; ?>
        <?php foreach (bpm_all_departments() as $d): ?>
          <a href="?<?= $tabQs((int) $d['id']) ?>" class="filter-chip" style="<?= $selectedDepartmentId === (int) $d['id'] ? 'background:var(--accent); color:#fff;' : '' ?>"><?= htmlspecialchars($d['name'], ENT_QUOTES) ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if (!empty($sourcesInUse)): ?>
    <div class="card">
      <div style="display:flex; gap:8px; flex-wrap:wrap; overflow-x:auto; align-items:center;">
        <span class="text-muted small" style="margin-right:2px;">แหล่งเงิน:</span>
        <a href="?<?= $sourceTabQs(null) ?>" class="filter-chip" style="<?= $selectedSourceId === null ? 'background:var(--accent); color:#fff;' : '' ?>">ทั้งหมด</a>
        <?php foreach ($sourcesInUse as $sr): ?>
          <a href="?<?= $sourceTabQs((int) $sr['id']) ?>" class="filter-chip" style="<?= $selectedSourceId === (int) $sr['id'] ? 'background:var(--accent); color:#fff;' : '' ?>"><?= htmlspecialchars($sr['name'], ENT_QUOTES) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div style="display:flex; gap:8px; flex-wrap:wrap; overflow-x:auto; align-items:center;">
        <span class="text-muted small" style="margin-right:2px;">หมวดเงิน:</span>
        <a href="?<?= $groupTabQs(null) ?>" class="filter-chip" style="<?= $selectedGroupId === null ? 'background:var(--accent); color:#fff;' : '' ?>">ทั้งหมด</a>
        <?php foreach ($groups as $g): ?>
          <a href="?<?= $groupTabQs((int) $g['id']) ?>" class="filter-chip" style="<?= $selectedGroupId === (int) $g['id'] ? 'background:var(--accent); color:#fff;' : '' ?>"><?= htmlspecialchars($g['name'], ENT_QUOTES) ?></a>
        <?php endforeach; ?>
        <a href="?<?= $groupTabQs(0) ?>" class="filter-chip" style="<?= $selectedGroupId === 0 ? 'background:var(--accent); color:#fff;' : '' ?>">ไม่ระบุกลุ่ม</a>
      </div>
    </div>

    <?php if ($selectedGroupId === null):
      // สรุปรวมตามหมวดเงินก่อน (เร็ว โหลดน้อย) — กดเข้าไปดูรายละเอียดรายการย่อยทีหลังได้
      $groupRollup = [];
      $groupNameMap = array_column($groups, 'name', 'id');
      foreach ($items as $it) {
          $gid = $it['group_id'] !== null ? (int) $it['group_id'] : 'none';
          if (!isset($groupRollup[$gid])) {
              $groupRollup[$gid] = ['id' => $gid, 'name' => $gid === 'none' ? 'ไม่ระบุกลุ่ม' : ($groupNameMap[$gid] ?? ''), 'total_budget' => 0.0, 'spent' => 0.0, 'balance' => 0.0];
          }
          $groupRollup[$gid]['total_budget'] += $it['total_budget'];
          $groupRollup[$gid]['spent']        += $it['spent'];
          $groupRollup[$gid]['balance']      += $it['balance'];
      }
      uksort($groupRollup, static fn ($a, $b) => ($a === 'none' ? PHP_INT_MAX : $a) <=> ($b === 'none' ? PHP_INT_MAX : $b)); ?>
      <div class="card">
        <h2>สรุปงบตามหมวดเงิน — ปีงบ พ.ศ. <?= (int) $fiscalYear['year_be'] ?></h2>
        <?php if (empty($groupRollup)): ?>
          <p class="empty-state">ยังไม่มีรายการงบในปีงบนี้</p>
        <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>หมวดเงิน</th>
                <th class="num">จัดสรร</th>
                <th class="num">เบิกจ่ายแล้ว</th>
                <th class="num">คงเหลือ</th>
                <th class="num">% เบิกจ่าย</th>
              </tr>
            </thead>
            <tbody>
              <?php $sumBudget = $sumSpent = $sumBalance = 0.0; foreach ($groupRollup as $g): $sumBudget += $g['total_budget']; $sumSpent += $g['spent']; $sumBalance += $g['balance']; ?>
                <tr>
                  <td><a href="?<?= $groupTabQs($g['id'] === 'none' ? 0 : (int) $g['id']) ?>"><?= htmlspecialchars($g['name'], ENT_QUOTES) ?></a></td>
                  <td class="num"><?= htmlspecialchars(bpm_money($g['total_budget']), ENT_QUOTES) ?></td>
                  <td class="num"><a href="<?= htmlspecialchars($ledgerUrl(['dept' => $selectedDepartmentId, 'source' => $selectedSourceId, 'group' => $g['id'] === 'none' ? 0 : (int) $g['id']]), ENT_QUOTES) ?>" title="ดูรายการเบิกจ่ายของหมวดนี้"><?= htmlspecialchars(bpm_money($g['spent']), ENT_QUOTES) ?></a></td>
                  <td class="num" style="<?= $g['balance'] < 0 ? 'color: var(--status-danger-text);' : 'color: var(--status-success-text);' ?>"><?= htmlspecialchars(bpm_money($g['balance']), ENT_QUOTES) ?></td>
                  <td class="num"><?= $g['total_budget'] > 0 ? number_format(($g['spent'] / $g['total_budget']) * 100, 1) : '0.0' ?>%</td>
                </tr>
              <?php endforeach; ?>
              <tr style="border-top:2px solid var(--border-subtle);">
                <td style="font-weight:600;">รวมทั้งหมด</td>
                <td class="num" style="font-weight:600;"><?= htmlspecialchars(bpm_money($sumBudget), ENT_QUOTES) ?></td>
                <td class="num" style="font-weight:600;"><?= htmlspecialchars(bpm_money($sumSpent), ENT_QUOTES) ?></td>
                <td class="num" style="font-weight:600; color: var(--status-success-text);"><?= htmlspecialchars(bpm_money($sumBalance), ENT_QUOTES) ?></td>
                <td class="num" style="font-weight:600;"><?= $sumBudget > 0 ? number_format(($sumSpent / $sumBudget) * 100, 1) : '0.0' ?>%</td>
              </tr>
            </tbody>
          </table>
          <p class="text-muted small" style="margin-top:12px;">คลิกชื่อหมวดเงินเพื่อดูแต่ละรายการงบ · คลิกยอดเบิกจ่ายเพื่อดูรายการเบิกจ่าย</p>
        <?php endif; ?>
      </div>
    <?php else:
      $groupItems = array_values(array_filter($items, static function ($it) use ($selectedGroupId) {
          return $selectedGroupId === 0 ? $it['group_id'] === null : (int) $it['group_id'] === $selectedGroupId;
      }));
      $groupNameMap = array_column($groups, 'name', 'id'); ?>
      <div class="card">
        <h2>รายละเอียดตามรายการ — หมวด "<?= htmlspecialchars($selectedGroupId === 0 ? 'ไม่ระบุกลุ่ม' : ($groupNameMap[$selectedGroupId] ?? ''), ENT_QUOTES) ?>" — ปีงบ พ.ศ. <?= (int) $fiscalYear['year_be'] ?></h2>
        <?php if (empty($groupItems)): ?>
          <p class="empty-state">ไม่มีรายการงบในหมวดนี้</p>
        <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <?php if ($selectedDepartmentId === null): ?><th>สาขา</th><?php endif; ?>
                <th>รายการ</th>
                <th class="num">จัดสรร</th>
                <th class="num">เบิกจ่ายแล้ว</th>
                <th class="num">คงเหลือ</th>
                <th class="num">% เบิกจ่าย</th>
              </tr>
            </thead>
            <tbody>
              <?php $sumBudget = $sumSpent = $sumBalance = 0.0; foreach ($groupItems as $it): $sumBudget += $it['total_budget']; $sumSpent += $it['spent']; $sumBalance += $it['balance']; ?>
                <tr>
                  <?php if ($selectedDepartmentId === null): ?><td><a href="<?= htmlspecialchars($tableUrl(['dept' => (int) $it['department_id'], 'source' => $selectedSourceId, 'group' => $selectedGroupId]), ENT_QUOTES) ?>"><?= htmlspecialchars($it['department_name'], ENT_QUOTES) ?></a></td><?php endif; ?>
                  <td><a href="<?= htmlspecialchars($ledgerUrl(['item' => (int) $it['id']]), ENT_QUOTES) ?>" title="ดูสมุดของรายการนี้"><?= htmlspecialchars(bpm_li_label($it), ENT_QUOTES) ?></a></td>
                  <td class="num"><?= htmlspecialchars(bpm_money($it['total_budget']), ENT_QUOTES) ?></td>
                  <td class="num"><a href="<?= htmlspecialchars($ledgerUrl(['item' => (int) $it['id']]), ENT_QUOTES) ?>" title="ดูรายการเบิกจ่าย"><?= htmlspecialchars(bpm_money($it['spent']), ENT_QUOTES) ?></a></td>
                  <td class="num" style="color: var(--status-success-text);"><?= htmlspecialchars(bpm_money($it['balance']), ENT_QUOTES) ?></td>
                  <td class="num"><?= number_format($it['spent_pct'], 1) ?>%</td>
                </tr>
              <?php endforeach; ?>
              <tr style="border-top:2px solid var(--border-subtle);">
                <td colspan="<?= $selectedDepartmentId === null ? 2 : 1 ?>" style="font-weight:600;">รวมหมวดนี้</td>
                <td class="num" style="font-weight:600;"><?= htmlspecialchars(bpm_money($sumBudget), ENT_QUOTES) ?></td>
                <td class="num" style="font-weight:600;"><?= htmlspecialchars(bpm_money($sumSpent), ENT_QUOTES) ?></td>
                <td class="num" style="font-weight:600; color: var(--status-success-text);"><?= htmlspecialchars(bpm_money($sumBalance), ENT_QUOTES) ?></td>
                <td class="num" style="font-weight:600;"><?= $sumBudget > 0 ? number_format(($sumSpent / $sumBudget) * 100, 1) : '0.0' ?>%</td>
              </tr>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

<?php require __DIR__ . '/../src/partials/layout_end.php'; ?>
