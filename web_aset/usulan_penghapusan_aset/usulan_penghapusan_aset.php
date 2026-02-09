<?php
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "asetreg3_db";

// Create connection
$con = mysqli_connect($servername, $username, $password, $dbname);
session_start();

// Cek login
if (!isset($_SESSION["nipp"]) || !isset($_SESSION["name"])) {
    header("Location: ../login/login_view.php");
    exit();
}

// Ambil profit center dari session user
$userProfitCenter = isset($_SESSION['Cabang']) ? $_SESSION['Cabang'] : '';

// Query hanya untuk profit center user
$query = "SELECT * FROM import_dat 
          WHERE profit_center = ? 
          ORDER BY nomor_asset_utama ASC";

$stmt = $con->prepare($query);
$stmt->bind_param("s", $userProfitCenter);
$stmt->execute();
$result = $stmt->get_result();

$asset_data = [];
while ($row = $result->fetch_assoc()) {
    $asset_data[] = $row;
}
$stmt->close();

// Query untuk mendapatkan data draft
$query_draft = "SELECT * FROM usulan_penghapusan 
                WHERE profit_center = ? AND status = 'draft' 
                ORDER BY created_at DESC";
$stmt_draft = $con->prepare($query_draft);
$stmt_draft->bind_param("s", $userProfitCenter);
$stmt_draft->execute();
$result_draft = $stmt_draft->get_result();

$draft_data = [];
$draft_asset_numbers = []; 
while ($row = $result_draft->fetch_assoc()) {
    $draft_data[] = $row;
    $draft_asset_numbers[] = $row['nomor_asset_utama']; 
}
$stmt_draft->close();

//Query untuk data yang perlu dilengkapi dokumen (untuk tab "Lengkapi Dokumen")
$userNipp = $_SESSION['nipp'];
$query_lengkapi = "SELECT up.*, 
                   id.keterangan_asset as nama_aset, 
                   id.asset_class_name as kategori_aset,
                   id.subreg,
                   id.profit_center_text,
                   id.masa_manfaat as umur_ekonomis,
                   id.sisa_manfaat as sisa_umur_ekonomis,
                   id.tgl_perolehan,
                   id.nilai_buku_sd as nilai_buku,
                   id.nilai_perolehan_sd as nilai_perolehan
                   FROM usulan_penghapusan up 
                   LEFT JOIN import_dat id ON up.nomor_asset_utama = id.nomor_asset_utama 
                   WHERE up.created_by = ? 
                   AND up.status IN ('lengkapi_dokumen', 'dokumen_lengkap') 
                   ORDER BY up.created_at DESC";
$stmt_lengkapi = $con->prepare($query_lengkapi);
$stmt_lengkapi->bind_param("s", $userNipp);
$stmt_lengkapi->execute();
$result_lengkapi = $stmt_lengkapi->get_result();

$lengkapi_data = [];
$lengkapi_asset_numbers = []; // Array untuk auto-centang checkbox
while ($row = $result_lengkapi->fetch_assoc()) {
    // Hilangkan kode "AUC-" dari nama_aset dan kategori_aset
    $row['nama_aset'] = str_replace('AUC-', '', $row['nama_aset']);
    $row['kategori_aset'] = str_replace('AUC-', '', $row['kategori_aset']);
    
    $lengkapi_data[] = $row;
    $lengkapi_asset_numbers[] = $row['nomor_asset_utama']; // Simpan nomor aset
}
$stmt_lengkapi->close();
// Handle save to database dengan status draft/submit
$pesan = "";
$tipe_pesan = "";
$saved_count = 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $is_submit = ($_POST['action'] === 'submit_data');

    if (!isset($_POST['selected_items']) || empty($_POST['selected_items'])) {
        $pesan = "Tidak ada data yang dipilih";
        $tipe_pesan = "warning";
    } else {
        $selected_data = json_decode($_POST['selected_items'], true);
        
        // Validasi bahwa selected_data adalah array dan tidak kosong
        if (is_array($selected_data) && count($selected_data) > 0) {
            $saved_count = saveSelectedAssets($con, $selected_data, $is_submit, $_SESSION['nipp'], $userProfitCenter);

            if ($saved_count > 0) {
                if ($is_submit) {
                    // Redirect ke tab Lengkapi Dokumen di halaman yang sama
                    $_SESSION['success_message'] = "✅ Berhasil mengusulkan " . $saved_count . " aset untuk penghapusan";
                    header("Location: " . $_SERVER['PHP_SELF'] . "#dokumen");
                    exit();
                } else {
                    // Untuk draft, reload halaman dengan pesan sukses
                    $_SESSION['success_message'] = "✅ Berhasil menyimpan " . $saved_count . " aset sebagai draft";
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                }
            } else {
                $pesan = "Tidak ada data baru yang disimpan (data mungkin sudah ada)";
                $tipe_pesan = "info";
            }
        } else {
            $pesan = "Format data tidak valid";
            $tipe_pesan = "danger";
        }
    }
}

// Tampilkan pesan sukses dari session jika ada
if (isset($_SESSION['success_message'])) {
    $pesan = $_SESSION['success_message'];
    $tipe_pesan = "success";
    unset($_SESSION['success_message']);
}

// Tampilkan pesan warning dari session jika ada
if (isset($_SESSION['warning_message'])) {
    $pesan = $_SESSION['warning_message'];
    $tipe_pesan = "warning";
    unset($_SESSION['warning_message']);
}

// Handle form lengkapi dokumen submit
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
            $_SESSION['show_success_modal'] = true;
            header("Location: " . $_SERVER['PHP_SELF'] . "#dokumen");
            exit();
            exit();
        } else {
            $pesan = "Gagal menyimpan data: " . $stmt->error;
            $tipe_pesan = "danger";
        }
        $stmt->close();
    }
}

