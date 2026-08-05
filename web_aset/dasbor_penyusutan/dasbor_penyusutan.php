<?php
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

    // ── Bulan mana saja yang ada datanya untuk account & tahun ini (biar kolomnya dinamis, sama kayak tabel atas) ──
    $resBulanD = mysqli_query($con, "SELECT DISTINCT MONTH(" . date_expr('ip.posting_date') . ") AS bln
                                      FROM import_fagll ip
                                      WHERE $accWhere AND " . date_expr('ip.posting_date') . " >= '$awalD' AND " . date_expr('ip.posting_date') . " < '$akhirD'
                                      ORDER BY bln ASC");
    $listBulanD = [];
    if ($resBulanD) { while ($r = mysqli_fetch_assoc($resBulanD)) { $listBulanD[] = (int)$r['bln']; } }

    $selectBulanD = '';
    foreach ($listBulanD as $b) {
        $selectBulanD .= ",\n                        SUM(CASE WHEN MONTH(" . date_expr('ip.posting_date') . ") = $b THEN " . amount_expr('ip.amount_local_currency') . " ELSE 0 END) AS bln_$b";
    }

    $sqlDetAcc = "SELECT
                        COALESCE(NULLIF(dat.cabang, ''), NULLIF(datAny.cabang, ''), CONCAT('(Belum match DAT — Cost Center: ', COALESCE(NULLIF(ip.cost_center,''),'-'), ')')) AS cabang,
                        COALESCE(NULLIF(dat.profit_center, ''), NULLIF(ip.profit_center,''), '(Tanpa Profit Center)') AS profit_center,
                        COALESCE(NULLIF(dat.keterangan_asset, ''), NULLIF(datAny.keterangan_asset, ''), '-') AS keterangan_asset,
                        COUNT(DISTINCT CONCAT(ip.asset,'|',ip.asset_subnumber)) AS jml_aset,
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
                  WHERE $accWhere
                    AND " . date_expr('ip.posting_date') . " >= '$awalD' AND " . date_expr('ip.posting_date') . " < '$akhirD'
                  GROUP BY cabang, profit_center, keterangan_asset
                  ORDER BY total DESC";
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
                'cabang'          => $r['cabang'],
                'profit_center'   => $r['profit_center'],
                'keterangan_asset' => $r['keterangan_asset'],
                'jml_aset'        => (int)$r['jml_aset'],
                'total'           => (float)$r['total'],
                'per_bulan'       => $perBulanD,
            ];
            $grandTotalAcc += (float)$r['total'];
        }
    } else {
        echo json_encode(['ok' => false, 'error' => mysqli_error($con)]);
        exit();
    }
    $ketMapAcc = get_pivot_keterangan_map();
    $keteranganAcc = $ketMapAcc[$accD] ?? '-';
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

// ── PENTING (perf): posting_date & amount_local_currency disimpan sebagai VARCHAR di DB
// (format tanggal SAP campur-campur & angka pakai pemisah ribuan). Dulu date_expr()/amount_expr()
// mem-parsing ulang STRING itu di SETIAP baris SETIAP query (STR_TO_DATE 4x + REGEXP, tanpa bisa
// pakai index -> full table scan). Sekarang tabel import_fagll sudah punya kolom hasil
// normalisasi (posting_date_norm DATE, amount_norm DECIMAL) yang di-index dan diisi otomatis saat
// import (lihat import_fagll.php). Jadi di sini kita cukup ARAHKAN ke kolom _norm itu --
// SEMUA query yang manggil date_expr()/amount_expr() otomatis jadi cepat tanpa perlu diubah satu-satu.
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

/**
 * Deteksi kategori penyebab perubahan nilai penyusutan 1 aset antara 2 periode (bulan A -> bulan B).
 * $accR = ['account' => .., 'cost_center' => .., 'profit_center' => ..] milik aset ini.
 * Kembalikan salah satu: Reklas Asset Class, Relokasi Aset, Perubahan Umur Ekonomis,
 * Pencatatan Aset Baru, Umur Ekonomis Habis, Penghapusan Aset, Perlu Dicek Manual, atau null (Tetap).
 */
