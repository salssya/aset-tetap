<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "asetreg3_db";

$con = mysqli_connect($servername, $username, $password, $dbname);
session_start();
if(!isset($_SESSION["nipp"]) || !isset($_SESSION["name"])) {
    header("Location: ../login/login_view.php");
    exit();
}

if (!isset($PAGE) || !is_array($PAGE) || !isset($PAGE['gid']) || trim((string)$PAGE['gid']) === '') { die('Konfigurasi halaman ($PAGE) tidak ditemukan.'); }
$EMBED = isset($_GET['embed']) && $_GET['embed'] === '1';  
$SHEET_ID   = '1YsOftHDUDS5hreoSS6TVxbUBKXFE_Buz9yxo4sMr_zg';
$CACHE_TTL      = 60;
$REFRESH_MS     = 60000;
$CACHE_DIR      = sys_get_temp_dir();

// ---------- helper: ambil URL ----------
function lahan_http_get($url, &$err) {
    $err = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (DasborPenggunaanLahan)',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        $cno  = curl_errno($ch);
        curl_close($ch);
        if ($body === false) {
            $err = $cerr ?: 'Koneksi ke Google gagal';
            if ($cno === 60 || $cno === 77) {
                $err .= ' (sertifikat SSL: di XAMPP isi curl.cainfo di php.ini dengan path cacert.pem, lalu restart Apache)';
            }
            return false;
        }
        if ($code !== 200) { $err = "Google menjawab HTTP $code"; return false; }
        return $body;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 25, 'header' => "User-Agent: Mozilla/5.0 (DasborPenggunaanLahan)\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) { $err = 'Koneksi ke Google gagal (aktifkan ekstensi cURL atau allow_url_fopen di php.ini)'; return false; }
    return $body;
}

// ---------- helper: angka format Indonesia ("724.120.716.431", "9.024,5", "-") -> float ----------
function lahan_angka($v) {
    $v = trim((string)$v);
    if ($v === '' || $v === '-' || $v === '–') return 0.0;
    $neg = false;
    if (preg_match('/^\((.*)\)$/', $v, $m)) { $neg = true; $v = $m[1]; }
    if (strpos($v, '-') === 0) { $neg = true; $v = substr($v, 1); }
    $v = preg_replace('/[^0-9.,]/', '', $v);
    if ($v === '') return 0.0;
    if (strpos($v, ',') !== false) { $v = str_replace('.', '', $v); $v = str_replace(',', '.', $v); }
    else { $v = str_replace('.', '', $v); }
    $f = (float)$v;
    return $neg ? -$f : $f;
}

// ---------- helper: tanggal campuran "6/7/2022" (b/t/Y) dan "21/4/1997" (t/b/Y) -> Y-m-d, selain itu null ----------
function lahan_tanggal($v) {
    $v = trim((string)$v);
    if (!preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) return null;
    $a = (int)$m[1]; $b = (int)$m[2]; $y = (int)$m[3];
    if ($a > 12) { $d = $a; $mo = $b; } else { $mo = $a; $d = $b; }   // default format Sheets: bulan/tanggal/tahun
    if (!checkdate($mo, $d, $y)) { if (checkdate($b, $a, $y)) { $d = $a; $mo = $b; } else return null; }
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}


// ---------- ambil CSV dari Google Sheets (dengan cache file) ----------
function lahan_ambil_csv($sheetId, $gid, $ttl, $cacheDir, $paksa, &$info) {
    $file = rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR . 'lahan_' . md5($sheetId . '_' . $gid) . '.csv';
    $ada  = is_file($file);
    $umur = $ada ? (time() - filemtime($file)) : PHP_INT_MAX;
    if ($ada && $umur < $ttl && !$paksa) {
        $info = ['sumber' => 'cache', 'umur' => $umur, 'error' => ''];
        return file_get_contents($file);
    }
    $url = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($sheetId) . '/export?format=csv&gid=' . rawurlencode($gid);
    $err = '';
    $body = lahan_http_get($url, $err);
    if ($body !== false && stripos(ltrim($body), '<') === 0) {   // jawaban HTML = halaman login Google (sheet belum dibagikan publik)
        $body = false;
        $err = 'Sheet belum dibagikan. Ubah berbagi ke "Siapa saja yang memiliki link" (Pelihat).';
    }
    if ($body !== false) {
        @file_put_contents($file, $body, LOCK_EX);
        $info = ['sumber' => 'live', 'umur' => 0, 'error' => ''];
        return $body;
    }
    if ($ada) {   // Google gagal -> pakai data terakhir yang tersimpan, tapi kasih tahu
        $info = ['sumber' => 'cache-lama', 'umur' => $umur, 'error' => $err];
        return file_get_contents($file);
    }
    $info = ['sumber' => 'error', 'umur' => 0, 'error' => $err];
    return false;
}


