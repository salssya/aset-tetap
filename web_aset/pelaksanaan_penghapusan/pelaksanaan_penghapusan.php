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

$userType = isset($_SESSION['Type_User']) ? $_SESSION['Type_User'] : '';
$userNipp = $_SESSION['nipp'];

// Semua role bisa akses, tapi hanya HO/Regional/Admin yang bisa edit
$canEdit = (
    stripos($userType, 'Regional') !== false ||
    stripos($userType, 'HO')       !== false ||
    stripos($userType, 'admin')    !== false
) && stripos($userType, 'Sub Regional') === false
  && stripos($userType, 'Cabang')       === false;

function serveFileFromDb($filePathDb, $fileName, $forceDownload = false) {
    $fileName = !empty($fileName) ? basename($fileName) : 'dokumen.pdf';
    if (strpos($filePathDb, 'data:') === 0 && strpos($filePathDb, ';gzip,') !== false) {
        $fileData = gzdecode(base64_decode(substr($filePathDb, strrpos($filePathDb, ',') + 1)));
    } elseif (strpos($filePathDb, 'data:') === 0 && strpos($filePathDb, ';base64,') !== false) {
        $fileData = base64_decode(substr($filePathDb, strpos($filePathDb, ',') + 1));
    } else { http_response_code(404); echo 'Format file tidak dikenali.'; exit(); }
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($fileData));
    header('Cache-Control: no-cache');
    echo $fileData; exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'view_dok_usulan' && isset($_GET['id_dok'])) {
    $id_dok = (int)$_GET['id_dok'];
    $res = mysqli_query($con, "SELECT file_path, file_name FROM dokumen_penghapusan WHERE id_dokumen = $id_dok LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) { http_response_code(404); echo 'Dokumen tidak ditemukan.'; exit(); }
    $row = mysqli_fetch_assoc($res);
    serveFileFromDb($row['file_path'], $row['file_name'], isset($_GET['download']));
}

if (isset($_GET['action']) && $_GET['action'] === 'view_dok_ho' && isset($_GET['id_dok'])) {
    $id_dok = (int)$_GET['id_dok'];
    $res = mysqli_query($con, "SELECT lokasi_file, file_name FROM dokumen_pelaksanaan WHERE id_dokumen = $id_dok LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) { http_response_code(404); echo 'Dokumen tidak ditemukan.'; exit(); }
    $row = mysqli_fetch_assoc($res);
    serveFileFromDb($row['lokasi_file'], $row['file_name'], isset($_GET['download']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_pelaksanaan' && $canEdit) {
    $id_pel              = (int)$_POST['id_pelaksanaan'];
    $status_pelaksanaan  = trim($_POST['status_pelaksanaan'] ?? '');
    $tgl_appraisal       = !empty($_POST['tanggal_appraisal'])         ? $_POST['tanggal_appraisal']  : null;
    $tgl_penjualan       = !empty($_POST['tanggal_penjualan'])         ? $_POST['tanggal_penjualan']  : null;
    $nilai_buku_bb       = !empty($_POST['nilai_buku_bulan_berjalan']) ? (float)str_replace(['.', ','], ['', '.'], $_POST['nilai_buku_bulan_berjalan'])   : null;
    $nilai_app_pasar     = !empty($_POST['nilai_appraisal_pasar'])     ? (float)str_replace(['.', ','], ['', '.'], $_POST['nilai_appraisal_pasar'])       : null;
    $nilai_app_likuidasi = !empty($_POST['nilai_appraisal_likuidasi']) ? (float)str_replace(['.', ','], ['', '.'], $_POST['nilai_appraisal_likuidasi'])   : null;
    $nilai_penjualan     = !empty($_POST['nilai_penjualan'])           ? (float)str_replace(['.', ','], ['', '.'], $_POST['nilai_penjualan'])             : null;
    $biaya_lainnya       = !empty($_POST['biaya_lainnya'])             ? (float)str_replace(['.', ','], ['', '.'], $_POST['biaya_lainnya'])               : null;
    $nomor_aset_pengganti = trim($_POST['nomor_aset_pengganti'] ?? '');

    $stmt = $con->prepare("UPDATE pelaksanaan_penghapusan SET
        status_pelaksanaan = ?, tanggal_appraisal = ?, tanggal_penjualan = ?,
        nilai_buku_bulan_berjalan = ?, nilai_appraisal_pasar = ?, nilai_appraisal_likuidasi = ?,
        nilai_penjualan = ?, biaya_lainnya = ?, nomor_aset_pengganti = ?,
        nipp = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("ssddddddssi", $status_pelaksanaan, $tgl_appraisal, $tgl_penjualan,
        $nilai_buku_bb, $nilai_app_pasar, $nilai_app_likuidasi, $nilai_penjualan,
        $biaya_lainnya, $nomor_aset_pengganti, $userNipp, $id_pel);

    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Data pelaksanaan berhasil diperbarui.";
    } else {
        $_SESSION['warning_message'] = "Gagal memperbarui data: " . $stmt->error;
    }
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF'] . "?tahun=" . date('Y')); exit();
}

$filterTahun  = isset($_GET['tahun'])  && !empty($_GET['tahun'])  ? (int)$_GET['tahun']  : date('Y');
$filterStatus = isset($_GET['status']) ? $_GET['status'] : 'all';

$whereStatus = '';
if ($filterStatus !== 'all') {
    $fs = mysqli_real_escape_string($con, $filterStatus);
    $whereStatus = " AND pp.status_pelaksanaan = '$fs'";
}

$res_main = mysqli_query($con, "SELECT pp.*,
    up.nomor_asset_utama, up.mekanisme_penghapusan, up.fisik_aset,
    up.justifikasi_alasan, up.status_approval_ho, up.catatan_ho,
    up.tanggal_approval_ho, up.tahun_usulan,
    id.keterangan_asset as nama_aset, id.asset_class_name as kategori_aset,
    id.profit_center_text, id.subreg, id.nilai_perolehan_sd,
    id.nilai_buku_sd as nilai_buku_awal, id.tgl_perolehan,
    id.masa_manfaat as umur_ekonomis, id.sisa_manfaat as sisa_umur_ekonomis,
    (SELECT COUNT(*) FROM dokumen_pelaksanaan dp WHERE dp.id_pelaksanaan = pp.id) as jml_dok_ho,
    (SELECT COUNT(*) FROM dokumen_penghapusan dp2 WHERE dp2.usulan_id = up.id) as jml_dok_usulan
    FROM pelaksanaan_penghapusan pp
    JOIN usulan_penghapusan up ON pp.usulan_id = up.id
    LEFT JOIN import_dat id ON up.nomor_asset_utama = id.nomor_asset_utama
    WHERE YEAR(pp.tanggal_persetujuan) = $filterTahun $whereStatus
    ORDER BY pp.created_at DESC");

$data_pelaksanaan = [];
while ($r = mysqli_fetch_assoc($res_main)) {
    $r['nama_aset'] = str_replace('AUC-', '', $r['nama_aset'] ?? '');
    $data_pelaksanaan[] = $r;
}

$daftar_dok_ho = [];
$res_dho = mysqli_query($con, "SELECT dp.*, pp.usulan_id FROM dokumen_pelaksanaan dp JOIN pelaksanaan_penghapusan pp ON dp.id_pelaksanaan = pp.id ORDER BY dp.id_dokumen DESC");
while ($r = mysqli_fetch_assoc($res_dho)) $daftar_dok_ho[] = $r;

$daftar_dok_usulan = [];
$res_du = mysqli_query($con, "SELECT dp.id_dokumen, dp.usulan_id, dp.tipe_dokumen, dp.file_name FROM dokumen_penghapusan dp JOIN usulan_penghapusan up ON dp.usulan_id = up.id JOIN pelaksanaan_penghapusan pp ON pp.usulan_id = up.id ORDER BY dp.id_dokumen DESC");
while ($r = mysqli_fetch_assoc($res_du)) $daftar_dok_usulan[] = $r;

$cnt_disetujui = $cnt_appraisal = $cnt_lelang = $cnt_terjual = 0;
foreach ($data_pelaksanaan as $d) {
    if ($d['status_pelaksanaan'] === 'Disetujui')        $cnt_disetujui++;
    elseif ($d['status_pelaksanaan'] === 'Appraisal Aset') $cnt_appraisal++;
    elseif ($d['status_pelaksanaan'] === 'Proses Lelang')  $cnt_lelang++;
    elseif ($d['status_pelaksanaan'] === 'Terjual')        $cnt_terjual++;
}

$list_tahun = [];
$res_thn = mysqli_query($con, "SELECT DISTINCT YEAR(tanggal_persetujuan) as t FROM pelaksanaan_penghapusan WHERE tanggal_persetujuan IS NOT NULL ORDER BY t DESC");
while ($r = mysqli_fetch_assoc($res_thn)) if ($r['t']) $list_tahun[] = $r['t'];
if (!in_array(date('Y'), $list_tahun)) array_unshift($list_tahun, (int)date('Y'));

$success_msg = $_SESSION['success_message'] ?? '';
$warning_msg = $_SESSION['warning_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['warning_message']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
  <title>Pelaksanaan Penghapusan - Web Aset Tetap</title>
  <link rel="icon" type="image/png" href="../../dist/assets/img/emblem.png"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="../../dist/css/index.css"/>
  <link rel="stylesheet" href="../../dist/css/overlayscrollbars.min.css"/>
  <link rel="stylesheet" href="../../dist/css/bootstrap-icons/bootstrap-icons.min.css"/>
  <link rel="stylesheet" href="../../dist/css/adminlte.css"/>
  <link rel="stylesheet" href="../../dist/css/dataTables.bootstrap5.min.css">
  <link rel="stylesheet" href="../../dist/css/dataTables.dataTables.min.css"/>
  <style>
    .app-header,nav.app-header,.app-header.navbar{border-bottom:0!important;box-shadow:none!important;}
    .app-sidebar{background-color:#0b3a8c!important;border-right:0!important;}
    .sidebar-brand{background-color:#0b3a8c!important;margin-bottom:0!important;padding:.25rem 0!important;border-bottom:0!important;}
    .sidebar-brand .brand-link{display:block!important;padding:.5rem .75rem!important;border-bottom:0!important;background-color:transparent!important;}
    .sidebar-brand .brand-link .brand-image{display:block!important;height:auto!important;max-height:48px!important;margin:0!important;padding:6px 8px!important;}
    .app-sidebar,.app-sidebar a,.app-sidebar .nav-link,.app-sidebar .nav-link p,.app-sidebar .nav-header,.app-sidebar .brand-text,.app-sidebar .nav-icon{color:#fff!important;fill:#fff!important;}
    .app-sidebar .nav-link.active,.app-sidebar .nav-link:hover{background-color:#0b5db7!important;color:#fff!important;}
    .sum-card{border-radius:12px;padding:16px 20px;color:#fff;display:flex;align-items:center;justify-content:space-between;}
    .sum-card .num{font-size:1.9rem;font-weight:700;line-height:1;}
    .sum-card .lbl{font-size:.78rem;opacity:.85;margin-top:3px;}
    .sum-card .ico{font-size:2.6rem;opacity:.22;}
    .card-table{border:1px solid #e9ecef;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-bottom:20px;background:#fff;}
    .card-table-header{padding:14px 20px;border-bottom:1px solid #e9ecef;background:#fff;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
    .card-table-header h5{margin:0;font-size:.95rem;font-weight:600;color:#1f2937;}
    .card-table-body{padding:16px 20px;}
    .table-responsive{overflow-x:auto;-webkit-overflow-scrolling:touch;}
    #pelaksanaanTable thead th,#pelaksanaanTable tbody td{padding:9px 13px;white-space:nowrap;vertical-align:middle;}
    #pelaksanaanTable thead th{background:#f8f9fa;font-weight:600;font-size:.875rem;border-bottom:2px solid #dee2e6;}
    #pelaksanaanTable tbody tr:hover{background:#f5f8ff;}
    .modal{padding-left:0!important;} body.modal-open{overflow:hidden!important;padding-right:0!important;}
    .detail-section{padding:16px 22px;border-bottom:1px solid #f0f0f0;}
    .detail-section:last-child{border-bottom:none;}
    .detail-section-title{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#9ca3af;margin-bottom:10px;display:flex;align-items:center;gap:6px;}
    .detail-section-title::after{content:'';flex:1;height:1px;background:#f0f0f0;}
    .detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;}
    .detail-item{padding:9px 0;border-bottom:1px solid #f5f5f5;}
    .detail-item:nth-last-child(-n+2){border-bottom:none;}
    .detail-item:nth-child(odd){padding-right:18px;border-right:1px solid #f5f5f5;}
    .detail-item:nth-child(even){padding-left:18px;}
    .detail-item-label{font-size:.72rem;color:#9ca3af;margin-bottom:2px;font-weight:500;}
    .detail-item-value{font-size:.88rem;font-weight:600;color:#1f2937;}
    .badge-pill{padding:3px 11px;border-radius:20px;font-size:.8rem;font-weight:600;display:inline-block;}
    .st-disetujui{background:#dbeafe;color:#1d4ed8;}
    .st-appraisal{background:#fef3c7;color:#92400e;}
    .st-lelang{background:#ede9fe;color:#6d28d9;}
    .st-terjual{background:#d1fae5;color:#065f46;}
    .st-ditolak{background:#fee2e2;color:#991b1b;}
    .nilai-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:6px;}
    .nilai-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;}
    .nilai-box-label{font-size:.7rem;color:#9ca3af;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;}
    .nilai-box-value{font-size:.95rem;font-weight:700;color:#1f2937;font-family:monospace;}
    .nilai-box-value.highlight{color:#059669;}
    .status-track{display:flex;align-items:center;padding:12px 0;}
    .st-node{flex:1;text-align:center;position:relative;}
    .st-node::after{content:'';position:absolute;top:16px;left:60%;width:80%;height:2px;background:#e9ecef;z-index:0;}
    .st-node:last-child::after{display:none;}
    .st-circle{width:32px;height:32px;border-radius:50%;margin:0 auto 5px;display:flex;align-items:center;justify-content:center;font-size:.85rem;position:relative;z-index:1;}
    .st-name{font-size:.75rem;font-weight:600;}
  </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">

  <nav class="app-header navbar navbar-expand bg-white border-0 shadow-none">
    <div class="container-fluid">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item"><a class="nav-link" href="#" data-lte-toggle="fullscreen"><i data-lte-icon="maximize" class="bi bi-arrows-fullscreen"></i><i data-lte-icon="minimize" class="bi bi-fullscreen-exit" style="display:none"></i></a></li>
        <li class="nav-item dropdown user-menu">
          <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
            <img src="../../dist/assets/img/profile.png" class="user-image rounded-circle shadow" alt="User"/>
            <span class="d-none d-md-inline"><?= htmlspecialchars($_SESSION['name']) ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
            <li class="user-header text-bg-primary text-center">
              <img src="../../dist/assets/img/profile.png" class="rounded-circle shadow mb-2" style="width:80px;height:80px;">
              <p class="mb-0 fw-bold"><?= htmlspecialchars($_SESSION['name']) ?></p>
              <small>NIPP: <?= htmlspecialchars($_SESSION['nipp']) ?></small>
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

  <aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
    <div class="sidebar-brand">
      <a href="../dasbor/dasbor.php" class="brand-link">
        <img src="../../dist/assets/img/logo.png" class="brand-image" alt="Logo"/>
      </a>
    </div>
    <div class="sidebar-wrapper">
      <nav class="mt-2">
        <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="navigation" data-accordion="false">
          <?php
          $un2 = htmlspecialchars($userNipp);
          $rm  = mysqli_query($con, "SELECT menus.menu, menus.nama_menu FROM user_access
              INNER JOIN menus ON user_access.id_menu = menus.id_menu
              WHERE user_access.NIPP = '" . mysqli_real_escape_string($con, $un2) . "'
              ORDER BY menus.urutan_menu ASC");
          $iMap = ['Dasboard'=>'bi bi-grid-fill','Usulan Penghapusan'=>'bi bi-clipboard-plus',
              'Daftar Usulan Penghapusan'=>'bi bi-clipboard-check-fill','Approval SubReg'=>'bi bi-check-circle',
              'Approval Regional'=>'bi bi-check2-square','Persetujuan Penghapusan'=>'bi bi-patch-check-fill',
              'Daftar Persetujuan Penghapusan'=>'bi bi-journal-check','Pelaksanaan Penghapusan'=>'bi bi-tools',
              'Daftar Pelaksanaan Penghapusan'=>'bi bi-archive-fill','Manajemen Menu'=>'bi bi-list-ul',
              'Import DAT'=>'bi bi-file-earmark-arrow-up-fill','Daftar Aset Tetap'=>'bi bi-card-list',
              'Manajemen User'=>'bi bi-people-fill'];
          $cp = basename($_SERVER['PHP_SELF']);
          while ($row = mysqli_fetch_assoc($rm)) {
              $nm = trim($row['nama_menu']);
              $ic = $iMap[$nm] ?? 'bi bi-circle';
              $ac = ($cp === $row['menu'].'.php') ? 'active' : '';
              if ($nm === 'Manajemen Menu') echo '<li class="nav-header"></li>';
              echo '<li class="nav-item"><a href="../'.$row['menu'].'/'.$row['menu'].'.php" class="nav-link '.$ac.'"><i class="nav-icon '.$ic.'"></i><p>'.htmlspecialchars($nm).'</p></a></li>';
          }
          ?>
        </ul>
      </nav>
    </div>
  </aside>

  <main class="app-main">
    <div class="app-content-header py-3 px-4">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
          <h4 class="mb-0 fw-bold" style="color:#1f2937;"><i class="bi bi-tools me-2" style="color:#0b3a8c;"></i>Pelaksanaan Penghapusan</h4>
          <small class="text-muted">Realisasi pelaksanaan penghapusan aset yang telah disetujui HO</small>
        </div>
        <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
          <select name="tahun" class="form-select form-select-sm" style="width:100px;" onchange="this.form.submit()">
            <?php foreach ($list_tahun as $t): ?>
              <option value="<?= $t ?>" <?= $t==$filterTahun?'selected':'' ?>><?= $t ?></option>
            <?php endforeach; ?>
          </select>
          <select name="status" class="form-select form-select-sm" style="width:160px;" onchange="this.form.submit()">
            <option value="all"           <?= $filterStatus==='all'?'selected':'' ?>>Semua Status</option>
            <option value="Disetujui"     <?= $filterStatus==='Disetujui'?'selected':'' ?>>Disetujui</option>
            <option value="Appraisal Aset" <?= $filterStatus==='Appraisal Aset'?'selected':'' ?>>Appraisal Aset</option>
            <option value="Proses Lelang"  <?= $filterStatus==='Proses Lelang'?'selected':'' ?>>Proses Lelang</option>
            <option value="Terjual"       <?= $filterStatus==='Terjual'?'selected':'' ?>>Terjual</option>
          </select>
          <a href="?" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-counterclockwise"></i></a>
        </form>
      </div>
    </div>

    <div class="app-content px-4 pb-4">

      <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success_msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
      <?php endif; ?>
      <?php if ($warning_msg): ?>
        <div class="alert alert-warning alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($warning_msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
      <?php endif; ?>

      <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
          <div class="sum-card" style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);">
            <div><div class="num"><?= $cnt_disetujui ?></div><div class="lbl">Disetujui</div></div>
            <i class="bi bi-check-circle ico"></i>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="sum-card" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
            <div><div class="num"><?= $cnt_appraisal ?></div><div class="lbl">Appraisal Aset</div></div>
            <i class="bi bi-calculator ico"></i>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="sum-card" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);">
            <div><div class="num"><?= $cnt_lelang ?></div><div class="lbl">Proses Lelang</div></div>
            <i class="bi bi-hammer ico"></i>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="sum-card" style="background:linear-gradient(135deg,#10b981,#059669);">
            <div><div class="num"><?= $cnt_terjual ?></div><div class="lbl">Terjual</div></div>
            <i class="bi bi-bag-check ico"></i>
          </div>
        </div>
      </div>

      <div class="card-table">
        <div class="card-table-header">
          <i class="bi bi-tools text-primary"></i>
          <h5>Daftar Pelaksanaan Penghapusan &mdash; Tahun <?= $filterTahun ?></h5>
          <span class="badge bg-primary ms-auto"><?= count($data_pelaksanaan) ?> Data</span>
        </div>
        <div class="card-table-body">
          <?php if (empty($data_pelaksanaan)): ?>
            <div class="text-center text-muted py-5">
              <i class="bi bi-inbox" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:.75rem;"></i>
              Belum ada data pelaksanaan untuk tahun <?= $filterTahun ?>.
            </div>
          <?php else: ?>
          <div class="table-responsive">
            <table id="pelaksanaanTable" class="table table-bordered table-hover align-middle w-100">
              <thead>
                <tr>
                  <th>No</th><th>Nomor Aset</th><th>Nama Aset</th><th>SubReg</th>
                  <th>Profit Center</th><th>Mekanisme</th><th>Tgl. Persetujuan</th>
                  <th>Status</th><th>Nilai Appraisal Pasar</th><th>Nilai Penjualan</th>
                  <th>Dokumen HO</th><th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($data_pelaksanaan as $i => $p):
                  $stMap = ['Disetujui'=>['st-disetujui','bi-check-circle'],'Appraisal Aset'=>['st-appraisal','bi-calculator'],'Proses Lelang'=>['st-lelang','bi-hammer'],'Terjual'=>['st-terjual','bi-bag-check'],'Ditolak'=>['st-ditolak','bi-x-circle']];
                  [$stClass,$stIcon] = $stMap[$p['status_pelaksanaan']] ?? ['st-disetujui','bi-circle'];
                ?>
                <tr>
                  <td class="text-center"><?= $i+1 ?></td>
                  <td><code style="color:#2563eb;"><?= htmlspecialchars($p['nomor_asset_utama']) ?></code></td>
                  <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($p['nama_aset']??'-') ?></td>
                  <td><?= htmlspecialchars($p['subreg']??'-') ?></td>
                  <td style="font-size:.82rem;"><?= htmlspecialchars($p['profit_center_text']??$p['profit_center']??'-') ?></td>
                  <td>
                    <?php if ($p['mekanisme_penghapusan']==='Jual Lelang'): ?>
                      <span class="badge-pill" style="background:#dbeafe;color:#1d4ed8;">Jual Lelang</span>
                    <?php elseif ($p['mekanisme_penghapusan']==='Hapus Administrasi'): ?>
                      <span class="badge-pill" style="background:#f3e8ff;color:#7c3aed;">Hapus Adm.</span>
                    <?php else: ?>&#8212;<?php endif; ?>
                  </td>
                  <td><?= $p['tanggal_persetujuan'] ?? '-' ?></td>
                  <td><span class="badge-pill <?= $stClass ?>"><i class="bi <?= $stIcon ?> me-1"></i><?= $p['status_pelaksanaan'] ?></span></td>
                  <td style="font-family:monospace;font-size:.82rem;"><?= $p['nilai_appraisal_pasar'] ? 'Rp '.number_format($p['nilai_appraisal_pasar'],0,',','.') : '&#8212;' ?></td>
                  <td style="font-family:monospace;font-size:.82rem;"><?= $p['nilai_penjualan'] ? 'Rp '.number_format($p['nilai_penjualan'],0,',','.') : '&#8212;' ?></td>
                  <td class="text-center"><span class="badge bg-<?= (int)$p['jml_dok_ho']>0?'success':'secondary' ?>"><?= (int)$p['jml_dok_ho'] ?></span></td>
                  <td>
                    <button class="btn btn-sm btn-outline-primary" onclick="openDetail(<?= $p['id'] ?>)" title="Detail"><i class="bi bi-eye"></i></button>
                    <?php if ($canEdit): ?>
                    <button class="btn btn-sm btn-outline-warning ms-1" onclick="openEdit(<?= $p['id'] ?>)" title="Edit"><i class="bi bi-pencil"></i></button>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>
</div>

<!-- MODAL DETAIL -->
<div class="modal fade" id="modalDetail" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#0b3a8c,#1d6ed8);color:#fff;">
        <div><h5 class="modal-title mb-0"><i class="bi bi-tools me-2"></i>Detail Pelaksanaan</h5><small id="modalSubtitle" style="opacity:.8;font-size:.8rem;"></small></div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0" id="modalDetailBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
        <?php if ($canEdit): ?><button type="button" class="btn btn-warning btn-sm" id="btnEditFromDetail"><i class="bi bi-pencil me-1"></i>Edit Data</button><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- MODAL EDIT -->
<div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header" style="background:#0b3a8c;color:#fff;">
        <div><h5 class="modal-title mb-0"><i class="bi bi-pencil me-2"></i>Edit Data Pelaksanaan</h5><small id="editSubtitle" style="opacity:.8;font-size:.8rem;"></small></div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="update_pelaksanaan">
        <input type="hidden" name="id_pelaksanaan" id="edit_id">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Status Pelaksanaan <span class="text-danger">*</span></label>
            <select name="status_pelaksanaan" id="edit_status" class="form-select" required>
              <option value="Disetujui">Disetujui</option>
              <option value="Appraisal Aset">Appraisal Aset</option>
              <option value="Proses Lelang">Proses Lelang</option>
              <option value="Terjual">Terjual</option>
            </select>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Tanggal Appraisal</label>
              <input type="date" name="tanggal_appraisal" id="edit_tgl_appraisal" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Tanggal Penjualan</label>
              <input type="date" name="tanggal_penjualan" id="edit_tgl_penjualan" class="form-control">
            </div>
          </div>
          <hr class="my-3">
          <p class="fw-semibold text-muted small mb-2"><i class="bi bi-currency-dollar me-1"></i>DATA NILAI</p>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Nilai Buku Bulan Berjalan</label>
              <div class="input-group"><span class="input-group-text">Rp</span><input type="text" name="nilai_buku_bulan_berjalan" id="edit_nb_bb" class="form-control" placeholder="0"></div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Nilai Appraisal Pasar</label>
              <div class="input-group"><span class="input-group-text">Rp</span><input type="text" name="nilai_appraisal_pasar" id="edit_app_pasar" class="form-control" placeholder="0"></div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Nilai Appraisal Likuidasi</label>
              <div class="input-group"><span class="input-group-text">Rp</span><input type="text" name="nilai_appraisal_likuidasi" id="edit_app_likuidasi" class="form-control" placeholder="0"></div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Nilai Penjualan</label>
              <div class="input-group"><span class="input-group-text">Rp</span><input type="text" name="nilai_penjualan" id="edit_nilai_jual" class="form-control" placeholder="0"></div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Biaya Lainnya</label>
              <div class="input-group"><span class="input-group-text">Rp</span><input type="text" name="biaya_lainnya" id="edit_biaya" class="form-control" placeholder="0"></div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Nomor Aset Pengganti</label>
              <input type="text" name="nomor_aset_pengganti" id="edit_aset_pengganti" class="form-control" placeholder="Isi jika ada aset pengganti">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Simpan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="../../dist/js/jquery.min.js"></script>
<script src="../../dist/js/bootstrap.bundle.min.js"></script>
<script src="../../dist/js/dataTables.min.js"></script>
<script src="../../dist/js/dataTables.bootstrap5.min.js"></script>
<script>
const dataPelaksanaan = <?= json_encode($data_pelaksanaan) ?>;
const dataDokHo       = <?= json_encode($daftar_dok_ho) ?>;
const dataDokUsulan   = <?= json_encode($daftar_dok_usulan) ?>;

$(document).ready(function() {
  if ($('#pelaksanaanTable tbody tr').length) {
    $('#pelaksanaanTable').DataTable({
      language:{search:"Cari:",lengthMenu:"Tampilkan _MENU_ data",info:"_START_-_END_ dari _TOTAL_ data",
        paginate:{first:"&laquo;",previous:"&lsaquo;",next:"&rsaquo;",last:"&raquo;"},zeroRecords:"Tidak ada data"},
      pageLength:25, columnDefs:[{orderable:false,targets:[11]}]
    });
  }
});

const rupiah = n => (n !== null && n !== '' && n !== undefined) ? 'Rp ' + parseFloat(n).toLocaleString('id-ID',{minimumFractionDigits:0}) : '\u2014';
const stConfig = {'Disetujui':{cls:'st-disetujui',ic:'bi-check-circle'},'Appraisal Aset':{cls:'st-appraisal',ic:'bi-calculator'},'Proses Lelang':{cls:'st-lelang',ic:'bi-hammer'},'Terjual':{cls:'st-terjual',ic:'bi-bag-check'}};

function openDetail(id) {
  const p = dataPelaksanaan.find(x => x.id == id);
  if (!p) return;
  const steps = ['Disetujui','Appraisal Aset','Proses Lelang','Terjual'];
  const curIdx = steps.indexOf(p.status_pelaksanaan);
  const trackHtml = steps.map((s,i) => {
    const done = i < curIdx, active = i === curIdx;
    const bg = done?'#d1fae5':active?'#dbeafe':'#f3f4f6';
    const clr = done?'#059669':active?'#1d4ed8':'#9ca3af';
    const ic = done?'bi-check-lg':(stConfig[s]?.ic||'bi-circle');
    return `<div class="st-node"><div class="st-circle" style="background:${bg};color:${clr};"><i class="bi ${ic}"></i></div><div class="st-name" style="color:${active?'#1d4ed8':done?'#059669':'#9ca3af'}">${s}</div></div>`;
  }).join('');

  const dokHo = dataDokHo.filter(d => d.id_pelaksanaan == p.id);
  const dokUsl = dataDokUsulan.filter(d => d.usulan_id == p.usulan_id);

  const makeDokRow = (d, i, urlKey, descKey, isHo) => {
    const url = isHo ? `?action=view_dok_ho&id_dok=${d.id_dokumen}` : `?action=view_dok_usulan&id_dok=${d.id_dokumen}`;
    const pid = `d${isHo?'h':'u'}-${d.id_dokumen}`;
    const label = isHo ? (d.deskripsi_dokumen||'Dokumen HO') : (d.tipe_dokumen||'Dokumen');
    const extra = isHo ? `<span style="color:#9ca3af;font-size:.75rem;margin-left:6px;">Tahun ${d.tahun_dokumen||'--'}</span>` : '';
    return `<div style="padding:8px 0;${i>0?'border-top:1px solid #f3f4f6':''}"><div style="display:flex;align-items:center;justify-content:space-between;"><div><span style="font-weight:600;font-size:.88rem;">${label}</span>${extra}</div><div style="display:flex;gap:6px;"><button onclick="togglePrev('${pid}','${url}')" class="btn btn-sm btn-outline-info" style="font-size:.75rem;padding:2px 8px;"><i class="bi bi-eye me-1"></i>Preview</button><a href="${url}" target="_blank" class="btn btn-sm btn-outline-secondary" style="font-size:.75rem;padding:2px 8px;"><i class="bi bi-box-arrow-up-right"></i></a></div></div><div id="${pid}" style="display:none;margin-top:8px;"><iframe id="${pid}-frame" src="" style="width:100%;height:420px;border:1px solid #e2e8f0;border-radius:8px;"></iframe></div></div>`;
  };

  const dokHoHtml  = dokHo.length  ? dokHo.map((d,i)  => makeDokRow(d,i,null,null,true)).join('')  : '<p class="text-muted small mb-0">Belum ada dokumen HO.</p>';
  const dokUslHtml = dokUsl.length ? dokUsl.map((d,i) => makeDokRow(d,i,null,null,false)).join('') : '<p class="text-muted small mb-0">Belum ada dokumen usulan.</p>';

  document.getElementById('modalSubtitle').textContent = p.nama_aset || p.nomor_asset_utama;
  document.getElementById('modalDetailBody').innerHTML = `
    <div class="detail-section"><div class="detail-section-title"><i class="bi bi-tag"></i> Identitas Aset</div>
      <div class="detail-grid">
        <div class="detail-item"><div class="detail-item-label">Nomor Aset</div><div class="detail-item-value" style="font-family:monospace;color:#2563eb;">${p.nomor_asset_utama}</div></div>
        <div class="detail-item"><div class="detail-item-label">Nama Aset</div><div class="detail-item-value">${p.nama_aset||'&mdash;'}</div></div>
        <div class="detail-item"><div class="detail-item-label">SubReg</div><div class="detail-item-value">${p.subreg||'&mdash;'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Profit Center</div><div class="detail-item-value">${p.profit_center_text||p.profit_center||'&mdash;'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Mekanisme</div><div class="detail-item-value">${p.mekanisme_penghapusan||'&mdash;'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Kategori</div><div class="detail-item-value">${p.kategori_aset||'&mdash;'}</div></div>
      </div>
    </div>
    <div class="detail-section" style="background:#f8faff;"><div class="detail-section-title"><i class="bi bi-arrow-right-circle"></i> Progres Pelaksanaan</div>
      <div class="status-track">${trackHtml}</div>
    </div>
    <div class="detail-section"><div class="detail-section-title"><i class="bi bi-calendar3"></i> Tanggal</div>
      <div class="detail-grid">
        <div class="detail-item"><div class="detail-item-label">Tgl. Persetujuan HO</div><div class="detail-item-value">${p.tanggal_persetujuan||'&mdash;'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Tgl. Appraisal</div><div class="detail-item-value">${p.tanggal_appraisal||'&mdash;'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Tgl. Penjualan</div><div class="detail-item-value">${p.tanggal_penjualan||'&mdash;'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Nomor Aset Pengganti</div><div class="detail-item-value">${p.nomor_aset_pengganti||'&mdash;'}</div></div>
      </div>
    </div>
    <div class="detail-section"><div class="detail-section-title"><i class="bi bi-cash-stack"></i> Nilai</div>
      <div class="nilai-grid">
        <div class="nilai-box"><div class="nilai-box-label">Nilai Buku Awal</div><div class="nilai-box-value">${rupiah(p.nilai_buku_awal)}</div></div>
        <div class="nilai-box"><div class="nilai-box-label">Nilai Buku Bulan Berjalan</div><div class="nilai-box-value">${rupiah(p.nilai_buku_bulan_berjalan)}</div></div>
        <div class="nilai-box"><div class="nilai-box-label">Nilai Appraisal Pasar</div><div class="nilai-box-value highlight">${rupiah(p.nilai_appraisal_pasar)}</div></div>
        <div class="nilai-box"><div class="nilai-box-label">Nilai Appraisal Likuidasi</div><div class="nilai-box-value">${rupiah(p.nilai_appraisal_likuidasi)}</div></div>
        <div class="nilai-box"><div class="nilai-box-label">Nilai Penjualan</div><div class="nilai-box-value highlight">${rupiah(p.nilai_penjualan)}</div></div>
        <div class="nilai-box"><div class="nilai-box-label">Biaya Lainnya</div><div class="nilai-box-value">${rupiah(p.biaya_lainnya)}</div></div>
      </div>
    </div>
    <div class="detail-section"><div class="detail-section-title"><i class="bi bi-patch-check"></i> Dokumen HO</div><div style="padding:0 4px;">${dokHoHtml}</div></div>
    <div class="detail-section"><div class="detail-section-title"><i class="bi bi-file-earmark-pdf"></i> Dokumen Usulan</div><div style="padding:0 4px;">${dokUslHtml}</div></div>`;

  document.getElementById('btnEditFromDetail')?.setAttribute('onclick', `openEdit(${id})`);
  new bootstrap.Modal(document.getElementById('modalDetail')).show();
}

function openEdit(id) {
  const p = dataPelaksanaan.find(x => x.id == id);
  if (!p) return;
  document.getElementById('edit_id').value             = p.id;
  document.getElementById('edit_status').value         = p.status_pelaksanaan || 'Disetujui';
  document.getElementById('edit_tgl_appraisal').value  = p.tanggal_appraisal || '';
  document.getElementById('edit_tgl_penjualan').value  = p.tanggal_penjualan || '';
  document.getElementById('edit_nb_bb').value          = p.nilai_buku_bulan_berjalan || '';
  document.getElementById('edit_app_pasar').value      = p.nilai_appraisal_pasar || '';
  document.getElementById('edit_app_likuidasi').value  = p.nilai_appraisal_likuidasi || '';
  document.getElementById('edit_nilai_jual').value     = p.nilai_penjualan || '';
  document.getElementById('edit_biaya').value          = p.biaya_lainnya || '';
  document.getElementById('edit_aset_pengganti').value = p.nomor_aset_pengganti || '';
  document.getElementById('editSubtitle').textContent  = p.nomor_asset_utama + ' \u2014 ' + (p.nama_aset||'');
  const md = bootstrap.Modal.getInstance(document.getElementById('modalDetail'));
  if (md) md.hide();
  setTimeout(() => new bootstrap.Modal(document.getElementById('modalEdit')).show(), 200);
}

function togglePrev(pid, url) {
  const el = document.getElementById(pid);
  const frame = document.getElementById(pid + '-frame');
  const hidden = !el || el.style.display === 'none' || el.style.display === '';
  if (el) el.style.display = hidden ? 'block' : 'none';
  if (hidden && frame && (!frame.src || frame.src === window.location.href)) frame.src = url;
}
</script>
<script src="../../dist/js/overlayscrollbars.browser.es6.min.js"></script>
<script src="../../dist/js/popper.min.js"></script>
<script src="../../dist/js/adminlte.js"></script>
</body>
</html>