function deteksi_kategori_ps($status, $entA, $entB, $dmA, $dmB, $accR, $tahunFilter, $bulanA, $bulanB, $bulanPertamaAset = null) {
    if ($status === 'Naik' || $status === 'Turun') {
        if ($entA && $entB && $entA['gl'] !== '' && $entB['gl'] !== '' && $entA['gl'] !== $entB['gl']) {
            return 'Reklas Asset Class';
        } elseif ($entA && $entB && $entA['cabang'] !== '' && $entB['cabang'] !== '' && $entA['cabang'] !== $entB['cabang']) {
            return 'Relokasi Aset';
        }
        // Aset yang baru saja mulai ADA POSTING tahun ini (assetSubBulanPertama === bulan A)
        // DAN tanggal perolehannya (DAT) memang baru (tahun ini atau tahun sebelumnya) --
        // genuinely aset baru yang postingnya baru mulai berjalan/nyusul dari tanggal perolehan
        // aslinya. Sisa umur manfaat yang "belum berkurang sesuai jadwal" dari bulan A ke B
        // masih WAJAR untuk kondisi ini (bukan indikasi perpanjangan umur ekonomis). Makanya
        // dicek SEBELUM cek delta sisa manfaat, supaya tidak keburu dicap "Perubahan Umur Ekonomis".
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
            // PENTING: syarat $entA === null wajib ada -- kalau DAT periode A justru SUDAH
            // menemukan aset ini terdaftar, berarti aset ini memang sudah lama ada (cuma
            // kebetulan bulan A adalah bulan pertama dalam RENTANG TAHUN yang difilter, mis.
            // bulan A = Januari, yang trivial jadi "bulan pertama" untuk hampir semua aset
            // lama juga). Tanpa syarat ini, aset lama yang dibandingkan mulai dari Januari
            // akan salah kena label "Pencatatan Aset Baru".
            // Aset ini baru mulai tercatat/disusutkan persis di bulan A -- Naik/Turun besar
            // antara bulan A ke bulan B masih wajar sebagai bagian dari pencatatan aset baru
            // (mis. bulan pertama ada penyesuaian/catch-up, lalu normal di bulan berikutnya).
            return 'Pencatatan Aset Baru';
        }
        return null; // selisih kecil/wajar tanpa penyebab spesifik terdeteksi
    } elseif ($status === 'Tercatat Baru') {
        if ($entA && $entA['gl'] !== '' && $entA['gl'] !== $accR['account']) {
            return 'Reklas Asset Class';
        } elseif ($dmA && (($dmA['cost_center'] != $accR['cost_center']) || ($dmA['profit_center'] != $accR['profit_center']))) {
            return 'Relokasi Aset';
        } elseif ($entA && $entA['sisa'] !== null && $entA['sisa'] <= 1 && $entB && $entB['sisa'] !== null && $entB['sisa'] > 1) {
            // Sisa umur manfaat sebelumnya sudah habis/hampir habis, lalu di periode B
            // direvisi naik lagi -- itu baru dianggap perpanjangan umur ekonomis.
            return 'Perubahan Umur Ekonomis';
        } elseif ($entB) {
            // Aset mulai ada posting/tercatat di periode B -- baik yang sama sekali belum
            // ada di DAT periode A, maupun yang sudah terdaftar di DAT periode A tapi belum
            // mulai disusutkan (posting-nya baru mulai di periode B, mis. SAP telat posting
            // dari tanggal perolehan aslinya). Keduanya tetap "Pencatatan Aset Baru".
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

/**
 * Ringkasan kategori dominan penyebab naik/turun total Amount antara bulan A dan bulan B,
 * dihitung dari kontribusi bersih (net) tiap kategori terhadap seluruh aset yang berubah.
 * Butuh lookup data setahun penuh: $datByBulan[bln][asset|sub] , $dimByBulan[bln][asset|sub],
 * $nilaiPerBulanAsset[bln][cost_center|asset|sub|account|profit_center] = nilai.
 */
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
        if (abs($selisih) < 0.01) continue; // dianggap Tetap, tidak berkontribusi ke perubahan

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

$totalTanpaTanggalValid = 0;
$listTahun = [];
$listBulan = [];
$totalData = 0; $totalNaik = 0; $totalTurun = 0; $totalTetap = 0; $totalTidakAda = 0;
$trenLabel = []; $trenUpload = []; $rekapBulanan = [];
$pivotData = []; $pivotKeterangan = [];
$grandTotalKolom = []; $grandTotalSemua = 0.0;
$detailRows = [];
$totalHalaman = 1;
$anomaliRows = []; $totalAnomali = 0; $bulanAnomA = null; $bulanAnomB = null; $hanyaBeda = false;
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

    $prevAggSql = "(SELECT cost_center, asset, asset_subnumber, account,
                            YEAR(" . date_expr('posting_date') . ") AS thn, MONTH(" . date_expr('posting_date') . ") AS bln,
                            SUM(" . amount_expr('amount_local_currency') . ") AS total_amt
                     FROM import_fagll
                     WHERE " . date_expr('posting_date') . " IS NOT NULL
                     GROUP BY cost_center, asset, asset_subnumber, account, thn, bln)";
    $joinPrevBulan = "LEFT JOIN $prevAggSql prevagg
                         ON prevagg.cost_center = ip.cost_center
                        AND prevagg.asset = ip.asset
                        AND prevagg.asset_subnumber = ip.asset_subnumber
                        AND prevagg.account = ip.account
                        AND prevagg.thn = YEAR(DATE_SUB(" . date_expr('ip.posting_date') . ", INTERVAL 1 MONTH))
                        AND prevagg.bln = MONTH(DATE_SUB(" . date_expr('ip.posting_date') . ", INTERVAL 1 MONTH))";
                        

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
    $sqlDimFull = "SELECT MONTH(" . date_expr('posting_date') . ") AS bln, asset, asset_subnumber, cost_center, profit_center
                   FROM import_fagll WHERE $rangeTahunWherePlain";
    $resDimFull = mysqli_query($con, $sqlDimFull);
    if ($resDimFull) {
        while ($d = mysqli_fetch_assoc($resDimFull)) {
            $blnDm  = (int)$d['bln'];
            $dkeyDm = trim((string)$d['asset']) . '|' . trim((string)$d['asset_subnumber']);
            if (!isset($dimByBulanFull[$blnDm][$dkeyDm])) {
                $dimByBulanFull[$blnDm][$dkeyDm] = ['cost_center' => $d['cost_center'], 'profit_center' => $d['profit_center']];
            }
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

    // ── Bulan pertama kemunculan tiap aset (asset|sub) sepanjang tahun ini -- dipakai supaya
    // Naik/Turun besar yang murni karena aset itu BARU MULAI disusutkan (bukan sudah lama
    // ada) tetap dikategorikan "Pencatatan Aset Baru", bukan "Perlu Dicek Manual". ──
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

    // ── Rekap Naik/Turun per bulan: bandingkan total Amount bulan ini vs bulan sebelumnya ──
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

    // ── Cek Anomali Antar 2 Bulan (BEBAS pilih bulan, gak harus berurutan) ──
    $bulanAnomA = isset($_GET['bulan_a']) && $_GET['bulan_a'] !== '' ? (int)$_GET['bulan_a'] : ($listBulan[0] ?? null);
    $bulanAnomB = isset($_GET['bulan_b']) && $_GET['bulan_b'] !== '' ? (int)$_GET['bulan_b'] : ($listBulan[count($listBulan) - 1] ?? null);
    $hanyaBeda  = isset($_GET['hanya_beda']) && $_GET['hanya_beda'] === '1';
    // Ambang batas "selisih kecil" yang disembunyikan (bisa diatur user, default 10, isi 0 untuk tampilkan semua)
    $minSelisih = isset($_GET['min_selisih']) && $_GET['min_selisih'] !== '' ? abs((float)$_GET['min_selisih']) : 10;
    $anomaliRows = [];
    $totalAnomali = 0;

    if ($bulanAnomA !== null && $bulanAnomB !== null) {
        // ── Lookup DAT (per aset per periode) & dimensi aset (cost center/profit center per periode) ──
        $datA = []; $datB = []; $dimA = []; $dimB = [];
        if ($adaTabelDat) {
            $sqlDat = "SELECT periode_bulan, nomor_asset, sub_number, sisa_manfaat_aset, gl_account_exp, tgl_perolehan, keterangan_asset, cabang
                       FROM import_dat_penyusutan
                       WHERE CAST(tahun_buku AS UNSIGNED) = " . (int)$tahunFilter . "
                         AND CAST(periode_bulan AS UNSIGNED) IN ($bulanAnomA, $bulanAnomB)";
            $resDat = mysqli_query($con, $sqlDat);
            if ($resDat) {
                while ($d = mysqli_fetch_assoc($resDat)) {
                    $dkey = trim((string)$d['nomor_asset']) . '|' . trim((string)$d['sub_number']);
                    $entry = [
                        'sisa'   => is_numeric($d['sisa_manfaat_aset']) ? (float)$d['sisa_manfaat_aset'] : null,
                        'gl'     => trim((string)$d['gl_account_exp']),
                        'tgl'    => trim((string)$d['tgl_perolehan']),
                        'ket'    => trim((string)$d['keterangan_asset']),
                        'cabang' => trim((string)$d['cabang']),
                    ];
                    if ((int)$d['periode_bulan'] === $bulanAnomA) { $datA[$dkey] = $entry; }
                    if ((int)$d['periode_bulan'] === $bulanAnomB) { $datB[$dkey] = $entry; }
                }
            }
            // Fallback KHUSUS untuk tampilan "Keterangan Aset" saja (bukan untuk deteksi kategori):
            // kalau DAT bulan yang sedang dibandingkan (mis. Jan/Feb) tidak punya data untuk aset ini
            // (mis. aset itu baru ke-upload DAT-nya di bulan Juni), tetap ambil keterangan dari
            // PERIODE MANAPUN yang tersedia -- karena nama/kelompok aset biasanya tidak berubah
            // antar bulan, beda dengan sisa umur manfaat / GL account yang memang harus per-periode.
            $datAnyKetLookup = [];
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
        $sqlDim = "SELECT MONTH(" . date_expr('posting_date') . ") AS bln, asset, asset_subnumber, cost_center, profit_center
                   FROM import_fagll ip
                   WHERE $rangeTahunWhere AND MONTH(" . date_expr('posting_date') . ") IN ($bulanAnomA, $bulanAnomB)";
        $resDim = mysqli_query($con, $sqlDim);
        if ($resDim) {
            while ($d = mysqli_fetch_assoc($resDim)) {
                $dkey = trim((string)$d['asset']) . '|' . trim((string)$d['asset_subnumber']);
                $entry = ['cost_center' => $d['cost_center'], 'profit_center' => $d['profit_center']];
                if ((int)$d['bln'] === $bulanAnomA && !isset($dimA[$dkey])) { $dimA[$dkey] = $entry; }
                if ((int)$d['bln'] === $bulanAnomB && !isset($dimB[$dkey])) { $dimB[$dkey] = $entry; }
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

                // Sembunyikan yang nilainya kecil (di bawah ambang $minSelisih), berlaku untuk
                // selisih (aset ada di 2 bulan) maupun nilai tunggal (Tercatat Baru / Hilang/Selesai)
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

                // Baris auto PSAK 73 / Penyusutan KSP (tidak punya nomor aset asli -- ditandai
                // "-" oleh proses import) TIDAK ikut diperhitungkan sebagai penyusutan aset tetap,
                // jadi jangan dipetakan ke kategori Naik/Turun/Tercatat Baru/Hilang-Selesai biasa.
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
                        // Aset yang baru saja mulai ada posting tahun ini (assetSubBulanPertama ===
                        // bulan A) DAN tanggal perolehannya (DAT) memang baru (tahun ini/tahun
                        // sebelumnya) -- genuinely aset baru yang postingnya baru mulai berjalan/
                        // nyusul dari tanggal perolehan aslinya. Dicek SEBELUM cek delta sisa
                        // manfaat, supaya tidak keburu dicap "Perubahan Umur Ekonomis" cuma karena
                        // sisa manfaatnya belum sempat berkurang (wajar untuk aset yang baru mulai).
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
                    // Aset ini baru mulai tercatat/disusutkan PERSIS di bulan A (belum pernah ada
                    // sama sekali di bulan-bulan sebelumnya tahun ini) -- Naik/Turun besar dari bulan
                    // A ke bulan B masih wajar sebagai bagian dari pencatatan aset baru (mis. bulan
                    // pertama ada penyesuaian/catch-up, baru normal di bulan berikutnya), BUKAN
                    // sesuatu yang perlu dicek manual.
                    if ($kategori === null && $entA === null && ($assetSubBulanPertama[$dkey] ?? null) === $bulanAnomA) {
                        // Syarat $entA === null wajib: kalau DAT periode A ternyata SUDAH ada
                        // (aset sudah terdaftar), berarti ini aset LAMA, bukan aset baru -- cuma
                        // kebetulan bulan A adalah bulan pertama dalam rentang tahun yang difilter.
                        $kategori = 'Pencatatan Aset Baru';
                        $ketDetail = "Aset ini baru mulai tercatat/disusutkan sejak $namaBulanA (belum pernah ada datanya di bulan manapun sebelum itu di tahun $tahunFilter), sehingga perubahan nilai dari $namaBulanA ke $namaBulanB masih wajar sebagai bagian dari pencatatan aset baru.";
                    }
                    // Tidak ada satupun pemicu spesifik (GL Account/Cabang/Sisa Manfaat/Aset Baru) yang
                    // terdeteksi dari DAT. Jangan tampilkan mentah "Naik"/"Turun" -- tetap dipetakan ke
                    // kategori supaya user tahu ini butuh dicek manual.
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
                        // Sisa umur manfaat sebelumnya sudah habis/hampir habis (<=1 bulan), lalu di
                        // periode B direvisi naik lagi -- itu baru dianggap perpanjangan umur ekonomis
                        // yang jelas, terlepas dari berapa persisnya sisa umur manfaat sebelumnya.
                        $kategori = 'Perubahan Umur Ekonomis';
                        $ketDetail = "Sudah tercatat di DAT sejak $namaBulanA dengan sisa umur manfaat sudah/hampir habis (" . (int)$entA['sisa'] . " bulan), tapi di $namaBulanB sisa umur manfaatnya direvisi jadi " . (int)$entB['sisa'] . " bulan — penyusutan aktif lagi karena umur ekonomisnya diperpanjang.";
                    } elseif ($entB) {
                        // Aset mulai ada posting di periode B -- baik yang sama sekali belum ada di
                        // DAT periode A, maupun yang sudah terdaftar di DAT periode A tapi belum
                        // mulai disusutkan (posting-nya baru mulai di periode B, mis. SAP telat
                        // posting dari tanggal perolehan aslinya). Keduanya tetap "Pencatatan Aset Baru".
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
                    'ket_detail' => $ketDetail,
                    'row_key' => $rowKeyThis,
                    'catatan' => $catatanLookup[$rowKeyThis] ?? '',
                ];
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

    // ── Export Excel (pakai data $anomaliRows yang sudah difilter & diurutkan, TANPA pagination) ──
    // Diganti dari HTML-table .xls ke .xlsx asli, supaya kolom Nilai/Selisih bisa diformat
    // Accounting (Rupiah) dan kolom Perubahan (%) bisa diformat Percent beneran di Excel.
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

    $grandTotalKolom = array_fill_keys($listBulan, 0.0);
    foreach ($pivotData as $acc => $perBulan) {
        foreach ($listBulan as $b) {
            $v = $perBulan[$b] ?? 0;
            $grandTotalKolom[$b] += $v;
            $grandTotalSemua += $v;
        }
    }

    // ── Export Excel GABUNGAN (semua card jadi 1 file, beda sheet per card) ──
    if (isset($_GET['export']) && $_GET['export'] === 'excel_all') {
        ini_set('memory_limit', '512M');
        set_time_limit(120);

        $sheets = [];

        // Sheet 1: Rekap Total Amount per Bulan
        $rowsRekap = [];
        foreach ($rekapBulanan as $rb) {
            $rowsRekap[] = [
                $rb['label'], $rb['total'], $rb['selisih'], $rb['persen'] !== null ? round($rb['persen'], 2) : null, $rb['status'],
                $rb['kategori_utama'] ?? '-',
                $rb['kategori_persen'] !== null ? round($rb['kategori_persen'], 1) : null,
            ];
        }
        $sheets[] = ['name' => 'Rekap Bulanan', 'currency_cols' => [1, 2], 'percent_cols' => [3, 6],
            'header' => ['Bulan', 'Total Amount (Rp)', 'Perubahan Penyusutan (Rp)', 'Perubahan (%)', 'Status', 'Kategori Penyebab Utama', '% Kontribusi Kategori'],
            'rows' => $rowsRekap];

        // Sheet 2: Perbandingan Amount Antar Bulan 
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

        // Sheet 3: Rekap per Account (GL Account) x Bulan
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

        // Sheet 4: Detail Penyusutan (query ULANG tanpa LIMIT, ikutin filter tahun/bulan yang aktif)
        // ── JOIN ke import_dat_penyusutan buat ambil Keterangan Aset (sama polanya dengan get_account_detail) ──
        $joinDatKet = '';
        $selectKet  = "'-' AS keterangan_asset";
        if ($adaTabelDat) {
            $selectKet = "COALESCE(NULLIF(dat.keterangan_asset, ''), NULLIF(datAny.keterangan_asset, ''), '-') AS keterangan_asset";
            $joinDatKet = "LEFT JOIN import_dat_penyusutan dat
                                  ON dat.nomor_asset = ip.asset
                                 AND (dat.sub_number = ip.asset_subnumber OR dat.sub_number_num = ip.asset_subnumber_num)
                                 AND CAST(dat.tahun_buku AS UNSIGNED) = YEAR(" . date_expr('ip.posting_date') . ")
                                 AND CAST(dat.periode_bulan AS UNSIGNED) = MONTH(" . date_expr('ip.posting_date') . ")
                            LEFT JOIN (
                                  SELECT nomor_asset, sub_number, sub_number_num,
                                         MAX(NULLIF(keterangan_asset, '')) AS keterangan_asset
                                  FROM import_dat_penyusutan
                                  GROUP BY nomor_asset, sub_number, sub_number_num
                            ) datAny ON datAny.nomor_asset = ip.asset
                                     AND (datAny.sub_number = ip.asset_subnumber OR datAny.sub_number_num = ip.asset_subnumber_num)";
        }
        $sqlDetailAll = "SELECT ip.account, ip.cost_center, ip.asset, ip.asset_subnumber, $selectKet, ip.profit_center,
                                ip.posting_date, ip.text, ip.amount_local_currency,
                                prevagg.total_amt AS amount_bulan_lalu
                         FROM import_fagll ip
                         $joinPrevBulan
                         $joinDatKet
                         WHERE $rangeTahunWhere $bulanWhere
                         ORDER BY ip.id DESC";
        $rowsDetail = [];
        $resDetailAll = mysqli_query($con, $sqlDetailAll);
        if ($resDetailAll) {
            while ($r = mysqli_fetch_assoc($resDetailAll)) {
                $nilaiUpload = (float)str_replace(['.', ','], '', $r['amount_local_currency']);
                $adaBulanLalu = $r['amount_bulan_lalu'] !== null && $r['amount_bulan_lalu'] !== '';
                $nilaiBulanLalu = $adaBulanLalu ? (float)$r['amount_bulan_lalu'] : null;
                $selisihD = $adaBulanLalu ? ($nilaiUpload - $nilaiBulanLalu) : null;
                $statusD = !$adaBulanLalu ? 'Data Awal' : ($selisihD > 0 ? 'Naik' : ($selisihD < 0 ? 'Turun' : 'Tetap'));
                $rowsDetail[] = [
                    $r['account'], $r['cost_center'], $r['asset'], $r['asset_subnumber'], $r['keterangan_asset'], $r['profit_center'],
                    $r['posting_date'], $r['text'], $nilaiUpload, $nilaiBulanLalu, $statusD,
                ];
            }
        }
        $sheets[] = ['name' => 'Detail Penyusutan', 'currency_cols' => [7, 8],
            'header' => ['Account', 'Cost Center', 'No Asset', 'Subnumber', 'Keterangan Aset','Profit Center', 'Posting Date', 'Text', 'Amount (Bulan Ini)', 'Amount (Bulan Sebelumnya)', 'Status'],
            'rows' => $rowsDetail];

        export_multi_sheet_xlsx('dasbor_penyusutan_' . $tahunFilter . '.xlsx', $sheets);
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
  <title>Dasbor Biaya Penyusutan - Web Aset Tetap</title>
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
    .badge-naik   { background:#dcfce7; color:#166534; }
    .badge-turun  { background:#fee2e2; color:#991b1b; }
    .badge-tetap  { background:#f3f4f6; color:#374151; }
    .badge-nodat  { background:#fef9c3; color:#854d0e; }
    .tabel-perbandingan th { white-space: nowrap; vertical-align: middle; }
    .tabel-perbandingan th a { white-space: nowrap; }
    .tabel-perbandingan, .tabel-perbandingan td, .tabel-perbandingan th {
      font-size: 0.85rem;
    }
    .tabel-rekap-gl, .tabel-rekap-gl td, .tabel-rekap-gl th {
      font-size: 0.85rem;
    }
  </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
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
          <div class="col-sm-6"><h3 class="mb-0">Dasbor Biaya Penyusutan</h3></div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end mb-0">
              <li class="breadcrumb-item"><a href="../dasbor/dasbor.php">Home</a></li>
              <li class="breadcrumb-item active">Dasbor Biaya Penyusutan</li>
            </ol>
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

        <!-- Filter -->
        <form method="get" class="row g-2 mb-3 align-items-end">
          <div class="col-auto">
            <label class="form-label mb-0 small text-muted">Tahun</label>
            <select name="tahun" class="form-select form-select-sm" onchange="this.form.submit()">
              <?php if (empty($listTahun)): ?>
                <option value="<?php echo htmlspecialchars($tahunFilter); ?>"><?php echo htmlspecialchars($tahunFilter); ?></option>
              <?php else: foreach ($listTahun as $th): ?>
                <option value="<?php echo htmlspecialchars($th); ?>" <?php echo ($th == $tahunFilter) ? 'selected' : ''; ?>><?php echo htmlspecialchars($th); ?></option>
              <?php endforeach; endif; ?>
            </select>
          </div>
          <div class="col-auto">
            <label class="form-label mb-0 small text-muted">Bulan</label>
            <select name="bulan" class="form-select form-select-sm" onchange="this.form.submit()">
              <option value="" <?php echo ($bulanFilter === 'Semua') ? 'selected' : ''; ?>>Semua Bulan</option>
              <?php foreach ($listBulan as $b): ?>
                <option value="<?php echo $b; ?>" <?php echo ($b === $bulanFilter) ? 'selected' : ''; ?>><?php echo $namaBulan[$b] ?? $b; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if (!$adaTabelPenyusutan): ?>
          <div class="col-auto">
            <span class="badge bg-warning text-dark">Belum ada data di tabel Akumulasi Penyusutan— upload dulu di menu Import DAT.</span>
          </div>
          <?php endif; ?>
          <div class="col ms-auto text-end">
            <?php
              $qExportAll = 'export=excel_all'
                  . '&tahun=' . urlencode($tahunFilter)
                  . '&bulan=' . urlencode($bulanFilter === 'Semua' ? '' : $bulanFilter)
                  . '&bulan_a=' . urlencode((string)$bulanAnomA)
                  . '&bulan_b=' . urlencode((string)$bulanAnomB)
                  . '&hanya_beda=' . ($hanyaBeda ? '1' : '0')
                  . '&min_selisih=' . urlencode((string)$minSelisih);
            ?>
            <a href="?<?php echo $qExportAll; ?>" class="btn btn-sm btn-success">
              <i class="bi bi-file-earmark-excel"></i> Export Excel
            </a>
          </div>
        </form>

        <!-- Chart tren -->
        <div class="card card-primary card-outline mb-4">
          <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-graph-up"></i>
            <h3 class="card-title mb-0">Tren Amount Penyusutan per Bulan</h3>
            <span class="badge bg-primary rounded-pill">Tahun <?php echo htmlspecialchars($tahunFilter); ?></span>
          </div>
          <div class="card-body">
            <div id="chart-tren-penyusutan"></div>
          </div>
        </div>

        <!-- Rekap Naik/Turun Amount per Bulan -->
        <div class="card card-primary card-outline mb-4">
          <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-list-ul"></i>
            <h3 class="card-title mb-0">Rekap Total Amount per Bulan</h3>
            <span class="badge bg-primary rounded-pill">Tahun <?php echo htmlspecialchars($tahunFilter); ?></span>
          </div>
          <div class="card-body table-responsive">
            <?php if (empty($rekapBulanan)): ?>
              <p class="text-muted mb-0">Belum ada data untuk tahun ini.</p>
            <?php else: ?>
            <table class="table table-bordered table-sm align-middle mb-0" style="min-width:700px;">
              <thead class="table-light">
                <tr>
                  <th>Bulan</th>
                  <th class="text-end">Total Amount (Rp)</th>
                  <th class="text-end">Perubahan Biaya Penyusutan (Rp)</th>
                  <th class="text-end">Persentase (%)</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rekapBulanan as $rb): ?>
                <tr>
                  <td class="fw-semibold"><?php echo htmlspecialchars($rb['label']); ?></td>
                  <td class="text-end"><?php echo fmt_rp($rb['total']); ?></td>
                  <td class="text-end <?php echo $rb['selisih'] === null ? '' : ($rb['selisih'] > 0 ? 'text-success fw-semibold' : ($rb['selisih'] < 0 ? 'text-danger fw-semibold' : '')); ?>">
                    <?php if ($rb['selisih'] === null): ?>
                      -
                    <?php else:
                      $hrefRekap = '?tahun=' . urlencode($tahunFilter) . '&bulan=' . urlencode($bulanFilter === 'Semua' ? '' : $bulanFilter)
                                 . '&bulan_a=' . urlencode($rb['bln_prev']) . '&bulan_b=' . urlencode($rb['bln'])
                                 . '#perbandingan-amount-antar-bulan';
                    ?>
                      <a href="<?php echo htmlspecialchars($hrefRekap); ?>" class="text-decoration-none <?php echo $rb['selisih'] > 0 ? 'text-success' : ($rb['selisih'] < 0 ? 'text-danger' : ''); ?> fw-semibold" title="Lihat rincian per aset (<?php echo htmlspecialchars($namaBulan[$rb['bln_prev']] ?? $rb['bln_prev']); ?> vs <?php echo htmlspecialchars($namaBulan[$rb['bln']] ?? $rb['bln']); ?>)">
                        <?php echo ($rb['selisih'] > 0 ? '+' : '') . fmt_rp($rb['selisih']); ?>
                      </a>
                    <?php endif; ?>
                  </td>
                  <td class="text-end <?php echo $rb['persen'] === null ? '' : ($rb['persen'] > 0 ? 'text-success fw-semibold' : ($rb['persen'] < 0 ? 'text-danger fw-semibold' : '')); ?>">
                    <?php echo $rb['persen'] === null ? '-' : (($rb['persen'] > 0 ? '+' : '') . number_format($rb['persen'], 1, ',', '.') . '%'); ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <!-- <small class="text-muted d-block mt-2">
              Total Amount dihitung dari SUM seluruh <code>Amount in Local Currency</code> pada <code>import_fagll</code>,
              dikelompokkan per bulan berdasarkan <code>Posting Date</code>. Selisih &amp; persen dihitung terhadap bulan tepat sebelumnya
              (mis. Feb dibanding Jan, Mar dibanding Feb, dst).
            </small> -->
            <?php endif; ?>
          </div>
        </div>

        <!-- Perbandingan Amount Antar Bulan -->
        <div class="card card-warning card-outline mb-4" id="perbandingan-amount-antar-bulan">
          <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-arrow-left-right"></i>
            <h3 class="card-title mb-0">Perbandingan Amount Antar Bulan</h3>
          </div>
          <div class="card-body">
            <!-- <p class="text-muted small">
              Bandingkan nilai <code>Amount in Local Currency</code> per aset antara 2 bulan mana saja
              (gak harus berurutan, misal Januari vs April) untuk mencari aset yang nilainya berubah tidak wajar.
            </p> -->
            <form method="get" class="row g-2 align-items-end mb-3" id="formFilterAnomBulan">
              <input type="hidden" name="tahun" value="<?php echo htmlspecialchars($tahunFilter); ?>">
              <input type="hidden" name="bulan" value="<?php echo htmlspecialchars($bulanFilter === 'Semua' ? '' : $bulanFilter); ?>">
              <div class="col-auto">
                <label class="form-label mb-0 small text-muted">Bulan Pertama</label>
                <select name="bulan_a" class="form-select form-select-sm" onchange="submitFormAnomBulan(this)">
                  <?php foreach ($listBulan as $b): ?>
                  <option value="<?php echo $b; ?>" <?php echo ($b === $bulanAnomA) ? 'selected' : ''; ?>><?php echo $namaBulan[$b] ?? $b; ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-auto">
                <label class="form-label mb-0 small text-muted">Bulan Kedua</label>
                <select name="bulan_b" class="form-select form-select-sm" onchange="submitFormAnomBulan(this)">
                  <?php foreach ($listBulan as $b): ?>
                  <option value="<?php echo $b; ?>" <?php echo ($b === $bulanAnomB) ? 'selected' : ''; ?>><?php echo $namaBulan[$b] ?? $b; ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-auto">
                <!-- <label class="form-label mb-0 small text-muted">Sembunyikan selisih antara -X s.d. X</label>
                <input type="number" step="any" min="0" name="min_selisih" class="form-control form-control-sm" style="width:150px;" value="<?php echo htmlspecialchars($minSelisih); ?>" placeholder="0 = tampilkan semua">
                <small class="text-muted d-block" style="font-size:.72rem;">Menyembunyikan selisih antara -<?php echo htmlspecialchars($minSelisih); ?> s.d. <?php echo htmlspecialchars($minSelisih); ?></small>
              </div>
              <div class="col-auto form-check ms-2 mb-1">
                <input type="checkbox" class="form-check-input" id="hanyaBeda" name="hanya_beda" value="1" <?php echo $hanyaBeda ? 'checked' : ''; ?>> -->
                <!-- <label class="form-check-label small" for="hanyaBeda">Hanya tampilkan yang berubah (sembunyikan yang "Tetap")</label> -->
              </div>
              <!-- <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Cari Selisih</button>
              </div> -->
            </form>

            <?php if ($bulanAnomA === null || $bulanAnomB === null): ?>
              <p class="text-muted mb-0">Belum ada cukup data bulan di tahun ini untuk dibandingkan.</p>
            <?php elseif (empty($anomaliRowsPaged)): ?>
              <p class="text-muted mb-0">Tidak ada aset yang berbeda antara <?php echo htmlspecialchars($namaBulan[$bulanAnomA] ?? $bulanAnomA); ?> dan <?php echo htmlspecialchars($namaBulan[$bulanAnomB] ?? $bulanAnomB); ?>.</p>
            <?php else: ?>
              <!-- <small class="text-muted d-block mb-2">
                Menampilkan <?php echo count($anomaliRowsPaged); ?> dari <?php echo number_format($totalAnomali, 0, ',', '.'); ?> aset —
                dibandingkan antara <strong><?php echo htmlspecialchars($namaBulan[$bulanAnomA] ?? $bulanAnomA); ?></strong> vs
                <strong><?php echo htmlspecialchars($namaBulan[$bulanAnomB] ?? $bulanAnomB); ?></strong>,
                diurutkan dari selisih paling besar duluan.
              </small> -->
              <?php
                // qBaseAnom (tanpa sort_by/sort_dir) dipakai untuk link sorting header (sort_link akan menambahkan sendiri)
                $qBaseAnom = 'tahun=' . urlencode($tahunFilter) . '&bulan=' . urlencode($bulanFilter === 'Semua' ? '' : $bulanFilter)
                            . '&bulan_a=' . urlencode($bulanAnomA) . '&bulan_b=' . urlencode($bulanAnomB)
                            . '&hanya_beda=' . ($hanyaBeda ? '1' : '0')
                            . '&min_selisih=' . urlencode($minSelisih);
                // qBaseAnomPage (dengan sort_by/sort_dir aktif) dipakai untuk link pindah halaman
                $qBaseAnomPage = $qBaseAnom . ($sortByAnom !== '' ? '&sort_by=' . urlencode($sortByAnom) . '&sort_dir=' . urlencode($sortDirAnom) : '');
              ?>
              <div class="table-responsive">
              <table class="table table-bordered align-middle mb-2 w-100 tabel-perbandingan" style="table-layout:auto;">
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
                    <td class="text-center">
                      <?php
                        $clsA = $ar['status'] === 'Naik' ? 'badge-naik' : ($ar['status'] === 'Turun' ? 'badge-turun' : ($ar['status'] === 'Tetap' ? 'badge-tetap' : 'badge-nodat'));
                        $popTitle = $ar['kategori'] ?: $ar['status'];
                        $popBody  = $ar['ket_detail'] ?: 'Tidak ada perubahan kategori khusus yang terdeteksi dari data DAT untuk perubahan ini (kemungkinan penyusutan berjalan normal).';
                      ?>
                      <span class="badge <?php echo $clsA; ?> anom-status-badge" style="cursor:pointer; white-space:normal;"
                            data-kategori="<?php echo htmlspecialchars($popTitle); ?>"
                            data-ket="<?php echo htmlspecialchars($popBody); ?>">
                        <?php echo htmlspecialchars($popTitle); ?> <i class="bi bi-info-circle" style="font-size:.75em;"></i>
                      </span>
                    </td>
                    <td>
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
                             placeholder="Tulis catatan..." style="min-width:160px;">
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              </div>
              <!-- Popover ringan tanpa dependency JS tambahan, dipakai untuk keterangan kategori per baris -->
              <div id="anomPopover" class="card shadow" style="display:none; position:fixed; z-index:9999; width:320px; max-width:90vw;">
                <div class="card-body p-2">
                  <div class="fw-bold small mb-1" id="anomPopoverTitle"></div>
                  <div class="small text-muted" id="anomPopoverBody" style="white-space:pre-line; max-height:260px; overflow-y:auto;"></div>
                  <button type="button" class="btn-close btn-close-sm position-absolute top-0 end-0 m-1" style="font-size:.6rem;" id="anomPopoverClose" aria-label="Tutup"></button>
                </div>
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
          </div>
        </div>

        <!-- Pivot Account x Bulan (spt PivotTable Excel) -->
        <div class="card card-primary card-outline mb-4">
          <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-diagram-3"></i>
            <h3 class="card-title mb-0">Rekap GL Account</h3>
            <span class="badge bg-primary rounded-pill">Tahun <?php echo htmlspecialchars($tahunFilter); ?></span>
          </div>
          <div class="card-body table-responsive">
            <?php if (empty($listBulan) || empty($pivotData)): ?>
              <p class="text-muted mb-0">Belum ada data untuk tahun ini.</p>
            <?php else: ?>
            <table class="table table-bordered table-sm align-middle mb-2" style="min-width:900px;">
              <thead class="table-light">
                <tr>
                  <th>GL Account</th>
                  <th>Keterangan Aset</th>
                  <?php foreach ($listBulan as $b): ?>
                    <th class="text-end"><?php echo $namaBulan[$b] ?? $b; ?></th>
                  <?php endforeach; ?>
                  <th class="text-end">Grand Total</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($pivotData as $acc => $perBulan):
                    $rowTotal = 0; $prevVal = null;
                    $keterangan = $pivotKeterangan[$acc] ?? '-';
                    $accId = 'accdet_' . md5($acc);
                ?>
                <tr class="acc-row" style="cursor:pointer;" data-account="<?php echo htmlspecialchars($acc); ?>" data-target="<?php echo $accId; ?>" data-tahun="<?php echo (int)$tahunFilter; ?>" title="Klik untuk lihat detail Cabang &amp; Profit Center">
                  <td class="fw-semibold text-nowrap"><i class="bi bi-caret-right-fill acc-caret me-1 text-muted" style="font-size:.7em;"></i><?php echo htmlspecialchars($acc); ?></td>
                  <td><?php echo htmlspecialchars($keterangan); ?></td>
                  <?php foreach ($listBulan as $b):
                      $val = $perBulan[$b] ?? null;
                      $cellClass = '';
                      if ($val !== null) {
                          $rowTotal += $val;
                          if ($prevVal !== null) {
                              if ($val > $prevVal) $cellClass = 'bg-success bg-opacity-25 text-success fw-semibold';
                              elseif ($val < $prevVal) $cellClass = 'bg-danger bg-opacity-25 text-danger fw-semibold';
                          }
                          $prevVal = $val;
                      }
                  ?>
                    <td class="text-end <?php echo $cellClass; ?>"><?php echo $val !== null ? fmt_rp($val) : '-'; ?></td>
                  <?php endforeach; ?>
                  <td class="text-end fw-bold"><?php echo fmt_rp($rowTotal); ?></td>
                </tr>
                <tr class="acc-detail-row d-none" id="<?php echo $accId; ?>">
                  <td colspan="<?php echo 3 + count($listBulan); ?>" class="p-0">
                    <div class="acc-detail-body bg-light p-3 border-top border-bottom">
                      <div class="text-center text-muted small py-2">Memuat detail...</div>
                    </div>
                  </td>
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
            <small class="text-muted">
              <i class="bi bi-square-fill text-success"></i> Hijau = naik dari bulan sebelumnya &nbsp;&nbsp;
              <i class="bi bi-square-fill text-danger"></i> Merah = turun dari bulan sebelumnya
            </small>
            <?php endif; ?>
          </div>
        </div>

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
// ── Submit form filter bulan pada card "Perbandingan Amount Antar Bulan" via JS (bukan
// this.form.submit() biasa) supaya setelah reload, browser langsung scroll balik ke
// card ini (anchor #perbandingan-amount-antar-bulan) -- gak perlu scroll ulang manual. ──
function submitFormAnomBulan(el) {
  const form = el.form;
  const params = new URLSearchParams(new FormData(form));
  window.location.href = window.location.pathname + '?' + params.toString() + '#perbandingan-amount-antar-bulan';
}

document.addEventListener('DOMContentLoaded', function () {

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

  // ── Auto-save Catatan (Perbandingan Amount Antar Bulan) ──
  (function () {
    document.querySelectorAll('.catatan-input').forEach(function (inp) {
      let orig = inp.value;
      inp.addEventListener('blur', function () {
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
        fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            inp.disabled = false;
            if (res && res.ok) {
              orig = inp.value;
              inp.classList.remove('is-invalid');
              inp.classList.add('is-valid');
              setTimeout(function () { inp.classList.remove('is-valid'); }, 1200);
            } else {
              inp.classList.add('is-invalid');
            }
          })
          .catch(function () {
            inp.disabled = false;
            inp.classList.add('is-invalid');
          });
      });
      // Simpan juga saat tekan Enter
      inp.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); inp.blur(); }
      });
    });
  })();

  // ── Klik baris GL Account (Rekap per Account) untuk lihat detail Cabang & Profit Center ──
  (function () {
    function escHtml(s) {
      const d = document.createElement('div');
      d.textContent = (s === null || s === undefined) ? '' : String(s);
      return d.innerHTML;
    }
    function fmtRpJs(n) {
      return new Intl.NumberFormat('id-ID').format(Math.round(n || 0));
    }

    document.querySelectorAll('.acc-row').forEach(function (row) {
      row.addEventListener('click', function () {
        const detailRow = document.getElementById(row.dataset.target);
        if (!detailRow) return;
        const caret = row.querySelector('.acc-caret');
        const sedangTersembunyi = detailRow.classList.contains('d-none');

        if (!sedangTersembunyi) {
          detailRow.classList.add('d-none');
          if (caret) { caret.classList.remove('bi-caret-down-fill'); caret.classList.add('bi-caret-right-fill'); }
          return;
        }

        detailRow.classList.remove('d-none');
        if (caret) { caret.classList.remove('bi-caret-right-fill'); caret.classList.add('bi-caret-down-fill'); }
        if (detailRow.dataset.loaded === '1') return;

        const body = detailRow.querySelector('.acc-detail-body');
        const fd = new FormData();
        fd.append('action', 'get_account_detail');
        fd.append('account', row.dataset.account);
        fd.append('tahun', row.dataset.tahun);

        fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            detailRow.dataset.loaded = '1';
            if (!res || !res.ok) {
              body.innerHTML = '<div class="text-center text-danger small py-2">Gagal memuat detail' + (res && res.error ? ': ' + escHtml(res.error) : '') + '.</div>';
              return;
            }
            if (!res.rows || res.rows.length === 0) {
              body.innerHTML = '<div class="text-center text-muted small py-2">Tidak ada data detail untuk account ini.</div>';
              return;
            }
            const ketAcc = res.keterangan || '-';
            const bulanList = res.bulan || [];
            let html = '<div class="small text-muted mb-2">Rincian Cabang &amp; Profit Center untuk Account <strong>' + escHtml(row.dataset.account) + '</strong> (' + escHtml(ketAcc) + ') tahun ' + escHtml(row.dataset.tahun) + ':</div>';
            html += '<div class="table-responsive"><table class="table table-sm table-bordered bg-white mb-0">';
            html += '<thead class="table-secondary"><tr>' +
                    '<th>Cabang</th>' +
                    '<th>Profit Center</th>' +
                    '<th>Keterangan Aset</th>' +
                    '<th class="text-end">Jumlah Aset</th>';
            bulanList.forEach(function (bl) {
              html += '<th class="text-end">' + escHtml(bl.label) + '</th>';
            });
            html += '<th class="text-end">Total Amount</th>' +
                    '</tr></thead><tbody>';
            res.rows.forEach(function (rr) {
              html += '<tr>' +
                      '<td>' + escHtml(rr.cabang) + '</td>' +
                      '<td>' + escHtml(rr.profit_center) + '</td>' +
                      '<td>' + escHtml(rr.keterangan_asset) + '</td>' +
                      '<td class="text-end">' + escHtml(rr.jml_aset) + '</td>';
              bulanList.forEach(function (bl) {
                const v = (rr.per_bulan && rr.per_bulan[bl.no] !== undefined) ? rr.per_bulan[bl.no] : 0;
                html += '<td class="text-end">' + fmtRpJs(v) + '</td>';
              });
              html += '<td class="text-end">' + fmtRpJs(rr.total) + '</td>' +
                      '</tr>';
            });
            html += '</tbody><tfoot><tr class="table-light fw-bold"><td colspan="4">Grand Total</td>';
            bulanList.forEach(function (bl) {
              const gv = (res.grand_total_bulan && res.grand_total_bulan[bl.no] !== undefined) ? res.grand_total_bulan[bl.no] : 0;
              html += '<td class="text-end">' + fmtRpJs(gv) + '</td>';
            });
            html += '<td class="text-end">' + fmtRpJs(res.grand_total) + '</td></tr></tfoot>';
            html += '</table></div>';
            body.innerHTML = html;
          })
          .catch(function () {
            detailRow.dataset.loaded = '';
            body.innerHTML = '<div class="text-center text-danger small py-2">Gagal memuat detail (koneksi bermasalah).</div>';
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