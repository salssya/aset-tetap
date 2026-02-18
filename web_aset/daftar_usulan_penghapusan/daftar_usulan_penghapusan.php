<?php
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "asetreg3_db";

$con = mysqli_connect($servername, $username, $password, $dbname);
session_start();

// Helper: hapus kode AUC dalam berbagai variasi (AUC, AUC-, AUC - )
function stripAUC($s) {
  if ($s === null) return $s;
  $s = preg_replace('/\\bAUC\\s*(?:-|–)?\\s*/i', '', $s);
  return trim($s);
}

if (!isset($_SESSION["nipp"]) || !isset($_SESSION["name"])) {
    header("Location: ../login/login_view.php");
    exit();
}

// Helper: normalize strings untuk perbandingan
function normalize_str($s) {
  return strtolower(trim((string)$s));
}

// Helper: ambil daftar profit_center berdasarkan profit_center_text (subreg)
function get_pcs_for_subreg($con, $subreg_text) {
  $out = [];
  $norm = normalize_str($subreg_text);
  if ($norm === '') return $out;
  $q = "SELECT DISTINCT profit_center FROM import_dat WHERE LOWER(TRIM(profit_center_text)) = '" . mysqli_real_escape_string($con, $norm) . "'";
  $res = mysqli_query($con, $q);
  if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
      if (!empty($r['profit_center'])) $out[] = trim(strtolower($r['profit_center']));
    }
  }
  return $out;
}

// ============================================================
// HANDLER: Lihat Dokumen — file disimpan di folder, path di DB
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'view_doc' && isset($_GET['id'])) {
  $id = (int)$_GET['id'];
  $nipp_val = (string)($_SESSION['nipp'] ?? '');

  $q = "SELECT file_path, file_name, nipp, subreg, profit_center, profit_center_text, type_user 
      , tahun_dokumen
      FROM dokumen_penghapusan WHERE usulan_id = $id LIMIT 1";
  $res = mysqli_query($con, $q);

  if (!$res || mysqli_num_rows($res) === 0) {
    http_response_code(404);
    echo 'Dokumen tidak ditemukan untuk usulan ID: ' . $id;
    exit();
  }

  $row = mysqli_fetch_assoc($res);

  // Cek akses — nipp dibandingkan sebagai string
  $isOwner            = (trim((string)$row['nipp']) === trim($nipp_val));
  $typeUser           = isset($_SESSION['Type_User']) ? (string)$_SESSION['Type_User'] : '';
  $isAdmin            = stripos($typeUser, 'admin') !== false;
  $isApprovalSubreg   = stripos($typeUser, 'approval subreg') !== false;
  $isApprovalRegional = stripos($typeUser, 'approval regional') !== false;

  $canView = false;

  if ($isOwner || $isAdmin) {
    $canView = true;
  } elseif ($isApprovalSubreg) {
    $session_subreg = normalize_str($_SESSION['profit_center_text'] ?? '');
    $session_pc     = normalize_str($_SESSION['profit_center'] ?? $_SESSION['Cabang'] ?? '');
    $doc_subreg     = normalize_str($row['subreg'] ?? '');
    $doc_pc         = normalize_str($row['profit_center'] ?? '');
    $doc_pct        = normalize_str($row['profit_center_text'] ?? '');
    $allowed_pcs    = get_pcs_for_subreg($con, $session_subreg);

    if ($session_subreg !== '' && ($doc_subreg === $session_subreg || $doc_pct === $session_subreg)) {
      $canView = true;
    } elseif ($session_pc !== '' && $doc_pc === $session_pc) {
      $canView = true;
    } elseif (!empty($allowed_pcs) && in_array($doc_pc, $allowed_pcs, true)) {
      $canView = true;
    }
  } elseif ($isApprovalRegional) {
    $sessionPc   = trim($_SESSION['profit_center'] ?? $_SESSION['Cabang'] ?? '');
    $docPc       = trim($row['profit_center'] ?? '');
    $docTypeUser = $row['type_user'] ?? '';
    if ($docPc === $sessionPc || stripos($docTypeUser, 'approval sub regional') !== false) {
      $canView = true;
    }
  }

  if ($canView) {
    $file_path_db = $row['file_path'] ?? '';
    $file_name    = !empty($row['file_name']) ? $row['file_name'] : 'dokumen.pdf';
    $file_year    = isset($row['tahun_dokumen']) && !empty($row['tahun_dokumen']) ? intval($row['tahun_dokumen']) : null;

    $candidates = [];

    if (!empty($file_path_db) && (strpos($file_path_db, '/') === 0 || preg_match('/^[A-Za-z]:\\\\/', $file_path_db))) {
        $candidates[] = $file_path_db;
    }

    if (!empty($file_path_db)) {
        $candidates[] = __DIR__ . '/' . $file_path_db;
        $candidates[] = __DIR__ . '/../../' . ltrim($file_path_db, '/\\');
    }

    $candidates[] = __DIR__ . '/../../uploads/dokumen_penghapusan/' . basename($file_path_db ?: $file_name);
    $candidates[] = __DIR__ . '/../../uploads/dokumen_penghapusan/' . basename($file_name);
   
    if ($file_year) {
      $candidates[] = __DIR__ . '/../../uploads/dokumen_penghapusan/' . $file_year . '/' . basename($file_path_db ?: $file_name);
      $candidates[] = __DIR__ . '/../../uploads/dokumen_penghapusan/' . $file_year . '/' . basename($file_name);
    }

    $abs_path_found = false;
    $checked = [];
    foreach ($candidates as $c) {
        $checked[] = $c;
        $real = realpath($c);
        if ($real && file_exists($real)) {
            $abs_path = $real;
            $abs_path_found = true;
            break;
        }
        
        if (file_exists($c)) {
            $abs_path = $c;
            $abs_path_found = true;
            break;
        }
    }

    if ($abs_path_found) {
      header('Content-Type: application/pdf');
      header('Content-Disposition: inline; filename="' . basename($abs_path) . '"');
      header('Content-Length: ' . filesize($abs_path));
      readfile($abs_path);
      exit();
    } else {
      // Coba cari rekursif berdasarkan nama file di folder uploads/dokumen_penghapusan
      $basename = basename($file_path_db ?: $file_name);
      $search_root = realpath(__DIR__ . '/../../uploads/dokumen_penghapusan');
      $found_in_uploads = null;
      if ($search_root && is_dir($search_root)) {
        try {
          $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($search_root));
          foreach ($it as $f) {
            if ($f->isFile() && $f->getFilename() === $basename) {
              $found_in_uploads = $f->getPathname();
              break;
            }
          }
        } catch (UnexpectedValueException $e) {
          // ignore iterator errors
        }
      }

      if ($found_in_uploads) {
        // Serve the file found in uploads (and show a note so admin can update DB path if desired)
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($found_in_uploads) . '"');
        header('Content-Length: ' . filesize($found_in_uploads));
        // echo a small notice about automatic fallback (optional)
        readfile($found_in_uploads);
        exit();
      }

      http_response_code(404);
      echo 'File tidak ada di server. Paths checked:\n';
      foreach ($checked as $p) {
        echo htmlspecialchars($p) . "\n";
      }
      echo '\nDB file_path: ' . htmlspecialchars($file_path_db) . '\nDB file_name: ' . htmlspecialchars($file_name);
      exit();
    }
  } else {
    http_response_code(403);
    echo 'Akses ditolak. Role: ' . htmlspecialchars($typeUser);
    exit();
  }
}

