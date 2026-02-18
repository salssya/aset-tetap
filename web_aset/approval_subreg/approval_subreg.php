<?php
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "asetreg3_db";

// Create connection
$con = mysqli_connect($servername, $username, $password, $dbname);
if (!$con) {
  http_response_code(500);
  echo '<h3 style="color:darkred">Database connection error:</h3><pre>' . htmlspecialchars(mysqli_connect_error()) . '</pre>';
  exit();
}
session_start();

if (!isset($_SESSION["nipp"]) || !isset($_SESSION["name"])) {
    header("Location: ../login/login_view.php");
    exit();
}
if (!isset($_SESSION['csrf_token'])) {
  try {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  } catch (Exception $e) {
    $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
  }
}

$userProfitCenter = isset($_SESSION['Cabang']) ? $_SESSION['Cabang'] : '';

// Ensure approval_history table exists (stores approve/reject events)
$create_history_sql = "CREATE TABLE IF NOT EXISTS approval_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usulan_id INT NOT NULL,
  action VARCHAR(50) NOT NULL,
  actor_nipp VARCHAR(50) DEFAULT NULL,
  actor_name VARCHAR(255) DEFAULT NULL,
  actor_role VARCHAR(100) DEFAULT NULL,
  note TEXT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
mysqli_query($con, $create_history_sql);

if (empty($_SESSION['profit_center_text']) && !empty($_SESSION['profit_center'])) {
  $pc_lookup = mysqli_real_escape_string($con, $_SESSION['profit_center']);
  $stmt_pc = mysqli_prepare($con, "SELECT profit_center_text FROM import_dat WHERE profit_center = ? LIMIT 1");
  if ($stmt_pc) {
    mysqli_stmt_bind_param($stmt_pc, 's', $pc_lookup);
    mysqli_stmt_execute($stmt_pc);
    $res_pc = mysqli_stmt_get_result($stmt_pc);
    if ($r = mysqli_fetch_assoc($res_pc)) {
      if (!empty($r['profit_center_text'])) $_SESSION['profit_center_text'] = $r['profit_center_text'];
    }
    mysqli_stmt_close($stmt_pc);
  }
}

if (empty($_SESSION['profit_center_text']) && !empty($_SESSION['Cabang'])) {
  $cabang_lookup = mysqli_real_escape_string($con, $_SESSION['Cabang']);
  $stmt_cb = mysqli_prepare($con, "SELECT profit_center_text FROM import_dat WHERE profit_center = ? LIMIT 1");
  if ($stmt_cb) {
    mysqli_stmt_bind_param($stmt_cb, 's', $cabang_lookup);
    mysqli_stmt_execute($stmt_cb);
    $res_cb = mysqli_stmt_get_result($stmt_cb);
    if ($r = mysqli_fetch_assoc($res_cb)) {
      if (!empty($r['profit_center_text'])) $_SESSION['profit_center_text'] = $r['profit_center_text'];
    }
    mysqli_stmt_close($stmt_cb);
  }
}

// Query hanya untuk profit center user dan nilai_perolehan_sd ≠ 0
$query = "SELECT * FROM import_dat 
          WHERE profit_center = ? 
          AND nilai_perolehan_sd != 0
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

//Query untuk tab "Lengkapi Data"
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

// Build filter condition for import_dat similar to dasbor.php logic
$filterConditionBase = "WHERE id.nilai_perolehan_sd <> 0 AND id.asset_class_name NOT LIKE '%AUC%'";
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

  if (empty($determinedSubreg) && !empty($_SESSION['subreg'])) {
    $determinedSubreg = $_SESSION['subreg'];
  }

  if (!empty($determinedSubreg)) {
    $filterCondition .= " AND id.subreg = '" . mysqli_real_escape_string($con, $determinedSubreg) . "'";
  }
} elseif (isset($_SESSION['Type_User']) && stripos($_SESSION['Type_User'], 'Cabang') !== false) {
  $userCabang = mysqli_real_escape_string($con, $_SESSION['Cabang'] ?? '');
  if ($userCabang !== '') {
    $filterCondition .= " AND id.profit_center = '" . $userCabang . "'";
  }
}

// Query khusus: tampilkan usulan yang sudah 'dokumen_lengkap' atau sudah 'submitted' dan menunggu approval SubReg
$query_subreg_pending = "SELECT up.*, 
       id.keterangan_asset as nama_aset, 
       id.asset_class_name as kategori_aset,
       id.subreg,
       id.profit_center_text
       FROM usulan_penghapusan up 
       LEFT JOIN import_dat id ON up.nomor_asset_utama = id.nomor_asset_utama 
       " . $filterCondition . " AND up.status IN ('dokumen_lengkap','submitted')
       AND COALESCE(up.status_approval_subreg, 'pending') IN ('pending','submitted')
       ORDER BY up.created_at DESC";

$result_subreg = mysqli_query($con, $query_subreg_pending);
$subreg_pending_data = [];
if ($result_subreg) {
  while ($row = mysqli_fetch_assoc($result_subreg)) {
    $row['nama_aset'] = str_replace('AUC-', '', $row['nama_aset']);
    $row['kategori_aset'] = str_replace('AUC-', '', $row['kategori_aset']);
    $subreg_pending_data[] = $row;
  }
}

// ========================================================
// Query untuk data Upload Dokumen
// - If user is a SubRegional/Cabang reviewer: show items awaiting SubReg approval
//   (use the $filterCondition built earlier so dasbor filters apply)
// - Otherwise (asset creator): show items created by the current user
// ========================================================

$upload_data = [];
// Reviewer view (SubRegional / Cabang) -> show items awaiting subreg approval
if (isset($_SESSION['Type_User']) && (stripos($_SESSION['Type_User'], 'Sub') !== false || stripos($_SESSION['Type_User'], 'Cabang') !== false)) {
  $uploadWhereClause = $filterCondition . " AND up.status IN ('dokumen_lengkap','submitted') AND COALESCE(up.status_approval_subreg, 'pending') IN ('pending','submitted')";

  $query_upload = "SELECT up.*, 
           id.keterangan_asset as nama_aset,
           id.profit_center_text,
           id.subreg,
           (SELECT COUNT(*) FROM dokumen_penghapusan WHERE usulan_id = up.id) as jumlah_dokumen
           FROM usulan_penghapusan up 
           LEFT JOIN import_dat id ON up.nomor_asset_utama = id.nomor_asset_utama 
           " . $uploadWhereClause . "
           ORDER BY up.updated_at DESC";

  $result_upload = mysqli_query($con, $query_upload);
  if ($result_upload) {
    while ($row = mysqli_fetch_assoc($result_upload)) {
      $row['nama_aset'] = str_replace('AUC-', '', $row['nama_aset']);
      $row['kategori_aset'] = isset($row['kategori_aset']) ? str_replace('AUC-', '', $row['kategori_aset']) : $row['kategori_aset'] ?? null;
      $upload_data[] = $row;
    }
  }

} else {
  // Creator view: only show assets created by the logged-in user
  $uploadWhereClause = "WHERE up.created_by = ? AND up.status IN ('lengkapi_dokumen','dokumen_lengkap')";
  if (isset($isSubRegional) && $isSubRegional) {
    $uploadWhereClause .= " AND (up.profit_center = ? OR up.subreg LIKE ?)";
  } elseif (isset($isCabang) && $isCabang) {
    $uploadWhereClause .= " AND up.profit_center = ?";
  }

  $query_upload = "SELECT up.*, 
           id.keterangan_asset as nama_aset,
           id.profit_center_text,
           id.subreg,
           (SELECT COUNT(*) FROM dokumen_penghapusan WHERE usulan_id = up.id) as jumlah_dokumen
           FROM usulan_penghapusan up 
           LEFT JOIN import_dat id ON up.nomor_asset_utama = id.nomor_asset_utama 
           " . $uploadWhereClause . "
           ORDER BY up.updated_at DESC";

  $stmt_upload = $con->prepare($query_upload);

  if (isset($isSubRegional) && $isSubRegional) {
    $subreg_pattern = $userProfitCenter . '%';
    $stmt_upload->bind_param("sss", $userNipp, $userProfitCenter, $subreg_pattern);
  } elseif (isset($isCabang) && $isCabang) {
    $stmt_upload->bind_param("ss", $userNipp, $userProfitCenter);
  } else {
    $stmt_upload->bind_param("s", $userNipp);
  }

  $stmt_upload->execute();
  $result_upload = $stmt_upload->get_result();

  while ($row = $result_upload->fetch_assoc()) {
    $row['nama_aset'] = str_replace('AUC-', '', $row['nama_aset']);
    $upload_data[] = $row;
  }
  $stmt_upload->close();
}

// Hitung jumlah untuk summary boxes
// - Pending: hanya yang berstatus 'dokumen_lengkap' dan menunggu approval SubReg
// - Approved: hitung semua usulan dengan kolom status_approval_subreg = 'approved' (terlepas dari nilai kolom status)
// - Rejected: hitung semua usulan dengan kolom status_approval_subreg yang menunjukkan rejected
$count_pending = 0;
$count_approved = 0;
$count_rejected = 0;

// Pending (dokumen_lengkap & awaiting SubReg)
$count_pending = 0;
$query_pending_count = "SELECT COUNT(*) AS cnt 
   FROM usulan_penghapusan up 
   LEFT JOIN import_dat id ON up.nomor_asset_utama = id.nomor_asset_utama 
   " . $filterCondition . " AND up.status IN ('dokumen_lengkap','submitted') AND COALESCE(up.status_approval_subreg, 'pending') IN ('pending','submitted')";
