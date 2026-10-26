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
// ============================  CATATAN PENTING  ==========================
// ==========================================================================
// Halaman ini ("KK Aset" -- Kertas Kerja Rekon Aset vs TB) BARU MEMAKAI DATA
// CONTOH (dummy), sesuai kesepakatan: tampilan dibuat dulu, baru disambungkan
// ke database di tahap berikutnya. Ada 2 bagian yang PASTI perlu diganti nanti:
//
//   1) $daftarCabang  -> nanti diganti query ke tabel MASTER CABANG (perlu nama
//      tabel & nama kolom kode/nama cabang -- misal "master_cabang" dgn kolom
//      "kode_cabang", "nama_cabang").
//
//   2) $petaAssetClass -> nanti diganti query XLOOKUP-style ke tabel
//      "Master Rincian COA Aset" (sheet yg dipakai formula XLOOKUP di file Excel
//      kamu), buat ambil GL Aset / GL Akumulasi / GL CKPN / GL Beban Penyusutan /
//      Deskripsi berdasarkan Asset Class. Perlu nama tabel & kolomnya juga.
//
//   3) $dataRekon -> nanti diganti query yang bandingin AR02 (import_ar02_reg3,
//      per aset per cabang) vs TB (REKAP FAGLL / import_fagll per GL Account),
//      untuk 3 metrik: Nilai Perolehan, Akumulasi Penyusutan (+ CKPN), dan
//      Beban Penyusutan.
//
// Cari komentar "TODO SAMBUNGKAN KE DB" di bawah buat nemuin titik2 ini.
// ==========================================================================

// ── SUDAH TERSAMBUNG KE DB ──────────────────────────────────────────────
// Sumber: import_ar02_reg3 (AR02) + master_coa (peta GL per Asset Class)
// + master_asset_class_name (nama Asset Class, tabel baru) + import_fagll (TB,
// dijumlah KUMULATIF dari awal s.d. akhir periode yang dipilih).
// Default periode = bulan terakhir yang ada datanya di FAGLL (bukan bulan berjalan),
// supaya ga tampil "October" padahal data baru sampai August.
$periodeDefault = date('Y-m');
$qMax = mysqli_query($con, "SELECT DATE_FORMAT(MAX(posting_date_norm), '%Y-%m') AS p FROM import_nilai_perolehan");
if ($qMax && ($rMax = mysqli_fetch_assoc($qMax)) && !empty($rMax['p'])) {
    $periodeDefault = $rMax['p'];
}
$periodeDipilih = (isset($_GET['periode']) && preg_match('/^\d{4}-\d{2}$/', $_GET['periode']))
    ? $_GET['periode']
    : $periodeDefault;
// batas atas eksklusif = tgl 1 bulan berikutnya, jadi bulan yang dipilih ikut terhitung (s.d. akhir bulan)
$akhirPeriode = date('Y-m-01', strtotime($periodeDipilih . '-01 +1 month'));

// ==========================================================================
// LOGIKA SAMA DENGAN EXCEL "KK PERCABANG"
// Baris : gabungan (Profit Center, GL Aset) dari AR02 + import_nilai_perolehan
//         -> akun yang hanya ada di TB (mis. AUC) tetap muncul, DAT = 0
// DAT   : AR02 (import_ar02_reg3) per Profit Center + GL Aset (bal_sh_acct_APC)
//         NP -> current_apc | AKM -> akumulasi_dep_nilai | PENY -> dep_tahun_berjalan
// TB    : NP  -> import_nilai_perolehan, account = GL Aset   (kumulatif s.d. akhir periode)
//         AKM -> import_nilai_akumulasi, account = GL AKM    (kumulatif s.d. akhir periode)
//         DEP -> import_nilai_dep,       account = GL DEP    (1 Jan s.d. akhir periode)
// Peta GL (AKM / CKPN / DEP) dari master_kk_aset; cadangan master_coa kalau kosong.
// SELISIH = DAT - TB
// ==========================================================================
$awalTahun = date('Y-01-01', strtotime($periodeDipilih . '-01'));
// AR02 berisi beberapa periode -> DAT hanya diambil untuk periode yang dipilih (mis. 2026-09 => tahun 2026, bulan 9)
$tahunPilih = substr($periodeDipilih, 0, 4);
$bulanPilih = (int)substr($periodeDipilih, 5, 2);

