<?php
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "asetreg3_db";

$con = mysqli_connect($servername, $username, $password, $dbname);

// ── serve file HARUS sebelum session_start ──
// ── Helper: serve file dari DB atau filesystem ─────────────────────────────────
function serveFileFromDb($filePathDb, $fileName, $forceDownload = false) {
    $fileName = !empty($fileName) ? basename($fileName) : 'dokumen.pdf';

    if (strpos($filePathDb, 'data:') === 0 && strpos($filePathDb, ';gzip,') !== false) {
        $fileData = gzdecode(base64_decode(substr($filePathDb, strrpos($filePathDb, ',') + 1)));
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . $fileName . '"');
        header('Content-Length: ' . strlen($fileData));
        header('Cache-Control: no-cache');
        echo $fileData; exit();
    } elseif (strpos($filePathDb, 'data:') === 0 && strpos($filePathDb, ';base64,') !== false) {
        $fileData = base64_decode(substr($filePathDb, strpos($filePathDb, ',') + 1));
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . $fileName . '"');
        header('Content-Length: ' . strlen($fileData));
        header('Cache-Control: no-cache');
        echo $fileData; exit();
    }

    // Raw binary BLOB — PDF dimulai dengan %PDF atau ada null byte (binary)
    if (!empty($filePathDb) && (
        substr($filePathDb, 0, 4) === '%PDF' ||
        substr($filePathDb, 0, 4) === "\x25\x50\x44\x46" ||
        (is_string($filePathDb) && strlen($filePathDb) > 4 && strpos($filePathDb, "\x00") !== false)
    )) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . $fileName . '"');
        header('Content-Length: ' . strlen($filePathDb));
        header('Cache-Control: no-cache');
        echo $filePathDb; exit();
    }

    // Path file di filesystem (Windows/Unix)
    $normalized = str_replace('\\', '/', trim($filePathDb));
    $docRoot    = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\'));
    $candidates = [];
    if (preg_match('#^[A-Za-z]:/#', $normalized))
        $candidates[] = str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    if (preg_match('#/htdocs/(.+)$#i', $normalized, $m))
        $candidates[] = str_replace('/', DIRECTORY_SEPARATOR, $docRoot . '/' . $m[1]);
    if (strpos($normalized, '/') === 0)
        $candidates[] = $normalized;
    if (!empty($docRoot)) {
        $stripped = ltrim(preg_replace('#^' . preg_quote($docRoot, '#') . '#', '', $normalized), '/');
        $candidates[] = str_replace('/', DIRECTORY_SEPARATOR, $docRoot . '/' . $stripped);
    }
    $candidates[] = __DIR__ . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $normalized), DIRECTORY_SEPARATOR);
    $candidates[] = $filePathDb;

    foreach ($candidates as $path) {
        if (!empty($path) && @file_exists($path) && @is_file($path)) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . $fileName . '"');
            header('Content-Length: ' . filesize($path));
            header('Cache-Control: no-cache');
            readfile($path); exit();
        }
    }

    http_response_code(404); echo 'File tidak ditemukan.'; exit();
}

// ── Helper: normalize path foto ──────────────────────────────────────────────

