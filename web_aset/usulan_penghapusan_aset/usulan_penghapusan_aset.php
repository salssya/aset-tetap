<?php
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "asetreg3_db";

$con = mysqli_connect($servername, $username, $password, $dbname);
session_start();

function stripAUC($s) {
  if ($s === null) return $s;
  $s = preg_replace('/\\bAUC\\s*(?:-|–)?\\s*/i', '', $s);
  return trim($s);
}

if (!isset($_SESSION["nipp"]) || !isset($_SESSION["name"])) {
    header("Location: ../login/login_view.php");
    exit();
}

$userProfitCenter = isset($_SESSION['Cabang']) ? $_SESSION['Cabang'] : '';
$userType = isset($_SESSION['Type_User']) ? $_SESSION['Type_User'] : '';

$isSubRegional = (
    stripos($userType, 'Approval Sub Regional') !== false ||
    stripos($userType, 'Approval SubReg')       !== false ||
    stripos($userType, 'User Entry Sub Regional') !== false
);
$isCabang  = (!$isSubRegional && stripos($userType, 'Cabang') !== false);
$isUserEntryRegional = stripos($userType, 'User Entry Regional') !== false;
$isRegional = !$isSubRegional && !$isCabang;

$userSubreg = '';
if ($isSubRegional && !empty($userProfitCenter)) {
    $pcCode = trim(explode(' - ', $userProfitCenter)[0]);

    $q_subreg = $con->prepare("SELECT subreg FROM import_dat WHERE profit_center = ? AND subreg IS NOT NULL AND subreg != '' LIMIT 1");
    $q_subreg->bind_param("s", $pcCode);
    $q_subreg->execute();
    $r_subreg = $q_subreg->get_result();
    if ($r_subreg && $r_subreg->num_rows > 0) {
        $userSubreg = $r_subreg->fetch_assoc()['subreg'];
    }
    $q_subreg->close();

    if (empty($userSubreg) && $pcCode !== $userProfitCenter) {
        $q_subreg2 = $con->prepare("SELECT subreg FROM import_dat WHERE profit_center = ? AND subreg IS NOT NULL AND subreg != '' LIMIT 1");
        $q_subreg2->bind_param("s", $userProfitCenter);
        $q_subreg2->execute();
        $r_subreg2 = $q_subreg2->get_result();
        if ($r_subreg2 && $r_subreg2->num_rows > 0) {
            $userSubreg = $r_subreg2->fetch_assoc()['subreg'];
        }
        $q_subreg2->close();
    }

    $userProfitCenter = $pcCode;
}

if ($isSubRegional && empty($userSubreg)) {
    $userSubreg = '__NO_SUBREG_ACCESS__';
}

$whereClause = "WHERE nilai_perolehan_sd != 0";
if ($isSubRegional) {
    $whereClause .= " AND subreg = ?";    
} elseif ($isCabang) {
    $whereClause .= " AND profit_center = ?"; 
} elseif ($isUserEntryRegional) {
    $whereClause .= " AND profit_center = '12101'";
}

$query = "SELECT * FROM import_dat " . $whereClause . " ORDER BY nomor_asset_utama ASC";

$stmt = $con->prepare($query);

if ($isSubRegional) {
    $stmt->bind_param("s", $userSubreg);
} elseif ($isCabang) {
    $stmt->bind_param("s", $userProfitCenter);
}

$stmt->execute();
$result = $stmt->get_result();

$asset_data = [];
while ($row = $result->fetch_assoc()) {
    $asset_data[] = $row;
}
$stmt->close();

$draftWhereClause = "WHERE status = 'draft' AND created_by = ?";
if ($isSubRegional) {
    $draftWhereClause .= " AND subreg = ?";
} elseif ($isCabang) {
    $draftWhereClause .= " AND profit_center = ?";
}

$query_draft = "SELECT * FROM usulan_penghapusan " . $draftWhereClause . " ORDER BY created_at DESC";
$stmt_draft = $con->prepare($query_draft);

if ($isSubRegional) {
    $stmt_draft->bind_param("ss", $_SESSION['nipp'], $userSubreg);
} elseif ($isCabang) {
    $stmt_draft->bind_param("ss", $_SESSION['nipp'], $userProfitCenter);
} else {
    $stmt_draft->bind_param("s", $_SESSION['nipp']);
}

$stmt_draft->execute();
$result_draft = $stmt_draft->get_result();

$draft_data = [];
$draft_asset_numbers = []; 
while ($row = $result_draft->fetch_assoc()) {
    $draft_data[] = $row;
    $draft_asset_numbers[] = $row['nomor_asset_utama']; 
}
$stmt_draft->close();

//Query untuk tab "Lengkapi Data" dengan filter type user
$userNipp = $_SESSION['nipp'];
$lengkapiWhereClause = "WHERE up.created_by = ? AND up.status IN ('lengkapi_dokumen', 'dokumen_lengkap')";
if ($isSubRegional) {
    $lengkapiWhereClause .= " AND up.subreg = ?";
} elseif ($isCabang) {
    $lengkapiWhereClause .= " AND up.profit_center = ?";
}

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
                   " . $lengkapiWhereClause . "
                   ORDER BY up.created_at DESC";

$stmt_lengkapi = $con->prepare($query_lengkapi);

if ($isSubRegional) {
    $stmt_lengkapi->bind_param("ss", $userNipp, $userSubreg);
} elseif ($isCabang) {
    $stmt_lengkapi->bind_param("ss", $userNipp, $userProfitCenter);
} else {
    $stmt_lengkapi->bind_param("s", $userNipp);
}

$stmt_lengkapi->execute();
$result_lengkapi = $stmt_lengkapi->get_result();

$lengkapi_data = [];
$lengkapi_asset_numbers = []; 
while ($row = $result_lengkapi->fetch_assoc()) {
    $row['nama_aset'] = stripAUC($row['nama_aset']);
    $row['kategori_aset'] = stripAUC($row['kategori_aset']);
    
    $lengkapi_data[] = $row;
    $lengkapi_asset_numbers[] = $row['nomor_asset_utama']; 
}
$stmt_lengkapi->close();

$submittedWhereClause = "WHERE up.created_by = ? AND up.status IN ('submitted','approved_subreg','pending_subreg','pending_regional','approved_regional','approved','rejected')";
if ($isSubRegional) {
    $submittedWhereClause .= " AND up.subreg = ?";
} elseif ($isCabang) {
    $submittedWhereClause .= " AND up.profit_center = ?";
}
$query_submitted = "SELECT up.nomor_asset_utama FROM usulan_penghapusan up " . $submittedWhereClause;
$stmt_submitted = $con->prepare($query_submitted);
if ($isSubRegional) {
    $stmt_submitted->bind_param("ss", $userNipp, $userSubreg);
} elseif ($isCabang) {
    $stmt_submitted->bind_param("ss", $userNipp, $userProfitCenter);
} else {
    $stmt_submitted->bind_param("s", $userNipp);
}
$stmt_submitted->execute();
$result_submitted = $stmt_submitted->get_result();

$submitted_asset_numbers = [];
while ($row = $result_submitted->fetch_assoc()) {
    $submitted_asset_numbers[] = $row['nomor_asset_utama'];
}
$stmt_submitted->close();

// Query untuk data Upload Dokumen (status = dokumen_lengkap)
$uploadWhereClause = "WHERE up.created_by = ? AND up.status = 'dokumen_lengkap'";
if ($isSubRegional) {
    $uploadWhereClause .= " AND up.subreg = ?";
} elseif ($isCabang) {
    $uploadWhereClause .= " AND up.profit_center = ?";
}

$query_upload = "SELECT up.*, 
                 id.keterangan_asset as nama_aset,
                 id.profit_center_text,
                 id.subreg,
                 (SELECT COUNT(*) FROM dokumen_penghapusan dp2 
                  WHERE dp2.usulan_id = up.id 
                     OR dp2.no_aset LIKE CONCAT('%', up.nomor_asset_utama, '%')
                 ) as jumlah_dokumen
                 FROM usulan_penghapusan up 
                 LEFT JOIN import_dat id ON up.nomor_asset_utama = id.nomor_asset_utama 
                 " . $uploadWhereClause . "
                 ORDER BY up.updated_at DESC";

$stmt_upload = $con->prepare($query_upload);

if ($isSubRegional) {
    $stmt_upload->bind_param("ss", $userNipp, $userSubreg);
} elseif ($isCabang) {
    $stmt_upload->bind_param("ss", $userNipp, $userProfitCenter);
} else {
    $stmt_upload->bind_param("s", $userNipp);
}

$stmt_upload->execute();
$result_upload = $stmt_upload->get_result();

$upload_data = [];
while ($row = $result_upload->fetch_assoc()) {
    $row['nama_aset'] = stripAUC($row['nama_aset']);
    $upload_data[] = $row;
}
$stmt_upload->close();

// Query untuk ambil semua dokumen penghapusan (untuk submit approval)
$query_all_dokumen = "SELECT dp.id_dokumen, dp.usulan_id 
                      FROM dokumen_penghapusan dp 
                      JOIN usulan_penghapusan up ON dp.usulan_id = up.id 
                      WHERE up.created_by = ?";

$stmt_all_dok = $con->prepare($query_all_dokumen);
$stmt_all_dok->bind_param("s", $userNipp);
$stmt_all_dok->execute();
$result_all_dok = $stmt_all_dok->get_result();

$semua_dokumen = [];
while ($row = $result_all_dok->fetch_assoc()) {
    $semua_dokumen[] = $row;
}
$stmt_all_dok->close();


