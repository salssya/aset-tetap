<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "asetreg3_db";

$con = mysqli_connect($servername, $username, $password, $dbname);
session_start();
if (!isset($_SESSION["nipp"]) || !isset($_SESSION["name"])) {
    header("Location: ../login/login_view.php");
    exit();
}

// ==========================================================================
// ================  KONFIGURASI PEMETAAN KATEGORI ASET  ===================
// ==========================================================================
// PENTING -- BACA INI DULU SEBELUM DIPAKAI:
// Kode "account" di bawah ini (5040xxxxxx) adalah kode GL Account BEBAN PENYUSUTAN yang
// muncul di tabel import_fagll (REKAP FAGLL). Kode ini dipakai sebagai KUNCI PENGELOMPOKAN
// (proxy "aset ini kategorinya apa"), dengan asumsi tiap aset cuma disusutkan ke SATU akun
// beban sesuai kategorinya. Kode akun NERACA (1205xxxxxx, kolom paling kiri tabel) dipetakan
// manual di sini cuma untuk keperluan TAMPILAN, mengikuti contoh template yang diberikan.
//
// ⚠ Kode akun "Kendaraan" (5040201070) BELUM DIKONFIRMASI -- itu tebakan berdasarkan pola
//   urutan kode yang lain. Mohon dicek ke Rekap GL Account di Dasbor Penyusutan dan disesuaikan
//   kalau salah.
// ⚠ "Tanah" tidak disusutkan sehingga TIDAK PUNYA kode akun beban penyusutan di FAGLL. Baris
//   Tanah di bagian Harga Perolehan memakai heuristik: aset DAT yang kolom "GL Account Exp
//   Depre"-nya KOSONG dianggap Tanah. Kolom Penambahan/Pengurangan/Reklasifikasi utk Tanah
//   TIDAK bisa di-lookup otomatis dari AR02 reg3 (AR02 tidak punya info kategori aset yang bisa
//   disambungkan tanpa GL Account), jadi untuk sementara diisi 0 -- kolom "30 Juni" utk Tanah
//   dihitung dari rollforward (bukan lookup FAGLL, karena tidak ada baris FAGLL utk Tanah).
// ⚠ Bagian "Penurunan Nilai (CKPN)" -- sumber datanya BELUM ditentukan (tidak disebutkan di
//   instruksi), jadi baris & label-nya sudah dibuat sesuai template, tapi ANGKANYA MASIH 0.
// ==========================================================================
$ACCOUNT_CATEGORY_MAP = [
    '5040201010' => ['nama_id' => 'Bangunan fasilitas pelabuhan',      'nama_en' => 'Port facilities',     'kode_hp' => '1205010200', 'kode_ap' => '1205010201', 'kode_ckpn' => '1205010202'],
    '5040201020' => ['nama_id' => 'Kapal',                              'nama_en' => 'Vessels',             'kode_hp' => '1205010300', 'kode_ap' => '1205010301', 'kode_ckpn' => '1205010302'],
    '5040201030' => ['nama_id' => 'Alat fasilitas pelabuhan',           'nama_en' => 'Port equipment',      'kode_hp' => '1205010400', 'kode_ap' => '1205010401', 'kode_ckpn' => '1205010402'],
    '5040201040' => ['nama_id' => 'Instalasi fasilitas pelabuhan',      'nama_en' => 'Port installation',   'kode_hp' => '1205010500', 'kode_ap' => '1205010501', 'kode_ckpn' => '1205010502'],
    '5040201050' => ['nama_id' => 'Jalan dan bangunan',                 'nama_en' => 'Roads and buildings', 'kode_hp' => '1205010600', 'kode_ap' => '1205010601', 'kode_ckpn' => '1205010602'],
    '5040201060' => ['nama_id' => 'Peralatan',                          'nama_en' => 'Equipment',           'kode_hp' => '1205010700', 'kode_ap' => '1205010701', 'kode_ckpn' => '1205010702'],
    '5040201070' => ['nama_id' => 'Kendaraan',                          'nama_en' => 'Vehicles',            'kode_hp' => '1205010800', 'kode_ap' => '1205010801', 'kode_ckpn' => '1205010802'], // ⚠ kode akun blm terkonfirmasi
    '5040201080' => ['nama_id' => 'Emplasemen',                         'nama_en' => 'Emplacement',         'kode_hp' => '1205010900', 'kode_ap' => '1205010901', 'kode_ckpn' => '1205010902'],
];
$KODE_HP_TANAH = '1205010100';

// ==========================================================================
// ==========================  FILTER PERIODE  =============================
// ==========================================================================
$defTahunAwal = date('Y') - 1;
$defTahunAkhir = date('Y');
$defBulanAkhir = (int)date('n');

$resMaxDat = mysqli_query($con, "SHOW TABLES LIKE 'import_dat_monitoring'");
if ($resMaxDat && mysqli_num_rows($resMaxDat) > 0) {
    $r = mysqli_query($con, "SELECT MAX(tahun_buku) as mx FROM import_dat_monitoring WHERE tahun_buku IS NOT NULL AND tahun_buku <> ''");
    if ($r && ($row = mysqli_fetch_assoc($r)) && $row['mx']) { $defTahunAwal = (int)$row['mx']; }
}
$resMaxFagll = mysqli_query($con, "SHOW TABLES LIKE 'import_fagll'");
if ($resMaxFagll && mysqli_num_rows($resMaxFagll) > 0) {
    $r = mysqli_query($con, "SELECT MAX(posting_date_norm) as mx FROM import_fagll WHERE posting_date_norm IS NOT NULL");
    if ($r && ($row = mysqli_fetch_assoc($r)) && $row['mx']) {
        $dtMax = new DateTime($row['mx']);
        $defTahunAkhir = (int)$dtMax->format('Y');
        $defBulanAkhir = (int)$dtMax->format('n');
    }
}

