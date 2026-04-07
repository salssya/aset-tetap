<?php
$servername = "localhost"; $username = "root"; $password = ""; $dbname = "asetreg3_db";
$con = mysqli_connect($servername, $username, $password, $dbname);
session_start();

if (!isset($_SESSION["nipp"]) || !isset($_SESSION["name"])) { header("Location: ../login/login_view.php"); exit(); }

$userType = isset($_SESSION['Type_User']) ? $_SESSION['Type_User'] : '';
$userNipp = $_SESSION['nipp'];

$isSubRegional = stripos($userType,'Sub Regional')!==false || stripos($userType,'Approval SubReg')!==false;
$isCabang      = !$isSubRegional && stripos($userType,'Cabang')!==false;
$isRegional    = !$isSubRegional && !$isCabang;

// Serve dokumen HO
if (isset($_GET['action']) && $_GET['action']==='view_dok_ho' && isset($_GET['id_dok'])) {
    $id_dok=(int)$_GET['id_dok'];
    $res=mysqli_query($con,"SELECT lokasi_file, file_name FROM dokumen_pelaksanaan WHERE id_dokumen=$id_dok LIMIT 1");
    if(!$res||mysqli_num_rows($res)===0){http_response_code(404);echo'Dokumen tidak ditemukan.';exit();}
    $row=mysqli_fetch_assoc($res); $fp=$row['lokasi_file']; $fn=basename($row['file_name']?:'dokumen.pdf');
    if(strpos($fp,'data:')===0&&strpos($fp,';gzip,')!==false) $fd=gzdecode(base64_decode(substr($fp,strrpos($fp,',')+1)));
    elseif(strpos($fp,'data:')===0&&strpos($fp,';base64,')!==false) $fd=base64_decode(substr($fp,strpos($fp,',')+1));
    else{http_response_code(404);echo'Format tidak dikenali.';exit();}
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="'.$fn.'"');
    header('Content-Length: '.strlen($fd)); header('Cache-Control: no-cache'); echo $fd; exit();
}

// Serve dokumen usulan
if (isset($_GET['action']) && $_GET['action']==='view_dok_usulan' && isset($_GET['id_dok'])) {
    $id_dok=(int)$_GET['id_dok'];
    $res=mysqli_query($con,"SELECT file_path, file_name FROM dokumen_penghapusan WHERE id_dokumen=$id_dok LIMIT 1");
    if(!$res||mysqli_num_rows($res)===0){http_response_code(404);echo'Dokumen tidak ditemukan.';exit();}
    $row=mysqli_fetch_assoc($res); $fp=$row['file_path']; $fn=basename($row['file_name']?:'dokumen.pdf');
    if(strpos($fp,'data:')===0&&strpos($fp,';gzip,')!==false) $fd=gzdecode(base64_decode(substr($fp,strrpos($fp,',')+1)));
    elseif(strpos($fp,'data:')===0&&strpos($fp,';base64,')!==false) $fd=base64_decode(substr($fp,strpos($fp,',')+1));
    else{http_response_code(404);echo'Format tidak dikenali.';exit();}
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="'.$fn.'"');
    header('Content-Length: '.strlen($fd)); header('Cache-Control: no-cache'); echo $fd; exit();
}

$filterTahun  = isset($_GET['tahun'])&&!empty($_GET['tahun'])?(int)$_GET['tahun']:date('Y');
$filterStatus = isset($_GET['status'])?$_GET['status']:'all';

$whereRole = '';
if ($isSubRegional) {
    $pc=trim(explode(' - ',isset($_SESSION['Cabang'])?$_SESSION['Cabang']:'')[0]);
    $rs=mysqli_query($con,"SELECT subreg FROM import_dat WHERE profit_center='".mysqli_real_escape_string($con,$pc)."' AND subreg!='' LIMIT 1");
    if($rs&&mysqli_num_rows($rs)>0){$sr=mysqli_fetch_assoc($rs)['subreg'];$whereRole=" AND up.subreg='".mysqli_real_escape_string($con,$sr)."'";}
} elseif ($isCabang) {
    $pc=trim(explode(' - ',isset($_SESSION['Cabang'])?$_SESSION['Cabang']:'')[0]);
    if(!empty($pc)) $whereRole=" AND up.profit_center='".mysqli_real_escape_string($con,$pc)."'";
}

