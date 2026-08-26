<?php

declare(strict_types=1);

require_once __DIR__ . '/icons.php';

/**
 * เปิด layout กลาง (sidebar + header) — ต้องตั้งตัวแปรเหล่านี้ก่อน require ไฟล์นี้เสมอ:
 *   $pageTitle            string  หัวข้อหน้า (แสดงใน <title> และ topbar)
 *   $activeNav            string  หนึ่งใน 'dashboard'|'transactions'|'transfers'|'reports'
 *   $user                 array   จาก bpm_require_role()
 * ตัวแปร optional:
 *   $fiscalYear            ?array  ปีงบที่กำลังดูอยู่ (จาก bpm_resolve_fiscal_year()) — ไม่ตั้งจะไม่โชว์ filter
 *   $selectedDepartmentId  ?int    สาขาที่กำลังดูอยู่ (null = ทั้งหมด) — ไม่โชว์ dropdown ถ้า role เป็น DEPT_STAFF
 */

$pendingBadge = bpm_pending_transfer_count($user['role'] === 'DEPT_STAFF' ? (int) $user['department_id'] : null);
$fiscalYear ??= null;
$selectedDepartmentId ??= null;

$navItems = [
    ['key' => 'dashboard',    'label' => 'ภาพรวม',        'icon' => 'dashboard', 'href' => '/index.php'],
    ['key' => 'transactions', 'label' => 'บันทึกเบิกจ่าย', 'icon' => 'receipt',   'href' => '/transactions.php'],
    ['key' => 'transfers',    'label' => 'ขอโยกย้ายงบ',    'icon' => 'swap',      'href' => '/transfers.php', 'badge' => $pendingBadge],
    ['key' => 'reports',      'label' => 'รายงานสรุป',     'icon' => 'report',    'href' => '/reports.php'],
];
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?> — BPM</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="app-shell">

  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="mark"><?= bpm_icon('dashboard', 18) ?></div>
      <div>
        <div class="name">BPM</div>
        <div class="sub">งบประมาณสาขาวิชา</div>
      </div>
    </div>

    <nav>
      <?php foreach ($navItems as $item): ?>
        <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES) ?>" class="<?= $activeNav === $item['key'] ? 'active' : '' ?>">
          <?= bpm_icon($item['icon'], 18) ?>
          <?= htmlspecialchars($item['label'], ENT_QUOTES) ?>
          <?php if (!empty($item['badge'])): ?>
            <span class="badge-count"><?= (int) $item['badge'] ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>

      <?php if ($user['role'] === 'ADMIN'): ?>
        <div class="sidebar-divider"></div>
        <a href="/admin/allocations.php" class="<?= $activeNav === 'admin' ? 'active' : '' ?>">
          <?= bpm_icon('gear', 18) ?>
          ตั้งค่างบ
        </a>
      <?php endif; ?>
    </nav>
  </aside>

  <div class="main-col">
    <header class="topbar">
      <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></h1>
      <div class="topbar-right">

        <?php if ($fiscalYear || $selectedDepartmentId !== null || $user['role'] !== 'DEPT_STAFF'): ?>
          <form method="get" style="display:flex; gap:10px; align-items:center;">
            <?php foreach ($_GET as $k => $v): if (in_array($k, ['fy', 'dept'], true)) continue; ?>
              <input type="hidden" name="<?= htmlspecialchars($k, ENT_QUOTES) ?>" value="<?= htmlspecialchars((string) $v, ENT_QUOTES) ?>">
            <?php endforeach; ?>

            <?php if ($fiscalYear): ?>
              <select name="fy" class="filter-chip" onchange="this.form.submit()" aria-label="ปีงบประมาณ">
                <?php foreach (bpm_all_fiscal_years() as $fy): ?>
                  <option value="<?= (int) $fy['id'] ?>" <?= (int) $fy['id'] === (int) $fiscalYear['id'] ? 'selected' : '' ?>>
                    ปีงบประมาณ พ.ศ. <?= (int) $fy['year_be'] ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>

            <?php if ($user['role'] !== 'DEPT_STAFF'): ?>
              <select name="dept" class="filter-chip" onchange="this.form.submit()" aria-label="สาขา">
                <option value="" <?= $selectedDepartmentId === null ? 'selected' : '' ?>>สาขา: ทั้งหมด</option>
                <?php foreach (bpm_all_departments() as $dept): ?>
                  <option value="<?= (int) $dept['id'] ?>" <?= $selectedDepartmentId === (int) $dept['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($dept['name'], ENT_QUOTES) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
          </form>
          <div class="topbar-divider"></div>
        <?php endif; ?>

        <div class="user-chip">
          <div class="user-avatar"><?= htmlspecialchars(bpm_initials($user['name']), ENT_QUOTES) ?></div>
          <div>
            <div class="name"><?= htmlspecialchars($user['name'], ENT_QUOTES) ?></div>
            <div class="role"><?= htmlspecialchars(bpm_role_label($user['role']), ENT_QUOTES) ?></div>
          </div>
        </div>
      </div>
    </header>

    <main class="content">
      <?php $flash = bpm_flash_get(); if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type'], ENT_QUOTES) ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES) ?></div>
      <?php endif; ?>