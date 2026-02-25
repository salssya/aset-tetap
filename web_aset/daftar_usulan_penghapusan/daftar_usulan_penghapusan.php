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

function normalize_str($s) {
  return strtolower(trim((string)$s));
}

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

function normalize_foto_path($p) {
  if (empty($p)) return '';
  $p = trim((string)$p);
  if (preg_match('#^https?://#i', $p)) return $p;
  if (strpos($p, '/') === 0) return $p;

  $p2 = str_replace('\\', '/', $p);

  $docroot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
  if ($docroot !== '') {
    $docroot = str_replace('\\', '/', $docroot);
    $abs = realpath($p2);
    if ($abs) {
      $abs = str_replace('\\', '/', $abs);
      if (strpos($abs, $docroot) === 0) {
        $rel = substr($abs, strlen($docroot));
        if ($rel === '' || $rel === false) return '/';
        return '/' . ltrim($rel, '/');
      }
    }
  }

  if (preg_match('#^(uploads/|\.\./uploads|/uploads)#', $p2)) {
    if (strpos($p2, '/uploads') === 0) return $p2;
    return '../../' . ltrim($p2, '/');
  }

  return $p;
}

if (isset($_GET['action']) && $_GET['action'] === 'view_doc' && (isset($_GET['id']) || isset($_GET['id_dok']))) {
  $nipp_val = (string)($_SESSION['nipp'] ?? '');

  if (isset($_GET['id_dok'])) {
    $id_dok = (int)$_GET['id_dok'];
    $q = "SELECT file_path, file_name, nipp, subreg, profit_center, profit_center_text, type_user 
          FROM dokumen_penghapusan WHERE id_dokumen = $id_dok LIMIT 1";
  } else {
    $id = (int)$_GET['id'];
    $q = "SELECT file_path, file_name, nipp, subreg, profit_center, profit_center_text, type_user 
          FROM dokumen_penghapusan WHERE usulan_id = $id LIMIT 1";
  }
  $res = mysqli_query($con, $q);

  if (!$res || mysqli_num_rows($res) === 0) {
    http_response_code(404);
    echo 'Dokumen tidak ditemukan untuk usulan ID: ' . $id;
    exit();
  }

  $row = mysqli_fetch_assoc($res);

  $isOwner = (trim((string)$row['nipp']) === trim($nipp_val));
  $typeUser = isset($_SESSION['Type_User']) ? (string)$_SESSION['Type_User'] : '';
  $sessionPc = trim($_SESSION['Cabang'] ?? $_SESSION['profit_center'] ?? '');

  $isAdmin             = stripos($typeUser, 'admin') !== false;
  $isApprovalSubreg    = stripos($typeUser, 'Approval Sub Regional') !== false;
  $isApprovalRegional  = stripos($typeUser, 'Approval Regional') !== false;
  $isUserEntryCabang   = stripos($typeUser, 'User Entry Cabang') !== false;
  $isUserEntrySubreg   = stripos($typeUser, 'User Entry Sub Regional') !== false;
  $isUserEntryRegional = stripos($typeUser, 'User Entry Regional') !== false;

  $canView = false;

  if ($isOwner || $isAdmin) {
    $canView = true;

  } elseif ($isApprovalRegional || $isUserEntryRegional) {
    $canView = true;

  } elseif ($isApprovalSubreg || $isUserEntrySubreg) {
    $doc_subreg = normalize_str($row['subreg'] ?? '');
    $session_subreg = '';
    if (!empty($sessionPc)) {
      $q_sr = "SELECT subreg FROM import_dat WHERE profit_center = '" . mysqli_real_escape_string($con, $sessionPc) . "' AND subreg IS NOT NULL AND subreg != '' LIMIT 1";
      $r_sr = mysqli_query($con, $q_sr);
      if ($r_sr && mysqli_num_rows($r_sr) > 0) {
        $session_subreg = normalize_str(mysqli_fetch_assoc($r_sr)['subreg']);
      }
    }
    if ($session_subreg !== '' && $doc_subreg === $session_subreg) {
      $canView = true;
    }

  } elseif ($isUserEntryCabang) {
    $doc_pc = normalize_str($row['profit_center'] ?? '');
    $canView = ($doc_pc === normalize_str($sessionPc));
  }

  if (!$canView) {
    http_response_code(403);
    echo 'Anda tidak memiliki izin untuk melihat dokumen ini.';
    exit();
  }

  $filePathDb = $row['file_path'] ?? '';
  $fileName   = !empty($row['file_name']) ? basename($row['file_name']) : 'dokumen.pdf';
  $forceDownload = isset($_GET['download']) && $_GET['download'] === '1';

  if (strpos($filePathDb, 'data:') === 0 && strpos($filePathDb, ';gzip,') !== false) {
    $commaPos = strrpos($filePathDb, ',');
    if ($commaPos === false) {
      http_response_code(500);
      echo 'Format data dokumen tidak valid.';
      exit();
    }
    $base64Data = substr($filePathDb, $commaPos + 1);
    $compressedData = base64_decode($base64Data);
    if ($compressedData === false) {
      http_response_code(500);
      echo 'Gagal decode data dokumen.';
      exit();
    }
    $fileData = gzdecode($compressedData);
    if ($fileData === false) {
      http_response_code(500);
      echo 'Gagal decompress data dokumen.';
      exit();
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($fileData));
    header('Cache-Control: no-cache, must-revalidate');
    echo $fileData;
    exit();
  }

  if (strpos($filePathDb, 'data:') === 0 && strpos($filePathDb, ';base64,') !== false) {
    $commaPos = strpos($filePathDb, ',');
    $base64Data = substr($filePathDb, $commaPos + 1);
    $fileData = base64_decode($base64Data);
    if ($fileData === false) {
      http_response_code(500);
      echo 'Gagal decode data dokumen.';
      exit();
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($fileData));
    header('Cache-Control: no-cache, must-revalidate');
    echo $fileData;
    exit();
  }

  $uploadBaseDir = realpath(__DIR__ . '/../../uploads/dokumen_penghapusan') ?: (__DIR__ . '/../../uploads/dokumen_penghapusan');
  $absPath = null;

  $try1 = $uploadBaseDir . '/' . basename($fileName);
  if (file_exists($try1)) $absPath = $try1;

  if (!$absPath && !empty($filePathDb)) {
    $try2 = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . ltrim(str_replace('\\', '/', $filePathDb), '/');
    if (file_exists($try2)) $absPath = $try2;
  }

  if (!$absPath && !empty($filePathDb)) {
    $try3 = realpath(__DIR__ . '/' . $filePathDb);
    if ($try3 && file_exists($try3)) $absPath = $try3;
  }

  if (!$absPath && !empty($filePathDb) && file_exists($filePathDb)) $absPath = $filePathDb;

  if (!$absPath || !file_exists($absPath)) {
    http_response_code(404);
    echo 'File dokumen tidak ditemukan di server.';
    exit();
  }

  $mimeType = mime_content_type($absPath) ?: 'application/pdf';
  header('Content-Type: ' . $mimeType);
  header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . $fileName . '"');
  header('Content-Length: ' . filesize($absPath));
  header('Cache-Control: no-cache, must-revalidate');
  readfile($absPath);
  exit();
}
if (!isset($_SESSION["nipp"]) || !isset($_SESSION["name"])) {
    header("Location: ../login/login_view.php");
    exit();
}

