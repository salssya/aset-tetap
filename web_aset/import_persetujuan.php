<?php
/**
 * SCRIPT IMPORT CSV PERSETUJUAN PENGHAPUSAN (revisi)
 * Taruh file ini di: htdocs/dashboard/import_persetujuan.php
 * Akses via browser: http://localhost/dashboard/import_persetujuan.php
 *
 * Cara pakai:
 * 1. Upload semua CSV ke folder: htdocs/dashboard/csv_import/
 * 2. Header CSV harus (urutan kolom persis seperti ini):
 *    Nomor Aset | Subreg | Cabang | Profit Center | Keterangan Aset | Asset Class |
 *    Nilai Aset | Umur Ekonomis | Tanggal Perolehan | Nilai Perolehan |
 *    Mekanisme Penghapusan | Alasan Penghapusan | Kajian Hukum | Kajian Ekonomis |
 *    Kajian Risiko | Nilai Pasar
 * 3. Akses halaman ini, klik "Mulai Import"
 *
 * CATATAN:
 * - Kolom "Nilai Pasar" (paling akhir) TIDAK disimpan ke usulan_penghapusan karena
 *   tabel ini tidak punya kolom untuk itu (yang ada cuma nilai_buku & nilai_perolehan).
 *   Nilai Pasar dari CSV yang sama justru dipakai di script backfill_pelaksanaan.php
 *   untuk mengisi kolom nilai_appraisal_pasar di tabel pelaksanaan_penghapusan.
 * - Semua status di bawah di-set 'approved'. Kalau kolom status / status_approval
 *   ternyata enum-nya bukan 'approved' persis, ubah nilai $STATUS_* di bagian
 *   KONFIGURASI di bawah ini sesuai nilai enum yang benar (cek tab Structure phpMyAdmin).
 */

// ── KONFIGURASI STATUS (ubah di sini kalau nilai enum berbeda) ────────────
$STATUS_UTAMA              = 'approved';          // kolom: status -> enum('draft','lengkapi_dokumen','dokumen_lengkap','submitted','approved','rejected')
$STATUS_APPROVAL           = 'approved_regional';  // kolom: status_approval -> enum('pending','approved_subreg','rejected_subreg','approved_regional','rejected_regional') -- TIDAK ADA nilai 'approved' polos di kolom ini
$STATUS_APPROVAL_SUBREG    = 'approved'; // kolom: status_approval_subreg -> enum('pending','approved','rejected')
$STATUS_APPROVAL_REGIONAL  = 'approved'; // kolom: status_approval_regional -> enum('pending','approved','rejected')
$STATUS_APPROVAL_HO        = 'approved'; // kolom: status_approval_ho -> enum('pending','approved','rejected')

$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "asetreg3_db";

$con = new mysqli($servername, $username, $password, $dbname);
$con->set_charset("utf8mb4");

if ($con->connect_error) {
    die("Koneksi gagal: " . $con->connect_error);
}

// ── Helper: bersihkan nilai Rupiah → angka ────────────────────────────────
function parseRupiah($str) {
    if (empty($str) || $str === '-' || $str === '#N/A') return 0;
    $str = preg_replace('/[^0-9,.]/', '', $str);
    // Asumsi format Indonesia: titik = ribuan, koma = desimal
    $str = str_replace('.', '', $str);
    $str = str_replace(',', '.', $str);
    return (float)$str ?: 0;
}

// ── Helper: mapping mekanisme (harus persis sesuai enum di tabel) ─────────
function parseMekanisme($str) {
    $str = strtolower(trim($str ?? ''));
    if (strpos($str, 'jual') !== false || $str === 'penjualan') return 'Jual Lelang';
    if (strpos($str, 'hapus') !== false || strpos($str, 'administrasi') !== false) return 'Hapus Administrasi';
    return 'Jual Lelang'; // default
}

// ── Helper: baca CSV dengan benar (handle koma dalam quotes) ──────────────
function readCsvRows($filepath) {
    $rows = [];
    if (($handle = fopen($filepath, 'r')) !== false) {
        while (($data = fgetcsv($handle, 4000, ',')) !== false) {
            $rows[] = $data;
        }
        fclose($handle);
    }
    return $rows;
}

// ── Folder CSV ────────────────────────────────────────────────────────────
$csvDir = __DIR__ . '/csv_import/';

