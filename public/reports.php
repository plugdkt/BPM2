<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$user = bpm_require_role('ADMIN', 'DEPT_STAFF', 'EXECUTIVE_VIEWER');

$fiscalYear = bpm_resolve_fiscal_year();
$selectedDepartmentId = bpm_resolve_department_filter($user);
$view = ($_GET['view'] ?? 'table') === 'matrix' ? 'matrix' : 'table';

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
    } else {
        $items = bpm_report_line_items($selectedDepartmentId, (int) $fiscalYear['id']);
        $headers = ['สาขา', 'รายการ', 'จัดสรร', 'เบิกจ่ายแล้ว', 'คงเหลือ', '% เบิกจ่าย'];
        $rows = [];
        foreach ($items as $it) {
            $rows[] = [
                $it['department_name'], $it['name'],
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

$exportQs = http_build_query(array_filter(['fy' => $_GET['fy'] ?? null, 'dept' => $_GET['dept'] ?? null, 'view' => $view]));
?>

  <div class="card" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:14px;">
    <div style="display:flex; gap:10px;">
      <a href="?<?= http_build_query(array_filter(['fy' => $_GET['fy'] ?? null, 'dept' => $_GET['dept'] ?? null, 'view' => 'table'])) ?>"
         class="filter-chip" style="<?= $view === 'table' ? 'background:var(--accent); color:#fff;' : '' ?>">ตารางรายการ</a>
      <a href="?<?= http_build_query(array_filter(['fy' => $_GET['fy'] ?? null, 'dept' => $_GET['dept'] ?? null, 'view' => 'matrix'])) ?>"
         class="filter-chip" style="<?= $view === 'matrix' ? 'background:var(--accent); color:#fff;' : '' ?>">ตารางไขว้ (ตามสาขา)</a>
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
                <?php foreach ($data['departments'] as $d): ?><th class="num"><?= htmlspecialchars($d['name'], ENT_QUOTES) ?></th><?php endforeach; ?>
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
  <?php else:
    $items = bpm_report_line_items($selectedDepartmentId, (int) $fiscalYear['id']);
    $tabQs = static fn (?int $deptId) => http_build_query(array_filter(['fy' => $_GET['fy'] ?? null, 'view' => 'table', 'dept' => $deptId])); ?>
    <div class="card">
      <div style="display:flex; gap:8px; flex-wrap:wrap; overflow-x:auto;">
        <a href="?<?= $tabQs(null) ?>" class="filter-chip" style="<?= $selectedDepartmentId === null ? 'background:var(--accent); color:#fff;' : '' ?>">ทั้งหมด</a>
        <?php foreach (bpm_all_departments() as $d): ?>
          <a href="?<?= $tabQs((int) $d['id']) ?>" class="filter-chip" style="<?= $selectedDepartmentId === (int) $d['id'] ? 'background:var(--accent); color:#fff;' : '' ?>"><?= htmlspecialchars($d['name'], ENT_QUOTES) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card">
      <h2>รายละเอียดตามรายการ — ปีงบ พ.ศ. <?= (int) $fiscalYear['year_be'] ?></h2>
      <?php if (empty($items)): ?>
        <p class="empty-state">ยังไม่มีรายการงบในปีงบนี้</p>
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
            <?php $sumBudget = $sumSpent = $sumBalance = 0.0; foreach ($items as $it): $sumBudget += $it['total_budget']; $sumSpent += $it['spent']; $sumBalance += $it['balance']; ?>
              <tr>
                <?php if ($selectedDepartmentId === null): ?><td><?= htmlspecialchars($it['department_name'], ENT_QUOTES) ?></td><?php endif; ?>
                <td><?= htmlspecialchars($it['name'], ENT_QUOTES) ?></td>
                <td class="num"><?= htmlspecialchars(bpm_money($it['total_budget']), ENT_QUOTES) ?></td>
                <td class="num"><?= htmlspecialchars(bpm_money($it['spent']), ENT_QUOTES) ?></td>
                <td class="num" style="color: var(--status-success-text);"><?= htmlspecialchars(bpm_money($it['balance']), ENT_QUOTES) ?></td>
                <td class="num"><?= number_format($it['spent_pct'], 1) ?>%</td>
              </tr>
            <?php endforeach; ?>
            <tr style="border-top:2px solid var(--border-subtle);">
              <td colspan="<?= $selectedDepartmentId === null ? 2 : 1 ?>" style="font-weight:600;">รวมทั้งหมด</td>
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

<?php require __DIR__ . '/../src/partials/layout_end.php'; ?>
