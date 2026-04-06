<?php
/**
 * export_dokumen_usulan.php
 * Export Daftar Dokumen ke Excel (.xlsx) + semua file PDF dikemas dalam ZIP
 *
 * Mode:
 *   ?mode=excel  → download Excel daftar dokumen
 *   ?mode=zip    → download ZIP semua file dokumen PDF/file terlampir
 *   (default)    → excel
 *
 * Lokasi  : web_aset/export_data/export_dokumen_usulan.php
 * Dipanggil dari: daftar_usulan_penghapusan/daftar_usulan_penghapusan.php
 *
 * Butuh: composer require phpoffice/phpspreadsheet
 * (jalankan di root project / folder web_aset)
 */

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
    __DIR__ . '/../vendor/autoload.php',    // web_aset/vendor/  ← paling umum
    __DIR__ . '/../../vendor/autoload.php', // satu level lebih atas
    __DIR__ . '/vendor/autoload.php',       // di folder export_data sendiri
];
$loaded = false;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) { require_once $path; $loaded = true; break; }
}

// ── Identitas user ─────────────────────────────────────────────────────────
$userNipp = $_SESSION['nipp'];
$userType = $_SESSION['Type_User'] ?? '';
$userPC   = $_SESSION['Cabang'] ?? '';

$isSubReg  = strpos($userType, 'Sub Regional') !== false;
$isCabang  = strpos($userType, 'Cabang')        !== false;
$isApprovalRegional = (strpos($userType, 'Approval Regional') !== false
                        && strpos($userType, 'User Entry') === false);

// ── Subreg user ────────────────────────────────────────────────────────────
$userSubreg = '';
if ($isSubReg && !empty($userPC)) {
    $st = $con->prepare("SELECT subreg FROM import_dat WHERE profit_center=? AND subreg IS NOT NULL LIMIT 1");
    $st->bind_param("s", $userPC);
    $st->execute();
    $r = $st->get_result();
    if ($r && $r->num_rows > 0) $userSubreg = $r->fetch_assoc()['subreg'];
    $st->close();
}

// ── Filter ─────────────────────────────────────────────────────────────────
$tahun = isset($_GET['tahun']) ? intval($_GET['tahun'])
       : (isset($_SESSION['last_tahun_daftar_usulan']) ? intval($_SESSION['last_tahun_daftar_usulan'])
       : date('Y'));
$mode  = isset($_GET['mode']) ? $_GET['mode'] : 'excel';

// ── Query dokumen ──────────────────────────────────────────────────────────
$whereUp = "WHERE up.status NOT IN ('draft','lengkapi_dokumen','dokumen_lengkap')
            AND up.tahun_usulan = ?";
$types = "i";
$params = [$tahun];

if ($isSubReg)   { $whereUp .= " AND up.subreg = ?";         $types .= "s"; $params[] = $userSubreg; }
elseif ($isCabang) { $whereUp .= " AND up.profit_center = ?"; $types .= "s"; $params[] = $userPC; }
elseif ($isApprovalRegional) {
    $whereUp .= " AND (up.status_approval_subreg != 'rejected' OR up.status_approval_subreg IS NULL)";
}

$sql = "SELECT dp.id_dokumen, dp.usulan_id, dp.tipe_dokumen,
               dp.file_name, dp.file_path, dp.no_aset,
               up.tahun_usulan, up.nomor_asset_utama, up.nama_aset,
               up.subreg, up.profit_center_text
        FROM dokumen_penghapusan dp
        JOIN usulan_penghapusan up ON dp.usulan_id = up.id
        $whereUp
        ORDER BY dp.id_dokumen ASC";

$stmt = $con->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$docs = [];
while ($r = $result->fetch_assoc()) $docs[] = $r;
$stmt->close();