// Proses import jika tombol diklik
$importLog   = [];
$totalInsert = 0;
$totalSkip   = 0;
$totalError  = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_import'])) {
    $selectedFiles     = $_POST['csv_files'] ?? [];
    $tahunUsulanManual = trim($_POST['tahun_usulan_manual'] ?? '');
    $createdByNipp     = trim($_POST['created_by_nipp'] ?? '') ?: '1234567890';

    foreach ($selectedFiles as $fname) {
        $filepath = $csvDir . basename($fname);
        if (!file_exists($filepath)) {
            $importLog[] = ['status' => 'error', 'file' => $fname, 'msg' => 'File tidak ditemukan'];
            continue;
        }

        // Prioritas tahun_usulan:
        // 1) Tahun yang dipilih manual di form (dropdown "Tahun Usulan/Persetujuan")
        // 2) Kalau form dikosongkan/"Auto dari nama file", deteksi dari nama file CSV
        // 3) Kalau nama file juga tidak ada angka tahun, pakai tahun sekarang
        if ($tahunUsulanManual !== '' && ctype_digit($tahunUsulanManual)) {
            $tahunUsulan = (int)$tahunUsulanManual;
        } else {
            preg_match('/(\d{4})/', $fname, $m);
            $tahunUsulan = isset($m[1]) ? (int)$m[1] : (int)date('Y');
        }

        $rows    = readCsvRows($filepath);
        $fileLog = ['file' => $fname, 'tahun' => $tahunUsulan, 'inserted' => 0, 'skipped' => 0, 'skippedList' => [], 'renamedList' => [], 'errors' => []];

        foreach ($rows as $row) {
            // Kolom CSV (urutan wajib sesuai ini - ada kolom NO. di paling depan):
            // 0=NO., 1=Nomor Aset, 2=Subreg, 3=Cabang, 4=Profit Center, 5=Keterangan Aset,
            // 6=Asset Class, 7=Nilai Aset, 8=Umur Ekonomis, 9=Tanggal Perolehan,
            // 10=Nilai Perolehan, 11=Mekanisme Penghapusan, 12=Alasan Penghapusan,
            // 13=Kajian Hukum, 14=Kajian Ekonomis, 15=Kajian Risiko, 16=Nilai Pasar (tidak dipakai)

            $no       = trim($row[0] ?? '');
            $noAset   = trim($row[1] ?? '');
            $subreg   = trim($row[2] ?? '');
            $cabang   = trim($row[3] ?? '');
            $namaAset = trim($row[5] ?? '');

            // Skip baris header / baris kosong (baris data asli punya NO. yang berupa angka)
            if (!is_numeric($no) || empty($noAset) || empty($namaAset)) continue;

            $profitCenter   = trim($row[4] ?? '');
            $assetClass     = trim($row[6] ?? '');
            $nilaiAset      = parseRupiah($row[7] ?? '');   // -> nilai_buku
            $umurEkonomis   = trim($row[8] ?? '');
            $tglPerolehan   = trim($row[9] ?? '');
            $nilaiPerolehan = parseRupiah($row[10] ?? '');  // -> nilai_perolehan
            $mekanisme      = parseMekanisme($row[11] ?? '');
            $alasan         = trim($row[12] ?? '');
            $kajianHukum    = trim($row[13] ?? '');
            $kajianEkonomi  = trim($row[14] ?? '');
            $kajianRisiko   = trim($row[15] ?? '');
            // $nilaiPasar   = parseRupiah($row[16] ?? ''); // tidak disimpan, lihat catatan di atas


            // tahun_usulan SELALU ikut tahun dari nama file CSV yang diupload
            // (bukan dari Tanggal Perolehan aset). Jadi kalau upload file "...2021...csv",
            // semua baris di file itu otomatis tahun_usulan = 2021.
            $tahunUsulanRow = $tahunUsulan;

            // Cek apakah nomor aset ini (atau varian -1/-2/dst-nya) sudah ada di DB.
            // Nomor aset yang SAMA bisa dipakai lebih dari satu aset fisik yang BEDA
            // (misal 1 nomor induk punya beberapa sub-aset) - jadi yang dianggap
            // duplikat ASLI (di-skip) itu kalau nomor aset DAN nama/keterangan asetnya
            // sama-sama persis sudah ada. Kalau nomor sama tapi nama beda, tetap
            // dimasukkan dengan nomor_asset_utama diberi suffix -1, -2, dst supaya
            // tidak bentrok, mengikuti format "nomor aset - sub aset" yang sudah dipakai
            // di form (Nomor Aset Pengganti).
            $stmtCek = $con->prepare("SELECT nomor_asset_utama, nama_aset FROM usulan_penghapusan
                WHERE nomor_asset_utama = ? OR nomor_asset_utama LIKE CONCAT(?, '-%')");
            $stmtCek->bind_param("ss", $noAset, $noAset);
            $stmtCek->execute();
            $resCek = $stmtCek->get_result();

            $isDuplikatAsli = false;
            $baseSudahDipakai = false;
            $maxSuffix = -1;
            while ($r = $resCek->fetch_assoc()) {
                if (mb_strtolower(trim($r['nomor_asset_utama'])) === mb_strtolower($noAset)) {
                    $baseSudahDipakai = true;
                }
                if (preg_match('/^' . preg_quote($noAset, '/') . '-(\d+)$/i', $r['nomor_asset_utama'], $m)) {
                    $maxSuffix = max($maxSuffix, (int)$m[1]);
                }
                // Anggap duplikat asli kalau nama asetnya juga sama persis (diabaikan besar-kecil huruf & spasi)
                if (mb_strtolower(trim($r['nama_aset'])) === mb_strtolower(trim($namaAset))) {
                    $isDuplikatAsli = true;
                }
            }
            $stmtCek->close();

            if ($isDuplikatAsli) {
                $fileLog['skipped']++;
                $fileLog['skippedList'][] = ['noAset' => $noAset, 'nama' => $namaAset];
                $totalSkip++;
                continue;
            }

            // Nomor aset sudah dipakai tapi asetnya beda -> kasih suffix biar unik
            $noAsetFinal = $noAset;
            if ($baseSudahDipakai) {
                $noAsetFinal = $noAset . '-' . ($maxSuffix + 1);
                $fileLog['renamedList'][] = ['noAsetAsli' => $noAset, 'noAsetBaru' => $noAsetFinal, 'nama' => $namaAset];
            }

            $fisikAset = 'Tidak Ada'; 
            $fotoPath  = ''; // 
            $tglNow    = date('Y-m-d');

            $stmt = $con->prepare("INSERT INTO usulan_penghapusan (
                nomor_asset_utama, subreg, profit_center, profit_center_text,
                nama_aset, kategori_aset,
                umur_ekonomis, tgl_perolehan, nilai_buku, nilai_perolehan,
                mekanisme_penghapusan, fisik_aset,
                justifikasi_alasan, kajian_hukum, kajian_ekonomis, kajian_risiko,
                foto_path,
                tahun_usulan,
                status, status_approval, status_approval_subreg,
                status_approval_regional, status_approval_ho, tanggal_approval_ho,
                created_by,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

            if (!$stmt) {
                $fileLog['errors'][] = "Prepare gagal ($noAset): " . $con->error;
                $totalError++;
                continue;
            }

            $stmt->bind_param(
                "ssssssssddsssssssisssssss",
                $noAsetFinal,
                $subreg,
                $profitCenter,
                $cabang,           // profit_center_text
                $namaAset,
                $assetClass,        // kategori_aset
                $umurEkonomis,
                $tglPerolehan,
                $nilaiAset,         // nilai_buku
                $nilaiPerolehan,    // nilai_perolehan
                $mekanisme,
                $fisikAset,
                $alasan,            // justifikasi_alasan
                $kajianHukum,
                $kajianEkonomi,
                $kajianRisiko,
                $fotoPath,
                $tahunUsulanRow,
                $STATUS_UTAMA,
                $STATUS_APPROVAL,
                $STATUS_APPROVAL_SUBREG,
                $STATUS_APPROVAL_REGIONAL,
                $STATUS_APPROVAL_HO,
                $tglNow,            // tanggal_approval_ho
                $createdByNipp      // created_by
            );

            if (!$stmt->execute()) {
                $fileLog['errors'][] = "Nomor Aset $noAset: " . $stmt->error;
                $totalError++;
                $stmt->close();
                continue;
            }
            $uid = $con->insert_id;
            $stmt->close();

            // ── Insert juga ke pelaksanaan_penghapusan supaya muncul di halaman "Pelaksanaan Penghapusan" ──
            $stmtPel = $con->prepare("INSERT INTO pelaksanaan_penghapusan
                (usulan_id, status_pelaksanaan, subreg, profit_center, tanggal_persetujuan, nipp)
                VALUES (?, 'Disetujui', ?, ?, ?, '00000')");
            if ($stmtPel) {
                $stmtPel->bind_param("isss", $uid, $subreg, $profitCenter, $tglNow);
                if (!$stmtPel->execute()) {
                    $fileLog['errors'][] = "Nomor Aset $noAset (pelaksanaan_penghapusan): " . $stmtPel->error;
                }
                $stmtPel->close();
            } else {
                $fileLog['errors'][] = "Nomor Aset $noAset: prepare pelaksanaan_penghapusan gagal - " . $con->error;
            }

            $fileLog['inserted']++;
            $totalInsert++;
        }

        $importLog[] = $fileLog;
    }
}

// ── Scan file CSV di folder ───────────────────────────────────────────────
$csvFiles = [];
if (is_dir($csvDir)) {
    foreach (glob($csvDir . '*.csv') as $f) {
        $csvFiles[] = basename($f);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Import CSV Persetujuan Penghapusan</title>
  <link rel="stylesheet" href="dist/css/bootstrap.min.css">
  <style>
    body { background: #f0f4f8; font-family: sans-serif; }
    .wrap { max-width: 860px; margin: 40px auto; background: #fff; border-radius: 12px; padding: 32px; box-shadow: 0 2px 16px rgba(0,0,0,.08); }
    h2 { color: #0b3a8c; margin-bottom: 4px; }
    .step { background: #f8f9fa; border-left: 4px solid #0b3a8c; padding: 12px 16px; border-radius: 0 8px 8px 0; margin-bottom: 16px; font-size: .9rem; }
    .log-box { background: #1e1e2e; color: #cdd6f4; border-radius: 8px; padding: 16px; font-family: monospace; font-size: .82rem; max-height: 360px; overflow-y: auto; margin-top: 16px; }
    .ok  { color: #a6e3a1; }
    .err { color: #f38ba8; }
    .skip{ color: #fab387; }
    .info{ color: #89b4fa; }
    .badge-ok   { background: #d1fae5; color: #065f46; padding: 2px 10px; border-radius: 20px; font-size:.8rem; }
    .badge-skip { background: #fef3c7; color: #92400e; padding: 2px 10px; border-radius: 20px; font-size:.8rem; }
    .badge-err  { background: #fee2e2; color: #991b1b; padding: 2px 10px; border-radius: 20px; font-size:.8rem; }
  </style>
</head>
<body>
<div class="wrap">
  <h2>📥 Import CSV Persetujuan Penghapusan</h2>
  <p class="text-muted small">Data langsung masuk ke DB dengan status <strong>approved</strong> (skip flow usulan)</p>

  <div class="step">
    <strong>Cara Pakai:</strong><br>
    1. Buat folder <code>htdocs/dashboard/csv_import/</code><br>
    2. Copy semua file CSV ke folder tersebut (header kolom harus sesuai urutan yang ditulis di komentar script)<br>
    3. Centang file yang mau diimport, klik <strong>Mulai Import</strong><br>
    4. Data yang <em>nomor asetnya sudah ada</em> di DB akan otomatis di-skip (tidak duplikat)
  </div>

  <?php if (!is_dir($csvDir)): ?>
    <div class="alert alert-warning">
      <strong>⚠️ Folder belum ada!</strong><br>
      Buat folder: <code><?= htmlspecialchars($csvDir) ?></code><br>
      Atau jalankan perintah ini di terminal XAMPP:<br>
      <code>mkdir "<?= htmlspecialchars($csvDir) ?>"</code>
    </div>
    <?php
      if (!mkdir($csvDir, 0777, true)) {
          echo '<div class="alert alert-danger">Gagal buat folder otomatis. Buat manual.</div>';
      } else {
          echo '<div class="alert alert-success">✅ Folder berhasil dibuat! Sekarang copy CSV ke: <code>' . htmlspecialchars($csvDir) . '</code></div>';
      }
    ?>
  <?php elseif (empty($csvFiles)): ?>
    <div class="alert alert-info">
      📂 Folder ditemukan tapi <strong>belum ada file CSV</strong>.<br>
      Copy semua CSV ke: <code><?= htmlspecialchars($csvDir) ?></code>
    </div>
  <?php else: ?>
    <form method="POST">
      <div class="mb-3">
        <label class="form-label fw-semibold">Tahun Usulan / Persetujuan:</label>
        <select name="tahun_usulan_manual" class="form-select" style="max-width:260px;">
          <option value="">Auto (deteksi dari nama file CSV)</option>
          <?php
            $tahunSekarang = (int)date('Y');
            for ($y = $tahunSekarang + 1; $y >= 2015; $y--) {
                echo '<option value="' . $y . '">' . $y . '</option>';
            }
          ?>
        </select>
        <div class="form-text">
          Pilih tahun di sini kalau mau semua data yang diimport (dari file yang dicentang di bawah)
          langsung diberi <code>tahun_usulan</code> sesuai pilihanmu — misalnya kamu upload file lama
          tapi datanya untuk persetujuan tahun 2020, tinggal pilih 2020.
          Kalau dibiarkan "Auto", tahun akan dideteksi dari angka 4 digit di nama file CSV.
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold">NIPP Pengimport (akan diisi ke kolom "Diajukan Oleh"):</label>
        <input type="text" name="created_by_nipp" class="form-control" style="max-width:260px;" value="1234567890">
        <div class="form-text">
          NIPP ini yang akan muncul di kolom "Diajukan Oleh" pada halaman Usulan Penghapusan.
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold">Pilih File CSV yang akan diimport:</label>
        <div class="mb-1">
          <input type="checkbox" id="selectAll"> <label for="selectAll" class="small">Pilih Semua</label>
        </div>
        <?php foreach ($csvFiles as $f): ?>
        <div class="form-check">
          <input class="form-check-input csv-cb" type="checkbox" name="csv_files[]"
                 value="<?= htmlspecialchars($f) ?>" id="cb_<?= md5($f) ?>">
          <label class="form-check-label" for="cb_<?= md5($f) ?>">
            <?= htmlspecialchars($f) ?>
          </label>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="alert alert-warning small">
        <strong>⚠️ Perhatian:</strong> Script ini akan INSERT langsung ke DB dengan status <code>approved</code>.
        Data yang nomor asetnya sudah ada akan di-<strong>skip</strong> otomatis.
        Pastikan backup DB dulu sebelum import!
      </div>

      <button type="submit" name="do_import" class="btn btn-primary">
        📥 Mulai Import
      </button>
    </form>
  <?php endif; ?>

  <?php if (!empty($importLog)): ?>
  <hr>
  <h5 class="mt-3">📊 Hasil Import</h5>
  <div class="d-flex gap-3 mb-3">
    <span class="badge-ok">✅ Berhasil: <?= $totalInsert ?></span>
    <span class="badge-skip">⏭ Di-skip: <?= $totalSkip ?></span>
    <span class="badge-err">❌ Error: <?= $totalError ?></span>
  </div>
  <div class="log-box">
    <?php foreach ($importLog as $log): ?>
      <?php if (isset($log['status']) && $log['status'] === 'error'): ?>
        <div class="err">❌ <?= htmlspecialchars($log['file']) ?>: <?= htmlspecialchars($log['msg']) ?></div>
      <?php else: ?>
        <div class="info">📄 <?= htmlspecialchars($log['file']) ?> (Tahun dipakai: <?= $log['tahun'] ?><?= $tahunUsulanManual !== '' ? ' — manual dari form' : ' — auto dari nama file' ?>)</div>
        <div class="ok">   ✅ Inserted: <?= $log['inserted'] ?></div>
        <div class="skip"> ⏭ Skipped : <?= $log['skipped'] ?></div>
        <?php if (!empty($log['skippedList'])): ?>
          <details style="margin:4px 0 8px 20px;">
            <summary style="cursor:pointer; color:#fab387;">Lihat <?= count($log['skippedList']) ?> nomor aset yang di-skip (sudah ada di DB)</summary>
            <?php foreach ($log['skippedList'] as $s): ?>
              <div class="skip" style="padding-left:14px;">— <?= htmlspecialchars($s['noAset']) ?> (<?= htmlspecialchars($s['nama']) ?>)</div>
            <?php endforeach; ?>
          </details>
        <?php endif; ?>
        <?php if (!empty($log['renamedList'])): ?>
          <details style="margin:4px 0 8px 20px;">
            <summary style="cursor:pointer; color:#89dceb;">Lihat <?= count($log['renamedList']) ?> nomor aset yang diberi suffix (nomor sama, aset beda)</summary>
            <?php foreach ($log['renamedList'] as $rn): ?>
              <div style="padding-left:14px; color:#89dceb;">— <?= htmlspecialchars($rn['noAsetAsli']) ?> → <b><?= htmlspecialchars($rn['noAsetBaru']) ?></b> (<?= htmlspecialchars($rn['nama']) ?>)</div>
            <?php endforeach; ?>
          </details>
        <?php endif; ?>
        <?php foreach ($log['errors'] as $e): ?>
          <div class="err">   ⚠️ <?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
        <br>
      <?php endif; ?>
    <?php endforeach; ?>
    <div class="ok">═══ SELESAI: <?= $totalInsert ?> data berhasil diimport ═══</div>
  </div>
  <?php endif; ?>

</div>

<script>
document.getElementById('selectAll')?.addEventListener('change', function() {
  document.querySelectorAll('.csv-cb').forEach(cb => cb.checked = this.checked);
});
</script>
</body>
</html>