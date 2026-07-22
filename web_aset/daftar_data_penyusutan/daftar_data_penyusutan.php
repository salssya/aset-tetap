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

    if ($action === 'dat') {
        $table = 'import_dat_penyusutan';
        $kolomDb = ['profit_center', 'cabang', 'periode_bulan', 'tahun_buku', 'nomor_asset', 'sub_number', 'keterangan_asset', 'tgl_perolehan', 'sisa_manfaat_aset', 'gl_account_exp'];
    } elseif ($action === 'penyusutan') {
        $table = 'import_penyusutan';
        $kolomDb = ['cost_center', 'asset', 'asset_subnumber', 'account', 'posting_date', 'amount_local_currency', 'profit_center', 'text'];
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

    // Filter Bulan & Tahun Buku (khusus tabel DAT yang punya kolom ini)
    if ($action === 'dat') {
        $filterBulan = isset($_GET['periode_bulan']) ? trim($_GET['periode_bulan']) : '';
        $filterTahun = isset($_GET['tahun_buku']) ? trim($_GET['tahun_buku']) : '';
        if ($filterBulan !== '' && ctype_digit($filterBulan)) {
            $whereParts[] = "`periode_bulan` = '" . mysqli_real_escape_string($con, $filterBulan) . "'";
        }
        if ($filterTahun !== '' && ctype_digit($filterTahun)) {
            $whereParts[] = "`tahun_buku` = '" . mysqli_real_escape_string($con, $filterTahun) . "'";
        }
    }

    $whereSql = !empty($whereParts) ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

    // Total setelah difilter pencarian
    $resFiltered = mysqli_query($con, "SELECT COUNT(*) AS c FROM `$table` $whereSql");
    $recordsFiltered = $resFiltered ? (int)mysqli_fetch_assoc($resFiltered)['c'] : 0;

    // Ambil data sesuai halaman yang diminta
    $sql = "SELECT " . implode(', ', array_map(fn($c) => "`$c`", $kolomDb)) . "
            FROM `$table`
            $whereSql
            ORDER BY $orderBy
            LIMIT $start, $length";
    $res = mysqli_query($con, $sql);

    $data = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rowOut = [];
            foreach ($kolomDb as $col) {
                $rowOut[] = htmlspecialchars($row[$col] ?? '', ENT_QUOTES, 'UTF-8');
            }
            $data[] = $rowOut;
        }
    }

    echo json_encode(['draw' => $draw, 'recordsTotal' => $recordsTotal, 'recordsFiltered' => $recordsFiltered, 'data' => $data]);
    exit();
}
?>
<!doctype html>
<html lang="en">
  <!--begin::Head-->
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Daftar Data Penyusutan - Web Aset Tetap</title>
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
      #tableDatPenyusutan thead th,
      #tableDatPenyusutan tbody td,
      #tablePenyusutan thead th,
      #tablePenyusutan tbody td {
        padding: 8px 12px;
        white-space: nowrap;
        min-width: 130px;
      }
      #tableDatPenyusutan thead th,
      #tablePenyusutan thead th {
        background-color: #f8f9fa;
        font-weight: 600;
        border-bottom: 2px solid #dee2e6;
      }
      #tableDatPenyusutan tbody td,
      #tablePenyusutan tbody td {
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
                'Dasboard'                       => 'bi bi-grid-fill',
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
                'Import Data Penyusutan'          => 'bi bi-cloud-upload',
                'Daftar Data Penyusutan'          => 'bi bi-table',
                'Selisih Penyusutan'              => 'bi bi-bar-chart-line',
                'Daftar Aset Tetap'               => 'bi bi-boxes',
                'Manajemen User'                  => 'bi bi-people',
            ];

            // ── Pengelompokan menu jadi 3 grup dropdown: Penghapusan, Penyusutan, Manajemen Admin ──
            // (menu di luar mapping ini, misal "Dasboard", dirender sebagai item biasa di luar grup)
            $groupMap = [
                'Usulan Penghapusan'             => 'Penghapusan',
                'Daftar Usulan Penghapusan'      => 'Penghapusan',
                'Approval SubReg'                => 'Penghapusan',
                'Approval Regional'              => 'Penghapusan',
                'Persetujuan Penghapusan'        => 'Penghapusan',
                'Daftar Persetujuan Penghapusan' => 'Penghapusan',
                'Pelaksanaan Penghapusan'        => 'Penghapusan',
                'Daftar Aset Tetap'              => 'Penghapusan',
                'Daftar Pelaksanaan Penghapusan' => 'Penghapusan',

                'Import Data Penyusutan'         => 'Penyusutan',
                'Daftar Data Penyusutan'         => 'Penyusutan',
                'Selisih Penyusutan'             => 'Penyusutan',

                'Import DAT'                     => 'Manajemen Admin',
                'Manajemen Menu'                 => 'Manajemen Admin',
                'Manajemen User'                 => 'Manajemen Admin',
            ];
            $groupIcon = [
                'Penghapusan'      => 'bi bi-file-earmark-minus',
                'Penyusutan'       => 'bi bi-graph-down-arrow',
                'Manajemen Admin'  => 'bi bi-sliders',
            ];
            $groupOrder = ['Penghapusan', 'Penyusutan', 'Manajemen Admin'];

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
              <div class="col-sm-6"><h3 class="mb-0">Daftar Data Penyusutan</h3></div>
              <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                  <li class="breadcrumb-item"><a href="../dasbor/dasbor.php">Home</a></li>
                  <li class="breadcrumb-item active">Daftar Data Penyusutan</li>
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
                      <button class="nav-link" id="tab-penyusutan-btn" data-bs-toggle="tab" data-bs-target="#tab-penyusutan" type="button" role="tab" aria-controls="tab-penyusutan" aria-selected="false">
                        <i class="bi bi-graph-down-arrow"></i> Data SAP Penyusutan
                      </button>
                    </li>
                  </ul>
                </div>
                <div class="card-body">
                  <div class="tab-content" id="hasilTabContent">

                    <!--begin::Tab Hasil DAT-->
                    <div class="tab-pane fade show active" id="tab-dat" role="tabpanel" aria-labelledby="tab-dat-btn">
                      <?php
                      $latestDatQuery = "SELECT COUNT(*) as total_records, MAX(created_at) as last_import FROM import_dat_penyusutan";
                      $latestDatResult = mysqli_query($con, $latestDatQuery);
                      $latestDatRow = $latestDatResult ? mysqli_fetch_assoc($latestDatResult) : null;
                      $totalDatRecords = isset($latestDatRow['total_records']) ? (int)$latestDatRow['total_records'] : 0;
                      ?>
                      <?php if (!isset($latestDatResult) || !$latestDatResult || $totalDatRecords === 0): ?>
                      <div class="alert alert-info d-flex align-items-center gap-2">
                        <i class="bi bi-info-circle fs-5"></i>
                        <div>
                          <strong>Belum ada data yang diupload ke database.</strong><br>
                          Silakan upload data DAT terlebih dahulu di menu
                          <a href="../import_penyusutan/import_penyusutan.php">Import DAT</a>.
                        </div>
                      </div>
                      <?php endif; ?>
                      <?php
                      // Nama bulan untuk ditampilkan di dropdown (angka periode_bulan diasumsikan 1-12)
                      $namaBulanMap = [
                        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
                      ];
                      // Ambil daftar bulan & tahun buku yang BENAR-BENAR ada di data (biar dropdown tidak nampilin pilihan kosong)
                      $daftarBulanDat = [];
                      $daftarTahunDat = [];
                      $cekTabelDat = mysqli_query($con, "SHOW TABLES LIKE 'import_dat_penyusutan'");
                      if ($cekTabelDat && mysqli_num_rows($cekTabelDat) > 0) {
                          $resBulanDat = mysqli_query($con, "SELECT DISTINCT periode_bulan FROM import_dat_penyusutan WHERE periode_bulan IS NOT NULL AND periode_bulan <> '' ORDER BY periode_bulan ASC");
                          if ($resBulanDat) { while ($r = mysqli_fetch_assoc($resBulanDat)) { $daftarBulanDat[] = $r['periode_bulan']; } }
                          $resTahunDat = mysqli_query($con, "SELECT DISTINCT tahun_buku FROM import_dat_penyusutan WHERE tahun_buku IS NOT NULL AND tahun_buku <> '' ORDER BY tahun_buku DESC");
                          if ($resTahunDat) { while ($r = mysqli_fetch_assoc($resTahunDat)) { $daftarTahunDat[] = $r['tahun_buku']; } }
                      }
                      ?>
                      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                          <label for="filterBulanDat" class="mb-0 fw-semibold">Bulan:</label>
                          <select id="filterBulanDat" class="form-select form-select-sm" style="width:auto;">
                            <option value="">Semua Bulan</option>
                            <?php foreach ($daftarBulanDat as $b): $bInt = (int)$b; ?>
                              <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($namaBulanMap[$bInt] ?? $b) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <label for="filterTahunDat" class="mb-0 fw-semibold">Tahun Buku:</label>
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
                        <table id="tableDatPenyusutan" class="display nowrap table table-striped" style="width:100%; min-width: 900px;">
                          <thead>
                            <tr>
                              <th>Profit Center</th>
                              <th>Cabang</th>
                              <th>Periode/Bulan</th>
                              <th>Tahun Buku</th>
                              <th>Nomor Aset</th>
                              <th>Sub-number</th>
                              <th>Keterangan Aset</th>
                              <th>Tgl. Perolehan</th>
                              <th>Sisa Manfaat</th>
                              <th>GL Account EXP. Depre.</th>
                            </tr>
                          </thead>
                          <tbody>
                          <!-- Data diisi lewat AJAX oleh DataTables-->
                          </tbody>
                        </table>
                      </div>
                    </div>
                    <!--end::Tab Hasil DAT-->

                    <!--begin::Tab Hasil Data Penyusutan-->
                    <div class="tab-pane fade" id="tab-penyusutan" role="tabpanel" aria-labelledby="tab-penyusutan-btn">
                      <?php
                      $latestPenyusutanQuery = "SELECT COUNT(*) as total_records, MAX(created_at) as last_import FROM import_penyusutan";
                      $latestPenyusutanResult = mysqli_query($con, $latestPenyusutanQuery);
                      $latestPenyusutanRow = $latestPenyusutanResult ? mysqli_fetch_assoc($latestPenyusutanResult) : null;
                      $totalPenyusutanRecords = isset($latestPenyusutanRow['total_records']) ? (int)$latestPenyusutanRow['total_records'] : 0;
                      ?>
                      <?php if (!isset($latestPenyusutanResult) || !$latestPenyusutanResult || $totalPenyusutanRecords === 0): ?>
                      <div class="alert alert-info d-flex align-items-center gap-2">
                        <i class="bi bi-info-circle fs-5"></i>
                        <div>
                          <strong>Belum ada data yang diupload ke database.</strong><br>
                          Silakan upload data Penyusutan terlebih dahulu di menu
                          <a href="../import_penyusutan/import_penyusutan.php">Import Data SAP Penyusutan</a>.
                        </div>
                      </div>
                      <?php endif; ?>
                      <div class="d-flex justify-content-end mb-2">
                        <?php
                        $lastPenyusutanDate = $latestPenyusutanRow['last_import'] ?? null;
                        if ($lastPenyusutanDate) {
                          $dt2 = new DateTime($lastPenyusutanDate);
                          echo '<span style="color:#dc3545;font-weight:bold;">Diimport: ' . htmlspecialchars($dt2->format('d M Y H:i:s')) . '</span>';
                        }
                        ?>
                      </div>
                      <div class="table-responsive">
                        <table id="tablePenyusutan" class="display nowrap table table-striped" style="width:100%;">
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
                            </tr>
                          </thead>
                          <tbody>
                          <!-- Data diisi lewat AJAX oleh DataTables -->
                          </tbody>
                          </table>
                      </div>
                    </div>
                    <!--end::Tab Hasil Data Penyusutan-->

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

      let dtDatPenyusutan = null;
      let dtPenyusutan = null;

      $(document).ready(function () {
        dtDatPenyusutan = $('#tableDatPenyusutan').DataTable({
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
            url: 'daftar_data_penyusutan.php?ajax_action=dat',
            type: 'GET',
            data: function (d) {
              d.periode_bulan = $('#filterBulanDat').val();
              d.tahun_buku = $('#filterTahunDat').val();
            }
          },
          columns: [
            { data: 0 }, // Profit Center
            { data: 1 }, // Cabang
            { data: 2 }, // Periode/Bulan
            { data: 3 }, // Tahun Buku
            { data: 4 }, // Nomor Aset
            { data: 5 }, // Sub-number
            { data: 6 }, // Keterangan Aset
            { data: 7 }, // Tgl. Perolehan
            { data: 8 }, // Sisa Manfaat
            { data: 9 }  // GL Account EXP. Depre.
          ]
        });

        // Saat dropdown Bulan / Tahun Buku diganti, muat ulang tabel dari halaman pertama
        $('#filterBulanDat, #filterTahunDat').on('change', function () {
          if (dtDatPenyusutan) {
            dtDatPenyusutan.ajax.reload(null, true); // true = reset ke halaman pertama
          }
        });

        $('#tab-penyusutan-btn').on('shown.bs.tab', function () {
          if (!dtPenyusutan) {
            dtPenyusutan = $('#tablePenyusutan').DataTable({
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
                url: 'daftar_data_penyusutan.php?ajax_action=penyusutan',
                type: 'GET'
              },
              columns: [
                { data: 0 }, // Cost Center
                { data: 1 }, // Asset
                { data: 2 }, // Asset Subnumber
                { data: 3 }, // Account
                { data: 4 }, // Posting Date
                { data: 5 }, // Amount in Local Currency
                { data: 6 }, // Profit Center
                { data: 7 }  // Text
              ]
            });
          } else {
            dtPenyusutan.columns.adjust();
          }
        });

        $('#tab-dat-btn').on('shown.bs.tab', function () {
          if (dtDatPenyusutan) {
            dtDatPenyusutan.columns.adjust();
          }
        });
      });
    </script>
    <!--end::Script-->
  </body>
</html>