// Nama Profit Center sesuai Excel KK Percabang (menimpa nama dari kolom cabang AR02).
// TODO: pindahkan ke tabel master kalau mau dikelola dari web.
$namaProfitCenter = [
    '10101' => 'KANTOR PUSAT', '10301' => 'GRESIK', '10801' => 'TANJUNG INTAN',
    '11101' => 'LEMBAR', '11201' => 'BIMA', '11301' => 'MAUMERE',
    '11401' => 'TENAU KUPANG', '12101' => 'KANTOR PUSAT', '12201' => 'REGION JATIM',
    '12204' => 'NILAM-MIRAH', '12205' => 'JAMRUD', '12206' => 'KALIMAS',
    '12207' => 'TELUK LAMONG', '12208' => 'APBS', '12209' => 'GRESIK',
    '12210' => 'KALIANGET', '12211' => 'TG TEMBAGA', '12213' => 'TANJUNG WANGI',
    '12302' => 'TANJUNG EMAS', '12303' => 'TEGAL', '12304' => 'Unit PK Semarang',
    '12305' => 'TANJUNG INTAN', '12306' => 'TERMINAL BATANG', '12401' => 'REGION BALI NUSRA',
    '12402' => 'BENOA', '12403' => 'CELUKAN BAWANG', '12405' => 'LEMBAR',
    '12406' => 'BIMA', '12407' => 'BADAS', '12408' => 'MAUMERE',
    '12409' => 'KUPANG', '12410' => 'ENDE-IPPI', '12411' => 'WAINGAPU',
    '12412' => 'KALABAHI', '12413' => 'LABUAN BAJO', '12501' => 'REGION KALIMANTAN',
    '12502' => 'Unit PK Banjarmasin', '12503' => 'TRISAKTI', '12504' => 'PULANG PISAU',
    '12505' => 'KOTABARU', '12506' => 'BATU LICIN', '12507' => 'MEKAR PUTIH',
    '12508' => 'SAMPIT', '12509' => 'BAGENDANG', '12510' => 'KUMAI',
    '12511' => 'BUMIHARJO', '12512' => 'KWS. SATUI',
];

$sql = "
    SELECT
        k.profit_center,
        COALESCE(pc.nama, k.profit_center)                  AS profit_center_text,
        COALESCE(acn.nama, g.kode_ac, k.gl)                 AS asset_class_name,
        k.gl                                                AS gl_aset,
        COALESCE(NULLIF(mk.gl_akumulasi, ''), mc.akun_akumulasi_penyusutan)       AS gl_akm,
        COALESCE(NULLIF(mk.gl_ckpn, ''), mc.ckpn)                                 AS gl_ckpn,
        COALESCE(NULLIF(mk.gl_beban_penyusutan, ''), mc.akun_beban_penyusutan)    AS gl_dep,
        COALESCE(d.np_dat, 0)   AS np_dat,
        COALESCE(d.akm_dat, 0)  AS akm_dat,
        COALESCE(d.peny_dat, 0) AS peny_dat,
        COALESCE(t_np.saldo, 0)  AS np_tb,
        COALESCE(t_akm.saldo, 0) AS akm_tb,
        COALESCE(t_dep.saldo, 0) AS peny_tb
    FROM (
        SELECT profit_center, bal_sh_acct_APC AS gl FROM import_ar02_reg3
        WHERE periode_tahun = ? AND CAST(periode_bulan AS UNSIGNED) = ?
          AND bal_sh_acct_APC IS NOT NULL AND bal_sh_acct_APC <> ''
        UNION
        SELECT profit_center, account AS gl FROM import_nilai_perolehan
        WHERE posting_date_norm < ? AND account IS NOT NULL AND account <> ''
    ) k

    INNER JOIN (SELECT profit_center, MAX(cabang) AS nama FROM import_ar02_reg3
                WHERE profit_center IS NOT NULL AND TRIM(profit_center) <> ''
                GROUP BY profit_center) pc
           ON pc.profit_center = k.profit_center

    LEFT JOIN (SELECT bal_sh_acct_APC AS gl, MAX(asset_class) AS kode_ac FROM import_ar02_reg3 GROUP BY bal_sh_acct_APC) g
           ON g.gl = k.gl

    LEFT JOIN (SELECT gl_aset, MAX(asset_class) AS asset_class, MAX(gl_akumulasi) AS gl_akumulasi,
                      MAX(gl_ckpn) AS gl_ckpn, MAX(gl_beban_penyusutan) AS gl_beban_penyusutan
               FROM master_kk_aset GROUP BY gl_aset) mk
           ON mk.gl_aset = k.gl

    LEFT JOIN (SELECT account_determination, MAX(akun_akumulasi_penyusutan) AS akun_akumulasi_penyusutan,
                      MAX(ckpn) AS ckpn, MAX(akun_beban_penyusutan) AS akun_beban_penyusutan
               FROM master_coa GROUP BY account_determination) mc
           ON mc.account_determination = COALESCE(NULLIF(mk.asset_class, ''), g.kode_ac)

    LEFT JOIN master_asset_class_name acn
           ON acn.kode = COALESCE(NULLIF(mk.asset_class, ''), g.kode_ac)

    LEFT JOIN (
        SELECT profit_center, bal_sh_acct_APC AS gl,
               SUM(CAST(CASE WHEN RIGHT(TRIM(current_apc),1)='-' THEN CONCAT('-', REPLACE(TRIM(TRAILING '-' FROM TRIM(current_apc)), ',', '')) ELSE REPLACE(NULLIF(TRIM(current_apc),''), ',', '') END AS DECIMAL(20,2))) AS np_dat,
               SUM(CAST(CASE WHEN RIGHT(TRIM(akumulasi_dep_nilai),1)='-' THEN CONCAT('-', REPLACE(TRIM(TRAILING '-' FROM TRIM(akumulasi_dep_nilai)), ',', '')) ELSE REPLACE(NULLIF(TRIM(akumulasi_dep_nilai),''), ',', '') END AS DECIMAL(20,2))) AS akm_dat,
               SUM(CAST(CASE WHEN RIGHT(TRIM(dep_tahun_berjalan),1)='-' THEN CONCAT('-', REPLACE(TRIM(TRAILING '-' FROM TRIM(dep_tahun_berjalan)), ',', '')) ELSE REPLACE(NULLIF(TRIM(dep_tahun_berjalan),''), ',', '') END AS DECIMAL(20,2))) AS peny_dat
        FROM import_ar02_reg3
        WHERE periode_tahun = ? AND CAST(periode_bulan AS UNSIGNED) = ?
        GROUP BY profit_center, bal_sh_acct_APC
    ) d ON d.profit_center = k.profit_center AND d.gl = k.gl

    LEFT JOIN (
        SELECT profit_center, account, SUM(amount_norm) AS saldo
        FROM import_nilai_perolehan WHERE posting_date_norm < ?
        GROUP BY profit_center, account
    ) t_np ON t_np.profit_center = k.profit_center AND t_np.account = k.gl

    LEFT JOIN (
        SELECT profit_center, account, SUM(amount_norm) AS saldo
        FROM import_nilai_akumulasi WHERE posting_date_norm < ?
        GROUP BY profit_center, account
    ) t_akm ON t_akm.profit_center = k.profit_center
           AND t_akm.account = COALESCE(NULLIF(mk.gl_akumulasi, ''), mc.akun_akumulasi_penyusutan)

    LEFT JOIN (
        SELECT profit_center, account, SUM(amount_norm) AS saldo
        FROM import_nilai_dep WHERE posting_date_norm >= ? AND posting_date_norm < ?
        GROUP BY profit_center, account
    ) t_dep ON t_dep.profit_center = k.profit_center
           AND t_dep.account = COALESCE(NULLIF(mk.gl_beban_penyusutan, ''), mc.akun_beban_penyusutan)

    ORDER BY k.profit_center, asset_class_name