$whereStatus='';
if($filterStatus!=='all'){$fs=mysqli_real_escape_string($con,$filterStatus);$whereStatus=" AND pp.status_pelaksanaan='$fs'";}

// Daftar pelaksanaan = semua data yang sudah dilengkapi (ada di tabel pelaksanaan_penghapusan)
$query = "SELECT pp.*,
    up.nomor_asset_utama, up.mekanisme_penghapusan, up.fisik_aset,
    up.justifikasi_alasan, up.kajian_hukum, up.kajian_ekonomis, up.kajian_risiko,
    up.status_approval_ho, up.catatan_ho, up.tanggal_approval_ho,
    id.keterangan_asset as nama_aset, id.asset_class_name as kategori_aset,
    id.profit_center_text, id.subreg, id.nilai_perolehan_sd,
    id.nilai_buku_sd as nilai_buku_awal, id.tgl_perolehan,
    id.masa_manfaat as umur_ekonomis, id.sisa_manfaat as sisa_umur_ekonomis,
    (SELECT COUNT(*) FROM dokumen_pelaksanaan dp WHERE dp.id_pelaksanaan=pp.id) as jml_dok_ho,
    (SELECT COUNT(*) FROM dokumen_penghapusan dp2 WHERE dp2.usulan_id=up.id) as jml_dok_usulan
    FROM pelaksanaan_penghapusan pp
    JOIN usulan_penghapusan up ON pp.usulan_id=up.id
    LEFT JOIN import_dat id ON up.nomor_asset_utama=id.nomor_asset_utama
    WHERE YEAR(pp.tanggal_persetujuan)=$filterTahun
    $whereStatus $whereRole
    ORDER BY pp.updated_at DESC";

$res=mysqli_query($con,$query); $data=[];
while($r=mysqli_fetch_assoc($res)){$r['nama_aset']=str_replace('AUC-','',$r['nama_aset']??'');$data[]=$r;}

// Dokumen HO
$daftar_dok_ho=[];
$res_dho=mysqli_query($con,"SELECT dp.*, pp.usulan_id FROM dokumen_pelaksanaan dp JOIN pelaksanaan_penghapusan pp ON dp.id_pelaksanaan=pp.id ORDER BY dp.id_dokumen DESC");
while($r=mysqli_fetch_assoc($res_dho)) $daftar_dok_ho[]=$r;

// Dokumen usulan
$daftar_dok_usulan=[];
$res_du=mysqli_query($con,"SELECT dp.id_dokumen,dp.usulan_id,dp.tipe_dokumen,dp.file_name FROM dokumen_penghapusan dp JOIN pelaksanaan_penghapusan pp ON pp.usulan_id=dp.usulan_id ORDER BY dp.id_dokumen DESC");
while($r=mysqli_fetch_assoc($res_du)) $daftar_dok_usulan[]=$r;

// Counter status
$cnt=['Disetujui'=>0,'Appraisal Aset'=>0,'Proses Lelang'=>0,'Terjual'=>0];
foreach($data as $d) if(isset($cnt[$d['status_pelaksanaan']])) $cnt[$d['status_pelaksanaan']]++;

$list_tahun=[];
$res_thn=mysqli_query($con,"SELECT DISTINCT YEAR(tanggal_persetujuan) as t FROM pelaksanaan_penghapusan WHERE tanggal_persetujuan IS NOT NULL ORDER BY t DESC");
while($r=mysqli_fetch_assoc($res_thn)) if($r['t']) $list_tahun[]=$r['t'];
if(!in_array(date('Y'),$list_tahun)) array_unshift($list_tahun,(int)date('Y'));

