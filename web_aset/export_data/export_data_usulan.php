<?php
session_start();
if (!isset($_SESSION["nipp"]) || !isset($_SESSION["name"])) {
    http_response_code(403);
    die('Akses ditolak.');
}

$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "asetreg3_db";
$con = new mysqli($servername, $username, $password, $dbname);
$con->set_charset('utf8mb4');

// ── Autoload PhpSpreadsheet ────────────────────────────────────────────────
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',    
    __DIR__ . '/../../vendor/autoload.php', 
    __DIR__ . '/vendor/autoload.php',       
];
$loaded = false;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) { require_once $path; $loaded = true; break; }
}
if (!$loaded) {
    die('PhpSpreadsheet belum terinstall. Jalankan: composer require phpoffice/phpspreadsheet');
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

// ── Helper ─────────────────────────────────────────────────────────────────
function normalize_str_exp($s) {
    return strtolower(trim((string)$s));
}

// ── Identitas user ─────────────────────────────────────────────────────────
$userNipp  = $_SESSION['nipp'];
$userType  = $_SESSION['Type_User'] ?? '';
$userPC    = $_SESSION['Cabang'] ?? '';

$isSubReg  = strpos($userType, 'Sub Regional') !== false;
$isCabang  = strpos($userType, 'Cabang')        !== false;
$isApprovalRegional = (strpos($userType, 'Approval Regional') !== false
                        && strpos($userType, 'User Entry') === false);

// ── Filter tahun ───────────────────────────────────────────────────────────
$tahun = isset($_GET['tahun']) ? intval($_GET['tahun'])
       : (isset($_SESSION['last_tahun_daftar_usulan']) ? intval($_SESSION['last_tahun_daftar_usulan'])
       : date('Y'));

// ── Cari subreg user ───────────────────────────────────────────────────────
$userSubreg = '';
if ($isSubReg && !empty($userPC)) {
    $st = $con->prepare("SELECT subreg FROM import_dat WHERE profit_center=? AND subreg IS NOT NULL LIMIT 1");
    $st->bind_param("s", $userPC);
    $st->execute();
    $r = $st->get_result();
    if ($r && $r->num_rows > 0) $userSubreg = $r->fetch_assoc()['subreg'];
    $st->close();
}

// ── Query data ─────────────────────────────────────────────────────────────
$where = "WHERE up.status NOT IN ('draft','lengkapi_dokumen','dokumen_lengkap')
          AND up.tahun_usulan = ?";
$types = "i";
$params = [$tahun];

if ($isSubReg)   { $where .= " AND up.subreg = ?";         $types .= "s"; $params[] = $userSubreg; }
elseif ($isCabang) { $where .= " AND up.profit_center = ?"; $types .= "s"; $params[] = $userPC; }
elseif ($isApprovalRegional) {
    $where .= " AND (up.status_approval_subreg != 'rejected' OR up.status_approval_subreg IS NULL)";
}

$sql = "SELECT up.id, up.nomor_asset_utama, up.subreg, up.profit_center,
               up.profit_center_text, up.nama_aset, up.kategori_aset,
               up.umur_ekonomis, up.sisa_umur_ekonomis, up.tgl_perolehan,
               up.nilai_buku, up.nilai_perolehan, up.jumlah_aset,
               up.mekanisme_penghapusan, up.fisik_aset,
               up.justifikasi_alasan, up.kajian_hukum, up.kajian_ekonomis, up.kajian_risiko,
               up.foto_path,
               up.tahun_usulan, up.status,
               up.status_approval_subreg, up.status_approval_regional,
               up.created_at
        FROM usulan_penghapusan up
        $where
        ORDER BY up.status DESC, up.created_at DESC";

$stmt = $con->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
while ($r = $result->fetch_assoc()) $rows[] = $r;
$stmt->close();

// ── Buat Spreadsheet ───────────────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Usulan Penghapusan');

// Header baris 1 – judul
$sheet->mergeCells('A1:X1');
$sheet->setCellValue('A1', 'DAFTAR USULAN PENGHAPUSAN ASET – TAHUN ' . $tahun);
$sheet->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0B3A8C']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(1)->setRowHeight(30);

// Header baris 2 – kolom
$headers = [
    'A' => 'No',
    'B' => 'Nomor Aset',
    'C' => 'SubReg',
    'D' => 'Profit Center',
    'E' => 'Profit Center Text',
    'F' => 'Nama Aset',
    'G' => 'Kategori Aset',
    'H' => 'Umur Ekonomis',
    'I' => 'Sisa Umur Ekonomis',
    'J' => 'Tgl Perolehan',
    'K' => 'Nilai Buku (Rp)',
    'L' => 'Nilai Perolehan (Rp)',
    'M' => 'Jumlah Aset',
    'N' => 'Mekanisme Penghapusan',
    'O' => 'Fisik Aset',
    'P' => 'Justifikasi / Alasan',
    'Q' => 'Kajian Hukum',
    'R' => 'Kajian Ekonomis',
    'S' => 'Kajian Risiko',
    'T' => 'Foto Aset',
    'U' => 'Tahun Usulan',
    'V' => 'Status',
    'W' => 'Status Approval SubReg',
    'X' => 'Status Approval Regional',
];

foreach ($headers as $col => $label) {
    $sheet->setCellValue($col . '2', $label);
}
$sheet->getStyle('A2:X2')->applyFromArray([
    'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1D4ED8']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFBFDBFE']]],
]);
$sheet->getRowDimension(2)->setRowHeight(30);

