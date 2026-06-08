<?php
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "asetreg3_db";

$con = mysqli_connect($servername, $username, $password, $dbname);

// ── Handle file serve SEBELUM session_start agar header tidak terlambat ──
if (isset($_GET['action']) && in_array($_GET['action'], ['view_dok_ho', 'view_dok_usulan'])) {
    while (ob_get_level()) ob_end_clean();
    
    if ($_GET['action'] === 'view_dok_ho' && isset($_GET['id_dok'])) {
        $id_dok = (int)$_GET['id_dok'];
        $res = mysqli_query($con, "SELECT lokasi_file, file_name FROM dokumen_pelaksanaan WHERE id_dokumen = $id_dok LIMIT 1");
        if (!$res || mysqli_num_rows($res) === 0) { http_response_code(404); echo 'Dokumen tidak ditemukan.'; exit(); }
        $row = mysqli_fetch_assoc($res);
        serveFileFromDb($row['lokasi_file'], $row['file_name'], isset($_GET['download']));
    }

    if ($_GET['action'] === 'view_dok_usulan' && isset($_GET['id_dok'])) {
        $id_dok = (int)$_GET['id_dok'];
        $res = mysqli_query($con, "SELECT file_path, file_name FROM dokumen_penghapusan WHERE id_dokumen = $id_dok LIMIT 1");
        if (!$res || mysqli_num_rows($res) === 0) { http_response_code(404); echo 'Dokumen tidak ditemukan.'; exit(); }
        $row = mysqli_fetch_assoc($res);
        serveFileFromDb($row['file_path'], $row['file_name'], isset($_GET['download']));
    }
    exit();
}

session_start();

if (!isset($_SESSION["nipp"]) || !isset($_SESSION["name"])) {
    header("Location: ../login/login_view.php");
    exit();
}

$userType = isset($_SESSION['Type_User']) ? $_SESSION['Type_User'] : '';
$userNipp = $_SESSION['nipp'];

// Semua role bisa edit
$canEdit = true;

// ── Auto-migrate data lama: "Telah Dimusnahkan" / "Telah dimusnahkan" → "Hapus Administrasi" ──
mysqli_query($con, "UPDATE pelaksanaan_penghapusan SET status_pelaksanaan = 'Hapus Administrasi' WHERE LOWER(status_pelaksanaan) IN ('telah dimusnahkan')");

function serveFileFromDb($filePathDb, $fileName, $forceDownload = false) {
    $fileName = !empty($fileName) ? basename($fileName) : 'dokumen.pdf';

    // Format: data URI dengan gzip
    if (strpos($filePathDb, 'data:') === 0 && strpos($filePathDb, ';gzip,') !== false) {
        $fileData = gzdecode(base64_decode(substr($filePathDb, strrpos($filePathDb, ',') + 1)));
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . $fileName . '"');
        header('Content-Length: ' . strlen($fileData));
        header('Cache-Control: no-cache');
        echo $fileData; exit();
    }

    // Format: data URI dengan base64
    if (strpos($filePathDb, 'data:') === 0 && strpos($filePathDb, ';base64,') !== false) {
        $fileData = base64_decode(substr($filePathDb, strpos($filePathDb, ',') + 1));
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . $fileName . '"');
        header('Content-Length: ' . strlen($fileData));
        header('Cache-Control: no-cache');
        echo $fileData; exit();
    }

    // Format: raw binary blob (longblob dari DB)
    if (!empty($filePathDb) && (
        substr($filePathDb, 0, 4) === '%PDF' ||
        substr($filePathDb, 0, 4) === "\x25\x50\x44\x46" ||
        strpos($filePathDb, "\x00") !== false
    )) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . $fileName . '"');
        header('Content-Length: ' . strlen($filePathDb));
        header('Cache-Control: no-cache');
        echo $filePathDb; exit();
    }

    // Format: path file (Windows absolute, Unix absolute, atau relative)
    $normalized = str_replace('\\', '/', trim($filePathDb));
    $docRoot    = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\'));

    $candidates = [];

    // 1. Path Windows absolut langsung (C:/xampp/...)
    if (preg_match('#^[A-Za-z]:/#', $normalized)) {
        $candidates[] = str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    }

    // 2. Ekstrak bagian setelah htdocs, gabung dengan DOCUMENT_ROOT sekarang
    // Contoh: C:/xampp/htdocs/dashboard/... → {DOCUMENT_ROOT}/dashboard/...
    if (preg_match('#/htdocs/(.+)$#i', $normalized, $m)) {
        $candidates[] = str_replace('/', DIRECTORY_SEPARATOR, $docRoot . '/' . $m[1]);
    }

    // 3. Path Unix absolut
    if (strpos($normalized, '/') === 0) {
        $candidates[] = $normalized;
    }

    // 4. Path relative ke DOCUMENT_ROOT
    if (!empty($docRoot)) {
        $stripped = ltrim(preg_replace('#^' . preg_quote($docRoot, '#') . '#', '', $normalized), '/');
        $candidates[] = str_replace('/', DIRECTORY_SEPARATOR, $docRoot . '/' . $stripped);
    }

    // 5. Path relative ke __DIR__
    $candidates[] = __DIR__ . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $normalized), DIRECTORY_SEPARATOR);

    // 6. Path apa adanya
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

    http_response_code(404); echo 'File tidak ditemukan di: ' . htmlspecialchars($filePathDb); exit();
}

// ── Helper: normalize path foto ──────────────────────────────────────────────
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
    while (ob_get_level()) ob_end_clean();
    
    $id_dok = (int)$_GET['id_dok'];
    $res = mysqli_query($con, "SELECT file_path, file_name FROM dokumen_penghapusan WHERE id_dokumen = $id_dok LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) { http_response_code(404); echo 'Dokumen tidak ditemukan.'; exit(); }
    $row = mysqli_fetch_assoc($res);
    serveFileFromDb($row['file_path'], $row['file_name'], isset($_GET['download']));
}

if (isset($_GET['action']) && $_GET['action'] === 'view_dok_ho' && isset($_GET['id_dok'])) {
    // Bersihkan semua output buffer sebelum kirim file
    while (ob_get_level()) ob_end_clean();
    
    $id_dok = (int)$_GET['id_dok'];
    $res = mysqli_query($con, "SELECT lokasi_file, file_name FROM dokumen_pelaksanaan WHERE id_dokumen = $id_dok LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) { http_response_code(404); echo 'Dokumen tidak ditemukan.'; exit(); }
    $row = mysqli_fetch_assoc($res);
    serveFileFromDb($row['lokasi_file'], $row['file_name'], isset($_GET['download']));
}

// ── AJAX: Detail Aset ────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_detail_aset_pel' && isset($_GET['no_aset'])) {
    header('Content-Type: application/json');
    $no_aset = trim($_GET['no_aset']);
    $aset_list = array_filter(array_map('trim', explode(';', $no_aset)));
    $rows_da = [];
    foreach ($aset_list as $single_aset) {
        $stmt_da = $con->prepare(
            "SELECT id.nomor_asset_utama, id.keterangan_asset, id.profit_center,
                id.subreg, id.profit_center_text,
                up.mekanisme_penghapusan, up.status AS status_penghapusan
             FROM import_dat id
             LEFT JOIN usulan_penghapusan up ON id.nomor_asset_utama = up.nomor_asset_utama
             WHERE id.nomor_asset_utama = ? LIMIT 10"
        );
        $stmt_da->bind_param('s', $single_aset);
        $stmt_da->execute();
        $res_da = $stmt_da->get_result();
        $status_map = ['draft'=>'Draft','lengkapi_dokumen'=>'Lengkapi Data','dokumen_lengkap'=>'Siap Upload',
            'submitted'=>'Submitted','approved_subreg'=>'Approved SubReg','approved'=>'Approved','rejected'=>'Rejected'];
        while ($r = $res_da->fetch_assoc()) {
            $r['status_penghapusan'] = isset($r['status_penghapusan']) && $r['status_penghapusan']
                ? ($status_map[$r['status_penghapusan']] ?? ucfirst($r['status_penghapusan'])) : '';
            $rows_da[] = $r;
        }
        $stmt_da->close();
    }
    echo json_encode(['status' => 'success', 'data' => $rows_da]);
    exit();
}

// ── Upload dokumen → simpan sebagai BLOB ke dokumen_penghapusan ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_dok_ho' && $canEdit) {
    $id_pel      = (int)($_POST['id_pelaksanaan'] ?? 0);
    $deskripsi   = trim($_POST['deskripsi_dokumen'] ?? 'Dokumen Pendukung');
    $nomor_aset  = trim($_POST['nomor_aset'] ?? '');
    $tahun_dok   = (int)date('Y');
    $kategori    = 'pendukung';
    $nipp_upload = $userNipp;

    if ($id_pel > 0 && isset($_FILES['file_dokumen']) && $_FILES['file_dokumen']['error'] === UPLOAD_ERR_OK) {
        $fileData = file_get_contents($_FILES['file_dokumen']['tmp_name']);
        $fileName = basename($_FILES['file_dokumen']['name']);
        $fileSize = strlen($fileData);

        // Simpan file sebagai BLOB ke tabel dokumen_pelaksanaan
        $stmt = $con->prepare("INSERT INTO dokumen_pelaksanaan
            (id_pelaksanaan, deskripsi_dokumen, nomor_aset, lokasi_file,
             file_name, file_size, nipp, tahun_dokumen, kategori)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $null = null;
        $stmt->bind_param("issbsisis",
            $id_pel, $deskripsi, $nomor_aset, $null,
            $fileName, $fileSize, $nipp_upload, $tahun_dok, $kategori);
        $stmt->send_long_data(3, $fileData);
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Dokumen berhasil diupload.";
        } else {
            $_SESSION['warning_message'] = "Gagal upload: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $_SESSION['warning_message'] = "File tidak valid atau tidak ada.";
    }
    $tahunRedirect = isset($_POST['tahun_filter']) ? (int)$_POST['tahun_filter'] : date('Y');
    header("Location: " . $_SERVER['PHP_SELF'] . "?tahun=$tahunRedirect&tab=upload"); exit();
}

// ── Migrate dokumen HO lama (path lokal → BLOB) dipanggil sekali via ?action=migrate_dok ──
if (isset($_GET['action']) && $_GET['action'] === 'migrate_dok_ho' && $canEdit) {
    $res_m = mysqli_query($con, "SELECT id_dokumen, lokasi_file, file_name FROM dokumen_pelaksanaan WHERE lokasi_file NOT LIKE 'data:%' AND lokasi_file != '' AND lokasi_file IS NOT NULL");
    $berhasil = 0; $gagal = 0;
    while ($row_m = mysqli_fetch_assoc($res_m)) {
        $path = str_replace('\\', '/', $row_m['lokasi_file']);
        // Coba Windows path langsung
        $candidates = [
            str_replace('/', DIRECTORY_SEPARATOR, $path),
        ];
        // Ekstrak setelah htdocs
        if (preg_match('#/htdocs/(.+)$#i', $path, $m)) {
            $docRoot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\'));
            $candidates[] = str_replace('/', DIRECTORY_SEPARATOR, $docRoot . '/' . $m[1]);
        }
        $fileData = null;
        foreach ($candidates as $c) {
            if (!empty($c) && @file_exists($c) && @is_file($c)) {
                $fileData = file_get_contents($c);
                break;
            }
        }
        if ($fileData !== null && strlen($fileData) > 0) {
            $id_dok_m = (int)$row_m['id_dokumen'];
            $stmt_m = $con->prepare("UPDATE dokumen_pelaksanaan SET lokasi_file = ? WHERE id_dokumen = ?");
            $null_m = null;
            $stmt_m->bind_param("bi", $null_m, $id_dok_m);
            $stmt_m->send_long_data(0, $fileData);
            $stmt_m->execute() ? $berhasil++ : $gagal++;
            $stmt_m->close();
        } else {
            $gagal++;
        }
    }
    $_SESSION['success_message'] = "Migrasi selesai: $berhasil berhasil, $gagal gagal.";
    header("Location: " . $_SERVER['PHP_SELF']); exit();
}