$success_msg=$_SESSION['success_message']??''; $warning_msg=$_SESSION['warning_message']??'';
unset($_SESSION['success_message'],$_SESSION['warning_message']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
  <title>Daftar Pelaksanaan Penghapusan - Web Aset Tetap</title>
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
    .sum-card{border-radius:12px;padding:14px 18px;color:#fff;display:flex;align-items:center;justify-content:space-between;}
    .sum-card .num{font-size:1.7rem;font-weight:700;line-height:1;} .sum-card .lbl{font-size:.76rem;opacity:.85;margin-top:3px;} .sum-card .ico{font-size:2.4rem;opacity:.22;}
    .card-table{border:1px solid #e9ecef;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-bottom:20px;background:#fff;}
    .card-table-header{padding:14px 20px;border-bottom:1px solid #e9ecef;background:#fff;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
    .card-table-header h5{margin:0;font-size:.95rem;font-weight:600;color:#1f2937;}
    .card-table-body{padding:16px 20px;}
    .table-responsive{overflow-x:auto;-webkit-overflow-scrolling:touch;}
    #daftarTable thead th,#daftarTable tbody td{padding:9px 13px;white-space:nowrap;vertical-align:middle;}
    #daftarTable thead th{background:#f8f9fa;font-weight:600;font-size:.875rem;border-bottom:2px solid #dee2e6;}
    #daftarTable tbody tr:hover{background:#f5f8ff;}
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
    .st-disetujui{background:#dbeafe;color:#1d4ed8;} .st-appraisal{background:#fef3c7;color:#92400e;}
    .st-lelang{background:#ede9fe;color:#6d28d9;} .st-terjual{background:#d1fae5;color:#065f46;}
    .nilai-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:6px;}
    .nilai-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;}
    .nilai-box-label{font-size:.7rem;color:#9ca3af;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;}
    .nilai-box-value{font-size:.92rem;font-weight:700;color:#1f2937;font-family:monospace;}
    .nilai-box-value.hl{color:#059669;}
    .status-track{display:flex;align-items:center;padding:12px 0;}
    .st-node{flex:1;text-align:center;position:relative;}
    .st-node::after{content:'';position:absolute;top:16px;left:60%;width:80%;height:2px;background:#e9ecef;z-index:0;}
    .st-node:last-child::after{display:none;}
    .st-circle{width:32px;height:32px;border-radius:50%;margin:0 auto 5px;display:flex;align-items:center;justify-content:center;font-size:.85rem;position:relative;z-index:1;}
    .st-name{font-size:.72rem;font-weight:600;}
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
    <div class="sidebar-brand"><a href="../dasbor/dasbor.php" class="brand-link"><img src="../../dist/assets/img/logo.png" class="brand-image" alt="Logo"/></a></div>
    <div class="sidebar-wrapper"><nav class="mt-2"><ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="navigation" data-accordion="false">
      <?php
      $un2=htmlspecialchars($userNipp);
      $rm=mysqli_query($con,"SELECT menus.menu,menus.nama_menu FROM user_access INNER JOIN menus ON user_access.id_menu=menus.id_menu WHERE user_access.NIPP='".mysqli_real_escape_string($con,$un2)."' ORDER BY menus.urutan_menu ASC");
      $iconMap=['Dasboard'=>'bi bi-grid-fill','Usulan Penghapusan'=>'bi bi-clipboard-plus','Daftar Usulan Penghapusan'=>'bi bi-clipboard-check-fill','Approval SubReg'=>'bi bi-check-circle','Approval Regional'=>'bi bi-check2-square','Persetujuan Penghapusan'=>'bi bi-patch-check-fill','Daftar Persetujuan Penghapusan'=>'bi bi-journal-check','Pelaksanaan Penghapusan'=>'bi bi-tools','Daftar Pelaksanaan Penghapusan'=>'bi bi-archive-fill','Manajemen Menu'=>'bi bi-list-ul','Import DAT'=>'bi bi-file-earmark-arrow-up-fill','Daftar Aset Tetap'=>'bi bi-card-list','Manajemen User'=>'bi bi-people-fill'];
      $menuRows=[];while($row=mysqli_fetch_assoc($rm)){$menuRows[]=$row;}$hasDaftarUsulan=false;$daftarRow=null;foreach($menuRows as $row){if(trim($row['nama_menu'])==='Daftar Usulan Penghapusan'){$hasDaftarUsulan=true;$daftarRow=$row;break;}}$currentPage=basename($_SERVER['PHP_SELF']);foreach($menuRows as $row){$namaMenu=trim($row['nama_menu']);if($namaMenu==='Daftar Usulan Penghapusan'){continue;}$icon=$iconMap[$namaMenu]??'bi bi-circle';$menuFile=$row['menu'].'.php';$isActive=($currentPage===$menuFile)?'active':'';if($namaMenu==='Manajemen Menu')echo '<li class="nav-header"></li>';echo '<li class="nav-item"><a href="../'.$row['menu'].'/'.$row['menu'].'.php" class="nav-link '.$isActive.'"><i class="nav-icon '.$icon.'"></i><p>'.htmlspecialchars($namaMenu).'</p></a></li>';if($namaMenu==='Usulan Penghapusan' && $hasDaftarUsulan && $daftarRow){$daftarIcon=$iconMap['Daftar Usulan Penghapusan']??'bi bi-circle';$daftarFile=$daftarRow['menu'].'.php';$isDaftarActive=($currentPage===$daftarFile)?'active':'';echo '<li class="nav-item"><a href="../'.$daftarRow['menu'].'/'.$daftarRow['menu'].'.php" class="nav-link '.$isDaftarActive.'"><i class="nav-icon '.$daftarIcon.'"></i><p>Daftar Usulan Penghapusan</p></a></li>';}}
      ?>
    </ul></nav></div>
  </aside>

  <main class="app-main">
    <div class="app-content-header py-3 px-4">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
          <h4 class="mb-0 fw-bold" style="color:#1f2937;"><i class="bi bi-archive-fill me-2" style="color:#0b3a8c;"></i>Daftar Pelaksanaan Penghapusan</h4>
          <small class="text-muted">Rekap lengkap hasil pelaksanaan penghapusan aset</small>
        </div>
        <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
          <select name="tahun" class="form-select form-select-sm" style="width:100px;" onchange="this.form.submit()">
            <?php foreach($list_tahun as $t): ?><option value="<?=$t?>" <?=$t==$filterTahun?'selected':''?>><?=$t?></option><?php endforeach; ?>
          </select>
          <select name="status" class="form-select form-select-sm" style="width:160px;" onchange="this.form.submit()">
            <option value="all"          <?=$filterStatus==='all'?'selected':''?>>Semua Status</option>
            <option value="Disetujui"    <?=$filterStatus==='Disetujui'?'selected':''?>>Disetujui</option>
            <option value="Appraisal Aset" <?=$filterStatus==='Appraisal Aset'?'selected':''?>>Appraisal Aset</option>
            <option value="Proses Lelang"  <?=$filterStatus==='Proses Lelang'?'selected':''?>>Proses Lelang</option>
            <option value="Terjual"      <?=$filterStatus==='Terjual'?'selected':''?>>Terjual</option>
          </select>
          <a href="?" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-counterclockwise"></i></a>
        </form>
      </div>
    </div>

    <div class="app-content px-4 pb-4">
      <?php if($success_msg): ?><div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?=htmlspecialchars($success_msg)?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

      <!-- Summary Cards -->
      <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
          <div class="sum-card" style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);">
            <div><div class="num"><?=$cnt['Disetujui']?></div><div class="lbl">Disetujui</div></div><i class="bi bi-check-circle ico"></i>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="sum-card" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
            <div><div class="num"><?=$cnt['Appraisal Aset']?></div><div class="lbl">Appraisal Aset</div></div><i class="bi bi-calculator ico"></i>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="sum-card" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);">
            <div><div class="num"><?=$cnt['Proses Lelang']?></div><div class="lbl">Proses Lelang</div></div><i class="bi bi-hammer ico"></i>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="sum-card" style="background:linear-gradient(135deg,#10b981,#059669);">
            <div><div class="num"><?=$cnt['Terjual']?></div><div class="lbl">Terjual</div></div><i class="bi bi-bag-check ico"></i>
          </div>
        </div>
      </div>

      <div class="card-table">
        <div class="card-table-header">
          <i class="bi bi-archive-fill text-primary"></i>
          <h5>Daftar Pelaksanaan Penghapusan &mdash; Tahun <?=$filterTahun?></h5>
          <span class="badge bg-primary ms-auto"><?=count($data)?> Data</span>
        </div>
        <div class="card-table-body">
          <?php if(empty($data)): ?>
            <div class="text-center text-muted py-5"><i class="bi bi-inbox" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:.75rem;"></i>Belum ada data pelaksanaan untuk tahun <?=$filterTahun?>.</div>
          <?php else: ?>
          <div class="table-responsive">
            <table id="daftarTable" class="table table-bordered table-hover align-middle w-100">
              <thead>
                <tr>
                  <th>No</th><th>Nomor Aset</th><th>Nama Aset</th><th>SubReg</th><th>Profit Center</th>
                  <th>Mekanisme</th><th>Tgl. Persetujuan</th><th>Status</th>
                  <th>Nilai Appraisal Pasar</th><th>Nilai Penjualan</th>
                  <th>Tgl. Appraisal</th><th>Tgl. Penjualan</th>
                  <th>Dok. HO</th><th>Detail</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($data as $i=>$p):
                  $stMap=['Disetujui'=>['st-disetujui','bi-check-circle'],'Appraisal Aset'=>['st-appraisal','bi-calculator'],'Proses Lelang'=>['st-lelang','bi-hammer'],'Terjual'=>['st-terjual','bi-bag-check']];
                  [$stClass,$stIcon]=$stMap[$p['status_pelaksanaan']]??['st-disetujui','bi-circle'];
                ?>
                <tr>
                  <td class="text-center"><?=$i+1?></td>
                  <td><code style="color:#2563eb;"><?=htmlspecialchars($p['nomor_asset_utama'])?></code></td>
                  <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;"><?=htmlspecialchars($p['nama_aset']??'-')?></td>
                  <td><?=htmlspecialchars($p['subreg']??'-')?></td>
                  <td style="font-size:.82rem;"><?=htmlspecialchars($p['profit_center_text']??$p['profit_center']??'-')?></td>
                  <td>
                    <?php if($p['mekanisme_penghapusan']==='Jual Lelang'): ?>
                      <span class="badge-pill" style="background:#dbeafe;color:#1d4ed8;">Jual Lelang</span>
                    <?php elseif($p['mekanisme_penghapusan']==='Hapus Administrasi'): ?>
                      <span class="badge-pill" style="background:#f3e8ff;color:#7c3aed;">Hapus Adm.</span>
                    <?php else: ?>&#8212;<?php endif; ?>
                  </td>
                  <td><?=$p['tanggal_persetujuan']??'-'?></td>
                  <td><span class="badge-pill <?=$stClass?>"><i class="bi <?=$stIcon?> me-1"></i><?=$p['status_pelaksanaan']?></span></td>
                  <td style="font-family:monospace;font-size:.82rem;"><?=$p['nilai_appraisal_pasar']?'Rp '.number_format($p['nilai_appraisal_pasar'],0,',','.'):'&#8212;'?></td>
                  <td style="font-family:monospace;font-size:.82rem;"><?=$p['nilai_penjualan']?'Rp '.number_format($p['nilai_penjualan'],0,',','.'):'&#8212;'?></td>
                  <td><?=$p['tanggal_appraisal']??'&#8212;'?></td>
                  <td><?=$p['tanggal_penjualan']??'&#8212;'?></td>
                  <td class="text-center"><span class="badge bg-<?=(int)$p['jml_dok_ho']>0?'success':'secondary'?>"><?=(int)$p['jml_dok_ho']?></span></td>
                  <td class="text-center"><button class="btn btn-sm btn-outline-primary btn-dpl-detail" data-id="<?=$p['id']?>" title="Detail"><i class="bi bi-eye"></i></button></td>
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
        <div><h5 class="modal-title mb-0"><i class="bi bi-archive-fill me-2"></i>Detail Pelaksanaan</h5><small id="modalSubtitle" style="opacity:.8;font-size:.8rem;"></small></div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0" id="modalDetailBody"></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button></div>
    </div>
  </div>
</div>

<script src="../../dist/js/jquery-3.7.1.min.js"></script>
<script src="../../dist/js/bootstrap.bundle.min.js"></script>
<script src="../../dist/js/dataTables.min.js"></script>
<script>
const dataPelaksanaan = <?= json_encode($data) ?>;
const dataDokHo       = <?= json_encode($daftar_dok_ho) ?>;
const dataDokUsulan   = <?= json_encode($daftar_dok_usulan) ?>;

$(document).ready(function() {
  if ($('#daftarTable tbody tr').length) {
    $('#daftarTable').DataTable({
      language:{search:"Cari:",lengthMenu:"Tampilkan _MENU_ data",info:"_START_-_END_ dari _TOTAL_ data",paginate:{first:"«",previous:"‹",next:"›",last:"»"},zeroRecords:"Tidak ada data"},
      pageLength:25, columnDefs:[{orderable:false,targets:[13]}]
    });
  }
});

$(document).on('click','.btn-dpl-detail',function(){ openDetail($(this).data('id')); });

const rupiah = n => (n!==null&&n!==''&&n!==undefined)?'Rp '+parseFloat(n).toLocaleString('id-ID',{minimumFractionDigits:0}):'—';
const stCfg = {'Disetujui':{cls:'st-disetujui',ic:'bi-check-circle'},'Appraisal Aset':{cls:'st-appraisal',ic:'bi-calculator'},'Proses Lelang':{cls:'st-lelang',ic:'bi-hammer'},'Terjual':{cls:'st-terjual',ic:'bi-bag-check'}};

function openDetail(id) {
  const p = dataPelaksanaan.find(x => x.id == id);
  if (!p) return;

  // Progress track
  const steps=['Disetujui','Appraisal Aset','Proses Lelang','Terjual'];
  const curIdx=steps.indexOf(p.status_pelaksanaan);
  const trackHtml=steps.map((s,i)=>{
    const done=i<curIdx,active=i===curIdx;
    const bg=done?'#d1fae5':active?'#dbeafe':'#f3f4f6';
    const clr=done?'#059669':active?'#1d4ed8':'#9ca3af';
    const ic=done?'bi-check-lg':(stCfg[s]?.ic||'bi-circle');
    return `<div class="st-node"><div class="st-circle" style="background:${bg};color:${clr};"><i class="bi ${ic}"></i></div><div class="st-name" style="color:${active?'#1d4ed8':done?'#059669':'#9ca3af'}">${s}</div></div>`;
  }).join('');

  // Dokumen HO
  const dokHo=dataDokHo.filter(d=>d.id_pelaksanaan==p.id);
  const dokHoHtml=dokHo.length===0?'<p class="text-muted small mb-0">Belum ada dokumen HO.</p>'
    :dokHo.map((d,i)=>{
      const url=`?action=view_dok_ho&id_dok=${d.id_dokumen}`;
      const pid=`dho-${d.id_dokumen}`;
      return `<div style="padding:8px 0;${i>0?'border-top:1px solid #f3f4f6':''}">
        <div style="display:flex;align-items:center;justify-content:space-between;">
          <div><span style="font-weight:600;font-size:.88rem;">${d.deskripsi_dokumen||'Dokumen HO'}</span>
          <span style="color:#9ca3af;font-size:.75rem;margin-left:6px;">Tahun ${d.tahun_dokumen||'—'}</span></div>
          <div style="display:flex;gap:6px;">
            <button onclick="togglePrev('${pid}','${url}')" class="btn btn-sm btn-outline-info" style="font-size:.75rem;padding:2px 8px;"><i class="bi bi-eye me-1"></i>Preview</button>
            <a href="${url}&download=1" class="btn btn-sm btn-outline-secondary" style="font-size:.75rem;padding:2px 8px;"><i class="bi bi-download"></i></a>
          </div>
        </div>
        <div id="${pid}" style="display:none;margin-top:8px;"><iframe id="${pid}-frame" src="" style="width:100%;height:440px;border:1px solid #e2e8f0;border-radius:8px;"></iframe></div>
      </div>`;
    }).join('');

  // Dokumen Usulan
  const dokUsl=dataDokUsulan.filter(d=>d.usulan_id==p.usulan_id);
  const dokUslHtml=dokUsl.length===0?'<p class="text-muted small mb-0">Belum ada dokumen usulan.</p>'
    :dokUsl.map((d,i)=>{
      const url=`?action=view_dok_usulan&id_dok=${d.id_dokumen}`;
      const pid=`du-${d.id_dokumen}`;
      return `<div style="padding:8px 0;${i>0?'border-top:1px solid #f3f4f6':''}">
        <div style="display:flex;align-items:center;justify-content:space-between;">
          <span style="font-weight:600;font-size:.88rem;">${d.tipe_dokumen||'Dokumen'}</span>
          <div style="display:flex;gap:6px;">
            <button onclick="togglePrev('${pid}','${url}')" class="btn btn-sm btn-outline-info" style="font-size:.75rem;padding:2px 8px;"><i class="bi bi-eye me-1"></i>Preview</button>
            <a href="${url}" target="_blank" class="btn btn-sm btn-outline-primary" style="font-size:.75rem;padding:2px 8px;"><i class="bi bi-box-arrow-up-right"></i></a>
          </div>
        </div>
        <div id="${pid}" style="display:none;margin-top:8px;"><iframe id="${pid}-frame" src="" style="width:100%;height:440px;border:1px solid #e2e8f0;border-radius:8px;"></iframe></div>
      </div>`;
    }).join('');

  document.getElementById('modalSubtitle').textContent = p.nama_aset||p.nomor_asset_utama;
  document.getElementById('modalDetailBody').innerHTML = `
    <div class="detail-section"><div class="detail-section-title"><i class="bi bi-tag"></i> Identitas Aset</div>
      <div class="detail-grid">
        <div class="detail-item"><div class="detail-item-label">Nomor Aset</div><div class="detail-item-value" style="font-family:monospace;color:#2563eb;">${p.nomor_asset_utama}</div></div>
        <div class="detail-item"><div class="detail-item-label">Nama Aset</div><div class="detail-item-value">${p.nama_aset||'—'}</div></div>
        <div class="detail-item"><div class="detail-item-label">SubReg</div><div class="detail-item-value">${p.subreg||'—'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Profit Center</div><div class="detail-item-value">${p.profit_center_text||p.profit_center||'—'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Mekanisme</div><div class="detail-item-value">${p.mekanisme_penghapusan||'—'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Kategori</div><div class="detail-item-value">${p.kategori_aset||'—'}</div></div>
      </div>
    </div>
    <div class="detail-section" style="background:#f8faff;"><div class="detail-section-title"><i class="bi bi-arrow-right-circle"></i> Progres Pelaksanaan</div>
      <div class="status-track">${trackHtml}</div>
    </div>
    <div class="detail-section"><div class="detail-section-title"><i class="bi bi-calendar3"></i> Tanggal Pelaksanaan</div>
      <div class="detail-grid">
        <div class="detail-item"><div class="detail-item-label">Tgl. Persetujuan HO</div><div class="detail-item-value">${p.tanggal_persetujuan||'—'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Tgl. Appraisal</div><div class="detail-item-value">${p.tanggal_appraisal||'—'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Tgl. Penjualan</div><div class="detail-item-value">${p.tanggal_penjualan||'—'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Nomor Aset Pengganti</div><div class="detail-item-value">${p.nomor_aset_pengganti||'—'}</div></div>
      </div>
    </div>
    <div class="detail-section"><div class="detail-section-title"><i class="bi bi-cash-stack"></i> Data Nilai</div>
      <div class="nilai-grid">
        <div class="nilai-box"><div class="nilai-box-label">Nilai Buku Awal</div><div class="nilai-box-value">${rupiah(p.nilai_buku_awal)}</div></div>
        <div class="nilai-box"><div class="nilai-box-label">Nilai Buku Bulan Berjalan</div><div class="nilai-box-value">${rupiah(p.nilai_buku_bulan_berjalan)}</div></div>
        <div class="nilai-box"><div class="nilai-box-label">Nilai Appraisal Pasar</div><div class="nilai-box-value hl">${rupiah(p.nilai_appraisal_pasar)}</div></div>
        <div class="nilai-box"><div class="nilai-box-label">Nilai Appraisal Likuidasi</div><div class="nilai-box-value">${rupiah(p.nilai_appraisal_likuidasi)}</div></div>
        <div class="nilai-box"><div class="nilai-box-label">Nilai Penjualan</div><div class="nilai-box-value hl">${rupiah(p.nilai_penjualan)}</div></div>
        <div class="nilai-box"><div class="nilai-box-label">Biaya Lainnya</div><div class="nilai-box-value">${rupiah(p.biaya_lainnya)}</div></div>
      </div>
    </div>
    <div class="detail-section"><div class="detail-section-title"><i class="bi bi-patch-check"></i> Dokumen Persetujuan HO</div><div style="padding:0 4px;">${dokHoHtml}</div></div>
    <div class="detail-section"><div class="detail-section-title"><i class="bi bi-file-earmark-pdf"></i> Dokumen Usulan</div><div style="padding:0 4px;">${dokUslHtml}</div></div>`;

  new bootstrap.Modal(document.getElementById('modalDetail')).show();
}

function togglePrev(pid,url) {
  const el=document.getElementById(pid),frame=document.getElementById(pid+'-frame');
  const hidden=!el||el.style.display==='none'||el.style.display==='';
  if(el) el.style.display=hidden?'block':'none';
  if(hidden&&frame&&(!frame.src||frame.src===window.location.href)) frame.src=url;
}
</script>
<script src="../../dist/js/overlayscrollbars.browser.es6.min.js"></script>
<script src="../../dist/js/popper.min.js"></script>
<script src="../../dist/js/adminlte.js"></script>
</body>
</html>