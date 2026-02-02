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

// Initialize variables
$importedData = [];
$pesan = "";
$tipe_pesan = "";
$saved_count = 0;


// Fetch data from hasil_dat table
$query = "SELECT * FROM import_dat ORDER BY nomor_asset_utama ASC";
$result = mysqli_query($con, $query);

$asset_data = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $asset_data[] = $row;
    }
}

// Handle save to database with status (draft or submitted)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && ($_POST['action'] === 'save_draft' || $_POST['action'] === 'submit_data')) {
    $is_submit = ($_POST['action'] === 'submit_data');
    
    if (!isset($_POST['selected_items']) || empty($_POST['selected_items'])) {
        $pesan = "Tidak ada data yang dipilih";
        $tipe_pesan = "warning";
    } else {
        try {
            $selected_ids = json_decode($_POST['selected_items'], true);
            
            if (empty($selected_ids)) {
                $pesan = "Tidak ada data yang dipilih";
                $tipe_pesan = "warning";
            } else {
                // Save to database with status
                $saved_count = saveSelectedAssets($con, $selected_ids, $is_submit, $_SESSION['nipp']);
                
                if ($saved_count > 0) {
                    if ($is_submit) {
                        $pesan = "✅ Berhasil submit " . $saved_count . " aset. Data telah masuk ke menu Lengkapi Dokumen";
                        $tipe_pesan = "success";
                    } else {
                        $pesan = "✅ Berhasil menyimpan " . $saved_count . " aset sebagai draft";
                        $tipe_pesan = "success";
                    }
                } else {
                    $pesan = "Tidak ada data baru yang disimpan (data mungkin sudah ada)";
                    $tipe_pesan = "info";
                }
            }
        } catch (Exception $e) {
            $pesan = "Error: " . $e->getMessage();
            $tipe_pesan = "danger";
        }
    }
}

// Function to save selected assets to usulan_penghapusan table
function saveSelectedAssets($con, $selected_ids, $is_submit, $created_by) {
    $saved_count = 0;
    $status = $is_submit ? 'submitted' : 'draft';
    
    // Prepare statement untuk insert
    $stmt = $con->prepare("INSERT INTO usulan_penghapusan (
        nomor_asset_utama, 
        subreg, 
        profit_center, 
        pc_text, 
        cost_center_baru, 
        deskripsi_cc, 
        cabang_kawasan, 
        tahun,
        status, 
        created_at, 
        created_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
    
    foreach ($selected_ids as $asset_id) {
        // Get asset data from hasil_dat
        $query = "SELECT * FROM hasil_dat WHERE id = ?";
        $get_stmt = $con->prepare($query);
        $get_stmt->bind_param("i", $asset_id);
        $get_stmt->execute();
        $result = $get_stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            // Check if asset already exists in usulan_penghapusan
            $check = $con->prepare("SELECT id FROM usulan_penghapusan WHERE no_asset_utama = ?");
            $check->bind_param("s", $row['nomor_asset_utama']);
            $check->execute();
            $check_result = $check->get_result();
            
            if ($check_result->num_rows > 0) {
                // Skip duplicate
                $check->close();
                continue;
            }
            $check->close();
            
            // Insert data
            $stmt->bind_param("ssssssssss", 
                $row['nomor_asset_utama'],
                $row['subreg'],
                $row['profit_center'],
                $row['pc_text'],
                $row['cost_center_baru'],
                $row['deskripsi_cc'],
                $row['cabang_kawasan'],
                $row['tahun'],
                $status,
                $created_by
            );
            
            if ($stmt->execute()) {
                $saved_count++;
            }
        }
        
        $get_stmt->close();
    }
    
    $stmt->close();
    return $saved_count;
}
    
    // Begin transaction for data integrity
    mysqli_begin_transaction($con);
    
    try {
        foreach ($importedData as $row_index => $row) {
            // Prepare values
            $values = [];
            foreach ($column_names as $col_idx => $col_name) {
                $value = isset($row[$col_idx]) ? $row[$col_idx] : '';
                $values[] = "'" . mysqli_real_escape_string($con, $value) . "'";
            }
            
            // Add imported_by
            $values[] = "'" . mysqli_real_escape_string($con, $nipp) . "'";
            
            // Build insert query
            $columns = implode(', ', $column_names) . ', imported_by';
            $insert_sql = "INSERT INTO import_dat (" . $columns . ") VALUES (" . implode(', ', $values) . ")";
            
            if (mysqli_query($con, $insert_sql)) {
                $saved_count++;
            } else {
                // Check if error is duplicate entry
                $error = mysqli_error($con);
                if (strpos($error, 'Duplicate entry') !== false) {
                    $failed_rows[] = "Baris " . ($row_index + 2) . ": Asset sudah ada di database";
                } else {
                    $failed_rows[] = "Baris " . ($row_index + 2) . ": " . $error;
                }
            }
        }
        
        // Commit transaction
        mysqli_commit($con);
        
    } catch (Exception $e) {
        // Rollback on error
        mysqli_rollback($con);
        throw new Exception("Gagal menyimpan data: " . $e->getMessage());
    }
    
    // Log failed rows (optional)
    if (!empty($failed_rows)) {
        error_log("Import failed rows: " . implode("; ", array_slice($failed_rows, 0, 5)));
    }
    