$usulan_with_docs = [];
$q_docs = $con->prepare("SELECT DISTINCT up.id
                         FROM usulan_penghapusan up
                         LEFT JOIN dokumen_penghapusan dp 
                               ON dp.usulan_id = up.id 
                               OR dp.no_aset LIKE CONCAT('%', up.nomor_asset_utama, '%')
                         WHERE up.created_by = ? 
                           AND up.status IN ('dokumen_lengkap', 'lengkapi_dokumen')
                           AND dp.id_dokumen IS NOT NULL");
$q_docs->bind_param("s", $userNipp);
$q_docs->execute();
$r_docs = $q_docs->get_result();
while ($row_doc = $r_docs->fetch_assoc()) {
    $usulan_with_docs[] = $row_doc['id'];
}
$q_docs->close();

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

        if (is_array($selected_data) && count($selected_data) > 0) {
            $saved_count = saveSelectedAssets($con, $selected_data, $is_submit, $_SESSION['nipp'], $userProfitCenter);

            if ($saved_count > 0) {
                if ($is_submit) {
                  
                    $_SESSION['success_message'] = "✅ Berhasil mengusulkan " . $saved_count . " aset untuk penghapusan";
                    header("Location: " . $_SERVER['PHP_SELF'] . "#dokumen");
                    exit();
                } else {

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

if (isset($_SESSION['success_message'])) {
    $pesan = $_SESSION['success_message'];
    $tipe_pesan = "success";
    unset($_SESSION['success_message']);
}

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
    
    // Handle file upload foto: store image into DB only (data URI base64), do NOT write to local filesystem
    $foto_path = null;
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
      $file = $_FILES['foto'];

      if ($file['size'] > 5 * 1024 * 1024) {
        $pesan = "Ukuran foto terlalu besar. Maksimal 5MB.";
        $tipe_pesan = "danger";
      } else {
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime_type, $allowed_types)) {
          $pesan = "Tipe file tidak didukung. Gunakan JPG, JPEG, atau PNG.";
          $tipe_pesan = "danger";
        } else {
          $data = @file_get_contents($file['tmp_name']);
          if ($data === false) {
            $pesan = "Gagal membaca file foto.";
            $tipe_pesan = "danger";
          } else {
            $base64 = base64_encode($data);
            $foto_path = 'data:' . $mime_type . ';base64,' . $base64;
          }
        }
      }
    }


    if ($tipe_pesan !== 'danger') {
      if ($foto_path !== null) {
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
      } else {
        $stmt = $con->prepare("UPDATE usulan_penghapusan SET 
          jumlah_aset = ?,
          mekanisme_penghapusan = ?,
          fisik_aset = ?,
          justifikasi_alasan = ?,
          kajian_hukum = ?,
          kajian_ekonomis = ?,
          kajian_risiko = ?,
          status = 'dokumen_lengkap',
          updated_at = NOW()
          WHERE id = ?");

        $stmt->bind_param("issssssi",
          $jumlah_aset,
          $mekanisme_penghapusan,
          $fisik_aset,
          $justifikasi_alasan,
          $kajian_hukum,
          $kajian_ekonomis,
          $kajian_risiko,
          $usulan_id
        );
      }

      if ($stmt->execute()) {
        $_SESSION['show_success_modal'] = true;
        header("Location: " . $_SERVER['PHP_SELF'] . "#dokumen");
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
    header("Location: " . $_SERVER['PHP_SELF'] . "#lengkapi");
    exit();
}

// Upload Dokumen
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_dokumen') {
    $usulan_ids = $_POST['usulan_id'];
    $tahun_dokumen = isset($_POST['tahun_dokumen']) && !empty($_POST['tahun_dokumen']) 
                    ? intval($_POST['tahun_dokumen']) 
                    : date('Y');
    $tipe_dokumen = $_POST['tipe_dokumen'];
    $nipp = $_SESSION['nipp'];
    $type_user = isset($_SESSION['Type_User']) ? $_SESSION['Type_User'] : '';
    
    // Ambil file dari form (input name="file_dokumen")
    $file = isset($_FILES['file_dokumen']) ? $_FILES['file_dokumen'] : null;

    // Validasi file tidak kosong
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['warning_message'] = "Silakan pilih file untuk diupload!";
        header("Location: " . $_SERVER['PHP_SELF'] . "#upload");
        exit();
    } else {
        $allowed_ext = ['pdf'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_ext, $allowed_ext)) {
            $_SESSION['warning_message'] = "Format file harus PDF!";
            header("Location: " . $_SERVER['PHP_SELF'] . "#upload");
            exit();
        }
        
        if ($file['size'] > 50 * 1024 * 1024) {
            $_SESSION['warning_message'] = "Ukuran file maksimal 50MB!";
            header("Location: " . $_SERVER['PHP_SELF'] . "#upload");
            exit();
        }
        
        // Baca file content
        $file_data = @file_get_contents($file['tmp_name']);
        if ($file_data === false) {
            $_SESSION['warning_message'] = "Gagal membaca file upload.";
            header("Location: " . $_SERVER['PHP_SELF'] . "#upload");
            exit();
        }
        
        // Compress file dengan gzip untuk mengurangi ukuran (biasanya jadi 10-20% dari original)
        $compressed_data = gzencode($file_data, 9);
        if ($compressed_data === false) {
            $_SESSION['warning_message'] = "Gagal kompres file!";
            header("Location: " . $_SERVER['PHP_SELF'] . "#upload");
            exit();
        }
        
        $base64_compressed = base64_encode($compressed_data);
        $file_path = 'data:application/pdf;base64;gzip,' . $base64_compressed;
        
        $new_filename = basename($file['name']);
        
        $success_count = 0;

        $no_aset_raw = isset($_POST['no_aset_list']) && !empty($_POST['no_aset_list']) 
                 ? trim($_POST['no_aset_list']) 
                 : '';

        $ids = array_filter(array_map('trim', explode(',', $usulan_ids)));
        $first_id = !empty($ids) ? intval(reset($ids)) : 0; 

        if ($first_id > 0) {
          $stmt = $con->prepare("SELECT nomor_asset_utama, profit_center, subreg FROM usulan_penghapusan WHERE id = ?");
          $stmt->bind_param("i", $first_id);
          $stmt->execute();
          $result = $stmt->get_result();
          $stmt->close();

          if ($result->num_rows > 0) {
            $usulan = $result->fetch_assoc();

            $profit_center_text = null;
            $qimp = $con->prepare("SELECT profit_center_text, subreg FROM import_dat WHERE nomor_asset_utama = ? LIMIT 1");
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

            $no_aset_save = !empty($no_aset_raw) ? $no_aset_raw : $usulan['nomor_asset_utama'];
            $insert_query = "INSERT INTO dokumen_penghapusan 
                    (usulan_id, tahun_dokumen, tipe_dokumen, no_aset, subreg, profit_center, profit_center_text, type_user, nipp, file_name, file_path, file_size) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_insert = $con->prepare($insert_query);

            $compressed_size = strlen($compressed_data);
            
            $stmt_insert->bind_param("iisssssssssi", 
              $first_id,     
              $tahun_dokumen, 
              $tipe_dokumen, 
              $no_aset_save, 
              $usulan['subreg'],
              $usulan['profit_center'],
              $profit_center_text,
              $type_user,
              $nipp,
              $new_filename,
              $file_path,
              $compressed_size
            );

            if ($stmt_insert->execute()) {
              $success_count++;
            }
            $stmt_insert->close();
          }
        }

        if ($success_count > 0) {
          $_SESSION['success_message'] = "✅ Berhasil upload dokumen untuk " . count($ids) . " aset!";
        } else {
          $_SESSION['warning_message'] = "Gagal menyimpan dokumen!";
        }
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "#upload");
    exit();
}