// ════════════════════════════════════════════════════════════
//  MODE ZIP – kirim semua file dokumen dalam satu ZIP
// ════════════════════════════════════════════════════════════
if ($mode === 'zip') {
    if (!class_exists('ZipArchive')) {
        die('ZipArchive tidak tersedia di server ini.');
    }

    $tmpZip  = sys_get_temp_dir() . '/dok_' . $tahun . '_' . time() . '.zip';
    $zip     = new ZipArchive();

    if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        die('Gagal membuat file ZIP.');
    }

    $tmpDir   = sys_get_temp_dir();
    $tmpFiles = [];

    foreach ($docs as $d) {
        $filePathDb = $d['file_path'] ?? '';

        // ── Ekstensi file asli ──────────────────────────────────────────
        $origName = !empty($d['file_name']) ? basename($d['file_name']) : '';
        $ext = '';
        if ($origName && strrpos($origName, '.') !== false) {
            $ext = strtolower(substr($origName, strrpos($origName, '.')));
        }
        if (!$ext) $ext = '.pdf';

        // ── Buat nama file dari nomor aset ──────────────────────────────
        // no_aset bisa berisi beberapa nomor dipisah ';'
        $noAsetRaw  = trim($d['no_aset'] ?? $d['nomor_asset_utama'] ?? '');
        $noAsetList = array_values(array_filter(array_map('trim', explode(';', $noAsetRaw))));
        $jumlahAset = count($noAsetList);

        if ($jumlahAset === 0) {
            // Tidak ada nomor aset sama sekali
            $namaFile = 'dokumen_' . $d['id_dokumen'];
        } elseif ($jumlahAset <= 3) {
            // 1–3 aset: gabung dengan koma, aman untuk nama file
            $namaFile = implode(', ', $noAsetList);
        } else {
            // 4+ aset: GABUNGAN_Naset_idDok (hindari nama terlalu panjang)
            $namaFile = 'GABUNGAN_' . $jumlahAset . 'aset_dok' . $d['id_dokumen'];
        }

        // Sanitasi karakter tidak aman di nama file
        $namaFile = preg_replace('/[\/\\\\:*?"<>|]/', '_', $namaFile);
        $safeName = $namaFile . $ext;

        // ── Subfolder = nomor aset utama usulan (bukan no_aset dokumen) ─
        $folderAset = preg_replace('/[^\w\-]/', '_', $d['nomor_asset_utama'] ?? 'usulan_' . $d['usulan_id']);
        $zipPath    = $folderAset . '/' . $safeName;

        $fileAdded  = false;

        // 1. Data URI gzip
        if (strpos($filePathDb, 'data:') === 0 && strpos($filePathDb, ';gzip,') !== false) {
            $b64 = substr($filePathDb, strrpos($filePathDb, ',') + 1);
            $data = @gzdecode(base64_decode($b64));
            if ($data) {
                $zip->addFromString($zipPath, $data);
                $fileAdded = true;
            }
        }
        // 2. Data URI base64
        elseif (strpos($filePathDb, 'data:') === 0 && strpos($filePathDb, ';base64,') !== false) {
            $data = base64_decode(substr($filePathDb, strpos($filePathDb, ',') + 1));
            if ($data) {
                $zip->addFromString($zipPath, $data);
                $fileAdded = true;
            }
        }
        // 3. Path file di server
        elseif (!empty($filePathDb)) {
            $try = [
                $filePathDb,
                $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($filePathDb, '/'),
                __DIR__ . '/../../uploads/dokumen_penghapusan/' . basename($origName),
            ];
            foreach ($try as $p) {
                if (file_exists($p)) { $zip->addFile($p, $zipPath); $fileAdded = true; break; }
            }
        }

        if (!$fileAdded) {
            // Tambahkan placeholder .txt agar user tahu file tidak ditemukan
            $zip->addFromString($folderAset . '/_TIDAK_DITEMUKAN_' . $safeName . '.txt',
                "File tidak ditemukan di server.\nNomor Aset: " . ($d['nomor_asset_utama'] ?? '') .
                "\nTipe: " . ($d['tipe_dokumen'] ?? '') . "\nNama file DB: $origName");
        }
    }

    $zip->close();

    // Hapus tmp files
    foreach ($tmpFiles as $f) { if (file_exists($f)) @unlink($f); }

    $zipFilename = 'Dokumen_Penghapusan_' . $tahun . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
    header('Content-Length: ' . filesize($tmpZip));
    header('Cache-Control: max-age=0');
    readfile($tmpZip);
    @unlink($tmpZip);
    exit();
}