$res_p = mysqli_query($con, $query_pending_count);
if ($res_p) {
  $count_pending = intval((mysqli_fetch_assoc($res_p)['cnt']) ?? 0);
}

// Approved (semua baris yang status_approval_subreg = 'approved')
$count_approved = 0;
$query_approved_count = "SELECT COUNT(*) AS cnt 
   FROM usulan_penghapusan up 
   LEFT JOIN import_dat id ON up.nomor_asset_utama = id.nomor_asset_utama 
   " . $filterCondition . " AND up.status_approval_subreg = 'approved'";
$res_a = mysqli_query($con, $query_approved_count);
if ($res_a) {
  $count_approved = intval((mysqli_fetch_assoc($res_a)['cnt']) ?? 0);
}

// Rejected (include possible variants)
$count_rejected = 0;
$query_rejected_count = "SELECT COUNT(*) AS cnt 
   FROM usulan_penghapusan up 
   LEFT JOIN import_dat id ON up.nomor_asset_utama = id.nomor_asset_utama 
   " . $filterCondition . " AND COALESCE(up.status_approval_subreg, '') IN ('rejected','rejected_subreg')";
$res_r = mysqli_query($con, $query_rejected_count);
if ($res_r) {
  $count_rejected = intval((mysqli_fetch_assoc($res_r)['cnt']) ?? 0);
}

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
                    // Redirect ke tab Lengkapi Data di halaman yang sama
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

// Handle form lengkapi data
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

// Handle hapus usulan dari tab Lengkapi Data
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
// ========================================================
// HANDLER: Upload Dokumen Pendukung
// ========================================================
// Handle upload dokumen dengan support multiple assets
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_dokumen') {
    $usulan_ids = $_POST['usulan_id']; // Bisa comma-separated IDs
    $tahun_dokumen = $_POST['tahun_dokumen'];
    $tipe_dokumen = $_POST['tipe_dokumen'];
    $nipp = $_SESSION['nipp'];
    
    // Handle file upload
    if (isset($_FILES['file_dokumen']) && $_FILES['file_dokumen']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['file_dokumen'];
        $upload_dir = '../../uploads/dokumen_penghapusan/';
        
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Validate file
        $allowed_ext = ['pdf'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_ext, $allowed_ext)) {
            $_SESSION['warning_message'] = "Format file harus PDF!";
            header("Location: " . $_SERVER['PHP_SELF'] . "#upload");
            exit();
        }
        
        if ($file['size'] > 5 * 1024 * 1024) {
            $_SESSION['warning_message'] = "Ukuran file maksimal 5MB!";
            header("Location: " . $_SERVER['PHP_SELF'] . "#upload");
            exit();
        }
        
        // Generate filename
        $new_filename = 'DOK_' . date('YmdHis') . '_' . uniqid() . '.pdf';
        $file_path = $upload_dir . $new_filename;
        
        if (move_uploaded_file($file['tmp_name'], $file_path)) {
            // Split usulan IDs jika multiple
            $ids = explode(',', $usulan_ids);
            $success_count = 0;
            
            foreach ($ids as $id) {
                $id = trim($id);
                if (empty($id)) continue;
                
                // Get usulan data
                $query = "SELECT nomor_asset_utama, profit_center, subreg FROM usulan_penghapusan WHERE id = ?";
                $stmt = $con->prepare($query);
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $usulan = $result->fetch_assoc();
                    
                    // Prepare additional metadata and insert dokumen for setiap aset
                    $type_user = isset($_SESSION['Type_User']) ? $_SESSION['Type_User'] : '';
                    $profit_center_text = '';
                    if (!empty($usulan['nomor_asset_utama'])) {
                      $qimp = $con->prepare("SELECT profit_center_text, subreg FROM import_dat WHERE nomor_asset_utama = ? LIMIT 1");
                      if ($qimp) {
                        $qimp->bind_param("s", $usulan['nomor_asset_utama']);
                        $qimp->execute();
                        $rimp = $qimp->get_result();
                        if ($rimp && $rimp->num_rows > 0) {
                          $rowimp = $rimp->fetch_assoc();
                          $profit_center_text = $rowimp['profit_center_text'];
                          if (empty($usulan['subreg'])) {
                            $usulan['subreg'] = $rowimp['subreg'];
                          }
                        }
                        $qimp->close();
                      }
                    }

                    // Insert dokumen untuk setiap aset, termasuk profit_center_text dan type_user
                    $insert_query = "INSERT INTO dokumen_penghapusan 
                             (usulan_id, tahun_dokumen, tipe_dokumen, no_aset, subreg, profit_center, profit_center_text, type_user, nipp, file_name, file_path, file_size) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt_insert = $con->prepare($insert_query);
                    if ($stmt_insert) {
                      $stmt_insert->bind_param("iisssssssssi",
                        $id,
                        $tahun_dokumen,
                        $tipe_dokumen,
                        $usulan['nomor_asset_utama'],
                        $usulan['subreg'],
                        $usulan['profit_center'],
                        $profit_center_text,
                        $type_user,
                        $nipp,
                        $new_filename,
                        $file_path,
                        $file['size']
                      );

                      if ($stmt_insert->execute()) {
                        $success_count++;
                      }
                      $stmt_insert->close();
                    }
                }
                $stmt->close();
            }
            
            if ($success_count > 0) {
                $_SESSION['success_message'] = "✅ Berhasil upload dokumen untuk " . $success_count . " aset!";
            } else {
                $_SESSION['warning_message'] = "Gagal menyimpan dokumen ke database!";
            }
        } else {
            $_SESSION['warning_message'] = "Gagal upload file!";
        }
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . "#upload");
    exit();
}
// ========================================================
// HANDLER: Delete Dokumen
// ========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_dokumen') {
    $dokumen_id = intval($_POST['dokumen_id']);
    $user_nipp = $_SESSION['nipp'];
    
    // Get file path dan cek ownership
    $q = $con->prepare("SELECT dp.file_path, dp.usulan_id 
                        FROM dokumen_penghapusan dp 
                        JOIN usulan_penghapusan up ON dp.usulan_id = up.id 
                        WHERE dp.id_dokumen = ? AND up.created_by = ?");
    $q->bind_param("is", $dokumen_id, $user_nipp);
    $q->execute();
    $res = $q->get_result();
    
    if ($res->num_rows > 0) {
        $dok = $res->fetch_assoc();
        $file_path = $dok['file_path'];
        
        // Delete dari database
        $del = $con->prepare("DELETE FROM dokumen_penghapusan WHERE id_dokumen = ?");
        $del->bind_param("i", $dokumen_id);
        
        if ($del->execute()) {
            // Delete file dari server
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            $_SESSION['success_message'] = "✅ Dokumen berhasil dihapus.";
        } else {
            $_SESSION['error_message'] = "❌ Gagal menghapus dokumen.";
        }
        $del->close();
    } else {
        $_SESSION['error_message'] = "❌ Dokumen tidak ditemukan atau Anda tidak memiliki akses.";
    }
    $q->close();
    
    header("Location: " . $_SERVER['PHP_SELF'] . "#upload");
    exit();
}

// ========================================================
// HANDLER: Submit to Approval (SubReg)
// ========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_to_approval') {
    $usulan_id = intval($_POST['usulan_id']);
    $user_nipp = $_SESSION['nipp'];
    
    // Cek apakah ada dokumen yang sudah diupload
    $check_dok = $con->prepare("SELECT COUNT(*) as jml FROM dokumen_penghapusan WHERE usulan_id = ?");
    $check_dok->bind_param("i", $usulan_id);
    $check_dok->execute();
    $res_dok = $check_dok->get_result()->fetch_assoc();
    $check_dok->close();
    
    if ($res_dok['jml'] == 0) {
        $_SESSION['error_message'] = "❌ Minimal upload 1 dokumen sebelum submit ke approval.";
        header("Location: " . $_SERVER['PHP_SELF'] . "#upload");
        exit();
    }
    
    // Update status usulan
    $upd = $con->prepare("UPDATE usulan_penghapusan 
                          SET status = 'submitted', 
                              dokumen_uploaded = 1,
                              submitted_to_approval_at = NOW(),
                              updated_at = NOW()
                          WHERE id = ? 
                          AND created_by = ? 
                          AND status = 'dokumen_lengkap'");
    $upd->bind_param("is", $usulan_id, $user_nipp);
    
    if ($upd->execute() && $upd->affected_rows > 0) {
        $_SESSION['success_message'] = "✅ Usulan berhasil di-submit ke Approval SubReg!";
        $upd->close();
        
        // Redirect ke halaman approval subreg (opsional)
        // header("Location: ../approval_subreg/approval_subreg.php");
        header("Location: " . $_SERVER['PHP_SELF'] . "#summary");
        exit();
    } else {
        $_SESSION['error_message'] = "❌ Gagal submit usulan. Pastikan status usulan adalah 'dokumen_lengkap'.";
        $upd->close();
        header("Location: " . $_SERVER['PHP_SELF'] . "#upload");
        exit();
    }
}

  // ========================================================
  // HANDLER: Approve / Reject Usulan (AJAX from SubReg reviewer)
  // ========================================================
  if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve_usulan','reject_usulan'])) {
    header('Content-Type: application/json');
    $usulan_id = isset($_POST['usulan_id']) ? intval($_POST['usulan_id']) : 0;
    // CSRF check
    if (!isset($_POST['csrf_token']) || ($_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? ''))) {
      echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
      exit();
    }
    if ($usulan_id <= 0) {
      echo json_encode(['success' => false, 'error' => 'Invalid id']);
      exit();
    }

    $action = $_POST['action'];
    $new_status = ($action === 'approve_usulan') ? 'approved' : 'rejected';
    $new_subreg = ($action === 'approve_usulan') ? 'approved' : 'rejected';

    $upd = $con->prepare("UPDATE usulan_penghapusan SET status = ?, status_approval_subreg = ?, updated_at = NOW() WHERE id = ?");
    if (!$upd) {
      echo json_encode(['success' => false, 'error' => 'Prepare failed']);
      exit();
    }
    $upd->bind_param("ssi", $new_status, $new_subreg, $usulan_id);
    if ($upd->execute()) {
      // Insert into approval_history (include optional reject note)
      $history_action = ($action === 'approve_usulan') ? 'approve_subreg' : 'reject_subreg';
      $actor_nipp = $_SESSION['nipp'] ?? '';
      $actor_name = $_SESSION['name'] ?? '';
      $actor_role = $_SESSION['role'] ?? 'SubReg';
      $note = isset($_POST['note']) ? trim($_POST['note']) : null;
      $ins = $con->prepare("INSERT INTO approval_history (usulan_id, action, actor_nipp, actor_name, actor_role, note) VALUES (?, ?, ?, ?, ?, ?)");
      if ($ins) {
        $ins->bind_param("isssss", $usulan_id, $history_action, $actor_nipp, $actor_name, $actor_role, $note);
        $ins->execute();
        $ins->close();
      }

      echo json_encode(['success' => true]);
    } else {
      echo json_encode(['success' => false, 'error' => $upd->error]);
    }
    $upd->close();
    exit();
  }