if (!isset($_SESSION["nipp"]) || !isset($_SESSION["name"])) {
    header("Location: ../login/login_view.php");
    exit();
}

$userNipp = $_SESSION['nipp'];
$userType = isset($_SESSION['Type_User']) ? $_SESSION['Type_User'] : '';
$userProfitCenter = isset($_SESSION['Cabang']) ? $_SESSION['Cabang'] : '';

$whereClause = "WHERE up.status = 'submitted'";
if (strpos($userType, 'Approval Sub Regional') !== false || strpos($userType, 'User Entry Sub Regional') !== false) {
    $whereClause .= " AND (up.profit_center = ? OR up.subreg LIKE ?)";
    $isSubRegional = true;
} elseif (strpos($userType, 'Cabang') !== false || strpos($userType, 'User Entry Cabang') !== false) {
    $whereClause .= " AND up.profit_center = ?";
    $isCabang = true;
} else {
    $isRegional = true;
}

$query_daftar = "SELECT up.*,
                        id.keterangan_asset as nama_aset,
                        id.asset_class_name as kategori_aset,
                        id.profit_center_text,
                        id.subreg,
                        (SELECT COUNT(*) FROM dokumen_penghapusan WHERE usulan_id = up.id) as jumlah_dokumen
                 FROM usulan_penghapusan up
                 LEFT JOIN import_dat id ON up.nomor_asset_utama = id.nomor_asset_utama
                 " . $whereClause . "
                 ORDER BY up.status DESC, up.created_at DESC";

$stmt = $con->prepare($query_daftar);

if (isset($isSubRegional)) {
    $subreg_pattern = $userProfitCenter . '%';
    $stmt->bind_param("ss", $userProfitCenter, $subreg_pattern);
} elseif (isset($isCabang)) {
    $stmt->bind_param("s", $userProfitCenter);
}

