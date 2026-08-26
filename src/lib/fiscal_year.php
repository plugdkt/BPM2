<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * ปีงบประมาณราชการไทย: 1 ต.ค. (ปี ค.ศ. = year_be - 543 - 1) – 30 ก.ย. (ปี ค.ศ. = year_be - 543)
 * ดู spec.md ข้อ 6
 */

const BPM_BE_OFFSET = 543;

function bpm_ad_year_to_be(int $adYear): int
{
    return $adYear + BPM_BE_OFFSET;
}

function bpm_be_year_to_ad(int $beYear): int
{
    return $beYear - BPM_BE_OFFSET;
}

/** คำนวณ start_date/end_date (Y-m-d) ของปีงบ พ.ศ. ที่กำหนด */
function bpm_fiscal_year_range(int $yearBe): array
{
    $adEnd = bpm_be_year_to_ad($yearBe);
    return [
        'start_date' => sprintf('%04d-10-01', $adEnd - 1),
        'end_date'   => sprintf('%04d-09-30', $adEnd),
    ];
}

/**
 * ไตรมาสของปีงบ จากวันที่ (DateTimeInterface หรือ string 'Y-m-d')
 * Q1 ต.ค.-ธ.ค. / Q2 ม.ค.-มี.ค. / Q3 เม.ย.-มิ.ย. / Q4 ก.ค.-ก.ย.
 */
function bpm_fiscal_quarter($date): int
{
    $d = $date instanceof DateTimeInterface ? $date : new DateTimeImmutable((string) $date);
    $month = (int) $d->format('n');

    return match (true) {
        $month >= 10 => 1,
        $month <= 3  => 2,
        $month <= 6  => 3,
        default      => 4, // 7-9
    };
}

/** ปีงบ (พ.ศ.) ที่วันที่นี้สังกัดอยู่ — ต.ค.-ธ.ค. นับเป็นปีงบถัดไปจากปี ค.ศ. ปัจจุบัน */
function bpm_fiscal_year_of_date($date): int
{
    $d = $date instanceof DateTimeInterface ? $date : new DateTimeImmutable((string) $date);
    $adYear = (int) $d->format('Y');
    $month  = (int) $d->format('n');

    return bpm_ad_year_to_be($month >= 10 ? $adYear + 1 : $adYear);
}

/**
 * ปีงบที่กำลังใช้แสดงผลอยู่ (จาก ?fy=<id> ใน query string หรือ default เป็นปีงบ OPEN ล่าสุด)
 * ใช้ร่วมกันทุกหน้าที่มี filter ปีงบ (dashboard/transactions/transfers/reports)
 */
function bpm_resolve_fiscal_year(): ?array
{
    $db = bpm_db();
    $requestedId = isset($_GET['fy']) ? (int) $_GET['fy'] : null;

    if ($requestedId) {
        $stmt = $db->prepare('SELECT * FROM fiscal_years WHERE id = ?');
        $stmt->execute([$requestedId]);
        $fy = $stmt->fetch();
        if ($fy) {
            return $fy;
        }
    }

    // default: ปีงบสถานะ OPEN ล่าสุด (year_be มากสุด) ถ้าไม่มีเลยค่อย fallback ไปปีล่าสุดที่มี
    $fy = $db->query("SELECT * FROM fiscal_years WHERE status = 'OPEN' ORDER BY year_be DESC LIMIT 1")->fetch();
    if ($fy) {
        return $fy;
    }

    return $db->query('SELECT * FROM fiscal_years ORDER BY year_be DESC LIMIT 1')->fetch() ?: null;
}

/** รายการปีงบทั้งหมด เรียงใหม่ไปเก่า — ใช้ทำ dropdown เลือกปีงบ */
function bpm_all_fiscal_years(): array
{
    return bpm_db()->query('SELECT * FROM fiscal_years ORDER BY year_be DESC')->fetchAll();
}

const BPM_THAI_MONTHS_SHORT = [
    1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.', 5 => 'พ.ค.', 6 => 'มิ.ย.',
    7 => 'ก.ค.', 8 => 'ส.ค.', 9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
];

/** วันที่แบบไทยสั้นๆ "18 ส.ค. 2569" (ปี พ.ศ. เสมอ ตามมาตรฐาน UI) */
function bpm_thai_date($date): string
{
    $d = $date instanceof DateTimeInterface ? $date : new DateTimeImmutable((string) $date);
    $day = (int) $d->format('j');
    $month = BPM_THAI_MONTHS_SHORT[(int) $d->format('n')];
    $yearBe = bpm_ad_year_to_be((int) $d->format('Y'));

    return "{$day} {$month} {$yearBe}";
}

const BPM_QUARTER_LABELS = [
    1 => ['label' => 'ไตรมาส 1', 'months' => 'ต.ค.–ธ.ค.'],
    2 => ['label' => 'ไตรมาส 2', 'months' => 'ม.ค.–มี.ค.'],
    3 => ['label' => 'ไตรมาส 3', 'months' => 'เม.ย.–มิ.ย.'],
    4 => ['label' => 'ไตรมาส 4', 'months' => 'ก.ค.–ก.ย.'],
];