// HANDLER: Delete Dokumen
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_dokumen') {
    $dokumen_id = intval($_POST['dokumen_id']);
    $user_nipp = $_SESSION['nipp'];
    
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
        
        $del = $con->prepare("DELETE FROM dokumen_penghapusan WHERE id_dokumen = ?");
        $del->bind_param("i", $dokumen_id);
        
        if ($del->execute()) {
          if (!empty($file_path) && strpos($file_path, 'data:') !== 0 && file_exists($file_path)) {
            @unlink($file_path);
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

// HANDLER: Submit to Approval (SubReg)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_to_approval') {
    $user_nipp = $_SESSION['nipp'];

    $raw_ids = $_POST['usulan_id'] ?? '';
    $decoded = json_decode($raw_ids, true);
    if (is_array($decoded)) {
        $usulan_ids = array_map('intval', $decoded);
    } else {
        $usulan_ids = [intval($raw_ids)];
    }
    $usulan_ids = array_filter($usulan_ids, fn($id) => $id > 0);
    
    if (empty($usulan_ids)) {
        $_SESSION['error_message'] = "❌ Tidak ada usulan yang dipilih.";
        header("Location: " . $_SERVER['PHP_SELF'] . "#summary");
        exit();
    }
    
    $submitted_count = 0;
    $failed_count = 0;
    $upd = $con->prepare("UPDATE usulan_penghapusan 
                          SET status = 'submitted', 
                              submitted_to_approval_at = NOW(),
                              updated_at = NOW()
                          WHERE id = ? 
                          AND created_by = ? 
                          AND status = 'dokumen_lengkap'");
    
    foreach ($usulan_ids as $uid) {
        $chk_nomor = '';
        $s_nom = $con->prepare("SELECT nomor_asset_utama FROM usulan_penghapusan WHERE id = ? LIMIT 1");
        $s_nom->bind_param("i", $uid);
        $s_nom->execute();
        $r_nom = $s_nom->get_result()->fetch_assoc();
        $s_nom->close();
        $chk_nomor = $r_nom['nomor_asset_utama'] ?? '';
        
        $chk = $con->prepare("SELECT COUNT(*) as jml FROM dokumen_penghapusan 
                               WHERE usulan_id = ? 
                                  OR (? != '' AND no_aset LIKE CONCAT('%', ?, '%'))");
        $chk->bind_param("iss", $uid, $chk_nomor, $chk_nomor);
        $chk->execute();
        $jml = $chk->get_result()->fetch_assoc()['jml'];
        $chk->close();
        
        if ($jml == 0) { $failed_count++; continue; }
        
        $upd->bind_param("is", $uid, $user_nipp);
        if ($upd->execute() && $upd->affected_rows > 0) {
            $submitted_count++;
        } else {
            $failed_count++;
        }
    }
    $upd->close();
    
    if ($submitted_count > 0) {
        $_SESSION['success_message'] = "✅ Berhasil submit {$submitted_count} usulan ke Approval SubReg!" 
                                      . ($failed_count > 0 ? " ({$failed_count} gagal)" : "");
    } else {
        $_SESSION['error_message'] = "❌ Gagal submit. Pastikan semua aset berstatus 'dokumen_lengkap' dan sudah punya dokumen.";
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "#summary");
    exit();
}

// HANDLER GET: View / Serve Dokumen PDF
if (isset($_GET['action']) && $_GET['action'] === 'view_dokumen' && isset($_GET['id_dok'])) {
    $id_dok = (int)$_GET['id_dok'];
    $nipp_sess = trim((string)($_SESSION['nipp'] ?? ''));
    $typeUser_v = (string)($_SESSION['Type_User'] ?? '');
    $sessionPc_v = trim($_SESSION['Cabang'] ?? '');

    $q = "SELECT file_path, file_name, nipp, subreg, profit_center FROM dokumen_penghapusan WHERE id_dokumen = $id_dok LIMIT 1";
    $res = mysqli_query($con, $q);
    if (!$res || mysqli_num_rows($res) === 0) {
        http_response_code(404); echo 'Dokumen tidak ditemukan.'; exit();
    }
    $dok = mysqli_fetch_assoc($res);

    $isOwner_v    = (trim((string)$dok['nipp']) === $nipp_sess);
    $isRegional_v = stripos($typeUser_v, 'Regional') !== false;
    $isSubreg_v   = stripos($typeUser_v, 'Sub Regional') !== false;
    $isCabang_v   = !$isRegional_v && !$isSubreg_v;

    $canView_v = false;
    if ($isOwner_v || $isRegional_v) {
        $canView_v = true;
    } elseif ($isSubreg_v) {
        $userSr = '';
        if (!empty($sessionPc_v)) {
            $r_sr2 = mysqli_query($con, "SELECT subreg FROM import_dat WHERE profit_center = '" . mysqli_real_escape_string($con, $sessionPc_v) . "' AND subreg != '' LIMIT 1");
            if ($r_sr2 && mysqli_num_rows($r_sr2) > 0) $userSr = strtolower(trim(mysqli_fetch_assoc($r_sr2)['subreg']));
        }
        $canView_v = ($userSr !== '' && strtolower(trim($dok['subreg'] ?? '')) === $userSr);
    } elseif ($isCabang_v) {
        $userPc = '';
        if (!empty($sessionPc_v)) {
            $r_pc2 = mysqli_query($con, "SELECT profit_center FROM import_dat WHERE profit_center = '" . mysqli_real_escape_string($con, $sessionPc_v) . "' LIMIT 1");
            if ($r_pc2 && mysqli_num_rows($r_pc2) > 0) $userPc = strtolower(trim(mysqli_fetch_assoc($r_pc2)['profit_center']));
        }
        $canView_v = ($userPc !== '' && strtolower(trim($dok['profit_center'] ?? '')) === $userPc);
    }

    if (!$canView_v) { http_response_code(403); echo 'Akses ditolak.'; exit(); }

    $filePathDb = $dok['file_path'] ?? '';
    $fileName   = !empty($dok['file_name']) ? basename($dok['file_name']) : 'dokumen.pdf';

    // If file_path is a data URI (base64), serve it directly
    if (!empty($filePathDb) && strpos($filePathDb, 'data:') === 0) {
      // Format: data:[<mediatype>][;base64][;gzip],<data>
      // Check if file is gzip-compressed
      $isGzipped = strpos($filePathDb, ';gzip,') !== false;
      
      if ($isGzipped) {
        // Format: data:application/pdf;base64;gzip,<compressed_base64>
        if (preg_match('#^data:([^;]+);base64;gzip,(.+)$#', $filePathDb, $m)) {
          $mime = $m[1];
          $b64_compressed = $m[2];
          $compressed_data = base64_decode($b64_compressed);
          if ($compressed_data === false) { http_response_code(500); echo 'Gagal decode data.'; exit(); }
          
          // Decompress gzip
          $data = @gzdecode($compressed_data);
          if ($data === false) { http_response_code(500); echo 'Gagal decompress data.'; exit(); }
          
          header('Content-Type: ' . $mime);
          header('Content-Disposition: inline; filename="' . $fileName . '"');
          header('Content-Length: ' . strlen($data));
          header('Cache-Control: no-cache');
          echo $data; exit();
        } else {
          http_response_code(400); echo 'Format data URI gzip tidak valid.'; exit();
        }
      } else {
        // Format lama: data:application/pdf;base64,<base64>
        if (preg_match('#^data:([^;]+);base64,(.+)$#', $filePathDb, $m)) {
          $mime = $m[1];
          $b64 = $m[2];
          $data = base64_decode($b64);
          if ($data === false) { http_response_code(500); echo 'Gagal decode data.'; exit(); }
          header('Content-Type: ' . $mime);
          header('Content-Disposition: inline; filename="' . $fileName . '"');
          header('Content-Length: ' . strlen($data));
          header('Cache-Control: no-cache');
          echo $data; exit();
        } else {
          http_response_code(400); echo 'Format data URI tidak valid.'; exit();
        }
      }
    }

    // If file_path is an absolute URL, redirect to it (so browser can render)
    if (!empty($filePathDb) && (strpos($filePathDb, 'http://') === 0 || strpos($filePathDb, 'https://') === 0)) {
      header('Location: ' . $filePathDb);
      exit();
    }
    $uploadBaseDir = realpath(__DIR__ . '/../../uploads/dokumen_penghapusan') 
                    ? realpath(__DIR__ . '/../../uploads/dokumen_penghapusan') . '/'
                    : __DIR__ . '/../../uploads/dokumen_penghapusan/';
    $absPath = null;

    $try1 = rtrim($uploadBaseDir, '/') . '/' . basename($fileName);
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

    if (!$absPath) {
      http_response_code(404);
      echo 'File tidak ditemukan di server. Path DB: ' . htmlspecialchars($filePathDb) . ' | Upload dir: ' . htmlspecialchars($uploadBaseDir);
      exit();
    }

    // Try to detect mime type, default to pdf
    $mime = 'application/pdf';
    if (function_exists('finfo_open')) {
      $finfo = finfo_open(FILEINFO_MIME_TYPE);
      if ($finfo) {
        $det = finfo_file($finfo, $absPath);
        if ($det) $mime = $det;
        finfo_close($finfo);
      }
    }

    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($absPath));
    header('Cache-Control: no-cache');
    readfile($absPath);
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'get_detail_aset' && isset($_GET['no_aset'])) {
    header('Content-Type: application/json');
    $no_aset_raw = trim($_GET['no_aset']);

    $no_aset_list = array_filter(array_map('trim', explode(';', $no_aset_raw)));
    if (empty($no_aset_list)) {
        echo json_encode(['status' => 'error', 'message' => 'No aset tidak valid']);
        exit();
    }

    $placeholders = implode(',', array_fill(0, count($no_aset_list), '?'));
    $types = str_repeat('s', count($no_aset_list));
    
    $stmt_da = $con->prepare(
        "SELECT id.nomor_asset_utama, id.keterangan_asset, id.profit_center,
                id.subreg, id.profit_center_text,
                up.status AS status_penghapusan
         FROM import_dat id
         LEFT JOIN usulan_penghapusan up
               ON id.nomor_asset_utama = up.nomor_asset_utama
         WHERE id.nomor_asset_utama IN ({$placeholders})
         GROUP BY id.nomor_asset_utama
         LIMIT 50"
    );
    $stmt_da->bind_param($types, ...$no_aset_list);
    $stmt_da->execute();
    $res_da = $stmt_da->get_result();
    $rows_da = [];
    while ($r = $res_da->fetch_assoc()) {
     
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

// Handle update dropdown field (mekanisme penghapusan / fisik aset)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_dropdown_field') {
    $asset_id = intval($_POST['asset_id']);
    $no_aset = $_POST['no_aset'];
    $field_type = $_POST['field_type']; 
    $value = $_POST['value'];
    
    $check = $con->prepare("SELECT id FROM usulan_penghapusan WHERE nomor_asset_utama = ? AND created_by = ?");
    $check->bind_param("ss", $no_aset, $_SESSION['nipp']);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows > 0) {
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
        $asset_id = isset($asset_data['id']) ? $asset_data['id'] : $asset_data; 
        $mekanisme_pilihan = isset($asset_data['mekanisme']) && !empty($asset_data['mekanisme']) ? $asset_data['mekanisme'] : null;
        $fisik_pilihan = isset($asset_data['fisik']) && !empty($asset_data['fisik']) ? $asset_data['fisik'] : null;
        
        $query = "SELECT * FROM import_dat WHERE id = ?";
        $get_stmt = $con->prepare($query);
        $get_stmt->bind_param("i", $asset_id);
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

    <style>
      /* Shared header/sidebar fixes (from dasbor.php) */
      .app-header, nav.app-header, .app-header.navbar { border-bottom: 0 !important; box-shadow: none !important; }
      .sidebar-brand { background-color: #0b3a8c !important; margin-bottom: 0 !important; padding: 0.25rem 0 !important; border-bottom: 0 !important; box-shadow: none !important; }
      .sidebar-brand .brand-link { display: block !important; padding: 0.5rem 0.75rem !important; border-bottom: 0 !important; box-shadow: none !important; background-color: transparent !important; }
      .sidebar-brand .brand-link .brand-image { display: block !important; height: auto !important; max-height: 48px !important; margin: 0 !important; padding: 6px 8px !important; background-color: transparent !important; }
      .app-sidebar { border-right: 0 !important; }
    </style>

    <!-- DataTables CSS -->
    <link rel="stylesheet" href="../../dist/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="../../dist/css/responsive.bootstrap5.min.css">
    
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
      .cursor-pointer {
        cursor: pointer;
      }
      .is-submitted {
        accent-color: #198754;
      }
      tr:has(.is-submitted) {
        background-color: #f0fff4 !important; 
      }
    </style>
    <!--end::Custom Styles-->
    <!-- apexcharts -->
    <link
      rel="stylesheet"
      href="../../dist/css/apexcharts.css"
    />
    <link rel="stylesheet"
      href="../../dist/css/dataTables.dataTables.min.css"
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
                      <li class="nav-item" role="presentation">
                        <button class="nav-link" id="upload-dokumen-tab" data-bs-toggle="tab" data-bs-target="#upload" type="button" role="tab" aria-controls="upload" aria-selected="false">
                          <i class="bi bi-cloud-upload me-2"></i>Upload Dokumen
                        </button>
                      </li>
                      <li class="nav-item" role="presentation">
                        <button class="nav-link" id="summary-tab" data-bs-toggle="tab" data-bs-target="#summary" type="button" role="tab" aria-controls="summary" aria-selected="false">
                          <i class="bi bi-clipboard-data me-2"></i>Summary
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
                                    if ($isSubRegional) {
                                        $q_tab1 = "SELECT id.*, 
                                                  up.id as draft_id,
                                                  up.mekanisme_penghapusan, 
                                                  up.fisik_aset,
                                                  up.status as draft_status,
                                                  up.created_by as usulan_created_by,
                                                  up_all.status as any_status,
                                                  up_all.mekanisme_penghapusan as any_mekanisme,
                                                  up_all.fisik_aset as any_fisik,
                                                  up_all.created_by as any_created_by
                                                  FROM import_dat id 
                                                  LEFT JOIN usulan_penghapusan up 
                                                        ON id.nomor_asset_utama = up.nomor_asset_utama AND up.created_by = ?
                                                  LEFT JOIN usulan_penghapusan up_all
                                                        ON id.nomor_asset_utama = up_all.nomor_asset_utama
                                                  WHERE id.nilai_perolehan_sd != 0
                                                    AND id.subreg = ?
                                                  GROUP BY id.id
                                                  ORDER BY CASE WHEN up_all.status IS NOT NULL THEN 0 ELSE 1 END ASC, id.nomor_asset_utama ASC";
                                        $stmt = $con->prepare($q_tab1);
                                        $stmt->bind_param("ss", $_SESSION['nipp'], $userSubreg);
                                    } elseif ($isCabang) {
                                        $q_tab1 = "SELECT id.*, 
                                                  up.id as draft_id,
                                                  up.mekanisme_penghapusan, 
                                                  up.fisik_aset,
                                                  up.status as draft_status,
                                                  up.created_by as usulan_created_by
                                                  FROM import_dat id 
                                                  LEFT JOIN usulan_penghapusan up 
                                                        ON id.nomor_asset_utama = up.nomor_asset_utama AND up.created_by = ?
                                                  WHERE id.nilai_perolehan_sd != 0
                                                    AND id.profit_center = ?
                                                  GROUP BY id.id
                                                  ORDER BY CASE WHEN up.status IS NOT NULL THEN 0 ELSE 1 END ASC, id.nomor_asset_utama ASC";
                                        $stmt = $con->prepare($q_tab1);
                                        $stmt->bind_param("ss", $_SESSION['nipp'], $userProfitCenter);
                                    } elseif ($isUserEntryRegional) {
                                                  $q_tab1 = "SELECT id.*, 
                                                  up.id as draft_id,
                                                  up.mekanisme_penghapusan, 
                                                  up.fisik_aset,
                                                  up.status as draft_status,
                                                  up.created_by as usulan_created_by,
                                                  up_all.status as any_status,
                                                  up_all.mekanisme_penghapusan as any_mekanisme,
                                                  up_all.fisik_aset as any_fisik,
                                                  up_all.created_by as any_created_by
                                                  FROM import_dat id 
                                                  LEFT JOIN usulan_penghapusan up 
                                                        ON id.nomor_asset_utama = up.nomor_asset_utama AND up.created_by = ?
                                                  LEFT JOIN usulan_penghapusan up_all
                                                        ON id.nomor_asset_utama = up_all.nomor_asset_utama
                                                  WHERE id.nilai_perolehan_sd != 0
                                                    AND id.profit_center = '12101'
                                                  GROUP BY id.id
                                                  ORDER BY CASE WHEN up_all.status IS NOT NULL THEN 0 ELSE 1 END ASC, id.nomor_asset_utama ASC";
                                        $stmt = $con->prepare($q_tab1);
                                        $stmt->bind_param("s", $_SESSION['nipp']);
                                    } else {
                                        $q_tab1 = "SELECT id.*, 
                                                  up.id as draft_id,
                                                  up.mekanisme_penghapusan, 
                                                  up.fisik_aset,
                                                  up.status as draft_status,
                                                  up.created_by as usulan_created_by,
                                                  up_all.status as any_status,
                                                  up_all.mekanisme_penghapusan as any_mekanisme,
                                                  up_all.fisik_aset as any_fisik,
                                                  up_all.created_by as any_created_by
                                                  FROM import_dat id 
                                                  LEFT JOIN usulan_penghapusan up 
                                                        ON id.nomor_asset_utama = up.nomor_asset_utama AND up.created_by = ?
                                                  LEFT JOIN usulan_penghapusan up_all
                                                        ON id.nomor_asset_utama = up_all.nomor_asset_utama
                                                  WHERE id.nilai_perolehan_sd != 0
                                                  GROUP BY id.id
                                                  ORDER BY CASE WHEN up_all.status IS NOT NULL THEN 0 ELSE 1 END ASC, id.nomor_asset_utama ASC";
                                        $stmt = $con->prepare($q_tab1);
                                        $stmt->bind_param("s", $_SESSION['nipp']);
                                    }
                                    $stmt->execute();
                                    $result = $stmt->get_result();

                                    if (!$result) {
                                      echo '<tr><td colspan="14">Error: ' . mysqli_error($con) . '</td></tr>';
                                    } elseif ($result->num_rows == 0) {
                                      echo '<tr><td colspan="14" class="text-center">Tidak ada data aset</td></tr>';
                                    } else {
                                     while ($row = $result->fetch_assoc()) {
                                        $isInDraft     = in_array($row['nomor_asset_utama'], $draft_asset_numbers);
                                        $isLengkapi    = in_array($row['nomor_asset_utama'], $lengkapi_asset_numbers);
                                        $isSubmitted   = in_array($row['nomor_asset_utama'], $submitted_asset_numbers);
                                        
                                        $any_status      = !empty($row['any_status']) ? $row['any_status'] : null;
                                        $any_created_by  = !empty($row['any_created_by']) ? $row['any_created_by'] : null;
                                        $isByOtherUser   = $any_status && ($any_created_by !== $_SESSION['nipp']);
                                        // Aset milik sendiri yang sudah di-approve/reject juga tidak bisa dipilih ulang
                                        $isOwnApproved   = $any_status && ($any_created_by === $_SESSION['nipp']) && in_array($any_status, ['approved_subreg','pending_subreg','pending_regional','approved_regional','approved','rejected']);
                                        $isAnySelected   = $isInDraft || $isLengkapi || $isSubmitted || $isByOtherUser || $isOwnApproved;
                                        $isAnySelected   = $isInDraft || $isLengkapi || $isSubmitted || $isByOtherUser || $isOwnApproved;
                                        
                                        $display_mekanisme = !empty($row['mekanisme_penghapusan']) 
                                                            ? $row['mekanisme_penghapusan'] 
                                                            : (!empty($row['any_mekanisme']) ? $row['any_mekanisme'] : '');
                                        $display_fisik     = !empty($row['fisik_aset']) 
                                                            ? $row['fisik_aset'] 
                                                            : (!empty($row['any_fisik']) ? $row['any_fisik'] : '');
                                        
                                        $checkedAttr    = $isAnySelected ? ' checked' : '';
                                        $disabledAttr   = ($isLengkapi || $isSubmitted || $isByOtherUser || $isOwnApproved) ? ' disabled title="Aset sudah dalam usulan"' : '';
                                        $draftClass     = $isInDraft ? ' is-draft' : '';
                                        $submittedClass = ($isSubmitted || $isByOtherUser || $isOwnApproved) ? ' is-submitted' : '';

                                        $nilai_buku = isset($row['nilai_buku_sd']) ? 'Rp ' . number_format($row['nilai_buku_sd'], 0, ',', '.') : '-';
                                        $nilai_perolehan = isset($row['nilai_perolehan_sd']) ? 'Rp ' . number_format($row['nilai_perolehan_sd'], 0, ',', '.') : '-';
                                        
                                        $kategori_aset = stripAUC($row['asset_class_name']);
                                        $nama_aset = stripAUC($row['keterangan_asset']);
                                        
                                        $mekanisme = !empty($display_mekanisme) ? htmlspecialchars($display_mekanisme) : '-';
                                        $fisik = !empty($display_fisik) ? htmlspecialchars($display_fisik) : '-';
                                        
                                        $hapusDraftBtn = '';
                                        if ($isInDraft && !empty($row['draft_id']) && $row['draft_status'] === 'draft') {
                                            $hapusDraftBtn = '<button type="button" class="btn btn-sm btn-outline-danger" 
                                                onclick="confirmCancelDraft('.intval($row['draft_id']).', \''.htmlspecialchars(addslashes($row['nomor_asset_utama'])).'\', \''.htmlspecialchars(addslashes($nama_aset)).'\')" 
                                                title="Batalkan draft usulan ini">
                                                <i class="bi bi-x-circle"></i>
                                            </button>';
                                        }
                                        
                                        // Dropdown Mekanisme Penghapusan
                                        $selectedMekanisme = !empty($display_mekanisme) ? $display_mekanisme : '';
                                        $mekanismeDropdown = '<select class="form-select form-select-sm mekanisme-dropdown" data-asset-id="'.htmlspecialchars($row['id']).'" data-nomor-aset="'.htmlspecialchars($row['nomor_asset_utama']).'" '.($isSubmitted || $isByOtherUser || $isOwnApproved ? 'disabled' : '').'>
                                            <option value="">-</option>
                                            <option value="Jual Lelang"'.($selectedMekanisme === 'Jual Lelang' ? ' selected' : '').'>Jual Lelang</option>
                                            <option value="Hapus Administrasi"'.($selectedMekanisme === 'Hapus Administrasi' ? ' selected' : '').'>Hapus Administrasi</option>
                                        </select>';
                                        
                                        // Dropdown Fisik Aset
                                        $selectedFisik = !empty($display_fisik) ? $display_fisik : '';
                                        $fisikDropdown = '<select class="form-select form-select-sm fisik-dropdown" data-asset-id="'.htmlspecialchars($row['id']).'" data-nomor-aset="'.htmlspecialchars($row['nomor_asset_utama']).'" '.($isSubmitted || $isByOtherUser || $isOwnApproved ? 'disabled' : '').'>
                                            <option value="">-</option>
                                            <option value="Ada"'.($selectedFisik === 'Ada' ? ' selected' : '').'>Ada</option>
                                            <option value="Tidak Ada"'.($selectedFisik === 'Tidak Ada' ? ' selected' : '').'>Tidak Ada</option>
                                        </select>';
                                        
                                        echo '<tr>
                                          <td style="text-align:center;">'.$hapusDraftBtn.'</td>
                                          <td style="text-align:center;">
                                            <input type="checkbox" class="row-checkbox form-check-input'.$draftClass.$submittedClass.'" value="'.htmlspecialchars($row['id']).'"'.$checkedAttr.$disabledAttr.'>
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

                          <!-- Tab Lengkapi Data -->
                          <div class="tab-pane fade" id="dokumen" role="tabpanel">
                            
                            
                            <!-- Summary Boxes -->
                            <div class="row mb-4">
                              <div class="col-md-4">
                                <div class="small-box" style="background: linear-gradient(135deg, #FFC107 0%, #FFB300 100%); color: white;">
                                  <div class="inner">
                                    <h3><?= count(array_filter($lengkapi_data, fn($d) => $d['status'] === 'lengkapi_dokumen')) ?></h3>
                                    <p>Lengkapi Data</p>
                                  </div>
                                  <i class="bi bi-exclamation-triangle small-box-icon"></i>
                                </div>
                              </div>
                              <div class="col-md-4">
                                <div class="small-box" style="background: linear-gradient(135deg, #28A745 0%, #218838 100%); color: white;">
                                  <div class="inner">
                                    <h3><?= count(array_filter($lengkapi_data, fn($d) => $d['status'] === 'dokumen_lengkap')) ?></h3>
                                    <p>Data Lengkap</p>
                                  </div>
                                    <i class="bi bi-check-circle small-box-icon"></i>
                                </div>
                              </div>
                              <div class="col-md-4">
                                <div class="small-box" style="background: linear-gradient(135deg, #17A2B8 0%, #138496 100%); color: white;">
                                  <div class="inner">
                                    <h3><?= count($lengkapi_data) ?></h3>
                                    <p>Total Usulan</p>
                                  </div>
                                  <i class="bi bi-file-earmark-text small-box-icon"></i>
                                </div>
                              </div>
                            </div>
                            <!-- End Summary Boxes -->      

                              <div class="d-flex justify-content-between align-items-center mb-2">
                              <h5 class="mb-0 mt-0">Daftar Usulan Aset yang Dapat Diajukan</h5>
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
                                    <th>Mekanisme</th>
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
                                    <td><?= !empty($row['mekanisme_penghapusan']) ? htmlspecialchars($row['mekanisme_penghapusan']) : '<span class="text-muted">-</span>' ?></td>
                                    <td><?= htmlspecialchars($row['nama_aset']) ?></td>
                                    <td><?= htmlspecialchars($row['kategori_aset']) ?></td>
                                    <td><?= htmlspecialchars($row['profit_center']) . (!empty($row['profit_center_text']) ? ' - ' . htmlspecialchars($row['profit_center_text']) : '') ?></td>
                                    <td>
                                      <?php if ($row['status'] === 'lengkapi_dokumen'): ?>
                                        <span class="badge" style="background: #FFC107; color: white;">
                                          <i class="bi bi-exclamation-triangle me-1"></i>Lengkapi Data
                                        </span>
                                      <?php elseif ($row['status'] === 'dokumen_lengkap'): ?>
                                        <span class="badge" style="background: #218838; color: white;">
                                          <i class="bi bi-check-circle-fill text-succes me-1"></i>Data Lengkap
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
        
                    <!-- Tab 3: Upload Dokumen -->
                     <div class="tab-pane fade" id="upload" role="tabpanel">

                            <?php
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
                                    <strong>Belum ada usulan yang siap untuk upload dokumen.</strong><br>
                                    Silakan lengkapi data aset terlebih dahulu di tab <strong>"Lengkapi Data Aset"</strong>.
                                  </div>
                                <?php else: ?>

                                <form method="POST" enctype="multipart/form-data" id="formUploadInline" novalidate>
                                  <input type="hidden" name="action" value="upload_dokumen">
                                  <input type="hidden" name="usulan_id" id="inlineUsulanId" value="">
                                  <input type="hidden" name="no_aset_list" id="inlineNomorAsetHidden" value="">

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

                                  <button type="submit" class="btn btn-primary" id="btnUploadInline"
                                          style="background: #0d6efd; border: none; padding: 8px 20px; border-radius: 4px;">
                                    <i class="bi bi-cloud-upload me-1"></i> Upload
                                  </button>
                                </form>
                                <?php endif; ?>

                              </div>
                            </div>

                            <?php if (!empty($upload_data)): ?>

                     <!-- TABEL PREVIEW DOKUMEN-->
                            <?php
                            
                            $semua_dokumen = [];
                            $seen_dok_ids = []; 
                            foreach ($upload_data as $usulan) {
                                $nomor_ua = $usulan['nomor_asset_utama'];
                                $q_dok = $con->prepare(
                                    "SELECT dp.id_dokumen, dp.tahun_dokumen, dp.profit_center, dp.subreg,
                                            dp.tipe_dokumen, dp.profit_center_text, dp.file_path, dp.file_name,
                                            dp.no_aset,
                                            up.nomor_asset_utama, up.id as usulan_id
                                     FROM dokumen_penghapusan dp
                                     JOIN usulan_penghapusan up ON dp.usulan_id = up.id
                                     WHERE dp.usulan_id = ?
                                        OR (dp.no_aset LIKE CONCAT('%', ?, '%') AND dp.no_aset LIKE '%-%')
                                     GROUP BY dp.id_dokumen
                                     ORDER BY dp.id_dokumen DESC"
                                );
                                $q_dok->bind_param("is", $usulan['id'], $nomor_ua);
                                $q_dok->execute();
                                $r_dok = $q_dok->get_result();
                                while ($d = $r_dok->fetch_assoc()) {
                                    if (in_array($d['id_dokumen'], $seen_dok_ids)) continue;
                                    $seen_dok_ids[] = $d['id_dokumen'];
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
                                        <th>Nomor Aset</th>
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
                                          <td colspan="8" class="text-center text-muted py-3">
                                            Belum ada dokumen yang diupload
                                          </td>
                                        </tr>
                                      <?php else: ?>
                                        <?php foreach ($semua_dokumen as $dok): ?>
                                        <tr>
                                          <td><?= $dok['id_dokumen'] ?></td>
                                          <td><?= htmlspecialchars($dok['tahun_dokumen'] ?? date('Y')) ?></td>
                                          <td style="max-width:200px; word-break:break-word;">
                                            <?php 
                                            $no_aset_raw = $dok['no_aset'] ?? '';
                                            $no_list = array_filter(array_map('trim', explode(';', $no_aset_raw)));
                                            if (count($no_list) > 1): ?>
                                              <span class="badge bg-info text-dark mb-1"><?= count($no_list) ?> aset</span><br>
                                              <small><?= htmlspecialchars(implode(' | ', $no_list)) ?></small>
                                            <?php else: ?>
                                              <?= htmlspecialchars($no_aset_raw) ?>
                                            <?php endif; ?>
                                          </td>
                                          <td><?= htmlspecialchars($dok['profit_center'] ?? '') ?></td>
                                          <td><?= htmlspecialchars($dok['subreg'] ?? '') ?></td>
                                          <td><?= htmlspecialchars($dok['tipe_dokumen'] ?? '') ?></td>
                                          <td><?= htmlspecialchars($dok['profit_center_text'] ?? '') ?></td>
                                          <td style="white-space: nowrap;">
                                            <!-- Lihat Dokumen -->
                                            <a href="<?= htmlspecialchars(basename($_SERVER['PHP_SELF']) . '?action=view_dokumen&id_dok=' . $dok['id_dokumen']) ?>"
                                               target="_blank"
                                               class="btn btn-sm btn-outline-secondary"
                                               style="margin-right: 4px; font-size: 0.8rem; padding: 2px 8px;">
                                              Lihat Dokumen
                                            </a>
                                            <!-- Detail Aset -->
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-info"
                                                    style="margin-right: 4px; font-size: 0.8rem; padding: 2px 8px;"
                                                    onclick="showDetailAset('<?= htmlspecialchars(addslashes($dok['no_aset'] ?? $dok['nomor_asset_utama'] ?? '')) ?>')">
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
                           <!-- Modal: Aset Picker untuk tab Upload -->
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
                                            <th>Kondisi Fisik</th>
                                          </tr>
                                        </thead>
                                        <tbody>
                                          <?php foreach ($upload_data as $ua): ?>
                                          <tr <?= in_array($ua['id'], $usulan_with_docs) ? 'style="background-color: #e8f5e9; opacity: 0.8;"' : '' ?>>
                                            <td class="text-center">
                                              <input type="checkbox" 
                                                    class="form-check-input asset-checkbox" 
                                                    value="<?= $ua['id'] ?>"
                                                    data-nomor="<?= htmlspecialchars($ua['nomor_asset_utama']) ?>"
                                                    data-nama="<?= htmlspecialchars(stripAUC($ua['nama_aset'] ?? '-')) ?>"
                                                    data-has-doc="<?= in_array($ua['id'], $usulan_with_docs) ? 'true' : 'false' ?>"
                                                    <?= in_array($ua['id'], $usulan_with_docs) ? 'disabled' : '' ?>>
                                           
                                            </td>
                                            <td><?= htmlspecialchars($ua['nomor_asset_utama']) ?></td>
                                            <td><?= !empty($ua['mekanisme_penghapusan']) ? htmlspecialchars($ua['mekanisme_penghapusan']) : '-' ?></td>
                                            <td><?= htmlspecialchars(stripAUC($ua['nama_aset'] ?? '-')) ?></td>
                                            <td><?= htmlspecialchars($ua['kategori_aset'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($row['profit_center']) . (!empty($row['profit_center_text']) ? ' - ' . htmlspecialchars($row['profit_center_text']) : '') ?></td>
                                            <td><?= !empty($ua['fisik_aset']) ? htmlspecialchars($ua['fisik_aset']) : '-' ?></td>
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
                          <script>
                          
                          (function() {
                            var _selectedAsets = [];

                            function updateAsetCounter() {
                              _selectedAsets = [];
                              document.querySelectorAll('.asset-checkbox:not([disabled]):checked').forEach(function(cb) {
                                _selectedAsets.push({
                                  id   : cb.value,
                                  nomor: cb.getAttribute('data-nomor'),
                                  nama : cb.getAttribute('data-nama')
                                });
                              });
                              var count = _selectedAsets.length;
                              document.getElementById('countNumber').textContent = count;
                              var counter = document.getElementById('selectedAssetCount');
                              if (count > 0) {
                                counter.style.display = 'block';
                              } else {
                                counter.style.display = 'none';
                              }

                              var all  = document.querySelectorAll('.asset-checkbox:not([disabled])').length;
                              var chk  = document.querySelectorAll('.asset-checkbox:not([disabled]):checked').length;
                              var sa   = document.getElementById('selectAllAssets');
                              if (sa) sa.checked = (all > 0 && all === chk);
                            }

                            document.addEventListener('change', function(e) {
                              if (e.target && e.target.classList.contains('asset-checkbox')) {
                                updateAsetCounter();
                              }
                              if (e.target && e.target.id === 'selectAllAssets') {
                                var isChecked = e.target.checked;
                                document.querySelectorAll('.asset-checkbox:not([disabled])').forEach(function(cb) {
                                  cb.checked = isChecked;
                                });
                                updateAsetCounter();
                              }
                            });

                            document.addEventListener('show.bs.modal', function(e) {
                              if (e.target && e.target.id === 'modalAsetPickerUpload') {
                                document.querySelectorAll('.asset-checkbox').forEach(function(cb) {
                                  cb.checked = (cb.getAttribute('data-has-doc') === 'true');
                                });
                                var sa = document.getElementById('selectAllAssets');
                                if (sa) {
                                  var all_enabled = document.querySelectorAll('.asset-checkbox:not([disabled])').length;
                                  var checked_enabled = document.querySelectorAll('.asset-checkbox:not([disabled]):checked').length;
                                  sa.checked = (all_enabled > 0 && all_enabled === checked_enabled);
                                }
                                updateAsetCounter();
                              }
                            });

                            // Tombol Konfirmasi Pilihan
                            document.addEventListener('click', function(e) {
                              if (e.target && (e.target.id === 'btnConfirmSelectAssets' || e.target.closest('#btnConfirmSelectAssets'))) {
                                if (_selectedAsets.length === 0) {
                                  alert('Silakan pilih minimal 1 aset terlebih dahulu!');
                                  return;
                                }
                                var usulanIds = _selectedAsets.map(function(a){ return a.id; }).join(',');
                                document.getElementById('inlineUsulanId').value = usulanIds;
                                var nomorList = _selectedAsets.map(function(a){ return a.nomor; });
                                document.getElementById('inlineNomorAsetHidden').value = nomorList.join(';');
                                var displayText = '';
                                if (_selectedAsets.length === 1) {
                                  displayText = _selectedAsets[0].nomor + ' - ' + _selectedAsets[0].nama;
                                } else {
                                  var preview = nomorList.slice(0, 3).join(', ');
                                  displayText = _selectedAsets.length + ' aset dipilih (' + preview + (_selectedAsets.length > 3 ? '...' : '') + ')';
                                }
                                document.getElementById('inlineNomorAset').value = displayText;

                                document.getElementById('inlineNomorAset').classList.remove('is-invalid');
                                document.getElementById('inlineNomorAsetError').style.display = 'none';

                                var modalEl = document.getElementById('modalAsetPickerUpload');
                                var bsModal = bootstrap.Modal.getInstance(modalEl);
                                if (bsModal) bsModal.hide();
                              }
                            });
                          })();
                          </script>

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
                                              <th style="width: 150px;">Subreg</th>
                                              <th style="width: 200px;">Cabang</th>
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
                                  url: '../../dist/js/i18n/id.json'
                                },
                                columnDefs: [
                                  { orderable: false, targets: [6] }
                                ]
                              });
                            }
                          });

                          /** Buka modal picker aset */
                          function openAsetPickerUpload() {
                            const modal = new bootstrap.Modal(document.getElementById('modalAsetPickerUpload'));
                            modal.show();
                          }

                          function konfirmasiPilihAsets() {
                            const checked = document.querySelectorAll('#asetPickerUploadTable .picker-cb:checked');
                            if (checked.length === 0) {
                                alert('Pilih minimal 1 aset terlebih dahulu!');
                                return;
                            }
                            const usulanIds   = [];
                            const nomorAsets  = [];
                            checked.forEach(function(cb) {
                                usulanIds.push(cb.dataset.id);
                                nomorAsets.push(cb.dataset.nomor);
                            });
          
                            document.getElementById('inlineUsulanId').value    = usulanIds.join(',');
                            document.getElementById('inlineNomorAset').value   = nomorAsets.join(';');

                            bootstrap.Modal.getInstance(document.getElementById('modalAsetPickerUpload')).hide();
                            setTimeout(function() {
                              document.getElementById('inlineTipeDokumen').focus();
                            }, 300);
                          }

                          function pilihAsetDariModal(usulanId, nomorAset) {
                            document.getElementById('inlineUsulanId').value  = usulanId;
                            document.getElementById('inlineNomorAset').value = nomorAset;
                            bootstrap.Modal.getInstance(document.getElementById('modalAsetPickerUpload')).hide();
                            setTimeout(function() {
                              document.getElementById('inlineTipeDokumen').focus();
                            }, 300);
                          }

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
                                  tr.innerHTML =
                                    '<td style="padding: 10px 16px; color: #555;">' + (i + 1) + '</td>' +
                                    '<td style="padding: 10px 16px; font-weight: 500;">' + escHtml(r.nomor_asset_utama || '') + '</td>' +
                                    '<td style="padding: 10px 16px; color: #0d6efd;">' + escHtml(r.keterangan_asset || r.nama_aset || '-') + '</td>' +
                                    '<td style="padding: 10px 16px;">' + escHtml(r.subreg || '-') + '</td>' +
                                    '<td style="padding: 10px 16px;"><strong>' + escHtml(r.profit_center_text || '-') + '</strong></td>';
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

                              const tahunInput = document.getElementById('inlineTahunDokumen');
                              if (!tahunInput.value || tahunInput.value === '') {
                                  tahunInput.classList.add('is-invalid');
                                  valid = false;
                              } else {
                                  tahunInput.classList.remove('is-invalid');
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
                          <!-- End Tab Upload Dokumen -->

                    <!-- Tab 4: Summary -->
                    <div class="tab-pane fade" id="summary" role="tabpanel"> 

                      <?php 
                      $total_dokumen_lengkap = count($upload_data);
                      $usulan_dengan_dok = [];
                      $usulan_tanpa_dok  = [];
                      foreach ($upload_data as $usulan) {
                          $nomor_ua = $usulan['nomor_asset_utama'] ?? '';
                          $q_dok_count = $con->prepare("SELECT COUNT(*) as jml FROM dokumen_penghapusan 
                                                        WHERE usulan_id = ? 
                                                           OR (? != '' AND no_aset LIKE CONCAT('%', ?, '%'))");
                          $q_dok_count->bind_param("iss", $usulan['id'], $nomor_ua, $nomor_ua);
                          $q_dok_count->execute();
                          $res_dok_count = $q_dok_count->get_result()->fetch_assoc();
                          $q_dok_count->close();
                          
                          if ($res_dok_count['jml'] > 0) {
                              $usulan_dengan_dok[] = $usulan;
                          } else {
                              $usulan_tanpa_dok[] = $usulan; 
                          }
                      }
                      $semua_siap_submit = ($total_dokumen_lengkap > 0) && empty($usulan_tanpa_dok);
                      ?>
                                            
                      <?php if ($semua_siap_submit): ?>
                      <div class="card" style="border: 2px solid #28a745; box-shadow: 0 0 15px rgba(40, 167, 69, 0.2);">
                        <div class="card-header d-flex align-items-center gap-2" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; padding: 14px 20px;">
                          <i class="bi bi-clipboard2-check-fill" style="font-size:1.2rem;"></i>
                          <h5 class="mb-0 fw-semibold">Submit Usulan ke Approval SubReg</h5>
                        </div>
                        <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3">
                          <div>
                            <div class="fw-semibold mb-1" style="font-size:0.95rem;">
                              <i class="bi bi-check2-circle text-success me-1"></i>
                              <?= count($usulan_dengan_dok) ?> aset siap diajukan ke proses persetujuan
                            </div>
                            <small class="text-muted">
                              Usulan akan diteruskan ke SubReg &rarr; Regional dengan status <strong>Pending</strong>.
                            </small>
                          </div>
                          <?php
                          $ids_siap       = array_map(fn($u) => $u['id'], $usulan_dengan_dok);
                          $total_dok_siap = array_sum(array_map(fn($u) => $u['jumlah_dokumen'] ?? 0, $usulan_dengan_dok));
                          ?>
                          <button type="button"
                                  class="btn btn-success px-4 py-2 fw-semibold ms-auto"
                                  onclick="confirmSubmitSemuaApproval(<?= htmlspecialchars(json_encode($ids_siap)) ?>, <?= count($usulan_dengan_dok) ?>)"
                                  style="box-shadow: 0 4px 12px rgba(40,167,69,0.3); white-space: nowrap;">
                            <i class="bi bi-send me-2"></i>Ajukan ke Approval
                          </button>
                        </div>
                      </div>
                      <?php elseif ($total_dokumen_lengkap > 0 && !empty($usulan_tanpa_dok)): ?>
                      <div class="alert alert-warning" style="border-left: 4px solid #ffc107;">
                        <i class="bi bi-exclamation-triangle me-2" style="color: #ff9800;"></i>
                        <strong>Belum semua aset siap submit.</strong>
                        <?= count($usulan_dengan_dok) ?> dari <?= $total_dokumen_lengkap ?> aset sudah punya dokumen.
                        Silakan upload dokumen untuk <strong><?= count($usulan_tanpa_dok) ?> aset</strong> berikut di tab "Upload Dokumen":
                        <ul class="mb-0 mt-2">
                          <?php foreach ($usulan_tanpa_dok as $u): ?>
                            <li><strong><?= htmlspecialchars($u['nomor_asset_utama']) ?></strong> — <?= htmlspecialchars(stripAUC($u['nama_aset'] ?? '-')) ?></li>
                          <?php endforeach; ?>
                        </ul>
                      </div>
                      <?php else: ?>
                      <div class="alert alert-warning" style="border-left: 4px solid #ffc107;">
                        <i class="bi bi-exclamation-triangle me-2" style="color: #ff9800;"></i>
                        <strong>Belum ada aset siap diajukan:</strong> Silakan upload dokumen pendukung terlebih dahulu di tab "Upload Dokumen".
                      </div>
                      <?php endif; ?>
 
                          <!-- Detailed Status Table -->
                          <div class="card">
                            <div class="card-header bg-light">
                              <h6 class="mb-0"><i class="bi me-2"></i>Daftar Semua Usulan dengan Status Tracking</h6>
                            </div>
                            <div class="card-body">
                              <?php
                              if ($isSubRegional && !empty($userSubreg)) {
                                  $q_all = $con->prepare("SELECT up.*, 
                                                          id.keterangan_asset as nama_aset,
                                                          (SELECT COUNT(*) FROM dokumen_penghapusan dp2 
                                                          WHERE dp2.usulan_id = up.id 
                                                             OR dp2.no_aset LIKE CONCAT('%', up.nomor_asset_utama, '%')
                                                         ) as jumlah_dokumen
                                                          FROM usulan_penghapusan up 
                                                          LEFT JOIN import_dat id ON up.nomor_asset_utama = id.nomor_asset_utama 
                                                          WHERE up.subreg = ?
                                                          ORDER BY up.status ASC, up.updated_at DESC");
                                  $q_all->bind_param("s", $userSubreg);
                              } elseif ($isCabang) {
                                  $q_all = $con->prepare("SELECT up.*, 
                                                          id.keterangan_asset as nama_aset,
                                                          (SELECT COUNT(*) FROM dokumen_penghapusan dp2 
                                                          WHERE dp2.usulan_id = up.id 
                                                             OR dp2.no_aset LIKE CONCAT('%', up.nomor_asset_utama, '%')
                                                         ) as jumlah_dokumen
                                                          FROM usulan_penghapusan up 
                                                          LEFT JOIN import_dat id ON up.nomor_asset_utama = id.nomor_asset_utama 
                                                          WHERE up.created_by = ?
                                                          ORDER BY up.created_at DESC");
                                  $q_all->bind_param("s", $_SESSION['nipp']);
                              } else {
                                  $q_all = $con->prepare("SELECT up.*, 
                                                          id.keterangan_asset as nama_aset,
                                                          (SELECT COUNT(*) FROM dokumen_penghapusan dp2 
                                                          WHERE dp2.usulan_id = up.id 
                                                             OR dp2.no_aset LIKE CONCAT('%', up.nomor_asset_utama, '%')
                                                         ) as jumlah_dokumen
                                                          FROM usulan_penghapusan up 
                                                          LEFT JOIN import_dat id ON up.nomor_asset_utama = id.nomor_asset_utama 
                                                          ORDER BY up.subreg ASC, up.status ASC, up.updated_at DESC");
                                  $q_all->execute();
                              }
                              if (!$isRegional) { $q_all->execute(); }
                              $q_all->execute();
                              $res_all = $q_all->get_result();
                              ?>
                              <div class="table-responsive">
                                <table class="table table-striped table-hover table-sm table-bordered" id="summaryTable" style="vertical-align: middle;">
                                  <thead class="table-light">
                                    <tr>
                                      <th>No</th>
                                      <th>Nomor Aset</th>
                                      <th>Nama Aset</th>
                                      <th>Cabang</th>
                                      <th>Diajukan Oleh</th>
                                      <th>Status Aset Saat Ini</th>
                                      <th>Dokumen</th>
                                      <th>Mekanisme Penghapusan</th>
                                      <th>Last Update</th>
                                    </tr>
                                  </thead>
                                  <tbody>
                                    <?php 
                                    $no = 1; 
                                    while ($row = $res_all->fetch_assoc()): 
                                      // Determine status badge
                                      $statusBadge = '';
                                      
                                      switch ($row['status']) {
                                        case 'draft':
                                          $statusBadge = '<span class="badge" style="background: #6C757D; color: white;"><i class="bi bi-file-earmark"></i> Draft</span>';
                                          break;
                                        case 'lengkapi_dokumen':
                                          $statusBadge = '<span class="badge" style="background: #FFC107; color: white;"><i class="bi bi-exclamation-triangle"></i> Lengkapi Data</span>';
                                          break;
                                        case 'dokumen_lengkap':
                                          $statusBadge = '<span class="badge" style="background: #218838; color: white;"><i class="bi bi-check-circle-fill"></i> Data Lengkap</span>';
                                          break;
                                        case 'submitted':
                                        case 'pending_subreg':
                                          $statusBadge = '<span class="badge" style="background: #0D6EFD; color: white;"><i class="bi bi-hourglass-split"></i> Pending SubReg</span>';
                                          break;
                                        case 'approved_subreg':
                                          $statusBadge = '<span class="badge" style="background: #17A2B8; color: white;"><i class="bi bi-check-circle-fill"></i> Approved SubReg</span>';
                                          break;
                                        case 'pending_regional':
                                          $statusBadge = '<span class="badge" style="background: #0D6EFD; color: white;"><i class="bi bi-hourglass"></i> Pending Regional</span>';
                                          break;
                                        case 'approved_regional':
                                        case 'approved':
                                          $statusBadge = '<span class="badge" style="background: #28A745; color: white;"><i class="bi bi-award-fill"></i> Approved Regional</span>';
                                          break;
                                        case 'rejected':
                                          $statusBadge = '<span class="badge bg-danger"><i class="bi bi-x-circle-fill"></i> Rejected</span>';
                                          break;
                                        default:
                                          $statusBadge = '<span class="badge bg-secondary">Unknown</span>';
                                      }
                                            ?>
                                              <tr>
                                                <td><?= $no++ ?></td>
                                                <td><strong><?= htmlspecialchars($row['nomor_asset_utama']) ?></strong></td>
                                                <td><?= htmlspecialchars(stripAUC($row['nama_aset'] ?? '-')) ?></td>
                                                <td>
                                                  <?php if (!empty($row['profit_center_text'])): ?>
                                                    <small><?= htmlspecialchars($row['profit_center']) ?> - <?= htmlspecialchars($row['profit_center_text']) ?></small>
                                                  <?php else: ?>
                                                    <small class="text-muted">-</small>
                                                  <?php endif; ?>
                                                </td>
                                                <td>
                                                  <span class="badge <?= $row['created_by'] === $_SESSION['nipp'] ? 'bg-primary' : 'bg-secondary' ?>">
                                                    <?= htmlspecialchars($row['created_by']) ?>
                                                  </span>
                                                </td>
                                                <td><?= $statusBadge ?></td>
                                                <td>
                                                  <?php if ($row['jumlah_dokumen'] > 0): ?>
                                                    <span class="badge bg-info"><?= $row['jumlah_dokumen'] ?> file(s)</span>
                                                  <?php else: ?>
                                                    <span class="text-muted small">-</span>
                                                  <?php endif; ?>
                                                </td>
                                                <td>
                                                  <?= !empty($row['mekanisme_penghapusan']) ? htmlspecialchars($row['mekanisme_penghapusan']) : '-' ?>
                                                </td>
                                                <td><?= date('d/m/Y H:i', strtotime($row['updated_at'])) ?></td>
                                              </tr>
                                            <?php endwhile; ?>
                                          </tbody>
                                        </table>
                                      </div>
                                      <?php $q_all->close(); ?>
                                    </div>
                                  </div>
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
    <!-- MODAL 1: Peringatan — belum pilih aset                       -->
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

    <!-- MODAL 2: Konfirmasi Submit ke Lengkapi Data               -->
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
            <div class="mt-3" style="background:#f0f7ff; border:2px dashed #0d6efd; border-radius:10px; max-height:200px; overflow-y:auto;">
                <div id="submitAssetList"></div>
            </div>
            <div class="text-end mt-1">
                <small class="text-muted"><span id="submitAssetCount">0</span> aset dipilih</small>
            </div>
            <div class="mt-3 p-2 px-3" style="background:#fff3cd; border:1px solid #ffc107; border-radius:8px;">
              <small style="color:#856404;">
                <i class="bi bi-clock-history me-1"></i>
                Setelah submit, aset akan dipindahkan ke tab <strong>"Lengkapi Data Aset"</strong> untuk pengisian berkas selanjutnya.
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

    <!-- MODAL 3: Konfirmasi Simpan Draft                             -->
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
            <div class="mt-3" style="background:#f8f9fa; border:2px dashed #6c757d; border-radius:10px; max-height:200px; overflow-y:auto;">
                <div id="draftAssetList">
                </div>
            </div>
            <div class="text-end mt-1">
                <small class="text-muted"><span id="draftAssetCount">0</span> aset dipilih</small>
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
    
    <!-- MODAL: Konfirmasi Hapus Usulan -->
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

    <!-- MODAL: Konfirmasi Delete Dokumen -->
    <div class="modal fade" id="modalConfirmDeleteDokumen" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
          <div class="modal-header bg-danger text-white">
            <h5 class="modal-title">
              <i class="bi bi-trash me-2"></i>Konfirmasi Hapus Dokumen
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-warning mb-3">
              <i class="bi bi-exclamation-triangle me-2"></i>
              <strong>Peringatan!</strong> File dokumen akan dihapus permanen.
            </div>
            <p class="mb-2">Anda akan menghapus dokumen:</p>
            <div class="p-3 bg-light rounded border">
              <i class="bi bi-file-earmark-pdf me-2"></i>
              <strong id="deleteDokumenName">-</strong>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
              <i class="bi bi-x-circle me-1"></i> Batal
            </button>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="action" value="delete_dokumen">
              <input type="hidden" name="dokumen_id" id="deleteDokumenId">
              <button type="submit" class="btn btn-danger">
                <i class="bi bi-trash me-1"></i> Ya, Hapus Dokumen
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- MODAL: Konfirmasi Submit ke Approval -->
    <div class="modal fade" id="modalConfirmSubmitApproval" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
          <div class="modal-header bg-primary text-white border-0">
            <h5 class="modal-title fw-semibold">
              <i class="bi bi-clipboard2-check-fill me-2"></i>Ajukan ke Approval SubReg
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body px-4 py-3">
            <div class="d-flex align-items-center gap-3 p-3 rounded-3 mb-3"
                 style="background:#eff6ff; border:1px solid #bfdbfe;">
              <i class="bi bi-boxes text-primary" style="font-size:2rem;"></i>
              <div>
                <div class="fw-bold fs-5 text-primary" id="submitApprovalNomor">-</div>
                <div class="text-muted small">yang akan diajukan ke proses persetujuan</div>
              </div>
            </div>
            <div class="d-flex align-items-center justify-content-center gap-2 mb-3">
              <span class="badge rounded-pill bg-primary px-3 py-2">
                <i class="bi bi-person-check me-1"></i>SubReg
              </span>
              <i class="bi bi-arrow-right text-muted"></i>
              <span class="badge rounded-pill bg-secondary px-3 py-2">
                <i class="bi bi-building me-1"></i>Regional
              </span>
            </div>
            <div class="text-muted small text-center">
              <i class="bi bi-lock me-1"></i>
              Data dan dokumen <strong>tidak dapat diubah</strong> setelah diajukan.
            </div>
          </div>
          <div class="modal-footer border-0 pt-0">
            <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">
              <i class="bi bi-x me-1"></i>Batal
            </button>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="action" value="submit_to_approval">
              <input type="hidden" name="usulan_id" id="submitApprovalUsulanId">
              <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-send me-1"></i>Ya, Ajukan ke Approval
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
    
    <!-- MODAL: Konfirmasi Cancel Draft -->
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
    
    <!-- MODAL: Sukses Data Lengkap -->
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

    <!-- MODAL: Konfirmasi Hapus Draft (single row)                   -->
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
    <script src="../../dist/js/jquery-3.6.0.min.js"></script>
    <script src="../../dist/js/dataTables.js"></script>
    <script src="../../dist/js/dataTables.responsive.js"></script>
    <script src="../../dist/js/dataTables.buttons.js"></script>
    <script src="../../dist/js/buttons.html5.js"></script>
    <script src="../../dist/js/buttons.print.js"></script>
    <script src="../../dist/js/jszip.min.js"></script>
    <script src="../../dist/js/pdfmake.min.js"></script>
    <script src="../../dist/js/vfs_fonts.min.js"></script>
    <script
      src="../../dist/js/apexcharts.min.js"
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
            paging: true,
            pageLength: 10,
            searching: true,
            ordering: false,
            info: true,
            deferRender: true,
            columnDefs: [
              { targets: 0, orderable: false, className: 'dt-body-center', width: '90px' },
              { targets: 1, orderable: false, className: 'dt-body-center', width: '50px' },
              { targets: [2, 3], orderable: false },
            ],
            language: { url: '../../dist/js/i18n/id.json' },
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
                url: '../../dist/js/i18n/id.json'
            }
        });

// Init DataTable summary
        if ($('#summaryTable').length) {
            $('#summaryTable').DataTable({
                responsive: false,
                autoWidth: false,
                scrollX: true,
                paging: true,
                pageLength: 10,
                searching: true,
                ordering: true,
                info: true,
                language: { url: '../../dist/js/i18n/id.json' },
                columnDefs: [
                    { targets: 0, width: '40px', className: 'text-center' },
                    { targets: [3,4], className: 'text-center' },
                ]
            });
        }

// Auto-switch ke tab Lengkapi Data
        if (window.location.hash === '#dokumen') {
            const lengkapiTab = new bootstrap.Tab(document.getElementById('lengkapi-dokumen-tab'));
            lengkapiTab.show();
        }
        if (window.location.hash === '#upload') {
            const uploadTab = new bootstrap.Tab(document.getElementById('upload-dokumen-tab'));
            uploadTab.show();
        }
        if (window.location.hash === '#summary') {
            const summaryTab = new bootstrap.Tab(document.getElementById('summary-tab'));
            summaryTab.show();
        }

// Update info text saat berpindah tab
        document.getElementById('daftar-aset-tab').addEventListener('shown.bs.tab', function () {
            document.getElementById('infoTextContent').textContent = 'Pilih aset yang akan diusulkan untuk penghapusan';
        });

        document.getElementById('lengkapi-dokumen-tab').addEventListener('shown.bs.tab', function () {
            document.getElementById('infoTextContent').textContent = 'Lengkapi data pendukung untuk aset yang sudah diusulkan';
        });
        document.getElementById('upload-dokumen-tab').addEventListener('shown.bs.tab', function () {
          document.getElementById('infoTextContent').textContent = 'Unggah dokumen pendukung untuk melengkapi usulan penghapusan aset.';
        });

        document.getElementById('summary-tab').addEventListener('shown.bs.tab', function () {
          document.getElementById('infoTextContent').textContent = 'Tinjau ringkasan data usulan sebelum masuk ke proses persetujuan.';
        });

// Function untuk konfirmasi hapus usulan
      function confirmDeleteUsulan(usulanId, nomorAset, namaAset) {
          document.getElementById('deleteUsulanId').value = usulanId;
          document.getElementById('deleteUsulanNomor').textContent = nomorAset;
          document.getElementById('deleteUsulanNama').textContent = namaAset || '-';
          
         
          const modal = new bootstrap.Modal(document.getElementById('modalConfirmDeleteUsulan'));
          modal.show();
      }

// Function untuk konfirmasi delete dokumen
      function confirmDeleteDokumen(dokumenId, fileName) {
          document.getElementById('deleteDokumenId').value = dokumenId;
          document.getElementById('deleteDokumenName').textContent = fileName;
          
          const modal = new bootstrap.Modal(document.getElementById('modalConfirmDeleteDokumen'));
          modal.show();
      }

// Function untuk konfirmasi submit ke approval
      function confirmSubmitApproval(usulanId, nomorAset, jumlahDokumen) {
          if (jumlahDokumen < 1) {
              alert('❌ Minimal upload 1 dokumen sebelum submit ke approval!');
              return;
          }
          document.getElementById('submitApprovalUsulanId').value = usulanId;
          document.getElementById('submitApprovalNomor').textContent = '1 aset (' + nomorAset + ')';
          const modal = new bootstrap.Modal(document.getElementById('modalConfirmSubmitApproval'));
          modal.show();
      }
      
      function confirmSubmitSemuaApproval(idsArray, jumlahAset) {
          if (!idsArray || idsArray.length === 0) {
              alert('❌ Tidak ada aset yang siap submit!');
              return;
          }
          document.getElementById('submitApprovalUsulanId').value = JSON.stringify(idsArray);
          document.getElementById('submitApprovalNomor').textContent = jumlahAset + ' aset';
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

      // Select All functionality
            $('#selectAll').on('change', function() {
                const isChecked = $(this).prop('checked');
                 $('#myTable .row-checkbox:not(:disabled)').prop('checked', isChecked);
                updateSelectedCount();
            });
            
            $(document).on('change', '.row-checkbox', function() {
                const totalCheckboxes = $('#myTable .row-checkbox:not(:disabled)').length;
                const checkedCheckboxes = $('#myTable .row-checkbox:checked:not(:disabled)').length;
                $('#selectAll').prop('checked', totalCheckboxes === checkedCheckboxes);
                updateSelectedCount();
            });
        
        function updateSelectedCount() {
            const count = $('#myTable .row-checkbox:checked:not(:disabled)').length;
            $('#selectionCount').text(count);
            
            if (count > 0) {
                $('#selectionInfo').slideDown();
            } else {
                $('#selectionInfo').slideUp();
            }
        }
        
        function clearSelection() {
            $('#myTable .row-checkbox:not(:disabled)').prop('checked', false);
            $('#selectAll').prop('checked', false);
            updateSelectedCount();
        }
        
        function saveData(type) {
            const selectedIds = [];
            const selectedAssetInfo = [];

            $('#myTable .row-checkbox:checked:not(:disabled)').each(function() {
                const id = $(this).val();
                const row = $(this).closest('tr');
                const nomorAset = row.find('.mekanisme-dropdown').data('nomor-aset') || id;
                const namaAset  = row.find('td:nth-child(8)').text().trim();

                selectedIds.push(id);
                selectedAssetInfo.push({ id, nomorAset, namaAset });
            });

            if (selectedIds.length === 0) {
                new bootstrap.Modal(document.getElementById('modalPeringatan')).show();
                return;
            }

            window._selectedIds = selectedIds;

            let listHtml = '';
            selectedAssetInfo.forEach(function(a) {
                listHtml += `
                    <div class="d-flex align-items-center gap-2 px-3 py-2" style="border-bottom:1px solid #eee;">
                        <i class="bi bi-tag-fill" style="color:#0d6efd;"></i>
                        <div>
                            <div class="fw-semibold" style="font-size:0.95rem;">${a.nomorAset}</div>
                            <div class="text-muted" style="font-size:0.82rem;">${a.namaAset}</div>
                        </div>
                    </div>`;
            });

            if (type === 'draft') {
                $('#draftAssetCount').text(selectedIds.length);
                $('#draftAssetList').html(listHtml);
                new bootstrap.Modal(document.getElementById('modalConfirmDraft')).show();
            } else {
                $('#submitAssetCount').text(selectedIds.length);
                $('#submitAssetList').html(listHtml);
                new bootstrap.Modal(document.getElementById('modalConfirmSubmit')).show();
            }
        }

        //Submit Usulan
        $('#btnDoSubmit').on('click', function() {
            const selectedAssets = [];
            window._selectedIds.forEach(function(assetId) {
                const row = $('input.row-checkbox[value="' + assetId + '"]').closest('tr');
                const mekanisme = row.find('.mekanisme-dropdown').val() || '';
                const fisik = row.find('.fisik-dropdown').val() || '';
                const nomorAset = row.find('.mekanisme-dropdown').data('nomor-aset') || '';
                
                selectedAssets.push({
                    id: assetId,
                    no_aset: nomorAset,
                    mekanisme: mekanisme,
                    fisik: fisik
                });
            });
            
            document.getElementById('selectedItemsInput').value = JSON.stringify(selectedAssets);
            document.getElementById('actionType').value = 'submit_data';

            $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Memproses...');

            document.getElementById('saveForm').submit();
        });

        //Simpan Draft
        $('#btnDoDraft').on('click', function() {
            const selectedAssets = [];
            window._selectedIds.forEach(function(assetId) {
                const row = $('input.row-checkbox[value="' + assetId + '"]').closest('tr');
                const mekanisme = row.find('.mekanisme-dropdown').val() || '';
                const fisik = row.find('.fisik-dropdown').val() || '';
                const nomorAset = row.find('.mekanisme-dropdown').data('nomor-aset') || '';
                
                selectedAssets.push({
                    id: assetId,
                    no_aset: nomorAset,
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
        
        //dropdown Mekanisme Penghapusan dan Fisik Aset
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
                    no_aset: nomorAset,
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
              <i class="bi bi-file-earmark-plus me-2"></i> Form Lengkapi Data Usulan Penghapusan Aset
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
                        <dd class="col-sm-8" id="display_no_aset">-</dd>
                        
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

                  <div class="mb-3" id="fotoUploadSection" style="display:none;">
                    <label class="form-label">Foto Aset <span class="text-danger" id="fotoRequired">*</span></label>
                    
                    <!-- Foto yang sudah ada sebelumnya -->
                    <div id="fotoExistingSection" class="mb-2" style="display:none;">
                      <div class="d-flex align-items-start gap-3 p-2" style="background:#f8f9fa; border:1px solid #dee2e6; border-radius:6px;">
                        <img id="fotoExistingImage" src="" 
                             style="max-width:150px; max-height:120px; border:1px solid #ccc; border-radius:6px; object-fit:cover; cursor:pointer;"
                             onclick="window.open(this.src,'_blank')"
                             title="Klik untuk lihat penuh">
                        <div>
                          <div class="fw-semibold text-success mb-1"><i class="bi bi-check-circle-fill me-1"></i>Foto sudah ada</div>
                          <small class="text-muted">Upload foto baru di bawah untuk mengganti foto ini</small>
                        </div>
                      </div>
                    </div>
                    
                    <input type="file" class="form-control" id="fotoInput" name="foto" accept="image/jpeg,image/jpg,image/png" 
                           onchange="previewFoto(event)">
                    <small class="text-muted">Format: JPG, JPEG, PNG. Maksimal 5MB. Kosongkan jika tidak ingin mengganti foto.</small>
                    
                    <!-- Preview Foto Baru -->
                    <div id="fotoPreview" class="mt-3" style="display:none;">
                      <div class="fw-semibold mb-1 text-primary"><i class="bi bi-eye me-1"></i>Preview foto baru:</div>
                      <img id="fotoPreviewImage" src="" style="max-width:300px; border:2px solid #0d6efd; border-radius:8px;">
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
        document.getElementById('display_no_aset').textContent = usulan.nomor_asset_utama || '-';
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
        document.getElementById('usulan_id').value = usulanId; 

        // PRE-FILL FORM dengan data yang sudah ada (jika ada)
        if (usulan.jumlah_aset) {
            document.querySelector('input[name="jumlah_aset"]').value = usulan.jumlah_aset;
        }
        if (usulan.mekanisme_penghapusan) {
            document.querySelector('select[name="mekanisme_penghapusan"]').value = usulan.mekanisme_penghapusan;
        }
        if (usulan.fisik_aset) {
            document.querySelector('select[name="fisik_aset"]').value = usulan.fisik_aset;
            // toggleFotoUpload() dipanggil setelah foto existing di-set (lihat di bawah)
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
        const existingSection = document.getElementById('fotoExistingSection');
        const existingImg     = document.getElementById('fotoExistingImage');
        if (usulan.foto_path) {
          let src = '';
          if (typeof usulan.foto_path === 'string' && usulan.foto_path.length > 0) {
            // If data URI, use as-is
            if (usulan.foto_path.indexOf('data:') === 0) {
              src = usulan.foto_path;
            } else {
              const isAbsoluteUrl = /^(https?:)?\/\//i.test(usulan.foto_path) || usulan.foto_path.charAt(0) === '/';
              src = isAbsoluteUrl ? usulan.foto_path : '../../' + usulan.foto_path;
            }
          }
          existingImg.src = src;
          existingSection.style.display = 'block';
          document.getElementById('fotoUploadSection').style.display = 'block';
        } else {
          existingSection.style.display = 'none';
        }
        // Reset preview foto baru
        document.getElementById('fotoPreview').style.display = 'none';
        document.getElementById('fotoPreviewImage').src = '';
        document.getElementById('fotoInput').value = '';

        if (usulan.fisik_aset) {
            toggleFotoUpload();
        }
        
        var modal = new bootstrap.Modal(document.getElementById('modalFormLengkapiDokumen'));
        modal.show();
    }

    // Fungsi untuk toggle foto upload section berdasarkan pilihan Fisik Aset
    function toggleFotoUpload() {
        const fisikAset   = document.getElementById('fisik_aset').value;
        const fotoSection = document.getElementById('fotoUploadSection');
        const fotoInput   = document.getElementById('fotoInput');
        const existingSec = document.getElementById('fotoExistingSection');
        const hasExisting = existingSec && existingSec.style.display !== 'none' 
                            && document.getElementById('fotoExistingImage').src 
                            && document.getElementById('fotoExistingImage').src !== window.location.href;
        
        if (fisikAset === 'Ada') {
            fotoSection.style.display = 'block';
            if (hasExisting) {
                fotoInput.removeAttribute('required');
            } else {
                fotoInput.setAttribute('required', 'required');
            }
        } else if (fisikAset === 'Tidak Ada') {
            fotoSection.style.display = 'none';
            fotoInput.removeAttribute('required');
            fotoInput.value = '';
            document.getElementById('fotoPreview').style.display = 'none';
        } else {
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

    function viewFoto(fotoPath, nomorAset, namaAset) {
      let title = 'Foto Aset: ' + nomorAset;
      if (namaAset) {
        title += ' (' + namaAset + ')';
      }
      document.getElementById('modalFotoTitle').textContent = title;

      let src = '';
      if (typeof fotoPath === 'string' && fotoPath.length > 0) {
        // If data URI (base64) — use as-is
        if (fotoPath.indexOf('data:') === 0) {
          src = fotoPath;
        } else {
          const isAbsoluteUrl = /^(https?:)?\/\//i.test(fotoPath) || fotoPath.charAt(0) === '/';
          src = isAbsoluteUrl ? fotoPath : '../../' + fotoPath;
        }
      }

      const img = document.getElementById('modalFotoImage');
      img.src = src;
      img.onerror = function() {
        img.src = '../../dist/assets/img/emblem.png';
      };

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