$tahunAwal   = isset($_GET['tahun_awal']) && ctype_digit($_GET['tahun_awal']) ? (int)$_GET['tahun_awal'] : $defTahunAwal;
$tahunAkhir  = isset($_GET['tahun_akhir']) && ctype_digit($_GET['tahun_akhir']) ? (int)$_GET['tahun_akhir'] : $defTahunAkhir;
$bulanAkhir  = isset($_GET['bulan_akhir']) && ctype_digit($_GET['bulan_akhir']) ? (int)$_GET['bulan_akhir'] : $defBulanAkhir;
if ($bulanAkhir < 1) $bulanAkhir = 1;
if ($bulanAkhir > 12) $bulanAkhir = 12;

$namaBulanMap = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
$namaBulanEnMap = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
$labelAwal  = "31 Desember $tahunAwal";
$labelAwalEn = "December 31, $tahunAwal";
$labelAkhirTanggal = date('t', mktime(0, 0, 0, $bulanAkhir, 1, $tahunAkhir));
$labelAkhir = "$labelAkhirTanggal " . $namaBulanMap[$bulanAkhir] . " $tahunAkhir";
$labelAkhirEn = $namaBulanEnMap[$bulanAkhir] . " $labelAkhirTanggal, $tahunAkhir";

// ==========================================================================
// ========================  HELPER SQL & KOMPUTASI  =======================
// ==========================================================================
function numExpr($col) {
    // Cast aman ke angka: kalau bukan format angka valid (mis. "-", kosong, teks), dianggap 0.
    return "CASE WHEN REPLACE(TRIM($col), ',', '') REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' " .
           "THEN CAST(REPLACE(TRIM($col), ',', '') AS DECIMAL(24,2)) ELSE 0 END";
}

function tabelAda($con, $nama) {
    $r = mysqli_query($con, "SHOW TABLES LIKE '" . mysqli_real_escape_string($con, $nama) . "'");
    return $r && mysqli_num_rows($r) > 0;
}

$adaDat   = tabelAda($con, 'import_dat_monitoring');
$adaAr02  = tabelAda($con, 'import_ar02_reg3');
$adaFagll = tabelAda($con, 'import_fagll');
$adaF01   = tabelAda($con, 'import_data_f01');

