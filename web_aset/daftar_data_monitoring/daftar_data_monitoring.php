<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "asetreg3_db";

// Create connection
$con = mysqli_connect($servername, $username, $password, $dbname);
session_start();
if(!isset($_SESSION["nipp"]) || !isset($_SESSION["name"])) {
    header("Location: ../login/login_view.php");
    exit();
}

// Tab "Nilai Perolehan / Nilai Akumulasi / Nilai DEP": data dari card Import Data Monitoring (3 tabel kembar, per periode).
$NILAI_TAB_DEF = [
    'nilai_perolehan' => ['judul' => 'Nilai Perolehan', 'tabel' => 'import_nilai_perolehan'],
    'nilai_akumulasi' => ['judul' => 'Nilai Akumulasi', 'tabel' => 'import_nilai_akumulasi'],
    'nilai_dep'       => ['judul' => 'Nilai DEP',       'tabel' => 'import_nilai_dep'],
];

if (isset($_GET['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax_action'];

    try {

    function tabelAdaDdm($con, $nama) {
        $r = mysqli_query($con, "SHOW TABLES LIKE '" . mysqli_real_escape_string($con, $nama) . "'");
        return $r && mysqli_num_rows($r) > 0;
    }

    // Konfigurasi per tab: tabel sumber, kolom yang ditampilkan (urutan = urutan kolom di tabel HTML).
    // $kolomExpr = ekspresi SQL sebenarnya (dipakai buat SELECT/WHERE/ORDER BY, sudah di-qualify
    // dengan alias tabel kalau perlu JOIN). $kolomAlias = nama field di JSON output (harus sejajar
    // 1:1 dengan $kolomExpr). $fromSql = klausa FROM (+ JOIN kalau ada).
    if ($action === 'dat') {
        $table = 'import_dat_monitoring';
        // GL Aset = kolom "GL Account Aset" dari file DAT (disimpan di import_dat_monitoring.gl_account_aset).
        // GL Akumulasi = XLOOKUP(GL Aset, KK Aset!D:D, KK Aset!E:E, 0) -> di database: master_kk_aset
        // (gl_aset = GL Aset, gl_akumulasi = GL Akumulasi; di-upload lewat kartu "Import KK Aset"); tidak ketemu = 0.
        $rcGlAset = mysqli_query($con, "SHOW COLUMNS FROM `import_dat_monitoring` LIKE 'gl_account_aset'");
        $adaKolomGlAset = $rcGlAset && mysqli_num_rows($rcGlAset) > 0;
        $adaMasterCoaDat = tabelAdaDdm($con, 'master_kk_aset'); // (nama var lama dibiarkan) tabel KK Aset: GL Aset -> GL Akumulasi
        $kolomAlias = ['profit_center', 'cabang', 'tahun_buku', 'nomor_asset', 'sub_number', 'gl_account_aset', 'gl_akumulasi', 'keterangan_asset', 'tgl_perolehan', 'tgl_mulai_penyusutan', 'nilai_perolehan_sd_tahun_berjalan', 'akumulasi_penyusutan', 'gl_account_exp'];
        if ($adaKolomGlAset && $adaMasterCoaDat) {
            $fromSql = "`import_dat_monitoring` d LEFT JOIN master_kk_aset mc ON TRIM(mc.gl_aset) = TRIM(d.gl_account_aset) AND TRIM(IFNULL(d.gl_account_aset, '')) <> ''";
            $exprGlAset = "d.`gl_account_aset`";
            $exprGlAkum = "IF(TRIM(IFNULL(d.`gl_account_aset`, '')) = '', '', IFNULL(NULLIF(TRIM(mc.`gl_akumulasi`), ''), '0'))";
        } elseif ($adaKolomGlAset) {
            $fromSql = "`import_dat_monitoring` d";
            $exprGlAset = "d.`gl_account_aset`";
            $exprGlAkum = "''"; // master_kk_aset belum diupload
        } else {
            $fromSql = "`import_dat_monitoring` d";
            $exprGlAset = "''"; // kolom belum ada di tabel (belum pernah upload DAT versi baru)
            $exprGlAkum = "''";
        }
        $kolomExpr = [
            "d.`profit_center`", "d.`cabang`", "d.`tahun_buku`", "d.`nomor_asset`", "d.`sub_number`",
            $exprGlAset, $exprGlAkum, "d.`keterangan_asset`", "d.`tgl_perolehan`", "d.`tgl_mulai_penyusutan`",
            "d.`nilai_perolehan_sd_tahun_berjalan`", "d.`akumulasi_penyusutan`", "d.`gl_account_exp`",
        ];
        $kolomBulan = null; // tabel ini gak ada dimensi periode_bulan, cuma per tahun_buku
        $kolomTahun = 'd.`tahun_buku`';
        $orderDefaultCol = 'd.`id`';
    } elseif ($action === 'ar02') {
        $table = 'import_ar02_reg3';
        $kolomBulan = 'periode_bulan';
        $kolomTahun = 'periode_tahun';

        // ⚠ Kolom gl_akumulasi_penyusutan, ckpn, & gl_beban_penyusutan di tabel import_ar02_reg3
        // SELALU KOSONG karena file sumber AR02 memang tidak punya info itu per baris. Jadi di
        // sini tiga kolom tsb di-"XLOOKUP" langsung dari tabel master_coa, dicocokkan
        // berdasarkan Asset Class (kolom asset_class di import_ar02_reg3) == account_determination
        // di master_coa -- PERSIS seperti cara VLOOKUP manual di file Excel referensi.
        // (Sempat dicoba lewat REKAP FAGLL dulu -- asset+sub_number -> account -> master_coa --
        // tapi itu SALAH: aset yang gak pernah ada posting di FAGLL, mis. Tanah [gak disusutkan],
        // jadi gak ketemu padahal harusnya tetap bisa di-lookup langsung dari Asset Class-nya.)
        $adaMasterCoaLookup = tabelAdaDdm($con, 'master_coa');
        $pakaiXlookupAr02   = $adaMasterCoaLookup;

        if ($pakaiXlookupAr02) {
            $fromSql = "`import_ar02_reg3` a
                LEFT JOIN master_coa mc ON TRIM(mc.account_determination) = TRIM(a.asset_class)";
            $kolomExpr = [
                "a.`bal_sh_acct_APC`", "a.`nomor_asset`", "a.`sub_number`", "a.`asset_class`", "a.`keterangan_asset`",
                "a.`cabang`", "a.`profit_center`", "a.`acquisition`", "a.`retirement`", "a.`transfers`",
                "mc.`akun_akumulasi_penyusutan`", "mc.`ckpn`", "mc.`akun_beban_penyusutan`",
            ];
            $kolomBulan = 'a.periode_bulan';
            $kolomTahun = 'a.periode_tahun';
            $orderDefaultCol = 'a.id'; // qualify: master_coa juga punya kolom `id`, tanpa alias jadi ambigu
        } else {
            $fromSql = "`$table`";
            $kolomExpr = array_map(fn($c) => "`$c`", [
                'bal_sh_acct_APC', 'nomor_asset', 'sub_number', 'asset_class', 'keterangan_asset',
                'cabang', 'profit_center', 'acquisition', 'retirement', 'transfers',
                'gl_akumulasi_penyusutan', 'ckpn', 'gl_beban_penyusutan',
            ]);
            $orderDefaultCol = 'id';
        }
        $kolomAlias = ['bal_sh_acct_APC', 'nomor_asset', 'sub_number', 'asset_class', 'keterangan_asset', 'cabang', 'profit_center', 'acquisition', 'retirement', 'transfers', 'gl_akumulasi_penyusutan', 'ckpn', 'gl_beban_penyusutan'];
    } elseif ($action === 'fagll') {
        // "Import Rekap FAGLL" nyimpen datanya ke tabel import_fagll (dedicated, terpisah dari
        // import_penyusutan). Tabel ini gak punya kolom periode_bulan/periode_tahun langsung,
        // jadi filter periode diturunkan dari posting_date_norm pakai MONTH()/YEAR().
        $table = 'import_fagll';
        $kolomAlias = ['cost_center', 'asset', 'asset_subnumber', 'account', 'posting_date', 'amount_local_currency', 'profit_center', 'cabang', 'text', 'document_number'];
        $kolomExpr = array_map(fn($c) => "`$c`", $kolomAlias);
        $fromSql = "`$table`";
        $kolomBulan = 'MONTH(posting_date_norm)'; // ekspresi SQL, bukan nama kolom polos
        $kolomTahun = 'YEAR(posting_date_norm)';  // ekspresi SQL, bukan nama kolom polos
        $orderDefaultCol = 'id'; // WAJIB ada: tanpa ini muncul warning 'Undefined variable' -> JSON rusak
    } elseif ($action === 'f01') {
        // "Import Data F.01" -- rekap saldo akun B/S P&L per Account Number, dipakai buat
        // cross-check saldo akhir (Penurunan Nilai/CKPN) di dashboard. Punya periode sendiri
        // (periode_bulan/periode_tahun), diisi manual pas upload sama seperti AR02 reg3.
        $table = 'import_data_f01';
        $kolomBulan = 'periode_bulan';
        $kolomTahun = 'periode_tahun';
        $kolomAlias = ['account_number', 'financial_statement_item', 'text_bs_pl_item', 'total_reporting_period', 'total_comparison_period', 'absolute_difference', 'percentage_difference'];
        $kolomExpr = array_map(fn($c) => "`$c`", $kolomAlias);
        $fromSql = "`$table`";
        $orderDefaultCol = 'id';
    } elseif ($action === 'coa') {
        // "Import Master COA" -- tabel referensi (bukan per periode), upsert berdasarkan
        // account_determination. Jadi tidak ada filter Bulan/Tahun untuk tab ini.
        $table = 'master_coa';
        $kolomBulan = null;
        $kolomTahun = null;
        $kolomAlias = ['account_determination', 'akun_akumulasi_penyusutan', 'ckpn', 'akun_beban_penyusutan'];
        $kolomExpr = array_map(fn($c) => "`$c`", $kolomAlias);
        $fromSql = "`$table`";
        $orderDefaultCol = 'id';
    } elseif (isset($NILAI_TAB_DEF[$action])) {
        // Nilai Perolehan / Nilai Akumulasi / Nilai DEP -- struktur sama, per periode (periode_bulan/periode_tahun)
        $table = $NILAI_TAB_DEF[$action]['tabel'];
        $kolomBulan = 'periode_bulan';
        $kolomTahun = 'periode_tahun';
        $kolomAlias = ['cost_center', 'asset', 'asset_subnumber', 'account', 'posting_date', 'amount_local_currency', 'profit_center', 'text', 'document_number'];
        $kolomExpr = array_map(fn($c) => "`$c`", $kolomAlias);
        $fromSql = "`$table`";
        $orderDefaultCol = 'id';
    } else {
        echo json_encode(['error' => 'Aksi tidak dikenal']);
        exit();
    }

    $draw   = isset($_GET['draw']) ? (int)$_GET['draw'] : 0;
    $start  = isset($_GET['start']) ? (int)$_GET['start'] : 0;
    $length = isset($_GET['length']) ? (int)$_GET['length'] : 10;
    if ($length < 1) { $length = 10; }
    if ($length > 500) { $length = 500; } // batas wajar biar tidak disalahgunakan
    $searchValue = isset($_GET['search']['value']) ? trim($_GET['search']['value']) : '';

    // Kalau tabel sumbernya belum ada (belum pernah upload), balas kosong
    $tabelAdaRes = mysqli_query($con, "SHOW TABLES LIKE '" . mysqli_real_escape_string($con, $table) . "'");
    if (!$tabelAdaRes || mysqli_num_rows($tabelAdaRes) === 0) {
        echo json_encode(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        exit();
    }

    // Sorting
    $orderBy = "$orderDefaultCol DESC"; // default: data terbaru dulu
    if (isset($_GET['order'][0]['column'])) {
        $colIdx = (int)$_GET['order'][0]['column'];
        $dir = (isset($_GET['order'][0]['dir']) && strtolower($_GET['order'][0]['dir']) === 'asc') ? 'ASC' : 'DESC';
        if (isset($kolomExpr[$colIdx])) {
            $orderBy = $kolomExpr[$colIdx] . ' ' . $dir;
        }
    }

    // Total keseluruhan (tanpa filter pencarian) -- selalu dari tabel dasarnya saja (tanpa JOIN),
    // karena JOIN-nya cuma LEFT JOIN lookup 1-ke-1 (tidak menambah/mengurangi jumlah baris).
    $resTotal = mysqli_query($con, "SELECT COUNT(*) AS c FROM `$table`");
    $recordsTotal = $resTotal ? (int)mysqli_fetch_assoc($resTotal)['c'] : 0;

    // WHERE untuk pencarian global (search semua kolom, termasuk kolom hasil lookup)
    $whereParts = [];
    if ($searchValue !== '') {
        $searchEsc = mysqli_real_escape_string($con, $searchValue);
        $likeParts = [];
        foreach ($kolomExpr as $expr) {
            $likeParts[] = "$expr LIKE '%$searchEsc%'";
        }
        $whereParts[] = '(' . implode(' OR ', $likeParts) . ')';
    }

    // Filter Bulan & Tahun (semua tab punya kolom periode, cuma beda nama kolom tahunnya)
    $filterBulan = isset($_GET['filter_bulan']) ? trim($_GET['filter_bulan']) : '';
    $filterTahun = isset($_GET['filter_tahun']) ? trim($_GET['filter_tahun']) : '';
    if ($kolomBulan !== null && $filterBulan !== '' && ctype_digit($filterBulan)) {
        $whereParts[] = "$kolomBulan = '" . mysqli_real_escape_string($con, $filterBulan) . "'";
    }
    if ($kolomTahun !== null && $filterTahun !== '' && ctype_digit($filterTahun)) {
        $whereParts[] = "$kolomTahun = '" . mysqli_real_escape_string($con, $filterTahun) . "'";
    }

    $whereSql = !empty($whereParts) ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

    // Total setelah difilter -- ini yang butuh FROM lengkap (dengan JOIN), karena filter/search
    // bisa menyentuh kolom hasil lookup.
    $resFiltered = mysqli_query($con, "SELECT COUNT(*) AS c FROM $fromSql $whereSql");
    $recordsFiltered = $resFiltered ? (int)mysqli_fetch_assoc($resFiltered)['c'] : 0;

    // Ambil data sesuai halaman yang diminta. Tiap ekspresi di-alias-kan ke nama field yang
    // dipakai di JSON output ($kolomAlias), supaya baris hasil lookup (mis. mc.ckpn) tetap
    // keluar dengan nama field yang diharapkan JS (mis. "ckpn").
    $selectList = [];
    foreach ($kolomExpr as $i => $expr) {
        $selectList[] = "$expr AS `{$kolomAlias[$i]}`";
    }
    $sql = "SELECT " . implode(', ', $selectList) . "
            FROM $fromSql
            $whereSql
            ORDER BY $orderBy
            LIMIT $start, $length";
    $res = mysqli_query($con, $sql);

    // Kalau query gagal (misal struktur tabel di database belum sinkron dengan kolom yang
    // diminta kode ini), jangan lanjut ke json_encode dengan data kosong diam-diam --
    // balas error yang jelas biar gampang di-debug, dan JSON tetap valid (gak bikin
    // "Invalid JSON response" di DataTables).
    if (!$res) {
        echo json_encode([
            'draw' => $draw,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
            'error' => 'Query gagal di tabel `' . $table . '`: ' . mysqli_error($con) . '. Kemungkinan struktur tabel di database belum sesuai dengan kolom yang diminta kode ini (' . implode(', ', $kolomAlias) . '). Cek dengan DESCRIBE ' . $table . ' di database.'
        ]);
        exit();
    }

    $data = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $rowOut = [];
        foreach ($kolomAlias as $col) {
            $rowOut[] = htmlspecialchars($row[$col] ?? '', ENT_QUOTES, 'UTF-8');
        }
        $data[] = $rowOut;
    }

    echo json_encode(['draw' => $draw, 'recordsTotal' => $recordsTotal, 'recordsFiltered' => $recordsFiltered, 'data' => $data]);
    exit();

    } catch (\Throwable $e) {
        // Jaring pengaman terakhir: apapun error-nya (bukan cuma query gagal), tetap
        // balas JSON valid, bukan HTML/warning polos yang bikin DataTables error
        // "Invalid JSON response".
        echo json_encode(['draw' => 0, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => 'Error: ' . $e->getMessage()]);
        exit();
    }
}

$namaBulanMap = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
?>
<!doctype html>
<html lang="en">
  <!--begin::Head-->
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Daftar Data Aset Tetap - Web Aset Tetap</title>
    <link rel="icon" type="image/png" href="../../dist/assets/img/emblem.png" /> 
    <link rel="shortcut icon" type="image/png" href="../../dist/assets/img/emblem.png" />  
    <!--begin::Accessibility Meta Tags-->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes" />
    <meta name="color-scheme" content="light dark" />
    <meta name="theme-color" content="#007bff" media="(prefers-color-scheme: light)" />
    <meta name="theme-color" content="#1a1a1a" media="(prefers-color-scheme: dark)" />
    <!--end::Accessibility Meta Tags-->
    <!--begin::Fonts-->
    <link rel="stylesheet" href="../../dist/css/index.css"/>
      <link rel="stylesheet" href="../../dist/css/overlayscrollbars.min.css"/>
      <link rel="stylesheet" href="../../dist/css/bootstrap-icons/bootstrap-icons.min.css"/>
      <link rel="stylesheet" href="../../dist/css/adminlte.css" />
    <!-- Custom Styles -->
    <style>
      .app-sidebar {
        background-color: #0b3a8c !important;
      }
      .app-header, nav.app-header, .app-header.navbar {
        border-bottom: 0 !important;
        box-shadow: none !important;
      }
      .sidebar-brand {
        background-color: #0b3a8c !important;
        margin-bottom: 0 !important;
        padding: 0.25rem 0 !important;
        border-bottom: 0 !important;
        box-shadow: none !important;
      }
      .sidebar-brand .brand-link {
        display: block !important;
        padding: 0.5rem 0.75rem !important;
        border-bottom: 0 !important;
        box-shadow: none !important;
        background-color: transparent !important;
      }
      .sidebar-brand .brand-link .brand-image {
        display: block !important;
        height: auto !important;
        max-height: 48px !important;
        margin: 0 !important;
        padding: 6px 8px !important;
        background-color: transparent !important;
      }
      .app-sidebar {
        border-right: 0 !important;
      }
      .app-sidebar,
      .app-sidebar a,
      .app-sidebar .nav-link,
      .app-sidebar .nav-link p,
      .app-sidebar .nav-header,
      .app-sidebar .brand-text,
      .app-sidebar .nav-icon,
      .app-sidebar .nav-badge {
        color: #ffffff !important;
        fill: #ffffff !important;
      }
      .app-sidebar .nav-link .nav-icon,
      .app-sidebar .nav-link i {
        color: #ffffff !important;
      }
      .app-sidebar .nav-link.active,
      .app-sidebar .nav-link:hover {
        background-color: #0b5db7 !important;
        color: #ffffff !important;
        fill: #ffffff !important;
      }
      .app-sidebar .nav-link.active .nav-icon,
      .app-sidebar .nav-link:hover .nav-icon,
      .app-sidebar .nav-link.active i,
      .app-sidebar .nav-link:hover i {
        color: #ffffff !important;
      }
      .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        border: 1px solid #dee2e6;
        border-radius: 0.25rem;
      }
      .table-responsive::-webkit-scrollbar {
        height: 8px;
      }
      .table-responsive::-webkit-scrollbar-track {
        background: #f1f1f1;
      }
      .table-responsive::-webkit-scrollbar-thumb {
        background: #888;
        border-radius: 4px;
      }
      .table-responsive::-webkit-scrollbar-thumb:hover {
        background: #555;
      }
      #tableDatAset thead th,
      #tableDatAset tbody td,
      #tableAr02 thead th,
      #tableAr02 tbody td,
      #tableFagll thead th,
      #tableFagll tbody td,
      #tableF01 thead th,
      #tableF01 tbody td,
      #tableCoa thead th,
      #tableCoa tbody td {
        padding: 8px 12px;
        white-space: nowrap;
        min-width: 130px;
      }
      #tableDatAset thead th,
      #tableAr02 thead th,
      #tableFagll thead th,
      #tableF01 thead th,
      #tableCoa thead th {
        background-color: #f8f9fa;
        font-weight: 600;
        border-bottom: 2px solid #dee2e6;
      }
      #tableDatAset tbody td,
      #tableAr02 tbody td,
      #tableFagll tbody td,
      #tableF01 tbody td,
      #tableCoa tbody td {
        border-bottom: 1px solid #dee2e6;
      }
      .tabel-nilai thead th,
      .tabel-nilai tbody td {
        padding: 8px 12px;
        white-space: nowrap;
        min-width: 130px;
      }
      .tabel-nilai thead th {
        background-color: #f8f9fa;
        font-weight: 600;
        border-bottom: 2px solid #dee2e6;
      }
      .tabel-nilai tbody td {
        border-bottom: 1px solid #dee2e6;
      }
      .nav-tabs .nav-link.active {
        font-weight: 600;
        border-bottom: 3px solid #0b3a8c;
      }
    </style>
    <!--end::Custom Styles-->
    <link rel="stylesheet" href="../../dist/css/dataTables.dataTables.min.css" />
  </head>
  <body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <!--begin::App Wrapper-->
    <div class="app-wrapper">
      <!--begin::Header-->
      <nav class="app-header navbar navbar-expand bg-white border-0 shadow-none" style="border-bottom:0!important;box-shadow:none!important;">
        <div class="container-fluid">
          <ul class="navbar-nav ms-auto">
            <li class="nav-item dropdown user-menu">
              <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                <img src="../../dist/assets/img/profile.png" 
                    class="user-image rounded-circle shadow" alt="User Image"/>
                <span class="d-none d-md-inline">
                  <?php echo htmlspecialchars($_SESSION['name']); ?>
                </span>
              </a>
              <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                <li class="user-header text-bg-primary text-center">
                  <img src="../../dist/assets/img/profile.png" 
                      class="rounded-circle shadow mb-2" alt="User Image" style="width:80px;height:80px;">
                  <p class="mb-0 fw-bold"><?php echo htmlspecialchars($_SESSION['name']); ?></p>
                  <small>NIPP: <?php echo htmlspecialchars($_SESSION['nipp']); ?></small>
                </li>
                <li class="user-menu-body">
                  <div class="row ps-3 pe-3 pt-2 pb-2 user-info">
                    <div class="col-6 text-start">
                      <small class="text-muted">Type User:</small><br>
                      <span class="badge bg-primary">
                        <?php echo htmlspecialchars($_SESSION['Type_User']); ?>
                      </span>
                    </div>
                    <div class="col-6 text-end">
                    <small class="text-muted">Cabang:</small><br>
                    <span class="fw-semibold small">
                    <p class="fw-semibold"><?php echo htmlspecialchars($_SESSION['Cabang'] . ' - ' . $_SESSION['profit_center_text']); ?></p>
                  </span>
                    </div>
                  </div>
                  <hr class="m-0"/>
                </li>
                  <li class="user-footer d-flex align-items-center px-3 py-2">
                    <a href="../profile/profile.php" class="btn btn-sm btn-outline-primary">
                      <i class="bi bi-person"></i> Profile
                    </a>
                    <a href="../login/login_view.php" class="btn btn-sm btn-danger ms-auto">
                      <i class="bi bi-box-arrow-right"></i> Logout
                    </a>
                  </li>
                </ul>
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
            <div class="row">
              <div class="col-sm-6"><h3 class="mb-0">Daftar Data Aset Tetap</h3></div>
              <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                  <li class="breadcrumb-item"><a href="../dasbor/dasbor.php">Home</a></li>
                  <li class="breadcrumb-item active">Daftar Data Aset Tetap</li>
                </ol>
              </div>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <div class="row">
              <div class="card card-outline mb-4">
                <div class="card-header">
                  <ul class="nav nav-tabs card-header-tabs" id="hasilTab" role="tablist">
                    <li class="nav-item" role="presentation">
                      <button class="nav-link active" id="tab-dat-btn" data-bs-toggle="tab" data-bs-target="#tab-dat" type="button" role="tab" aria-controls="tab-dat" aria-selected="true">
                        DAT26PER16 
                      </button>
                    </li>
                    <li class="nav-item" role="presentation">
                      <button class="nav-link" id="tab-ar02-btn" data-bs-toggle="tab" data-bs-target="#tab-ar02" type="button" role="tab" aria-controls="tab-ar02" aria-selected="false">
                        AR02 reg3
                      </button>
                    </li>
                    <li class="nav-item" role="presentation">
                      <button class="nav-link" id="tab-fagll-btn" data-bs-toggle="tab" data-bs-target="#tab-fagll" type="button" role="tab" aria-controls="tab-fagll" aria-selected="false">
                        FAGLL Nilai Penyusutan
                      </button>
                    </li>
                    <li class="nav-item" role="presentation">
                      <button class="nav-link" id="tab-f01-btn" data-bs-toggle="tab" data-bs-target="#tab-f01" type="button" role="tab" aria-controls="tab-f01" aria-selected="false">
                        Data F.01
                      </button>
                    </li>
                    <?php foreach ($NILAI_TAB_DEF as $nk => $nd): ?>
                    <li class="nav-item" role="presentation">
                      <button class="nav-link" id="tab-<?= $nk ?>-btn" data-bs-toggle="tab" data-bs-target="#tab-<?= $nk ?>" type="button" role="tab" aria-controls="tab-<?= $nk ?>" aria-selected="false">
                        <?= htmlspecialchars($nd['judul']) ?>
                      </button>
                    </li>
                    <?php endforeach; ?>
                    <li class="nav-item" role="presentation">
                      <button class="nav-link" id="tab-coa-btn" data-bs-toggle="tab" data-bs-target="#tab-coa" type="button" role="tab" aria-controls="tab-coa" aria-selected="false">
                        Master COA
                      </button>
                    </li>
                  </ul>
                </div>
                <div class="card-body">
                  <div class="tab-content" id="hasilTabContent">

                    <!--begin::Tab Hasil DAT-->
                    <div class="tab-pane fade show active" id="tab-dat" role="tabpanel" aria-labelledby="tab-dat-btn">
                      <?php
                      $latestDatRow = null;
                      $totalDatRecords = 0;
                      $cekTabelDatAda = mysqli_query($con, "SHOW TABLES LIKE 'import_dat_monitoring'");
                      if ($cekTabelDatAda && mysqli_num_rows($cekTabelDatAda) > 0) {
                          $latestDatQuery = "SELECT COUNT(*) as total_records, MAX(created_at) as last_import FROM import_dat_monitoring";
                          $latestDatResult = mysqli_query($con, $latestDatQuery);
                          $latestDatRow = $latestDatResult ? mysqli_fetch_assoc($latestDatResult) : null;
                          $totalDatRecords = isset($latestDatRow['total_records']) ? (int)$latestDatRow['total_records'] : 0;
                      }
                      ?>
                      <?php if ($totalDatRecords === 0): ?>
                      <div class="alert alert-info d-flex align-items-center gap-2">
                        <i class="bi bi-info-circle fs-5"></i>
                        <div>
                          <strong>Belum ada data yang diupload ke database.</strong><br>
                          Silakan upload data DAT terlebih dahulu di menu
                          <a href="../import_monitoring/import_monitoring.php">Import DAT</a>.
                        </div>
                      </div>
                      <?php endif; ?>
                      <?php
                      // Ambil daftar tahun yang BENAR-BENAR ada di data (biar dropdown tidak nampilin pilihan kosong)
                      // Catatan: tabel ini gak ada dimensi periode_bulan lagi, jadi cuma dropdown Tahun.
                      $daftarTahunDat = [];
                      $cekTabelDat = mysqli_query($con, "SHOW TABLES LIKE 'import_dat_monitoring'");
                      if ($cekTabelDat && mysqli_num_rows($cekTabelDat) > 0) {
                          $resTahunDat = mysqli_query($con, "SELECT DISTINCT tahun_buku FROM import_dat_monitoring WHERE tahun_buku IS NOT NULL AND tahun_buku <> '' ORDER BY tahun_buku DESC");
                          if ($resTahunDat) { while ($r = mysqli_fetch_assoc($resTahunDat)) { $daftarTahunDat[] = $r['tahun_buku']; } }
                      }
                      ?>
                      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                          <label for="filterTahunDat" class="mb-0 fw-semibold">Tahun:</label>
                          <select id="filterTahunDat" class="form-select form-select-sm" style="width:auto;">
                            <option value="">Semua Tahun</option>
                            <?php foreach ($daftarTahunDat as $t): ?>
                              <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <?php
                        $lastDatDate = $latestDatRow['last_import'] ?? null;
                        if ($lastDatDate) {
                          $dt = new DateTime($lastDatDate);
                          echo '<span style="color:#dc3545;font-weight:bold;">Diimport: ' . htmlspecialchars($dt->format('d M Y H:i:s')) . '</span>';
                        }
                        ?>
                      </div>
                      <div class="table-responsive">
                        <table id="tableDatAset" class="display nowrap table table-striped" style="width:100%; min-width: 900px;">
                          <thead>
                            <tr>
                              <th>Profit Center</th>
                              <th>Cabang</th>
                              <th>Tahun Buku</th>
                              <th>Nomor Aset</th>
                              <th>Sub-number</th>
                              <th>GL Aset</th>
                              <th>GL Akumulasi</th>
                              <th>Keterangan Aset</th>
                              <th>Tgl. Perolehan</th>
                              <th>Tgl. Mulai Penyusutan</th>
                              <th>Nilai Perolehan s.d Tahun Berjalan</th>
                              <th>Akumulasi Penyusutan</th>
                              <th>GL Account EXP. Depre.</th>
                            </tr>
                          </thead>
                          <tbody>
                          <!-- Data diisi lewat AJAX oleh DataTables -->
                          </tbody>
                        </table>
                      </div>
                    </div>
                    <!--end::Tab Hasil DAT-->

                    <!--begin::Tab Hasil AR02 reg3-->
                    <div class="tab-pane fade" id="tab-ar02" role="tabpanel" aria-labelledby="tab-ar02-btn">
                      <?php
                      $latestAr02Row = null;
                      $totalAr02Records = 0;
                      $cekTabelAr02Ada = mysqli_query($con, "SHOW TABLES LIKE 'import_ar02_reg3'");
                      if ($cekTabelAr02Ada && mysqli_num_rows($cekTabelAr02Ada) > 0) {
                          $latestAr02Query = "SELECT COUNT(*) as total_records, MAX(created_at) as last_import FROM import_ar02_reg3";
                          $latestAr02Result = mysqli_query($con, $latestAr02Query);
                          $latestAr02Row = $latestAr02Result ? mysqli_fetch_assoc($latestAr02Result) : null;
                          $totalAr02Records = isset($latestAr02Row['total_records']) ? (int)$latestAr02Row['total_records'] : 0;
                      }
                      ?>
                      <?php if ($totalAr02Records === 0): ?>
                      <div class="alert alert-info d-flex align-items-center gap-2">
                        <i class="bi bi-info-circle fs-5"></i>
                        <div>
                          <strong>Belum ada data yang diupload ke database.</strong><br>
                          Silakan upload data AR02 reg3 terlebih dahulu di menu
                          <a href="../import_monitoring/import_monitoring.php">Import AR02 reg3</a>.
                        </div>
                      </div>
                      <?php endif; ?>
                      <?php
                      // Kolom Akumulasi Penyusutan/CKPN/Beban Penyusutan di tabel ini di-XLOOKUP
                      // langsung: asset_class (kolom Asset Class di AR02) -> account_determination
                      // di master_coa. Kalau tabel master_coa belum ada, kasih tau.
                      $cekMasterCoaUtkAr02Tab = mysqli_query($con, "SHOW TABLES LIKE 'master_coa'");
                      $adaMasterCoaUtkAr02Tab = $cekMasterCoaUtkAr02Tab && mysqli_num_rows($cekMasterCoaUtkAr02Tab) > 0;
                      ?>
                      <?php if ($totalAr02Records > 0 && !$adaMasterCoaUtkAr02Tab): ?>
                      <div class="alert alert-warning d-flex align-items-center gap-2">
                        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                        <div>
                          <strong>⚠ Kolom Akumulasi Penyusutan, CKPN, & Beban Penyusutan belum bisa di-lookup.</strong>
                          Kolom ini diambil (xlookup Asset Class → <code>master_coa.account_determination</code>)
                          dari tabel <strong>master_coa</strong>, yang belum ada di database.
                          Sampai tabelnya diimport, tiga kolom tsb akan tampil kosong.
                        </div>
                      </div>
                      <?php endif; ?>
                      <?php
                      $daftarBulanAr02 = [];
                      $daftarTahunAr02 = [];
                      $cekTabelAr02 = mysqli_query($con, "SHOW TABLES LIKE 'import_ar02_reg3'");
                      if ($cekTabelAr02 && mysqli_num_rows($cekTabelAr02) > 0) {
                          $resBulanAr02 = mysqli_query($con, "SELECT DISTINCT periode_bulan FROM import_ar02_reg3 WHERE periode_bulan IS NOT NULL AND periode_bulan <> '' ORDER BY periode_bulan ASC");
                          if ($resBulanAr02) { while ($r = mysqli_fetch_assoc($resBulanAr02)) { $daftarBulanAr02[] = $r['periode_bulan']; } }
                          $resTahunAr02 = mysqli_query($con, "SELECT DISTINCT periode_tahun FROM import_ar02_reg3 WHERE periode_tahun IS NOT NULL AND periode_tahun <> '' ORDER BY periode_tahun DESC");
                          if ($resTahunAr02) { while ($r = mysqli_fetch_assoc($resTahunAr02)) { $daftarTahunAr02[] = $r['periode_tahun']; } }
                      }
                      ?>
                      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                          <label for="filterBulanAr02" class="mb-0 fw-semibold">Bulan:</label>
                          <select id="filterBulanAr02" class="form-select form-select-sm" style="width:auto;">
                            <option value="">Semua Bulan</option>
                            <?php foreach ($daftarBulanAr02 as $b): $bInt = (int)$b; ?>
                              <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($namaBulanMap[$bInt] ?? $b) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <label for="filterTahunAr02" class="mb-0 fw-semibold">Tahun:</label>
                          <select id="filterTahunAr02" class="form-select form-select-sm" style="width:auto;">
                            <option value="">Semua Tahun</option>
                            <?php foreach ($daftarTahunAr02 as $t): ?>
                              <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <?php
                        $lastAr02Date = $latestAr02Row['last_import'] ?? null;
                        if ($lastAr02Date) {
                          $dt3 = new DateTime($lastAr02Date);
                          echo '<span style="color:#dc3545;font-weight:bold;">Diimport: ' . htmlspecialchars($dt3->format('d M Y H:i:s')) . '</span>';
                        }
                        ?>
                      </div>
                      <div class="table-responsive">
                        <table id="tableAr02" class="display nowrap table table-striped" style="width:100%; min-width: 900px;">
                          <thead>
                            <tr>
                              <th>Bal.Sh.Acct APC</th>
                              <th>Nomor Aset</th>
                              <th>Sub-number</th>
                              <th>Asset Class</th>
                              <th>Keterangan Aset</th>
                              <th>Cabang</th>
                              <th>Profit Center</th>
                              <th>Acquisition</th>
                              <th>Retirement</th>
                              <th>Transfer</th>
                              <th>Akumulasi Penyusutan</th>
                              <th>CKPN</th>
                              <th>Beban Penyusutan</th>
                            </tr>
                          </thead>
                          <tbody>
                          <!-- Data diisi lewat AJAX oleh DataTables -->
                          </tbody>
                        </table>
                      </div>
                    </div>
                    <!--end::Tab Hasil AR02 reg3-->

                    <!--begin::Tab Hasil REKAP FAGLL-->
                    <div class="tab-pane fade" id="tab-fagll" role="tabpanel" aria-labelledby="tab-fagll-btn">
                      <?php
                      $latestFagllRow = null;
                      $totalFagllRecords = 0;
                      $cekTabelFagllAda = mysqli_query($con, "SHOW TABLES LIKE 'import_fagll'");
                      if ($cekTabelFagllAda && mysqli_num_rows($cekTabelFagllAda) > 0) {
                          $latestFagllQuery = "SELECT COUNT(*) as total_records, MAX(created_at) as last_import FROM import_fagll";
                          $latestFagllResult = mysqli_query($con, $latestFagllQuery);
                          $latestFagllRow = $latestFagllResult ? mysqli_fetch_assoc($latestFagllResult) : null;
                          $totalFagllRecords = isset($latestFagllRow['total_records']) ? (int)$latestFagllRow['total_records'] : 0;
                      }
                      ?>
                      <?php if ($totalFagllRecords === 0): ?>
                      <div class="alert alert-info d-flex align-items-center gap-2">
                        <i class="bi bi-info-circle fs-5"></i>
                        <div>
                          <strong>Belum ada data yang diupload ke database.</strong><br>
                          Silakan upload data REKAP FAGLL terlebih dahulu di menu
                          <a href="../import_monitoring/import_monitoring.php">Import REKAP FAGLL</a>.
                        </div>
                      </div>
                      <?php endif; ?>
                      <?php
                      // Ambil daftar Bulan/Tahun dari posting_date_norm (bukan kolom periode_bulan/
                      // periode_tahun -- tabel import_fagll gak punya kolom itu)
                      $daftarBulanFagll = [];
                      $daftarTahunFagll = [];
                      $cekTabelFagll = mysqli_query($con, "SHOW TABLES LIKE 'import_fagll'");
                      if ($cekTabelFagll && mysqli_num_rows($cekTabelFagll) > 0) {
                          $resBulanFagll = mysqli_query($con, "SELECT DISTINCT MONTH(posting_date_norm) AS bln FROM import_fagll WHERE posting_date_norm IS NOT NULL ORDER BY bln ASC");
                          if ($resBulanFagll) { while ($r = mysqli_fetch_assoc($resBulanFagll)) { if ($r['bln'] !== null) $daftarBulanFagll[] = $r['bln']; } }
                          $resTahunFagll = mysqli_query($con, "SELECT DISTINCT YEAR(posting_date_norm) AS thn FROM import_fagll WHERE posting_date_norm IS NOT NULL ORDER BY thn DESC");
                          if ($resTahunFagll) { while ($r = mysqli_fetch_assoc($resTahunFagll)) { if ($r['thn'] !== null) $daftarTahunFagll[] = $r['thn']; } }
                      }
                      ?>
                      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                          <label for="filterBulanFagll" class="mb-0 fw-semibold">Bulan:</label>
                          <select id="filterBulanFagll" class="form-select form-select-sm" style="width:auto;">
                            <option value="">Semua Bulan</option>
                            <?php foreach ($daftarBulanFagll as $b): $bInt = (int)$b; ?>
                              <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($namaBulanMap[$bInt] ?? $b) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <label for="filterTahunFagll" class="mb-0 fw-semibold">Tahun:</label>
                          <select id="filterTahunFagll" class="form-select form-select-sm" style="width:auto;">
                            <option value="">Semua Tahun</option>
                            <?php foreach ($daftarTahunFagll as $t): ?>
                              <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <?php
                        $lastFagllDate = $latestFagllRow['last_import'] ?? null;
                        if ($lastFagllDate) {
                          $dt4 = new DateTime($lastFagllDate);
                          echo '<span style="color:#dc3545;font-weight:bold;">Diimport: ' . htmlspecialchars($dt4->format('d M Y H:i:s')) . '</span>';
                        }
                        ?>
                      </div>
                      <div class="table-responsive">
                        <table id="tableFagll" class="display nowrap table table-striped" style="width:100%; min-width: 900px;">
                          <thead>
                            <tr>
                              <th>Cost Center</th>
                              <th>Asset</th>
                              <th>Asset Subnumber</th>
                              <th>Account (GL)</th>
                              <th>Posting Date</th>
                              <th>Amount (Local Currency)</th>
                              <th>Profit Center</th>
                              <th>Cabang</th>
                              <th>Text</th>
                              <th>Document Number</th>
                            </tr>
                          </thead>
                          <tbody>
                          <!-- Data diisi lewat AJAX oleh DataTables -->
                          </tbody>
                        </table>
                      </div>
                    </div>
                    <!--end::Tab Hasil REKAP FAGLL-->

                    <!--begin::Tab Hasil Data F.01-->
                    <div class="tab-pane fade" id="tab-f01" role="tabpanel" aria-labelledby="tab-f01-btn">
                      <?php
                      $latestF01Row = null;
                      $totalF01Records = 0;
                      $cekTabelF01Ada = mysqli_query($con, "SHOW TABLES LIKE 'import_data_f01'");
                      if ($cekTabelF01Ada && mysqli_num_rows($cekTabelF01Ada) > 0) {
                          $latestF01Query = "SELECT COUNT(*) as total_records, MAX(created_at) as last_import FROM import_data_f01";
                          $latestF01Result = mysqli_query($con, $latestF01Query);
                          $latestF01Row = $latestF01Result ? mysqli_fetch_assoc($latestF01Result) : null;
                          $totalF01Records = isset($latestF01Row['total_records']) ? (int)$latestF01Row['total_records'] : 0;
                      }
                      ?>
                      <?php if ($totalF01Records === 0): ?>
                      <div class="alert alert-info d-flex align-items-center gap-2">
                        <i class="bi bi-info-circle fs-5"></i>
                        <div>
                          <strong>Belum ada data yang diupload ke database.</strong><br>
                          Silakan upload data F.01 terlebih dahulu di menu
                          <a href="../import_monitoring/import_monitoring.php">Import Data F.01</a>.
                        </div>
                      </div>
                      <?php endif; ?>
                      <?php
                      $daftarBulanF01 = [];
                      $daftarTahunF01 = [];
                      $cekTabelF01 = mysqli_query($con, "SHOW TABLES LIKE 'import_data_f01'");
                      if ($cekTabelF01 && mysqli_num_rows($cekTabelF01) > 0) {
                          $resBulanF01 = mysqli_query($con, "SELECT DISTINCT periode_bulan FROM import_data_f01 WHERE periode_bulan IS NOT NULL AND periode_bulan <> '' ORDER BY periode_bulan ASC");
                          if ($resBulanF01) { while ($r = mysqli_fetch_assoc($resBulanF01)) { $daftarBulanF01[] = $r['periode_bulan']; } }
                          $resTahunF01 = mysqli_query($con, "SELECT DISTINCT periode_tahun FROM import_data_f01 WHERE periode_tahun IS NOT NULL AND periode_tahun <> '' ORDER BY periode_tahun DESC");
                          if ($resTahunF01) { while ($r = mysqli_fetch_assoc($resTahunF01)) { $daftarTahunF01[] = $r['periode_tahun']; } }
                      }
                      ?>
                      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                          <label for="filterBulanF01" class="mb-0 fw-semibold">Bulan:</label>
                          <select id="filterBulanF01" class="form-select form-select-sm" style="width:auto;">
                            <option value="">Semua Bulan</option>
                            <?php foreach ($daftarBulanF01 as $b): $bInt = (int)$b; ?>
                              <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($namaBulanMap[$bInt] ?? $b) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <label for="filterTahunF01" class="mb-0 fw-semibold">Tahun:</label>
                          <select id="filterTahunF01" class="form-select form-select-sm" style="width:auto;">
                            <option value="">Semua Tahun</option>
                            <?php foreach ($daftarTahunF01 as $t): ?>
                              <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <?php
                        $lastF01Date = $latestF01Row['last_import'] ?? null;
                        if ($lastF01Date) {
                          $dt5 = new DateTime($lastF01Date);
                          echo '<span style="color:#dc3545;font-weight:bold;">Diimport: ' . htmlspecialchars($dt5->format('d M Y H:i:s')) . '</span>';
                        }
                        ?>
                      </div>
                      <div class="table-responsive">
                        <table id="tableF01" class="display nowrap table table-striped" style="width:100%; min-width: 900px;">
                          <thead>
                            <tr>
                              <th>Account Number</th>
                              <th>Financial Statement Item</th>
                              <th>Text for B/S P&amp;L Item</th>
                              <th>Total of Reporting Period</th>
                              <th>Total of Comparison Period</th>
                              <th>Absolute Difference</th>
                              <th>Percentage Difference</th>
                            </tr>
                          </thead>
                          <tbody>
                          <!-- Data diisi lewat AJAX oleh DataTables -->
                          </tbody>
                        </table>
                      </div>
                    </div>
                    <!--end::Tab Hasil Data F.01-->

                    <!--begin::Tab Hasil Nilai Perolehan / Akumulasi / DEP-->
                    <?php foreach ($NILAI_TAB_DEF as $nk => $nd):
                      $nTabel = $nd['tabel'];
                      $nLatest = null; $nTotal = 0;
                      $daftarBulanN = []; $daftarTahunN = [];
                      $cekN = mysqli_query($con, "SHOW TABLES LIKE '" . mysqli_real_escape_string($con, $nTabel) . "'");
                      if ($cekN && mysqli_num_rows($cekN) > 0) {
                          $rN = mysqli_query($con, "SELECT COUNT(*) AS total_records, MAX(created_at) AS last_import FROM `$nTabel`");
                          $nLatest = $rN ? mysqli_fetch_assoc($rN) : null;
                          $nTotal = isset($nLatest['total_records']) ? (int)$nLatest['total_records'] : 0;
                          $rB = mysqli_query($con, "SELECT DISTINCT periode_bulan FROM `$nTabel` WHERE periode_bulan IS NOT NULL AND periode_bulan <> '' ORDER BY CAST(periode_bulan AS UNSIGNED) ASC");
                          if ($rB) { while ($r = mysqli_fetch_assoc($rB)) { $daftarBulanN[] = $r['periode_bulan']; } }
                          $rT = mysqli_query($con, "SELECT DISTINCT periode_tahun FROM `$nTabel` WHERE periode_tahun IS NOT NULL AND periode_tahun <> '' ORDER BY periode_tahun DESC");
                          if ($rT) { while ($r = mysqli_fetch_assoc($rT)) { $daftarTahunN[] = $r['periode_tahun']; } }
                      }
                    ?>
                    <div class="tab-pane fade" id="tab-<?= $nk ?>" role="tabpanel" aria-labelledby="tab-<?= $nk ?>-btn">
                      <?php if ($nTotal === 0): ?>
                      <div class="alert alert-info d-flex align-items-center gap-2">
                        <i class="bi bi-info-circle fs-5"></i>
                        <div>
                          <strong>Belum ada data yang diupload ke database.</strong><br>
                          Silakan upload data <?= htmlspecialchars($nd['judul']) ?> terlebih dahulu di menu
                          <a href="../import_monitoring/import_monitoring.php">Import <?= htmlspecialchars($nd['judul']) ?></a>.
                        </div>
                      </div>
                      <?php endif; ?>
                      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                          <label for="filterBulan-<?= $nk ?>" class="mb-0 fw-semibold">Bulan:</label>
                          <select id="filterBulan-<?= $nk ?>" class="form-select form-select-sm" style="width:auto;">
                            <option value="">Semua Bulan</option>
                            <?php foreach ($daftarBulanN as $b): $bInt = (int)$b; ?>
                              <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($namaBulanMap[$bInt] ?? $b) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <label for="filterTahun-<?= $nk ?>" class="mb-0 fw-semibold">Tahun:</label>
                          <select id="filterTahun-<?= $nk ?>" class="form-select form-select-sm" style="width:auto;">
                            <option value="">Semua Tahun</option>
                            <?php foreach ($daftarTahunN as $t): ?>
                              <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <?php
                        $lastNDate = $nLatest['last_import'] ?? null;
                        if ($lastNDate) {
                          $dtN = new DateTime($lastNDate);
                          echo '<span style="color:#dc3545;font-weight:bold;">Diimport: ' . htmlspecialchars($dtN->format('d M Y H:i:s')) . '</span>';
                        }
                        ?>
                      </div>
                      <div class="table-responsive">
                        <table id="table-<?= $nk ?>" class="display nowrap table table-striped tabel-nilai" style="width:100%; min-width: 900px;">
                          <thead>
                            <tr>
                              <th>Cost Center</th>
                              <th>Asset</th>
                              <th>Asset Subnumber</th>
                              <th>Account</th>
                              <th>Posting Date</th>
                              <th>Amount in Local Currency</th>
                              <th>Profit Center</th>
                              <th>Text</th>
                              <th>Document Number</th>
                            </tr>
                          </thead>
                          <tbody>
                          <!-- Data diisi lewat AJAX oleh DataTables -->
                          </tbody>
                        </table>
                      </div>
                    </div>
                    <?php endforeach; ?>
                    <!--end::Tab Hasil Nilai Perolehan / Akumulasi / DEP-->

                    <!--begin::Tab Hasil Master COA-->
                    <div class="tab-pane fade" id="tab-coa" role="tabpanel" aria-labelledby="tab-coa-btn">
                      <?php
                      $latestCoaRow = null;
                      $totalCoaRecords = 0;
                      $cekTabelCoaAda = mysqli_query($con, "SHOW TABLES LIKE 'master_coa'");
                      if ($cekTabelCoaAda && mysqli_num_rows($cekTabelCoaAda) > 0) {
                          $latestCoaQuery = "SELECT COUNT(*) as total_records, MAX(updated_at) as last_import FROM master_coa";
                          $latestCoaResult = mysqli_query($con, $latestCoaQuery);
                          $latestCoaRow = $latestCoaResult ? mysqli_fetch_assoc($latestCoaResult) : null;
                          $totalCoaRecords = isset($latestCoaRow['total_records']) ? (int)$latestCoaRow['total_records'] : 0;
                      }
                      ?>
                      <?php if ($totalCoaRecords === 0): ?>
                      <div class="alert alert-info d-flex align-items-center gap-2">
                        <i class="bi bi-info-circle fs-5"></i>
                        <div>
                          <strong>Belum ada data yang diupload ke database.</strong><br>
                          Silakan upload data Master COA terlebih dahulu di menu
                          <a href="../import_monitoring/import_monitoring.php">Import Master COA</a>.
                        </div>
                      </div>
                      <?php endif; ?>
                      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                          <span class="text-muted">Tabel referensi (master) &mdash; tidak per periode, upsert berdasarkan Account Determination.</span>
                        </div>
                        <?php
                        $lastCoaDate = $latestCoaRow['last_import'] ?? null;
                        if ($lastCoaDate) {
                          $dt6 = new DateTime($lastCoaDate);
                          echo '<span style="color:#dc3545;font-weight:bold;">Terakhir diperbarui: ' . htmlspecialchars($dt6->format('d M Y H:i:s')) . '</span>';
                        }
                        ?>
                      </div>
                      <div class="table-responsive">
                        <table id="tableCoa" class="display nowrap table table-striped" style="width:100%; min-width: 700px;">
                          <thead>
                            <tr>
                              <th>Account Determination</th>
                              <th>Akun Akumulasi Penyusutan</th>
                              <th>CKPN</th>
                              <th>Akun Beban Penyusutan</th>
                            </tr>
                          </thead>
                          <tbody>
                          <!-- Data diisi lewat AJAX oleh DataTables -->
                          </tbody>
                        </table>
                      </div>
                    </div>
                    <!--end::Tab Hasil Master COA-->

                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </main>
      <!--end::App Main-->
      <footer class="app-footer">
        <div class="float-end d-none d-sm-inline">PT Pelabuhan Indonesia (Persero)</div>
        <strong>
          Copyright &copy; Proyek Aset Tetap Regional&nbsp;
        </strong>
      </footer>
    </div>
    <!--end::App Wrapper-->
    <!--begin::Script-->
    <script src="../../dist/js/overlayscrollbars.browser.es6.min.js"></script>
    <script src="../../dist/js/popper.min.js"></script>
    <script src="../../dist/js/bootstrap.min.js"></script>
    <script src="../../dist/js/adminlte.js"></script>
    <script>
      const SELECTOR_SIDEBAR_WRAPPER = '.sidebar-wrapper';
      const Default = {
        scrollbarTheme: 'os-theme-light',
        scrollbarAutoHide: 'leave',
        scrollbarClickScroll: true,
      };
      document.addEventListener('DOMContentLoaded', function () {
        const sidebarWrapper = document.querySelector(SELECTOR_SIDEBAR_WRAPPER);
        if (sidebarWrapper && OverlayScrollbarsGlobal?.OverlayScrollbars !== undefined) {
          OverlayScrollbarsGlobal.OverlayScrollbars(sidebarWrapper, {
            scrollbars: {
              theme: Default.scrollbarTheme,
              autoHide: Default.scrollbarAutoHide,
              clickScroll: Default.scrollbarClickScroll,
            },
          });
        }
      });
    </script>
    <script src="../../dist/js/jquery-3.6.0.min.js"></script>
    <script src="../../dist/js/dataTables.js"></script>
    <script src="../../dist/js/dataTables.responsive.js"></script>
    <script src="../../dist/js/dataTables.buttons.js"></script>
    <script src="../../dist/js/buttons.html5.js"></script>
    <script src="../../dist/js/buttons.print.js"></script>
    <script src="../../dist/js/jszip.min.js"></script>
    <script src="../../dist/js/pdfmake.min.js"></script>
    <script src="../../dist/js/vfs_fonts.min.js"></script>
    <script>

      const bahasaIndonesiaDataTable = {
        emptyTable: "Tidak ada data yang tersedia",
        info: "Menampilkan _START_ sampai _END_ dari _TOTAL_ entri",
        infoEmpty: "Menampilkan 0 sampai 0 dari 0 entri",
        infoFiltered: "(disaring dari _MAX_ entri keseluruhan)",
        lengthMenu: "Tampilkan _MENU_ entri",
        loadingRecords: "Sedang memuat...",
        processing: "Sedang memproses...",
        search: "Cari:",
        zeroRecords: "Tidak ditemukan data yang sesuai",
        paginate: {
          first: "Pertama",
          last: "Terakhir",
          next: "Selanjutnya",
          previous: "Sebelumnya"
        }
      };

      let dtDatAset = null;
      let dtAr02 = null;
      let dtFagll = null;
      let dtF01 = null;
      let dtCoa = null;

      $(document).ready(function () {
        function formatAngkaRibuanDt(data, type) {
          if (type !== 'display') return data; // filter/sort/type tetap pakai nilai mentah
          if (data === null || data === undefined || data === '') return data;
          var str = String(data).trim();
          if (str === '' || isNaN(str)) return data;
          var negatif = str.startsWith('-');
          if (negatif) str = str.substring(1);
          var parts = str.split('.'); // pisahkan bagian desimal kalau ada
          parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
          return (negatif ? '-' : '') + parts.join(',');
        }

        // Tab DAT langsung dimuat karena aktif dari awal
        dtDatAset = $('#tableDatAset').DataTable({
          responsive: false,
          autoWidth: false,
          scrollX: true,
          scrollCollapse: true,
          paging: true,
          pageLength: 10,
          searching: true,
          ordering: true,
          info: true,
          processing: true,
          language: bahasaIndonesiaDataTable,
          serverSide: true,
          ajax: {
            url: 'daftar_data_monitoring.php?ajax_action=dat',
            type: 'GET',
            data: function (d) {
              d.filter_tahun = $('#filterTahunDat').val();
            }
          },
          columns: [
            { data: 0 }, // Profit Center
            { data: 1 }, // Cabang
            { data: 2 }, // Tahun Buku
            { data: 3 }, // Nomor Aset
            { data: 4 }, // Sub-number
            { data: 5 }, // GL Aset
            { data: 6 }, // GL Akumulasi
            { data: 7 }, // Keterangan Aset
            { data: 8 }, // Tgl. Perolehan
            { data: 9 }, // Tgl. Mulai Penyusutan
            { data: 10, className: 'text-end', render: formatAngkaRibuanDt }, // Nilai Perolehan s.d Tahun Berjalan
            { data: 11, className: 'text-end', render: formatAngkaRibuanDt }, // Akumulasi Penyusutan
            { data: 12 } // GL Account EXP. Depre.
          ]
        });

        $('#filterTahunDat').on('change', function () {
          if (dtDatAset) {
            dtDatAset.ajax.reload(null, true); 
          }
        });

        // Tab AR02 reg3, di-init pas tab-nya pertama kali ditampilkan (biar kolom lebar kehitung benar)
        $('#tab-ar02-btn').on('shown.bs.tab', function () {
          if (!dtAr02) {
            dtAr02 = $('#tableAr02').DataTable({
              responsive: false,
              autoWidth: false,
              scrollX: true,
              scrollCollapse: true,
              paging: true,
              pageLength: 10,
              searching: true,
              ordering: true,
              info: true,
              processing: true,
              language: bahasaIndonesiaDataTable,
              serverSide: true,
              ajax: {
                url: 'daftar_data_monitoring.php?ajax_action=ar02',
                type: 'GET',
                data: function (d) {
                  d.filter_bulan = $('#filterBulanAr02').val();
                  d.filter_tahun = $('#filterTahunAr02').val();
                }
              },
              columns: [
                { data: 0 },  // Bal.Sh.Acct APC
                { data: 1 },  // Nomor Aset
                { data: 2 },  // Sub-number
                { data: 3 },  // Asset Class
                { data: 4 },  // Keterangan Aset
                { data: 5 },  // Cabang
                { data: 6 },  // Profit Center
                { data: 7 },  // Acquisition
                { data: 8 },  // Retirement
                { data: 9 },  // Transfer
                { data: 10 }, // Akumulasi Penyusutan
                { data: 11 }, // CKPN
                { data: 12 }  // Beban Penyusutan
              ]
            });

            $('#filterBulanAr02, #filterTahunAr02').on('change', function () {
              if (dtAr02) {
                dtAr02.ajax.reload(null, true);
              }
            });
          } else {
            dtAr02.columns.adjust();
          }
        });

        // Tab REKAP FAGLL, di-init pas tab-nya pertama kali ditampilkan
        $('#tab-fagll-btn').on('shown.bs.tab', function () {
          if (!dtFagll) {
            dtFagll = $('#tableFagll').DataTable({
              responsive: false,
              autoWidth: false,
              scrollX: true,
              scrollCollapse: true,
              paging: true,
              pageLength: 10,
              searching: true,
              ordering: true,
              info: true,
              processing: true,
              language: bahasaIndonesiaDataTable,
              serverSide: true,
              ajax: {
                url: 'daftar_data_monitoring.php?ajax_action=fagll',
                type: 'GET',
                data: function (d) {
                  d.filter_bulan = $('#filterBulanFagll').val();
                  d.filter_tahun = $('#filterTahunFagll').val();
                }
              },
              columns: [
                { data: 0 },  // Cost Center
                { data: 1 },  // Asset
                { data: 2 },  // Asset Subnumber
                { data: 3 },  // Account (GL)
                { data: 4 },  // Posting Date
                { data: 5, className: 'text-end', render: formatAngkaRibuanDt },  // Amount (Local Currency)
                { data: 6 },  // Profit Center
                { data: 7 },  // Cabang
                { data: 8 },  // Text
                { data: 9 }   // Document Number
              ]
            });

            $('#filterBulanFagll, #filterTahunFagll').on('change', function () {
              if (dtFagll) {
                dtFagll.ajax.reload(null, true);
              }
            });
          } else {
            dtFagll.columns.adjust();
          }
        });

        // Tab Data F.01, di-init pas tab-nya pertama kali ditampilkan
        $('#tab-f01-btn').on('shown.bs.tab', function () {
          if (!dtF01) {
            dtF01 = $('#tableF01').DataTable({
              responsive: false,
              autoWidth: false,
              scrollX: true,
              scrollCollapse: true,
              paging: true,
              pageLength: 10,
              searching: true,
              ordering: true,
              info: true,
              processing: true,
              language: bahasaIndonesiaDataTable,
              serverSide: true,
              ajax: {
                url: 'daftar_data_monitoring.php?ajax_action=f01',
                type: 'GET',
                data: function (d) {
                  d.filter_bulan = $('#filterBulanF01').val();
                  d.filter_tahun = $('#filterTahunF01').val();
                }
              },
              columns: [
                { data: 0 }, // Account Number
                { data: 1 }, // Financial Statement Item
                { data: 2 }, // Text for B/S P&L Item
                { data: 3, className: 'text-end', render: formatAngkaRibuanDt }, // Total of Reporting Period
                { data: 4, className: 'text-end', render: formatAngkaRibuanDt }, // Total of Comparison Period
                { data: 5, className: 'text-end', render: formatAngkaRibuanDt }, // Absolute Difference
                { data: 6 }  // Percentage Difference
              ]
            });

            $('#filterBulanF01, #filterTahunF01').on('change', function () {
              if (dtF01) {
                dtF01.ajax.reload(null, true);
              }
            });
          } else {
            dtF01.columns.adjust();
          }
        });


        // Tab Nilai Perolehan / Nilai Akumulasi / Nilai DEP, di-init pas tab-nya pertama kali ditampilkan
        const dtNilai = {};
        ['nilai_perolehan', 'nilai_akumulasi', 'nilai_dep'].forEach(function (nk) {
          $('#tab-' + nk + '-btn').on('shown.bs.tab', function () {
            if (!dtNilai[nk]) {
              dtNilai[nk] = $('#table-' + nk).DataTable({
                responsive: false,
                autoWidth: false,
                scrollX: true,
                scrollCollapse: true,
                paging: true,
                pageLength: 10,
                searching: true,
                ordering: true,
                info: true,
                processing: true,
                language: bahasaIndonesiaDataTable,
                serverSide: true,
                ajax: {
                  url: 'daftar_data_monitoring.php?ajax_action=' + nk,
                  type: 'GET',
                  data: function (d) {
                    d.filter_bulan = $('#filterBulan-' + nk).val();
                    d.filter_tahun = $('#filterTahun-' + nk).val();
                  }
                },
                columns: [
                  { data: 0 },  // Cost Center
                  { data: 1 },  // Asset
                  { data: 2 },  // Asset Subnumber
                  { data: 3 },  // Account
                  { data: 4 },  // Posting Date
                  { data: 5, className: 'text-end', render: formatAngkaRibuanDt },  // Amount (Local Currency)
                  { data: 6 },  // Profit Center
                  { data: 7 },  // Text
                  { data: 8 }   // Document Number
                ]
              });

              $('#filterBulan-' + nk + ', #filterTahun-' + nk).on('change', function () {
                if (dtNilai[nk]) {
                  dtNilai[nk].ajax.reload(null, true);
                }
              });
            } else {
              dtNilai[nk].columns.adjust();
            }
          });
        });

        // Tab Master COA, di-init pas tab-nya pertama kali ditampilkan (tanpa filter periode -- tabel referensi)
        $('#tab-coa-btn').on('shown.bs.tab', function () {
          if (!dtCoa) {
            dtCoa = $('#tableCoa').DataTable({
              responsive: false,
              autoWidth: false,
              scrollX: true,
              scrollCollapse: true,
              paging: true,
              pageLength: 10,
              searching: true,
              ordering: true,
              info: true,
              processing: true,
              language: bahasaIndonesiaDataTable,
              serverSide: true,
              ajax: {
                url: 'daftar_data_monitoring.php?ajax_action=coa',
                type: 'GET'
              },
              columns: [
                { data: 0 }, // Account Determination
                { data: 1 }, // Akun Akumulasi Penyusutan
                { data: 2 }, // CKPN
                { data: 3 }  // Akun Beban Penyusutan
              ]
            });
          } else {
            dtCoa.columns.adjust();
          }
        });


        $('#tab-dat-btn').on('shown.bs.tab', function () {
          if (dtDatAset) {
            dtDatAset.columns.adjust();
          }
        });
      });
    </script>
    <!--end::Script-->
  </body>
</html>