// Lebar kolom
$colWidths = [
    'A'=>5,'B'=>20,'C'=>18,'D'=>14,'E'=>25,'F'=>28,'G'=>20,'H'=>14,'I'=>16,
    'J'=>14,'K'=>18,'L'=>18,'M'=>10,'N'=>22,'O'=>12,'P'=>40,'Q'=>35,'R'=>35,
    'S'=>35,'T'=>18,'U'=>10,'V'=>14,'W'=>20,'X'=>20,
];
foreach ($colWidths as $col => $w) {
    $sheet->getColumnDimension($col)->setWidth($w);
}

// ── Isi data ───────────────────────────────────────────────────────────────
$rowNum = 3;
$tmpDir = sys_get_temp_dir();

foreach ($rows as $i => $d) {
    $no = $i + 1;
    $rowH = 80; // tinggi baris default (untuk foto)

    $sheet->setCellValue('A' . $rowNum, $no);
    $sheet->setCellValue('B' . $rowNum, $d['nomor_asset_utama'] ?? '');
    $sheet->setCellValue('C' . $rowNum, $d['subreg'] ?? '');
    $sheet->setCellValue('D' . $rowNum, $d['profit_center'] ?? '');
    $sheet->setCellValue('E' . $rowNum, $d['profit_center_text'] ?? '');
    $sheet->setCellValue('F' . $rowNum, str_replace('AUC-', '', $d['nama_aset'] ?? ''));
    $sheet->setCellValue('G' . $rowNum, $d['kategori_aset'] ?? '');
    $sheet->setCellValue('H' . $rowNum, $d['umur_ekonomis'] ?? '');
    $sheet->setCellValue('I' . $rowNum, $d['sisa_umur_ekonomis'] ?? '');
    $sheet->setCellValue('J' . $rowNum, $d['tgl_perolehan'] ?? '');
    $sheet->setCellValue('K' . $rowNum, (float)($d['nilai_buku'] ?? 0));
    $sheet->setCellValue('L' . $rowNum, (float)($d['nilai_perolehan'] ?? 0));
    $sheet->setCellValue('M' . $rowNum, (int)($d['jumlah_aset'] ?? 0));
    $sheet->setCellValue('N' . $rowNum, $d['mekanisme_penghapusan'] ?? '');
    $sheet->setCellValue('O' . $rowNum, $d['fisik_aset'] ?? '');
    $sheet->setCellValue('P' . $rowNum, $d['justifikasi_alasan'] ?? '');
    $sheet->setCellValue('Q' . $rowNum, $d['kajian_hukum'] ?? '');
    $sheet->setCellValue('R' . $rowNum, $d['kajian_ekonomis'] ?? '');
    $sheet->setCellValue('S' . $rowNum, $d['kajian_risiko'] ?? '');
    // Kolom T (foto) – diisi gambar di bawah
    $sheet->setCellValue('U' . $rowNum, $d['tahun_usulan'] ?? '');
    $sheet->setCellValue('V' . $rowNum, $d['status'] ?? '');
    $sheet->setCellValue('W' . $rowNum, $d['status_approval_subreg'] ?? '');
    $sheet->setCellValue('X' . $rowNum, $d['status_approval_regional'] ?? '');

    // Format angka
    $sheet->getStyle('K' . $rowNum)->getNumberFormat()->setFormatCode('#,##0');
    $sheet->getStyle('L' . $rowNum)->getNumberFormat()->setFormatCode('#,##0');

    // ── Embed foto ───────────────────────────────────────────────────────
    $fotoRaw = $d['foto_path'] ?? '';
    $imgFile = null;

    if (!empty($fotoRaw)) {
        // Deteksi jika blob binary (longblob)
        if (strpos($fotoRaw, "\x00") !== false) {
            $ext = (substr($fotoRaw, 0, 3) === "\xff\xd8\xff") ? 'jpg' : 'png';
            $imgFile = $tmpDir . '/foto_exp_' . $i . '.' . $ext;
            file_put_contents($imgFile, $fotoRaw);
        }
        // Data URI base64
        elseif (preg_match('#^data:image/(\w+);base64,#i', $fotoRaw, $m)) {
            $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
            $imgData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $fotoRaw));
            if ($imgData) {
                $imgFile = $tmpDir . '/foto_exp_' . $i . '.' . $ext;
                file_put_contents($imgFile, $imgData);
            }
        }
        // Path file di server
        elseif (!preg_match('#^https?://#', $fotoRaw)) {
            $try = [
                $fotoRaw,
                $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($fotoRaw, '/'),
                __DIR__ . '/' . $fotoRaw,
            ];
            foreach ($try as $p) {
                if (file_exists($p)) { $imgFile = $p; break; }
            }
        }
    }

    if ($imgFile && file_exists($imgFile)) {
        try {
            $drawing = new Drawing();
            $drawing->setPath($imgFile);
            $drawing->setCoordinates('T' . $rowNum);
            $drawing->setOffsetX(2);
            $drawing->setOffsetY(2);
            $drawing->setWidth(100);
            $drawing->setHeight(72);
            $drawing->setWorksheet($sheet);
            $rowH = max($rowH, 60);
        } catch (Exception $e) {
            $sheet->setCellValue('T' . $rowNum, '(gagal load foto)');
        }
    } else {
        $sheet->setCellValue('T' . $rowNum, '—');
    }

    // Styling baris data
    $sheet->getStyle('A' . $rowNum . ':X' . $rowNum)->applyFromArray([
        'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE5E7EB']]],
        'fill'      => ($no % 2 === 0)
            ? ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF8FAFF']]
            : [],
    ]);
    $sheet->getStyle('A' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('T' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->getRowDimension($rowNum)->setRowHeight($rowH);
    $rowNum++;
}


// ── Freeze panes & filter ──────────────────────────────────────────────────
$sheet->freezePane('A3');
$sheet->setAutoFilter('A2:X2');

// ── Output ─────────────────────────────────────────────────────────────────
$filename = 'Usulan_Penghapusan_' . $tahun . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit();