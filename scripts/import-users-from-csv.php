<?php

declare(strict_types=1);

/**
 * เพิ่มผู้ใช้ล่วงหน้า (pre-provision) จากไฟล์ CSV export ของ UP Account — เทียบเท่ากดปุ่ม "เพิ่มผู้ใช้" ที่หน้า "จัดการผู้ใช้" ทีละคน
 * คอลัมน์ที่ใช้: "ชื่อ-นามสกุล", "ชื่อผู้ใช้งาน (UP Account)", "ฝ่ายงาน/สังกัด", "ตำแหน่งงาน", "อีเมล" (ลำดับ ไม่ใช้)
 *
 * ไฟล์ CSV มีข้อมูลบุคคลจริง (PII) ห้าม commit — .gitignore กันไว้แล้ว (*.csv) ส่งให้ทีม server แยกต่างหาก แล้วระบุ path ตอนรัน
 *
 * กติกา (ตกลงกับเจ้าของระบบ 7 ต.ค. 2569):
 *   - เพิ่มทุกคนที่ยังไม่มีบัญชีในระบบ (match ด้วย username — คนที่มีอยู่แล้ว "ข้ามเสมอ" ไม่แตะสิทธิ์/ข้อมูลเดิม)
 *   - ให้สิทธิ์เฉพาะ: คณบดี/รองคณบดี/ผู้ช่วยคณบดี → EXECUTIVE_VIEWER (ดูอย่างเดียว ทุกสาขา)
 *                      ประธานหลักสูตร           → DEPT_HEAD ของสาขาตามคอลัมน์สังกัด
 *     คนอื่นทั้งหมด (อาจารย์ นักวิทยาศาสตร์ บุคลากรสำนักงานธุรการ) ไม่กำหนดสิทธิ์ — ADMIN ตั้งเองทีหลังที่หน้า "จัดการผู้ใช้"
 *   - username ซ้ำหลายแถว (หลายสังกัด/ตำแหน่ง): รวมเป็น 1 บัญชี เลือกแถวที่ให้สิทธิ์ได้ก่อน → สังกัดที่ไม่ใช่สำนักงานธุรการ
 *     → ตำแหน่งที่เจาะจงกว่า "อาจารย์" → แถวแรก (ชื่อ/ตำแหน่ง/สังกัดที่เก็บไว้จะถูกทับด้วยข้อมูลจริงจาก SSO ตอน login ครั้งแรกอยู่แล้ว)
 *   - สาขา (department_id) กำหนดเฉพาะ DEPT_HEAD เหมือนที่หน้าจัดการผู้ใช้ทำ; อื่นๆ เป็น NULL (สังกัดจริงเก็บใน div_name)
 *   - บันทึก audit_logs (USER_PRE_PROVISION) ทุกบัญชีที่เพิ่ม
 *
 *   php scripts/import-users-from-csv.php <path-to-csv> --dry-run   # ดูผลก่อน ไม่เขียนอะไร
 *   php scripts/import-users-from-csv.php <path-to-csv>             # เขียนจริง
 *
 * ปลอดภัยรันซ้ำได้ (คนที่เพิ่มแล้วจะถูกข้าม) — production ต้อง backup ก่อน
 */

require_once __DIR__ . '/../src/lib/config.php';
require_once __DIR__ . '/../src/lib/db.php';

$args   = array_values(array_filter(array_slice($argv ?? [], 1), static fn ($a) => !str_starts_with($a, '--')));
$dryRun = in_array('--dry-run', $argv ?? [], true);
$path   = $args[0] ?? null;
if ($path === null || !is_file($path)) {
    fwrite(STDERR, "ใช้: php scripts/import-users-from-csv.php <path-to-csv> [--dry-run]\n");
    exit(1);
}

// ชื่อสังกัดในไฟล์ => รหัสสาขา (departments.code)
$unitToDept = [
    'กายวิภาคศาสตร์'           => 'ANATOMY',
    'จุลชีววิทยาและปรสิตวิทยา' => 'MICRO',
    'ชีวเคมี'                  => 'BIOCHEM',
    'สรีรวิทยา'                => 'PHYSIO',
    'โภชนาการ'                 => 'NUTRITION',
    'สำนักงานธุรการ'           => 'OFFICE',
];

function position_role(string $pos): ?string
{
    if ($pos === 'ประธานหลักสูตร') {
        return 'DEPT_HEAD';
    }
    if (str_contains($pos, 'คณบดี')) { // คณบดี, รองคณบดี..., ผู้ช่วยคณบดี
        return 'EXECUTIVE_VIEWER';
    }
    return null;
}

$fh = fopen($path, 'rb');
$header = fgetcsv($fh, 0, ',', '"', '');
if ($header === false) {
    fwrite(STDERR, "อ่านหัวตารางไม่ได้\n");
    exit(1);
}
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]); // ตัด BOM
$col = array_flip(array_map('trim', $header));
foreach (['ชื่อ-นามสกุล', 'ชื่อผู้ใช้งาน (UP Account)', 'ฝ่ายงาน/สังกัด', 'ตำแหน่งงาน', 'อีเมล'] as $need) {
    if (!isset($col[$need])) {
        fwrite(STDERR, "ไม่พบคอลัมน์ \"{$need}\" ในไฟล์\n");
        exit(1);
    }
}

