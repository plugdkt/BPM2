<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$user = bpm_require_role('ADMIN');
$pageTitle = 'วงเงินแหล่งเงิน';
$activeNav = 'admin-fund-budgets';

$fiscalYear = bpm_resolve_fiscal_year();
$fiscalYearId = (int) ($fiscalYear['id'] ?? 0);
$overview = $fiscalYearId ? bpm_fund_envelope_overview($fiscalYearId) : [];
// แท็บแหล่งเงิน: เลือกด้วย ?source=<id> (ไม่ส่ง = แหล่งแรก) แสดงทีละแหล่ง ไม่ต่อกันยาวลงไปด้านล่าง
$sourceIds = array_map(static fn ($e) => (int) $e['source']['id'], $overview);
$selectedSourceId = (int) ($_GET['source'] ?? 0);
if (!in_array($selectedSourceId, $sourceIds, true)) {
    $selectedSourceId = $sourceIds[0] ?? 0;
}
$sourceTabQs = static fn (int $id) => http_build_query(array_filter(['fy' => $_GET['fy'] ?? null, 'source' => $id], static fn ($v) => $v !== null && $v !== ''));
$fyClosed = ($fiscalYear['status'] ?? '') === 'CLOSED';

$formAction = htmlspecialchars(bpm_url('actions/save-fund-budget.php'), ENT_QUOTES);
$num = static fn (?float $v): string => $v === null ? '' : number_format($v, 2, '.', '');
$remainingCell = static function (?float $remaining): string {
    if ($remaining === null) {
        return '<span class="text-muted">—</span>';
    }
    $color = $remaining < 0 ? 'var(--status-danger-text)' : 'var(--status-success-text)';
    return '<span style="color:' . $color . ';">' . htmlspecialchars(bpm_money($remaining), ENT_QUOTES) . ($remaining < 0 ? ' (เกิน)' : '') . '</span>';
};