// ── Hapus dokumen pendukung ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hapus_dok_pendukung' && $canEdit) {
    $id_dok = (int)($_POST['id_dokumen'] ?? 0);
    $tahunR = (int)($_POST['tahun_filter'] ?? date('Y'));
    if ($id_dok > 0) {
        $stmt = $con->prepare("DELETE FROM dokumen_pelaksanaan WHERE id_dokumen = ? AND COALESCE(kategori,'ho') = 'pendukung'");
        $stmt->bind_param("i", $id_dok);
        $stmt->execute();
        $_SESSION['success_message'] = $stmt->affected_rows > 0 ? '✅ Dokumen berhasil dihapus.' : '⚠️ Dokumen tidak ditemukan.';
        $stmt->close();
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?tahun=$tahunR&tab=upload"); exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_pelaksanaan' && $canEdit) {
    function cleanAngka($val) {
        if (!isset($val) || $val === '' || $val === null) return null;
        $val = trim($val);
        if (strpos($val, ',') !== false) {
            $clean = str_replace('.', '', $val);
            $clean = str_replace(',', '.', $clean);
        } else {
            $clean = $val;
        }
        return is_numeric($clean) ? (float)$clean : null;
    }

    $id_pel              = (int)$_POST['id_pelaksanaan'];
    $status_pelaksanaan  = trim($_POST['status_pelaksanaan'] ?? '');
    $tgl_appraisal       = (!empty($_POST['tanggal_appraisal'])  && $_POST['tanggal_appraisal']  !== '0000-00-00') ? $_POST['tanggal_appraisal']  : null;
    $tgl_penjualan       = (!empty($_POST['tanggal_penjualan'])  && $_POST['tanggal_penjualan']  !== '0000-00-00') ? $_POST['tanggal_penjualan']  : null;
    $nilai_buku_bb       = cleanAngka($_POST['nilai_buku_bulan_berjalan'] ?? '');
    $nilai_app_pasar     = cleanAngka($_POST['nilai_appraisal_pasar']     ?? '');
    $nilai_app_likuidasi = cleanAngka($_POST['nilai_appraisal_likuidasi'] ?? '');
    $nilai_penjualan     = cleanAngka($_POST['nilai_penjualan']           ?? '');
    $biaya_lainnya       = cleanAngka($_POST['biaya_lainnya']             ?? '');
    $nomor_aset_pengganti = trim($_POST['nomor_aset_pengganti'] ?? '');

    $stmt = $con->prepare("UPDATE pelaksanaan_penghapusan SET
        status_pelaksanaan = ?, tanggal_appraisal = ?, tanggal_penjualan = ?,
        nilai_buku_bulan_berjalan = ?, nilai_appraisal_pasar = ?, nilai_appraisal_likuidasi = ?,
        nilai_penjualan = ?, biaya_lainnya = ?, nomor_aset_pengganti = ?,
        nipp = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("sssdddddssi", $status_pelaksanaan, $tgl_appraisal, $tgl_penjualan,
        $nilai_buku_bb, $nilai_app_pasar, $nilai_app_likuidasi, $nilai_penjualan,
        $biaya_lainnya, $nomor_aset_pengganti, $userNipp, $id_pel);

    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Data pelaksanaan berhasil diperbarui.";
    } else {
        $_SESSION['warning_message'] = "Gagal memperbarui data: " . $stmt->error;
    }
    $stmt->close();
    $tahunRedirect = isset($_POST['tahun_filter']) && !empty($_POST['tahun_filter']) ? (int)$_POST['tahun_filter'] : date('Y');
    header("Location: " . $_SERVER['PHP_SELF'] . "?tahun=$tahunRedirect&tab=daftar"); exit();
}

$filterTahun  = isset($_GET['tahun'])  && !empty($_GET['tahun'])  ? (int)$_GET['tahun']  : date('Y');
$filterStatus = isset($_GET['status']) ? $_GET['status'] : 'all';
$activeTab    = isset($_GET['tab']) ? $_GET['tab'] : 'daftar';

$whereStatus = '';
if ($filterStatus !== 'all') {
    $fs = mysqli_real_escape_string($con, $filterStatus);
    $whereStatus = " AND pp.status_pelaksanaan = '$fs'";
}