// ========================================================
// AJAX HANDLER: GET Detail Aset by nomor_asset_utama
// ========================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_detail_aset' && isset($_GET['no_aset'])) {
    header('Content-Type: application/json');
    $no_aset = trim($_GET['no_aset']);

    // Ambil data dari import_dat
    $stmt_da = $con->prepare(
        "SELECT id.nomor_asset_utama, id.keterangan_asset, id.profit_center,
                id.subreg, id.profit_center_text,
                up.status AS status_penghapusan
         FROM import_dat id
         LEFT JOIN usulan_penghapusan up
               ON id.nomor_asset_utama = up.nomor_asset_utama
              AND up.created_by = ?
         WHERE id.nomor_asset_utama = ?
         LIMIT 10"
    );
    $stmt_da->bind_param("ss", $_SESSION['nipp'], $no_aset);
    $stmt_da->execute();
    $res_da = $stmt_da->get_result();
    $rows_da = [];
    while ($r = $res_da->fetch_assoc()) {
        // Format status penghapusan jadi label lebih rapi
        $status_map = [
            'draft'           => 'Draft',
            'lengkapi_dokumen'=> 'Lengkapi Data',
            'dokumen_lengkap' => 'Siap Upload',
            'submitted'       => 'Submitted',
            'approved_subreg' => 'Approved SubReg',
            'approved'        => 'Approved',
            'rejected'        => 'Rejected',
        ];
        $r['status_penghapusan'] = isset($r['status_penghapusan']) && $r['status_penghapusan']
            ? ($status_map[$r['status_penghapusan']] ?? ucfirst($r['status_penghapusan']))
            : '';
        $rows_da[] = $r;
    }
    $stmt_da->close();

    echo json_encode(['status' => 'success', 'data' => $rows_da]);
    exit();
}

// ========================================================
// AJAX: Get approval history for a usulan
// ========================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_approval_history' && isset($_GET['usulan_id'])) {
    header('Content-Type: application/json');
    $uid = intval($_GET['usulan_id']);
    $stmt_h = $con->prepare("SELECT action, actor_nipp, actor_name, actor_role, note, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at FROM approval_history WHERE usulan_id = ? ORDER BY created_at DESC");
    $stmt_h->bind_param("i", $uid);
    $stmt_h->execute();
    $res_h = $stmt_h->get_result();
    $rows = [];
    while ($r = $res_h->fetch_assoc()) $rows[] = $r;
    $stmt_h->close();
    echo json_encode(['success' => true, 'data' => $rows]);
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
        
        $ins->bind_param("sssss", $no_aset, $userProfitCenter, $_SESSION['nipp'], $mekanisme_val, $fisik_val);
        
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
        header("Location: ../usulan_penghapusan_aset/usulan_penghapusan_aset.php");
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