require __DIR__ . '/../../src/partials/layout_start.php';
?>

  <?php if (!$fiscalYearId): ?>
    <div class="card empty-state">ยังไม่มีปีงบประมาณในระบบ</div>
  <?php else: ?>
    <div class="card">
      <h2>วงเงินแหล่งเงิน ปีงบประมาณ พ.ศ. <?= (int) $fiscalYear['year_be'] ?></h2>
      <p class="text-muted small" style="margin-top:-8px;">
        ตั้งวงเงินที่<strong>แต่ละสาขา/หลักสูตรได้รับจากแต่ละแหล่งเงิน</strong> (ตามตาราง "งบประมาณที่ได้รับ" ของงานแผน) — วงเงินทั้งก้อนของแหล่งเงินเป็นทางเลือกเสริม
        จากนั้นแบ่งเป็นรายการงบของสาขาที่หน้า "ตั้งค่างบ" ระบบจะเทียบกับ<strong>ยอดที่แบ่งเป็นรายการงบแล้ว</strong>และเตือนเมื่อเกินวงเงิน (ไม่ block การบันทึก — ADMIN ตัดสินใจเอง)
        เว้นช่องว่างแล้วกดบันทึก = ยกเลิกวงเงินที่ตั้งไว้ (มีประวัติใน "ประวัติการเปลี่ยนแปลง")
        <?php if ($fyClosed): ?><br><strong>ปีงบนี้ปิดแล้ว แก้ไขไม่ได้</strong><?php endif; ?>
      </p>
    </div>

    <?php if (empty($overview)): ?>
      <div class="card empty-state">ยังไม่มีแหล่งเงิน — เพิ่มที่เมนู "แหล่งเงิน" ก่อน</div>
    <?php endif; ?>

    <?php if (count($overview) > 1): ?>
      <div class="card">
        <div style="display:flex; gap:8px; flex-wrap:wrap; overflow-x:auto;">
          <?php foreach ($overview as $e): $eid = (int) $e['source']['id']; ?>
            <a href="?<?= $sourceTabQs($eid) ?>" class="filter-chip" style="<?= $selectedSourceId === $eid ? 'background:var(--accent); color:#fff;' : '' ?>"><?= htmlspecialchars($e['source']['name'], ENT_QUOTES) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php foreach ($overview as $env): $src = $env['source']; $isUnspecified = $src['code'] === 'UNSPECIFIED'; $sid = (int) $src['id'];
      if ($sid !== $selectedSourceId) { continue; } ?>
      <div class="card">
        <h2><?= htmlspecialchars($src['name'], ENT_QUOTES) ?></h2>

        <?php if ($isUnspecified): ?>
          <p class="text-muted small">รายการงบที่ยังไม่ได้ระบุแหล่งเงิน รวม <strong><?= htmlspecialchars(bpm_money($env['allocated']), ENT_QUOTES) ?></strong> — ไปกำหนดแหล่งเงินให้แต่ละรายการที่หน้า "ตั้งค่างบ" (ตั้งวงเงินให้แหล่งนี้ไม่ได้)</p>
        <?php else: ?>
          <form method="post" action="<?= $formAction ?>" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-bottom:14px;">
            <?= bpm_csrf_field() ?>
            <input type="hidden" name="fiscal_year_id" value="<?= $fiscalYearId ?>">
            <input type="hidden" name="fund_source_id" value="<?= $sid ?>">
            <div>
              <label class="field-label">วงเงินทั้งก้อน (บาท)</label>
              <input type="text" inputmode="decimal" name="amount" class="field num" style="width:200px;" value="<?= $num($env['total']) ?>" placeholder="ยังไม่ตั้ง" <?= $fyClosed ? 'disabled' : '' ?>>
            </div>
            <?php if (!$fyClosed): ?><button type="submit" class="btn btn-primary" style="padding:8px 14px;">บันทึกวงเงิน</button><?php endif; ?>
            <div class="small" style="padding-bottom:8px; line-height:1.7;">
              <div>ตั้งวงเงินให้สาขาแล้ว <strong><?= htmlspecialchars(bpm_money($env['dept_planned']), ENT_QUOTES) ?></strong>
                · ยังแบ่งให้สาขาได้อีก
                <?php if ($env['dept_remaining'] === null): ?><span class="text-muted">— (ตั้งวงเงินทั้งก้อนก่อนจึงจะคำนวณให้)</span>
                <?php else: ?><strong><?= $remainingCell($env['dept_remaining']) ?></strong><?php endif; ?></div>
              <div class="text-muted">แบ่งเป็นรายการงบของสาขาแล้ว <?= htmlspecialchars(bpm_money($env['allocated']), ENT_QUOTES) ?></div>
            </div>
          </form>
        <?php endif; ?>

        <?php if (!$isUnspecified): ?>
          <h3 style="font-size:14px; margin:6px 0;">วงเงินที่แต่ละสาขา/หลักสูตรได้รับ
            <?php if ($env['total'] !== null && $env['dept_planned'] > $env['total']): ?><span class="pill pill-danger" style="font-weight:400;">ตั้งให้สาขารวมเกินวงเงินทั้งก้อน</span><?php endif; ?>
          </h3>
          <table class="data-table" style="margin-bottom:18px;">
            <thead>
              <tr>
                <th>สาขา/หลักสูตร</th>
                <th class="num" style="width:200px;">วงเงินที่ได้รับ (บาท)</th>
                <th class="num">แบ่งเป็นรายการงบแล้ว</th>
                <th class="num">ยังแบ่งเป็นรายการได้อีก</th>
                <th style="width:90px;"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($env['departments'] as $dr): $dfid = 'dept-' . $sid . '-' . (int) $dr['department_id']; ?>
                <tr>
                  <td><?= htmlspecialchars($dr['name'], ENT_QUOTES) ?></td>
                  <td>
                    <form id="<?= $dfid ?>" method="post" action="<?= $formAction ?>">
                      <?= bpm_csrf_field() ?>
                      <input type="hidden" name="fiscal_year_id" value="<?= $fiscalYearId ?>">
                      <input type="hidden" name="fund_source_id" value="<?= $sid ?>">
                      <input type="hidden" name="department_id" value="<?= (int) $dr['department_id'] ?>">
                    </form>
                    <input type="text" inputmode="decimal" name="amount" form="<?= $dfid ?>" class="field num" value="<?= $num($dr['budget']) ?>" placeholder="ไม่ได้รับ" <?= $fyClosed ? 'disabled' : '' ?>>
                  </td>
                  <td class="num"><?= htmlspecialchars(bpm_money($dr['allocated']), ENT_QUOTES) ?></td>
                  <td class="num"><?= $remainingCell($dr['remaining']) ?></td>
                  <td><?php if (!$fyClosed): ?><button type="submit" form="<?= $dfid ?>" class="btn btn-secondary" style="padding:6px 10px;">บันทึก</button><?php endif; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <p class="text-muted small">"แบ่งเป็นรายการงบแล้ว" = ผลรวม "งบต้นปี" ของรายการงบที่เปิดใช้งานอยู่ในแหล่งเงิน/สาขานั้น (ไม่รวมการโยกย้ายงบภายหลัง)</p>
  <?php endif; ?>

<?php require __DIR__ . '/../../src/partials/layout_end.php'; ?>