// ---------- CSV -> baris rapi (kolom mengikuti header Sheet secara otomatis) ----------
function lahan_parse_generik($csv, &$cols) {
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    $fh = fopen('php://temp', 'r+'); fwrite($fh, $csv); rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) { $rows[] = $r; }
    fclose($fh);
    $cols = [];
    if (empty($rows)) return [];

    // baris header = baris (dari 15 pertama) yang punya sel "NO"; cadangan: baris dengan sel terisi terbanyak
    $hdr = -1;
    for ($i = 0; $i < min(15, count($rows)) && $hdr < 0; $i++) {
        foreach ($rows[$i] as $cell) { if (strtoupper(trim((string)$cell)) === 'NO') { $hdr = $i; break; } }
    }
    if ($hdr < 0) {
        $best = -1;
        for ($i = 0; $i < min(15, count($rows)); $i++) {
            $c = count(array_filter($rows[$i], function ($x) { return trim((string)$x) !== ''; }));
            if ($c > $best) { $best = $c; $hdr = $i; }
        }
    }

    $idx = []; $noIdx = null;
    foreach ($rows[$hdr] as $ci => $cell) {
        $lbl = trim(preg_replace('/\s+/', ' ', (string)$cell));
        if ($lbl === '') continue;
        $up = strtoupper($lbl);
        if ($up === 'NO' && $noIdx === null) $noIdx = $ci;
        if (preg_match('/\((RP|M2|M²|TON)\)/u', $up) || strpos($up, 'LUAS') === 0) $type = 'num';
        elseif (strpos($up, 'TANGGAL') !== false) $type = 'date';
        else $type = 'text';
        $idx[] = ['ci' => $ci, 'label' => $lbl, 'up' => $up, 'type' => $type];
    }
    if ($noIdx !== null) { foreach ($idx as &$c) { if ($c['ci'] === $noIdx) $c['type'] = 'no'; } unset($c); }

    // baris data
    $data = [];
    for ($i = $hdr + 1; $i < count($rows); $i++) {
        $r = $rows[$i];
        $no = ($noIdx !== null && isset($r[$noIdx])) ? trim($r[$noIdx]) : '';
        if (!preg_match('/^\d+$/', $no)) continue;      
        $data[] = $r;
    }

    // kolom teks yang isinya link -> tipe link
    foreach ($idx as &$c) {
        if ($c['type'] !== 'text') continue;
        $n = 0; $h = 0;
        foreach ($data as $r) {
            $v = isset($r[$c['ci']]) ? trim($r[$c['ci']]) : '';
            if ($v === '') continue;
            $n++; if (preg_match('#^https?://#i', $v)) $h++;
        }
        if (($n > 0 && $h / $n >= 0.5) || (preg_match('/LINK|POLYGON/', $c['up']) && $h > 0)) $c['type'] = 'link';
    }
    unset($c);

    // kolom tanggal mulai/berakhir -> kolom hitungan "Masa Berlaku"
    $startKey = null; $endKey = null; $lastDatePos = -1;
    foreach ($idx as $p => $c) {
        if ($c['type'] !== 'date') continue;
        $lastDatePos = $p;
        if (strpos($c['up'], 'BERAKHIR') !== false && $endKey === null)  $endKey = 'c' . $c['ci'];
        if (strpos($c['up'], 'MULAI')   !== false && $startKey === null) $startKey = 'c' . $c['ci'];
    }
    foreach ($idx as $c) { $cols[] = ['key' => 'c' . $c['ci'], 'label' => $c['label'], 'type' => $c['type']]; }
    if ($endKey !== null) {
        array_splice($cols, $lastDatePos + 1, 0, [['key' => 'masa_berlaku', 'label' => 'Masa Berlaku', 'type' => 'status']]);
    }

    $today = new DateTime('today');
    $out = [];
    foreach ($data as $r) {
        $o = [];
        foreach ($idx as $c) {
            $raw = isset($r[$c['ci']]) ? trim((string)$r[$c['ci']]) : '';
            $k = 'c' . $c['ci'];
            if ($c['type'] === 'no')        $o[$k] = (int)$raw;
            elseif ($c['type'] === 'num')   $o[$k] = ($raw === '' || $raw === '-' || $raw === '–') ? null : lahan_angka($raw);
            elseif ($c['type'] === 'date')  $o[$k] = ($raw === '') ? '' : (lahan_tanggal($raw) ?: $raw);
            else                            $o[$k] = $raw;
        }
        if ($endKey !== null) {
            $e = $o[$endKey]; $s = $startKey !== null ? $o[$startKey] : '';
            $isIso = function ($v) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v); };
            if ($e === '' || $e === '-')       $st = 'Tidak ada data';
            elseif (!$isIso($e))               $st = 'Selama dipergunakan';
            else {
                $d = new DateTime($e);
                if ($s !== '' && $isIso($s) && $d < new DateTime($s)) $st = 'Cek tanggal';
                else {
                    $sel = (int)$today->diff($d)->format('%r%a');
                    if ($sel < 0)        $st = 'Sudah berakhir';
                    elseif ($sel <= 365) $st = 'Berakhir ≤ 1 tahun';
                    else                 $st = 'Masih berlaku';
                }
            }
            $o['masa_berlaku'] = $st;
        }
        $out[] = $o;
    }
    return $out;
}