$stmt->execute();
$result = $stmt->get_result();

$daftar_usulan = [];
while ($row = $result->fetch_assoc()) {
  $row['nama_aset'] = stripAUC($row['nama_aset']);
  $daftar_usulan[] = $row;
}
$stmt->close();

$query_docs = "SELECT dp.id_dokumen, dp.usulan_id, dp.tipe_dokumen, 
                      dp.file_path, YEAR(up.created_at) as tahun_usulan,
                      up.nomor_asset_utama, id.keterangan_asset as nama_aset
               FROM dokumen_penghapusan dp
               JOIN usulan_penghapusan up ON dp.usulan_id = up.id
               LEFT JOIN import_dat id ON up.nomor_asset_utama = id.nomor_asset_utama
               WHERE up.status = 'submitted'";

if (strpos($userType, 'Approval Sub Regional') !== false || strpos($userType, 'User Entry Sub Regional') !== false) {
    $query_docs .= " AND (up.profit_center = ? OR up.subreg LIKE ?)";
} elseif (strpos($userType, 'Cabang') !== false || strpos($userType, 'User Entry Cabang') !== false) {
    $query_docs .= " AND up.profit_center = ?";
}

$query_docs .= " ORDER BY dp.id_dokumen DESC";

$stmt_docs = $con->prepare($query_docs);

if (isset($isSubRegional)) {
    $subreg_pattern = $userProfitCenter . '%';
    $stmt_docs->bind_param("ss", $userProfitCenter, $subreg_pattern);
} elseif (isset($isCabang)) {
    $stmt_docs->bind_param("s", $userProfitCenter);
}

$stmt_docs->execute();
$result_docs = $stmt_docs->get_result();

