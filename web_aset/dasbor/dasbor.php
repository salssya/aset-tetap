<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "asetreg3_db";

session_start();
if(!isset($_SESSION["nipp"]) || !isset($_SESSION["name"])) {
    header("Location: ../login/login_view.php");
    exit();
}

// Create connection
$con = mysqli_connect($servername, $username, $password, $dbname);
?>
<!doctype html>
<html lang="en">
  <!--begin::Head-->
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Web Aset Tetap</title>
    <!-- Favicon -->
    
    <!-- emblem pelindo -->
    <link rel="icon" type="image/png" href="../../dist/assets/img/emblem.png" /> 
    <link rel="shortcut icon" type="image/png" href="../../dist/assets/img/emblem.png" />  
    <!--begin::Accessibility Meta Tags-->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes" />
    <meta name="color-scheme" content="light dark" />
    <meta name="theme-color" content="#007bff" media="(prefers-color-scheme: light)" />
    <meta name="theme-color" content="#1a1a1a" media="(prefers-color-scheme: dark)" />
    <!--end::Accessibility Meta Tags-->
    <!--begin::Primary Meta Tags-->
    <meta charset="utf-8" />
    <meta
      name="description"
      content="Aset Tetap Dashboard Application for PT Pelabuhan Indonesia (Persero) Regional 3"
    />
    <!--end::Primary Meta Tags-->
    <!--begin::Accessibility Features-->
    <!-- Skip links will be dynamically added by accessibility.js -->
    <meta name="supported-color-schemes" content="light dark" />
    <link rel="preload" href="../../dist/css/adminlte.css" as="style" />
    <!--end::Accessibility Features-->
    <!--begin::Fonts-->
    <link
      rel="stylesheet"
      href="../../dist/css/index.css"/>
    <!--end::Fonts-->
    <!--begin::Third Party Plugin(OverlayScrollbars)-->
    <link
      rel="stylesheet"
      href="../../dist/css/overlayscrollbars.min.css"/>
    <!--end::Third Party Plugin(OverlayScrollbars)-->
    <!--begin::Third Party Plugin(Bootstrap Icons)-->
    <link
      rel="stylesheet"
      href="../../dist/css/bootstrap-icons/bootstrap-icons.min.css"/>
    <!--end::Third Party Plugin(Bootstrap Icons)-->
    <!--begin::Required Plugin(AdminLTE)-->
    <link rel="stylesheet" href="../../dist/css/adminlte.css"/>
    <!--end::Required Plugin(AdminLTE)-->
    <!-- apexcharts -->
    <link
      rel="stylesheet"
      href="../../dist/css/apexcharts.css"/> 
    <style> 
     .app-sidebar {
        background-color: #0b3a8c !important;
      }
      /* Remove header border/shadow and brand bottom line */
      .app-header, nav.app-header, .app-header.navbar {
        border-bottom: 0 !important;
        box-shadow: none !important;
      }
      /* Ensure the sidebar-brand area fills with the same blue and has no divider */
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
      /* Make sure the logo image doesn't leave a visual gap */
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
    </style>

  </head>
  <body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <!--begin::App Wrapper-->
    <div class="app-wrapper">
      <!--begin::Header-->
      <nav class="app-header navbar navbar-expand bg-white border-0 shadow-none" style="border-bottom:0!important;box-shadow:none!important;">
        <!--begin::Container-->
        <div class="container-fluid">
          <!--begin::Start Navbar Links-->
          <!--end::Start Navbar Links-->
          <!--begin::End Navbar Links-->
          <ul class="navbar-nav ms-auto">
            <!--begin::Navbar Search-->
            <!--end::Navbar Search-->
            <!--begin::Messages Dropdown Menu-->
            <!--end::Notifications Dropdown Menu-->
            <!--begin::Fullscreen Toggle-->
            <!--end::Fullscreen Toggle-->
            <!--begin::User Menu Dropdown-->
            <li class="nav-item dropdown user-menu">
              <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                <img src="../../dist/assets/img/profile.png" 
                    class="user-image rounded-circle shadow" alt="User Image"/>
                <span class="d-none d-md-inline">
                  <?php echo htmlspecialchars($_SESSION['name']); ?>
                </span>
              </a>
              <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                <!-- User Header -->
                <li class="user-header text-bg-primary text-center">
                  <img src="../../dist/assets/img/profile.png" 
                      class="rounded-circle shadow mb-2" alt="User Image" style="width:80px;height:80px;">
                  <p class="mb-0 fw-bold"><?php echo htmlspecialchars($_SESSION['name']); ?></p>
                  <small>NIPP: <?php echo htmlspecialchars($_SESSION['nipp']); ?></small>
                </li>

                <!-- User Info -->
                <li class="user-menu-body">
                  <div class="row ps-3 pe-3 pt-2 pb-2 user-info">
                    <div class="col-6 text-start">
                      <small class="text-muted">Type User:</small><br>
                      <span class="badge bg-primary small">
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
                  <!-- Footer -->
                  <li class="user-footer d-flex align-items-center px-3 py-2">
                    <a href="../profile/profile.php" class="btn btn-sm btn-outline-primary">
                      <i class="bi bi-person"></i> Profile
                    </a>
                    <a href="../login/login_view.php" class="btn btn-sm btn-danger ms-auto">
                      <i class="bi bi-box-arrow-right"></i> Logout
                    </a>
                  </li>
                </ul>
            <!--end::User Menu Dropdown-->
          </ul>
          <!--end::End Navbar Links-->
        </div>
        <!--end::Container-->
      </nav>
      <!--end::Header-->
      <!--begin::Sidebar-->
      <aside class="app-sidebar">
        <!--begin::Sidebar Brand-->
        <div class="sidebar-brand">
          <!--begin::Brand Link-->
          <a href="../dasbor/dasbor.php" class="brand-link">
            <!--begin::Brand Image-->
            <img
              src="../../dist/assets/img/logo.png"
              class="brand-image"
              alt="Logo Pelindo"
              title="PT Pelabuhan Indonesia"
            />
            <!--end::Brand Image-->
          </a>
          <!--end::Brand Link-->
        </div>
        <!--end::Sidebar Brand-->
        <!--begin::Sidebar Wrapper-->
        <div class="sidebar-wrapper" >
          <nav class="mt-2">
            <!--begin::Sidebar Menu-->
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
                'Dasboard'                  => 'bi bi-grid-fill',
                'Usulan Penghapusan'        => 'bi bi-clipboard-plus',
                'Daftar Usulan Penghapusan' => 'bi bi-clipboard-check-fill',
                'Approval SubReg'           => 'bi bi-check-circle',
                'Approval Regional'         => 'bi bi-check2-square',
                'Persetujuan Penghapusan'   => 'bi bi-clipboard-check-fill',
                'Pelaksanaan Penghapusan'   => 'bi bi-tools',
                'Manajemen Menu'            => 'bi bi-list-ul',
                'Import DAT'                => 'bi bi-file-earmark-arrow-up-fill',
                'Daftar Aset Tetap'         => 'bi bi-card-list',
                'Manajemen User'            => 'bi bi-people-fill'
            ];
            
            $menuRows = [];
            while ($row = mysqli_fetch_assoc($result_menu)) {
                $menuRows[] = $row;
            }
            
            $hasDaftarUsulan = false;
            $daftarRow = null;
            foreach ($menuRows as $row) {
                if (trim($row['nama_menu']) === 'Daftar Usulan Penghapusan') {
                    $hasDaftarUsulan = true;
                    $daftarRow = $row;
                    break;
                }
            }
            
            $currentPage = basename($_SERVER['PHP_SELF']);
            
            foreach ($menuRows as $row) {
                $namaMenu = trim($row['nama_menu']);
                
                if ($namaMenu === 'Daftar Usulan Penghapusan') {
                    continue;
                }
                
                $icon = $iconMap[$namaMenu] ?? 'bi bi-circle';
                $menuFile = $row['menu'].'.php';
                $isActive = ($currentPage === $menuFile) ? 'active' : '';

                if ($namaMenu === 'Manajemen Menu') {
                    echo '<li class="nav-header"></li>';
                }
                
                echo '
                <li class="nav-item">
                    <a href="../'.$row['menu'].'/'.$row['menu'].'.php" class="nav-link '.$isActive.'">
                        <i class="nav-icon '.$icon.'"></i>
                        <p>'.$row['nama_menu'].'</p>
                    </a>
                </li>';
                
                if ($namaMenu === 'Usulan Penghapusan' && $hasDaftarUsulan && $daftarRow) {
                    $daftarIcon = $iconMap['Daftar Usulan Penghapusan'] ?? 'bi bi-circle';
                    $daftarFile = $daftarRow['menu'].'.php';
                    $isDaftarActive = ($currentPage === $daftarFile) ? 'active' : '';
                    
                    echo '
                <li class="nav-item">
                    <a href="../'.$daftarRow['menu'].'/'.$daftarRow['menu'].'.php" class="nav-link '.$isDaftarActive.'">
                        <i class="nav-icon '.$daftarIcon.'"></i>
                        <p>Daftar Usulan Penghapusan</p>
                    </a>
                </li>';
                }
            }
            ?>

            </ul>
            <!--end::Sidebar Menu-->
          </nav>
        </div>
        <!--end::Sidebar Wrapper-->
      </aside>
      <!--end::Sidebar-->
      <!--begin::App Main-->
      <main class="app-main">
        <!--begin::App Content Header-->
        <div class="app-content-header">
          <!--begin::Container-->
          <div class="container-fluid">
            <!--begin::Row-->
            <div class="row">
              <div class="col-sm-6"><h3 class="mb-0">Dashboard</h3></div>
            </div>
            <!--end::Row-->
          </div>
          <!--end::Container-->
        </div>
        <div class="app-content">
          <!--begin::Container-->
          <div class="container-fluid">
            <!-- Info boxes -->
            <div class="row">
              <?php

              // Filter criteria
              $filterConditionBase = "WHERE nilai_perolehan_sd <> 0 AND asset_class_name NOT LIKE '%AUC%'";
              $filterCondition = $filterConditionBase;

              if (isset($_SESSION['Type_User']) && stripos($_SESSION['Type_User'], 'Sub') !== false) {
                  $userCabang = mysqli_real_escape_string($con, $_SESSION['Cabang'] ?? '');
                  $determinedSubreg = '';

                  if ($userCabang !== '') {
                      $stmt = mysqli_prepare($con, "SELECT DISTINCT subreg FROM import_dat WHERE profit_center = ? AND TRIM(subreg) <> '' LIMIT 1");
                      if ($stmt) {
                          mysqli_stmt_bind_param($stmt, 's', $userCabang);
                          mysqli_stmt_execute($stmt);
                          $res = mysqli_stmt_get_result($stmt);
                          if ($r = mysqli_fetch_assoc($res)) {
                              $determinedSubreg = $r['subreg'];
                          }
                          mysqli_stmt_close($stmt);
                      }
                  }

                  // Fallback: if session contains a subreg value use it
                  if (empty($determinedSubreg) && !empty($_SESSION['subreg'])) {
                      $determinedSubreg = $_SESSION['subreg'];
                  }

                  // Apply filter if we were able to determine a subreg
                  if (!empty($determinedSubreg)) {
                      $filterCondition .= " AND subreg = '" . mysqli_real_escape_string($con, $determinedSubreg) . "'";
                  }
              } elseif (isset($_SESSION['Type_User']) && stripos($_SESSION['Type_User'], 'Cabang') !== false) {
                  $userCabang = mysqli_real_escape_string($con, $_SESSION['Cabang'] ?? '');
                  if ($userCabang !== '') {
                      $filterCondition .= " AND profit_center = '" . $userCabang . "'";
                  }
              }

              $totalQuery = "SELECT COUNT(*) as total FROM import_dat " . $filterCondition;
              $totalResult = mysqli_query($con, $totalQuery);
              if (!$totalResult) {
                  echo "<!-- Query Error: " . mysqli_error($con) . " -->";
                  $totalAssets = 0;
              } else {
                  $totalRow = mysqli_fetch_assoc($totalResult);
                  $totalAssets = $totalRow['total'] ?? 0;
              }
              
              // Query Nilai Perolehan - CAST to DECIMAL for accurate sum
              $perolehanQuery = "SELECT CAST(SUM(CAST(COALESCE(nilai_perolehan_sd, 0) AS DECIMAL(20,2))) AS DECIMAL(20,2)) as perolehan FROM import_dat " . $filterCondition;
              $perolehanResult = mysqli_query($con, $perolehanQuery);
              $perolehanCount = 0;
              
              if ($perolehanResult) {
                  $perolehanRow = mysqli_fetch_assoc($perolehanResult);
                  $perolehanCount = round((float)($perolehanRow['perolehan'] ?? 0), 0);
              } else {
                  echo "<!-- Perolehan Query Error: " . mysqli_error($con) . " -->";
              }
              
              // Query Nilai Buku - CAST to DECIMAL for accurate sum
              $nilai_bukuQuery = "SELECT CAST(SUM(CAST(COALESCE(nilai_buku_sd, 0) AS DECIMAL(20,2))) AS DECIMAL(20,2)) as nilai_buku FROM import_dat " . $filterCondition;
              $nilai_bukuResult = mysqli_query($con, $nilai_bukuQuery);
              $nilai_bukuCount = 0;
              
              if ($nilai_bukuResult) {
                  $nilai_bukuRow = mysqli_fetch_assoc($nilai_bukuResult);
                  $nilai_bukuCount = round((float)($nilai_bukuRow['nilai_buku'] ?? 0), 0);
              } else {
                  echo "<!-- Nilai Buku Query Error: " . mysqli_error($con) . " -->";
              }
              
              // Query Akumulasi Penyusutan - CAST to DECIMAL for accurate sum
              // Use COALESCE to handle NULL values and ensure proper conversion
              $penyusutanQuery = "SELECT CAST(SUM(CAST(COALESCE(akumulasi_penyusutan, 0) AS DECIMAL(20,2))) AS DECIMAL(20,2)) as penyusutan FROM import_dat " . $filterCondition;
              $penyusutanResult = mysqli_query($con, $penyusutanQuery);
              $penyusutanCount = 0;
              
              if ($penyusutanResult) {
                  $penyusutanRow = mysqli_fetch_assoc($penyusutanResult);
                  // Convert to proper integer for display
                  $penyusutanCount = round((float)($penyusutanRow['penyusutan'] ?? 0), 0);
              } else {
                  echo "<!-- Penyusutan Query Error: " . mysqli_error($con) . " -->";
              }
              
              // Function to format currency
              function formatCurrency($value) {
                  if ($value === null || $value === '' || $value == 0) {
                      return 'Rp 0';
                  }
                  // Ensure value is numeric and round to nearest integer
                  $value = round((float)$value, 0);
                  if ($value >= 1000000000) {
                      return 'Rp ' . number_format($value, 0, ',', '.');
                  }
                  return 'Rp ' . number_format($value, 0, ',', '.');
              }
              ?>
              <style>
                .stat-card { background: #ffffff; border-radius: 12px; padding: 18px 22px 18px 18px; box-shadow: 0 8px 24px rgba(11,93,183,0.06); display:flex; align-items:center; gap:14px; border:1px solid rgba(0,0,0,0.04); position:relative; overflow:visible; min-height:88px; }
                .stat-card .accent { position:absolute; left:0; top:0; bottom:0; width:8px; border-radius:12px 0 0 12px; }
                .stat-card .stat-body { display:flex; flex-direction:column; gap:4px; z-index:2; padding-left:18px; }
                .stat-text { display:block; color:#4b5563; font-size:0.70rem; text-transform:uppercase; letter-spacing:0.08em; font-weight:700; }
                .stat-number { font-weight:900; font-size:2.15rem; color:#09203f; line-height:1; }
                .stat-small { display:block; font-size:0.98rem; color:#2b3a48; font-weight:700; margin-top:2px; }
                .stat-bg-icon { position:absolute; right:14px; top:12px; font-size:40px; color: rgba(9,32,63,0.12); z-index:1; }
                .accent-blue{ background: #0d6efd; }
                .accent-green{ background: #20c997; }
                .accent-gray{ background: #0dcaf0; }
                .accent-yellow{ background: #ffc107; }
                @media (max-width:768px){ .stat-bg-icon{ display:none; } }
              </style>

              <style>
                /* ApexCharts legend: align left and wrap neatly */
                .apexcharts-legend {
                  justify-content: flex-start !important;
                  padding-left: 6px !important;
                  flex-wrap: wrap !important;
                }
                .apexcharts-legend .apexcharts-legend-series {
                  margin-right: 10px !important;
                  white-space: nowrap;
                }
                /* make background icons lighter and consistent */
                .stat-bg-icon { opacity: 0.14; }
              </style>

              <div class="col-xl-3 col-md-6 mb-4">
                <div class="stat-card">
                  <div class="accent accent-blue"></div>
                  <div class="stat-body">
                    <span class="stat-text">Total Aset</span>
                    <span class="stat-number"><?php echo $totalAssets; ?></span>
                  </div>
                  <i class="bi bi-clipboard-fill stat-bg-icon"></i>
                </div>
              </div>

              <div class="col-12 col-sm-6 col-md-3 mb-4">
                <div class="stat-card">
                  <div class="accent accent-green"></div>
                  <div class="stat-body">
                    <span class="stat-text">Total Nilai Perolehan</span>
                    <span class="stat-number stat-small"><?php echo formatCurrency($perolehanCount); ?></span>
                  </div>
                  <i class="bi bi-currency-dollar stat-bg-icon"></i>
                </div>
              </div>

              <div class="col-12 col-sm-6 col-md-3 mb-4">
                <div class="stat-card">
                  <div class="accent accent-gray"></div>
                  <div class="stat-body">
                    <span class="stat-text">Total Nilai Buku</span>
                    <span class="stat-number stat-small"><?php echo formatCurrency($nilai_bukuCount); ?></span>
                  </div>
                  <i class="bi bi-currency-dollar stat-bg-icon"></i>
                </div>
              </div>

              <div class="col-12 col-sm-6 col-md-3 mb-4">
                <div class="stat-card">
                  <div class="accent accent-yellow"></div>
                  <div class="stat-body">
                    <span class="stat-text">Total Akumulasi Penyusutan</span>
                    <span class="stat-number stat-small"><?php echo formatCurrency($penyusutanCount); ?></span>
                  </div>
                  <i class="bi bi-currency-dollar stat-bg-icon"></i>
                </div>
              </div>
              <!-- /.col -->
            
            <!--begin::Row-->
            <div class="row">
                
              <!-- /.col -->
              <div class="col-md-fluid">
                <!-- Distribusi Aset Card -->
                <div class="card mb-4 border-top border-primary border-top-3">
                  <div class="card-header bg-light">
                    <h3 class="card-title fw-bold">Distribusi Aset</h3>
                    <div class="card-tools">
                      <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse">
                        <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                        <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                      </button>
                      <button type="button" class="btn btn-tool" data-lte-toggle="card-remove">
                        <i class="bi bi-x-lg"></i>
                      </button>
                    </div>
                  </div>
                  <!-- /.card-header -->
                  <div class="card-body">
                    <!-- Nav Tabs untuk pilih diagram -->
                    <ul class="nav nav-tabs mb-3" id="chartTabs" role="tablist">
                      <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="horizontal-tab" data-bs-toggle="tab" data-bs-target="#horizontal-chart-tab" type="button" role="tab">
                          <i class="bi bi-bar-chart me-2"></i>Bar Chart (Top 15)
                        </button>
                      </li>
                      <li class="nav-item" role="presentation">
                        <button class="nav-link" id="vertical-tab" data-bs-toggle="tab" data-bs-target="#vertical-chart-tab" type="button" role="tab">
                          <i class="bi bi-bar-chart-fill me-2"></i>Vertical Chart (All Asets)
                        </button>
                      </li>
                      <li class="nav-item" role="presentation">
                        <button class="nav-link" id="pie-tab" data-bs-toggle="tab" data-bs-target="#pie-chart-tab" type="button" role="tab">
                          <i class="bi bi-pie-chart me-2"></i>Pie Chart
                        </button>
                      </li>
                    </ul>

                    <!-- Tab Content -->
                    <div class="tab-content" id="chartTabContent">
                      <!-- Horizontal Bar Chart (Top 15) -->
                      <div class="tab-pane fade show active" id="horizontal-chart-tab" role="tabpanel">
                        <div id="horizontal-bar-chart" style="min-height: 500px;"></div>
                      </div>

                      <!-- Vertical Bar Chart (All Data) -->
                      <div class="tab-pane fade" id="vertical-chart-tab" role="tabpanel">
                        <div id="vertical-bar-chart" style="min-height: 600px;"></div>
                      </div>

                      <!-- Pie Chart (Original) -->
                      <div class="tab-pane fade" id="pie-chart-tab" role="tabpanel">
                        <div id="pie-chart" style="min-height: 400px;"></div>
                      </div>
                    </div>

                    <div class="row mt-4">
                        <div class="col-12">
                        <?php
                        // Breakdown table: total nilai_perolehan_sd and total nilai_buku_sd grouped by asset_class_name
                        $breakdownQuery = "SELECT asset_class_name, COUNT(*) as count, " .
                                  "CAST(SUM(CAST(COALESCE(nilai_perolehan_sd,0) AS DECIMAL(20,2))) AS DECIMAL(20,2)) as total_nilai, " .
                                  "CAST(SUM(CAST(COALESCE(nilai_buku_sd,0) AS DECIMAL(20,2))) AS DECIMAL(20,2)) as total_nilai_buku, " .
                                  "CAST(SUM(CAST(COALESCE(akumulasi_penyusutan,0) AS DECIMAL(20,2))) AS DECIMAL(20,2)) as total_akumulasi_penyusutan, " .
                                  "CAST(SUM(CAST(COALESCE(penyusutan_bulan,0) AS DECIMAL(20,2))) AS DECIMAL(20,2)) as total_penyusutan_bulan " .
                                  "FROM import_dat " .
                                  $filterCondition . " " .
                                  "GROUP BY asset_class_name " .
                                  "ORDER BY total_nilai DESC";

                        $breakdownResult = mysqli_query($con, $breakdownQuery);
                        if (!$breakdownResult) {
                          echo "<!-- Breakdown Query Error: " . mysqli_error($con) . " -->";
                        }

                        if ($breakdownResult && mysqli_num_rows($breakdownResult) > 0) {
                          echo '<div class="mt-3 table-responsive">
                          <table class="table table-sm table-striped mb-0">
                          <thead><tr><th>Asset Class</th>
                          <th class="text-end">Jumlah</th> 
                          <th class="text-start">Total Perolehan</th>
                          <th class="text-start">Total Nilai Buku</th>
                          <th class="text-start">Total Akumulasi Penyusutan</th>
                          <th class="text-start">Total Penyusutan perBulan</th>
                          </tr></thead><tbody>';
                          
                          while ($b = mysqli_fetch_assoc($breakdownResult)) {
                            echo '<tr>';
                            echo '<td>' . htmlspecialchars($b['asset_class_name']) . '</td>';
                            echo '<td class="text-end">' . number_format((int)$b['count']) . '</td>';
                            echo '<td class="text-start">' . formatCurrency(round((float)$b['total_nilai'], 0)) . '</td>';
                            echo '<td class="text-start">' . formatCurrency(round((float)$b['total_nilai_buku'], 0)) . '</td>';
                            echo '<td class="text-start">' . formatCurrency(round((float)$b['total_akumulasi_penyusutan'], 0)) . '</td>';
                            echo '<td class="text-start">' . formatCurrency(round((float)$b['total_penyusutan_bulan'], 0)) . '</td>';
                            echo '</tr>';
                          }
                          echo '</tbody></table></div>';
                        } else {
                          echo '<div class="mt-3 text-muted small">Tidak ada data perolehan per kelas aset.</div>';
                        }
                        ?>
                        </div>
                    </div>
                  </div>
                <!-- /.card -->
              </div>
              <!-- /.col -->
            </div>
            <!-- /.row -->
            <!--end::Row-->
            <!-- /.footer -->
                </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->
      </main>
      <!--end::App Main-->
      <!--begin::Footer-->
      <footer class="app-footer">
        <!--begin::To the end-->
        <div class="float-end d-none d-sm-inline">PT Pelabuhan Indonesia (Persero)</div>
        <!--end::To the end-->
        <!--begin::Copyright-->
        <strong>
          Copyright &copy; Proyek Aset Tetap Regional 3&nbsp;
        </strong>
        <!--end::Copyright-->
      </footer>
      <!--end::Footer-->
    </div>
    <!--end::App Wrapper-->
    <!--begin::Script-->
    <!--begin::Third Party Plugin(OverlayScrollbars)-->
    <script
      src="../../dist/js/overlayscrollbars.browser.es6.min.js"
    ></script>
    <!--end::Third Party Plugin(OverlayScrollbars)--><!--begin::Required Plugin(popperjs for Bootstrap 5)-->
    <script
      src="../../dist/js/popper.min.js"
    ></script>
    <!--end::Required Plugin(popperjs for Bootstrap 5)--><!--begin::Required Plugin(Bootstrap 5)-->
    <script
      src="../../dist/js/bootstrap.min.js"
    ></script>
    <!--end::Required Plugin(Bootstrap 5)--><!--begin::Required Plugin(AdminLTE)-->
    <script src="../../dist/js/adminlte.js"></script>
    <!--end::Required Plugin(AdminLTE)--><!--begin::OverlayScrollbars Configure-->
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
    <!--end::OverlayScrollbars Configure-->
    <!-- OPTIONAL SCRIPTS -->
    <!-- apexcharts -->
    <script
      src="../../dist/js/apexcharts.min.js"
    ></script>
    <script>
      // NOTICE!! DO NOT USE ANY OF THIS JAVASCRIPT
      // IT'S ALL JUST JUNK FOR DEMO
      // ++++++++++++++++++++++++++++++++++++++++++

      /* apexcharts
       * -------
       * Here we will create a few charts using apexcharts
       */

      //-----------------------
      // - MONTHLY SALES CHART -
      //-----------------------

      const sales_chart_options = {
        series: [
          {
            name: 'Digital Goods',
            data: [28, 48, 40, 19, 86, 27, 90],
          },
          {
            name: 'Electronics',
            data: [65, 59, 80, 81, 56, 55, 40],
          },
        ],
        chart: {
          height: 180,
          type: 'area',
          toolbar: {
            show: false,
          },
        },
        legend: {
          show: false,
        },
        colors: ['#0d6efd', '#20c997'],
        dataLabels: {
          enabled: false,
        },
        stroke: {
          curve: 'smooth',
        },
        xaxis: {
          type: 'datetime',
          categories: [
            '2023-01-01',
            '2023-02-01',
            '2023-03-01',
            '2023-04-01',
            '2023-05-01',
            '2023-06-01',
            '2023-07-01',
          ],
        },
        tooltip: {
          x: {
            format: 'MMMM yyyy',
          },
        },
      };

      const sales_chart = new ApexCharts(
        document.querySelector('#sales-chart'),
        sales_chart_options,
      );
      sales_chart.render();

      //---------------------------
      // - END MONTHLY SALES CHART -
      //---------------------------

      function createSparklineChart(selector, data) {
        const options = {
          series: [{ data }],
          chart: {
            type: 'line',
            width: 150,
            height: 30,
            sparkline: {
              enabled: true,
            },
          },
          colors: ['var(--bs-primary)'],
          stroke: {
            width: 2,
          },
          tooltip: {
            fixed: {
              enabled: false,
            },
            x: {
              show: false,
            },
            y: {
              title: {
                formatter() {
                  return '';
                },
              },
            },
            marker: {
              show: false,
            },
          },
        };

        const chart = new ApexCharts(document.querySelector(selector), options);
        chart.render();
      }

      const table_sparkline_1_data = [25, 66, 41, 89, 63, 25, 44, 12, 36, 9, 54];
      const table_sparkline_2_data = [12, 56, 21, 39, 73, 45, 64, 52, 36, 59, 44];
      const table_sparkline_3_data = [15, 46, 21, 59, 33, 15, 34, 42, 56, 19, 64];
      const table_sparkline_4_data = [30, 56, 31, 69, 43, 35, 24, 32, 46, 29, 64];
      const table_sparkline_5_data = [20, 76, 51, 79, 53, 35, 54, 22, 36, 49, 64];
      const table_sparkline_6_data = [5, 36, 11, 69, 23, 15, 14, 42, 26, 19, 44];
      const table_sparkline_7_data = [12, 56, 21, 39, 73, 45, 64, 52, 36, 59, 74];

      createSparklineChart('#table-sparkline-1', table_sparkline_1_data);
      createSparklineChart('#table-sparkline-2', table_sparkline_2_data);
      createSparklineChart('#table-sparkline-3', table_sparkline_3_data);
      createSparklineChart('#table-sparkline-4', table_sparkline_4_data);
      createSparklineChart('#table-sparkline-5', table_sparkline_5_data);
      createSparklineChart('#table-sparkline-6', table_sparkline_6_data);
      createSparklineChart('#table-sparkline-7', table_sparkline_7_data);

      //-------------
      // - PIE CHART -
      //-------------

      <?php
      // Get data from database for pie chart - berdasarkan TOTAL NILAI PEROLEHAN per kategori
      $statusQuery = "SELECT asset_class_name, COUNT(*) as count, 
                      CAST(SUM(CAST(COALESCE(nilai_perolehan_sd, 0) AS DECIMAL(20,2))) AS DECIMAL(20,2)) as total_nilai
                      FROM import_dat 
                      " . $filterCondition . " 
                      GROUP BY asset_class_name 
                      ORDER BY total_nilai DESC";
      $statusResult = mysqli_query($con, $statusQuery);
      
      $labels = []; 
      $data = [];
      $details = []; // simpan detail untuk legend footer
      
      if ($statusResult && mysqli_num_rows($statusResult) > 0) {
          while ($row = mysqli_fetch_assoc($statusResult)) {
              $labels[] = htmlspecialchars($row['asset_class_name']);
              // Gunakan total_nilai (dalam Rp) untuk pie chart, rounded to integer
              $data[] = round((float)$row['total_nilai'], 0);
              $details[] = [
                  'name' => htmlspecialchars($row['asset_class_name']),
                  'count' => (int)$row['count'],
                  'nilai' => round((float)$row['total_nilai'], 0)
              ];
          }
      }
      
      // Jika tidak ada data, gunakan fallback
      if (empty($labels)) {
          $labels = ['Bangunan', 'Tanah', 'Kendaraan', 'Peralatan', 'Lainnya'];
          $data = [5000000000, 3000000000, 2000000000, 1500000000, 1000000000];
          $details = [];
      }
      
      // Convert PHP arrays to JavaScript
      $labelsJson = json_encode($labels);
      $dataJson = json_encode($data);
      ?>

      console.log('Chart Labels:', <?php echo $labelsJson; ?>);
      console.log('Chart Data:', <?php echo $dataJson; ?>);

      const pie_chart_options = {
        series: <?php echo $dataJson; ?>,
        chart: {
          type: 'donut',
          height: 320,
          width: '100%',
          fontFamily: 'inherit',
          sparkline: {
            enabled: false,
          },
        },
        labels: <?php echo $labelsJson; ?>,
        dataLabels: {
          enabled: false,
        },
        plotOptions: {
          pie: {
            donut: {
              size: '68%',
              background: 'transparent',
              labels: {
                show: false,
              },
            },
            expandOnClick: true,
          },
        },
        colors: [
          '#1666df', '#20c997', '#ffc107', '#dc3545', '#17a2b8', '#6c757d', 
          '#198754', '#fd7e14', '#0dcaf0', '#6f42c1', '#e83e8c', '#ff6b6b',
          '#00bcd4', '#673ab7', '#ff5722', '#795548', '#9c27b0', '#2196f3',
          '#4caf50', '#cddc39', '#ffeb3b', '#ff9800', '#e91e63', '#009688',
          '#00897b', '#3f51b5', '#8bc34a', '#ff7043', '#ab47bc', '#42a5f5',
          '#66bb6a', '#ab47bc'
        ],
        legend: {
          position: 'bottom',
          horizontalAlign: 'left',
          fontSize: '12px',
          fontWeight: 500,
          labels: {
            colors: '#666',
          },
          itemMargin: {
            horizontal: 8,
            vertical: 5,
          },
        },
        responsive: [{
          breakpoint: 768,
          options: {
            chart: {
              height: 280,
            },
            legend: {
              fontSize: '11px',
            },
          },
        }, {
          breakpoint: 480,
          options: {
            chart: {
              height: 250,
            },
            legend: {
              fontSize: '10px',
              itemMargin: {
                horizontal: 5,
                vertical: 3,
              },
            },
          },
        }],
        tooltip: {
          enabled: true,
          theme: 'light',
          style: {
            fontSize: '12px',
          },
          y: {
            formatter: function(val) {
              // Format nilai dalam Rp dengan separator
              return 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(val));
            },
            title: {
              formatter: function(seriesName) {
                return seriesName;
              },
            },
          },
          marker: {
            show: true,
          },
        },
        states: {
          hover: {
            filter: {
              type: 'lighten',
              value: 0.1,
            },
          },
          active: {
            filter: {
              type: 'darken',
              value: 0.15,
            },
          },
        },
        stroke: {
          colors: ['#fff'],
          width: 2,
        },
      };

      const pie_chart = new ApexCharts(document.querySelector('#pie-chart'), pie_chart_options);
      pie_chart.render();

      //-----------------
      // - END PIE CHART -
      //-----------------

      // =====================
      // HORIZONTAL BAR CHART (TOP 15)
      // =====================
      const horizontal_labels = <?php echo $labelsJson; ?>;
      const horizontal_data = <?php echo $dataJson; ?>;
      
      // Buat array dengan labels & values, sort descending, ambil top 15
      let horizontal_combined = horizontal_labels.map((label, idx) => ({
        label: label,
        value: horizontal_data[idx]
      })).sort((a, b) => b.value - a.value).slice(0, 15);
      
      const horizontal_chart_options = {
        series: [{
          name: 'Nilai Perolehan (Rp)',
          data: horizontal_combined.map(d => d.value)
        }],
        chart: {
          type: 'bar',
          height: 500,
          toolbar: { show: true }
        },
        plotOptions: {
          bar: {
            horizontal: true,
            dataLabels: { position: 'top' }
          }
        },
        dataLabels: {
          enabled: true,
          formatter: function(val) {
            return 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(val / 1000000000)) + ' JT';
          },
          
          offsetX: 0,
          style: { fontSize: '11px', fontWeight: 600 }
        },
        xaxis: {
          categories: horizontal_combined.map(d => d.label),
          labels: {
            formatter: function(val) {
              return 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(val / 1000000000000)) + 'M';
            }
          }
        },
        colors: ['#1666df'],
        tooltip: {
          y: {
            formatter: function(val) {
              return 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(val));
            }
          }
        },
        noData: { text: 'Loading...' }
      };

      const horizontal_bar_chart = new ApexCharts(document.querySelector('#horizontal-bar-chart'), horizontal_chart_options);
      horizontal_bar_chart.render();

      // =====================
      // VERTICAL BAR CHART (ALL DATA)
      // =====================
      const vertical_combined = horizontal_labels.map((label, idx) => ({
        label: label,
        value: horizontal_data[idx]
      })).sort((a, b) => b.value - a.value);
      
      const vertical_chart_options = {
        series: [{
          name: 'Nilai Perolehan (Rp)',
          data: vertical_combined.map(d => d.value)
        }],
        chart: {
          type: 'bar',
          height: 600,
          toolbar: { show: true },
          zoom: { enabled: true }
        },
        plotOptions: {
          bar: {
            horizontal: false,
            columnWidth: '60%',
            dataLabels: { position: 'top' }
          }
        },
        dataLabels: {
          enabled: false
        },
        xaxis: {
          categories: vertical_combined.map(d => d.label),
          labels: {
            style: { fontSize: '11px' },
            maxHeight: 95
          }
        },
        yaxis: {
          labels: {
            formatter: function(val) {
              return 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(val / 1000000000000)) + 'M';
            }
          }
        },
        colors: ['#20c997'],
        tooltip: {
          y: {
            formatter: function(val) {
              return 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(val));
            }
          }
        }
      };

      const vertical_bar_chart = new ApexCharts(document.querySelector('#vertical-bar-chart'), vertical_chart_options);
      vertical_bar_chart.render();

    </script>
    <!--end::Script-->
  </body>
  <!--end::Body-->
</html>
