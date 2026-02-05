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

// Get data usulan yang perlu dilengkapi dokumen (status = 'lengkapi_dokumen')
$query = "SELECT * FROM usulan_penghapusan 
          WHERE status IN ('lengkapi_dokumen', 'dokumen_lengkap') 
          ORDER BY created_at DESC";
$result = mysqli_query($con, $query);

$submitted_data = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $submitted_data[] = $row;
    }
}

// Get asset data untuk tab "Daftar Aset Tetap" (read-only view)
$userProfitCenter = isset($_SESSION['Cabang']) ? $_SESSION['Cabang'] : '';
$query_aset = "SELECT * FROM import_dat WHERE profit_center = ? ORDER BY nomor_asset_utama ASC";
$stmt_aset = $con->prepare($query_aset);
$stmt_aset->bind_param("s", $userProfitCenter);
$stmt_aset->execute();
$result_aset = $stmt_aset->get_result();

$asset_data = [];
while ($row = $result_aset->fetch_assoc()) {
    $asset_data[] = $row;
}
$stmt_aset->close();

// Handle form lengkapi dokumen submit
$pesan = "";
$tipe_pesan = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'lengkapi_dokumen_submit') {
    $usulan_id = intval($_POST['usulan_id']);
    $jumlah_aset = intval($_POST['jumlah_aset']);
    $mekanisme_penghapusan = $_POST['mekanisme_penghapusan'];
    $fisik_aset = $_POST['fisik_aset'];
    $justifikasi_alasan = $_POST['justifikasi_alasan'];
    $kajian_hukum = $_POST['kajian_hukum'];
    $kajian_ekonomis = $_POST['kajian_ekonomis'];
    $kajian_risiko = $_POST['kajian_risiko'];
    
    // Handle file upload foto
    $foto_path = null;
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['foto'];
        $upload_dir = '../../uploads/foto_aset/';
        
        // Create directory if not exists
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Validate file size (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            $pesan = "Ukuran foto terlalu besar. Maksimal 5MB.";
            $tipe_pesan = "danger";
        } else {
            // Validate file type
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mime_type, $allowed_types)) {
                $pesan = "Tipe file tidak didukung. Gunakan JPG, JPEG, atau PNG.";
                $tipe_pesan = "danger";
            } else {
                $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $new_filename = 'foto_' . $usulan_id . '_' . time() . '.' . $file_ext;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                    $foto_path = $upload_path;
                } else {
                    $pesan = "Gagal mengupload foto.";
                    $tipe_pesan = "danger";
                }
            }
        }
    }
    
    // Update database jika tidak ada error upload
    if ($tipe_pesan !== 'danger') {
        $stmt = $con->prepare("UPDATE usulan_penghapusan SET 
            jumlah_aset = ?,
            mekanisme_penghapusan = ?,
            fisik_aset = ?,
            justifikasi_alasan = ?,
            kajian_hukum = ?,
            kajian_ekonomis = ?,
            kajian_risiko = ?,
            foto_path = ?,
            status = 'dokumen_lengkap',
            updated_at = NOW()
            WHERE id = ?");
        
        $stmt->bind_param("isssssssi", 
            $jumlah_aset, 
            $mekanisme_penghapusan, 
            $fisik_aset,
            $justifikasi_alasan, 
            $kajian_hukum, 
            $kajian_ekonomis, 
            $kajian_risiko,
            $foto_path,
            $usulan_id
        );
        
        if ($stmt->execute()) {
            $pesan = "✅ Data berhasil disimpan. Status diubah menjadi 'Dokumen Lengkap'.";
            $tipe_pesan = "success";
            
            // Refresh data
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } else {
            $pesan = "Gagal menyimpan data: " . $stmt->error;
            $tipe_pesan = "danger";
        }
        $stmt->close();
    }
}
?>