function saveSelectedAssets($con, $selected_data, $is_submit, $created_by, $userProfitCenter) {
    $saved_count = 0;
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
        $asset_id = isset($asset_data['id']) ? $asset_data['id'] : $asset_data; // Backward compatibility
        $mekanisme_pilihan = isset($asset_data['mekanisme']) && !empty($asset_data['mekanisme']) ? $asset_data['mekanisme'] : null;
        $fisik_pilihan = isset($asset_data['fisik']) && !empty($asset_data['fisik']) ? $asset_data['fisik'] : null;
        
        $query = "SELECT * FROM import_dat WHERE id = ? AND profit_center = ?";
        $get_stmt = $con->prepare($query);
        $get_stmt->bind_param("is", $asset_id, $userProfitCenter);
        $get_stmt->execute();
        $result = $get_stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $check = $con->prepare("SELECT id, status FROM usulan_penghapusan WHERE nomor_asset_utama = ? AND created_by = ?");
            $check->bind_param("ss", $row['nomor_asset_utama'], $created_by);
            $check->execute();
            $check_result = $check->get_result();

            if ($check_result->num_rows > 0) {
                
                $existing = $check_result->fetch_assoc();
                $check->close();
                
                if ($is_submit && $existing['status'] === 'draft') {
                    $upd = $con->prepare("UPDATE usulan_penghapusan SET status = 'lengkapi_dokumen', updated_at = NOW() WHERE id = ?");
                    $upd->bind_param("i", $existing['id']);
                    if ($upd->execute()) {
                        $saved_count++; 
                    }
                    $upd->close();
                }
                continue;
            }
            $check->close();
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
                $mekanisme_pilihan,  
                $fisik_pilihan,        
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
      <nav class="app-header navbar navbar-expand bg-white border-0 shadow-none" style="border-bottom:0!important;box-shadow:none!important;">
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
              <div class="col-sm-6"><h3 class="mb-0">Approval Sub Regional</h3></div>
              <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                  <li class="breadcrumb-item"><a href="../dasbor/dasbor.php">Home</a></li>
                  <li class="breadcrumb-item active">Approval Sub Regional</li>
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
            <div class="row">
              <div class="col-12">
                <div class="card">
                  <div class="card-body">

                    <!-- Nav tabs -->
                    <ul class="nav nav-tabs mb-3" id="myTab" role="tablist">
                      <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="upload-dokumen-tab" data-bs-toggle="tab" data-bs-target="#upload" type="button" role="tab" aria-controls="upload" aria-selected="true">
                          <i class="bi bi-cloud-upload me-2"></i>Upload Dokumen
                          <span class="badge bg-primary ms-1"><?= count($upload_data) ?></span>
                        </button>
                      </li>
                      <li class="nav-item" role="presentation">
                        <button class="nav-link" id="daftar-aset-tab" data-bs-toggle="tab" data-bs-target="#aset" type="button" role="tab" aria-controls="aset" aria-selected="false">
                          <i class="bi bi-list-ul me-2"></i>Daftar Approval
                        </button>
                      </li>
                    </ul>
                    <!-- Tab Content -->
                    <div class="tab-content" id="usulanTabsContent">
                      <!-- Tab 1: Upload Dokumen -->
                      <div class="tab-pane fade show active" id="upload" role="tabpanel">
                            <?php
                            // Tampilkan pesan error/success dari session untuk tab upload
                            if (isset($_SESSION['error_message'])) {
                                echo '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle me-2"></i>' . htmlspecialchars($_SESSION['error_message']) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
                                unset($_SESSION['error_message']);
                            }
                            ?>
                            <div class="card mb-0" style="border: 1px solid #dee2e6; border-radius: 4px;">
                              <div class="card-header" style="background: #fff; border-bottom: 1px solid #dee2e6; padding: 12px 20px;">
                                <strong style="font-size: 1rem;">Form Upload Dokumen</strong>
                              </div>
                              <div class="card-body" style="padding: 20px;">

                                <?php if (empty($upload_data)): ?>
                                  <div class="alert alert-warning mb-0">
                                    <i class="bi bi-info-circle me-2"></i>
                                    <strong>Belum ada usulan</strong>
                                  </div>
                                <?php else: ?>

                                <form method="POST" enctype="multipart/form-data" id="formUploadInline" novalidate>
                                  <input type="hidden" name="action" value="upload_dokumen">
                                  <input type="hidden" name="usulan_id" id="inlineUsulanId" value="">

                                  <!-- Baris 1: Deskripsi Dokumen -->
                                  <div class="mb-3">
                                    <label class="form-label" style="font-weight: normal;">Deskripsi Dokumen</label>
                                    <input type="text" class="form-control" name="tipe_dokumen" id="inlineTipeDokumen"
                                           style="border: 1px solid #dee2e6; border-radius: 4px;"
                                           maxlength="100" required>
                                    <div class="invalid-feedback">Isi deskripsi dokumen terlebih dahulu.</div>
                                  </div>

                                  <div class="mb-3">
                                    <label class="form-label" style="font-weight: normal;">
                                      Tahun Dokumen <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-control" name="tahun_dokumen" id="inlineTahunDokumen" 
                                            style="border: 1px solid #dee2e6; border-radius: 4px;" required>
                                      <option value="">-- Pilih Tahun --</option>
                                      <?php
                                      $current_year = date('Y');
                                      for ($y = $current_year; $y >= 2015; $y--) {
                                          $selected = ($y == $current_year) ? 'selected' : '';
                                          echo "<option value=\"$y\" $selected>$y</option>";
                                      }
                                      ?>
                                    </select>
                                    <div class="invalid-feedback">Pilih tahun dokumen.</div>
                                  </div>

                                  <!-- Baris 2: Nomor Aset -->
                                  <div class="mb-3">
                                    <label class="form-label" style="font-weight: normal;">Nomor Aset</label>
                                    <div class="input-group">
                                      <input type="text" class="form-control" id="inlineNomorAset"
                                             placeholder="Masukkan nomor aset atau pilih"
                                             style="border: 1px solid #dee2e6; border-radius: 4px 0 0 4px;"
                                             readonly>
                                      <button type="button" class="btn btn-outline-primary" id="btnPilihNomorAset"
                                              style="border-radius: 0 4px 4px 0; border-color: #dee2e6;"
                                              onclick="openAsetPickerUpload()">
                                        <i class="bi bi-search me-1"></i> Pilih Nomor Aset
                                      </button>
                                      
                                    </div>
                                    <div class="invalid-feedback" id="inlineNomorAsetError" style="display:none;">Pilih nomor aset terlebih dahulu.</div>
                                  </div>

                                  <!-- Baris 3: Choose File -->
                                  <div class="mb-1">
                                    <input type="file" class="form-control" name="file_dokumen" id="inlineFileDokumen"
                                           accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                           style="border: 1px solid #dee2e6; border-radius: 4px;"
                                           required>
                                    <div class="invalid-feedback">Pilih file yang akan diupload.</div>
                                  </div>
                                  <div class="mb-3" style="font-size: 0.85rem; color: #6c757d;">
                                    Format yang didukung: <strong>.pdf</strong>. Maksimal 5MB.
                                  </div>

                                  <!-- Tombol Upload -->
                                  <button type="submit" class="btn btn-primary" id="btnUploadInline"
                                          style="background: #0d6efd; border: none; padding: 8px 20px; border-radius: 4px;">
                                    <i class="bi bi-cloud-upload me-1"></i> Upload
                                  </button>
                                </form>
                                <?php endif; ?>
                              </div>
                            </div>
                            <?php
                            // Initialize $semua_dokumen sebelum if statement agar bisa diakses di luar scope
                            $semua_dokumen = [];
                            if (!empty($upload_data)): ?>

                     <!-- TABEL PREVIEW DOKUMEN-->
                            <?php
                            
                            foreach ($upload_data as $usulan) {
                                $q_dok = $con->prepare(
                                    "SELECT dp.id_dokumen, dp.tahun_dokumen, dp.profit_center, dp.subreg,
                                            dp.tipe_dokumen, dp.profit_center_text, dp.file_path, dp.file_name,
                                            up.nomor_asset_utama, up.id as usulan_id
                                     FROM dokumen_penghapusan dp
                                     JOIN usulan_penghapusan up ON dp.usulan_id = up.id
                                     WHERE dp.usulan_id = ?
                                     ORDER BY dp.id_dokumen DESC"
                                );
                                $q_dok->bind_param("i", $usulan['id']);
                                $q_dok->execute();
                                $r_dok = $q_dok->get_result();
                                while ($d = $r_dok->fetch_assoc()) {
                                    $semua_dokumen[] = $d;
                                }
                                $q_dok->close();
                            }
                            ?>
                            <div class="card mt-3" style="border: none;">
                              <div class="card-header" style="background: #5a6268; color: white; padding: 10px 16px; border-radius: 4px 4px 0 0;">
                                <strong>Preview Dokumen</strong>
                              </div>
                              <div class="card-body p-0">
                                <div class="table-responsive">
                                  <table id="uploadTable" class="table table-bordered table-hover mb-0" style="font-size: 0.9rem;">
                                    <thead style="background: #f8f9fa;">
                                      <tr>
                                        <th style="width: 50px;">ID</th>
                                        <th style="width: 70px;">Tahun</th>
                                        <th>Profit Center</th>
                                        <th>Subreg</th>
                                        <th>Deskripsi Dokumen</th>
                                        <th>Cabang</th>
                                        <th style="width: 220px;">Aksi</th>
                                      </tr>
                                    </thead>
                                    <tbody>
                                      <?php if (empty($semua_dokumen)): ?>
                                        <tr>
                                          <td colspan="7" class="text-center text-muted py-3">
                                            Belum ada dokumen yang diupload
                                          </td>
                                        </tr>
                                      <?php else: ?>
                                        <?php foreach ($semua_dokumen as $dok): ?>
                                        <tr>
                                          <td><?= $dok['id_dokumen'] ?></td>
                                          <td><?= htmlspecialchars($dok['tahun_dokumen'] ?? date('Y')) ?></td>
                                          <td><?= htmlspecialchars($dok['profit_center'] ?? '') ?></td>
                                          <td><?= htmlspecialchars($dok['subreg'] ?? '') ?></td>
                                          <td><?= htmlspecialchars($dok['tipe_dokumen'] ?? '') ?></td>
                                          <td><?= htmlspecialchars($dok['profit_center_text'] ?? '') ?></td>
                                          <td style="white-space: nowrap;">
                                            <!-- Lihat Dokumen -->
                                            <a href="<?= htmlspecialchars($dok['file_path'] ?? '#') ?>"
                                               target="_blank"
                                               class="btn btn-sm btn-outline-secondary"
                                               style="margin-right: 4px; font-size: 0.8rem; padding: 2px 8px;">
                                              Lihat Dokumen
                                            </a>
                                            <!-- Detail Aset -->
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-info"
                                                    style="margin-right: 4px; font-size: 0.8rem; padding: 2px 8px;"
                                                    onclick="showDetailAset('<?= htmlspecialchars(addslashes($dok['nomor_asset_utama'])) ?>')">
                                              Detail Aset
                                            </button>
                                            <!-- Hapus -->
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-danger"
                                                    style="font-size: 0.8rem; padding: 2px 8px;"
                                                    onclick="confirmDeleteDokumen(<?= $dok['id_dokumen'] ?>, '<?= htmlspecialchars(addslashes($dok['tipe_dokumen'])) ?>')">
                                              Hapus
                                            </button>
                                          </td>
                                        </tr>
                                        <?php endforeach; ?>
                                      <?php endif; ?>
                                    </tbody>
                                  </table>
                                </div>
                              </div>
                            </div>
                            <?php endif; ?>
                          </div>
                          <!-- End Tab Upload Dokumen -->
                      </div>
                      <!-- End Tab Upload Dokumen Pane -->
                                                  <div class="modal fade" id="modalAsetPickerUpload" tabindex="-1" aria-hidden="true">
                              <div class="modal-dialog modal-xl modal-dialog-centered">
                                <div class="modal-content">
                                  <div class="modal-header bg-primary text-white">
                                    <h5 class="modal-title">
                                      <i class="bi bi-search me-2"></i>Pilih Nomor Aset
                                    </h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                  </div>
                                  <div class="modal-body">
                                    <!-- Info Alert -->
                                    <div class="alert alert-info d-flex align-items-center mb-3">
                                      <i class="bi bi-info-circle me-2"></i>
                                      <div>
                                        <strong>Multiple Select:</strong> Centang beberapa aset jika 1 dokumen berlaku untuk beberapa aset sekaligus
                                      </div>
                                      <!-- Modal: Confirm Approve/Reject (moved to end of body) -->
                                    </div>
                                    
                                    <!-- Selection Counter -->
                                    <div id="selectedAssetCount" class="alert alert-success" style="display:none;">
                                      <i class="bi bi-check-circle me-2"></i>
                                      <strong><span id="countNumber">0</span> aset dipilih</strong>
                                    </div>
                                    
                                    <!-- Table -->
                                    <div class="table-responsive">
                                      <table class="table table-bordered table-hover table-sm" id="asetPickerUploadTable">
                                        <thead class="table-light">
                                          <tr>
                                            <th style="width: 50px;">
                                              <input type="checkbox" id="selectAllAssets" class="form-check-input">
                                            </th>
                                            <th>Nomor Aset</th>
                                            <th>Mekanisme Penghapusan</th>
                                            <th>Nama Aset</th>
                                            <th>Kategori</th>
                                            <th>Profit Center</th>
                                          </tr>
                                        </thead>
                                        <tbody>
                                          <?php foreach ($upload_data as $ua): ?>
                                          <tr>
                                            <td class="text-center">
                                              <input type="checkbox" 
                                                    class="form-check-input asset-checkbox" 
                                                    value="<?= $ua['id'] ?>"
                                                    data-nomor="<?= htmlspecialchars($ua['nomor_asset_utama']) ?>"
                                                    data-nama="<?= htmlspecialchars(str_replace('AUC-', '', $ua['nama_aset'] ?? '-')) ?>">
                                            </td>
                                            <td><?= htmlspecialchars($ua['nomor_asset_utama']) ?></td>
                                            <td>
                                              <?= !empty($ua['mekanisme_penghapusan']) ? htmlspecialchars($ua['mekanisme_penghapusan']) : '-' ?> 
                                            </td>
                                            <td><?= htmlspecialchars(str_replace('AUC-', '', $ua['nama_aset'] ?? '-')) ?></td>
                                            <td><?= htmlspecialchars($ua['kategori_aset'] ?? '-') ?></td>
                                             <td><?= htmlspecialchars($ua['profit_center'] ?? '') . (!empty($ua['profit_center_text']) ? ' - ' . htmlspecialchars($ua['profit_center_text']) : '') ?></td>
                                          </tr>                                       
                                          <?php endforeach; ?>
                                        </tbody>
                                      </table>
                                    </div>
                                  </div>
                                  <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                      <i class="bi bi-x-circle me-1"></i> Batal
                                    </button>
                                    <button type="button" class="btn btn-primary" id="btnConfirmSelectAssets">
                                      <i class="bi bi-check-circle me-1"></i> Konfirmasi Pilihan
                                    </button>
                                  </div>
                                </div>
                              </div>
                            </div>
                          <!-- End Modal Aset Picker -->

                          
                          
                          <!-- Modal: Detail Data Aset dengan Mekanisme Penghapusan -->
                          <div class="modal fade" id="modalDetailAset" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-xl modal-dialog-centered">
                              <div class="modal-content" style="border-radius: 6px; overflow: hidden;">
                                <!-- Header -->
                                <div class="modal-header" style="background: #fff; border-bottom: 2px solid #0d6efd; padding: 14px 20px;">
                                  <div>
                                    <div class="d-flex align-items-center mb-1">
                                      <i class="bi bi-table me-2" style="color: #0d6efd; font-size: 1.1rem;"></i>
                                      <h5 class="modal-title mb-0 fw-bold" style="color: #0d6efd;">Detail Data Aset</h5>
                                    </div>
                                    <div style="font-size: 0.875rem; color: #555;">
                                      Profit Center: <strong id="detailAsetPC">-</strong>
                                      &nbsp;|&nbsp; Subreg: <strong id="detailAsetSubreg">-</strong>
                                    </div>
                                  </div>
                                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <!-- Body — tabel data aset -->
                                <div class="modal-body p-0">
                                  <div class="table-responsive">
                                    <table class="table mb-0" style="border-collapse: collapse;">
                                      <thead class="table-light">
                                        <tr>
                                          <th style="width: 50px;">No</th>
                                          <th style="width: 180px;">Nomor Aset</th>
                                          <th>Nama Aset</th>
                                          <th style="width: 150px;">Mekanisme</th>
                                          <th style="width: 120px;">Status</th>
                                        </tr>
                                      </thead>
                                      <tbody id="detailAsetTbody">
                                        <tr>
                                          <td colspan="5" class="text-center py-3 text-muted">Memuat data...</td>
                                        </tr>
                                      </tbody>
                                    </table>
                                  </div>
                                </div>
                                <!-- Footer -->
                                <div class="modal-footer" style="background: #f8f9fa; border-top: 1px solid #dee2e6; padding: 10px 20px; justify-content: space-between;">
                                  <span id="detailAsetTotal" style="font-size: 0.875rem; color: #555;"></span>
                                  <button type="button" class="btn btn-secondary btn-sm px-4"
                                          data-bs-dismiss="modal"
                                          style="background: #6c757d; border: none; border-radius: 4px;">
                                    Tutup
                                  </button>
                                </div>
                              </div>
                            </div>
                          </div>

                          <!-- End Modal Detail Aset -->

                          <!-- JavaScript for Upload Tab -->
                          <script>
                          // Initialize DataTable for preview dokumen
                          $(document).ready(function() {
                            if ($('#uploadTable').length) {
                              $('#uploadTable').DataTable({
                                responsive: false,
                                autoWidth: false,
                                paging: true,
                                pageLength: 10,
                                searching: true,
                                ordering: true,
                                info: true,
                                language: {
                                  url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json'
                                },
                                columnDefs: [
                                  { orderable: false, targets: [6] }
                                ]
                              });
                            }

                            if ($('#asetPickerUploadTable').length) {
                              $('#asetPickerUploadTable').DataTable({
                                responsive: false,
                                pageLength: 10,
                                language: {
                                  url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json'
                                }
                              });
                            }
                          });

                          /** Buka modal picker aset */
                          function openAsetPickerUpload() {
                            const modal = new bootstrap.Modal(document.getElementById('modalAsetPickerUpload'));
                            modal.show();
                          }

                          /** Pilih aset dari modal picker */
                          function pilihAsetDariModal(usulanId, nomorAset) {
                            document.getElementById('inlineUsulanId').value = usulanId;
                            document.getElementById('inlineNomorAset').value = nomorAset;
                            // Tutup modal
                            bootstrap.Modal.getInstance(document.getElementById('modalAsetPickerUpload')).hide();
                            // Focus ke deskripsi
                            setTimeout(function() {
                              document.getElementById('inlineTipeDokumen').focus();
                            }, 300);
                          }

                          /** Fungsi Detail Aset — buka modal dengan data dari DB */
                          function showDetailAset(nomorAset) {
                            const tbody = document.getElementById('detailAsetTbody');
                            const pcEl = document.getElementById('detailAsetPC');
                            const subEl = document.getElementById('detailAsetSubreg');
                            const totalEl = document.getElementById('detailAsetTotal');

                            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-muted">Memuat data...</td></tr>';
                            pcEl.textContent  = '-';
                            subEl.textContent = '-';
                            totalEl.textContent = '';

                            const modal = new bootstrap.Modal(document.getElementById('modalDetailAset'));
                            modal.show();

                            // Fetch data aset via AJAX
                            fetch(location.pathname + '?action=get_detail_aset&no_aset=' + encodeURIComponent(nomorAset), {
                              credentials: 'same-origin'
                            })
                            .then(function(res) { return res.json(); })
                            .then(function(json) {
                              if (json.status === 'success' && json.data && json.data.length > 0) {
                                const row = json.data[0];
                                pcEl.textContent  = row.profit_center || '-';
                                subEl.textContent = row.subreg || '-';
                                totalEl.textContent = 'Total: ' + json.data.length + ' aset dimuat';
                                tbody.innerHTML = '';
                                json.data.forEach(function(r, i) {
                                  const tr = document.createElement('tr');
                                  tr.style.background = (i % 2 === 0) ? '#f8f9fa' : '#fff';
                                  
                                  // Badge untuk mekanisme (dengan fallback property names)
                                  let mekanismeBadge = '-';
                                  const mekanismeVal = r.mekanisme_penghapusan || r.mekanisme || r.mekanisme_penghapusan_text || '';
                                  if (mekanismeVal) {
                                    const badgeClass = mekanismeVal === 'Jual Lelang' ? 'success' : 'warning';
                                    mekanismeBadge = '<span class="badge bg-' + badgeClass + '">' + escHtml(mekanismeVal) + '</span>';
                                  }

                                  // Badge untuk status (dengan fallback property names)
                                  let statusBadge = '-';
                                  const statusVal = r.status_penghapusan || r.status_penghapusan_text || r.status || '';
                                  if (statusVal) {
                                    const statusClass = statusVal === 'Siap Upload' ? 'info' :
                                                       statusVal === 'Lengkapi Data' ? 'warning' : 'secondary';
                                    statusBadge = '<span class="badge bg-' + statusClass + '">' + escHtml(statusVal) + '</span>';
                                  }
                                  
                                  tr.innerHTML =
                                    '<td style="padding: 10px 16px; color: #555;">' + (i + 1) + '</td>' +
                                    '<td style="padding: 10px 16px; font-weight: 500;">' + escHtml(r.nomor_asset_utama || '') + '</td>' +
                                    '<td style="padding: 10px 16px; color: #0d6efd;">' + escHtml(r.keterangan_asset || r.nama_aset || '-') + '</td>' +
                                    '<td style="padding: 10px 16px;">' + mekanismeBadge + '</td>' +
                                    '<td style="padding: 10px 16px;">' + statusBadge + '</td>';
                                  tbody.appendChild(tr);
                                });
                              } else {
                                pcEl.textContent = '-';
                                subEl.textContent = '-';
                                totalEl.textContent = 'Total: 0 aset dimuat';
                                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Data aset tidak ditemukan</td></tr>';
                              }
                            })
                            .catch(function(err) {
                              tbody.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-3">Gagal memuat data: ' + err.message + '</td></tr>';
                            });
                          }

                          function escHtml(str) {
                            const d = document.createElement('div');
                            d.appendChild(document.createTextNode(str));
                            return d.innerHTML;
                          }

                          /** Validasi form sebelum submit */
                          document.addEventListener('DOMContentLoaded', function() {
                            const form = document.getElementById('formUploadInline');
                            if (!form) return;
                            form.addEventListener('submit', function(e) {
                              let valid = true;

                              // Cek usulan_id (nomor aset sudah dipilih)
                              const usulanId   = document.getElementById('inlineUsulanId').value;
                              const nomorInput = document.getElementById('inlineNomorAset');
                              const nomorErr   = document.getElementById('inlineNomorAsetError');
                              if (!usulanId || usulanId === '0' || usulanId === '') {
                                nomorInput.classList.add('is-invalid');
                                nomorErr.style.display = 'block';
                                valid = false;
                              } else {
                                nomorInput.classList.remove('is-invalid');
                                nomorErr.style.display = 'none';
                              }

                              // Cek deskripsi dokumen
                              const tipeInput = document.getElementById('inlineTipeDokumen');
                              if (!tipeInput.value.trim()) {
                                tipeInput.classList.add('is-invalid');
                                valid = false;
                              } else {
                                tipeInput.classList.remove('is-invalid');
                              }

                              // Cek file
                              const fileInput = document.getElementById('inlineFileDokumen');
                              if (!fileInput.files || fileInput.files.length === 0) {
                                fileInput.classList.add('is-invalid');
                                valid = false;
                              } else {
                                fileInput.classList.remove('is-invalid');
                              }

                              if (!valid) {
                                e.preventDefault();
                                e.stopPropagation();
                                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                              } else {
                                const btn = document.getElementById('btnUploadInline');
                                btn.disabled = true;
                                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Mengupload...';
                              }
                            });
                          });
                          </script>
                          <!-- Tab 2: Daftar Approval -->
                      <div class="tab-pane fade" id="aset" role="tabpanel">
                            <div class="row mb-3 mt-2">
                              <div class="col-md-4">
                                <div class="small-box" style="background: linear-gradient(135deg, #FFC107 0%, #FFB300 100%); color: white; margin-bottom: 0;">
                                  <div class="inner">
                                    <h3><?= $count_pending ?></h3>
                                    <p>Pending</p>
                                  </div>
                                  <i class="bi bi-clock small-box-icon"></i>
                                </div>
                              </div>
                              <div class="col-md-4">
                                <div class="small-box" style="background: linear-gradient(135deg, #28A745 0%, #218838 100%); color: white; margin-bottom: 0;">
                                  <div class="inner">
                                    <h3><?= $count_approved ?></h3>
                                    <p>Approved</p>
                                  </div>
                                    <i class="bi bi-check-circle small-box-icon"></i>
                                </div>
                              </div>
                              <div class="col-md-4">
                                <div class="small-box" style="background: linear-gradient(135deg, #c83636 0%, #961313 100%); color: white; margin-bottom: 0;">
                                  <div class="inner">
                                    <h3><?= $count_rejected ?></h3>
                                    <p>Rejected</p>
                                  </div>
                                  <i class="bi bi-x-circle small-box-icon"></i>
                                </div>
                              </div>
                            </div>
                            <!-- End Summary Boxes -->      

                            <?php if (empty($subreg_pending_data)): ?>
                            <div class="alert alert-warning mb-3 mt-2">
                              <i class="bi bi-info-circle me-2"></i>
                              <strong>Belum ada usulan</strong>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                              <table id="lengkapiTable" class="display nowrap table table-striped table-sm w-100">
                                <thead>
                                  <tr>
                                    <th>No</th>
                                    <th>Profit Center</th>
                                    <th>Nomor Aset</th>
                                    <th>Nama Aset</th>
                                    <th>Mekanisme Penghapusan</th>
                                    <th>Status SubReg</th>
                                    <th>Action</th>
                                  </tr>
                                </thead>
                                <tbody>
                                  <?php foreach ($subreg_pending_data as $index => $row): ?>
                                  <tr>
                                    <td class="text-center"><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars($row['profit_center']) . (!empty($row['profit_center_text']) ? ' - ' . htmlspecialchars($row['profit_center_text']) : '') ?></td>
                                    <td><?= htmlspecialchars($row['nomor_asset_utama']) ?></td>
                                    <td><?= htmlspecialchars($row['nama_aset']) ?></td>
                                    <td><?= htmlspecialchars($row['mekanisme_penghapusan']) ?></td>
                                    <td>
                                      <?php
                                        $sub_status = isset($row['status_approval_subreg']) ? $row['status_approval_subreg'] : '';
                                        if ($sub_status === 'pending') {
                                          echo '<span class="badge" style="background: #FFC107; color: #000;"><i class="bi bi-hourglass-split me-1"></i>Pending</span>';
                                        } else if ($sub_status === 'approved') {
                                          echo '<span class="badge" style="background: #28A745; color: #fff;"><i class="bi bi-check-circle me-1"></i>Approved</span>';
                                        } else if ($sub_status === 'rejected') {
                                          echo '<span class="badge" style="background: #dc3545; color: #fff;"><i class="bi bi-x-circle me-1"></i>Rejected</span>';
                                        } else {
                                          echo '<span class="badge bg-secondary">-</span>';
                                        }
                                      ?>
                                    </td>
                                    <td>
                                            <button class="btn btn-sm btn-outline-primary" 
                                              onclick="openFormLengkapiDokumen(<?= $row['id'] ?>)" 
                                              title="Lengkapi dokumen">
                                        <i class="bi bi-eye"></i> Review
                                      </button>
                                    </td>
                                  </tr>
                                  <?php endforeach; ?>
                                </tbody>
                              </table>
                            </div>
                            <?php endif; ?>
                      </div>
                      <!-- End Tab Daftar Approval -->
                    </div>
                    <!-- End Tab Content -->

                        
                          <!-- Status Flow Diagram
                          <div class="card mb-4">
                            <div class="card-header bg-light">
                              <h6 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>Alur Status Approval</h6>
                            </div>
                            <div class="card-body">
                              <div class="d-flex justify-content-between align-items-center flex-wrap" style="gap: 1rem;">
                                <div class="text-center" style="flex: 1; min-width: 150px;">
                                  <div class="badge p-3" style="width: 100%; font-size: 0.9rem; background: linear-gradient(135deg, #6C757D 0%, #5A6268 100%); color: white;">
                                    <i class="bi bi-file-earmark"></i><br>Draft
                                  </div>
                                </div>
                                <i class="bi bi-arrow-right" style="font-size: 1.5rem; color: #6c757d;"></i>
                                
                                <div class="text-center" style="flex: 1; min-width: 150px;">
                                  <div class="badge p-3" style="width: 100%; font-size: 0.9rem; background: linear-gradient(135deg, #FFC107 0%, #FFB300 100%); color: white;">
                                    <i class="bi bi-pencil-square"></i><br>Lengkapi Data
                                  </div>
                                </div>
                                <i class="bi bi-arrow-right" style="font-size: 1.5rem; color: #6c757d;"></i>
                                
                                <div class="text-center" style="flex: 1; min-width: 150px;">
                                  <div class="badge p-3" style="width: 100%; font-size: 0.9rem; background: linear-gradient(135deg, #FF8C00 0%, #FF7700 100%); color: white;">
                                    <i class="bi bi-cloud-upload"></i><br>Perlu Dokumen
                                  </div>
                                </div>
                                <i class="bi bi-arrow-right" style="font-size: 1.5rem; color: #6c757d;"></i>
                                
                                <div class="text-center" style="flex: 1; min-width: 150px;">
                                  <div class="badge p-3" style="width: 100%; font-size: 0.9rem; background: linear-gradient(135deg, #0D6EFD 0%, #0B5ED7 100%); color: white;">
                                    <i class="bi bi-hourglass-split"></i><br>Pending SubReg
                                  </div>
                                </div>
                                <i class="bi bi-arrow-right" style="font-size: 1.5rem; color: #6c757d;"></i>
                                
                                <div class="text-center" style="flex: 1; min-width: 150px;">
                                  <div class="badge p-3" style="width: 100%; font-size: 0.9rem; background: linear-gradient(135deg, #17A2B8 0%, #138496 100%); color: white;">
                                    <i class="bi bi-check-circle"></i><br>Approved SubReg
                                  </div>
                                </div>
                                <i class="bi bi-arrow-right" style="font-size: 1.5rem; color: #6c757d;"></i>
                                
                                <div class="text-center" style="flex: 1; min-width: 150px;">
                                  <div class="badge p-3" style="width: 100%; font-size: 0.9rem; background: linear-gradient(135deg, #28A745 0%, #218838 100%); color: white;">
                                    <i class="bi bi-award"></i><br>Approved Regional
                                  </div>
                                </div>
                              </div>
                            </div>
                          </div> -->
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                        <!-- End Tab Summary -->
                      
                    <!-- End Tab Content -->  
                  
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
    <!-- MODAL: Konfirmasi Submit ke Approval -->
    <!-- ============================================================ -->
    <div class="modal fade" id="modalConfirmSubmitApproval" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title">
              <i class="bi bi-send-check me-2"></i>Konfirmasi Submit ke Approval
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-info mb-3">
              <i class="bi bi-info-circle me-2"></i>
              <strong>Info:</strong> Usulan akan dikirim ke Approval SubReg untuk diproses.
            </div>
            <p class="mb-2">Anda akan submit usulan untuk aset:</p>
            <div class="p-3 bg-light rounded border mb-3">
              <div class="mb-2">
                <i class="bi bi-tag me-2"></i>
                <strong id="submitApprovalNomor">-</strong>
              </div>
              <div>
                <i class="bi bi-paperclip me-2"></i>
                Jumlah dokumen: <strong id="submitApprovalJumlahDok">0</strong> file(s)
              </div>
            </div>
            <p class="text-muted small">
              <i class="bi bi-exclamation-circle me-1"></i>
              Setelah di-submit, Anda tidak dapat mengubah data atau menambah dokumen lagi.
            </p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
              <i class="bi bi-x-circle me-1"></i> Batal
            </button>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="action" value="submit_to_approval">
              <input type="hidden" name="usulan_id" id="submitApprovalUsulanId">
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-send-check me-1"></i> Ya, Submit ke Approval
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
    <!-- MODAL: Sukses Data Lengkap -->
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
            <h4 class="mb-3 text-success fw-bold">Data Berhasil Dilengkapi!</h4>
            <p class="text-muted mb-4">
              Data usulan penghapusan aset telah berhasil dilengkapi, namun statusnya diubah menjadi 
              <span class="badge" style="background: #218838; color: white;">
                <i class="bi bi-check-circle-fill text-success"></i>Data Lengkap</span>.
            </p>
            <div class="alert alert-info mb-4">
              <i class="bi bi-info-circle me-2"></i>
              Silakan unggah dokumen pendukung agar data dapat masuk ke <strong>Halaman Approval SubReg</strong> untuk proses selanjutnya. 
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

// Initialize DataTable untuk tab Lengkapi Data
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

// Auto-switch ke tab Upload Dokumen bila URL mengandung #upload (dipakai oleh redirect PHP)
        if (window.location.hash === '#upload') {
            const uploadTab = new bootstrap.Tab(document.getElementById('upload-dokumen-tab'));
            uploadTab.show();
        }

        // Auto-switch ke tab Daftar Approval jika ada hash #approval di URL
        if (window.location.hash === '#approval') {
            const asetTab = new bootstrap.Tab(document.getElementById('daftar-aset-tab'));
            asetTab.show();
        }

// Update info text saat berpindah tab
        document.getElementById('upload-dokumen-tab').addEventListener('shown.bs.tab', function () {
          document.getElementById('infoTextContent').textContent = 'Unggah dokumen pendukung untuk melengkapi usulan penghapusan aset.';
        });

        document.getElementById('daftar-aset-tab').addEventListener('shown.bs.tab', function () {
            document.getElementById('infoTextContent').textContent = 'Tinjau ringkasan approval untuk aset yang sudah di-submit.';
        });

// Function untuk konfirmasi hapus usulan
      function confirmDeleteUsulan(usulanId, nomorAset, namaAset) {
          document.getElementById('deleteUsulanId').value = usulanId;
          document.getElementById('deleteUsulanNomor').textContent = nomorAset;
          document.getElementById('deleteUsulanNama').textContent = namaAset || '-';
          
         
          const modal = new bootstrap.Modal(document.getElementById('modalConfirmDeleteUsulan'));
          modal.show();
      }

// ============================================================
// Function untuk konfirmasi delete dokumen
// ============================================================
      function confirmDeleteDokumen(dokumenId, fileName) {
          document.getElementById('deleteDokumenId').value = dokumenId;
          document.getElementById('deleteDokumenName').textContent = fileName;
          
          const modal = new bootstrap.Modal(document.getElementById('modalConfirmDeleteDokumen'));
          modal.show();
      }

      // Approve an usulan (AJAX POST)
      function approveUsulan(usulanId) {
        if (!confirm('Setujui usulan ini untuk di-approve?')) return;
        const body = new URLSearchParams();
        body.append('action', 'approve_usulan');
        body.append('usulan_id', usulanId);
        body.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');

        fetch(location.pathname, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString()
        }).then(res => res.json())
          .then(json => {
            if (json.success) {
              location.reload();
            } else {
              alert('Gagal approve: ' + (json.error || 'Unknown error'));
            }
          }).catch(err => alert('Error: ' + err.message));
      }

          // Show confirm modal for Approve/Reject from reviewer UI
          function showApproveRejectConfirm(action, usulanId) {
            const titleEl = document.getElementById('confirmApproveRejectTitle');
            const msgEl = document.getElementById('confirmApproveRejectMessage');
            const noteWrapper = document.getElementById('confirmRejectNoteWrapper');
            const noteInput = document.getElementById('confirmRejectNote');

            document.getElementById('confirmApproveRejectUsulanId').value = usulanId;
            document.getElementById('confirmApproveRejectAction').value = action;
            noteInput.value = '';

            if (action === 'approve') {
              titleEl.textContent = 'Konfirmasi Approve';
              msgEl.textContent = 'Anda akan menyetujui usulan ini. Lanjutkan?';
              noteWrapper.style.display = 'none';
            } else {
              titleEl.textContent = 'Konfirmasi Reject';
              msgEl.textContent = 'Anda akan menolak usulan ini. Tambahkan alasan (opsional) lalu lanjutkan.';
              noteWrapper.style.display = 'block';
            }

            const modal = new bootstrap.Modal(document.getElementById('modalConfirmApproveReject'));
            modal.show();

            const btn = document.getElementById('btnConfirmApproveReject');
            // remove previous handler
            btn.onclick = null;
            btn.onclick = function() {
              modal.hide();
              if (action === 'approve') {
                performApproveUsulan(usulanId);
              } else {
                const note = document.getElementById('confirmRejectNote').value.trim();
                performRejectUsulan(usulanId, note);
              }
            };
          }

          // Perform approve without browser confirm
          function performApproveUsulan(usulanId) {
            const body = new URLSearchParams();
            body.append('action', 'approve_usulan');
            body.append('usulan_id', usulanId);
            body.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');

            fetch(location.pathname, {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: body.toString()
            }).then(res => res.json())
              .then(json => {
                if (json.success) {
                  location.reload();
                } else {
                  alert('Gagal approve: ' + (json.error || 'Unknown error'));
                }
              }).catch(err => alert('Error: ' + err.message));
          }

          // Perform reject without browser confirm (accepts note)
          function performRejectUsulan(usulanId, note) {
            const body = new URLSearchParams();
            body.append('action', 'reject_usulan');
            body.append('usulan_id', usulanId);
            body.append('note', note || '');
            body.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');

            fetch(location.pathname, {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: body.toString()
            }).then(res => res.json())
              .then(json => {
                if (json.success) {
                  location.reload();
                } else {
                  alert('Gagal reject: ' + (json.error || 'Unknown error'));
                }
              }).catch(err => alert('Error: ' + err.message));
          }

      // Reject an usulan (AJAX POST) — includes optional reject reason from modal
      function rejectUsulan(usulanId) {
        if (!confirm('Tolak usulan ini?')) return;
        const noteEl = document.getElementById('modalRejectNote');
        const note = noteEl ? noteEl.value.trim() : '';

        const body = new URLSearchParams();
        body.append('action', 'reject_usulan');
        body.append('usulan_id', usulanId);
        body.append('note', note);
        body.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');

        fetch(location.pathname, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString()
        }).then(res => res.json())
          .then(json => {
            if (json.success) {
              location.reload();
            } else {
              alert('Gagal reject: ' + (json.error || 'Unknown error'));
            }
          }).catch(err => alert('Error: ' + err.message));
      }

