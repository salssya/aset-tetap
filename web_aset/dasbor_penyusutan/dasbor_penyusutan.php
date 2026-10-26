<?php
ini_set('max_execution_time', '300');
set_time_limit(300);
ini_set('memory_limit', '1024M');

$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "asetreg3_db";

$con = mysqli_connect($servername, $username, $password, $dbname);

session_start();
if (!isset($_SESSION["nipp"]) || !isset($_SESSION["name"])) {
    header("Location: ../login/login_view.php");
    exit();
}
$userNipp = $_SESSION['nipp'];

$isAjaxAnomFragment = isset($_POST['action']) && $_POST['action'] === 'get_perbandingan_html';
$isAjaxAnomFull = isset($_POST['action']) && $_POST['action'] === 'get_perbandingan_data_full';
if ($isAjaxAnomFragment || $isAjaxAnomFull) {
    $_GET = array_merge($_GET, $_POST);
}

// ── Tabel Catatan (isian bebas dari user) untuk tabel Perbandingan Amount Antar Bulan ──
mysqli_query($con, "CREATE TABLE IF NOT EXISTS catatan_penyusutan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    row_key VARCHAR(64) NOT NULL,
    tahun INT NOT NULL,
    bulan_a INT NOT NULL,
    bulan_b INT NOT NULL,
    cost_center VARCHAR(50),
    asset VARCHAR(50),
    asset_subnumber VARCHAR(50),
    account VARCHAR(50),
    profit_center VARCHAR(50),
    catatan TEXT,
    updated_by VARCHAR(50),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_row (row_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Handler AJAX: simpan catatan (dipanggil via fetch POST dari JS, balasan JSON) ──
if (isset($_POST['action']) && $_POST['action'] === 'save_catatan') {
    header('Content-Type: application/json');
    $tahunC   = (int)($_POST['tahun'] ?? 0);
    $bulanAC  = (int)($_POST['bulan_a'] ?? 0);
    $bulanBC  = (int)($_POST['bulan_b'] ?? 0);
    $ccC      = trim((string)($_POST['cost_center'] ?? ''));
    $assetC   = trim((string)($_POST['asset'] ?? ''));
    $subC     = trim((string)($_POST['asset_subnumber'] ?? ''));
    $accC     = trim((string)($_POST['account'] ?? ''));
    $pcC      = trim((string)($_POST['profit_center'] ?? ''));
    $catatanC = trim((string)($_POST['catatan'] ?? ''));
    $rowKey   = md5($tahunC . '|' . $bulanAC . '|' . $bulanBC . '|' . $ccC . '|' . $assetC . '|' . $subC . '|' . $accC . '|' . $pcC);

    $stmt = mysqli_prepare($con, "INSERT INTO catatan_penyusutan
        (row_key, tahun, bulan_a, bulan_b, cost_center, asset, asset_subnumber, account, profit_center, catatan, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE catatan = VALUES(catatan), updated_by = VALUES(updated_by)");
    if ($stmt) {
        mysqli_stmt_bind_param(
            $stmt, 'siiisssssss',
            $rowKey, $tahunC, $bulanAC, $bulanBC, $ccC, $assetC, $subC, $accC, $pcC, $catatanC, $userNipp
        );
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo json_encode(['ok' => (bool)$ok]);
    } else {
        echo json_encode(['ok' => false, 'error' => mysqli_error($con)]);
    }
    exit();
}

// ── Tabel Override Kategori (verifikasi manual) untuk tabel Perbandingan Amount Antar Bulan.
// Kategori awalnya dideteksi otomatis (deteksi_kategori_ps), tapi kalau polanya keliru, verifikator
// bisa mengoreksi lewat dropdown -- dan koreksi ini juga otomatis dipakai di kartu GL Account
// (dasbor utama) karena keduanya baca dari tabel yang sama, dikunci per tahun+bulan_a+bulan_b+aset. ──
mysqli_query($con, "CREATE TABLE IF NOT EXISTS override_kategori_penyusutan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    row_key VARCHAR(64) NOT NULL,
    tahun INT NOT NULL,
    bulan_a INT NOT NULL,
    bulan_b INT NOT NULL,
    cost_center VARCHAR(50),
    asset VARCHAR(50),
    asset_subnumber VARCHAR(50),
    account VARCHAR(50),
    profit_center VARCHAR(50),
    kategori_manual VARCHAR(50) NOT NULL,
    kategori_asli VARCHAR(50),
    updated_by VARCHAR(50),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_row (row_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Daftar kategori yang valid dipilih manual (dipakai di handler ini & untuk isi dropdown di frontend)
function daftar_kategori_penyusutan() {
    return ['Pencatatan Aset Baru', 'Reklas Asset Class', 'Relokasi Aset', 'Perubahan Umur Ekonomis',
            'Umur Ekonomis Habis', 'Penghapusan Aset', 'Perlu Dicek Manual'];
}

// ── Handler AJAX: simpan/reset override kategori (dropdown verifikasi di tabel Perbandingan Antar Bulan) ──
if (isset($_POST['action']) && $_POST['action'] === 'save_kategori_override') {
    header('Content-Type: application/json');
    $tahunO        = (int)($_POST['tahun'] ?? 0);
    $bulanAO       = (int)($_POST['bulan_a'] ?? 0);
    $bulanBO       = (int)($_POST['bulan_b'] ?? 0);
    $ccO           = trim((string)($_POST['cost_center'] ?? ''));
    $assetO        = trim((string)($_POST['asset'] ?? ''));
    $subO          = trim((string)($_POST['asset_subnumber'] ?? ''));
    $accO          = trim((string)($_POST['account'] ?? ''));
    $pcO           = trim((string)($_POST['profit_center'] ?? ''));
    $kategoriO     = trim((string)($_POST['kategori_manual'] ?? ''));
    $kategoriAsliO = trim((string)($_POST['kategori_asli'] ?? ''));
    $rowKeyO       = md5($tahunO . '|' . $bulanAO . '|' . $bulanBO . '|' . $ccO . '|' . $assetO . '|' . $subO . '|' . $accO . '|' . $pcO);

    if ($kategoriO === '' || $kategoriO === 'auto') {
        // Pilih "(Otomatis)" di dropdown -> reset, hapus override supaya balik pakai deteksi sistem lagi
        $stmt = mysqli_prepare($con, "DELETE FROM override_kategori_penyusutan WHERE row_key = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $rowKeyO);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            echo json_encode(['ok' => (bool)$ok, 'reset' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => mysqli_error($con)]);
        }
        exit();
    }

    if (!in_array($kategoriO, daftar_kategori_penyusutan(), true)) {
        echo json_encode(['ok' => false, 'error' => 'Kategori tidak valid']);
        exit();
    }

    $stmt = mysqli_prepare($con, "INSERT INTO override_kategori_penyusutan
        (row_key, tahun, bulan_a, bulan_b, cost_center, asset, asset_subnumber, account, profit_center, kategori_manual, kategori_asli, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE kategori_manual = VALUES(kategori_manual), updated_by = VALUES(updated_by)");
    if ($stmt) {
        mysqli_stmt_bind_param(
            $stmt, 'siiissssssss',
            $rowKeyO, $tahunO, $bulanAO, $bulanBO, $ccO, $assetO, $subO, $accO, $pcO, $kategoriO, $kategoriAsliO, $userNipp
        );
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo json_encode(['ok' => (bool)$ok]);
    } else {
        echo json_encode(['ok' => false, 'error' => mysqli_error($con)]);
    }
    exit();
}

// ── Keterangan Asset per GL Account (mapping statis Asset Class Name), dipakai di beberapa tempat ──
function get_pivot_keterangan_map() {
    return [
        '5040101000' => 'Beban Penyusutan Properti Investasi',
        '5040103000' => 'Beban Penyusutan Properti Investasi Jalan dan Bang',
        '5040201010' => 'Beban Penyusutan Bangunan Faspel',
        '5040201020' => 'Beban Penyusutan Kapal',
        '5040201030' => 'Beban Penyusutan Alat-alat Faspel',
        '5040201040' => 'Beban Penyusutan Instalasi Faspel',
        '5040201050' => 'Beban Penyusutan Jalan & Bangunan',
        '5040201060' => 'Beban Penyusutan Peralatan',
        '5040201070' => 'Beban Penyusutan Kendaraan',
        '5040201080' => 'Beban Penyusutan Emplasemen',
        '5040302000' => 'Amortisasi Lisensi',
        '5040304000' => 'Amortisasi Pengembangan Piranti',
        '5040305000' => 'Amortisasi Aset Konsesi',
        '5040401010' => 'Beban Penyusutan AHG Bangunan Fasilitas Pelabuhan',
        '5040401070' => 'Beban Penyusutan AHG Kendaraan',
        '5040601010' => 'Amortisasi Konsesi Tanah',
        '5040601020' => 'Amortisasi Konsesi Bangunan Fasilitas Pel',
        '5040601040' => 'Amortisasi Konsesi Alat-Alat Fasilitas Pel',
        '5040601050' => 'Amortisasi Konsesi Instalasi Fasilitas Pel',
        '5040601060' => 'Amortisasi Konsesi Jalan Dan Bangunan',
        '5040601090' => 'Amortisasi Konsesi Emplasemen',
    ];
}

// ── Handler AJAX: detail breakdown Cabang (Cost Center) & Profit Center per GL Account ──
// (dipanggil saat baris "Rekap per Account" diklik, di card Rekap per Account/GL Account)
if (isset($_POST['action']) && $_POST['action'] === 'get_account_detail') {
    header('Content-Type: application/json');
    $accD   = trim((string)($_POST['account'] ?? ''));
    $tahunD = (int)($_POST['tahun'] ?? 0);
    if ($accD === '' || $tahunD <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Parameter tidak lengkap']);
        exit();
    }
    $accEsc = mysqli_real_escape_string($con, $accD);
    $awalD  = $tahunD . '-01-01';
    $akhirD = ($tahunD + 1) . '-01-01';
    // "(Tanpa Account)" adalah nilai semu yang dipakai di tabel Rekap per Account saat account kosong
    $accWhere = ($accD === '(Tanpa Account)')
        ? "(ip.account = '' OR ip.account IS NULL)"
        : "ip.account = '$accEsc'";

    if (!table_exists($con, 'import_dat_penyusutan')) {
        echo json_encode(['ok' => false, 'error' => 'Tabel import_dat_penyusutan tidak ditemukan']);
        exit();
    }

    $namaBulanD = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];

    $ketMapAcc = get_pivot_keterangan_map();
    $keteranganAcc = $ketMapAcc[$accD] ?? '-';
    $isAhgAcc = (stripos($keteranganAcc, 'AHG') !== false);
    $groupByAssetSql = $isAhgAcc ? '' : 'ip.asset, ip.asset_subnumber, ';
    $resBulanD = mysqli_query($con, "SELECT DISTINCT MONTH(" . date_expr('ip.posting_date') . ") AS bln
                                      FROM import_fagll ip
                                      WHERE " . date_expr('ip.posting_date') . " >= '$awalD' AND " . date_expr('ip.posting_date') . " < '$akhirD'
                                      ORDER BY bln ASC");
    $listBulanD = [];
    if ($resBulanD) { while ($r = mysqli_fetch_assoc($resBulanD)) { $listBulanD[] = (int)$r['bln']; } }

    $selectBulanD = '';
    foreach ($listBulanD as $b) {
        $selectBulanD .= ",\n                        SUM(CASE WHEN MONTH(" . date_expr('ip.posting_date') . ") = $b THEN " . amount_expr('ip.amount_local_currency') . " ELSE 0 END) AS bln_$b";
    }

    $sqlDetAcc = "SELECT
                        MIN(ip.asset) AS nomor_aset,
                        MIN(ip.asset_subnumber) AS sub_number,
                        COALESCE(NULLIF(dat.cabang, ''), NULLIF(datAny.cabang, ''), NULLIF(datPc.cabang, ''), CONCAT('(Belum match DAT — Cost Center: ', COALESCE(NULLIF(ip.cost_center,''),'-'), ')')) AS cabang,
                        COALESCE(NULLIF(dat.profit_center, ''), NULLIF(ip.profit_center,''), '(Tanpa Profit Center)') AS profit_center,
                        COALESCE(NULLIF(dat.keterangan_asset, ''), NULLIF(datAny.keterangan_asset, ''), '-') AS keterangan_asset,
                        SUM(" . amount_expr('ip.amount_local_currency') . ") AS total $selectBulanD
                  FROM import_fagll ip
                  -- JOIN pakai kolom yang sudah di-trim & dinormalisasi saat import (asset/asset_subnumber_num
                  -- di import_fagll, nomor_asset/sub_number_num di import_dat_penyusutan) -- BUKAN
                  -- TRIM()/CAST() runtime lagi, supaya index idx_asset_sub_num & idx_nomor_asset_sub_num kepakai.
                  LEFT JOIN import_dat_penyusutan dat
                         ON dat.nomor_asset = ip.asset
                        AND (dat.sub_number = ip.asset_subnumber OR dat.sub_number_num = ip.asset_subnumber_num)
                        AND CAST(dat.tahun_buku AS UNSIGNED) = YEAR(" . date_expr('ip.posting_date') . ")
                        AND CAST(dat.periode_bulan AS UNSIGNED) = MONTH(" . date_expr('ip.posting_date') . ")
                  -- Fallback AMAN: kalau match ketat (asset+periode persis) di atas gagal (mis. DAT
                  -- bulan itu belum diupload), coba cocokin ke ASET YANG SAMA PERSIS (nomor_asset +
                  -- sub_number) dari periode DAT MANAPUN yang pernah ada -- bukan tebak lewat Profit
                  -- Center (sudah dicoba, terbukti salah karena 1 Profit Center bisa beda Cabang).
                  -- Cabang & Keterangan Aset untuk 1 aset fisik yang sama biasanya stabil antar bulan.
                  LEFT JOIN (
                        SELECT nomor_asset, sub_number, sub_number_num,
                               MAX(NULLIF(cabang, '')) AS cabang,
                               MAX(NULLIF(keterangan_asset, '')) AS keterangan_asset
                        FROM import_dat_penyusutan
                        GROUP BY nomor_asset, sub_number, sub_number_num
                  ) datAny ON datAny.nomor_asset = ip.asset
                           AND (datAny.sub_number = ip.asset_subnumber OR datAny.sub_number_num = ip.asset_subnumber_num)
                  -- Fallback TERAKHIR khusus kalau match per-nomor-aset di atas gagal TOTAL (mis. GL
                  -- AHG yang emang gak nempel ke aset fisik manapun di DAT): tebak Cabang dari Profit
                  -- Center aja (asumsi 1 Profit Center pada dasarnya konsisten dipakai 1 Cabang). ──
                  LEFT JOIN (
                        SELECT profit_center, MAX(NULLIF(cabang, '')) AS cabang
                        FROM import_dat_penyusutan
                        WHERE profit_center IS NOT NULL AND profit_center <> ''
                        GROUP BY profit_center
                  ) datPc ON datPc.profit_center = ip.profit_center
                  WHERE $accWhere
                    AND " . date_expr('ip.posting_date') . " >= '$awalD' AND " . date_expr('ip.posting_date') . " < '$akhirD'
                  GROUP BY $groupByAssetSql cabang, profit_center, keterangan_asset
                  ORDER BY cabang ASC, total DESC";
    $resDetAcc = mysqli_query($con, $sqlDetAcc);
    $rowsAcc = [];
    $grandTotalAcc = 0.0;
    $grandTotalBulanD = array_fill_keys($listBulanD, 0.0);
    if ($resDetAcc) {
        while ($r = mysqli_fetch_assoc($resDetAcc)) {
            $perBulanD = [];
            foreach ($listBulanD as $b) {
                $v = (float)($r['bln_' . $b] ?? 0);
                $perBulanD[$b] = $v;
                $grandTotalBulanD[$b] += $v;
            }
            $rowsAcc[] = [
                'nomor_aset'      => $isAhgAcc ? $accD : $r['nomor_aset'],
                'sub_number'      => $isAhgAcc ? '' : $r['sub_number'],
                'cabang'          => $r['cabang'],
                'profit_center'   => $r['profit_center'],
                'keterangan_asset' => $isAhgAcc ? $keteranganAcc : $r['keterangan_asset'],
                'total'           => (float)$r['total'],
                'per_bulan'       => $perBulanD,
            ];
            $grandTotalAcc += (float)$r['total'];
        }
    } else {
        echo json_encode(['ok' => false, 'error' => mysqli_error($con)]);
        exit();
    }
    $bulanLabelD = [];
    foreach ($listBulanD as $b) { $bulanLabelD[] = ['no' => $b, 'label' => $namaBulanD[$b] ?? $b]; }
    echo json_encode([
        'ok' => true, 'rows' => $rowsAcc, 'grand_total' => $grandTotalAcc, 'keterangan' => $keteranganAcc,
        'bulan' => $bulanLabelD, 'grand_total_bulan' => $grandTotalBulanD,
    ]);
    exit();
}

function fmt_rp($n) {
    return number_format((float)$n, 0, ',', '.');
}

// ── Helper bikin file XLSX asli (multi-sheet) tanpa library eksternal, pakai ZipArchive ──
function idx_ke_kolom_excel($idx) {
    $idx++;
    $kolom = '';
    while ($idx > 0) {
        $sisa = ($idx - 1) % 26;
        $kolom = chr(65 + $sisa) . $kolom;
        $idx = intdiv($idx - 1, 26);
    }
    return $kolom;
}

function xlsx_escape($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

// Style index constants (harus sinkron dengan urutan cellXfs di xl/styles.xml)
define('XLSX_STYLE_DEFAULT', 0);
define('XLSX_STYLE_HEADER',  1);
define('XLSX_STYLE_RUPIAH',  2); 
define('XLSX_STYLE_PERSEN',  3); 

function estimate_cell_width($val, $isCurrency, $isPercent) {
    if ($val === null || $val === '') { return 1; }
    if (($isCurrency || $isPercent) && is_numeric($val)) {
        if ($isPercent) {
            $teks = number_format((float)$val, 2) . '%';
        } else {
            $teks = number_format((float)$val, 0, ',', '.');
        }
    } else {
        $teks = (string)$val;
    }
    return mb_strlen($teks);
}

function build_sheet_xml_ps($header, $rows, $currencyCols = [], $percentCols = []) {
    // ── Hitung lebar kolom otomatis dari isi terpanjang (header + semua baris) ──
    $colWidths = [];
    foreach ($header as $ci => $h) {
        $colWidths[$ci] = max($colWidths[$ci] ?? 0, mb_strlen((string)$h));
    }
    foreach ($rows as $row) {
        foreach ($row as $ci => $val) {
            $isCurrency = in_array($ci, $currencyCols, true);
            $isPercent  = in_array($ci, $percentCols, true);
            $w = estimate_cell_width($val, $isCurrency, $isPercent);
            $colWidths[$ci] = max($colWidths[$ci] ?? 0, $w);
        }
    }

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

    $xml .= '<cols>';
    foreach ($colWidths as $ci => $len) {
        $width = min(60, max(9, $len + 3)); // padding dikit + batas biar gak kepanjangan
        $xml .= '<col min="' . ($ci + 1) . '" max="' . ($ci + 1) . '" width="' . $width . '" customWidth="1"/>';
    }
    $xml .= '</cols>';

    $xml .= '<sheetData>';
    $rIdx = 1;
    $xml .= '<row r="' . $rIdx . '">';
    foreach ($header as $ci => $h) {
        $col = idx_ke_kolom_excel($ci);
        $xml .= '<c r="' . $col . $rIdx . '" t="inlineStr" s="' . XLSX_STYLE_HEADER . '"><is><t xml:space="preserve">' . xlsx_escape($h) . '</t></is></c>';
    }
    $xml .= '</row>';
    $rIdx++;
    foreach ($rows as $row) {
        $xml .= '<row r="' . $rIdx . '">';
        foreach ($row as $ci => $val) {
            $col = idx_ke_kolom_excel($ci);
            $isCurrency = in_array($ci, $currencyCols, true);
            $isPercent  = in_array($ci, $percentCols, true);
            if (($isCurrency || $isPercent) && $val !== null && $val !== '' && is_numeric($val)) {
                $styleIdx = $isPercent ? XLSX_STYLE_PERSEN : XLSX_STYLE_RUPIAH;
                $xml .= '<c r="' . $col . $rIdx . '" s="' . $styleIdx . '"><v>' . (float)$val . '</v></c>';
            } else {
                $teks = ($val === null || $val === '') ? '-' : (string)$val;
                $xml .= '<c r="' . $col . $rIdx . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_escape($teks) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
        $rIdx++;
    }
    $xml .= '</sheetData></worksheet>';
    return $xml;
}

function export_multi_sheet_xlsx($filename, $sheets) {
    $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
    $zip = new ZipArchive();
    $zip->open($tmpFile, ZipArchive::OVERWRITE);

    $overrides = '';
    foreach ($sheets as $i => $s) { $overrides .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'; }

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
        $overrides . '</Types>');

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>');

    $sheetEntries = '';
    $relEntries = '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    foreach ($sheets as $i => $s) {
        $n = $i + 1;
        $sheetEntries .= '<sheet name="' . xlsx_escape(mb_substr($s['name'], 0, 31)) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $relEntries .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
    }

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets>' . $sheetEntries . '</sheets></workbook>');

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relEntries . '</Relationships>');

    $numFmtRupiah = '_-* #,##0_-;-* #,##0_-;_-* &quot;-&quot;_-;_-@_-';
    $numFmtPersen = '0.00\%;-0.00\%;0.00\%';
    $zip->addFromString('xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<numFmts count="2">' .
        '<numFmt numFmtId="164" formatCode="' . $numFmtRupiah . '"/>' .
        '<numFmt numFmtId="165" formatCode="' . $numFmtPersen . '"/>' .
        '</numFmts>' .
        '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><sz val="11"/><name val="Calibri"/><b/></font></fonts>' .
        '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>' .
        '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>' .
        '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0"/></cellStyleXfs>' .
        '<cellXfs count="4">' .
        '<xf numFmtId="0" fontId="0" xfId="0"/>' .                                      
        '<xf numFmtId="0" fontId="1" xfId="0" applyFont="1"/>' .                         
        '<xf numFmtId="164" fontId="0" xfId="0" applyNumberFormat="1"/>' .              
        '<xf numFmtId="165" fontId="0" xfId="0" applyNumberFormat="1"/>' .               
        '</cellXfs>' .
        '</styleSheet>');

    foreach ($sheets as $i => $s) {
        $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', build_sheet_xml_ps(
            $s['header'],
            $s['rows'],
            $s['currency_cols'] ?? ($s['numeric_cols'] ?? []),
            $s['percent_cols'] ?? []
        ));
    }
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmpFile));
    header('Cache-Control: max-age=0');
    readfile($tmpFile);
    unlink($tmpFile);
    exit;
}

function render_anomali_fragment() {
    global $bulanAnomA, $bulanAnomB, $namaBulan, $anomaliRowsPaged, $tahunFilter, $bulanFilter,
           $hanyaBeda, $minSelisih, $cariAnom, $sortByAnom, $sortDirAnom, $totalAnomali,
           $totalHalamanAnom, $halamanAnom;
    ?>
            <?php if ($bulanAnomA === null || $bulanAnomB === null): ?>
              <p class="text-muted mb-0">Belum ada cukup data bulan di tahun ini untuk dibandingkan.</p>
            <?php elseif (empty($anomaliRowsPaged)): ?>
              <p class="text-muted mb-0">Tidak ada aset yang berbeda antara <?php echo htmlspecialchars($namaBulan[$bulanAnomA] ?? $bulanAnomA); ?> dan <?php echo htmlspecialchars($namaBulan[$bulanAnomB] ?? $bulanAnomB); ?>.</p>
              <?php if ($cariAnom !== ''): ?>
              <p class="text-muted small mb-0">(pencarian "<?php echo htmlspecialchars($cariAnom); ?>" tidak ketemu)</p>
              <?php endif; ?>
            <?php else: ?>
              <small class="text-muted d-block mb-2">
                Menampilkan <?php echo count($anomaliRowsPaged); ?> dari <?php echo number_format($totalAnomali, 0, ',', '.'); ?> aset
                <?php if ($cariAnom !== ''): ?> (hasil pencarian "<strong><?php echo htmlspecialchars($cariAnom); ?></strong>")<?php endif; ?>
              </small>
              <?php
                $qBaseAnom = 'tahun=' . urlencode($tahunFilter) . '&bulan=' . urlencode($bulanFilter === 'Semua' ? '' : $bulanFilter)
                            . '&bulan_a=' . urlencode($bulanAnomA) . '&bulan_b=' . urlencode($bulanAnomB)
                            . '&hanya_beda=' . ($hanyaBeda ? '1' : '0')
                            . '&min_selisih=' . urlencode($minSelisih)
                            . '&cari=' . urlencode($cariAnom);
                $qBaseAnomPage = $qBaseAnom . ($sortByAnom !== '' ? '&sort_by=' . urlencode($sortByAnom) . '&sort_dir=' . urlencode($sortDirAnom) : '');
              ?>
              <div class="table-responsive">
              <table class="table table-bordered align-middle mb-2 w-100 tabel-perbandingan" style="table-layout:auto; font-size:.8rem;">
                <thead class="table-light">
                  <tr>
                    <th><?php echo sort_link('cost_center', 'Cost Center', $sortByAnom, $sortDirAnom, $qBaseAnom, '#perbandingan-amount-antar-bulan'); ?></th>
                    <th><?php echo sort_link('asset', 'Nomor Aset', $sortByAnom, $sortDirAnom, $qBaseAnom, '#perbandingan-amount-antar-bulan'); ?></th>
                    <th><?php echo sort_link('asset_subnumber', 'Sub', $sortByAnom, $sortDirAnom, $qBaseAnom, '#perbandingan-amount-antar-bulan'); ?></th>
                    <th><?php echo sort_link('account', 'Account', $sortByAnom, $sortDirAnom, $qBaseAnom, '#perbandingan-amount-antar-bulan'); ?></th>
                    <th>Keterangan Aset</th>
                    <th><?php echo sort_link('profit_center', 'Profit Center', $sortByAnom, $sortDirAnom, $qBaseAnom, '#perbandingan-amount-antar-bulan'); ?></th>
                    <th class="text-end"><?php echo sort_link('nilai_a', 'Nilai ' . ($namaBulan[$bulanAnomA] ?? $bulanAnomA), $sortByAnom, $sortDirAnom, $qBaseAnom, '#perbandingan-amount-antar-bulan'); ?></th>
                    <th class="text-end"><?php echo sort_link('nilai_b', 'Nilai ' . ($namaBulan[$bulanAnomB] ?? $bulanAnomB), $sortByAnom, $sortDirAnom, $qBaseAnom, '#perbandingan-amount-antar-bulan'); ?></th>
                    <th class="text-end"><?php echo sort_link('selisih', 'Selisih', $sortByAnom, $sortDirAnom, $qBaseAnom, '#perbandingan-amount-antar-bulan'); ?></th>
                    <th class="text-end"><?php echo sort_link('persen', '%', $sortByAnom, $sortDirAnom, $qBaseAnom, '#perbandingan-amount-antar-bulan'); ?></th>
                    <th class="text-center"><?php echo sort_link('status', 'Status', $sortByAnom, $sortDirAnom, $qBaseAnom, '#perbandingan-amount-antar-bulan'); ?></th>
                    <th>Catatan</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($anomaliRowsPaged as $ar): ?>
                  <tr>
                    <td><?php echo htmlspecialchars($ar['cost_center']); ?></td>
                    <td><?php echo htmlspecialchars($ar['asset']); ?></td>
                    <td><?php echo htmlspecialchars($ar['asset_subnumber']); ?></td>
                    <td><?php echo htmlspecialchars($ar['account']); ?></td>
                    <td><?php echo htmlspecialchars($ar['keterangan_asset'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($ar['profit_center']); ?></td>
                    <td class="text-end"><?php echo $ar['nilai_a'] !== null ? fmt_rp($ar['nilai_a']) : '-'; ?></td>
                    <td class="text-end"><?php echo $ar['nilai_b'] !== null ? fmt_rp($ar['nilai_b']) : '-'; ?></td>
                    <td class="text-end <?php echo $ar['selisih'] === null ? '' : ($ar['selisih'] > 0 ? 'text-success fw-semibold' : ($ar['selisih'] < 0 ? 'text-danger fw-semibold' : '')); ?>">
                      <?php echo $ar['selisih'] === null ? '-' : (($ar['selisih'] > 0 ? '+' : '') . fmt_rp($ar['selisih'])); ?>
                    </td>
                    <td class="text-end <?php echo $ar['persen'] === null ? '' : ($ar['persen'] > 0 ? 'text-success fw-semibold' : ($ar['persen'] < 0 ? 'text-danger fw-semibold' : '')); ?>">
                      <?php echo $ar['persen'] === null ? '-' : (($ar['persen'] > 0 ? '+' : '') . number_format($ar['persen'], 1, ',', '.') . '%'); ?>
                    </td>
                    <td class="text-center" style="min-width:240px;">
                      <?php
                        $clsA = $ar['status'] === 'Naik' ? 'badge-naik' : ($ar['status'] === 'Turun' ? 'badge-turun' : ($ar['status'] === 'Tetap' ? 'badge-tetap' : 'badge-nodat'));
                        $popTitle = $ar['kategori'] ?: $ar['status'];
                        $popBody  = $ar['ket_detail'] ?: 'Tidak ada perubahan kategori khusus yang terdeteksi dari data DAT untuk perubahan ini (kemungkinan penyusutan berjalan normal).';
                        $labelOtomatisA = $ar['kategori_otomatis'] ?: $ar['status'];
                      ?>
                      <div class="d-flex align-items-center justify-content-center gap-1">
                        <select class="form-select form-select-sm kategori-select <?php echo $clsA; ?> <?php echo $ar['kategori_manual'] ? 'border-warning' : ''; ?>"
                                style="font-size:.72rem;"
                                data-tahun="<?php echo (int)$tahunFilter; ?>"
                                data-bulan-a="<?php echo (int)$bulanAnomA; ?>"
                                data-bulan-b="<?php echo (int)$bulanAnomB; ?>"
                                data-cost-center="<?php echo htmlspecialchars($ar['cost_center']); ?>"
                                data-asset="<?php echo htmlspecialchars($ar['asset']); ?>"
                                data-asset-subnumber="<?php echo htmlspecialchars($ar['asset_subnumber']); ?>"
                                data-account="<?php echo htmlspecialchars($ar['account']); ?>"
                                data-profit-center="<?php echo htmlspecialchars($ar['profit_center']); ?>"
                                data-kategori-asli="<?php echo htmlspecialchars($labelOtomatisA); ?>"
                                data-status-class="<?php echo $clsA; ?>"
                                title="<?php echo htmlspecialchars($labelOtomatisA); ?> — kalau pola deteksi otomatis salah, ganti kategori di sini. Pilih pilihan paling atas lagi untuk membatalkan koreksi.">
                          <option value="auto" <?php echo !$ar['kategori_manual'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($labelOtomatisA); ?></option>
                          <?php foreach (daftar_kategori_penyusutan() as $koOpt): ?>
                            <option value="<?php echo htmlspecialchars($koOpt); ?>" <?php echo ($ar['kategori_manual'] && $ar['kategori'] === $koOpt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($koOpt); ?></option>
                          <?php endforeach; ?>
                        </select>
                        <i class="bi bi-info-circle anom-status-badge text-muted" style="cursor:pointer;"
                           data-kategori="<?php echo htmlspecialchars($popTitle); ?>"
                           data-ket="<?php echo htmlspecialchars($popBody); ?>"></i>
                        <?php if ($ar['kategori_manual']): ?>
                          <i class="bi bi-pencil-fill text-warning" style="font-size:.75em;" title="Sudah dikoreksi manual (otomatis: <?php echo htmlspecialchars($labelOtomatisA); ?>)"></i>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td>
                      <div class="input-group input-group-sm" style="min-width:200px;">
                        <input type="text" class="form-control form-control-sm catatan-input"
                               data-tahun="<?php echo (int)$tahunFilter; ?>"
                               data-bulan-a="<?php echo (int)$bulanAnomA; ?>"
                               data-bulan-b="<?php echo (int)$bulanAnomB; ?>"
                               data-cost-center="<?php echo htmlspecialchars($ar['cost_center']); ?>"
                               data-asset="<?php echo htmlspecialchars($ar['asset']); ?>"
                               data-asset-subnumber="<?php echo htmlspecialchars($ar['asset_subnumber']); ?>"
                               data-account="<?php echo htmlspecialchars($ar['account']); ?>"
                               data-profit-center="<?php echo htmlspecialchars($ar['profit_center']); ?>"
                               value="<?php echo htmlspecialchars($ar['catatan']); ?>"
                               placeholder="Tulis catatan...">
                        <button type="button" class="btn btn-primary catatan-save-btn" disabled title="Simpan catatan">OK</button>
                      </div>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              </div>
              <?php if ($totalHalamanAnom > 1): ?>
              <?php
                // Bangun daftar nomor halaman dengan ellipsis: 1 ... (cur-1) cur (cur+1) ... last
                $sekitar = 1;
                $itemsHalaman = [];
                for ($p = 1; $p <= $totalHalamanAnom; $p++) {
                    if ($p === 1 || $p === $totalHalamanAnom || ($p >= $halamanAnom - $sekitar && $p <= $halamanAnom + $sekitar)) {
                        $itemsHalaman[] = $p;
                    } elseif (end($itemsHalaman) !== '...') {
                        $itemsHalaman[] = '...';
                    }
                }
              ?>
              <nav>
                <ul class="pagination pagination-sm flex-wrap mb-0 mt-2">
                  <li class="page-item <?php echo $halamanAnom <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo $qBaseAnomPage; ?>&halaman_anom=1#perbandingan-amount-antar-bulan" title="Halaman pertama">&laquo;&laquo;</a>
                  </li>
                  <li class="page-item <?php echo $halamanAnom <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo $qBaseAnomPage; ?>&halaman_anom=<?php echo max(1, $halamanAnom - 1); ?>#perbandingan-amount-antar-bulan">&laquo; Sebelumnya</a>
                  </li>
                  <?php foreach ($itemsHalaman as $it): ?>
                    <?php if ($it === '...'): ?>
                      <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                    <?php else: ?>
                      <li class="page-item <?php echo $it === $halamanAnom ? 'active' : ''; ?>">
                        <a class="page-link" href="?<?php echo $qBaseAnomPage; ?>&halaman_anom=<?php echo $it; ?>#perbandingan-amount-antar-bulan"><?php echo $it; ?></a>
                      </li>
                    <?php endif; ?>
                  <?php endforeach; ?>
                  <li class="page-item <?php echo $halamanAnom >= $totalHalamanAnom ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo $qBaseAnomPage; ?>&halaman_anom=<?php echo min($totalHalamanAnom, $halamanAnom + 1); ?>#perbandingan-amount-antar-bulan">Selanjutnya &raquo;</a>
                  </li>
                  <li class="page-item <?php echo $halamanAnom >= $totalHalamanAnom ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo $qBaseAnomPage; ?>&halaman_anom=<?php echo $totalHalamanAnom; ?>#perbandingan-amount-antar-bulan" title="Halaman terakhir">&raquo;&raquo;</a>
                  </li>
                </ul>
              </nav>
              <?php endif; ?>
              <!-- <small class="text-muted d-block mt-2">
                <strong>Tercatat Baru</strong> = aset belum ada di Bulan Pertama. <strong>Hilang/Selesai</strong> = aset ada di Bulan Pertama
                tapi sudah tidak muncul lagi di Bulan Kedua (misal sudah fully depreciated).
              </small> -->
            <?php endif; ?>
    <?php
}

function sort_link($col, $label, $sortBy, $sortDir, $baseQuery, $anchor = '') {
    $nextDir = ($sortBy === $col && $sortDir === 'asc') ? 'desc' : 'asc';
    if ($sortBy === $col) {
        $icon = $sortDir === 'asc' ? ' <i class="bi bi-caret-up-fill"></i>' : ' <i class="bi bi-caret-down-fill"></i>';
    } else {
        $icon = ' <i class="bi bi-arrow-down-up text-muted" style="font-size:.7em;"></i>';
    }
    $href = '?' . $baseQuery . '&sort_by=' . urlencode($col) . '&sort_dir=' . urlencode($nextDir) . $anchor;
    return '<a href="' . htmlspecialchars($href) . '" class="text-decoration-none text-dark">' . htmlspecialchars($label) . '</a>' . $icon;
}

$namaBulan = [1=>'Januari',
              2=>'Februari',
              3=>'Maret',
              4=>'April',
              5=>'Mei',
              6=>'Juni',
              7=>'Juli',
              8=>'Agustus',
              9=>'September',
              10=>'Oktober',
              11=>'November',
              12=>'Desember'];

function table_exists($con, $table) {
    $res = mysqli_query($con, "SHOW TABLES LIKE '" . mysqli_real_escape_string($con, $table) . "'");
    return $res && mysqli_num_rows($res) > 0;
}
$adaTabelPenyusutan   = table_exists($con, 'import_fagll');
$adaTabelDat          = table_exists($con, 'import_dat_penyusutan');
$adaTabelDatRingkas   = table_exists($con, 'import_dat_penyusutan');

function date_expr($col) {
    // Terima 'posting_date' atau 'alias.posting_date' -> jadi 'posting_date_norm' / 'alias.posting_date_norm'
    if (preg_match('/^(\w+\.)?posting_date$/', trim($col), $m)) {
        return ($m[1] ?? '') . 'posting_date_norm';
    }
    // Fallback (kolom lain / belum ternormalisasi): cara lama, tetap aman dipakai.
    return "COALESCE(
        STR_TO_DATE($col,'%m/%d/%Y'),
        STR_TO_DATE($col,'%d.%m.%Y'),
        STR_TO_DATE($col,'%d/%m/%Y'),
        STR_TO_DATE($col,'%Y-%m-%d'),
        CASE
            WHEN $col REGEXP '^[0-9]+$' AND CAST($col AS UNSIGNED) BETWEEN 3653 AND 100000
            THEN DATE_ADD('1899-12-30', INTERVAL CAST($col AS UNSIGNED) DAY)
            ELSE NULL
        END
    )";
}
function amount_expr($col) {
    // Terima 'amount_local_currency' atau 'alias.amount_local_currency' -> jadi kolom amount_norm
    if (preg_match('/^(\w+\.)?amount_local_currency$/', trim($col), $m)) {
        return ($m[1] ?? '') . 'amount_norm';
    }
    // Fallback (kolom lain): cara lama.
    return "CAST(REPLACE(REPLACE($col,'.',''),',','') AS DECIMAL(20,2))";
}

function deteksi_kategori_ps($status, $entA, $entB, $dmA, $dmB, $accR, $tahunFilter, $bulanA, $bulanB, $bulanPertamaAset = null) {
    if ($status === 'Naik' || $status === 'Turun') {
        if ($entA && $entB && $entA['gl'] !== '' && $entB['gl'] !== '' && $entA['gl'] !== $entB['gl']) {
            return 'Reklas Asset Class';
        } elseif ($entA && $entB && $entA['cabang'] !== '' && $entB['cabang'] !== '' && $entA['cabang'] !== $entB['cabang']) {
            return 'Relokasi Aset';
        }

        $tglAcuanBaru = ($entA['tgl'] ?? '') !== '' ? $entA['tgl'] : ($entB['tgl'] ?? '');
        $acquisitionRecent = false;
        if ($tglAcuanBaru !== '' && strtotime($tglAcuanBaru) !== false) {
            $acquisitionRecent = ((int) date('Y', strtotime($tglAcuanBaru))) >= ($tahunFilter - 1);
        }
        if ($acquisitionRecent && $bulanPertamaAset !== null && $bulanPertamaAset === $bulanA) {
            return 'Pencatatan Aset Baru';
        }
        if ($entA && $entB && $entA['sisa'] !== null && $entB['sisa'] !== null) {
            $deltaSisa = $entB['sisa'] - $entA['sisa'];
            $expectedDeltaSisa = $bulanA - $bulanB;
            $sudahHabisMasaManfaat = ($entA['sisa'] <= 0 && $entB['sisa'] <= 0);
            if ($deltaSisa != $expectedDeltaSisa && !$sudahHabisMasaManfaat) {
                return 'Perubahan Umur Ekonomis';
            }
        }
        if ($entA === null && $bulanPertamaAset !== null && $bulanPertamaAset === $bulanA) {
            return 'Pencatatan Aset Baru';
        }
        return null; // selisih kecil/wajar tanpa penyebab spesifik terdeteksi
    } elseif ($status === 'Tercatat Baru') {
        if ($entA && $entA['gl'] !== '' && $entA['gl'] !== $accR['account']) {
            return 'Reklas Asset Class';
        } elseif ($dmA && (($dmA['cost_center'] != $accR['cost_center']) || ($dmA['profit_center'] != $accR['profit_center']))) {
            return 'Relokasi Aset';
        } elseif ($entA && $entA['sisa'] !== null && $entA['sisa'] <= 1 && $entB && $entB['sisa'] !== null && $entB['sisa'] > 1) {
            return 'Perubahan Umur Ekonomis';
        } elseif ($entB) {
            return 'Pencatatan Aset Baru';
        }
        return 'Perlu Dicek Manual';
    } elseif ($status === 'Hilang/Selesai') {
        if ($entB && $entB['gl'] !== '' && $entB['gl'] !== $accR['account']) {
            return 'Reklas Asset Class';
        } elseif ($dmB && (($dmB['cost_center'] != $accR['cost_center']) || ($dmB['profit_center'] != $accR['profit_center']))) {
            return 'Relokasi Aset';
        } elseif ($entA && $entA['sisa'] !== null && $entA['sisa'] <= 1) {
            return 'Umur Ekonomis Habis';
        } elseif ($entB && $entB['sisa'] !== null && $entA && $entA['sisa'] !== null && $entB['sisa'] <= 0 && $entA['sisa'] > 1) {
            return 'Perubahan Umur Ekonomis';
        }
        return 'Penghapusan Aset';
    }
    return null;
}

function hitung_ringkasan_kategori_bulan($bulanA, $bulanB, $datByBulan, $dimByBulan, $nilaiPerBulanAsset, $namaBulan, $tahunFilter, $assetSubBulanPertama = []) {
    if ($bulanA === null || $bulanB === null) return null;
    $keysA = $nilaiPerBulanAsset[$bulanA] ?? [];
    $keysB = $nilaiPerBulanAsset[$bulanB] ?? [];
    $allKeys = array_unique(array_merge(array_keys($keysA), array_keys($keysB)));

    $kategoriNet = [];
    foreach ($allKeys as $fullkey) {
        $adaA = array_key_exists($fullkey, $keysA);
        $adaB = array_key_exists($fullkey, $keysB);
        $nilaiA = $adaA ? $keysA[$fullkey] : 0.0;
        $nilaiB = $adaB ? $keysB[$fullkey] : 0.0;
        $selisih = $nilaiB - $nilaiA;
        if (abs($selisih) < 0.01) continue; 

        if (!$adaA) { $status = 'Tercatat Baru'; }
        elseif (!$adaB) { $status = 'Hilang/Selesai'; }
        elseif ($selisih > 0) { $status = 'Naik'; }
        else { $status = 'Turun'; }

        $parts = explode('|', $fullkey);
        if (count($parts) < 5) continue;
        [$costCenter, $asset, $subnumber, $account, $profitCenter] = $parts;
        $dkey = $asset . '|' . $subnumber;
        $entA = $datByBulan[$bulanA][$dkey] ?? null;
        $entB = $datByBulan[$bulanB][$dkey] ?? null;
        $dmA  = $dimByBulan[$bulanA][$dkey] ?? null;
        $dmB  = $dimByBulan[$bulanB][$dkey] ?? null;
        $accR = ['account' => $account, 'cost_center' => $costCenter, 'profit_center' => $profitCenter];

        $kategori = deteksi_kategori_ps($status, $entA, $entB, $dmA, $dmB, $accR, $tahunFilter, $bulanA, $bulanB, $assetSubBulanPertama[$dkey] ?? null);
        if ($kategori === null) { $kategori = 'Perlu Dicek Manual'; }

        if (!isset($kategoriNet[$kategori])) { $kategoriNet[$kategori] = 0.0; }
        $kategoriNet[$kategori] += $selisih;
    }

    if (empty($kategoriNet)) return null;

    uasort($kategoriNet, fn($a, $b) => abs($b) <=> abs($a));
    $kategoriUtama = array_key_first($kategoriNet);
    $totalAbs = array_sum(array_map('abs', $kategoriNet));
    $persenUtama = $totalAbs != 0 ? (abs($kategoriNet[$kategoriUtama]) / $totalAbs) * 100 : 0;

    return [
        'kategori_utama'   => $kategoriUtama,
        'kontribusi_utama' => $kategoriNet[$kategoriUtama],
        'persen_utama'     => $persenUtama,
        'breakdown'        => $kategoriNet,
    ];
}

function hitung_gl_card($con, $account, $tahunFilter, $bulanA, $bulanB, $namaBulan) {
    $result = [
        'account'     => $account,
        'keterangan'  => get_pivot_keterangan_map()[$account] ?? '-',
        'bulan_awal'  => ['no' => $bulanA, 'label' => $namaBulan[$bulanA] ?? $bulanA],
        'bulan_akhir' => ['no' => $bulanB, 'label' => $namaBulan[$bulanB] ?? $bulanB],
        'nilai_awal' => 0.0, 'nilai_akhir' => 0.0, 'selisih_total' => 0.0,
        'penambahan'  => ['sattlement_aset_baru' => 0.0, 'reklas' => 0.0, 'lainnya' => 0.0, 'total' => 0.0, 'items' => []],
        'pengurangan' => ['ue_habis' => 0.0, 'reklas' => 0.0, 'penghapusan_aset' => 0.0, 'lainnya' => 0.0, 'total' => 0.0, 'items' => []],
    ];
    if ($account === '' || $tahunFilter <= 0 || $bulanA === null || $bulanB === null || !table_exists($con, 'import_fagll')) {
        return $result;
    }

    $tahunAwalG      = $tahunFilter . '-01-01';
    $tahunAkhirExklG = ((int)$tahunFilter + 1) . '-01-01';
    $accEscG   = mysqli_real_escape_string($con, $account);
    $accWhereG = ($account === '(Tanpa Account)')
        ? "(ip.account = '' OR ip.account IS NULL)"
        : "ip.account = '$accEscG'";
    $rangeWhereG = date_expr('ip.posting_date') . " >= '$tahunAwalG' AND " . date_expr('ip.posting_date') . " < '$tahunAkhirExklG'";

    // ── Total GL per periode (nilai awal & akhir kartu) ──
    $sqlTotalG = "SELECT
            SUM(CASE WHEN MONTH(" . date_expr('ip.posting_date') . ") = $bulanA THEN " . amount_expr('ip.amount_local_currency') . " ELSE 0 END) AS nilai_a,
            SUM(CASE WHEN MONTH(" . date_expr('ip.posting_date') . ") = $bulanB THEN " . amount_expr('ip.amount_local_currency') . " ELSE 0 END) AS nilai_b
        FROM import_fagll ip
        WHERE $accWhereG AND $rangeWhereG AND MONTH(" . date_expr('ip.posting_date') . ") IN ($bulanA, $bulanB)";
    $resTotalG = mysqli_query($con, $sqlTotalG);
    if ($resTotalG) {
        $rt = mysqli_fetch_assoc($resTotalG);
        $result['nilai_awal']    = (float)($rt['nilai_a'] ?? 0);
        $result['nilai_akhir']   = (float)($rt['nilai_b'] ?? 0);
        $result['selisih_total'] = $result['nilai_akhir'] - $result['nilai_awal'];
    }

    // ── Lookup DAT (per aset per periode) untuk kategorisasi, sama pola dengan card "Perbandingan Amount Antar Bulan" ──
    $datAg = []; $datBg = [];
    if (table_exists($con, 'import_dat_penyusutan')) {
        $sqlDatG = "SELECT periode_bulan, nomor_asset, sub_number, sisa_manfaat_aset, gl_account_exp, tgl_perolehan, keterangan_asset, cabang
                    FROM import_dat_penyusutan
                    WHERE CAST(tahun_buku AS UNSIGNED) = " . (int)$tahunFilter . "
                      AND CAST(periode_bulan AS UNSIGNED) IN ($bulanA, $bulanB)";
        $resDatG = mysqli_query($con, $sqlDatG);
        if ($resDatG) {
            while ($d = mysqli_fetch_assoc($resDatG)) {
                $dkeyG = trim((string)$d['nomor_asset']) . '|' . trim((string)$d['sub_number']);
                $entryG = [
                    'sisa'   => is_numeric($d['sisa_manfaat_aset']) ? (float)$d['sisa_manfaat_aset'] : null,
                    'gl'     => trim((string)$d['gl_account_exp']),
                    'tgl'    => trim((string)$d['tgl_perolehan']),
                    'ket'    => trim((string)$d['keterangan_asset']),
                    'cabang' => trim((string)$d['cabang']),
                ];
                if ((int)$d['periode_bulan'] === $bulanA) { $datAg[$dkeyG] = $entryG; }
                if ((int)$d['periode_bulan'] === $bulanB) { $datBg[$dkeyG] = $entryG; }
            }
        }
    }
    // Fallback Keterangan Aset dari periode manapun (aset tidak berubah nama antar bulan)
    $datAnyKetG = [];
    if (table_exists($con, 'import_dat_penyusutan')) {
        $resAnyKetG = mysqli_query($con, "SELECT nomor_asset, sub_number, MAX(NULLIF(keterangan_asset, '')) AS keterangan_asset
                                           FROM import_dat_penyusutan GROUP BY nomor_asset, sub_number");
        if ($resAnyKetG) {
            while ($d = mysqli_fetch_assoc($resAnyKetG)) {
                if (($d['keterangan_asset'] ?? '') !== '') {
                    $datAnyKetG[trim((string)$d['nomor_asset']) . '|' . trim((string)$d['sub_number'])] = $d['keterangan_asset'];
                }
            }
        }
    }

    $overrideMapG = [];
    if (table_exists($con, 'override_kategori_penyusutan')) {
        $resOvrG = mysqli_query($con, "SELECT row_key, kategori_manual FROM override_kategori_penyusutan
                                        WHERE tahun = " . (int)$tahunFilter . " AND bulan_a = $bulanA AND bulan_b = $bulanB");
        if ($resOvrG) { while ($o = mysqli_fetch_assoc($resOvrG)) { $overrideMapG[$o['row_key']] = $o['kategori_manual']; } }
    }

    // ── Dimensi cost_center/profit_center per periode (untuk deteksi Relokasi Aset) ──
    $dimAg = []; $dimBg = [];
    $sqlDimG = "SELECT MONTH(" . date_expr('posting_date') . ") AS bln, asset, asset_subnumber, cost_center, profit_center
                FROM import_fagll ip
                WHERE $accWhereG AND $rangeWhereG AND MONTH(" . date_expr('ip.posting_date') . ") IN ($bulanA, $bulanB)";
    $resDimG = mysqli_query($con, $sqlDimG);
    if ($resDimG) {
        while ($d = mysqli_fetch_assoc($resDimG)) {
            $dkeyG  = trim((string)$d['asset']) . '|' . trim((string)$d['asset_subnumber']);
            $entryG = ['cost_center' => $d['cost_center'], 'profit_center' => $d['profit_center']];
            if ((int)$d['bln'] === $bulanA && !isset($dimAg[$dkeyG])) { $dimAg[$dkeyG] = $entryG; }
            if ((int)$d['bln'] === $bulanB && !isset($dimBg[$dkeyG])) { $dimBg[$dkeyG] = $entryG; }
        }
    }

    // ── Nilai per aset (GL Account ini saja) di 2 periode ──
    $sqlAssetG = "SELECT cost_center, asset, asset_subnumber, profit_center,
                        SUM(CASE WHEN MONTH(" . date_expr('posting_date') . ") = $bulanA THEN " . amount_expr('amount_local_currency') . " ELSE 0 END) AS nilai_a,
                        SUM(CASE WHEN MONTH(" . date_expr('posting_date') . ") = $bulanB THEN " . amount_expr('amount_local_currency') . " ELSE 0 END) AS nilai_b,
                        MAX(CASE WHEN MONTH(" . date_expr('posting_date') . ") = $bulanA THEN 1 ELSE 0 END) AS ada_a,
                        MAX(CASE WHEN MONTH(" . date_expr('posting_date') . ") = $bulanB THEN 1 ELSE 0 END) AS ada_b
                  FROM import_fagll ip
                  WHERE $accWhereG AND $rangeWhereG AND MONTH(" . date_expr('ip.posting_date') . ") IN ($bulanA, $bulanB)
                  GROUP BY cost_center, asset, asset_subnumber, profit_center";
    $resAssetG = mysqli_query($con, $sqlAssetG);
    if ($resAssetG) {
        while ($r = mysqli_fetch_assoc($resAssetG)) {
            $nilaiAg = (float)$r['nilai_a'];
            $nilaiBg = (float)$r['nilai_b'];
            $adaAg = (int)$r['ada_a'] === 1;
            $adaBg = (int)$r['ada_b'] === 1;
            $selisihG = $nilaiBg - $nilaiAg;
            if (abs($selisihG) < 0.01) { continue; } 
            if (!$adaAg) { $statusG = 'Tercatat Baru'; }
            elseif (!$adaBg) { $statusG = 'Hilang/Selesai'; }
            elseif ($selisihG > 0) { $statusG = 'Naik'; }
            else { $statusG = 'Turun'; }

            // Baris auto PSAK 73 / Penyusutan KSP (tanpa nomor aset asli) tidak ikut diperhitungkan
            $isPsakKspG = (trim((string)$r['asset']) === '-' && trim((string)$r['asset_subnumber']) === '-');
            if ($isPsakKspG) { continue; }

            $dkeyG = trim((string)$r['asset']) . '|' . trim((string)$r['asset_subnumber']);
            $entAg = $datAg[$dkeyG] ?? null; $entBg = $datBg[$dkeyG] ?? null;
            $dmAg  = $dimAg[$dkeyG] ?? null; $dmBg  = $dimBg[$dkeyG] ?? null;
            $accRg = ['account' => $account, 'cost_center' => $r['cost_center'], 'profit_center' => $r['profit_center']];

            $kategoriG = deteksi_kategori_ps($statusG, $entAg, $entBg, $dmAg, $dmBg, $accRg, (int)$tahunFilter, $bulanA, $bulanB, null);
            if ($kategoriG === null) { $kategoriG = 'Perlu Dicek Manual'; }

            // Kalau baris ini sudah pernah dikoreksi manual lewat dropdown di tab "Perbandingan Antar Bulan", pakai itu
            $rowKeyG    = md5($tahunFilter . '|' . $bulanA . '|' . $bulanB . '|' . $r['cost_center'] . '|' . $r['asset'] . '|' . $r['asset_subnumber'] . '|' . $account . '|' . $r['profit_center']);
            $isManualG  = isset($overrideMapG[$rowKeyG]);
            if ($isManualG) { $kategoriG = $overrideMapG[$rowKeyG]; }

            $ketAsetG = ($entBg['ket'] ?? '') !== '' ? $entBg['ket'] : (($entAg['ket'] ?? '') !== '' ? $entAg['ket'] : ($datAnyKetG[$dkeyG] ?? '-'));
            $itemG = [
                'nomor_aset'      => $r['asset'],
                'sub_number'      => $r['asset_subnumber'],
                'keterangan_aset' => $ketAsetG,
                'kategori'        => $kategoriG,
                'manual'          => $isManualG,
                'nilai'           => $selisihG,
            ];

            if ($kategoriG === 'Pencatatan Aset Baru') {
                $result['penambahan']['sattlement_aset_baru'] += $selisihG;
                $result['penambahan']['items'][] = $itemG;
            } elseif ($kategoriG === 'Reklas Asset Class') {
                if ($selisihG >= 0) {
                    $result['penambahan']['reklas'] += $selisihG;
                    $result['penambahan']['items'][] = $itemG;
                } else {
                    $result['pengurangan']['reklas'] += $selisihG;
                    $result['pengurangan']['items'][] = $itemG;
                }
            } elseif ($kategoriG === 'Umur Ekonomis Habis') {
                $result['pengurangan']['ue_habis'] += $selisihG;
                $result['pengurangan']['items'][] = $itemG;
            } elseif ($kategoriG === 'Penghapusan Aset') {
                $result['pengurangan']['penghapusan_aset'] += $selisihG;
                $result['pengurangan']['items'][] = $itemG;
            } else {
                // Relokasi Aset, Perubahan Umur Ekonomis, Perlu Dicek Manual -> bucket "Lainnya"
                if ($selisihG >= 0) {
                    $result['penambahan']['lainnya'] += $selisihG;
                    $result['penambahan']['items'][] = $itemG;
                } else {
                    $result['pengurangan']['lainnya'] += $selisihG;
                    $result['pengurangan']['items'][] = $itemG;
                }
            }
        }
    }

    $result['penambahan']['total'] = $result['penambahan']['sattlement_aset_baru'] + $result['penambahan']['reklas'] + $result['penambahan']['lainnya'];
    $result['pengurangan']['total'] = $result['pengurangan']['ue_habis'] + $result['pengurangan']['reklas'] + $result['pengurangan']['penghapusan_aset'] + $result['pengurangan']['lainnya'];

    usort($result['penambahan']['items'], fn($a, $b) => abs($b['nilai']) <=> abs($a['nilai']));
    usort($result['pengurangan']['items'], fn($a, $b) => abs($b['nilai']) <=> abs($a['nilai']));

    return $result;
}

if (isset($_POST['action']) && $_POST['action'] === 'get_gl_card_data') {
    header('Content-Type: application/json');
    $accGl    = trim((string)($_POST['account'] ?? ''));
    $tahunGl  = (int)($_POST['tahun'] ?? 0);
    $bulanAGl = isset($_POST['bulan_awal']) && $_POST['bulan_awal'] !== '' ? (int)$_POST['bulan_awal'] : null;
    $bulanBGl = isset($_POST['bulan_akhir']) && $_POST['bulan_akhir'] !== '' ? (int)$_POST['bulan_akhir'] : null;
    if ($accGl === '' || $tahunGl <= 0 || $bulanAGl === null || $bulanBGl === null) {
        echo json_encode(['ok' => false, 'error' => 'Parameter tidak lengkap']);
        exit();
    }
    if (!table_exists($con, 'import_fagll')) {
        echo json_encode(['ok' => false, 'error' => 'Tabel import_fagll tidak ditemukan']);
        exit();
    }
    $dataGl = hitung_gl_card($con, $accGl, $tahunGl, $bulanAGl, $bulanBGl, $namaBulan);
    $dataGl['ok'] = true;
    echo json_encode($dataGl);
    exit();
}

$totalTanpaTanggalValid = 0;
$listTahun = [];
$listBulan = [];
$totalData = 0; $totalNaik = 0; $totalTurun = 0; $totalTetap = 0; $totalTidakAda = 0;
$trenLabel = []; $trenUpload = []; $rekapBulanan = [];
$pivotData = []; $pivotKeterangan = [];
$grandTotalKolom = []; $grandTotalSemua = 0.0;
$detailRows = [];
$totalHalaman = 1;
$anomaliRows = []; $anomaliRowsAllForJs = []; $totalAnomali = 0; $bulanAnomA = null; $bulanAnomB = null; $hanyaBeda = false; $minSelisih = 10;
$anomaliRowsPaged = []; $halamanAnom = 1; $totalHalamanAnom = 1; $perHalamanAnom = 10;

if ($adaTabelPenyusutan) {

    // ── Berapa baris yang tanggalnya gagal diparsing (buat notifikasi) ──
    $resCekTgl = mysqli_query($con, "SELECT COUNT(*) AS c FROM import_fagll WHERE " . date_expr('posting_date') . " IS NULL");
    $totalTanpaTanggalValid = $resCekTgl ? (int)mysqli_fetch_assoc($resCekTgl)['c'] : 0;

    // ── Daftar tahun yang tersedia ──
    $resTahun = mysqli_query($con, "SELECT DISTINCT YEAR(" . date_expr('posting_date') . ") AS th FROM import_fagll WHERE " . date_expr('posting_date') . " IS NOT NULL ORDER BY th DESC");
    if ($resTahun) { while ($r = mysqli_fetch_assoc($resTahun)) { $listTahun[] = $r['th']; } }

    $tahunFilter = isset($_GET['tahun']) && $_GET['tahun'] !== '' ? $_GET['tahun'] : ($listTahun[0] ?? date('Y'));
    $tahunFilterEsc = mysqli_real_escape_string($con, $tahunFilter);

    $tahunAwal        = $tahunFilterEsc . '-01-01';
    $tahunAkhirExkl   = ((int)$tahunFilterEsc + 1) . '-01-01';
    $rangeTahunWhere  = "" . date_expr('ip.posting_date') . " >= '$tahunAwal' AND " . date_expr('ip.posting_date') . " < '$tahunAkhirExkl'";

    // ── Daftar bulan yang ada datanya untuk tahun terpilih ──
    $resBulan = mysqli_query($con, "SELECT DISTINCT MONTH(" . date_expr('posting_date') . ") AS bln FROM import_fagll WHERE " . date_expr('posting_date') . " >= '$tahunAwal' AND " . date_expr('posting_date') . " < '$tahunAkhirExkl' ORDER BY bln ASC");
    if ($resBulan) { while ($r = mysqli_fetch_assoc($resBulan)) { $listBulan[] = (int)$r['bln']; } }

    $bulanFilter = isset($_GET['bulan']) && $_GET['bulan'] !== '' ? (int)$_GET['bulan'] : 'Semua';
    $bulanWhere = ($bulanFilter !== 'Semua') ? " AND MONTH(" . date_expr('ip.posting_date') . ") = '" . (int)$bulanFilter . "'" : '';

    $prevAggAwal = ((int)$tahunFilterEsc - 1) . '-12-01';
    $prevAggSql = "(SELECT cost_center, asset, asset_subnumber, account,
                            YEAR(" . date_expr('posting_date') . ") AS thn, MONTH(" . date_expr('posting_date') . ") AS bln,
                            SUM(" . amount_expr('amount_local_currency') . ") AS total_amt
                     FROM import_fagll
                     WHERE " . date_expr('posting_date') . " >= '$prevAggAwal' AND " . date_expr('posting_date') . " < '$tahunAkhirExkl'
                     GROUP BY cost_center, asset, asset_subnumber, account, thn, bln)";
    $joinPrevBulan = "LEFT JOIN $prevAggSql prevagg
                         ON prevagg.cost_center = ip.cost_center
                        AND prevagg.asset = ip.asset
                        AND prevagg.asset_subnumber = ip.asset_subnumber
                        AND prevagg.account = ip.account
                        AND prevagg.thn = YEAR(DATE_SUB(" . date_expr('ip.posting_date') . ", INTERVAL 1 MONTH))
                        AND prevagg.bln = MONTH(DATE_SUB(" . date_expr('ip.posting_date') . ", INTERVAL 1 MONTH))";
                        
    if (!$isAjaxAnomFragment && !$isAjaxAnomFull) {
    // ── KPI: total data, naik, turun, tidak ada pembanding bulan lalu ──
    $sqlKpi = "SELECT
                  COUNT(*) AS total_data,
                  SUM(CASE WHEN prevagg.total_amt IS NULL THEN 1 ELSE 0 END) AS tidak_ada,
                  SUM(CASE WHEN prevagg.total_amt IS NOT NULL AND " . amount_expr('ip.amount_local_currency') . " > prevagg.total_amt THEN 1 ELSE 0 END) AS naik,
                  SUM(CASE WHEN prevagg.total_amt IS NOT NULL AND " . amount_expr('ip.amount_local_currency') . " < prevagg.total_amt THEN 1 ELSE 0 END) AS turun
               FROM import_fagll ip
               $joinPrevBulan
               WHERE $rangeTahunWhere $bulanWhere";
    $resKpi = mysqli_query($con, $sqlKpi);
    if ($resKpi) {
        $rowKpi = mysqli_fetch_assoc($resKpi);
        $totalData     = (int)$rowKpi['total_data'];
        $totalTidakAda = (int)$rowKpi['tidak_ada'];
        $totalNaik     = (int)$rowKpi['naik'];
        $totalTurun    = (int)$rowKpi['turun'];
        $totalTetap    = $totalData - $totalTidakAda - $totalNaik - $totalTurun;
    }

    // ── Tren total Amount per bulan, abaikan filter bulan ──
    $trenBulanNum = [];
    $sqlTren = "SELECT MONTH(" . date_expr('ip.posting_date') . ") AS bln,
                       SUM(" . amount_expr('ip.amount_local_currency') . ") AS total_upload
                FROM import_fagll ip
                WHERE $rangeTahunWhere
                GROUP BY bln ORDER BY bln ASC";
    $resTren = mysqli_query($con, $sqlTren);
    if ($resTren) {
        while ($r = mysqli_fetch_assoc($resTren)) {
            $trenLabel[]    = $namaBulan[(int)$r['bln']] ?? $r['bln'];
            $trenUpload[]   = round((float)$r['total_upload']);
            $trenBulanNum[] = (int)$r['bln'];
        }
    }
    }

    // ── Pre-fetch data 1 tahun penuh (sekali saja) untuk deteksi kategori penyebab naik/turun tiap bulan ──
    $rangeTahunWherePlain = "" . date_expr('posting_date') . " >= '$tahunAwal' AND " . date_expr('posting_date') . " < '$tahunAkhirExkl'";
    $datByBulanFull = []; $dimByBulanFull = []; $nilaiPerBulanAssetFull = [];
    if ($adaTabelDat) {
        $sqlDatFull = "SELECT periode_bulan, nomor_asset, sub_number, sisa_manfaat_aset, gl_account_exp, tgl_perolehan, keterangan_asset, cabang
                       FROM import_dat_penyusutan
                       WHERE CAST(tahun_buku AS UNSIGNED) = " . (int)$tahunFilter;
        $resDatFull = mysqli_query($con, $sqlDatFull);
        if ($resDatFull) {
            while ($d = mysqli_fetch_assoc($resDatFull)) {
                $blnD  = (int)$d['periode_bulan'];
                $dkeyD = trim((string)$d['nomor_asset']) . '|' . trim((string)$d['sub_number']);
                $datByBulanFull[$blnD][$dkeyD] = [
                    'sisa'   => is_numeric($d['sisa_manfaat_aset']) ? (float)$d['sisa_manfaat_aset'] : null,
                    'gl'     => trim((string)$d['gl_account_exp']),
                    'tgl'    => trim((string)$d['tgl_perolehan']),
                    'ket'    => trim((string)$d['keterangan_asset']),
                    'cabang' => trim((string)$d['cabang']),
                ];
            }
        }
    }
    $sqlDimFull = "SELECT MONTH(" . date_expr('posting_date') . ") AS bln, asset, asset_subnumber,
                          MIN(cost_center) AS cost_center, MIN(profit_center) AS profit_center
                   FROM import_fagll WHERE $rangeTahunWherePlain
                   GROUP BY bln, asset, asset_subnumber";
    $resDimFull = mysqli_query($con, $sqlDimFull);
    if ($resDimFull) {
        while ($d = mysqli_fetch_assoc($resDimFull)) {
            $blnDm  = (int)$d['bln'];
            $dkeyDm = trim((string)$d['asset']) . '|' . trim((string)$d['asset_subnumber']);
            $dimByBulanFull[$blnDm][$dkeyDm] = ['cost_center' => $d['cost_center'], 'profit_center' => $d['profit_center']];
        }
    }
    $sqlNilaiFull = "SELECT MONTH(" . date_expr('posting_date') . ") AS bln, cost_center, asset, asset_subnumber, account, profit_center,
                            SUM(" . amount_expr('amount_local_currency') . ") AS nilai
                     FROM import_fagll WHERE $rangeTahunWherePlain
                     GROUP BY bln, cost_center, asset, asset_subnumber, account, profit_center";
    $resNilaiFull = mysqli_query($con, $sqlNilaiFull);
    if ($resNilaiFull) {
        while ($r = mysqli_fetch_assoc($resNilaiFull)) {
            $blnN = (int)$r['bln'];
            $fullkey = trim((string)$r['cost_center']) . '|' . trim((string)$r['asset']) . '|' . trim((string)$r['asset_subnumber']) . '|' . trim((string)$r['account']) . '|' . trim((string)$r['profit_center']);
            $nilaiPerBulanAssetFull[$blnN][$fullkey] = (float)$r['nilai'];
        }
    }

    $assetSubBulanPertama = [];
    foreach ($nilaiPerBulanAssetFull as $blnN => $keysN) {
        foreach ($keysN as $fullkeyN => $nilaiN) {
            if (abs($nilaiN) < 0.01) continue;
            $partsN = explode('|', $fullkeyN);
            if (count($partsN) < 5) continue;
            $dkeyN = $partsN[1] . '|' . $partsN[2]; // asset|sub
            if (!isset($assetSubBulanPertama[$dkeyN]) || $blnN < $assetSubBulanPertama[$dkeyN]) {
                $assetSubBulanPertama[$dkeyN] = $blnN;
            }
        }
    }

    if (!$isAjaxAnomFragment && !$isAjaxAnomFull) {
    $rekapBulanan = [];
    foreach ($trenUpload as $i => $totalBulanIni) {
        $totalBulanLalu = $i > 0 ? $trenUpload[$i - 1] : null;
        $selisihBulan   = $totalBulanLalu !== null ? ($totalBulanIni - $totalBulanLalu) : null;
        $persenBulan    = ($totalBulanLalu !== null && $totalBulanLalu != 0)
            ? ($selisihBulan / $totalBulanLalu) * 100
            : null;
        $statusBulan = ($selisihBulan === null) ? 'Awal'
            : ($selisihBulan > 0 ? 'Naik' : ($selisihBulan < 0 ? 'Turun' : 'Tetap'));

        // ── Kategori dominan penyebab naik/turun bulan ini (dihitung dari perubahan per aset) ──
        $kategoriUtamaBulan = null; $persenKategoriBulan = null; $breakdownKategoriBulan = [];
        if ($i > 0 && ($statusBulan === 'Naik' || $statusBulan === 'Turun')) {
            $ringkasanKat = hitung_ringkasan_kategori_bulan(
                $trenBulanNum[$i - 1], $trenBulanNum[$i],
                $datByBulanFull, $dimByBulanFull, $nilaiPerBulanAssetFull,
                $namaBulan, $tahunFilter, $assetSubBulanPertama
            );
            if ($ringkasanKat !== null) {
                $kategoriUtamaBulan     = $ringkasanKat['kategori_utama'];
                $persenKategoriBulan    = $ringkasanKat['persen_utama'];
                $breakdownKategoriBulan = $ringkasanKat['breakdown'];
            }
        }

        $rekapBulanan[] = [
            'label'    => $trenLabel[$i],
            'total'    => $totalBulanIni,
            'selisih'  => $selisihBulan,
            'persen'   => $persenBulan,
            'status'   => $statusBulan,
            'bln'      => $trenBulanNum[$i],
            'bln_prev' => $i > 0 ? $trenBulanNum[$i - 1] : null,
            'kategori_utama'     => $kategoriUtamaBulan,
            'kategori_persen'    => $persenKategoriBulan,
            'kategori_breakdown' => $breakdownKategoriBulan,
        ];
    }
    }

    // ── Cek Anomali Antar 2 Bulan (BEBAS pilih bulan, gak harus berurutan) ──
    $bulanAnomA = isset($_GET['bulan_a']) && $_GET['bulan_a'] !== '' ? (int)$_GET['bulan_a'] : ($listBulan[0] ?? null);
    $bulanAnomB = isset($_GET['bulan_b']) && $_GET['bulan_b'] !== '' ? (int)$_GET['bulan_b'] : ($listBulan[count($listBulan) - 1] ?? null);
    $hanyaBeda  = isset($_GET['hanya_beda']) && $_GET['hanya_beda'] === '1';
    // Ambang batas "selisih kecil" yang disembunyikan (bisa diatur user, default 10, isi 0 untuk tampilkan semua)
    $minSelisih = isset($_GET['min_selisih']) && $_GET['min_selisih'] !== '' ? abs((float)$_GET['min_selisih']) : 10;
    $cariAnom = trim((string)($_GET['cari'] ?? ''));
    $anomaliRows = [];
    $totalAnomali = 0;

    if ($bulanAnomA !== null && $bulanAnomB !== null) {
        $datA = $adaTabelDat ? ($datByBulanFull[$bulanAnomA] ?? []) : [];
        $datB = $adaTabelDat ? ($datByBulanFull[$bulanAnomB] ?? []) : [];
        $dimA = $dimByBulanFull[$bulanAnomA] ?? [];
        $dimB = $dimByBulanFull[$bulanAnomB] ?? [];
        $datAnyKetLookup = [];
        if ($adaTabelDat) {
            $resDatAnyKet = mysqli_query($con, "SELECT nomor_asset, sub_number, MAX(NULLIF(keterangan_asset, '')) AS keterangan_asset
                                                 FROM import_dat_penyusutan
                                                 GROUP BY nomor_asset, sub_number");
            if ($resDatAnyKet) {
                while ($d = mysqli_fetch_assoc($resDatAnyKet)) {
                    $dkeyAny = trim((string)$d['nomor_asset']) . '|' . trim((string)$d['sub_number']);
                    if (($d['keterangan_asset'] ?? '') !== '') {
                        $datAnyKetLookup[$dkeyAny] = $d['keterangan_asset'];
                    }
                }
            }
        }

        // ── Lookup Catatan (isian manual user) untuk kombinasi tahun+bulan_a+bulan_b ini ──
        $catatanLookup = [];
        $sqlCatatan = "SELECT row_key, catatan FROM catatan_penyusutan WHERE tahun = " . (int)$tahunFilter . "
                       AND bulan_a = $bulanAnomA AND bulan_b = $bulanAnomB";
        $resCatatan = mysqli_query($con, $sqlCatatan);
        if ($resCatatan) {
            while ($c = mysqli_fetch_assoc($resCatatan)) {
                $catatanLookup[$c['row_key']] = $c['catatan'];
            }
        }

        // ── Lookup Override Kategori (hasil verifikasi manual) untuk kombinasi tahun+bulan_a+bulan_b ini ──
        $overrideLookup = [];
        $sqlOverride = "SELECT row_key, kategori_manual FROM override_kategori_penyusutan WHERE tahun = " . (int)$tahunFilter . "
                       AND bulan_a = $bulanAnomA AND bulan_b = $bulanAnomB";
        $resOverride = mysqli_query($con, $sqlOverride);
        if ($resOverride) {
            while ($ov = mysqli_fetch_assoc($resOverride)) {
                $overrideLookup[$ov['row_key']] = $ov['kategori_manual'];
            }
        }

        $sqlAnom = "SELECT cost_center, asset, asset_subnumber, account, profit_center,
                           SUM(CASE WHEN MONTH(" . date_expr('posting_date') . ") = $bulanAnomA THEN " . amount_expr('amount_local_currency') . " ELSE 0 END) AS nilai_a,
                           SUM(CASE WHEN MONTH(" . date_expr('posting_date') . ") = $bulanAnomB THEN " . amount_expr('amount_local_currency') . " ELSE 0 END) AS nilai_b,
                           MAX(CASE WHEN MONTH(" . date_expr('posting_date') . ") = $bulanAnomA THEN 1 ELSE 0 END) AS ada_a,
                           MAX(CASE WHEN MONTH(" . date_expr('posting_date') . ") = $bulanAnomB THEN 1 ELSE 0 END) AS ada_b
                    FROM import_fagll ip
                    WHERE $rangeTahunWhere AND MONTH(" . date_expr('posting_date') . ") IN ($bulanAnomA, $bulanAnomB)
                    GROUP BY cost_center, asset, asset_subnumber, account, profit_center";
        $resAnom = mysqli_query($con, $sqlAnom);
        if ($resAnom) {
            while ($r = mysqli_fetch_assoc($resAnom)) {
                $nilaiA = (float)$r['nilai_a'];
                $nilaiB = (float)$r['nilai_b'];
                $adaA = (int)$r['ada_a'] === 1;
                $adaB = (int)$r['ada_b'] === 1;
                $selisih = $nilaiB - $nilaiA;
                $persen  = ($adaA && $nilaiA != 0) ? ($selisih / $nilaiA) * 100 : null;
                if (!$adaA) { $status = 'Tercatat Baru'; }
                elseif (!$adaB) { $status = 'Hilang/Selesai'; }
                elseif ($selisih > 0) { $status = 'Naik'; }
                elseif ($selisih < 0) { $status = 'Turun'; }
                else { $status = 'Tetap'; }

                if ($hanyaBeda && $status === 'Tetap') { continue; }
                if ($minSelisih > 0) {
                    if ($adaA && $adaB) {
                        if (abs($selisih) <= $minSelisih) { continue; }
                    } else {
                        $nilaiRelevan = $adaA ? $nilaiA : $nilaiB;
                        if (abs($nilaiRelevan) <= $minSelisih) { continue; }
                    }
                }

                // ── Tentukan kategori perubahan (Penambahan/Pengurangan Penyusutan) via DAT ──
                $kategori = null; $ketDetail = null;
                $dkey = trim((string)$r['asset']) . '|' . trim((string)$r['asset_subnumber']);
                $entA = $datA[$dkey] ?? null;
                $entB = $datB[$dkey] ?? null;
                $dmA  = $dimA[$dkey] ?? null;
                $dmB  = $dimB[$dkey] ?? null;
                $namaBulanA = $namaBulan[$bulanAnomA] ?? $bulanAnomA;
                $namaBulanB = $namaBulan[$bulanAnomB] ?? $bulanAnomB;

                $isPsakKsp = (trim((string)$r['asset']) === '-' && trim((string)$r['asset_subnumber']) === '-');

                if ($isPsakKsp) {
                    $kategori = 'PSAK/KSP';
                    $ketDetail = "Entri otomatis PSAK 73 / Penyusutan KSP dari SAP (tidak memiliki nomor aset tetap asli), sehingga tidak ikut diperhitungkan dalam kategori perubahan penyusutan aset tetap manapun.";
                } elseif ($status === 'Naik' || $status === 'Turun') {
                    if ($entA && $entB && $entA['gl'] !== '' && $entB['gl'] !== '' && $entA['gl'] !== $entB['gl']) {
                        $kategori = 'Reklas Asset Class';
                        $ketDetail = "GL Account referensi DAT berubah dari {$entA['gl']} ($namaBulanA) menjadi {$entB['gl']} ($namaBulanB), meski posting aktual masih tercatat di GL Account {$r['account']}.";
                    } elseif ($entA && $entB && $entA['cabang'] !== '' && $entB['cabang'] !== '' && $entA['cabang'] !== $entB['cabang']) {
                        $kategori = 'Relokasi Aset';
                        $ketDetail = "Cabang referensi DAT berubah dari {$entA['cabang']} ($namaBulanA) menjadi {$entB['cabang']} ($namaBulanB).";
                    } elseif ((function () use ($entA, $entB, $tahunFilter, $assetSubBulanPertama, $dkey, $bulanAnomA) {
                        $tglAcuanBaru = ($entA['tgl'] ?? '') !== '' ? $entA['tgl'] : ($entB['tgl'] ?? '');
                        if ($tglAcuanBaru === '' || strtotime($tglAcuanBaru) === false) return false;
                        $acquisitionRecent = ((int) date('Y', strtotime($tglAcuanBaru))) >= ($tahunFilter - 1);
                        return $acquisitionRecent && (($assetSubBulanPertama[$dkey] ?? null) === $bulanAnomA);
                    })()) {
                        $kategori = 'Pencatatan Aset Baru';
                        $tglPerolehanInfo = ($entA['tgl'] ?? '') !== '' ? $entA['tgl'] : ($entB['tgl'] ?? '-');
                        $ketDetail = "Aset ini baru diperoleh (tanggal perolehan: $tglPerolehanInfo) dan baru mulai ada posting penyusutan sejak $namaBulanA, sehingga sisa umur manfaat yang belum berkurang sesuai jadwal dari $namaBulanA ke $namaBulanB masih wajar sebagai bagian dari pencatatan aset baru (posting baru mulai berjalan/menyusul).";
                    } elseif ($entA && $entB && $entA['sisa'] !== null && $entB['sisa'] !== null) {
                        $deltaSisa = $entB['sisa'] - $entA['sisa'];
                        $expectedDeltaSisa = $bulanAnomA - $bulanAnomB;
                        $sudahHabisMasaManfaat = ($entA['sisa'] <= 0 && $entB['sisa'] <= 0);
                        if ($deltaSisa != $expectedDeltaSisa && !$sudahHabisMasaManfaat) {
                            $jarakBulan = abs($bulanAnomB - $bulanAnomA);
                            $kategori = 'Perubahan Umur Ekonomis';
                            if ($deltaSisa == 0) {
                                $ketDetail = "Sisa umur manfaat seharusnya berkurang $jarakBulan bulan dari $namaBulanA ke $namaBulanB (pengurangan normal), tapi kenyataannya tetap di " . (int)$entA['sisa'] . " bulan — tidak berkurang sama sekali.";
                            } elseif ($deltaSisa > 0) {
                                $ketDetail = "Sisa umur manfaat malah bertambah dari " . (int)$entA['sisa'] . " bulan ($namaBulanA) menjadi " . (int)$entB['sisa'] . " bulan ($namaBulanB), padahal seharusnya berkurang $jarakBulan bulan — kemungkinan ada perpanjangan umur ekonomis.";
                            } elseif ($deltaSisa > $expectedDeltaSisa) {
                                $ketDetail = "Sisa umur manfaat berkurang dari " . (int)$entA['sisa'] . " ke " . (int)$entB['sisa'] . " bulan ($namaBulanA ke $namaBulanB), tapi cuma berkurang " . abs((int)$deltaSisa) . " bulan — lebih sedikit dari pengurangan normal ($jarakBulan bulan).";
                            } else {
                                $ketDetail = "Sisa umur manfaat berkurang dari " . (int)$entA['sisa'] . " ke " . (int)$entB['sisa'] . " bulan ($namaBulanA ke $namaBulanB), berkurang " . abs((int)$deltaSisa) . " bulan — lebih banyak dari pengurangan normal ($jarakBulan bulan), kemungkinan ada revisi umur ekonomis.";
                            }
                        }
                    }
                    if ($kategori === null && $entA === null && ($assetSubBulanPertama[$dkey] ?? null) === $bulanAnomA) {
                        $kategori = 'Pencatatan Aset Baru';
                        $ketDetail = "Aset ini baru mulai tercatat/disusutkan sejak $namaBulanA (belum pernah ada datanya di bulan manapun sebelum itu di tahun $tahunFilter), sehingga perubahan nilai dari $namaBulanA ke $namaBulanB masih wajar sebagai bagian dari pencatatan aset baru.";
                    }
                    if ($kategori === null) {
                        $kategori = 'Perlu Dicek Manual';
                        $arahPerubahan = $status === 'Naik' ? 'kenaikan' : 'penurunan';
                        if (!$entA && !$entB) {
                            $ketDetail = "Aset ini tidak ditemukan di data DAT pada $namaBulanA maupun $namaBulanB, sehingga penyebab perubahan nilai ($arahPerubahan) tidak bisa dipetakan otomatis ke kategori manapun.";
                        } elseif (!$entA && $entB) {
                            $ketDetail = "Data DAT $namaBulanA tidak ditemukan (belum di-upload / aset belum tercatat), jadi $arahPerubahan-nya tidak bisa dipastikan penyebabnya. Data DAT $namaBulanB tersedia dan tidak menunjukkan perubahan GL Account/Cabang/Sisa Manfaat.";
                        } elseif ($entA && !$entB) {
                            $ketDetail = "Data DAT $namaBulanB tidak ditemukan (belum di-upload), jadi $arahPerubahan-nya tidak bisa dipastikan penyebabnya. Data DAT $namaBulanA tersedia dan tidak menunjukkan perubahan GL Account/Cabang/Sisa Manfaat.";
                        } else {
                            $ketDetail = "Data DAT ($namaBulanA & $namaBulanB) untuk aset ini sama persis (GL Account, Cabang, Sisa Manfaat), tapi nilai Amount tetap berubah. Kemungkinan koreksi/penyesuaian posting manual — perlu dicek langsung ke jurnal SAP.";
                        }
                    }
                } elseif ($status === 'Tercatat Baru') {
                    if ($entA && $entA['gl'] !== '' && $entA['gl'] !== $r['account']) {
                        $kategori = 'Reklas Asset Class';
                        $ketDetail = "Sebelumnya tercatat di GL Account {$entA['gl']} ($namaBulanA), sekarang di GL Account {$r['account']} ($namaBulanB).";
                    } elseif ($dmA && (($dmA['cost_center'] != $r['cost_center']) || ($dmA['profit_center'] != $r['profit_center']))) {
                        $kategori = 'Relokasi Aset';
                        $ketDetail = "Sebelumnya di Cost Center {$dmA['cost_center']} / Profit Center {$dmA['profit_center']} ($namaBulanA), sekarang di Cost Center {$r['cost_center']} / Profit Center {$r['profit_center']} ($namaBulanB).";
                    } elseif ($entA && $entA['sisa'] !== null && $entA['sisa'] <= 1 && $entB && $entB['sisa'] !== null && $entB['sisa'] > 1) {
                        $kategori = 'Perubahan Umur Ekonomis';
                        $ketDetail = "Sudah tercatat di DAT sejak $namaBulanA dengan sisa umur manfaat sudah/hampir habis (" . (int)$entA['sisa'] . " bulan), tapi di $namaBulanB sisa umur manfaatnya direvisi jadi " . (int)$entB['sisa'] . " bulan — penyusutan aktif lagi karena umur ekonomisnya diperpanjang.";
                    } elseif ($entB) {
                        $kategori = 'Pencatatan Aset Baru';
                        if (!$entA) {
                            if ($entB['tgl'] !== '' && date('Y-m', strtotime($entB['tgl'])) === sprintf('%04d-%02d', $tahunFilter, $bulanAnomB)) {
                                $ketDetail = "Tanggal perolehan aset: " . $entB['tgl'] . ' (sesuai periode ' . $namaBulanB . ').';
                            } else {
                                $ketDetail = "Aset baru muncul di data DAT pada periode $namaBulanB"
                                    . ($entB['tgl'] !== '' ? " (tanggal perolehan tercatat: {$entB['tgl']})" : '')
                                    . ", sebelumnya ($namaBulanA) belum ada di data DAT sama sekali.";
                            }
                        } else {
                            $sisaAInfo = $entA['sisa'] !== null ? ((int)$entA['sisa'] . ' bulan') : 'tidak diketahui';
                            $ketDetail = "Aset sudah terdaftar di DAT sejak $namaBulanA (sisa umur manfaat saat itu $sisaAInfo), namun baru mulai ada posting penyusutan di $namaBulanB"
                                . ($entB['tgl'] !== '' ? " (tanggal perolehan tercatat: {$entB['tgl']})" : '')
                                . " — kemungkinan pencatatan/posting di SAP telat dari tanggal perolehan aslinya, jadi tetap dihitung sebagai pencatatan aset baru.";
                        }
                    } else {
                        $kategori = 'Perlu Dicek Manual';
                        $ketDetail = $adaTabelDat
                            ? "Aset belum ditemukan di data DAT periode $namaBulanA maupun tanggal perolehannya tidak jatuh di $namaBulanB. Perlu dicek manual."
                            : "Tabel referensi DAT belum tersedia, kategori tidak bisa dipastikan otomatis.";
                    }
                } elseif ($status === 'Hilang/Selesai') {
                    if ($entB && $entB['gl'] !== '' && $entB['gl'] !== $r['account']) {
                        $kategori = 'Reklas Asset Class';
                        $ketDetail = "Pindah dari GL Account {$r['account']} ($namaBulanA) ke GL Account {$entB['gl']} ($namaBulanB).";
                    } elseif ($dmB && (($dmB['cost_center'] != $r['cost_center']) || ($dmB['profit_center'] != $r['profit_center']))) {
                        $kategori = 'Relokasi Aset';
                        $ketDetail = "Pindah dari Cost Center {$r['cost_center']} / Profit Center {$r['profit_center']} ($namaBulanA) ke Cost Center {$dmB['cost_center']} / Profit Center {$dmB['profit_center']} ($namaBulanB).";
                    } elseif ($entA && $entA['sisa'] !== null && $entA['sisa'] <= 1) {
                        $kategori = 'Umur Ekonomis Habis';
                        $ketDetail = "Sisa umur manfaat sudah habis (" . (int)$entA['sisa'] . " bulan) per $namaBulanA, sehingga tidak disusutkan lagi mulai $namaBulanB.";
                    } elseif ($entB && $entB['sisa'] !== null && $entA && $entA['sisa'] !== null && $entB['sisa'] <= 0 && $entA['sisa'] > 1) {
                        $kategori = 'Perubahan Umur Ekonomis';
                        $ketDetail = "Sisa umur manfaat masih " . (int)$entA['sisa'] . " bulan per $namaBulanA, tapi direvisi jadi habis (" . (int)$entB['sisa'] . " bulan) per $namaBulanB — penyusutan dihentikan lebih awal dari jadwal normal.";
                    } elseif (!$entB) {
                        $kategori = 'Penghapusan Aset';
                        $ketDetail = $adaTabelDat
                            ? "Aset tidak lagi tercatat sama sekali di data DAT mulai $namaBulanB (bukan cuma berhenti disusutkan). Kemungkinan dihapus/write-off — cek modul Usulan/Pelaksanaan Penghapusan."
                            : "Tabel referensi DAT belum tersedia, kategori tidak bisa dipastikan otomatis.";
                    } else {
                        $kategori = 'Penghapusan Aset';
                        $ketDetail = $adaTabelDat
                            ? "Aset tidak lagi tercatat di data penyusutan mulai $namaBulanB, sementara data DAT masih ada. Kemungkinan dihapus/write-off — cek modul Usulan/Pelaksanaan Penghapusan."
                            : "Tabel referensi DAT belum tersedia, kategori tidak bisa dipastikan otomatis.";
                    }
                }

                $rowKeyThis = md5($tahunFilter . '|' . $bulanAnomA . '|' . $bulanAnomB . '|' . $r['cost_center'] . '|' . $r['asset'] . '|' . $r['asset_subnumber'] . '|' . $r['account'] . '|' . $r['profit_center']);
                $kategoriOtomatisIni = $kategori;
                $kategoriManualAda   = isset($overrideLookup[$rowKeyThis]);
                if ($kategoriManualAda) { $kategori = $overrideLookup[$rowKeyThis]; }

                $keteranganAsset = ($entB['ket'] ?? '') !== '' ? $entB['ket'] : (($entA['ket'] ?? '') !== '' ? $entA['ket'] : ($datAnyKetLookup[$dkey] ?? '-'));

                $anomaliRows[] = [
                    'cost_center' => $r['cost_center'], 'asset' => $r['asset'],
                    'asset_subnumber' => $r['asset_subnumber'], 'account' => $r['account'],
                    'profit_center' => $r['profit_center'],
                    'keterangan_asset' => $keteranganAsset,
                    'nilai_a' => $adaA ? $nilaiA : null,
                    'nilai_b' => $adaB ? $nilaiB : null,
                    'selisih' => ($adaA && $adaB) ? $selisih : null,
                    'persen' => $persen,
                    'status' => $status,
                    'kategori' => $kategori,
                    'kategori_otomatis' => $kategoriOtomatisIni,
                    'kategori_manual' => $kategoriManualAda,
                    'ket_detail' => $ketDetail,
                    'row_key' => $rowKeyThis,
                    'catatan' => $catatanLookup[$rowKeyThis] ?? '',
                ];
            }

            $anomaliRowsAllForJs = $anomaliRows;
            if ($isAjaxAnomFull) {
                header('Content-Type: application/json');
                echo json_encode([
                    'ok'       => true,
                    'rows'     => $anomaliRowsAllForJs,
                    'bulan_a'  => $bulanAnomA,
                    'bulan_b'  => $bulanAnomB,
                    'nama_a'   => $namaBulan[$bulanAnomA] ?? $bulanAnomA,
                    'nama_b'   => $namaBulan[$bulanAnomB] ?? $bulanAnomB,
                ]);
                exit();
            }

            if ($cariAnom !== '') {
                $cariLower = mb_strtolower($cariAnom);
                $anomaliRows = array_values(array_filter($anomaliRows, function ($ar) use ($cariLower) {
                    $haystack = mb_strtolower(implode(' ', [
                        $ar['cost_center'], $ar['asset'], $ar['asset_subnumber'], $ar['account'],
                        $ar['keterangan_asset'] ?? '', $ar['profit_center'], $ar['status'],
                        $ar['kategori'] ?? '', $ar['catatan'] ?? '',
                    ]));
                    return mb_strpos($haystack, $cariLower) !== false;
                }));
            }
            // Urutkan berdasarkan pilihan header (klik kolom), default: selisih paling mencolok duluan
            $sortByAnom  = isset($_GET['sort_by']) ? (string)$_GET['sort_by'] : '';
            $sortDirAnom = (isset($_GET['sort_dir']) && $_GET['sort_dir'] === 'desc') ? 'desc' : 'asc';
            $allowedSortAnom = ['cost_center','asset','asset_subnumber','account','profit_center','nilai_a','nilai_b','selisih','persen','status'];
            if (!in_array($sortByAnom, $allowedSortAnom, true)) { $sortByAnom = ''; }

            usort($anomaliRows, function ($x, $y) use ($sortByAnom, $sortDirAnom) {
                if ($sortByAnom === '') {
                    // default: selisih paling besar (naik/turun paling mencolok) di atas
                    return abs($y['selisih'] ?? 0) <=> abs($x['selisih'] ?? 0);
                }
                $vx = $x[$sortByAnom]; $vy = $y[$sortByAnom];
                if (in_array($sortByAnom, ['nilai_a', 'nilai_b', 'selisih', 'persen'], true)) {
                    if ($vx === null && $vy === null) { $cmp = 0; }
                    elseif ($vx === null) { $cmp = 1; }
                    elseif ($vy === null) { $cmp = -1; }
                    else { $cmp = $vx <=> $vy; }
                } else {
                    $cmp = strcmp((string)$vx, (string)$vy);
                }
                return $sortDirAnom === 'desc' ? -$cmp : $cmp;
            });
            $totalAnomali = count($anomaliRows);
        }
    }

    if (isset($_GET['export']) && $_GET['export'] === 'excel') {
        $namaBulanAExp = $namaBulan[$bulanAnomA] ?? $bulanAnomA;
        $namaBulanBExp = $namaBulan[$bulanAnomB] ?? $bulanAnomB;
        $namaFileExp = 'perbandingan_amount_' . $tahunFilter . '_' . $namaBulanAExp . '_vs_' . $namaBulanBExp . '.xlsx';

        $rowsAnomSingle = [];
        foreach ($anomaliRows as $ar) {
            $rowsAnomSingle[] = [
                $ar['cost_center'], $ar['asset'], $ar['asset_subnumber'], $ar['account'], $ar['keterangan_asset'] ?? '-', $ar['profit_center'],
                $ar['nilai_a'], $ar['nilai_b'], $ar['selisih'],
                $ar['persen'] !== null ? round($ar['persen'], 2) : null,
                $ar['status'], $ar['kategori'] ?? '', $ar['ket_detail'] ?? '', $ar['catatan'] ?? '',
            ];
        }
        $sheetSingle = [
            'name' => "Perbandingan $namaBulanAExp-$namaBulanBExp",
            'header' => ['Cost Center', 'No Asset', 'Subnumber', 'Account', 'Keterangan Aset', 'Profit Center',
                         "Nilai $namaBulanAExp", "Nilai $namaBulanBExp", 'Selisih', 'Perubahan (%)', 'Status', 'Kategori', 'Keterangan', 'Catatan'],
            'rows' => $rowsAnomSingle,
            'currency_cols' => [6, 7, 8], // Nilai A, Nilai B, Selisih -> format Accounting Rupiah
            'percent_cols'  => [9],       // Perubahan (%) -> format Percent
        ];
        export_multi_sheet_xlsx($namaFileExp, [$sheetSingle]);
        exit;
    }
    // Pagination hasil anomali (di level PHP, karena datanya sudah kecil setelah di-GROUP BY per asset)
    $perHalamanAnom = 10;
    $halamanAnom = isset($_GET['halaman_anom']) ? max(1, (int)$_GET['halaman_anom']) : 1;
    $totalHalamanAnom = max(1, (int)ceil($totalAnomali / $perHalamanAnom));
    if ($halamanAnom > $totalHalamanAnom) { $halamanAnom = $totalHalamanAnom; }
    $anomaliRowsPaged = array_slice($anomaliRows, ($halamanAnom - 1) * $perHalamanAnom, $perHalamanAnom);

    if ($isAjaxAnomFragment) {
        header('Content-Type: application/json');
        ob_start();
        render_anomali_fragment();
        $fragmentHtml = ob_get_clean();
        echo json_encode([
            'ok'            => true,
            'html'          => $fragmentHtml,
            'total'         => $totalAnomali,
            'halaman'       => $halamanAnom,
            'total_halaman' => $totalHalamanAnom,
            'sort_by'       => $sortByAnom,
            'sort_dir'      => $sortDirAnom,
        ]);
        exit();
    }

    // ── Pivot Account x Bulan (abaikan filter bulan, selalu tampilkan semua bulan di tahun itu) ──
    $sqlPivot = "SELECT ip.account, MONTH(" . date_expr('ip.posting_date') . ") AS bln,
                        SUM(" . amount_expr('ip.amount_local_currency') . ") AS total
                 FROM import_fagll ip
                 WHERE $rangeTahunWhere
                 GROUP BY ip.account, bln";
    $resPivot = mysqli_query($con, $sqlPivot);
    if ($resPivot) {
        while ($r = mysqli_fetch_assoc($resPivot)) {
            $acc = $r['account'] !== '' ? $r['account'] : '(Tanpa Account)';
            $pivotData[$acc][(int)$r['bln']] = (float)$r['total'];
        }
        ksort($pivotData);
    }

    // ── Keterangan Asset per GL Account (mapping statis Asset Class Name) ──
    $pivotKeterangan = get_pivot_keterangan_map();
    $glBulanAwalDefault = null; $glBulanAkhirDefault = null;
    if (count($listBulan) >= 2) {
        $glBulanAwalDefault  = $listBulan[count($listBulan) - 2];
        $glBulanAkhirDefault = $listBulan[count($listBulan) - 1];
    } elseif (count($listBulan) === 1) {
        $glBulanAwalDefault = $glBulanAkhirDefault = $listBulan[0];
    }

    $glBulanAwalExp  = isset($_GET['gl_bulan_awal'])  && $_GET['gl_bulan_awal']  !== '' ? (int)$_GET['gl_bulan_awal']  : $glBulanAwalDefault;
    $glBulanAkhirExp = isset($_GET['gl_bulan_akhir']) && $_GET['gl_bulan_akhir'] !== '' ? (int)$_GET['gl_bulan_akhir'] : $glBulanAkhirDefault;
    $glListRows = [];
    if ($glBulanAwalDefault !== null && $glBulanAkhirDefault !== null) {
        foreach ($pivotData as $accGlList => $perBulanGlList) {
            $nA = $perBulanGlList[$glBulanAwalDefault] ?? 0.0;
            $nB = $perBulanGlList[$glBulanAkhirDefault] ?? 0.0;
            $glListRows[] = [
                'account'     => $accGlList,
                'keterangan'  => $pivotKeterangan[$accGlList] ?? '-',
                'nilai_awal'  => $nA,
                'nilai_akhir' => $nB,
                'selisih'     => $nB - $nA,
            ];
        }
        usort($glListRows, fn($a, $b) => abs($b['selisih']) <=> abs($a['selisih']));
    }

    $grandTotalKolom = array_fill_keys($listBulan, 0.0);
    foreach ($pivotData as $acc => $perBulan) {
        foreach ($listBulan as $b) {
            $v = $perBulan[$b] ?? 0;
            $grandTotalKolom[$b] += $v;
            $grandTotalSemua += $v;
        }
    }

    if (isset($_GET['export']) && $_GET['export'] === 'excel_all') {
        ini_set('memory_limit', '512M');
        set_time_limit(120);

        $sheetPresetDefault = ['overview', 'perbandingan', 'rekap_gl'];
        $sheetsDiminta = isset($_GET['sheets']) && $_GET['sheets'] !== ''
            ? array_intersect(explode(',', (string)$_GET['sheets']), $sheetPresetDefault)
            : $sheetPresetDefault;
        if (empty($sheetsDiminta)) { $sheetsDiminta = $sheetPresetDefault; }
        $labelSheetExp = [
            'overview'     => 'Overview',
            'perbandingan' => 'Perbandingan ' . ($namaBulan[$bulanAnomA] ?? $bulanAnomA) . '-' . ($namaBulan[$bulanAnomB] ?? $bulanAnomB),
            'rekap_gl'     => 'Rekap per GL Account',
        ];

        $sheets = [];
        if (in_array('overview', $sheetsDiminta, true)) {
        $rowsOverview = [];
        if ($glBulanAwalExp !== null && $glBulanAkhirExp !== null) {
            $sqlOverview = "SELECT cost_center, asset, asset_subnumber, account, profit_center,
                                    SUM(CASE WHEN MONTH(" . date_expr('posting_date') . ") = $glBulanAwalExp THEN " . amount_expr('amount_local_currency') . " ELSE 0 END) AS nilai_a,
                                    SUM(CASE WHEN MONTH(" . date_expr('posting_date') . ") = $glBulanAkhirExp THEN " . amount_expr('amount_local_currency') . " ELSE 0 END) AS nilai_b,
                                    MAX(CASE WHEN MONTH(" . date_expr('posting_date') . ") = $glBulanAwalExp THEN 1 ELSE 0 END) AS ada_a,
                                    MAX(CASE WHEN MONTH(" . date_expr('posting_date') . ") = $glBulanAkhirExp THEN 1 ELSE 0 END) AS ada_b
                             FROM import_fagll ip
                             WHERE $rangeTahunWhere AND MONTH(" . date_expr('posting_date') . ") IN ($glBulanAwalExp, $glBulanAkhirExp)
                             GROUP BY cost_center, asset, asset_subnumber, account, profit_center";
            $resOverview = mysqli_query($con, $sqlOverview);
            if ($resOverview) {
                while ($r = mysqli_fetch_assoc($resOverview)) {
                    $nilaiAOv = (float)$r['nilai_a'];
                    $nilaiBOv = (float)$r['nilai_b'];
                    $adaAOv = (int)$r['ada_a'] === 1;
                    $adaBOv = (int)$r['ada_b'] === 1;
                    $selisihOv = $nilaiBOv - $nilaiAOv;
                    if (!$adaAOv) { $statusOv = 'Data Awal'; }
                    elseif (!$adaBOv) { $statusOv = 'Hilang/Selesai'; }
                    elseif ($selisihOv > 0) { $statusOv = 'Naik'; }
                    elseif ($selisihOv < 0) { $statusOv = 'Turun'; }
                    else { $statusOv = 'Tetap'; }

                    // Kategori penyebab -- logic & lookup 1 tahun penuh yang sama kayak tab Perbandingan.
                    $dkeyOv = trim((string)$r['asset']) . '|' . trim((string)$r['asset_subnumber']);
                    $entAOv = $datByBulanFull[$glBulanAwalExp][$dkeyOv] ?? null;
                    $entBOv = $datByBulanFull[$glBulanAkhirExp][$dkeyOv] ?? null;
                    $dmAOv  = $dimByBulanFull[$glBulanAwalExp][$dkeyOv] ?? null;
                    $dmBOv  = $dimByBulanFull[$glBulanAkhirExp][$dkeyOv] ?? null;
                    $accROv = ['account' => $r['account'], 'cost_center' => $r['cost_center'], 'profit_center' => $r['profit_center']];
                    $kategoriOv = null;
                    if ($statusOv === 'Naik' || $statusOv === 'Turun') {
                        $kategoriOv = deteksi_kategori_ps($statusOv, $entAOv, $entBOv, $dmAOv, $dmBOv, $accROv, (int)$tahunFilter, $glBulanAwalExp, $glBulanAkhirExp, $assetSubBulanPertama[$dkeyOv] ?? null);
                        if ($kategoriOv === null) { $kategoriOv = 'Perlu Dicek Manual'; }
                    } elseif ($statusOv === 'Data Awal') {
                        $kategoriOv = deteksi_kategori_ps('Tercatat Baru', null, $entBOv, null, $dmBOv, $accROv, (int)$tahunFilter, $glBulanAwalExp, $glBulanAkhirExp, $assetSubBulanPertama[$dkeyOv] ?? null);
                    } elseif ($statusOv === 'Hilang/Selesai') {
                        $kategoriOv = deteksi_kategori_ps('Hilang/Selesai', $entAOv, null, $dmAOv, null, $accROv, (int)$tahunFilter, $glBulanAwalExp, $glBulanAkhirExp, $assetSubBulanPertama[$dkeyOv] ?? null);
                    }

                    $ketAsetOv = ($entBOv['ket'] ?? '') !== '' ? $entBOv['ket'] : (($entAOv['ket'] ?? '') !== '' ? $entAOv['ket'] : '-');

                    $rowsOverview[] = [
                        $r['account'], $r['cost_center'], $r['asset'], $r['asset_subnumber'], $ketAsetOv, $r['profit_center'],
                        $adaAOv ? $nilaiAOv : null, $adaBOv ? $nilaiBOv : null,
                        ($adaAOv && $adaBOv) ? $selisihOv : null,
                        $statusOv, $kategoriOv ?? '-',
                    ];
                }
            }
            usort($rowsOverview, fn($x, $y) => abs($y[8] ?? 0) <=> abs($x[8] ?? 0)); // selisih paling mencolok duluan
        }
        $namaAwalOv  = $namaBulan[$glBulanAwalExp] ?? $glBulanAwalExp;
        $namaAkhirOv = $namaBulan[$glBulanAkhirExp] ?? $glBulanAkhirExp;
        $sheets[] = ['name' => 'Overview', 'currency_cols' => [6, 7, 8],
            'header' => ['Account', 'Cost Center', 'No Asset', 'Subnumber', 'Keterangan Aset', 'Profit Center',
                         "Bulan $namaAwalOv", "Bulan $namaAkhirOv", 'Selisih', 'Status', 'Kategori'],
            'rows' => $rowsOverview];
        }
        // Sheet 2: Perbandingan Amount Antar Bulan (udah ikut kena filter pencarian/hanya_beda/min_selisih yang aktif)
        if (in_array('perbandingan', $sheetsDiminta, true)) {
        $namaBulanAExp = $namaBulan[$bulanAnomA] ?? $bulanAnomA;
        $namaBulanBExp = $namaBulan[$bulanAnomB] ?? $bulanAnomB;
        $rowsAnom = [];
        foreach ($anomaliRows as $ar) {
            $rowsAnom[] = [
                $ar['cost_center'], $ar['asset'], $ar['asset_subnumber'], $ar['keterangan_asset'] ?? '-', $ar['account'], $ar['profit_center'],
                $ar['nilai_a'], $ar['nilai_b'], $ar['selisih'], $ar['persen'] !== null ? round($ar['persen'], 2) : null,
                $ar['status'], $ar['kategori'] ?? '', $ar['ket_detail'] ?? '', $ar['catatan'] ?? '',
            ];
        }
        $sheets[] = ['name' => "Perbandingan $namaBulanAExp-$namaBulanBExp", 'currency_cols' => [6, 7, 8], 'percent_cols' => [9],
            'header' => ['Cost Center', 'No Asset', 'Subnumber', 'Keterangan Aset','Account', 'Profit Center',
                         "Nilai $namaBulanAExp", "Nilai $namaBulanBExp", 'Selisih', 'Perubahan (%)', 'Status', 'Kategori', 'Keterangan', 'Catatan'],
            'rows' => $rowsAnom];
        }

        // Sheet 3: Rekap per Account (GL Account) x Bulan
        if (in_array('rekap_gl', $sheetsDiminta, true)) {
        $headerPivot = ['GL Account', 'Keterangan'];
        foreach ($listBulan as $b) { $headerPivot[] = $namaBulan[$b] ?? $b; }
        $headerPivot[] = 'Total';
        $rowsPivot = [];
        foreach ($pivotData as $acc => $perBulan) {
            $row = [$acc, $pivotKeterangan[$acc] ?? '-'];
            $totalBaris = 0.0;
            foreach ($listBulan as $b) { $v = $perBulan[$b] ?? 0; $row[] = $v; $totalBaris += $v; }
            $row[] = $totalBaris;
            $rowsPivot[] = $row;
        }
        $rowTotal = ['TOTAL', ''];
        foreach ($listBulan as $b) { $rowTotal[] = $grandTotalKolom[$b] ?? 0; }
        $rowTotal[] = $grandTotalSemua;
        $rowsPivot[] = $rowTotal;
        $numCols3 = range(2, count($headerPivot) - 1);
        $sheets[] = ['name' => 'Rekap per Account', 'currency_cols' => $numCols3, 'header' => $headerPivot, 'rows' => $rowsPivot];
        }


        if (empty($sheets)) {
            // Jaga-jaga kalau somehow semua opsi ke-filter habis -- jangan bikin file .xlsx kosong tanpa sheet.
            $sheets[] = ['name' => 'Kosong', 'header' => ['Info'], 'rows' => [['Tidak ada sheet yang dipilih untuk diexport.']]];
        }

        if (count($sheetsDiminta) >= count($sheetPresetDefault)) {
            // Export SEMUA sheet -> tetep pakai nama lama biar konsisten sama link/behavior sebelumnya.
            $namaFileAll = 'dasbor_penyusutan_' . $tahunFilter . '.xlsx';
        } else {
            // Export SEBAGIAN -> nama file ikut label sheet yang beneran dipilih (kalau lebih dari satu, digabung " & ").
            $labelDipilihExp = array_map(function ($k) use ($labelSheetExp) {
                return $labelSheetExp[$k] ?? $k;
            }, $sheetsDiminta);
            $namaFileAll = str_replace(['/', '\\'], '-', implode(' & ', $labelDipilihExp)) . ' - Dasbor Penyusutan ' . $tahunFilter . '.xlsx';
        }
        export_multi_sheet_xlsx($namaFileAll, $sheets);
    }

    // ── Detail rows: DIPAGINASI di level SQL (bukan ditarik semua ke PHP) ──
    $perHalaman = 10;
    $halaman = isset($_GET['halaman']) ? max(1, (int)$_GET['halaman']) : 1;
    $offset = ($halaman - 1) * $perHalaman;
    $totalHalaman = max(1, (int)ceil($totalData / $perHalaman));
    if ($halaman > $totalHalaman) { $halaman = $totalHalaman; $offset = ($halaman - 1) * $perHalaman; }

    $sqlDetail = "SELECT ip.account, ip.cost_center, ip.asset, ip.asset_subnumber, ip.profit_center,
                         ip.posting_date, ip.text, ip.amount_local_currency,
                         prevagg.total_amt AS amount_bulan_lalu
                  FROM import_fagll ip
                  $joinPrevBulan
                  WHERE $rangeTahunWhere $bulanWhere
                  ORDER BY ip.id DESC
                  LIMIT $offset, $perHalaman";
    $resDetail = mysqli_query($con, $sqlDetail);
    if ($resDetail) {
        while ($r = mysqli_fetch_assoc($resDetail)) {
            $nilaiUpload = (float)str_replace(['.', ','], '', $r['amount_local_currency']);
            $adaBulanLalu = $r['amount_bulan_lalu'] !== null && $r['amount_bulan_lalu'] !== '';
            $nilaiBulanLalu = $adaBulanLalu ? (float)$r['amount_bulan_lalu'] : null;
            $selisih = $adaBulanLalu ? ($nilaiUpload - $nilaiBulanLalu) : null;
            $status = !$adaBulanLalu ? 'Data Awal' : ($selisih > 0 ? 'Naik' : ($selisih < 0 ? 'Turun' : 'Tetap'));
            $r['nilai_upload']      = $nilaiUpload;
            $r['nilai_bulan_lalu']  = $nilaiBulanLalu;
            $r['selisih']           = $selisih;
            $r['status']            = $status;
            $detailRows[] = $r;
        }
    }
} else {
    $tahunFilter = date('Y');
    $bulanFilter = 'Semua';
    $halaman = 1;
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
  <title>Dasbor Monitoring Beban Penyusutan - Web Aset Tetap</title>
  <link rel="icon" type="image/png" href="../../dist/assets/img/emblem.png" />
  <link rel="shortcut icon" type="image/png" href="../../dist/assets/img/emblem.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes" />
  <meta name="color-scheme" content="light dark" />
  <link rel="preload" href="../../dist/css/adminlte.css" as="style" />
  <link rel="stylesheet" href="../../dist/css/index.css"/>
  <link rel="stylesheet" href="../../dist/css/overlayscrollbars.min.css"/>
  <link rel="stylesheet" href="../../dist/css/bootstrap-icons/bootstrap-icons.min.css"/>
  <link rel="stylesheet" href="../../dist/css/adminlte.css" />
  <link rel="stylesheet" href="../../dist/css/apexcharts.css" />
  <style>
    .app-sidebar { background-color: #0b3a8c !important; }
    .app-header, nav.app-header, .app-header.navbar { border-bottom: 0 !important; box-shadow: none !important; }
    .sidebar-brand { background-color: #0b3a8c !important; margin-bottom: 0 !important; padding: 0.25rem 0 !important; border-bottom: 0 !important; box-shadow: none !important; }
    .sidebar-brand .brand-link { display: block !important; padding: 0.5rem 0.75rem !important; border-bottom: 0 !important; box-shadow: none !important; background-color: transparent !important; }
    .sidebar-brand .brand-link .brand-image { display: block !important; height: auto !important; max-height: 48px !important; margin: 0 !important; padding: 6px 8px !important; background-color: transparent !important; }
    .app-sidebar { border-right: 0 !important; }
    .app-sidebar, .app-sidebar a, .app-sidebar .nav-link, .app-sidebar .nav-link p,
    .app-sidebar .nav-header, .app-sidebar .brand-text, .app-sidebar .nav-icon, .app-sidebar .nav-badge {
      color: #ffffff !important; fill: #ffffff !important;
    }
    .app-sidebar .nav-link .nav-icon, .app-sidebar .nav-link i { color: #ffffff !important; }
    .app-sidebar .nav-link.active, .app-sidebar .nav-link:hover { background-color: #0b5db7 !important; color: #ffffff !important; fill: #ffffff !important; }
    .app-sidebar .nav-link.active .nav-icon, .app-sidebar .nav-link:hover .nav-icon,
    .app-sidebar .nav-link.active i, .app-sidebar .nav-link:hover i { color: #ffffff !important; }

    .kpi-card { border-radius: 12px; padding: 1.1rem 1.3rem; color: #fff; }
    .kpi-card h3 { font-size: 1.8rem; font-weight: 700; margin: 0; }
    .kpi-card small { opacity: .85; }
    .badge-naik   { background-color:#dcfce7; color:#166534; }
    .badge-turun  { background-color:#fee2e2; color:#991b1b; }
    .badge-tetap  { background-color:#f3f4f6; color:#374151; }
    .badge-nodat  { background-color:#fef9c3; color:#854d0e; }
    select.kategori-select { font-weight:600; border-width:1px; text-overflow:ellipsis; white-space:nowrap; overflow:hidden; max-width:170px; }
    select.kategori-select option { background-color:#fff; color:#212529; font-weight:400; }
    select.kategori-select.badge-naik  { border-color:#86efac; }
    select.kategori-select.badge-turun { border-color:#fca5a5; }
    select.kategori-select.badge-tetap { border-color:#d1d5db; }
    select.kategori-select.badge-nodat { border-color:#fde047; }
    select.kategori-select.border-warning { border-color:#f59e0b !important; border-width:2px; }
    #globalLoadingOverlay {
      display:none; position:fixed; inset:0; z-index:10000;
      background:rgba(255,255,255,.75); align-items:center; justify-content:center; flex-direction:column; gap:.75rem;
    }
    #globalLoadingOverlay .spinner-border { width:2.5rem; height:2.5rem; }
    .tabel-perbandingan th { white-space: nowrap; vertical-align: middle; }
    .tabel-perbandingan th a { white-space: nowrap; }
    .tabel-perbandingan, .tabel-perbandingan td, .tabel-perbandingan th {
      font-size: 0.85rem;
    }
    .tabel-rekap-gl, .tabel-rekap-gl td, .tabel-rekap-gl th {
      font-size: 0.85rem;
    }

    /* ── Baris detail Cabang/Profit Center - Aset (drill-down GL Account), di-insert LANGSUNG ke <tbody> via JS ── */
    .gl-cabang-row { background-color:#eaf1fb; cursor:pointer; }
    .gl-cabang-row:hover { background-color:#dbe7f8; }
    .gl-cabang-row td { color:#0b3a8c; font-weight:600; }
    .gl-asset-row td { color:#495057; font-weight:400; }
    .gl-asset-row td:first-child { padding-left:1.5rem; }
    .gl-subtotal-row { background-color:#f8f9fa; }
    .gl-subtotal-row td { border-top:2px solid #dee2e6; }
    /* Kolom "Keterangan Aset" di tabel Rekap GL Account: lebar kolomnya diatur lewat <colgroup>
       (bisa digeser manual -- lihat #glKetResizer), sama persis di semua baris (header, GL
       Account, Cabang, Aset). Teksnya dipotong dengan "..." kalau kepanjangan (bukan wrap ke
       baris baru), biar tinggi baris tetap rapi. Hover buat lihat teks lengkapnya (tooltip). */
    #glRekapTable .gl-ket-cell { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:0; }
    /* Handle drag di pojok kanan header "Keterangan Aset" buat resize lebar kolom manual. */
    /* Handle drag di pojok kanan header "Keterangan Aset" buat resize lebar kolom manual --
       ada ikon grip kecil yang selalu kelihatan (redup), biar user tahu kolom ini bisa digeser. */
    .gl-col-resizer {
      position:absolute; top:0; right:-3px; width:14px; height:100%; cursor:col-resize;
      user-select:none; touch-action:none; z-index:3;
      display:flex; align-items:center; justify-content:center;
    }
    .gl-col-resizer::before {
      content:""; width:4px; height:60%; border-radius:2px;
      background-color:#0b3a8c; opacity:.35;
    }
    .gl-col-resizer:hover::before, .gl-col-resizer.resizing::before { opacity:.9; background-color:#0b5db7; }
    .gl-col-resizer:hover, .gl-col-resizer.resizing { background-color:#0b5db7; opacity:.12; }
    #glRekapTable th.position-relative { padding-right:1rem; }

    /* ── Modal popup detail GL Account (Penambahan/Pengurangan Biaya Penyusutan) ── */
    #glDetailModalPanel { border-radius:.6rem; overflow:hidden; }
    #glDetailModalHeader {
      background: linear-gradient(135deg, #0b3a8c 0%, #1554b8 100%);
      color:#fff;
    }
    #glDetailModalHeader .text-muted { color: rgba(255,255,255,.82) !important; }
  </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div id="globalLoadingOverlay">
  <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Memuat...</span></div>
  <div class="fw-semibold text-primary">Memuat data...</div>
</div>
<div id="glDetailModalBackdrop" style="display:none; position:fixed; inset:0; z-index:10500; background:rgba(15,23,42,.55); align-items:center; justify-content:center; padding:1.5rem; box-sizing:border-box;">
  <div id="glDetailModalPanel" style="background:#fff; width:100%; max-width:1140px; max-height:88vh; display:flex; flex-direction:column; box-shadow:0 20px 50px rgba(0,0,0,.35); margin:0 auto;">
    <div class="d-flex align-items-start justify-content-between p-3" id="glDetailModalHeader">
      <div>
        <div class="fw-bold" id="glDetailModalAccount" style="font-size:1.1rem;"></div>
        <div class="small" id="glDetailModalKeterangan"></div>
      </div>
      <button type="button" class="btn-close btn-close-white" id="glDetailModalClose" aria-label="Tutup"></button>
    </div>
    <div class="p-3" id="glDetailModalBody" style="overflow-y:auto;">
      <div class="text-center text-muted small py-4">Memuat...</div>
    </div>
  </div>
</div>
<div class="app-wrapper">
  <!--begin::Header-->
  <nav class="app-header navbar navbar-expand bg-white border-0 shadow-none">
    <div class="container-fluid">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item dropdown user-menu">
          <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
            <img src="../../dist/assets/img/profile.png" class="user-image rounded-circle shadow" alt="User Image"/>
            <span class="d-none d-md-inline"><?php echo htmlspecialchars($_SESSION['name']); ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
            <li class="user-header text-bg-primary text-center">
              <img src="../../dist/assets/img/profile.png" class="rounded-circle shadow mb-2" alt="User Image" style="width:80px;height:80px;">
              <p class="mb-0 fw-bold"><?php echo htmlspecialchars($_SESSION['name']); ?></p>
              <small>NIPP: <?php echo htmlspecialchars($_SESSION['nipp']); ?></small>
            </li>
            <li class="user-footer d-flex align-items-center px-3 py-2">
              <a href="../profile/profile.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-person"></i> Profile</a>
              <a href="../login/login_view.php" class="btn btn-sm btn-danger ms-auto"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </li>
          </ul>
        </li>
      </ul>
    </div>
  </nav>
  <!--end::Header-->

  <!--begin::Sidebar-->
      <aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
        <div class="sidebar-brand">
          <a href="./index.html" class="brand-link">
            <img
              src="../../dist/assets/img/logo.png"
              class="brand-image"
              alt="Logo Pelindo"
              title="PT Pelabuhan Indonesia"
            />
          </a>
        </div>
        <div class="sidebar-wrapper">
          <nav class="mt-2">
            <ul
              class="nav sidebar-menu flex-column"
              data-lte-toggle="treeview"
              role="navigation"
              aria-label="Main navigation"
              data-accordion="false"
              id="navigation"
            >
            <?php  
            $userNipp = isset($_SESSION['nipp']) ? htmlspecialchars($_SESSION['nipp']) : '';
            $query = "SELECT menus.menu, menus.nama_menu, menus.urutan_menu FROM user_access INNER JOIN menus ON user_access.id_menu = menus.id_menu WHERE user_access.NIPP = '" . mysqli_real_escape_string($con, $userNipp) . "' ORDER BY menus.urutan_menu ASC";
            $result_menu = mysqli_query($con, $query) or die(mysqli_error($con));
             $iconMap = [
                'Dasboard'                        => 'bi bi-grid-fill',
                'Usulan Penghapusan'              => 'bi bi-file-earmark-plus',
                'Daftar Usulan Penghapusan'       => 'bi bi-collection',
                'Approval SubReg'                 => 'bi bi-person-check',
                'Approval Regional'               => 'bi bi-building-check',
                'Persetujuan Penghapusan'         => 'bi bi-shield-check',
                'Daftar Persetujuan Penghapusan'  => 'bi bi-journal-check',
                'Pelaksanaan Penghapusan'         => 'bi bi-gear-wide-connected',
                'Daftar Pelaksanaan Penghapusan'  => 'bi bi-archive-fill',
                'Manajemen Menu'                  => 'bi bi-layout-text-sidebar',
                'Import DAT'                      => 'bi bi-file-earmark-arrow-up',

                // Penyusutan
                'Import Data Penyusutan'          => 'bi bi-upload',
                'Daftar Data Penyusutan'          => 'bi bi-table',
                'Dasbor Monitoring Beban Penyusutan'  => 'bi-bar-chart-line',

                // Monitoring SAP-DAT
                'Import Data Monitoring'          => 'bi bi-upload',
                'Daftar Data Monitoring'          => 'bi bi-table',
                'Dasbor Monitoring SAP-DAT'       => 'bi bi-speedometer2',

                'Daftar Aset Tetap'               => 'bi bi-boxes',
                'Manajemen User'                  => 'bi bi-people',
            ];

            $groupMap = [
                'Usulan Penghapusan'              => 'Penghapusan',
                'Daftar Usulan Penghapusan'       => 'Penghapusan',
                'Approval SubReg'                 => 'Penghapusan',
                'Approval Regional'               => 'Penghapusan',
                'Persetujuan Penghapusan'         => 'Penghapusan',
                'Daftar Persetujuan Penghapusan'  => 'Penghapusan',
                'Pelaksanaan Penghapusan'         => 'Penghapusan',
                'Daftar Aset Tetap'               => 'Penghapusan',
                'Daftar Pelaksanaan Penghapusan'  => 'Penghapusan',

                'Import Data Penyusutan'          => 'Penyusutan',
                'Daftar Data Penyusutan'          => 'Penyusutan',
                'Dasbor Monitoring Beban Penyusutan'  => 'Penyusutan',

                'Import Data Monitoring'          => 'Monitoring SAP-DAT',
                'Daftar Data Monitoring'          => 'Monitoring SAP-DAT',
                'Dasbor Monitoring SAP-DAT'       => 'Monitoring SAP-DAT',

                'Import DAT'                      => 'Manajemen Admin',
                'Manajemen Menu'                  => 'Manajemen Admin',
                'Manajemen User'                  => 'Manajemen Admin',
            ];
            $groupIcon = [
                'Penghapusan'                     => 'bi bi-file-earmark-minus',
                'Penyusutan'                      => 'bi bi-graph-down-arrow',
                'Monitoring SAP-DAT'              => 'bi bi-arrow-left-right',
                'Manajemen Admin'                 => 'bi bi-sliders',               
            ];
            $groupOrder = ['Penghapusan', 'Penyusutan', 'Monitoring SAP-DAT', 'Manajemen Admin'];
            $currentPage = basename($_SERVER['PHP_SELF']);

            $ungrouped = [];
            $grouped   = [];
            while ($row = mysqli_fetch_assoc($result_menu)) {
                $namaMenu = trim($row['nama_menu']);
                if (isset($groupMap[$namaMenu])) {
                    $grouped[$groupMap[$namaMenu]][] = $row;
                } else {
                    $ungrouped[] = $row;
                }
            }

            // ── Render item di luar grup (mis. Dasboard) di paling atas, seperti sebelumnya ──
            foreach ($ungrouped as $row) {
                $namaMenu = trim($row['nama_menu']);
                $icon     = $iconMap[$namaMenu] ?? 'bi bi-circle';
                $isActive = ($currentPage === $row['menu'] . '.php') ? 'active' : '';
                echo '<li class="nav-item"><a href="../' . $row['menu'] . '/' . $row['menu'] . '.php" class="nav-link ' . $isActive . '"><i class="nav-icon ' . $icon . '"></i><p>' . htmlspecialchars($namaMenu) . '</p></a></li>';
            }

            // ── Render tiap grup sebagai dropdown treeview, isinya cuma menu yang user PUNYA AKSES ──
            foreach ($groupOrder as $groupName) {
                if (empty($grouped[$groupName])) continue; // user gak punya akses menu apapun di grup ini

                $itemsGrup = $grouped[$groupName];
                $adaAktif  = false;
                foreach ($itemsGrup as $itemG) {
                    if ($currentPage === $itemG['menu'] . '.php') { $adaAktif = true; break; }
                }
                $liClassGrup   = 'nav-item' . ($adaAktif ? ' menu-open' : '');
                $linkClassGrup = 'nav-link' . ($adaAktif ? ' active' : '');
                $iconGrup      = $groupIcon[$groupName] ?? 'bi bi-folder';

                echo '<li class="' . $liClassGrup . '">';
                echo '<a href="#" class="' . $linkClassGrup . '"><i class="nav-icon ' . $iconGrup . '"></i><p>' . htmlspecialchars($groupName) . '<i class="nav-arrow bi bi-chevron-right"></i></p></a>';
                echo '<ul class="nav nav-treeview">';
                foreach ($itemsGrup as $itemG) {
                    $namaMenuG = trim($itemG['nama_menu']);
                    $iconItemG = $iconMap[$namaMenuG] ?? 'bi bi-circle';
                    $isActiveG = ($currentPage === $itemG['menu'] . '.php') ? 'active' : '';
                    echo '<li class="nav-item"><a href="../' . $itemG['menu'] . '/' . $itemG['menu'] . '.php" class="nav-link ' . $isActiveG . '"><i class="nav-icon ' . $iconItemG . '"></i><p>' . htmlspecialchars($namaMenuG) . '</p></a></li>';
                }
                echo '</ul>';
                echo '</li>';
            }
              ?>
        </ul>
      </nav>
    </div>
  </aside>
  <!--end::Sidebar-->

  <!--begin::App Main-->
  <main class="app-main">
    <div class="app-content-header">
      <div class="container-fluid">
        <div class="row align-items-center">
          <div class="col-sm-6"><h3 class="mb-0">Dasbor Monitoring Beban Penyusutan</h3></div>
          <div class="col-sm-6">
            <div class="d-flex align-items-center justify-content-sm-end gap-2 flex-wrap">
              <?php if (!$adaTabelPenyusutan): ?>
              <span class="badge bg-warning text-dark">Belum ada data di tabel Akumulasi Penyusutan— upload dulu di menu Import DAT.</span>
              <?php endif; ?>
              <form method="get" class="d-flex align-items-center gap-1 mb-0">
                <label class="form-label mb-0 small text-muted text-nowrap"><i class="bi bi-calendar3"></i> Tahun:</label>
                <select name="tahun" class="form-select form-select-sm" onchange="this.form.submit()">
                  <?php if (empty($listTahun)): ?>
                    <option value="<?php echo htmlspecialchars($tahunFilter); ?>"><?php echo htmlspecialchars($tahunFilter); ?></option>
                  <?php else: foreach ($listTahun as $th): ?>
                    <option value="<?php echo htmlspecialchars($th); ?>" <?php echo ($th == $tahunFilter) ? 'selected' : ''; ?>><?php echo htmlspecialchars($th); ?></option>
                  <?php endforeach; endif; ?>
                </select>
              </form>
              <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="../dasbor/dasbor.php">Home</a></li>
                <li class="breadcrumb-item active">Dasbor Monitoring Beban Penyusutan</li>
              </ol>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="app-content">
      <div class="container-fluid">

        <?php if (!$adaTabelPenyusutan): ?>
        <div class="alert alert-info">
          <i class="bi bi-info-circle"></i>
          Belum ada data sama sekali — tabel <code>import_fagll</code> baru akan otomatis
          terbuat setelah kamu upload file pertama kali di menu
          <strong>Import DAT &amp; Penyusutan</strong> (card "Import Data Penyusutan").
        </div>
        <?php endif; ?>

        <?php if ($totalTanpaTanggalValid > 0): ?>
        <div class="alert alert-warning">
          <i class="bi bi-exclamation-triangle"></i>
          <?php echo $totalTanpaTanggalValid; ?> baris di <code>import_fagll</code> punya
          <strong>Posting Date</strong> yang formatnya tidak terbaca otomatis, jadi tidak ikut
          dihitung di dasbor ini (kemungkinan sisa data lama sebelum perbaikan format tanggal).
        </div>
        <?php endif; ?>

        <!-- Tab Navigasi Dasbor -->
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
          <ul class="nav nav-tabs mb-0" id="dasborTab" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link active" id="tab-utama-btn" data-bs-toggle="tab" data-bs-target="#tab-utama" type="button" role="tab" aria-controls="tab-utama" aria-selected="true">
                <i class="bi bi-bar-chart-line"></i> Overview
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" id="tab-perbandingan-btn" data-bs-toggle="tab" data-bs-target="#tab-perbandingan" type="button" role="tab" aria-controls="tab-perbandingan" aria-selected="false">
                <i class="bi bi-arrow-left-right"></i> Analisis Perbandingan
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" id="tab-rekap-gl-btn" data-bs-toggle="tab" data-bs-target="#tab-rekap-gl" type="button" role="tab" aria-controls="tab-rekap-gl" aria-selected="false">
                <i class="bi bi-clipboard-data"></i> Rekap GL Account
              </button>
            </li>
          </ul>
          <?php
            $qExportAllBase = 'tahun=' . urlencode($tahunFilter)
                . '&bulan=' . urlencode($bulanFilter === 'Semua' ? '' : $bulanFilter)
                . '&bulan_a=' . urlencode((string)$bulanAnomA)
                . '&bulan_b=' . urlencode((string)$bulanAnomB)
                . '&hanya_beda=' . ($hanyaBeda ? '1' : '0')
                . '&min_selisih=' . urlencode((string)$minSelisih)
                . '&cari=' . urlencode($cariAnom)
                . '&gl_bulan_awal=' . urlencode((string)$glBulanAwalExp)
                . '&gl_bulan_akhir=' . urlencode((string)$glBulanAkhirExp);
            $qExportAll = 'export=excel_all&' . $qExportAllBase; // default: semua sheet (dipakai tombol utama)
            // Opsi export sebagian: pilih beberapa sheet aja lewat parameter &sheets=a,b,c -- persis
            // 3 opsi ini aja, disamain 1:1 sama 3 tab yang ada (Overview / Analisis Perbandingan / Rekap GL Account).
            $sheetPresetLabel = [
                'overview'     => ['label' => 'Overview',                      'icon' => 'bi-bar-chart-line'],
                'perbandingan' => ['label' => 'Perbandingan Antar Bulan',      'icon' => 'bi-arrow-left-right'],
                'rekap_gl'     => ['label' => 'Rekap per GL Account',          'icon' => 'bi-clipboard-data'],
            ];
          ?>
          <div class="btn-group" id="exportExcelBtnGroup">
            <a href="?<?php echo $qExportAll; ?>" class="btn btn-sm btn-success" data-export-link="all">
              <i class="bi bi-file-earmark-excel"></i> Export Excel (Semua)
            </a>
            <button type="button" class="btn btn-sm btn-success dropdown-toggle dropdown-toggle-split" id="exportExcelDropdownBtn" data-bs-toggle="dropdown" aria-expanded="false" title="Pilih sheet tertentu aja">
              <span class="visually-hidden">Pilihan Export</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end" id="exportExcelDropdownMenu">
              <li><h6 class="dropdown-header">Export Halaman tertentu</h6></li>
              <?php foreach ($sheetPresetLabel as $sKey => $sInfo): ?>
              <li>
                <a class="dropdown-item" data-export-link="<?php echo htmlspecialchars($sKey); ?>" href="?export=excel_all&sheets=<?php echo urlencode($sKey); ?>&<?php echo $qExportAllBase; ?>">
                  <i class="bi <?php echo $sInfo['icon']; ?> me-1"></i><?php echo htmlspecialchars($sInfo['label']); ?>
                </a>
              </li>
              <?php endforeach; ?>
              <!-- <li><hr class="dropdown-divider"></li>
              <li>
                <a class="dropdown-item fw-semibold" data-export-link="all" href="?<?php echo $qExportAll; ?>">
                  <i class="bi bi-files me-1"></i> Semua Sheet (default)
                </a>
              </li> -->
            </ul>
          </div>
          </div>
          <script>
            // Toggle manual (gak gantung ke Bootstrap Dropdown JS component yang mungkin gak
            // ke-bundle di adminlte.js) -- dijamin kebuka meskipun komponen dropdown Bootstrap
            // beneran gak aktif di halaman ini, karena CSS-nya (.dropdown-menu.show) tetap ada.
            (function () {
              const btn  = document.getElementById('exportExcelDropdownBtn');
              const menu = document.getElementById('exportExcelDropdownMenu');
              if (!btn || !menu) return;
              function closeMenu() { menu.classList.remove('show'); btn.setAttribute('aria-expanded', 'false'); }
              function openMenu() {
                menu.classList.add('show');
                menu.style.position = 'absolute';
                menu.style.inset = '0px 0px auto auto';
                menu.style.margin = '0px';
                menu.style.transform = 'translate(0px, ' + btn.offsetHeight + 'px)';
                btn.setAttribute('aria-expanded', 'true');
              }
              btn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (menu.classList.contains('show')) { closeMenu(); } else { openMenu(); }
              });
              document.addEventListener('click', function (e) {
                if (!menu.contains(e.target) && e.target !== btn) { closeMenu(); }
              });
              document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { closeMenu(); }
              });
            })();
          </script>
        <div class="tab-content" id="dasborTabContent">

          <!-- ══════════ TAB: UTAMA ══════════ -->
          <div class="tab-pane fade show active" id="tab-utama" role="tabpanel" aria-labelledby="tab-utama-btn">

        <!-- Chart tren -->
        <div class="card card-primary card-outline mb-4">
          <div class="card-header d-flex align-items-center gap-2">
            <h3 class="card-title mb-0">Tren Amount Penyusutan per Bulan</h3>
            <span class="badge bg-primary rounded-pill">Tahun <?php echo htmlspecialchars($tahunFilter); ?></span>
          </div>
          <div class="card-body">
            <div id="chart-tren-penyusutan"></div>
          </div>
        </div>

        <!-- Ringkasan Penambahan/Pengurangan per GL Account (list, klik baris -> kategori -> aset) -->
        <div class="card card-primary card-outline mb-4" id="ringkasan-gl-account">
          <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <h3 class="card-title mb-0">Penambahan &amp; Pengurangan Biaya Penyusutan per GL Account</h3>
            <span class="badge bg-primary rounded-pill">Tahun <?php echo htmlspecialchars($tahunFilter); ?></span>
            <div class="ms-md-auto d-flex flex-wrap align-items-end gap-2">
              <div>
                <label class="form-label small text-muted mb-0">Periode Awal</label>
                <select class="form-select form-select-sm" id="glListBulanAwal" style="min-width:120px;">
                  <?php foreach ($listBulan as $bGl): ?>
                    <option value="<?php echo $bGl; ?>" <?php echo $bGl === $glBulanAwalDefault ? 'selected' : ''; ?>><?php echo htmlspecialchars($namaBulan[$bGl] ?? $bGl); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="form-label small text-muted mb-0">Periode Akhir</label>
                <select class="form-select form-select-sm" id="glListBulanAkhir" style="min-width:120px;">
                  <?php foreach ($listBulan as $bGl): ?>
                    <option value="<?php echo $bGl; ?>" <?php echo $bGl === $glBulanAkhirDefault ? 'selected' : ''; ?>><?php echo htmlspecialchars($namaBulan[$bGl] ?? $bGl); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
          <div class="card-body table-responsive">
            <?php if (empty($glListRows)): ?>
              <p class="text-muted mb-0">Belum ada data GL Account untuk tahun ini.</p>
            <?php else: ?>
            <table class="table table-bordered table-sm align-middle mb-0" id="glListTable">
              <thead class="table-light">
                <tr>
                  <th>GL Account</th>
                  <th>Keterangan Aset</th>
                  <th class="text-end">Nilai Periode Awal</th>
                  <th class="text-end">Nilai Periode Akhir</th>
                  <th class="text-end">Selisih</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($glListRows as $gr):
                    $selCls  = $gr['selisih'] > 0 ? 'text-success' : ($gr['selisih'] < 0 ? 'text-danger' : '');
                ?>
                <tr class="gl-row" style="cursor:pointer;" data-account="<?php echo htmlspecialchars($gr['account']); ?>" data-keterangan="<?php echo htmlspecialchars($gr['keterangan']); ?>" data-tahun="<?php echo (int)$tahunFilter; ?>" title="Klik untuk lihat kategori Penambahan &amp; Pengurangan">
                  <td class="fw-semibold text-nowrap" style="font-size:1rem;"><i class="bi bi-info-circle gl-caret me-1 text-muted" style="font-size:.7em;" title="Klik baris untuk lihat detail"></i><?php echo htmlspecialchars($gr['account']); ?></td>
                  <td><?php echo htmlspecialchars($gr['keterangan']); ?></td>
                  <td class="text-end gl-row-nilai-awal"><?php echo fmt_rp($gr['nilai_awal']); ?></td>
                  <td class="text-end gl-row-nilai-akhir"><?php echo fmt_rp($gr['nilai_akhir']); ?></td>
                  <td class="text-end fw-semibold <?php echo $selCls; ?> gl-row-selisih"><?php echo ($gr['selisih'] > 0 ? '+' : '') . fmt_rp($gr['selisih']); ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <?php endif; ?>
          </div>
        </div>

          </div>
          <!-- ══════════ /TAB: UTAMA ══════════ -->

          <!-- ══════════ TAB: PERBANDINGAN ANTAR BULAN ══════════ -->
          <div class="tab-pane fade" id="tab-perbandingan" role="tabpanel" aria-labelledby="tab-perbandingan-btn">

        <!-- Perbandingan Amount Antar Bulan -->
        <div class="card card-primary card-outline mb-4" id="perbandingan-amount-antar-bulan">
          <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <i class="bi bi-arrow-left-right"></i>
            <h3 class="card-title mb-0">Analisis Perbandingan Amount Antar Bulan</h3>
            <div class="spinner-border spinner-border-sm text-primary d-none" role="status" id="anomLoadingSpinner" style="width:1rem;height:1rem;" title="Memuat...">
              <span class="visually-hidden">Memuat...</span>
            </div>
            <form method="get" class="ms-md-auto d-flex flex-wrap align-items-end gap-2" id="formFilterAnomBulan">
              <input type="hidden" name="tahun" value="<?php echo htmlspecialchars($tahunFilter); ?>">
              <input type="hidden" name="bulan" value="<?php echo htmlspecialchars($bulanFilter === 'Semua' ? '' : $bulanFilter); ?>">
              <div>
                <label class="form-label small text-muted mb-0">Bulan Pertama</label>
                <select name="bulan_a" id="bulanAnomASelect" class="form-select form-select-sm" style="min-width:120px;">
                  <?php foreach ($listBulan as $b): ?>
                  <option value="<?php echo $b; ?>" <?php echo ($b === $bulanAnomA) ? 'selected' : ''; ?>><?php echo $namaBulan[$b] ?? $b; ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="form-label small text-muted mb-0">Bulan Kedua</label>
                <select name="bulan_b" id="bulanAnomBSelect" class="form-select form-select-sm" style="min-width:120px;">
                  <?php foreach ($listBulan as $b): ?>
                  <option value="<?php echo $b; ?>" <?php echo ($b === $bulanAnomB) ? 'selected' : ''; ?>><?php echo $namaBulan[$b] ?? $b; ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="form-label small text-muted mb-0">Cari</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                  <input type="text" name="cari" id="cariAnomInput" class="form-control" style="min-width:200px;"
                         placeholder="Cost Center / Aset / Account / Keterangan..."
                         value="<?php echo htmlspecialchars($cariAnom); ?>"
                         title="Cari di SELURUH data (semua halaman), bukan cuma yang lagi tampil -- langsung kefilter pas ngetik."
                         autocomplete="off">
                  <button type="button" class="btn btn-outline-secondary <?php echo $cariAnom === '' ? 'd-none' : ''; ?>" id="cariAnomClearBtn" title="Hapus pencarian"><i class="bi bi-x-lg"></i></button>
                </div>
              </div>
            </form>
          </div>
          <div class="card-body">
            <!-- <p class="text-muted small">
              Bandingkan nilai <code>Amount in Local Currency</code> per aset antara 2 bulan mana saja
              (gak harus berurutan, misal Januari vs April) untuk mencari aset yang nilainya berubah tidak wajar.
            </p> -->

            <!-- Popover ringan tanpa dependency JS tambahan, buat keterangan kategori per baris.
                 Statis di luar #anomFragmentContainer (SELALU ada 1x di DOM, gak ikut ke-replace
                 tiap cari/sort/ganti halaman lewat AJAX) supaya gak dobel & event delegasinya awet. -->
            <div id="anomPopover" class="card shadow" style="display:none; position:fixed; z-index:9999; width:320px; max-width:90vw;">
              <div class="card-body p-2">
                <div class="fw-bold small mb-1" id="anomPopoverTitle"></div>
                <div class="small text-muted" id="anomPopoverBody" style="white-space:pre-line; max-height:260px; overflow-y:auto;"></div>
                <button type="button" class="btn-close btn-close-sm position-absolute top-0 end-0 m-1" style="font-size:.6rem;" id="anomPopoverClose" aria-label="Tutup"></button>
              </div>
            </div>

            <div id="anomFragmentContainer">
              <?php render_anomali_fragment(); ?>
            </div>
            <script>
              // Data lengkap (1 pasangan bulan, SEMUA baris, gak kena filter cari) buat mode
              // client-side penuh -- cari/sort/pindah halaman abis ini dikerjain di JS doang, gak
              // roundtrip ke server lagi (lihat IIFE "Live-search ... versi client-side" di bawah).
              window.__anomInitialRows = <?php echo json_encode($anomaliRowsAllForJs); ?>;
              window.__anomKategoriOptions = <?php echo json_encode(daftar_kategori_penyusutan()); ?>;
              window.__anomNamaBulan = <?php echo json_encode($namaBulan); ?>;
            </script>
          </div>
        </div>

          </div>
          <!-- ══════════ /TAB: PERBANDINGAN ANTAR BULAN ══════════ -->
          <!-- ══════════ TAB: REKAP GL ACCOUNT ══════════ -->
          <div class="tab-pane fade" id="tab-rekap-gl" role="tabpanel" aria-labelledby="tab-rekap-gl-btn">

        <!-- Pivot Account x Bulan (spt PivotTable Excel) -->
        <div class="card card-primary card-outline mb-4">
          <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <i class="bi bi-clipboard-data"></i>
            <h3 class="card-title mb-0">Rekap GL Account</h3>
            <span class="badge bg-primary rounded-pill">Tahun <?php echo htmlspecialchars($tahunFilter); ?></span>
            <div class="ms-auto d-flex flex-wrap align-items-center gap-2">
              <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-outline-primary dropdown-toggle" id="glTampilanDropdownBtn" data-bs-toggle="dropdown" aria-expanded="false" title="Buka/tutup breakdown Cabang &amp; Aset untuk semua GL Account sekaligus">
                  Expand/Collapse
                </button>
                <ul class="dropdown-menu dropdown-menu-end" id="glTampilanDropdownMenu">
                  <li>
                    <a class="dropdown-item" href="#" id="glExpandCabangBtn" title="Buka breakdown Cabang &amp; Profit Center untuk SEMUA GL Account (aset masih disembunyikan)">
                      <i></i> Tampilkan Semua Cabang
                    </a>
                  </li>
                  <li>
                    <a class="dropdown-item" href="#" id="glExpandAsetBtn" title="Buka breakdown Cabang & sampai ke nomor Aset untuk SEMUA GL Account">
                      <i></i> Tampilkan Semua Aset
                    </a>
                  </li>
                  <li><hr class="dropdown-divider"></li>
                  <li>
                    <a class="dropdown-item" href="#" id="glCollapseAllBtn" title="Tutup semua breakdown, balik ke tampilan ringkas">
                      <i></i> Tutup Semua
                    </a>
                  </li>
                </ul>
              </div>
              <div style="min-width:220px;">
                <div class="input-group input-group-sm">
                  <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                  <input type="text" class="form-control" id="glSearchInput" placeholder="Cari GL Account / Keterangan...">
                </div>
              </div>
            </div>
          </div>
          <script>
            // Toggle manual (pola sama kayak dropdown Export Excel) -- gak gantung ke komponen
            // Bootstrap Dropdown JS yang mungkin gak ke-bundle di adminlte.js.
            (function () {
              const btn  = document.getElementById('glTampilanDropdownBtn');
              const menu = document.getElementById('glTampilanDropdownMenu');
              if (!btn || !menu) return;
              function closeMenu() { menu.classList.remove('show'); btn.setAttribute('aria-expanded', 'false'); }
              function openMenu() {
                menu.classList.add('show');
                menu.style.position = 'absolute';
                menu.style.inset = '0px auto auto 0px';
                menu.style.margin = '0px';
                menu.style.transform = 'translate(0px, ' + btn.offsetHeight + 'px)';
                btn.setAttribute('aria-expanded', 'true');
              }
              btn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (menu.classList.contains('show')) { closeMenu(); } else { openMenu(); }
              });
              menu.addEventListener('click', function (e) {
                if (e.target.closest('a.dropdown-item')) { closeMenu(); } // nutup begitu salah satu opsi diklik
              });
              document.addEventListener('click', function (e) {
                if (!menu.contains(e.target) && e.target !== btn) { closeMenu(); }
              });
              document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { closeMenu(); }
              });
            })();
          </script>
          <div class="card-body">
            <?php if (empty($listBulan) || empty($pivotData)): ?>
              <p class="text-muted mb-0">Belum ada data untuk tahun ini.</p>
            <?php else: ?>
            <div class="table-responsive tabel-rekap-gl-scroll" style="max-height:620px; overflow-y:auto;">
            <table class="table table-bordered table-sm align-middle mb-0" style="min-width:900px; font-size:.92rem; table-layout:fixed;" id="glRekapTable">
              <colgroup>
                <col style="width:150px;">
                <col id="glKetCol" style="width:260px;">
                <?php foreach ($listBulan as $b): ?>
                  <col style="width:120px;">
                <?php endforeach; ?>
                <col style="width:150px;">
              </colgroup>
              <thead class="table-light" style="position:sticky; top:0; z-index:2;">
                <tr>
                  <th>GL Account</th>
                  <th class="position-relative">Keterangan Aset<span id="glKetResizer" class="gl-col-resizer" title="Geser untuk mengubah lebar kolom"></span></th>
                  <?php foreach ($listBulan as $b): ?>
                    <th class="text-end"><?php echo $namaBulan[$b] ?? $b; ?></th>
                  <?php endforeach; ?>
                  <th class="text-end">Grand Total</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($pivotData as $acc => $perBulan):
                    $rowTotal = 0;
                    $keterangan = $pivotKeterangan[$acc] ?? '-';
                    $accId = 'accdet_' . md5($acc);
                ?>
                <tr class="acc-row" style="cursor:pointer;" data-account="<?php echo htmlspecialchars($acc); ?>" data-target="<?php echo $accId; ?>" data-tahun="<?php echo (int)$tahunFilter; ?>" title="Klik untuk lihat detail Cabang &amp; Profit Center">
                  <td class="fw-semibold text-nowrap"><i class="bi bi-caret-right-fill acc-caret me-1 text-muted" style="font-size:.8em;"></i><?php echo htmlspecialchars($acc); ?></td>
                  <td class="gl-ket-cell" title="<?php echo htmlspecialchars($keterangan); ?>"><?php echo htmlspecialchars($keterangan); ?></td>
                  <?php foreach ($listBulan as $b):
                      $val = $perBulan[$b] ?? null;
                      if ($val !== null) {
                          $rowTotal += $val;
                      }
                  ?>
                    <td class="text-end"><?php echo $val !== null ? fmt_rp($val) : '-'; ?></td>
                  <?php endforeach; ?>
                  <td class="text-end fw-bold"><?php echo fmt_rp($rowTotal); ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot class="table-light">
                <tr>
                  <th>Grand Total</th>
                  <th></th>
                  <?php foreach ($listBulan as $b): ?>
                    <th class="text-end"><?php echo fmt_rp($grandTotalKolom[$b]); ?></th>
                  <?php endforeach; ?>
                  <th class="text-end"><?php echo fmt_rp($grandTotalSemua); ?></th>
                </tr>
              </tfoot>
            </table>
            </div>
            <script>window.__glListBulanCount = <?php echo (int) count($listBulan); ?>;</script>
            <?php endif; ?>
          </div>
        </div>

          </div>
          <!-- ══════════ /TAB: REKAP GL ACCOUNT ══════════ -->

        </div>
        <!-- /tab-content -->


      </div>
    </div>
  </main>
  <!--end::App Main-->
</div>

<script src="../../dist/js/overlayscrollbars.browser.es6.min.js"></script>
<script src="../../dist/js/popper.min.js"></script>
<script src="../../dist/js/adminlte.js"></script>
<script src="../../dist/js/jquery-3.7.1.min.js"></script>
<script src="../../dist/js/dataTables.min.js"></script>
<script src="../../dist/js/dataTables.bootstrap5.min.js"></script>

<script
  src="../../dist/js/apexcharts.min.js"
  integrity="sha256-+vh8GkaU7C9/wbSLIcwq82tQ2wTf44aOHA8HlBMwRI8="
  crossorigin="anonymous"
></script>
<script>
(function () {
  // ── Tab aktif disimpan di localStorage supaya SETELAH REFRESH HALAMAN (F5, submit form
  // filter/tahun yang reload penuh, dsb) tetap di tab yang sama -- gak otomatis balik ke
  // Overview lagi. Anchor khusus "#perbandingan-amount-antar-bulan" (ditulis via history.replaceState
  // sama live-search AJAX di tabel Perbandingan) tetap diprioritaskan kalau ada, karena itu juga
  // menandakan tab Analisis Perbandingan. ──
  const TAB_STORAGE_KEY = 'dasborPenyusutanActiveTab';

  function activateTabById(tabId) {
    const btn = document.getElementById(tabId + '-btn');
    const pane = document.getElementById(tabId);
    if (!btn || !pane) return false;
    document.querySelectorAll('#dasborTab > .nav-item > .nav-link').forEach(function (b) {
      b.classList.remove('active');
      b.setAttribute('aria-selected', 'false');
    });
    document.querySelectorAll('#dasborTabContent > .tab-pane').forEach(function (p) {
      p.classList.remove('show', 'active');
    });
    btn.classList.add('active');
    btn.setAttribute('aria-selected', 'true');
    pane.classList.add('show', 'active');
    return true;
  }

  document.querySelectorAll('#dasborTab > .nav-item > .nav-link[data-bs-target]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      const targetId = btn.getAttribute('data-bs-target').replace('#', '');
      if (activateTabById(targetId)) {
        try { localStorage.setItem(TAB_STORAGE_KEY, targetId); } catch (err) {}
      }
    });
  });

  let sudahDipulihkan = false;
  if (window.location.hash === '#perbandingan-amount-antar-bulan') {
    if (activateTabById('tab-perbandingan')) {
      sudahDipulihkan = true;
      try { localStorage.setItem(TAB_STORAGE_KEY, 'tab-perbandingan'); } catch (err) {}
      const anchorEl = document.getElementById('perbandingan-amount-antar-bulan');
      if (anchorEl) { setTimeout(function () { anchorEl.scrollIntoView(); }, 0); }
    }
  }

  if (!sudahDipulihkan) {
    let savedTabId = null;
    try { savedTabId = localStorage.getItem(TAB_STORAGE_KEY); } catch (err) {}
    if (savedTabId && savedTabId !== 'tab-utama') { activateTabById(savedTabId); }
  }

  // (Catatan: dulu di sini ada listener yang nampilin #globalLoadingOverlay -- overlay
  // full-halaman yang nutupin & bikin "blur" -- tiap klik link sort/pagination di tabel
  // Perbandingan. Itu peninggalan dari jaman sebelum sort/pagination di-AJAX-in (dulu emang
  // reload halaman penuh, jadi overlay-nya wajar). Sekarang klik itu udah ditangkep & diproses
  // via AJAX [lihat blok "Live-search + sort/pagination/ganti-bulan" di bawah], TAPI listener
  // lama itu tetap nempel & nembak overlay-nya juga di klik yang sama -- dan overlay itu gak
  // pernah ditutup lagi karena halamannya emang gak reload. Makanya kerasa "lama"/"ke-blur".
  // Listener lama itu udah dihapus dari sini -- indikator loading buat sort/pagination/ganti
  // bulan sekarang cukup spinner kecil #anomLoadingSpinner di judul card, gak nutupin apapun.) ──
})();


  // ── Simpan Catatan per baris (dipanggil ulang tiap fragment tabel Perbandingan di-refresh via
  // AJAX, karena baris barunya gak otomatis kebawa listener yang dipasang cuma sekali di awal). ──
  function bindCatatanInputs(scope) {
    (scope || document).querySelectorAll('.catatan-input').forEach(function (inp) {
      if (inp.dataset.bound === '1') return; // jangan pasang listener dobel kalau elemennya kepanggil lagi
      inp.dataset.bound = '1';
      const btn = inp.closest('.input-group').querySelector('.catatan-save-btn');
      let orig = inp.value;

      function saveCatatan() {
        if (inp.value === orig) return;
        const fd = new FormData();
        fd.append('action', 'save_catatan');
        fd.append('tahun', inp.dataset.tahun);
        fd.append('bulan_a', inp.dataset.bulanA);
        fd.append('bulan_b', inp.dataset.bulanB);
        fd.append('cost_center', inp.dataset.costCenter);
        fd.append('asset', inp.dataset.asset);
        fd.append('asset_subnumber', inp.dataset.assetSubnumber);
        fd.append('account', inp.dataset.account);
        fd.append('profit_center', inp.dataset.profitCenter);
        fd.append('catatan', inp.value);
        inp.disabled = true;
        btn.disabled = true;
        fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            inp.disabled = false;
            if (res && res.ok) {
              orig = inp.value;
              inp.classList.remove('is-invalid');
              inp.classList.add('is-valid');
              btn.disabled = true;
              setTimeout(function () { inp.classList.remove('is-valid'); }, 1200);
            } else {
              inp.classList.add('is-invalid');
              btn.disabled = false;
            }
          })
          .catch(function () {
            inp.disabled = false;
            inp.classList.add('is-invalid');
            btn.disabled = false;
          });
      }

      // Tombol OK cuma aktif kalau ada perubahan dari yang tersimpan
      inp.addEventListener('input', function () {
        btn.disabled = (inp.value === orig);
      });
      btn.addEventListener('click', saveCatatan);
      // Simpan juga saat tekan Enter
      inp.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); saveCatatan(); }
      });
    });
  }

  function bindKategoriSelects(scope) {
    (scope || document).querySelectorAll('.kategori-select').forEach(function (sel) {
      if (sel.dataset.bound === '1') return;
      sel.dataset.bound = '1';
      sel.addEventListener('change', function () {
        const fd = new FormData();
        fd.append('action', 'save_kategori_override');
        fd.append('tahun', sel.dataset.tahun);
        fd.append('bulan_a', sel.dataset.bulanA);
        fd.append('bulan_b', sel.dataset.bulanB);
        fd.append('cost_center', sel.dataset.costCenter);
        fd.append('asset', sel.dataset.asset);
        fd.append('asset_subnumber', sel.dataset.assetSubnumber);
        fd.append('account', sel.dataset.account);
        fd.append('profit_center', sel.dataset.profitCenter);
        fd.append('kategori_asli', sel.dataset.kategoriAsli);
        fd.append('kategori_manual', sel.value);
        sel.disabled = true;
        fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            sel.disabled = false;
            if (res && res.ok) {
              sel.classList.toggle('border-warning', sel.value !== 'auto');
              const pencilIcon = sel.closest('td').querySelector('.bi-pencil-fill');
              if (sel.value === 'auto') {
                if (pencilIcon) { pencilIcon.remove(); }
              } else if (!pencilIcon) {
                const infoIcon = sel.closest('td').querySelector('.anom-status-badge');
                if (infoIcon) {
                  const ic = document.createElement('i');
                  ic.className = 'bi bi-pencil-fill text-warning';
                  ic.style.fontSize = '.75em';
                  ic.title = 'Sudah dikoreksi manual (otomatis: ' + sel.dataset.kategoriAsli + ')';
                  infoIcon.after(ic);
                }
              }
            } else {
              sel.classList.add('is-invalid');
              setTimeout(function () { sel.classList.remove('is-invalid'); }, 1500);
            }
          })
          .catch(function () {
            sel.disabled = false;
            sel.classList.add('is-invalid');
            setTimeout(function () { sel.classList.remove('is-invalid'); }, 1500);
          });
      });
    });
  }

document.addEventListener('DOMContentLoaded', function () {

  bindCatatanInputs();
  bindKategoriSelects();

  // ── Popover ringan untuk keterangan kategori perubahan (Perbandingan Amount Antar Bulan) ──
  (function () {
    const pop = document.getElementById('anomPopover');
    if (!pop) return;
    document.body.appendChild(pop); 
    const titleEl = document.getElementById('anomPopoverTitle');
    const bodyEl  = document.getElementById('anomPopoverBody');
    const closeBtn = document.getElementById('anomPopoverClose');

    function hidePop() { pop.style.display = 'none'; }
    if (closeBtn) closeBtn.addEventListener('click', function (e) { e.stopPropagation(); hidePop(); });

    document.addEventListener('click', function (e) {
      const badge = e.target.closest('.anom-status-badge');
      if (badge) {
        titleEl.textContent = badge.dataset.kategori || '';
        bodyEl.textContent  = badge.dataset.ket || '';
        pop.style.display = 'block'; 

        const rect = badge.getBoundingClientRect();
        const popW = pop.offsetWidth;
        const popH = pop.offsetHeight;
        const margin = 8;

        let top = rect.bottom + 6;
        let left = rect.left;

        // Kalau kepotong bawah, taruh di atas badge
        if (top + popH > window.innerHeight - margin) {
          top = rect.top - popH - 6;
          if (top < margin) top = margin; // fallback kalau tetep gak muat
        }
        // Kalau kepotong kanan, geser ke kiri
        if (left + popW > window.innerWidth - margin) {
          left = window.innerWidth - popW - margin;
        }
        if (left < margin) left = margin;

        pop.style.top  = top + 'px';
        pop.style.left = left + 'px';
        e.stopPropagation();
      } else if (!e.target.closest('#anomPopover')) {
        hidePop();
      }
    });
    window.addEventListener('scroll', hidePop, true);
    window.addEventListener('resize', hidePop);
  })();

  (function () {
    const container = document.getElementById('anomFragmentContainer');
    const form = document.getElementById('formFilterAnomBulan');
    if (!container || !form) return;

    const searchInput = form.querySelector('#cariAnomInput');
    const clearBtn    = document.getElementById('cariAnomClearBtn');
    const loadingSpinner = document.getElementById('anomLoadingSpinner');
    const selA = document.getElementById('bulanAnomASelect');
    const selB = document.getElementById('bulanAnomBSelect');

    const KATEGORI_OPTIONS = Array.isArray(window.__anomKategoriOptions) ? window.__anomKategoriOptions : [];
    const NAMA_BULAN = window.__anomNamaBulan || {};
    const TAHUN = form.querySelector('input[name="tahun"]').value;
    const PER_HALAMAN = 10;

    let allRows = Array.isArray(window.__anomInitialRows) ? window.__anomInitialRows : [];
    let bulanA = <?php echo json_encode($bulanAnomA); ?>;
    let bulanB = <?php echo json_encode($bulanAnomB); ?>;

    const state = { sort_by: '', sort_dir: 'asc', halaman: 1, cari: '' };
    state.cari      = searchInput ? searchInput.value : '';
    state.sort_by   = <?php echo json_encode($sortByAnom); ?>;
    state.sort_dir  = <?php echo json_encode($sortDirAnom); ?>;
    state.halaman   = <?php echo (int)$halamanAnom; ?>;

    function escHtml(s) {
      const d = document.createElement('div');
      d.textContent = (s === null || s === undefined) ? '' : String(s);
      return d.innerHTML;
    }
    function fmtRp(n) { return new Intl.NumberFormat('id-ID').format(Math.round(n || 0)); }
    function fmtPersen(n) { return n.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }); }

    function setLoading(loading) {
      if (loadingSpinner) { loadingSpinner.classList.toggle('d-none', !loading); }
    }

    function matchesSearch(ar, q) {
      if (!q) return true;
      const hay = [ar.cost_center, ar.asset, ar.asset_subnumber, ar.account, ar.keterangan_asset,
                   ar.profit_center, ar.status, ar.kategori, ar.catatan].join(' ').toLocaleLowerCase('id-ID');
      return hay.indexOf(q) !== -1;
    }

    function sortRows(rows) {
      if (state.sort_by === '') {
        // Default: selisih paling mencolok (absolut) duluan -- sama kayak default urutan PHP.
        return rows.slice().sort(function (x, y) { return Math.abs(y.selisih || 0) - Math.abs(x.selisih || 0); });
      }
      const by = state.sort_by, dir = state.sort_dir;
      const numeric = ['nilai_a', 'nilai_b', 'selisih', 'persen'].indexOf(by) !== -1;
      const arr = rows.slice();
      arr.sort(function (x, y) {
        const vx = x[by], vy = y[by];
        let cmp;
        if (numeric) {
          if (vx === null && vy === null) cmp = 0;
          else if (vx === null) cmp = 1;
          else if (vy === null) cmp = -1;
          else cmp = vx - vy;
        } else {
          cmp = String(vx || '').localeCompare(String(vy || ''));
        }
        return dir === 'desc' ? -cmp : cmp;
      });
      return arr;
    }

    function statusBadgeClass(status) {
      return status === 'Naik' ? 'badge-naik' : (status === 'Turun' ? 'badge-turun' : (status === 'Tetap' ? 'badge-tetap' : 'badge-nodat'));
    }

    function renderRow(ar) {
      const cls = statusBadgeClass(ar.status);
      const labelOtomatis = ar.kategori_otomatis || ar.status;
      const popTitle = ar.kategori || ar.status;
      const popBody = ar.ket_detail || 'Tidak ada perubahan kategori khusus yang terdeteksi dari data DAT untuk perubahan ini (kemungkinan penyusutan berjalan normal).';

      let opts = '<option value="auto"' + (!ar.kategori_manual ? ' selected' : '') + '>' + escHtml(labelOtomatis) + '</option>';
      KATEGORI_OPTIONS.forEach(function (k) {
        opts += '<option value="' + escHtml(k) + '"' + ((ar.kategori_manual && ar.kategori === k) ? ' selected' : '') + '>' + escHtml(k) + '</option>';
      });

      const selCls = ar.selisih === null ? '' : (ar.selisih > 0 ? 'text-success fw-semibold' : (ar.selisih < 0 ? 'text-danger fw-semibold' : ''));
      const perCls = ar.persen === null ? '' : (ar.persen > 0 ? 'text-success fw-semibold' : (ar.persen < 0 ? 'text-danger fw-semibold' : ''));

      let html = '<tr>';
      html += '<td>' + escHtml(ar.cost_center) + '</td>';
      html += '<td>' + escHtml(ar.asset) + '</td>';
      html += '<td>' + escHtml(ar.asset_subnumber) + '</td>';
      html += '<td>' + escHtml(ar.account) + '</td>';
      html += '<td>' + escHtml(ar.keterangan_asset || '-') + '</td>';
      html += '<td>' + escHtml(ar.profit_center) + '</td>';
      html += '<td class="text-end">' + (ar.nilai_a !== null ? fmtRp(ar.nilai_a) : '-') + '</td>';
      html += '<td class="text-end">' + (ar.nilai_b !== null ? fmtRp(ar.nilai_b) : '-') + '</td>';
      html += '<td class="text-end ' + selCls + '">' + (ar.selisih === null ? '-' : ((ar.selisih > 0 ? '+' : '') + fmtRp(ar.selisih))) + '</td>';
      html += '<td class="text-end ' + perCls + '">' + (ar.persen === null ? '-' : ((ar.persen > 0 ? '+' : '') + fmtPersen(ar.persen) + '%')) + '</td>';
      html += '<td class="text-center" style="min-width:240px;">';
      html += '<div class="d-flex align-items-center justify-content-center gap-1">';
      html += '<select class="form-select form-select-sm kategori-select ' + cls + (ar.kategori_manual ? ' border-warning' : '') + '" style="font-size:.72rem;"' +
              ' data-tahun="' + TAHUN + '" data-bulan-a="' + bulanA + '" data-bulan-b="' + bulanB + '"' +
              ' data-cost-center="' + escHtml(ar.cost_center) + '" data-asset="' + escHtml(ar.asset) + '" data-asset-subnumber="' + escHtml(ar.asset_subnumber) + '"' +
              ' data-account="' + escHtml(ar.account) + '" data-profit-center="' + escHtml(ar.profit_center) + '"' +
              ' data-kategori-asli="' + escHtml(labelOtomatis) + '" data-status-class="' + cls + '"' +
              ' title="' + escHtml(labelOtomatis) + ' — kalau pola deteksi otomatis salah, ganti kategori di sini. Pilih pilihan paling atas lagi untuk membatalkan koreksi.">';
      html += opts + '</select>';
      html += '<i class="bi bi-info-circle anom-status-badge text-muted" style="cursor:pointer;" data-kategori="' + escHtml(popTitle) + '" data-ket="' + escHtml(popBody) + '"></i>';
      if (ar.kategori_manual) {
        html += '<i class="bi bi-pencil-fill text-warning" style="font-size:.75em;" title="Sudah dikoreksi manual (otomatis: ' + escHtml(labelOtomatis) + ')"></i>';
      }
      html += '</div></td>';
      html += '<td><div class="input-group input-group-sm" style="min-width:200px;">';
      html += '<input type="text" class="form-control form-control-sm catatan-input"' +
              ' data-tahun="' + TAHUN + '" data-bulan-a="' + bulanA + '" data-bulan-b="' + bulanB + '"' +
              ' data-cost-center="' + escHtml(ar.cost_center) + '" data-asset="' + escHtml(ar.asset) + '" data-asset-subnumber="' + escHtml(ar.asset_subnumber) + '"' +
              ' data-account="' + escHtml(ar.account) + '" data-profit-center="' + escHtml(ar.profit_center) + '"' +
              ' value="' + escHtml(ar.catatan || '') + '" placeholder="Tulis catatan...">';
      html += '<button type="button" class="btn btn-primary catatan-save-btn" disabled title="Simpan catatan">OK</button>';
      html += '</div></td></tr>';
      return html;
    }

    function sortLinkHtml(col, label) {
      const active = state.sort_by === col;
      const icon = active
        ? (state.sort_dir === 'asc' ? ' <i class="bi bi-caret-up-fill"></i>' : ' <i class="bi bi-caret-down-fill"></i>')
        : ' <i class="bi bi-arrow-down-up text-muted" style="font-size:.7em;"></i>';
      return '<a href="#" class="text-decoration-none text-dark anom-sort-link" data-col="' + col + '">' + escHtml(label) + '</a>' + icon;
    }

    function buildPaginationHtml(halaman, totalHalaman) {
      const sekitar = 1;
      const items = [];
      for (let p = 1; p <= totalHalaman; p++) {
        if (p === 1 || p === totalHalaman || (p >= halaman - sekitar && p <= halaman + sekitar)) {
          items.push(p);
        } else if (items[items.length - 1] !== '...') {
          items.push('...');
        }
      }
      let html = '<nav><ul class="pagination pagination-sm flex-wrap mb-0 mt-2">';
      html += '<li class="page-item ' + (halaman <= 1 ? 'disabled' : '') + '"><a class="page-link anom-page-link" href="#" data-page="1" title="Halaman pertama">&laquo;&laquo;</a></li>';
      html += '<li class="page-item ' + (halaman <= 1 ? 'disabled' : '') + '"><a class="page-link anom-page-link" href="#" data-page="' + Math.max(1, halaman - 1) + '">&laquo; Sebelumnya</a></li>';
      items.forEach(function (it) {
        if (it === '...') { html += '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>'; }
        else { html += '<li class="page-item ' + (it === halaman ? 'active' : '') + '"><a class="page-link anom-page-link" href="#" data-page="' + it + '">' + it + '</a></li>'; }
      });
      html += '<li class="page-item ' + (halaman >= totalHalaman ? 'disabled' : '') + '"><a class="page-link anom-page-link" href="#" data-page="' + Math.min(totalHalaman, halaman + 1) + '">Selanjutnya &raquo;</a></li>';
      html += '<li class="page-item ' + (halaman >= totalHalaman ? 'disabled' : '') + '"><a class="page-link anom-page-link" href="#" data-page="' + totalHalaman + '" title="Halaman terakhir">&raquo;&raquo;</a></li>';
      html += '</ul></nav>';
      return html;
    }

    function render() {
      if (bulanA === null || bulanB === null) {
        container.innerHTML = '<p class="text-muted mb-0">Belum ada cukup data bulan di tahun ini untuk dibandingkan.</p>';
        return;
      }
      const q = state.cari.trim().toLocaleLowerCase('id-ID');
      const filtered = allRows.filter(function (ar) { return matchesSearch(ar, q); });
      const namaA = NAMA_BULAN[bulanA] || bulanA;
      const namaB = NAMA_BULAN[bulanB] || bulanB;

      if (filtered.length === 0) {
        let html = '<p class="text-muted mb-0">Tidak ada aset yang berbeda antara ' + escHtml(namaA) + ' dan ' + escHtml(namaB) + '.</p>';
        if (q) { html += '<p class="text-muted small mb-0">(pencarian "' + escHtml(state.cari) + '" tidak ketemu)</p>'; }
        container.innerHTML = html;
        return;
      }

      const sorted = sortRows(filtered);
      const totalHalaman = Math.max(1, Math.ceil(sorted.length / PER_HALAMAN));
      if (state.halaman > totalHalaman) { state.halaman = totalHalaman; }
      const startIdx = (state.halaman - 1) * PER_HALAMAN;
      const pageRows = sorted.slice(startIdx, startIdx + PER_HALAMAN);

      let html = '<small class="text-muted d-block mb-2">Menampilkan ' + pageRows.length + ' dari ' + sorted.length + ' aset';
      if (q) { html += ' (hasil pencarian "<strong>' + escHtml(state.cari) + '</strong>")'; }
      html += '</small>';
      html += '<div class="table-responsive"><table class="table table-bordered align-middle mb-2 w-100 tabel-perbandingan" style="table-layout:auto; font-size:.8rem;">';
      html += '<thead class="table-light"><tr>';
      html += '<th>' + sortLinkHtml('cost_center', 'Cost Center') + '</th>';
      html += '<th>' + sortLinkHtml('asset', 'Nomor Aset') + '</th>';
      html += '<th>' + sortLinkHtml('asset_subnumber', 'Sub') + '</th>';
      html += '<th>' + sortLinkHtml('account', 'Account') + '</th>';
      html += '<th>Keterangan Aset</th>';
      html += '<th>' + sortLinkHtml('profit_center', 'Profit Center') + '</th>';
      html += '<th class="text-end">' + sortLinkHtml('nilai_a', 'Nilai ' + namaA) + '</th>';
      html += '<th class="text-end">' + sortLinkHtml('nilai_b', 'Nilai ' + namaB) + '</th>';
      html += '<th class="text-end">' + sortLinkHtml('selisih', 'Selisih') + '</th>';
      html += '<th class="text-end">' + sortLinkHtml('persen', '%') + '</th>';
      html += '<th class="text-center">' + sortLinkHtml('status', 'Status') + '</th>';
      html += '<th>Catatan</th>';
      html += '</tr></thead><tbody>';
      pageRows.forEach(function (ar) { html += renderRow(ar); });
      html += '</tbody></table></div>';
      if (totalHalaman > 1) { html += buildPaginationHtml(state.halaman, totalHalaman); }

      container.innerHTML = html;
      bindCatatanInputs(container);
      bindKategoriSelects(container);
    }

    // ── Ngetik di kotak Cari -> langsung kefilter, instan, gak ada fetch ke server sama sekali ──
    if (searchInput) {
      searchInput.addEventListener('input', function () {
        if (clearBtn) { clearBtn.classList.toggle('d-none', searchInput.value === ''); }
        state.cari = searchInput.value;
        state.halaman = 1;
        render();
      });
    }
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        if (searchInput) { searchInput.value = ''; }
        clearBtn.classList.add('d-none');
        state.cari = '';
        state.halaman = 1;
        render();
      });
    }

    // ── Klik header sort / nomor halaman -> instan juga, di data yang udah ada ──
    container.addEventListener('click', function (e) {
      const sortA = e.target.closest('.anom-sort-link');
      if (sortA) {
        e.preventDefault();
        const col = sortA.dataset.col;
        if (state.sort_by === col) { state.sort_dir = state.sort_dir === 'asc' ? 'desc' : 'asc'; }
        else { state.sort_by = col; state.sort_dir = 'asc'; }
        render();
        return;
      }
      const pageA = e.target.closest('.anom-page-link');
      if (pageA) {
        e.preventDefault();
        state.halaman = parseInt(pageA.dataset.page, 10);
        render();
      }
    });

    let abortCtrl = null;
    // ── SATU-SATUNYA yang beneran perlu ke server: ganti Bulan Pertama/Kedua (data buat pasangan
    // bulan baru emang belum ada di browser). Selain ini semua full client-side (instan). ──
    function loadNewBulanPair() {
      if (abortCtrl) { abortCtrl.abort(); }
      abortCtrl = new AbortController();
      setLoading(true);
      const fd = new FormData();
      fd.append('action', 'get_perbandingan_data_full');
      fd.append('tahun', TAHUN);
      fd.append('bulan_a', selA.value);
      fd.append('bulan_b', selB.value);
      fetch(window.location.pathname, { method: 'POST', body: fd, signal: abortCtrl.signal })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res || !res.ok) return;
          allRows = res.rows || [];
          bulanA = res.bulan_a;
          bulanB = res.bulan_b;
          state.halaman = 1;
          render();
          // Update URL bar & link Export Excel biar tetap konsisten sama bulan yang lagi dipilih.
          const params = new URLSearchParams(new FormData(form));
          history.replaceState(null, '', window.location.pathname + '?' + params.toString() + '#perbandingan-amount-antar-bulan');
          document.querySelectorAll('#exportExcelBtnGroup a[data-export-link]').forEach(function (a) {
            try {
              const u = new URL(a.href, window.location.origin);
              u.searchParams.set('bulan_a', bulanA);
              u.searchParams.set('bulan_b', bulanB);
              a.href = u.pathname + u.search;
            } catch (e) { /* biarin apa adanya */ }
          });
        })
        .catch(function (err) { if (err && err.name === 'AbortError') return; })
        .finally(function () { setLoading(false); });
    }
    if (selA) { selA.addEventListener('change', loadNewBulanPair); }
    if (selB) { selB.addEventListener('change', loadNewBulanPair); }

    // ── Render awal dari data yang udah di-embed PHP -- gak perlu fetch apapun buat load pertama. ──
    render();
  })();

  (function () {
    function escHtml(s) {
      const d = document.createElement('div');
      d.textContent = (s === null || s === undefined) ? '' : String(s);
      return d.innerHTML;
    }
    function fmtRpJs(n) {
      return new Intl.NumberFormat('id-ID').format(Math.round(n || 0));
    }

    const colCount = 3 + (window.__glListBulanCount || 0);
    const table = document.getElementById('glRekapTable');

    // Cache Promise per accId, biar tombol "Expand Semua" & klik manual baris gak nge-fetch ULANG
    // kalau breakdown-nya udah pernah/lagi dimuat sebelumnya (dari klik baris manual ATAU dari
    // Expand Semua yang lain) -- cukup 1x fetch per GL Account, sisanya tinggal show/hide.
    const loadPromises = {};

    function renderDetailHtml(row, accId, res) {
      const bulanList = res.bulan || [];

      // ── Kelompokkan per Cabang + Profit Center. ──
      const groupsMap = {};
      const groupsOrder = [];
      res.rows.forEach(function (rr) {
        const key = rr.cabang + '||' + rr.profit_center;
        if (!groupsMap[key]) {
          const perBulanG = {};
          bulanList.forEach(function (bl) { perBulanG[bl.no] = 0; });
          groupsMap[key] = { cabang: rr.cabang, profit_center: rr.profit_center, items: [], per_bulan: perBulanG, total: 0 };
          groupsOrder.push(key);
        }
        const g = groupsMap[key];
        g.items.push(rr);
        g.total += rr.total;
        bulanList.forEach(function (bl) {
          const v = (rr.per_bulan && rr.per_bulan[bl.no] !== undefined) ? rr.per_bulan[bl.no] : 0;
          g.per_bulan[bl.no] += v;
        });
      });
      groupsOrder.sort(function (ka, kb) { return groupsMap[kb].total - groupsMap[ka].total; });

      let html = '';
      groupsOrder.forEach(function (key, idx) {
        const g = groupsMap[key];
        const groupId = accId + '__g' + idx;
        const cabangLabelFull = g.cabang + (g.profit_center ? ' - ' + g.profit_center : '') + ' (' + g.items.length + ' aset)';
        // Baris Cabang: bisa diklik sendiri buat buka/tutup daftar Aset di bawahnya (level 2) --
        // caret ke-2 (.cab-caret) nunjukin status buka/tutup level ini, terpisah dari caret GL Account.
        html += '<tr class="accchild-' + accId + ' gl-cabang-row" data-cabgroup="' + groupId + '" title="Klik untuk buka/tutup daftar aset">' +
                '<td colspan="2" class="gl-ket-cell" title="' + escHtml(cabangLabelFull) + '">' +
                '<i class="bi bi-caret-right-fill cab-caret me-1" style="font-size:.7em;"></i>' +
                '<i class="bi bi-building me-1"></i>' +
                escHtml(g.cabang) + (g.profit_center ? ' <span class="fw-normal">- ' + escHtml(g.profit_center) + '</span>' : '') +
                ' <span class="fw-normal opacity-75">(' + g.items.length + ' aset)</span></td>';
        bulanList.forEach(function (bl) {
          html += '<td class="text-end">' + fmtRpJs(g.per_bulan[bl.no]) + '</td>';
        });
        html += '<td class="text-end">' + fmtRpJs(g.total) + '</td></tr>';

        g.items.forEach(function (rr) {
          const noAset = escHtml(rr.nomor_aset) + (rr.sub_number ? '-' + escHtml(rr.sub_number) : '');
          // Baris Aset: default TERTUTUP (d-none) -- baru kelihatan kalau baris Cabang di atasnya
          // diklik, atau lewat tombol "Expand Semua Aset".
          html += '<tr class="accchild-' + accId + ' gl-asset-row d-none cabgroup-' + groupId + '">' +
                  '<td class="text-nowrap">' + noAset + '</td>' +
                  '<td class="gl-ket-cell" title="' + escHtml(rr.keterangan_asset) + '">' + escHtml(rr.keterangan_asset) + '</td>';
          bulanList.forEach(function (bl) {
            const v = (rr.per_bulan && rr.per_bulan[bl.no] !== undefined) ? rr.per_bulan[bl.no] : 0;
            html += '<td class="text-end">' + fmtRpJs(v) + '</td>';
          });
          html += '<td class="text-end">' + fmtRpJs(rr.total) + '</td></tr>';
        });
      });

      html += '<tr class="accchild-' + accId + ' gl-subtotal-row fw-bold"><td colspan="2">Subtotal ' + escHtml(row.dataset.account) + '</td>';
      bulanList.forEach(function (bl) {
        const gv = (res.grand_total_bulan && res.grand_total_bulan[bl.no] !== undefined) ? res.grand_total_bulan[bl.no] : 0;
        html += '<td class="text-end">' + fmtRpJs(gv) + '</td>';
      });
      html += '<td class="text-end">' + fmtRpJs(res.grand_total) + '</td></tr>';
      return html;
    }

    function ensureLoaded(row) {
      const accId = row.dataset.target;
      if (loadPromises[accId]) return loadPromises[accId];

      const childSelector = 'tr.accchild-' + accId;
      const loadingHtml = '<tr class="accchild-' + accId + '"><td colspan="' + colCount + '" class="text-center text-muted small py-2 bg-light">Memuat detail...</td></tr>';
      row.insertAdjacentHTML('afterend', loadingHtml);

      const fd = new FormData();
      fd.append('action', 'get_account_detail');
      fd.append('account', row.dataset.account);
      fd.append('tahun', row.dataset.tahun);

      loadPromises[accId] = fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          document.querySelectorAll(childSelector).forEach(function (tr2) { tr2.remove(); });

          if (!res || !res.ok) {
            row.insertAdjacentHTML('afterend', '<tr class="accchild-' + accId + '"><td colspan="' + colCount + '" class="text-center text-danger small py-2 bg-light">Gagal memuat detail' + (res && res.error ? ': ' + escHtml(res.error) : '') + '.</td></tr>');
            return false;
          }
          if (!res.rows || res.rows.length === 0) {
            row.insertAdjacentHTML('afterend', '<tr class="accchild-' + accId + '"><td colspan="' + colCount + '" class="text-center text-muted small py-2 bg-light">Tidak ada data detail untuk account ini.</td></tr>');
            return false;
          }
          row.insertAdjacentHTML('afterend', renderDetailHtml(row, accId, res));
          return true;
        })
        .catch(function () {
          document.querySelectorAll(childSelector).forEach(function (tr2) { tr2.remove(); });
          row.insertAdjacentHTML('afterend', '<tr class="accchild-' + accId + '"><td colspan="' + colCount + '" class="text-center text-danger small py-2 bg-light">Gagal memuat detail (koneksi bermasalah).</td></tr>');
          return false;
        });
      return loadPromises[accId];
    }

    function setAccCaretOpen(row, open) {
      const caret = row.querySelector('.acc-caret');
      if (caret) {
        caret.classList.toggle('bi-caret-right-fill', !open);
        caret.classList.toggle('bi-caret-down-fill', open);
      }
    }
    function showCabangRows(accId, show) {
      document.querySelectorAll('tr.accchild-' + accId + '.gl-cabang-row, tr.accchild-' + accId + '.gl-subtotal-row').forEach(function (tr2) {
        tr2.classList.toggle('d-none', !show);
      });
    }
    function showAssetRows(accId, show) {
      document.querySelectorAll('tr.accchild-' + accId + '.gl-asset-row').forEach(function (tr2) {
        tr2.classList.toggle('d-none', !show);
      });
      document.querySelectorAll('tr.accchild-' + accId + '.gl-cabang-row .cab-caret').forEach(function (ic) {
        ic.classList.toggle('bi-caret-right-fill', !show);
        ic.classList.toggle('bi-caret-down-fill', show);
      });
    }

    // ── Klik baris GL Account -> buka/tutup breakdown Cabang (level 1). Aset (level 2) tetap
    // tersembunyi sampai baris Cabang-nya sendiri diklik (lihat delegasi klik di bawah). ──
    document.querySelectorAll('.acc-row').forEach(function (row) {
      row.addEventListener('click', function () {
        const accId = row.dataset.target;
        const existing = document.querySelectorAll('tr.accchild-' + accId + '.gl-cabang-row, tr.accchild-' + accId + '.gl-subtotal-row');

        if (existing.length > 0) {
          const sedangTersembunyi = existing[0].classList.contains('d-none');
          showCabangRows(accId, sedangTersembunyi);
          if (!sedangTersembunyi) { showAssetRows(accId, false); } // baru ditutup -> aset ikut disembunyikan lagi
          setAccCaretOpen(row, sedangTersembunyi);
          return;
        }

        setAccCaretOpen(row, true);
        ensureLoaded(row);
      });
    });

    // ── Klik baris Cabang -> buka/tutup daftar Aset di bawahnya (level 2). Pakai delegasi karena
    // baris Cabang di-insert belakangan lewat JS (gak ada dari awal render PHP). ──
    if (table) {
      table.addEventListener('click', function (e) {
        const cabRow = e.target.closest('.gl-cabang-row');
        if (!cabRow || !table.contains(cabRow)) return;
        const groupId = cabRow.dataset.cabgroup;
        if (!groupId) return;
        const items = document.querySelectorAll('tr.cabgroup-' + groupId);
        if (!items.length) return;
        const sedangTersembunyi = items[0].classList.contains('d-none');
        items.forEach(function (tr2) { tr2.classList.toggle('d-none', !sedangTersembunyi); });
        const caret = cabRow.querySelector('.cab-caret');
        if (caret) {
          caret.classList.toggle('bi-caret-right-fill', !sedangTersembunyi);
          caret.classList.toggle('bi-caret-down-fill', sedangTersembunyi);
        }
      });
    }

    // ── Tombol "Expand Semua Cabang" / "Expand Semua Aset" / "Tutup Semua" di atas tabel --
    // biar gak perlu klik baris GL Account satu-satu buat lihat breakdown semuanya sekaligus. ──
    function allAccRows() { return Array.from(document.querySelectorAll('.acc-row')); }

    function expandAllTo(showAsset) {
      const rows = allAccRows();
      return Promise.all(rows.map(function (row) {
        const accId = row.dataset.target;
        setAccCaretOpen(row, true);
        return ensureLoaded(row).then(function (ok) {
          if (!ok) return;
          showCabangRows(accId, true);
          showAssetRows(accId, showAsset);
        });
      }));
    }

    const btnExpandCabang = document.getElementById('glExpandCabangBtn');
    const btnExpandAset   = document.getElementById('glExpandAsetBtn');
    const btnCollapseAll  = document.getElementById('glCollapseAllBtn');

    function setExpandButtonsBusy(busy) {
      [btnExpandCabang, btnExpandAset].forEach(function (b) {
        if (b) { b.classList.toggle('disabled', busy); b.style.pointerEvents = busy ? 'none' : ''; }
      });
    }

    if (btnExpandCabang) {
      btnExpandCabang.addEventListener('click', function (e) {
        e.preventDefault();
        setExpandButtonsBusy(true);
        expandAllTo(false).finally(function () { setExpandButtonsBusy(false); });
      });
    }
    if (btnExpandAset) {
      btnExpandAset.addEventListener('click', function (e) {
        e.preventDefault();
        setExpandButtonsBusy(true);
        expandAllTo(true).finally(function () { setExpandButtonsBusy(false); });
      });
    }
    if (btnCollapseAll) {
      btnCollapseAll.addEventListener('click', function (e) {
        e.preventDefault();
        allAccRows().forEach(function (row) {
          const accId = row.dataset.target;
          showCabangRows(accId, false);
          showAssetRows(accId, false);
          setAccCaretOpen(row, false);
        });
      });
    }
  })();

  (function () {
    const searchInput = document.getElementById('glSearchInput');
    const table = document.getElementById('glRekapTable');
    if (!searchInput || !table) return;

    searchInput.addEventListener('input', function () {
      const q = searchInput.value.trim().toLocaleLowerCase('id-ID');
      table.querySelectorAll('tbody > tr.acc-row').forEach(function (row) {
        const glText = (row.children[0] ? row.children[0].textContent : '').toLocaleLowerCase('id-ID');
        const ketText = (row.children[1] ? row.children[1].textContent : '').toLocaleLowerCase('id-ID');
        const match = q === '' || glText.indexOf(q) !== -1 || ketText.indexOf(q) !== -1;
        row.classList.toggle('d-none', !match);

        const children = table.querySelectorAll('tbody > tr.accchild-' + row.dataset.target);
        if (children.length && !match) {
          children.forEach(function (c) { c.classList.add('d-none'); });
          const caret = row.querySelector('.acc-caret');
          if (caret) { caret.classList.remove('bi-caret-down-fill'); caret.classList.add('bi-caret-right-fill'); }
        }
      });
    });
  })();

  (function () {
    const resizer = document.getElementById('glKetResizer');
    const ketCol = document.getElementById('glKetCol');
    if (!resizer || !ketCol) return;

    const MIN_WIDTH = 100;
    const MAX_WIDTH = 700;
    let startX = 0;
    let startWidth = 0;

    function onMouseMove(e) {
      const clientX = e.touches ? e.touches[0].clientX : e.clientX;
      const delta = clientX - startX;
      let newWidth = startWidth + delta;
      newWidth = Math.max(MIN_WIDTH, Math.min(MAX_WIDTH, newWidth));
      ketCol.style.width = newWidth + 'px';
    }
    function onMouseUp() {
      resizer.classList.remove('resizing');
      document.removeEventListener('mousemove', onMouseMove);
      document.removeEventListener('mouseup', onMouseUp);
      document.removeEventListener('touchmove', onMouseMove);
      document.removeEventListener('touchend', onMouseUp);
      document.body.style.userSelect = '';
      document.body.style.cursor = '';
    }
    function onMouseDown(e) {
      e.preventDefault();
      startX = e.touches ? e.touches[0].clientX : e.clientX;
      startWidth = ketCol.getBoundingClientRect().width;
      resizer.classList.add('resizing');
      document.body.style.userSelect = 'none';
      document.body.style.cursor = 'col-resize';
      document.addEventListener('mousemove', onMouseMove);
      document.addEventListener('mouseup', onMouseUp);
      document.addEventListener('touchmove', onMouseMove, { passive: false });
      document.addEventListener('touchend', onMouseUp);
    }
    resizer.addEventListener('mousedown', onMouseDown);
    resizer.addEventListener('touchstart', onMouseDown, { passive: false });
  })();

  // ── Ringkasan GL Account (list)
  (function () {
    const glPivotData  = <?php echo json_encode($pivotData); ?>;
    const glCardData   = {};
    const selAwal  = document.getElementById('glListBulanAwal');
    const selAkhir = document.getElementById('glListBulanAkhir');

    function escHtmlGl(s) {
      const d = document.createElement('div');
      d.textContent = (s === null || s === undefined) ? '' : String(s);
      return d.innerHTML;
    }
    function fmtRpGl(n) {
      const v = Math.round(n || 0);
      return (v < 0 ? '-' : '') + new Intl.NumberFormat('id-ID').format(Math.abs(v));
    }

    function itemsTableSkeleton(items, uid, totalLabel, totalValue) {
      if (!items || !items.length) {
        return '<div class="text-muted small">Tidak ada aset pada kategori ini untuk periode yang dipilih.</div>';
      }
      let html = '<div class="table-responsive"><table class="table table-sm table-bordered bg-white mb-2">';
      html += '<thead class="table-secondary"><tr><th>Kategori</th><th style="white-space:nowrap;">Nomor Aset</th><th>Keterangan Aset</th><th class="text-end" style="white-space:nowrap;">Biaya Penyusutan Perbulan</th></tr></thead>';
      html += '<tbody id="' + uid + '_tbody"></tbody>';
      html += '<tfoot><tr class="table-light fw-bold"><td colspan="3">' + escHtmlGl(totalLabel) + '</td><td class="text-end">' + fmtRpGl(totalValue) + '</td></tr></tfoot>';
      html += '</table></div>';
      html += '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2">';
      html += '<div class="small text-muted" id="' + uid + '_info"></div>';
      html += '<nav><ul class="pagination pagination-sm flex-wrap mb-0" id="' + uid + '_pager"></ul></nav>';
      html += '</div>';
      return html;
    }


    // ── Pagination client-side utk daftar aset (biar ga scroll panjang kalau asetnya banyak) ──
    function setupItemsPagination(scopeEl, items, uid) {
      if (!items || !items.length) return;
      const pageSize = 10;
      const totalPages = Math.max(1, Math.ceil(items.length / pageSize));
      const tbody = scopeEl.querySelector('#' + uid + '_tbody');
      const pager = scopeEl.querySelector('#' + uid + '_pager');
      const info  = scopeEl.querySelector('#' + uid + '_info');
      if (!tbody) return;

      function renderPage(page) {
        page = Math.min(Math.max(1, page), totalPages);
        const start = (page - 1) * pageSize;
        const pageItems = items.slice(start, start + pageSize);
        let rows = '';
        pageItems.forEach(function (it) {
          rows += '<tr><td>' + escHtmlGl(it.kategori) +
                  (it.manual ? ' <span class="badge bg-secondary" title="Sudah diverifikasi manual di tab Perbandingan Antar Bulan" style="font-size:.65em;">manual</span>' : '') + '</td>' +
                  '<td style="white-space:nowrap;">' + escHtmlGl(it.nomor_aset) + (it.sub_number ? '-' + escHtmlGl(it.sub_number) : '') + '</td>' +
                  '<td>' + escHtmlGl(it.keterangan_aset) + '</td>' +
                  '<td class="text-end">' + fmtRpGl(it.nilai) + '</td></tr>';
        });
        tbody.innerHTML = rows;
        if (info) {
          info.textContent = 'Menampilkan ' + (start + 1) + '\u2013' + Math.min(start + pageSize, items.length) + ' dari ' + items.length + ' aset';
        }
        if (pager) {
          if (totalPages <= 1) { pager.innerHTML = ''; return; }
          let itemsHtml = '';
          itemsHtml += '<li class="page-item ' + (page <= 1 ? 'disabled' : '') + '"><a class="page-link" href="#" data-page="' + (page - 1) + '">&laquo;</a></li>';
          for (let p = 1; p <= totalPages; p++) {
            if (p === 1 || p === totalPages || (p >= page - 1 && p <= page + 1)) {
              itemsHtml += '<li class="page-item ' + (p === page ? 'active' : '') + '"><a class="page-link" href="#" data-page="' + p + '">' + p + '</a></li>';
            } else if (p === page - 2 || p === page + 2) {
              itemsHtml += '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            }
          }
          itemsHtml += '<li class="page-item ' + (page >= totalPages ? 'disabled' : '') + '"><a class="page-link" href="#" data-page="' + (page + 1) + '">&raquo;</a></li>';
          pager.innerHTML = itemsHtml;
          pager.querySelectorAll('a.page-link').forEach(function (a) {
            a.addEventListener('click', function (e) {
              e.preventDefault();
              const p = parseInt(a.dataset.page, 10);
              if (p >= 1 && p <= totalPages && p !== page) { renderPage(p); }
            });
          });
        }
      }
      renderPage(1);
    }

    // ── Update kolom Nilai Awal/Akhir/Selisih semua baris secara instan (client-side, dari glPivotData) ──
    function refreshGlListColumns() {
      const bA = selAwal ? selAwal.value : null;
      const bB = selAkhir ? selAkhir.value : null;
      if (!bA || !bB) return;
      document.querySelectorAll('.gl-row').forEach(function (row) {
        const acc = row.dataset.account;
        const perBulan = glPivotData[acc] || {};
        const nA = perBulan[bA] || 0;
        const nB = perBulan[bB] || 0;
        const sel = nB - nA;
        row.querySelector('.gl-row-nilai-awal').textContent = fmtRpGl(nA);
        row.querySelector('.gl-row-nilai-akhir').textContent = fmtRpGl(nB);
        const selEl = row.querySelector('.gl-row-selisih');
        selEl.textContent = (sel > 0 ? '+' : '') + fmtRpGl(sel);
        selEl.classList.remove('text-success', 'text-danger');
        if (sel > 0) { selEl.classList.add('text-success'); }
        else if (sel < 0) { selEl.classList.add('text-danger'); }
      });
      closeGlModal();

      document.querySelectorAll('#exportExcelBtnGroup a[data-export-link]').forEach(function (a) {
        try {
          const u = new URL(a.href, window.location.origin);
          u.searchParams.set('gl_bulan_awal', bA);
          u.searchParams.set('gl_bulan_akhir', bB);
          a.href = u.pathname + u.search;
        } catch (e) { /* abaikan kalau href-nya gak valid URL, biarin apa adanya */ }
      });
    }
    if (selAwal)  { selAwal.addEventListener('change', refreshGlListColumns); }
    if (selAkhir) { selAkhir.addEventListener('change', refreshGlListColumns); }

    // ── Render langsung 2 bagian (Penambahan & Pengurangan) lengkap dgn kategori + daftar aset, begitu baris GL dibuka ──
    function renderGlSections(container, account, res) {
      const uidTambah = 'glit_' + account.replace(/[^a-zA-Z0-9]/g, '') + '_t';
      const uidKurang = 'glit_' + account.replace(/[^a-zA-Z0-9]/g, '') + '_k';
      const bodyIdTambah = uidTambah + '_body';
      const bodyIdKurang = uidKurang + '_body';
      const jumlahTambah = res.penambahan.items ? res.penambahan.items.length : 0;
      const jumlahKurang = res.pengurangan.items ? res.pengurangan.items.length : 0;
      let html = '';

      html += '<div class="gl-section mb-3">';
      html += '  <div class="bg-success bg-opacity-25 fw-bold p-2 border border-success rounded-top gl-section-toggle d-flex align-items-center justify-content-between flex-wrap gap-1" style="cursor:pointer;" data-target="' + bodyIdTambah + '">';
      html += '    <span><i class="bi bi-chevron-right me-2 gl-section-chevron"></i>Penambahan Biaya Penyusutan <span class="fw-normal text-muted">(' + jumlahTambah + ' aset)</span></span>';
      html += '    <span class="text-success">+' + fmtRpGl(res.penambahan.total) + '</span>';
      html += '  </div>';
      html += '  <div class="border border-top-0 border-success p-2 bg-white d-none" id="' + bodyIdTambah + '">';
      html += itemsTableSkeleton(res.penambahan.items, uidTambah, 'Total Penambahan', res.penambahan.total);
      html += '  </div>';
      html += '</div>';

      html += '<div class="gl-section mb-3">';
      html += '  <div class="bg-danger bg-opacity-25 fw-bold p-2 border border-danger rounded-top gl-section-toggle d-flex align-items-center justify-content-between flex-wrap gap-1" style="cursor:pointer;" data-target="' + bodyIdKurang + '">';
      html += '    <span><i class="bi bi-chevron-right me-2 gl-section-chevron"></i>Pengurangan Biaya Penyusutan <span class="fw-normal text-muted">(' + jumlahKurang + ' aset)</span></span>';
      html += '    <span class="text-danger">' + fmtRpGl(res.pengurangan.total) + '</span>';
      html += '  </div>';
      html += '  <div class="border border-top-0 border-danger p-2 bg-white d-none" id="' + bodyIdKurang + '">';
      html += itemsTableSkeleton(res.pengurangan.items, uidKurang, 'Total Pengurangan', res.pengurangan.total);
      html += '  </div>';
      html += '</div>';

      const netSelisih = res.penambahan.total + res.pengurangan.total;
      const netCls = netSelisih > 0 ? 'text-success' : (netSelisih < 0 ? 'text-danger' : '');
      html += '<table class="table table-sm table-bordered mb-0"><tbody>';
      html += '<tr class="fw-bold"><td>Selisih</td><td class="text-end ' + netCls + '" style="width:200px;">' +
              (netSelisih > 0 ? '+' : '') + fmtRpGl(netSelisih) + '</td></tr>';
      html += '</tbody></table>';

      container.innerHTML = html;
      setupItemsPagination(container, res.penambahan.items, uidTambah);
      setupItemsPagination(container, res.pengurangan.items, uidKurang);

      container.querySelectorAll('.gl-section-toggle').forEach(function (hdr) {
        hdr.addEventListener('click', function () {
          const target = document.getElementById(hdr.dataset.target);
          if (!target) return;
          const chevron = hdr.querySelector('.gl-section-chevron');
          const sedangTertutup = target.classList.contains('d-none');
          target.classList.toggle('d-none', !sedangTertutup);
          if (chevron) {
            chevron.classList.toggle('bi-chevron-right', !sedangTertutup);
            chevron.classList.toggle('bi-chevron-down', sedangTertutup);
          }
        });
      });
    }

    // ── Modal popup untuk detail Penambahan/Pengurangan per GL Account (biar rapi & fokus,
    const glModalBackdrop = document.getElementById('glDetailModalBackdrop');
    const glModalPanel    = document.getElementById('glDetailModalPanel');
    const modalBody       = document.getElementById('glDetailModalBody');
    const modalAccountEl  = document.getElementById('glDetailModalAccount');
    const modalKetEl      = document.getElementById('glDetailModalKeterangan');
    const modalCloseBtn   = document.getElementById('glDetailModalClose');

    function computeSidebarOffsetPx() {
      const sb = document.querySelector('.app-sidebar');
      if (!sb) return 0;
      const rect = sb.getBoundingClientRect();
      if (rect.right <= 0 || rect.width <= 0) return 0;
      return rect.width;
    }

    function centerGlModal() {
      if (!glModalBackdrop) return;
      const sbWidth = computeSidebarOffsetPx();
      const basePad = 24; // 1.5rem
      glModalBackdrop.style.paddingLeft  = (basePad + sbWidth) + 'px';
      glModalBackdrop.style.paddingRight = basePad + 'px';
    }

    function openGlModal(account, keterangan) {
      if (!glModalBackdrop) return;
      modalAccountEl.textContent = account;
      modalKetEl.textContent = keterangan || '';
      modalBody.innerHTML = '<div class="text-center text-muted small py-4"><div class="spinner-border spinner-border-sm me-2"></div>Memuat...</div>';
      centerGlModal();
      glModalBackdrop.style.display = 'flex';
      document.body.style.overflow = 'hidden';
    }

    window.addEventListener('resize', function () {
      if (glModalBackdrop && glModalBackdrop.style.display !== 'none') { centerGlModal(); }
    });
    function closeGlModal() {
      if (!glModalBackdrop) return;
      glModalBackdrop.style.display = 'none';
      document.body.style.overflow = '';
    }
    if (modalCloseBtn) { modalCloseBtn.addEventListener('click', closeGlModal); }
    if (glModalBackdrop) {
      glModalBackdrop.addEventListener('click', function (e) {
        if (e.target === glModalBackdrop) { closeGlModal(); }
      });
    }
    if (glModalPanel) { glModalPanel.addEventListener('click', function (e) { e.stopPropagation(); }); }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && glModalBackdrop && glModalBackdrop.style.display !== 'none') { closeGlModal(); }
    });

    document.querySelectorAll('.gl-row').forEach(function (row) {
      row.addEventListener('click', function () {
        const bA = selAwal ? selAwal.value : '';
        const bB = selAkhir ? selAkhir.value : '';
        const cacheKey = row.dataset.account + '|' + bA + '|' + bB;

        openGlModal(row.dataset.account, row.dataset.keterangan);

        function tampilkan(res) {
          if (!res || !res.ok) {
            modalBody.innerHTML = '<div class="text-center text-danger small py-4">Gagal memuat detail.</div>';
            return;
          }
          renderGlSections(modalBody, row.dataset.account, res);
        }

        if (glCardData[cacheKey]) { tampilkan(glCardData[cacheKey]); return; }

        const fd = new FormData();
        fd.append('action', 'get_gl_card_data');
        fd.append('account', row.dataset.account);
        fd.append('tahun', row.dataset.tahun);
        fd.append('bulan_awal', bA);
        fd.append('bulan_akhir', bB);
        fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (res && res.ok) { glCardData[cacheKey] = res; }
            tampilkan(res);
          })
          .catch(function () {
            modalBody.innerHTML = '<div class="text-center text-danger small py-4">Gagal memuat detail (koneksi bermasalah).</div>';
          });
      });
    });
  })();

  const labels  = <?php echo json_encode($trenLabel); ?>;
  const upload  = <?php echo json_encode($trenUpload); ?>;

  const chartTren = new ApexCharts(document.querySelector('#chart-tren-penyusutan'), {
    series: [
      { name: 'Total Amount per Bulan', data: upload }
    ],
    chart: { height: 320, type: 'line', toolbar: { show: false } },
    colors: ['#0d6efd'],
    stroke: { curve: 'smooth', width: 3 },
    xaxis: { categories: labels },
    yaxis: { labels: { formatter: function (v) { return new Intl.NumberFormat('id-ID').format(v); } } },
    tooltip: { y: { formatter: function (v) { return 'Rp ' + new Intl.NumberFormat('id-ID').format(v); } } }
  });
  chartTren.render();
});
</script>
</body>
</html>