$byUser = [];
while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
    if (count($r) < count($col) || trim((string) $r[$col['ชื่อผู้ใช้งาน (UP Account)']]) === '') {
        continue;
    }
    $username = trim((string) $r[$col['ชื่อผู้ใช้งาน (UP Account)']]);
    $byUser[$username][] = [
        'name'  => trim(preg_replace('/\s+/u', ' ', (string) $r[$col['ชื่อ-นามสกุล']])),
        'unit'  => trim((string) $r[$col['ฝ่ายงาน/สังกัด']]),
        'pos'   => trim((string) $r[$col['ตำแหน่งงาน']]),
        'email' => trim((string) $r[$col['อีเมล']]),
    ];
}
fclose($fh);

$db = bpm_db();
$deptIds = $db->query('SELECT code, id FROM departments')->fetchAll(PDO::FETCH_KEY_PAIR);
$actor   = $db->query("SELECT id FROM users WHERE role = 'ADMIN' ORDER BY id LIMIT 1")->fetchColumn();
$exists  = $db->prepare('SELECT COUNT(*) FROM users WHERE sso_username = ?');
$insert  = $db->prepare('INSERT INTO users (sso_username, name, email, pos_name, div_name, role, department_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');

echo ($dryRun ? '[DRY RUN] ' : '') . 'นำเข้าผู้ใช้จาก ' . basename($path) . ': ' . count($byUser) . " บัญชี (username ไม่ซ้ำ)\n\n";

$plan = [];
$problems = [];
foreach ($byUser as $username => $rows) {
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
        $problems[] = "username ไม่ถูกต้อง: {$username}";
        continue;
    }
    foreach ($rows as $row) {
        if (!isset($unitToDept[$row['unit']])) {
            $problems[] = "ไม่รู้จักสังกัด \"{$row['unit']}\" ของ {$username}";
        }
    }

    // เลือกแถวหลัก: ให้สิทธิ์ได้ → สังกัดที่ไม่ใช่สำนักงานธุรการ → ตำแหน่งเจาะจงกว่า "อาจารย์" → แถวแรก
    usort($rows, static function (array $a, array $b): int {
        $score = static fn (array $x): array => [
            position_role($x['pos']) !== null ? 0 : 1,
            $x['unit'] !== 'สำนักงานธุรการ' ? 0 : 1,
            $x['pos'] !== 'อาจารย์' ? 0 : 1,
        ];
        return $score($a) <=> $score($b);
    });
    $p = $rows[0];
    $role = position_role($p['pos']);
    $deptCode = $unitToDept[$p['unit']] ?? null;
    $deptId = ($role === 'DEPT_HEAD' && $deptCode !== null) ? ($deptIds[$deptCode] ?? null) : null;
    if ($role === 'DEPT_HEAD' && $deptId === null) {
        $problems[] = "ไม่พบสาขา {$deptCode} ในระบบ (ประธานหลักสูตร {$username})";
    }
    $plan[$username] = $p + ['role' => $role, 'dept_id' => $deptId, 'dept_code' => $deptCode, 'rows' => count($rows)];
}

if (!empty($problems)) {
    fwrite(STDERR, "พบปัญหา ไม่เขียนอะไร:\n  - " . implode("\n  - ", $problems) . "\n");
    exit(1);
}

$added = 0;
$skipped = 0;
$byRole = [];
if (!$dryRun) {
    $db->beginTransaction();
}
try {
    foreach ($plan as $username => $p) {
        $exists->execute([$username]);
        if ((int) $exists->fetchColumn() > 0) {
            $skipped++;
            echo "  = ข้าม (มีบัญชีอยู่แล้ว) {$username}\n";
            continue;
        }
        $email = ($p['email'] !== '' && $p['email'] !== '-') ? $p['email'] : $username . '@pending.local';
        $label = $p['role'] ?? 'ไม่กำหนดสิทธิ์';
        $byRole[$label] = ($byRole[$label] ?? 0) + 1;
        if ($p['role'] !== null) {
            echo sprintf("  + %-18s %-34s %-18s %s%s%s\n", $username, $p['name'], $p['pos'], $p['role'], $p['dept_code'] && $p['role'] === 'DEPT_HEAD' ? " ({$p['dept_code']})" : '', $p['rows'] > 1 ? "  [รวม {$p['rows']} แถว]" : '');
        } elseif ($p['rows'] > 1) {
            echo sprintf("  + %-18s %-34s ไม่กำหนดสิทธิ์  [รวม %d แถว → ใช้สังกัด %s / %s]\n", $username, $p['name'], $p['rows'], $p['unit'], $p['pos']);
        }
        if (!$dryRun) {
            $insert->execute([$username, $p['name'], $email, $p['pos'] ?: null, $p['unit'] ?: null, $p['role'], $p['dept_id']]);
            if ($actor !== false) {
                bpm_audit_log((int) $actor, 'USER_PRE_PROVISION', 'users', (int) $db->lastInsertId(), null, [
                    'sso_username' => $username, 'role' => $p['role'], 'department_id' => $p['dept_id'], 'source' => 'import-users-from-csv',
                ]);
            }
        }
        $added++;
    }
    if (!$dryRun) {
        $db->commit();
    }
} catch (Throwable $e) {
    if (!$dryRun && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'ผิดพลาด: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "\nสรุปตามสิทธิ์ (เฉพาะที่เพิ่ม):\n";
foreach ($byRole as $label => $n) {
    echo "  {$label}: {$n}\n";
}
echo "\nเสร็จสิ้น — เพิ่ม {$added} บัญชี ข้าม {$skipped} บัญชี" . ($dryRun ? ' (dry run — ไม่ได้เขียนข้อมูล)' : '') . "\n";
echo "(คนที่ไม่ได้ตั้งสิทธิ์ login แล้วจะขึ้น \"รอกำหนดสิทธิ์\" จนกว่า ADMIN ตั้งที่หน้า \"จัดการผู้ใช้\")\n";