$res_main = mysqli_query($con, "SELECT pp.*,
    up.nomor_asset_utama, up.mekanisme_penghapusan, up.fisik_aset, up.foto_path,
    up.justifikasi_alasan, up.kajian_hukum, up.kajian_ekonomis, up.kajian_risiko,
    up.status_approval_ho, up.catatan_ho, up.tanggal_approval_ho,
    up.tahun_usulan, up.nilai_buku, up.jumlah_aset,
    up.nama_aset, up.kategori_aset,
    up.profit_center_text, up.subreg,
    up.nilai_perolehan as nilai_perolehan_sd,
    up.nilai_buku as nilai_buku_awal,
    up.tgl_perolehan, up.umur_ekonomis, up.sisa_umur_ekonomis,
    (SELECT COUNT(*) FROM dokumen_pelaksanaan dp WHERE dp.id_pelaksanaan = pp.id) as jml_dok_ho,
    (SELECT COUNT(*) FROM dokumen_penghapusan dp2 WHERE dp2.usulan_id = up.id) as jml_dok_usulan,
    (SELECT COUNT(*) FROM dokumen_pelaksanaan dp3 WHERE dp3.id_pelaksanaan = pp.id AND COALESCE(dp3.kategori,'ho') = 'pendukung') as has_pendukung
    FROM pelaksanaan_penghapusan pp
    JOIN usulan_penghapusan up ON pp.usulan_id = up.id
    WHERE up.tahun_usulan = $filterTahun $whereStatus
    ORDER BY pp.created_at DESC");

$data_pelaksanaan = [];
while ($r = mysqli_fetch_assoc($res_main)) {
    $r['nama_aset'] = str_replace('AUC-', '', $r['nama_aset'] ?? '');
    $r['foto_path'] = normalize_foto_path($r['foto_path'] ?? '');
    $data_pelaksanaan[] = $r;
}

$daftar_dok_ho = [];
$res_dho = mysqli_query($con, "SELECT dp.id_dokumen, dp.id_pelaksanaan, dp.deskripsi_dokumen, dp.file_name, dp.tahun_dokumen, pp.usulan_id, COALESCE(dp.kategori,'ho') as kategori FROM dokumen_pelaksanaan dp JOIN pelaksanaan_penghapusan pp ON dp.id_pelaksanaan = pp.id ORDER BY dp.id_dokumen DESC");
while ($r = mysqli_fetch_assoc($res_dho)) $daftar_dok_ho[] = $r;

// Dokumen pendukung (upload dari tab halaman) — hanya kategori='pendukung'
$daftar_dok_pendukung = [];
$res_dpend = mysqli_query($con, "SELECT
    dp.id_dokumen, dp.id_pelaksanaan, dp.deskripsi_dokumen, dp.file_name,
    dp.tahun_dokumen, COALESCE(dp.nomor_aset, up.nomor_asset_utama) as nomor_aset,
    pp.profit_center, pp.subreg,
    up.profit_center_text as cabang
    FROM dokumen_pelaksanaan dp
    JOIN pelaksanaan_penghapusan pp ON dp.id_pelaksanaan = pp.id
    JOIN usulan_penghapusan up ON pp.usulan_id = up.id
    WHERE COALESCE(dp.kategori,'ho') = 'pendukung'
    ORDER BY dp.id_dokumen DESC");
while ($r = mysqli_fetch_assoc($res_dpend)) $daftar_dok_pendukung[] = $r;

$daftar_dok_usulan = [];
$res_du = mysqli_query($con, "SELECT dp.id_dokumen, dp.usulan_id, dp.tipe_dokumen, dp.file_name,
    YEAR(up.created_at) as tahun_usulan
    FROM dokumen_penghapusan dp
    JOIN usulan_penghapusan up ON dp.usulan_id = up.id
    JOIN pelaksanaan_penghapusan pp ON pp.usulan_id = up.id
    ORDER BY dp.id_dokumen DESC");
while ($r = mysqli_fetch_assoc($res_du)) $daftar_dok_usulan[] = $r;

// ── Query "Total Usulan" dari tabel usulan yg sudah di approved regional──
$r_total = mysqli_fetch_assoc(mysqli_query($con,
    "SELECT COUNT(*) as c FROM usulan_penghapusan
     WHERE status_approval_regional = 'approved' AND tahun_usulan = $filterTahun"));
$cnt_total_usulan = (int)($r_total['c'] ?? 0);

// ── Query "Disetujui HO" dari tabel usulan ──
$r_ho = mysqli_fetch_assoc(mysqli_query($con,
    "SELECT COUNT(*) as c FROM usulan_penghapusan
     WHERE status_approval_ho = 'approved' AND tahun_usulan = $filterTahun"));
$cnt_disetujui_ho = (int)($r_ho['c'] ?? 0);

// ── Counter progress status dari tabel pelaksanaan ──
$cnt_appraisal = $cnt_lelang = $cnt_terjual = $cnt_musnahkan = 0;
$cnt_jual_lelang_total = $cnt_hapus_admin_total = 0;
foreach ($data_pelaksanaan as $d) {
    $st  = strtolower($d['status_pelaksanaan'] ?? '');
    $mek = $d['mekanisme_penghapusan'] ?? '';
    if ($st === 'appraisal aset')                                          $cnt_appraisal++;
    elseif ($st === 'proses lelang')                                       $cnt_lelang++;
    elseif ($st === 'terjual')                                             $cnt_terjual++;
    elseif ($st === 'hapus administrasi' || $st === 'telah dimusnahkan')   $cnt_musnahkan++;
    if ($mek === 'Jual Lelang')        $cnt_jual_lelang_total++;
    if ($mek === 'Hapus Administrasi') $cnt_hapus_admin_total++;
}
$cnt_total_pelaksanaan = count($data_pelaksanaan);
$cnt_belum_disetujui_ho = $cnt_total_usulan - $cnt_disetujui_ho;
$cnt_jual_progress = $cnt_appraisal + $cnt_lelang + $cnt_terjual;

$list_tahun = [];
// Ambil semua tahun_usulan yang sudah approved HO — bukan hanya yang sudah ada di pelaksanaan
$res_thn = mysqli_query($con, "SELECT DISTINCT up.tahun_usulan as t
    FROM usulan_penghapusan up
    WHERE up.status_approval_ho = 'approved'
      AND up.tahun_usulan IS NOT NULL AND up.tahun_usulan > 0
    ORDER BY t DESC");
while ($r = mysqli_fetch_assoc($res_thn)) if ($r['t']) $list_tahun[] = (int)$r['t'];
if (!in_array((int)date('Y'), $list_tahun)) array_unshift($list_tahun, (int)date('Y'));

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
    #tblJualLelang thead th,#tblJualLelang tbody td,
    #tblHapusAdmin thead th,#tblHapusAdmin tbody td{padding:9px 13px;white-space:nowrap;vertical-align:middle;}
    #tblJualLelang tbody tr:hover{background:#f0f2f5!important;}
    #tblHapusAdmin tbody tr:hover{background:#e8f0fe!important;}
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
    /* Status Pelaksanaan badges — warna lebih terang/kontras, tidak overlap dengan Mekanisme */
    .st-disetujui{background:#dbeafe;color:#1d4ed8;}        /* Biru  — Disetujui */
    .st-appraisal{background:#fef9c3;color:#854d0e;}        /* Kuning — Appraisal */
    .st-lelang   {background:#ede9fe;color:#5b21b6;}        /* Ungu  — Proses Lelang */
    .st-terjual  {background:#bbf7d0;color:#166534;}        /* Hijau — Terjual */
    .st-ditolak  {background:#fee2e2;color:#991b1b;}        /* Merah muda — Ditolak */
    .st-musnahkan{background:#dc2626;color:#fff;}           /* Merah solid — Hapus Administrasi */
    /* Tab styles in modal edit */
    .edit-tab-nav{display:flex;border-bottom:2px solid #e9ecef;background:#f8f9fa;padding:0 22px;}
    .edit-tab-btn{padding:10px 16px;border:none;background:none;font-size:.82rem;font-weight:600;color:#6b7280;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .18s;}
    .edit-tab-btn.active{color:#0b3a8c;border-bottom-color:#0b3a8c;background:none;}
    .edit-tab-btn:hover:not(.active){color:#374151;background:#f0f0f0;}
    .edit-tab-pane{display:none;}
    .edit-tab-pane.active{display:block;}
    .upload-zone{border:2px dashed #d1d5db;border-radius:10px;padding:22px 16px;text-align:center;background:#f9fafb;transition:border-color .2s;}
    .upload-zone:hover{border-color:#3b82f6;background:#eff6ff;}
    .dok-list-item{display:flex;align-items:center;justify-content:space-between;padding:9px 12px;border:1px solid #e9ecef;border-radius:8px;margin-bottom:7px;background:#fff;}
    .dok-list-item:hover{background:#f8f9fa;}
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
    .foto-aset-img{max-height:220px;object-fit:contain;cursor:pointer;border-radius:8px;border:1px solid #e9ecef;transition:box-shadow .2s;}
    .foto-aset-img:hover{box-shadow:0 4px 16px rgba(0,0,0,.12);}
    /* Kajian */
    .kajian-item{margin-bottom:14px;}
    .kajian-item:last-child{margin-bottom:0;}
    .kajian-label{font-size:.72rem;font-weight:600;color:#6b7280;margin-bottom:5px;}
    .kajian-box{background:#f8f9fa;border-left:3px solid #0d6efd;border-radius:0 6px 6px 0;padding:9px 13px;font-size:.875rem;color:#374151;white-space:pre-wrap;word-break:break-word;line-height:1.6;}
    .kajian-box.empty{border-left-color:#e5e7eb;color:#9ca3af;font-style:italic;}
    /* Nav tabs page-level */
    .page-nav-tabs .nav-link{font-weight:500;color:#6b7280;}
    .page-nav-tabs .nav-link.active{color:#0b3a8c;border-bottom:2px solid #0b3a8c;font-weight:600;}
    .upload-zone-page{border:2px dashed #d1d5db;border-radius:10px;padding:22px 16px;text-align:center;background:#f9fafb;transition:border-color .2s;cursor:pointer;}
    .upload-zone-page:hover{border-color:#3b82f6;background:#eff6ff;}
    .selected-aset-bar{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 16px;margin-bottom:14px;display:none;align-items:center;gap:10px;flex-wrap:wrap;}
    .selected-aset-bar.show{display:flex;}
    .table-responsive::-webkit-scrollbar{height:8px;}
    .table-responsive::-webkit-scrollbar-track{background:#f1f1f1;}
    .table-responsive::-webkit-scrollbar-thumb{background:#888;border-radius:4px;}
    .app-footer .footer-inner{display:flex;align-items:center;justify-content:space-between;gap:0.75rem;padding:0 1.5rem;max-width:100%;flex-wrap:wrap;}
    .app-footer .footer-inner .footer-text{font-size:.95rem;color:#374151;}
    .app-footer .footer-inner .footer-right{margin-left:auto;}
    /* Fix DataTables sort icons - prevent numeric/broken chars */
    table.dataTable thead th.sorting,
    table.dataTable thead th.sorting_asc,
    table.dataTable thead th.sorting_desc,
    table.dataTable thead th.sorting_asc_disabled,
    table.dataTable thead th.sorting_desc_disabled {
      background-image: none !important;
      padding-right: 30px !important;
      position: relative;
    }
    /* Hide span/button injected by DataTables v2 with broken chars */
    table.dataTable thead th .dt-column-order,
    table.dataTable thead th span.dt-column-order,
    table.dataTable thead th button.dt-column-order { display: none !important; }
    /* Hide ::before and ::after from DataTables */
    table.dataTable thead th.sorting::before,
    table.dataTable thead th.sorting_asc::before,
    table.dataTable thead th.sorting_desc::before,
    table.dataTable thead th.sorting::after,
    table.dataTable thead th.sorting_asc::after,
    table.dataTable thead th.sorting_desc::after { display: none !important; content: none !important; }
    /* Custom sort icon via our own span injected via JS */
    table.dataTable thead th .sort-icon {
      display: inline-block;
      margin-left: 5px;
      font-family: "bootstrap-icons" !important;
      font-size: .72rem;
      opacity: .45;
      color: inherit;
    }
    table.dataTable thead th.sorting_asc .sort-icon  { opacity:1; color:#0b3a8c; }
    table.dataTable thead th.sorting_desc .sort-icon { opacity:1; color:#0b3a8c; }
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
                'Daftar Aset Tetap'               => 'bi bi-card-list',
                'Manajemen User'                  => 'bi bi-people',
            ];
            $allMenus = [];
                while ($row = mysqli_fetch_assoc($result_menu)) {
                    $allMenus[] = $row;
                }

                // Sort berdasarkan urutan_menu untuk memastikan urutan selalu konsisten
                usort($allMenus, function($a, $b) {
                    return $a['urutan_menu'] <=> $b['urutan_menu'];
                });

                $currentPage = basename($_SERVER['PHP_SELF']);
                foreach ($allMenus as $row) {
                    $namaMenu = trim($row['nama_menu']);
                    $icon     = $iconMap[$namaMenu] ?? 'bi bi-circle';
                    $isActive = ($currentPage === $row['menu'] . '.php') ? 'active' : '';
                    if ($namaMenu === 'Manajemen Menu') echo '<li class="nav-header"></li>';
                    echo '<li class="nav-item"><a href="../' . $row['menu'] . '/' . $row['menu'] . '.php" class="nav-link ' . $isActive . '"><i class="nav-icon ' . $icon . '"></i><p>' . htmlspecialchars($namaMenu) . '</p></a></li>';
                }
        ?>
        </ul>
      </nav>
    </div>
  </aside>

  <main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6">
          <h3 class="mb-0">Pelaksanaan Penghapusan</h3>
        </div>
        <div class="col-sm-6 d-flex align-items-center justify-content-end gap-2">

          <!-- SATU FORM untuk tahun + status + reset -->
          <form method="GET" class="d-flex align-items-center gap-2">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">

            <label class="mb-0 text-muted small fw-semibold text-nowrap">
              <i class="bi bi-calendar3 me-1"></i>Tahun:
            </label>
            <select name="tahun" class="form-select form-select-sm" style="min-width:100px;" onchange="this.form.submit()">
              <?php foreach ($list_tahun as $t): ?>
                <option value="<?= $t ?>" <?= $t == $filterTahun ? 'selected' : '' ?>><?= $t ?></option>
              <?php endforeach; ?>
            </select>

            <select name="status" class="form-select form-select-sm" style="width:180px;" onchange="this.form.submit()">
              <option value="all"              <?= $filterStatus==='all'?'selected':'' ?>>Semua Status</option>
              <optgroup label="── Jual Lelang ──">
                <option value="Appraisal Aset" <?= $filterStatus==='Appraisal Aset'?'selected':'' ?>>Appraisal Aset</option>
                <option value="Proses Lelang"  <?= $filterStatus==='Proses Lelang'?'selected':'' ?>>Proses Lelang</option>
                <option value="Terjual"        <?= $filterStatus==='Terjual'?'selected':'' ?>>Terjual</option>
              </optgroup>
              <optgroup label="── Hapus Administrasi ──">
                <option value="Disetujui"           <?= $filterStatus==='Disetujui'?'selected':'' ?>>Disetujui</option>
                <option value="Hapus Administrasi"  <?= $filterStatus==='Hapus Administrasi'?'selected':'' ?>>Hapus Administrasi</option>
              </optgroup>
            </select>
          </form>

          <ol class="breadcrumb float-sm-end mb-0">
            <li class="breadcrumb-item"><a href="../dasbor/dasbor.php">Home</a></li>
            <li class="breadcrumb-item active">Pelaksanaan Penghapusan</li>
          </ol>
        </div>
      </div>
    </div>
  </div><!-- /app-content-header -->

  <div class="app-content">
    <div class="container-fluid">

      <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success_msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
      <?php endif; ?>
      <?php if ($warning_msg): ?>
        <div class="alert alert-warning alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($warning_msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
      <?php endif; ?>

      <!-- ══ SUMMARY 3 GROUP ══ -->
      <div class="row g-3 mb-4">

        <!-- GROUP 1: Total Pelaksanaan -->
        <div class="col-12 col-md-3">
          <div style="border-radius:14px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.08);height:100%;">
            <div style="background:linear-gradient(135deg,#1e293b,#334155);padding:16px 20px 10px;color:#fff;">
              <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;opacity:.85;margin-bottom:6px;">
                <i class="bi bi-collection me-1"></i>Total Usulan
              </div>
              <div style="font-size:2.4rem;font-weight:800;line-height:1;"><?= $cnt_total_usulan ?></div>
              <div style="font-size:.75rem;opacity:.8;margin-top:2px;">Total usulan tahun ini</div>
            </div>
            <div style="background:#fff;padding:12px 20px;border-top:1px solid #e0eaff;">
              <div style="display:flex;align-items:center;justify-content:space-between;">
                <span style="font-size:.8rem;color:#6b7280;"><i class="bi bi-check-circle me-1 text-success"></i>Disetujui HO</span>
                <span style="font-size:.9rem;font-weight:700;color:#059669;"><?= $cnt_disetujui_ho ?></span>
              </div>
              <div style="display:flex;align-items:center;justify-content:space-between;margin-top:5px;">
                <span style="font-size:.8rem;color:#6b7280;"><i class="bi bi-clock me-1 text-warning"></i>Belum Disetujui HO</span>
                <span style="font-size:.9rem;font-weight:700;color:#d97706;"><?= $cnt_belum_disetujui_ho ?></span>
              </div>
           
            </div>
          </div>
        </div>

        <!-- GROUP 2: Jual / Lelang -->
        <div class="col-12 col-md-5">
          <div style="border-radius:14px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.08);height:100%;">
            <div style="background:linear-gradient(135deg,#0369a1,#0ea5e9);padding:16px 20px 10px;color:#fff;">
              <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;opacity:.85;margin-bottom:6px;">
                <i class="bi bi-tag-fill me-1"></i>Jual / Lelang
              </div>
              <div style="font-size:2.4rem;font-weight:800;line-height:1;"><?= $cnt_jual_lelang_total ?></div>
              <div style="font-size:.75rem;opacity:.8;margin-top:2px;">Total aset mekanisme Jual Lelang</div>
            </div>
            <div style="background:#fff;padding:12px 20px;border-top:1px solid #e0f2fe;">
              <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;text-align:center;">
                <div style="background:#fef3c7;border-radius:8px;padding:8px 6px;">
                  <div style="font-size:1.4rem;font-weight:800;color:#92400e;"><?= $cnt_appraisal ?></div>
                  <div style="font-size:.72rem;color:#78350f;font-weight:600;"><i class="bi bi-calculator me-1"></i>Appraisal</div>
                </div>
                <div style="background:#ede9fe;border-radius:8px;padding:8px 6px;">
                  <div style="font-size:1.4rem;font-weight:800;color:#6d28d9;"><?= $cnt_lelang ?></div>
                  <div style="font-size:.72rem;color:#5b21b6;font-weight:600;"><i class="bi bi-arrow-repeat me-1"></i>Proses Lelang</div>
                </div>
                <div style="background:#ccfbf1;border-radius:8px;padding:8px 6px;">
                  <div style="font-size:1.4rem;font-weight:800;color:#0f766e;"><?= $cnt_terjual ?></div>
                  <div style="font-size:.72rem;color:#0f766e;font-weight:600;"><i class="bi bi-bag-check me-1"></i>Terjual</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- GROUP 3: Hapus Administrasi -->
        <div class="col-12 col-md-4">
          <div style="border-radius:14px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.08);height:100%;">
            <div style="background:linear-gradient(135deg,#b91c1c,#ef4444);padding:16px 20px 10px;color:#fff;">
              <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;opacity:.85;margin-bottom:6px;">
                <i class="bi bi-trash3 me-1"></i>Hapus Administrasi
              </div>
              <div style="font-size:2.4rem;font-weight:800;line-height:1;"><?= $cnt_hapus_admin_total ?></div>
              <div style="font-size:.75rem;opacity:.8;margin-top:2px;">Total aset mekanisme Hapus Administrasi</div>
            </div>
            <div style="background:#fff;padding:12px 20px;border-top:1px solid #fee2e2;">
              <div style="display:flex;align-items:center;justify-content:space-between;">
                <span style="font-size:.8rem;color:#6b7280;"><i class="bi bi-file-earmark-check me-1 text-danger"></i>Submit BA Hapus Administrasi</span>
                <span style="font-size:.9rem;font-weight:700;color:#dc2626;"><?= $cnt_musnahkan ?></span>
              </div>

            </div>
          </div>
        </div>

      </div>
      <!-- END SUMMARY -->

      <!-- Page-level Tabs -->
      <div class="card">
        <div class="card-body">

          <ul class="nav nav-tabs page-nav-tabs mb-3" role="tablist">
            <li class="nav-item">
              <button class="nav-link <?= $activeTab==='daftar'?'active':'' ?>"
                      data-bs-toggle="tab" data-bs-target="#tab-pel-daftar" type="button"
                      onclick="updateTabUrl('daftar')">
                <i class="bi bi-list-ul me-2"></i>Daftar Pelaksanaan
              </button>
            </li>
            <li class="nav-item">
              <button class="nav-link <?= $activeTab==='upload'?'active':'' ?>"
                      data-bs-toggle="tab" data-bs-target="#tab-pel-upload" type="button"
                      onclick="updateTabUrl('upload')">
                <i class="bi bi-upload me-2"></i>Upload Dokumen Pelaksanaan
              </button>
            </li>
          </ul>

          <div class="tab-content">

            <!-- ══ TAB 1: DAFTAR PELAKSANAAN ══ -->
            <div class="tab-pane fade <?= $activeTab==='daftar'?'show active':'' ?>" id="tab-pel-daftar">

              <?php
              // Split data by mekanisme
              $data_jual_lelang   = array_values(array_filter($data_pelaksanaan, fn($d) => ($d['mekanisme_penghapusan'] ?? '') === 'Jual Lelang'));
              $data_hapus_admin   = array_values(array_filter($data_pelaksanaan, fn($d) => ($d['mekanisme_penghapusan'] ?? '') === 'Hapus Administrasi'));

              $stMap = [
                'Disetujui'           =>['st-disetujui','bi-check-circle'],
                'Appraisal Aset'      =>['st-appraisal','bi-calculator'],
                'Proses Lelang'       =>['st-lelang','bi-arrow-repeat'],
                'Terjual'             =>['st-terjual','bi-bag-check'],
                'Ditolak'             =>['st-ditolak','bi-x-circle'],
                'Hapus Administrasi'  =>['st-musnahkan','bi-trash3'],
                'Telah Dimusnahkan'   =>['st-musnahkan','bi-trash3'],
                'Telah dimusnahkan'   =>['st-musnahkan','bi-trash3']
              ];
              ?>

              <?php if (empty($data_pelaksanaan)): ?>
                <div class="text-center text-muted py-5">
                  <i class="bi bi-inbox" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:.75rem;"></i>
                  Belum ada data pelaksanaan untuk tahun <?= $filterTahun ?>.
                </div>
              <?php else: ?>

              <!-- ── TABEL 1: JUAL LELANG ── -->
              <div class="card-table mb-4" style="border:1px solid #dee2e6;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);">
                <div class="card-table-header" style="background:#fff;border-bottom:2px solid #dee2e6;padding:13px 20px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                  <i class="bi bi-tag-fill" style="font-size:1rem;color:#374151;"></i>
                  <h5 style="margin:0;font-size:.95rem;font-weight:700;color:#1f2937;flex:1;">Jual / Lelang</h5>
                  <span class="badge bg-secondary" style="font-size:.78rem;"><?= count($data_jual_lelang) ?> Aset</span>
                  <span class="badge bg-primary" style="font-size:.75rem;">Tahun <?= $filterTahun ?></span>
                </div>
                <div class="card-table-body" style="padding:0;">
                  <?php if (empty($data_jual_lelang)): ?>
                    <div class="text-center text-muted py-4" style="font-size:.88rem;">
                      <i class="bi bi-inbox" style="font-size:1.8rem;opacity:.3;display:block;margin-bottom:.5rem;"></i>
                      Belum ada data Jual Lelang untuk tahun <?= $filterTahun ?>.
                    </div>
                  <?php else: ?>
                  <div class="table-responsive">
                    <table id="tblJualLelang" class="table table-bordered table-hover align-middle w-100 mb-0" style="font-size:.875rem;">
                      <thead>
                        <tr>
                          <th style="white-space:nowrap;">No</th>
                          <th style="white-space:nowrap;">Profit Center</th>
                          <th style="white-space:nowrap;">Nomor Aset</th>
                          <th style="white-space:nowrap;">Nomor Aset Baru</th>
                          <th style="white-space:nowrap;">Deskripsi</th>
                          <th style="white-space:nowrap;">Tahun Perolehan</th>
                          <th style="white-space:nowrap;">Umur Aset</th>
                          <th style="white-space:nowrap;">Nilai Buku</th>
                          <th style="white-space:nowrap;">Nilai Pasar (Appraisal KJPP)</th>
                          <th style="white-space:nowrap;">Nilai Hasil Penjualan/Pelelangan</th>
                          <th style="white-space:nowrap;">Status Pelaksanaan</th>
                          <th style="white-space:nowrap;text-align:center;">Dokumen</th>
                          <th style="white-space:nowrap;text-align:center;">Aksi</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($data_jual_lelang as $i => $p):
                          $stKey = $p['status_pelaksanaan'];
                          if (!isset($stMap[$stKey])) {
                            foreach ($stMap as $k => $v) { if (strtolower($k) === strtolower($stKey)) { $stKey = $k; break; } }
                          }
                          [$stClass,$stIcon] = $stMap[$stKey] ?? ['st-disetujui','bi-circle'];
                          $stLabel = (stripos($stKey,'telah dimusnahkan')!==false || stripos($stKey,'hapus administrasi')!==false) ? 'Hapus Administrasi' : $stKey;
                          $total_dokumen = (int)$p['jml_dok_ho'] + (int)$p['jml_dok_usulan'];
                          $tgl_perolehan = $p['tgl_perolehan'] ?? '-';
                          $thn_perolehan = ($tgl_perolehan !== '-' && $tgl_perolehan) ? date('Y', strtotime($tgl_perolehan)) : '-';
                        ?>
                        <tr>
                          <td class="text-center"><?= $i+1 ?></td>
                          <td><?= htmlspecialchars($p['profit_center_text']??$p['profit_center']??'-') ?></td>
                          <td><code style="color:#2563eb;font-size:.82rem;"><?= htmlspecialchars($p['nomor_asset_utama']) ?></code></td>
                          <td><code style="color:#6b7280;font-size:.82rem;"><?= htmlspecialchars($p['nomor_aset_pengganti']??'-') ?></code></td>
                          <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($p['nama_aset']??'-') ?></td>
                          <td class="text-center"><?= $thn_perolehan ?></td>
                          <td style="white-space:nowrap;"><?php
                            $u_bln = isset($p['umur_ekonomis']) ? (int)$p['umur_ekonomis'] : null;
                            if ($u_bln === null) {
                              echo '<span style="color:#9ca3af;">-</span>';
                            } elseif ($u_bln < 60) {
                              echo '<div style="font-size:.78rem;font-weight:600;">Di bawah 5 thn</div>';
                            } else {
                              echo '<div style="font-size:.78rem;font-weight:600;">Sampai dengan 5 thn';
                            }
                          ?></td>
                          <td class="text-end" style="font-family:monospace;font-size:.82rem;"><?php
                            $nb = $p['nilai_buku_bulan_berjalan'] ?? $p['nilai_buku_awal'] ?? $p['nilai_buku'] ?? null;
                            echo $nb !== null ? number_format((float)$nb, 0, ',', '.') : '-';
                          ?></td>
                          <td class="text-end" style="font-family:monospace;font-size:.82rem;"><?php
                            $nap = $p['nilai_appraisal_pasar'] ?? null;
                            echo $nap !== null ? number_format((float)$nap, 0, ',', '.') : '-';
                          ?></td>
                          <td class="text-end" style="font-family:monospace;font-size:.82rem;"><?php
                            $npj = $p['nilai_penjualan'] ?? null;
                            echo $npj !== null ? number_format((float)$npj, 0, ',', '.') : '-';
                          ?></td>
                          <td><span class="badge-pill <?= $stClass ?>"><i class="bi <?= $stIcon ?> me-1"></i><?= $stLabel ?></span></td>
                          <td class="text-center">
                            <span class="badge bg-<?= $total_dokumen > 0 ? 'success' : 'secondary' ?>"><?= $total_dokumen ?></span>
                          </td>
                          <td class="text-center" style="white-space:nowrap;">
                            <button class="btn btn-sm btn-outline-primary btn-detail-pel" data-id="<?= $p['id'] ?>" title="Detail"><i class="bi bi-eye"></i></button>
                            <?php if ($canEdit): ?>
                            <button class="btn btn-sm btn-outline-warning ms-1 btn-edit-pel" data-id="<?= $p['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></button>
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
              <!-- END TABEL 1 -->

              <!-- ── TABEL 2: HAPUS ADMINISTRASI ── -->
              <div class="card-table" style="border:1px solid #dee2e6;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);">
                <div class="card-table-header" style="background:#fff;border-bottom:2px solid #dee2e6;padding:13px 20px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                  <i class="bi bi-trash3" style="font-size:1rem;color:#374151;"></i>
                  <h5 style="margin:0;font-size:.95rem;font-weight:700;color:#1f2937;flex:1;">Hapus Administrasi</h5>
                  <span class="badge bg-secondary" style="font-size:.78rem;"><?= count($data_hapus_admin) ?> Aset</span>
                  <span class="badge bg-primary" style="font-size:.75rem;">Tahun <?= $filterTahun ?></span>
                </div>
                <div class="card-table-body" style="padding:0;">
                  <?php if (empty($data_hapus_admin)): ?>
                    <div class="text-center text-muted py-4" style="font-size:.88rem;">
                      <i class="bi bi-inbox" style="font-size:1.8rem;opacity:.3;display:block;margin-bottom:.5rem;"></i>
                      Belum ada data Hapus Administrasi untuk tahun <?= $filterTahun ?>.
                    </div>
                  <?php else: ?>
                  <div class="table-responsive">
                    <table id="tblHapusAdmin" class="table table-bordered table-hover align-middle w-100 mb-0" style="font-size:.875rem;">
                      <thead>
                        <tr>
                          <th style="white-space:nowrap;">No</th>
                          <th style="white-space:nowrap;">Sub Regional</th>
                          <th style="white-space:nowrap;">Lokasi</th>
                          <th style="white-space:nowrap;">No Aset Usulan</th>
                          <th style="white-space:nowrap;">Aset Tetap</th>
                          <th style="white-space:nowrap;text-align:center;">Jumlah</th>
                          <th style="white-space:nowrap;">Umur Ekonomis</th>
                          <th style="white-space:nowrap;">Tahun Perolehan</th>
                          <th style="white-space:nowrap;">Nilai Perolehan (Rp)</th>
                          <th style="white-space:nowrap;">Nilai Buku</th>
                          <th style="white-space:nowrap;">Fisik Aset</th>
                          <th style="white-space:nowrap;">Status Pelaksanaan</th>
                          <th style="white-space:nowrap;text-align:center;">Dokumen</th>
                          <th style="white-space:nowrap;text-align:center;">Aksi</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($data_hapus_admin as $i => $p):
                          $stKey = $p['status_pelaksanaan'];
                          if (!isset($stMap[$stKey])) {
                            foreach ($stMap as $k => $v) { if (strtolower($k) === strtolower($stKey)) { $stKey = $k; break; } }
                          }
                          [$stClass,$stIcon] = $stMap[$stKey] ?? ['st-disetujui','bi-circle'];
                          $stLabel = (stripos($stKey,'telah dimusnahkan')!==false || stripos($stKey,'hapus administrasi')!==false) ? 'Hapus Administrasi' : $stKey;
                          $total_dokumen = (int)$p['jml_dok_ho'] + (int)$p['jml_dok_usulan'];
                          $tgl_perolehan = $p['tgl_perolehan'] ?? '-';
                          $thn_perolehan = ($tgl_perolehan !== '-' && $tgl_perolehan) ? date('Y', strtotime($tgl_perolehan)) : '-';
                          $umur_bln = isset($p['umur_ekonomis']) ? (int)$p['umur_ekonomis'] : null;
                        ?>
                        <tr>
                          <td class="text-center"><?= $i+1 ?></td>
                          <td><?= htmlspecialchars($p['subreg']??'-') ?></td>
                          <td><?= htmlspecialchars($p['profit_center_text']??$p['profit_center']??'-') ?></td>
                          <td><code style="color:#2563eb;font-size:.82rem;"><?= htmlspecialchars($p['nomor_asset_utama']) ?></code></td>
                          <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($p['nama_aset']??'-') ?></td>
                          <td class="text-center"><?= htmlspecialchars($p['jumlah_aset']??'1') ?></td>
                          <td style="white-space:nowrap;"><?php
                            $u_bln = isset($p['umur_ekonomis']) ? (int)$p['umur_ekonomis'] : null;
                            if ($u_bln === null) {
                              echo '<span style="color:#9ca3af;">-</span>';
                            } elseif ($u_bln < 60) {
                              echo '<div style="font-size:.78rem;font-weight:600;">Di bawah 5 thn</div>';
                            } else {
                              echo '<div style="font-size:.78rem;font-weight:600;">Sampai dengan 5 thn</div>';
                            }
                          ?></td>
                          <td class="text-center"><?= $thn_perolehan ?></td>
                          <td class="text-end" style="font-family:monospace;font-size:.82rem;"><?php
                            $np = $p['nilai_perolehan_sd'] ?? null;
                            echo $np !== null ? number_format((float)$np, 0, ',', '.') : '-';
                          ?></td>
                          <td class="text-end" style="font-family:monospace;font-size:.82rem;"><?php
                            $nb = $p['nilai_buku_bulan_berjalan'] ?? $p['nilai_buku_awal'] ?? $p['nilai_buku'] ?? null;
                            echo $nb !== null ? number_format((float)$nb, 0, ',', '.') : '-';
                          ?></td>
                          <td class="text-center"><?php
                            $fis = $p['fisik_aset'] ?? '-';
                            echo $fis && $fis !== '-' ? '<div style="font-size:.78rem;font-size:.82rem;">'.htmlspecialchars($fis).'</div>' : '<span style="color:#9ca3af;">-</span>';
                          ?></td>
                          <td><span class="badge-pill <?= $stClass ?>"><i class="bi <?= $stIcon ?> me-1"></i><?= $stLabel ?></span></td>
                          <td class="text-center">
                            <span class="badge bg-<?= $total_dokumen > 0 ? 'success' : 'secondary' ?>"><?= $total_dokumen ?></span>
                          </td>
                          <td class="text-center" style="white-space:nowrap;">
                            <button class="btn btn-sm btn-outline-primary btn-detail-pel" data-id="<?= $p['id'] ?>" title="Detail"><i class="bi bi-eye"></i></button>
                            <?php if ($canEdit): ?>
                            <button class="btn btn-sm btn-outline-warning ms-1 btn-edit-pel" data-id="<?= $p['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></button>
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
              <!-- END TABEL 2 -->

              <?php endif; ?>

            </div>
            <!-- End Tab Daftar -->

            <!-- ══ TAB 2: UPLOAD DOKUMEN HO ══ -->
            <div class="tab-pane fade <?= $activeTab==='upload'?'show active':'' ?>" id="tab-pel-upload">

              <!-- Form Upload -->
              <div class="card mb-3" style="border:1px solid #dee2e6;border-radius:8px;">
                <div class="card-header" style="background:#0b3a8c;color:#fff;border-radius:8px 8px 0 0;">
                  <strong><i class="bi bi-cloud-upload me-2"></i>Form Upload Dokumen Pelaksanaan</strong>
                </div>
                <!-- alert cara upload dihapus -->
                <div class="card-body">
                  <?php if (empty($data_pelaksanaan)): ?>
                    <div class="alert alert-info mb-0">
                      <i class="bi bi-info-circle me-2"></i>
                      Belum ada data pelaksanaan. Persetujuan HO harus disetujui terlebih dahulu.
                    </div>
                  <?php else: ?>
                  <form method="POST" enctype="multipart/form-data" id="formUploadHoPel">
                    <input type="hidden" name="action" value="upload_dok_ho">
                    <input type="hidden" name="kategori_dok" value="pendukung">
                    <input type="hidden" name="tahun_filter" value="<?= $filterTahun ?>">
                    <input type="hidden" name="id_pelaksanaan" id="upload_pel_id_pelaksanaan">
                    <input type="hidden" name="nomor_aset" id="upload_pel_nomor_aset">

                    <div class="mb-3">
                      <label class="form-label">Deskripsi Dokumen <span class="text-danger">*</span></label>
                      <input type="text" class="form-control" name="deskripsi_dokumen"
                             placeholder="Contoh: Berita Acara Penghapusan, Risalah Lelang, dst." required>
                    </div>

                    <div class="mb-3">
                      <label class="form-label">Aset <span class="text-danger">*</span></label>
                      <div class="input-group">
                        <input type="text" class="form-control" id="upload_pel_nomor_aset_display"
                               placeholder="Klik tombol untuk pilih aset" readonly>
                        <button type="button" class="btn btn-outline-primary" onclick="openPelAsetPickerModal()">
                          <i class="bi bi-search me-1"></i>Pilih Aset
                        </button>
                      </div>
                      <div id="upload_pel_selected_list" class="mt-1" style="font-size:.85rem;color:#374151;display:none;"></div>
                    </div>

                    <div class="mb-3">
                      <label class="form-label">File PDF <span class="text-danger">*</span></label>
                      <input type="file" class="form-control" name="file_dokumen" id="upload_pel_file" accept=".pdf" required onchange="checkUploadPelReady()">
                      <div class="form-text">Format: <strong>.pdf</strong>. Maksimal 50MB.</div>
                    </div>

                    <button type="submit" class="btn btn-primary" id="btnUploadPel" disabled>
                      <i class="bi bi-cloud-upload me-1"></i>Upload Dokumen
                    </button>
                  </form>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Preview Dokumen Terupload -->
              <div class="card" style="border:1px solid #28a745;box-shadow:0 1px 3px rgba(40,167,69,.1);">
                <div class="card-header" style="background:linear-gradient(135deg,#28a745,#20c997);color:#fff;border-radius:4px 4px 0 0;">
                  <strong><i class="bi bi-file-earmark-pdf me-2"></i>Preview Dokumen Terupload (<?= count($daftar_dok_pendukung) ?>)</strong>
                </div>
                <div class="card-body p-0">
                  <div class="table-responsive">
                    <table class="table table-bordered table-hover mb-0" style="font-size:.9rem;">
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
                        <?php if (empty($daftar_dok_pendukung)): ?>
                          <tr><td colspan="8" class="text-center text-muted py-3">Belum ada dokumen pendukung yang diupload</td></tr>
                        <?php else: ?>
                          <?php foreach ($daftar_dok_pendukung as $d): ?>
                          <?php $vurl = "?action=view_dok_ho&id_dok={$d['id_dokumen']}"; ?>
                          <tr>
                            <td><?= $d['id_dokumen'] ?></td>
                            <td><?= htmlspecialchars($d['tahun_dokumen'] ?? '-') ?></td>
                            <td style="white-space:nowrap;"><code style="color:#2563eb;font-size:.82rem;"><?= htmlspecialchars($d['nomor_aset'] ?? '-') ?></code></td>
                            <td><?= htmlspecialchars($d['profit_center'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($d['subreg'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($d['deskripsi_dokumen'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($d['cabang'] ?? '-') ?></td>
                            <td style="white-space:nowrap;">
                              <button type="button" class="btn btn-sm btn-outline-secondary"
                                      onclick="togglePelPreview('pelp-<?= $d['id_dokumen'] ?>','<?= $vurl ?>')">
                                <i class="bi bi-eye me-1"></i>Lihat Dokumen
                              </button>
                              <button type="button" class="btn btn-sm btn-outline-info ms-1"
                                      onclick="showDetailAsetPel('<?= htmlspecialchars(addslashes($d['nomor_aset'] ?? '')) ?>')">
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

                  <!-- Preview panel full-width -->
                  <div id="previewPanelPel" style="display:none;border-top:2px solid #28a745;">
                    <div style="background:#f1f5f9;padding:8px 16px;display:flex;align-items:center;justify-content:space-between;">
                      <span style="font-size:.82rem;color:#374151;font-weight:600;">
                        <i class="bi bi-file-earmark-pdf text-danger me-1"></i>
                        Preview: <span id="previewPanelPelLabel">-</span>
                      </span>
                      <div style="display:flex;gap:6px;">
                        <a id="previewPanelPelBuka" href="#" target="_blank" class="btn btn-sm btn-outline-primary" style="font-size:.76rem;">
                          <i class="bi bi-box-arrow-up-right me-1"></i>Buka
                        </a>
                        <button onclick="tutupPreviewPel()" class="btn btn-sm btn-outline-secondary" style="font-size:.76rem;">
                          <i class="bi bi-x-lg me-1"></i>Tutup
                        </button>
                      </div>
                    </div>
                    <iframe id="previewPanelPelFrame" src="" style="width:100%;height:600px;border:none;display:block;"></iframe>
                  </div>

                </div>
              </div>
            </div>
            <!-- End Tab Upload -->

          </div><!-- end tab-content -->
        </div>
      </div><!-- end card -->

    </div>
  </main>
  <footer class="app-footer">
    <div class="footer-inner">
      <strong class="footer-text">Copyright &copy; Proyek Aset Tetap Regional 3&nbsp;</strong>
      <div class="footer-right d-none d-sm-inline">PT Pelabuhan Indonesia (Persero)</div>
    </div>
  </footer>
</div>

<!-- MODAL DETAIL ASET PELAKSANAAN -->
<div class="modal fade" id="modalDetailAsetPel" tabindex="-1" aria-modal="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content" style="border-radius:6px;overflow:hidden;">
      <div class="modal-header" style="background:#fff;border-bottom:2px solid #0d6efd;padding:14px 20px;">
        <div>
          <div class="d-flex align-items-center mb-1">
            <i class="bi bi-table me-2" style="color:#0d6efd;font-size:1.1rem;"></i>
            <h5 class="modal-title mb-0 fw-bold" style="color:#0d6efd;">Detail Data Aset</h5>
          </div>
          <div style="font-size:0.875rem;color:#555;">
            Profit Center: <strong id="detailAsetPelPC">-</strong>
            &nbsp;|&nbsp; Subreg: <strong id="detailAsetPelSubreg">-</strong>
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
            <tbody id="detailAsetPelTbody">
              <tr><td colspan="5" class="text-center py-3 text-muted">Memuat data...</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer" style="background:#f8f9fa;border-top:1px solid #dee2e6;padding:10px 20px;justify-content:space-between;">
        <span id="detailAsetPelTotal" style="font-size:0.875rem;color:#555;"></span>
        <button type="button" class="btn btn-secondary btn-sm px-4" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>
<!-- END MODAL DETAIL ASET PELAKSANAAN -->

<!-- MODAL HAPUS DOKUMEN PENDUKUNG -->
<div class="modal fade" id="modalHapusDokPendukung" tabindex="-1" aria-modal="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Hapus Dokumen</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="hapus_dok_pendukung">
        <input type="hidden" name="tahun_filter" value="<?= $filterTahun ?>">
        <input type="hidden" name="id_dokumen" id="hapusDokPendukungId">
        <div class="modal-body">
          <p>Anda akan menghapus dokumen:</p>
          <p class="fw-bold text-danger" id="hapusDokPendukungNama"></p>
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

<!-- MODAL DETAIL -->
<div class="modal fade" id="modalDetail" tabindex="-1" aria-modal="true">
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
<div class="modal fade" id="modalEdit" tabindex="-1" aria-modal="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header" style="background:#0b3a8c;color:#fff;">
        <div><h5 class="modal-title mb-0"><i class="bi bi-pencil me-2"></i>Edit Data Pelaksanaan</h5><small id="editSubtitle" style="opacity:.8;font-size:.8rem;"></small></div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body" style="padding:0;overflow-y:auto;">

        <div id="tab-data">
          <form method="POST" id="formEditPelaksanaan">
          <input type="hidden" name="action" value="update_pelaksanaan">
          <input type="hidden" name="id_pelaksanaan" id="edit_id">
          <input type="hidden" name="tahun_filter" value="<?= $filterTahun ?>">
          <input type="hidden" id="edit_mekanisme" value="">

          <!-- ── BLOK ATAS: Nilai Buku Aset + Nilai Buku Bulan Berjalan ── -->
          <div style="padding:18px 22px 14px;">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold text-muted" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">
                  <i class="bi bi-book me-1"></i>Nilai Buku Aset
                </label>
                <div class="input-group">
                  <span class="input-group-text bg-light text-muted">Rp</span>
                  <input type="text" id="edit_nilai_buku_display" class="form-control"
                         readonly style="background:#f8f9fa;color:#6b7280;cursor:not-allowed;font-family:monospace;" placeholder="—">
                </div>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold text-muted" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;">
                  <i class="bi bi-book-half me-1"></i>Nilai Buku Bulan Berjalan
                </label>
                <div class="input-group">
                  <span class="input-group-text bg-light text-muted">Rp</span>
                  <input type="text" id="edit_nb_bb_display" class="form-control"
                         readonly style="background:#f8f9fa;color:#6b7280;cursor:not-allowed;font-family:monospace;" placeholder="—">
                </div>
              </div>
            </div>
          </div>

          <!-- ── STATUS PELAKSANAAN ── -->
          <div style="padding:14px 22px;background:#f8faff;border-top:1px solid #e9ecef;border-bottom:1px solid #e9ecef;">
            <label class="form-label fw-semibold" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;">
              <i class="bi bi-flag me-1"></i>Status Pelaksanaan
            </label>
            <!-- Info mekanisme -->
            <!-- <div id="edit_mekanisme_info" class="mb-2" style="font-size:.78rem;color:#6b7280;display:flex;align-items:center;gap:6px;"></div> -->
            <div class="d-flex align-items-center gap-3" style="width:100%;">
              <span id="edit_status_badge" class="badge-pill st-disetujui"
                    style="font-size:.9rem;padding:0 22px;border-radius:8px;min-width:160px;text-align:center;height:38px;line-height:38px;display:inline-block;white-space:nowrap;">
                Disetujui
              </span>
              <!-- Dropdown Jual Lelang -->
              <select name="status_pelaksanaan" id="edit_status_lelang" class="form-select edit-status-select" required
                      style="flex:1;font-weight:600;font-size:.9rem;height:38px;"
                      onchange="updateStatusBadge(this.value)">
                <option value="Appraisal Aset">Appraisal Aset</option>
                <option value="Proses Lelang">Proses Lelang</option>
                <option value="Terjual">Terjual</option>
              </select>
              <!-- Dropdown Hapus Administrasi -->
              <select name="status_pelaksanaan" id="edit_status_hapus" class="form-select edit-status-select" required
                      style="flex:1;font-weight:600;font-size:.9rem;height:38px;display:none;"
                      onchange="updateStatusBadge(this.value)">
                <option value="Disetujui">Disetujui</option>
                <option value="Hapus Administrasi">Hapus Administrasi</option>
              </select>
            </div>
          </div>

          <!-- ── SEKSI DATA APPRAISAL (hanya Jual Lelang) ── -->
          <div id="seksi_appraisal" style="padding:16px 22px 14px;">
            <p class="fw-semibold mb-3" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.8px;color:#9ca3af;display:flex;align-items:center;gap:6px;">
              <i class="bi bi-calculator"></i> Data Appraisal
              <span style="flex:1;height:1px;background:#f0f0f0;display:inline-block;"></span>
            </p>
            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">Nilai Appraisal Pasar</label>
                <div class="input-group">
                  <span class="input-group-text">Rp</span>
                  <input type="text" name="nilai_appraisal_pasar" id="edit_app_pasar" class="form-control" placeholder="0" style="font-family:monospace;">
                </div>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Nilai Appraisal Likuidasi</label>
                <div class="input-group">
                  <span class="input-group-text">Rp</span>
                  <input type="text" name="nilai_appraisal_likuidasi" id="edit_app_likuidasi" class="form-control" placeholder="0" style="font-family:monospace;">
                </div>
              </div>
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">Tanggal Appraisal</label>
                <input type="date" name="tanggal_appraisal" id="edit_tgl_appraisal" class="form-control">
              </div>
            </div>
          </div>

          <!-- ── SEKSI DATA PENJUALAN (hanya Jual Lelang) ── -->
          <div id="seksi_penjualan" style="padding:16px 22px 14px;border-top:1px solid #f0f0f0;">
            <p class="fw-semibold mb-3" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.8px;color:#9ca3af;display:flex;align-items:center;gap:6px;">
              <i class="bi bi-currency-dollar"></i> Data Penjualan
              <span style="flex:1;height:1px;background:#f0f0f0;display:inline-block;"></span>
            </p>
            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">Nilai Penjualan</label>
                <div class="input-group">
                  <span class="input-group-text">Rp</span>
                  <input type="text" name="nilai_penjualan" id="edit_nilai_jual" class="form-control" placeholder="0" style="font-family:monospace;">
                </div>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Biaya Lainnya</label>
                <div class="input-group">
                  <span class="input-group-text">Rp</span>
                  <input type="text" name="biaya_lainnya" id="edit_biaya" class="form-control" placeholder="0" style="font-family:monospace;">
                </div>
              </div>
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">Tanggal Penjualan</label>
                <input type="date" name="tanggal_penjualan" id="edit_tgl_penjualan" class="form-control">
              </div>
            </div>
          </div>

          <!-- ── NOMOR ASET PENGGANTI ── -->
           <div style="padding:16px 22px 14px;border-top:1px solid #f0f0f0;">
            <p class="fw-semibold mb-3" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.8px;color:#9ca3af;display:flex;align-items:center;gap:6px;">
              <i class="bi bi-arrow-left-right"></i>Aset Pengganti
              <span style="flex:1;height:1px;background:#f0f0f0;display:inline-block;"></span>
            </p>
            <div class="row g-3">
              <div class="col-md-12">
                <label class="form-label fw-semibold">Nomor Aset Pengganti</label>
                <input type="text" name="nomor_aset_pengganti" id="edit_aset_pengganti"
                       class="form-control" placeholder="Isi jika ada aset pengganti">
                <div class="form-text text-muted mt-1" style="font-size:0.75rem;">
                  <i class="bi bi-info-circle me-1"></i>
                  Format: <code>10 digit nomor aset</code> - <code>sub aset</code> &nbsp;
                  <span style="color:#6b7280;">Contoh: <em>120502001183-0</em></span>
                </div>
              </div>
            </div>
          </div>

          <!-- hidden field nilai buku bulan berjalan (readonly display field terpisah) -->
          <input type="hidden" name="nilai_buku_bulan_berjalan" id="edit_nb_bb">

          </form>
        </div><!-- end tab-data -->

      </div><!-- end modal-body -->

      <div class="modal-footer" style="border-top:1px solid #e9ecef;background:#f8faff;position:sticky;bottom:0;z-index:10;gap:8px;">
        <div style="display:flex;align-items:center;justify-content:flex-end;width:100%;gap:8px;flex-wrap:wrap;">
          <div style="display:flex;gap:8px;">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
            <button type="submit" form="formEditPelaksanaan" class="btn btn-primary btn-sm" id="btnSimpanData">
              <i class="bi bi-save me-1"></i>Simpan Data
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ══ MODAL: PILIH ASET (Upload Tab) ══ -->
<div class="modal fade" id="modalPelAsetPicker" tabindex="-1" aria-modal="true">
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
        <div class="alert alert-success" id="pelPickerSelectedCount" style="display:none;">
          <i class="bi bi-check-circle me-2"></i>
          <strong><span id="pelPickerCountNum">0</span> aset dipilih</strong>
        </div>
        <div class="table-responsive">
          <table class="table table-bordered table-hover table-sm w-100" id="pelAsetPickerTable">
            <thead class="table-light">
              <tr>
                <th style="width:42px;"><input type="checkbox" id="selectAllPelPicker" class="form-check-input"></th>
                <th>Nomor Aset</th>
                <th>Nama Aset</th>
                <th>Mekanisme</th>
                <th>Profit Center</th>
                <th>SubReg</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($data_pelaksanaan as $p): ?>
              <?php $sdh_pendukung = (int)($p['has_pendukung'] ?? 0) > 0; ?>
              <tr class="<?= $sdh_pendukung ? 'table-secondary' : '' ?>">
                <td class="text-center">
                  <input type="checkbox" class="form-check-input pel-picker-check"
                         value="<?= $p['id'] ?>"
                         data-nomor="<?= htmlspecialchars($p['nomor_asset_utama'], ENT_QUOTES) ?>"
                         data-nama="<?= htmlspecialchars($p['nama_aset'] ?? '-', ENT_QUOTES) ?>"
                         <?= $sdh_pendukung ? 'checked disabled' : '' ?>>
                  <?php if ($sdh_pendukung): ?>
                  <?php endif; ?>
                </td>
                <td><code style="color:#2563eb;font-size:.82rem;"><?= htmlspecialchars($p['nomor_asset_utama']) ?></code></td>
                <td><?= htmlspecialchars($p['nama_aset']??'-') ?></td>
                <td><?= htmlspecialchars($p['mekanisme_penghapusan']??'-') ?></td>
                <td><?= htmlspecialchars($p['profit_center_text']??$p['profit_center']??'-') ?></td>
                <td><?= htmlspecialchars($p['subreg']??'-') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary btn-sm" id="btnConfirmPelPicker">
          <i class="bi bi-check-circle me-1"></i>Konfirmasi Pilihan
        </button>
      </div>
    </div>
  </div>
</div>

<script src="../../dist/js/jquery-3.7.1.min.js"></script>
<script src="../../dist/js/bootstrap.bundle.min.js"></script>
<script src="../../dist/js/dataTables.min.js"></script>
<script>
const dataPelaksanaan = <?= json_encode($data_pelaksanaan) ?>;
const dataDokHo       = <?= json_encode($daftar_dok_ho) ?>;
const dataDokUsulan   = <?= json_encode($daftar_dok_usulan) ?>;
const dataDokPendukung = <?= json_encode($daftar_dok_pendukung) ?>;

$(document).ready(function() {
  // Helper: init DataTable on a table
  function initDT(tableId) {
  if (!$('#' + tableId + ' tbody tr').length) return;
  var noOrderCols = tableId === 'tblJualLelang' ? [0, 11, 12] : [0, 12, 13];
  var dt = $('#' + tableId).DataTable({
    language:{search:"Cari:",lengthMenu:"Tampilkan _MENU_ data",info:"_START_-_END_ dari _TOTAL_ data",
      paginate:{first:"&laquo;",previous:"&lsaquo;",next:"&rsaquo;",last:"&raquo;"},zeroRecords:"Tidak ada data"},
    pageLength:10,
    lengthMenu:[10, 25, 50, 100],
    columnDefs:[
      {orderable:false, targets: noOrderCols},
    ],
    order: [[1, 'asc']],
    rowCallback: function(row, data, index) {      
      var info = this.api().page.info();
      $('td:first', row).html(info.start + index + 1);
    }
  });
    function refreshSortIcons() {
      $('#' + tableId + ' thead th').each(function() {
        $(this).find('.sort-icon').remove();
        if ($(this).hasClass('sorting') || $(this).hasClass('sorting_asc') || $(this).hasClass('sorting_desc')) {
          let icon = '\uF127';
          if ($(this).hasClass('sorting_asc'))  icon = '\uF148';
          if ($(this).hasClass('sorting_desc')) icon = '\uF146';
          $(this).append('<span class="sort-icon">' + icon + '</span>');
        }
      });
    }
    refreshSortIcons();
    $('#' + tableId).on('order.dt', refreshSortIcons);
  }

  initDT('tblJualLelang');
  initDT('tblHapusAdmin');
});


$(document).on('click','.btn-detail-pel',function(){ openDetail($(this).data('id')); });
$(document).on('click','.btn-edit-pel',function(){ openEdit($(this).data('id')); });

const rupiah = n => (n !== null && n !== '' && n !== undefined) ? 'Rp ' + parseFloat(n).toLocaleString('id-ID',{minimumFractionDigits:0}) : '\u2014';
const stConfig = {
  'Disetujui':          {cls:'st-disetujui', ic:'bi-check-circle'},
  'Appraisal Aset':     {cls:'st-appraisal', ic:'bi-calculator'},
  'Proses Lelang':      {cls:'st-lelang',    ic:'bi-arrow-repeat'},
  'Terjual':            {cls:'st-terjual',   ic:'bi-bag-check'},
  'Hapus Administrasi': {cls:'st-musnahkan', ic:'bi-trash3'},
  'Telah Dimusnahkan':  {cls:'st-musnahkan', ic:'bi-trash3'},
  'Telah dimusnahkan':  {cls:'st-musnahkan', ic:'bi-trash3'},
  'Ditolak':            {cls:'st-ditolak',   ic:'bi-x-circle'},
};

// normalize status agar case-insensitive
function normalizeStatus(s) {
  if (!s) return '';
  const map = {
    'disetujui': 'Disetujui',
    'ditolak': 'Ditolak',
    'appraisal aset': 'Appraisal Aset',
    'proses lelang': 'Proses Lelang',
    'terjual': 'Terjual',
    'hapus administrasi': 'Hapus Administrasi',
    'telah dimusnahkan': 'Hapus Administrasi',
  };
  return map[s.toLowerCase()] || s;
}

function openDetail(id) {
  const p = dataPelaksanaan.find(x => x.id == id);
  if (!p) return;
  const isHapus = (p.mekanisme_penghapusan === 'Hapus Administrasi');
  const status = normalizeStatus(p.status_pelaksanaan);

  // Progress track steps berbeda per mekanisme
  const steps = isHapus
    ? ['Disetujui', 'Hapus Administrasi']
    : ['Disetujui', 'Appraisal Aset', 'Proses Lelang', 'Terjual'];

  const kajian = (label, value) => {
    const isEmpty = !value || String(value).trim() === '';
    return `<div class="kajian-item">
      <div class="kajian-label">${label}</div>
      <div class="kajian-box${isEmpty?' empty':''}">${isEmpty?'Tidak diisi':value}</div>
    </div>`;
  };

  const curIdx = steps.indexOf(status);
  const trackHtml = steps.map((s,i) => {
    const done = i < curIdx, active = i === curIdx;
    const bg   = done?'#d1fae5':active?'#dbeafe':'#f3f4f6';
    const clr  = done?'#059669':active?'#1d4ed8':'#9ca3af';
    const ic   = done?'bi-check-lg':(stConfig[s]?.ic||'bi-circle');
    return `<div class="st-node"><div class="st-circle" style="background:${bg};color:${clr};"><i class="bi ${ic}"></i></div><div class="st-name" style="color:${active?'#1d4ed8':done?'#059669':'#9ca3af'}">${s}</div></div>`;
  }).join('');

  const dokHo  = dataDokHo.filter(d => d.id_pelaksanaan == p.id && (d.kategori === 'ho' || !d.kategori));
  const dokUsl = dataDokUsulan.filter(d => d.usulan_id == p.usulan_id);
  const dokPend = dataDokPendukung.filter(d => d.id_pelaksanaan == p.id);

  const makeDokRow = (d, i, urlKey, descKey, isHo) => {
    const url = isHo ? `?action=view_dok_ho&id_dok=${d.id_dokumen}` : `?action=view_dok_usulan&id_dok=${d.id_dokumen}`;
    const pid = `d${isHo?'h':'u'}-${d.id_dokumen}`;
    const label = isHo ? (d.deskripsi_dokumen||'Dokumen HO') : (d.tipe_dokumen||'Dokumen');
    const tahunInfo = isHo
      ? `<span style="color:#9ca3af;font-size:.75rem;">Tahun ${d.tahun_dokumen||'--'}</span>`
      : `<span style="color:#9ca3af;font-size:.75rem;">Tahun ${d.tahun_usulan||'--'}</span>`;
    return `<div style="padding:10px 0;${i>0?'border-top:1px solid #f3f4f6':''}">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;">
        <div style="flex:1;">
          <div style="display:flex;align-items:center;flex-wrap:wrap;gap:4px;">
            <span style="font-weight:600;font-size:0.88rem;">${label}</span>
            ${tahunInfo}
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
  };

  const dokHoHtml   = dokHo.length   ? dokHo.map((d,i)   => makeDokRow(d,i,null,null,true)).join('')  : '<p class="text-muted small mb-0">Belum ada dokumen HO.</p>';
  const dokUslHtml  = dokUsl.length  ? dokUsl.map((d,i)  => makeDokRow(d,i,null,null,false)).join('') : '<p class="text-muted small mb-0">Belum ada dokumen usulan.</p>';
  const dokPendHtml = dokPend.length ? dokPend.map((d,i) => {
    const url = `?action=view_dok_ho&id_dok=${d.id_dokumen}`;
    const pid = `dp-${d.id_dokumen}`;
    const label = d.deskripsi_dokumen || 'Dokumen Pendukung';
    const tahunInfo = `<span style="color:#9ca3af;font-size:.75rem;">Tahun ${d.tahun_dokumen||'--'}</span>`;
    return `<div style="padding:10px 0;${i>0?'border-top:1px solid #f3f4f6':''}">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;">
        <div style="flex:1;">
          <div style="display:flex;align-items:center;flex-wrap:wrap;gap:4px;">
            <span style="font-weight:600;font-size:0.88rem;">${label}</span>
            ${tahunInfo}
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
  }).join('') : '<p class="text-muted small mb-0">Belum ada dokumen pendukung.</p>';

  const totalDokumen = (parseInt(p.jml_dok_ho || 0, 10) + parseInt(p.jml_dok_usulan || 0, 10));
  const umurAsetBadge = (() => {
    const raw = p.umur_ekonomis;
    if (raw === null || raw === undefined || raw === '') return '&mdash;';
    const months = Number(raw);
    if (!Number.isFinite(months)) return '&mdash;';
    // 0 bulan tetap dianggap di bawah 5 tahun (bukan —)
    if (months < 60) {
      return `<span style="font-weight:600;">Di bawah 5 thn</span>`;
    } else {
      return `<span style="font-weight:600;">Sampai dengan 5 thn</span>`;
    }
  })();
  const mekanismeBadge = p.mekanisme_penghapusan === 'Hapus Administrasi' 
  ? '<span class="badge-pill" style="background:#ffedd5;color:#c2410c;">Hapus Administrasi</span>' 
  : p.mekanisme_penghapusan === 'Jual Lelang' 
  ? '<span class="badge-pill" style="background:#e0f2fe;color:#0369a1;">Jual Lelang</span>' : (p.mekanisme_penghapusan || '&mdash;');
  const fotoHtml = p.foto_path
    ? `<div class="text-center py-3" style="background:#f8f9fa;border-bottom:1px solid #f0f0f0;">
         <img src="${p.foto_path}" class="foto-aset-img img-fluid"
              onclick="bukaLightbox('${p.foto_path}')"
              title="Klik untuk perbesar">
         <div class="mt-1" style="font-size:0.72rem;color:#9ca3af;">Klik foto untuk memperbesar</div>
       </div>`
    : '';
  document.getElementById('modalSubtitle').textContent = p.nama_aset || p.nomor_asset_utama;
  document.getElementById('modalDetailBody').innerHTML = `
    ${fotoHtml}
    <div class="detail-section"><div class="detail-section-title"><i class="bi bi-tag"></i> Identitas Aset</div>
      <div class="detail-grid">
        <div class="detail-item"><div class="detail-item-label">Nomor Aset</div><div class="detail-item-value" style="font-family:monospace;color:#2563eb;">${p.nomor_asset_utama}</div></div>
        <div class="detail-item"><div class="detail-item-label">Nama Aset</div><div class="detail-item-value">${p.nama_aset||'&mdash;'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Kategori Aset</div><div class="detail-item-value">${p.kategori_aset||'&mdash;'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Profit Center</div><div class="detail-item-value">${p.profit_center_text||p.profit_center||'&mdash;'}</div></div>
        <div class="detail-item"><div class="detail-item-label">SubReg</div><div class="detail-item-value">${p.subreg||'&mdash;'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Umur Aset</div><div class="detail-item-value">${umurAsetBadge}</div></div>
      </div>
    </div>
    <div class="detail-section"><div class="detail-section-title"><i class="bi bi-clipboard-data"></i> Detail Usulan</div>
      <div class="detail-grid">
        <div class="detail-item"><div class="detail-item-label">Nilai Buku</div><div class="detail-item-value">${rupiah(p.nilai_buku)}</div></div>
        <div class="detail-item"><div class="detail-item-label">Nilai Perolehan</div><div class="detail-item-value">${rupiah(p.nilai_perolehan_sd)}</div></div>
        <div class="detail-item"><div class="detail-item-label">Tanggal Perolehan</div><div class="detail-item-value">${p.tgl_perolehan||'&mdash;'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Tahun Usulan</div><div class="detail-item-value">${p.tahun_usulan||'&mdash;'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Umur Ekonomis</div><div class="detail-item-value">${p.umur_ekonomis ? p.umur_ekonomis + ' bulan' : '&mdash;'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Sisa Umur Ekonomis</div><div class="detail-item-value">${p.sisa_umur_ekonomis ? p.sisa_umur_ekonomis + ' bulan' : '&mdash;'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Jumlah Aset</div><div class="detail-item-value">${p.jumlah_aset||'&mdash;'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Mekanisme Penghapusan</div><div class="detail-item-value">${mekanismeBadge}</div></div>
       <div class="detail-item"><div class="detail-item-label">Fisik Aset</div><div class="detail-item-value">${p.fisik_aset ? `<div style="font-size:.78rem;font-weight:600;">${p.fisik_aset}</div>` : '&mdash;'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Jumlah Dokumen</div><div class="detail-item-value"><span class="badge-pill" style="background:#0ea5e9;color:#fff;">${totalDokumen} file(s)</span></div></div>
      </div>
    </div>
    <div class="detail-section"><div class="detail-section-title"><i class="bi bi-journal-text"></i> Kajian & Justifikasi</div>
      <div style="padding:4px 0;">
        ${kajian('Justifikasi & Alasan Penghapusan', p.justifikasi_alasan)}
        ${kajian('Kajian Hukum', p.kajian_hukum)}
        ${kajian('Kajian Ekonomis', p.kajian_ekonomis)}
        ${kajian('Kajian Risiko', p.kajian_risiko)}
      </div>
    </div>
    <div class="detail-section" style="background:#f8faff;"><div class="detail-section-title"><i class="bi bi-arrow-right-circle"></i> Progres Pelaksanaan</div>
      <div class="status-track">${trackHtml}</div>
    </div>
    <div class="detail-section"><div class="detail-section-title"><i class="bi bi-calendar3"></i> Tanggal</div>
      <div class="detail-grid">
        <div class="detail-item"><div class="detail-item-label">Tgl. Persetujuan HO</div><div class="detail-item-value">${p.tanggal_persetujuan||'&mdash;'}</div></div>
        ${!isHapus ? `
        <div class="detail-item"><div class="detail-item-label">Tgl. Appraisal</div><div class="detail-item-value">${(p.tanggal_appraisal && p.tanggal_appraisal !== '0000-00-00') ? p.tanggal_appraisal : '&mdash;'}</div></div>
        <div class="detail-item"><div class="detail-item-label">Tgl. Penjualan</div><div class="detail-item-value">${(p.tanggal_penjualan && p.tanggal_penjualan !== '0000-00-00') ? p.tanggal_penjualan : '&mdash;'}</div></div>
        ` : ''}
        <div class="detail-item"><div class="detail-item-label">Nomor Aset Pengganti</div><div class="detail-item-value">${p.nomor_aset_pengganti||'&mdash;'}</div></div>
      </div>
    </div>
    <div class="detail-section"><div class="detail-section-title"><i class="bi bi-cash-stack"></i> Nilai</div>
      <div class="nilai-grid">
        <div class="nilai-box"><div class="nilai-box-label">Nilai Buku Awal</div><div class="nilai-box-value">${rupiah(p.nilai_buku_awal)}</div></div>
        <div class="nilai-box"><div class="nilai-box-label">Nilai Buku Bulan Berjalan</div><div class="nilai-box-value">${rupiah(p.nilai_buku_bulan_berjalan)}</div></div>
        ${!isHapus ? `
        <div class="nilai-box"><div class="nilai-box-label">Nilai Appraisal Pasar</div><div class="nilai-box-value highlight">${rupiah(p.nilai_appraisal_pasar)}</div></div>
        <div class="nilai-box"><div class="nilai-box-label">Nilai Appraisal Likuidasi</div><div class="nilai-box-value">${rupiah(p.nilai_appraisal_likuidasi)}</div></div>
        <div class="nilai-box"><div class="nilai-box-label">Nilai Penjualan</div><div class="nilai-box-value highlight">${rupiah(p.nilai_penjualan)}</div></div>
        <div class="nilai-box"><div class="nilai-box-label">Biaya Lainnya</div><div class="nilai-box-value">${rupiah(p.biaya_lainnya)}</div></div>
        ` : ''}
      </div>
    </div>
    <div class="detail-section"><div class="detail-section-title"><i class="bi bi-file-earmark-pdf"></i> Dokumen Usulan</div><div style="padding:0 4px;">${dokUslHtml}</div></div>
    <div class="detail-section"><div class="detail-section-title"><i class="bi bi-patch-check"></i> Dokumen Persetujuan HO</div><div style="padding:0 4px;">${dokHoHtml}</div></div>
    <div class="detail-section"><div class="detail-section-title"><i class="bi bi-file-earmark-check"></i> Dokumen Pelaksanaan</div><div style="padding:0 4px;">${dokPendHtml}</div></div>`;

  // Simpan id aktif agar tombol Edit di footer modal bisa pakai
  window._currentDetailId = id;
  new bootstrap.Modal(document.getElementById('modalDetail')).show();
}

// Tombol "Edit Data" di footer modal detail
document.getElementById('btnEditFromDetail')?.addEventListener('click', function() {
  if (window._currentDetailId) openEdit(window._currentDetailId);
});

// ── Tab switching (modal edit — only tab-data now) ─────────────────────────
function switchEditTab(tabId, btn) {
  document.querySelectorAll('.edit-tab-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.edit-tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById(tabId).classList.add('active');
  if (btn) btn.classList.add('active');
}

// ── Upload: file select (modal — no longer used, kept for safety) ──────────
function handleFileSelect(input) {}

// ── Update status badge ────────────────────────────────────────────────────
function updateStatusBadge(val) {
  const badge = document.getElementById('edit_status_badge');
  if (!badge) return;
  const normVal = normalizeStatus(val);
  badge.className = 'badge-pill';
  const map = {
    'Disetujui':         'st-disetujui',
    'Appraisal Aset':    'st-appraisal',
    'Proses Lelang':     'st-lelang',
    'Terjual':           'st-terjual',
    'Hapus Administrasi':'st-musnahkan',
    'Ditolak':           'st-ditolak',
  };
  badge.classList.add(map[normVal] || 'st-disetujui');
  badge.textContent = normVal || val;

}

// ── Apply mekanisme: show/hide fields ─────────────────────────────────────
function applyMekanisme(mekanisme) {
  const isHapus  = (mekanisme === 'Hapus Administrasi');
  const selLelang = document.getElementById('edit_status_lelang');
  const selHapus  = document.getElementById('edit_status_hapus');
  const seksiApp  = document.getElementById('seksi_appraisal');
  const seksiJual = document.getElementById('seksi_penjualan');
  const infoEl    = document.getElementById('edit_mekanisme_info');

  if (selLelang) { selLelang.style.display = isHapus ? 'none' : ''; selLelang.disabled = isHapus; }
  if (selHapus)  { selHapus.style.display  = isHapus ? '' : 'none'; selHapus.disabled  = !isHapus; }
  if (seksiApp)  seksiApp.style.display  = isHapus ? 'none' : '';
  if (seksiJual) seksiJual.style.display = isHapus ? 'none' : '';

  if (infoEl) {
    infoEl.innerHTML = isHapus
      ? '<span class="badge-pill" style="background:#ffedd5;color:#c2410c;"><i class="bi bi-trash3 me-1"></i>Hapus Administrasi</span>'
      : '<span class="badge-pill" style="background:#dbeafe;color:#1d4ed8;"><i class="bi bi-hammer me-1"></i>Jual Lelang</span>';
  }
}

// ── Open edit modal ────────────────────────────────────────────────────────
function openEdit(id) {
  const p = dataPelaksanaan.find(x => x.id == id);
  if (!p) return;

  // Kembali ke tab pertama
  switchEditTab('tab-data', document.querySelector('.edit-tab-btn'));

  const fixTgl = v => (v && v !== '0000-00-00') ? v : '';
  const toInt  = v => (v !== null && v !== '' && v !== undefined) ? Math.round(parseFloat(v)) : '';

  document.getElementById('edit_id').value             = p.id;
  document.getElementById('edit_mekanisme').value      = p.mekanisme_penghapusan || '';
  document.getElementById('edit_tgl_appraisal').value  = fixTgl(p.tanggal_appraisal);
  document.getElementById('edit_tgl_penjualan').value  = fixTgl(p.tanggal_penjualan);
  document.getElementById('edit_app_pasar').value      = toInt(p.nilai_appraisal_pasar);
  document.getElementById('edit_app_likuidasi').value  = toInt(p.nilai_appraisal_likuidasi);
  document.getElementById('edit_nilai_jual').value     = toInt(p.nilai_penjualan);
  document.getElementById('edit_biaya').value          = toInt(p.biaya_lainnya);
  document.getElementById('edit_aset_pengganti').value = p.nomor_aset_pengganti || '';
  document.getElementById('editSubtitle').textContent  = p.nomor_asset_utama + ' \u2014 ' + (p.nama_aset || '');

  const nbAset = p.nilai_buku ? parseFloat(p.nilai_buku).toLocaleString('id-ID', {minimumFractionDigits:0}) : '';
  document.getElementById('edit_nilai_buku_display').value = nbAset;
  const nbSdRaw = p.nilai_buku_awal || p.nilai_buku_bulan_berjalan;
  const nbSd    = nbSdRaw ? parseFloat(nbSdRaw).toLocaleString('id-ID', {minimumFractionDigits:0}) : '';
  document.getElementById('edit_nb_bb_display').value = nbSd;
  document.getElementById('edit_nb_bb').value         = toInt(p.nilai_buku_awal || p.nilai_buku_bulan_berjalan);

  const mek = p.mekanisme_penghapusan || 'Jual Lelang';
  applyMekanisme(mek);

  let curStatus = normalizeStatus(p.status_pelaksanaan || 'Disetujui');
  // Data lama mungkin masih "Telah Dimusnahkan" → paksa ke "Hapus Administrasi"
  if (mek === 'Hapus Administrasi' && (curStatus === 'Telah Dimusnahkan' || curStatus === 'Telah dimusnahkan')) {
    curStatus = 'Hapus Administrasi';
  }
  if (mek === 'Hapus Administrasi') {
    const selH = document.getElementById('edit_status_hapus');
    if (selH) selH.value = curStatus;
  } else {
    const selL = document.getElementById('edit_status_lelang');
    if (selL) selL.value = curStatus;
  }
  updateStatusBadge(curStatus);

  const md = bootstrap.Modal.getInstance(document.getElementById('modalDetail'));
  if (md) { md.hide(); setTimeout(() => new bootstrap.Modal(document.getElementById('modalEdit')).show(), 250); }
  else { new bootstrap.Modal(document.getElementById('modalEdit')).show(); }
}

document.getElementById('formEditPelaksanaan')?.addEventListener('submit', function(e) {
  const mek = document.getElementById('edit_mekanisme')?.value || '';
  if (mek === 'Hapus Administrasi') {
    // Kosongkan field jual lelang agar tidak terkirim
    ['edit_app_pasar','edit_app_likuidasi','edit_tgl_appraisal','edit_nilai_jual','edit_biaya','edit_tgl_penjualan'].forEach(id => {
      const el = document.getElementById(id);
      if (el) { el.removeAttribute('disabled'); el.value = ''; }
    });
  } else {
    // Pastikan semua field jual lelang tidak disabled saat submit
    ['edit_app_pasar','edit_app_likuidasi','edit_tgl_appraisal','edit_nilai_jual','edit_biaya','edit_tgl_penjualan'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.removeAttribute('disabled');
    });
  }
});

function togglePrev(pid, url) {
  const el    = document.getElementById(pid);
  const frame = document.getElementById(pid + '-frame');
  if (!el) return;
  const isVisible = el.style.display !== 'none' && el.style.display !== '';
  if (isVisible || url === null) {
    el.style.display = 'none';
    if (frame) frame.src = '';
  } else {
    if (frame && url) frame.src = url;
    el.style.display = 'block';
    setTimeout(function() { el.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }, 150);
  }
}

function updateTabUrl(name) {
  const url = new URL(window.location);
  url.searchParams.set('tab', name);
  history.replaceState({}, '', url);
}

// ── Upload tab: checkbox multi-select aset ─────────────────────────────────
function openPelAsetPickerModal() {
  new bootstrap.Modal(document.getElementById('modalPelAsetPicker')).show();
}

// Select all checkbox in picker
document.addEventListener('DOMContentLoaded', function() {
  document.getElementById('selectAllPelPicker')?.addEventListener('change', function() {
    document.querySelectorAll('.pel-picker-check:not(:disabled)').forEach(c => c.checked = this.checked);
    updatePelPickerCount();
  });

  document.querySelectorAll('.pel-picker-check').forEach(c => {
    c.addEventListener('change', updatePelPickerCount);
  });

  document.getElementById('btnConfirmPelPicker')?.addEventListener('click', function() {
    const checked = [...document.querySelectorAll('.pel-picker-check:not(:disabled):checked')];
    if (!checked.length) { alert('Pilih minimal 1 aset!'); return; }

    const ids    = checked.map(c => c.value);           // id pelaksanaan
    const nomors = checked.map(c => c.dataset.nomor);   // nomor aset

    const elId    = document.getElementById('upload_pel_id_pelaksanaan');
    const elNomor = document.getElementById('upload_pel_nomor_aset');
    const elDisplay = document.getElementById('upload_pel_nomor_aset_display');
    const elList    = document.getElementById('upload_pel_selected_list');

    if (elId)      elId.value      = ids[0];
    if (elNomor)   elNomor.value   = nomors.join('; ');
    if (elDisplay) elDisplay.value = nomors.length === 1 ? nomors[0] : nomors.length + ' aset dipilih';

    if (elList) {
      elList.style.display = 'block';
      elList.innerHTML = '<strong>Dipilih (' + nomors.length + '):</strong> ' + nomors.join(', ');
    }

    const modalEl = document.getElementById('modalPelAsetPicker');
    const modalInst = bootstrap.Modal.getInstance(modalEl);
    if (modalInst) modalInst.hide();

    checkUploadPelReady();
  });
});

function updatePelPickerCount() {
  const n   = document.querySelectorAll('.pel-picker-check:not(:disabled):checked').length;
  const all = document.querySelectorAll('.pel-picker-check:not(:disabled)').length;
  document.getElementById('pelPickerCountNum').textContent = n;
  document.getElementById('pelPickerSelectedCount').style.display = n ? 'flex' : 'none';
  document.getElementById('selectAllPelPicker').checked = n > 0 && n === all;
}

function handlePelFileSelect(input) {
  const label = document.getElementById('upload_pel_file_label');
  if (input.files && input.files[0]) {
    label.textContent = input.files[0].name;
    label.style.color = '#1d4ed8';
  } else {
    label.textContent = 'Maks. 50MB · Format PDF';
    label.style.color = '#9ca3af';
  }
  checkUploadPelReady();
}

function checkUploadPelReady() {
  const hasAset = !!document.getElementById('upload_pel_id_pelaksanaan').value;
  const hasFile = document.getElementById('upload_pel_file').files.length > 0;
  document.getElementById('btnUploadPel').disabled = !(hasAset && hasFile);
}

// ── Upload tab: preview dokumen ────────────────────────────────────────────
let _prevPelActive = null;
function togglePelPreview(pid, url) {
  const panel = document.getElementById('previewPanelPel');
  const frame = document.getElementById('previewPanelPelFrame');
  const label = document.getElementById('previewPanelPelLabel');
  const buka  = document.getElementById('previewPanelPelBuka');
  if (!panel) return;
  if (_prevPelActive === pid && panel.style.display !== 'none') { tutupPreviewPel(); return; }
  _prevPelActive = pid;
  if (frame) frame.src = url;
  if (label) label.textContent = pid.replace('pelp-','Dokumen #');
  if (buka)  buka.href = url;
  panel.style.display = 'block';
  setTimeout(function() { panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }, 150);
}
function tutupPreviewPel() {
  const panel = document.getElementById('previewPanelPel');
  const frame = document.getElementById('previewPanelPelFrame');
  if (panel) panel.style.display = 'none';
  if (frame) frame.src = '';
  _prevPelActive = null;
}


function showDetailAsetPel(nomorAset) {
  const tbody   = document.getElementById('detailAsetPelTbody');
  const pcEl    = document.getElementById('detailAsetPelPC');
  const subEl   = document.getElementById('detailAsetPelSubreg');
  const totalEl = document.getElementById('detailAsetPelTotal');
  tbody.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-muted">Memuat data...</td></tr>';
  pcEl.textContent = '-'; subEl.textContent = '-'; totalEl.textContent = '';
  new bootstrap.Modal(document.getElementById('modalDetailAsetPel')).show();
  fetch(location.pathname + '?action=get_detail_aset_pel&no_aset=' + encodeURIComponent(nomorAset), {credentials:'same-origin'})
    .then(r => r.json())
    .then(json => {
      if (json.status === 'success' && json.data && json.data.length > 0) {
        const row = json.data[0];
        pcEl.textContent  = row.profit_center || '-';
        subEl.textContent = row.subreg || '-';
        totalEl.textContent = 'Total: ' + json.data.length + ' aset dimuat';
        tbody.innerHTML = '';
        json.data.forEach(function(r, i) {
          const mekVal = r.mekanisme_penghapusan || '';
          let mekBadge = '-';
          if (mekVal) {
            const mekStyle = mekVal === 'Hapus Administrasi' ? 'background:#ffedd5;color:#c2410c;' 
            : mekVal === 'Jual Lelang' ? 'background:#e0f2fe;color:#0369a1;' : 'background:#f3f4f6;color:#6b7280;';
            mekBadge = '<span class="badge" style="' + mekStyle + '">' + mekVal + '</span>';
          }
          const stVal = r.status_penghapusan || '';
          let stBadge = '-';
          if (stVal) {
            const stStyle = (stVal === 'Approved' || stVal.includes('Approved')) ? 'background:#28a745;color:#fff;'
              : stVal === 'Submitted' ? 'background:#17a2b8;color:#fff;'
              : stVal === 'Rejected' ? 'background:#dc3545;color:#fff;'
              : 'background:#6c757d;color:#fff;';
            stBadge = '<span class="badge" style="' + stStyle + '">' + stVal + '</span>';
          }
          const namaLink = '<a href="#" style="color:#0d6efd;text-decoration:none;">' + (r.keterangan_asset || '-') + '</a>';
          tbody.innerHTML += '<tr style="background:' + (i%2===0?'#f8f9fa':'#fff') + '">'
            + '<td class="text-center">' + (i+1) + '</td>'
            + '<td><code style="color:#2563eb;font-size:.82rem;">' + (r.nomor_asset_utama||'-') + '</code></td>'
            + '<td>' + namaLink + '</td>'
            + '<td class="text-center">' + mekBadge + '</td>'
            + '<td class="text-center">' + stBadge + '</td>'
            + '</tr>';
        });
      } else {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-muted">Data aset tidak ditemukan.</td></tr>';
      }
    })
    .catch(() => { tbody.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-danger">Gagal memuat data.</td></tr>'; });
}
function confirmHapusDokPendukung(id, nama) {
  document.getElementById('hapusDokPendukungId').value   = id;
  document.getElementById('hapusDokPendukungNama').textContent = nama || 'Dokumen #' + id;
  new bootstrap.Modal(document.getElementById('modalHapusDokPendukung')).show();
}
</script>
<script src="../../dist/js/overlayscrollbars.browser.es6.min.js"></script>
<script src="../../dist/js/popper.min.js"></script>
<script src="../../dist/js/adminlte.js"></script>

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
  document.getElementById('lightboxImg').src = src;
  document.getElementById('lightboxOverlay').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function tutupLightbox() {
  document.getElementById('lightboxOverlay').style.display = 'none';
  document.getElementById('lightboxImg').src = '';
  document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') tutupLightbox();
});
</script>
</body>
</html>