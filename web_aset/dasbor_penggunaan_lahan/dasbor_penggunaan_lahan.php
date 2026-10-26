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

$SHEET_ID   = '1YsOftHDUDS5hreoSS6TVxbUBKXFE_Buz9yxo4sMr_zg';
$SHEET_TABS = [
    '260245505' => 'Rekap Penggunaan Lahan',
];

$TAB_PENGGUNA = [
    '1530979140' => 'Lahan Kerja Sama Eksternal',
    '1644269457' => 'Lahan Kerja Sama Internal',
    '120535048'  => 'Lahan Idle (Komersial)',
    '1729189989' => 'Lahan Bermasalah',
    '2140122815' => 'Lahan untuk Bangunan Internal',
    '1059231650' => 'Lahan untuk Bangunan Operasional',
    '446578132'  => 'Lahan untuk Bangunan Komersial',
    '1580003382' => 'Lahan untuk Rumah Dinas',
];
$LAHAN_TABS = require __DIR__ . '/lahan_tabs_config.php';  
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

// ---------- normalisasi teks header ----------
function lahan_norm($s) { return strtoupper(trim(preg_replace('/\s+/u', ' ', (string)$s))); }

// ---------- kelompok penggunaan lahan (kolom B.I.1 s/d C.I.1 Rumah Dinas di Sheet) ----------
function lahan_kelompok_meta() {
    return [
        ['k' => 'b11a', 'kode' => 'B.I.1',  'nama' => 'Lahan Kerja Sama Eksternal',                 'warna' => '#0d6efd'],
        ['k' => 'b11b', 'kode' => 'B.I.1',  'nama' => 'Lahan Kerja Sama Eksternal',                 'warna' => '#6ea8fe'],
        ['k' => 'b12',  'kode' => 'B.I.2',  'nama' => 'Lahan Kerja Sama Internal',                  'warna' => '#20c997'],
        ['k' => 'b2',   'kode' => 'B.II',   'nama' => 'Lahan Idle (Komersial)',                     'warna' => '#ffc107'],
        ['k' => 'b3',   'kode' => 'B.III',  'nama' => 'Lahan Bermasalah',                           'warna' => '#dc3545'],
        ['k' => 'c11',  'kode' => 'C.I.1',  'nama' => 'Lahan untuk Bangunan Internal',              'warna' => '#6f42c1'],
        ['k' => 'c12',  'kode' => 'C.I.2',  'nama' => 'Lahan untuk Bangunan Internal - Operasional','warna' => '#a370f7'],
        ['k' => 'c2a',  'kode' => 'C.II',   'nama' => 'Lahan untuk Bangunan Komersial',             'warna' => '#fd7e14'],
        ['k' => 'c2b',  'kode' => 'C.II',   'nama' => 'Lahan untuk Bangunan Komersial',             'warna' => '#fdba74'],
        ['k' => 'rda',  'kode' => 'C.I.1',  'nama' => 'Lahan untuk Rumah Dinas',                    'warna' => '#198754'],
        ['k' => 'rdb',  'kode' => 'C.I.1',  'nama' => 'Lahan untuk Rumah Dinas',                    'warna' => '#75b798'],
    ];
}
function lahan_kelompok_key($h) {   // $h sudah dinormalisasi (huruf besar)
    $gt = (strpos($h, '>') !== false);
    if (preg_match('/^B\.III\b/', $h)) return 'b3';
    if (preg_match('/^B\.II\b/', $h))  return 'b2';
    if (preg_match('/^B\.I\.1\b/', $h)) return $gt ? 'b11b' : 'b11a';
    if (preg_match('/^B\.I\.2\b/', $h)) return 'b12';
    if (preg_match('/^C\.I\.2\b/', $h)) return 'c12';
    if (preg_match('/^C\.I\.1\b/', $h)) {
        if (strpos($h, 'RUMAH DINAS') !== false) return $gt ? 'rdb' : 'rda';
        return 'c11';
    }
    if (preg_match('/^C\.II\b/', $h)) return $gt ? 'c2b' : 'c2a';
    return null;
}

// ---------- nama header -> kunci ----------
function lahan_kunci_header($h) {
    $h = lahan_norm($h);
    if ($h === '') return null;
    if (strpos($h, 'NOMOR ASET TANAH') === 0) return 'nomor_aset';
    if (strpos($h, 'NOMOR ASET PI') === 0)    return 'nomor_pi';
    if ($h === 'CABANG')                      return 'cabang';
    if (strpos($h, 'NOMOR SERTIFIKAT') === 0) return 'sertifikat';
    if ($h === 'ALAS HAK')                    return 'alas_hak';
    if (strpos($h, 'LUAS SERTIFIKAT') === 0)  return 'luas';
    if (strpos($h, 'TOTAL PENGGUNAAN') === 0) return 'total_pakai';
    if ($h === 'PENGGUNAAN INTERNAL')         return 'internal';
    if ($h === 'PENGGUNAAN EKSTERNAL')        return 'eksternal';
    $k = lahan_kelompok_key($h);
    if ($k !== null) return 'k_' . $k;
    return null;
}

// ---------- CSV -> baris rapi ----------
function lahan_baca_csv($csv) {
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    $fh = fopen('php://temp', 'r+'); fwrite($fh, $csv); rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh)) !== false) { $rows[] = $r; }
    fclose($fh);
    return $rows;
}
function lahan_parse($csv) {
    $rows = lahan_baca_csv($csv);
    $meta = lahan_kelompok_meta();
    foreach ($meta as $i => $m) { $meta[$i]['sub'] = ''; }
    if (empty($rows)) return ['rows' => [], 'kel' => $meta];

    // baris header = 15 baris pertama yang paling banyak cocok dengan kolom yang dikenal
    $hdrIdx = 0; $best = -1;
    for ($i = 0; $i < min(15, count($rows)); $i++) {
        $c = 0; foreach ($rows[$i] as $cell) { if (lahan_kunci_header($cell) !== null) $c++; }
        if ($c > $best) { $best = $c; $hdrIdx = $i; }
    }
    $h2 = $rows[$hdrIdx];
    $h1 = $hdrIdx > 0 ? $rows[$hdrIdx - 1] : [];

    $map = [];          // kunci -> index kolom
    $nilaiKe = ['TANAH' => 0, 'PI - TANAH' => 0, 'TOTAL' => 0];
    $subByKey = [];
    foreach ($h2 as $ci => $cell) {
        $n = lahan_norm($cell);
        $k = lahan_kunci_header($cell);
        if ($k !== null) {
            if (!isset($map[$k])) $map[$k] = $ci;
            if (strpos($k, 'k_') === 0 && preg_match('/SEWA.*$/', $n, $mm)) { $subByKey[substr($k, 2)] = 'Sewa' . substr($mm[0], 4); }
            continue;
        }
        if (isset($nilaiKe[$n])) {                 // blok "DATA ASET": kemunculan pertama dipakai
            if ($nilaiKe[$n] === 0) {
                $map[['TANAH' => 'nilai_tanah', 'PI - TANAH' => 'nilai_pi', 'TOTAL' => 'nilai_total'][$n]] = $ci;
            } elseif ($nilaiKe[$n] === 1) {                 // blok "PENYESUAIAN NILAI"
                $map[['TANAH' => 'adj_tanah', 'PI - TANAH' => 'adj_pi', 'TOTAL' => 'adj_total'][$n]] = $ci;
            }
            $nilaiKe[$n]++;
            continue;
        }
        // header kosong di baris 2 -> lihat baris 1 (SELISIH, STATUS PERUBAHAN)
        $n1 = lahan_norm(isset($h1[$ci]) ? $h1[$ci] : '');
        if ($n === '' && $n1 === 'SELISIH' && !isset($map['selisih'])) $map['selisih'] = $ci;
        if ($n === '' && strpos($n1, 'STATUS PERUBAHAN') === 0 && !isset($map['status'])) $map['status'] = $ci;
    }
    foreach ($meta as $i => $m) { if (isset($subByKey[$m['k']])) $meta[$i]['sub'] = $subByKey[$m['k']]; }

    $get = function ($r, $k) use ($map) { return isset($map[$k]) && isset($r[$map[$k]]) ? trim($r[$map[$k]]) : ''; };
    $out = []; $id = 0;
    for ($i = $hdrIdx + 1; $i < count($rows); $i++) {
        $r = $rows[$i];
        $cabang = $get($r, 'cabang'); $sert = $get($r, 'sertifikat');
        if ($cabang === '' || $sert === '') continue;           // lewati baris total / kosong
        $k = [];
        foreach ($meta as $m) { $k[$m['k']] = lahan_angka($get($r, 'k_' . $m['k'])); }
        $status = strtoupper($get($r, 'status'));
        $out[] = [
            'id' => $id++,
            'nomor_aset' => $get($r, 'nomor_aset'), 'nomor_pi' => $get($r, 'nomor_pi'),
            'cabang' => $cabang, 'sertifikat' => $sert, 'alas_hak' => $get($r, 'alas_hak'),
            'luas' => lahan_angka($get($r, 'luas')), 'total_pakai' => lahan_angka($get($r, 'total_pakai')),
            'internal' => lahan_angka($get($r, 'internal')), 'eksternal' => lahan_angka($get($r, 'eksternal')),
            'selisih' => lahan_angka($get($r, 'selisih')),
            'nilai_tanah' => lahan_angka($get($r, 'nilai_tanah')), 'nilai_pi' => lahan_angka($get($r, 'nilai_pi')),
            'nilai_total' => lahan_angka($get($r, 'nilai_total')),
            'adj_tanah' => lahan_angka($get($r, 'adj_tanah')), 'adj_pi' => lahan_angka($get($r, 'adj_pi')), 'adj_total' => lahan_angka($get($r, 'adj_total')),
            'status' => $status === '' ? '-' : $status,
            'k' => $k,
        ];
    }
    return ['rows' => $out, 'kel' => $meta];
}

