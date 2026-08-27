<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Export เป็น Excel — ดู spec.md ข้อ 4/7
 * @param string[] $headers ชื่อคอลัมน์แถวแรก
 * @param array[] $rows แต่ละแถวเป็น array เรียงตามลำดับ $headers
 */
function bpm_send_excel(array $headers, array $rows, string $filename): void
{
    // sanitize เผื่ออนาคตมี caller อื่นที่ path ไม่ได้ผูกกับ year_be (int 2500-2700) เหมือน public/reports.php วันนี้
    $filename = preg_replace('/[^A-Za-z0-9_-]/', '', $filename) ?: 'export';

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    $sheet->fromArray($headers, null, 'A1');
    $sheet->fromArray($rows, null, 'A2');

    foreach (range(1, count($headers)) as $i) {
        $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
    }
    $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Cache-Control: max-age=0');

    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

/**
 * Export เป็น PDF — ต้องฝัง font ไทยเอง เพราะ font default ของ Dompdf ไม่รองรับภาษาไทย (ดู spec.md ข้อ 4)
 * $bodyHtml คือแค่เนื้อหาภายใน <body> (มี <table> เป็นหลัก) ฟังก์ชันนี้ประกอบ <html>/<style> ให้เอง
 */
function bpm_send_pdf(string $bodyHtml, string $filename): void
{
    $filename = preg_replace('/[^A-Za-z0-9_-]/', '', $filename) ?: 'export';

    $options = new Options();
    // isRemoteEnabled=true + chroot จำกัดไว้แค่ src/fonts — จำเป็นสำหรับให้ registerFont() โหลดไฟล์ font ท้องถิ่นได้
    // (ค้นพบตอน dev: chroot default ของ Dompdf บล็อกการอ่านไฟล์นอก path ที่อนุญาต แม้จะเป็นไฟล์ในเครื่องเราเองก็ตาม
    // ไม่เปิด isRemoteEnabled ทั่วไปแบบไม่จำกัด เพราะ HTML ที่ render อาจมาจากข้อมูลผู้ใช้บางส่วน — จำกัด chroot ให้แคบที่สุดเท่าที่ทำได้แทน)
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'NotoSansThai');
    // fontDir/fontCache คือที่ที่ Dompdf เขียน cache runtime ของมันเอง (.ufm/.ttf สำเนา + installed-fonts.json)
    // ต้องแยกจากโฟลเดอร์ที่เก็บไฟล์ font ต้นทาง (../fonts) ไม่งั้น cache ที่ generate จะปนกับไฟล์ source ที่ commit เข้า git
    $cacheDir = sys_get_temp_dir() . '/bpm-dompdf-cache';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0775, true);
    }
    $options->set('fontDir', $cacheDir);
    $options->set('fontCache', $cacheDir);
    $options->setChroot([realpath(__DIR__ . '/../fonts')]); // เฉพาะโฟลเดอร์ไฟล์ font ต้นทาง — ดูเหตุผลใน spec.md ข้อ 4

    $dompdf = new Dompdf($options);
    $dompdf->getFontMetrics()->registerFont(
        ['family' => 'NotoSansThai', 'style' => 'normal', 'weight' => 'normal'],
        __DIR__ . '/../fonts/NotoSansThai-Regular.ttf'
    );
    // ใช้ไฟล์เดียวกันแทนตัวหนา/เอียง (ไม่มีน้ำหนักแยกให้ดาวน์โหลด) — พอสำหรับ v1 ที่เน้นให้อ่านภาษาไทยได้ถูกต้องก่อน
    $dompdf->getFontMetrics()->registerFont(['family' => 'NotoSansThai', 'style' => 'normal', 'weight' => 'bold'], __DIR__ . '/../fonts/NotoSansThai-Regular.ttf');

    $html = '<!doctype html><html><head><meta charset="utf-8"><style>
        body { font-family: NotoSansThai, sans-serif; font-size: 11px; color: #0F172A; }
        h1 { font-size: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #E2E8F0; padding: 5px 8px; text-align: left; }
        th { background: #F1F5F9; }
        td.num, th.num { text-align: right; }
    </style></head><body>' . $bodyHtml . '</body></html>';

    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $dompdf->stream($filename . '.pdf', ['Attachment' => true]);
    exit;
}