$userNipp = $_SESSION['nipp'];
$userType = isset($_SESSION['Type_User']) ? $_SESSION['Type_User'] : '';
$userProfitCenter = isset($_SESSION['Cabang']) ? $_SESSION['Cabang'] : '';

$isSubRegional       = (strpos($userType, 'Approval Sub Regional') !== false || strpos($userType, 'User Entry Sub Regional') !== false);
$isCabang            = (strpos($userType, 'Cabang') !== false || strpos($userType, 'User Entry Cabang') !== false);
$isApprovalRegional  = (strpos($userType, 'Approval Regional') !== false && strpos($userType, 'User Entry') === false);
$isUserEntryRegional = (strpos($userType, 'User Entry Regional') !== false);
$isRegional          = !$isSubRegional && !$isCabang;

$userSubreg = '';
if ($isSubRegional && !empty($userProfitCenter)) {
    $q_sub = $con->prepare("SELECT subreg FROM import_dat WHERE profit_center = ? AND subreg IS NOT NULL AND subreg != '' LIMIT 1");
    $q_sub->bind_param("s", $userProfitCenter);
    $q_sub->execute();
    $r_sub = $q_sub->get_result();
    if ($r_sub && $r_sub->num_rows > 0) {
        $userSubreg = $r_sub->fetch_assoc()['subreg'];
    }
    $q_sub->close();
}