";

$dataRows = [];
$daftarCabang = [];
$sudahAdaCabang = [];

if ($stmt = mysqli_prepare($con, $sql)) {
    mysqli_stmt_bind_param($stmt, 'sississs' . 's',
        $tahunPilih, $bulanPilih, $akhirPeriode,   // baris: AR02 periode + TB NP
        $tahunPilih, $bulanPilih,                  // DAT: AR02 periode
        $akhirPeriode,                             // TB NP
        $akhirPeriode,                             // TB AKM
        $awalTahun, $akhirPeriode);                // TB DEP
    mysqli_stmt_execute($stmt);
    $hasil = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($hasil)) {
        $namaKelas = $row['asset_class_name'];
        // KET: AUC = dlm konstruksi, ATB = tak berwujud (GL 1208xxxx), selain itu AT
        $ket = 'AT';
        if (stripos($namaKelas, 'AUC') === 0) { $ket = 'AUC'; }
        elseif (stripos($namaKelas, 'ATB') === 0 || strpos($row['gl_aset'], '1208') === 0) { $ket = 'ATB'; }

        $npDat = (float)$row['np_dat'];   $npTb = (float)$row['np_tb'];
        $akDat = (float)$row['akm_dat'];  $akTb = (float)$row['akm_tb'];
        $peDat = (float)$row['peny_dat']; $peTb = (float)$row['peny_tb'];

        $dataRows[] = [
            'profit_center'      => $row['profit_center'],
            'profit_center_text' => $namaProfitCenter[$row['profit_center']] ?? $row['profit_center_text'],
            'asset_class'        => $namaKelas,
            'ket'                => $ket,
            'gl_aset'            => $row['gl_aset'],
            'gl_akm'             => $row['gl_akm'],
            'gl_ckpn'            => $row['gl_ckpn'],
            'gl_dep'             => $row['gl_dep'],
            'np_dat' => $npDat, 'np_tb' => $npTb, 'np_selisih' => $npDat - $npTb,
            'akm_dat' => $akDat, 'akm_tb' => $akTb, 'akm_selisih' => $akDat - $akTb,
            'peny_dat' => $peDat, 'peny_tb' => $peTb, 'peny_selisih' => $peDat - $peTb,
        ];

        $pc = $row['profit_center'];
        if (!isset($sudahAdaCabang[$pc])) {
            $sudahAdaCabang[$pc] = true;
            $daftarCabang[] = ['kode' => $pc, 'nama' => $namaProfitCenter[$pc] ?? $row['profit_center_text']];
        }
    }
    mysqli_stmt_close($stmt);
}