<!doctype html>
<html lang="en">
  <!--begin::Head-->
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Usulan Penghapusan - Web Aset Tetap</title>
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
            </nav>
        </div>
        <!--end::Sidebar Wrapper-->
      </aside>
        <main class="app-main">
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6">
                            <h3 class="mb-0">Lengkapi Dokumen</h3>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <li class="breadcrumb-item"><a href="../dasbor/dasbor.php">Home</a></li>
                                <li class="breadcrumb-item active">Lengkapi Dokumen</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="app-content">
                <div class="container-fluid">
                    <div class="row">
                    <div class="col-12">
                      <div class="card">
                        <div class="card-header">
                          <h3 class="card-title">Daftar Aset untuk Usulan Penghapusan</h3>
                        </div>
                        <!-- /.card-header -->
                        <div class="card-body">

                          <!-- Nav tabs -->
                          <ul class="nav nav-tabs mb-3" id="myTab" role="tablist">
                            <li class="nav-item" role="presentation">
                              <button class="nav-link" id="daftar-aset-tab" data-bs-toggle="tab" data-bs-target="#aset" type="button" role="tab" aria-controls="aset" aria-selected="false">
                                <i class="bi bi-list-ul me-2"></i>Daftar Aset Tetap
                              </button>
                            </li>
                            <li class="nav-item" role="presentation">
                              <button class="nav-link active" id="lengkapi-dokumen-tab" data-bs-toggle="tab" data-bs-target="#dokumen" type="button" role="tab" aria-controls="dokumen" aria-selected="true">
                                <i class="bi bi-file-earmark-check me-2"></i>Lengkapi Dokumen
                              </button>
                            </li>
                            <li class="nav-item" role="presentation">
                              <button class="nav-link" id="summary-tab" data-bs-toggle="tab" data-bs-target="#summary" type="button" role="tab" aria-controls="summary" aria-selected="false">
                                <i class="bi bi-clipboard-data me-2"></i>Summary
                              </button>
                            </li>
                          </ul>

                          <div class="tab-content" id="usulanTabsContent">

                            <!-- Tab Daftar Aset Tetap -->                      
                             <div class="tab-pane fade" id="daftar-aset" role="tabpanel">
                              <div class="card">
                                <div class="card-header">
                                  <h5 class="mb-0">Daftar Aset Tetap</h5>
                                  <small class="text-muted">Klik tombol "Pilih" untuk menggunakan aset dalam form lengkapi dokumen</small>
                                </div>
                                <div class="card-body">
                                  <table id="assetTable" class="table table-bordered table-hover table-sm">
                                    <thead class="table-light">
                                      <tr>
                                        <th style="width: 80px;">Pilih</th> <!-- KOLOM BARU -->
                                        <th>No</th>
                                        <th>Nomor Aset</th>
                                        <th>SubReg</th>
                                        <th>Profit Center</th>
                                        <th>Nama Aset</th>
                                        <th>Kategori Aset</th>
                                        <th>Umur Ekonomis</th>
                                        <th>Sisa Umur Ekonomis</th>
                                        <th>Tgl Perolehan</th>
                                      </tr>
                                    </thead>
                                    <tbody>
                                      <?php 
                                      $no = 1;
                                      foreach($asset_data as $asset): 
                                      ?>
                                      <tr>
                                        <!-- TOMBOL PILIH - BARU -->
                                        <td>
                                          <button type="button" class="btn btn-sm btn-success btn-select-asset" 
                                                  data-nomor-aset="<?= htmlspecialchars($asset['nomor_asset_utama']) ?>"
                                                  data-nama-aset="<?= htmlspecialchars($asset['keterangan_asset']) ?>"
                                                  data-kategori="<?= htmlspecialchars($asset['asset_class_name']) ?>"
                                                  data-profit-center="<?= htmlspecialchars($asset['profit_center']) ?>"
                                                  data-profit-center-text="<?= htmlspecialchars($asset['profit_center_text']) ?>"
                                                  data-nilai-buku="<?= htmlspecialchars($asset['nilai_buku']) ?>"
                                                  data-nilai-perolehan="<?= htmlspecialchars($asset['nilai_perolehan']) ?>"
                                                  title="Gunakan aset ini untuk form lengkapi dokumen">
                                            <i class="bi bi-check-circle"></i> Pilih
                                          </button>
                                        </td>
                                        <td><?= $no++ ?></td>
                                        <td><?= htmlspecialchars($asset['nomor_asset_utama']) ?></td>
                                        <td><?= htmlspecialchars($asset['subreg']) ?></td>
                                        <td><?= htmlspecialchars($asset['profit_center']) ?></td>
                                        <td><?= htmlspecialchars($asset['keterangan_asset']) ?></td>
                                        <td><?= htmlspecialchars($asset['asset_class_name']) ?></td>
                                        <td><?= htmlspecialchars($asset['masa_manfaat']) ?></td>
                                        <td><?= htmlspecialchars($asset['sisa_manfaat']) ?></td>
                                        <td><?= htmlspecialchars($asset['tgl_perolehan']) ?></td>
                                      </tr>
                                      <?php endforeach; ?>
                                    </tbody>
                                  </table>
                                </div>
                              </div>
                            </div>
                            <!-- End Tab Aset -->

                            <!-- Tab Lengkapi Dokumen (active) -->
                            <div class="tab-pane fade show active" id="dokumen" role="tabpanel">

                              <?php if ($pesan): ?>
                              <div class="alert alert-<?= $tipe_pesan ?> alert-dismissible fade show" role="alert">
                                  <?= $pesan ?>
                                  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                              </div>
                              <?php endif; ?>

                              <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0">Data Usulan yang Perlu Dilengkapi Dokumen</h5>
                                <span class="badge bg-info px-3 py-2"><?= count($submitted_data) ?> Data</span>
                              </div>

                              <?php if (empty($submitted_data)): ?>
                                <div class="alert alert-warning">
                                  <i class="bi bi-info-circle me-2"></i>
                                  Belum ada usulan yang perlu dilengkapi dokumen.
                                </div>
                              <?php else: ?>

                              <div class="table-responsive">
                                <table id="documentTable" class="display nowrap table table-striped table-sm w-100">
                                  <thead>
                                    <tr>
                                      <th>No</th>
                                      <th>Nomor Aset</th>
                                      <th>Profit Center</th>
                                      <th>Kategori Aset</th>
                                      <th>Nama Aset</th>
                                      <th>Status</th>
                                      <th>Tanggal Submit</th>
                                      <th>Action</th>
                                    </tr>
                                  </thead>
                                  <tbody>
                                    <?php foreach ($submitted_data as $index => $row): ?>
                                    <tr>
                                      <td><?= $index + 1 ?></td>
                                      <td><?= htmlspecialchars($row['nomor_asset_utama']) ?></td>
                                      <td><?= htmlspecialchars($row['profit_center']) ?></td>
                                      <td><?= htmlspecialchars($row['kategori_aset']) ?></td>
                                      <td><?= htmlspecialchars($row['nama_aset']) ?></td>
                                      <td>
                                        <?php if ($row['status'] === 'lengkapi_dokumen'): ?>
                                          <span class="badge bg-warning text-dark">
                                            <i class="bi bi-exclamation-triangle me-1"></i>Lengkapi Dokumen
                                          </span>
                                        <?php elseif ($row['status'] === 'dokumen_lengkap'): ?>
                                          <span class="badge bg-success">
                                            <i class="bi bi-check-circle me-1"></i>Dokumen Lengkap
                                          </span>
                                        <?php else: ?>
                                          <span class="badge bg-secondary"><?= strtoupper($row['status']) ?></span>
                                        <?php endif; ?>
                                      </td>
                                      <td><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></td>
                                      <td>
                                        <button class="btn btn-sm btn-primary" onclick="openFormLengkapiDokumen(<?= $row['id'] ?>)">
                                          <i class="bi bi-pencil-square"></i> Lengkapi
                                        </button>
                                      </td>
                                    </tr>
                                    </tr>
                                    <?php endforeach; ?>
                                  </tbody>
                                </table>
                              </div>

                              <?php endif; ?>

                            </div>
                            <!-- End Tab Dokumen -->

                            <!-- Tab Summary -->
                            <div class="tab-pane fade" id="summary" role="tabpanel">
                              <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                Ringkasan semua usulan penghapusan aset
                              </div>
                              <!-- Content summary -->
                            </div>
                            <!-- End Tab Summary -->

                          </div>
                          <!-- End tab-content -->
                        </div>
                        <!-- End card-body -->
                      </div>
                      <!-- End card -->
                    </div>
                </div>
            </div>
        
    </main>
    
    <!-- ============================================ -->
    <!-- MODAL FORM LENGKAPI DOKUMEN                  -->
    <!-- ============================================ -->
    <div class="modal fade" id="modalFormLengkapiDokumen" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl">
        <div class="modal-content">
          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title">
              <i class="bi bi-file-earmark-check me-2"></i>Form Lengkapi Dokumen Penghapusan Aset
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          
          <form method="POST" enctype="multipart/form-data" id="formLengkapiDokumen">
            <input type="hidden" name="action" value="lengkapi_dokumen_submit">
            <input type="hidden" name="usulan_id" id="usulan_id">
            
            <div class="modal-body">
              
              <!-- Data Aset (Read-only) -->
              <div class="card mb-3">
                <div class="card-header bg-light">
                  <strong>Data Aset</strong>
                </div>
                <div class="card-body">
                  <div class="row">
                
                    <div class="col-md-6">
                      <div class="mb-2">
                        <strong>Nomor Aset:</strong>
                        <span id="display_nomor_aset">-</span>
                      </div>
                      <div class="mb-2">
                        <strong>Nama Aset:</strong>
                        <span id="display_nama_aset">-</span>
                      </div>
                      <div class="mb-2">
                        <strong>SubReg:</strong>
                        <span id="display_subreg">-</span>
                      </div>
                      <div class="mb-2">
                        <strong>Profit Center:</strong>
                        <span id="display_profit_center">-</span>
                      </div>
                      <div class="mb-2">
                        <strong>Kategori Aset:</strong>
                        <span id="display_kategori_aset">-</span>
                      </div>
                    </div>

                    <div class="col-md-6">
                      <div class="mb-2">
                        <strong>Umur Ekonomis:</strong>
                        <span id="display_umur_ekonomis">-</span>
                      </div>
                      <div class="mb-2">
                        <strong>Sisa Umur Ekonomis:</strong>
                        <span id="display_sisa_umur">-</span>
                      </div>
                      <div class="mb-2">
                        <strong>Tanggal Perolehan:</strong>
                        <span id="display_tgl_perolehan">-</span>
                      </div>
                      <div class="mb-2">
                        <strong>Nilai Buku:</strong>
                        <span id="display_nilai_buku">-</span>
                      </div>
                      <div class="mb-2">
                        <strong>Nilai Perolehan:</strong>
                        <span id="display_nilai_perolehan">-</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Form Input User -->
              <div class="card">
                <div class="card-header bg-light">
                  <strong>Lengkapi Data Penghapusan</strong>
                </div>
                <div class="card-body">
                  
                  <!-- Row 1 -->
                  <div class="row mb-3">
                    <div class="col-md-4">
                      <label class="form-label">Jumlah Aset <span class="text-danger">*</span></label>
                      <input type="number" class="form-control" name="jumlah_aset" value="1" min="1" required>
                      <small class="text-muted">Masukkan jumlah aset, minimal 1</small>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Mekanisme Penghapusan <span class="text-danger">*</span></label>
                      <select class="form-select" name="mekanisme_penghapusan" required>
                        <option value="">-- Pilih --</option>
                        <option value="Jual Lelang">Jual Lelang</option>
                        <option value="Hapus Administrasi">Hapus Administrasi</option>
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Fisik Aset <span class="text-danger">*</span></label>
                      <select class="form-select" name="fisik_aset" required>
                        <option value="">-- Pilih --</option>
                        <option value="Ada">Ada</option>
                        <option value="Tidak Ada">Tidak Ada</option>
                      </select>
                    </div>
                  </div>

                  <!-- Row 2 -->
                  <div class="mb-3">
                    <label class="form-label">Justifikasi/Alasan Penghapusan <span class="text-danger">*</span></label>
                    <textarea class="form-control" name="justifikasi_alasan" rows="3" required 
                              placeholder="Jelaskan alasan penghapusan aset ini..."></textarea>
                  </div>

                  <!-- Row 3 -->
                  <div class="mb-3">
                    <label class="form-label">Kajian Hukum <span class="text-danger">*</span></label>
                    <textarea class="form-control" name="kajian_hukum" rows="3" required
                              placeholder="Kajian aspek hukum terkait penghapusan aset..."></textarea>
                  </div>

                  <!-- Row 4 -->
                  <div class="mb-3">
                    <label class="form-label">Kajian Ekonomis <span class="text-danger">*</span></label>
                    <textarea class="form-control" name="kajian_ekonomis" rows="3" required
                              placeholder="Kajian aspek ekonomis/finansial..."></textarea>
                  </div>

                  <!-- Row 5 -->
                  <div class="mb-3">
                    <label class="form-label">Kajian Risiko <span class="text-danger">*</span></label>
                    <textarea class="form-control" name="kajian_risiko" rows="3" required
                              placeholder="Kajian risiko yang mungkin timbul..."></textarea>
                  </div>

                  <!-- Row 6 - Upload Foto -->
                  <div class="mb-3">
                    <label class="form-label">Foto Aset</label>
                    <input type="file" class="form-control" name="foto" accept="image/jpeg,image/jpg,image/png" 
                           onchange="previewFoto(event)">
                    <small class="text-muted">Format: JPG, JPEG, PNG. Maksimal 5MB.</small>
                    
                    <!-- Preview Foto -->
                    <div id="fotoPreview" class="mt-3" style="display:none;">
                      <img id="fotoPreviewImage" src="" style="max-width:300px; border:2px solid #ddd; border-radius:8px;">
                    </div>
                  </div>

                </div>
              </div>

            </div>
            
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                <i class="bi bi-x-circle"></i> Batal
              </button>
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-save"></i> Simpan Data
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
    </div>
    
    <!-- Modal Upload Document -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.min.js"></script>
    <script src="../../dist/js/adminlte.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/2.3.6/js/dataTables.js"></script>
    
    <script>
        $(document).ready(function() {
        // DataTable untuk tabel dokumen
        $('#documentTable').DataTable({
            responsive: false,
            autoWidth: false,
            scrollX: true,
            paging: true,
            pageLength: 10,
            searching: true,
            ordering: true,
            info: true,
            language: {
                url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json'
            }
        });

        // DataTable untuk tabel aset (read-only di tab Daftar Aset Tetap)
        $('#assetTable').DataTable({
            responsive: false,
            autoWidth: false,
            scrollX: true,
            paging: true,
            pageLength: 10,
            searching: true,
            ordering: true,
            info: true,
            language: {
                url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json'
            }
        });
    });
    
    // Data usulan dalam format JSON untuk akses cepat
    const usulanData = <?= json_encode($submitted_data) ?>;

      function openFormLengkapiDokumen(usulanId) {
        console.log("Klik Lengkapi dengan ID:", usulanId); 
        console.log("Data JSON:", usulanData);

        const usulan = usulanData.find(u => u.id == usulanId);
        
        if (!usulan) {
            alert('Data tidak ditemukan');
            return;
        }
        
        document.getElementById('usulan_id').value = usulanId;
        
        // FIX: Populate semua 10 field dengan mapping yang benar
        document.getElementById('display_nomor_aset').textContent = usulan.nomor_asset_utama || '-';
        document.getElementById('display_nama_aset').textContent = usulan.nama_aset || '-';
        document.getElementById('display_subreg').textContent = usulan.subreg || '-';
        document.getElementById('display_profit_center').textContent = usulan.profit_center + ' - ' + (usulan.profit_center_text || '');
        document.getElementById('display_kategori_aset').textContent = usulan.kategori_aset || '-';
        
        // KOLOM KANAN (5 field)
        document.getElementById('display_umur_ekonomis').textContent = usulan.umur_ekonomis || '-';
        document.getElementById('display_sisa_umur').textContent = usulan.sisa_umur_ekonomis || '-';
        document.getElementById('display_tgl_perolehan').textContent = usulan.tgl_perolehan || '-';
        
        // Format currency untuk Nilai Buku dan Nilai Perolehan
        const nilaiBuku = usulan.nilai_buku ? 'Rp ' + parseInt(usulan.nilai_buku).toLocaleString('id-ID') : '-';
        const nilaiPerolehan = usulan.nilai_perolehan ? 'Rp ' + parseInt(usulan.nilai_perolehan).toLocaleString('id-ID') : '-';
        
        document.getElementById('display_nilai_buku').textContent = nilaiBuku;
        document.getElementById('display_nilai_perolehan').textContent = nilaiPerolehan;
        
        // Reset form
        document.getElementById('formLengkapiDokumen').reset();
        document.getElementById('usulan_id').value = usulanId;
        document.getElementById('fotoPreview').style.display = 'none';
        
        var modal = new bootstrap.Modal(document.getElementById('modalFormLengkapiDokumen'));
        modal.show();
    }

    function previewFoto(event) {
        const file = event.target.files[0];
        if (file) {
            // Validate size (5MB)
            if (file.size > 5 * 1024 * 1024) {
                alert('Ukuran file terlalu besar. Maksimal 5MB.');
                event.target.value = '';
                document.getElementById('fotoPreview').style.display = 'none';
                return;
            }
            
            // Preview
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('fotoPreviewImage').src = e.target.result;
                document.getElementById('fotoPreview').style.display = 'block';
            }
            reader.readAsDataURL(file);
        } else {
            document.getElementById('fotoPreview').style.display = 'none';
        }
    }

    // === FITUR BARU: Event handler untuk tombol "Pilih Aset" ===
    $(document).on('click', '.btn-select-asset', function() {
        const nomorAset = $(this).data('nomor-aset');
        const namaAset = $(this).data('nama-aset');
        const kategori = $(this).data('kategori');
        const profitCenter = $(this).data('profit-center');
        const profitCenterText = $(this).data('profit-center-text');
        const nilaiBuku = $(this).data('nilai-buku');
        const nilaiPerolehan = $(this).data('nilai-perolehan');
        
        console.log('Aset dipilih:', nomorAset, namaAset);
        
        // Cek apakah aset ini sudah ada di usulan_penghapusan
        const existingUsulan = usulanData.find(u => u.nomor_asset_utama === nomorAset);
        
        if (existingUsulan) {
            // Jika sudah ada, gunakan data dari usulan
            if (confirm('Aset ini sudah terdaftar dalam usulan. Gunakan data yang ada?')) {
                fillFormWithUsulan(existingUsulan);
            }
        } else {
            // Jika belum ada, buat usulan baru terlebih dahulu
            if (confirm('Aset "' + namaAset + '" belum terdaftar dalam usulan.\n\nBuat usulan penghapusan untuk aset ini?')) {
                createNewUsulan(nomorAset, namaAset, kategori, profitCenter, profitCenterText, nilaiBuku, nilaiPerolehan);
            }
        }
    });

    // Fungsi untuk mengisi form dengan data usulan yang sudah ada
    function fillFormWithUsulan(usulan) {
        // Set hidden input
        document.getElementById('usulan_id').value = usulan.id;
        
        // Populate read-only fields
        document.getElementById('display_nomor_aset').textContent = usulan.nomor_asset_utama;
        document.getElementById('display_nama_aset').textContent = usulan.nama_aset || '-';
        document.getElementById('display_kategori_aset').textContent = usulan.kategori_aset || '-';
        document.getElementById('display_profit_center').textContent = usulan.profit_center + ' - ' + (usulan.profit_center_text || '');
        
        // Format currency
        const nilaiBuku = usulan.nilai_buku ? 'Rp ' + parseInt(usulan.nilai_buku).toLocaleString('id-ID') : '-';
        const nilaiPerolehan = usulan.nilai_perolehan ? 'Rp ' + parseInt(usulan.nilai_perolehan).toLocaleString('id-ID') : '-';
        
        document.getElementById('display_nilai_buku').textContent = nilaiBuku;
        document.getElementById('display_nilai_perolehan').textContent = nilaiPerolehan;
        
        // Reset form
        document.getElementById('formLengkapiDokumen').reset();
        document.getElementById('usulan_id').value = usulan.id; // Set lagi karena reset
        document.getElementById('fotoPreview').style.display = 'none';
        
        // Pindah ke tab "Lengkapi Dokumen"
        switchToLengkapiTab();
        
        showSuccessMessage('✅ Aset berhasil dipilih! Silakan lengkapi data penghapusan.');
    }

    // Fungsi untuk membuat usulan baru via AJAX
    function createNewUsulan(nomorAset, namaAset, kategori, profitCenter, profitCenterText, nilaiBuku, nilaiPerolehan) {
        // Tampilkan loading
        showLoadingOverlay('Membuat usulan baru...');
        
        $.ajax({
            url: 'create_usulan_from_asset.php',
            type: 'POST',
            data: {
                nomor_aset: nomorAset,
                action: 'create_from_lengkapi'
            },
            dataType: 'json',
            success: function(response) {
                hideLoadingOverlay();
                
                if (response.success) {
                    // Tambahkan ke usulanData
                    usulanData.push(response.usulan);
                    
                    // Isi form dengan usulan yang baru dibuat
                    fillFormWithUsulan(response.usulan);
                    
                    showSuccessMessage('✅ Usulan berhasil dibuat! Silakan lengkapi data penghapusan.');
                } else {
                    alert('❌ Gagal membuat usulan: ' + response.message);
                }
            },
            error: function(xhr, status, error) {
                hideLoadingOverlay();
                console.error('Error creating usulan:', error);
                alert('❌ Terjadi kesalahan saat membuat usulan. Silakan coba lagi.');
            }
        });
    }

    // Fungsi helper untuk pindah tab
    function switchToLengkapiTab() {
        const lengkapiTab = document.querySelector('a[href="#lengkapi-dokumen"]');
        if (lengkapiTab) {
            const tabInstance = bootstrap.Tab.getInstance(lengkapiTab) || new bootstrap.Tab(lengkapiTab);
            tabInstance.show();
            
            // Scroll ke bagian form setelah pindah tab
            setTimeout(() => {
                const formCard = document.querySelector('#lengkapi-dokumen .card');
                if (formCard) {
                    formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }, 300);
        }
    }

    // Fungsi helper untuk menampilkan pesan sukses
    function showSuccessMessage(message) {
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
        alertDiv.style.zIndex = '9999';
        alertDiv.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(alertDiv);
        
        setTimeout(() => {
            alertDiv.remove();
        }, 5000);
    }

    // Fungsi helper untuk loading overlay
    function showLoadingOverlay(message) {
        const overlay = document.createElement('div');
        overlay.id = 'loadingOverlay';
        overlay.className = 'position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center';
        overlay.style.backgroundColor = 'rgba(0,0,0,0.5)';
        overlay.style.zIndex = '9999';
        overlay.innerHTML = `
            <div class="text-center">
                <div class="spinner-border text-light" role="status" style="width: 3rem; height: 3rem;">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="text-white mt-3">${message}</p>
            </div>
        `;
        document.body.appendChild(overlay);
    }

    function hideLoadingOverlay() {
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) {
            overlay.remove();
        }
    }
</script>
</body>
</html>