// ---------- cari pengguna/mitra suatu sertifikat di tab-tab kelompok ----------
function lahan_kunci_cocok($v) { return preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$v)); }
function lahan_cari_pengguna($sheetId, $tabs, $ttl, $cacheDir, $sertifikat) {
    $hasil = [];
    $target = lahan_kunci_cocok($sertifikat);
    if ($target === '') return $hasil;
    foreach ($tabs as $gid => $label) {
        $info = [];
        $csv = lahan_ambil_csv($sheetId, (string)$gid, $ttl, $cacheDir, false, $info);
        if ($csv === false) continue;
        $rows = lahan_baca_csv($csv);
        if (empty($rows)) continue;
        // baris header: baris dengan sel terisi terbanyak di 12 baris pertama
        $hdrIdx = 0; $best = -1;
        for ($i = 0; $i < min(12, count($rows)); $i++) {
            $c = 0; foreach ($rows[$i] as $cell) { if (trim($cell) !== '') $c++; }
            if ($c > $best) { $best = $c; $hdrIdx = $i; }
        }
        $hdrAsli = $rows[$hdrIdx];
        $hdr = array_map('lahan_norm', $hdrAsli);
        // kolom kunci = "NOMOR SERTIPIKAT/SERTIFIKAT ..." (bukan yang diberikan ke penyewa / jenis)
        $kc = null; $kp = null; $kl = null;
        foreach ($hdr as $ci => $h) {
            if ($kc === null && (strpos($h, 'SERTIPIKAT') !== false || strpos($h, 'SERTIFIKAT') !== false)
                && strpos($h, 'DIBERIKAN') === false && strpos($h, 'JENIS') === false) { $kc = $ci; }
            if ($kp === null && preg_match('/^(PIHAK PENYEWA|NAMA PENGGUNA|PENGGUNA\b|PEMAKAI|NAMA PIHAK)/', $h)) { $kp = $ci; }
            if ($kl === null && strpos($h, 'LUASAN') === 0) { $kl = $ci; }
        }
        if ($kl === null) { foreach ($hdr as $ci => $h) { if (strpos($h, 'LUAS') === 0) { $kl = $ci; break; } } }
        if ($kp === null) {       // tab tanpa kolom pihak (mis. Rumah Dinas): pakai kolom "NAMA ASET ..." sebagai judul
            foreach ($hdr as $ci => $h) { if (preg_match('/^NAMA (ASET|PIHAK|MITRA|PENYEWA|LAHAN)/', $h)) { $kp = $ci; break; } }
        }
        if ($kc === null) continue;
        $baris = []; $total = 0;
        for ($i = $hdrIdx + 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            if (!isset($r[$kc]) || lahan_kunci_cocok($r[$kc]) !== $target) continue;
            $pihak = ($kp !== null && isset($r[$kp])) ? trim($r[$kp]) : '';
            $luas  = ($kl !== null && isset($r[$kl]) && trim($r[$kl]) !== '') ? lahan_angka($r[$kl]) : null;
            if ($luas !== null) $total += $luas;
            $detail = [];
            foreach ($r as $ci => $cell) {
                $v = trim((string)$cell);
                if ($v === '' || $v === '-' || $v === '0') continue;
                if ($ci === $kc || $ci === $kp || $ci === $kl) continue;
                $h = isset($hdr[$ci]) ? $hdr[$ci] : '';
                if ($h === '' || strpos($h, 'DIBERIKAN') !== false || strpos($h, 'POLYGON') !== false) continue;
                if (preg_match('/^(KODE ASET|TITIK KOORDINAT|JENIS SERTI|NOMOR ASET|NAMA CABANG)/', $h)) continue;   // sudah ada di data utama / tidak perlu
                if (preg_match('/^(NO|REGIONAL|CABANG|PROVINSI|KABUPATEN\/KOTA|KECAMATAN|KELURAHAN\/DESA|NAMA JALAN|TITIK KOORDINAT|NOMOR ASET TANAH|JENIS SERTIFIKAT PELINDO|NILAI PEROLEHAN \(RP\)|NILAI BUKU \(RP\)|BEBAN PBB PERTAHUN \(RP\))$/', $h)) continue;
                $detail[] = [ucwords(strtolower(trim(preg_replace('/\s+/u', ' ', $hdrAsli[$ci])))), $v];
                if (count($detail) >= 10) break;
            }
            $baris[] = ['pihak' => $pihak, 'luas' => $luas, 'detail' => $detail];
            if (count($baris) >= 40) break;
        }
        if (!empty($baris)) $hasil[] = ['kelompok' => $label, 'total_luas' => $total, 'baris' => $baris];
    }
    return $hasil;
}