// ════════════════════════════════════════════════════════════
//  MODE EXCEL – export daftar dokumen ke .xlsx
// ════════════════════════════════════════════════════════════
if (!$loaded) {
    die('PhpSpreadsheet belum terinstall. Jalankan: composer require phpoffice/phpspreadsheet');
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Daftar Dokumen');

// Judul
$sheet->mergeCells('A1:H1');
$sheet->setCellValue('A1', 'DAFTAR DOKUMEN USULAN PENGHAPUSAN ASET – TAHUN ' . $tahun);
$sheet->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFB91C1C']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(1)->setRowHeight(30);

// Header kolom
$headers = ['A'=>'No','B'=>'ID Dokumen','C'=>'Nomor Aset','D'=>'Nama Aset',
            'E'=>'Tipe Dokumen','F'=>'Nama File','G'=>'SubReg','H'=>'Profit Center','I'=>'Tahun'];
foreach ($headers as $col => $label) $sheet->setCellValue($col . '2', $label);

$sheet->getStyle('A2:I2')->applyFromArray([
    'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEF4444']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFFECACA']]],
]);

$colWidths = ['A'=>5,'B'=>12,'C'=>22,'D'=>30,'E'=>25,'F'=>35,'G'=>18,'H'=>25,'I'=>8];
foreach ($colWidths as $col => $w) $sheet->getColumnDimension($col)->setWidth($w);

$rowNum = 3;
foreach ($docs as $i => $d) {
    $noAset = $d['no_aset'] ?: $d['nomor_asset_utama'];
    // Jika multi-aset, gabung dengan newline
    $noAset = str_replace(';', "\n", $noAset);

    $sheet->setCellValue('A' . $rowNum, $i + 1);
    $sheet->setCellValue('B' . $rowNum, $d['id_dokumen']);
    $sheet->setCellValue('C' . $rowNum, $noAset);
    $sheet->setCellValue('D' . $rowNum, str_replace('AUC-', '', $d['nama_aset'] ?? ''));
    $sheet->setCellValue('E' . $rowNum, $d['tipe_dokumen'] ?? '');
    $sheet->setCellValue('F' . $rowNum, $d['file_name'] ?? '');
    $sheet->setCellValue('G' . $rowNum, $d['subreg'] ?? '');
    $sheet->setCellValue('H' . $rowNum, $d['profit_center_text'] ?? '');
    $sheet->setCellValue('I' . $rowNum, $d['tahun_usulan'] ?? '');

    $sheet->getStyle('A' . $rowNum . ':I' . $rowNum)->applyFromArray([
        'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFFDE8E8']]],
        'fill'      => (($i % 2) === 0)
            ? ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF5F5']]
            : [],
    ]);
    $sheet->getStyle('A' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $rowNum++;
}

// Summary
$sheet->mergeCells('A' . $rowNum . ':I' . $rowNum);
$sheet->setCellValue('A' . $rowNum,
    'Total: ' . count($docs) . ' dokumen  |  Diekspor oleh: ' . $_SESSION['name'] . ' (' . $userNipp . ')  |  ' . date('d/m/Y H:i'));
$sheet->getStyle('A' . $rowNum)->applyFromArray([
    'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF6B7280']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF5F5']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
]);

$sheet->freezePane('A3');
$sheet->setAutoFilter('A2:I2');

$filename = 'Daftar_Dokumen_' . $tahun . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit();