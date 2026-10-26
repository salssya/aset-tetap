<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "asetreg3_db";

$con = mysqli_connect($servername, $username, $password, $dbname);
session_start();
if (!isset($_SESSION["nipp"]) || !isset($_SESSION["name"])) {
    header("Location: ../login/login_view.php");
    exit();
}

// =============================================================================
// KK F01 -- penyimpanan snapshot bulanan + keterangan/OK (tabel dibuat otomatis)
// =============================================================================
// kk_snapshot   : hasil perhitungan tiap baris per periode (tahun+bulan) DIBEKUKAN di sini,
//                 jadi saat data bulan baru di-upload, angka bulan lama tetap bisa dibuka.
// kk_keterangan : teks keterangan + status OK per baris per periode.
$KK_HAL = 'ATB';
mysqli_query($con, "CREATE TABLE IF NOT EXISTS kk_snapshot (
    id INT AUTO_INCREMENT PRIMARY KEY,
    halaman VARCHAR(10) NOT NULL,
    periode_tahun SMALLINT NOT NULL,
    periode_bulan TINYINT NOT NULL,
    seksi VARCHAR(10) NOT NULL,
    kode_gl VARCHAR(30) NOT NULL,
    nama_id VARCHAR(255) NULL,
    awal DECIMAL(24,2) NULL,
    penambahan DECIMAL(24,2) NULL,
    pengurangan DECIMAL(24,2) NULL,
    reklasifikasi DECIMAL(24,2) NULL,
    akhir DECIMAL(24,2) NULL,
    f01 DECIMAL(24,2) NULL,
    selisih_f01 DECIMAL(24,2) NULL,
    saved_by VARCHAR(50) NULL,
    saved_at DATETIME NULL,
    UNIQUE KEY uq_snap (halaman, periode_tahun, periode_bulan, seksi, kode_gl)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($con, "CREATE TABLE IF NOT EXISTS kk_keterangan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    halaman VARCHAR(10) NOT NULL,
    periode_tahun SMALLINT NOT NULL,
    periode_bulan TINYINT NOT NULL,
    seksi VARCHAR(10) NOT NULL,
    kode_gl VARCHAR(30) NOT NULL,
    keterangan TEXT NULL,
    status_ok TINYINT(1) NOT NULL DEFAULT 0,
    updated_by VARCHAR(50) NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_ket (halaman, periode_tahun, periode_bulan, seksi, kode_gl)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Endpoint AJAX: simpan keterangan / OK satu baris ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'simpan_ket') {
    header('Content-Type: application/json; charset=utf-8');
    $kTh = (int)($_POST['tahun'] ?? 0);
    $kBl = (int)($_POST['bulan'] ?? 0);
    $kSeksi = $_POST['seksi'] ?? '';
    $kKode = trim($_POST['kode'] ?? '');
    $kKet = mb_substr(trim($_POST['ket'] ?? ''), 0, 2000);
    $kOk = !empty($_POST['ok']) ? 1 : 0;
    if ($kTh < 2000 || $kBl < 1 || $kBl > 12 || !in_array($kSeksi, ['HP', 'AP', 'CKPN'], true)
        || !preg_match('/^[0-9A-Za-z_\-]{1,30}$/', $kKode)) {
        echo json_encode(['ok' => false, 'pesan' => 'Data tidak valid']);
        exit();
    }
    $by = (string)$_SESSION['nipp'];
    $st = mysqli_prepare($con, "INSERT INTO kk_keterangan (halaman, periode_tahun, periode_bulan, seksi, kode_gl, keterangan, status_ok, updated_by, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE keterangan = VALUES(keterangan), status_ok = VALUES(status_ok), updated_by = VALUES(updated_by), updated_at = NOW()");
    mysqli_stmt_bind_param($st, 'siisssis', $KK_HAL, $kTh, $kBl, $kSeksi, $kKode, $kKet, $kOk, $by);
    $okSimpan = mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
    echo json_encode(['ok' => (bool)$okSimpan, 'pesan' => $okSimpan ? 'Tersimpan' : 'Gagal menyimpan']);
    exit();
}

// ── Endpoint AJAX: batal simpan = hapus keterangan + status OK satu baris dari DB ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'hapus_ket') {
    header('Content-Type: application/json; charset=utf-8');
    $kTh = (int)($_POST['tahun'] ?? 0);
    $kBl = (int)($_POST['bulan'] ?? 0);
    $kSeksi = $_POST['seksi'] ?? '';
    $kKode = trim($_POST['kode'] ?? '');
    if ($kTh < 2000 || $kBl < 1 || $kBl > 12 || !in_array($kSeksi, ['HP', 'AP', 'CKPN'], true)
        || !preg_match('/^[0-9A-Za-z_\-]{1,30}$/', $kKode)) {
        echo json_encode(['ok' => false, 'pesan' => 'Data tidak valid']);
        exit();
    }
    $st = mysqli_prepare($con, "DELETE FROM kk_keterangan WHERE halaman = ? AND periode_tahun = ? AND periode_bulan = ? AND seksi = ? AND kode_gl = ?");
    mysqli_stmt_bind_param($st, 'siiss', $KK_HAL, $kTh, $kBl, $kSeksi, $kKode);
    $okHapus = mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
    echo json_encode(['ok' => (bool)$okHapus, 'pesan' => $okHapus ? 'Dibatalkan' : 'Gagal membatalkan']);
    exit();
}

function kkKey($seksi, $kode) { return $seksi . '|' . $kode; }

function kkAdaF01Periode($con, $bulan, $tahun) {
    $r = mysqli_query($con, "SHOW TABLES LIKE 'import_data_f01'");
    if (!$r || mysqli_num_rows($r) === 0) return false;
    $r = mysqli_query($con, "SELECT COUNT(*) c FROM import_data_f01 WHERE periode_bulan = '" . (int)$bulan . "' AND periode_tahun = '" . (int)$tahun . "'");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return $row && (int)$row['c'] > 0;
}

function kkSumF01($con, $kode, $bulan, $tahun) {
    if ($kode === null || $kode === '') return 0.0;
    $expr = numExprRobust('total_reporting_period');
    $accEsc = mysqli_real_escape_string($con, normAkun($kode));
    $r = mysqli_query($con, "SELECT SUM($expr) AS total FROM import_data_f01
        WHERE TRIM(LEADING '0' FROM TRIM(account_number)) = '$accEsc' AND periode_bulan = '" . (int)$bulan . "' AND periode_tahun = '" . (int)$tahun . "'");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return ($row && $row['total'] !== null) ? (float)$row['total'] : 0.0;
}

// HP dibandingkan apa adanya; AP & CKPN dibandingkan nilai absolutnya (tanda +/- antar sumber bisa beda).
function kkHitungSelisih($seksi, $akhir, $f01) {
    return ($seksi === 'HP') ? ((float)$akhir - (float)$f01) : (abs((float)$akhir) - abs((float)$f01));
}

function kkAmbilSnapshot($con, $hal, $th, $bl) {
    $map = []; $savedAt = null;
    $st = mysqli_prepare($con, "SELECT seksi, kode_gl, awal, penambahan, pengurangan, reklasifikasi, akhir, f01, saved_at
        FROM kk_snapshot WHERE halaman = ? AND periode_tahun = ? AND periode_bulan = ?");
    mysqli_stmt_bind_param($st, 'sii', $hal, $th, $bl);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($r = mysqli_fetch_assoc($res)) {
        $map[kkKey($r['seksi'], $r['kode_gl'])] = $r;
        if ($savedAt === null || $r['saved_at'] > $savedAt) $savedAt = $r['saved_at'];
    }
    mysqli_stmt_close($st);
    return [$map, $savedAt];
}

function kkTerapkanSnapshot(array &$b, $seksi, $kode, array $snapMap) {
    $k = kkKey($seksi, $kode);
    if (!isset($snapMap[$k])) return;
    $s = $snapMap[$k];
    foreach (['awal', 'penambahan', 'pengurangan', 'reklasifikasi', 'akhir'] as $f) {
        $b[$f] = (float)$s[$f];
    }
    if ($s['f01'] !== null) $b['f01'] = (float)$s['f01'];   // kalau F01 saat snapshot belum ada, pakai hasil live
}

function kkSimpanSnapshot($con, $hal, $th, $bl, array $sections) {
    $by = (string)$_SESSION['nipp'];
    mysqli_begin_transaction($con);
    try {
        $del = mysqli_prepare($con, "DELETE FROM kk_snapshot WHERE halaman = ? AND periode_tahun = ? AND periode_bulan = ?");
        mysqli_stmt_bind_param($del, 'sii', $hal, $th, $bl);
        mysqli_stmt_execute($del);
        mysqli_stmt_close($del);
        $ins = mysqli_prepare($con, "INSERT INTO kk_snapshot
            (halaman, periode_tahun, periode_bulan, seksi, kode_gl, nama_id, awal, penambahan, pengurangan, reklasifikasi, akhir, f01, selisih_f01, saved_by, saved_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        foreach ($sections as $seksi => $sec) {
            foreach ($sec['rows'] as $r) {
                $kode = (string)$r['kode']; $nama = (string)$r['nama'];
                $aw = (float)$r['awal']; $pe = (float)$r['penambahan']; $pg = (float)$r['pengurangan'];
                $rk = (float)$r['reklasifikasi']; $ak = (float)$r['akhir'];
                $f01 = $GLOBALS['kkF01Ada'] ? (float)$r['f01'] : null;
                $sel = $GLOBALS['kkF01Ada'] ? (float)$r['sel_f01'] : null;
                mysqli_stmt_bind_param($ins, 'siisssdddddddds', $hal, $th, $bl, $seksi, $kode, $nama, $aw, $pe, $pg, $rk, $ak, $f01, $sel, $by);
                mysqli_stmt_execute($ins);
            }
        }
        mysqli_stmt_close($ins);
        mysqli_commit($con);
        return true;
    } catch (Throwable $e) {
        mysqli_rollback($con);
        return false;
    }
}

function kkAmbilKet($con, $hal, $th, $bl) {
    $map = [];
    $st = mysqli_prepare($con, "SELECT seksi, kode_gl, keterangan, status_ok FROM kk_keterangan WHERE halaman = ? AND periode_tahun = ? AND periode_bulan = ?");
    mysqli_stmt_bind_param($st, 'sii', $hal, $th, $bl);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($r = mysqli_fetch_assoc($res)) { $map[kkKey($r['seksi'], $r['kode_gl'])] = $r; }
    mysqli_stmt_close($st);
    return $map;
}

function kkFmtSel($v, $ada) {
    if (!$ada) return '-';
    if (abs((float)$v) < 0.5) return '<span class="text-success">-</span>';
    $abs = number_format(abs((float)$v), 0, ',', '.');
    return '<span class="text-danger fw-semibold">' . (((float)$v < 0) ? "- $abs" : $abs) . '</span>';
}

// 4 sel di sisi kanan tiap baris: F01 | Selisih | Keterangan | Simpan + OK
function kkCells($seksi, $kode, $f01, $sel) {
    global $kkF01Ada, $kkKetMap;
    $k = kkKey($seksi, $kode);
    $ket = $kkKetMap[$k]['keterangan'] ?? '';
    $ok = !empty($kkKetMap[$k]['status_ok']);
    $f01Txt = $kkF01Ada ? fmtRp($f01) : '-';
    $h  = '<td class="angka kk-f01">' . $f01Txt . '</td>';
    $h .= '<td class="angka">' . kkFmtSel($sel, $kkF01Ada) . '</td>';
    $h .= '<td class="kk-ket-cell"><div class="input-group input-group-sm flex-nowrap">'
        . '<input type="text" class="form-control kk-ket" maxlength="2000" placeholder="Tulis keterangan..." value="' . htmlspecialchars($ket) . '" data-seksi="' . $seksi . '" data-kode="' . htmlspecialchars($kode) . '" data-ok="' . ($ok ? '1' : '0') . '">'
        . '<button type="button" class="btn ' . ($ok ? 'btn-success' : 'btn-primary') . ' kk-ok-btn" title="Simpan keterangan">' . ($ok ? '<i class="bi bi-check-lg"></i> OK' : 'OK') . '</button>'
        . '<button type="button" class="btn btn-outline-danger kk-batal-btn' . ($ok ? '' : ' d-none') . '" title="Batal simpan (keterangan dihapus)"><i class="bi bi-x-lg"></i></button>'
        . '</div></td>';
    return $h;
}
function kkCellsTotal($f01, $sel) {
    global $kkF01Ada;
    return '<td class="angka">' . ($kkF01Ada ? fmtRp($f01) : '-') . '</td><td class="angka">' . kkFmtSel($sel, $kkF01Ada) . '</td><td></td>';
}

$ATB_ITEMS = [
    [
        'type'         => 'item',
        'kode'         => '5040301000',
        'kode_hp'      => '1206010100',
        'kode_ap'      => '1206010101',
        'kode_ckpn'    => '1206010102',
        'nama_id'      => 'Goodwill',
        'nama_en'      => 'Goodwill',
        'nama_ckpn_id' => 'CKPN - Goodwill',
        'nama_ckpn_en' => 'Goodwill Impairment',
        'in_ckpn'      => true,
    ],
    [
        'type'         => 'item',
        'kode'         => '5040302000',
        'kode_hp'      => '1206020100',
        'kode_ap'      => '1206020101',
        'kode_ckpn'    => '1206020102',
        'nama_id'      => 'Lisensi',
        'nama_en'      => 'Licenses',
        'nama_ckpn_id' => 'CKPN - Lisensi',
        'nama_ckpn_en' => 'Licenses Impairment',
        'in_ckpn'      => true,
    ],
    [
        'type'         => 'item',
        'kode'         => '5040303000',
        'kode_hp'      => '1206030100',
        'kode_ap'      => '1206030101',
        'kode_ckpn'    => '1206030102',
        'nama_id'      => 'Hak Paten',
        'nama_en'      => 'Patents',
        'nama_ckpn_id' => 'CKPN - Hak Paten',
        'nama_ckpn_en' => 'Patents Impairment',
        'in_ckpn'      => true,
    ],
    [
        'type'         => 'item',
        'kode'         => '5040304000',
        'kode_hp'      => '1206040100',
        'kode_ap'      => '1206040101',
        'kode_ckpn'    => '1206040102',
        'nama_id'      => 'Pengembangan Piranti Lunak',
        'nama_en'      => 'Software Development',
        'nama_ckpn_id' => 'CKPN - Pengembangan Piranti',
        'nama_ckpn_en' => 'Software Development Impairment',
        'in_ckpn'      => true,
    ],

    [
        'type'         => 'header',
        'kode_hp'      => '1206050100',
        'kode_ap'      => '1206050001',
        'kode_ckpn'    => '1206050002',
        'nama_id'      => 'Aset Konsesi',
        'nama_en'      => 'Concession Assets',
        'nama_ckpn_id' => 'CKPN - Aset konsesi',
        'nama_ckpn_en' => 'Concession Assets Impairment',
    ],

    [
        'type'         => 'item',
        'kode'         => '5040601010',
        'kode_hp'      => '1206050110',
        'kode_ap'      => '1206050111',
        'kode_ckpn'    => '1206050112',
        'nama_id'      => 'Aset Konsesi - Tanah',
        'nama_en'      => 'Concession Assets - Land',
        'nama_ckpn_id' => 'CKPN Aset Konsesi Tanah',
        'nama_ckpn_en' => 'Concession Assets - Land Impairment',
        'in_ckpn'      => true,
    ],
    [
        'type'         => 'item',
        'kode'         => '5040601030',
        'kode_hp'      => '1206050130',
        'kode_ap'      => '1206050131',
        'kode_ckpn'    => '1206050132',
        'nama_id'      => 'Aset Konsesi - Kapal',
        'nama_en'      => 'Concession Assets - Vessels',
        'nama_ckpn_id' => 'CKPN Aset Konsesi Kapal',
        'nama_ckpn_en' => 'Concession Assets - Vessels Impairment',
        'in_ckpn'      => true,
    ],
    [
        'type'         => 'item',
        'kode'         => '5040601020',
        'kode_hp'      => '1206050120',
        'kode_ap'      => '1206050121',
        'kode_ckpn'    => '1206050122',
        'nama_id'      => 'Aset Konsesi - Bangunan Fasilitas Pelabuhan',
        'nama_en'      => 'Concession Assets - Port Facilities',
        'nama_ckpn_id' => 'CKPN Aset Konsesi Bangunan',
        'nama_ckpn_en' => 'Concession Assets - Port Facilities Impairment',
        'in_ckpn'      => true,
    ],
    [
        'type'         => 'item',
        'kode'         => '5040601040',
        'kode_hp'      => '1206050140',
        'kode_ap'      => '1206050141',
        'kode_ckpn'    => '1206050142',
        'nama_id'      => 'Aset Konsesi - Alat-Alat Fasilitas',
        'nama_en'      => 'Concession Assets - Port Equipment',
        'nama_ckpn_id' => 'CKPN Aset Konsesi Alat-Alat Fasilitas Pelabuhan',
        'nama_ckpn_en' => 'Concession Assets - Port Equipment Impairment',
        'in_ckpn'      => true,
    ],
    [
        'type'         => 'item',
        'kode'         => '5040601050',
        'kode_hp'      => '1206050150',
        'kode_ap'      => '1206050151',
        'kode_ckpn'    => '1206050152',
        'nama_id'      => 'Aset Konsesi - Instalasi Fasilitas',
        'nama_en'      => 'Concession Assets - Port Facility Installations',
        'nama_ckpn_id' => 'CKPN Aset Konsesi Instalasi Fasilitas',
        'nama_ckpn_en' => 'Concession Assets - Port Facility Installations Impairment',
        'in_ckpn'      => true,
    ],
    [
        'type'         => 'item',
        'kode'         => '5040601060',
        'kode_hp'      => '1206050160',
        'kode_ap'      => '1206050161',
        'kode_ckpn'    => '1206050162',
        'nama_id'      => 'Aset Konsesi - Jalan Dan Bangunan',
        'nama_en'      => 'Concession Assets - Roads and Buildings',
        'nama_ckpn_id' => 'CKPN Aset Konsesi Jalan Dan',
        'nama_ckpn_en' => 'Concession Assets - Roads and Buildings Impairment',
        'in_ckpn'      => true,
    ],
    [
        'type'         => 'item',
        'kode'         => '5040601080',
        'kode_hp'      => '1206050180',
        'kode_ap'      => '1206050181',
        'kode_ckpn'    => '1206050182',
        'nama_id'      => 'Aset Konsesi - Kendaraan',
        'nama_en'      => 'Concession Assets - Vehicles',
        'nama_ckpn_id' => 'CKPN Aset Konsesi Kendaraan',
        'nama_ckpn_en' => 'Concession Assets - Vehicles Impairment',
        'in_ckpn'      => true,
    ],
    [
        'type'         => 'item',
        'kode'         => '5040601090',
        'kode_hp'      => '1206050190',
        'kode_ap'      => '1206050191',
        'kode_ckpn'    => '1206050192',
        'nama_id'      => 'Aset Konsesi - Emplasemen',
        'nama_en'      => 'Concession Assets - Yards',
        'nama_ckpn_id' => 'CKPN Aset Konsesi Emplasemen',
        'nama_ckpn_en' => 'Concession Assets - Yards Impairment',
        'in_ckpn'      => true,
    ],
    [
        'type'         => 'item',
        'kode'         => '5040601070',
        'kode_hp'      => '1206050170',
        'kode_ap'      => '1206050171',
        'kode_ckpn'    => '1206050172',
        'nama_id'      => 'Aset Konsesi - Peralatan',
        'nama_en'      => 'Concession Assets - Equipment',
        'nama_ckpn_id' => 'CKPN Aset Konsesi Peralatan',
        'nama_ckpn_en' => 'Concession Assets - Equipment Impairment',
        'in_ckpn'      => true,
    ],

    [
        'type'    => 'item',
        'kode'    => '5040306010',
        'kode_hp' => '1206060200',
        'kode_ap' => '1206060201',
        'nama_id' => 'Aset Tak Berwujud Lainnya',
        'nama_en' => 'Other Intangible Assets',
        'in_ckpn' => false,
    ],
];

// ==========================  FILTER PERIODE  =============================
$defTahunAwal = date('Y') - 1;
$defTahunAkhir = date('Y');
$defBulanAkhir = (int)date('n');

$resMaxDat = mysqli_query($con, "SHOW TABLES LIKE 'import_dat_monitoring'");
if ($resMaxDat && mysqli_num_rows($resMaxDat) > 0) {
    $r = mysqli_query($con, "SELECT MAX(tahun_buku) as mx FROM import_dat_monitoring WHERE tahun_buku IS NOT NULL AND tahun_buku <> ''");
    if ($r && ($row = mysqli_fetch_assoc($r)) && $row['mx']) { $defTahunAwal = (int)$row['mx']; }
}
$resMaxFagll = mysqli_query($con, "SHOW TABLES LIKE 'import_fagll'");
if ($resMaxFagll && mysqli_num_rows($resMaxFagll) > 0) {
    $r = mysqli_query($con, "SELECT MAX(posting_date_norm) as mx FROM import_fagll WHERE posting_date_norm IS NOT NULL");
    if ($r && ($row = mysqli_fetch_assoc($r)) && $row['mx']) {
        $dtMax = new DateTime($row['mx']);
        $defTahunAkhir = (int)$dtMax->format('Y');
        $defBulanAkhir = (int)$dtMax->format('n');
    }
}

$tahunAwal   = isset($_GET['tahun_awal']) && ctype_digit($_GET['tahun_awal']) ? (int)$_GET['tahun_awal'] : $defTahunAwal;
$tahunAkhir  = isset($_GET['tahun_akhir']) && ctype_digit($_GET['tahun_akhir']) ? (int)$_GET['tahun_akhir'] : $defTahunAkhir;
$bulanAkhir  = isset($_GET['bulan_akhir']) && ctype_digit($_GET['bulan_akhir']) ? (int)$_GET['bulan_akhir'] : $defBulanAkhir;
if ($bulanAkhir < 1) $bulanAkhir = 1;
if ($bulanAkhir > 12) $bulanAkhir = 12;

$namaBulanMap = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
$namaBulanEnMap = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
$labelAwal  = "31 Desember $tahunAwal";
$labelAwalEn = "December 31, $tahunAwal";
$labelAkhirTanggal = date('t', mktime(0, 0, 0, $bulanAkhir, 1, $tahunAkhir));
$labelAkhir = "$labelAkhirTanggal " . $namaBulanMap[$bulanAkhir] . " $tahunAkhir";
$labelAkhirEn = $namaBulanEnMap[$bulanAkhir] . " $labelAkhirTanggal, $tahunAkhir";
// F.01 dilaporkan mundur satu bulan: kolom F01 (pembanding KK) memakai F01 BULAN SEBELUM bulan akhir (Agustus -> F01 Juli).
$f01Bulan = $bulanAkhir - 1; $f01Tahun = $tahunAkhir;
if ($f01Bulan < 1) { $f01Bulan = 12; $f01Tahun = $tahunAkhir - 1; }
$labelF01 = date('t', mktime(0, 0, 0, $f01Bulan, 1, $f01Tahun)) . ' ' . $namaBulanMap[$f01Bulan] . ' ' . $f01Tahun;

// ==========================================================================
// ========================  HELPER SQL & KOMPUTASI  =======================
// ==========================================================================
function numExpr($col) {
    return "CASE WHEN REPLACE(TRIM($col), ',', '') REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' " .
           "THEN CAST(REPLACE(TRIM($col), ',', '') AS DECIMAL(24,2)) ELSE 0 END";
}

function tabelAda($con, $nama) {
    $r = mysqli_query($con, "SHOW TABLES LIKE '" . mysqli_real_escape_string($con, $nama) . "'");
    return $r && mysqli_num_rows($r) > 0;
}

$adaDat       = tabelAda($con, 'import_dat_monitoring');
$adaAr02      = tabelAda($con, 'import_ar02_reg3');
$adaFagll     = tabelAda($con, 'import_fagll');
$adaF01       = tabelAda($con, 'import_data_f01');
$adaMasterCoa = tabelAda($con, 'master_coa');

// kode_ap & kode_ckpn sekarang sudah di-hardcode langsung di $ATB_ITEMS di atas (dari KK Rekon
// Aset vs TB), jadi lookup ke master_coa untuk kode GL-nya tidak diperlukan lagi.

if ($adaFagll) {
    mysqli_query($con, "DROP TEMPORARY TABLE IF EXISTS tmp_asset_account_atb");
    mysqli_query($con, "CREATE TEMPORARY TABLE tmp_asset_account_atb
        SELECT asset, asset_subnumber, MAX(account) as acc FROM import_fagll GROUP BY asset, asset_subnumber");
    mysqli_query($con, "ALTER TABLE tmp_asset_account_atb ADD PRIMARY KEY (asset, asset_subnumber)");
}

define('TMP_ASSET_ACC', 'tmp_asset_account_atb');

// ==========================================================================
// HELPER PERHITUNGAN -- DISAMAKAN dengan mutasi_aset_tetap.php
//   31 Des  : DAT (per GL Aset; fallback lewat akun beban FAGLL), CKPN dari F.01 Desember
//   Mutasi  : AR02 reg3, SATU snapshot (bulan terbaru <= bulan akhir), kelompok lewat gl_beban_penyusutan
//   Akhir   : rollforward (31 Des + tambah - kurang + reklas). REKAP FAGLL / TB GL TIDAK dipakai
// ==========================================================================
function normAkun($c) { return ltrim(trim((string)$c), '0'); }

// Parser angka khusus F01/FAGLL: mengenali "-123", "123-" (format SAP) dan "(123)".
function numExprRobust($col) {
    $c    = "REPLACE(REPLACE(TRIM($col), ',', ''), ' ', '')";
    $core = "REPLACE(REPLACE(REPLACE(REPLACE($c, '(', ''), ')', ''), '-', ''), '+', '')";
    return "CASE WHEN $core REGEXP '^[0-9]+(\\\\.[0-9]+)?$' THEN CAST($core AS DECIMAL(24,2)) * " .
           "(CASE WHEN $c REGEXP '^-|-$|^\\\\(.*\\\\)$' THEN -1 ELSE 1 END) ELSE 0 END";
}

function buildInList($con, array $codes) {
    $codes = array_values(array_unique(array_filter($codes, function ($c) { return $c !== null && $c !== ''; })));
    if (empty($codes)) return null;
    $escaped = array_map(function ($c) use ($con) { return "'" . mysqli_real_escape_string($con, $c) . "'"; }, $codes);
    return implode(',', $escaped);
}

// Saldo awal (31 Des tahun awal) dari DAT, dikelompokkan per akun beban lewat tabel sementara aset -> akun FAGLL.
function sumDatByAccountBatch($con, $adaDat, $adaFagll, array $accountCodes, $tahunAwal, $field) {
    $out = [];
    if (!$adaDat || !$adaFagll) return $out;
    $inList = buildInList($con, $accountCodes);
    if ($inList === null) return $out;
    $expr = numExpr("d.$field");
    $sql = "SELECT fam.acc as acc, SUM($expr) as total FROM import_dat_monitoring d
            INNER JOIN " . TMP_ASSET_ACC . " fam ON fam.asset = d.nomor_asset AND fam.asset_subnumber = d.sub_number
            WHERE fam.acc IN ($inList) AND d.tahun_buku = '" . (int)$tahunAwal . "'
            GROUP BY fam.acc";
    $res = mysqli_query($con, $sql);
    if ($res) { while ($row = mysqli_fetch_assoc($res)) { $out[$row['acc']] = ($row['total'] !== null) ? (float)$row['total'] : 0.0; } }
    return $out;
}

// Bulan snapshot AR02 yang dipakai: bulan terbaru yang sudah di-upload dan <= bulan akhir (null kalau belum ada).
function ar02BulanSnapshot($con, $tahun, $bulan) {
    static $cache = [];
    $k = (int)$tahun . '-' . (int)$bulan;
    if (!array_key_exists($k, $cache)) {
        $rM = mysqli_query($con, "SELECT MAX(CAST(periode_bulan AS UNSIGNED)) AS m FROM import_ar02_reg3
            WHERE periode_tahun = '" . (int)$tahun . "' AND CAST(periode_bulan AS UNSIGNED) <= " . (int)$bulan);
        $rowM = $rM ? mysqli_fetch_assoc($rM) : null;
        $cache[$k] = ($rowM && $rowM['m'] !== null) ? (int)$rowM['m'] : null;
    }
    return $cache[$k];
}

// AR02 reg3 itu KUMULATIF (year-to-date) per bulan upload, jadi JANGAN dijumlah antar bulan.
function sumAr02ByAccountBatch($con, $adaAr02, $adaFagll, array $accountCodes, $tahunAkhir, $bulanAkhir, $field) {
    $out = [];
    if (!$adaAr02) return $out;
    $inList = buildInList($con, $accountCodes);
    if ($inList === null) return $out;
    $expr  = numExpr("a.$field");
    $tahun = (int)$tahunAkhir;
    $bulanPakai = ar02BulanSnapshot($con, $tahun, (int)$bulanAkhir);
    if ($bulanPakai === null) return $out;
    $cond = "a.periode_tahun = '$tahun' AND CAST(a.periode_bulan AS UNSIGNED) = $bulanPakai";

    // Pengelompokan: kolom gl_beban_penyusutan milik AR02 sendiri; kalau snapshot itu belum terisi, jatuh ke FAGLL.
    $rN = mysqli_query($con, "SELECT COUNT(*) AS n FROM import_ar02_reg3 a
        WHERE $cond AND TRIM(IFNULL(a.gl_beban_penyusutan,'')) NOT IN ('', '0')");
    $rowN = $rN ? mysqli_fetch_assoc($rN) : null;
    if ($rowN && (int)$rowN['n'] > 0) {
        $sql = "SELECT TRIM(a.gl_beban_penyusutan) AS acc, SUM($expr) AS total FROM import_ar02_reg3 a
                WHERE $cond AND TRIM(a.gl_beban_penyusutan) IN ($inList)
                GROUP BY TRIM(a.gl_beban_penyusutan)";
    } elseif ($adaFagll) {
        $sql = "SELECT fam.acc AS acc, SUM($expr) AS total FROM import_ar02_reg3 a
                INNER JOIN " . TMP_ASSET_ACC . " fam ON fam.asset = a.nomor_asset AND fam.asset_subnumber = a.sub_number
                WHERE fam.acc IN ($inList) AND $cond
                GROUP BY fam.acc";
    } else {
        return $out;
    }
    $res = mysqli_query($con, $sql);
    if ($res) { while ($row = mysqli_fetch_assoc($res)) { $out[$row['acc']] = ($row['total'] !== null) ? (float)$row['total'] : 0.0; } }
    return $out;
}

function sumF01ByAccountBatch($con, $adaF01, array $accountCodes, $bulan, $tahun) {
    $out = [];
    if (!$adaF01) return $out;
    $norm = [];
    foreach ($accountCodes as $c) { $n = normAkun($c); if ($n !== '') $norm[$n] = true; }
    $inList = buildInList($con, array_keys($norm));
    if ($inList === null) return $out;
    $expr = numExprRobust("total_reporting_period");
    $sql = "SELECT TRIM(LEADING '0' FROM TRIM(account_number)) AS acc, SUM($expr) as total FROM import_data_f01
            WHERE TRIM(LEADING '0' FROM TRIM(account_number)) IN ($inList) AND periode_bulan = '" . (int)$bulan . "' AND periode_tahun = '" . (int)$tahun . "'
            GROUP BY acc";
    $res = mysqli_query($con, $sql);
    if ($res) { while ($row = mysqli_fetch_assoc($res)) { $out[$row['acc']] = ($row['total'] !== null) ? (float)$row['total'] : 0.0; } }
    return $out;
}

// Hitung baris HP/AP untuk SEMUA akun beban sekaligus -> hasil per kode akun beban.
// 'akhir' = rollforward (awal + penambahan - pengurangan + reklasifikasi); nanti ditimpa TB GL kalau ada.
function hitungBarisBatch($con, $adaDat, $adaAr02, $adaFagll, array $accountCodes, $tahunAwal, $tahunAkhir, $bulanAkhir, $fieldAwal, $fieldPenambahan, $fieldPengurangan, $fieldReklasifikasi) {
    $mapAwal          = sumDatByAccountBatch($con, $adaDat, $adaFagll, $accountCodes, $tahunAwal, $fieldAwal);
    $mapPenambahan    = sumAr02ByAccountBatch($con, $adaAr02, $adaFagll, $accountCodes, $tahunAkhir, $bulanAkhir, $fieldPenambahan);
    $mapPengurangan   = sumAr02ByAccountBatch($con, $adaAr02, $adaFagll, $accountCodes, $tahunAkhir, $bulanAkhir, $fieldPengurangan);
    $mapReklasifikasi = sumAr02ByAccountBatch($con, $adaAr02, $adaFagll, $accountCodes, $tahunAkhir, $bulanAkhir, $fieldReklasifikasi);
    $hasil = [];
    foreach ($accountCodes as $accCode) {
        $awal = $mapAwal[$accCode] ?? 0.0; $pe = $mapPenambahan[$accCode] ?? 0.0;
        $pg = $mapPengurangan[$accCode] ?? 0.0; $rk = $mapReklasifikasi[$accCode] ?? 0.0;
        $akhirHitung = $awal + $pe - $pg + $rk;
        $hasil[$accCode] = [
            'awal' => $awal, 'penambahan' => $pe, 'pengurangan' => $pg, 'reklasifikasi' => $rk,
            'akhir' => $akhirHitung, 'akhir_hitung' => $akhirHitung, 'selisih' => 0.0,
        ];
    }
    return $hasil;
}

// CKPN: kredit/debit FAGLL 1 Jan s.d. akhir bulan akhir (saldo = kredit - debit).
function sumFagllCkpnBatch($con, $adaFagll, array $accountCodes, $tahunAkhir, $bulanAkhir) {
    $out = [];
    if (!$adaFagll) return $out;
    $norm = [];
    foreach ($accountCodes as $c) { $n = normAkun($c); if ($n !== '') $norm[$n] = true; }
    $inList = buildInList($con, array_keys($norm));
    if ($inList === null) return $out;
    $expr = numExprRobust('amount_local_currency');
    $tglAwal  = sprintf('%04d-01-01', $tahunAkhir);
    $tglAkhir = date('Y-m-t', mktime(0, 0, 0, $bulanAkhir, 1, $tahunAkhir));
    $sql = "SELECT TRIM(LEADING '0' FROM TRIM(account)) AS acc,
                   SUM(CASE WHEN ($expr) < 0 THEN -($expr) ELSE 0 END) AS kredit,
                   SUM(CASE WHEN ($expr) > 0 THEN ($expr) ELSE 0 END) AS debit
            FROM import_fagll
            WHERE TRIM(LEADING '0' FROM TRIM(account)) IN ($inList)
              AND posting_date_norm BETWEEN '$tglAwal' AND '$tglAkhir'
            GROUP BY acc";
    $res = mysqli_query($con, $sql);
    if ($res) { while ($row = mysqli_fetch_assoc($res)) { $out[$row['acc']] = ['kredit' => (float)$row['kredit'], 'debit' => (float)$row['debit']]; } }
    return $out;
}

// CKPN (semua kolom positif): awal = F.01 Desember tahun awal; akhir = |kredit - debit| FAGLL; mutasi = 0.
function hitungBarisCkpnBatch($con, $adaF01, $adaFagll, array $accountCodes, $tahunAwal, $tahunAkhir, $bulanAkhir) {
    $mapAwal = sumF01ByAccountBatch($con, $adaF01, $accountCodes, 12, $tahunAwal);
    $mapMut  = sumFagllCkpnBatch($con, $adaFagll, $accountCodes, $tahunAkhir, $bulanAkhir);
    $hasil = [];
    foreach ($accountCodes as $accCode) {
        if ($accCode === null || $accCode === '') continue;
        $awal = abs($mapAwal[normAkun($accCode)] ?? 0.0);
        $mut  = $mapMut[normAkun($accCode)] ?? ['kredit' => 0.0, 'debit' => 0.0];
        $hasil[$accCode] = [
            'awal' => $awal, 'penambahan' => 0.0, 'pengurangan' => 0.0, 'reklasifikasi' => 0.0,
            'akhir' => abs($mut['kredit'] - $mut['debit']),
        ];
    }
    return $hasil;
}
$kosongCkpn = ['awal' => 0.0, 'penambahan' => 0.0, 'pengurangan' => 0.0, 'reklasifikasi' => 0.0, 'akhir' => 0.0];

// Kumpulkan referensi baris supaya override di bawah bisa mengubah baris aslinya.
function kkRefs(array &$list) { $o = []; foreach (array_keys($list) as $k) { $o[] = &$list[$k]; } return $o; }

function kkSetAwalBaru(array &$r, $awalBaru) {
    $r['awal'] = (float)$awalBaru;
    $r['akhir_hitung'] = $r['awal'] + ($r['penambahan'] ?? 0) - ($r['pengurangan'] ?? 0) + ($r['reklasifikasi'] ?? 0);
    $r['akhir'] = $r['akhir_hitung'];
    $r['selisih'] = 0.0;
}

// ── Kolom 31 Des (awal) HP & AP: langsung per GL Aset dari DAT, seperti di Excel ──
// HP : SUMIF(DAT!GL Account Aset, kode HP, DAT!Nilai Perolehan s.d Tahun Berjalan)
// AP : SUMIF(DAT!GL Akumulasi,    kode AP, DAT!Akumulasi Penyusutan)  (GL Akumulasi = lookup GL Aset -> master_kk_aset)
// Kalau DAT tahun awal belum punya kolom GL Aset terisi, tetap pakai cara lama (lewat akun beban FAGLL).
function kkTerapkanAwalDatGl($con, $adaDat, $tahunAwal, array &$rowsHP, array &$rowsAP) {
    if (!$adaDat) return;
    $rc = mysqli_query($con, "SHOW COLUMNS FROM import_dat_monitoring LIKE 'gl_account_aset'");
    if (!$rc || mysqli_num_rows($rc) === 0) return;
    $rn = mysqli_query($con, "SELECT COUNT(*) AS n FROM import_dat_monitoring WHERE tahun_buku = '" . (int)$tahunAwal . "' AND TRIM(IFNULL(gl_account_aset, '')) <> ''");
    if (!($rn && ($rw = mysqli_fetch_assoc($rn)) && (int)$rw['n'] > 0)) return;

    $hpGl = []; $apGl = []; $hpOk = false; $apOk = false;
    $exprHp = numExpr('d.nilai_perolehan_sd_tahun_berjalan');
    $rHp = mysqli_query($con, "SELECT TRIM(LEADING '0' FROM TRIM(d.gl_account_aset)) AS gl, SUM($exprHp) AS total
        FROM import_dat_monitoring d WHERE d.tahun_buku = '" . (int)$tahunAwal . "' AND TRIM(IFNULL(d.gl_account_aset, '')) <> ''
        GROUP BY TRIM(LEADING '0' FROM TRIM(d.gl_account_aset))");
    if ($rHp) { while ($x = mysqli_fetch_assoc($rHp)) { $hpGl[(string)$x['gl']] = (float)$x['total']; } $hpOk = true; }
    if (tabelAda($con, 'master_kk_aset')) {
        $exprAp = numExpr('d.akumulasi_penyusutan');
        $rAp = mysqli_query($con, "SELECT TRIM(LEADING '0' FROM TRIM(k.gl_akumulasi)) AS gl, SUM($exprAp) AS total
            FROM import_dat_monitoring d INNER JOIN master_kk_aset k ON TRIM(k.gl_aset) = TRIM(d.gl_account_aset)
            WHERE d.tahun_buku = '" . (int)$tahunAwal . "' AND TRIM(IFNULL(k.gl_akumulasi, '')) NOT IN ('', '0')
            GROUP BY TRIM(LEADING '0' FROM TRIM(k.gl_akumulasi))");
        if ($rAp) { while ($x = mysqli_fetch_assoc($rAp)) { $apGl[(string)$x['gl']] = (float)$x['total']; } $apOk = true; }
    }
    if ($hpOk) { foreach ($rowsHP as &$r) { $k = normAkun($r['kode'] ?? ''); if ($k === '') continue; kkSetAwalBaru($r, $hpGl[$k] ?? 0.0); } unset($r); }
    if ($apOk) { foreach ($rowsAP as &$r) { $k = normAkun($r['kode'] ?? ''); if ($k === '') continue; kkSetAwalBaru($r, $apGl[$k] ?? 0.0); } unset($r); }
}

// F.01 Desember tahun awal (sumber kolom 31 Des bagian Penurunan Nilai).
$f01AwalAda = false;
if ($adaF01) {
    $rF = mysqli_query($con, "SELECT COUNT(*) AS n FROM import_data_f01 WHERE periode_bulan = '12' AND periode_tahun = '" . (int)$tahunAwal . "'");
    $f01AwalAda = ($rF && ($rwF = mysqli_fetch_assoc($rF)) && (int)$rwF['n'] > 0);
}

// ── Hitung HP, AP, dan CKPN tiap item (dibatch per akun beban, lalu ditempel ke $ATB_ITEMS) ──
$atbIsPlaceholder = function ($it) {
    return !isset($it['kode']) || !is_string($it['kode']) || trim($it['kode']) === '' || stripos(trim($it['kode']), 'ISI_KODE_') === 0;
};
$atbKodeBeban = []; $atbKodeCkpn = [];
foreach ($ATB_ITEMS as $it) {
    if ($it['type'] !== 'item') continue;
    if (!$atbIsPlaceholder($it)) $atbKodeBeban[] = $it['kode'];
    if (!empty($it['in_ckpn']) && !empty($it['kode_ckpn'])) $atbKodeCkpn[] = $it['kode_ckpn'];
}
$hpMap = hitungBarisBatch($con, $adaDat, $adaAr02, $adaFagll, $atbKodeBeban, $tahunAwal, $tahunAkhir, $bulanAkhir,
    'nilai_perolehan_sd_tahun_berjalan', 'acquisition', 'retirement', 'transfers');
$apMap = hitungBarisBatch($con, $adaDat, $adaAr02, $adaFagll, $atbKodeBeban, $tahunAwal, $tahunAkhir, $bulanAkhir,
    'akumulasi_penyusutan', 'dep_tahun_berjalan', 'dep_retir', 'dep_transfer');
$ckpnMap = hitungBarisCkpnBatch($con, $adaF01, $adaFagll, $atbKodeCkpn, $tahunAwal, $tahunAkhir, $bulanAkhir);
$kosongBaris = ['awal' => 0.0, 'penambahan' => 0.0, 'pengurangan' => 0.0, 'reklasifikasi' => 0.0, 'akhir' => 0.0, 'akhir_hitung' => 0.0, 'selisih' => 0.0];

foreach (array_keys($ATB_ITEMS) as $i) {
    $it = &$ATB_ITEMS[$i];
    if ($it['type'] !== 'item') { unset($it); continue; }
    $ph = $atbIsPlaceholder($it);
    $it['hp'] = array_merge(['kode' => $it['kode_hp'] ?? ''], $ph ? $kosongBaris : ($hpMap[$it['kode']] ?? $kosongBaris));
    $it['ap'] = array_merge(['kode' => $it['kode_ap'] ?? ''], $ph ? $kosongBaris : ($apMap[$it['kode']] ?? $kosongBaris));
    if (!empty($it['in_ckpn'])) {
        $it['ckpn'] = array_merge(['kode' => $it['kode_ckpn'] ?? ''], $ckpnMap[$it['kode_ckpn'] ?? ''] ?? $kosongCkpn);
    }
    unset($it);
}

// ── Override kolom 31 Des (DAT per GL Aset); kolom Akhir = rollforward ──
$refHP = []; $refAP = []; $refCKPN = [];
foreach (array_keys($ATB_ITEMS) as $i) {
    if ($ATB_ITEMS[$i]['type'] !== 'item') continue;
    $refHP[] = &$ATB_ITEMS[$i]['hp'];
    $refAP[] = &$ATB_ITEMS[$i]['ap'];
    if (isset($ATB_ITEMS[$i]['ckpn'])) $refCKPN[] = &$ATB_ITEMS[$i]['ckpn'];
}
kkTerapkanAwalDatGl($con, $adaDat, $tahunAwal, $refHP, $refAP);
unset($refHP, $refAP, $refCKPN);

// ── KK F01: banding saldo Akhir (hasil hitungan) dengan F01, lalu terapkan snapshot kalau periode ini sudah disimpan ──
$kkF01Ada = kkAdaF01Periode($con, $f01Bulan, $f01Tahun);
$kkAksiPost = ($_SERVER['REQUEST_METHOD'] === 'POST') ? ($_POST['aksi'] ?? '') : '';
list($kkSnapMap, $kkSnapSavedAt) = kkAmbilSnapshot($con, $KK_HAL, $tahunAkhir, $bulanAkhir);
// Kalau snapshot ada, tampilkan dari snapshot (bukan hitung ulang) -- kecuali user menekan "Hitung ulang & timpa".
$kkPakaiSnapshot = !empty($kkSnapMap) && $kkAksiPost !== 'simpan_snapshot';
$kkLatest = ($bulanAkhir == $defBulanAkhir && $tahunAkhir == $defTahunAkhir);

foreach ($ATB_ITEMS as &$kkIt) {
    if ($kkIt['type'] !== 'item') continue;
    foreach (['HP' => ['hp', 'kode_hp'], 'AP' => ['ap', 'kode_ap'], 'CKPN' => ['ckpn', 'kode_ckpn']] as $kkSeksi => $kkMeta) {
        list($kkSec, $kkKodeKey) = $kkMeta;
        if (!isset($kkIt[$kkSec])) continue;
        $kkKode = $kkIt[$kkKodeKey] ?? '';
        $kkIt[$kkSec]['f01'] = $kkF01Ada ? kkSumF01($con, $kkKode, $f01Bulan, $f01Tahun) : 0.0;
        if ($kkPakaiSnapshot) kkTerapkanSnapshot($kkIt[$kkSec], $kkSeksi, $kkKode, $kkSnapMap);
        $kkIt[$kkSec]['sel_f01'] = $kkF01Ada ? kkHitungSelisih($kkSeksi, $kkIt[$kkSec]['akhir'], $kkIt[$kkSec]['f01']) : 0.0;
    }
}
unset($kkIt);

function subtotalATB($items, $section, $kolom) {
    $t = 0;
    foreach ($items as $it) {
        if ($it['type'] !== 'item') continue;
        if ($section === 'ckpn' && empty($it['in_ckpn'])) continue;
        $t += $it[$section][$kolom];
    }
    return $t;
}
$subHP   = ['awal' => subtotalATB($ATB_ITEMS, 'hp', 'awal'), 'penambahan' => subtotalATB($ATB_ITEMS, 'hp', 'penambahan'), 'pengurangan' => subtotalATB($ATB_ITEMS, 'hp', 'pengurangan'), 'reklasifikasi' => subtotalATB($ATB_ITEMS, 'hp', 'reklasifikasi'), 'akhir' => subtotalATB($ATB_ITEMS, 'hp', 'akhir')];
$subAP   = ['awal' => subtotalATB($ATB_ITEMS, 'ap', 'awal'), 'penambahan' => subtotalATB($ATB_ITEMS, 'ap', 'penambahan'), 'pengurangan' => subtotalATB($ATB_ITEMS, 'ap', 'pengurangan'), 'reklasifikasi' => subtotalATB($ATB_ITEMS, 'ap', 'reklasifikasi'), 'akhir' => subtotalATB($ATB_ITEMS, 'ap', 'akhir')];
$subCKPN = ['awal' => subtotalATB($ATB_ITEMS, 'ckpn', 'awal'), 'penambahan' => subtotalATB($ATB_ITEMS, 'ckpn', 'penambahan'), 'pengurangan' => subtotalATB($ATB_ITEMS, 'ckpn', 'pengurangan'), 'reklasifikasi' => subtotalATB($ATB_ITEMS, 'ckpn', 'reklasifikasi'), 'akhir' => subtotalATB($ATB_ITEMS, 'ckpn', 'akhir')];

$nilaiBukuAwal  = $subHP['awal']  - abs($subAP['awal'])  - abs($subCKPN['awal']);
$nilaiBukuAkhir = $subHP['akhir'] - abs($subAP['akhir']) - abs($subCKPN['akhir']);

function fmtRp($n, $negatif = false) {
    $n = (float)$n;
    if (abs($n) < 0.5) return '-';
    $abs = number_format(abs($n), 0, ',', '.');
    return ($negatif || $n < 0) ? "- $abs" : $abs;
}

// GL Account (kode_hp/kode_ap/kode_ckpn) sekarang sudah lengkap semua dari KK Rekon Aset vs TB.
// Yang masih perlu dicek: akun BEBAN AMORTISASI ('kode') -- selama masih placeholder ISI_KODE_...,
// baris itu query-nya gak match apa-apa jadi nilainya tampil "-" terus (bukan berarti salah hitung).
$kategoriTanpaKodeATB = [];
$adaPlaceholder = false;
foreach ($ATB_ITEMS as $it) {
    if ($it['type'] !== 'item') continue;
    if (strpos($it['kode'], 'ISI_KODE_') === 0) { $adaPlaceholder = true; $kategoriTanpaKodeATB[] = $it['nama_id']; }
}

// ── KK F01: susun data final, simpan snapshot, dan export Excel ──
$kkKetMap = kkAmbilKet($con, $KK_HAL, $tahunAkhir, $bulanAkhir);
$kkSections = [
    'HP'   => ['judul' => 'Harga perolehan',       'rows' => [], 'sub' => &$subHP],
    'AP'   => ['judul' => 'Akumulasi penyusutan',  'rows' => [], 'sub' => &$subAP],
    'CKPN' => ['judul' => 'Penurunan Nilai',       'rows' => [], 'sub' => &$subCKPN],
];
foreach ($ATB_ITEMS as $kkIt) {
    if ($kkIt['type'] !== 'item') continue;
    foreach (['HP' => ['hp', 'kode_hp', 'nama_id'], 'AP' => ['ap', 'kode_ap', 'nama_id'], 'CKPN' => ['ckpn', 'kode_ckpn', 'nama_ckpn_id']] as $kkS => $kkM) {
        if (!isset($kkIt[$kkM[0]])) continue;
        if ($kkS === 'CKPN' && empty($kkIt['in_ckpn'])) continue;
        $kkB = $kkIt[$kkM[0]];
        $kkSections[$kkS]['rows'][] = ['kode' => $kkIt[$kkM[1]] ?? '', 'nama' => $kkIt[$kkM[2]] ?? ($kkIt['nama_id'] ?? ''), 'awal' => $kkB['awal'], 'penambahan' => $kkB['penambahan'],
            'pengurangan' => $kkB['pengurangan'], 'reklasifikasi' => $kkB['reklasifikasi'], 'akhir' => $kkB['akhir'], 'f01' => $kkB['f01'], 'sel_f01' => $kkB['sel_f01']];
    }
}
foreach ($kkSections as $kkS => $kkSec) {
    $kkSections[$kkS]['sub']['f01'] = 0.0; $kkSections[$kkS]['sub']['sel_f01'] = 0.0;
    foreach ($kkSec['rows'] as $kkR) {
        $kkSections[$kkS]['sub']['f01'] += $kkR['f01'];
        $kkSections[$kkS]['sub']['sel_f01'] += $kkR['sel_f01'];
    }
}

$kkStatusSnap = '';
if ($kkAksiPost === 'simpan_snapshot') {
    kkSimpanSnapshot($con, $KK_HAL, $tahunAkhir, $bulanAkhir, $kkSections);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query([
        'tahun_awal' => $tahunAwal, 'bulan_akhir' => $bulanAkhir, 'tahun_akhir' => $tahunAkhir, 'snap' => 'ok']));
    exit();
}

if (($_GET['export'] ?? '') === 'xls') {
    $kkNamaFile = 'KK_F01_' . $KK_HAL . '_' . $tahunAkhir . '_' . str_pad($bulanAkhir, 2, '0', STR_PAD_LEFT);
    $n2 = function ($v) { return number_format((float)$v, 2, '.', ''); };
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $kkNamaFile . '.xls"');
    echo "\xEF\xBB\xBF";
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>';
    echo '<table border="1">';
    echo '<tr><td colspan="11"><b>ASET TAK BERWUJUD - Perbandingan dengan F01</b></td></tr>';
    echo '<tr><td colspan="11">Periode: ' . htmlspecialchars($labelAkhir) . ' | Sumber: ' . ($kkPakaiSnapshot ? 'Snapshot tersimpan (' . htmlspecialchars((string)$kkSnapSavedAt) . ')' : 'Hitung langsung (belum disimpan)') . '</td></tr>';
    echo '<tr style="background:#dbe4f0"><th>Kode GL</th><th>Uraian</th><th>' . htmlspecialchars($labelAwal) . '</th><th>Penambahan</th><th>Pengurangan</th><th>Reklasifikasi</th><th>' . htmlspecialchars($labelAkhir) . '</th><th>F01</th><th>Selisih</th><th>Keterangan</th><th>Status</th></tr>';
    foreach ($kkSections as $kkS => $kkSec) {
        echo '<tr><td colspan="11" style="background:#dbe4f0"><b>' . htmlspecialchars($kkSec['judul']) . '</b></td></tr>';
        foreach ($kkSec['rows'] as $kkR) {
            $kk = $kkKetMap[kkKey($kkS, $kkR['kode'])] ?? null;
            echo '<tr><td style="mso-number-format:\'@\'">' . htmlspecialchars($kkR['kode']) . '</td><td>' . htmlspecialchars($kkR['nama']) . '</td>'
               . '<td>' . $n2($kkR['awal']) . '</td><td>' . $n2($kkR['penambahan']) . '</td><td>' . $n2($kkR['pengurangan']) . '</td><td>' . $n2($kkR['reklasifikasi']) . '</td><td>' . $n2($kkR['akhir']) . '</td>'
               . '<td>' . ($kkF01Ada ? $n2($kkR['f01']) : '') . '</td><td>' . ($kkF01Ada ? $n2($kkR['sel_f01']) : '') . '</td>'
               . '<td>' . htmlspecialchars($kk['keterangan'] ?? '') . '</td><td>' . (!empty($kk['status_ok']) ? 'OK' : '') . '</td></tr>';
        }
        $sb = $kkSec['sub'];
        echo '<tr style="background:#f1f3f5"><td></td><td><b>Jumlah</b></td><td><b>' . $n2($sb['awal']) . '</b></td><td><b>' . $n2($sb['penambahan']) . '</b></td><td><b>' . $n2($sb['pengurangan']) . '</b></td><td><b>' . $n2($sb['reklasifikasi']) . '</b></td><td><b>' . $n2($sb['akhir']) . '</b></td>'
           . '<td><b>' . ($kkF01Ada ? $n2($sb['f01']) : '') . '</b></td><td><b>' . ($kkF01Ada ? $n2($sb['sel_f01']) : '') . '</b></td><td></td><td></td></tr>';
    }
    echo '<tr style="background:#0b3a8c;color:#fff"><td></td><td><b>Nilai buku neto</b></td><td><b>' . $n2($nilaiBukuAwal) . '</b></td><td></td><td></td><td></td><td><b>' . $n2($nilaiBukuAkhir) . '</b></td><td></td><td></td><td></td><td></td></tr>';
    echo '</table></body></html>';
    exit();
}
?>
<!doctype html>
<html lang="id">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Dasbor Mutasi Aset Tak Berwujud - Web Aset Tetap</title>
    <link rel="icon" type="image/png" href="../../dist/assets/img/emblem.png" />
    <link rel="shortcut icon" type="image/png" href="../../dist/assets/img/emblem.png" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes" />
    <meta name="color-scheme" content="light dark" />
    <meta name="theme-color" content="#007bff" media="(prefers-color-scheme: light)" />
    <meta name="theme-color" content="#1a1a1a" media="(prefers-color-scheme: dark)" />
    <link rel="stylesheet" href="../../dist/css/index.css"/>
    <link rel="stylesheet" href="../../dist/css/overlayscrollbars.min.css"/>
    <link rel="stylesheet" href="../../dist/css/bootstrap-icons/bootstrap-icons.min.css"/>
    <link rel="stylesheet" href="../../dist/css/adminlte.css" />
    <style>
      .app-sidebar { background-color: #0b3a8c !important; }
      .app-header, nav.app-header, .app-header.navbar { border-bottom: 0 !important; box-shadow: none !important; }
      .sidebar-brand { background-color: #0b3a8c !important; margin-bottom: 0 !important; padding: 0.25rem 0 !important; border-bottom: 0 !important; box-shadow: none !important; }
      .sidebar-brand .brand-link { display: block !important; padding: 0.5rem 0.75rem !important; border-bottom: 0 !important; box-shadow: none !important; background-color: transparent !important; }
      .sidebar-brand .brand-link .brand-image { display: block !important; height: auto !important; max-height: 48px !important; margin: 0 !important; padding: 6px 8px !important; background-color: transparent !important; }
      .app-sidebar { border-right: 0 !important; }
      .app-sidebar, .app-sidebar a, .app-sidebar .nav-link, .app-sidebar .nav-link p, .app-sidebar .nav-header, .app-sidebar .brand-text, .app-sidebar .nav-icon, .app-sidebar .nav-badge { color: #ffffff !important; fill: #ffffff !important; }
      .app-sidebar .nav-link .nav-icon, .app-sidebar .nav-link i { color: #ffffff !important; }
      .app-sidebar .nav-link.active, .app-sidebar .nav-link:hover { background-color: #0b5db7 !important; color: #ffffff !important; fill: #ffffff !important; }
      .app-sidebar .nav-link.active .nav-icon, .app-sidebar .nav-link:hover .nav-icon, .app-sidebar .nav-link.active i, .app-sidebar .nav-link:hover i { color: #ffffff !important; }
      .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; border: 1px solid #dee2e6; border-radius: 0.25rem; }
      /* Freeze header: tabel di-scroll di dalam kotaknya sendiri, header menempel di atas */
      .table-responsive.kk-scroll { max-height: calc(100vh - 260px); overflow: auto; }
      .tabel-pi thead th { position: sticky; top: 0; z-index: 3; box-shadow: inset 0 -1px 0 #dee2e6, inset 0 1px 0 #dee2e6; }

      .tabel-pi { width: 100%; min-width: 1050px; border-collapse: collapse; font-size: 0.85rem; }
      .tabel-pi th, .tabel-pi td { padding: 6px 10px; border: 1px solid #dee2e6; white-space: nowrap; }
      .tabel-pi thead th { background-color: #f1f3f5; text-align: center; vertical-align: middle; font-weight: 600; }
      .tabel-pi td.kode { color: #6c757d; font-size: 0.8rem; }
      .tabel-pi td.nama-id { text-align: left; font-weight: 500; }
      .tabel-pi td.sub-nama-id { text-align: left; font-weight: 500; }
      .tabel-pi td.nama-en { text-align: left; color: #6c757d; font-style: italic; }
      .tabel-pi thead small { font-style: italic; }
      .tabel-pi tr.baris-section span { font-style: italic; }
      .tabel-pi td.angka { text-align: right; font-variant-numeric: tabular-nums; }
      .tabel-pi tr.baris-section td { background-color: #dbe4f0; font-weight: 700; text-transform: uppercase; }
      .tabel-pi tr.baris-subheader td { font-weight: 500; }
      .tabel-pi tr.baris-jumlah td { background-color: #f8f9fa; font-weight: 700; border-top: 2px solid #495057; }
      .tabel-pi tr.baris-neto td { background-color: #0b3a8c; color: #fff; font-weight: 700; border-top: 3px double #212529; }
      .tabel-pi tr.baris-neto td.angka { color: #fff; }
      .judul-modul { font-weight: 700; font-size: 1.1rem; margin-bottom: 1.25rem; }
      .catatan-box { font-size: 0.85rem; }
      .tabel-pi td.kk-ket-cell { min-width: 300px; }
      .tabel-pi .kk-ket { font-size: 0.8rem; }
      .tabel-pi .kk-ok-btn { min-width: 52px; }
      .tabel-pi .kk-batal-btn { padding-left: .5rem; padding-right: .5rem; }
      .tabel-pi tr.kk-row-ok td { background-color: #e9f7ef; }
      .tabel-pi .kk-ket.kk-berhasil { border-color: #198754; box-shadow: 0 0 0 .2rem rgba(25,135,84,.25); }
      .tabel-pi .kk-ket.kk-gagal { border-color: #dc3545; box-shadow: 0 0 0 .2rem rgba(220,53,69,.25); }
      
    </style>
    <link rel="stylesheet" href="../../dist/css/dataTables.dataTables.min.css" />
   <link rel="stylesheet" href="../sidebar.css" />
  </head>
  <body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
      <nav class="app-header navbar navbar-expand bg-white border-0 shadow-none" style="border-bottom:0!important;box-shadow:none!important;">
        <div class="container-fluid">
          <ul class="navbar-nav ms-auto">
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
                  <div class="row ps-3 pe-3 pt-2 pb-2 user-info">
                    <div class="col-6 text-start">
                      <small class="text-muted">Type User:</small><br>
                      <span class="badge bg-primary"><?php echo htmlspecialchars($_SESSION['Type_User']); ?></span>
                    </div>
                    <div class="col-6 text-end">
                      <small class="text-muted">Cabang:</small><br>
                      <span class="fw-semibold small"><p class="fw-semibold"><?php echo htmlspecialchars($_SESSION['Cabang'] . ' - ' . $_SESSION['profit_center_text']); ?></p></span>
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
          <a href="./index.html" class="brand-link">
            <img src="../../dist/assets/img/logo.png" class="brand-image" alt="Logo Pelindo" title="PT Pelabuhan Indonesia" />
          </a>
        </div>
        <div class="sidebar-wrapper">
          <nav class="mt-2">
            <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="navigation" aria-label="Main navigation" data-accordion="false" id="navigation">
            <?php
            $userNipp = isset($_SESSION['nipp']) ? htmlspecialchars($_SESSION['nipp']) : '';
            $query = "SELECT menus.menu, menus.nama_menu, menus.urutan_menu FROM user_access INNER JOIN menus ON user_access.id_menu = menus.id_menu WHERE user_access.NIPP = '" . mysqli_real_escape_string($con, $userNipp) . "' ORDER BY menus.urutan_menu ASC";
            $result_menu = mysqli_query($con, $query) or die(mysqli_error($con));
                        $sidebarMenuConfig = require __DIR__ . '/../sidebar_menu_config.php';
            $iconMap = $sidebarMenuConfig['iconMap'];
            $groupMap = $sidebarMenuConfig['groupMap'];
            $groupIcon = $sidebarMenuConfig['groupIcon'];
            $groupOrder = $sidebarMenuConfig['groupOrder'];

            $currentPage = basename($_SERVER['PHP_SELF']);
            $ungrouped = []; $grouped = [];
            while ($row = mysqli_fetch_assoc($result_menu)) {
                $namaMenu = trim($row['nama_menu']);
                if (isset($groupMap[$namaMenu])) { $grouped[$groupMap[$namaMenu]][] = $row; } else { $ungrouped[] = $row; }
            }
            foreach ($ungrouped as $row) {
                $namaMenu = trim($row['nama_menu']);
                $icon = $iconMap[$namaMenu] ?? 'bi bi-circle';
                $isActive = ($currentPage === $row['menu'] . '.php') ? 'active' : '';
                echo '<li class="nav-item"><a href="../' . $row['menu'] . '/' . $row['menu'] . '.php" class="nav-link ' . $isActive . '"><i class="nav-icon ' . $icon . '"></i><p>' . htmlspecialchars($namaMenu) . '</p></a></li>';
            }
            foreach ($groupOrder as $groupName) {
                if (empty($grouped[$groupName])) continue;
                $itemsGrup = $grouped[$groupName];
                $adaAktif = false;
                foreach ($itemsGrup as $itemG) { if ($currentPage === $itemG['menu'] . '.php') { $adaAktif = true; break; } }
                $liClassGrup = 'nav-item' . ($adaAktif ? ' menu-open' : '');
                $linkClassGrup = 'nav-link' . ($adaAktif ? ' active' : '');
                $iconGrup = $groupIcon[$groupName] ?? 'bi bi-folder';
                echo '<li class="' . $liClassGrup . '">';
                echo '<a href="#" class="' . $linkClassGrup . '"><i class="nav-icon ' . $iconGrup . '"></i><p>' . htmlspecialchars($groupName) . '<i class="nav-arrow bi bi-chevron-right"></i></p></a>';
                echo '<ul class="nav nav-treeview">';
                foreach ($itemsGrup as $itemG) {
                    $namaMenuG = trim($itemG['nama_menu']);
                    $iconItemG = $iconMap[$namaMenuG] ?? 'bi bi-circle';
                    $isActiveG = ($currentPage === $itemG['menu'] . '.php') ? 'active' : '';
                    echo '<li class="nav-item"><a href="../' . $itemG['menu'] . '/' . $itemG['menu'] . '.php" class="nav-link ' . $isActiveG . '"><i class="nav-icon ' . $iconItemG . '"></i><p>' . htmlspecialchars($namaMenuG) . '</p></a></li>';
                }
                echo '</ul></li>';
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
              <div class="col-sm-6"><h3 class="mb-0">Dasbor Mutasi Aset Tak Berwujud</h3></div>
              <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                  <li class="breadcrumb-item"><a href="../dasbor/dasbor.php">Home</a></li>
                  <li class="breadcrumb-item active">Dasbor Mutasi Aset Tak Berwujud</li>
                </ol>
              </div>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">

            <?php if ($adaPlaceholder): ?>
            <div class="alert alert-warning">
              <strong>⚠ Akun beban amortisasi belum diisi untuk beberapa kategori:</strong>
              <?php echo htmlspecialchars(implode(', ', $kategoriTanpaKodeATB)); ?>.
              Kolom GL Account, Harga Perolehan &amp; Akumulasi Penyusutan-nya tetap tampil (dari KK Rekon Aset vs TB),
              tapi kolom Penambahan/Pengurangan/Reklasifikasi baris tsb akan tampil "-" sampai kode akun
              beban amortisasinya diisi di <code>$ATB_ITEMS</code> (bagian paling atas file ini, ganti
              <code>ISI_KODE_...</code>).
            </div>
            <?php endif; ?>

            <?php if (!$adaDat || !$adaAr02 || !$adaFagll || !$f01AwalAda): ?>
            <div class="alert alert-warning">
              <strong>⚠ Ada sumber data yang belum lengkap:</strong>
              <?php if (!$adaDat) echo '<div>- Tabel DAT (import_dat_monitoring) belum ada / belum diupload.</div>'; ?>
              <?php if (!$adaAr02) echo '<div>- Tabel AR02 reg3 (import_ar02_reg3) belum ada / belum diupload.</div>'; ?>
              <?php if (!$adaFagll) echo '<div>- Tabel REKAP FAGLL (import_fagll) belum ada / belum diupload.</div>'; ?>
              <?php if (!$f01AwalAda) echo '<div>- Data F.01 periode <strong>Desember ' . (int)$tahunAwal . '</strong> belum diupload (bagian Penurunan Nilai kolom 31 Des akan tampil "-").</div>'; ?>
              Angka pada kolom yang sumbernya belum lengkap akan tampil sebagai "-".
            </div>
            <?php endif; ?>

            <div class="card card-outline mb-4">
              <div class="card-body">
                <?php
                $kkQs = http_build_query(['tahun_awal' => $tahunAwal, 'bulan_akhir' => $bulanAkhir, 'tahun_akhir' => $tahunAkhir]);
                ?>
                <?php if (($_GET['snap'] ?? '') === 'ok'): ?>
                  <div class="alert alert-success py-2">Snapshot periode <?php echo htmlspecialchars($labelAkhir); ?> berhasil disimpan.</div>
                <?php endif; ?>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                  <div class="small">
                    <?php if ($kkPakaiSnapshot): ?>
                      <span class="badge text-bg-success"><i class="bi bi-lock-fill"></i> Snapshot tersimpan</span>
                      <span class="text-muted">disimpan <?php echo htmlspecialchars((string)$kkSnapSavedAt); ?></span>
                    <?php else: ?>
                          <span class="badge text-bg-warning"><i class="bi bi-unlock-fill"></i> Snapshot belum disimpan</span>
                    <!-- <?php endif; ?>
                    <?php if (!$kkF01Ada): ?>
                      <span class="badge text-bg-secondary ms-1">Data F01 periode ini belum ada</span>
                    <?php endif; ?> -->
                  </div>
                  <div class="d-flex gap-2">
                    <form method="post" id="kkFormSnapshot" action="?<?php echo htmlspecialchars($kkQs); ?>" class="m-0">
                      <input type="hidden" name="aksi" value="simpan_snapshot">
                      <button type="button" id="kkBtnSnapshot" class="btn btn-sm <?php echo $kkPakaiSnapshot ? 'btn-outline-secondary' : 'btn-primary'; ?>">
                        <i class="bi bi-arrow-repeat"></i> <?php echo $kkPakaiSnapshot ? 'Hitung ulang &amp; timpa snapshot' : 'Simpan snapshot'; ?>
                      </button>
                    </form>
                    <a href="?<?php echo htmlspecialchars($kkQs); ?>&export=xls" class="btn btn-sm btn-success"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
                  </div>
                </div>
                <?php
                $labelDataTerbaru = $namaBulanMap[$defBulanAkhir] . ' ' . $defTahunAkhir;
                $iniDataTerbaru = ($bulanAkhir == $defBulanAkhir && $tahunAkhir == $defTahunAkhir);
                $tahunMin = min($defTahunAwal, $tahunAwal, $tahunAkhir) - 5;
                $tahunMax = max($defTahunAkhir, $tahunAwal, $tahunAkhir) + 1;
                $daftarTahunOpsi = range($tahunMax, $tahunMin);
                ?>
                <form method="GET" id="formFilterDasborATB" class="row g-2 align-items-end mb-3">
                  <div class="col-auto">
                    <label class="form-label mb-0 fw-semibold">Tahun Buku Awal</label>
                    <select name="tahun_awal" class="form-select form-select-sm" style="width:140px;" onchange="this.form.submit()">
                      <?php foreach ($daftarTahunOpsi as $th): ?>
                        <option value="<?php echo $th; ?>" <?php echo ($th == $tahunAwal) ? 'selected' : ''; ?>><?php echo $th; ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-auto">
                    <label class="form-label mb-0 fw-semibold">Bulan Akhir</label>
                    <select name="bulan_akhir" class="form-select form-select-sm" style="width:140px;" onchange="this.form.submit()">
                      <?php foreach ($namaBulanMap as $num => $nm): ?>
                        <option value="<?php echo $num; ?>" <?php echo ($num == $bulanAkhir) ? 'selected' : ''; ?>><?php echo $nm; ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-auto">
                    <label class="form-label mb-0 fw-semibold">Tahun Akhir</label>
                    <select name="tahun_akhir" class="form-select form-select-sm" style="width:140px;" onchange="this.form.submit()">
                      <?php foreach ($daftarTahunOpsi as $th): ?>
                        <option value="<?php echo $th; ?>" <?php echo ($th == $tahunAkhir) ? 'selected' : ''; ?>><?php echo $th; ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <?php if (!$iniDataTerbaru): ?>
                  <div class="col-auto">
                    <a href="?tahun_awal=<?php echo (int)$tahunAwal; ?>&bulan_akhir=<?php echo (int)$defBulanAkhir; ?>&tahun_akhir=<?php echo (int)$defTahunAkhir; ?>" class="btn btn-outline-secondary btn-sm">
                      <i class="bi bi-arrow-counterclockwise"></i> Kembali ke data terbaru
                    </a>
                  </div>
                  <?php endif; ?>
                </form>

                <div class="table-responsive kk-scroll">
                  <table class="tabel-pi">
                    <thead>
                      <tr>
                          <th style="min-width:370px;text-align:left;" colspan="2">ASET TAK BERWUJUD</th>
                        <th style="min-width:150px;"><?php echo htmlspecialchars($labelAwal); ?><br><small class="fw-normal"><?php echo htmlspecialchars($labelAwalEn); ?></small></th>
                        <th style="min-width:130px;">Penambahan<br><small class="fw-normal">Additions</small></th>
                        <th style="min-width:130px;">Pengurangan<br><small class="fw-normal">Deductions</small></th>
                        <th style="min-width:130px;">Reklasifikasi<br><small class="fw-normal">Transfers</small></th>
                        <th style="min-width:150px;"><?php echo htmlspecialchars($labelAkhir); ?><br><small class="fw-normal"><?php echo htmlspecialchars($labelAkhirEn); ?></small></th>
                        <th style="min-width:220px;text-align:left;">Kategori (EN)</th>
                        <th style="min-width:130px;">F01<br><small class="fw-normal"><?php echo htmlspecialchars($labelF01); ?></small></th>
                        <th style="min-width:120px;">Selisih<br><small class="fw-normal">TB - F01</small></th>
                        <th style="min-width:300px;text-align:left;">Keterangan</th>
                      </tr>
                    </thead>
                    <tbody>

                      <!-- ============ HARGA PEROLEHAN ============ -->
                      <tr class="baris-section"><td colspan="11">Harga perolehan</td></tr>
                      <?php foreach ($ATB_ITEMS as $it): ?>
                        <?php if ($it['type'] === 'header'): ?>
                        <tr>
                          <td class="kode"><?php echo htmlspecialchars($it['kode_hp'] ?? ''); ?></td>
                          <td class="nama-id"><?php echo htmlspecialchars($it['nama_id']); ?></td>
                          <td class="angka">-</td>
                          <td class="angka">-</td>
                          <td class="angka">-</td>
                          <td class="angka">-</td>
                          <td class="angka">-</td>
                          <td class="nama-en"><?php echo htmlspecialchars($it['nama_en']); ?></td>
                        <td colspan="3"></td>
                        </tr>
                        <?php else: $b = $it['hp']; ?>
                        <tr>
                          <td class="kode"><?php echo htmlspecialchars($it['kode_hp'] ?? ''); ?></td>
                          <td class="<?php echo (strpos($it['nama_id'], 'Aset Konsesi -') === 0) ? 'sub-nama-id' : 'nama-id'; ?>"><?php echo htmlspecialchars($it['nama_id']); ?></td>
                          <td class="angka"><?php echo fmtRp($b['awal']); ?></td>
                          <td class="angka"><?php echo fmtRp($b['penambahan']); ?></td>
                          <td class="angka"><?php echo fmtRp($b['pengurangan'], true); ?></td>
                          <td class="angka"><?php echo fmtRp($b['reklasifikasi']); ?></td>
                          <td class="angka"><?php echo fmtRp($b['akhir']); ?></td>
                          <td class="nama-en"><?php echo htmlspecialchars($it['nama_en']); ?></td>
                        <?php echo kkCells('HP', $it['kode_hp'] ?? '', $it['hp']['f01'], $it['hp']['sel_f01']); ?>
                        </tr>
                        <?php endif; ?>
                      <?php endforeach; ?>
                      <tr class="baris-jumlah">
                        <td></td>
                        <td class="nama-id">Jumlah</td>
                        <td class="angka"><?php echo fmtRp($subHP['awal']); ?></td>
                        <td class="angka"><?php echo fmtRp($subHP['penambahan']); ?></td>
                        <td class="angka"><?php echo fmtRp($subHP['pengurangan'], true); ?></td>
                        <td class="angka"><?php echo fmtRp($subHP['reklasifikasi']); ?></td>
                        <td class="angka"><?php echo fmtRp($subHP['akhir']); ?></td>
                        <td class="nama-en">Total</td>
                        <?php echo kkCellsTotal($subHP['f01'], $subHP['sel_f01']); ?>
                      </tr>

                      <!-- ============ AKUMULASI PENYUSUTAN ============ -->
                      <tr class="baris-section"><td colspan="11">Akumulasi penyusutan</td></tr>
                      <?php foreach ($ATB_ITEMS as $it): ?>
                        <?php if ($it['type'] === 'header'): ?>
                        <tr>
                          <td class="kode"><?php echo htmlspecialchars($it['kode_ap'] ?? ''); ?></td>
                          <td class="nama-id"><?php echo htmlspecialchars($it['nama_id']); ?></td>
                          <td class="angka">-</td>
                          <td class="angka">-</td>
                          <td class="angka">-</td>
                          <td class="angka">-</td>
                          <td class="angka">-</td>
                          <td class="nama-en"><?php echo htmlspecialchars($it['nama_en']); ?></td>
                        <td colspan="3"></td>
                        </tr>
                        <?php else: $b = $it['ap']; ?>
                        <tr>
                          <td class="kode"><?php echo htmlspecialchars($it['kode_ap'] ?? ''); ?></td>
                          <td class="<?php echo (strpos($it['nama_id'], 'Aset Konsesi -') === 0) ? 'sub-nama-id' : 'nama-id'; ?>"><?php echo htmlspecialchars($it['nama_id']); ?></td>
                          <td class="angka"><?php echo fmtRp($b['awal'], true); ?></td>
                          <td class="angka"><?php echo fmtRp($b['penambahan'], true); ?></td>
                          <td class="angka"><?php echo fmtRp($b['pengurangan']); ?></td>
                          <td class="angka"><?php echo fmtRp($b['reklasifikasi']); ?></td>
                          <td class="angka"><?php echo fmtRp($b['akhir'], true); ?></td>
                          <td class="nama-en"><?php echo htmlspecialchars($it['nama_en']); ?></td>
                        <?php echo kkCells('AP', $it['kode_ap'] ?? '', $it['ap']['f01'], $it['ap']['sel_f01']); ?>
                        </tr>
                        <?php endif; ?>
                      <?php endforeach; ?>
                      <tr class="baris-jumlah">
                        <td></td>
                        <td class="nama-id">Jumlah</td>
                        <td class="angka"><?php echo fmtRp($subAP['awal'], true); ?></td>
                        <td class="angka"><?php echo fmtRp($subAP['penambahan'], true); ?></td>
                        <td class="angka"><?php echo fmtRp($subAP['pengurangan']); ?></td>
                        <td class="angka"><?php echo fmtRp($subAP['reklasifikasi']); ?></td>
                        <td class="angka"><?php echo fmtRp($subAP['akhir'], true); ?></td>
                        <td class="nama-en">Total</td>
                        <?php echo kkCellsTotal($subAP['f01'], $subAP['sel_f01']); ?>
                      </tr>

                      <!-- ============ PENURUNAN NILAI / CKPN ============ -->
                      <tr class="baris-section"><td colspan="11">Penurunan Nilai</td></tr>
                      <?php foreach ($ATB_ITEMS as $it): ?>
                        <?php if ($it['type'] === 'header'): ?>
                        <tr>
                          <td class="kode"><?php echo htmlspecialchars($it['kode_ckpn'] ?? ''); ?></td>
                          <td class="nama-id"><?php echo htmlspecialchars($it['nama_ckpn_id'] ?? $it['nama_id']); ?></td>
                          <td class="angka">-</td>
                          <td class="angka">-</td>
                          <td class="angka">-</td>
                          <td class="angka">-</td>
                          <td class="angka">-</td>
                          <td class="nama-en"><?php echo htmlspecialchars($it['nama_ckpn_en'] ?? $it['nama_en']); ?></td>
                        <td colspan="3"></td>
                        </tr>
                        <?php elseif (!empty($it['in_ckpn'])): $b = $it['ckpn']; ?>
                        <tr>
                          <td class="kode"><?php echo htmlspecialchars($it['kode_ckpn'] ?? ''); ?></td>
                          <td class="<?php echo (strpos($it['nama_id'], 'Aset Konsesi -') === 0) ? 'sub-nama-id' : 'nama-id'; ?>"><?php echo htmlspecialchars($it['nama_ckpn_id']); ?></td>
                          <td class="angka"><?php echo fmtRp($b['awal']); ?></td>
                          <td class="angka"><?php echo fmtRp($b['penambahan']); ?></td>
                          <td class="angka"><?php echo fmtRp($b['pengurangan']); ?></td>
                          <td class="angka"><?php echo fmtRp($b['reklasifikasi']); ?></td>
                          <td class="angka"><?php echo fmtRp($b['akhir']); ?></td>
                          <td class="nama-en"><?php echo htmlspecialchars($it['nama_ckpn_en']); ?></td>
                        <?php echo kkCells('CKPN', $it['kode_ckpn'] ?? '', $it['ckpn']['f01'], $it['ckpn']['sel_f01']); ?>
                        </tr>
                        <?php endif; ?>
                      <?php endforeach; ?>
                      <tr class="baris-jumlah">
                        <td></td>
                        <td class="nama-id">Jumlah</td>
                        <td class="angka"><?php echo fmtRp($subCKPN['awal']); ?></td>
                        <td class="angka"><?php echo fmtRp($subCKPN['penambahan']); ?></td>
                        <td class="angka"><?php echo fmtRp($subCKPN['pengurangan']); ?></td>
                        <td class="angka"><?php echo fmtRp($subCKPN['reklasifikasi']); ?></td>
                        <td class="angka"><?php echo fmtRp($subCKPN['akhir']); ?></td>
                        <td class="nama-en">Total</td>
                        <?php echo kkCellsTotal($subCKPN['f01'], $subCKPN['sel_f01']); ?>
                      </tr>

                      <!-- ============ NILAI BUKU NETO ============ -->
                      <tr class="baris-neto">
                        <td></td>
                        <td class="nama-id">Nilai buku neto</td>
                        <td class="angka"><?php echo fmtRp($nilaiBukuAwal); ?></td>
                        <td colspan="3"></td>
                        <td class="angka"><?php echo fmtRp($nilaiBukuAkhir); ?></td>
                        <td class="nama-en">Net book value</td>
                        <td colspan="3"></td>
                      </tr>

                    </tbody>
                  </table>
                </div>
              </div>
            </div>

          </div>
        </div>
      </main>
    </div>
    <div class="modal fade" id="kkModalSnapshot" tabindex="-1" aria-labelledby="kkModalSnapshotLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="kkModalSnapshotLabel">
              <i class="bi <?php echo $kkPakaiSnapshot ? 'bi-exclamation-triangle text-warning' : 'bi-save text-primary'; ?>"></i>
              <?php echo $kkPakaiSnapshot ? 'Timpa snapshot?' : 'Simpan snapshot?'; ?>
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
          </div>
          <div class="modal-body">
            <?php if ($kkPakaiSnapshot): ?>
              <p class="mb-2">Angka periode <strong><?php echo htmlspecialchars($labelAkhir); ?></strong> akan dihitung ulang dari data saat ini dan <strong>menimpa snapshot yang sudah tersimpan</strong>.</p>
              <p class="mb-0 small text-muted">Keterangan dan status OK tidak ikut terhapus.</p>
            <?php else: ?>
              <p class="mb-2">Simpan angka periode <strong><?php echo htmlspecialchars($labelAkhir); ?></strong> sebagai snapshot?</p>
              <p class="mb-0 small text-muted">Angka bulan ini akan dikunci, jadi tidak ikut berubah saat data bulan berikutnya di-upload.</p>
            <?php endif; ?>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="button" class="btn <?php echo $kkPakaiSnapshot ? 'btn-warning' : 'btn-primary'; ?>" id="kkBtnKonfirmSnapshot">
              <?php echo $kkPakaiSnapshot ? 'Ya, timpa' : 'Ya, simpan'; ?>
            </button>
          </div>
        </div>
      </div>
    </div>
    <script src="../../dist/js/overlayscrollbars.browser.es6.min.js"></script>
    <script src="../../dist/js/popper.min.js"></script>
    <script src="../../dist/js/bootstrap.min.js"></script>
    <script src="../../dist/js/adminlte.js"></script>
    <script src="../../dist/js/jquery-3.6.0.min.js"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const sidebarWrapper = document.querySelector('.sidebar-wrapper');
        if (sidebarWrapper && OverlayScrollbarsGlobal?.OverlayScrollbars !== undefined) {
          OverlayScrollbarsGlobal.OverlayScrollbars(sidebarWrapper, {
            scrollbars: { theme: 'os-theme-light', autoHide: 'leave', clickScroll: true },
          });
        }
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
        tooltipTriggerList.map(function (el) { return new bootstrap.Tooltip(el); });
      });
    </script>
    <script>
      (function () {
        const modalEl = document.getElementById('kkModalSnapshot');
        const btn = document.getElementById('kkBtnSnapshot');
        const btnOk = document.getElementById('kkBtnKonfirmSnapshot');
        const form = document.getElementById('kkFormSnapshot');
        if (!modalEl || !btn || !btnOk || !form) return;
        const modal = new bootstrap.Modal(modalEl);
        btn.addEventListener('click', () => modal.show());
        btnOk.addEventListener('click', function () {
          btnOk.disabled = true;
          btnOk.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Menyimpan...';
          form.submit();
        });
      })();
      (function () {
        const KK_TAHUN = <?php echo (int)$tahunAkhir; ?>;
        const KK_BULAN = <?php echo (int)$bulanAkhir; ?>;
        function kirim(tr, tandaiSelesai) {
          const inp = tr.querySelector('.kk-ket');
          const btn = tr.querySelector('.kk-ok-btn');
          const teks = inp.value.trim();
          // klik OK: keterangan terisi -> baris ditandai selesai (hijau); keterangan dikosongkan -> tanda dicabut
          const ok = tandaiSelesai ? (teks !== '') : (inp.dataset.ok === '1');
          const fd = new FormData();
          fd.append('aksi', 'simpan_ket');
          fd.append('tahun', KK_TAHUN);
          fd.append('bulan', KK_BULAN);
          fd.append('seksi', inp.dataset.seksi);
          fd.append('kode', inp.dataset.kode);
          fd.append('ket', inp.value);
          if (ok) fd.append('ok', '1');
          btn.disabled = true;
          return fetch(window.location.pathname, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(j => {
              if (j.ok) { inp.dataset.ok = ok ? '1' : '0'; terapkanStatus(tr, ok); inp.dataset.asli = inp.value; }
              else { alert(j.pesan || 'Gagal menyimpan'); }
            })
            .catch(() => alert('Gagal terhubung ke server'))
            .finally(() => { btn.disabled = false; });
        }
        // Batal simpan (tombol X): hapus keterangan + status OK dari DB, kotak dikosongkan
        function batalOk(tr) {
          const inp = tr.querySelector('.kk-ket');
          const fd = new FormData();
          fd.append('aksi', 'hapus_ket');
          fd.append('tahun', KK_TAHUN);
          fd.append('bulan', KK_BULAN);
          fd.append('seksi', inp.dataset.seksi);
          fd.append('kode', inp.dataset.kode);
          return fetch(window.location.pathname, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(j => {
              if (j.ok) { inp.value = ''; inp.dataset.ok = '0'; inp.dataset.asli = ''; terapkanStatus(tr, false); }
              else { alert(j.pesan || 'Gagal membatalkan'); }
            })
            .catch(() => alert('Gagal terhubung ke server'));
        }
        function terapkanStatus(tr, ok) {
          const btn = tr.querySelector('.kk-ok-btn');
          tr.classList.toggle('kk-row-ok', ok);
          btn.classList.toggle('btn-success', ok);
          btn.classList.toggle('btn-primary', !ok);
          btn.innerHTML = ok ? '<i class="bi bi-check-lg"></i> OK' : 'OK';
          const bt = tr.querySelector('.kk-batal-btn');
          if (bt) bt.classList.toggle('d-none', !ok);
        }
        document.querySelectorAll('.tabel-pi tr').forEach(function (tr) {
          const inp = tr.querySelector('.kk-ket');
          const btn = tr.querySelector('.kk-ok-btn');
          if (!inp || !btn) return;
          inp.dataset.asli = inp.value;
          terapkanStatus(tr, inp.dataset.ok === '1');
          btn.addEventListener('click', () => kirim(tr, true));
          const bt = tr.querySelector('.kk-batal-btn');
          if (bt) bt.addEventListener('click', () => batalOk(tr));
          inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); kirim(tr, true); } });
        });
      })();
    </script>
  </body>
</html>