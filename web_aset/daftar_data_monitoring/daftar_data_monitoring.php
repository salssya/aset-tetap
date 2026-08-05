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

if (isset($_GET['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax_action'];

    try {

    // Konfigurasi per tab: tabel sumber, kolom yang ditampilkan (urutan = urutan kolom di tabel HTML),
    // dan nama kolom periode (dipakai buat filter Bulan/Tahun).
    if ($action === 'dat') {
        $table = 'import_dat_monitoring';
        $kolomDb = ['profit_center', 'cabang', 'tahun_buku', 'nomor_asset', 'sub_number', 'keterangan_asset', 'tgl_perolehan', 'tgl_mulai_penyusutan', 'nilai_perolehan_sd_tahun_berjalan', 'akumulasi_penyusutan', 'gl_account_exp'];
        $kolomBulan = null; // tabel ini gak ada dimensi periode_bulan, cuma per tahun_buku
        $kolomTahun = 'tahun_buku';
    } elseif ($action === 'ar02') {
        $table = 'import_ar02_reg3';
        $kolomDb = ['bal_sh_acct_APC', 'nomor_asset', 'sub_number', 'asset_class', 'keterangan_asset', 'cabang', 'profit_center', 'acquisition', 'retirement', 'transfers', 'gl_akumulasi_penyusutan', 'ckpn', 'gl_beban_penyusutan'];
        $kolomBulan = 'periode_bulan';
        $kolomTahun = 'periode_tahun';
    } elseif ($action === 'fagll') {
        // "Import Rekap FAGLL" nyimpen datanya ke tabel import_fagll (dedicated, terpisah dari
        // import_penyusutan). Tabel ini gak punya kolom periode_bulan/periode_tahun langsung,
        // jadi filter periode diturunkan dari posting_date_norm pakai MONTH()/YEAR().
        $table = 'import_fagll';
        $kolomDb = ['cost_center', 'asset', 'asset_subnumber', 'account', 'posting_date', 'amount_local_currency', 'profit_center', 'cabang', 'text', 'document_number'];
        $kolomBulan = 'MONTH(posting_date_norm)'; // ekspresi SQL, bukan nama kolom polos
        $kolomTahun = 'YEAR(posting_date_norm)';  // ekspresi SQL, bukan nama kolom polos
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
    $orderBy = 'id DESC'; // default: data terbaru dulu
    if (isset($_GET['order'][0]['column'])) {
        $colIdx = (int)$_GET['order'][0]['column'];
        $dir = (isset($_GET['order'][0]['dir']) && strtolower($_GET['order'][0]['dir']) === 'asc') ? 'ASC' : 'DESC';
        if (isset($kolomDb[$colIdx])) {
            $orderBy = '`' . $kolomDb[$colIdx] . '` ' . $dir;
        }
    }

    // Total keseluruhan (tanpa filter pencarian)
    $resTotal = mysqli_query($con, "SELECT COUNT(*) AS c FROM `$table`");
    $recordsTotal = $resTotal ? (int)mysqli_fetch_assoc($resTotal)['c'] : 0;

    // WHERE untuk pencarian global (search semua kolom)
    $whereParts = [];
    if ($searchValue !== '') {
        $searchEsc = mysqli_real_escape_string($con, $searchValue);
        $likeParts = [];
        foreach ($kolomDb as $col) {
            $likeParts[] = "`$col` LIKE '%$searchEsc%'";
        }
        $whereParts[] = '(' . implode(' OR ', $likeParts) . ')';
    }

    // Filter Bulan & Tahun (semua tab punya kolom periode, cuma beda nama kolom tahunnya)
    $filterBulan = isset($_GET['filter_bulan']) ? trim($_GET['filter_bulan']) : '';
    $filterTahun = isset($_GET['filter_tahun']) ? trim($_GET['filter_tahun']) : '';
    if ($kolomBulan !== null && $filterBulan !== '' && ctype_digit($filterBulan)) {
        $whereParts[] = "$kolomBulan = '" . mysqli_real_escape_string($con, $filterBulan) . "'";
    }
    if ($filterTahun !== '' && ctype_digit($filterTahun)) {
        $whereParts[] = "$kolomTahun = '" . mysqli_real_escape_string($con, $filterTahun) . "'";
    }

    $whereSql = !empty($whereParts) ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

    // Total setelah difilter
    $resFiltered = mysqli_query($con, "SELECT COUNT(*) AS c FROM `$table` $whereSql");
    $recordsFiltered = $resFiltered ? (int)mysqli_fetch_assoc($resFiltered)['c'] : 0;

    // Ambil data sesuai halaman yang diminta
    $sql = "SELECT " . implode(', ', array_map(fn($c) => "`$c`", $kolomDb)) . "
            FROM `$table`
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
            'error' => 'Query gagal di tabel `' . $table . '`: ' . mysqli_error($con) . '. Kemungkinan struktur tabel di database belum sesuai dengan kolom yang diminta kode ini (' . implode(', ', $kolomDb) . '). Cek dengan DESCRIBE ' . $table . ' di database.'
        ]);
        exit();
    }

    $data = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $rowOut = [];
        foreach ($kolomDb as $col) {
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
      #tableFagll tbody td {
        padding: 8px 12px;
        white-space: nowrap;
        min-width: 130px;
      }
      #tableDatAset thead th,
      #tableAr02 thead th,
      #tableFagll thead th {
        background-color: #f8f9fa;
        font-weight: 600;
        border-bottom: 2px solid #dee2e6;
      }
      #tableDatAset tbody td,
      #tableAr02 tbody td,
      #tableFagll tbody td {
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

                // Aset Tetap (rekonsiliasi DAT + AR02 reg3 + REKAP FAGLL)
                'Import Data Aset Tetap'          => 'bi bi-upload',
                'Daftar Data Aset Tetap'          => 'bi bi-table',
                'Dasbor Rekonsiliasi Aset Tetap'  => 'bi bi-clipboard-data',

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

                'Import Data Aset Tetap'          => 'Aset Tetap',
                'Daftar Data Aset Tetap'          => 'Aset Tetap',
                'Dasbor Rekonsiliasi Aset Tetap'  => 'Aset Tetap',

                'Import DAT'                      => 'Manajemen Admin',
                'Manajemen Menu'                  => 'Manajemen Admin',
                'Manajemen User'                  => 'Manajemen Admin',
            ];
            $groupIcon = [
                'Penghapusan'                     => 'bi bi-file-earmark-minus',
                'Penyusutan'                      => 'bi bi-graph-down-arrow',
                'Monitoring SAP-DAT'              => 'bi bi-arrow-left-right',
                'Aset Tetap'                      => 'bi bi-clipboard-data',
                'Manajemen Admin'                 => 'bi bi-sliders',               
            ];
            $groupOrder = ['Penghapusan', 'Penyusutan', 'Monitoring SAP-DAT', 'Aset Tetap', 'Manajemen Admin'];

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
                        <i class="bi bi-file-earmark-arrow-up"></i> File DAT 
                      </button>
                    </li>
                    <li class="nav-item" role="presentation">
                      <button class="nav-link" id="tab-ar02-btn" data-bs-toggle="tab" data-bs-target="#tab-ar02" type="button" role="tab" aria-controls="tab-ar02" aria-selected="false">
                        <i class="bi bi-arrow-left-right"></i> AR02 reg3
                      </button>
                    </li>
                    <li class="nav-item" role="presentation">
                      <button class="nav-link" id="tab-fagll-btn" data-bs-toggle="tab" data-bs-target="#tab-fagll" type="button" role="tab" aria-controls="tab-fagll" aria-selected="false">
                        <i class="bi bi-journal-check"></i> REKAP FAGLL
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

      $(document).ready(function () {
        // Format angka jadi ribuan pakai titik (mis. 3351791000 -> "3.351.791.000"), biar jelas
        // itu nilai rupiah bukan cuma angka polos. Dipakai di kolom Nilai Perolehan & Akumulasi
        // Penyusutan. Aman untuk angka negatif (mis. -3284755180 -> "-3.284.755.180") dan untuk
        // nilai kosong/bukan angka (dibiarkan apa adanya, mis. "-" atau "").
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
            { data: 5 }, // Keterangan Aset
            { data: 6 }, // Tgl. Perolehan
            { data: 7 }, // Tgl. Mulai Penyusutan
            { data: 8, className: 'text-end', render: formatAngkaRibuanDt }, // Nilai Perolehan s.d Tahun Berjalan
            { data: 9, className: 'text-end', render: formatAngkaRibuanDt }, // Akumulasi Penyusutan
            { data: 10 } // GL Account EXP. Depre.
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