<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$user = bpm_require_role('ADMIN');
$pageTitle = 'ประวัติการเปลี่ยนแปลง';
$activeNav = 'admin-audit-log';

$actionFilter = trim((string) ($_GET['action'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

$listing = bpm_list_audit_logs($actionFilter !== '' ? $actionFilter : null, $page, 50);

/** แปลงค่า scalar/array/null จาก JSON ให้อ่านง่ายในตาราง diff */
function bpm_audit_scalar_text($v): string
{
    if ($v === null) {
        return '(ว่าง)';
    }
    if (is_bool($v)) {
        return $v ? 'true' : 'false';
    }
    if (is_array($v)) {
        return json_encode($v, JSON_UNESCAPED_UNICODE);
    }
    return (string) $v;
}

/** สร้าง HTML สรุป diff จาก old_value/new_value (JSON text หรือ null) ของ audit_logs หนึ่งแถว */
function bpm_render_audit_diff(?string $oldJson, ?string $newJson): string
{
    $old = $oldJson !== null ? json_decode($oldJson, true) : null;
    $new = $newJson !== null ? json_decode($newJson, true) : null;

    if ($old === null && $new !== null) {
        $parts = [];
        foreach ($new as $k => $v) {
            $parts[] = htmlspecialchars((string) $k, ENT_QUOTES) . ': ' . htmlspecialchars(bpm_audit_scalar_text($v), ENT_QUOTES);
        }
        return '<span class="pill pill-success" style="margin-right:6px;">สร้างใหม่</span>' . implode(' · ', $parts);
    }

    if ($new === null && $old !== null) {
        $parts = [];
        foreach ($old as $k => $v) {
            $parts[] = htmlspecialchars((string) $k, ENT_QUOTES) . ': ' . htmlspecialchars(bpm_audit_scalar_text($v), ENT_QUOTES);
        }
        return '<span class="pill pill-danger" style="margin-right:6px;">ลบ</span>' . implode(' · ', $parts);
    }

    if ($old !== null && $new !== null) {
        $parts = [];
        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
        foreach ($keys as $k) {
            $ov = $old[$k] ?? null;
            $nv = $new[$k] ?? null;
            if ($ov !== $nv) {
                $parts[] = '<strong>' . htmlspecialchars((string) $k, ENT_QUOTES) . '</strong>: '
                    . htmlspecialchars(bpm_audit_scalar_text($ov), ENT_QUOTES) . ' → ' . htmlspecialchars(bpm_audit_scalar_text($nv), ENT_QUOTES);
            }
        }
        return $parts ? implode('<br>', $parts) : '<span class="text-muted small">ไม่มีการเปลี่ยนแปลงค่า</span>';
    }

    return '<span class="text-muted small">-</span>';
}

require __DIR__ . '/../../src/partials/layout_start.php';

$filterQs = static fn (string $action) => http_build_query(array_filter(['action' => $action], static fn ($v) => $v !== ''));
?>

  <div class="card">
    <div style="display:flex; gap:8px; flex-wrap:wrap; overflow-x:auto;">
      <a href="?<?= $filterQs('') ?>" class="filter-chip" style="<?= $actionFilter === '' ? 'background:var(--accent); color:#fff;' : '' ?>">ทั้งหมด</a>
      <?php foreach (BPM_AUDIT_ACTION_LABELS as $code => $label): ?>
        <a href="?<?= $filterQs($code) ?>" class="filter-chip" style="<?= $actionFilter === $code ? 'background:var(--accent); color:#fff;' : '' ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <h2>ประวัติการเปลี่ยนแปลงทั้งหมด (<?= number_format($listing['total']) ?> รายการ)</h2>
    <p class="text-muted small" style="margin-top:-8px; margin-bottom:16px;">
      บันทึกอัตโนมัติทุกครั้งที่มีการแก้ไข/ลบ/อนุมัติข้อมูลสำคัญ — ไม่มีปุ่มลบประวัตินี้โดยเจตนา
    </p>

    <?php if (empty($listing['rows'])): ?>
      <p class="empty-state">ยังไม่มีประวัติการเปลี่ยนแปลง<?= $actionFilter !== '' ? 'ในหมวดที่เลือก' : '' ?></p>
    <?php else: ?>
      <table class="data-table">
        <thead>
          <tr>
            <th class="center" style="width:150px;">วันที่/เวลา</th>
            <th style="width:150px;">ผู้ทำรายการ</th>
            <th style="width:170px;">การกระทำ</th>
            <th style="width:130px;">เป้าหมาย</th>
            <th>รายละเอียด</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($listing['rows'] as $log): ?>
            <tr>
              <td class="center text-muted small"><?= htmlspecialchars(bpm_thai_date($log['created_at']), ENT_QUOTES) ?> <?= htmlspecialchars((new DateTimeImmutable($log['created_at']))->format('H:i'), ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($log['actor_name'], ENT_QUOTES) ?></td>
              <td><span class="pill pill-neutral"><?= htmlspecialchars(bpm_audit_action_label($log['action']), ENT_QUOTES) ?></span></td>
              <td class="text-muted small"><?= htmlspecialchars($log['target_table'], ENT_QUOTES) ?> #<?= (int) $log['target_id'] ?></td>
              <td class="small"><?= bpm_render_audit_diff($log['old_value'], $log['new_value']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($listing['total_pages'] > 1): ?>
        <div style="display:flex; gap:8px; justify-content:center; margin-top:16px; flex-wrap:wrap;">
          <?php for ($p = 1; $p <= $listing['total_pages']; $p++):
              $qs = array_filter(['action' => $actionFilter ?: null, 'page' => $p], static fn ($v) => $v !== null && $v !== ''); ?>
            <a href="?<?= http_build_query($qs) ?>" class="filter-chip" style="<?= $p === $listing['page'] ? 'background:var(--accent); color:#fff;' : '' ?>"><?= $p ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

<?php require __DIR__ . '/../../src/partials/layout_end.php'; ?>