?>

<!doctype html>
<html lang="en">
  <!--begin::Head-->
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Ususlan Penghapusan - Web Aset Tetap</title>
    <link rel="icon" type="image/png" href="../../dist/assets/img/emblem.png" /> 
    <link rel="shortcut icon" type="image/png" href="../../dist/assets/img/emblem.png" />  
    <!--begin::Accessibility Meta Tags-->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes" />
    <meta name="color-scheme" content="light dark" />
    <meta name="theme-color" content="#007bff" media="(prefers-color-scheme: light)" />
    <meta name="theme-color" content="#1a1a1a" media="(prefers-color-scheme: dark)" />
    <!--end::Accessibility Meta Tags-->
    <!--begin::Primary Meta Tags-->
    <meta name="title" content="AdminLTE | Dashboard v2" />
    <meta name="author" content="ColorlibHQ" />
    <meta
      name="description"
      content="AdminLTE is a Free Bootstrap 5 Admin Dashboard, 30 example pages using Vanilla JS. Fully accessible with WCAG 2.1 AA compliance."
    />
    <meta 
      name="keywords"
      content="bootstrap 5, bootstrap, bootstrap 5 admin dashboard, bootstrap 5 dashboard, bootstrap 5 charts, bootstrap 5 calendar, bootstrap 5 datepicker, bootstrap 5 tables, bootstrap 5 datatable, vanilla js datatable, colorlibhq, colorlibhq dashboard, colorlibhq admin dashboard, accessible admin panel, WCAG compliant"
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
      href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css"
      integrity="sha256-tXJfXfp6Ewt1ilPzLDtQnJV4hclT9XuaZUKyUvmyr+Q="
      crossorigin="anonymous"
      media="print"
      onload="this.media='all'"
    />
    <!--end::Fonts-->
    <!--begin::Third Party Plugin(OverlayScrollbars)-->
    <link
      rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/styles/overlayscrollbars.min.css"
      crossorigin="anonymous"
    />
    <!--end::Third Party Plugin(OverlayScrollbars)-->
    <!--begin::Third Party Plugin(Bootstrap Icons)-->
    <link
      rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
      crossorigin="anonymous"
    />
    <!--end::Third Party Plugin(Bootstrap Icons)-->
    <!--begin::Required Plugin(AdminLTE)-->
    <link rel="stylesheet" href="../../dist/css/adminlte.css" />
    <!--end::Required Plugin(AdminLTE)-->
    <!-- Custom Styles for Horizontal Scroll -->
    <style>
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
      #myTable {
        margin-bottom: 0;
      }
      #myTable thead th, 
      #myTable tbody td {
        padding: 8px 12px;
        white-space: nowrap;
        min-width: 130px;
      }
      #myTable thead th {
        background-color: #f8f9fa;
        font-weight: 600;
        border-bottom: 2px solid #dee2e6;
      }
      #myTable tbody td {
        border-bottom: 1px solid #dee2e6;
      }
    </style>
    <!--end::Custom Styles-->
    <!-- apexcharts -->
    <link
      rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.css"
      integrity="sha256-4MX+61mt9NVvvuPjUWdUdyfZfxSB1/Rf9WtqRHgG5S0="
      crossorigin="anonymous"
    />
    <link rel="stylesheet"
      href="https://cdn.datatables.net/2.3.6/css/dataTables.dataTables.min.css"
    />
  </head>
  <body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <!--begin::App Wrapper-->
    <div class="app-wrapper">
      <!--begin::Header-->
      <nav class="app-header navbar navbar-expand bg-body">
        <!--begin::Container-->
        <div class="container-fluid">
          <!--begin::Start Navbar Links-->
          <!--end::Start Navbar Links-->
          <!--begin::End Navbar Links-->
          <ul class="navbar-nav ms-auto">
            <!--begin::Navbar Search-->
            <li class="nav-item">
              <a class="nav-link" data-widget="navbar-search" href="#" role="button">
                <i class="bi bi-search"></i>
              </a>
            </li>
            <!--end::Navbar Search-->
            <!--begin::Messages Dropdown Menu-->
            <!--end::Notifications Dropdown Menu-->
            <!--begin::Fullscreen Toggle-->
            <li class="nav-item">
              <a class="nav-link" href="#" data-lte-toggle="fullscreen">
                <i data-lte-icon="maximize" class="bi bi-arrows-fullscreen"></i>
                <i data-lte-icon="minimize" class="bi bi-fullscreen-exit" style="display: none"></i>
              </a>
            </li>
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
      <aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
        <!--begin::Sidebar Brand-->
        <div class="sidebar-brand">
          <!--begin::Brand Link-->
          <a href="./index.html" class="brand-link">
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
        <div class="sidebar-wrapper">
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
            $result = mysqli_query($con, $query) or die(mysqli_error($con));
            $iconMap = [
                'Dasboard'               => 'bi bi-grid-fill',
                'Usulan Penghapusan'     => 'bi bi-clipboard-plus-fill',
                'Approval SubReg'        => 'bi bi-check-circle',
                'Approval Regional'      => 'bi bi-check2-square',
                'Persetujuan Penghapusan'=> 'bi bi-clipboard-check-fill',
                'Pelaksanaan Penghapusan'=> 'bi bi-tools',
                'Manajemen Menu'         => 'bi bi-list-ul',
                'Import DAT'             => 'bi bi-file-earmark-arrow-up-fill',
                'Daftar Aset Tetap'      => 'bi bi-card-list',
                'Manajemen User'         => 'bi bi-people-fill'
            ];
  
            while ($row = mysqli_fetch_assoc($result)) {
                $namaMenu = trim($row['nama_menu']); 
                $icon = $iconMap[$namaMenu] ?? 'bi bi-circle';
                
                $currentPage = basename($_SERVER['PHP_SELF']);
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
              <div class="col-sm-6"><h3 class="mb-0">Usulan Penghapusan Aset</h3></div>
              <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                  <li class="breadcrumb-item"><a href="../dasbor/dasbor.php">Home</a></li>
                  <li class="breadcrumb-item active">Usulan Penghapusan</li>
                </ol>
              </div>
            </div>
            <!--end::Row-->
          </div>
          <!--end::Container-->
        </div>
        <div class="app-content">
          <!--begin::Container-->
          <div class="container-fluid">
            <!--begin::Row-->
                  <!--begin::Header-->
                  <!--end::Header-->
                  <!--begin::Form-->
                  <!--begin::Header-->
                  <div class="row">
                    <div class="card card-outline mb-4">
                    <div class="card-header">
                      <ul class="nav nav-tabs card-header-tabs" id="usulanTabs" role="tablist">
                        <li class="nav-item">
                          <button class="nav-link active" id="aset-tab" data-bs-toggle="tab" data-bs-target="#aset" type="button" role="tab">
                            Daftar Aset Tetap
                          </button>
                        </li>
                        <li class="nav-item">
                          <button class="nav-link" id="dokumen-tab" data-bs-toggle="tab" data-bs-target="#dokumen" type="button" role="tab">
                            Lengkapi Dokumen
                          </button>
                        </li>
                        <li class="nav-item">
                          <button class="nav-link" id="summary-tab" data-bs-toggle="tab" data-bs-target="#summary" type="button" role="tab">
                            Summary
                          </button>
                        </li>
                      </ul>
                    </div>
                   <div class="card-body">
                    <div class="tab-content" id="usulanTabsContent">
                      
                          <!-- Tab Pilih Aset -->
                          <div class="tab-pane fade show active" id="aset" role="tabpanel">
                            <h5>Pilih untuk Usulan Penghapusan</h5>  
                            <!-- Table -->
                            <div class="table-responsive">
                              <table id="myTable" class="display nowrap table table-striped table-sm w-auto">
                                <thead>
                                  <tr>
                                    <th>No Asset Utama</th>
                                    <th>Subreg</th>
                                    <th>Profit Center</th>
                                    <th>PC Text</th>
                                    <th>Cost Center Baru</th>
                                    <th>Deskripsi CC</th>
                                    <th>Cabang/Kawasan</th>
                                    <th>Tahun</th>
                                    <th>Action</th>
                                  </tr>
                                </thead>
                                <tbody>
                                  <?php  
                                    $query = "SELECT * FROM import_dat";
                                    $result = mysqli_query($con, $query);
                                    if (!$result) {
                                      echo '<tr><td colspan="45">Error: ' . mysqli_error($con) . '</td></tr>';
                                    } elseif (mysqli_num_rows($result) == 0) {
                                      $query = "SELECT * FROM import_dat";
                                      $result = mysqli_query($con, $query);
                                    }
                                    while ($row = mysqli_fetch_assoc($result)) {
                                      echo '
                                        <tr>
                                          <td>'.htmlspecialchars($row['nomor_asset_utama']).'</td>
                                          <td>'.htmlspecialchars($row['subreg']).'</td>
                                          <td>'.htmlspecialchars($row['profit_center']).'</td>
                                          <td>'.htmlspecialchars($row['profit_center_text']).'</td>
                                          <td>'.htmlspecialchars($row['cost_center_baru']).'</td>
                                          <td>'.htmlspecialchars($row['deskripsi_cost_center']).'</td>
                                          <td>'.htmlspecialchars($row['nama_cabang_kawasan']).'</td>
                                          <td>'.htmlspecialchars($row['kode_plant']).'</td>
                                          <td>
                                            <input type="checkbox" class="row-checkbox" value="'.htmlspecialchars($row['id']).'"> 
                                        </tr>';
                                    }
                                  ?>
                                </tbody>
                              </table>
                            </div>
                            <!-- End Table -->
                            <!-- Tombol hanya di tab aset -->
                             <!-- Action Buttons -->
                                    <form id="actionForm" method="POST">
                                        
                                        <div class="action-buttons">
                                            <button type="button" class="btn btn-secondary btn-action" id="saveDraftBtn" onclick="saveData('draft')">
                                                <i class="bi bi-save"></i> Simpan sebagai Draft
                                            </button>
                                            <button type="button" class="btn btn-primary btn-action" id="submitBtn" onclick="saveData('submit')">
                                                <i class="bi bi-send-check"></i> Submit ke Lengkapi Dokumen
                                            </button>
                                            <button type="button" class="btn btn-danger" onclick="clearSelection()">
                                                <i class="bi bi-x-circle"></i> Batal Pilihan
                                            </button>
                                        </div>
                                    </form>
                                    
                                    <!-- Selection Info --> 
                                    <div id="selectionInfo" class="alert alert-info mt-3" style="display: none;">
                                        <i class="bi bi-check2-circle"></i> Anda telah memilih <span id="selectionCount">0</span> aset.
                                    </div>

                          <!-- End Tab Pilih Aset -->
                  </div>
                </div>
            </div>
                  <!--end::Form-->
            <!--end::Row-->
          </div>
        </div>
        <!--end::App Content-->
      </main>
      <!--end::App Main-->
      <!--begin::Footer-->
      <footer class="app-footer">
        <!--begin::To the end-->
        <div class="float-end d-none d-sm-inline">PT Pelabuhan Indon3sia (Persero)</div>
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
      src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/browser/overlayscrollbars.browser.es6.min.js"
      crossorigin="anonymous"
    ></script>
    <!--end::Third Party Plugin(OverlayScrollbars)--><!--begin::Required Plugin(popperjs for Bootstrap 5)-->
    <script
      src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"
      crossorigin="anonymous"
    ></script>
    <!--end::Required Plugin(popperjs for Bootstrap 5)--><!--begin::Required Plugin(Bootstrap 5)-->
    <script
      src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"
      crossorigin="anonymous"
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
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/2.3.6/js/dataTables.js"></script>
    <script src="https://cdn.datatables.net/responsive/3.0.0/js/dataTables.responsive.js"></script>
    <script src="https://cdn.datatables.net/buttons/3.0.0/js/dataTables.buttons.js"></script>
    <script src="https://cdn.datatables.net/buttons/3.0.0/js/buttons.html5.js"></script>
    <script src="https://cdn.datatables.net/buttons/3.0.0/js/buttons.print.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.min.js"></script>
    <script
      src="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.min.js"
      integrity="sha256-+vh8GkaU7C9/wbSLIcwq82tQ2wTf44aOHA8HlBMwRI8="
      crossorigin="anonymous"
    ></script>
    <script>
      // NOTICE!! DO NOT USE ANY OF THIS JAVASCRIPT
      // IT'S ALL JUST JUNK FOR DEMO
      // ++++++++++++++++++++++++++++++++++++++++++

      // Optimized document ready function
      $(document).ready(function() {
        // Add loading state to import button
        const submitBtn = document.querySelector('form[method="POST"][enctype="multipart/form-data"] button[type="submit"]');        
        if (submitBtn) {
          submitBtn.addEventListener('click', function(e) {
            const fileInput = document.getElementById('file_excel');
            if (!fileInput.value) {
              e.preventDefault();
              alert('Silakan pilih file terlebih dahulu');
              return;
            }
          });
        }

      // Initialize DataTable dengan responsive
          $('#myTable').DataTable({
            responsive: true,
            autoWidth: false,
            scrollX: true,
            scrollCollapse: true,
            fixedHeader: true,
            paging: true,
            pageLength: 10,
            searching: true,
            ordering: true,
            info: true,
            processing: true,
            deferRender: true,
            retrieve: true,
            columnDefs: [
              {
                targets: '_all',
                className: 'dt-body-center'
              }
            ],
            language: {
              url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/id.json'
          },
            initComplete: function() {
            console.log('DataTable initialized successfully');
          }
        });
      });

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

      // Only initialize sparkline charts if they exist in the page
      const table_sparkline_1_data = [25, 66, 41, 89, 63, 25, 44, 12, 36, 9, 54];
      const table_sparkline_2_data = [12, 56, 21, 39, 73, 45, 64, 52, 36, 59, 44];
      const table_sparkline_3_data = [15, 46, 21, 59, 33, 15, 34, 42, 56, 19, 64];
      const table_sparkline_4_data = [30, 56, 31, 69, 43, 35, 24, 32, 46, 29, 64];
      const table_sparkline_5_data = [20, 76, 51, 79, 53, 35, 54, 22, 36, 49, 64];
      const table_sparkline_6_data = [5, 36, 11, 69, 23, 15, 14, 42, 26, 19, 44];
      const table_sparkline_7_data = [12, 56, 21, 39, 73, 45, 64, 52, 36, 59, 74];

      // Only create sparklines if their containers exist
      if (document.querySelector('#table-sparkline-1')) createSparklineChart('#table-sparkline-1', table_sparkline_1_data);
      if (document.querySelector('#table-sparkline-2')) createSparklineChart('#table-sparkline-2', table_sparkline_2_data);
      if (document.querySelector('#table-sparkline-3')) createSparklineChart('#table-sparkline-3', table_sparkline_3_data);
      if (document.querySelector('#table-sparkline-4')) createSparklineChart('#table-sparkline-4', table_sparkline_4_data);
      if (document.querySelector('#table-sparkline-5')) createSparklineChart('#table-sparkline-5', table_sparkline_5_data);
      if (document.querySelector('#table-sparkline-6')) createSparklineChart('#table-sparkline-6', table_sparkline_6_data);
      if (document.querySelector('#table-sparkline-7')) createSparklineChart('#table-sparkline-7', table_sparkline_7_data);

      //-----------------
      // - END PIE CHART -
      //-----------------

      // Select All functionality
            $('#selectAll').on('change', function() {
                const isChecked = $(this).prop('checked');
                $('.row-checkbox').prop('checked', isChecked);
                updateSelectedCount();
            });
            
            // Individual checkbox
            $(document).on('change', '.row-checkbox', function() {
                const totalCheckboxes = $('.row-checkbox').length;
                const checkedCheckboxes = $('.row-checkbox:checked').length;
                $('#selectAll').prop('checked', totalCheckboxes === checkedCheckboxes);
                updateSelectedCount();
            });
        
        function updateSelectedCount() {
            const count = $('.row-checkbox:checked').length;
            $('#selectionCount').text(count);
            
            if (count > 0) {
                $('#selectionInfo').slideDown();
            } else {
                $('#selectionInfo').slideUp();
            }
        }
        
        function clearSelection() {
            $('.row-checkbox').prop('checked', false);
            $('#selectAll').prop('checked', false);
            updateSelectedCount();
        }
        
        function saveData(type) {
            // Get selected checkboxes
            const selectedIds = [];
            $('.row-checkbox:checked').each(function() {
                selectedIds.push($(this).val());
            });
            
            if (selectedIds.length === 0) {
                alert('⚠️ Silakan pilih minimal 1 aset untuk diusulkan penghapusan');
                return;
            }
            
            const actionText = type === 'draft' ? 'menyimpan sebagai draft' : 'submit ke menu Lengkapi Dokumen';
            const actionIcon = type === 'draft' ? '💾' : '📤';
            const message = `${actionIcon} Anda yakin ingin ${actionText}?\n\nJumlah aset: ${selectedIds.length} aset`;
            
            if (confirm(message)) {
                // Set selected items and action type
                document.getElementById('selectedItemsInput').value = JSON.stringify(selectedIds);
                document.getElementById('actionType').value = type === 'draft' ? 'save_draft' : 'submit_data';
                
                // Disable buttons
                const btnId = type === 'draft' ? 'saveDraftBtn' : 'submitBtn';
                const btn = document.getElementById(btnId);
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Memproses...';
                
                // Also disable the other button
                const otherBtnId = type === 'draft' ? 'submitBtn' : 'saveDraftBtn';
                document.getElementById(otherBtnId).disabled = true;
          // Submit form
          document.getElementById('saveForm').submit();
        }
      }
    </script>
    <!--end::Script-->
  </body>
  <!--end::Body-->
</html>