// ── Handler file — sebelum session_start ──
if (isset($_GET['action']) && $_GET['action'] === 'view_dok_ho' && isset($_GET['id_dok'])) {
    while (ob_get_level()) ob_end_clean();
    $id_dok = (int)$_GET['id_dok'];
    $res = mysqli_query($con, "SELECT lokasi_file, file_name FROM dokumen_pelaksanaan WHERE id_dokumen = $id_dok LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) { http_response_code(404); echo 'Dokumen tidak ditemukan.'; exit(); }
    $row = mysqli_fetch_assoc($res);
    serveFileFromDb($row['lokasi_file'], $row['file_name'], isset($_GET['download']));
}

if (isset($_GET['action']) && $_GET['action'] === 'view_dok_usulan' && isset($_GET['id_dok'])) {
    while (ob_get_level()) ob_end_clean();
    $id_dok = (int)$_GET['id_dok'];
    $res = mysqli_query($con, "SELECT file_path, file_name FROM dokumen_penghapusan WHERE id_dokumen = $id_dok LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) { http_response_code(404); echo 'Dokumen tidak ditemukan.'; exit(); }
    $row = mysqli_fetch_assoc($res);
    serveFileFromDb($row['file_path'], $row['file_name'], isset($_GET['download']));
}

session_start();

// ── Hanya cek login, semua user yang login boleh akses ──────────────────────
if (!isset($_SESSION["nipp"]) || !isset($_SESSION["name"])) {
    header("Location: ../login/login_view.php");
    exit();
}

$userNipp = $_SESSION['nipp'];
$userType = isset($_SESSION['Type_User']) ? $_SESSION['Type_User'] : '';

function normalize_foto_path($p) {
  if (empty($p)) return '';
  $p = (string)$p;
  if (strpos($p, "\x00") !== false) {
    if (substr($p,0,8)==="\x89PNG\r\n\x1a\n") return 'data:image/png;base64,'.base64_encode($p);
    if (substr($p,0,3)==="\xff\xd8\xff")      return 'data:image/jpeg;base64,'.base64_encode($p);
    if (substr($p,0,4)==='GIF8')               return 'data:image/gif;base64,'.base64_encode($p);
    return '';
  }
  $p = trim($p);
  if (preg_match('#^data:image/#i',$p)) return $p;
  if (preg_match('#^https?://#i',$p))  return $p;
  if (strpos($p,'/')===0)              return $p;
  $p2 = str_replace('\\','/',$p);
  $docroot = str_replace('\\','/',realpath($_SERVER['DOCUMENT_ROOT']??'')?: '');
  if ($docroot!=='') {
    $abs = @realpath($p2);
    if ($abs) {
      $abs = str_replace('\\','/',$abs);
      if (strpos($abs,$docroot)===0) return '/'.ltrim(substr($abs,strlen($docroot)),'/');
    }
  }
  if (preg_match('#^(uploads/|\.\./uploads|/uploads)#',$p2))
    return strpos($p2,'/uploads')===0 ? $p2 : '../../'.ltrim($p2,'/');
  return $p;
}


if (isset($_GET['action']) && $_GET['action'] === 'view_dok_usulan' && isset($_GET['id_dok'])) {
    $id_dok = (int)$_GET['id_dok'];
    $res = mysqli_query($con, "SELECT file_path, file_name FROM dokumen_penghapusan WHERE id_dokumen = $id_dok LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) { http_response_code(404); echo 'Dokumen tidak ditemukan.'; exit(); }
    $row = mysqli_fetch_assoc($res);
    serveFileFromDb($row['file_path'], $row['file_name'], isset($_GET['download']));
}

// ── ACTION: Serve Dokumen HO ─────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'view_dok_ho' && isset($_GET['id_dok'])) {
    $id_dok = (int)$_GET['id_dok'];
    $res = mysqli_query($con, "SELECT lokasi_file, file_name FROM dokumen_pelaksanaan WHERE id_dokumen = $id_dok LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) { http_response_code(404); echo 'Dokumen tidak ditemukan.'; exit(); }
    $row = mysqli_fetch_assoc($res);
    serveFileFromDb($row['lokasi_file'], $row['file_name'], isset($_GET['download']));
}

// ── ACTION: Approve HO ───────────────────────────────────────────────────────
// Tidak perlu cek role — semua user yang login bisa approve (berdasarkan surat fisik HO)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_ho') {
    $ids     = array_filter(array_map('intval', explode(',', $_POST['usulan_ids'] ?? '')));
    $catatan = trim($_POST['catatan_ho'] ?? '');
    $tgl     = date('Y-m-d');
    $ok      = 0;
    $tahun_redirect = 0; // akan diisi dari tahun_usulan

    foreach ($ids as $uid) {
        // Hapus syarat status_approval_regional karena HO approve berdasarkan surat fisik
        $stmt = $con->prepare("UPDATE usulan_penghapusan SET
            status_approval_ho = 'approved', tanggal_approval_ho = ?, catatan_ho = ?, status = 'approved'
            WHERE id = ?");
        $stmt->bind_param("ssi", $tgl, $catatan, $uid);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            $ok++;
            // Buat record pelaksanaan jika belum ada
            $chk = $con->prepare("SELECT id FROM pelaksanaan_penghapusan WHERE usulan_id = ? LIMIT 1");
            $chk->bind_param("i", $uid); $chk->execute();
            if ($chk->get_result()->num_rows === 0) {
                $q_up = $con->prepare("SELECT nomor_asset_utama, profit_center, subreg, tahun_usulan FROM usulan_penghapusan WHERE id = ?");
                $q_up->bind_param("i", $uid); $q_up->execute();
                $r_up = $q_up->get_result()->fetch_assoc(); $q_up->close();
                // tanggal_persetujuan tetap hari ini (tanggal HO approve),
                // tapi tahun_usulan diambil dari data usulan untuk keperluan filter
                $ins = $con->prepare("INSERT INTO pelaksanaan_penghapusan (usulan_id, status_pelaksanaan, subreg, profit_center, tanggal_persetujuan, nipp) VALUES (?, 'Disetujui', ?, ?, ?, ?)");
                $ins->bind_param("issss", $uid, $r_up['subreg'], $r_up['profit_center'], $tgl, $userNipp);
                $ins->execute(); $ins->close();
                // ambil tahun_usulan untuk redirect
                if (!empty($r_up['tahun_usulan'])) {
                    $tahun_redirect = (int)$r_up['tahun_usulan'];
                }
            }
            $chk->close();
        }
        $stmt->close();
    }
    // Redirect ke tahun_usulan dari usulan yang baru saja disetujui,
    // bukan tahun sekarang — agar data langsung terlihat di filter yang benar
    if ($tahun_redirect === 0) {
        // fallback: ambil tahun_usulan dari id pertama
        $first_id = reset($ids);
        $q_thn = $con->prepare("SELECT tahun_usulan FROM usulan_penghapusan WHERE id = ? LIMIT 1");
        $q_thn->bind_param("i", $first_id); $q_thn->execute();
        $r_thn = $q_thn->get_result()->fetch_assoc(); $q_thn->close();
        $tahun_redirect = !empty($r_thn['tahun_usulan']) ? (int)$r_thn['tahun_usulan'] : (int)date('Y');
    }
    $_SESSION['success_message'] = "✅ $ok usulan berhasil disetujui HO.";
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=upload&tahun=" . $tahun_redirect); exit();
}

// ── ACTION: Reject HO ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject_ho') {
    $uid     = (int)($_POST['usulan_id'] ?? 0);
    $catatan = trim($_POST['catatan_ho'] ?? '');
    $tgl     = date('Y-m-d');
    $stmt = $con->prepare("UPDATE usulan_penghapusan SET status_approval_ho='rejected', tanggal_approval_ho=?, catatan_ho=?, status='rejected' WHERE id=?");
    $stmt->bind_param("ssi", $tgl, $catatan, $uid); $stmt->execute(); $stmt->close();
    // Ambil tahun_usulan untuk redirect yang benar
    $q_thn_r = $con->prepare("SELECT tahun_usulan FROM usulan_penghapusan WHERE id = ? LIMIT 1");
    $q_thn_r->bind_param("i", $uid); $q_thn_r->execute();
    $r_thn_r = $q_thn_r->get_result()->fetch_assoc(); $q_thn_r->close();
    $tahun_reject = !empty($r_thn_r['tahun_usulan']) ? (int)$r_thn_r['tahun_usulan'] : (int)date('Y');
    $_SESSION['success_message'] = "❌ Usulan berhasil ditolak.";
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=daftar&tahun=" . $tahun_reject); exit();
}

// ── ACTION: Delete Dokumen HO ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_dokumen') {
    $filterTahun = isset($_POST['tahun']) ? (int)$_POST['tahun'] : date('Y'); // fix: define before use
    $dokumen_id  = (int)($_POST['dokumen_id'] ?? 0);
    if ($dokumen_id > 0) {
        $filePath = null;
        $stmt = $con->prepare("SELECT lokasi_file FROM dokumen_pelaksanaan WHERE id_dokumen = ? LIMIT 1");
        $stmt->bind_param('i', $dokumen_id);
        $stmt->execute();
        $stmt->bind_result($filePath);
        $stmt->fetch();
        $stmt->close();

        if (!empty($filePath) && strpos($filePath, 'data:') !== 0 && file_exists($filePath)) {
            @unlink($filePath);
        }

        $stmt = $con->prepare("DELETE FROM dokumen_pelaksanaan WHERE id_dokumen = ?");
        $stmt->bind_param('i', $dokumen_id);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();
        $_SESSION['success_message'] = $deleted > 0 ? '✅ Dokumen berhasil dihapus.' : '⚠️ Dokumen tidak ditemukan.';
    } else {
        $_SESSION['warning_message'] = 'ID dokumen tidak valid.';
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=upload&tahun=' . $filterTahun);
    exit();
}

// ── ACTION: Upload Dokumen HO ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_dok_ho') {
    $ids_str   = trim($_POST['usulan_ids'] ?? '');
    $deskripsi = trim($_POST['deskripsi_dokumen'] ?? '');
    $tahun_dok = (int)($_POST['tahun_dokumen'] ?? date('Y'));
    $file      = $_FILES['file_dokumen'] ?? null;

    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['warning_message'] = 'Pilih file PDF terlebih dahulu!';
    } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'pdf') {
        $_SESSION['warning_message'] = 'Format file harus PDF!';
    } elseif ($file['size'] > 50 * 1024 * 1024) {
        $_SESSION['warning_message'] = 'Ukuran file maksimal 50MB!';
    } elseif (empty($ids_str)) {
        $_SESSION['warning_message'] = 'Pilih minimal 1 aset!';
    } else {
        $ids       = array_filter(array_map('intval', explode(',', $ids_str)));
        $file_name = basename($file['name']);
        $file_size = $file['size'];

        $fileData = file_get_contents($file['tmp_name']);
        if ($fileData === false || strlen($fileData) === 0) {
            $_SESSION['warning_message'] = 'Gagal membaca file upload.';
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=upload&tahun=" . $tahun_dok);
            exit();
        }

        // Kumpulkan semua nomor aset dan pastikan pelaksanaan sudah ada
        $no_aset_list = [];
        $id_pel_first = null;

        foreach ($ids as $uid) {
            // Ambil nomor aset
            $q_no = $con->prepare("SELECT nomor_asset_utama FROM usulan_penghapusan WHERE id = ? LIMIT 1");
            $q_no->bind_param("i", $uid); $q_no->execute();
            $r_no = $q_no->get_result()->fetch_assoc(); $q_no->close();
            if ($r_no) $no_aset_list[] = $r_no['nomor_asset_utama'];

            // Pastikan pelaksanaan ada
            $q_pel = $con->prepare("SELECT id FROM pelaksanaan_penghapusan WHERE usulan_id = ? LIMIT 1");
            $q_pel->bind_param("i", $uid); $q_pel->execute();
            $r_pel = $q_pel->get_result()->fetch_assoc(); $q_pel->close();

            if (!$r_pel) {
                $q_up = $con->prepare("SELECT nomor_asset_utama, profit_center, subreg FROM usulan_penghapusan WHERE id = ?");
                $q_up->bind_param("i", $uid); $q_up->execute();
                $r_up = $q_up->get_result()->fetch_assoc(); $q_up->close();
                if ($r_up) {
                    $ins2 = $con->prepare("INSERT INTO pelaksanaan_penghapusan (usulan_id, status_pelaksanaan, subreg, profit_center, tanggal_persetujuan, nipp) VALUES (?, 'Disetujui', ?, ?, ?, ?)");
                    $tgl_now = date('Y-m-d');
                    $ins2->bind_param("issss", $uid, $r_up['subreg'], $r_up['profit_center'], $tgl_now, $userNipp);
                    $ins2->execute(); $ins2->close();
                    $q_pel2 = $con->prepare("SELECT id FROM pelaksanaan_penghapusan WHERE usulan_id = ? LIMIT 1");
                    $q_pel2->bind_param("i", $uid); $q_pel2->execute();
                    $r_pel = $q_pel2->get_result()->fetch_assoc(); $q_pel2->close();
                }
            }
            // Simpan id_pelaksanaan pertama sebagai anchor
            if ($r_pel && $id_pel_first === null) $id_pel_first = (int)$r_pel['id'];
        }

        // Gabung semua nomor aset jadi 1 string
        $no_aset_str = implode('; ', $no_aset_list);

        // ── INSERT HANYA 1 ROW untuk semua aset yang dipilih ──
        $inserted = false;
        if ($id_pel_first !== null) {
            $null = null;
            $ins = $con->prepare("INSERT INTO dokumen_pelaksanaan
                (id_pelaksanaan, tahun_dokumen, deskripsi_dokumen, nomor_aset, lokasi_file, file_name, file_size, nipp)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->bind_param("iissbsss", $id_pel_first, $tahun_dok, $deskripsi, $no_aset_str, $null, $file_name, $file_size, $userNipp);
            $ins->send_long_data(4, $fileData);
            $inserted = $ins->execute();
            $ins->close();
        }

        $_SESSION['success_message'] = $inserted
            ? "✅ Dokumen HO berhasil diupload untuk " . count($ids) . " aset (" . implode(', ', $no_aset_list) . ")."
            : "⚠️ Gagal upload dokumen.";
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=upload&tahun=" . $tahun_dok); exit();
}

// ── QUERY DATA ───────────────────────────────────────────────────────────────
// filterTahun: dari GET jika ada, default-nya nanti ditentukan setelah query list_tahun
$filterTahun = isset($_GET['tahun']) && !empty($_GET['tahun']) ? (int)$_GET['tahun'] : 0;
$activeTab   = isset($_GET['tab']) ? $_GET['tab'] : 'daftar';

// Tahun dropdown - ambil dari tahun_usulan yang valid di DB, selalu tambahkan tahun sekarang
$list_tahun = [];
$res_thn = mysqli_query($con, "SELECT DISTINCT tahun_usulan as t FROM usulan_penghapusan WHERE status_approval_regional='approved' AND tahun_usulan IS NOT NULL AND tahun_usulan > 0 ORDER BY t DESC");
while ($r = mysqli_fetch_assoc($res_thn)) if (!empty($r['t'])) $list_tahun[] = (int)$r['t'];
// Selalu masukkan tahun sekarang di dropdown meski belum ada data
if (!in_array((int)date('Y'), $list_tahun)) $list_tahun[] = (int)date('Y');
$list_tahun = array_unique($list_tahun);
rsort($list_tahun); // pastikan urut DESC
// Default: selalu tahun sekarang (2026) saat buka menu tanpa parameter
if ($filterTahun === 0) {
    $filterTahun = (int)date('Y');
}

// Tab Daftar: semua usulan yang sudah approved regional, belum/pending HO
// Tidak filter per user — semua bisa lihat & approve
$res_p = mysqli_query($con, "SELECT up.*,
    up.nama_aset, up.kategori_aset, up.profit_center_text, up.subreg,
    up.nilai_perolehan as nilai_perolehan_sd, up.nilai_buku,
    up.tgl_perolehan, up.umur_ekonomis, up.sisa_umur_ekonomis,
    up.justifikasi_alasan, up.kajian_hukum, up.kajian_ekonomis, up.kajian_risiko,
    up.tahun_usulan,
    (SELECT COUNT(*) FROM dokumen_penghapusan dp
     WHERE dp.usulan_id = up.id
        OR dp.no_aset LIKE CONCAT('%', up.nomor_asset_utama, '%')
    ) as jml_dok_usulan
    FROM usulan_penghapusan up
    WHERE up.status_approval_regional = 'approved'
      AND (up.status_approval_ho IS NULL OR up.status_approval_ho = 'pending')
      AND up.tahun_usulan = $filterTahun
      AND up.tahun_usulan IS NOT NULL AND up.tahun_usulan > 0
    ORDER BY up.created_at DESC");
$data_pending = [];
while ($r = mysqli_fetch_assoc($res_p)) {
    $r['nama_aset'] = str_replace('AUC-', '', $r['nama_aset']??'');
    $r['foto_path'] = normalize_foto_path($r['foto_path'] ?? '');
    // fallback: pastikan subreg dan profit_center_text tidak kosong
    if (empty($r['subreg']))           $r['subreg']           = $r['profit_center'] ?? '-';
    if (empty($r['profit_center_text'])) $r['profit_center_text'] = $r['profit_center'] ?? '-';
    $data_pending[] = $r;
}

// Tab Upload: yang sudah approved HO
$res_a = mysqli_query($con, "SELECT up.*,
    up.nama_aset, up.profit_center_text, up.subreg, up.nilai_buku,
    pel.id as id_pelaksanaan,
    (SELECT COUNT(*) FROM dokumen_pelaksanaan dp WHERE dp.id_pelaksanaan = pel.id) as jml_dok_ho
    FROM usulan_penghapusan up
    LEFT JOIN pelaksanaan_penghapusan pel ON pel.usulan_id = up.id
    WHERE up.status_approval_ho = 'approved' AND up.tahun_usulan = $filterTahun
      AND up.tahun_usulan IS NOT NULL AND up.tahun_usulan > 0
    ORDER BY up.tanggal_approval_ho DESC");
$data_approved = [];
while ($r = mysqli_fetch_assoc($res_a)) { $r['nama_aset'] = str_replace('AUC-', '', $r['nama_aset']??''); $data_approved[] = $r; }

// Dokumen usulan untuk preview di modal detail
$res_du = mysqli_query($con, "SELECT dp.id_dokumen, dp.usulan_id, dp.tipe_dokumen, dp.file_name, dp.no_aset,
    up.nomor_asset_utama,
    up.tahun_usulan
    FROM dokumen_penghapusan dp
    JOIN usulan_penghapusan up ON dp.usulan_id = up.id
    WHERE up.status_approval_regional = 'approved' ORDER BY dp.id_dokumen DESC");
$daftar_dok_usulan = [];
while ($r = mysqli_fetch_assoc($res_du)) $daftar_dok_usulan[] = $r;

// Dokumen HO (untuk tabel preview upload) — hanya kategori='ho'
$res_dh = mysqli_query($con, "SELECT dp.*, pp.usulan_id,
    up.profit_center, up.subreg, up.profit_center_text
    FROM dokumen_pelaksanaan dp
    JOIN pelaksanaan_penghapusan pp ON dp.id_pelaksanaan = pp.id
    JOIN usulan_penghapusan up ON pp.usulan_id = up.id
    WHERE COALESCE(dp.kategori, 'ho') = 'ho'
    ORDER BY dp.id_dokumen DESC");
$daftar_dok_ho = [];
while ($r = mysqli_fetch_assoc($res_dh)) $daftar_dok_ho[] = $r;

// Counter summary boxes
$cnt_pending  = count($data_pending);
$cnt_approved = count($data_approved);
$res_rej = mysqli_query($con, "SELECT COUNT(*) as c FROM usulan_penghapusan WHERE status_approval_ho='rejected' AND tahun_usulan=$filterTahun AND tahun_usulan IS NOT NULL AND tahun_usulan > 0");
$cnt_rejected = mysqli_fetch_assoc($res_rej)['c'] ?? 0;

// Dropdown tahun — query ulang untuk render HTML (sama logika seperti di atas)
$list_tahun = [];
$res_thn = mysqli_query($con, "SELECT DISTINCT tahun_usulan as t FROM usulan_penghapusan WHERE status_approval_regional='approved' AND tahun_usulan IS NOT NULL AND tahun_usulan > 0 ORDER BY t DESC");
while ($r = mysqli_fetch_assoc($res_thn)) if (!empty($r['t'])) $list_tahun[] = (int)$r['t'];
if (!in_array((int)date('Y'), $list_tahun)) $list_tahun[] = (int)date('Y');
$list_tahun = array_unique($list_tahun);
rsort($list_tahun);
// filterTahun sudah di-set di atas, tidak perlu override lagi

$success_msg = $_SESSION['success_message'] ?? '';
$warning_msg = $_SESSION['warning_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['warning_message']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
  <title>Persetujuan Penghapusan - Web Aset Tetap</title>
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
    .sidebar-brand{background-color:#0b3a8c!important;margin-bottom:0!important;padding:.25rem 0!important;border-bottom:0!important;box-shadow:none!important;}
    .sidebar-brand .brand-link{display:block!important;padding:.5rem .75rem!important;border-bottom:0!important;box-shadow:none!important;background-color:transparent!important;}
    .sidebar-brand .brand-link .brand-image{display:block!important;height:auto!important;max-height:48px!important;margin:0!important;padding:6px 8px!important;background-color:transparent!important;}
    .app-sidebar{background-color:#0b3a8c!important;border-right:0!important;}
    .app-sidebar,.app-sidebar a,.app-sidebar .nav-link,.app-sidebar .nav-link p,.app-sidebar .nav-header,.app-sidebar .brand-text,.app-sidebar .nav-icon,.app-sidebar .nav-badge{color:#fff!important;fill:#fff!important;}
    .app-sidebar .nav-link .nav-icon,.app-sidebar .nav-link i{color:#fff!important;}
    .app-sidebar .nav-link.active,.app-sidebar .nav-link:hover{background-color:#0b5db7!important;color:#fff!important;}
    .app-sidebar .nav-link.active .nav-icon,.app-sidebar .nav-link:hover .nav-icon,.app-sidebar .nav-link.active i,.app-sidebar .nav-link:hover i{color:#fff!important;}

    /* Summary boxes — identik approval_regional */
    .small-box{border-radius:10px;padding:20px 24px;color:#fff;display:flex;align-items:center;justify-content:space-between;position:relative;overflow:hidden;}
    .small-box .inner h3{font-size:2.4rem;font-weight:700;line-height:1;margin:0;}
    .small-box .inner p{font-size:.82rem;opacity:.88;margin:4px 0 0;}
    .small-box-icon{font-size:3.5rem;opacity:.22;}

    /* Nav tabs */
    .nav-tabs .nav-link{font-weight:500;color:#6b7280;}
    .nav-tabs .nav-link.active{color:#0b3a8c;border-bottom:2px solid #0b3a8c;font-weight:600;}

    /* Table */
    .table-responsive{overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid #dee2e6;border-radius:.25rem;}
    .table-responsive::-webkit-scrollbar{height:8px;}
    .table-responsive::-webkit-scrollbar-track{background:#f1f1f1;}
    .table-responsive::-webkit-scrollbar-thumb{background:#888;border-radius:4px;}
    #daftarTable thead th,#daftarTable tbody td,#uploadTable thead th,#uploadTable tbody td{
      padding:9px 13px;vertical-align:middle;white-space:normal;font-size:.875rem;}
    #daftarTable thead th,#uploadTable thead th{
      background:#f8f9fa;font-weight:600;font-size:.875rem;border-bottom:2px solid #dee2e6;white-space:nowrap;}
    #daftarTable tbody tr:hover,#uploadTable tbody tr:hover{background:#f5f8ff;}
    /* Kolom no, nomor aset, nilai buku, jml dok, aksi: nowrap */
    #daftarTable td:nth-child(1),#daftarTable td:nth-child(2),#daftarTable td:nth-child(3),
    #daftarTable td:nth-child(7),#daftarTable td:nth-child(8),#daftarTable td:nth-child(9),
    #daftarTable td:nth-child(10){white-space:nowrap;font-size:.875rem;}
    /* Nama aset: ellipsis via class */
    #daftarTable td:nth-child(4){
      white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
      max-width:180px;font-size:.875rem;}
    /* SubReg, Profit Center */
    #daftarTable td:nth-child(5),#daftarTable td:nth-child(6){
      white-space:normal;min-width:90px;font-size:.875rem;}
    .no-aset-cell{white-space:normal;word-break:break-word;min-width:160px;max-width:240px;font-size:.875rem;}
    .selected-bar{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 16px;margin-bottom:14px;display:none;align-items:center;gap:10px;flex-wrap:wrap;}
    .selected-bar.show{display:flex;}

    /* Card table wrap */
    .card-table-wrap{border:1px solid #dee2e6;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-bottom:20px;background:#fff;}
    .card-table-wrap .ctw-header{padding:14px 20px;border-bottom:1px solid #dee2e6;background:#fff;display:flex;align-items:center;gap:8px;}
    .card-table-wrap .ctw-header h5{margin:0;font-size:.95rem;font-weight:600;color:#1f2937;}

    /* Foto aset */
    .foto-aset-img{max-height:220px;object-fit:contain;cursor:pointer;border-radius:8px;border:1px solid #e9ecef;transition:box-shadow .2s;}
    .foto-aset-img:hover{box-shadow:0 4px 16px rgba(0,0,0,.12);}
    /* Kajian */
    .kajian-item{margin-bottom:14px;}
    .kajian-item:last-child{margin-bottom:0;}
    .kajian-label{font-size:.72rem;font-weight:600;color:#6b7280;margin-bottom:5px;}
    .kajian-box{background:#f8f9fa;border-left:3px solid #0d6efd;border-radius:0 6px 6px 0;padding:9px 13px;font-size:.875rem;color:#374151;white-space:pre-wrap;word-break:break-word;line-height:1.6;}
    .kajian-box.empty{border-left-color:#e5e7eb;color:#9ca3af;font-style:italic;}

    /* Detail modal */
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

    /* Modal */
    .modal{padding-left:0!important;}
    body.modal-open{overflow:hidden!important;padding-right:0!important;}

    .badge-pill{padding:3px 11px;border-radius:20px;font-size:.8rem;font-weight:600;display:inline-block;}
  </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">

  <!-- ── HEADER ── -->
  <nav class="app-header navbar navbar-expand bg-white border-0 shadow-none" style="border-bottom:0!important;box-shadow:none!important;">
    <div class="container-fluid">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item">
          <a class="nav-link" href="#" data-lte-toggle="fullscreen">
            <i data-lte-icon="maximize" class="bi bi-arrows-fullscreen"></i>
            <i data-lte-icon="minimize" class="bi bi-fullscreen-exit" style="display:none"></i>
          </a>
        </li>
        <li class="nav-item dropdown user-menu">
          <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
            <img src="../../dist/assets/img/profile.png" class="user-image rounded-circle shadow" alt="User"/>
            <span class="d-none d-md-inline"><?= htmlspecialchars($_SESSION['name']) ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
            <li class="user-header text-bg-primary text-center">
              <img src="../../dist/assets/img/profile.png" class="rounded-circle shadow mb-2" style="width:80px;height:80px;" alt="User">
              <p class="mb-0 fw-bold"><?= htmlspecialchars($_SESSION['name']) ?></p>
              <small>NIPP: <?= htmlspecialchars($_SESSION['nipp']) ?></small>
            </li>
            <li class="user-menu-body">
              <div class="row ps-3 pe-3 pt-2 pb-2">
                <div class="col-6 text-start">
                  <small class="text-muted">Type User:</small><br>
                  <span class="badge bg-primary"><?= htmlspecialchars($userType) ?></span>
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

  <!-- ── SIDEBAR ── -->
  <aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
    <div class="sidebar-brand">
      <a href="./index.html" class="brand-link">
        <img src="../../dist/assets/img/logo.png" class="brand-image" alt="Logo"/>
      </a>
    </div>
    <div class="sidebar-wrapper">
      <nav class="mt-2">
        <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="navigation" data-accordion="false">
          <?php  
            $userNipp = isset($_SESSION['nipp']) ? htmlspecialchars($_SESSION['nipp']) : '';
            $query = "SELECT menus.menu, menus.nama_menu, menus.urutan_menu FROM user_access INNER JOIN menus ON user_access.id_menu = menus.id_menu WHERE user_access.NIPP = '" . mysqli_real_escape_string($con, $userNipp) . "' ORDER BY menus.urutan_menu ASC";
            $result_menu = mysqli_query($con, $query) or die(mysqli_error($con));
            $iconMap = [
                'Dasboard'                       => 'bi bi-grid-fill',
                'Usulan Penghapusan'              => 'bi bi-file-earmark-plus',
                'Daftar Usulan Penghapusan'       => 'bi bi-collection',
                'Approval SubReg'                 => 'bi bi-person-check',
                'Approval Regional'               => 'bi bi-building-check',
                'Persetujuan Penghapusan'         => 'bi bi-shield-check',
                'Daftar Persetujuan Penghapusan'  => 'bi bi-journal-check',
                'Pelaksanaan Penghapusan'         => 'bi bi-gear-wide-connected',
                'Daftar Pelaksanaan Penghapusan'  => 'bi bi-archive-fill',
                'Manajemen Menu'                  => 'bi bi-layout-text-sidebar',
                'Import DAT'                      => 'bi bi-file-earmark-arrow-up',
                'Import Data Penyusutan'          => 'bi bi-cloud-upload',
                'Daftar Data Penyusutan'          => 'bi bi-table',
                'Selisih Penyusutan'              => 'bi bi-bar-chart-line',
                'Daftar Aset Tetap'               => 'bi bi-boxes',
                'Manajemen User'                  => 'bi bi-people',
            ];

            // ── Pengelompokan menu jadi 3 grup dropdown: Penghapusan, Penyusutan, Manajemen Admin ──
            // (menu di luar mapping ini, misal "Dasboard", dirender sebagai item biasa di luar grup)
            $groupMap = [
                'Usulan Penghapusan'             => 'Penghapusan',
                'Daftar Usulan Penghapusan'      => 'Penghapusan',
                'Approval SubReg'                => 'Penghapusan',
                'Approval Regional'              => 'Penghapusan',
                'Persetujuan Penghapusan'        => 'Penghapusan',
                'Daftar Persetujuan Penghapusan' => 'Penghapusan',
                'Pelaksanaan Penghapusan'        => 'Penghapusan',
                'Daftar Aset Tetap'              => 'Penghapusan',
                'Daftar Pelaksanaan Penghapusan' => 'Penghapusan',

                'Import Data Penyusutan'         => 'Penyusutan',
                'Daftar Data Penyusutan'         => 'Penyusutan',
                'Selisih Penyusutan'             => 'Penyusutan',

                'Import DAT'                     => 'Manajemen Admin',
                'Manajemen Menu'                 => 'Manajemen Admin',
                'Manajemen User'                 => 'Manajemen Admin',
            ];
            $groupIcon = [
                'Penghapusan'      => 'bi bi-file-earmark-minus',
                'Penyusutan'       => 'bi bi-graph-down-arrow',
                'Manajemen Admin'  => 'bi bi-sliders',
            ];
            $groupOrder = ['Penghapusan', 'Penyusutan', 'Manajemen Admin'];

            $currentPage = basename($_SERVER['PHP_SELF']);

            $ungrouped = [];
            $grouped   = [];
            while ($row = mysqli_fetch_assoc($result_menu)) {
                $namaMenu = trim($row['nama_menu']);
                if (isset($groupMap[$namaMenu])) {
                    $grouped[$groupMap[$namaMenu]][] = $row;
                } else {
                    $ungrouped[] = $row;
                }
            }

            // ── Render item di luar grup (mis. Dasboard) di paling atas, seperti sebelumnya ──
            foreach ($ungrouped as $row) {
                $namaMenu = trim($row['nama_menu']);
                $icon     = $iconMap[$namaMenu] ?? 'bi bi-circle';
                $isActive = ($currentPage === $row['menu'] . '.php') ? 'active' : '';
                echo '<li class="nav-item"><a href="../' . $row['menu'] . '/' . $row['menu'] . '.php" class="nav-link ' . $isActive . '"><i class="nav-icon ' . $icon . '"></i><p>' . htmlspecialchars($namaMenu) . '</p></a></li>';
            }

            // ── Render tiap grup sebagai dropdown treeview, isinya cuma menu yang user PUNYA AKSES ──
            foreach ($groupOrder as $groupName) {
                if (empty($grouped[$groupName])) continue; // user gak punya akses menu apapun di grup ini

                $itemsGrup = $grouped[$groupName];
                $adaAktif  = false;
                foreach ($itemsGrup as $itemG) {
                    if ($currentPage === $itemG['menu'] . '.php') { $adaAktif = true; break; }
                }
                $liClassGrup   = 'nav-item' . ($adaAktif ? ' menu-open' : '');
                $linkClassGrup = 'nav-link' . ($adaAktif ? ' active' : '');
                $iconGrup      = $groupIcon[$groupName] ?? 'bi bi-folder';

                echo '<li class="' . $liClassGrup . '">';
                echo '<a href="#" class="' . $linkClassGrup . '"><i class="nav-icon ' . $iconGrup . '"></i><p>' . htmlspecialchars($groupName) . '<i class="nav-arrow bi bi-chevron-right"></i></p></a>';
                echo '<ul class="nav nav-treeview">';
                foreach ($itemsGrup as $itemG) {
                    $namaMenuG = trim($itemG['nama_menu']);
                    $iconItemG = $iconMap[$namaMenuG] ?? 'bi bi-circle';
                    $isActiveG = ($currentPage === $itemG['menu'] . '.php') ? 'active' : '';
                    echo '<li class="nav-item"><a href="../' . $itemG['menu'] . '/' . $itemG['menu'] . '.php" class="nav-link ' . $isActiveG . '"><i class="nav-icon ' . $iconItemG . '"></i><p>' . htmlspecialchars($namaMenuG) . '</p></a></li>';
                }
                echo '</ul>';
                echo '</li>';
            }
              ?>
        </ul>
      </nav>
    </div>
  </aside>

  <!-- ── MAIN ── -->
  <main class="app-main">
    <div class="app-content-header">
      <div class="container-fluid">
        <div class="row">
          <div class="col-sm-6">
            <h3 class="mb-0">Persetujuan Penghapusan</h3>
          </div>
          <div class="col-sm-6 d-flex align-items-center justify-content-end gap-2">
            <form method="GET" class="d-flex align-items-center gap-2 me-3">
              <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
              <label class="mb-0 text-muted small fw-semibold text-nowrap">
                <i class="bi bi-calendar3 me-1"></i>Tahun:
              </label>
              <select name="tahun" class="form-select form-select-sm" style="min-width:100px;" onchange="this.form.submit()">
                <?php foreach ($list_tahun as $t): ?>
                  <option value="<?= $t ?>" <?= $t == $filterTahun ? 'selected' : '' ?>><?= $t ?></option>
                <?php endforeach; ?>
              </select>
            </form>
            <ol class="breadcrumb float-sm-end mb-0">
              <li class="breadcrumb-item"><a href="../dasbor/dasbor.php">Home</a></li>
              <li class="breadcrumb-item active">Persetujuan Penghapusan</li>
            </ol>
          </div>
        </div>
      </div>
    </div>

    <div class="app-content">
      <div class="container-fluid">

        <?php if ($success_msg): ?>
          <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>
        <?php if ($warning_msg): ?>
          <div class="alert alert-warning alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($warning_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <!-- Summary Boxes -->
        <div class="row mb-4">
          <div class="col-md-4">
            <div class="small-box" style="background:linear-gradient(135deg,#FFC107,#FFB300);">
              <div class="inner"><h3><?= $cnt_pending ?></h3><p>Menunggu Persetujuan HO</p></div>
              <i class="bi bi-clock small-box-icon"></i>
            </div>
          </div>
          <div class="col-md-4">
            <div class="small-box" style="background:linear-gradient(135deg,#28A745,#218838);">
              <div class="inner"><h3><?= $cnt_approved ?></h3><p>Disetujui HO</p></div>
              <i class="bi bi-check-circle small-box-icon"></i>
            </div>
          </div>
          <div class="col-md-4">
            <div class="small-box" style="background:linear-gradient(135deg,#c83636,#961313);">
              <div class="inner"><h3><?= $cnt_rejected ?></h3><p>Ditolak HO</p></div>
              <i class="bi bi-x-circle small-box-icon"></i>
            </div>
          </div>
        </div>

        <!-- Card + Tabs -->
        <div class="row"><div class="col-12"><div class="card"><div class="card-body">

          <ul class="nav nav-tabs mb-3" role="tablist">
            <li class="nav-item">
              <button class="nav-link <?= $activeTab==='daftar'?'active':'' ?>"
                      data-bs-toggle="tab" data-bs-target="#tab-daftar" type="button"
                      onclick="updateTabUrl('daftar')">
                <i class="bi bi-list-check me-2"></i>Daftar Persetujuan
              
              </button>
            </li>
            <li class="nav-item">
              <button class="nav-link <?= $activeTab==='upload'?'active':'' ?>"
                      data-bs-toggle="tab" data-bs-target="#tab-upload" type="button"
                      onclick="updateTabUrl('upload')">
                <i class="bi bi-upload me-2"></i>Upload Dokumen Persetujuan HO
               
              </button>
            </li>
          </ul>

          <div class="tab-content">

            <!-- ══ TAB 1: DAFTAR PERSETUJUAN ══ -->
            <div class="tab-pane fade <?= $activeTab==='daftar'?'show active':'' ?>" id="tab-daftar">

              <!-- Selected Bar -->
              <div class="selected-bar" id="selectedBar">
                <i class="bi bi-check2-square text-primary"></i>
                <span id="selectedCount" class="fw-semibold text-primary">0 aset dipilih</span>
                <button class="btn btn-success btn-sm ms-auto" onclick="openApproveModal()">
                  <i class="bi bi-check-circle me-1"></i>Setujui yang Dipilih
                </button>
              </div>

              <div class="card-table-wrap">
                <div class="ctw-header">
                  <i class="bi bi-clock-history text-warning"></i>
                  <h5>Menunggu Persetujuan HO</h5>
                  <span class="badge bg-primary me-2">Tahun <?= $filterTahun ?></span>
                </div>
                <div class="p-3">
                  <?php if (empty($data_pending)): ?>
                    <div class="alert alert-info mb-0">
                      <i class="bi bi-info-circle me-2"></i>
                      Belum ada usulan yang menunggu persetujuan HO untuk tahun <?= $filterTahun ?>.
                    </div>
                  <?php else: ?>
                  <div class="table-responsive">
                    <table id="daftarTable" class="table table-bordered table-hover align-middle w-100">
                      <thead>
                        <tr>
                          <th><input type="checkbox" id="checkAll" class="form-check-input" title="Pilih semua"></th>
                          <th>No</th>
                          <th>Nomor Aset</th>
                          <th>Nama Aset</th>
                          <th>SubReg</th>
                          <th>Profit Center</th>
                          <th>Mekanisme</th>
                          <th>Nilai Buku</th>
                          <th>Jumlah Dokumen</th>
                          <th>Aksi</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($data_pending as $i => $u): ?>
                        <tr>
                          <td class="text-center">
                            <input type="checkbox" class="form-check-input row-check"
                                   value="<?= $u['id'] ?>"
                                   data-no="<?= htmlspecialchars($u['nomor_asset_utama']) ?>">
                          </td>
                          <td class="text-center"><?= $i+1 ?></td>
                          <td><code style="color:#2563eb;"><?= htmlspecialchars($u['nomor_asset_utama']) ?></code></td>
                          <td><?= htmlspecialchars($u['nama_aset']??'-') ?></td>
                          <td><?= htmlspecialchars($u['subreg']??'-') ?></td>
                          <td><?= htmlspecialchars($u['profit_center_text']??$u['profit_center']??'-') ?></td>
                          <td>
                            <?php if ($u['mekanisme_penghapusan']==='Jual Lelang'): ?>
                              <span class="badge-pill" style="background:#e0f2fe;color:#0369a1;">Jual Lelang</span>
                            <?php elseif ($u['mekanisme_penghapusan']==='Hapus Administrasi'): ?>
                              <span class="badge-pill" style="background:#ffedd5;color:#c2410c;">Hapus Administrasi</span>
                            <?php else: ?>—<?php endif; ?>
                          </td>
                          <td style="font-family:monospace;font-size:.82rem;">
                            <?= $u['nilai_buku'] ? 'Rp '.number_format($u['nilai_buku'],0,',','.') : '-' ?>
                          </td>
                          <td class="text-center"><span class="badge bg-info"><?= (int)$u['jml_dok_usulan'] ?></span></td>
                          <td style="white-space:nowrap;">
                            <button class="btn btn-sm btn-outline-primary" onclick="openDetail(<?= $u['id'] ?>)" title="Review Dokumen">
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
              </div>
            </div>
            <!-- End Tab Daftar -->

            <!-- ══ TAB 2: UPLOAD DOKUMEN HO ══ -->
            <div class="tab-pane fade <?= $activeTab==='upload'?'show active':'' ?>" id="tab-upload">

              <!-- Form Upload -->
              <div class="card mb-3" style="border:1px solid #dee2e6;border-radius:8px;">
                <div class="card-header" style="background:#0b3a8c;color:#fff;border-radius:8px 8px 0 0;">
                  <strong><i class="bi bi-cloud-upload me-2"></i>Form Upload Dokumen HO</strong>
                </div>
                <div class="card-body">
                  <?php if (empty($data_approved)): ?>
                    <div class="alert alert-info mb-0">
                      <i class="bi bi-info-circle me-2"></i>
                      Belum ada aset yang disetujui HO. Setujui terlebih dahulu di tab
                      <strong>Daftar Persetujuan</strong>.
                    </div>
                  <?php else: ?>
                  <form method="POST" enctype="multipart/form-data" id="formUploadHo">
                    <input type="hidden" name="action" value="upload_dok_ho">
                    <input type="hidden" name="tahun_dokumen" id="uploadTahunHidden" value="<?= $filterTahun ?>">
                    <input type="hidden" name="usulan_ids" id="upload_ids_input">

                    <div class="mb-3">
                      <label class="form-label">Deskripsi Dokumen <span class="text-danger">*</span></label>
                      <input type="text" class="form-control" name="deskripsi_dokumen" required placeholder="Contoh: SK Direksi No. xxx/2026">
                    </div>

                    <div class="mb-3">
                      <label class="form-label">Tahun Dokumen <span class="text-danger">*</span></label>
                      <input type="text" class="form-control" value="<?= htmlspecialchars($filterTahun) ?>" readonly disabled>
                      <!-- <div class="form-text">Tahun dokumen diambil dari data yang tersimpan, tidak dapat diubah manual.</div> -->
                    </div>

                    <div class="mb-3">
                      <label class="form-label">Nomor Aset <span class="text-danger">*</span></label>
                      <div class="input-group">
                        <input type="text" class="form-control" id="uploadNomorAset" placeholder="Klik tombol untuk pilih aset" readonly>
                        <button type="button" class="btn btn-outline-primary" onclick="openAsetPickerModal()">
                          <i class="bi bi-search me-1"></i>Pilih Aset
                        </button>
                      </div>
                      <div id="uploadSelectedAssetsList" class="mt-1" style="font-size:.85rem;color:#374151;display:none;"></div>
                    </div>

                    <div class="mb-3">
                      <label class="form-label">File PDF <span class="text-danger">*</span></label>
                      <input type="file" class="form-control" name="file_dokumen" id="uploadFileDokumen" accept=".pdf" required>
                      <div class="form-text">Format: <strong>.pdf</strong>. Maksimal 50MB.</div>
                    </div>

                    <button type="submit" class="btn btn-primary" id="btnUpload" disabled>
                      <i class="bi bi-cloud-upload me-1"></i>Upload Dokumen
                    </button>
                  </form>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Preview Dokumen Terupload -->
              <div class="card" style="border:1px solid #28a745;box-shadow:0 1px 3px rgba(40,167,69,.1);">
                <div class="card-header" style="background:linear-gradient(135deg,#28a745,#20c997);color:#fff;border-radius:4px 4px 0 0;">
                  <strong><i class="bi bi-file-earmark-pdf me-2"></i>Preview Dokumen Terupload (<?= count($daftar_dok_ho) ?>)</strong>
                </div>
                <div class="card-body p-0">
                  <div class="table-responsive">
                    <table id="uploadTable" class="table table-bordered table-hover mb-0" style="font-size:.9rem;">
                      <thead style="background:#f8f9fa;">
                        <tr>
                          <th>ID Dokumen</th>
                          <th>Tahun</th>
                          <th>Nomor Aset</th>
                          <th>Profit Center</th>
                          <th>Subreg</th>
                          <th>Deskripsi Dokumen</th>
                          <th>Cabang</th>
                          <th>Aksi</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php if (empty($daftar_dok_ho)): ?>
                          <tr><td colspan="8" class="text-center text-muted py-3">Belum ada dokumen yang diupload</td></tr>
                        <?php else: ?>
                          <?php foreach ($daftar_dok_ho as $d): ?>
                          <?php
                            $no_aset_raw = $d['nomor_aset'] ?? '';
                            $no_list = array_filter(array_map('trim', explode(';', $no_aset_raw)));
                          ?>
                          <tr>
                            <td><?= $d['id_dokumen'] ?></td>
                            <td><?= htmlspecialchars($d['tahun_dokumen'] ?? date('Y')) ?></td>
                            <td style="max-width:200px;word-break:break-word;">
                              <?php if (count($no_list)>1): ?>
                                <span class="badge bg-info text-dark"><?= count($no_list) ?> aset</span><br>
                                <small><?= htmlspecialchars(implode(' | ', $no_list)) ?></small>
                              <?php else: ?>
                                <?= htmlspecialchars($no_aset_raw ?: '-') ?>
                              <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($d['profit_center'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($d['subreg'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($d['deskripsi_dokumen'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($d['profit_center_text'] ?? '-') ?></td>
                            <td style="white-space:nowrap;">
                              <?php
                                $vu = "persetujuan_penghapusan.php?action=view_dok_ho&id_dok={$d['id_dokumen']}";
                                $no_aset_raw2 = $d['nomor_aset'] ?? '';
                                $no_list2 = array_filter(array_map('trim', explode(';', $no_aset_raw2)));
                                // Kumpulkan data aset untuk modal detail
                                $detail_aset_rows = [];
                                foreach ($no_list2 as $na) {
                                    $na_esc = mysqli_real_escape_string($con, $na);
                                    $ra = mysqli_query($con, "SELECT up.nomor_asset_utama, up.nama_aset, up.mekanisme_penghapusan, up.status_approval_ho
                                        FROM usulan_penghapusan up WHERE up.nomor_asset_utama = '$na_esc' LIMIT 1");
                                    if ($ra && $row_a = mysqli_fetch_assoc($ra)) $detail_aset_rows[] = $row_a;
                                }
                                $detail_aset_json = json_encode($detail_aset_rows, JSON_HEX_APOS | JSON_HEX_QUOT);
                                $pc_label = htmlspecialchars($d['profit_center'] ?? '-');
                                $subreg_label = htmlspecialchars($d['subreg'] ?? '-');
                              ?>
                              <button type="button" class="btn btn-sm btn-outline-secondary" style="margin-right:4px;"
                                      onclick="togglePrevExternal('dph-<?= $d['id_dokumen'] ?>','<?= $vu ?>')">
                                <i class="bi bi-file-earmark-pdf me-1"></i>Lihat Dokumen
                              </button>
                              <button type="button" class="btn btn-sm btn-outline-info btn-detail-aset" style="margin-right:4px;"
                                      data-aset='<?= htmlspecialchars($detail_aset_json, ENT_QUOTES, "UTF-8") ?>'
                                      data-subtitle="PC: <?= $pc_label ?> | Subreg: <?= $subreg_label ?>">
                                <i class="bi bi-table me-1"></i>Detail Aset
                              </button>
                              <button type="button" class="btn btn-sm btn-outline-danger"
                                      onclick="confirmDeleteDokumen(<?= $d['id_dokumen'] ?>, '<?= htmlspecialchars(addslashes($d['deskripsi_dokumen']??'Dokumen')) ?>', <?= $filterTahun ?>)">
                                <i class="bi bi-trash me-1"></i>Hapus
                              </button>
                            </td>
                          </tr>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>

                  <!-- Preview panel full-width di luar tabel -->
                  <div id="previewPanelUpload" style="display:none;border-top:2px solid #28a745;">
                    <div style="background:#f1f5f9;padding:8px 16px;display:flex;align-items:center;justify-content:space-between;">
                      <span style="font-size:.82rem;color:#374151;font-weight:600;">
                        <i class="bi bi-file-earmark-pdf text-danger me-1"></i>
                        Preview: <span id="previewPanelLabel">-</span>
                      </span>
                      <div style="display:flex;gap:6px;">
                        <a id="previewPanelBuka" href="#" target="_blank" class="btn btn-sm btn-outline-primary" style="font-size:.76rem;">
                          <i class="bi bi-box-arrow-up-right me-1"></i>Buka
                        </a>
                        <button onclick="tutupPreviewUpload()" class="btn btn-sm btn-outline-secondary" style="font-size:.76rem;">
                          <i class="bi bi-x-lg me-1"></i>Tutup
                        </button>
                      </div>
                    </div>
                    <iframe id="previewPanelFrame" src="" style="width:100%;height:600px;border:none;display:block;"></iframe>
                  </div>

                </div>
              </div>
            </div>
            <!-- End Tab Upload -->

          </div><!-- end tab-content -->
        </div></div></div></div><!-- end card/row -->

      </div>
    </div>
  </main>

  <footer class="app-footer">
    <div class="float-end d-none d-sm-inline">PT Pelabuhan Indonesia (Persero)</div>
    <strong>Copyright &copy; Proyek Aset Tetap Regional 3&nbsp;</strong>
  </footer>
</div>

<!-- ══ MODAL: PILIH ASET ══ -->
<div class="modal fade" id="modalAsetPicker" tabindex="-1" aria-modal="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="bi bi-search me-2"></i>Pilih Nomor Aset</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info d-flex align-items-center mb-3">
          <i class="bi bi-info-circle me-2"></i>
          <div><strong>Multiple Select:</strong> Centang beberapa aset jika 1 dokumen berlaku untuk beberapa aset sekaligus.</div>
        </div>
        <div class="alert alert-success" id="pickerSelectedCount" style="display:none;">
          <i class="bi bi-check-circle me-2"></i>
          <strong><span id="pickerCountNum">0</span> aset dipilih</strong>
        </div>
        <div class="table-responsive">
          <table id="asetPickerTable" class="table table-bordered table-hover table-sm w-100">
            <thead class="table-light">
              <tr>
                <th style="width:42px;"><input type="checkbox" id="selectAllPicker" class="form-check-input"></th>
                <th>Nomor Aset</th>
                <th>Nama Aset</th>
                <th>Mekanisme</th>
                <th>Profit Center</th>
                <th>SubReg</th>         
              </tr>
            </thead>
            <tbody>
              <?php foreach ($data_approved as $u): ?>
              <tr>
                <td class="text-center">
                  <input type="checkbox" 
                        class="form-check-input picker-check"
                         value="<?= $u['id'] ?>"
                         data-nomor="<?= htmlspecialchars($u['nomor_asset_utama']) ?>"
                         data-nama="<?= htmlspecialchars($u['nama_aset']??'-') ?>">
                  <?php
                    $sdh_ho = array_filter($daftar_dok_ho, fn($d) => (int)($d['usulan_id'] ?? 0) === (int)$u['id']);
                    if (!empty($sdh_ho)):
                  ?>
                  <!-- <br><small class="text-success" style="font-size:.68rem;white-space:nowrap;">✓ Sudah upload</small> -->
                  <?php endif; ?>
                </td>
                <td><code style="color:#2563eb;font-size:.82rem;"><?= htmlspecialchars($u['nomor_asset_utama']) ?></code></td>
                <td><?= htmlspecialchars($u['nama_aset']??'-') ?></td>
                <td><?= htmlspecialchars($u['mekanisme_penghapusan']??'-') ?></td>
                <td><?= htmlspecialchars($u['profit_center_text']??$u['profit_center']??'-') ?></td>
                <td><?= htmlspecialchars($u['subreg']??'-') ?></td>           
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary btn-sm" id="btnConfirmPicker">
          <i class="bi bi-check-circle me-1"></i>Konfirmasi Pilihan
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ MODAL: DETAIL USULAN ══ -->
<div class="modal fade" id="modalDetail" tabindex="-1" aria-modal="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#0b3a8c,#1d6ed8);color:#fff;">
        <div>
          <h5 class="modal-title mb-0"><i class="bi bi-clipboard-data me-2"></i>Detail Usulan</h5>
          <small id="modalSubtitle" style="opacity:.8;font-size:.8rem;"></small>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0" id="modalDetailBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-danger btn-sm" id="btnDetailReject">
          <i class="bi bi-x-circle me-1"></i>Reject
        </button>
        <button type="button" class="btn btn-success btn-sm" id="btnDetailApprove">
          <i class="bi bi-check-circle me-1"></i>Approve
        </button>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ MODAL: APPROVE ══ -->
<div class="modal fade" id="modalApprove" tabindex="-1" aria-modal="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Setujui Usulan (HO)</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="approve_ho">
        <input type="hidden" name="usulan_ids" id="approve_ids">
        <div class="modal-body">
          <div class="alert alert-info mb-3">
            <i class="bi bi-info-circle me-2"></i>
            Persetujuan HO berdasarkan surat fisik. Pastikan surat sudah diterima sebelum klik setujui.
          </div>
          <p>Anda akan <strong class="text-success">menyetujui</strong> usulan untuk:</p>
          <div id="approve_list" class="mb-3"
               style="font-family:monospace;font-size:.85rem;background:#f0fdf4;padding:8px 12px;border-radius:8px;max-height:140px;overflow-y:auto;">
          </div>
          <div>
            <label class="form-label">Catatan (opsional)</label>
            <textarea name="catatan_ho" class="form-control" rows="2" placeholder="Nomor surat / catatan HO..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-success btn-sm">
            <i class="bi bi-check-circle me-1"></i>Ya, Setujui
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ MODAL: REJECT ══ -->
<div class="modal fade" id="modalReject" tabindex="-1" aria-modal="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Tolak Usulan</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="reject_ho">
        <input type="hidden" name="usulan_id" id="reject_id">
        <div class="modal-body">
          <p>Anda akan <strong class="text-danger">menolak</strong> usulan untuk aset:</p>
          <p class="fw-bold text-danger font-monospace mb-3" id="reject_no_aset"></p>
          <div>
            <label class="form-label fw-semibold">Alasan Penolakan <span class="text-danger">*</span></label>
            <textarea name="catatan_ho" class="form-control" rows="3" placeholder="Tuliskan alasan penolakan..." required></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-danger btn-sm">
            <i class="bi bi-x-circle me-1"></i>Ya, Tolak
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ MODAL: KONFIRMASI HAPUS DOKUMEN ══ -->
<div class="modal fade" id="modalConfirmDelete" tabindex="-1" aria-modal="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Hapus Dokumen</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="formDeleteDokumen">
        <input type="hidden" name="action" value="delete_dokumen">
        <input type="hidden" name="dokumen_id" id="deleteDokumenId">
        <input type="hidden" name="tahun" id="deleteTahun">
        <div class="modal-body">
          <p>Anda akan menghapus dokumen:</p>
          <p class="fw-bold" id="deleteDokumenName"></p>
          <p class="text-muted small">Aksi ini tidak dapat dibatalkan.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash me-1"></i>Ya, Hapus</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ MODAL: DETAIL ASET ══ -->
<div class="modal fade" id="modalDetailAset" tabindex="-1" aria-modal="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content" style="border-radius:6px;overflow:hidden;">
      <div class="modal-header" style="background:#fff;border-bottom:2px solid #0d6efd;padding:14px 20px;">
        <div>
          <div class="d-flex align-items-center mb-1">
            <i class="bi bi-table me-2" style="color:#0d6efd;font-size:1.1rem;"></i>
            <h5 class="modal-title mb-0 fw-bold" style="color:#0d6efd;">Detail Data Aset</h5>
          </div>
          <div style="font-size:0.875rem;color:#555;">
            Profit Center: <strong id="detailAsetHoPC">-</strong>
            &nbsp;|&nbsp; Subreg: <strong id="detailAsetHoSubreg">-</strong>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div class="table-responsive">
          <table class="table mb-0" style="border-collapse:collapse;">
            <thead class="table-light">
              <tr>
                <th style="width:50px;">No</th>
                <th style="width:180px;">Nomor Aset</th>
                <th>Nama Aset</th>
                <th style="width:160px;">Mekanisme</th>
                <th style="width:130px;">Status</th>
              </tr>
            </thead>
            <tbody id="modalDetailAsetBody">
              <tr><td colspan="5" class="text-center py-3 text-muted">Memuat data...</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer" style="background:#f8f9fa;border-top:1px solid #dee2e6;padding:10px 20px;justify-content:space-between;">
        <span id="modalDetailAsetFooter" style="font-size:0.875rem;color:#555;"></span>
        <button type="button" class="btn btn-secondary btn-sm px-4" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<!-- Scripts -->
<script src="../../dist/js/jquery-3.6.0.min.js"></script>
<script src="../../dist/js/popper.min.js"></script>
<script src="../../dist/js/bootstrap.min.js"></script>
<script src="../../dist/js/adminlte.js"></script>
<script src="../../dist/js/dataTables.js"></script>
<script src="../../dist/js/dataTables.bootstrap5.min.js"></script>
<script src="../../dist/js/overlayscrollbars.browser.es6.min.js"></script>

<script>
const dataPending   = <?= json_encode($data_pending) ?>;
const dataDokUsulan = <?= json_encode($daftar_dok_usulan) ?>;
const usulanDgkDok  = new Set(<?= json_encode(array_unique(array_map(fn($d) => (int)($d['usulan_id'] ?? 0), $daftar_dok_ho))) ?>);
let   _currentDetailId = null;

$(document).ready(function() {
  const dtOpts = {
    language: {
      search:"Cari:", lengthMenu:"Tampilkan _MENU_ data",
      info:"_START_-_END_ dari _TOTAL_", infoEmpty:"0-0 dari 0",
      emptyTable:"Belum ada data",
      paginate:{first:"«",previous:"‹",next:"›",last:"»"},
      zeroRecords:"Tidak ada data"
    },
    pageLength: 25
  };
  // daftarTable: 10 kolom (0=checkbox,1=No,...,9=Aksi) → non-orderable: checkbox & aksi
  if ($('#daftarTable').length) $('#daftarTable').DataTable({
    ...dtOpts,
    columnDefs:[{orderable:false, targets:[0,9]}]
  });

  // uploadTable: Tidak pakai DataTables karena ada baris preview iframe (colspan) yang
  // tidak kompatibel dengan DataTables. Pakai search manual sederhana.
  // if ($('#uploadTable').length) {
  //   // Inject search box
  //   const searchBox = $('<div class="mb-2 d-flex justify-content-between align-items-center">' +
  //     '<input type="text" id="uploadTableSearch" class="form-control form-control-sm" style="max-width:250px;" placeholder="Cari dokumen...">' +
  //     '</div>');
  //   $('#uploadTable').closest('.card-body, .p-0').prepend(searchBox);

  //   $('#uploadTableSearch').on('keyup', function() {
  //     const q = $(this).val().toLowerCase();
  //     $('#uploadTable tbody tr:not(.dt-preview-row)').each(function() {
  //       const match = $(this).text().toLowerCase().includes(q);
  //       const id = $(this).find('td').first().text().trim();
  //       $(this).toggle(match);
  //       // Sembunyikan baris preview-nya juga kalau baris utama hidden
  //       $('#dph-' + id).hide();
  //     });
  //   });
  // }

  // asetPickerTable: 7 kolom (0=checkbox,...,6=Dok HO) → nonorderable: checkbox
  // Tidak pakai DataTable untuk picker karena kompleksitas state management
  // Gunakan handler sederhana saja
  
  let pickerDT = null;

  function syncPickerCheckboxes() {
    const el = document.getElementById('upload_ids_input');
    if (!el) return; // ← tambahkan ini
    const savedIds = new Set((el.value || '')
      .split(',').map(id => id.trim()).filter(Boolean));
    $('#asetPickerTable .picker-check').each(function() {
      const checkId = $(this).val();
      const isSelected = savedIds.has(checkId);
      const hasDoc = usulanDgkDok.has(parseInt(checkId, 10));
      const shouldDisable = hasDoc;

      if (hasDoc) savedIds.delete(checkId);
      $(this).prop('checked', isSelected || hasDoc);
      $(this).prop('disabled', shouldDisable);
      $(this).attr('data-has-doc', hasDoc ? '1' : '0');
      $(this).closest('tr').toggleClass('table-secondary', hasDoc);
    });
    document.getElementById('upload_ids_input').value = [...savedIds].join(',');
    updatePickerCount();
  }

  // Picker: select all checkbox
  $('#selectAllPicker').on('change', function() {
    const shouldCheck = this.checked;
    const savedIds = new Set((document.getElementById('upload_ids_input').value || '')
      .split(',').map(id => id.trim()).filter(Boolean));

    $('#asetPickerTable .picker-check:not(:disabled)').each(function() {
      $(this).prop('checked', shouldCheck);
      if (shouldCheck) savedIds.add(this.value);
      else savedIds.delete(this.value);
    });

    document.getElementById('upload_ids_input').value = [...savedIds].join(',');
    updatePickerCount();
  });

  // Tiap checkbox diklik: langsung update hidden input dan sync state
  $(document).on('change', '.picker-check:not(:disabled)', function() {
    const savedIds = new Set((document.getElementById('upload_ids_input').value || '')
      .split(',').map(id => id.trim()).filter(Boolean));
    if (this.checked) savedIds.add(this.value);
    else savedIds.delete(this.value);
    document.getElementById('upload_ids_input').value = [...savedIds].join(',');
    syncPickerCheckboxes();
  });

  function restoreUploadSelection() {
    if (!document.getElementById('upload_ids_input')) return; // ← tambahkan ini
    syncPickerCheckboxes();

    const allNomor = [];
    $('#asetPickerTable .picker-check:not(:disabled):checked').each(function() {
      allNomor.push($(this).data('nomor'));
    });
    
    const listEl = document.getElementById('uploadSelectedAssetsList');
    if (allNomor.length) {
      document.getElementById('uploadNomorAset').value = allNomor.length === 1 ? allNomor[0] : allNomor.length + ' aset dipilih';
      listEl.style.display = 'block';
      listEl.innerHTML = '<strong>Dipilih (' + allNomor.length + '):</strong> ' + allNomor.join(', ');
      document.getElementById('btnUpload').disabled = false;
    } else {
      document.getElementById('uploadNomorAset').value = '';
      listEl.style.display = 'none';
      listEl.innerHTML = '';
      document.getElementById('btnUpload').disabled = true;
    }
  }

  $('#modalAsetPicker').on('shown.bs.modal', restoreUploadSelection);
  restoreUploadSelection(); 

  // Picker: confirm
  $('#btnConfirmPicker').on('click', function() {
    const checked = [...document.querySelectorAll('.picker-check:not(:disabled):checked')];
    if (!checked.length) { alert('Pilih minimal 1 aset!'); return; }
    
    const checked_ids = new Set(checked.map(c => c.value));
    const nomor = checked.map(c => c.dataset.nomor);
    
    document.getElementById('upload_ids_input').value = [...checked_ids].join(',');
    document.getElementById('uploadNomorAset').value  = nomor.length === 1 ? nomor[0] : nomor.length + ' aset dipilih';
    
    const listEl = document.getElementById('uploadSelectedAssetsList');
    listEl.style.display = 'block';
    listEl.innerHTML = '<strong>Dipilih (' + checked_ids.size + '):</strong> ' + nomor.join(', ');
    document.getElementById('btnUpload').disabled = false;
    
    bootstrap.Modal.getInstance(document.getElementById('modalAsetPicker'))?.hide();
  });

  // Detail modal: wire approve/reject buttons
  document.getElementById('btnDetailApprove').addEventListener('click', function() {
    if (!_currentDetailId) return;
    const u = dataPending.find(x => x.id == _currentDetailId);
    if (!u) return;
    bootstrap.Modal.getInstance(document.getElementById('modalDetail'))?.hide();
    setTimeout(() => openApproveOne(u.id, u.nomor_asset_utama), 300);
  });
  document.getElementById('btnDetailReject').addEventListener('click', function() {
    if (!_currentDetailId) return;
    const u = dataPending.find(x => x.id == _currentDetailId);
    if (!u) return;
    bootstrap.Modal.getInstance(document.getElementById('modalDetail'))?.hide();
    setTimeout(() => openReject(u.id, u.nomor_asset_utama), 300);
  });

  // ── Detail Aset button — event delegation ──
  $(document).on('click', '.btn-detail-aset', function() {
    const raw      = $(this).attr('data-aset') || '[]';
    const subtitle = $(this).attr('data-subtitle') || '';
    let asetList = [];
    try { asetList = JSON.parse(raw); } catch(e) { asetList = []; }
    openDetailAset(asetList, subtitle);
  });
});

function updateTabUrl(name) {
  const url = new URL(window.location);
  url.searchParams.set('tab', name);
  history.replaceState({}, '', url);
}

// Checkbox pilih semua
document.getElementById('checkAll')?.addEventListener('change', function() {
  document.querySelectorAll('.row-check').forEach(c => c.checked = this.checked);
  updateSelectedBar();
});

document.querySelectorAll('.row-check').forEach(c => c.addEventListener('change', updateSelectedBar));

function updateSelectedBar() {
  const checked = [...document.querySelectorAll('.row-check:checked')];
  document.getElementById('selectedCount').textContent = checked.length + ' aset dipilih';
  document.getElementById('selectedBar').classList.toggle('show', checked.length > 0);
  const all = document.querySelectorAll('.row-check');
  const ca  = document.getElementById('checkAll');
  if (ca) ca.checked = checked.length === all.length && all.length > 0;
}

function openApproveModal() {
  const checked = [...document.querySelectorAll('.row-check:checked')];
  if (!checked.length) return;
  document.getElementById('approve_ids').value = checked.map(c=>c.value).join(',');
  document.getElementById('approve_list').innerHTML = checked.map(c=>`<div>• ${c.dataset.no}</div>`).join('');
  new bootstrap.Modal(document.getElementById('modalApprove')).show();
}

function openApproveOne(id, noAset) {
  document.getElementById('approve_ids').value = id;
  document.getElementById('approve_list').innerHTML = `<div>• ${noAset}</div>`;
  new bootstrap.Modal(document.getElementById('modalApprove')).show();
}

function openReject(id, noAset) {
  document.getElementById('reject_id').value = id;
  document.getElementById('reject_no_aset').textContent = noAset;
  new bootstrap.Modal(document.getElementById('modalReject')).show();
}

function updatePickerCount() {
  const selectable = document.querySelectorAll('.picker-check:not(:disabled)');
  const n = document.querySelectorAll('.picker-check:not(:disabled):checked').length;
  document.getElementById('pickerCountNum').textContent = n;
  document.getElementById('pickerSelectedCount').style.display = n ? 'flex' : 'none';
  document.getElementById('selectAllPicker').checked = n > 0 && n === selectable.length;
}

function openAsetPickerModal() {
  new bootstrap.Modal(document.getElementById('modalAsetPicker')).show();
}

function togglePrev(pid, url) {
  const row   = document.getElementById(pid);
  const frame = document.getElementById(pid + '-frame');
  if (!row) return;
  const isVisible = row.style.display !== 'none' && row.style.display !== '';
  if (isVisible || url === null) {
    row.style.display = 'none';
    if (frame) frame.src = '';
  } else {
    if (frame && url) frame.src = url;
    row.style.display = 'block';
    setTimeout(function() { row.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }, 150);
  }
}

// Preview full-width di bawah tabel upload
let _prevUploadActive = null;
function togglePrevExternal(pid, url) {
  const panel = document.getElementById('previewPanelUpload');
  const frame = document.getElementById('previewPanelFrame');
  const label = document.getElementById('previewPanelLabel');
  const buka  = document.getElementById('previewPanelBuka');
  if (!panel) return;
  // Kalau klik dokumen yang sama → tutup
  if (_prevUploadActive === pid && panel.style.display !== 'none') {
    tutupPreviewUpload(); return;
  }
  _prevUploadActive = pid;
  if (frame) frame.src = url;
  if (label) label.textContent = pid.replace('dph-', 'Dokumen #');
  if (buka)  buka.href = url;
  panel.style.display = 'block';
  setTimeout(function() { panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }, 150);
}
function tutupPreviewUpload() {
  const panel = document.getElementById('previewPanelUpload');
  const frame = document.getElementById('previewPanelFrame');
  if (panel) panel.style.display = 'none';
  if (frame) frame.src = '';
  _prevUploadActive = null;
}

function confirmDeleteDokumen(id, nama, tahun) {
  document.getElementById('deleteDokumenId').value  = id;
  document.getElementById('deleteDokumenName').textContent = nama;
  document.getElementById('deleteTahun').value = tahun || <?= $filterTahun ?>;
  new bootstrap.Modal(document.getElementById('modalConfirmDelete')).show();
}

function openDetail(uid) {
  const u = dataPending.find(x => x.id == uid);
  if (!u) return;
  _currentDetailId = uid;
  const rupiah = n => n ? 'Rp ' + parseInt(n).toLocaleString('id-ID') : '—';
  const kajian = (label, value) => {
    const isEmpty = !value || String(value).trim() === '';
    return `<div class="kajian-item">
      <div class="kajian-label">${label}</div>
      <div class="kajian-box${isEmpty?' empty':''}">${isEmpty?'Tidak diisi':value}</div>
    </div>`;
  };
  const mekanismeBadge = u.mekanisme_penghapusan === 'Hapus Administrasi' ? '<span class="badge-pill" style="background:#ffedd5;color:#c2410c;">Hapus Administrasi</span>' 
  : u.mekanisme_penghapusan === 'Jual Lelang' ? '<span class="badge-pill" style="background:#e0f2fe;color:#0369a1;">Jual Lelang</span>' : (u.mekanisme_penghapusan || '—');
  const fotoPath = u.foto_path || u.foto_aset || '';
  const fotoHtml = fotoPath
    ? `<div class="text-center py-3" style="background:#f8f9fa;border-bottom:1px solid #f0f0f0;">
         <img src="${fotoPath}" class="foto-aset-img img-fluid"
              onclick="bukaLightbox('${fotoPath}')"
              title="Klik untuk perbesar">
         <div class="mt-1" style="font-size:0.72rem;color:#9ca3af;">Klik foto untuk memperbesar</div>
       </div>`
    : '';
  const noAsetUsulan = String(u.nomor_asset_utama || '');
  const dok = dataDokUsulan.filter(d => {
    if (String(d.usulan_id) === String(u.id)) return true;
    // dokumen yang no_aset-nya mengandung nomor aset ini (1 dokumen multi-aset)
    if (d.no_aset && noAsetUsulan && d.no_aset.split(';').map(s => s.trim()).includes(noAsetUsulan)) return true;
    return false;
  });  const dokHtml = dok.length === 0
    ? '<p class="text-muted small mb-0">Belum ada dokumen pendukung.</p>'
    : dok.map((d,i) => {
        const url = `persetujuan_penghapusan.php?action=view_dok_usulan&id_dok=${d.id_dokumen}`;
        const pid = `dd-${d.id_dokumen}`;
        return `<div style="padding:10px 0;${i>0?'border-top:1px solid #f3f4f6':''}">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;">
            <div style="flex:1;">
              <div style="display:flex;align-items:center;flex-wrap:wrap;gap:4px;">
                <span style="font-weight:600;font-size:0.88rem;">${d.tipe_dokumen||'Dokumen'}</span>
                <span style="color:#9ca3af;font-size:0.76rem;">Tahun ${d.tahun_usulan||'—'}</span>
              </div>
            </div>
            <div style="display:flex;gap:6px;flex-shrink:0;margin-left:12px;">
              <button onclick="togglePrev('${pid}','${url}')"
                      class="btn btn-sm btn-outline-info"
                      style="font-size:0.76rem;padding:2px 9px;"
                      title="Preview Dokumen">
                <i class="bi bi-file-text me-1"></i>
              </button>
              <a href="${url}" target="_blank"
                 class="btn btn-sm btn-outline-primary"
                 style="font-size:0.76rem;padding:2px 9px;"
                 title="Buka di tab baru">
                <i class="bi bi-box-arrow-up-right me-1"></i>Buka
              </a>
            </div>
          </div>
          <div id="${pid}" style="display:none;margin-top:8px;">
            <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#f8fafc;">
              <div style="padding:6px 12px;background:#f1f5f9;border-bottom:1px solid #e2e8f0;font-size:0.75rem;color:#64748b;display:flex;align-items:center;justify-content:space-between;">
                <span><i class="bi bi-file-earmark-pdf text-danger me-1"></i>Preview Dokumen</span>
                <button onclick="togglePrev('${pid}',null)"
                        class="btn btn-sm" style="padding:0 4px;font-size:0.7rem;color:#94a3b8;line-height:1;">
                  <i class="bi bi-x-lg"></i> Tutup
                </button>
              </div>
              <iframe id="${pid}-frame" src=""
                      style="width:100%;height:560px;border:none;display:block;"
                      title="Preview Dokumen PDF">
              </iframe>
            </div>
          </div>
        </div>`;
      }).join('');

  document.getElementById('modalSubtitle').textContent = u.nama_aset || u.nomor_asset_utama;
  document.getElementById('modalDetailBody').innerHTML = `
    ${fotoHtml}
    <div class="detail-section">
      <div class="detail-section-title"><i class="bi bi-tag"></i> Identitas Aset</div>
      <div class="detail-grid">
        <div class="detail-item"><div class="detail-item-label">Nomor Aset</div><div class="detail-item-value" style="font-family:monospace;color:#2563eb;">${u.nomor_asset_utama}</div></div>
        <div class="detail-item"><div class="detail-item-label">Nama Aset</div><div class="detail-item-value">${u.nama_aset||'—'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Kategori Aset</div><div class="detail-item-value">${u.kategori_aset||'—'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Profit Center</div><div class="detail-item-value">${u.profit_center_text||u.profit_center||'—'}</div></div>
        <div class="detail-item"><div class="detail-item-label">SubReg</div><div class="detail-item-value">${u.subreg||'—'}</div></div>
      </div>
    </div>
    <div class="detail-section">
      <div class="detail-section-title"><i class="bi bi-clipboard-data"></i> Detail Usulan</div>
      <div class="detail-grid">
        <div class="detail-item"><div class="detail-item-label">Nilai Buku</div><div class="detail-item-value" style="font-family:monospace;">${rupiah(u.nilai_buku)}</div></div>
        <div class="detail-item"><div class="detail-item-label">Nilai Perolehan</div><div class="detail-item-value" style="font-family:monospace;">${rupiah(u.nilai_perolehan_sd)}</div></div>
        <div class="detail-item"><div class="detail-item-label">Tanggal Perolehan</div><div class="detail-item-value">${u.tgl_perolehan||'—'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Tahun Usulan</div><div class="detail-item-value">${u.tahun_usulan||'—'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Umur Ekonomis</div><div class="detail-item-value">${u.umur_ekonomis||'—'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Sisa Umur Ekonomis</div><div class="detail-item-value">${u.sisa_umur_ekonomis||'—'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Jumlah Aset</div><div class="detail-item-value">${u.jumlah_aset||'—'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Mekanisme Penghapusan</div><div class="detail-item-value">${mekanismeBadge}</div></div>
        <div class="detail-item"><div class="detail-item-label">Fisik Aset</div><div class="detail-item-value">${u.fisik_aset ? `<span class="badge-pill" style="background:${u.fisik_aset==='Ada'?'#d1fae5':'#fee2e2'};color:${u.fisik_aset==='Ada'?'#065f46':'#991b1b'};">${u.fisik_aset}</span>` : '—'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Jumlah Dokumen</div><div class="detail-item-value"><span class="badge-pill" style="background:#0ea5e9;color:#fff;">${u.jml_dok_usulan||0} file(s)</span></div></div>
      </div>
    </div>
    <div class="detail-section">
      <div class="detail-section-title"><i class="bi bi-journal-text"></i> Kajian & Justifikasi</div>
      <div style="padding:4px 0;">
        ${kajian('Justifikasi & Alasan Penghapusan', u.justifikasi_alasan)}
        ${kajian('Kajian Hukum', u.kajian_hukum)}
        ${kajian('Kajian Ekonomis', u.kajian_ekonomis)}
        ${kajian('Kajian Risiko', u.kajian_risiko)}
      </div>
    </div>
    <div class="detail-section">
      <div class="detail-section-title"><i class="bi bi-file-earmark-pdf"></i> Dokumen Usulan</div>
      <div style="padding:0 16px;">${dokHtml}</div>
    </div>`;
  new bootstrap.Modal(document.getElementById('modalDetail')).show();
}
</script>

<!-- Lightbox Foto Aset -->
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
  document.getElementById('lightboxOverlay').style.display = 'none';
  document.getElementById('lightboxImg').src = '';
  document.body.style.overflow = '';
}
function openDetailAset(asetList, subtitle) {
  const tbody   = document.getElementById('modalDetailAsetBody');
  const pcEl    = document.getElementById('detailAsetHoPC');
  const subEl   = document.getElementById('detailAsetHoSubreg');
  const totalEl = document.getElementById('modalDetailAsetFooter');

  const pcMatch  = (subtitle || '').match(/PC:\s*([^|]+)/);
  const subMatch = (subtitle || '').match(/Subreg:\s*(.+)/);
  if (pcEl)  pcEl.textContent  = pcMatch  ? pcMatch[1].trim()  : '-';
  if (subEl) subEl.textContent = subMatch ? subMatch[1].trim() : '-';

  if (!asetList || asetList.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-muted">Data aset tidak ditemukan.</td></tr>';
    if (totalEl) totalEl.textContent = '';
  } else {
    tbody.innerHTML = '';
    asetList.forEach(function(a, i) {
      const mekVal = a.mekanisme_penghapusan || '';
      let mekBadge = '-';
      if (mekVal) {
        const mekStyle = mekVal === 'Hapus Administrasi' ? 'background:#ffedd5;color:#c2410c;'
          : mekVal === 'Jual Lelang' ? 'background:#e0f2fe;color:#0369a1;' : 'background:#f3f4f6;color:#6b7280;';
        mekBadge = '<span class="badge" style="' + mekStyle + '">' + mekVal + '</span>';
      }
      const stVal = a.status_approval_ho || '';
      let stBadge = '-';
      if (stVal === 'approved')      stBadge = '<span class="badge" style="background:#28a745;color:#fff;">Approved</span>';
      else if (stVal === 'rejected') stBadge = '<span class="badge" style="background:#dc3545;color:#fff;">Rejected</span>';
      else if (stVal)                stBadge = '<span class="badge" style="background:#ffc107;color:#333;">Pending</span>';
      const namaLink = '<a href="#" style="color:#0d6efd;text-decoration:none;">' + (a.nama_aset || '-') + '</a>';
      tbody.innerHTML += '<tr style="background:' + (i%2===0 ? '#f8f9fa' : '#fff') + '">'
        + '<td class="text-center">' + (i+1) + '</td>'
        + '<td><code style="color:#2563eb;font-size:.82rem;">' + (a.nomor_asset_utama || '-') + '</code></td>'
        + '<td>' + namaLink + '</td>'
        + '<td class="text-center">' + mekBadge + '</td>'
        + '<td class="text-center">' + stBadge + '</td>'
        + '</tr>';
    });
    if (totalEl) totalEl.textContent = 'Total: ' + asetList.length + ' aset dimuat';
  }
  new bootstrap.Modal(document.getElementById('modalDetailAset')).show();
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') tutupLightbox();
});
</script>
</body>
</html>