// ---------- endpoint JSON ----------
function lahan_json($payload) {
    while (ob_get_level() > 0) { ob_end_clean(); }      // buang output liar (warning/notice) supaya JSON tetap bersih
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); }
    $j = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($j === false) { $j = json_encode(['ok' => false, 'error' => 'Gagal membuat JSON: ' . json_last_error_msg()]); }
    echo $j;
    exit;
}
if (isset($_GET['ajax_action']) && in_array($_GET['ajax_action'], ['data', 'pengguna'], true)) {
    @ini_set('display_errors', '0');
    ob_start();
    register_shutdown_function(function () {          // fatal error -> tetap balas JSON berisi pesannya
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            while (ob_get_level() > 0) { ob_end_clean(); }
            if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
            echo json_encode(['ok' => false, 'error' => 'Error PHP: ' . $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')'], JSON_UNESCAPED_UNICODE);
        }
    });
    try {
        if ($_GET['ajax_action'] === 'data') {
            $gid = isset($_GET['gid']) ? (string)$_GET['gid'] : (string)array_key_first($SHEET_TABS);
            if (!isset($SHEET_TABS[$gid])) { http_response_code(400); lahan_json(['ok' => false, 'error' => 'Tab tidak dikenal']); }
            $paksa = isset($_GET['fresh']) && $_GET['fresh'] === '1';
            $info = [];
            $csv = lahan_ambil_csv($SHEET_ID, $gid, $CACHE_TTL, $CACHE_DIR, $paksa, $info);
            if ($csv === false) { lahan_json(['ok' => false, 'sumber' => 'error', 'error' => $info['error']]); }
            $p = lahan_parse($csv);
            lahan_json([
                'ok' => true, 'sumber' => $info['sumber'], 'umur' => $info['umur'], 'peringatan' => $info['error'],
                'tab' => $SHEET_TABS[$gid], 'diambil' => date('c'), 'jumlah' => count($p['rows']), 'rows' => $p['rows'],
                'kel' => $p['kel'], 'pengguna_aktif' => !empty($TAB_PENGGUNA),
            ]);
        } else {
            $sert = isset($_GET['sert']) ? (string)$_GET['sert'] : '';
            $hasil = lahan_cari_pengguna($SHEET_ID, $TAB_PENGGUNA, $CACHE_TTL, $CACHE_DIR, $sert);
            lahan_json(['ok' => true, 'aktif' => !empty($TAB_PENGGUNA), 'hasil' => $hasil]);
        }
    } catch (Throwable $e) {
        lahan_json(['ok' => false, 'error' => 'Error PHP: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')']);
    }
}
?>

