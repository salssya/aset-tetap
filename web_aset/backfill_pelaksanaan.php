<?php
/**
 * SCRIPT BACKFILL PELAKSANAAN_PENGHAPUSAN
 * Taruh file ini di: htdocs/dashboard/aset-tetap/web_aset/backfill_pelaksanaan.php
 * Akses via browser: http://localhost/dashboard/aset-tetap/web_aset/backfill_pelaksanaan.php
 *
 * Fungsi: Mencari semua baris di usulan_penghapusan yang BELUM punya pasangan
 * di pelaksanaan_penghapusan (usulan_id-nya belum ada), lalu membuatkan
 * baris pelaksanaan_penghapusan untuk masing-masing, dengan status 'Disetujui'.
 *
 * Script ini TIDAK menyentuh / menghapus data yang sudah ada di pelaksanaan_penghapusan.
 * Aman dijalankan berkali-kali - baris yang sudah punya pasangan otomatis dilewati.
 */

$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "asetreg3_db";

$con = new mysqli($servername, $username, $password, $dbname);
$con->set_charset("utf8mb4");

if ($con->connect_error) {
    die("Koneksi gagal: " . $con->connect_error);
}

$log        = [];
$totalOk    = 0;
$totalError = 0;
$totalSkip  = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_backfill'])) {
    // Ambil semua usulan_penghapusan yang belum ada pasangannya di pelaksanaan_penghapusan
    $sql = "SELECT u.id, u.nomor_asset_utama, u.subreg, u.profit_center
            FROM usulan_penghapusan u
            LEFT JOIN pelaksanaan_penghapusan p ON p.usulan_id = u.id
            WHERE p.id IS NULL
            AND u.status = 'approved'";
    $result = $con->query($sql);

    $tglNow = date('Y-m-d');

    while ($row = $result->fetch_assoc()) {
        $uid          = $row['id'];
        $noAset       = $row['nomor_asset_utama'];
        $subreg       = $row['subreg'];
        $profitCenter = $row['profit_center'];

        $stmt = $con->prepare("INSERT INTO pelaksanaan_penghapusan
            (usulan_id, status_pelaksanaan, subreg, profit_center, tanggal_persetujuan, nipp)
            VALUES (?, 'Disetujui', ?, ?, ?, '00000')");

        if (!$stmt) {
            $log[] = ['status' => 'error', 'noAset' => $noAset, 'msg' => $con->error];
            $totalError++;
            continue;
        }

        $stmt->bind_param("isss", $uid, $subreg, $profitCenter, $tglNow);

        if (!$stmt->execute()) {
            $log[] = ['status' => 'error', 'noAset' => $noAset, 'msg' => $stmt->error];
            $totalError++;
        } else {
            $log[] = ['status' => 'ok', 'noAset' => $noAset];
            $totalOk++;
        }
        $stmt->close();
    }
}

// Hitung berapa yang masih belum ada pasangannya (untuk ditampilkan sebelum klik tombol)
$pending = 0;
$resCount = $con->query("SELECT COUNT(*) AS cnt
    FROM usulan_penghapusan u
    LEFT JOIN pelaksanaan_penghapusan p ON p.usulan_id = u.id
    WHERE p.id IS NULL AND u.status = 'approved'");
if ($resCount) {
    $pending = (int)$resCount->fetch_assoc()['cnt'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Backfill Pelaksanaan Penghapusan</title>
  <link rel="stylesheet" href="dist/css/bootstrap.min.css">
  <style>
    body { background: #f0f4f8; font-family: sans-serif; }
    .wrap { max-width: 780px; margin: 40px auto; background: #fff; border-radius: 12px; padding: 32px; box-shadow: 0 2px 16px rgba(0,0,0,.08); }
    h2 { color: #0b3a8c; }
    .log-box { background: #1e1e2e; color: #cdd6f4; border-radius: 8px; padding: 16px; font-family: monospace; font-size: .82rem; max-height: 360px; overflow-y: auto; margin-top: 16px; }
    .ok  { color: #a6e3a1; }
    .err { color: #f38ba8; }
  </style>
</head>
<body>
<div class="wrap">
  <h2>🔗 Backfill Pelaksanaan Penghapusan</h2>
  <p class="text-muted small">
    Mengisi tabel <code>pelaksanaan_penghapusan</code> untuk data <code>usulan_penghapusan</code>
    berstatus <strong>approved</strong> yang belum punya pasangan pelaksanaan.
  </p>

  <div class="alert alert-info">
    Ditemukan <strong><?= $pending ?></strong> data yang belum punya pasangan di <code>pelaksanaan_penghapusan</code>.
  </div>

  <?php if ($pending > 0): ?>
  <form method="POST">
    <button type="submit" name="do_backfill" class="btn btn-primary">
      🔗 Jalankan Backfill Sekarang
    </button>
  </form>
  <?php endif; ?>

  <?php if (!empty($log)): ?>
  <hr>
  <h5>Hasil</h5>
  <div class="alert alert-success">
    ✅ Berhasil: <?= $totalOk ?> &nbsp; | &nbsp; ❌ Error: <?= $totalError ?>
  </div>
  <div class="log-box">
    <?php foreach ($log as $l): ?>
      <?php if ($l['status'] === 'ok'): ?>
        <div class="ok">✅ <?= htmlspecialchars($l['noAset']) ?></div>
      <?php else: ?>
        <div class="err">❌ <?= htmlspecialchars($l['noAset']) ?>: <?= htmlspecialchars($l['msg']) ?></div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
</body>
</html>