// Handle hapus draft
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] === 'delete_draft') {
    $draft_id = intval($_POST['draft_id']);
    $del = $con->prepare("DELETE FROM usulan_penghapusan WHERE id = ? AND status = 'draft' AND created_by = ?");
    $del->bind_param("is", $draft_id, $_SESSION['nipp']);
    if ($del->execute() && $del->affected_rows > 0) {
        $_SESSION['success_message'] = "✅ Draft usulan berhasil dibatalkan.";
    } else {
        $_SESSION['warning_message'] = "⚠️ Gagal membatalkan draft.";
    }
    $del->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle cancel draft dari tombol di tabel (sama dengan delete_draft tapi dengan pesan berbeda)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_draft') {
    $draft_id = intval($_POST['draft_id']);
    $del = $con->prepare("DELETE FROM usulan_penghapusan WHERE id = ? AND status = 'draft' AND created_by = ?");
    $del->bind_param("is", $draft_id, $_SESSION['nipp']);
    if ($del->execute() && $del->affected_rows > 0) {
        $_SESSION['success_message'] = "✅ Draft usulan berhasil dibatalkan.";
    } else {
        $_SESSION['warning_message'] = "⚠️ Gagal membatalkan draft.";
    }
    $del->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle hapus usulan dari tab Lengkapi Dokumen
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_usulan') {
    $usulan_id = intval($_POST['usulan_id']);
    $del = $con->prepare("DELETE FROM usulan_penghapusan 
                          WHERE id = ? 
                          AND created_by = ? 
                          AND status IN ('lengkapi_dokumen', 'dokumen_lengkap')");
    $del->bind_param("is", $usulan_id, $_SESSION['nipp']);
    
    if ($del->execute() && $del->affected_rows > 0) {
        $_SESSION['success_message'] = "✅ Usulan berhasil dihapus.";
    } else {
        $_SESSION['success_message'] = "⚠️ Gagal menghapus usulan.";
    }
    $del->close();
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle update dropdown field (mekanisme penghapusan / fisik aset)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_dropdown_field') {
    $asset_id = intval($_POST['asset_id']);
    $nomor_aset = $_POST['nomor_aset'];
    $field_type = $_POST['field_type']; // 'mekanisme' atau 'fisik'
    $value = $_POST['value'];
    
    // Cek apakah sudah ada record di usulan_penghapusan untuk aset ini
    $check = $con->prepare("SELECT id FROM usulan_penghapusan WHERE nomor_asset_utama = ? AND created_by = ?");
    $check->bind_param("ss", $nomor_aset, $_SESSION['nipp']);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows > 0) {
        // Update existing record
        $row = $result->fetch_assoc();
        $usulan_id = $row['id'];
        
        if ($field_type === 'mekanisme') {
            $upd = $con->prepare("UPDATE usulan_penghapusan SET mekanisme_penghapusan = ?, updated_at = NOW() WHERE id = ?");
            $upd->bind_param("si", $value, $usulan_id);
        } else if ($field_type === 'fisik') {
            $upd = $con->prepare("UPDATE usulan_penghapusan SET fisik_aset = ?, updated_at = NOW() WHERE id = ?");
            $upd->bind_param("si", $value, $usulan_id);
        }
        
        if ($upd->execute()) {
            echo json_encode(['success' => true, 'message' => 'Updated']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update']);
        }
        $upd->close();
    } else {
        // Create new draft record
        $userProfitCenter = isset($_SESSION['Cabang']) ? $_SESSION['Cabang'] : '';
        $ins = $con->prepare("INSERT INTO usulan_penghapusan (nomor_asset_utama, profit_center, status, created_by, created_at, updated_at, mekanisme_penghapusan, fisik_aset) VALUES (?, ?, 'draft', ?, NOW(), NOW(), ?, ?)");
        
        $mekanisme_val = ($field_type === 'mekanisme') ? $value : '';
        $fisik_val = ($field_type === 'fisik') ? $value : '';
        
        $ins->bind_param("sssss", $nomor_aset, $userProfitCenter, $_SESSION['nipp'], $mekanisme_val, $fisik_val);
        
        if ($ins->execute()) {
            echo json_encode(['success' => true, 'message' => 'Created']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to create']);
        }
        $ins->close();
    }
    $check->close();
    exit();
}

// Handle submit dari tab draft (ubah status draft → submitted)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] === 'submit_from_draft') {
    $draft_ids = json_decode($_POST['selected_drafts'], true);
    $submitted = 0;
    if (is_array($draft_ids)) {
        $upd = $con->prepare("UPDATE usulan_penghapusan SET status = 'lengkapi_dokumen', updated_at = NOW() WHERE id = ? AND status = 'draft' AND created_by = ?");
        foreach ($draft_ids as $did) {
            $did = intval($did);
            $upd->bind_param("is", $did, $_SESSION['nipp']);
            if ($upd->execute() && $upd->affected_rows > 0) {
                $submitted++;
            }
        }
        $upd->close();
    }
    if ($submitted > 0) {
        header("Location: ../usulan_penghapusan_aset/lengkapi_dokumen.php");
        exit();
    } else {
        $pesan = "⚠️ Tidak ada draft yang berhasil di-submit.";
        $tipe_pesan = "warning";
    }
}

// Ambil data draft untuk tab Draft
$draft_data = [];
$draft_count = 0;
$dq = $con->prepare("SELECT * FROM usulan_penghapusan WHERE status = 'draft' AND created_by = ? ORDER BY created_at DESC");
$dq->bind_param("s", $_SESSION['nipp']);
$dq->execute();
$dr = $dq->get_result();
$draft_count = $dr->num_rows;
while ($row = $dr->fetch_assoc()) {
    $draft_data[] = $row;
}
$dq->close();

// Function untuk simpan aset
function saveSelectedAssets($con, $selected_data, $is_submit, $created_by, $userProfitCenter) {
    $saved_count = 0;
    // Status: draft (simpan draft) atau lengkapi_dokumen (submit ke lengkapi dokumen)
    $status = $is_submit ? 'lengkapi_dokumen' : 'draft';

    $stmt = $con->prepare("INSERT INTO usulan_penghapusan (
        tahun_usulan,
        nomor_asset_utama,
        subreg,
        profit_center,
        profit_center_text,
        nama_aset,
        kategori_aset,
        umur_ekonomis,
        sisa_umur_ekonomis,
        tgl_perolehan,
        nilai_buku,
        nilai_perolehan,
        mekanisme_penghapusan,
        fisik_aset,
        status,
        created_at,
        created_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");

    $tahun_usulan = date('Y'); 

    foreach ($selected_data as $asset_data) {
        // Extract data dari object: {id, nomor_aset, mekanisme, fisik}
        $asset_id = isset($asset_data['id']) ? $asset_data['id'] : $asset_data; // Backward compatibility
        $mekanisme_pilihan = isset($asset_data['mekanisme']) && !empty($asset_data['mekanisme']) ? $asset_data['mekanisme'] : null;
        $fisik_pilihan = isset($asset_data['fisik']) && !empty($asset_data['fisik']) ? $asset_data['fisik'] : null;
        
        $query = "SELECT * FROM import_dat WHERE id = ? AND profit_center = ?";
        $get_stmt = $con->prepare($query);
        $get_stmt->bind_param("is", $asset_id, $userProfitCenter);
        $get_stmt->execute();
        $result = $get_stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            // Cek apakah aset sudah ada di database
            $check = $con->prepare("SELECT id, status FROM usulan_penghapusan WHERE nomor_asset_utama = ? AND created_by = ?");
            $check->bind_param("ss", $row['nomor_asset_utama'], $created_by);
            $check->execute();
            $check_result = $check->get_result();

            if ($check_result->num_rows > 0) {
                // Aset sudah ada
                $existing = $check_result->fetch_assoc();
                $check->close();
                
                // CRITICAL FIX: 
                // Jika user klik SUBMIT dan aset masih draft → UPDATE status jadi lengkapi_dokumen
                if ($is_submit && $existing['status'] === 'draft') {
                    $upd = $con->prepare("UPDATE usulan_penghapusan SET status = 'lengkapi_dokumen', updated_at = NOW() WHERE id = ?");
                    $upd->bind_param("i", $existing['id']);
                    if ($upd->execute()) {
                        $saved_count++; // Count as "saved" karena berhasil update
                    }
                    $upd->close();
                }
                // Jika user klik DRAFT dan aset sudah ada → skip (nggak perlu update)
                // Jika user klik SUBMIT tapi aset sudah lengkapi_dokumen → skip juga
                continue;
            }
            $check->close();

            // Aset belum ada di database → INSERT baru dengan mekanisme dan fisik
            $stmt->bind_param("issssssiisddssss",
                $tahun_usulan,
                $row['nomor_asset_utama'],
                $row['subreg'],
                $row['profit_center'],
                $row['profit_center_text'],
                $row['keterangan_asset'],      
                $row['asset_class_name'],      
                $row['masa_manfaat'],          
                $row['sisa_manfaat'],         
                $row['tgl_perolehan'],
                $row['nilai_buku_sd'],          
                $row['nilai_perolehan_sd'],
                $mekanisme_pilihan,  // Dari dropdown
                $fisik_pilihan,      // Dari dropdown     
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

    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
    
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
              <div class="col-sm-6"><h3 class="mb-0">Usulan Penghapusan Aset Tetap</h3></div>
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
            
            <!-- Alert Pesan Global -->
            <?php if ($pesan): ?>
            <div class="alert alert-<?= $tipe_pesan ?> alert-dismissible fade show" role="alert">
                <?= $pesan ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!--begin::Row-->
                  <!--begin::Header-->
                  <!--end::Header-->
                  <!--begin::Form-->
                  <!--begin::Header-->
                  <div class="row">
                    <div class="col-12">
                      <div class="card">
                        <div class="card-body">
                          <p class="text-muted mb-3" id="infoText">
                            <i class="bi bi-info-circle me-1"></i>
                            <span id="infoTextContent">Pilih aset yang akan diusulkan untuk penghapusan</span>
                          </p>

                    <!-- Nav tabs -->
                    <ul class="nav nav-tabs mb-3" id="myTab" role="tablist">
                      <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="daftar-aset-tab" data-bs-toggle="tab" data-bs-target="#aset" type="button" role="tab" aria-controls="aset" aria-selected="true">
                          <i class="bi bi-list-ul me-2"></i>Daftar Aset Tetap
                        </button>
                      </li>
                      <li class="nav-item" role="presentation">
                        <button class="nav-link" id="lengkapi-dokumen-tab" data-bs-toggle="tab" data-bs-target="#dokumen" type="button" role="tab" aria-controls="dokumen" aria-selected="false">
                          <i class="bi bi-file-earmark-check me-2"></i>Lengkapi Data Aset
                          
                        </button>
                      </li>
                    </ul>

                    <div class="tab-content" id="usulanTabsContent">
                      
                          <!-- Tab Pilih Aset -->
                          <div class="tab-pane fade show active" id="aset" role="tabpanel">
                            <h5>Pilih untuk Usulan Penghapusan</h5>  
                            <!-- Table -->
                            <div class="table-responsive">
                              <table id="myTable" class="display nowrap table table-striped table-sm w-100">
                                <thead>
                                  <tr>
                                    <th style="width:40px;">Action</th>
                                    <th style="width:50px;">Pilih</th>
                                    <th>Mekanisme Penghapusan</th>
                                    <th>Fisik Aset</th>
                                    <th>Nomor Aset</th>
                                    <th>SubReg</th>
                                    <th>Profit Center</th>
                                    <th>Nama Aset</th>
                                    <th>Kategori Aset</th>
                                    <th>Umur Ekonomis</th>
                                    <th>Sisa Umur Ekonomis</th>
                                    <th>Tgl Perolehan</th>
                                    <th>Nilai Buku</th>
                                    <th>Nilai Perolehan</th>
                                  </tr>
                                </thead>
                                <tbody>
                                  
                                  <?php  
                                    // Ambil profit center dari session
                                    $userProfitCenter = isset($_SESSION['Cabang']) ? $_SESSION['Cabang'] : '';

                                    // Query dengan LEFT JOIN ke usulan_penghapusan untuk mendapatkan mekanisme dan fisik aset
                                    $query = "SELECT id.*, 
                                              up.id as draft_id,
                                              up.mekanisme_penghapusan, 
                                              up.fisik_aset,
                                              up.status as draft_status
                                              FROM import_dat id 
                                              LEFT JOIN usulan_penghapusan up ON id.nomor_asset_utama = up.nomor_asset_utama 
                                              WHERE id.profit_center = ?
                                              ORDER BY id.nomor_asset_utama ASC";
                                    $stmt = $con->prepare($query);
                                    $stmt->bind_param("s", $userProfitCenter);
                                    $stmt->execute();
                                    $result = $stmt->get_result();

                                    if (!$result) {
                                      echo '<tr><td colspan="14">Error: ' . mysqli_error($con) . '</td></tr>';
                                    } elseif ($result->num_rows == 0) {
                                      echo '<tr><td colspan="14" class="text-center">Tidak ada data aset</td></tr>';
                                    } else {
                                     while ($row = $result->fetch_assoc()) {
                                        // Auto-check jika aset ini ada di draft ATAU sudah di-submit
                                        $isInDraft = in_array($row['nomor_asset_utama'], $draft_asset_numbers);
                                        $isSubmitted = in_array($row['nomor_asset_utama'], $lengkapi_asset_numbers);
                                        $checkedAttr = ($isInDraft || $isSubmitted) ? ' checked' : '';
                                        $disabledAttr = $isSubmitted ? ' disabled title="Aset sudah di-submit"' : '';
                                        
                                        // Format nilai currency (Rupiah)
                                        $nilai_buku = isset($row['nilai_buku_sd']) ? 'Rp ' . number_format($row['nilai_buku_sd'], 0, ',', '.') : '-';
                                        $nilai_perolehan = isset($row['nilai_perolehan_sd']) ? 'Rp ' . number_format($row['nilai_perolehan_sd'], 0, ',', '.') : '-';
                                        
                                        // Hilangkan kode "AUC-" dari asset_class_name dan keterangan_asset
                                        $kategori_aset = str_replace('AUC-', '', $row['asset_class_name']);
                                        $nama_aset = str_replace('AUC-', '', $row['keterangan_asset']);
                                        
                                        // Tampilkan mekanisme dan fisik aset jika sudah ada usulan
                                        $mekanisme = !empty($row['mekanisme_penghapusan']) ? htmlspecialchars($row['mekanisme_penghapusan']) : '-';
                                        $fisik = !empty($row['fisik_aset']) ? htmlspecialchars($row['fisik_aset']) : '-';
                                        
                                        // Tombol hapus draft (hanya tampil jika status = 'draft')
                                        $hapusDraftBtn = '';
                                        if ($isInDraft && !empty($row['draft_id']) && $row['draft_status'] === 'draft') {
                                            $hapusDraftBtn = '<button type="button" class="btn btn-sm btn-outline-danger" 
                                                onclick="confirmCancelDraft('.intval($row['draft_id']).', \''.htmlspecialchars(addslashes($row['nomor_asset_utama'])).'\', \''.htmlspecialchars(addslashes($nama_aset)).'\')" 
                                                title="Batalkan draft usulan ini">
                                                <i class="bi bi-x-circle"></i>
                                            </button>';
                                        }
                                        
                                        // Dropdown Mekanisme Penghapusan
                                        $selectedMekanisme = !empty($row['mekanisme_penghapusan']) ? $row['mekanisme_penghapusan'] : '';
                                        $mekanismeDropdown = '<select class="form-select form-select-sm mekanisme-dropdown" data-asset-id="'.htmlspecialchars($row['id']).'" data-nomor-aset="'.htmlspecialchars($row['nomor_asset_utama']).'" '.($isSubmitted ? 'disabled' : '').'>
                                            <option value="">-</option>
                                            <option value="Jual Lelang"'.($selectedMekanisme === 'Jual Lelang' ? ' selected' : '').'>Jual Lelang</option>
                                            <option value="Hapus Administrasi"'.($selectedMekanisme === 'Hapus Administrasi' ? ' selected' : '').'>Hapus Administrasi</option>
                                        </select>';
                                        
                                        // Dropdown Fisik Aset
                                        $selectedFisik = !empty($row['fisik_aset']) ? $row['fisik_aset'] : '';
                                        $fisikDropdown = '<select class="form-select form-select-sm fisik-dropdown" data-asset-id="'.htmlspecialchars($row['id']).'" data-nomor-aset="'.htmlspecialchars($row['nomor_asset_utama']).'" '.($isSubmitted ? 'disabled' : '').'>
                                            <option value="">-</option>
                                            <option value="Ada"'.($selectedFisik === 'Ada' ? ' selected' : '').'>Ada</option>
                                            <option value="Tidak Ada"'.($selectedFisik === 'Tidak Ada' ? ' selected' : '').'>Tidak Ada</option>
                                        </select>';
                                        
                                        echo '<tr>
                                          <td style="text-align:center;">'.$hapusDraftBtn.'</td>
                                          <td style="text-align:center;">
                                            <input type="checkbox" class="row-checkbox form-check-input" value="'.htmlspecialchars($row['id']).'"'.$checkedAttr.$disabledAttr.'>
                                          </td>
                                          <td>'.$mekanismeDropdown.'</td>
                                          <td>'.$fisikDropdown.'</td>
                                          <td>'.htmlspecialchars($row['nomor_asset_utama']).'</td>
                                          <td>'.htmlspecialchars($row['subreg']).'</td>
                                          <td>'.htmlspecialchars($row['profit_center']).(!empty($row['profit_center_text']) ? ' - '.htmlspecialchars($row['profit_center_text']) : '').'</td>
                                          <td>'.htmlspecialchars($nama_aset).'</td>
                                          <td>'.htmlspecialchars($kategori_aset).'</td>
                                          <td>'.htmlspecialchars($row['masa_manfaat']).'</td>
                                          <td>'.htmlspecialchars($row['sisa_manfaat']).'</td>
                                          <td>'.htmlspecialchars($row['tgl_perolehan']).'</td>
                                          <td style="text-align:right;">'.$nilai_buku.'</td>
                                          <td style="text-align:right;">'.$nilai_perolehan.'</td>
                                        </tr>';
                                      }
                                    }
                                    $stmt->close();
                                  ?>
                                </tbody>
                              </table>
                            </div>
                            <!-- End Table -->
                            <!-- Tombol hanya di tab aset -->
                             <!-- Action Buttons -->
                                        <form id="saveForm" method="POST">
                                        <input type="hidden" id="selectedItemsInput" name="selected_items">
                                        <input type="hidden" id="actionType" name="action">
                                    </form>
                                    <form id="actionForm" method="POST">                                        
                                        <div class="action-buttons">
                                            <button type="button" class="btn btn-secondary btn-action" id="saveDraftBtn" onclick="saveData('draft')">
                                                <i class="bi bi-save"></i> Simpan sebagai Draft
                                            </button>
                                            <button type="button" class="btn btn-primary btn-action" id="submitBtn" onclick="saveData('submit')">
                                                <i class="bi bi-send-check"></i> Submit ke Lengkapi Data
                                            </button>
                                            <button type="button" class="btn btn-danger" onclick="clearSelection()">
                                                <i class="bi bi-x-circle"></i> Batal Pilihan
                                            </button>
                                        </div>
                                    <!-- Selection Info --> 
                                    <div id="selectionInfo" class="alert alert-info mt-3" style="display: none;">
                                        <i class="bi bi-check2-circle"></i> Anda telah memilih <span id="selectionCount">0</span> aset.
                                    </div>
                                    </form>
                          </div>
                          <!-- End Tab Pilih Aset -->

                          <!-- Tab Lengkapi Dokumen -->
                          <div class="tab-pane fade" id="dokumen" role="tabpanel">
                            
                            <!-- Summary Boxes -->
                            <div class="row mb-4">
                              <div class="col-md-4">
                                <div class="small-box text-bg-warning">
                                  <div class="inner">
                                    <h3><?= count(array_filter($lengkapi_data, fn($d) => $d['status'] === 'lengkapi_dokumen')) ?></h3>
                                    <p>Lengkapi Data</p>
                                  </div>
                                  <i class="bi bi-exclamation-triangle small-box-icon"></i>
                                </div>
                              </div>
                              <div class="col-md-4">
                                <div class="small-box text-bg-success">
                                  <div class="inner">
                                    <h3><?= count(array_filter($lengkapi_data, fn($d) => $d['status'] === 'dokumen_lengkap')) ?></h3>
                                    <p>Data Lengkap</p>
                                  </div>
                                  <i class="bi bi-check-circle small-box-icon"></i>
                                </div>
                              </div>
                              <div class="col-md-4">
                                <div class="small-box text-bg-info">
                                  <div class="inner">
                                    <h3><?= count($lengkapi_data) ?></h3>
                                    <p>Total Usulan</p>
                                  </div>
                                  <i class="bi bi-file-earmark-text small-box-icon"></i>
                                </div>
                              </div>
                            </div>
                            <div class="row mb-1">
                         
                            <!-- End Summary Boxes -->                    
                              <div class="d-flex justify-content-between align-items-center mb-2">
                              <h5 class="mb-0 mt-0">Daftar Aset yang Dapat Diajukan</h5>
                            </div>

                            <?php if (empty($lengkapi_data)): ?>
                              <div class="alert alert-warning">
                                <i class="bi bi-info-circle me-2"></i>
                                Belum ada usulan yang perlu dilengkapi data. 
                                Silakan submit usulan dari tab "Daftar Aset Tetap".
                              </div>
                            <?php else: ?>

                            <div class="table-responsive">
                              <table id="lengkapiTable" class="display nowrap table table-striped table-sm w-100">
                                <thead>
                                  <tr>
                                    <th>No</th>
                                    <th>Nomor Aset</th>
                                    <th>Nama Aset</th>
                                    <th>Kategori Aset</th>
                                    <th>Profit Center</th>
                                    <th>Status</th>
                                    <th>Tanggal Submit</th>
                                    <th>Action</th>
                                  </tr>
                                </thead>
                                <tbody>
                                  <?php foreach ($lengkapi_data as $index => $row): ?>
                                  <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars($row['nomor_asset_utama']) ?></td>
                                    <td><?= htmlspecialchars($row['nama_aset']) ?></td>
                                    <td><?= htmlspecialchars($row['kategori_aset']) ?></td>
                                    <td><?= htmlspecialchars($row['profit_center']) . (!empty($row['profit_center_text']) ? ' - ' . htmlspecialchars($row['profit_center_text']) : '') ?></td>
                                    <td>
                                      <?php if ($row['status'] === 'lengkapi_dokumen'): ?>
                                        <span class="badge bg-warning text-dark">
                                          <i class="bi bi-exclamation-triangle me-1"></i>Lengkapi Data
                                        </span>
                                      <?php elseif ($row['status'] === 'dokumen_lengkap'): ?>
                                        <span class="badge bg-success">
                                          <i class="bi bi-check-circle me-1"></i>Data Lengkap
                                        </span>
                                      <?php endif; ?>
                                    </td>
                                    <td><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></td>
                                    <td>
                                      <button class="btn btn-sm btn-primary" 
                                              onclick="openFormLengkapiDokumen(<?= $row['id'] ?>)" 
                                              title="Lengkapi dokumen">
                                        <i class="bi bi-pencil-square"></i> Lengkapi
                                      </button>
                                      <?php if (!empty($row['foto_path'])): ?>
                                      <button class="btn btn-sm btn-info" 
                                              onclick="viewFoto('<?= htmlspecialchars($row['foto_path']) ?>', '<?= htmlspecialchars($row['nomor_asset_utama']) ?>', '<?= htmlspecialchars(addslashes($row['nama_aset'])) ?>')" 
                                              title="Lihat foto aset">
                                        <i class="bi bi-image"></i> Foto
                                      </button>
                                      <?php endif; ?>
                                      <button class="btn btn-sm btn-danger" 
                                              onclick="confirmDeleteUsulan(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['nomor_asset_utama'])) ?>', '<?= htmlspecialchars(addslashes($row['nama_aset'])) ?>')"
                                              title="Hapus usulan ini">
                                        <i class="bi bi-trash"></i> Hapus
                                      </button>
                                    </td>
                                  </tr>
                                  <?php endforeach; ?>
                                </tbody>
                              </table>
                            </div>
                         <?php endif; ?>
                      </div>
                    <!-- End Tab Lengkapi Dokumen -->
                 </div> <!-- End tab-content -->
               </div> <!-- End card-body -->
             <!--end::Row-->
           </div>
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
    <!-- ============================================================ -->
    <!-- MODAL 1: Peringatan — belum pilih aset                       -->
    <!-- ============================================================ -->
    <div class="modal fade" id="modalPeringatan" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" style="max-width:480px;">
        <div class="modal-content border-0 shadow-lg overflow-hidden">
          <div class="modal-header" style="background:linear-gradient(135deg,#dc3545,#c82333); border:none;">
            <h5 class="modal-title text-white fw-semibold">
              <i class="bi bi-exclamation-triangle-fill me-2"></i>Peringatan
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body p-4">
            <div class="d-flex align-items-start" style="background:#fff3cd; border:1px solid #ffc107; border-radius:10px; padding:16px;">
              <i class="bi bi-info-circle-fill me-3 mt-1 flex-shrink-0" style="color:#856404; font-size:1.3rem;"></i>
              <div>
                <strong style="color:#856404;">Perhatian!</strong>
                <p class="mb-0 mt-1" style="color:#856404;">
                  Anda belum memilih aset untuk diusulkan penghapusan.<br>
                  Silakan pilih minimal <strong>1 aset</strong> dari tabel di atas.
                </p>
              </div>
            </div>
          </div>
          <div class="modal-footer" style="background:#f8f9fa; border-top:1px solid #eee;">
            <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">
              <i class="bi bi-x-circle me-1"></i> Tutup
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ============================================================ -->
    <!-- MODAL 2: Konfirmasi Submit ke Lengkapi Dokumen               -->
    <!-- ============================================================ -->
    <div class="modal fade" id="modalConfirmSubmit" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" style="max-width:500px;">
        <div class="modal-content border-0 shadow-lg overflow-hidden">
          <div class="modal-header" style="background:linear-gradient(135deg,#0d6efd,#0a58ca); border:none;">
            <h5 class="modal-title text-white fw-semibold">
              <i class="bi bi-send-check-fill me-2"></i>Konfirmasi Submit
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body p-4">
            <div class="d-flex align-items-start" style="background:#cfe2ff; border:1px solid #b6d3ff; border-radius:10px; padding:16px;">
              <i class="bi bi-info-circle-fill me-3 mt-1 flex-shrink-0" style="color:#084298; font-size:1.3rem;"></i>
              <div>
                <strong style="color:#084298;">Perhatian!</strong>
                <p class="mb-0 mt-1" style="color:#084298;">
                  Anda akan melakukan <strong>Submit Usulan Penghapusan</strong> untuk aset berikut:
                </p>
              </div>
            </div>
            <div class="d-flex align-items-center justify-content-center mt-3 p-3" style="background:#f0f7ff; border:2px dashed #0d6efd; border-radius:10px;">
              <i class="bi bi-layers-fill me-2" style="color:#0d6efd; font-size:1.5rem;"></i>
              <div class="text-center">
                <div class="fw-bold" style="color:#0d6efd; font-size:1.6rem;" id="submitAssetCount">0</div>
                <div class="text-muted small">Aset akan di-Submit</div>
              </div>
            </div>
            <div class="mt-3 p-2 px-3" style="background:#fff3cd; border:1px solid #ffc107; border-radius:8px;">
              <small style="color:#856404;">
                <i class="bi bi-clock-history me-1"></i>
                Setelah submit, aset akan dipindahkan ke tab <strong>"Lengkapi Dokumen"</strong> untuk pengisian berkas selanjutnya.
              </small>
            </div>
          </div>
          <div class="modal-footer" style="background:#f8f9fa; border-top:1px solid #eee;">
            <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">
              <i class="bi bi-x-circle me-1"></i> Batal
            </button>
            <button type="button" class="btn px-4 text-white fw-semibold" id="btnDoSubmit"
                    style="background:linear-gradient(135deg,#0d6efd,#0a58ca); border:none;">
              <i class="bi bi-send-check me-1"></i> Submit Usulan
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ============================================================ -->
    <!-- MODAL 3: Konfirmasi Simpan Draft                             -->
    <!-- ============================================================ -->
    <div class="modal fade" id="modalConfirmDraft" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" style="max-width:500px;">
        <div class="modal-content border-0 shadow-lg overflow-hidden">
          <div class="modal-header" style="background:linear-gradient(135deg,#6c757d,#495057); border:none;">
            <h5 class="modal-title text-white fw-semibold">
              <i class="bi bi-save-fill me-2"></i>Konfirmasi Simpan Draft
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body p-4">
            <div class="d-flex align-items-start" style="background:#e2e3e5; border:1px solid #d6d8db; border-radius:10px; padding:16px;">
              <i class="bi bi-info-circle-fill me-3 mt-1 flex-shrink-0" style="color:#383d41; font-size:1.3rem;"></i>
              <div>
                <strong style="color:#383d41;">Perhatian!</strong>
                <p class="mb-0 mt-1" style="color:#383d41;">
                  Anda akan menyimpan <strong>Usulan Penghapusan</strong> sebagai draft untuk aset berikut:
                </p>
              </div>
            </div>
            <div class="d-flex align-items-center justify-content-center mt-3 p-3" style="background:#f8f9fa; border:2px dashed #6c757d; border-radius:10px;">
              <i class="bi bi-layers-fill me-2" style="color:#6c757d; font-size:1.5rem;"></i>
              <div class="text-center">
                <div class="fw-bold" style="color:#6c757d; font-size:1.6rem;" id="draftAssetCount">0</div>
                <div class="text-muted small">Aset akan Disimpan sebagai Draft</div>
              </div>
            </div>
            <div class="mt-3 p-2 px-3" style="background:#fff3cd; border:1px solid #ffc107; border-radius:8px;">
              <small style="color:#856404;">
                <i class="bi bi-clock-history me-1"></i>
                Draft dapat diedit atau di-submit kembali nanti.
              </small>
            </div>
          </div>
          <div class="modal-footer" style="background:#f8f9fa; border-top:1px solid #eee;">
            <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">
              <i class="bi bi-x-circle me-1"></i> Batal
            </button>
            <button type="button" class="btn px-4 text-white fw-semibold" id="btnDoDraft"
                    style="background:linear-gradient(135deg,#6c757d,#495057); border:none;">
              <i class="bi bi-save me-1"></i> Simpan Draft
            </button>
          </div>
        </div>
      </div>
    </div>
    <!-- End Modal Confirm Draft -->
    
    <!-- ============================================================ -->
    <!-- MODAL: Konfirmasi Hapus Usulan -->
    <!-- ============================================================ -->
   <div class="modal fade" id="modalConfirmDeleteUsulan" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
          <div class="modal-header bg-danger text-white">
            <h5 class="modal-title">
              <i class="bi bi-trash me-2"></i>Konfirmasi Hapus Usulan
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-warning mb-3">
              <i class="bi bi-exclamation-triangle me-2"></i>
              <strong>Peringatan!</strong> Tindakan ini tidak dapat dibatalkan.
            </div>
            <p class="mb-2">Anda akan menghapus usulan penghapusan untuk aset:</p>
            <div class="p-3 bg-light rounded border">
              <div class="mb-2">
                <i class="bi bi-tag me-2"></i>
                <strong id="deleteUsulanNomor">-</strong>
              </div>
              <div>
                <i class="bi bi-file-earmark-text me-2"></i>
                <span id="deleteUsulanNama" class="text-muted">-</span>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
              <i class="bi bi-x-circle me-1"></i> Batal
            </button>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="action" value="delete_usulan">
              <input type="hidden" name="usulan_id" id="deleteUsulanId">
              <button type="submit" class="btn btn-danger">
                <i class="bi bi-trash me-1"></i> Ya, Hapus Usulan
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
    
    <!-- ============================================================ -->
    <!-- MODAL: Konfirmasi Cancel Draft -->
    <!-- ============================================================ -->
    <div class="modal fade" id="modalConfirmCancelDraft" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
          <div class="modal-header bg-warning text-dark">
            <h5 class="modal-title">
              <i class="bi bi-x-circle me-2"></i>Konfirmasi Batalkan Draft
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-info mb-3">
              <i class="bi bi-info-circle me-2"></i>
              <strong>Perhatian!</strong> Draft usulan akan dihapus dari sistem.
            </div>
            <p class="mb-2">Anda akan membatalkan draft usulan penghapusan untuk aset:</p>
            <div class="p-3 bg-light rounded border">
              <div class="mb-2">
                <i class="bi bi-tag me-2"></i>
                <strong id="cancelDraftNomor">-</strong>
              </div>
              <div>
                <i class="bi bi-file-earmark-text me-2"></i>
                <span id="cancelDraftNama" class="text-muted">-</span>
              </div>
            </div>
            <div class="mt-3 p-2 px-3" style="background:#fff3cd; border:1px solid #ffc107; border-radius:8px;">
              <small style="color:#856404;">
                <i class="bi bi-info-circle me-1"></i>
                Checkbox akan kembali tidak tercentang dan data draft akan dihapus.
              </small>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
              <i class="bi bi-x-circle me-1"></i> Batal
            </button>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="action" value="cancel_draft">
              <input type="hidden" name="draft_id" id="cancelDraftId">
              <button type="submit" class="btn btn-warning">
                <i class="bi bi-x-circle me-1"></i> Ya, Batalkan Draft
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
</div>
    
    <!-- ============================================================ -->
    <!-- MODAL: Sukses Dokumen Lengkap -->
    <!-- ============================================================ -->
    <div class="modal fade" id="modalSuksesDokumenLengkap" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
          <div class="modal-body text-center p-5">
            <div class="mb-4">
              <div class="rounded-circle bg-success d-inline-flex align-items-center justify-content-center" 
                   style="width: 80px; height: 80px;">
                <i class="bi bi-check-circle text-white" style="font-size: 3rem;"></i>
              </div>
            </div>
            <h4 class="mb-3 text-success fw-bold">Dokumen Berhasil Dilengkapi!</h4>
            <p class="text-muted mb-4">
              Data usulan penghapusan aset telah berhasil dilengkapi dan statusnya diubah menjadi 
              <span class="badge bg-success">Dokumen Lengkap</span>
            </p>
            <div class="alert alert-info mb-4">
              <i class="bi bi-info-circle me-2"></i>
              Data akan masuk ke <strong>Halaman Approval SubReg</strong> untuk proses selanjutnya.
            </div>
            <button type="button" class="btn btn-success px-4" data-bs-dismiss="modal">
              <i class="bi bi-check-circle me-1"></i> OK, Mengerti
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ============================================================ -->
    <!-- MODAL: Konfirmasi Hapus Draft (single row)                   -->
    <!-- ============================================================ -->
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

        // Auto show modal sukses dokumen lengkap jika ada flag dari session
        <?php if (isset($_SESSION['show_success_modal']) && $_SESSION['show_success_modal']): ?>
          const modalSukses = new bootstrap.Modal(document.getElementById('modalSuksesDokumenLengkap'));
          modalSukses.show();
          <?php unset($_SESSION['show_success_modal']); ?>
        <?php endif; ?>
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
            responsive: false,
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
                targets: 0,
                orderable: false,
                width: '50px',
                className: 'dt-body-center'
              }
            ],
            language: {
              url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json'
          },
            initComplete: function() {
            console.log('DataTable initialized successfully');
          }
        });
      });