// Peta (asset, asset_subnumber) -> account, diturunkan dari REKAP FAGLL. Dipakai buat
// nyambungin baris DAT & AR02 reg3 (yang gak punya field akun beban langsung) ke kategori
// yang sama. Disimpan di temporary table biar gak query ulang GROUP BY 34rb baris tiap kali.
if ($adaFagll) {
    mysqli_query($con, "DROP TEMPORARY TABLE IF EXISTS tmp_asset_account");
    mysqli_query($con, "CREATE TEMPORARY TABLE tmp_asset_account
        SELECT asset, asset_subnumber, MAX(account) as acc FROM import_fagll GROUP BY asset, asset_subnumber");
    mysqli_query($con, "ALTER TABLE tmp_asset_account ADD PRIMARY KEY (asset, asset_subnumber)");
}

function sumDatByAccount($con, $adaDat, $adaFagll, $accountCode, $tahunAwal, $field) {
    if (!$adaDat || !$adaFagll) return 0.0;
    $expr = numExpr("d.$field");
    $accEsc = mysqli_real_escape_string($con, $accountCode);
    $sql = "SELECT SUM($expr) as total FROM import_dat_monitoring d
            INNER JOIN tmp_asset_account fam ON fam.asset = d.nomor_asset AND fam.asset_subnumber = d.sub_number
            WHERE fam.acc = '$accEsc' AND d.tahun_buku = '" . (int)$tahunAwal . "'";
    $res = mysqli_query($con, $sql);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return ($row && $row['total'] !== null) ? (float)$row['total'] : 0.0;
}

function sumDatTanah($con, $adaDat, $tahunAwal, $field) {
    if (!$adaDat) return 0.0;
    $expr = numExpr($field);
    $sql = "SELECT SUM($expr) as total FROM import_dat_monitoring
            WHERE (gl_account_exp IS NULL OR TRIM(gl_account_exp) = '') AND tahun_buku = '" . (int)$tahunAwal . "'";
    $res = mysqli_query($con, $sql);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return ($row && $row['total'] !== null) ? (float)$row['total'] : 0.0;
}

function sumAr02ByAccount($con, $adaAr02, $adaFagll, $accountCode, $tahunAkhir, $bulanAkhir, $field) {
    if (!$adaAr02 || !$adaFagll) return 0.0;
    $expr = numExpr("a.$field");
    $accEsc = mysqli_real_escape_string($con, $accountCode);
    $sql = "SELECT SUM($expr) as total FROM import_ar02_reg3 a
            INNER JOIN tmp_asset_account fam ON fam.asset = a.nomor_asset AND fam.asset_subnumber = a.sub_number
            WHERE fam.acc = '$accEsc' AND a.periode_tahun = '" . (int)$tahunAkhir . "'
              AND CAST(a.periode_bulan AS UNSIGNED) <= " . (int)$bulanAkhir;
    $res = mysqli_query($con, $sql);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return ($row && $row['total'] !== null) ? (float)$row['total'] : 0.0;
}

function sumFagllByAccount($con, $adaFagll, $accountCode, $tahunAkhir, $bulanAkhir) {
    if (!$adaFagll) return 0.0;
    $accEsc = mysqli_real_escape_string($con, $accountCode);
    $expr = numExpr("amount_local_currency");
    $sql = "SELECT SUM($expr) as total FROM import_fagll
            WHERE account = '$accEsc' AND YEAR(posting_date_norm) = " . (int)$tahunAkhir . "
              AND MONTH(posting_date_norm) <= " . (int)$bulanAkhir;
    $res = mysqli_query($con, $sql);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return ($row && $row['total'] !== null) ? (float)$row['total'] : 0.0;
}

function sumF01ByAccount($con, $adaF01, $accountCode, $bulan, $tahun) {
    if (!$adaF01) return 0.0;
    $expr = numExpr("total_reporting_period");
    $accEsc = mysqli_real_escape_string($con, $accountCode);
    $sql = "SELECT SUM($expr) as total FROM import_data_f01
            WHERE account_number = '$accEsc' AND periode_bulan = '" . (int)$bulan . "' AND periode_tahun = '" . (int)$tahun . "'";
    $res = mysqli_query($con, $sql);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return ($row && $row['total'] !== null) ? (float)$row['total'] : 0.0;
}

function hitungBarisCkpn($con, $adaF01, $adaFagll, $accountCode, $tahunAwal, $tahunAkhir, $bulanAkhir) {
    // 31 Desember: lookup dari import_data_f01 (per akhir tahun buku sebelumnya = bulan 12)
    $awal = sumF01ByAccount($con, $adaF01, $accountCode, 12, $tahunAwal);
    // Penambahan/Pengurangan/Reklasifikasi CKPN: memang selalu 0 (dash), gak ada lookup
    $penambahan = 0.0; $pengurangan = 0.0; $reklasifikasi = 0.0;
    // 30 Juni: lookup dari REKAP FAGLL, berdasarkan account yang sama
    $akhir = sumFagllByAccount($con, $adaFagll, $accountCode, $tahunAkhir, $bulanAkhir);
    return ['awal' => $awal, 'penambahan' => $penambahan, 'pengurangan' => $pengurangan, 'reklasifikasi' => $reklasifikasi, 'akhir' => $akhir];
}

function hitungBaris($con, $adaDat, $adaAr02, $adaFagll, $accountCode, $tahunAwal, $tahunAkhir, $bulanAkhir, $fieldAwal, $fieldPenambahan, $fieldPengurangan, $fieldReklasifikasi) {
    $awal = sumDatByAccount($con, $adaDat, $adaFagll, $accountCode, $tahunAwal, $fieldAwal);
    $penambahan = sumAr02ByAccount($con, $adaAr02, $adaFagll, $accountCode, $tahunAkhir, $bulanAkhir, $fieldPenambahan);
    $pengurangan = sumAr02ByAccount($con, $adaAr02, $adaFagll, $accountCode, $tahunAkhir, $bulanAkhir, $fieldPengurangan);
    $reklasifikasi = sumAr02ByAccount($con, $adaAr02, $adaFagll, $accountCode, $tahunAkhir, $bulanAkhir, $fieldReklasifikasi);
    $akhirFagll = sumFagllByAccount($con, $adaFagll, $accountCode, $tahunAkhir, $bulanAkhir);
    $akhirHitung = $awal + $penambahan - $pengurangan + $reklasifikasi;
    return [
        'awal' => $awal, 'penambahan' => $penambahan, 'pengurangan' => $pengurangan,
        'reklasifikasi' => $reklasifikasi, 'akhir' => $akhirFagll, 'akhir_hitung' => $akhirHitung,
        'selisih' => $akhirFagll - $akhirHitung,
    ];
}

// ── Bagian Harga Perolehan ──
$barisHP = [];
$tanahAwal = sumDatTanah($con, $adaDat, $tahunAwal, 'nilai_perolehan_sd_tahun_berjalan');
$barisHP[] = [
    'kode' => $KODE_HP_TANAH, 'nama_id' => 'Tanah', 'nama_en' => 'Land',
    'awal' => $tanahAwal, 'penambahan' => 0, 'pengurangan' => 0, 'reklasifikasi' => 0,
    'akhir' => $tanahAwal, 'akhir_hitung' => $tanahAwal, 'selisih' => 0, 'catatan' => 'tanah',
];
foreach ($ACCOUNT_CATEGORY_MAP as $accCode => $cat) {
    $h = hitungBaris($con, $adaDat, $adaAr02, $adaFagll, $accCode, $tahunAwal, $tahunAkhir, $bulanAkhir,
        'nilai_perolehan_sd_tahun_berjalan', 'acquisition', 'retirement', 'transfers');
    $barisHP[] = array_merge(['kode' => $cat['kode_hp'], 'nama_id' => $cat['nama_id'], 'nama_en' => $cat['nama_en'], 'catatan' => ''], $h);
}

// ── Bagian Akumulasi Penyusutan (Tanah tidak disusutkan, tidak ada barisnya) ──
$barisAP = [];
foreach ($ACCOUNT_CATEGORY_MAP as $accCode => $cat) {
    $h = hitungBaris($con, $adaDat, $adaAr02, $adaFagll, $accCode, $tahunAwal, $tahunAkhir, $bulanAkhir,
        'akumulasi_penyusutan', 'dep_fy_start', 'dep_retir', 'dep_transfer');
    $barisAP[] = array_merge(['kode' => $cat['kode_ap'], 'nama_id' => $cat['nama_id'], 'nama_en' => $cat['nama_en'], 'catatan' => ''], $h);
}

// ── Bagian Penurunan Nilai / CKPN ──
// 31 Des: lookup import_data_f01 (kolom total_reporting_period, berdasarkan kode akun CKPN
// langsung -- account_number di tabel ini SUDAH berupa kode GL Account 1205xxxxx02).
// Penambahan/Pengurangan/Reklasifikasi: memang selalu 0 sesuai instruksi.
// 30 Juni: lookup REKAP FAGLL (kolom amount_local_currency), berdasarkan account yang sama.
$barisCKPN = [];
$hTanahCkpn = hitungBarisCkpn($con, $adaF01, $adaFagll, '1205010102', $tahunAwal, $tahunAkhir, $bulanAkhir);
$barisCKPN[] = array_merge(['kode' => '1205010102', 'nama_id' => 'CKPN Tanah', 'nama_en' => 'Land Impairment'], $hTanahCkpn);
foreach ($ACCOUNT_CATEGORY_MAP as $accCode => $cat) {
    $hCkpn = hitungBarisCkpn($con, $adaF01, $adaFagll, $cat['kode_ckpn'], $tahunAwal, $tahunAkhir, $bulanAkhir);
    $barisCKPN[] = array_merge(['kode' => $cat['kode_ckpn'], 'nama_id' => 'CKPN ' . $cat['nama_id'], 'nama_en' => $cat['nama_en'] . ' Impairment'], $hCkpn);
}

function subtotal($baris, $kolom) {
    $t = 0;
    foreach ($baris as $b) { $t += $b[$kolom]; }
    return $t;
}
$subHP = ['awal' => subtotal($barisHP, 'awal'), 'penambahan' => subtotal($barisHP, 'penambahan'), 'pengurangan' => subtotal($barisHP, 'pengurangan'), 'reklasifikasi' => subtotal($barisHP, 'reklasifikasi'), 'akhir' => subtotal($barisHP, 'akhir')];
$subAP = ['awal' => subtotal($barisAP, 'awal'), 'penambahan' => subtotal($barisAP, 'penambahan'), 'pengurangan' => subtotal($barisAP, 'pengurangan'), 'reklasifikasi' => subtotal($barisAP, 'reklasifikasi'), 'akhir' => subtotal($barisAP, 'akhir')];
$subCKPN = ['awal' => subtotal($barisCKPN, 'awal'), 'penambahan' => subtotal($barisCKPN, 'penambahan'), 'pengurangan' => subtotal($barisCKPN, 'pengurangan'), 'reklasifikasi' => subtotal($barisCKPN, 'reklasifikasi'), 'akhir' => subtotal($barisCKPN, 'akhir')];

$nilaiBukuAwal  = $subHP['awal']  - abs($subAP['awal'])  - abs($subCKPN['awal']);
$nilaiBukuAkhir = $subHP['akhir'] - abs($subAP['akhir']) - abs($subCKPN['akhir']);

function fmtRp($n, $negatif = false) {
    $n = (float)$n;
    if (abs($n) < 0.5) return '-';
    $abs = number_format(abs($n), 0, ',', '.');
    return ($negatif || $n < 0) ? "- $abs" : $abs;
}
?>
<!doctype html>
<html lang="id">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Dasbor Rekonsiliasi Aset Tetap - Web Aset Tetap</title>
    <link rel="icon" type="image/png" href="../../dist/assets/img/emblem.png" />
    <link rel="shortcut icon" type="image/png" href="../../dist/assets/img/emblem.png" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes" />
    <meta name="color-scheme" content="light dark" />
    <meta name="theme-color" content="#007bff" media="(prefers-color-scheme: light)" />
    <meta name="theme-color" content="#1a1a1a" media="(prefers-color-scheme: dark)" />
    <link rel="stylesheet" href="../../dist/css/index.css"/>
    <link rel="stylesheet" href="../../dist/css/overlayscrollbars.min.css"/>
    <link rel="stylesheet" href="../../dist/css/bootstrap-icons/bootstrap-icons.min.css"/>
    <link rel="stylesheet" href="../../dist/css/adminlte.css" />
    <style>
      .app-sidebar { background-color: #0b3a8c !important; }
      .app-header, nav.app-header, .app-header.navbar { border-bottom: 0 !important; box-shadow: none !important; }
      .sidebar-brand { background-color: #0b3a8c !important; margin-bottom: 0 !important; padding: 0.25rem 0 !important; border-bottom: 0 !important; box-shadow: none !important; }
      .sidebar-brand .brand-link { display: block !important; padding: 0.5rem 0.75rem !important; border-bottom: 0 !important; box-shadow: none !important; background-color: transparent !important; }
      .sidebar-brand .brand-link .brand-image { display: block !important; height: auto !important; max-height: 48px !important; margin: 0 !important; padding: 6px 8px !important; background-color: transparent !important; }
      .app-sidebar { border-right: 0 !important; }
      .app-sidebar, .app-sidebar a, .app-sidebar .nav-link, .app-sidebar .nav-link p, .app-sidebar .nav-header, .app-sidebar .brand-text, .app-sidebar .nav-icon, .app-sidebar .nav-badge { color: #ffffff !important; fill: #ffffff !important; }
      .app-sidebar .nav-link .nav-icon, .app-sidebar .nav-link i { color: #ffffff !important; }
      .app-sidebar .nav-link.active, .app-sidebar .nav-link:hover { background-color: #0b5db7 !important; color: #ffffff !important; fill: #ffffff !important; }
      .app-sidebar .nav-link.active .nav-icon, .app-sidebar .nav-link:hover .nav-icon, .app-sidebar .nav-link.active i, .app-sidebar .nav-link:hover i { color: #ffffff !important; }
      .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; border: 1px solid #dee2e6; border-radius: 0.25rem; }

      .tabel-rekon { width: 100%; min-width: 1100px; border-collapse: collapse; font-size: 0.85rem; }
      .tabel-rekon th, .tabel-rekon td { padding: 6px 10px; border: 1px solid #dee2e6; white-space: nowrap; }
      .tabel-rekon thead th { background-color: #f1f3f5; text-align: center; vertical-align: middle; font-weight: 600; }
      .tabel-rekon td.kode { color: #6c757d; font-size: 0.8rem; }
      .tabel-rekon td.nama-id { text-align: left; font-weight: 500; }
      .tabel-rekon td.nama-en { text-align: left; color: #6c757d; font-style: italic; }
      .tabel-rekon td.angka { text-align: right; font-variant-numeric: tabular-nums; }
      .tabel-rekon tr.baris-section td { background-color: #dbe4f0; font-weight: 700; text-transform: uppercase; }
      .tabel-rekon tr.baris-jumlah td { background-color: #f8f9fa; font-weight: 700; border-top: 2px solid #495057; }
      .tabel-rekon tr.baris-neto td { background-color: #0b3a8c; color: #fff; font-weight: 700; border-top: 3px double #212529; }
      .tabel-rekon tr.baris-neto td.angka { color: #fff; }
      .catatan-box { font-size: 0.85rem; }
    </style>
    <link rel="stylesheet" href="../../dist/css/dataTables.dataTables.min.css" />
  </head>
  <body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
      <nav class="app-header navbar navbar-expand bg-white border-0 shadow-none" style="border-bottom:0!important;box-shadow:none!important;">
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
                <li class="user-menu-body">
                  <div class="row ps-3 pe-3 pt-2 pb-2 user-info">
                    <div class="col-6 text-start">
                      <small class="text-muted">Type User:</small><br>
                      <span class="badge bg-primary"><?php echo htmlspecialchars($_SESSION['Type_User']); ?></span>
                    </div>
                    <div class="col-6 text-end">
                      <small class="text-muted">Cabang:</small><br>
                      <span class="fw-semibold small"><p class="fw-semibold"><?php echo htmlspecialchars($_SESSION['Cabang'] . ' - ' . $_SESSION['profit_center_text']); ?></p></span>
                    </div>
                  </div>
                  <hr class="m-0"/>
                </li>
                <li class="user-footer d-flex align-items-center px-3 py-2">
                  <a href="../profile/profile.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-person"></i> Profile</a>
                  <a href="../login/login_view.php" class="btn btn-sm btn-danger ms-auto"><i class="bi bi-box-arrow-right"></i> Logout</a>
                </li>
              </ul>
          </ul>
        </div>
      </nav>
      <aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
        <div class="sidebar-brand">
          <a href="./index.html" class="brand-link">
            <img src="../../dist/assets/img/logo.png" class="brand-image" alt="Logo Pelindo" title="PT Pelabuhan Indonesia" />
          </a>
        </div>
        <div class="sidebar-wrapper">
          <nav class="mt-2">
            <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="navigation" aria-label="Main navigation" data-accordion="false" id="navigation">
            <?php
            $userNipp = isset($_SESSION['nipp']) ? htmlspecialchars($_SESSION['nipp']) : '';
            $query = "SELECT menus.menu, menus.nama_menu, menus.urutan_menu FROM user_access INNER JOIN menus ON user_access.id_menu = menus.id_menu WHERE user_access.NIPP = '" . mysqli_real_escape_string($con, $userNipp) . "' ORDER BY menus.urutan_menu ASC";
            $result_menu = mysqli_query($con, $query) or die(mysqli_error($con));
            $iconMap = [
                'Dasboard' => 'bi bi-grid-fill', 'Usulan Penghapusan' => 'bi bi-file-earmark-plus', 'Daftar Usulan Penghapusan' => 'bi bi-collection',
                'Approval SubReg' => 'bi bi-person-check', 'Approval Regional' => 'bi bi-building-check', 'Persetujuan Penghapusan' => 'bi bi-shield-check',
                'Daftar Persetujuan Penghapusan' => 'bi bi-journal-check', 'Pelaksanaan Penghapusan' => 'bi bi-gear-wide-connected', 'Daftar Pelaksanaan Penghapusan' => 'bi bi-archive-fill',
                'Manajemen Menu' => 'bi bi-layout-text-sidebar', 'Import DAT' => 'bi bi-file-earmark-arrow-up',
                'Import Data Penyusutan' => 'bi bi-upload', 'Daftar Data Penyusutan' => 'bi bi-table', 'Dasbor Monitoring Beban Penyusutan' => 'bi-bar-chart-line',
                'Import Data Monitoring' => 'bi bi-upload', 'Daftar Data Monitoring' => 'bi bi-table', 'Dasbor Monitoring SAP-DAT' => 'bi bi-speedometer2',
                'Dasbor Monitoring' => 'bi bi-clipboard-data', 'Dasbor Rekonsiliasi Aset Tetap' => 'bi bi-clipboard-data',
                'Daftar Aset Tetap' => 'bi bi-boxes', 'Manajemen User' => 'bi bi-people',
            ];
            $groupMap = [
                'Usulan Penghapusan' => 'Penghapusan', 'Daftar Usulan Penghapusan' => 'Penghapusan', 'Approval SubReg' => 'Penghapusan',
                'Approval Regional' => 'Penghapusan', 'Persetujuan Penghapusan' => 'Penghapusan', 'Daftar Persetujuan Penghapusan' => 'Penghapusan',
                'Pelaksanaan Penghapusan' => 'Penghapusan', 'Daftar Aset Tetap' => 'Penghapusan', 'Daftar Pelaksanaan Penghapusan' => 'Penghapusan',
                'Import Data Penyusutan' => 'Penyusutan', 'Daftar Data Penyusutan' => 'Penyusutan', 'Dasbor Monitoring Beban Penyusutan' => 'Penyusutan',
                'Import Data Monitoring' => 'Monitoring SAP-DAT', 'Daftar Data Monitoring' => 'Monitoring SAP-DAT', 'Dasbor Monitoring SAP-DAT' => 'Monitoring SAP-DAT',
                'Dasbor Monitoring' => 'Monitoring SAP-DAT', 'Dasbor Rekonsiliasi Aset Tetap' => 'Monitoring SAP-DAT',
                'Import DAT' => 'Manajemen Admin', 'Manajemen Menu' => 'Manajemen Admin', 'Manajemen User' => 'Manajemen Admin',
            ];
            $groupIcon = [
                'Penghapusan' => 'bi bi-file-earmark-minus', 'Penyusutan' => 'bi bi-graph-down-arrow',
                'Monitoring SAP-DAT' => 'bi bi-arrow-left-right', 'Manajemen Admin' => 'bi bi-sliders',
            ];
            $groupOrder = ['Penghapusan', 'Penyusutan', 'Monitoring SAP-DAT', 'Manajemen Admin'];

            $currentPage = basename($_SERVER['PHP_SELF']);
            $ungrouped = []; $grouped = [];
            while ($row = mysqli_fetch_assoc($result_menu)) {
                $namaMenu = trim($row['nama_menu']);
                if (isset($groupMap[$namaMenu])) { $grouped[$groupMap[$namaMenu]][] = $row; } else { $ungrouped[] = $row; }
            }
            foreach ($ungrouped as $row) {
                $namaMenu = trim($row['nama_menu']);
                $icon = $iconMap[$namaMenu] ?? 'bi bi-circle';
                $isActive = ($currentPage === $row['menu'] . '.php') ? 'active' : '';
                echo '<li class="nav-item"><a href="../' . $row['menu'] . '/' . $row['menu'] . '.php" class="nav-link ' . $isActive . '"><i class="nav-icon ' . $icon . '"></i><p>' . htmlspecialchars($namaMenu) . '</p></a></li>';
            }
            foreach ($groupOrder as $groupName) {
                if (empty($grouped[$groupName])) continue;
                $itemsGrup = $grouped[$groupName];
                $adaAktif = false;
                foreach ($itemsGrup as $itemG) { if ($currentPage === $itemG['menu'] . '.php') { $adaAktif = true; break; } }
                $liClassGrup = 'nav-item' . ($adaAktif ? ' menu-open' : '');
                $linkClassGrup = 'nav-link' . ($adaAktif ? ' active' : '');
                $iconGrup = $groupIcon[$groupName] ?? 'bi bi-folder';
                echo '<li class="' . $liClassGrup . '">';
                echo '<a href="#" class="' . $linkClassGrup . '"><i class="nav-icon ' . $iconGrup . '"></i><p>' . htmlspecialchars($groupName) . '<i class="nav-arrow bi bi-chevron-right"></i></p></a>';
                echo '<ul class="nav nav-treeview">';
                foreach ($itemsGrup as $itemG) {
                    $namaMenuG = trim($itemG['nama_menu']);
                    $iconItemG = $iconMap[$namaMenuG] ?? 'bi bi-circle';
                    $isActiveG = ($currentPage === $itemG['menu'] . '.php') ? 'active' : '';
                    echo '<li class="nav-item"><a href="../' . $itemG['menu'] . '/' . $itemG['menu'] . '.php" class="nav-link ' . $isActiveG . '"><i class="nav-icon ' . $iconItemG . '"></i><p>' . htmlspecialchars($namaMenuG) . '</p></a></li>';
                }
                echo '</ul></li>';
            }
            ?>
            </ul>
          </nav>
        </div>
      </aside>
      <main class="app-main">
        <div class="app-content-header">
          <div class="container-fluid">
            <div class="row">
              <div class="col-sm-6"><h3 class="mb-0">Dasbor Rekonsiliasi Aset Tetap</h3></div>
              <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                  <li class="breadcrumb-item"><a href="../dasbor/dasbor.php">Home</a></li>
                  <li class="breadcrumb-item active">Dasbor Rekonsiliasi Aset Tetap</li>
                </ol>
              </div>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">

            <?php if (!$adaDat || !$adaAr02 || !$adaFagll): ?>
            <div class="alert alert-warning">
              <strong>⚠ Ada sumber data yang belum lengkap:</strong>
              <?php if (!$adaDat) echo '<div>- Tabel DAT (import_dat_monitoring) belum ada / belum diupload.</div>'; ?>
              <?php if (!$adaAr02) echo '<div>- Tabel AR02 reg3 (import_ar02_reg3) belum ada / belum diupload.</div>'; ?>
              <?php if (!$adaFagll) echo '<div>- Tabel REKAP FAGLL (import_fagll) belum ada / belum diupload.</div>'; ?>
              Angka pada kolom yang sumbernya belum lengkap akan tampil sebagai "-".
            </div>
            <?php endif; ?>

            <!-- <div class="alert alert-info catatan-box">
              <strong><i class="bi bi-info-circle"></i> Catatan penting soal data di dasbor ini:</strong>
              <ul class="mb-0 mt-1">
                <li>Pengelompokan kategori aset (Harga Perolehan &amp; Akumulasi Penyusutan) memakai kode akun beban penyusutan dari <strong>REKAP FAGLL</strong> sebagai kunci penghubung ke DAT &amp; AR02 reg3 (lewat nomor aset + sub-number).</li>
                <li>Kode akun kategori <strong>Kendaraan</strong> (5040201070) masih tebakan berdasarkan pola kode lain -- mohon dicek &amp; disesuaikan di bagian atas file (variabel <code>$ACCOUNT_CATEGORY_MAP</code>) kalau salah.</li>
                <li>Baris <strong>Tanah</strong> (bagian Harga Perolehan) tidak disusutkan sehingga tidak ada di REKAP FAGLL. Nilai "31 Des" diambil dari aset DAT yang GL Account Exp Depre-nya kosong; kolom Penambahan/Pengurangan/Reklasifikasi belum bisa dihitung otomatis (masih 0) dan kolom "30 Juni" dihitung dari rollforward, bukan lookup FAGLL.</li>
                <li>Bagian <strong>Penurunan Nilai (CKPN)</strong>: kolom "31 Des" diambil dari <code>import_data_f01</code> (kolom <code>total_reporting_period</code>, dicocokkan langsung ke kode GL Account CKPN-nya), kolom Penambahan/Pengurangan/Reklasifikasi selalu 0, dan kolom "30 Juni" diambil dari REKAP FAGLL.</li>
              </ul>
            </div> -->

            <div class="card card-outline mb-4">
              <div class="card-body">
                <?php
                $labelDataTerbaru = $namaBulanMap[$defBulanAkhir] . ' ' . $defTahunAkhir;
                $iniDataTerbaru = ($bulanAkhir == $defBulanAkhir && $tahunAkhir == $defTahunAkhir);
                ?>
                <div class="alert <?php echo $iniDataTerbaru ? 'alert-success' : 'alert-warning'; ?> py-2 px-3 mb-3 d-flex align-items-center gap-2">
                  <i class="bi <?php echo $iniDataTerbaru ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?>"></i>
                  <?php if ($iniDataTerbaru): ?>
                    <span>Menampilkan <strong>data terbaru</strong> (posisi per <?php echo htmlspecialchars($labelDataTerbaru); ?>, otomatis mengikuti data terakhir di REKAP FAGLL).</span>
                  <?php else: ?>
                    <span>⚠ Kamu sedang melihat posisi <strong><?php echo $namaBulanMap[$bulanAkhir]; ?> <?php echo $tahunAkhir; ?></strong> -- <u>bukan</u> data terbaru. Data terbaru yang tersedia: <strong><?php echo htmlspecialchars($labelDataTerbaru); ?></strong>.</span>
                  <?php endif; ?>
                </div>
                <form method="GET" class="row g-2 align-items-end mb-3">
                  <div class="col-auto">
                    <label class="form-label mb-0 fw-semibold">Tahun Buku Awal (Saldo Awal)</label>
                    <input type="number" name="tahun_awal" class="form-control form-control-sm" value="<?php echo (int)$tahunAwal; ?>" style="width:110px;">
                  </div>
                  <div class="col-auto">
                    <label class="form-label mb-0 fw-semibold">Bulan Akhir (Saldo Akhir)</label>
                    <select name="bulan_akhir" class="form-select form-select-sm" style="width:140px;">
                      <?php foreach ($namaBulanMap as $num => $nm): ?>
                        <option value="<?php echo $num; ?>" <?php echo ($num == $bulanAkhir) ? 'selected' : ''; ?>><?php echo $nm; ?><?php echo ($num == $defBulanAkhir) ? ' (terbaru)' : ''; ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-auto">
                    <label class="form-label mb-0 fw-semibold">Tahun Akhir (Saldo Akhir)</label>
                    <input type="number" name="tahun_akhir" class="form-control form-control-sm" value="<?php echo (int)$tahunAkhir; ?>" style="width:110px;">
                  </div>
                  <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-arrow-repeat"></i> Terapkan</button>
                  </div>
                  <?php if (!$iniDataTerbaru): ?>
                  <div class="col-auto">
                    <a href="?tahun_awal=<?php echo (int)$tahunAwal; ?>&bulan_akhir=<?php echo (int)$defBulanAkhir; ?>&tahun_akhir=<?php echo (int)$defTahunAkhir; ?>" class="btn btn-outline-secondary btn-sm">
                      <i class="bi bi-arrow-counterclockwise"></i> Kembali ke data terbaru
                    </a>
                  </div>
                  <?php endif; ?>
                </form>

                <div class="table-responsive">
                  <table class="tabel-rekon">
                    <thead>
                      <tr>
                        <th rowspan="2" style="min-width:100px;">GL Account</th>
                        <th rowspan="2" style="min-width:220px;">ASET TETAP</th>
                        <th rowspan="2" style="min-width:150px;"><?php echo htmlspecialchars($labelAwal); ?><br><small class="fw-normal"><?php echo htmlspecialchars($labelAwalEn); ?></small></th>
                        <th colspan="3">Pergerakan Periode Berjalan / Movement This Period</th>
                        <th rowspan="2" style="min-width:150px;"><?php echo htmlspecialchars($labelAkhir); ?><br><small class="fw-normal"><?php echo htmlspecialchars($labelAkhirEn); ?></small></th>
                        <th rowspan="2" style="min-width:190px;">Kategori (EN)</th>
                      </tr>
                      <tr>
                        <th style="min-width:150px;">Penambahan<br><small class="fw-normal">Additions</small></th>
                        <th style="min-width:150px;">Pengurangan<br><small class="fw-normal">Deductions</small></th>
                        <th style="min-width:150px;">Reklasifikasi<br><small class="fw-normal">Transfers</small></th>
                      </tr>
                    </thead>
                    <tbody>

                      <!-- ============ HARGA PEROLEHAN ============ -->
                      <tr class="baris-section"><td colspan="8">Harga perolehan <span class="fw-normal text-lowercase">/ Acquisition cost</span></td></tr>
                      <?php foreach ($barisHP as $b): ?>
                      <tr>
                        <td class="kode"><?php echo htmlspecialchars($b['kode']); ?></td>
                        <td class="nama-id"><?php echo htmlspecialchars($b['nama_id']); ?></td>
                        <td class="angka"><?php echo fmtRp($b['awal']); ?></td>
                        <td class="angka"><?php echo fmtRp($b['penambahan']); ?></td>
                        <td class="angka"><?php echo fmtRp($b['pengurangan'], true); ?></td>
                        <td class="angka"><?php echo fmtRp($b['reklasifikasi']); ?></td>
                        <td class="angka"><?php echo fmtRp($b['akhir']); ?></td>
                        <td class="nama-en"><?php echo htmlspecialchars($b['nama_en']); ?></td>
                      </tr>
                      <?php endforeach; ?>
                      <tr class="baris-jumlah">
                        <td></td><td class="nama-id">Jumlah <span class="fw-normal text-lowercase">/ Total</span></td>
                        <td class="angka"><?php echo fmtRp($subHP['awal']); ?></td>
                        <td class="angka"><?php echo fmtRp($subHP['penambahan']); ?></td>
                        <td class="angka"><?php echo fmtRp($subHP['pengurangan'], true); ?></td>
                        <td class="angka"><?php echo fmtRp($subHP['reklasifikasi']); ?></td>
                        <td class="angka"><?php echo fmtRp($subHP['akhir']); ?></td>
                        <td class="nama-en">Total</td>
                      </tr>

                      <!-- ============ AKUMULASI PENYUSUTAN ============ -->
                      <tr class="baris-section"><td colspan="8">Akumulasi penyusutan <span class="fw-normal text-lowercase">/ Accumulated depreciation</span></td></tr>
                      <?php foreach ($barisAP as $b): ?>
                      <tr>
                        <td class="kode"><?php echo htmlspecialchars($b['kode']); ?></td>
                        <td class="nama-id"><?php echo htmlspecialchars($b['nama_id']); ?></td>
                        <td class="angka"><?php echo fmtRp($b['awal'], true); ?></td>
                        <td class="angka"><?php echo fmtRp($b['penambahan'], true); ?></td>
                        <td class="angka"><?php echo fmtRp($b['pengurangan']); ?></td>
                        <td class="angka"><?php echo fmtRp($b['reklasifikasi']); ?></td>
                        <td class="angka"><?php echo fmtRp($b['akhir'], true); ?></td>
                        <td class="nama-en"><?php echo htmlspecialchars($b['nama_en']); ?></td>
                      </tr>
                      <?php endforeach; ?>
                      <tr class="baris-jumlah">
                        <td></td><td class="nama-id">Jumlah <span class="fw-normal text-lowercase">/ Total</span></td>
                        <td class="angka"><?php echo fmtRp($subAP['awal'], true); ?></td>
                        <td class="angka"><?php echo fmtRp($subAP['penambahan'], true); ?></td>
                        <td class="angka"><?php echo fmtRp($subAP['pengurangan']); ?></td>
                        <td class="angka"><?php echo fmtRp($subAP['reklasifikasi']); ?></td>
                        <td class="angka"><?php echo fmtRp($subAP['akhir'], true); ?></td>
                        <td class="nama-en">Total</td>
                      </tr>

                      <!-- ============ PENURUNAN NILAI / CKPN ============ -->
                      <tr class="baris-section"><td colspan="8">Penurunan nilai <span class="fw-normal text-lowercase">/ Impairment</span></td></tr>
                      <?php foreach ($barisCKPN as $b): ?>
                      <tr>
                        <td class="kode"><?php echo htmlspecialchars($b['kode']); ?></td>
                        <td class="nama-id"><?php echo htmlspecialchars($b['nama_id']); ?></td>
                        <td class="angka"><?php echo fmtRp($b['awal']); ?></td>
                        <td class="angka"><?php echo fmtRp($b['penambahan']); ?></td>
                        <td class="angka"><?php echo fmtRp($b['pengurangan']); ?></td>
                        <td class="angka"><?php echo fmtRp($b['reklasifikasi']); ?></td>
                        <td class="angka"><?php echo fmtRp($b['akhir']); ?></td>
                        <td class="nama-en"><?php echo htmlspecialchars($b['nama_en']); ?></td>
                      </tr>
                      <?php endforeach; ?>
                      <tr class="baris-jumlah">
                        <td></td><td class="nama-id">Jumlah <span class="fw-normal text-lowercase">/ Total</span></td>
                        <td class="angka"><?php echo fmtRp($subCKPN['awal']); ?></td>
                        <td class="angka"><?php echo fmtRp($subCKPN['penambahan']); ?></td>
                        <td class="angka"><?php echo fmtRp($subCKPN['pengurangan']); ?></td>
                        <td class="angka"><?php echo fmtRp($subCKPN['reklasifikasi']); ?></td>
                        <td class="angka"><?php echo fmtRp($subCKPN['akhir']); ?></td>
                        <td class="nama-en">Total</td>
                      </tr>

                      <!-- ============ NILAI BUKU NETO ============ -->
                      <tr class="baris-neto">
                        <td></td>
                        <td class="nama-id">Nilai buku neto</td>
                        <td class="angka"><?php echo fmtRp($nilaiBukuAwal); ?></td>
                        <td colspan="3"></td>
                        <td class="angka"><?php echo fmtRp($nilaiBukuAkhir); ?></td>
                        <td class="nama-en">Net book value</td>
                      </tr>

                    </tbody>
                  </table>
                </div>
              </div>
            </div>

          </div>
        </div>
      </main>
    </div>
    <script src="../../dist/js/overlayscrollbars.browser.es6.min.js"></script>
    <script src="../../dist/js/popper.min.js"></script>
    <script src="../../dist/js/bootstrap.min.js"></script>
    <script src="../../dist/js/adminlte.js"></script>
    <script src="../../dist/js/jquery-3.6.0.min.js"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const sidebarWrapper = document.querySelector('.sidebar-wrapper');
        if (sidebarWrapper && OverlayScrollbarsGlobal?.OverlayScrollbars !== undefined) {
          OverlayScrollbarsGlobal.OverlayScrollbars(sidebarWrapper, {
            scrollbars: { theme: 'os-theme-light', autoHide: 'leave', clickScroll: true },
          });
        }
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
        tooltipTriggerList.map(function (el) { return new bootstrap.Tooltip(el); });
      });
    </script>
  </body>
</html>