$whereClause = "WHERE up.status NOT IN ('draft', 'lengkapi_dokumen', 'dokumen_lengkap')";
if ($isSubRegional) {
    $whereClause .= " AND up.subreg = ?";
} elseif ($isCabang) {
    $whereClause .= " AND up.profit_center = ?";
} elseif ($isApprovalRegional) {
    $whereClause .= " AND (up.status_approval_subreg != 'rejected' OR up.status_approval_subreg IS NULL)";
} else {
    
}
$query_daftar = "SELECT up.*,
                        id.keterangan_asset as nama_aset,
                        id.asset_class_name as kategori_aset,
                        id.profit_center_text,
                        id.subreg,
                        id.nilai_perolehan_sd,
                        (SELECT COUNT(*) FROM dokumen_penghapusan dp2
                         WHERE dp2.usulan_id = up.id
                            OR dp2.no_aset LIKE CONCAT('%', up.nomor_asset_utama, '%')
                        ) as jumlah_dokumen,
                        u.Nama as created_by_name,
                        (SELECT ah.note FROM approval_history ah
                         WHERE ah.usulan_id = up.id AND ah.action = 'reject_subreg'
                         ORDER BY ah.created_at DESC LIMIT 1
                        ) as alasan_reject_subreg,
                        (SELECT ah.note FROM approval_history ah
                         WHERE ah.usulan_id = up.id AND ah.action = 'reject_regional'
                         ORDER BY ah.created_at DESC LIMIT 1
                        ) as alasan_reject_regional
                 FROM usulan_penghapusan up
                 LEFT JOIN import_dat id ON up.nomor_asset_utama = id.nomor_asset_utama
                 LEFT JOIN users u ON up.created_by = u.NIPP
                 " . $whereClause . "
                 ORDER BY up.status DESC, up.created_at DESC";

$stmt = $con->prepare($query_daftar);

if ($isSubRegional) {
    $stmt->bind_param("s", $userSubreg);
} elseif ($isCabang) {
    $stmt->bind_param("s", $userProfitCenter);
}

$stmt->execute();
$result = $stmt->get_result();

$daftar_usulan = [];
while ($row = $result->fetch_assoc()) {
    $row['nama_aset'] = str_replace('AUC-', '', $row['nama_aset']);
  if (isset($row['foto_path'])) {
    $row['foto_path'] = normalize_foto_path($row['foto_path']);
  } else {
    $row['foto_path'] = '';
  }
    $daftar_usulan[] = $row;
}
$stmt->close();

$query_docs = "SELECT dp.id_dokumen, dp.usulan_id, dp.tipe_dokumen, 
                      dp.file_path, dp.file_name, dp.no_aset,
                      YEAR(up.created_at) as tahun_usulan,
                      up.nomor_asset_utama, id.keterangan_asset as nama_aset
               FROM dokumen_penghapusan dp
               JOIN usulan_penghapusan up ON dp.usulan_id = up.id
               LEFT JOIN import_dat id ON up.nomor_asset_utama = id.nomor_asset_utama
               WHERE up.status NOT IN ('draft', 'lengkapi_dokumen', 'dokumen_lengkap')";