$daftar_dokumen = [];
while ($row = $result_docs->fetch_assoc()) {
  $row['nama_aset'] = stripAUC($row['nama_aset']);
  $daftar_dokumen[] = $row;
}
$stmt_docs->close();
?>
<!doctype html>
<html lang="en">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
  <title>Daftar Usulan Penghapusan - Web Aset Tetap</title>
  <link rel="icon" type="image/png" href="../../dist/assets/img/emblem.png" />
  <link rel="shortcut icon" type="image/png" href="../../dist/assets/img/emblem.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous" media="print" onload="this.media='all'" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/styles/overlayscrollbars.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="../../dist/css/adminlte.css" />
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/2.3.6/css/dataTables.dataTables.min.css" />

  <style>
    .table-responsive {
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      border: 1px solid #dee2e6;
      border-radius: 0.25rem;
    }
    .table-responsive::-webkit-scrollbar { height: 8px; }
    .table-responsive::-webkit-scrollbar-track { background: #f1f1f1; }
    .table-responsive::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
    .table-responsive::-webkit-scrollbar-thumb:hover { background: #555; }
    #usulanTable thead th, #usulanTable tbody td,
    #dokumenTable thead th, #dokumenTable tbody td {
      padding: 10px 14px;
      white-space: nowrap;
      vertical-align: middle;
    }
    #usulanTable thead th, #dokumenTable thead th {
      background-color: #f8f9fa;
      font-weight: 600;
      font-size: 0.875rem;
      border-bottom: 2px solid #dee2e6;
    }
    #usulanTable tbody tr:hover, #dokumenTable tbody tr:hover {
      background-color: #f5f8ff;
    }
    .cursor-pointer { cursor: pointer; }
    .badge { font-size: 0.82rem; }
    .card-table {
      border: 1px solid #e9ecef;
      border-radius: 10px;
      overflow: hidden;
      box-shadow: 0 1px 4px rgba(0,0,0,.06);
      margin-bottom: 24px;
      background: #fff;
    }
    .card-table-header {
      padding: 14px 20px;
      border-bottom: 1px solid #e9ecef;
      background: #fff;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .card-table-header h5 {
      margin: 0;
      font-size: 0.95rem;
      font-weight: 600;
      color: #1f2937;
    }
    .card-table-body { padding: 16px 20px; }
    .dataTables_wrapper .dataTables_length,
    .dataTables_wrapper .dataTables_filter { margin-bottom: 12px; }
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate { margin-top: 12px; }
    .card-table .table-responsive { border: none !important; border-radius: 0; }

    /* Fix posisi modal AdminLTE */
    .modal { padding-left: 0 !important; }
    .modal-dialog {
      margin: 1.75rem auto !important;
      max-width: 800px;
    }
    .modal-backdrop { z-index: 1040 !important; }
    #modalDetail { z-index: 1050 !important; }
    body.modal-open { overflow: hidden !important; padding-right: 0 !important; }
    .detail-section {
      padding: 18px 24px;
      border-bottom: 1px solid #f0f0f0;
    }
    .detail-section:last-child { border-bottom: none; }
    .detail-section-title {
      font-size: 0.7rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .8px;
      color: #9ca3af;
      margin-bottom: 12px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .detail-section-title i { font-size: 0.8rem; }
    .detail-section-title::after {
      content: '';
      flex: 1;
      height: 1px;
      background: #f0f0f0;
    }
    .detail-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0;
    }
    .detail-item {
      padding: 10px 0;
      border-bottom: 1px solid #f5f5f5;
    }
    .detail-item:nth-last-child(-n+2) { border-bottom: none; }
    .detail-item:nth-child(odd) { padding-right: 20px; border-right: 1px solid #f5f5f5; }
    .detail-item:nth-child(even) { padding-left: 20px; }
    .detail-item-label {
      font-size: 0.72rem;
      color: #9ca3af;
      margin-bottom: 3px;
      font-weight: 500;
    }
    .detail-item-value {
      font-size: 0.9rem;
      font-weight: 600;
      color: #1f2937;
    }
    .detail-item-full {
      padding: 10px 0;
      border-bottom: 1px solid #f5f5f5;
    }
    .detail-item-full:last-child { border-bottom: none; }
    .status-track {
      display: flex;
      align-items: center;
    }
    .status-node {
      flex: 1;
      text-align: center;
      position: relative;
    }
    .status-node::after {
      content: '';
      position: absolute;
      top: 18px;
      left: 60%;
      width: 80%;
      height: 2px;
      background: #e9ecef;
      z-index: 0;
    }
    .status-node:last-child::after { display: none; }
    .status-node-circle {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      margin: 0 auto 6px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.9rem;
      position: relative;
      z-index: 1;
    }
    .status-node-name { font-size: 0.8rem; font-weight: 600; }
    .status-node-sub { font-size: 0.68rem; color: #9ca3af; margin-top: 1px; }
    .kajian-item { margin-bottom: 14px; }
    .kajian-item:last-child { margin-bottom: 0; }
    .kajian-label {
      font-size: 0.72rem;
      font-weight: 600;
      color: #6b7280;
      margin-bottom: 5px;
    }
    .kajian-box {
      background: #f8f9fa;
      border-left: 3px solid #0d6efd;
      border-radius: 0 6px 6px 0;
      padding: 9px 13px;
      font-size: 0.875rem;
      color: #374151;
      white-space: pre-wrap;
      word-break: break-word;
      line-height: 1.6;
    }
    .kajian-box.empty {
      border-left-color: #e5e7eb;
      color: #9ca3af;
      font-style: italic;
    }
    .foto-aset-img {
      max-height: 220px;
      object-fit: contain;
      cursor: pointer;
      border-radius: 8px;
      border: 1px solid #e9ecef;
      transition: box-shadow .2s;
    }
    .foto-aset-img:hover { box-shadow: 0 4px 16px rgba(0,0,0,.12); }
    .badge-pill {
      padding: 3px 11px;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 600;
      display: inline-block;
    }
  </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">

  <!-- Header -->
  <nav class="app-header navbar navbar-expand bg-body">
    <div class="container-fluid">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item">
          <a class="nav-link" data-widget="navbar-search" href="#" role="button">
            <i class="bi bi-search"></i>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="#" data-lte-toggle="fullscreen">
            <i data-lte-icon="maximize" class="bi bi-arrows-fullscreen"></i>
            <i data-lte-icon="minimize" class="bi bi-fullscreen-exit" style="display:none"></i>
          </a>
        </li>
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
              <div class="row ps-3 pe-3 pt-2 pb-2">
                <div class="col-6 text-start">
                  <small class="text-muted">Type User:</small><br>
                  <span class="badge bg-primary"><?php echo htmlspecialchars($_SESSION['Type_User']); ?></span>
                </div>
                <div class="col-6 text-end">
                  <small class="text-muted">Cabang:</small><br>
                  <p class="fw-semibold small mb-0"><?php echo htmlspecialchars($_SESSION['Cabang'] . ' - ' . $_SESSION['profit_center_text']); ?></p>
                </div>
              </div>
              <hr class="m-0"/>
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

  <!-- Sidebar -->
  <aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
    <div class="sidebar-brand">
      <a href="./index.html" class="brand-link">
        <img src="../../dist/assets/img/logo.png" class="brand-image" alt="Logo Pelindo" title="PT Pelabuhan Indonesia" />
      </a>
    </div>
    <div class="sidebar-wrapper">
      <nav class="mt-2">
        <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="navigation" data-accordion="false" id="navigation">
          <?php
          $userNipp2 = isset($_SESSION['nipp']) ? htmlspecialchars($_SESSION['nipp']) : '';
          $query_menu = "SELECT menus.menu, menus.nama_menu, menus.urutan_menu FROM user_access INNER JOIN menus ON user_access.id_menu = menus.id_menu WHERE user_access.NIPP = '" . mysqli_real_escape_string($con, $userNipp2) . "' ORDER BY menus.urutan_menu ASC";
          $result_menu = mysqli_query($con, $query_menu) or die(mysqli_error($con));
          $iconMap = [
              'Dasboard'                  => 'bi bi-grid-fill',
              'Usulan Penghapusan'        => 'bi bi-clipboard-plus-fill',
              'Daftar Usulan Penghapusan' => 'bi bi-list-check',
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
          while ($row = mysqli_fetch_assoc($result_menu)) { $menuRows[] = $row; }
          $hasDaftarUsulan = false; $daftarRow = null;
          foreach ($menuRows as $row) {
              if (trim($row['nama_menu']) === 'Daftar Usulan Penghapusan') { $hasDaftarUsulan = true; $daftarRow = $row; break; }
          }
          $currentPage = basename($_SERVER['PHP_SELF']);
          foreach ($menuRows as $row) {
              $namaMenu = trim($row['nama_menu']);
              if ($namaMenu === 'Daftar Usulan Penghapusan') continue;
              $icon = $iconMap[$namaMenu] ?? 'bi bi-circle';
              $menuFile = $row['menu'].'.php';
              $isActive = ($currentPage === $menuFile) ? 'active' : '';
              if ($namaMenu === 'Manajemen Menu') echo '<li class="nav-header"></li>';
              echo '<li class="nav-item"><a href="../'.$row['menu'].'/'.$row['menu'].'.php" class="nav-link '.$isActive.'"><i class="nav-icon '.$icon.'"></i><p>'.$row['nama_menu'].'</p></a></li>';
              if ($namaMenu === 'Usulan Penghapusan' && $hasDaftarUsulan && $daftarRow) {
                  $daftarIcon = $iconMap['Daftar Usulan Penghapusan'] ?? 'bi bi-circle';
                  $daftarFile = $daftarRow['menu'].'.php';
                  $isDaftarActive = ($currentPage === $daftarFile) ? 'active' : '';
                  echo '<li class="nav-item"><a href="../'.$daftarRow['menu'].'/'.$daftarRow['menu'].'.php" class="nav-link '.$isDaftarActive.'"><i class="nav-icon '.$daftarIcon.'"></i><p>Daftar Usulan Penghapusan</p></a></li>';
              }
          }
          ?>
        </ul>
      </nav>
    </div>
  </aside>

  <!-- Main Content -->
  <main class="app-main">
    <div class="app-content-header">
      <div class="container-fluid">
        <div class="row">
          <div class="col-sm-6"><h3 class="mb-0">Daftar Usulan Penghapusan Aset</h3></div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
              <li class="breadcrumb-item"><a href="../dasbor/dasbor.php">Home</a></li>
              <li class="breadcrumb-item active">Daftar Usulan Penghapusan</li>
            </ol>
          </div>
        </div>
      </div>
    </div>

    <div class="app-content">
      <div class="container-fluid">

        <!-- Info Box -->
        <div class="row mb-3">
          <div class="col-12">
            <div class="alert alert-info mb-0">
              <i class="bi bi-info-circle me-2"></i>
              <strong>Informasi:</strong> Tabel di bawah menampilkan semua usulan penghapusan aset dengan status approval SubReg dan Regional.
            </div>
          </div>
        </div>

        <!-- Tabel Usulan -->
        <div class="row mb-4">
          <div class="col-12">
            <div class="card-table">
              <div class="card-table-header">
                <i class="bi bi-list-check text-primary"></i>
                <h5>Daftar Usulan Penghapusan</h5>
              </div>
              <div class="card-table-body">
                <div class="table-responsive">
                  <table class="table table-hover mb-0" id="usulanTable">
                    <thead class="table-light">
                      <tr>
                        <th style="width:50px;">No</th>
                        <th style="text-align:center;">Status SubReg</th>
                        <th style="text-align:center;">Status Regional</th>
                        <th>Mekanisme Penghapusan</th>
                        <th>Nilai Buku</th>
                        <th>Nomor Aset</th>
                        <th>Nama Aset</th>
                        <th>Subreg</th>
                        <th>Profit Center</th>
                        <th style="width:80px; text-align:center;">Dokumen</th>
                        <th style="width:80px; text-align:center;">Detail</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (!empty($daftar_usulan)): ?>
                        <?php $no = 1; foreach ($daftar_usulan as $usulan): ?>
                        <tr>
                          <td><?= $no++ ?></td>
                          <td style="text-align:center;">
                            <?php
                            switch($usulan['status_approval_subreg']) {
                                case 'approved':
                                    echo '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Approved</span>';
                                    break;
                                case 'rejected':
                                    echo '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Rejected</span>';
                                    break;
                                default:
                                    echo '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Pending</span>';
                            }
                            ?>
                          </td>
                          <td style="text-align:center;">
                            <?php
                            if ($usulan['status_approval_subreg'] === 'approved') {
                                switch($usulan['status_approval_regional']) {
                                    case 'approved':
                                        echo '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Approved</span>';
                                        break;
                                    case 'rejected':
                                        echo '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Rejected</span>';
                                        break;
                                    default:
                                        echo '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Pending</span>';
                                }
                            } else {
                                echo '<span class="text-muted">—</span>';
                            }
                            ?>
                          </td>
                          <td><?= !empty($usulan['mekanisme_penghapusan']) ? htmlspecialchars($usulan['mekanisme_penghapusan']) : '<span class="text-muted">—</span>' ?></td>
                          <td style="font-family:'Courier New',monospace; font-weight:500;">
                            <?php if (!empty($usulan['nilai_buku']) || $usulan['nilai_buku'] === '0' || $usulan['nilai_buku'] === 0): ?>
                              Rp <?= number_format((float)$usulan['nilai_buku'], 0, ',', '.') ?>
                            <?php else: ?>
                              <span class="text-muted">—</span>
                            <?php endif; ?>
                          </td>
                          <td><strong><?= htmlspecialchars($usulan['nomor_asset_utama']) ?></strong></td>
                          <td><?= htmlspecialchars($usulan['nama_aset'] ?? '—') ?></td>
                          <td style="font-size:0.85rem;"><?= htmlspecialchars($usulan['subreg'] ?? '—') ?></td>
                          <td style="font-size:0.85rem;"><?= htmlspecialchars($usulan['profit_center_text'] ?? '') ?></td>
                          <td style="text-align:center;">
                            <span class="badge bg-info"><?= $usulan['jumlah_dokumen'] ?></span>
                          </td>
                          <td style="text-align:center;">
                            <button class="btn btn-sm btn-outline-primary cursor-pointer"
                                    onclick="lihatDetail(<?= htmlspecialchars(json_encode($usulan)) ?>)"
                                    title="Lihat Detail">
                              <i class="bi bi-eye"></i>
                            </button>
                          </td>
                        </tr>
                        <?php endforeach; ?>
                      <?php else: ?>
                      <tr>
                        <td colspan="11" class="text-center text-muted py-5">
                          <i class="bi bi-inbox" style="font-size:2.5rem; opacity:0.3; display:block; margin-bottom:0.75rem;"></i>
                          Belum ada usulan penghapusan
                        </td>
                      </tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div><!-- end card-table-body -->
            </div><!-- end card-table -->
          </div>
        </div>

        <!-- Tabel Dokumen -->
        <div class="row">
          <div class="col-12">
            <div class="card-table">
              <div class="card-table-header">
                <i class="bi bi-file-earmark-pdf text-danger"></i>
                <h5>Daftar Dokumen yang Diupload</h5>
              </div>
              <div class="card-table-body">
                <div class="table-responsive">
                  <table class="table table-hover mb-0" id="dokumenTable">
                    <thead class="table-light">
                      <tr>
                        <th style="width:50px;">No</th>
                        <th>Nomor Aset</th>
                        <th>Nama Aset</th>
                        <th>Tipe Dokumen</th>
                        <th>Tahun</th>
                        <th style="width:100px; text-align:center;">Lihat</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (!empty($daftar_dokumen)): ?>
                        <?php $no = 1; foreach ($daftar_dokumen as $dokumen): ?>
                        <tr>
                          <td><?= $no++ ?></td>
                          <td><strong><?= htmlspecialchars($dokumen['nomor_asset_utama']) ?></strong></td>
                          <td><?= htmlspecialchars($dokumen['nama_aset'] ?? '—') ?></td>
                          <td><?= htmlspecialchars($dokumen['tipe_dokumen'] ?? '—') ?></td>
                          <td><?= htmlspecialchars($dokumen['tahun_usulan'] ?? '—') ?></td>
                          <td style="text-align:center;">
                            <a href="?action=view_doc&id=<?= $dokumen['usulan_id'] ?>"
                               class="btn btn-sm btn-outline-primary"
                               target="_blank"
                               title="Lihat Dokumen">
                              <i class="bi bi-eye"></i>
                            </a>
                          </td>
                        </tr>
                        <?php endforeach; ?>
                      <?php else: ?>
                      <tr>
                        <td colspan="6" class="text-center text-muted py-5">
                          <i class="bi bi-inbox" style="font-size:2.5rem; opacity:0.3; display:block; margin-bottom:0.75rem;"></i>
                          Belum ada dokumen yang diupload
                        </td>
                      </tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div><!-- end card-table-body -->
            </div><!-- end card-table -->
          </div>
        </div>

      </div>
    </div>
  </main>

  <footer class="app-footer">
    <div class="float-end d-none d-sm-inline">PT Pelabuhan Indonesia (Persero)</div>
    <strong>Copyright &copy; Proyek Aset Tetap Regional 3</strong>
  </footer>

</div><!-- end app-wrapper -->


<!-- ============================================================ -->
<!-- MODAL DETAIL                                                  -->
<!-- ============================================================ -->
<div class="modal fade" id="modalDetail" tabindex="-1" style="position:fixed !important;">
  <div class="modal-dialog modal-lg modal-dialog-scrollable" style="margin:1.75rem auto !important;">
    <div class="modal-content border-0 shadow-lg overflow-hidden">

      <div class="modal-header text-white border-0" style="background:linear-gradient(135deg,#0d6efd,#0a58ca);">
        <div>
          <h5 class="modal-title fw-semibold mb-0">
            <i class="bi bi-file-earmark-text me-2"></i>Detail Usulan Penghapusan
          </h5>
          <small class="opacity-75" id="modalSubtitle">—</small>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body p-0" id="modalDetailBody">
        <!-- diisi JS -->
      </div>

      <div class="modal-footer bg-light border-top">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <i class="bi bi-x-circle me-1"></i>Tutup
        </button>
      </div>

    </div>
  </div>
</div>


<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/2.3.6/js/dataTables.min.js"></script>

<script>
  // Pindahkan modal ke body agar tidak terpengaruh CSS AdminLTE
  document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('modalDetail');
    if (modal && modal.parentElement !== document.body) {
      document.body.appendChild(modal);
    }
  });
  document.addEventListener('DOMContentLoaded', function () {
    // Prevent DataTables default alert on column/data mismatch; log instead
    if (typeof $.fn.dataTable !== 'undefined') {
      $.fn.dataTable.ext.errMode = function ( settings, helpPage, message ) {
        console.warn('DataTables warning:', message);
      };
    }

    $('#usulanTable').DataTable({
      ordering: true, searching: true, paging: true, pageLength: 25,
      language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/id.json' },
      scrollX: true
    });
    $('#dokumenTable').DataTable({
      ordering: true, searching: true, paging: true, pageLength: 25,
      language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/id.json' },
      scrollX: true
    });
  });

  // ============================================================
  // FUNGSI MODAL DETAIL — clean & proporsional
  // ============================================================
  function lihatDetail(usulan) {

    function rupiah(val) {
      if (!val && val !== 0) return '—';
      return 'Rp ' + parseInt(val).toLocaleString('id-ID');
    }

    function val(v) {
      return v || '<span style="color:#d1d5db;font-weight:400;">—</span>';
    }

    function item(label, value) {
      return `<div class="detail-item">
        <div class="detail-item-label">${label}</div>
        <div class="detail-item-value">${value}</div>
      </div>`;
    }

    function itemFull(label, value) {
      return `<div class="detail-item-full">
        <div class="detail-item-label">${label}</div>
        <div class="detail-item-value">${value}</div>
      </div>`;
    }

    function statusNode(label, sub, status, locked) {
      let bg, color, icon;
      if (locked)               { bg='#f3f4f6'; color='#9ca3af'; icon='lock-fill'; }
      else if(status==='approved'){ bg='#d1fae5'; color='#059669'; icon='check-lg'; }
      else if(status==='rejected'){ bg='#fee2e2'; color='#dc2626'; icon='x-lg'; }
      else                      { bg='#fef3c7'; color='#d97706'; icon='hourglass-split'; }

      const txt = locked ? 'Menunggu SubReg'
                : status==='approved' ? 'Disetujui'
                : status==='rejected' ? 'Ditolak' : 'Menunggu';
      const txtColor = locked ? '#9ca3af'
                     : status==='approved' ? '#059669'
                     : status==='rejected' ? '#dc2626' : '#d97706';
      return `<div class="status-node">
        <div class="status-node-circle" style="background:${bg};">
          <i class="bi bi-${icon}" style="color:${color};"></i>
        </div>
        <div class="status-node-name" style="color:${txtColor};">${txt}</div>
        <div class="status-node-sub">${label}</div>
        <div class="status-node-sub" style="font-size:0.65rem;">${sub}</div>
      </div>`;
    }

    function kajian(label, value) {
      const isEmpty = !value || value.trim() === '';
      return `<div class="kajian-item">
        <div class="kajian-label">${label}</div>
        <div class="kajian-box${isEmpty ? ' empty' : ''}">${isEmpty ? 'Tidak diisi' : value}</div>
      </div>`;
    }

    // Badge mekanisme
    const mek = usulan.mekanisme_penghapusan === 'Jual Lelang'
      ? `<span class="badge-pill" style="background:#dbeafe;color:#1d4ed8;">Jual Lelang</span>`
      : usulan.mekanisme_penghapusan === 'Hapus Administrasi'
      ? `<span class="badge-pill" style="background:#f3e8ff;color:#7c3aed;">Hapus Administrasi</span>`
      : '—';

    // Badge fisik
    const fisik = usulan.fisik_aset === 'Ada'
      ? `<span class="badge-pill" style="background:#d1fae5;color:#065f46;">Ada</span>`
      : usulan.fisik_aset === 'Tidak Ada'
      ? `<span class="badge-pill" style="background:#fee2e2;color:#991b1b;">Tidak Ada</span>`
      : '—';

    // Foto
    const fotoHtml = usulan.foto_path
      ? `<div class="text-center py-3" style="background:#f8f9fa; border-bottom:1px solid #f0f0f0;">
           <img src="${usulan.foto_path}" class="foto-aset-img img-fluid"
                onclick="window.open('${usulan.foto_path}','_blank')"
                title="Klik untuk perbesar">
           <div class="mt-1" style="font-size:0.72rem;color:#9ca3af;">
             Klik foto untuk memperbesar
           </div>
         </div>`
      : '';

    const isLocked = usulan.status_approval_subreg !== 'approved';

    const html = `
      ${fotoHtml}

      <!-- Identitas Aset -->
      <div class="detail-section">
        <div class="detail-section-title"><i class="bi bi-tag"></i> Identitas Aset</div>
        <div class="detail-grid">
          ${item('Nomor Aset', `<span style="font-family:monospace;color:#2563eb;">${usulan.nomor_asset_utama}</span>`)}
          ${item('Nama Aset', val(usulan.nama_aset))}
          ${item('SubReg', val(usulan.subreg))}
          ${item('Profit Center', val(usulan.profit_center_text || usulan.profit_center))}
        </div>
      </div>

      <!-- Detail Usulan -->
      <div class="detail-section">
        <div class="detail-section-title"><i class="bi bi-clipboard-data"></i> Detail Usulan</div>
        <div class="detail-grid">
          ${item('Nilai Buku', `<span style="font-family:monospace;">${rupiah(usulan.nilai_buku)}</span>`)}
          ${item('Tahun Usulan', val(usulan.tahun_usulan))}
          ${item('Mekanisme Penghapusan', mek)}
          ${item('Fisik Aset', fisik)}
          ${item('Jumlah Dokumen',
            `<span class="badge-pill" style="background:#0ea5e9;color:#fff;">${usulan.jumlah_dokumen} file(s)</span>`)}
        </div>
      </div>

      <!-- Status Persetujuan -->
      <div class="detail-section" style="background:#f8faff;">
        <div class="detail-section-title"><i class="bi bi-shield-check"></i> Status Persetujuan</div>
        <div class="status-track px-4 py-2">
          ${statusNode('Sub Regional', 'Persetujuan ke-1', usulan.status_approval_subreg, false)}
          ${statusNode('Regional', 'Persetujuan ke-2', usulan.status_approval_regional, isLocked)}
        </div>
      </div>

      <!-- Kajian -->
      <div class="detail-section">
        <div class="detail-section-title"><i class="bi bi-journal-text"></i> Kajian & Justifikasi</div>
        ${kajian('Justifikasi & Alasan Penghapusan', usulan.justifikasi_alasan)}
        ${kajian('Kajian Hukum', usulan.kajian_hukum)}
        ${kajian('Kajian Ekonomis', usulan.kajian_ekonomis)}
        ${kajian('Kajian Risiko', usulan.kajian_risiko)}
      </div>
    `;

    document.getElementById('modalSubtitle').textContent = usulan.nama_aset || usulan.nomor_asset_utama;
    document.getElementById('modalDetailBody').innerHTML = html;
    new bootstrap.Modal(document.getElementById('modalDetail')).show();
  }
</script>

<script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/browser/overlayscrollbars.browser.es6.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="../../dist/js/adminlte.js"></script>
</body>
</html>