// Initialize DataTable untuk tab Lengkapi Dokumen
        $('#lengkapiTable').DataTable({
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

// Auto-switch ke tab Lengkapi Dokumen jika ada hash #dokumen di URL
        if (window.location.hash === '#dokumen') {
            const lengkapiTab = new bootstrap.Tab(document.getElementById('lengkapi-dokumen-tab'));
            lengkapiTab.show();
        }

// Update info text saat berpindah tab
        document.getElementById('daftar-aset-tab').addEventListener('shown.bs.tab', function () {
            document.getElementById('infoTextContent').textContent = 'Pilih aset yang akan diusulkan untuk penghapusan';
        });

        document.getElementById('lengkapi-dokumen-tab').addEventListener('shown.bs.tab', function () {
            document.getElementById('infoTextContent').textContent = 'Lengkapi data pendukung untuk aset yang sudah diusulkan';
        });

// Function untuk konfirmasi hapus usulan
      function confirmDeleteUsulan(usulanId, nomorAset, namaAset) {
          document.getElementById('deleteUsulanId').value = usulanId;
          document.getElementById('deleteUsulanNomor').textContent = nomorAset;
          document.getElementById('deleteUsulanNama').textContent = namaAset || '-';
          
         
          const modal = new bootstrap.Modal(document.getElementById('modalConfirmDeleteUsulan'));
          modal.show();
      }

// Function untuk konfirmasi cancel draft
      function confirmCancelDraft(draftId, nomorAset, namaAset) {
          document.getElementById('cancelDraftId').value = draftId;
          document.getElementById('cancelDraftNomor').textContent = nomorAset;
          document.getElementById('cancelDraftNama').textContent = namaAset || '-';
          
          const modal = new bootstrap.Modal(document.getElementById('modalConfirmCancelDraft'));
          modal.show();
      }
        
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
            // Hanya hitung checkbox yang TIDAK disabled (bukan yang sudah di-submit)
            const count = $('.row-checkbox:checked:not(:disabled)').length;
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
        

        // =========================================================
        // saveData — buka modal yang sesuai
        // =========================================================
        function saveData(type) {
            const selectedIds = [];
            // Hanya ambil checkbox yang checked DAN tidak disabled
            $('.row-checkbox:checked:not(:disabled)').each(function() {
                selectedIds.push($(this).val());
            });

            // Kalau belum pilih aset → buka modal peringatan
            if (selectedIds.length === 0) {
                new bootstrap.Modal(document.getElementById('modalPeringatan')).show();
                return;
            }

            // Simpan di variable global supaya bisa dipakai pas tombol modal diklik
            window._selectedIds = selectedIds;

            if (type === 'draft') {
                $('#draftAssetCount').text(selectedIds.length);
                new bootstrap.Modal(document.getElementById('modalConfirmDraft')).show();
            } else {
                $('#submitAssetCount').text(selectedIds.length);
                new bootstrap.Modal(document.getElementById('modalConfirmSubmit')).show();
            }
        }



        // =========================================================
        // Handler tombol "Submit Usulan" di modal konfirmasi submit
        // =========================================================
        $('#btnDoSubmit').on('click', function() {
            // Ambil data lengkap (termasuk mekanisme dan fisik) untuk setiap aset yang dicentang
            const selectedAssets = [];
            window._selectedIds.forEach(function(assetId) {
                const row = $('input.row-checkbox[value="' + assetId + '"]').closest('tr');
                const mekanisme = row.find('.mekanisme-dropdown').val() || '';
                const fisik = row.find('.fisik-dropdown').val() || '';
                const nomorAset = row.find('.mekanisme-dropdown').data('nomor-aset') || '';
                
                selectedAssets.push({
                    id: assetId,
                    nomor_aset: nomorAset,
                    mekanisme: mekanisme,
                    fisik: fisik
                });
            });
            
            document.getElementById('selectedItemsInput').value = JSON.stringify(selectedAssets);
            document.getElementById('actionType').value = 'submit_data';

            // Disable tombol + spinner
            $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Memproses...');

            document.getElementById('saveForm').submit();
        });

        // =========================================================
        // Handler tombol "Simpan Draft" di modal konfirmasi draft
        // =========================================================
        $('#btnDoDraft').on('click', function() {
            // Ambil data lengkap (termasuk mekanisme dan fisik) untuk setiap aset yang dicentang
            const selectedAssets = [];
            window._selectedIds.forEach(function(assetId) {
                const row = $('input.row-checkbox[value="' + assetId + '"]').closest('tr');
                const mekanisme = row.find('.mekanisme-dropdown').val() || '';
                const fisik = row.find('.fisik-dropdown').val() || '';
                const nomorAset = row.find('.mekanisme-dropdown').data('nomor-aset') || '';
                
                selectedAssets.push({
                    id: assetId,
                    nomor_aset: nomorAset,
                    mekanisme: mekanisme,
                    fisik: fisik
                });
            });
            
            document.getElementById('selectedItemsInput').value = JSON.stringify(selectedAssets);
            document.getElementById('actionType').value = 'save_draft';
            $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Memproses...');
            document.getElementById('saveForm').submit();
        });
            document.getElementById('draftActionForm').submit();
        
        // =========================================================
        // Handler untuk dropdown Mekanisme Penghapusan dan Fisik Aset
        // =========================================================
        $(document).on('change', '.mekanisme-dropdown, .fisik-dropdown', function() {
            const dropdown = $(this);
            const assetId = dropdown.data('asset-id');
            const nomorAset = dropdown.data('nomor-aset');
            const fieldType = dropdown.hasClass('mekanisme-dropdown') ? 'mekanisme' : 'fisik';
            const value = dropdown.val();
            
            // Auto-save ke database via AJAX
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: {
                    action: 'update_dropdown_field',
                    asset_id: assetId,
                    nomor_aset: nomorAset,
                    field_type: fieldType,
                    value: value
                },
                success: function(response) {
                    // Optional: tampilkan notifikasi sukses
                    console.log('Auto-saved:', fieldType, value);
                },
                error: function() {
                    alert('Gagal menyimpan perubahan');
                    dropdown.val(dropdown.data('original-value') || '');
                }
            });
        });
    </script>

    <!-- MODAL: Form Lengkapi Dokumen -->
    <div class="modal fade" id="modalFormLengkapiDokumen" tabindex="-1" aria-labelledby="modalFormLengkapiDokumenLabel" aria-hidden="true">
      <div class="modal-dialog modal-xl">
        <div class="modal-content">
          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title" id="modalFormLengkapiDokumenLabel">
              <i class="bi bi-file-earmark-plus me-2"></i>Lengkapi Data Usulan Penghapusan Aset
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form id="formLengkapiDokumen" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="lengkapi_dokumen_submit">
            <input type="hidden" name="usulan_id" id="usulan_id">
            
            <div class="modal-body">
              
              <!-- Informasi Aset (Read-only) -->
              <div class="card mb-3">
                <div class="card-header bg-light">
                  <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Informasi Aset</h6>
                </div>
                <div class="card-body">
                  <div class="row">
                    <div class="col-md-6">
                      <dl class="row mb-0">
                        <dt class="col-sm-4">Nomor Aset:</dt>
                        <dd class="col-sm-8" id="display_nomor_aset">-</dd>
                        
                        <dt class="col-sm-4">Nama Aset:</dt>
                        <dd class="col-sm-8" id="display_nama_aset">-</dd>
                        
                        <dt class="col-sm-4">SubReg:</dt>
                        <dd class="col-sm-8" id="display_subreg">-</dd>

                        <dt class="col-sm-4">Profit Center:</dt>
                        <dd class="col-sm-8" id="display_profit_center">-</dd>

                        <dt class="col-sm-4">Kategori Aset:</dt>
                        <dd class="col-sm-8" id="display_kategori_aset">-</dd>
                      </dl>
                    </div>
                    <div class="col-md-6">
                      <dl class="row mb-0">
                        <dt class="col-sm-4">Umur Ekonomis:</dt>
                        <dd class="col-sm-8" id="display_umur_ekonomis">-</dd>

                        <dt class="col-sm-4">Sisa Umur:</dt>
                        <dd class="col-sm-8" id="display_sisa_umur">-</dd>

                        <dt class="col-sm-4">Tanggal Perolehan:</dt>
                        <dd class="col-sm-8" id="display_tgl_perolehan">-</dd>
                        
                        <dt class="col-sm-4">Nilai Buku:</dt>
                        <dd class="col-sm-8" id="display_nilai_buku">-</dd>
                        
                        <dt class="col-sm-4">Nilai Perolehan:</dt>
                        <dd class="col-sm-8" id="display_nilai_perolehan">-</dd>
                      </dl>
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
                      <select class="form-select" id="fisik_aset" name="fisik_aset" required onchange="toggleFotoUpload()">
                        <option value="">-- Pilih --</option>
                        <option value="Ada">Ada</option>
                        <option value="Tidak Ada">Tidak Ada</option>
                      </select>
                    </div>
                  </div>
                    
                  <div class="mb-3">
                    <label for="justifikasi_alasan" class="form-label">Justifikasi & Alasan Penghapusan <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="justifikasi_alasan" name="justifikasi_alasan" rows="3" required 
                      placeholder="Jelaskan alasan penghapusan aset..."></textarea>
                  </div>

                  <div class="mb-3">
                    <label for="kajian_hukum" class="form-label">Kajian Hukum <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="kajian_hukum" name="kajian_hukum" rows="3" required 
                      placeholder="Aspek hukum terkait penghapusan aset..."></textarea>
                  </div>

                  <div class="mb-3">
                    <label for="kajian_ekonomis" class="form-label">Kajian Ekonomis <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="kajian_ekonomis" name="kajian_ekonomis" rows="3" required 
                      placeholder="Analisis ekonomis penghapusan aset..."></textarea>
                  </div>

                  <div class="mb-3">
                    <label for="kajian_risiko" class="form-label">Kajian Risiko <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="kajian_risiko" name="kajian_risiko" rows="3" required 
                      placeholder="Identifikasi risiko terkait penghapusan..."></textarea>
                  </div>

              <!-- Row 6 - Upload Foto (conditional) -->
                  <div class="mb-3" id="fotoUploadSection" style="display:none;">
                    <label class="form-label">Foto Aset <span class="text-danger" id="fotoRequired">*</span></label>
                    <input type="file" class="form-control" id="fotoInput" name="foto" accept="image/jpeg,image/jpg,image/png" 
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

    <script>
    // Data usulan dalam format JSON untuk akses cepat
    const usulanLengkapiData = <?= json_encode($lengkapi_data) ?>;

    function openFormLengkapiDokumen(usulanId) {
        console.log("Klik Lengkapi dengan ID:", usulanId); 
        console.log("Data JSON:", usulanLengkapiData);

        const usulan = usulanLengkapiData.find(u => u.id == usulanId);
        
        if (!usulan) {
            alert('Data tidak ditemukan');
            return;
        }
        
        document.getElementById('usulan_id').value = usulanId;
        
        // Populate READ-ONLY fields (info aset)
        document.getElementById('display_nomor_aset').textContent = usulan.nomor_asset_utama || '-';
        document.getElementById('display_nama_aset').textContent = usulan.nama_aset || '-';
        document.getElementById('display_subreg').textContent = usulan.subreg || '-';
        document.getElementById('display_profit_center').textContent = usulan.profit_center + ' - ' + (usulan.profit_center_text || '');
        document.getElementById('display_kategori_aset').textContent = usulan.kategori_aset || '-';
        document.getElementById('display_umur_ekonomis').textContent = usulan.umur_ekonomis || '-';
        document.getElementById('display_sisa_umur').textContent = usulan.sisa_umur_ekonomis || '-';
        document.getElementById('display_tgl_perolehan').textContent = usulan.tgl_perolehan || '-';
        
        // Format currency untuk Nilai Buku dan Nilai Perolehan
        const nilaiBuku = usulan.nilai_buku ? 'Rp ' + parseInt(usulan.nilai_buku).toLocaleString('id-ID') : '-';
        const nilaiPerolehan = usulan.nilai_perolehan ? 'Rp ' + parseInt(usulan.nilai_perolehan).toLocaleString('id-ID') : '-';
        
        document.getElementById('display_nilai_buku').textContent = nilaiBuku;
        document.getElementById('display_nilai_perolehan').textContent = nilaiPerolehan;
        
        // Reset form terlebih dahulu
        document.getElementById('formLengkapiDokumen').reset();
        document.getElementById('usulan_id').value = usulanId; // Set lagi setelah reset
        
        // PRE-FILL FORM dengan data yang sudah ada (jika ada)
        if (usulan.jumlah_aset) {
            document.querySelector('input[name="jumlah_aset"]').value = usulan.jumlah_aset;
        }
        if (usulan.mekanisme_penghapusan) {
            document.querySelector('select[name="mekanisme_penghapusan"]').value = usulan.mekanisme_penghapusan;
        }
        if (usulan.fisik_aset) {
            document.querySelector('select[name="fisik_aset"]').value = usulan.fisik_aset;
            // Trigger toggleFotoUpload untuk show/hide foto section
            toggleFotoUpload();
        }
        if (usulan.justifikasi_alasan) {
            document.getElementById('justifikasi_alasan').value = usulan.justifikasi_alasan;
        }
        if (usulan.kajian_hukum) {
            document.getElementById('kajian_hukum').value = usulan.kajian_hukum;
        }
        if (usulan.kajian_ekonomis) {
            document.getElementById('kajian_ekonomis').value = usulan.kajian_ekonomis;
        }
        if (usulan.kajian_risiko) {
            document.getElementById('kajian_risiko').value = usulan.kajian_risiko;
        }
        
        // Tampilkan foto yang sudah ada (jika ada)
        if (usulan.foto_path) {
            document.getElementById('fotoPreviewImage').src = usulan.foto_path;
            document.getElementById('fotoPreview').style.display = 'block';
            document.getElementById('fotoUploadSection').style.display = 'block';
        } else {
            document.getElementById('fotoPreview').style.display = 'none';
        }
        
        var modal = new bootstrap.Modal(document.getElementById('modalFormLengkapiDokumen'));
        modal.show();
    }

    // Fungsi untuk toggle foto upload section berdasarkan pilihan Fisik Aset
    function toggleFotoUpload() {
        const fisikAset = document.getElementById('fisik_aset').value;
        const fotoSection = document.getElementById('fotoUploadSection');
        const fotoInput = document.getElementById('fotoInput');
        
        if (fisikAset === 'Ada') {
            // Show foto section dan set required
            fotoSection.style.display = 'block';
            fotoInput.setAttribute('required', 'required');
        } else if (fisikAset === 'Tidak Ada') {
            // Hide foto section dan remove required
            fotoSection.style.display = 'none';
            fotoInput.removeAttribute('required');
            fotoInput.value = ''; // Clear file input
            document.getElementById('fotoPreview').style.display = 'none';
        } else {
            // Default: hide
            fotoSection.style.display = 'none';
            fotoInput.removeAttribute('required');
        }
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

    // Fungsi untuk melihat foto yang sudah diupload
    function viewFoto(fotoPath, nomorAset, namaAset) {
        let title = 'Foto Aset: ' + nomorAset;
        if (namaAset) {
            title += ' (' + namaAset + ')';
        }
        document.getElementById('modalFotoTitle').textContent = title;
        document.getElementById('modalFotoImage').src = fotoPath;
        
        var modal = new bootstrap.Modal(document.getElementById('modalViewFoto'));
        modal.show();
    }

    // MODAL: View Foto
    </script>
    
    <div class="modal fade" id="modalViewFoto" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header bg-info text-white">
            <h5 class="modal-title" id="modalFotoTitle">Foto Aset</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body text-center">
            <img id="modalFotoImage" src="" class="img-fluid" style="max-height: 500px; border-radius: 8px;">
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
              <i class="bi bi-x-circle"></i> Tutup
            </button>
          </div>
        </div>
      </div>
    </div>

    <!--end::Script-->
  </body>
  <!--end::Body-->
</html>