if ($isSubRegional) {
    $query_docs .= " AND up.subreg = ?";
} elseif ($isCabang) {
    $query_docs .= " AND up.profit_center = ?";
} elseif ($isApprovalRegional) {
    $query_docs .= " AND (up.status_approval_subreg != 'rejected' OR up.status_approval_subreg IS NULL)";
} else {
    
}

$query_docs .= " ORDER BY dp.id_dokumen DESC";

$stmt_docs = $con->prepare($query_docs);

if ($isSubRegional) {
    $stmt_docs->bind_param("s", $userSubreg);
} elseif ($isCabang) {
    $stmt_docs->bind_param("s", $userProfitCenter);
}

$stmt_docs->execute();
$result_docs = $stmt_docs->get_result();

$daftar_dokumen = [];
while ($row = $result_docs->fetch_assoc()) {
    $row['nama_aset'] = str_replace('AUC-', '', $row['nama_aset']);
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
  <link rel="stylesheet" href="../../dist/css/index.css"/>
  <link rel="stylesheet" href="../../dist/css/overlayscrollbars.min.css"/>
  <link rel="stylesheet" href="../../dist/css/bootstrap-icons/bootstrap-icons.min.css"/>
  <link rel="stylesheet" href="../../dist/css/adminlte.css" />
  <style>
    .app-header, nav.app-header, .app-header.navbar { border-bottom: 0 !important; box-shadow: none !important; }
    .sidebar-brand { background-color: #0b3a8c !important; margin-bottom: 0 !important; padding: 0.25rem 0 !important; border-bottom: 0 !important; box-shadow: none !important; }
    .sidebar-brand .brand-link { display: block !important; padding: 0.5rem 0.75rem !important; border-bottom: 0 !important; box-shadow: none !important; background-color: transparent !important; }
    .sidebar-brand .brand-link .brand-image { display: block !important; height: auto !important; max-height: 48px !important; margin: 0 !important; padding: 6px 8px !important; background-color: transparent !important; }
    .app-sidebar { border-right: 0 !important; }
  </style>
  <link rel="stylesheet" href="../../dist/css/dataTables.bootstrap5.min.css">
  <link rel="stylesheet" href="../../dist/css/dataTables.dataTables.min.css" />

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
  <nav class="app-header navbar navbar-expand bg-white border-0 shadow-none" style="border-bottom:0!important;box-shadow:none!important;">
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
          $daftarRow       = null;
          $hasUsulanMenu   = false;

          foreach ($menuRows as $row) {
              $nm = trim($row['nama_menu']);
              if ($nm === 'Daftar Usulan Penghapusan') { $hasDaftarUsulan = true; $daftarRow = $row; }
              if ($nm === 'Usulan Penghapusan')         { $hasUsulanMenu = true; }
          }

          $currentPage = basename($_SERVER['PHP_SELF']);

          foreach ($menuRows as $row) {
              $namaMenu = trim($row['nama_menu']);
              if ($namaMenu === 'Daftar Usulan Penghapusan') continue;

              $icon     = $iconMap[$namaMenu] ?? 'bi bi-circle';
              $menuFile = $row['menu'] . '.php';
              $isActive = ($currentPage === $menuFile) ? 'active' : '';

              if ($namaMenu === 'Manajemen Menu') echo '<li class="nav-header"></li>';
              echo '<li class="nav-item"><a href="../' . $row['menu'] . '/' . $row['menu'] . '.php" class="nav-link ' . $isActive . '"><i class="nav-icon ' . $icon . '"></i><p>' . $row['nama_menu'] . '</p></a></li>';

              if ($namaMenu === 'Usulan Penghapusan' && $hasDaftarUsulan && $daftarRow) {
                  $daftarIcon     = $iconMap['Daftar Usulan Penghapusan'] ?? 'bi bi-circle';
                  $daftarFile     = $daftarRow['menu'] . '.php';
                  $isDaftarActive = ($currentPage === $daftarFile) ? 'active' : '';
                  echo '<li class="nav-item"><a href="../' . $daftarRow['menu'] . '/' . $daftarRow['menu'] . '.php" class="nav-link ' . $isDaftarActive . '"><i class="nav-icon ' . $daftarIcon . '"></i><p>Daftar Usulan Penghapusan</p></a></li>';
              }
          }

          if ($hasDaftarUsulan && $daftarRow && !$hasUsulanMenu) {
              $daftarIcon     = $iconMap['Daftar Usulan Penghapusan'] ?? 'bi bi-circle';
              $daftarFile     = $daftarRow['menu'] . '.php';
              $isDaftarActive = ($currentPage === $daftarFile) ? 'active' : '';
              echo '<li class="nav-item"><a href="../' . $daftarRow['menu'] . '/' . $daftarRow['menu'] . '.php" class="nav-link ' . $isDaftarActive . '"><i class="nav-icon ' . $daftarIcon . '"></i><p>Daftar Usulan Penghapusan</p></a></li>';
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
                        <th>Nilai Perolehan</th>
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
                          <td style="font-family:'Courier New',monospace; font-weight:500;">
                            <?php if (isset($usulan['nilai_perolehan_sd']) && $usulan['nilai_perolehan_sd'] !== null && $usulan['nilai_perolehan_sd'] !== ''): ?>
                              Rp <?= number_format((float)$usulan['nilai_perolehan_sd'], 0, ',', '.') ?>
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
                        <td colspan="12" class="text-center text-muted py-5">
                          <i class="bi bi-inbox" style="font-size:2.5rem; opacity:0.3; display:block; margin-bottom:0.75rem;"></i>
                          Belum ada usulan penghapusan
                        </td>
                      </tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
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
                        <th style="width:100px; text-align:center;">Lihat Dokumen</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (!empty($daftar_dokumen)): ?>
                        <?php $no = 1; foreach ($daftar_dokumen as $dokumen): ?>
                        <tr>
                          <td><?= $no++ ?></td>
                          <td>
                            <?php
                            // Tampilkan semua nomor aset (bisa multi dengan ; separator)
                            $no_aset_d = $dokumen['no_aset'] ?? $dokumen['nomor_asset_utama'] ?? '';
                            $no_list_d = array_filter(array_map('trim', explode(';', $no_aset_d)));
                            if (count($no_list_d) > 1): ?>
                              <span class="badge bg-info text-dark mb-1"><?= count($no_list_d) ?> aset</span><br>
                              <?php foreach ($no_list_d as $nm): ?>
                                <small class="d-block"><strong><?= htmlspecialchars($nm) ?></strong></small>
                              <?php endforeach; ?>
                            <?php else: ?>
                              <strong><?= htmlspecialchars($no_aset_d) ?></strong>
                            <?php endif; ?>
                          </td>
                          <td><?= htmlspecialchars($dokumen['nama_aset'] ?? '—') ?></td>
                          <td><?= htmlspecialchars($dokumen['tipe_dokumen'] ?? '—') ?></td>
                          <td><?= htmlspecialchars($dokumen['tahun_usulan'] ?? '—') ?></td>
                          <td style="text-align:center;">
                          <a href="?action=view_doc&id_dok=<?= $dokumen['id_dokumen'] ?>"
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

<!-- LIGHTBOX FOTO -->
<div id="lightboxOverlay" onclick="tutupLightbox()"
     style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.88);
            align-items:center;justify-content:center;cursor:zoom-out;">
  <div style="position:relative;max-width:90vw;max-height:90vh;" onclick="event.stopPropagation()">
    <img id="lightboxImg" src="" alt="Foto Aset"
         style="max-width:90vw;max-height:85vh;object-fit:contain;border-radius:8px;
                box-shadow:0 8px 40px rgba(0,0,0,.6);display:block;">
    <button onclick="tutupLightbox()"
            style="position:absolute;top:-14px;right:-14px;width:32px;height:32px;border-radius:50%;
                   border:none;background:#fff;color:#333;font-size:1rem;cursor:pointer;
                   display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,.3);">
      <i class="bi bi-x-lg"></i>
    </button>
    <div style="text-align:center;margin-top:8px;font-size:0.75rem;color:#ccc;">
      Klik di luar foto atau tombol × untuk menutup
    </div>
  </div>
</div>

<!-- MODAL DETAIL                                                  -->
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

      </div>

      <div class="modal-footer bg-light border-top d-flex align-items-center justify-content-between">
        <div id="modalFooterMeta" style="font-size:0.78rem;color:#6b7280;line-height:1.5;">
          <!-- diisi oleh JS -->
        </div>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <i class="bi bi-x-circle me-1"></i>Tutup
        </button>
      </div>

    </div>
  </div>
</div>


<!-- Scripts -->
<script src="../../dist/js/jquery-3.7.1.min.js"></script>
<script src="../../dist/js/bootstrap.bundle.min.js"></script>
<script src="../../dist/js/dataTables.min.js"></script>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('modalDetail');
    if (modal && modal.parentElement !== document.body) {
      document.body.appendChild(modal);
    }
  });
  document.addEventListener('DOMContentLoaded', function () {
  if ($('#usulanTable tbody tr td[colspan]').length === 0) {
    $('#usulanTable').DataTable({
      ordering: true, searching: true, paging: true, pageLength: 25,
      language: { url: '../../dist/js/i18n/id.json' },
      scrollX: true,
      columnDefs: [{ orderable: false, targets: [1, 2, 10, 11] }]
    });
  }

  if ($('#dokumenTable tbody tr td[colspan]').length === 0) {
    $('#dokumenTable').DataTable({
      ordering: true, searching: true, paging: true, pageLength: 25,
      language: { url: '../../dist/js/i18n/id.json' },
      scrollX: true,
      columnDefs: [{ orderable: false, targets: [5] }]
    });
  }
});

  // Data dokumen untuk cek relasi gabungan aset
  const semuaDokumen = <?= json_encode(array_values($daftar_dokumen)) ?>;

  // Toggle preview inline dokumen di dalam modal
  function togglePreviewDok(previewId, url) {
    const container = document.getElementById(previewId);
    const frame = document.getElementById(previewId + '-frame');
    if (!container) return;

    const isVisible = container.style.display !== 'none';
    if (isVisible) {
      container.style.display = 'none';
      if (frame) frame.src = ''; // reset iframe biar hemat resource
    } else {
      if (frame && url) frame.src = url;
      container.style.display = 'block';
      // Scroll ke preview supaya kelihatan
      setTimeout(function() { container.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }, 150);
    }
  }

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

    function statusNode(label, sub, status, locked, alasanReject) {
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

      const rejectHtml = (!locked && status === 'rejected' && alasanReject)
        ? `<div style="margin-top:6px;font-size:0.75rem;font-weight:700;color:#dc2626;max-width:160px;margin-left:auto;margin-right:auto;line-height:1.4;">
             <i class="bi bi-chat-left-text me-1"></i>${alasanReject}
           </div>`
        : '';

      return `<div class="status-node">
        <div class="status-node-circle" style="background:${bg};">
          <i class="bi bi-${icon}" style="color:${color};"></i>
        </div>
        <div class="status-node-name" style="color:${txtColor};">${txt}</div>
        <div class="status-node-sub">${label}</div>
        <div class="status-node-sub" style="font-size:0.65rem;">${sub}</div>
        ${rejectHtml}
      </div>`;
    }

    function kajian(label, value) {
      const isEmpty = !value || value.trim() === '';
      return `<div class="kajian-item">
        <div class="kajian-label">${label}</div>
        <div class="kajian-box${isEmpty ? ' empty' : ''}">${isEmpty ? 'Tidak diisi' : value}</div>
      </div>`;
    }

    const mek = usulan.mekanisme_penghapusan === 'Jual Lelang'
      ? `<span class="badge-pill" style="background:#dbeafe;color:#1d4ed8;">Jual Lelang</span>`
      : usulan.mekanisme_penghapusan === 'Hapus Administrasi'
      ? `<span class="badge-pill" style="background:#f3e8ff;color:#7c3aed;">Hapus Administrasi</span>`
      : '—';

    const fisik = usulan.fisik_aset === 'Ada'
      ? `<span class="badge-pill" style="background:#d1fae5;color:#065f46;">Ada</span>`
      : usulan.fisik_aset === 'Tidak Ada'
      ? `<span class="badge-pill" style="background:#fee2e2;color:#991b1b;">Tidak Ada</span>`
      : '—';

    const fotoHtml = usulan.foto_path
      ? `<div class="text-center py-3" style="background:#f8f9fa; border-bottom:1px solid #f0f0f0;">
           <img src="${usulan.foto_path}" class="foto-aset-img img-fluid"
                onclick="bukaLightbox('${usulan.foto_path}')"
                title="Klik untuk perbesar">
           <div class="mt-1" style="font-size:0.72rem;color:#9ca3af;">
             Klik foto untuk memperbesar
           </div>
         </div>`
      : '';

    const isLocked = usulan.status_approval_subreg !== 'approved';

    // Cari dokumen yang berkaitan dengan aset ini
    // Prioritas: match usulan_id langsung (paling akurat)
    // Fallback: match no_aset atau nomor_asset_utama (untuk dokumen gabungan multi-aset)
    const usulanId   = String(usulan.id || '');
    const nomorAset  = String(usulan.nomor_asset_utama || '').trim();

    const dokTerkait = semuaDokumen.filter(function(d) {
      // 1. Exact match via usulan_id
      if (usulanId && String(d.usulan_id || '') === usulanId) return true;

      // 2. Match via no_aset (dokumen gabungan yang mencakup nomor aset ini)
      if (nomorAset) {
        const noAsetDok = String(d.no_aset || '').trim();
        if (noAsetDok) {
          return noAsetDok.split(';').map(s => s.trim()).some(n => n === nomorAset);
        }
        // 3. Fallback: match via nomor_asset_utama dari tabel usulan_penghapusan
        const noUtama = String(d.nomor_asset_utama || '').trim();
        if (noUtama === nomorAset) return true;
      }

      return false;
    });

    let dokGabunganHtml = '';
    if (dokTerkait.length > 0) {
      const dokRows = dokTerkait.map(function(d, i) {
        const noAsetList = (d.no_aset || d.nomor_asset_utama || '').split(';').map(s => s.trim()).filter(Boolean);
        const isGabungan = noAsetList.length > 1;

        const asetBadge = '';

        // Keterangan gabungan: tampil info aset-aset yang satu dokumen
        const infoGabungan = isGabungan
          ? `<div style="margin-top:5px;font-size:0.75rem;color:#6b7280;">
               <i class="bi bi-paperclip me-1"></i>Dokumen ini juga mencakup:
               ${noAsetList.filter(n => n !== nomorAset).map(n => `<code style="background:#f1f5f9;color:#475569;padding:1px 6px;border-radius:4px;margin-left:3px;">${n}</code>`).join('')}
             </div>`
          : '';

        const viewUrl = `?action=view_doc&id_dok=${d.id_dokumen}`;
        const previewId = `dok-preview-${d.id_dokumen}`;

        return `<div style="padding:10px 0;${i > 0 ? 'border-top:1px solid #f3f4f6;' : ''}">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;">
            <div style="flex:1;">
              <div style="display:flex;align-items:center;flex-wrap:wrap;gap:4px;">
                <span style="font-weight:600;font-size:0.88rem;">${d.tipe_dokumen || 'Dokumen'}</span>
                ${asetBadge}
                <span style="color:#9ca3af;font-size:0.76rem;">Tahun ${d.tahun_usulan || '—'}</span>
              </div>
              ${infoGabungan}
            </div>
            <div style="display:flex;gap:6px;flex-shrink:0;margin-left:12px;">
              <button onclick="togglePreviewDok('${previewId}','${viewUrl}')"
                      class="btn btn-sm btn-outline-info"
                      style="font-size:0.76rem;padding:2px 9px;"
                      title="Preview Dokumen">
                <i class="bi bi-file-text me-1"></i>
              </button>
              <a href="${viewUrl}" target="_blank"
                 class="btn btn-sm btn-outline-primary"
                 style="font-size:0.76rem;padding:2px 9px;"
                 title="Buka di tab baru">
                <i class="bi bi-box-arrow-up-right me-1"></i>Buka
              </a>
            </div>
          </div>
          <!-- Inline Preview Area -->
          <div id="${previewId}" style="display:none;margin-top:8px;">
            <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#f8fafc;">
              <div style="padding:6px 12px;background:#f1f5f9;border-bottom:1px solid #e2e8f0;font-size:0.75rem;color:#64748b;display:flex;align-items:center;justify-content:space-between;">
                <span><i class="bi bi-file-earmark-pdf text-danger me-1"></i>Preview Dokumen</span>
                <button onclick="togglePreviewDok('${previewId}',null)" 
                        class="btn btn-sm" style="padding:0 4px;font-size:0.7rem;color:#94a3b8;line-height:1;">
                  <i class="bi bi-x-lg"></i> Tutup
                </button>
              </div>
              <iframe id="${previewId}-frame" src="" 
                      style="width:100%;height:480px;border:none;display:block;"
                      title="Preview Dokumen PDF">
              </iframe>
            </div>
          </div>
        </div>`;
      }).join('');

      dokGabunganHtml = `<div class="detail-section">
        <div class="detail-section-title"><i class="bi bi-file-earmark-pdf"></i> Dokumen Terkait</div>
        <div style="padding:0 16px;">${dokRows}</div>
      </div>`;
    }

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
          ${item('Nilai Perolehan', `<span style="font-family:monospace;">${rupiah(usulan.nilai_perolehan_sd)}</span>`)}
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
          ${statusNode('Sub Regional', 'Persetujuan ke-1', usulan.status_approval_subreg, false, usulan.alasan_reject_subreg || '')}
          ${statusNode('Regional', 'Persetujuan ke-2', usulan.status_approval_regional, isLocked, usulan.alasan_reject_regional || '')}
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

      <!-- Dokumen Terkait + Preview -->
      ${dokGabunganHtml}
    `;

    document.getElementById('modalSubtitle').textContent = usulan.nama_aset || usulan.nomor_asset_utama;
    document.getElementById('modalDetailBody').innerHTML = html;

    // Footer meta: created_at & created_by
    const metaEl = document.getElementById('modalFooterMeta');
    if (metaEl) {
      let metaHtml = '';
      if (usulan.created_at) {
        const dt = new Date(usulan.created_at.replace(' ', 'T'));
        const tgl = dt.toLocaleDateString('id-ID', { day:'2-digit', month:'long', year:'numeric' });
        const jam = dt.toLocaleTimeString('id-ID', { hour:'2-digit', minute:'2-digit' });
        metaHtml += `<span><i class="bi bi-clock me-1"></i><strong>Dibuat:</strong> ${tgl}, ${jam}</span>`;
      }
      if (usulan.created_by || usulan.created_by_name) {
        const byNipp = usulan.created_by || '';
        const byName = usulan.created_by_name || '';
        const byLabel = byName ? `${byName} (${byNipp})` : byNipp;
        metaHtml += `<span class="ms-3"><i class="bi bi-person me-1"></i><strong>Oleh:</strong> ${byLabel}</span>`;
      }
      metaEl.innerHTML = metaHtml || '';
    }

    new bootstrap.Modal(document.getElementById('modalDetail')).show();
  }
</script>

<script src="../../dist/js/overlayscrollbars.browser.es6.min.js"></script>
<script src="../../dist/js/popper.min.js"></script>
<script src="../../dist/js/adminlte.js"></script>

<script>
  function bukaLightbox(src) {
    if (!src) return;
    const overlay = document.getElementById('lightboxOverlay');
    const img     = document.getElementById('lightboxImg');
    img.src = src;
    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }
  function tutupLightbox() {
    const overlay = document.getElementById('lightboxOverlay');
    overlay.style.display = 'none';
    document.getElementById('lightboxImg').src = '';
    document.body.style.overflow = '';
  }
  // Tutup lightbox dengan tombol Escape
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') tutupLightbox();
  });
</script>
</body>
</html>