<!doctype html>
<html lang="en">
  <!--begin::Head-->
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Dasbor Penggunaan Lahan - Web Aset Tetap</title>
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
      /* hover lebih halus supaya beda dengan menu aktif */
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
          .lihat-aset { font-weight: 600; text-decoration: none; cursor: pointer; }
      .lihat-aset:hover { text-decoration: underline; }
      .stack-track { flex: 1 1 auto; background: #e9ecef; border-radius: 4px; height: 16px; overflow: hidden; display: flex; }
      .stack-track > span { display: block; height: 100%; }
      .kel-swatch { display: inline-block; width: 12px; height: 12px; border-radius: 3px; margin-right: .5rem; vertical-align: middle; }
      .kel-row { display: flex; align-items: center; gap: .6rem; padding: .45rem 0; border-bottom: 1px solid rgba(0,0,0,.07); font-size: .88rem; }
      .kel-row:last-child { border-bottom: 0; }
      .kel-nama { flex: 0 0 42%; }
      .kel-nama small { display: block; color: #6c757d; }
      .kel-bar { flex: 1 1 auto; background: #e9ecef; border-radius: 4px; height: 12px; overflow: hidden; }
      .kel-bar > span { display: block; height: 100%; }
      .kel-val { flex: 0 0 24%; text-align: right; font-variant-numeric: tabular-nums; }
      .kel-val small { display: block; color: #6c757d; }
      #tabelLahan { font-size: .85rem; }
      #tabelLahan thead th { white-space: normal; vertical-align: middle; font-size: .8rem; line-height: 1.2; }
      #tabelLahan tbody td { padding: .45rem .6rem; vertical-align: middle; }
      #tabelLahan td.kol-sert { min-width: 190px; }
      #tabelLahan td.kol-kel { min-width: 200px; }
      #tabelLahan .badge-kel { display: inline-block; margin: 1px 2px 1px 0; padding: .22rem .5rem; border-radius: .3rem; font-size: .75rem; font-weight: 600; white-space: nowrap; }
      /* ===== Tab halaman ===== */
      .lahan-tabs { display: flex; flex-wrap: wrap; gap: .35rem; background: #fff; border: 1px solid #e6ebf2; border-radius: .75rem; padding: .4rem; box-shadow: 0 1px 3px rgba(16,24,40,.05); }
      .lahan-tabs .nav-link { display: inline-flex; align-items: center; gap: .4rem; white-space: nowrap; color: #4b5870; font-weight: 600; font-size: .85rem; border: 0; border-radius: .55rem; padding: .5rem .85rem; background: transparent; transition: background .15s, color .15s; }
      .lahan-tabs .nav-link i { font-size: .95rem; opacity: .85; }
      .lahan-tabs .nav-link:hover { color: #0b3a8c; background: #eef3fb; }
      .lahan-tabs .nav-link.active { color: #fff; background: #0b3a8c; box-shadow: 0 2px 6px rgba(11,58,140,.35); }
      .lahan-tabs .nav-link.active i { opacity: 1; }
      .lahan-frame { display: block; width: 100%; border: 0; background: transparent; }
      /* ===== Kolom nilai gaya Excel: DAT biru, Eksisting hijau, Status merah ===== */
      /* selector hanya memakai kelas (tanpa .dataTables_wrapper / #tabelLahan) supaya ikut berlaku di header salinan DataTables */
      th.th-xl { color: #fff !important; text-align: center !important; font-size: .8rem; text-transform: uppercase; letter-spacing: .02em; border: 1px solid rgba(0,0,0,.35) !important; vertical-align: middle; background-image: none !important; cursor: default; }
      th.th-xl::before, th.th-xl::after { display: none !important; content: none !important; }
      th.th-xl.th-dat { background-color: #2f6fd0 !important; }
      th.th-xl.th-eks { background-color: #1f9d55 !important; }
      th.th-xl.th-xl-status { background-color: #cc0000 !important; }
      td.kol-dat { background-color: rgba(47,111,208,.09); }
      td.kol-eks { background-color: rgba(31,157,85,.10); }
      th.grp-first, td.grp-first { border-left: 2px solid #cfd8e6; }
      .chip-ubah { display: inline-flex; align-items: center; gap: .35rem; font-size: .75rem; font-weight: 600; padding: .18rem .6rem; border-radius: .4rem; background: #fff4e0; color: #a15c00; white-space: nowrap; }
      /* ===== Tampilan kartu ===== */
      .tab-pane > .card { border: 1px solid #e6ebf2; border-radius: .75rem; box-shadow: 0 1px 3px rgba(16,24,40,.06); }
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
      .kpi-card2 { display: flex; align-items: center; gap: .9rem; background: #fff; border: 1px solid #e6ebf2; border-radius: .75rem; padding: .9rem 1rem; box-shadow: 0 1px 3px rgba(16,24,40,.06); position: relative; overflow: hidden; height: 100%; transition: box-shadow .15s, transform .15s; }
      .kpi-card2::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: var(--c); }
      .kpi-card2:hover { box-shadow: 0 6px 16px rgba(16,24,40,.10); transform: translateY(-1px); }
      .kpi-ico { flex: 0 0 46px; width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; color: var(--c); background: #eef3fb; background: color-mix(in srgb, var(--c) 12%, #fff); }
      .kpi-body { min-width: 0; }
      .kpi-card2 .kpi-label { font-size: .72rem; letter-spacing: .03em; text-transform: uppercase; color: #6b7788; font-weight: 600; line-height: 1.25; }
      .kpi-card2 .kpi-val { font-size: 1.4rem; font-weight: 700; color: #1f2937; line-height: 1.25; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
      /* ===== Popup rincian ===== */
      .m-head { background: linear-gradient(135deg, #0b3a8c 0%, #1565c0 100%); color: #fff; padding: 1rem 1.25rem; }
      .m-head-ico { width: 42px; height: 42px; border-radius: 10px; background: rgba(255,255,255,.18); display: flex; align-items: center; justify-content: center; font-size: 1.25rem; flex: 0 0 auto; }
      .m-head-title { font-size: 1.15rem; font-weight: 700; line-height: 1.2; }
      .m-head-sub { font-size: .85rem; opacity: .92; margin-top: .15rem; }
      .m-body { background: #fff; padding: 1rem 1.25rem 1.25rem; }
      .m-sec { display: flex; align-items: center; gap: .5rem; margin: 1.15rem 0 .6rem; font-size: .72rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #8a94a6; }
      .m-sec:first-child { margin-top: .1rem; }
      .m-sec::after { content: ''; flex: 1; height: 1px; background: #e3e8ef; }
      .m-sec em { font-style: normal; text-transform: none; letter-spacing: 0; font-weight: 500; }
      .m-grid { display: grid; grid-template-columns: 1fr 1fr; border: 1px solid #e9edf3; border-radius: .5rem; overflow: hidden; }
      .m-cell { padding: .6rem .85rem; border-bottom: 1px solid #eef1f6; min-width: 0; }
      .m-cell:nth-child(odd) { border-right: 1px solid #eef1f6; }
      .m-cell.full { grid-column: 1 / -1; border-right: 0; }
      .m-lab { font-size: .7rem; color: #8a94a6; margin-bottom: .1rem; }
      .m-val { font-size: .92rem; color: #1f2937; font-weight: 500; word-break: break-word; }
      .m-stat { border: 1px solid #e9edf3; border-left: 4px solid var(--c, #0b3a8c); border-radius: .5rem; padding: .55rem .8rem; height: 100%; }
      .m-stat .m-big { font-size: 1.2rem; font-weight: 700; color: #1f2937; line-height: 1.25; }
      .m-stat .kpi-sub, .kpi-card2 .kpi-sub { font-size: .72rem; color: #6b7788; line-height: 1.3; margin-top: .15rem; }
      .m-grp { display: flex; justify-content: space-between; align-items: center; font-weight: 600; font-size: .9rem; margin: .2rem 0 .45rem; }
      .m-rec { border: 1px solid #e9edf3; border-radius: .6rem; margin-bottom: .7rem; overflow: hidden; }
      .m-rec-head { display: flex; justify-content: space-between; align-items: center; gap: .6rem; padding: .55rem .85rem; background: #f3f7ff; border-bottom: 1px solid #e3e8ef; font-weight: 600; font-size: .92rem; color: #0b3a8c; }
      .m-rec-head .m-luas { white-space: nowrap; background: #0b3a8c; color: #fff; border-radius: 1rem; padding: .1rem .6rem; font-size: .78rem; font-weight: 600; }
      .m-rec .m-grid { border: 0; border-radius: 0; }
      @media (max-width: 576px) { .m-grid { grid-template-columns: 1fr; } .m-cell:nth-child(odd) { border-right: 0; } }
    </style>
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
                        $sidebarMenuConfig = require __DIR__ . '/../sidebar_menu_config.php';
            $iconMap = $sidebarMenuConfig['iconMap'];
            $groupMap = $sidebarMenuConfig['groupMap'];
            $groupIcon = $sidebarMenuConfig['groupIcon'];
            $groupOrder = $sidebarMenuConfig['groupOrder'];

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

            foreach ($ungrouped as $row) {
                $namaMenu = trim($row['nama_menu']);
                $icon     = $iconMap[$namaMenu] ?? 'bi bi-circle';
                $isActive = ($currentPage === $row['menu'] . '.php') ? 'active' : '';
                echo '<li class="nav-item"><a href="../' . $row['menu'] . '/' . $row['menu'] . '.php" class="nav-link ' . $isActive . '"><i class="nav-icon ' . $icon . '"></i><p>' . htmlspecialchars($namaMenu) . '</p></a></li>';
            }

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
              <div class="col-sm-6"><h3 class="mb-0">Dasbor Penggunaan Lahan</h3></div>
              <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                  <li class="breadcrumb-item"><a href="../dasbor/dasbor.php">Home</a></li>
                  <li class="breadcrumb-item active">Dasbor Penggunaan Lahan</li>
                </ol>
              </div>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">

            <!-- Tab -->
            <ul class="nav lahan-tabs mb-3" id="lahanTabs" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#paneRekap" type="button" role="tab" data-tab="rekap">
                  <i class="bi bi-grid-1x2-fill"></i>REKAP</button>
                </li>
            <?php foreach ($LAHAN_TABS as $key => $tb): ?>
              <li class="nav-item" role="presentation">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-<?php echo htmlspecialchars($key); ?>" type="button" role="tab" data-tab="<?php echo htmlspecialchars($key); ?>" data-src="tab_lahan.php?t=<?php echo urlencode($key); ?>&amp;embed=1"><i class="bi <?php echo htmlspecialchars(isset($tb['ikon']) ? $tb['ikon'] : 'bi-folder2-open'); ?>"></i><?php echo htmlspecialchars($tb['label']); ?></button></li>
            <?php endforeach; ?>
            </ul>
            <div class="tab-content">
            <div class="tab-pane fade show active" id="paneRekap" role="tabpanel">

            <!-- Status sumber data -->
            <div class="lahan-status mb-3">
              <span id="badgeSumber" class="badge-live"><i class="dot"></i>Memuat…</span>
              <div class="ms-auto d-flex align-items-center gap-3 flex-wrap">
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" id="autoRefresh" checked>
                  <label class="form-check-label small" for="autoRefresh">Auto-refresh</label>
                </div>
                <button type="button" class="btn btn-sm btn-primary" id="btnMuat"><i class="bi bi-arrow-repeat"></i> Muat ulang</button>
                <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="https://docs.google.com/spreadsheets/d/<?php echo htmlspecialchars($SHEET_ID); ?>/edit"><i class="bi bi-box-arrow-up-right"></i> Buka Sheet</a>
              </div>
            </div>
            <div id="alertError" class="alert alert-danger d-none"></div>

            <!-- Filter -->
            <div class="card lahan-filter mb-3">
              <div class="lahan-filter-head"><i class="bi bi-funnel-fill"></i><span>Filter data</span>
                <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="btnReset"><i class="bi bi-arrow-counterclockwise"></i> Reset filter</button>
              </div>
              <div class="card-body py-3">
                <div class="row g-3">
                  <div class="col-6 col-md-3 col-xl-2"><label class="lahan-lab">Cabang</label><select id="fCabang" class="form-select form-select-sm"></select></div>
                  <div class="col-6 col-md-3 col-xl-2"><label class="lahan-lab">Alas Hak</label><select id="fAlas" class="form-select form-select-sm"></select></div>
                  <div class="col-6 col-md-3 col-xl-2"><label class="lahan-lab">Status Perubahan</label><select id="fStatus" class="form-select form-select-sm"></select></div>
                  <div class="col-6 col-md-6 col-xl-2"><label class="lahan-lab">Digunakan untuk (kelompok)</label><select id="fKel" class="form-select form-select-sm"></select></div>
                </div>
              </div>
            </div>

            <!-- KPI -->
            <div class="row g-3 mb-3" id="kpiRow"></div>

            <!-- Tabel -->
            <div class="card mb-4">
              <div class="card-header d-flex flex-wrap align-items-center gap-2"><div class="card-title">Daftar Aset Tanah <span class="text-muted small ms-2">Klik nomor sertifikat untuk melihat rincian penggunaan</span></div><div class="form-check form-switch mb-0 ms-auto"><input class="form-check-input" type="checkbox" id="semuaKolom"><label class="form-check-label small" for="semuaKolom">Tampilkan semua kolom</label></div></div>
              <div class="card-body">
                <table id="tabelLahan" class="display" style="width:100%">
                  <thead><tr></tr></thead>
                  <tbody></tbody>
                </table>
              </div>
            </div>

            </div><!-- /paneRekap -->
<?php foreach ($LAHAN_TABS as $key => $tb): ?>
            <div class="tab-pane fade" id="pane-<?php echo htmlspecialchars($key); ?>" role="tabpanel">
              <iframe class="lahan-frame" title="<?php echo htmlspecialchars($tb['label']); ?>" style="height:720px"></iframe>
            </div>
<?php endforeach; ?>
            </div><!-- /tab-content -->
          </div>
        </div>
      </main>

      <!-- Modal rincian aset -->
      <div class="modal fade" id="modalAset" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
          <div class="modal-content border-0 overflow-hidden">
            <div class="m-head">
              <div class="d-flex align-items-start gap-3">
                <div class="m-head-ico"><i class="bi bi-geo-alt-fill"></i></div>
                <div class="flex-grow-1">
                  <div class="m-head-title">Rincian Penggunaan Lahan</div>
                  <div class="m-head-sub" id="mSub"></div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
              </div>
            </div>
            <div class="modal-body m-body">
              <div class="m-sec"><i class="bi bi-file-earmark-text"></i><span>Identitas sertifikat</span></div>
              <div class="m-grid" id="mIdent"></div>
              <div class="m-sec"><i class="bi bi-rulers"></i><span>Ringkasan penggunaan</span></div>
              <div class="row g-2" id="mKpi"></div>
              <div class="m-sec"><i class="bi bi-cash-stack"></i><span>Nilai aset</span></div>
              <div class="m-grid" id="mNilai"></div>
              <div class="m-sec"><i class="bi bi-pie-chart"></i><span>Penggunaan per kelompok</span><em id="mKelInfo"></em></div>
              <div id="mKel"></div>
              <div class="m-sec"><i class="bi bi-people"></i><span>Pihak Pengguna</span></div>
              <div id="mPengguna"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button></div>
          </div>
        </div>
      </div>

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
      const TABS = <?php echo json_encode($SHEET_TABS, JSON_UNESCAPED_UNICODE); ?>;
      const ENDPOINT = location.pathname;

      let allRows = [], viewRows = [], KEL = [], PENGGUNA_AKTIF = false;
      let dt = null, timer = null, tick = null, loading = false, lockMuat = false, sisaDetik = 0, modalAset = null;

      const fmtInt = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 });
      const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
      const sum = (arr, f) => arr.reduce((t, r) => t + (Number(f(r)) || 0), 0);
      const pct = (a, b) => b > 0 ? (a / b * 100) : 0;
      const fmtPct = v => v.toLocaleString('id-ID', { maximumFractionDigits: 1 }) + '%';
      function rpSingkat(n) {
        const a = Math.abs(n), s = n < 0 ? '-' : '';
        const f = (v, u) => s + 'Rp ' + v.toLocaleString('id-ID', { maximumFractionDigits: 2 }) + ' ' + u;
        if (a >= 1e12) return f(a / 1e12, 'T');
        if (a >= 1e9)  return f(a / 1e9, 'M');
        if (a >= 1e6)  return f(a / 1e6, 'Jt');
        return s + 'Rp ' + fmtInt.format(a);
      }
      const hitungSelisih = r => Math.round((r.luas - (r.internal + r.eksternal)) * 100) / 100;   // = I - (V + W) di Sheet
      const klsSelisih = v => v !== 0 ? 'text-danger' : 'text-success';
      // Status = rumus di Sheet: =IF(OR(A="Belum Tercatat"; A="Menjadi 1 dengan Flyover TTL"); "TETAP"; IF(Y=AC; "TETAP"; "BERUBAH"))
      //   A = Nomor Aset Tanah, Y = nilai tanah (Data Aset), AC = nilai tanah hasil penyesuaian (Penyesuaian Nilai)
      const ASET_TETAP = ['BELUM TERCATAT', 'MENJADI 1 DENGAN FLYOVER TTL'];
      const hitungStatus = r => {
        if (ASET_TETAP.includes(String(r.nomor_aset || '').trim().toUpperCase())) return 'TETAP';
        return Math.round(r.nilai_tanah) === Math.round(r.adj_tanah) ? 'TETAP' : 'BERUBAH';
      };
      const alasanStatus = r => {
        const a = String(r.nomor_aset || '').trim();
        if (ASET_TETAP.includes(a.toUpperCase())) return 'Aset "' + a + '" selalu berstatus TETAP.';
        if (Math.round(r.nilai_tanah) === Math.round(r.adj_tanah)) return 'Nilai tanah menurut DAT sama dengan nilai eksisting.';
        return 'Nilai tanah menurut DAT ' + rpFull(r.nilai_tanah) + ' berbeda dari nilai eksisting ' + rpFull(r.adj_tanah) + '.';
      };
      // Ringkasan perubahan nilai DAT -> eksisting, dalam satu kalimat
      const perubahanNilai = r => {
        const dT = Math.round(r.adj_tanah - r.nilai_tanah), dP = Math.round(r.adj_pi - r.nilai_pi);
        const tip = 'Tanah: ' + fmtInt.format(r.nilai_tanah) + ' → ' + fmtInt.format(r.adj_tanah) + ' | PI Tanah: ' + fmtInt.format(r.nilai_pi) + ' → ' + fmtInt.format(r.adj_pi);
        if (r.status !== 'BERUBAH') return { tipe: 'tetap', teks: 'Tidak ada perubahan', urut: 0, tip };
        if (dT !== 0 && Math.abs(dT + dP) <= 1) {                       // total sama: nilai hanya berpindah
          const jumlah = Math.abs(dP), dari = dT < 0 ? 'Tanah' : 'PI Tanah', ke = dT < 0 ? 'PI Tanah' : 'Tanah';
          return { tipe: 'pindah', dari, ke, jumlah, teks: dari + ' → ' + ke + ' ' + rpSingkat(jumlah), urut: jumlah, tip };
        }
        const f = v => (v > 0 ? '+' : v < 0 ? '−' : '') + rpSingkat(Math.abs(v));
        return { tipe: 'lain', teks: 'Tanah ' + f(dT) + ' · PI Tanah ' + f(dP), urut: Math.abs(dT) + Math.abs(dP), tip };
      };
      const NAMA_PENDEK = { b11a: 'Lahan Kerja Sama Eksternal', b11b: 'Lahan Kerja Sama Eksternal', b12: 'Lahan Kerja Sama Internal', b2: 'Lahan Idle (Komersial)', b3: 'Lahan Bermasalah',
                            c11: 'Lahan untuk Bangunan Internal', c12: 'Lahan untuk Bangunan Operasional', c2a: 'Lahan untuk Bangunan Komersial', c2b: 'Lahan untuk Bangunan Komersial', rda: 'Lahan untuk Rumah Dinas', rdb: 'Lahan untuk Rumah Dinas' };
      const pendek = k => NAMA_PENDEK[k.k] || k.nama;
      const namaKel = k => pendek(k);
      const subKel  = k => (k.sub || '');

      // ---------- ambil data ----------
      async function muatData(paksa) {
        if (loading) return;
        loading = true;
        const gid = Object.keys(TABS)[0];
        try {
          const res = await fetch(ENDPOINT + '?ajax_action=data&gid=' + encodeURIComponent(gid) + (paksa ? '&fresh=1' : ''), { credentials: 'same-origin', cache: 'no-store' });
          const txt = await res.text();
          let j;
          try { j = JSON.parse(txt); } catch (e) { throw new Error('Respons server bukan JSON: ' + txt.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300)); }
          if (!j.ok) throw new Error(j.error || 'Gagal mengambil data');
          allRows = j.rows || []; KEL = j.kel || []; PENGGUNA_AKTIF = !!j.pengguna_aktif;
          allRows.forEach(r => { r.status_sheet = r.status; r.status = hitungStatus(r); });   // status dihitung dengan rumus di atas
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

      // ---------- filter ----------
      const FILTER = { fCabang: 'cabang', fAlas: 'alas_hak', fStatus: 'status' };
      function isiFilter() {
        for (const [id, key] of Object.entries(FILTER)) {
          const el = document.getElementById(id), cur = el.value;
          const vals = [...new Set(allRows.map(r => r[key]).filter(v => v && v !== '-'))].sort((a, b) => String(a).localeCompare(String(b), 'id'));
          el.innerHTML = '<option value="">Semua</option>' + vals.map(v => '<option value="' + esc(v) + '">' + esc(v) + '</option>').join('');
          if (vals.includes(cur)) el.value = cur;
        }
        const ek = document.getElementById('fKel'), curK = ek.value;
        ek.innerHTML = '<option value="">Semua</option>' + KEL.map(k => '<option value="' + esc(k.k) + '">' + esc(pendek(k) + (subKel(k) ? ' — ' + subKel(k) : '')) + '</option>').join('');
        if (KEL.some(k => k.k === curK)) ek.value = curK;
      }
      function terapkan() {
        const f = {}; for (const [id, key] of Object.entries(FILTER)) f[key] = document.getElementById(id).value;
        const fk = document.getElementById('fKel').value;
        viewRows = allRows.filter(r => {
          for (const key in f) { if (f[key] && r[key] !== f[key]) return false; }
          if (fk && !(r.k[fk] > 0)) return false;
          return true;
        });
        renderKpi(); renderTabel();
      }

      // ---------- KPI ----------
      function ikonKpi(label) {
        const u = String(label).toUpperCase();
        if (/JUMLAH/.test(u))     return ['bi-collection-fill', '#0b3a8c'];
        if (/LUAS/.test(u))       return ['bi-rulers', '#198754'];
        if (/TOTAL PENGGUNAAN/.test(u)) return ['bi-pie-chart-fill', '#0d9aa8'];
        if (/SELISIH/.test(u))    return ['bi-plus-slash-minus', '#dc3545'];
        if (/INTERNAL/.test(u))   return ['bi-building', '#6f42c1'];
        if (/EKSTERNAL/.test(u))  return ['bi-people-fill', '#fd7e14'];
        if (/NILAI/.test(u))      return ['bi-cash-stack', '#0d6efd'];
        if (/STATUS/.test(u))     return ['bi-arrow-repeat', '#a15c00'];
        return ['bi-bar-chart-fill', '#0b3a8c'];
      }
      function kpi(label, val, sub) {
        const [ikon, warna] = ikonKpi(label);
        return '<div class="col-6 col-lg-3"><div class="kpi-card2" style="--c:' + warna + '"><div class="kpi-ico"><i class="bi ' + ikon + '"></i></div>' +
          '<div class="kpi-body"><div class="kpi-label">' + esc(label) + '</div><div class="kpi-val">' + val + '</div>' + (sub ? '<div class="kpi-sub">' + sub + '</div>' : '') + '</div></div></div>';
      }
      function renderKpi() {
        const luas = sum(viewRows, r => r.luas), pakai = sum(viewRows, r => r.total_pakai);
        const inter = sum(viewRows, r => r.internal), eks = sum(viewRows, r => r.eksternal);
        const nilai = sum(viewRows, r => r.nilai_total);
        const selisih = Math.round(sum(viewRows, r => hitungSelisih(r)) * 100) / 100;
        const berubah = viewRows.filter(r => r.status === 'BERUBAH').length;
        document.getElementById('kpiRow').innerHTML =
          kpi('Jumlah Aset Tanah', '<span class="text-primary">' + fmtInt.format(viewRows.length) + '</span>') +
          kpi('Luas Sertifikat (m²)', fmtInt.format(luas)) +
          kpi('Total Penggunaan (m²)', fmtInt.format(pakai), fmtPct(pct(pakai, luas)) + ' dari luas sertifikat') +
          kpi('Selisih (m²)', '<span class="' + klsSelisih(selisih) + '">' + fmtInt.format(selisih) + '</span>') +
          kpi('Penggunaan Internal (m²)', fmtInt.format(inter), fmtPct(pct(inter, pakai)) + ' dari penggunaan') +
          kpi('Penggunaan Eksternal (m²)', fmtInt.format(eks), fmtPct(pct(eks, pakai)) + ' dari penggunaan') +
          kpi('Nilai Aset', rpSingkat(nilai)) +
          kpi('Aset Status Berubah', fmtInt.format(berubah), 'Dari ' + fmtInt.format(viewRows.length) + ' aset');
      }

      // ---------- tabel ----------
      const kolText = k => ({ data: k, render: (d, t) => t === 'display' ? esc(d) : (d == null ? '' : d) });
      const kolNum  = k => ({ data: k, className: 'text-end text-nowrap', render: (d, t) => t === 'display' ? fmtInt.format(d) : d });
      // Kolom nilai gaya Excel: Data Aset (DAT) | Status | Penyesuaian Nilai (eksisting). Angka yang berbeda dari pasangannya dimerahkan.
      const kolNilai = (nama, key, keyLain, grup, kls, awal) => ({
        title: nama, grup, grupKls: kls, data: key, orderable: false,
        className: 'text-end text-nowrap kol-nilai ' + (kls.includes('th-dat') ? 'kol-dat' : 'kol-eks') + (awal ? ' grp-first' : ''),
        render: (d, t, row) => {
          if (t !== 'display') return d;
          const lain = row[keyLain] || 0, v = d || 0;
          const tetap = ASET_TETAP.includes(String(row.nomor_aset || '').trim().toUpperCase());
          const beda = !tetap && Math.round(v) !== Math.round(lain);
          return beda ? '<span class="text-danger fw-bold" title="Berbeda, pasangannya: ' + fmtInt.format(lain) + '">' + fmtInt.format(v) + '</span>' : fmtInt.format(v);
        }
      });
      function kolSert() {
        return { data: 'sertifikat', className: 'kol-sert', render: (d, t, row) =>
          t === 'display' ? '<a href="#" class="lihat-aset" data-id="' + esc(row.id) + '" title="Lihat rincian penggunaan sertifikat ini">' + esc(d) + '</a>' : d };
      }
      const kolK = key => ({ data: null, className: 'text-end text-nowrap', render: (d, t, row) => { const v = row.k[key] || 0; return t === 'display' ? (v ? fmtInt.format(v) : '<span class="text-muted">0</span>') : v; } });
      let KOL_DETAIL = [];
      const teksWarna = hex => { const n = parseInt(String(hex).replace('#', ''), 16); const l = 0.299 * ((n >> 16) & 255) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255); return l > 160 ? '#212529' : '#ffffff'; };
      function kolomTabel() {
        const c = [], det = [];
        const add = (def, detail) => { if (detail) def.detail = true; c.push(def); };   // detail = kolom tambahan (muncul lewat "Tampilkan semua kolom")
        add({ title: 'No', data: null, orderable: false, render: (d, t, r, m) => m.row + m.settings._iDisplayStart + 1 });
        const kolAsetTeks = (title, key) => ({ title, data: key, render: (d, t) => t === 'display' ? (d ? esc(d) : '<span class="text-muted">-</span>') : (d || '') });
        add(kolAsetTeks('Nomor Aset Tanah', 'nomor_aset'));
        add(kolAsetTeks('Nomor Aset PI', 'nomor_pi'));
        add(Object.assign({ title: 'No. Sertifikat' }, kolSert()));
        add(Object.assign({ title: 'Cabang' }, kolText('cabang')));
        add(Object.assign({ title: 'Luas Sertifikat (m²)' }, kolNum('luas')));
        add(Object.assign({ title: 'Total Penggunaan (m²)' }, kolNum('total_pakai')), true);   // disembunyikan (muncul lewat "Tampilkan semua kolom")
        KEL.forEach(k => add(Object.assign({ title: pendek(k) + (k.sub ? ' ' + k.sub : '') + ' (m²)' }, kolK(k.k)), true));
        add(Object.assign({ title: 'Internal (m²)' }, kolNum('internal')));
        add(Object.assign({ title: 'Eksternal (m²)' }, kolNum('eksternal')));
       add({ title: 'Selisih (m²)', data: null, className: 'text-end text-nowrap', render: (d, t, row) => { const v = hitungSelisih(row); return t === 'display' ? '<span class="text-danger fw-bold">' + fmtInt.format(v) + '</span>' : v; } });
        add(kolNilai('Tanah', 'nilai_tanah', 'adj_tanah', 'Data Aset (DAT)', 'th-xl th-dat', true));
        add(kolNilai('PI - Tanah', 'nilai_pi', 'adj_pi', 'Data Aset (DAT)', 'th-xl th-dat'));
        add(kolNilai('Total', 'nilai_total', 'adj_total', 'Data Aset (DAT)', 'th-xl th-dat'));
        add({ title: 'Status Perubahan', thKls: 'th-xl th-xl-status', data: 'status', render: (d, t) => t === 'display' ? '<span class="badge text-bg-' + (d === 'BERUBAH' ? 'warning' : (d === 'TETAP' ? 'success' : 'secondary')) + '">' + esc(d) + '</span>' : d });
        add(kolNilai('Tanah', 'adj_tanah', 'nilai_tanah', 'Penyesuaian Nilai (Eksisting)', 'th-xl th-eks', true));
        add(kolNilai('PI - Tanah', 'adj_pi', 'nilai_pi', 'Penyesuaian Nilai (Eksisting)', 'th-xl th-eks'));
        add(kolNilai('Total', 'adj_total', 'nilai_total', 'Penyesuaian Nilai (Eksisting)', 'th-xl th-eks'));
        add({ title: 'Perubahan Nilai', data: null, detail: true, render: (d, t, row) => {
          const p = perubahanNilai(row);
          if (t === 'sort' || t === 'type') return p.urut;
          if (t !== 'display') return p.teks;
          if (p.tipe === 'tetap') return '<span class="text-muted">' + esc(p.teks) + '</span>';
          const isi = p.tipe === 'pindah' ? esc(p.dari) + ' <i class="bi bi-arrow-right"></i> ' + esc(p.ke) + ' ' + esc(rpSingkat(p.jumlah)) : esc(p.teks);
          return '<span class="chip-ubah" title="' + esc(p.tip) + '">' + isi + '</span>';
        } });
        KOL_DETAIL = det;
        return c;
      }
      // header dua baris seperti Excel: baris atas = judul kelompok (digabung), baris bawah = nama kolom
      function bangunHeader(cols) {
        if (!cols.some(c => c.grup)) return '<tr>' + cols.map(c => '<th>' + esc(c.title) + '</th>').join('') + '</tr>';
        let r1 = '', r2 = '';
        for (let i = 0; i < cols.length; i++) {
          const c = cols[i];
          if (c.grup) {
            if (i === 0 || cols[i - 1].grup !== c.grup) {
              let n = 0; while (i + n < cols.length && cols[i + n].grup === c.grup) n++;
              r1 += '<th colspan="' + n + '" class="' + c.grupKls + ' grp-first">' + esc(c.grup) + '</th>';
            }
            r2 += '<th class="' + c.grupKls + '">' + esc(c.title) + '</th>';
          } else {
            r1 += '<th rowspan="2"' + (c.thKls ? ' class="' + c.thKls + '"' : '') + '>' + esc(c.title) + '</th>';
          }
        }
        return '<tr>' + r1 + '</tr><tr>' + r2 + '</tr>';
      }
      function initTabel() {
        const semua = document.getElementById('semuaKolom').checked;
        let cols = kolomTabel();
        if (!semua) cols = cols.filter(c => !c.detail);
        document.querySelector('#tabelLahan thead').innerHTML = bangunHeader(cols);
        dt = $('#tabelLahan').DataTable({
          data: viewRows, deferRender: true, scrollX: true, pageLength: 10, lengthMenu: [10, 25, 50, 100],
          order: [[cols.findIndex(c => c.title === 'Cabang'), 'asc']],
          columns: cols,
            language: {
              search: 'Cari:', lengthMenu: 'Tampilkan _MENU_ entri', info: 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
              infoEmpty: 'Tidak ada data', emptyTable: 'Tidak ada data', zeroRecords: 'Tidak ada data yang cocok',
              infoFiltered: '(disaring dari _MAX_ entri keseluruhan)',
              paginate: { first: 'Pertama', previous: 'Sebelumnya', next: 'Selanjutnya', last: 'Terakhir' },
            },
        });
      }
      function renderTabel() {
        if (!dt) initTabel();
        else dt.clear().rows.add(viewRows).draw(false);
      }

      // ---------- modal rincian aset ----------
      const mCell = (lab, val, full) => '<div class="m-cell' + (full ? ' full' : '') + '"><div class="m-lab">' + esc(lab) + '</div><div class="m-val">' + val + '</div></div>';
      const mStat = (lab, big, sub, warna) => '<div class="col-6 col-md-4"><div class="m-stat" style="--c:' + warna + '"><div class="m-lab">' + esc(lab) + '</div><div class="m-big">' + big + '</div>' + (sub ? '<div class="kpi-sub">' + sub + '</div>' : '') + '</div></div>';
      const rpFull = n => 'Rp ' + fmtInt.format(n);
      function fmtDetail(lab, val) {
        const v = String(val);
        return (/nilai|harga|biaya/i.test(lab) && /^[\d.,]+$/.test(v)) ? 'Rp ' + v : v;
      }
      function bukaRincian(id) {
        const r = allRows.find(x => String(x.id) === String(id));
        if (!r) return;
        document.getElementById('mSub').textContent = r.sertifikat + ' · Cabang ' + r.cabang;

        const stCls = r.status === 'BERUBAH' ? 'warning' : (r.status === 'TETAP' ? 'success' : 'secondary');
        document.getElementById('mIdent').innerHTML =
          mCell('No. Sertifikat', esc(r.sertifikat), true) +
          mCell('Alas Hak', esc(r.alas_hak || '-')) + mCell('Cabang', esc(r.cabang)) +
          mCell('Nomor Aset Tanah', esc(r.nomor_aset || '-')) + mCell('Nomor Aset PI', esc(r.nomor_pi || '-')) +
          mCell('Status Perubahan', '<span class="badge text-bg-' + stCls + '">' + esc(r.status) + '</span> <span class="text-muted small ms-2">' + esc(alasanStatus(r)) + '</span>', true);

        const sisa = hitungSelisih(r);
        document.getElementById('mKpi').innerHTML =
          mStat('Luas Sertifikat', fmtInt.format(r.luas) + ' m²', '', '#0b3a8c') +
          mStat('Total Penggunaan', fmtInt.format(r.total_pakai) + ' m²', fmtPct(pct(r.total_pakai, r.luas)) + ' dari luas', '#198754') +
          mStat('Selisih', '<span class="' + klsSelisih(sisa) + '">' + fmtInt.format(sisa) + ' m²</span>', 'Luas − (Internal + Eksternal)', sisa !== 0 ? '#dc3545' : '#198754') +
          mStat('Internal', fmtInt.format(r.internal) + ' m²', fmtPct(pct(r.internal, r.total_pakai)) + ' dari penggunaan', '#6f42c1') +
          mStat('Eksternal', fmtInt.format(r.eksternal) + ' m²', fmtPct(pct(r.eksternal, r.total_pakai)) + ' dari penggunaan', '#fd7e14');

        document.getElementById('mNilai').innerHTML =
          mCell('Tanah - menurut DAT', rpFull(r.nilai_tanah)) + mCell('Tanah - eksisting', rpFull(r.adj_tanah)) +
          mCell('PI Tanah - menurut DAT', rpFull(r.nilai_pi)) + mCell('PI Tanah - eksisting', rpFull(r.adj_pi)) +
          mCell('Total - menurut DAT', '<strong>' + rpFull(r.nilai_total) + '</strong>') + mCell('Total - eksisting', '<strong>' + rpFull(r.adj_total) + '</strong>') +
          mCell('Perubahan nilai', esc(perubahanNilai(r).teks), true) +
          mCell('Komposisi penggunaan', 'Internal ' + fmtPct(pct(r.internal, r.total_pakai)) + ' · Eksternal ' + fmtPct(pct(r.eksternal, r.total_pakai)), true);

        const list = KEL.map(k => ({ k, v: r.k[k.k] || 0 })).filter(x => x.v > 0).sort((a, b) => b.v - a.v);
        document.getElementById('mKelInfo').textContent = list.length ? '· ' + list.length + ' kelompok' : '';
        document.getElementById('mKel').innerHTML = list.length ? list.map(x =>
          '<div class="kel-row"><div class="kel-nama"><span class="kel-swatch" style="background:' + esc(x.k.warna) + '"></span>' + esc(pendek(x.k)) + (subKel(x.k) ? '<small>' + esc(subKel(x.k)) + '</small>' : '') + '</div>' +
          '<div class="kel-bar"><span style="width:' + Math.min(100, pct(x.v, r.luas)) + '%;background:' + esc(x.k.warna) + '"></span></div>' +
          '<div class="kel-val">' + fmtInt.format(x.v) + ' m²<small>' + fmtPct(pct(x.v, r.luas)) + ' dari luas</small></div></div>').join('')
          : '<div class="text-muted small">Belum ada penggunaan yang tercatat untuk aset ini.</div>';

        const boxP = document.getElementById('mPengguna');
        boxP.innerHTML = '<div class="text-muted small">Memuat…</div>';
        fetch(ENDPOINT + '?ajax_action=pengguna&sert=' + encodeURIComponent(r.sertifikat), { credentials: 'same-origin', cache: 'no-store' })
          .then(x => x.json())
          .then(j => {
            if (!j.ok) throw new Error(j.error || 'Gagal');
            if (!j.aktif) { boxP.innerHTML = '<div class="alert alert-info mb-0 small">Tab rincian pengguna belum diisi di <code>$TAB_PENGGUNA</code>.</div>'; return; }
            if (!j.hasil.length) { boxP.innerHTML = '<div class="text-muted small">Tidak ada pengguna/mitra tercatat untuk sertifikat ini di tab kelompok.</div>'; return; }
            boxP.innerHTML = j.hasil.map(g =>
              '<div class="m-grp"><span>' + esc(g.kelompok) + ' <span class="badge text-bg-secondary">' + g.baris.length + '</span></span>' +
              (g.total_luas ? '<span class="small text-muted">' + fmtInt.format(g.total_luas) + ' m²</span>' : '') + '</div>' +
              g.baris.map(b =>
                '<div class="m-rec"><div class="m-rec-head"><span>' + esc(b.pihak || '(tanpa nama pihak)') + '</span>' +
                (b.luas != null ? '<span class="m-luas">' + fmtInt.format(b.luas) + ' m²</span>' : '') + '</div>' +
                (b.detail.length ? '<div class="m-grid">' + b.detail.map(p => mCell(p[0], esc(fmtDetail(p[0], p[1])), /alamat/i.test(p[0]) || String(p[1]).length > 55)).join('') + '</div>' : '') + '</div>').join('')).join('');
          })
          .catch(e => { boxP.innerHTML = '<div class="text-danger small">Gagal memuat data pengguna: ' + esc(e.message) + '</div>'; });
        if (!modalAset) modalAset = new bootstrap.Modal(document.getElementById('modalAset'));
        modalAset.show();
      }

      // ---------- event ----------
      document.addEventListener('DOMContentLoaded', () => {
        const ids = [...Object.keys(FILTER), 'fKel'];
        for (const id of ids) document.getElementById(id).addEventListener('change', terapkan);
        document.getElementById('btnReset').addEventListener('click', () => { for (const id of ids) document.getElementById(id).value = ''; terapkan(); });
        document.getElementById('btnMuat').addEventListener('click', () => {
          if (lockMuat) return; lockMuat = true; setTimeout(() => lockMuat = false, 5000);
          muatData(true);
        });
        document.getElementById('autoRefresh').addEventListener('change', jadwalkan);
        document.getElementById('semuaKolom').addEventListener('change', () => {
          if (!dt) return;
          dt.destroy(); dt = null;                                            // bangun ulang supaya header gabungan tetap rapi
          document.getElementById('tabelLahan').innerHTML = '<thead></thead><tbody></tbody>';
          renderTabel();
        });
        document.addEventListener('click', e => {
          const a = e.target.closest('.lihat-aset'); if (!a) return;
          e.preventDefault(); bukaRincian(a.dataset.id);
        });
        document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && document.getElementById('autoRefresh').checked && sisaDetik <= 0) muatData(false); });
        muatData(false);
      });

      // ---------- tab halaman ----------
      document.addEventListener('DOMContentLoaded', () => {
        const nav = document.getElementById('lahanTabs');
        const tombol = key => nav.querySelector('button[data-tab="' + key + '"]');
        nav.addEventListener('shown.bs.tab', e => {
          const b = e.target, key = b.dataset.tab;
          const pane = document.querySelector(b.getAttribute('data-bs-target'));
          const f = pane ? pane.querySelector('iframe') : null;
          if (f && b.dataset.src && !f.getAttribute('src')) f.setAttribute('src', b.dataset.src);  
          if (key === 'rekap' && dt) dt.columns.adjust().draw(false);
          try { history.replaceState(null, '', key === 'rekap' ? location.pathname + location.search : '#' + key); } catch (err) {}
        });
        const h = (location.hash || '').replace('#', '');
        if (h && h !== 'rekap' && tombol(h)) bootstrap.Tab.getOrCreateInstance(tombol(h)).show();
        window.addEventListener('message', e => {           // tinggi iframe mengikuti isi
          if (e.origin !== location.origin || !e.data || typeof e.data.lahanEmbedHeight !== 'number') return;
          document.querySelectorAll('iframe.lahan-frame').forEach(f => { if (f.contentWindow === e.source) f.style.height = Math.max(320, e.data.lahanEmbedHeight + 8) + 'px'; });
        });
      });
    </script>
  </body>
</html>