// ── Filter Cabang / Profit Center (GET) ──
$filterCabang = isset($_GET['cabang']) && $_GET['cabang'] !== '' ? $_GET['cabang'] : null;
$dataDitampilkan = $filterCabang
    ? array_values(array_filter($dataRows, fn($r) => $r['profit_center'] === $filterCabang))
    : $dataRows;

// Kelompokkan per Profit Center (urut sesuai hasil query)
$grup = [];
foreach ($dataDitampilkan as $r) {
    $grup[$r['profit_center']]['nama'] = $r['profit_center_text'];
    $grup[$r['profit_center']]['baris'][] = $r;
}

function fmtRp($n) {
    $n = (float)$n;
    if (abs($n) < 0.5) return '-';
    $abs = number_format(abs($n), 0, ',', '.');
    return ($n < 0) ? "- $abs" : $abs;
}
function fmtSelisih($selisih) {
    $s = (float)$selisih;
    if (abs($s) < 0.5) return '<span class="text-success">-</span>';
    return '<span class="text-danger fw-semibold">' . fmtRp($s) . '</span>';
}
function fmtSingkat($n) {
    $n = (float)$n;
    if (abs($n) < 0.5) return '-';
    $neg = $n < 0;
    $abs = abs($n);
    if ($abs >= 1_000_000_000) { $val = $abs / 1_000_000_000; $sfx = ' M'; }
    elseif ($abs >= 1_000_000) { $val = $abs / 1_000_000; $sfx = ' jt'; }
    elseif ($abs >= 1_000) { $val = $abs / 1_000; $sfx = ' rb'; }
    else { $val = $abs; $sfx = ''; }
    $str = number_format($val, 1, ',', '.') . $sfx;
    return ($neg ? '- ' : '') . $str;
}
function fmtSelisihSingkat($selisih) {
    $s = (float)$selisih;
    if (abs($s) < 0.5) return '<span class="text-success">-</span>';
    return '<span class="text-danger fw-semibold">' . fmtSingkat($s) . '</span>';
}
function totalGrup($rows) {
    $t = ['np_dat'=>0,'np_tb'=>0,'np_selisih'=>0,'akm_dat'=>0,'akm_tb'=>0,'akm_selisih'=>0,'peny_dat'=>0,'peny_tb'=>0,'peny_selisih'=>0];
    foreach ($rows as $r) { foreach ($t as $k => $v) { $t[$k] += $r[$k]; } }
    return $t;
}
$grandTotal = totalGrup($dataDitampilkan);
?>
<!doctype html>
<html lang="id">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Dasbor Selisih Percabang - Web Aset Tetap</title>
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

      .tabel-kk { width: 100%; min-width: 2000px; border-collapse: collapse; font-size: 0.8rem; }
      .tabel-kk th, .tabel-kk td { padding: 5px 8px; border: 1px solid #dee2e6; white-space: nowrap; }
      .tabel-kk thead th { background-color: #f1f3f5; text-align: center; vertical-align: middle; font-weight: 600; position: sticky; top: 0; z-index: 2; }
      .tabel-kk thead th.grp-perolehan { background-color: #dbe4f0; }
      .tabel-kk thead th.grp-akumulasi { background-color: #e6ecdb; }
      .tabel-kk thead th.grp-beban { background-color: #f0e2db; }
      .tabel-kk td.kode { color: #6c757d; font-size: 0.75rem; text-align: center; }
      .tabel-kk td.nama-id { text-align: left; font-weight: 500; }
      .tabel-kk td.angka { text-align: right; font-variant-numeric: tabular-nums; }
      .tabel-kk td.selisih { text-align: right; font-variant-numeric: tabular-nums; }
      .tabel-kk td.sticky-col1, .tabel-kk th.sticky-col1 { position: sticky; left: 0; background-color: #fff; z-index: 1; }
      .tabel-kk thead th.sticky-col1 { z-index: 3; background-color: #f1f3f5; }
      .tabel-kk tr.baris-cabang td { background-color: #0b3a8c; color: #fff; font-weight: 700; }
      .tabel-kk tr.baris-cabang td.sticky-col1 { background-color: #0b3a8c; }
      .tabel-kk tr.baris-total-cabang td { background-color: #f8f9fa; font-weight: 700; border-top: 2px solid #495057; }
      .tabel-kk tr.baris-total-cabang td.sticky-col1 { background-color: #f8f9fa; }
      .tabel-kk tr.baris-grand-total td { background-color: #212529; color: #fff; font-weight: 700; border-top: 3px double #000; }
      .tabel-kk tr.baris-grand-total td.sticky-col1 { background-color: #212529; }
      .badge-selisih-ada { background-color: #dc3545; }
      .badge-selisih-nol { background-color: #198754; }
      .col-gl { display: none; }
      body.show-gl .col-gl { display: table-cell; }
      .tabel-kk { min-width: 900px; }
      .tabel-excel { min-width: 1300px; font-size: 0.74rem; }
      .tabel-excel th, .tabel-excel td { padding: 2px 6px !important; white-space: nowrap; }
      .tabel-excel .nama-id { max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
      .tabel-excel thead th { font-size: 0.72rem; vertical-align: middle; }
      .tabel-excel .grup-np   { background-color: #eaf4ea; }
      .tabel-excel .grup-akm  { background-color: #eaf0f8; }
      .tabel-excel .grup-peny { background-color: #faf0e6; }
      .tabel-excel .sticky-col1 { width: 90px; min-width: 90px; max-width: 90px; }
      .tabel-excel td.sticky-col2, .tabel-excel th.sticky-col2 { position: sticky; left: 90px; width: 160px; min-width: 160px; max-width: 160px; background-color: #fff; z-index: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
      .tabel-excel thead th.sticky-col2 { z-index: 3; background-color: #f1f3f5; }
      .tabel-excel td.sticky-col3, .tabel-excel th.sticky-col3 { position: sticky; left: 250px; width: 170px; min-width: 170px; max-width: 170px; background-color: #fff; z-index: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; box-shadow: 2px 0 3px rgba(0,0,0,0.08); }
      .tabel-excel thead th.sticky-col3 { z-index: 3; background-color: #f1f3f5; }
      .tabel-excel tr.grup-zebra-a { background-color: #ffffff; }
      .tabel-excel tr.grup-zebra-b { background-color: #f2f4f7; }
      .tabel-excel tr.grup-zebra-a td.sticky-col1, .tabel-excel tr.grup-zebra-a td.sticky-col2, .tabel-excel tr.grup-zebra-a td.sticky-col3 { background-color: #ffffff; }
      .tabel-excel tr.grup-zebra-b td.sticky-col1, .tabel-excel tr.grup-zebra-b td.sticky-col2, .tabel-excel tr.grup-zebra-b td.sticky-col3 { background-color: #f2f4f7; }
      
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
                'Dasboard'                           => 'bi bi-grid-fill', 

                'Usulan Penghapusan'                 => 'bi bi-file-earmark-plus', 
                'Daftar Usulan Penghapusan'          => 'bi bi-collection',
                'Approval SubReg'                    => 'bi bi-person-check', 
                'Approval Regional'                  => 'bi bi-building-check', 
                'Persetujuan Penghapusan'            => 'bi bi-shield-check',
                'Pelaksanaan Penghapusan'            => 'bi bi-gear-wide-connected', 
                'Daftar Pelaksanaan Penghapusan'     => 'bi bi-archive-fill',
                'Daftar Aset Tetap'                  => 'bi bi-boxes',    

                'Import Data Penyusutan'             => 'bi bi-upload', 
                'Daftar Data Penyusutan'             => 'bi bi-table', 
                'Dasbor Monitoring Beban Penyusutan' => 'bi-bar-chart-line',

                'Import Data Monitoring'             => 'bi bi-upload', 
                'Daftar Data Monitoring'             => 'bi bi-table', 
                'Dasbor Mutasi Aset Tetap'           => 'bi bi-speedometer2',
                'Dasbor Selisih Percabang'           => 'bi bi-card-checklist', 
                'Dasbor Mutasi Aset Tak Berwujud'    => 'bi bi-file-earmark-text',
                'Dasbor Mutasi Properti Investasi'   => 'bi bi-house-fill',

                'Import DAT'                         => 'bi bi-upload',
                'Manajemen Menu'                     => 'bi bi-layout-text-sidebar', 
                'Manajemen User'                     => 'bi bi-people',
            ];
            $groupMap = [
                'Usulan Penghapusan'                 => 'Penghapusan Aset', 
                'Daftar Usulan Penghapusan'          => 'Penghapusan Aset', 
                'Approval SubReg'                    => 'Penghapusan Aset',
                'Approval Regional'                  => 'Penghapusan Aset', 
                'Persetujuan Penghapusan'            => 'Penghapusan Aset', 
                'Daftar Persetujuan Penghapusan'     => 'Penghapusan Aset',
                'Pelaksanaan Penghapusan'            => 'Penghapusan Aset', 
                'Daftar Aset Tetap'                  => 'Penghapusan Aset', 
                'Daftar Pelaksanaan Penghapusan'     => 'Penghapusan Aset',

                'Import Data Penyusutan'             => 'Penyusutan Aset', 
                'Daftar Data Penyusutan'             => 'Penyusutan Aset', 
                'Dasbor Monitoring Beban Penyusutan' => 'Penyusutan Aset',

                'Import Data Monitoring'             => 'Monitoring Aset Tetap', 
                'Daftar Data Monitoring'             => 'Monitoring Aset Tetap', 
                'Dasbor Mutasi Aset Tetap'           => 'Monitoring Aset Tetap',
                'Dasbor Selisih Percabang'           => 'Monitoring Aset Tetap', 
                'Dasbor Mutasi Aset Tak Berwujud'    => 'Monitoring Aset Tetap',
                'Dasbor Mutasi Properti Investasi'   => 'Monitoring Aset Tetap',

                'Import DAT'                         => 'Administrasi Sistem', 
                'Manajemen Menu'                     => 'Administrasi Sistem', 
                'Manajemen User'                     => 'Administrasi Sistem',
            ];
            $groupIcon = [
                'Penghapusan Aset'                   => 'bi bi-file-earmark-minus', 
                'Penyusutan Aset'                    => 'bi bi-graph-down-arrow',
                'Monitoring Aset Tetap'              => 'bi bi-arrow-left-right', 
                'Administrasi Sistem'                => 'bi bi-sliders',
            ];
            $groupOrder = ['Penghapusan Aset', 'Penyusutan Aset', 'Monitoring Aset Tetap', 'Administrasi Sistem'];

            $currentPage = basename($_SERVER['PHP_SELF']);
            $ungrouped = []; $grouped = [];
            while ($row = mysqli_fetch_assoc($result_menu)) {
                $namaMenu = trim($row['nama_menu']);
                if (isset($groupMap[$namaMenu])) { $grouped[$groupMap[$namaMenu]][] = $row; } else { $ungrouped[] = $row; }
            }
            // Menu "KK Aset" ditambahkan manual di grup Monitoring SAP-DAT kalau belum ada di tabel menus.
            $sudahAdaKkAset = false;
            foreach ($grouped['Monitoring SAP-DAT'] ?? [] as $itemCek) { if (trim($itemCek['nama_menu']) === 'Dasbor KK Aset Tetap') { $sudahAdaKkAset = true; } }
            if (!$sudahAdaKkAset) { $grouped['Monitoring SAP-DAT'][] = ['menu' => 'kk_aset', 'nama_menu' => 'Dasbor KK Aset Tetap', 'urutan_menu' => 999]; }

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
              <div class="col-sm-6">
                <h1 class="m-0">Dasbor Selisih Percabang</h1>
              </div>
              <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                  <li class="breadcrumb-item"><a href="../dasbor/dasbor.php">Home</a></li>
                  <li class="breadcrumb-item active">Dasbor Selisih Percabang</li>
                </ol>
              </div>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">

            <div class="card card-outline mb-4">
              <div class="card-body">

                <form method="GET" class="row g-2 align-items-end mb-3">
                  <div class="col-auto">
                    <label class="form-label mb-0 fw-semibold">Profit Center</label>
                    <select name="cabang" class="form-select form-select-sm" style="min-width:260px;">
                      <option value="">Semua Profit Center</option>
                      <?php foreach ($daftarCabang as $cb): ?>
                        <option value="<?php echo htmlspecialchars($cb['kode']); ?>" <?php echo ($filterCabang === $cb['kode']) ? 'selected' : ''; ?>>
                          <?php echo htmlspecialchars($cb['kode'] . ' - ' . $cb['nama']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-auto">
                    <label class="form-label mb-0 fw-semibold">Periode s.d.</label>
                    <input type="month" name="periode" class="form-control form-control-sm" value="<?php echo htmlspecialchars($periodeDipilih); ?>" max="<?php echo htmlspecialchars($periodeDefault); ?>">
                  </div>
                  <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-arrow-repeat"></i> Terapkan</button>
                    <?php if ($filterCabang): ?>
                      <a href="?" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-circle"></i> Reset</a>
                    <?php endif; ?>
                  </div>
                  <div class="col-auto">
                    <label class="form-label mb-0 fw-semibold d-block">KET</label>
                    <div class="btn-group btn-group-sm" role="group" id="filterKet">
                      <button type="button" class="btn btn-outline-secondary active" data-ket="">Semua</button>
                      <button type="button" class="btn btn-outline-secondary" data-ket="at">AT</button>
                      <button type="button" class="btn btn-outline-secondary" data-ket="auc">AUC</button>
                      <button type="button" class="btn btn-outline-secondary" data-ket="atb">ATB</button>
                    </div>
                  </div>
                  <div class="col-auto ms-auto" style="width:280px;">
                    <label class="form-label mb-0 fw-semibold">Cari</label>
                    <input type="text" id="cariBaris" class="form-control form-control-sm" placeholder="Cari Profit Center / Asset Class...">
                  </div>

                  <div class="col-12 d-flex flex-wrap align-items-center gap-4 pt-1">
                    <div class="form-check form-switch mb-0">
                      <input class="form-check-input" type="checkbox" id="toggleSelisih">
                      <label class="form-check-label small fw-semibold text-danger" for="toggleSelisih">Hanya yang ada selisih</label>
                    </div>
                    <div class="form-check form-switch mb-0">
                      <input class="form-check-input" type="checkbox" id="toggleGl">
                      <label class="form-check-label small" for="toggleGl">Tampilkan kode GL</label>
                    </div>
                  </div>
                </form>

                <div id="modeExcel">
                  <div class="table-responsive">
                    <table class="tabel-kk tabel-excel">
                      <thead>
                        <tr>
                          <th class="sticky-col1" rowspan="2" style="min-width:90px;">Profit Center</th>
                          <th class="sticky-col2" rowspan="2" style="min-width:160px;">Profit Center Text</th>
                          <th class="sticky-col3" rowspan="2" style="min-width:170px;">Asset Class</th>
                          <th rowspan="2" style="min-width:45px;">KET</th>
                          <th class="col-gl" rowspan="2" style="min-width:90px;">GL Aset</th>
                          <th class="col-gl" rowspan="2" style="min-width:90px;">AKM</th>
                          <th class="col-gl" rowspan="2" style="min-width:90px;">CKPN</th>
                          <th class="col-gl" rowspan="2" style="min-width:90px;">DEP</th>
                          <th colspan="3" class="text-center grup-np">NILAI PEROLEHAN</th>
                          <th colspan="3" class="text-center grup-akm">AKUMULASI</th>
                          <th colspan="3" class="text-center grup-peny">PENY. THN BERJALAN</th>
                        </tr>
                        <tr>
                          <th class="text-end grup-np">DAT</th><th class="text-end grup-np">TB</th><th class="text-end grup-np">Selisih NP</th>
                          <th class="text-end grup-akm">DAT</th><th class="text-end grup-akm">TB</th><th class="text-end grup-akm">Selisih AKM</th>
                          <th class="text-end grup-peny">DAT</th><th class="text-end grup-peny">TB</th><th class="text-end grup-peny">Selisih</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php $pcSebelumnya = null; $zebra = 0; ?>
                        <?php foreach ($dataDitampilkan as $r):
                          $semuaNol = (abs($r['np_selisih']) < 0.5) && (abs($r['akm_selisih']) < 0.5) && (abs($r['peny_selisih']) < 0.5);
                          if ($r['profit_center'] !== $pcSebelumnya) { $zebra = 1 - $zebra; $pcSebelumnya = $r['profit_center']; }
                          $kelasZebra = $zebra ? 'grup-zebra-a' : 'grup-zebra-b';
                        ?>
                        <tr class="<?php echo $kelasZebra; ?>" data-cari="<?php echo htmlspecialchars(strtolower($r['profit_center'] . ' ' . $r['profit_center_text'] . ' ' . $r['asset_class'])); ?>" data-ket="<?php echo htmlspecialchars(strtolower($r['ket'])); ?>" data-nol="<?php echo $semuaNol ? '1' : '0'; ?>">
                          <td class="sticky-col1"><?php echo htmlspecialchars($r['profit_center']); ?></td>
                          <td class="sticky-col2"><?php echo htmlspecialchars($r['profit_center_text']); ?></td>
                          <td class="sticky-col3 nama-id" title="<?php echo htmlspecialchars($r['asset_class']); ?>"><?php echo htmlspecialchars($r['asset_class']); ?></td>
                          <td class="kode"><?php echo htmlspecialchars($r['ket']); ?></td>
                          <td class="kode col-gl"><?php echo htmlspecialchars($r['gl_aset']); ?></td>
                          <td class="kode col-gl"><?php echo htmlspecialchars($r['gl_akm']); ?></td>
                          <td class="kode col-gl"><?php echo htmlspecialchars($r['gl_ckpn']); ?></td>
                          <td class="kode col-gl"><?php echo htmlspecialchars($r['gl_dep']); ?></td>
                          <td class="angka"><?php echo fmtRp($r['np_dat']); ?></td>
                          <td class="angka"><?php echo fmtRp($r['np_tb']); ?></td>
                          <td class="selisih"><?php echo fmtSelisih($r['np_selisih']); ?></td>
                          <td class="angka"><?php echo fmtRp($r['akm_dat']); ?></td>
                          <td class="angka"><?php echo fmtRp($r['akm_tb']); ?></td>
                          <td class="selisih"><?php echo fmtSelisih($r['akm_selisih']); ?></td>
                          <td class="angka"><?php echo fmtRp($r['peny_dat']); ?></td>
                          <td class="angka"><?php echo fmtRp($r['peny_tb']); ?></td>
                          <td class="selisih"><?php echo fmtSelisih($r['peny_selisih']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>

                  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-2">
                    <div class="small text-muted" id="infoPaginasi">Menampilkan ...</div>
                    <div class="d-flex align-items-center gap-2">
                      <label class="small text-muted mb-0">Baris / halaman</label>
                      <select id="jumlahPerHalaman" class="form-select form-select-sm" style="width:auto;">
                        <option value="25">25</option>
                        <option value="50" selected>50</option>
                        <option value="100">100</option>
                        <option value="250">250</option>
                      </select>
                      <div class="btn-group btn-group-sm">
                        <button type="button" id="btnHalPertama" class="btn btn-outline-secondary" title="Halaman pertama"><i class="bi bi-chevron-bar-left"></i></button>
                        <button type="button" id="btnHalSebelum" class="btn btn-outline-secondary" title="Sebelumnya"><i class="bi bi-chevron-left"></i></button>
                        <span class="btn btn-outline-secondary disabled" id="labelHalaman">1 / 1</span>
                        <button type="button" id="btnHalBerikut" class="btn btn-outline-secondary" title="Berikutnya"><i class="bi bi-chevron-right"></i></button>
                        <button type="button" id="btnHalTerakhir" class="btn btn-outline-secondary" title="Halaman terakhir"><i class="bi bi-chevron-bar-right"></i></button>
                      </div>
                    </div>
                  </div>
                </div><!-- /#modeExcel -->

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

      document.getElementById('toggleGl')?.addEventListener('change', function () {
        document.body.classList.toggle('show-gl', this.checked);
      });

      let ketAktif = '';
      let halamanSekarang = 1;

      function semuaBaris() {
        return Array.from(document.querySelectorAll('.tabel-kk tbody tr[data-cari]'));
      }

      function barisLolosFilter(tr, kata, hanyaSelisih) {
        const cocokCari = !kata || tr.dataset.cari.includes(kata);
        const cocokKet  = !ketAktif || tr.dataset.ket === ketAktif;
        const cocokSelisih = !hanyaSelisih || tr.dataset.nol === '0';
        return cocokCari && cocokKet && cocokSelisih;
      }

      function terapkanFilterBaris(resetHalaman = true) {
        if (resetHalaman) halamanSekarang = 1;
        const kata = (document.getElementById('cariBaris')?.value || '').trim().toLowerCase();
        const hanyaSelisih = document.getElementById('toggleSelisih')?.checked;
        const jumlahPerHalaman = parseInt(document.getElementById('jumlahPerHalaman')?.value || '50', 10);

        const semua = semuaBaris();
        const lolos = semua.filter(tr => barisLolosFilter(tr, kata, hanyaSelisih));

        const totalHalaman = Math.max(1, Math.ceil(lolos.length / jumlahPerHalaman));
        if (halamanSekarang > totalHalaman) halamanSekarang = totalHalaman;
        if (halamanSekarang < 1) halamanSekarang = 1;

        const awal = (halamanSekarang - 1) * jumlahPerHalaman;
        const akhir = awal + jumlahPerHalaman;

        semua.forEach(tr => { tr.style.display = 'none'; });
        lolos.slice(awal, akhir).forEach(tr => { tr.style.display = ''; });

        const info = document.getElementById('infoPaginasi');
        if (info) {
          info.textContent = lolos.length === 0
            ? 'Tidak ada baris yang cocok'
            : `Menampilkan ${awal + 1}–${Math.min(akhir, lolos.length)} dari ${lolos.length} baris`;
        }
        const label = document.getElementById('labelHalaman');
        if (label) label.textContent = `${halamanSekarang} / ${totalHalaman}`;

        document.getElementById('btnHalPertama').disabled = (halamanSekarang <= 1);
        document.getElementById('btnHalSebelum').disabled = (halamanSekarang <= 1);
        document.getElementById('btnHalBerikut').disabled = (halamanSekarang >= totalHalaman);
        document.getElementById('btnHalTerakhir').disabled = (halamanSekarang >= totalHalaman);

        window._totalHalamanSekarang = totalHalaman;
      }

      document.getElementById('cariBaris')?.addEventListener('input', () => terapkanFilterBaris(true));
      document.getElementById('toggleSelisih')?.addEventListener('change', () => terapkanFilterBaris(true));
      document.getElementById('jumlahPerHalaman')?.addEventListener('change', () => terapkanFilterBaris(true));

      document.querySelectorAll('#filterKet button').forEach(function (btn) {
        btn.addEventListener('click', function () {
          document.querySelectorAll('#filterKet button').forEach(b => b.classList.remove('active'));
          this.classList.add('active');
          ketAktif = this.dataset.ket;
          terapkanFilterBaris(true);
        });
      });

      document.getElementById('btnHalPertama')?.addEventListener('click', () => { halamanSekarang = 1; terapkanFilterBaris(false); });
      document.getElementById('btnHalSebelum')?.addEventListener('click', () => { halamanSekarang--; terapkanFilterBaris(false); });
      document.getElementById('btnHalBerikut')?.addEventListener('click', () => { halamanSekarang++; terapkanFilterBaris(false); });
      document.getElementById('btnHalTerakhir')?.addEventListener('click', () => { halamanSekarang = window._totalHalamanSekarang || 1; terapkanFilterBaris(false); });

      terapkanFilterBaris(true);
    </script>
  </body>
</html>