// ---------- endpoint JSON ----------
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'data') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $paksa = isset($_GET['fresh']) && $_GET['fresh'] === '1';
    $info = [];
    $csv = lahan_ambil_csv($SHEET_ID, $PAGE['gid'], $CACHE_TTL, $CACHE_DIR, $paksa, $info);
    if ($csv === false) { echo json_encode(['ok' => false, 'sumber' => 'error', 'error' => $info['error']]); exit; }
    $cols = [];
    $rows = lahan_parse_generik($csv, $cols);
    echo json_encode([
        'ok' => true, 'sumber' => $info['sumber'], 'umur' => $info['umur'], 'peringatan' => $info['error'],
        'tab' => $PAGE['tab'], 'diambil' => date('c'), 'jumlah' => count($rows), 'cols' => $cols, 'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
?>

<!doctype html>
<html lang="en">
  <!--begin::Head-->
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title><?php echo htmlspecialchars($PAGE['judul']); ?> - Web Aset Tetap</title>
    <link rel="icon" type="image/png" href="../../dist/assets/img/emblem.png" /> 
    <link rel="shortcut icon" type="image/png" href="../../dist/assets/img/emblem.png" />  
    <!--begin::Accessibility Meta Tags-->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes" />
    <meta name="color-scheme" content="light dark" />
    <meta name="theme-color" content="#007bff" media="(prefers-color-scheme: light)" />
    <meta name="theme-color" content="#1a1a1a" media="(prefers-color-scheme: dark)" />
    <!--end::Accessibility Meta Tags-->
    <!--begin::Fonts-->
    <link rel="stylesheet" href="../../dist/css/index.css"/>
      <link rel="stylesheet" href="../../dist/css/overlayscrollbars.min.css"/>
      <link rel="stylesheet" href="../../dist/css/bootstrap-icons/bootstrap-icons.min.css"/>
      <link rel="stylesheet" href="../../dist/css/adminlte.css" />
    <!-- Custom Styles -->
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
      #tableDatAset thead th,
      #tableDatAset tbody td,
      #tableAr02 thead th,
      #tableAr02 tbody td,
      #tableFagll thead th,
      #tableFagll tbody td,
      #tableF01 thead th,
      #tableF01 tbody td,
      #tableCoa thead th,
      #tableCoa tbody td {
        padding: 8px 12px;
        white-space: nowrap;
        min-width: 130px;
      }
      #tableDatAset thead th,
      #tableAr02 thead th,
      #tableFagll thead th,
      #tableF01 thead th,
      #tableCoa thead th {
        background-color: #f8f9fa;
        font-weight: 600;
        border-bottom: 2px solid #dee2e6;
      }
      #tableDatAset tbody td,
      #tableAr02 tbody td,
      #tableFagll tbody td,
      #tableF01 tbody td,
      #tableCoa tbody td {
        border-bottom: 1px solid #dee2e6;
      }
      .nav-tabs .nav-link.active {
        font-weight: 600;
        border-bottom: 3px solid #0b3a8c;
      }

      /* ===== Perapihan sidebar ===== */
      .app-sidebar .nav-link p {
        white-space: normal !important;
        overflow: visible !important;
        text-overflow: clip !important;
        line-height: 1.25;
        margin: 0;
        padding-right: 1.5rem;
      }
      .app-sidebar .nav-link .nav-arrow {
        position: absolute;
        right: .9rem;
        top: 50%;
        transform: translateY(-50%);
        transition: transform .2s ease;
      }
      .app-sidebar .menu-open > .nav-link .nav-arrow {
        transform: translateY(-50%) rotate(90deg);
      }
      .app-sidebar .nav-link:hover {
        background-color: rgba(255,255,255,.12) !important;
      }
      /* induk grup yang sedang terbuka: tidak ikut biru terang, cukup halus */
      .app-sidebar .nav-item.menu-open > .nav-link.active {
        background-color: rgba(255,255,255,.12) !important;
      }
      /* submenu: menjorok, latar lebih gelap, font sedikit lebih kecil */
      .app-sidebar .nav-treeview {
        background-color: rgba(0,0,0,.18);
        border-radius: .375rem;
        margin: .15rem 0 .4rem;
        padding: .25rem 0;
      }
      .app-sidebar .nav-treeview > .nav-item > .nav-link {
        padding-left: 2.6rem;
        font-size: .9rem;
      }
      .app-sidebar .nav-treeview > .nav-item > .nav-link .nav-icon {
        font-size: .95rem;
      }
      /* hanya halaman yang sedang dibuka yang biru terang */
      .app-sidebar .nav-treeview > .nav-item > .nav-link.active {
        background-color: #0b5db7 !important;
      }
    </style>
    <!--end::Custom Styles-->
    <link rel="stylesheet" href="../../dist/css/dataTables.dataTables.min.css" />

    <style>
      .kpi-card .kpi-label { font-size: .78rem; color: #6c757d; text-transform: uppercase; letter-spacing: .02em; }
      .kpi-card .kpi-val { font-size: 1.45rem; font-weight: 700; line-height: 1.2; }
      .kpi-card .kpi-sub { font-size: .75rem; color: #6c757d; }
      .bar-row { display: flex; align-items: center; gap: .6rem; margin-bottom: .45rem; font-size: .85rem; }
      .bar-label { flex: 0 0 34%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
      .bar-track { flex: 1 1 auto; background: #e9ecef; border-radius: 4px; height: 16px; overflow: hidden; }
      .bar-fill { height: 100%; border-radius: 4px; background: #0b3a8c; }
      .bar-val { flex: 0 0 22%; text-align: right; font-variant-numeric: tabular-nums; }
      #tabelLahan thead th { white-space: nowrap; }
      #tabelLahan td.text-end { text-align: right; }
      /* ===== Tampilan kartu ===== */
      .app-content > .container-fluid > .card { border: 1px solid #e6ebf2; border-radius: .75rem; box-shadow: 0 1px 3px rgba(16,24,40,.06); }
      .lahan-status { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; background: #fff; border: 1px solid #e6ebf2; border-radius: .75rem; padding: .6rem 1rem; box-shadow: 0 1px 3px rgba(16,24,40,.05); }
      .badge-live { display: inline-flex; align-items: center; gap: .45rem; padding: .28rem .8rem; border-radius: 2rem; font-size: .8rem; font-weight: 700; background: #e9ecef; color: #495057; cursor: default; }
      .badge-live .dot { width: 8px; height: 8px; border-radius: 50%; background: #adb5bd; display: inline-block; }
      .badge-live.is-live  { background: #e7f6ee; color: #157347; }
      .badge-live.is-live .dot { background: #198754; animation: lahanPulse 1.6s infinite; }
      .badge-live.is-stale { background: #fff4e0; color: #a15c00; }
      .badge-live.is-stale .dot { background: #fd7e14; }
      .badge-live.is-error { background: #fdecec; color: #b02a37; }
      .badge-live.is-error .dot { background: #dc3545; }
      @keyframes lahanPulse { 0% { box-shadow: 0 0 0 0 rgba(25,135,84,.5); } 70% { box-shadow: 0 0 0 7px rgba(25,135,84,0); } 100% { box-shadow: 0 0 0 0 rgba(25,135,84,0); } }
      .lahan-filter { overflow: hidden; }
      .lahan-filter-head { display: flex; align-items: center; gap: .5rem; padding: .6rem 1rem; background: #f5f8fd; border-bottom: 1px solid #e6ebf2; font-weight: 700; font-size: .85rem; color: #0b3a8c; }
      .lahan-lab { display: block; font-size: .7rem; font-weight: 600; letter-spacing: .03em; text-transform: uppercase; color: #6b7788; margin-bottom: .25rem; }
      .lahan-filter .form-select { border-radius: .5rem; border-color: #d5dde9; }
      .lahan-filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 1rem; }   /* berapa pun jumlah filter, otomatis memenuhi satu baris */
      .lahan-filter-item { min-width: 0; }
      .kpi-card2 { display: flex; align-items: center; gap: .9rem; background: #fff; border: 1px solid #e6ebf2; border-radius: .75rem; padding: .9rem 1rem; box-shadow: 0 1px 3px rgba(16,24,40,.06); position: relative; overflow: hidden; height: 100%; transition: box-shadow .15s, transform .15s; }
      .kpi-card2::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: var(--c); }
      .kpi-card2:hover { box-shadow: 0 6px 16px rgba(16,24,40,.10); transform: translateY(-1px); }
      .kpi-ico { flex: 0 0 46px; width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; color: var(--c); background: #eef3fb; background: color-mix(in srgb, var(--c) 12%, #fff); }
      .kpi-body { min-width: 0; }
      .kpi-card2 .kpi-label { font-size: .72rem; letter-spacing: .03em; text-transform: uppercase; color: #6b7788; font-weight: 600; line-height: 1.25; }
      .kpi-card2 .kpi-val { font-size: 1.4rem; font-weight: 700; color: #1f2937; line-height: 1.25; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    </style>
<?php if ($EMBED): ?>
    <style>
      .app-sidebar, .app-header, .app-footer, .app-content-header { display: none !important; }
      html, body { height: auto !important; min-height: 0 !important; background: transparent !important; overflow-y: hidden; }
      .app-wrapper { display: block !important; height: auto !important; min-height: 0 !important; }
      .app-main { margin: 0 !important; min-height: 0 !important; padding: 0 !important; }
      .app-content { padding-top: 0 !important; }
    </style>
<?php endif; ?>
  </head>
  <body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <!--begin::App Wrapper-->
    <div class="app-wrapper">
      <!--begin::Header-->
      <nav class="app-header navbar navbar-expand bg-white border-0 shadow-none" style="border-bottom:0!important;box-shadow:none!important;">
        <div class="container-fluid">
          <ul class="navbar-nav ms-auto">
            <li class="nav-item dropdown user-menu">
              <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                <img src="../../dist/assets/img/profile.png" 
                    class="user-image rounded-circle shadow" alt="User Image"/>
                <span class="d-none d-md-inline">
                  <?php echo htmlspecialchars($_SESSION['name']); ?>
                </span>
              </a>
              <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                <li class="user-header text-bg-primary text-center">
                  <img src="../../dist/assets/img/profile.png" 
                      class="rounded-circle shadow mb-2" alt="User Image" style="width:80px;height:80px;">
                  <p class="mb-0 fw-bold"><?php echo htmlspecialchars($_SESSION['name']); ?></p>
                  <small>NIPP: <?php echo htmlspecialchars($_SESSION['nipp']); ?></small>
                </li>
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
                  <li class="user-footer d-flex align-items-center px-3 py-2">
                    <a href="../profile/profile.php" class="btn btn-sm btn-outline-primary">
                      <i class="bi bi-person"></i> Profile
                    </a>
                    <a href="../login/login_view.php" class="btn btn-sm btn-danger ms-auto">
                      <i class="bi bi-box-arrow-right"></i> Logout
                    </a>
                  </li>
                </ul>
          </ul>
        </div>
      </nav>
      <!--end::Header-->
      <!--begin::Sidebar-->
      <aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
        <div class="sidebar-brand">
          <a href="./index.html" class="brand-link">
            <img
              src="../../dist/assets/img/logo.png"
              class="brand-image"
              alt="Logo Pelindo"
              title="PT Pelabuhan Indonesia"
            />
          </a>
        </div>
        <div class="sidebar-wrapper">
          <nav class="mt-2">
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
                'Dasboard'                           => 'bi bi-grid-fill', 

                'Usulan Penghapusan'                 => 'bi bi-file-earmark-plus', 
                'Daftar Usulan Penghapusan'          => 'bi bi-collection',
                'Approval SubReg'                    => 'bi bi-person-check', 
                'Approval Regional'                  => 'bi bi-building-check', 
                'Persetujuan Penghapusan'            => 'bi bi-shield-check',
                'Pelaksanaan Penghapusan'            => 'bi bi-gear-wide-connected', 
                'Daftar Pelaksanaan Penghapusan'     => 'bi bi-archive-fill',
                'Daftar Aset Tetap'                  => 'bi bi-boxes',    

                'Import Data Penyusutan'             => 'bi bi-upload', 
                'Daftar Data Penyusutan'             => 'bi bi-table', 
                'Dasbor Monitoring Beban Penyusutan' => 'bi-bar-chart-line',

                'Import Data Monitoring'             => 'bi bi-upload', 
                'Daftar Data Monitoring'             => 'bi bi-table', 
                'Dasbor Mutasi Aset Tetap'           => 'bi bi-speedometer2',
                'Dasbor Selisih Percabang'           => 'bi bi-card-checklist', 
                'Dasbor Mutasi Aset Tak Berwujud'    => 'bi bi-file-earmark-text',
                'Dasbor Mutasi Properti Investasi'   => 'bi bi-house-fill',

                'Lahan dan Sertifikasi'              => 'bi bi-geo-alt-fill',
                'Lahan Kerja Sama Eksternal'         => 'bi bi-people-fill',
                'Lahan Kerja Sama Internal'          => 'bi bi-people',
                'Lahan Idle (Komersial)'             => 'bi bi-house-door-fill',
                'Lahan Bermasalah'                   => 'bi bi-exclamation-triangle-fill',
                'Lahan untuk Bangunan Internal'      => 'bi bi-building',
                'Lahan untuk Bangunan Operasional'   => 'bi bi-building-fill',
                'Lahan untuk Bangunan Komersial'     => 'bi bi-shop',
                'Lahan untuk Rumah Dinas'            => 'bi bi-house-fill',

                'Import DAT'                         => 'bi bi-upload',
                'Manajemen Menu'                     => 'bi bi-layout-text-sidebar', 
                'Manajemen User'                     => 'bi bi-people',
            ];
            $groupMap = [
                'Usulan Penghapusan'                 => 'Penghapusan Aset', 
                'Daftar Usulan Penghapusan'          => 'Penghapusan Aset', 
                'Approval SubReg'                    => 'Penghapusan Aset',
                'Approval Regional'                  => 'Penghapusan Aset', 
                'Persetujuan Penghapusan'            => 'Penghapusan Aset', 
                'Daftar Persetujuan Penghapusan'     => 'Penghapusan Aset',
                'Pelaksanaan Penghapusan'            => 'Penghapusan Aset', 
                'Daftar Aset Tetap'                  => 'Penghapusan Aset', 
                'Daftar Pelaksanaan Penghapusan'     => 'Penghapusan Aset',

                'Import Data Penyusutan'             => 'Penyusutan Aset', 
                'Daftar Data Penyusutan'             => 'Penyusutan Aset', 
                'Dasbor Monitoring Beban Penyusutan' => 'Penyusutan Aset',

                'Import Data Monitoring'             => 'Monitoring Aset Tetap', 
                'Daftar Data Monitoring'             => 'Monitoring Aset Tetap', 
                'Dasbor Mutasi Aset Tetap'           => 'Monitoring Aset Tetap',
                'Dasbor Selisih Percabang'           => 'Monitoring Aset Tetap', 
                'Dasbor Mutasi Aset Tak Berwujud'    => 'Monitoring Aset Tetap',
                'Dasbor Mutasi Properti Investasi'   => 'Monitoring Aset Tetap',


                'Lahan dan Sertifikasi'              => 'Monitoring Penggunaan Lahan',
                'Lahan Kerja Sama Eksternal'         => 'Monitoring Penggunaan Lahan',
                'Lahan Kerja Sama Internal'          => 'Monitoring Penggunaan Lahan',
                'Lahan Idle (Komersial)'             => 'Monitoring Penggunaan Lahan',
                'Lahan Bermasalah'                   => 'Monitoring Penggunaan Lahan',
                'Lahan untuk Bangunan Internal'      => 'Monitoring Penggunaan Lahan',
                'Lahan untuk Bangunan Operasional'   => 'Monitoring Penggunaan Lahan',
                'Lahan untuk Bangunan Komersial'     => 'Monitoring Penggunaan Lahan',
                'Lahan untuk Rumah Dinas'            => 'Monitoring Penggunaan Lahan',

                'Import DAT'                         => 'Administrasi Sistem', 
                'Manajemen Menu'                     => 'Administrasi Sistem', 
                'Manajemen User'                     => 'Administrasi Sistem',
            ];
            $groupIcon = [
                'Penghapusan Aset'                   => 'bi bi-file-earmark-minus', 
                'Penyusutan Aset'                    => 'bi bi-graph-down-arrow',
                'Monitoring Aset Tetap'              => 'bi bi-arrow-left-right', 
                'Monitoring Penggunaan Lahan'        => 'bi bi-map',
                'Administrasi Sistem'                => 'bi bi-sliders',
            ];
            $groupOrder = ['Penghapusan Aset', 'Penyusutan Aset', 'Monitoring Aset Tetap', 'Monitoring Penggunaan Lahan', 'Administrasi Sistem'];

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
                if (empty($grouped[$groupName])) continue; 

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
      <!--end::Sidebar-->

      <!--begin::App Main-->
      <main class="app-main">
        <div class="app-content-header">
          <div class="container-fluid">
            <div class="row">
              <div class="col-sm-6"><h3 class="mb-0"><?php echo htmlspecialchars($PAGE['judul']); ?></h3></div>
              <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                  <li class="breadcrumb-item"><a href="../dasbor/dasbor.php">Home</a></li>
                  <li class="breadcrumb-item">Monitoring Penggunaan Lahan</li>
                  <li class="breadcrumb-item active"><?php echo htmlspecialchars($PAGE['judul']); ?></li>
                </ol>
              </div>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">

            <!-- Status sumber data -->
            <div class="lahan-status mb-3">
              <span id="badgeSumber" class="badge-live"><i class="dot"></i>Memuat…</span>
              <div class="ms-auto d-flex align-items-center gap-3 flex-wrap">
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" id="autoRefresh" checked>
                  <label class="form-check-label small" for="autoRefresh">Auto-refresh</label>
                </div>
                <button type="button" class="btn btn-sm btn-primary" id="btnMuat"><i class="bi bi-arrow-repeat"></i> Muat ulang</button>
                <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="https://docs.google.com/spreadsheets/d/<?php echo htmlspecialchars($SHEET_ID); ?>/edit?gid=<?php echo htmlspecialchars($PAGE['gid']); ?>#gid=<?php echo htmlspecialchars($PAGE['gid']); ?>"><i class="bi bi-box-arrow-up-right"></i> Buka Sheet</a>
              </div>
            </div>
            <div id="alertError" class="alert alert-danger d-none"></div>

            <!-- Filter (dibangun otomatis dari kolom Sheet) -->
            <div class="card lahan-filter mb-3">
              <div class="lahan-filter-head"><i class="bi bi-funnel-fill"></i><span>Filter data</span>
                <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="btnReset"><i class="bi bi-arrow-counterclockwise"></i> Reset filter</button>
              </div>
              <div class="card-body py-3"><div class="lahan-filter-grid" id="filterRow"></div></div>
            </div>

            <!-- KPI -->
            <div class="row g-3 mb-3" id="kpiRow"></div>


            <!-- Tabel -->
            <div class="card mb-4">
              <div class="card-header"><div class="card-title">Daftar <?php echo htmlspecialchars($PAGE['judul']); ?></div></div>
              <div class="card-body">
                <table id="tabelLahan" class="display nowrap" style="width:100%">
                  <thead></thead>
                  <tbody></tbody>
                </table>
              </div>
            </div>

          </div>
        </div>
      </main>

      <footer class="app-footer">
        <div class="float-end d-none d-sm-inline">PT Pelabuhan Indonesia (Persero)</div>
        <strong>
          Copyright &copy; Proyek Aset Tetap Regional&nbsp;
        </strong>
      </footer>
    </div>
    <!--end::App Wrapper-->
    <!--begin::Script-->
    <script src="../../dist/js/overlayscrollbars.browser.es6.min.js"></script>
    <script src="../../dist/js/popper.min.js"></script>
    <script src="../../dist/js/bootstrap.min.js"></script>
    <script src="../../dist/js/adminlte.js"></script>
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
      });
    </script>
    <script src="../../dist/js/jquery-3.6.0.min.js"></script>
    <script src="../../dist/js/dataTables.js"></script>
    <script src="../../dist/js/dataTables.responsive.js"></script>
    <script src="../../dist/js/dataTables.buttons.js"></script>
    <script src="../../dist/js/buttons.html5.js"></script>
    <script src="../../dist/js/buttons.print.js"></script>
    <script src="../../dist/js/jszip.min.js"></script>
    <script src="../../dist/js/pdfmake.min.js"></script>
    <script src="../../dist/js/vfs_fonts.min.js"></script>

    <script>
      const REFRESH_MS = <?php echo (int)$REFRESH_MS; ?>;
      const ENDPOINT = location.pathname;
      const PAGE_FILTER = <?php echo json_encode(array_values($PAGE['filter'] ?? []), JSON_UNESCAPED_UNICODE); ?>;
      const PAGE_FILTER_KECUALI = <?php echo json_encode(array_values($PAGE['filter_kecuali'] ?? []), JSON_UNESCAPED_UNICODE); ?>;   // kolom yang tidak dijadikan filter (awalan nama header)
      const LABEL_JUMLAH = <?php echo json_encode($PAGE['label_jumlah'] ?? 'Jumlah Data', JSON_UNESCAPED_UNICODE); ?>;

      let COLS = [], FILTERCOLS = [], signature = '';
      let allRows = [], viewRows = [], dt = null, timer = null, tick = null, loading = false, lockMuat = false, sisaDetik = 0;

      const fmtInt = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 });
      const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
      const safeUrl = u => /^https?:\/\//i.test(u || '') ? u : '';
      const sum = (arr, k) => arr.reduce((t, r) => t + (Number(r[k]) || 0), 0);
      const U = c => c.label.toUpperCase();
      const colBy = lbl => COLS.find(c => U(c) === lbl);
      function rp(n) { return 'Rp ' + fmtInt.format(Math.round(n)); }
      function rpSingkat(n) {
        const a = Math.abs(n), s = n < 0 ? '-' : '';
        const f = (v, u) => s + 'Rp ' + (v).toLocaleString('id-ID', { maximumFractionDigits: 2 }) + ' ' + u;
        if (a >= 1e12) return f(a / 1e12, 'T');
        if (a >= 1e9)  return f(a / 1e9, 'M');
        if (a >= 1e6)  return f(a / 1e6, 'Jt');
        return s + 'Rp ' + fmtInt.format(a);
      }
      // "LUASAN PENGGUNAAN (M2)" -> "Luasan Penggunaan (m²)"
      function judulKolom(l) {
        return l.toLowerCase().replace(/\b\w/g, m => m.toUpperCase())
          .replace(/\(M2\)/i, '(m²)').replace(/\(Rp\)/i, '(Rp)').replace(/\(Ton\)/i, '(Ton)')
          .replace(/\bPbb\b/g, 'PBB').replace(/\bDat\b/g, 'DAT').replace(/\bSpjm\b|\bSpmt\b|\bSpsl\b|\bSptp\b/g, m => m.toUpperCase());
      }
      const fmtTgl = d => /^\d{4}-\d{2}-\d{2}$/.test(d || '') ? d.slice(8) + '/' + d.slice(5, 7) + '/' + d.slice(0, 4) : (d || '');

      // ---------- ambil data ----------
      async function muatData(paksa) {
        if (loading) return;
        loading = true;
        try {
          const qs = new URLSearchParams(location.search); qs.set('ajax_action', 'data'); if (paksa) qs.set('fresh', '1'); else qs.delete('fresh');
          const res = await fetch(ENDPOINT + '?' + qs.toString(), { credentials: 'same-origin', cache: 'no-store' });
          const txt = await res.text();
          let j;
          try { j = JSON.parse(txt); } catch (e) { throw new Error('Respons server bukan JSON (cek error PHP / sesi login).'); }
          if (!j.ok) throw new Error(j.error || 'Gagal mengambil data');
          allRows = j.rows || [];
          const sig = JSON.stringify(j.cols.map(c => c.key + '|' + c.label));
          if (sig !== signature) { COLS = j.cols; signature = sig; bangunStruktur(); }
          tampilStatus(j);
          document.getElementById('alertError').classList.add('d-none');
          isiFilter();
          terapkan();
        } catch (e) {
          setBadge('is-error', 'Gagal', e.message);
          const al = document.getElementById('alertError');
          al.textContent = 'Tidak bisa mengambil data dari Google Sheets: ' + e.message + (allRows.length ? ' (menampilkan data terakhir yang berhasil dimuat)' : '');
          al.classList.remove('d-none');
        } finally {
          loading = false;
          jadwalkan();
        }
      }
      function setBadge(kls, teks, tip) {
        const b = document.getElementById('badgeSumber');
        b.className = 'badge-live ' + kls;
        b.innerHTML = '<i class="dot"></i>' + esc(teks);
        b.title = tip || '';
      }
      function tampilStatus(j) {          // hanya tulisan "Live"; detail (waktu, jumlah baris) ada di tooltip
        const jam = new Date().toLocaleTimeString('id-ID');
        const info = j.tab + ' — ' + fmtInt.format(j.jumlah) + ' baris — dimuat ' + jam + (j.peringatan ? ' — peringatan: ' + j.peringatan : '');
        if (j.sumber === 'live' || j.sumber === 'cache') setBadge('is-live', 'Live', info);
        else setBadge('is-stale', 'Data lama', info);
      }
      function jadwalkan() {
        clearTimeout(timer); clearInterval(tick);
        const cb = document.getElementById('autoRefresh');
        if (!cb.checked) return;
        sisaDetik = Math.round(REFRESH_MS / 1000);
        tick = setInterval(() => { sisaDetik = Math.max(0, sisaDetik - 1); }, 1000);
        timer = setTimeout(() => { if (document.visibilityState === 'visible') muatData(false); else jadwalkan(); }, REFRESH_MS);
      }

      // ---------- struktur halaman (filter, grafik, header tabel) dari kolom Sheet ----------
      function pilihFilter() {
        const out = [];
        const add = c => { if (c && !out.includes(c)) out.push(c); };
        if (PAGE_FILTER.length) {
          PAGE_FILTER.forEach(l => add(colBy(String(l).toUpperCase())));
        } else {
          add(colBy('CABANG')); add(colBy('PROVINSI'));
          const skip = /^(NO|REGIONAL|CABANG|PROVINSI)$|NOMOR|NAMA|KABUPATEN|KECAMATAN|KELURAHAN|JALAN|KOORDINAT|KORDINAT|POLYGON|PIHAK PENYEWA|KETERANGAN|TITIK/;
          let n = 0;
          for (const c of COLS) {
            if (n >= 3) break;
            if (c.type !== 'text' || skip.test(U(c)) || PAGE_FILTER_KECUALI.some(x => U(c).startsWith(String(x).toUpperCase()))) continue;
            const d = new Set(allRows.map(r => r[c.key]).filter(v => v));
            if (d.size >= 2 && d.size <= 20) { add(c); n++; }
          }
        }
        add(COLS.find(c => c.type === 'status'));
        return out.filter(Boolean);
      }
      function bangunStruktur() {
        // filter
        FILTERCOLS = pilihFilter();
        document.getElementById('filterRow').innerHTML = FILTERCOLS.map(c =>
          '<div class="lahan-filter-item"><label class="lahan-lab">' + esc(judulKolom(c.label)) + '</label><select id="f_' + c.key + '" class="form-select form-select-sm"></select></div>').join('');
        FILTERCOLS.forEach(c => document.getElementById('f_' + c.key).addEventListener('change', terapkan));
        document.getElementById('btnReset').onclick = () => { FILTERCOLS.forEach(c => document.getElementById('f_' + c.key).value = ''); terapkan(); };

        // header tabel
        if (dt) { dt.destroy(); dt = null; }
        $('#tabelLahan').empty().append('<thead></thead><tbody></tbody>');
        $('#tabelLahan thead').html('<tr>' + COLS.map(c => '<th>' + esc(c.type === 'no' ? 'No' : judulKolom(c.label)) + '</th>').join('') + '</tr>');
      }

      // ---------- filter ----------
      function isiFilter() {
        for (const c of FILTERCOLS) {
          const el = document.getElementById('f_' + c.key), cur = el.value;
          const vals = [...new Set(allRows.map(r => r[c.key]).filter(v => v !== '' && v != null))].sort((a, b) => String(a).localeCompare(String(b), 'id'));
          el.innerHTML = '<option value="">Semua</option>' + vals.map(v => '<option value="' + esc(v) + '">' + esc(v) + '</option>').join('');
          if (vals.includes(cur)) el.value = cur;
        }
      }
      function terapkan() {
        const f = {}; for (const c of FILTERCOLS) f[c.key] = document.getElementById('f_' + c.key).value;
        viewRows = allRows.filter(r => { for (const c of FILTERCOLS) { if (f[c.key] && r[c.key] !== f[c.key]) return false; } return true; });
        renderKpi(); renderTabel();
      }

      // ---------- KPI ----------
      function ikonKpi(label) {
        const u = String(label).toUpperCase();
        if (/JUMLAH/.test(u))                     return ['bi-collection-fill', '#0b3a8c'];
        if (/LUAS/.test(u))                       return ['bi-rulers', '#198754'];
        if (/BEBAN|PBB/.test(u))                  return ['bi-receipt', '#fd7e14'];
        if (/BUKU/.test(u))                       return ['bi-journal-text', '#6f42c1'];
        if (/PEROLEHAN/.test(u))                  return ['bi-bag-check-fill', '#0d9aa8'];
        if (/KERJASAMA|SEWA|POTENSI|PENDAPATAN/.test(u)) return ['bi-graph-up-arrow', '#dc3545'];
        if (/NILAI|BIAYA/.test(u))                return ['bi-cash-stack', '#0d6efd'];
        return ['bi-bar-chart-fill', '#0b3a8c'];
      }
      function renderKpi() {
        const kartu = [{ l: LABEL_JUMLAH, v: fmtInt.format(viewRows.length), full: '' }];
        COLS.filter(c => c.type === 'num' && /LUAS|NILAI|BEBAN|BIAYA|POTENSI/.test(U(c))).slice(0, 8).forEach(c => {
          const s = sum(viewRows, c.key), isRp = /\(RP\)/i.test(c.label);
          kartu.push({ l: judulKolom(c.label), v: isRp ? rpSingkat(s) : fmtInt.format(s), full: isRp ? rp(s) : '' });
        });
        document.getElementById('kpiRow').innerHTML = kartu.map(k => {
          const [ikon, warna] = ikonKpi(k.l);
          return '<div class="col-6 col-lg-3"><div class="kpi-card2" style="--c:' + warna + '"><div class="kpi-ico"><i class="bi ' + ikon + '"></i></div>' +
            '<div class="kpi-body"><div class="kpi-label">' + esc(k.l) + '</div><div class="kpi-val" title="' + esc(k.full || k.v) + '">' + esc(k.v) + '</div></div></div></div>';
        }).join('');
      }

      // ---------- tabel ----------
      const kolText = k => ({ data: k, render: (d, t) => t === 'display' ? esc(d) : (d == null ? '' : d) });
      const kolLink = (k, label) => ({ data: k, orderable: false, render: (d, t) => (t === 'display' ? (safeUrl(d) ? '<a href="' + esc(safeUrl(d)) + '" target="_blank" rel="noopener noreferrer">' + label + '</a>' : (d ? esc(d) : '-')) : (d || '')) });
      const WARNA_BADGE = { 'Masih berlaku': 'success', 'Selama dipergunakan': 'primary', 'Berakhir ≤ 1 tahun': 'warning', 'Sudah berakhir': 'danger', 'Cek tanggal': 'dark', 'Tidak ada data': 'secondary' };
      function kolDef(c) {
        if (c.type === 'no')     return { data: c.key, defaultContent: '' };
        if (c.type === 'num')    return { data: c.key, className: 'text-end', render: (d, t) => t === 'display' ? (d == null ? '' : fmtInt.format(d)) : (d == null ? 0 : d) };
        if (c.type === 'link')   return kolLink(c.key, 'Buka');
        if (c.type === 'date')   return { data: c.key, render: (d, t) => t === 'display' ? esc(fmtTgl(d)) : (d || '') };
        if (c.type === 'status') return { data: c.key, render: (d, t) => t === 'display' ? '<span class="badge text-bg-' + (WARNA_BADGE[d] || 'secondary') + '">' + esc(d) + '</span>' : d };
        return kolText(c.key);
      }
      function renderTabel() {
        if (!dt) {
          dt = $('#tabelLahan').DataTable({
            data: viewRows, deferRender: true, scrollX: true, pageLength: 10, order: [],
            columns: COLS.map(kolDef),
            language: {
              search: 'Cari:', lengthMenu: 'Tampilkan _MENU_ entri', info: 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
              infoEmpty: 'Tidak ada data', emptyTable: 'Tidak ada data', zeroRecords: 'Tidak ada data yang cocok',
              infoFiltered: '(disaring dari _MAX_ entri keseluruhan)',
              paginate: { first: 'Pertama', previous: 'Sebelumnya', next: 'Selanjutnya', last: 'Terakhir' },
            },
          });
        } else {
          dt.clear().rows.add(viewRows).draw(false);   
        }
      }

      // ---------- event ----------
      document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('btnMuat').addEventListener('click', () => {
          if (lockMuat) return; lockMuat = true; setTimeout(() => lockMuat = false, 5000);
          muatData(true);
        });
        document.getElementById('autoRefresh').addEventListener('change', jadwalkan);
        document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && document.getElementById('autoRefresh').checked && sisaDetik <= 0) muatData(false); });
        muatData(false);
      });
    </script>
<?php if ($EMBED): ?>
    <script>
      (function () {
        var el = document.querySelector('.app-main') || document.body;
        function kirim() {
          var h = Math.ceil(el.getBoundingClientRect().height);
          try { parent.postMessage({ lahanEmbedHeight: h }, location.origin); } catch (e) {}
        }
        if (window.ResizeObserver) { new ResizeObserver(kirim).observe(el); }
        window.addEventListener('load', kirim);
        setInterval(kirim, 1500);
      })();
    </script>
<?php endif; ?>
  </body>
</html>