// ============================================================
// Function untuk konfirmasi submit ke approval
// ============================================================
      function confirmSubmitApproval(usulanId, nomorAset, jumlahDokumen) {
          if (jumlahDokumen < 1) {
              alert('❌ Minimal upload 1 dokumen sebelum submit ke approval!');
              return;
          }
          
          document.getElementById('submitApprovalUsulanId').value = usulanId;
          document.getElementById('submitApprovalNomor').textContent = nomorAset;
          document.getElementById('submitApprovalJumlahDok').textContent = jumlahDokumen;
          
          const modal = new bootstrap.Modal(document.getElementById('modalConfirmSubmitApproval'));
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
                 $('#myTable .row-checkbox:not(:disabled):not(.is-draft)').prop('checked', isChecked);
                updateSelectedCount();
            });
            
            // Individual checkbox
            $(document).on('change', '.row-checkbox', function() {
                const totalCheckboxes = $('#myTable .row-checkbox:not(:disabled):not(.is-draft)').length;
                const checkedCheckboxes = $('#myTable .row-checkbox:checked:not(:disabled):not(.is-draft)').length;
                $('#selectAll').prop('checked', totalCheckboxes === checkedCheckboxes);
                updateSelectedCount();
            });
        
        function updateSelectedCount() {
            // Hanya hitung checkbox yang TIDAK disabled (bukan yang sudah di-submit)
            const count = $('#myTable .row-checkbox:checked:not(:disabled):not(.is-draft)').length;
            $('#selectionCount').text(count);
            
            if (count > 0) {
                $('#selectionInfo').slideDown();
            } else {
                $('#selectionInfo').slideUp();
            }
        }
        
        function clearSelection() {
            $('#myTable .row-checkbox:not(:disabled):not(.is-draft)').prop('checked', false);
            $('#selectAll').prop('checked', false);
            updateSelectedCount();
        }
        

        // =========================================================
        // saveData — buka modal yang sesuai
        // =========================================================
        function saveData(type) {
            const selectedIds = [];
            // Hanya ambil checkbox yang checked DAN tidak disabled
            $('#myTable .row-checkbox:checked:not(:disabled):not(.is-draft)').each(function() {
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

    <!-- MODAL: Form Lengkapi Data -->
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
                      <input type="number" class="form-control" name="jumlah_aset" value="1" min="1" required disabled>
                      <small class="text-muted">Masukkan jumlah aset, minimal 1</small>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Mekanisme Penghapusan <span class="text-danger">*</span></label>
                      <select class="form-select" name="mekanisme_penghapusan" required disabled>
                        <option value="">-- Pilih --</option>
                        <option value="Jual Lelang">Jual Lelang</option>
                        <option value="Hapus Administrasi">Hapus Administrasi</option>
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Fisik Aset <span class="text-danger">*</span></label>
                      <select class="form-select" id="fisik_aset" name="fisik_aset" required onchange="toggleFotoUpload()" disabled>
                        <option value="">-- Pilih --</option>
                        <option value="Ada">Ada</option>
                        <option value="Tidak Ada">Tidak Ada</option>
                      </select>
                    </div>
                  </div>
                    
                  <div class="mb-3">
                    <label for="justifikasi_alasan" class="form-label">Justifikasi & Alasan Penghapusan <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="justifikasi_alasan" name="justifikasi_alasan" rows="3" required disabled
                      placeholder="Jelaskan alasan penghapusan aset..."></textarea>
                  </div>

                  <div class="mb-3">
                    <label for="kajian_hukum" class="form-label">Kajian Hukum <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="kajian_hukum" name="kajian_hukum" rows="3" required disabled
                      placeholder="Aspek hukum terkait penghapusan aset..."></textarea>
                  </div>

                  <div class="mb-3">
                    <label for="kajian_ekonomis" class="form-label">Kajian Ekonomis <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="kajian_ekonomis" name="kajian_ekonomis" rows="3" required disabled
                      placeholder="Analisis ekonomis penghapusan aset..."></textarea>
                  </div>

                  <div class="mb-3">
                    <label for="kajian_risiko" class="form-label">Kajian Risiko <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="kajian_risiko" name="kajian_risiko" rows="3" required disabled
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

              <!-- Reject reason (will be persisted to approval_history.note) -->
              <div class="mb-3 mt-3">
                <label for="modalRejectNote" class="form-label">Alasan Reject (opsional)</label>
                <textarea id="modalRejectNote" class="form-control" rows="3" placeholder="Tuliskan alasan jika menolak usulan (akan disimpan di riwayat)."></textarea>
              </div>

              <!-- Approval History -->
              <div class="card mt-3">
                <div class="card-header bg-light">
                  <strong><i class="bi bi-clock-history me-2"></i>Riwayat Persetujuan</strong>
                </div>
                <div class="card-body" id="approvalHistoryContainer">
                  <p class="text-muted">Memuat riwayat...</p>
                </div>
              </div>
            </div>
            
            <div class="modal-footer">
              <button type="button" class="btn btn-danger" id="btnModalReject">
                <i class="bi bi-x-circle"></i> Reject
              </button>
              <button type="button" class="btn btn-success" id="btnModalApprove">
                <i class="bi bi-check-circle"></i> Approve
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script>
    // Data usulan dalam format JSON untuk akses cepat
    const usulanLengkapiData = <?= json_encode($lengkapi_data) ?>;
    // Juga sediakan data pending SubReg (dipakai oleh reviewer ketika menekan Review)
    const subregPendingData = <?= json_encode($subreg_pending_data) ?>;

    function openFormLengkapiDokumen(usulanId) {
        console.log("Klik Lengkapi dengan ID:", usulanId); 
        console.log("Data JSON:", usulanLengkapiData);

        // Cari di data milik creator (lengkapi) dulu, jika tidak ada cari di data pending SubReg
        let usulan = usulanLengkapiData.find(u => u.id == usulanId);
        if (!usulan) {
          usulan = subregPendingData.find(u => u.id == usulanId);
        }

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
        // reset reject reason textarea each time modal opens
        const modalRejectEl = document.getElementById('modalRejectNote');
        if (modalRejectEl) modalRejectEl.value = '';

        // Load approval history for this usulan and render
        fetch(location.pathname + '?action=get_approval_history&usulan_id=' + usulanId, { credentials: 'same-origin' })
          .then(r => r.json())
          .then(payload => {
            const container = document.getElementById('approvalHistoryContainer');
            if (!payload.success || !Array.isArray(payload.data) || payload.data.length === 0) {
              container.innerHTML = '<p class="text-muted">Belum ada riwayat persetujuan.</p>';
              return;
            }
            const list = document.createElement('div');
            list.className = 'list-group';
            payload.data.forEach(item => {
              const el = document.createElement('div');
              el.className = 'list-group-item list-group-item-light';
              el.innerHTML = `
                <div class="d-flex w-100 justify-content-between">
                  <div><strong>${item.action}</strong> oleh <em>${item.actor_name || item.actor_nipp || '-'}</em></div>
                  <small class="text-muted">${item.created_at}</small>
                </div>
                <div class="small text-muted">Role: ${item.actor_role || '-'}</div>
                ${item.note ? '<div class="mt-2">' + item.note + '</div>' : ''}
              `;
              list.appendChild(el);
            });
            container.innerHTML = '';
            container.appendChild(list);
          }).catch(() => {
            document.getElementById('approvalHistoryContainer').innerHTML = '<p class="text-muted">Gagal memuat riwayat.</p>';
          });

        // Attach modal button handlers (use current usulan id)
        document.getElementById('btnModalApprove').onclick = function() {
          const id = document.getElementById('usulan_id').value;
          if (id) showApproveRejectConfirm('approve', id);
        };
        document.getElementById('btnModalReject').onclick = function() {
          const id = document.getElementById('usulan_id').value;
          if (id) showApproveRejectConfirm('reject', id);
        };
        // Save (submit the lengkapi form) — kept separate from approve/reject
        document.getElementById('btnSaveLengkapi').onclick = function() {
          document.getElementById('formLengkapiDokumen').submit();
        };
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
    
    <!-- Aset Picker Modal JS (moved here so jQuery is loaded) -->
    <script>
    $(document).ready(function() {
      let selectedAssets = [];

      // Select All checkbox
      $('#selectAllAssets').on('change', function() {
        const isChecked = $(this).prop('checked');
        $('.asset-checkbox').prop('checked', isChecked);
        updateSelectedAssets();
      });

      // Individual checkbox
      $(document).on('change', '.asset-checkbox', function() {
        updateSelectedAssets();

        // Update Select All state
        const total = $('.asset-checkbox').length;
        const checked = $('.asset-checkbox:checked').length;
        $('#selectAllAssets').prop('checked', total === checked);
      });

      // Update selected assets array
      function updateSelectedAssets() {
        selectedAssets = [];
        $('.asset-checkbox:checked').each(function() {
          selectedAssets.push({
            id: $(this).val(),
            nomor: $(this).data('nomor'),
            nama: $(this).data('nama')
          });
        });

        // Update counter
        const count = selectedAssets.length;
        $('#countNumber').text(count);
        if (count > 0) {
          $('#selectedAssetCount').slideDown();
        } else {
          $('#selectedAssetCount').slideUp();
        }
      }

      // Confirm selection
      $('#btnConfirmSelectAssets').on('click', function() {
        if (selectedAssets.length === 0) {
          alert('Silakan pilih minimal 1 aset terlebih dahulu!');
          return;
        }

        // Set usulan IDs (comma separated)
        const usulanIds = selectedAssets.map(a => a.id).join(',');
        document.getElementById('inlineUsulanId').value = usulanIds;

        // Set display text
        let displayText = '';
        if (selectedAssets.length === 1) {
          displayText = selectedAssets[0].nomor + ' - ' + selectedAssets[0].nama;
        } else {
          displayText = selectedAssets.length + ' aset dipilih (' +
                        selectedAssets.map(a => a.nomor).slice(0, 3).join(', ') +
                        (selectedAssets.length > 3 ? '...' : '') + ')';
        }
        document.getElementById('inlineNomorAset').value = displayText;

        // Clear validation
        document.getElementById('inlineNomorAset').classList.remove('is-invalid');
        document.getElementById('inlineNomorAsetError').style.display = 'none';

        // Close modal
        bootstrap.Modal.getInstance(document.getElementById('modalAsetPickerUpload')).hide();

        // Clear checkboxes for next use
        $('.asset-checkbox').prop('checked', false);
        $('#selectAllAssets').prop('checked', false);
        $('#selectedAssetCount').slideUp();
      });
    });
    </script>

    <!--end::Script-->
    <!-- Modal: Confirm Approve/Reject (placed at end of body to avoid nesting issues) -->
    <div class="modal fade" id="modalConfirmApproveReject" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" style="max-width:540px;">
        <div class="modal-content border-0 shadow-lg overflow-hidden">
          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title" id="confirmApproveRejectTitle">Konfirmasi</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p id="confirmApproveRejectMessage">Anda yakin ingin melakukan tindakan ini?</p>
            <input type="hidden" id="confirmApproveRejectUsulanId" value="">
            <input type="hidden" id="confirmApproveRejectAction" value="">
            <div id="confirmRejectNoteWrapper" style="display:none;">
              <label for="confirmRejectNote" class="form-label">Alasan Reject (opsional)</label>
              <textarea id="confirmRejectNote" class="form-control" rows="3" placeholder="Tuliskan alasan jika menolak usulan (akan disimpan di riwayat)."></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="button" id="btnConfirmApproveReject" class="btn btn-primary">Ya, Lanjutkan</button>
          </div>
        </div>
      </div>
    </div>
  </body>
  <!--end::Body-->
</html>