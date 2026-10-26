<?php
// File import bisa sampai 50MB: beri ruang memori & waktu eksekusi yang cukup untuk membaca file besar.
@ini_set('memory_limit', '1024M');
@set_time_limit(600);
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "asetreg3_db";
 
// Create connection
$con = mysqli_connect($servername, $username, $password, $dbname);
session_start();
if(!isset($_SESSION["nipp"]) || !isset($_SESSION["name"])) {
    header("Location: ../login/login_view.php");
    exit();
}

// Variabel untuk card "Import DAT"
$importedData = [];
$pesan = "";
$tipe_pesan = "";
$saved_count = 0;

// Variabel untuk card "Import Data Penyusutan"
$importedDataPenyusutan = [];
$pesanPenyusutan = "";
$tipePenyusutan = "";
$savedCountPenyusutan = 0;

// Card "Import Nilai Perolehan", "Import Nilai Akumulasi", dan "Import Nilai DEP":
// format file SAMA dengan Import Data Penyusutan (Cost Center, Asset, Asset Subnumber, Account, Document Number,
// Posting Date, Amount in Local Currency, Profit Center, Text), tapi tiap jenis disimpan di tabel sendiri
// dan punya periode (Bulan + Tahun) yang dipilih saat upload. Upload ulang periode yang sama menimpa data lama.
$NILAI_ASET_JENIS = [
    'nilai_perolehan' => ['judul' => 'Nilai Perolehan', 'tabel' => 'import_nilai_perolehan'],
    'nilai_akumulasi' => ['judul' => 'Nilai Akumulasi', 'tabel' => 'import_nilai_akumulasi'],
    'nilai_dep'       => ['judul' => 'Nilai DEP',       'tabel' => 'import_nilai_dep'],
];
$pesanNilai = [];
$tipeNilai  = [];

$TARGET_HEADERS_PENYUSUTAN = [
    'cost center', 'asset', 'asset subnumber', 'account',
    'posting date', 'amount in local currency', 'profit center', 'text', 'document number'
];

$WAJIB_HEADERS_PENYUSUTAN = [
    'cost center', 'asset', 'asset subnumber', 'account',
    'posting date', 'amount in local currency', 'profit center', 'text'
];

// Card "Import DAT" (6 kolom acuan untuk dashboard Data Penyusutan)
$HEADER_TO_FIELD_DAT = [
    'profit center'         => 'profit_center',
    'nama cabang/kawasan'   => 'cabang',
    'periode/bulan'         => 'periode_bulan',
    'tahun buku'            => 'tahun_buku',
    'nomor asset'           => 'nomor_asset',
    'sub-number'            => 'sub_number',
    'keterangan asset'      => 'keterangan_asset',
    'gl account exp depre'  => 'gl_account_exp',
    'tgl perolehan'         => 'tgl_perolehan',
    'sisa manfaat aset'     => 'sisa_manfaat_aset',
];
// Urutan HARUS sama persis dengan $column_names di saveDatPenyusutanToDatabase()
$DB_COLUMNS_DAT_ORDERED = [
    'profit_center', 'cabang', 'periode_bulan', 'tahun_buku', 'nomor_asset', 'sub_number', 'keterangan_asset', 'gl_account_exp',
    'tgl_perolehan', 'sisa_manfaat_aset',
];
// Kolom yang WAJIB ketemu di header file, kalau tidak ada langsung tolak
$WAJIB_ADA_DAT = ['profit center', 'nama cabang/kawasan', 'periode/bulan', 'tahun buku', 'nomor asset', 'sub-number', 'keterangan asset', 
'tgl perolehan', 'sisa manfaat aset', 'gl account exp depre'];

// Variabel untuk card "Import DAT" versi Monitoring (tabel SENDIRI: import_dat_monitoring --
// TERPISAH dari import_dat_penyusutan yang dipakai dashboard Penyusutan, jadi bebas beda kolom)
$HEADER_TO_FIELD_DAT_MONITORING = [
    'profit center'                     => 'profit_center',
    'nama cabang/kawasan'               => 'cabang',
    'periode/bulan'                     => 'periode_bulan',
    'tahun buku'                        => 'tahun_buku',
    'nomor asset'                       => 'nomor_asset',
    'sub-number'                        => 'sub_number',
    'keterangan asset'                  => 'keterangan_asset',
    'tgl perolehan'                     => 'tgl_perolehan',
    'tgl mulai penyusutan'              => 'tgl_mulai_penyusutan',
    'nilai perolehan sd tahun berjalan' => 'nilai_perolehan_sd_tahun_berjalan',
    'akumulasi penyusutan'              => 'akumulasi_penyusutan',
    'gl account exp depre'              => 'gl_account_exp',
    'gl account aset'                   => 'gl_account_aset', // OPSIONAL (tidak masuk WAJIB_ADA); varian nama header: lihat $ALT_HEADER_GL_ASET_DAT_MON
];
// Varian penulisan header GL Aset yang juga diterima (sudah dinormalisasi: huruf kecil, tanpa titik)
$ALT_HEADER_GL_ASET_DAT_MON = ['gl account aset', 'gl account asset', 'gl aset', 'gl asset'];
// Urutan HARUS sama persis dengan $column_names di saveDatMonitoringToDatabase()
$DB_COLUMNS_DAT_MONITORING_ORDERED = [
    'profit_center', 'cabang', 'periode_bulan', 'tahun_buku', 'nomor_asset', 'sub_number', 'gl_account_aset', 'keterangan_asset',
    'tgl_perolehan', 'tgl_mulai_penyusutan', 'nilai_perolehan_sd_tahun_berjalan', 'akumulasi_penyusutan', 'gl_account_exp',
];
// Kolom yang WAJIB ketemu di header file, kalau tidak ada langsung tolak
$WAJIB_ADA_DAT_MONITORING = ['profit center', 'nama cabang/kawasan', 'periode/bulan', 'tahun buku', 'nomor asset', 'sub-number', 'keterangan asset', 'gl account exp depre'];

// Variabel untuk card "Import AR02 reg3"
$importedDataAr02 = [];
$pesanAr02 = "";
$tipeAr02 = "";
$savedCountAr02 = 0;

// Card "Import AR02 reg3" -- data mutasi aset (Penambahan/Pengurangan/Reklasifikasi) per aset,
// dipakai buat rollforward "Harga Perolehan" di dashboard Aset Tetap (setara SUMIF ke kolom
// R/S/T di sheet 'AR02 reg3' Excel). File ini TIDAK punya kolom Periode/Tahun sendiri (snapshot
// per tanggal tarik data dari SAP), makanya periode diambil dari input form saat upload.
$HEADER_TO_FIELD_AR02 = [
    'balshacct apc'          => 'bal_sh_acct_APC',        // kolom C -> key pencocokan ke sheet Aset Tetap
    'asset'                  => 'nomor_asset',
    'sub-number'             => 'sub_number',
    'asset class'            => 'asset_class',
    'akumulasi penyusutan'   => 'gl_akumulasi_penyusutan',
    'ckpn'                   => 'ckpn',
    'beban penyusutan'       => 'gl_beban_penyusutan',    // GL code, nyambung ke dashboard Penyusutan
    'capitalized on'         => 'tgl_kapitalisasi',
    'asset description'      => 'keterangan_asset',
    'current apc'            => 'current_apc',
    'currbkval'              => 'nilai_buku',
    'accumul dep'            => 'akumulasi_dep_nilai',
    'dep for year'           => 'dep_tahun_berjalan',
    'write-ups'              => 'write_ups',
    'plant'                  => 'plant',
    'acquisition'            => 'acquisition',             // kolom R -> Penambahan
    'retirement'             => 'retirement',              // kolom S -> Pengurangan
    'transfer'                => 'transfers',              // kolom T -> Reklasifikasi
    'dep fy start'           => 'dep_fy_start',
    'depretir'               => 'dep_retir',
    'deptransfer'            => 'dep_transfer',
    'cabang'                 => 'cabang',
    'profit center'          => 'profit_center',
    'cost center'            => 'cost_center',            // kolom X -> 5 digit pertama = kode Profit Center (lookup di Excel)
];
// Urutan HARUS sama persis dengan $column_names di saveAr02ToDatabase()
$DB_COLUMNS_AR02_ORDERED = [
    'bal_sh_acct_APC', 'nomor_asset', 'sub_number', 'asset_class', 'gl_akumulasi_penyusutan', 'ckpn',
    'gl_beban_penyusutan', 'tgl_kapitalisasi', 'keterangan_asset', 'current_apc', 'nilai_buku',
    'akumulasi_dep_nilai', 'dep_tahun_berjalan', 'write_ups', 'plant', 'acquisition', 'retirement',
    'transfers', 'dep_fy_start', 'dep_retir', 'dep_transfer', 'cabang', 'profit_center', 'cost_center',
];
// Kolom yang WAJIB ketemu di header file, kalau tidak ada langsung tolak
$WAJIB_ADA_AR02 = ['asset', 'sub-number', 'balshacct apc', 'acquisition', 'retirement', 'transfer'];

// Variabel untuk card "Import Master COA"
$importedDataCoa = [];
$pesanCoa = "";
$tipeCoa = "";
$savedCountCoa = 0;

// Card "Import Master COA" -- data referensi akun GL per Asset Class (Account Determination),
// dipakai buat lookup mengisi kolom Akumulasi Penyusutan/CKPN/Beban Penyusutan di AR02 reg3.
// Ini tabel MASTER (bukan per periode) -- upload ulang akan meng-update baris yang sudah ada
// berdasarkan Account Determination, dan menambah baris baru kalau belum ada (upsert), tanpa
// menghapus baris lama yang tidak ikut ke-upload.
$HEADER_TO_FIELD_COA = [
    'account determination'                  => 'account_determination',
    'accdep accntfor ordinary depreciation'  => 'akun_akumulasi_penyusutan',
    'ckpn'                                    => 'ckpn',
    'expense account for ordinary depreciat' => 'akun_beban_penyusutan',
];
// Urutan HARUS sama persis dengan $column_names di saveMasterCoaToDatabase()
$DB_COLUMNS_COA_ORDERED = [
    'account_determination', 'akun_akumulasi_penyusutan', 'ckpn', 'akun_beban_penyusutan',
];
$WAJIB_ADA_COA = ['account determination'];

// Variabel untuk card "Import KK Aset"
$importedDataKka = [];
$pesanKka = "";
$tipeKka = "";
$savedCountKka = 0;

// Card "Import KK Aset" -- data referensi akun GL per Asset Class (Account Determination),
// dipakai buat lookup mengisi kolom Akumulasi Penyusutan/CKPN/Beban Penyusutan di AR02 reg3.
// Ini tabel MASTER (bukan per periode) -- upload ulang akan meng-update baris yang sudah ada
// berdasarkan Account Determination, dan menambah baris baru kalau belum ada (upsert), tanpa
// menghapus baris lama yang tidak ikut ke-upload.
$HEADER_TO_FIELD_KKA = [
    'asset class'                     => 'asset_class',
    'gl aset'                         => 'gl_aset',                // key (kolom D di sheet KK Aset)
    'gl akumulasi'                    => 'gl_akumulasi',           // kolom E
    'gl ckpn'                         => 'gl_ckpn',                // kolom F
    'gl beban penyusutan'             => 'gl_beban_penyusutan',    // kolom G
    'deskripsi gl beban penyusutan'   => 'deskripsi_gl_beban',     // kolom H
];
// Urutan HARUS sama persis dengan $column_names di saveMasterKkAsetToDatabase()
$DB_COLUMNS_KKA_ORDERED = [
    'asset_class', 'gl_aset', 'gl_akumulasi', 'gl_ckpn', 'gl_beban_penyusutan', 'deskripsi_gl_beban',
];
$WAJIB_ADA_KKA = []; // tidak ada header wajib: header dicari otomatis di baris mana pun, kalau gak ada dibaca per posisi kolom


$importedDataF01 = [];
$pesanF01 = "";
$tipeF01 = "";
$savedCountF01 = 0;

// Card "Import Data F.01" -- rekap saldo akun B/S P&L per Account Number dari laporan SAP F.01,
// dipakai buat cross-check saldo akhir per akun di dashboard Aset Tetap (key pencocokan
// kolom "Account Number"). Sama seperti AR02 reg3, file ini tidak punya kolom periode
// sendiri, jadi periode diambil otomatis dari tanggal server saat upload.
$HEADER_TO_FIELD_F01 = [
    'financial statement item'       => 'financial_statement_item',
    'account number'                 => 'account_number',       // key pencocokan ke sheet Aset Tetap
    'text for b/s p&l item'          => 'text_bs_pl_item',
    'total of reporting period'      => 'total_reporting_period',
    'total of the comparison period' => 'total_comparison_period',
    'absolute difference'            => 'absolute_difference',
    'percentage difference'          => 'percentage_difference',
];
// Urutan HARUS sama persis dengan $column_names di saveF01ToDatabase()
$DB_COLUMNS_F01_ORDERED = [
    'account_number', 'financial_statement_item', 'text_bs_pl_item',
    'total_reporting_period', 'total_comparison_period', 'absolute_difference', 'percentage_difference',
];
// Kolom yang WAJIB ketemu di header file, kalau tidak ada langsung tolak
$WAJIB_ADA_F01 = ['account number', 'total of reporting period'];

// ==========================================================
// Handle upload file untuk card "Import DAT"
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['file_excel'])) {
    $file = $_FILES['file_excel'];
    
    // Validasi file
    $allowed_ext = ['xls', 'xlsx', 'csv'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $file_size = $file['size'];
    
    // Cek ekstensi file
    if (!in_array($file_ext, $allowed_ext)) {
        $pesan = "Format file tidak didukung. Gunakan file Excel (.xls atau .xlsx)";
        $tipe_pesan = "danger";
    }
    // Cek ukuran file (max 50MB)
    else if ($file_size > 50 * 1024 * 1024) {
        $pesan = "Ukuran file terlalu besar. Maksimal 50MB";
        $tipe_pesan = "danger";
    }
    // Cek error upload
    else if ($file['error'] !== UPLOAD_ERR_OK) {
        $pesan = "Terjadi kesalahan saat upload file";
        $tipe_pesan = "danger";
    }
    else {
        // Proses file Excel -- ini "Import DAT" versi Monitoring, simpan ke tabel TERPISAH
        // import_dat_monitoring (bukan import_dat_penyusutan yang dipakai dashboard Penyusutan)
        try {
            $importedData = readDatMonitoringFileByHeader($file['tmp_name'], $file_ext);
            
            if (empty($importedData)) {
                $pesan = "File tidak memiliki data untuk diimport";
                $tipe_pesan = "warning";
            } else {
                // Langsung simpan ke database tanpa preview
                try {
                    $hasilSimpanMonitoring = saveDatMonitoringToDatabase($con, $importedData);
                    $saved_count   = $hasilSimpanMonitoring['saved_count'];
                    $skipped_count = $hasilSimpanMonitoring['skipped_blank'];
                    $failed_count  = $hasilSimpanMonitoring['failed_count'];

                    if ($saved_count > 0) {
                        $pesan = "✅ Berhasil mengimport dan menyimpan " . $saved_count . " baris data ke database";
                        // Tampilkan juga rincian baris yang TIDAK ikut tersimpan, biar kalau jumlahnya
                        // beda jauh dari total baris di file, penyebabnya langsung kelihatan (bukan
                        // harus nebak / cek error_log server).
                        $rincianGagal = [];
                        if ($skipped_count > 0) { $rincianGagal[] = "$skipped_count baris kosong (Nomor Asset kosong) dilewati"; }
                        if ($failed_count > 0)  { $rincianGagal[] = "$failed_count baris gagal (duplikat Asset+Sub-number+Bulan+Tahun Buku yang sama)"; }
                        if (!empty($rincianGagal)) {
                            $pesan .= ". " . implode(', ', $rincianGagal) . ".";
                        }
                        $tipe_pesan = "success";
                        // Clear imported data
                        $importedData = [];
                        if (isset($_SESSION['importedData'])) {
                            unset($_SESSION['importedData']);
                        }
                    } else {
                        $pesan = "File berhasil dibaca namun tidak ada data yang tersimpan ($skipped_count baris kosong, $failed_count baris duplikat/gagal)";
                        $tipe_pesan = "warning";
                        $importedData = [];
                    }
                } catch (Exception $e) {
                    $pesan = "Gagal menyimpan data ke database: " . $e->getMessage();
                    $tipe_pesan = "danger";
                    $importedData = [];
                }
            }
        } catch (Exception $e) {
            $error_msg = $e->getMessage();
            // Provide helpful suggestions based on error
            if (strpos($error_msg, 'ZipArchive') !== false || strpos($error_msg, 'XLSX') !== false) {
                $pesan = "Format XLSX tidak didukung di server ini. Silakan gunakan format CSV atau XLS.";
            } else {
                $pesan = "Gagal membaca file: " . $error_msg;
            }
            $tipe_pesan = "danger";
        }
    }
}

// ==========================================================
// Handle upload file untuk card "Import Data Penyusutan"
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['file_penyusutan'])) {
    $file = $_FILES['file_penyusutan'];
    
    // Validasi file
    $allowed_ext = ['xls', 'xlsx', 'csv'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $file_size = $file['size'];
    
    // Cek ekstensi file
    if (!in_array($file_ext, $allowed_ext)) {
        $pesanPenyusutan = "Format file tidak didukung. Gunakan file Excel (.xls atau .xlsx)";
        $tipePenyusutan = "danger";
    }
    // Cek ukuran file (max 50MB)
    else if ($file_size > 50 * 1024 * 1024) {
        $pesanPenyusutan = "Ukuran file terlalu besar. Maksimal 50MB";
        $tipePenyusutan = "danger";
    }
    // Cek error upload
    else if ($file['error'] !== UPLOAD_ERR_OK) {
        $pesanPenyusutan = "Terjadi kesalahan saat upload file";
        $tipePenyusutan = "danger";
    }
    else {
        // Proses file Excel/CSV Data Penyusutan (8 kolom)
        try {
            $importedDataPenyusutan = readPenyusutanFileByHeader($file['tmp_name'], $file_ext);
            
            if (empty($importedDataPenyusutan)) {
                $pesanPenyusutan = "File tidak memiliki data untuk diimport";
                $tipePenyusutan = "warning";
            } else {
                try {
                    $savedCountPenyusutan = saveDataPenyusutanToDatabase($con, $importedDataPenyusutan);
                    
                    if ($savedCountPenyusutan > 0) {
                        $pesanPenyusutan = "✅ Berhasil mengimport dan menyimpan " . $savedCountPenyusutan . " baris data penyusutan ke database";
                        $tipePenyusutan = "success";
                        $importedDataPenyusutan = [];
                    } else {
                        $pesanPenyusutan = "File berhasil dibaca namun tidak ada data yang tersimpan";
                        $tipePenyusutan = "warning";
                        $importedDataPenyusutan = [];
                    }
                } catch (Exception $e) {
                    $pesanPenyusutan = "Gagal menyimpan data ke database: " . $e->getMessage();
                    $tipePenyusutan = "danger";
                    $importedDataPenyusutan = [];
                }
            }
        } catch (Exception $e) {
            $error_msg = $e->getMessage();
            if (strpos($error_msg, 'ZipArchive') !== false || strpos($error_msg, 'XLSX') !== false) {
                $pesanPenyusutan = "Format XLSX tidak didukung di server ini. Silakan gunakan format CSV atau XLS.";
            } else {
                $pesanPenyusutan = "Gagal membaca file: " . $error_msg;
            }
            $tipePenyusutan = "danger";
        }
    }
}

// ==========================================================
// Handle upload file untuk card "Import AR02 reg3"
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['file_ar02'])) {
    $file = $_FILES['file_ar02'];
    // Periode diisi manual di form (file AR02 reg3 tidak punya kolom periode sendiri, dan datanya
    // KUMULATIF per bulan -- jadi bulan harus sesuai isi file, bukan tanggal server saat upload).
    $periodeBulanAr02 = isset($_POST['periode_bulan_ar02']) ? (string)(int)$_POST['periode_bulan_ar02'] : date('n');
    $periodeTahunAr02 = isset($_POST['periode_tahun_ar02']) ? (string)(int)$_POST['periode_tahun_ar02'] : date('Y');

    $allowed_ext = ['xls', 'xlsx', 'csv'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $file_size = $file['size'];

    if (!in_array($file_ext, $allowed_ext)) {
        $pesanAr02 = "Format file tidak didukung. Gunakan file Excel (.xls atau .xlsx) atau CSV";
        $tipeAr02 = "danger";
    }
    else if ($file_size > 50 * 1024 * 1024) {
        $pesanAr02 = "Ukuran file terlalu besar. Maksimal 50MB";
        $tipeAr02 = "danger";
    }
    else if ($file['error'] !== UPLOAD_ERR_OK) {
        $pesanAr02 = "Terjadi kesalahan saat upload file";
        $tipeAr02 = "danger";
    }
    else {
        try {
            $importedDataAr02 = readAr02FileByHeader($file['tmp_name'], $file_ext);

            if (empty($importedDataAr02)) {
                $pesanAr02 = "File tidak memiliki data untuk diimport";
                $tipeAr02 = "warning";
            } else {
                try {
                    $savedCountAr02 = saveAr02ToDatabase($con, $importedDataAr02, $periodeBulanAr02, $periodeTahunAr02);

                    if ($savedCountAr02 > 0) {
                        $pesanAr02 = "✅ Berhasil mengimport dan menyimpan " . $savedCountAr02 . " baris data AR02 reg3 (periode $periodeBulanAr02/$periodeTahunAr02) ke database";
                        $tipeAr02 = "success";
                        $importedDataAr02 = [];
                    } else {
                        $pesanAr02 = "File berhasil dibaca namun tidak ada data yang tersimpan (mungkin semua baris kosong)";
                        $tipeAr02 = "warning";
                        $importedDataAr02 = [];
                    }
                } catch (Exception $e) {
                    $pesanAr02 = "Gagal menyimpan data ke database: " . $e->getMessage();
                    $tipeAr02 = "danger";
                    $importedDataAr02 = [];
                }
            }
        } catch (Exception $e) {
            $error_msg = $e->getMessage();
            if (strpos($error_msg, 'ZipArchive') !== false || strpos($error_msg, 'XLSX') !== false) {
                $pesanAr02 = "Format XLSX tidak didukung di server ini. Silakan gunakan format CSV atau XLS.";
            } else {
                $pesanAr02 = "Gagal membaca file: " . $error_msg;
            }
            $tipeAr02 = "danger";
        }
    }
}

// ==========================================================
// Handle upload file untuk card "Import Master COA"
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['file_coa'])) {
    $file = $_FILES['file_coa'];

    $allowed_ext = ['xls', 'xlsx', 'csv'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $file_size = $file['size'];

    if (!in_array($file_ext, $allowed_ext)) {
        $pesanCoa = "Format file tidak didukung. Gunakan file Excel (.xls atau .xlsx) atau CSV";
        $tipeCoa = "danger";
    }
    else if ($file_size > 50 * 1024 * 1024) {
        $pesanCoa = "Ukuran file terlalu besar. Maksimal 50MB";
        $tipeCoa = "danger";
    }
    else if ($file['error'] !== UPLOAD_ERR_OK) {
        $pesanCoa = "Terjadi kesalahan saat upload file";
        $tipeCoa = "danger";
    }
    else {
        try {
            $importedDataCoa = readCoaFileByHeader($file['tmp_name'], $file_ext);

            if (empty($importedDataCoa)) {
                $pesanCoa = "File tidak memiliki data untuk diimport";
                $tipeCoa = "warning";
            } else {
                try {
                    $savedCountCoa = saveMasterCoaToDatabase($con, $importedDataCoa);

                    if ($savedCountCoa > 0) {
                        $pesanCoa = "✅ Berhasil mengimport dan menyimpan " . $savedCountCoa . " baris data Master COA ke database";
                        $tipeCoa = "success";
                        $importedDataCoa = [];
                    } else {
                        $pesanCoa = "File berhasil dibaca namun tidak ada data yang tersimpan (mungkin semua baris kosong)";
                        $tipeCoa = "warning";
                        $importedDataCoa = [];
                    }
                } catch (Exception $e) {
                    $pesanCoa = "Gagal menyimpan data ke database: " . $e->getMessage();
                    $tipeCoa = "danger";
                    $importedDataCoa = [];
                }
            }
        } catch (Exception $e) {
            $error_msg = $e->getMessage();
            if (strpos($error_msg, 'ZipArchive') !== false || strpos($error_msg, 'XLSX') !== false) {
                $pesanCoa = "Format XLSX tidak didukung di server ini. Silakan gunakan format CSV atau XLS.";
            } else {
                $pesanCoa = "Gagal membaca file: " . $error_msg;
            }
            $tipeCoa = "danger";
        }
    }
}

// ==========================================================
// Handle upload file untuk card "Import KK Aset"
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['file_kka'])) {
    $file = $_FILES['file_kka'];

    $allowed_ext = ['xls', 'xlsx', 'csv'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $file_size = $file['size'];

    if (!in_array($file_ext, $allowed_ext)) {
        $pesanKka = "Format file tidak didukung. Gunakan file Excel (.xls atau .xlsx) atau CSV";
        $tipeKka = "danger";
    }
    else if ($file_size > 50 * 1024 * 1024) {
        $pesanKka = "Ukuran file terlalu besar. Maksimal 50MB";
        $tipeKka = "danger";
    }
    else if ($file['error'] !== UPLOAD_ERR_OK) {
        $pesanKka = "Terjadi kesalahan saat upload file";
        $tipeKka = "danger";
    }
    else {
        try {
            $importedDataKka = readKkaFileByHeader($file['tmp_name'], $file_ext);

            if (empty($importedDataKka)) {
                $pesanKka = "File tidak memiliki data untuk diimport";
                $tipeKka = "warning";
            } else {
                try {
                    $savedCountKka = saveMasterKkAsetToDatabase($con, $importedDataKka);

                    if ($savedCountKka > 0) {
                        $pesanKka = "✅ Berhasil mengimport dan menyimpan " . $savedCountKka . " baris data KK Aset ke database";
                        $tipeKka = "success";
                        $importedDataKka = [];
                    } else {
                        $pesanKka = "File berhasil dibaca namun tidak ada data yang tersimpan (mungkin semua baris kosong)";
                        $tipeKka = "warning";
                        $importedDataKka = [];
                    }
                } catch (Exception $e) {
                    $pesanKka = "Gagal menyimpan data ke database: " . $e->getMessage();
                    $tipeKka = "danger";
                    $importedDataKka = [];
                }
            }
        } catch (Exception $e) {
            $error_msg = $e->getMessage();
            if (strpos($error_msg, 'ZipArchive') !== false || strpos($error_msg, 'XLSX') !== false) {
                $pesanKka = "Format XLSX tidak didukung di server ini. Silakan gunakan format CSV atau XLS.";
            } else {
                $pesanKka = "Gagal membaca file: " . $error_msg;
            }
            $tipeKka = "danger";
        }
    }
}

// ==========================================================
// Handle upload untuk card "Import Nilai Perolehan / Nilai Akumulasi / Nilai DEP"
// ==========================================================
foreach ($NILAI_ASET_JENIS as $nKey => $nMeta) {
    $nField = 'file_' . $nKey;
    if ($_SERVER['REQUEST_METHOD'] != 'POST' || !isset($_FILES[$nField])) { continue; }
    $file = $_FILES[$nField];
    $nBulan = (string)max(1, min(12, (int)($_POST['periode_bulan_' . $nKey] ?? date('n'))));
    $nTahun = (string)(int)($_POST['periode_tahun_' . $nKey] ?? date('Y'));
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_ext, ['xls', 'xlsx', 'csv'])) {
        $pesanNilai[$nKey] = "Format file tidak didukung. Gunakan file Excel (.xls atau .xlsx) atau CSV";
        $tipeNilai[$nKey] = "danger";
    } elseif ($file['size'] > 50 * 1024 * 1024) {
        $pesanNilai[$nKey] = "Ukuran file terlalu besar. Maksimal 50MB";
        $tipeNilai[$nKey] = "danger";
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $pesanNilai[$nKey] = "Terjadi kesalahan saat upload file";
        $tipeNilai[$nKey] = "danger";
    } else {
        try {
            $nRows = readPenyusutanFileByHeader($file['tmp_name'], $file_ext);
            if (empty($nRows)) {
                $pesanNilai[$nKey] = "File tidak memiliki data untuk diimport";
                $tipeNilai[$nKey] = "warning";
            } else {
                try {
                    $nSaved = saveNilaiAsetToDatabase($con, $nMeta['tabel'], $nRows, $nBulan, $nTahun);
                    if ($nSaved > 0) {
                        $pesanNilai[$nKey] = "✅ Berhasil mengimport dan menyimpan " . $nSaved . " baris data " . $nMeta['judul'] . " (periode " . $nBulan . "/" . $nTahun . ") ke database";
                        $tipeNilai[$nKey] = "success";
                    } else {
                        $pesanNilai[$nKey] = "File berhasil dibaca namun tidak ada data yang tersimpan";
                        $tipeNilai[$nKey] = "warning";
                    }
                } catch (Exception $e) {
                    $pesanNilai[$nKey] = "Gagal menyimpan data ke database: " . $e->getMessage();
                    $tipeNilai[$nKey] = "danger";
                }
            }
        } catch (Exception $e) {
            $err = $e->getMessage();
            $pesanNilai[$nKey] = (strpos($err, 'ZipArchive') !== false || strpos($err, 'XLSX') !== false)
                ? "Format XLSX tidak didukung di server ini. Silakan gunakan format CSV atau XLS."
                : "Gagal membaca file: " . $err;
            $tipeNilai[$nKey] = "danger";
        }
    }
}

// ==========================================================
// Handle upload file untuk card "Import Data F.01"
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['file_f01'])) {
    $file = $_FILES['file_f01'];
    // Periode dipilih di form (file F.01 tidak punya kolom periode sendiri). Kalau tidak dikirim, pakai tanggal server.
    // Contoh: untuk kolom "31 Desember 2025" di Mutasi Aset Tetap, upload F.01 dengan Bulan = Desember, Tahun = 2025.
    $periodeBulanF01 = isset($_POST['periode_bulan_f01']) ? (string)max(1, min(12, (int)$_POST['periode_bulan_f01'])) : date('n');
    $periodeTahunF01 = isset($_POST['periode_tahun_f01']) ? (string)(int)$_POST['periode_tahun_f01'] : date('Y');

    $allowed_ext = ['xls', 'xlsx', 'csv'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $file_size = $file['size'];

    if (!in_array($file_ext, $allowed_ext)) {
        $pesanF01 = "Format file tidak didukung. Gunakan file Excel (.xls atau .xlsx) atau CSV";
        $tipeF01 = "danger";
    }
    else if ($file_size > 50 * 1024 * 1024) {
        $pesanF01 = "Ukuran file terlalu besar. Maksimal 50MB";
        $tipeF01 = "danger";
    }
    else if ($file['error'] !== UPLOAD_ERR_OK) {
        $pesanF01 = "Terjadi kesalahan saat upload file";
        $tipeF01 = "danger";
    }
    else {
        try {
            $importedDataF01 = readF01FileByHeader($file['tmp_name'], $file_ext);

            if (empty($importedDataF01)) {
                $pesanF01 = "File tidak memiliki data untuk diimport";
                $tipeF01 = "warning";
            } else {
                try {
                    $savedCountF01 = saveF01ToDatabase($con, $importedDataF01, $periodeBulanF01, $periodeTahunF01);

                    if ($savedCountF01 > 0) {
                        $pesanF01 = "✅ Berhasil mengimport dan menyimpan " . $savedCountF01 . " baris data F.01 (periode $periodeBulanF01/$periodeTahunF01) ke database";
                        $tipeF01 = "success";
                        $importedDataF01 = [];
                    } else {
                        $pesanF01 = "File berhasil dibaca namun tidak ada data yang tersimpan (mungkin semua baris kosong)";
                        $tipeF01 = "warning";
                        $importedDataF01 = [];
                    }
                } catch (Exception $e) {
                    $pesanF01 = "Gagal menyimpan data ke database: " . $e->getMessage();
                    $tipeF01 = "danger";
                    $importedDataF01 = [];
                }
            }
        } catch (Exception $e) {
            $error_msg = $e->getMessage();
            if (strpos($error_msg, 'ZipArchive') !== false || strpos($error_msg, 'XLSX') !== false) {
                $pesanF01 = "Format XLSX tidak didukung di server ini. Silakan gunakan format CSV atau XLS.";
            } else {
                $pesanF01 = "Gagal membaca file: " . $error_msg;
            }
            $tipeF01 = "danger";
        }
    }
}

function readExcelFile($filePath, $ext, $numCols = 47) {
    $rows = [];
    
    try {
        if ($ext === 'csv') {
            // Baca CSV dengan semicolon delimiter
            if (($handle = fopen($filePath, 'r')) !== false) {
                // Detect delimiter
                $first_line = fgets($handle);
                $delimiter = (strpos($first_line, "\t") !== false) ? "\t" : ((strpos($first_line, ';') !== false) ? ';' : ',');
                rewind($handle);
                
                // Skip header
                fgetcsv($handle, 0, $delimiter);
                
                while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                    // Pad ke $numCols kolom
                    while (count($row) < $numCols) {
                        $row[] = '';
                    }
                    $rows[] = array_slice($row, 0, $numCols);
                }
                fclose($handle);
            }
        } 
        else if ($ext === 'xlsx') {
            $rows = readXLSXFile($filePath, $numCols);
        }
        else if ($ext === 'xls') {
            $rows = readXLSFile($filePath, $numCols);
        }
    } catch (Exception $e) {
        throw new Exception($e->getMessage());
    }

    // ── Perbaikan notasi ilmiah (mis. "1.20401E+11") jadi angka desimal utuh (mis. "120401000000") ──
    // Berlaku untuk SEMUA jalur baca (XLSX langsung, fallback LibreOffice/Gnumeric, maupun CSV),
    // karena ketiganya bisa menghasilkan teks notasi ilmiah untuk angka besar/"bulat" (nomor asset,
    // kode akun, dst) -- tanpa perbaikan ini, banyak nomor beda ke-"gepeng" jadi teks yang sama.
    foreach ($rows as &$rowFix) {
        foreach ($rowFix as &$cellFix) {
            $cellFix = perbaiki_notasi_ilmiah_ps($cellFix);
        }
        unset($cellFix);
    }
    unset($rowFix);

    return $rows;
}

// Deteksi teks notasi ilmiah (mis. "1.20401E+11", "1.2E+11") lalu kembalikan ke bentuk desimal
// utuh TANPA kehilangan digit -- dipakai untuk kolom yang isinya nomor "id" seperti nomor asset,
// account, dst yang gak boleh ada bagian desimalnya. Nilai yang bukan notasi ilmiah dibiarkan
// apa adanya (tidak disentuh sama sekali), jadi aman dipakai ke semua kolom termasuk yang
// memang berisi angka desimal asli (mis. Amount).
function perbaiki_notasi_ilmiah_ps($value) {
    if (!is_string($value)) { return $value; }
    $trimmed = trim($value);
    if ($trimmed === '' || !preg_match('/^-?\d+(\.\d+)?[eE][+-]?\d+$/', $trimmed)) {
        return $value; // bukan notasi ilmiah, biarkan apa adanya
    }
    // sprintf %.0f membulatkan ke integer terdekat -- aman dipakai di sini karena kolom id/nomor
    // seperti ini memang gak punya pecahan; kalaupun aslinya "bulat" (misal .000000), hasilnya tetap
    // representasi desimal penuh yang persis sama nilainya.
    return sprintf('%.0f', (float)$trimmed);
}

function readXLSXFile($filePath, $numCols = 47) {
    $rows = [];

    if (!class_exists('ZipArchive')) {
        return convertXLSXtoCSVAndParse($filePath, $numCols);
    }
    
    $zip = new ZipArchive();
    
    if ($zip->open($filePath) !== true) {
        return convertXLSXtoCSVAndParse($filePath, $numCols);
    }
    
    try {
        $sharedStrings = [];
        if (($xmlContent = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $xml = simplexml_load_string($xmlContent);
            foreach ($xml->si as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string)$si->t;
                } else if (isset($si->r)) {
                    foreach ($si->r as $r) {
                        if (isset($r->t)) {
                            $text .= (string)$r->t;
                        }
                    }
                }
                $sharedStrings[] = $text;
            }
        }

        $xmlContent = $zip->getFromName('xl/workbook.xml');
        $xml = simplexml_load_string($xmlContent);

        $sheetId = null;
        foreach ($xml->sheets->sheet as $sheet) {
            $sheetId = (string)$sheet->attributes('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            break;
        }
        
        if (!$sheetId) {
            throw new Exception("Tidak ada worksheet ditemukan");
        }
        
        $relsContent = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $relsXml = simplexml_load_string($relsContent);
        
        $worksheetPath = '';
        foreach ($relsXml->Relationship as $rel) {
            if ((string)$rel->attributes()['Id'] === $sheetId) {
                $worksheetPath = 'xl/' . (string)$rel->attributes()['Target'];
                break;
            }
        }
        
        if (empty($worksheetPath) || !$zip->locateName($worksheetPath)) {
            throw new Exception("File worksheet tidak ditemukan");
        }
        
        $xmlContent = $zip->getFromName($worksheetPath);
        $xml = simplexml_load_string($xmlContent);
        
        $firstRow = true;
        foreach ($xml->sheetData->row as $row) {

            if ($firstRow) {
                $firstRow = false;
                continue;
            }
            
            $cellData = [];
            foreach ($row->c as $cell) {
                $value = '';
                $type = (string)$cell->attributes()['t'] ?? 'n';
                
                if (isset($cell->v)) {
                    $value = (string)$cell->v;

                    if ($type === 's') {
                        $value = $sharedStrings[(int)$value] ?? '';
                    }
                }
                
                $cellData[] = $value;
            }
            
            if (!empty($cellData)) {
                while (count($cellData) < $numCols) {
                    $cellData[] = '';
                }
                $rows[] = array_slice($cellData, 0, $numCols);
            }
        }
        
        $zip->close();
        return $rows;
    } catch (Exception $e) {
        $zip->close();
        return convertXLSXtoCSVAndParse($filePath, $numCols);
    }
}

function convertXLSXtoCSVAndParse($filePath, $numCols = 47) {
    $temp_csv = tempnam(sys_get_temp_dir(), 'xlsx_') . '.csv';
    $rows = [];
    
    $command = "libreoffice --headless --convert-to csv:Text --outdir " . 
               escapeshellarg(dirname($temp_csv)) . " " . escapeshellarg($filePath) . " 2>/dev/null";
    @shell_exec($command);
    
    $base_name = pathinfo($filePath, PATHINFO_FILENAME);
    $expected_csv = dirname($temp_csv) . '/' . $base_name . '.csv';
    
    if (!file_exists($expected_csv) && !file_exists($temp_csv)) {

        $command = "ssconvert " . escapeshellarg($filePath) . " " . escapeshellarg($temp_csv) . " 2>/dev/null";
        @shell_exec($command);
    } else if (file_exists($expected_csv)) {
        $temp_csv = $expected_csv;
    }

    if (file_exists($temp_csv)) {
        if (($handle = fopen($temp_csv, 'r')) !== false) {

            $first_line = fgets($handle);
            $delimiter = (strpos($first_line, "\t") !== false) ? "\t" : ((strpos($first_line, ';') !== false) ? ';' : ',');
            rewind($handle);
            
            fgetcsv($handle, 0, $delimiter);
            
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                while (count($row) < $numCols) {
                    $row[] = '';
                }
                $rows[] = array_slice($row, 0, $numCols);
            }
            fclose($handle);
        }
        @unlink($temp_csv);
        
        if (!empty($rows)) {
            return $rows;
        }
    }
    
    throw new Exception("Tidak dapat membaca file XLSX. Pastikan LibreOffice atau Gnumeric terinstall, atau gunakan format CSV/XLS");
}

function readXLSFile($filePath, $numCols = 47) {
    $rows = [];

    $temp_csv = tempnam(sys_get_temp_dir(), 'xls_') . '.csv';
    
    $command = "ssconvert " . escapeshellarg($filePath) . " " . escapeshellarg($temp_csv) . " 2>/dev/null";
    @shell_exec($command);
    
    if (file_exists($temp_csv)) {
        if (($handle = fopen($temp_csv, 'r')) !== false) {
            fgetcsv($handle, NULL, ';');

            while (($row = fgetcsv($handle, NULL, ';')) !== false) {
                while (count($row) < $numCols) {
                    $row[] = '';
                }
                $rows[] = array_slice($row, 0, $numCols);
            }
            fclose($handle);
        }
        @unlink($temp_csv);
        
        if (!empty($rows)) {
            return $rows;
        }
    }
    
    if (empty($rows)) {
        throw new Exception("Tidak dapat membaca file XLS. Pastikan file valid atau gunakan format XLSX/CSV");
    }
    
    return $rows;
}

function normalize_header_ps($h) {
    $h = strtolower(trim((string)$h));
    $h = preg_replace('/\s+/', ' ', $h);
    $h = rtrim($h, '.'); 
    return $h;
}

function build_header_map_ps($headerRow) {
    $map = [];
    foreach ($headerRow as $idx => $h) {
        $norm = normalize_header_ps($h);
        if ($norm !== '' && !isset($map[$norm])) {
            $map[$norm] = $idx;
        }
    }
    return $map;
}

/**
 * Konversi Excel serial date number (mis. 46142) ke string tanggal 'm/d/Y'
 * (mis. '04/30/2026'). Kalau nilainya bukan angka murni (sudah teks tanggal
 * biasa, misal dari CSV), dibiarkan apa adanya.
 */
function excel_serial_ke_tanggal_ps($value) {
    $value = trim((string)$value);
    if ($value === '' || !is_numeric($value)) {
        return $value;
    }
    $serial = (float)$value;
    if ($serial < 3653 || $serial > 100000) {
        return $value;
    }
    $unixTimestamp = ($serial - 25569) * 86400;
    return gmdate('m/d/Y', (int)round($unixTimestamp));
}

function extract_row_by_header_ps($dataRow, $indexMap) {
    global $TARGET_HEADERS_PENYUSUTAN;
    $out = [];
    foreach ($TARGET_HEADERS_PENYUSUTAN as $key) {
        $idx = $indexMap[$key] ?? null;
        $val = ($idx !== null && isset($dataRow[$idx])) ? $dataRow[$idx] : '';
        if ($key === 'posting date') {
            $val = excel_serial_ke_tanggal_ps($val);
        }
        $out[] = $val;
    }
    return $out;
}

/**
 * Isi otomatis kolom identitas (Cost Center, Asset, Sub-number, Account, Profit Center) yang kosong,
 * memakai nilai terakhir yang valid dari baris sebelumnya. Ini menangani kasus umum file SAP export
 * yang pakai MERGED CELL untuk kolom-kolom itu -- di file .xlsx, nilai merged cell cuma tersimpan
 * di baris pertamanya, baris-baris berikutnya memang kosong di data mentah.
 * TIDAK forward-fill Posting Date / Amount / Text karena itu memang unik per baris transaksi.
 * Reset $lastValues ke [] setiap kali mulai sheet/file baru.
 *
 * PENGECUALIAN: kalau Text baris ini menandakan entri otomatis PSAK 73 / Penyusutan KSP
 * (mis. "By Peny PSAK 73 ..." atau "By Penyusutan KSP ..."), maka Asset & Asset Subnumber
 * memang tidak punya nomor aset asli -- JANGAN disalin dari baris sebelumnya, isi "-" saja.
 */
function forward_fill_row_ps(array $row, array &$lastValues) {
    // Index sesuai urutan $TARGET_HEADERS_PENYUSUTAN: 0=cost_center,1=asset,2=asset_subnumber,3=account,4=posting_date,5=amount,6=profit_center,7=text
    $idxIdentitas = [0, 1, 2, 3, 6];

    $text = trim((string)($row[7] ?? ''));
    $isAutoPsakKsp = (bool) preg_match('/by\s+peny(?:usutan)?\s*psak\s*73/i', $text)
        || (bool) preg_match('/by\s+penyusutan\s*ksp/i', $text);

    foreach ($idxIdentitas as $i) {
        // Asset (1) & Asset Subnumber (2) untuk baris auto PSAK 73/KSP: isi "-", jangan forward-fill
        if ($isAutoPsakKsp && ($i === 1 || $i === 2)) {
            $row[$i] = '-';
            continue;
        }

        $val = trim((string)($row[$i] ?? ''));
        if ($val === '') {
            $row[$i] = $lastValues[$i] ?? '';
        } else {
            $lastValues[$i] = $val;
        }
    }
    return $row;
}

function cek_kolom_wajib_ditemukan($indexMap) {
    global $WAJIB_HEADERS_PENYUSUTAN;
    $hilang = [];
    foreach ($WAJIB_HEADERS_PENYUSUTAN as $key) {
        if (!isset($indexMap[$key])) $hilang[] = $key;
    }
    return $hilang;
}

function excel_col_to_index_ps($cellRef) {
    $colStr = preg_replace('/[0-9]/', '', (string)$cellRef);
    $idx = 0;
    for ($i = 0; $i < strlen($colStr); $i++) {
        $idx = $idx * 26 + (ord(strtoupper($colStr[$i])) - ord('A') + 1);
    }
    return $idx - 1;
}

function readPenyusutanFileByHeader($filePath, $ext) {
    if ($ext === 'csv') {
        return readPenyusutanCSVByHeader($filePath);
    } elseif ($ext === 'xlsx') {
        return readPenyusutanXLSXByHeader($filePath);
    } elseif ($ext === 'xls') {
        return readPenyusutanXLSByHeader($filePath);
    }
    return [];
}

function readPenyusutanCSVByHeader($filePath) {
    $rows = [];
    if (($handle = fopen($filePath, 'r')) !== false) {
        $first_line = fgets($handle);
        $delimiter = (strpos($first_line, "\t") !== false) ? "\t" : ((strpos($first_line, ';') !== false) ? ';' : ',');
        rewind($handle);

        $headerRow = fgetcsv($handle, 0, $delimiter);
        if ($headerRow === false) { fclose($handle); return []; }

        $map = build_header_map_ps($headerRow);
        $hilang = cek_kolom_wajib_ditemukan($map);
        if (!empty($hilang)) {
            fclose($handle);
            throw new Exception("Kolom berikut tidak ditemukan di header file: " . implode(', ', $hilang) . ". Pastikan baris pertama file berisi nama kolom yang sesuai.");
        }

        $lastValuesFF = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = forward_fill_row_ps(extract_row_by_header_ps($row, $map), $lastValuesFF);
        }
        fclose($handle);
    }
    return $rows;
}

function readPenyusutanXLSXByHeader($filePath) {
    $rows = [];
    if (!class_exists('ZipArchive')) return convertXLSXtoCSVAndParsePenyusutanByHeader($filePath);
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) return convertXLSXtoCSVAndParsePenyusutanByHeader($filePath);

    try {
        $sharedStrings = [];
        if (($xmlContent = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $xml = simplexml_load_string($xmlContent);
            foreach ($xml->si as $si) {
                $text = '';
                if (isset($si->t)) { $text = (string)$si->t; }
                elseif (isset($si->r)) { foreach ($si->r as $r) { if (isset($r->t)) $text .= (string)$r->t; } }
                $sharedStrings[] = $text;
            }
        }

        $xmlContent = $zip->getFromName('xl/workbook.xml');
        $xml = simplexml_load_string($xmlContent);

        $sheetIds = [];
        foreach ($xml->sheets->sheet as $sheet) {
            $sheetIds[] = (string)$sheet->attributes('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        }
        if (empty($sheetIds)) throw new Exception("Tidak ada worksheet ditemukan");

        $relsContent = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $relsXml = simplexml_load_string($relsContent);
        $relMap = [];
        foreach ($relsXml->Relationship as $rel) {
            $relMap[(string)$rel->attributes()['Id']] = 'xl/' . (string)$rel->attributes()['Target'];
        }

        $adaKolomWajibHilang = null;
        foreach ($sheetIds as $sheetId) {
            $worksheetPath = $relMap[$sheetId] ?? '';
            if (empty($worksheetPath) || !$zip->locateName($worksheetPath)) continue; 

            $sheetXmlContent = $zip->getFromName($worksheetPath);
            $sheetXml = simplexml_load_string($sheetXmlContent);
            if (!$sheetXml || !isset($sheetXml->sheetData)) continue;

            $map = null;
            $lastValuesFF = []; 
            foreach ($sheetXml->sheetData->row as $row) {
                $sparse = [];
                foreach ($row->c as $cell) {
                    $ref = (string)($cell->attributes()['r'] ?? '');
                    $colIdx = $ref !== '' ? excel_col_to_index_ps($ref) : count($sparse);
                    $value = ''; $type = (string)($cell->attributes()['t'] ?? 'n');
                    if (isset($cell->v)) {
                        $value = (string)$cell->v;
                        if ($type === 's') { $value = $sharedStrings[(int)$value] ?? ''; }
                    }
                    $sparse[$colIdx] = $value;
                }
                if (empty($sparse)) continue;
                $maxIdx = max(array_keys($sparse));
                $cellData = [];
                for ($i = 0; $i <= $maxIdx; $i++) { $cellData[] = $sparse[$i] ?? ''; }

                if ($map === null) {
                    $map = build_header_map_ps($cellData);
                    $hilang = cek_kolom_wajib_ditemukan($map);
                    if (!empty($hilang)) {
                        $adaKolomWajibHilang = $hilang;
                        $map = false;
                        continue;
                    }
                    continue;
                }
                if ($map === false) continue;

                $rows[] = forward_fill_row_ps(extract_row_by_header_ps($cellData, $map), $lastValuesFF);
            }
        }
        $zip->close();

        if (empty($rows) && $adaKolomWajibHilang !== null) {
            throw new Exception("Kolom berikut tidak ditemukan di header salah satu sheet: " . implode(', ', $adaKolomWajibHilang) . ". Pastikan baris pertama tiap sheet berisi nama kolom yang sesuai.");
        }
        return $rows;
    } catch (Exception $e) {
        $zip->close();
        if (strpos($e->getMessage(), 'Kolom berikut tidak ditemukan') !== false) {
            throw $e;
        }
        return convertXLSXtoCSVAndParsePenyusutanByHeader($filePath);
    }
}

function convertXLSXtoCSVAndParsePenyusutanByHeader($filePath) {
    $temp_csv = tempnam(sys_get_temp_dir(), 'xlsxps_') . '.csv';

    $command = "libreoffice --headless --convert-to csv:Text --outdir " .
               escapeshellarg(dirname($temp_csv)) . " " . escapeshellarg($filePath) . " 2>/dev/null";
    @shell_exec($command);

    $base_name = pathinfo($filePath, PATHINFO_FILENAME);
    $expected_csv = dirname($temp_csv) . '/' . $base_name . '.csv';

    if (!file_exists($expected_csv) && !file_exists($temp_csv)) {
        $command = "ssconvert " . escapeshellarg($filePath) . " " . escapeshellarg($temp_csv) . " 2>/dev/null";
        @shell_exec($command);
    } elseif (file_exists($expected_csv)) {
        $temp_csv = $expected_csv;
    }

    if (file_exists($temp_csv)) {
        $rows = readPenyusutanCSVByHeader($temp_csv);
        @unlink($temp_csv);
        if (!empty($rows)) return $rows;
    }

    throw new Exception("Tidak dapat membaca file XLSX. Pastikan LibreOffice/Gnumeric terinstall, atau gunakan format CSV/XLS.");
}

function readPenyusutanXLSByHeader($filePath) {
    $temp_csv = tempnam(sys_get_temp_dir(), 'xlsps_') . '.csv';
    $command = "ssconvert " . escapeshellarg($filePath) . " " . escapeshellarg($temp_csv) . " 2>/dev/null";
    @shell_exec($command);

    if (file_exists($temp_csv)) {
        $rows = readPenyusutanCSVByHeader($temp_csv);
        @unlink($temp_csv);
        if (!empty($rows)) return $rows;
    }

    throw new Exception("Tidak dapat membaca file XLS. Pastikan file valid atau gunakan format XLSX/CSV.");
}

function normalize_header_dat($h) {
    $h = strtolower(trim((string)$h));
    $h = str_replace('.', '', $h);
    $h = preg_replace('/\s+/', ' ', $h);
    return trim($h);
}

function build_header_map_dat($headerRow) {
    $map = [];
    foreach ($headerRow as $idx => $h) {
        $norm = normalize_header_dat($h);
        if ($norm !== '' && !isset($map[$norm])) {
            $map[$norm] = $idx;
        }
    }
    return $map;
}

function cek_kolom_wajib_dat($indexMap) {
    global $WAJIB_ADA_DAT;
    $hilang = [];
    foreach ($WAJIB_ADA_DAT as $h) {
        if (!isset($indexMap[$h])) $hilang[] = $h;
    }
    return $hilang;
}


function excel_serial_ke_tanggal_dat($value) {
    $value = trim((string)$value);
    if ($value === '' || !is_numeric($value)) {
        return $value;
    }

    $serial = (float)$value;
    if ($serial < 3653 || $serial > 100000) {
        return $value;
    }
    $unixTimestamp = ($serial - 25569) * 86400;
    return gmdate('Y-m-d', (int)round($unixTimestamp));
}

function extract_row_by_header_dat($dataRow, $indexMap) {
    global $HEADER_TO_FIELD_DAT, $DB_COLUMNS_DAT_ORDERED;
    static $fieldToHeader = null;
    if ($fieldToHeader === null) {
        $fieldToHeader = [];
        foreach ($HEADER_TO_FIELD_DAT as $normHeader => $field) { $fieldToHeader[$field] = $normHeader; }
    }

    $out = [];
    foreach ($DB_COLUMNS_DAT_ORDERED as $field) {
        $normHeader = $fieldToHeader[$field] ?? null;
        $idx = ($normHeader !== null) ? ($indexMap[$normHeader] ?? null) : null;
        $val = ($idx !== null && isset($dataRow[$idx])) ? $dataRow[$idx] : '';
        if ($field === 'tgl_perolehan') {
            $val = excel_serial_ke_tanggal_dat($val);
        }
        $out[] = $val;
    }
    return $out;
}

function readDatPenyusutanFileByHeader($filePath, $ext) {
    if ($ext === 'csv') {
        return readDatPenyusutanCSVByHeader($filePath);
    } elseif ($ext === 'xlsx') {
        return readDatPenyusutanXLSXByHeader($filePath);
    } elseif ($ext === 'xls') {
        return readDatPenyusutanXLSByHeader($filePath);
    }
    return [];
}

function readDatPenyusutanCSVByHeader($filePath) {
    $rows = [];
    if (($handle = fopen($filePath, 'r')) !== false) {
        $first_line = fgets($handle);
        $delimiter = (strpos($first_line, "\t") !== false) ? "\t" : ((strpos($first_line, ';') !== false) ? ';' : ',');
        rewind($handle);

        $headerRow = fgetcsv($handle, 0, $delimiter);
        if ($headerRow === false) { fclose($handle); return []; }

        $map = build_header_map_dat($headerRow);
        $hilang = cek_kolom_wajib_dat($map);
        if (!empty($hilang)) {
            fclose($handle);
            throw new Exception("Kolom berikut tidak ditemukan di header file DAT: " . implode(', ', $hilang) . ". Pastikan baris pertama file berisi nama kolom yang sesuai.");
        }

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = extract_row_by_header_dat($row, $map);
        }
        fclose($handle);
    }
    return $rows;
}

function readDatPenyusutanXLSXByHeader($filePath) {
    $rows = [];
    if (!class_exists('ZipArchive')) return convertXLSXtoCSVAndParseDatPenyusutanByHeader($filePath);
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) return convertXLSXtoCSVAndParseDatPenyusutanByHeader($filePath);

    try {
        $sharedStrings = [];
        if (($xmlContent = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $xml = simplexml_load_string($xmlContent);
            foreach ($xml->si as $si) {
                $text = '';
                if (isset($si->t)) { $text = (string)$si->t; }
                elseif (isset($si->r)) { foreach ($si->r as $r) { if (isset($r->t)) $text .= (string)$r->t; } }
                $sharedStrings[] = $text;
            }
        }

        $xmlContent = $zip->getFromName('xl/workbook.xml');
        $xml = simplexml_load_string($xmlContent);

        // Kumpulkan SEMUA sheet (bukan cuma sheet pertama), supaya sheet JAN, FEB, MAR, dst semuanya diproses
        $sheetIds = [];
        foreach ($xml->sheets->sheet as $sheet) {
            $sheetIds[] = (string)$sheet->attributes('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        }
        if (empty($sheetIds)) throw new Exception("Tidak ada worksheet ditemukan");

        $relsContent = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $relsXml = simplexml_load_string($relsContent);
        $relMap = [];
        foreach ($relsXml->Relationship as $rel) {
            $relMap[(string)$rel->attributes()['Id']] = 'xl/' . (string)$rel->attributes()['Target'];
        }

        $adaKolomWajibHilang = null;
        foreach ($sheetIds as $sheetId) {
            $worksheetPath = $relMap[$sheetId] ?? '';
            if (empty($worksheetPath) || !$zip->locateName($worksheetPath)) continue; // lewati sheet yang rusak/tidak ada

            $sheetXmlContent = $zip->getFromName($worksheetPath);
            $sheetXml = simplexml_load_string($sheetXmlContent);
            if (!$sheetXml || !isset($sheetXml->sheetData)) continue;

            // Header dicari ulang di SETIAP sheet (masing-masing tab JAN/FEB/dst punya baris header sendiri)
            $map = null;
            foreach ($sheetXml->sheetData->row as $row) {
                $sparse = [];
                foreach ($row->c as $cell) {
                    $ref = (string)($cell->attributes()['r'] ?? '');
                    $colIdx = $ref !== '' ? excel_col_to_index_ps($ref) : count($sparse);
                    $value = ''; $type = (string)($cell->attributes()['t'] ?? 'n');
                    if (isset($cell->v)) {
                        $value = (string)$cell->v;
                        if ($type === 's') { $value = $sharedStrings[(int)$value] ?? ''; }
                    }
                    $sparse[$colIdx] = $value;
                }
                if (empty($sparse)) continue;
                $maxIdx = max(array_keys($sparse));
                $cellData = [];
                for ($i = 0; $i <= $maxIdx; $i++) { $cellData[] = $sparse[$i] ?? ''; }

                if ($map === null) {
                    $map = build_header_map_dat($cellData);
                    $hilang = cek_kolom_wajib_dat($map);
                    if (!empty($hilang)) {
                        // Sheet ini tidak punya header yang sesuai -> lewati sheet ini,
                        // tapi tetap lanjut proses sheet lain supaya sheet lain tidak ikut gagal
                        $adaKolomWajibHilang = $hilang;
                        $map = false;
                        continue;
                    }
                    continue;
                }
                if ($map === false) continue;

                $rows[] = extract_row_by_header_dat($cellData, $map);
            }
        }
        $zip->close();

        if (empty($rows) && $adaKolomWajibHilang !== null) {
            throw new Exception("Kolom berikut tidak ditemukan di header salah satu sheet DAT: " . implode(', ', $adaKolomWajibHilang) . ". Pastikan baris pertama tiap sheet berisi nama kolom yang sesuai.");
        }
        return $rows;
    } catch (Exception $e) {
        $zip->close();
        if (strpos($e->getMessage(), 'Kolom berikut tidak ditemukan') !== false) {
            throw $e;
        }
        return convertXLSXtoCSVAndParseDatPenyusutanByHeader($filePath);
    }
}

function convertXLSXtoCSVAndParseDatPenyusutanByHeader($filePath) {
    $temp_csv = tempnam(sys_get_temp_dir(), 'xlsxdat_') . '.csv';

    $command = "libreoffice --headless --convert-to csv:Text --outdir " .
               escapeshellarg(dirname($temp_csv)) . " " . escapeshellarg($filePath) . " 2>/dev/null";
    @shell_exec($command);

    $base_name = pathinfo($filePath, PATHINFO_FILENAME);
    $expected_csv = dirname($temp_csv) . '/' . $base_name . '.csv';

    if (!file_exists($expected_csv) && !file_exists($temp_csv)) {
        $command = "ssconvert " . escapeshellarg($filePath) . " " . escapeshellarg($temp_csv) . " 2>/dev/null";
        @shell_exec($command);
    } elseif (file_exists($expected_csv)) {
        $temp_csv = $expected_csv;
    }

    if (file_exists($temp_csv)) {
        $rows = readDatPenyusutanCSVByHeader($temp_csv);
        @unlink($temp_csv);
        if (!empty($rows)) return $rows;
    }

    throw new Exception("Tidak dapat membaca file XLSX. Pastikan LibreOffice/Gnumeric terinstall, atau gunakan format CSV/XLS.");
}

function readDatPenyusutanXLSByHeader($filePath) {
    $temp_csv = tempnam(sys_get_temp_dir(), 'xlsdat_') . '.csv';
    $command = "ssconvert " . escapeshellarg($filePath) . " " . escapeshellarg($temp_csv) . " 2>/dev/null";
    @shell_exec($command);

    if (file_exists($temp_csv)) {
        $rows = readDatPenyusutanCSVByHeader($temp_csv);
        @unlink($temp_csv);
        if (!empty($rows)) return $rows;
    }

    throw new Exception("Tidak dapat membaca file XLS. Pastikan file valid atau gunakan format XLSX/CSV.");
}

function saveDatPenyusutanToDatabase($con, $importedData) {
    if (empty($importedData)) {
        return 0;
    }

    // (Catatan: blok migrasi darurat "DROP TABLE kalau kolom profit_center sudah ada" yang
    // sebelumnya di sini SUDAH DIHAPUS -- itu jalan terus di SETIAP upload dan menghapus TOTAL
    // seluruh histori data DAT, bukan cuma bulan yang sedang diupload. Migrasi kolom baru
    // sekarang cukup lewat CREATE TABLE IF NOT EXISTS + ALTER TABLE index di bawah, yang aman
    // dijalankan berkali-kali tanpa menghapus data.)

    $create_table_sql = "CREATE TABLE IF NOT EXISTS import_dat_penyusutan (
        id INT AUTO_INCREMENT PRIMARY KEY,
        profit_center VARCHAR(20),
        cabang VARCHAR(100),
        periode_bulan VARCHAR(20),
        tahun_buku VARCHAR(4),
        nomor_asset VARCHAR(50),
        sub_number VARCHAR(50),
        keterangan_asset TEXT,
        tgl_perolehan VARCHAR(20),
        sisa_manfaat_aset VARCHAR(20),
        gl_account_exp VARCHAR(25),
        sub_number_num INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        imported_by VARCHAR(20),
        UNIQUE KEY uk_asset_sub_periode (nomor_asset, sub_number, periode_bulan, tahun_buku),
        KEY idx_nomor_asset_sub_num (nomor_asset, sub_number_num)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!mysqli_query($con, $create_table_sql)) {
        throw new Exception("Gagal membuat tabel: " . mysqli_error($con));
    }

    $idxRes = mysqli_query($con, "SHOW INDEX FROM import_dat_penyusutan WHERE Key_name = 'uk_asset_sub'");
    if ($idxRes && mysqli_num_rows($idxRes) > 0) {
        mysqli_query($con, "ALTER TABLE import_dat_penyusutan DROP INDEX uk_asset_sub");
        mysqli_query($con, "ALTER TABLE import_dat_penyusutan ADD UNIQUE KEY uk_asset_sub_periode (nomor_asset, sub_number, periode_bulan, tahun_buku)");
    }

    // Migrasi AMAN kolom profit_center & cabang untuk tabel lama yang belum punya kolom ini
    // (tambah kolom kalau belum ada -- TIDAK PERNAH drop/hapus data)
    $existingColsDat = [];
    $resColsDat = mysqli_query($con, "SHOW COLUMNS FROM import_dat_penyusutan");
    if ($resColsDat) { while ($c = mysqli_fetch_assoc($resColsDat)) { $existingColsDat[] = $c['Field']; } }
    if (!in_array('profit_center', $existingColsDat, true)) {
        mysqli_query($con, "ALTER TABLE import_dat_penyusutan ADD COLUMN profit_center VARCHAR(20) AFTER id");
    }
    if (!in_array('cabang', $existingColsDat, true)) {
        mysqli_query($con, "ALTER TABLE import_dat_penyusutan ADD COLUMN cabang VARCHAR(100) AFTER profit_center");
    }
    // Migrasi AMAN: kolom sub_number_num (versi angka dari sub_number, dipakai biar JOIN ke
    // import_penyusutan bisa pakai index -- lihat komentar di dasbor_penyusutan.php).
    if (!in_array('sub_number_num', $existingColsDat, true)) {
        mysqli_query($con, "ALTER TABLE import_dat_penyusutan ADD COLUMN sub_number_num INT UNSIGNED NULL");
    }
    $existingIdxDat = [];
    $resIdxDat = mysqli_query($con, "SHOW INDEX FROM import_dat_penyusutan");
    if ($resIdxDat) { while ($ix = mysqli_fetch_assoc($resIdxDat)) { $existingIdxDat[] = $ix['Key_name']; } }
    if (!in_array('idx_nomor_asset_sub_num', $existingIdxDat, true)) {
        mysqli_query($con, "ALTER TABLE import_dat_penyusutan ADD INDEX idx_nomor_asset_sub_num (nomor_asset, sub_number_num)");
    }

    // Cari posisi kolom periode_bulan & tahun_buku secara dinamis dari $DB_COLUMNS_DAT_ORDERED,
    // bukan hardcode index 0/1 -- supaya tidak rusak lagi kalau urutan kolom berubah di kemudian hari
    // (ini yang jadi penyebab bug saat profit_center & cabang ditambahkan di depan).
    global $DB_COLUMNS_DAT_ORDERED;
    $idxPeriodeBulan = array_search('periode_bulan', $DB_COLUMNS_DAT_ORDERED);
    $idxTahunBuku = array_search('tahun_buku', $DB_COLUMNS_DAT_ORDERED);

    $periodeSet = [];
    foreach ($importedData as $rowCek) {
        $pb = trim((string)($rowCek[$idxPeriodeBulan] ?? ''));
        $tb = trim((string)($rowCek[$idxTahunBuku] ?? ''));
        if ($pb !== '' && $tb !== '') {
            $periodeSet[$pb . '|' . $tb] = [$pb, $tb];
        }
    }
    foreach ($periodeSet as $pasangPeriode) {
        [$pb, $tb] = $pasangPeriode;
        $pbEsc = mysqli_real_escape_string($con, $pb);
        $tbEsc = mysqli_real_escape_string($con, $tb);
        if (!mysqli_query($con, "DELETE FROM import_dat_penyusutan WHERE periode_bulan = '$pbEsc' AND tahun_buku = '$tbEsc'")) {
            throw new Exception("Gagal menghapus data lama periode $pb/$tb: " . mysqli_error($con));
        }
    }

    $nipp = isset($_SESSION['nipp']) ? $_SESSION['nipp'] : 'unknown';

    $column_names = [
        'profit_center',
        'cabang',
        'periode_bulan',
        'tahun_buku',
        'nomor_asset',
        'sub_number',
        'keterangan_asset',
        'gl_account_exp',
        'tgl_perolehan',
        'sisa_manfaat_aset'
    ];

    $saved_count = 0;
    $failed_rows = [];
    $skipped_blank = 0;
    $idxNomorAssetCol = array_search('nomor_asset', $column_names);
    $idxSubNumberCol = array_search('sub_number', $column_names);

    mysqli_begin_transaction($con);

    try {
        foreach ($importedData as $row_index => $row) {
            // Lewati baris kosong/blank (mis. baris kosong di akhir file Excel) -- kalau tidak,
            // baris-baris blank ini semua punya nomor_asset='' yang sama, jadi nabrak
            // UNIQUE KEY begitu ada baris blank kedua, dst.
            $nomorAssetVal = trim((string)($row[$idxNomorAssetCol] ?? ''));
            if ($nomorAssetVal === '') {
                $skipped_blank++;
                continue;
            }

            $values = [];
            foreach ($column_names as $col_idx => $col_name) {
                $value = isset($row[$col_idx]) ? $row[$col_idx] : '';
                // Trim nomor_asset & sub_number saat insert supaya JOIN ke import_penyusutan bisa
                // pakai perbandingan langsung (index-friendly) tanpa TRIM() di query.
                if ($col_name === 'nomor_asset' || $col_name === 'sub_number') {
                    $value = trim((string)$value);
                }
                $values[] = "'" . mysqli_real_escape_string($con, $value) . "'";
            }

            $subNumberNum = normalisasi_subnumber_num_ps($row[$idxSubNumberCol] ?? '');
            $values[] = $subNumberNum === null ? 'NULL' : $subNumberNum;

            $values[] = "'" . mysqli_real_escape_string($con, $nipp) . "'";
            
            $columns = implode(', ', $column_names) . ', sub_number_num, imported_by';
            $insert_sql = "INSERT INTO import_dat_penyusutan (" . $columns . ") VALUES (" . implode(', ', $values) . ")";
            
            try {
                if (mysqli_query($con, $insert_sql)) {
                    $saved_count++;
                } else {
                    $error = mysqli_error($con);
                    if (strpos($error, 'Duplicate entry') !== false) {
                        $failed_rows[] = "Baris " . ($row_index + 2) . ": Asset sudah ada di database untuk periode ini";
                    } else {
                        $failed_rows[] = "Baris " . ($row_index + 2) . ": " . $error;
                    }
                }
            } catch (\Throwable $eRow) {
                // Kalau mysqli di-set exception-mode (MYSQLI_REPORT_STRICT), error SQL (termasuk
                // Duplicate entry) dilempar sebagai exception, bukan return false -- ditangkap di
                // sini per-baris supaya SATU baris bermasalah tidak membatalkan seluruh import.
                $msgRow = $eRow->getMessage();
                if (strpos($msgRow, 'Duplicate entry') !== false) {
                    $failed_rows[] = "Baris " . ($row_index + 2) . ": Asset sudah ada di database untuk periode ini";
                } else {
                    $failed_rows[] = "Baris " . ($row_index + 2) . ": " . $msgRow;
                }
            }
        }
        
        mysqli_commit($con);
        
    } catch (Exception $e) {
        mysqli_rollback($con);
        throw new Exception("Gagal menyimpan data: " . $e->getMessage());
    }
    
    if (!empty($failed_rows)) {
        error_log("Import DAT Penyusutan failed rows: " . implode("; ", array_slice($failed_rows, 0, 5)));
    }
    if ($skipped_blank > 0) {
        error_log("Import DAT Penyusutan: $skipped_blank baris kosong dilewati.");
    }
    
    return $saved_count;
}

// ==========================================================
// Fungsi-fungsi untuk card "Import DAT" versi MONITORING
// (tabel SENDIRI: import_dat_monitoring -- TERPISAH dari import_dat_penyusutan)
// ==========================================================
function normalize_header_dat_monitoring($h) {
    return normalize_header_dat($h); // logika normalisasi sama persis, tinggal reuse
}

function build_header_map_dat_monitoring($headerRow) {
    $map = [];
    foreach ($headerRow as $idx => $h) {
        $norm = normalize_header_dat_monitoring($h);
        if ($norm !== '' && !isset($map[$norm])) {
            $map[$norm] = $idx;
        }
    }
    return $map;
}

function cek_kolom_wajib_dat_monitoring($indexMap) {
    global $WAJIB_ADA_DAT_MONITORING;
    $hilang = [];
    foreach ($WAJIB_ADA_DAT_MONITORING as $h) {
        if (!isset($indexMap[$h])) $hilang[] = $h;
    }
    return $hilang;
}

function extract_row_by_header_dat_monitoring($dataRow, $indexMap) {
    global $HEADER_TO_FIELD_DAT_MONITORING, $DB_COLUMNS_DAT_MONITORING_ORDERED;
    static $fieldToHeader = null;
    if ($fieldToHeader === null) {
        $fieldToHeader = [];
        foreach ($HEADER_TO_FIELD_DAT_MONITORING as $normHeader => $field) { $fieldToHeader[$field] = $normHeader; }
    }

    $out = [];
    foreach ($DB_COLUMNS_DAT_MONITORING_ORDERED as $field) {
        $normHeader = $fieldToHeader[$field] ?? null;
        $idx = ($normHeader !== null) ? ($indexMap[$normHeader] ?? null) : null;
        if ($field === 'gl_account_aset' && $idx === null) {
            global $ALT_HEADER_GL_ASET_DAT_MON;
            foreach ($ALT_HEADER_GL_ASET_DAT_MON as $altH) { if (isset($indexMap[$altH])) { $idx = $indexMap[$altH]; break; } }
        }
        $val = ($idx !== null && isset($dataRow[$idx])) ? $dataRow[$idx] : '';
        if ($field === 'gl_account_aset') { $val = trim((string)$val); }
        if ($field === 'tgl_perolehan' || $field === 'tgl_mulai_penyusutan') {
            $val = excel_serial_ke_tanggal_dat($val);
        }
        $out[] = $val;
    }
    return $out;
}

function readDatMonitoringFileByHeader($filePath, $ext) {
    if ($ext === 'csv') {
        return readDatMonitoringCSVByHeader($filePath);
    } elseif ($ext === 'xlsx') {
        return readDatMonitoringXLSXByHeader($filePath);
    } elseif ($ext === 'xls') {
        return readDatMonitoringXLSByHeader($filePath);
    }
    return [];
}

function readDatMonitoringCSVByHeader($filePath) {
    $rows = [];
    if (($handle = fopen($filePath, 'r')) !== false) {
        $first_line = fgets($handle);
        $delimiter = (strpos($first_line, "\t") !== false) ? "\t" : ((strpos($first_line, ';') !== false) ? ';' : ',');
        rewind($handle);

        $headerRow = fgetcsv($handle, 0, $delimiter);
        if ($headerRow === false) { fclose($handle); return []; }

        $map = build_header_map_dat_monitoring($headerRow);
        $hilang = cek_kolom_wajib_dat_monitoring($map);
        if (!empty($hilang)) {
            fclose($handle);
            throw new Exception("Kolom berikut tidak ditemukan di header file DAT: " . implode(', ', $hilang) . ". Pastikan baris pertama file berisi nama kolom yang sesuai.");
        }

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = extract_row_by_header_dat_monitoring($row, $map);
        }
        fclose($handle);
    }
    return $rows;
}

function readDatMonitoringXLSXByHeader($filePath) {
    $rows = [];
    if (!class_exists('ZipArchive')) return convertXLSXtoCSVAndParseDatMonitoringByHeader($filePath);
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) return convertXLSXtoCSVAndParseDatMonitoringByHeader($filePath);

    try {
        $sharedStrings = [];
        if (($xmlContent = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $xml = simplexml_load_string($xmlContent);
            foreach ($xml->si as $si) {
                $text = '';
                if (isset($si->t)) { $text = (string)$si->t; }
                elseif (isset($si->r)) { foreach ($si->r as $r) { if (isset($r->t)) $text .= (string)$r->t; } }
                $sharedStrings[] = $text;
            }
        }

        $xmlContent = $zip->getFromName('xl/workbook.xml');
        $xml = simplexml_load_string($xmlContent);

        $sheetIds = [];
        foreach ($xml->sheets->sheet as $sheet) {
            $sheetIds[] = (string)$sheet->attributes('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        }
        if (empty($sheetIds)) throw new Exception("Tidak ada worksheet ditemukan");

        $relsContent = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $relsXml = simplexml_load_string($relsContent);
        $relMap = [];
        foreach ($relsXml->Relationship as $rel) {
            $relMap[(string)$rel->attributes()['Id']] = 'xl/' . (string)$rel->attributes()['Target'];
        }

        $adaKolomWajibHilang = null;
        foreach ($sheetIds as $sheetId) {
            $worksheetPath = $relMap[$sheetId] ?? '';
            if (empty($worksheetPath) || !$zip->locateName($worksheetPath)) continue;

            $sheetXmlContent = $zip->getFromName($worksheetPath);
            $sheetXml = simplexml_load_string($sheetXmlContent);
            if (!$sheetXml || !isset($sheetXml->sheetData)) continue;

            $map = null;
            foreach ($sheetXml->sheetData->row as $row) {
                $sparse = [];
                foreach ($row->c as $cell) {
                    $ref = (string)($cell->attributes()['r'] ?? '');
                    $colIdx = $ref !== '' ? excel_col_to_index_ps($ref) : count($sparse);
                    $value = ''; $type = (string)($cell->attributes()['t'] ?? 'n');
                    if (isset($cell->v)) {
                        $value = (string)$cell->v;
                        if ($type === 's') { $value = $sharedStrings[(int)$value] ?? ''; }
                    }
                    $sparse[$colIdx] = $value;
                }
                if (empty($sparse)) continue;
                $maxIdx = max(array_keys($sparse));
                $cellData = [];
                for ($i = 0; $i <= $maxIdx; $i++) { $cellData[] = $sparse[$i] ?? ''; }

                if ($map === null) {
                    $map = build_header_map_dat_monitoring($cellData);
                    $hilang = cek_kolom_wajib_dat_monitoring($map);
                    if (!empty($hilang)) {
                        $adaKolomWajibHilang = $hilang;
                        $map = false;
                        continue;
                    }
                    continue;
                }
                if ($map === false) continue;

                $rows[] = extract_row_by_header_dat_monitoring($cellData, $map);
            }
        }
        $zip->close();

        if (empty($rows) && $adaKolomWajibHilang !== null) {
            throw new Exception("Kolom berikut tidak ditemukan di header DAT: " . implode(', ', $adaKolomWajibHilang) . ". Pastikan baris pertama sheet berisi nama kolom yang sesuai.");
        }
        return $rows;
    } catch (Exception $e) {
        $zip->close();
        if (strpos($e->getMessage(), 'Kolom berikut tidak ditemukan') !== false) {
            throw $e;
        }
        return convertXLSXtoCSVAndParseDatMonitoringByHeader($filePath);
    }
}

function convertXLSXtoCSVAndParseDatMonitoringByHeader($filePath) {
    $temp_csv = tempnam(sys_get_temp_dir(), 'xlsxdatmon_') . '.csv';

    $command = "libreoffice --headless --convert-to csv:Text --outdir " .
               escapeshellarg(dirname($temp_csv)) . " " . escapeshellarg($filePath) . " 2>/dev/null";
    @shell_exec($command);

    $base_name = pathinfo($filePath, PATHINFO_FILENAME);
    $expected_csv = dirname($temp_csv) . '/' . $base_name . '.csv';

    if (!file_exists($expected_csv) && !file_exists($temp_csv)) {
        $command = "ssconvert " . escapeshellarg($filePath) . " " . escapeshellarg($temp_csv) . " 2>/dev/null";
        @shell_exec($command);
    } elseif (file_exists($expected_csv)) {
        $temp_csv = $expected_csv;
    }

    if (file_exists($temp_csv)) {
        $rows = readDatMonitoringCSVByHeader($temp_csv);
        @unlink($temp_csv);
        if (!empty($rows)) return $rows;
    }

    throw new Exception("Tidak dapat membaca file XLSX. Pastikan LibreOffice/Gnumeric terinstall, atau gunakan format CSV/XLS.");
}

function readDatMonitoringXLSByHeader($filePath) {
    $temp_csv = tempnam(sys_get_temp_dir(), 'xlsdatmon_') . '.csv';
    $command = "ssconvert " . escapeshellarg($filePath) . " " . escapeshellarg($temp_csv) . " 2>/dev/null";
    @shell_exec($command);

    if (file_exists($temp_csv)) {
        $rows = readDatMonitoringCSVByHeader($temp_csv);
        @unlink($temp_csv);
        if (!empty($rows)) return $rows;
    }

    throw new Exception("Tidak dapat membaca file XLS. Pastikan file valid atau gunakan format XLSX/CSV.");
}

function saveDatMonitoringToDatabase($con, $importedData) {
    if (empty($importedData)) {
        return 0;
    }

    // Tabel TERPISAH dari import_dat_penyusutan -- ikutan punya dimensi periode_bulan (data DAT
    // Tahun Sebelumnya ternyata juga snapshot BULANAN, bukan cuma 1x per tahun -- makanya sebelum
    // ini banyak baris "bulan lain" ke-tolak sebagai duplikat karena UNIQUE KEY lama cuma
    // (nomor_asset, sub_number, tahun_buku), tanpa bulan). UNIQUE KEY sekarang ikut periode_bulan.
    $create_table_sql = "CREATE TABLE IF NOT EXISTS import_dat_monitoring (
        id INT AUTO_INCREMENT PRIMARY KEY,
        profit_center VARCHAR(20),
        cabang VARCHAR(100),
        periode_bulan VARCHAR(20),
        tahun_buku VARCHAR(4),
        nomor_asset VARCHAR(50),
        sub_number VARCHAR(50),
        gl_account_aset VARCHAR(25),
        keterangan_asset TEXT,
        tgl_perolehan VARCHAR(20),
        tgl_mulai_penyusutan VARCHAR(20),
        nilai_perolehan_sd_tahun_berjalan VARCHAR(30),
        akumulasi_penyusutan VARCHAR(30),
        gl_account_exp VARCHAR(25),
        sub_number_num INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        imported_by VARCHAR(20),
        UNIQUE KEY uk_asset_sub_periode_tahun (nomor_asset, sub_number, periode_bulan, tahun_buku),
        KEY idx_nomor_asset_sub_num (nomor_asset, sub_number_num)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!mysqli_query($con, $create_table_sql)) {
        throw new Exception("Gagal membuat tabel: " . mysqli_error($con));
    }

    // ── Migrasi AMAN untuk tabel yang sudah ada sebelumnya (dibuat sebelum kolom periode_bulan
    // ada) -- tambah kolom kalau belum ada, lalu ganti UNIQUE KEY lama ke yang ikut periode_bulan.
    // TIDAK PERNAH drop/hapus data yang sudah tersimpan. ──
    $existingColsMon = [];
    $resColsMon = mysqli_query($con, "SHOW COLUMNS FROM import_dat_monitoring");
    if ($resColsMon) { while ($c = mysqli_fetch_assoc($resColsMon)) { $existingColsMon[] = $c['Field']; } }
    if (!in_array('periode_bulan', $existingColsMon, true)) {
        mysqli_query($con, "ALTER TABLE import_dat_monitoring ADD COLUMN periode_bulan VARCHAR(20) AFTER cabang");
    }
    // Kolom GL Aset (dari kolom "GL Account Aset" di file DAT) -- tabel lama belum punya, tambahkan setelah sub_number.
    if (!in_array('gl_account_aset', $existingColsMon, true)) {
        if (!mysqli_query($con, "ALTER TABLE import_dat_monitoring ADD COLUMN gl_account_aset VARCHAR(25) NULL AFTER sub_number")) {
            throw new Exception("Gagal menambah kolom gl_account_aset: " . mysqli_error($con));
        }
    }
    $idxMonRes = mysqli_query($con, "SHOW INDEX FROM import_dat_monitoring WHERE Key_name = 'uk_asset_sub_tahun'");
    if ($idxMonRes && mysqli_num_rows($idxMonRes) > 0) {
        mysqli_query($con, "ALTER TABLE import_dat_monitoring DROP INDEX uk_asset_sub_tahun");
        mysqli_query($con, "ALTER TABLE import_dat_monitoring ADD UNIQUE KEY uk_asset_sub_periode_tahun (nomor_asset, sub_number, periode_bulan, tahun_buku)");
    }

    // Cari posisi kolom tahun_buku secara dinamis (bukan hardcode index), biar tidak rusak kalau
    // urutan kolom berubah lagi di kemudian hari.
    global $DB_COLUMNS_DAT_MONITORING_ORDERED;
    $idxTahunBuku = array_search('tahun_buku', $DB_COLUMNS_DAT_MONITORING_ORDERED);

    // Replace by tahun_buku -- kalau upload file berisi data tahun 2026, semua baris LAMA yang
    // tahun_buku=2026 dihapus dulu, baru insert yang baru (tahun lain TIDAK kesentuh).
    $tahunSet = [];
    foreach ($importedData as $rowCek) {
        $tb = trim((string)($rowCek[$idxTahunBuku] ?? ''));
        if ($tb !== '') { $tahunSet[$tb] = true; }
    }
    foreach (array_keys($tahunSet) as $tb) {
        $tbEsc = mysqli_real_escape_string($con, $tb);
        if (!mysqli_query($con, "DELETE FROM import_dat_monitoring WHERE tahun_buku = '$tbEsc'")) {
            throw new Exception("Gagal menghapus data lama tahun buku $tb: " . mysqli_error($con));
        }
    }

    $nipp = isset($_SESSION['nipp']) ? $_SESSION['nipp'] : 'unknown';

    $column_names = $DB_COLUMNS_DAT_MONITORING_ORDERED;

    $saved_count = 0;
    $failed_rows = [];
    $skipped_blank = 0;
    $idxNomorAssetCol = array_search('nomor_asset', $column_names);
    $idxSubNumberCol = array_search('sub_number', $column_names);

    mysqli_begin_transaction($con);

    try {
        foreach ($importedData as $row_index => $row) {
            $nomorAssetVal = trim((string)($row[$idxNomorAssetCol] ?? ''));
            if ($nomorAssetVal === '') {
                $skipped_blank++;
                continue;
            }

            $values = [];
            foreach ($column_names as $col_idx => $col_name) {
                $value = isset($row[$col_idx]) ? $row[$col_idx] : '';
                if ($col_name === 'nomor_asset' || $col_name === 'sub_number') {
                    $value = trim((string)$value);
                }
                $values[] = "'" . mysqli_real_escape_string($con, $value) . "'";
            }

            $subNumberNum = normalisasi_subnumber_num_ps($row[$idxSubNumberCol] ?? '');
            $values[] = $subNumberNum === null ? 'NULL' : $subNumberNum;

            $values[] = "'" . mysqli_real_escape_string($con, $nipp) . "'";

            $columns = implode(', ', $column_names) . ', sub_number_num, imported_by';
            $insert_sql = "INSERT INTO import_dat_monitoring (" . $columns . ") VALUES (" . implode(', ', $values) . ")";

            try {
                if (mysqli_query($con, $insert_sql)) {
                    $saved_count++;
                } else {
                    $error = mysqli_error($con);
                    if (strpos($error, 'Duplicate entry') !== false) {
                        $failed_rows[] = "Baris " . ($row_index + 2) . ": Asset $nomorAssetVal sudah ada di database untuk bulan & tahun buku ini";
                    } else {
                        $failed_rows[] = "Baris " . ($row_index + 2) . ": $error";
                    }
                }
            } catch (\Throwable $eRow) {
                $msgRow = $eRow->getMessage();
                if (strpos($msgRow, 'Duplicate entry') !== false) {
                    $failed_rows[] = "Baris " . ($row_index + 2) . ": Asset $nomorAssetVal sudah ada di database untuk bulan & tahun buku ini";
                } else {
                    $failed_rows[] = "Baris " . ($row_index + 2) . ": " . $msgRow;
                }
            }
        }

        mysqli_commit($con);

    } catch (Exception $e) {
        mysqli_rollback($con);
        throw new Exception("Gagal menyimpan data: " . $e->getMessage());
    }

    if (!empty($failed_rows)) {
        error_log("Import DAT Monitoring failed rows: " . implode("; ", array_slice($failed_rows, 0, 5)));
    }
    if ($skipped_blank > 0) {
        error_log("Import DAT Monitoring: $skipped_blank baris kosong dilewati.");
    }

    return [
        'saved_count'    => $saved_count,
        'skipped_blank'  => $skipped_blank,
        'failed_count'   => count($failed_rows),
        'failed_samples' => array_slice($failed_rows, 0, 5),
    ];
}

// ── Normalisasi posting_date & amount SEKALI di sini, saat data masuk ──
// Ini persis logika date_expr()/amount_expr() di dasbor_penyusutan.php, tapi dijalankan di PHP
// saat INSERT, bukan di SQL saat SELECT. Hasilnya disimpan ke kolom posting_date_norm/amount_norm
// (DATE & DECIMAL asli + ter-index), supaya dashboard & export tinggal baca kolom itu langsung
// tanpa perlu parsing string berulang-ulang tiap query dijalankan.
// Meniru persis CAST(TRIM(x) AS UNSIGNED) di MySQL: ambil angka di awal string setelah di-trim.
// Dipakai untuk sub_number (tabel DAT) & asset_subnumber (tabel penyusutan), supaya "001" dan "1"
// dianggap sama tanpa perlu CAST/TRIM saat query (yang mematikan index).
function normalisasi_subnumber_num_ps($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') { return null; }
    if (preg_match('/^\d+/', $raw, $m)) {
        return (int)$m[0];
    }
    return null; // sama seperti MySQL: string tanpa awalan angka -> tidak match numerik
}

function normalisasi_posting_date_ps($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') { return null; }

    // Urutan format sama seperti STR_TO_DATE di date_expr(): m/d/Y, d.m.Y, d/m/Y, Y-m-d
    $formats = ['m/d/Y', 'd.m.Y', 'd/m/Y', 'Y-m-d'];
    foreach ($formats as $fmt) {
        $d = DateTime::createFromFormat('!' . $fmt, $raw);
        $errors = DateTime::getLastErrors();
        if ($d && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $d->format('Y-m-d');
        }
    }

    // Excel serial date number (mis. 46142), sama seperti fallback di date_expr()
    if (ctype_digit($raw)) {
        $num = (int)$raw;
        if ($num >= 3653 && $num <= 100000) {
            $d = new DateTime('1899-12-30');
            $d->modify('+' . $num . ' days');
            return $d->format('Y-m-d');
        }
    }

    return null; // gagal parse -> biarkan NULL, sama seperti COALESCE(...) di date_expr()
}

function normalisasi_amount_ps($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') { return null; }
    // Sama seperti amount_expr(): buang '.' dan ',' lalu CAST ke DECIMAL(20,2)
    $clean = str_replace(['.', ','], '', $raw);
    if ($clean === '' || !is_numeric($clean)) { return null; }
    return round((float)$clean, 2);
}

function saveDataPenyusutanToDatabase($con, $importedData) {
    if (empty($importedData)) {
        return 0;
    }
    
    $create_table_sql = "CREATE TABLE IF NOT EXISTS import_penyusutan (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cost_center VARCHAR(20),
        asset VARCHAR(50),
        asset_subnumber VARCHAR(50),
        account VARCHAR(20),
        posting_date VARCHAR(20),
        amount_local_currency VARCHAR(50),
        profit_center VARCHAR(20),
        cabang VARCHAR(100),
        text VARCHAR(255),
        document_number VARCHAR(30),
        posting_date_norm DATE NULL,
        amount_norm DECIMAL(20,2) NULL,
        asset_subnumber_num INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        imported_by VARCHAR(20),
        KEY idx_penyusutan_lookup (cost_center, asset, asset_subnumber, account, posting_date),
        KEY idx_posting_date_norm (posting_date_norm),
        KEY idx_account (account),
        KEY idx_asset_sub (asset, asset_subnumber),
        KEY idx_asset_sub_num (asset, asset_subnumber_num)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if (!mysqli_query($con, $create_table_sql)) {
        throw new Exception("Gagal membuat tabel: " . mysqli_error($con));
    }

    // Migrasi AMAN untuk tabel lama: tambah kolom document_number kalau belum ada.
    $existingColsPs = [];
    $resColsPs = mysqli_query($con, "SHOW COLUMNS FROM import_penyusutan");
    if ($resColsPs) { while ($c = mysqli_fetch_assoc($resColsPs)) { $existingColsPs[] = $c['Field']; } }
    if (!in_array('document_number', $existingColsPs, true)) {
        mysqli_query($con, "ALTER TABLE import_penyusutan ADD COLUMN document_number VARCHAR(30) AFTER text");
    }
    // Migrasi AMAN: tambah kolom hasil normalisasi (posting_date_norm/amount_norm) + index-nya kalau
    // belum ada, jaga-jaga kalau server ini belum pernah dijalankan migrasi manualnya lewat phpMyAdmin.
    if (!in_array('posting_date_norm', $existingColsPs, true)) {
        mysqli_query($con, "ALTER TABLE import_penyusutan ADD COLUMN posting_date_norm DATE NULL, ADD COLUMN amount_norm DECIMAL(20,2) NULL");
    }
    // Migrasi AMAN: kolom asset_subnumber_num (versi angka asset_subnumber) buat JOIN cepat ke
    // import_dat_penyusutan.sub_number_num.
    if (!in_array('asset_subnumber_num', $existingColsPs, true)) {
        mysqli_query($con, "ALTER TABLE import_penyusutan ADD COLUMN asset_subnumber_num INT UNSIGNED NULL");
    }
    $existingIdxPs = [];
    $resIdxPs = mysqli_query($con, "SHOW INDEX FROM import_penyusutan");
    if ($resIdxPs) { while ($ix = mysqli_fetch_assoc($resIdxPs)) { $existingIdxPs[] = $ix['Key_name']; } }
    if (!in_array('idx_posting_date_norm', $existingIdxPs, true)) {
        mysqli_query($con, "ALTER TABLE import_penyusutan ADD INDEX idx_posting_date_norm (posting_date_norm)");
    }
    if (!in_array('idx_asset_sub', $existingIdxPs, true)) {
        mysqli_query($con, "ALTER TABLE import_penyusutan ADD INDEX idx_asset_sub (asset, asset_subnumber)");
    }
    if (!in_array('idx_asset_sub_num', $existingIdxPs, true)) {
        mysqli_query($con, "ALTER TABLE import_penyusutan ADD INDEX idx_asset_sub_num (asset, asset_subnumber_num)");
    }

    // ── PENTING: hapus UNIQUE KEY lama kalau masih ada ──
    // Data penyusutan SAP (Fixed Asset FI-AA) bisa punya BEBERAPA baris yang identik
    // di SEMUA kolom yang kita export (cost_center, asset, account, posting_date,
    // document_number, bahkan amount) -- ini terjadi karena 1 dokumen bisa posting ke
    // beberapa "Depreciation Area" (Buku/Fiskal/Grup) sekaligus, dan kolom Depreciation
    // Area itu TIDAK ada di file export-nya. Jadi TIDAK ADA kombinasi kolom manapun yang
    // bisa dipakai sebagai "kunci unik" tanpa risiko salah buang baris yang valid.
    // Makanya UNIQUE KEY dihapus total -- deteksi duplikat dilakukan lewat TRUNCATE
    // (replace-all) di bawah, bukan lewat constraint per baris.
    $idxResPs = mysqli_query($con, "SHOW INDEX FROM import_penyusutan WHERE Key_name = 'uk_penyusutan_row'");
    if ($idxResPs && mysqli_num_rows($idxResPs) > 0) {
        mysqli_query($con, "ALTER TABLE import_penyusutan DROP INDEX uk_penyusutan_row");
    }

    // ── Strategi REPLACE-ALL ──
    // File "Penyusutan sd Bulan X" itu sifatnya KUMULATIF (selalu berisi data dari awal
    // tahun s.d. bulan terbaru), bukan data incremental per bulan. Jadi setiap kali upload
    // baru, cara paling aman & benar adalah KOSONGKAN dulu seluruh tabel, baru masukin
    // ulang semua baris dari file yang baru -- bukan coba "gabung" data lama+baru pakai
    // deteksi duplikat (yang sudah terbukti gak reliable untuk data ini).
    if (!mysqli_query($con, "TRUNCATE TABLE import_penyusutan")) {
        throw new Exception("Gagal mengosongkan tabel sebelum import ulang: " . mysqli_error($con));
    }

    $nipp = isset($_SESSION['nipp']) ? $_SESSION['nipp'] : 'unknown';
    
    $column_names = [
        'cost_center',
        'asset',
        'asset_subnumber',
        'account',
        'posting_date',
        'amount_local_currency',
        'profit_center',
        'text',
        'document_number'
    ];
    
    $saved_count = 0;
    $failed_rows = [];
    
    mysqli_begin_transaction($con);
    
    try {
        foreach ($importedData as $row_index => $row) {
            $values = [];
            foreach ($column_names as $col_idx => $col_name) {
                $value = isset($row[$col_idx]) ? $row[$col_idx] : '';
                // Trim asset & asset_subnumber saat insert supaya JOIN ke import_dat_penyusutan
                // bisa pakai perbandingan langsung (index-friendly) tanpa TRIM() di query.
                if ($col_name === 'asset' || $col_name === 'asset_subnumber') {
                    $value = trim((string)$value);
                }
                $values[] = "'" . mysqli_real_escape_string($con, $value) . "'";
            }

            // Hitung kolom hasil normalisasi dari posting_date (index 4) & amount_local_currency (index 5) mentah
            $rawPostingDate = isset($row[4]) ? $row[4] : '';
            $rawAmount      = isset($row[5]) ? $row[5] : '';
            $postingDateNorm = normalisasi_posting_date_ps($rawPostingDate);
            $amountNorm      = normalisasi_amount_ps($rawAmount);
            $values[] = $postingDateNorm === null ? 'NULL' : "'" . mysqli_real_escape_string($con, $postingDateNorm) . "'";
            $values[] = $amountNorm === null ? 'NULL' : $amountNorm;

            // asset_subnumber_num: versi angka dari asset_subnumber (index 2), buat JOIN cepat
            $assetSubnumberNum = normalisasi_subnumber_num_ps($row[2] ?? '');
            $values[] = $assetSubnumberNum === null ? 'NULL' : $assetSubnumberNum;

            $values[] = "'" . mysqli_real_escape_string($con, $nipp) . "'";

            $columns = implode(', ', $column_names) . ', posting_date_norm, amount_norm, asset_subnumber_num, imported_by';
            $insert_sql = "INSERT INTO import_penyusutan (" . $columns . ") VALUES (" . implode(', ', $values) . ")";
            
            if (mysqli_query($con, $insert_sql)) {
                $saved_count++;
            } else {
                $failed_rows[] = "Baris " . ($row_index + 2) . ": " . mysqli_error($con);
            }
        }

        mysqli_commit($con);
        
    } catch (Exception $e) {
        mysqli_rollback($con);
        throw new Exception("Gagal menyimpan data: " . $e->getMessage());
    }
    
    if (!empty($failed_rows)) {
        error_log("Import Penyusutan failed rows: " . implode("; ", array_slice($failed_rows, 0, 5)));
    }
    
    return $saved_count;
}

// ==========================================================
// Simpan "Nilai Perolehan / Nilai Akumulasi / Nilai DEP" -- 3 tabel kembar (struktur sama), beda tabel saja.
// Baris dari readPenyusutanFileByHeader() urutannya: cost_center, asset, asset_subnumber, account,
// posting_date, amount_local_currency, profit_center, text, document_number.
// ==========================================================
function saveNilaiAsetToDatabase($con, $tabel, $importedData, $periodeBulan, $periodeTahun) {
    if (empty($importedData)) { return 0; }
    // Whitelist nama tabel (nama tabel tidak bisa di-escape pakai parameter)
    if (!in_array($tabel, ['import_nilai_perolehan', 'import_nilai_akumulasi', 'import_nilai_dep'], true)) {
        throw new Exception("Nama tabel tidak valid");
    }
    $create = "CREATE TABLE IF NOT EXISTS $tabel (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cost_center VARCHAR(20),
        asset VARCHAR(50),
        asset_subnumber VARCHAR(50),
        account VARCHAR(20),
        document_number VARCHAR(30),
        posting_date VARCHAR(20),
        amount_local_currency VARCHAR(50),
        profit_center VARCHAR(20),
        text VARCHAR(255),
        posting_date_norm DATE NULL,
        amount_norm DECIMAL(20,2) NULL,
        asset_subnumber_num INT UNSIGNED NULL,
        periode_bulan VARCHAR(20) NOT NULL,
        periode_tahun VARCHAR(4) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        imported_by VARCHAR(20),
        KEY idx_periode (periode_tahun, periode_bulan),
        KEY idx_account (account),
        KEY idx_asset_sub (asset, asset_subnumber),
        KEY idx_posting_date_norm (posting_date_norm)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($con, $create)) { throw new Exception("Gagal membuat tabel: " . mysqli_error($con)); }

    $pb = mysqli_real_escape_string($con, (string)$periodeBulan);
    $pt = mysqli_real_escape_string($con, (string)$periodeTahun);
    $nipp = isset($_SESSION['nipp']) ? $_SESSION['nipp'] : 'unknown';
    $saved = 0; $failed = [];

    mysqli_begin_transaction($con);
    try {
        // Periode yang sama di-replace; periode lain tidak disentuh
        if (!mysqli_query($con, "DELETE FROM $tabel WHERE periode_bulan = '$pb' AND periode_tahun = '$pt'")) {
            throw new Exception("Gagal menghapus data periode lama: " . mysqli_error($con));
        }
        $esc = function ($v) use ($con) { return "'" . mysqli_real_escape_string($con, trim((string)$v)) . "'"; };
        foreach ($importedData as $i => $r) {
            $dateNorm = normalisasi_posting_date_ps($r[4] ?? '');
            $amtNorm  = normalisasi_amount_ps($r[5] ?? '');
            $subNum   = normalisasi_subnumber_num_ps($r[2] ?? '');
            $sql = "INSERT INTO $tabel (cost_center, asset, asset_subnumber, account, document_number, posting_date, amount_local_currency,
                        profit_center, text, posting_date_norm, amount_norm, asset_subnumber_num, periode_bulan, periode_tahun, imported_by)
                    VALUES (" . implode(', ', [
                        $esc($r[0] ?? ''), $esc($r[1] ?? ''), $esc($r[2] ?? ''), $esc($r[3] ?? ''), $esc($r[8] ?? ''),
                        $esc($r[4] ?? ''), $esc($r[5] ?? ''), $esc($r[6] ?? ''), $esc($r[7] ?? ''),
                        $dateNorm === null ? 'NULL' : "'" . mysqli_real_escape_string($con, $dateNorm) . "'",
                        $amtNorm === null ? 'NULL' : $amtNorm,
                        $subNum === null ? 'NULL' : $subNum,
                        "'$pb'", "'$pt'", $esc($nipp),
                    ]) . ")";
            if (mysqli_query($con, $sql)) { $saved++; } else { $failed[] = "Baris " . ($i + 2) . ": " . mysqli_error($con); }
        }
        mysqli_commit($con);
    } catch (Exception $e) {
        mysqli_rollback($con);
        throw new Exception("Gagal menyimpan data: " . $e->getMessage());
    }
    if (!empty($failed)) { error_log("Import $tabel failed rows: " . implode("; ", array_slice($failed, 0, 5))); }
    return $saved;
}

// ==========================================================
// Fungsi-fungsi untuk card "Import AR02 reg3"
// (pola sama persis dengan fungsi *_dat di atas)
// ==========================================================
function normalize_header_ar02($h) {
    $h = strtolower(trim((string)$h));
    $h = str_replace('.', '', $h);
    $h = preg_replace('/\s+/', ' ', $h);
    return trim($h);
}

function build_header_map_ar02($headerRow) {
    $map = [];
    foreach ($headerRow as $idx => $h) {
        $norm = normalize_header_ar02($h);
        if ($norm !== '' && !isset($map[$norm])) {
            $map[$norm] = $idx;
        }
    }
    return $map;
}

function cek_kolom_wajib_ar02($indexMap) {
    global $WAJIB_ADA_AR02;
    $hilang = [];
    foreach ($WAJIB_ADA_AR02 as $h) {
        if (!isset($indexMap[$h])) $hilang[] = $h;
    }
    return $hilang;
}

function excel_serial_ke_tanggal_ar02($value) {
    $value = trim((string)$value);
    if ($value === '' || !is_numeric($value)) {
        return $value;
    }
    $serial = (float)$value;
    if ($serial < 3653 || $serial > 100000) {
        return $value;
    }
    $unixTimestamp = ($serial - 25569) * 86400;
    return gmdate('Y-m-d', (int)round($unixTimestamp));
}

function extract_row_by_header_ar02($dataRow, $indexMap) {
    global $HEADER_TO_FIELD_AR02, $DB_COLUMNS_AR02_ORDERED;
    static $fieldToHeader = null;
    if ($fieldToHeader === null) {
        $fieldToHeader = [];
        foreach ($HEADER_TO_FIELD_AR02 as $normHeader => $field) { $fieldToHeader[$field] = $normHeader; }
    }

    $out = [];
    foreach ($DB_COLUMNS_AR02_ORDERED as $field) {
        $normHeader = $fieldToHeader[$field] ?? null;
        $idx = ($normHeader !== null) ? ($indexMap[$normHeader] ?? null) : null;
        $val = ($idx !== null && isset($dataRow[$idx])) ? $dataRow[$idx] : '';
        if ($field === 'tgl_kapitalisasi') {
            $val = excel_serial_ke_tanggal_ar02($val);
        }
        $out[] = $val;
    }
    return $out;
}

function readAr02FileByHeader($filePath, $ext) {
    if ($ext === 'csv') {
        return readAr02CSVByHeader($filePath);
    } elseif ($ext === 'xlsx') {
        return readAr02XLSXByHeader($filePath);
    } elseif ($ext === 'xls') {
        return readAr02XLSByHeader($filePath);
    }
    return [];
}

function readAr02CSVByHeader($filePath) {
    $rows = [];
    if (($handle = fopen($filePath, 'r')) !== false) {
        $first_line = fgets($handle);
        $delimiter = (strpos($first_line, "\t") !== false) ? "\t" : ((strpos($first_line, ';') !== false) ? ';' : ',');
        rewind($handle);

        $headerRow = fgetcsv($handle, 0, $delimiter);
        if ($headerRow === false) { fclose($handle); return []; }

        $map = build_header_map_ar02($headerRow);
        $hilang = cek_kolom_wajib_ar02($map);
        if (!empty($hilang)) {
            fclose($handle);
            throw new Exception("Kolom berikut tidak ditemukan di header file AR02 reg3: " . implode(', ', $hilang) . ". Pastikan baris pertama file berisi nama kolom yang sesuai.");
        }

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = extract_row_by_header_ar02($row, $map);
        }
        fclose($handle);
    }
    return $rows;
}

function readAr02XLSXByHeader($filePath) {
    $rows = [];
    if (!class_exists('ZipArchive')) return convertXLSXtoCSVAndParseAr02ByHeader($filePath);
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) return convertXLSXtoCSVAndParseAr02ByHeader($filePath);

    try {
        $sharedStrings = [];
        if (($xmlContent = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $xml = simplexml_load_string($xmlContent);
            foreach ($xml->si as $si) {
                $text = '';
                if (isset($si->t)) { $text = (string)$si->t; }
                elseif (isset($si->r)) { foreach ($si->r as $r) { if (isset($r->t)) $text .= (string)$r->t; } }
                $sharedStrings[] = $text;
            }
        }

        $xmlContent = $zip->getFromName('xl/workbook.xml');
        $xml = simplexml_load_string($xmlContent);

        $sheetIds = [];
        foreach ($xml->sheets->sheet as $sheet) {
            $sheetIds[] = (string)$sheet->attributes('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        }
        if (empty($sheetIds)) throw new Exception("Tidak ada worksheet ditemukan");

        $relsContent = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $relsXml = simplexml_load_string($relsContent);
        $relMap = [];
        foreach ($relsXml->Relationship as $rel) {
            $relMap[(string)$rel->attributes()['Id']] = 'xl/' . (string)$rel->attributes()['Target'];
        }

        $adaKolomWajibHilang = null;
        foreach ($sheetIds as $sheetId) {
            $worksheetPath = $relMap[$sheetId] ?? '';
            if (empty($worksheetPath) || !$zip->locateName($worksheetPath)) continue;

            $sheetXmlContent = $zip->getFromName($worksheetPath);
            $sheetXml = simplexml_load_string($sheetXmlContent);
            if (!$sheetXml || !isset($sheetXml->sheetData)) continue;

            $map = null;
            foreach ($sheetXml->sheetData->row as $row) {
                $sparse = [];
                foreach ($row->c as $cell) {
                    $ref = (string)($cell->attributes()['r'] ?? '');
                    $colIdx = $ref !== '' ? excel_col_to_index_ps($ref) : count($sparse);
                    $value = ''; $type = (string)($cell->attributes()['t'] ?? 'n');
                    if (isset($cell->v)) {
                        $value = (string)$cell->v;
                        if ($type === 's') { $value = $sharedStrings[(int)$value] ?? ''; }
                    }
                    $sparse[$colIdx] = $value;
                }
                if (empty($sparse)) continue;
                $maxIdx = max(array_keys($sparse));
                $cellData = [];
                for ($i = 0; $i <= $maxIdx; $i++) { $cellData[] = $sparse[$i] ?? ''; }

                if ($map === null) {
                    $map = build_header_map_ar02($cellData);
                    $hilang = cek_kolom_wajib_ar02($map);
                    if (!empty($hilang)) {
                        $adaKolomWajibHilang = $hilang;
                        $map = false;
                        continue;
                    }
                    continue;
                }
                if ($map === false) continue;

                $rows[] = extract_row_by_header_ar02($cellData, $map);
            }
        }
        $zip->close();

        if (empty($rows) && $adaKolomWajibHilang !== null) {
            throw new Exception("Kolom berikut tidak ditemukan di header AR02 reg3: " . implode(', ', $adaKolomWajibHilang) . ". Pastikan baris pertama sheet berisi nama kolom yang sesuai.");
        }
        return $rows;
    } catch (Exception $e) {
        $zip->close();
        if (strpos($e->getMessage(), 'Kolom berikut tidak ditemukan') !== false) {
            throw $e;
        }
        return convertXLSXtoCSVAndParseAr02ByHeader($filePath);
    }
}

function convertXLSXtoCSVAndParseAr02ByHeader($filePath) {
    $temp_csv = tempnam(sys_get_temp_dir(), 'xlsxar02_') . '.csv';

    $command = "libreoffice --headless --convert-to csv:Text --outdir " .
               escapeshellarg(dirname($temp_csv)) . " " . escapeshellarg($filePath) . " 2>/dev/null";
    @shell_exec($command);

    $base_name = pathinfo($filePath, PATHINFO_FILENAME);
    $expected_csv = dirname($temp_csv) . '/' . $base_name . '.csv';

    if (!file_exists($expected_csv) && !file_exists($temp_csv)) {
        $command = "ssconvert " . escapeshellarg($filePath) . " " . escapeshellarg($temp_csv) . " 2>/dev/null";
        @shell_exec($command);
    } elseif (file_exists($expected_csv)) {
        $temp_csv = $expected_csv;
    }

    if (file_exists($temp_csv)) {
        $rows = readAr02CSVByHeader($temp_csv);
        @unlink($temp_csv);
        if (!empty($rows)) return $rows;
    }

    throw new Exception("Tidak dapat membaca file XLSX. Pastikan LibreOffice/Gnumeric terinstall, atau gunakan format CSV/XLS.");
}

function readAr02XLSByHeader($filePath) {
    $temp_csv = tempnam(sys_get_temp_dir(), 'xlsar02_') . '.csv';
    $command = "ssconvert " . escapeshellarg($filePath) . " " . escapeshellarg($temp_csv) . " 2>/dev/null";
    @shell_exec($command);

    if (file_exists($temp_csv)) {
        $rows = readAr02CSVByHeader($temp_csv);
        @unlink($temp_csv);
        if (!empty($rows)) return $rows;
    }

    throw new Exception("Tidak dapat membaca file XLS. Pastikan file valid atau gunakan format XLSX/CSV.");
}

function saveAr02ToDatabase($con, $importedData, $periodeBulan, $periodeTahun) {
    if (empty($importedData)) {
        return 0;
    }

    $create_table_sql = "CREATE TABLE IF NOT EXISTS import_ar02_reg3 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bal_sh_acct_APC VARCHAR(20),
        nomor_asset VARCHAR(50),
        sub_number VARCHAR(50),
        asset_class VARCHAR(20),
        gl_akumulasi_penyusutan VARCHAR(20),
        ckpn VARCHAR(20),
        gl_beban_penyusutan VARCHAR(20),
        tgl_kapitalisasi VARCHAR(20),
        keterangan_asset VARCHAR(255),
        current_apc VARCHAR(30),
        nilai_buku VARCHAR(30),
        akumulasi_dep_nilai VARCHAR(30),
        dep_tahun_berjalan VARCHAR(30),
        write_ups VARCHAR(30),
        plant VARCHAR(150),
        acquisition VARCHAR(30),
        retirement VARCHAR(30),
        transfers VARCHAR(30),
        dep_fy_start VARCHAR(30),
        dep_retir VARCHAR(30),
        dep_transfer VARCHAR(30),
        cabang VARCHAR(100),
        profit_center VARCHAR(20),
        cost_center VARCHAR(30),
        periode_bulan VARCHAR(20) NOT NULL,
        periode_tahun VARCHAR(4) NOT NULL,
        sub_number_num INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        imported_by VARCHAR(20),
        UNIQUE KEY uk_ar02_asset_sub_periode (nomor_asset, sub_number, periode_bulan, periode_tahun),
        KEY idx_bal_sh_acct_apc_periode (bal_sh_acct_APC, periode_tahun, periode_bulan),
        KEY idx_ar02_nomor_asset_sub_num (nomor_asset, sub_number_num)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!mysqli_query($con, $create_table_sql)) {
        throw new Exception("Gagal membuat tabel: " . mysqli_error($con));
    }

    // Migrasi aman untuk tabel lama yang belum punya kolom cost_center.
    $rcCc = mysqli_query($con, "SHOW COLUMNS FROM import_ar02_reg3 LIKE 'cost_center'");
    if ($rcCc && mysqli_num_rows($rcCc) === 0) {
        if (!mysqli_query($con, "ALTER TABLE import_ar02_reg3 ADD COLUMN cost_center VARCHAR(30) NULL AFTER profit_center")) {
            throw new Exception("Gagal menambah kolom cost_center: " . mysqli_error($con));
        }
    }

    // Hapus dulu data periode yang sama sebelum insert ulang, biar upload ulang untuk periode
    // yang sama TIDAK numpuk jadi data ganda (pola sama seperti saveDatPenyusutanToDatabase).
    $pbEsc = mysqli_real_escape_string($con, $periodeBulan);
    $tbEsc = mysqli_real_escape_string($con, $periodeTahun);
    $nipp = isset($_SESSION['nipp']) ? $_SESSION['nipp'] : 'unknown';

    global $DB_COLUMNS_AR02_ORDERED;
    $column_names = $DB_COLUMNS_AR02_ORDERED;

    $saved_count = 0;
    $failed_rows = [];
    $skipped_blank = 0;
    $idxNomorAssetCol = array_search('nomor_asset', $column_names);
    $idxSubNumberCol = array_search('sub_number', $column_names);

    mysqli_begin_transaction($con);

    try {
        // DELETE periode lama ada DI DALAM transaksi: kalau insert gagal, data lama ikut ter-rollback.
        if (!mysqli_query($con, "DELETE FROM import_ar02_reg3 WHERE periode_bulan = '$pbEsc' AND periode_tahun = '$tbEsc'")) {
            throw new Exception("Gagal menghapus data lama periode $periodeBulan/$periodeTahun: " . mysqli_error($con));
        }

        foreach ($importedData as $row_index => $row) {
            $nomorAssetVal = trim((string)($row[$idxNomorAssetCol] ?? ''));
            if ($nomorAssetVal === '') {
                $skipped_blank++;
                continue;
            }

            $values = [];
            foreach ($column_names as $col_idx => $col_name) {
                $value = isset($row[$col_idx]) ? $row[$col_idx] : '';
                if ($col_name === 'nomor_asset' || $col_name === 'sub_number' || $col_name === 'bal_sh_acct_APC') {
                    $value = trim((string)$value);
                }
                $values[] = "'" . mysqli_real_escape_string($con, $value) . "'";
            }

            // periode_bulan & periode_tahun tidak ada di file, ambil dari input form (sama untuk semua baris)
            $values[] = "'" . mysqli_real_escape_string($con, $periodeBulan) . "'";
            $values[] = "'" . mysqli_real_escape_string($con, $periodeTahun) . "'";

            $subNumberNum = normalisasi_subnumber_num_ps($row[$idxSubNumberCol] ?? '');
            $values[] = $subNumberNum === null ? 'NULL' : $subNumberNum;

            $values[] = "'" . mysqli_real_escape_string($con, $nipp) . "'";

            $columns = implode(', ', $column_names) . ', periode_bulan, periode_tahun, sub_number_num, imported_by';
            $insert_sql = "INSERT INTO import_ar02_reg3 (" . $columns . ") VALUES (" . implode(', ', $values) . ")";

            try {
                if (mysqli_query($con, $insert_sql)) {
                    $saved_count++;
                } else {
                    $error = mysqli_error($con);
                    if (strpos($error, 'Duplicate entry') !== false) {
                        $failed_rows[] = "Baris " . ($row_index + 2) . ": Asset sudah ada di database untuk periode ini";
                    } else {
                        $failed_rows[] = "Baris " . ($row_index + 2) . ": " . $error;
                    }
                }
            } catch (\Throwable $eRow) {
                $msgRow = $eRow->getMessage();
                if (strpos($msgRow, 'Duplicate entry') !== false) {
                    $failed_rows[] = "Baris " . ($row_index + 2) . ": Asset sudah ada di database untuk periode ini";
                } else {
                    $failed_rows[] = "Baris " . ($row_index + 2) . ": " . $msgRow;
                }
            }
        }

        // Isi akun Akumulasi/CKPN/Beban dari master_coa lewat Asset Class (setara XLOOKUP di Excel),
        // hanya untuk periode ini dan hanya kolom yang masih kosong.
        $adaMc = mysqli_query($con, "SHOW TABLES LIKE 'master_coa'");
        if ($adaMc && mysqli_num_rows($adaMc) > 0) {
            $sqlLookup = "UPDATE import_ar02_reg3 a
                JOIN master_coa m ON TRIM(a.asset_class) = TRIM(m.account_determination)
                SET a.gl_akumulasi_penyusutan = IF(IFNULL(TRIM(a.gl_akumulasi_penyusutan),'') = '', m.akun_akumulasi_penyusutan, a.gl_akumulasi_penyusutan),
                    a.ckpn                    = IF(IFNULL(TRIM(a.ckpn),'') = '', m.ckpn, a.ckpn),
                    a.gl_beban_penyusutan     = IF(IFNULL(TRIM(a.gl_beban_penyusutan),'') = '', m.akun_beban_penyusutan, a.gl_beban_penyusutan)
                WHERE a.periode_bulan = '$pbEsc' AND a.periode_tahun = '$tbEsc'";
            if (!mysqli_query($con, $sqlLookup)) {
                throw new Exception("Gagal mengisi akun dari master_coa: " . mysqli_error($con));
            }
        }

        // Isi Cabang dari master_plant lewat kolom Plant. Plant Regional 3 memakai nama_cabang,
        // plant lain memakai deskripsi. Hanya untuk periode ini dan hanya kalau cabang masih kosong.
        $adaMp = mysqli_query($con, "SHOW TABLES LIKE 'master_plant'");
        if ($adaMp && mysqli_num_rows($adaMp) > 0) {
            $sqlPlant = "UPDATE import_ar02_reg3 a
                JOIN master_plant p ON TRIM(a.plant) = p.kode_plant
                SET a.cabang = COALESCE(NULLIF(TRIM(p.nama_cabang),''), p.deskripsi)
                WHERE a.periode_bulan = '$pbEsc' AND a.periode_tahun = '$tbEsc'
                  AND IFNULL(TRIM(a.cabang),'') = ''";
            if (!mysqli_query($con, $sqlPlant)) {
                throw new Exception("Gagal mengisi cabang dari master_plant: " . mysqli_error($con));
            }
        }

        // Profit Center = 5 digit pertama Cost Center (setara XLOOKUP(VALUE(LEFT(cost center,5)), DAT...) di Excel).
        // Hanya untuk periode ini, hanya kalau profit_center masih kosong dan 5 digit pertama berupa angka.
        $sqlPc = "UPDATE import_ar02_reg3
            SET profit_center = LEFT(TRIM(cost_center), 5)
            WHERE periode_bulan = '$pbEsc' AND periode_tahun = '$tbEsc'
              AND IFNULL(TRIM(profit_center),'') = ''
              AND LEFT(TRIM(cost_center), 5) REGEXP '^[0-9]{5}$'";
        if (!mysqli_query($con, $sqlPc)) {
            throw new Exception("Gagal mengisi profit center dari cost center: " . mysqli_error($con));
        }

        mysqli_commit($con);

    } catch (Exception $e) {
        mysqli_rollback($con);
        throw new Exception("Gagal menyimpan data: " . $e->getMessage());
    }

    if (!empty($failed_rows)) {
        error_log("Import AR02 reg3 failed rows: " . implode("; ", array_slice($failed_rows, 0, 5)));
    }
    if ($skipped_blank > 0) {
        error_log("Import AR02 reg3: $skipped_blank baris kosong dilewati.");
    }

    return $saved_count;
}

// ==========================================================
// Fungsi-fungsi untuk card "Import Master COA"
// (pola sama persis dengan fungsi *_ar02 di atas, tapi tabelnya MASTER: upsert
// berdasarkan Account Determination, bukan per periode_bulan/periode_tahun)
// ==========================================================
function normalize_header_coa($h) {
    $h = strtolower(trim((string)$h));
    $h = str_replace('.', '', $h);
    $h = preg_replace('/\s+/', ' ', $h);
    return trim($h);
}

function build_header_map_coa($headerRow) {
    $map = [];
    foreach ($headerRow as $idx => $h) {
        $norm = normalize_header_coa($h);
        if ($norm !== '' && !isset($map[$norm])) {
            $map[$norm] = $idx;
        }
    }
    return $map;
}

function cek_kolom_wajib_coa($indexMap) {
    global $WAJIB_ADA_COA;
    $hilang = [];
    foreach ($WAJIB_ADA_COA as $h) {
        if (!isset($indexMap[$h])) $hilang[] = $h;
    }
    return $hilang;
}

function extract_row_by_header_coa($dataRow, $indexMap) {
    global $HEADER_TO_FIELD_COA, $DB_COLUMNS_COA_ORDERED;
    static $fieldToHeader = null;
    if ($fieldToHeader === null) {
        $fieldToHeader = array_flip($HEADER_TO_FIELD_COA);
    }
    $out = [];
    foreach ($DB_COLUMNS_COA_ORDERED as $field) {
        $normHeader = $fieldToHeader[$field] ?? null;
        $idx = ($normHeader !== null) ? ($indexMap[$normHeader] ?? null) : null;
        $val = ($idx !== null && isset($dataRow[$idx])) ? $dataRow[$idx] : '';
        $out[] = $val;
    }
    return $out;
}

function readCoaFileByHeader($filePath, $ext) {
    if ($ext === 'csv') {
        return readCoaCSVByHeader($filePath);
    } elseif ($ext === 'xlsx') {
        return readCoaXLSXByHeader($filePath);
    } elseif ($ext === 'xls') {
        return readCoaXLSByHeader($filePath);
    }
    return [];
}

function readCoaCSVByHeader($filePath) {
    $rows = [];
    if (($handle = fopen($filePath, 'r')) !== false) {
        $first_line = fgets($handle);
        $delimiter = (strpos($first_line, "\t") !== false) ? "\t" : ((strpos($first_line, ';') !== false) ? ';' : ',');
        rewind($handle);

        $headerRow = fgetcsv($handle, 0, $delimiter);
        if ($headerRow === false) { fclose($handle); return []; }

        $map = build_header_map_coa($headerRow);
        $hilang = cek_kolom_wajib_coa($map);
        if (!empty($hilang)) {
            fclose($handle);
            throw new Exception("Kolom berikut tidak ditemukan di header file Master COA: " . implode(', ', $hilang) . ". Pastikan baris pertama file berisi nama kolom yang sesuai.");
        }

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = extract_row_by_header_coa($row, $map);
        }
        fclose($handle);
    }
    return $rows;
}

function readCoaXLSXByHeader($filePath) {
    $rows = [];
    if (!class_exists('ZipArchive')) return convertXLSXtoCSVAndParseCoaByHeader($filePath);
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) return convertXLSXtoCSVAndParseCoaByHeader($filePath);

    try {
        $sharedStrings = [];
        if (($xmlContent = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $xml = simplexml_load_string($xmlContent);
            foreach ($xml->si as $si) {
                $text = '';
                if (isset($si->t)) { $text = (string)$si->t; }
                elseif (isset($si->r)) { foreach ($si->r as $r) { if (isset($r->t)) $text .= (string)$r->t; } }
                $sharedStrings[] = $text;
            }
        }

        $xmlContent = $zip->getFromName('xl/workbook.xml');
        $xml = simplexml_load_string($xmlContent);

        $sheetIds = [];
        foreach ($xml->sheets->sheet as $sheet) {
            $sheetIds[] = (string)$sheet->attributes('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        }
        if (empty($sheetIds)) throw new Exception("Tidak ada worksheet ditemukan");

        $relsContent = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $relsXml = simplexml_load_string($relsContent);
        $relMap = [];
        foreach ($relsXml->Relationship as $rel) {
            $relMap[(string)$rel->attributes()['Id']] = 'xl/' . (string)$rel->attributes()['Target'];
        }

        $adaKolomWajibHilang = null;
        foreach ($sheetIds as $sheetId) {
            $worksheetPath = $relMap[$sheetId] ?? '';
            if (empty($worksheetPath) || !$zip->locateName($worksheetPath)) continue;

            $sheetXmlContent = $zip->getFromName($worksheetPath);
            $sheetXml = simplexml_load_string($sheetXmlContent);
            if (!$sheetXml || !isset($sheetXml->sheetData)) continue;

            $map = null;
            foreach ($sheetXml->sheetData->row as $row) {
                $sparse = [];
                foreach ($row->c as $cell) {
                    $ref = (string)($cell->attributes()['r'] ?? '');
                    $colIdx = $ref !== '' ? excel_col_to_index_ps($ref) : count($sparse);
                    $value = ''; $type = (string)($cell->attributes()['t'] ?? 'n');
                    if (isset($cell->v)) {
                        $value = (string)$cell->v;
                        if ($type === 's') { $value = $sharedStrings[(int)$value] ?? ''; }
                    }
                    $sparse[$colIdx] = $value;
                }
                if (empty($sparse)) continue;
                $maxIdx = max(array_keys($sparse));
                $cellData = [];
                for ($i = 0; $i <= $maxIdx; $i++) { $cellData[] = $sparse[$i] ?? ''; }

                if ($map === null) {
                    $map = build_header_map_coa($cellData);
                    $hilang = cek_kolom_wajib_coa($map);
                    if (!empty($hilang)) {
                        $adaKolomWajibHilang = $hilang;
                        $map = false;
                        continue;
                    }
                    continue;
                }
                if ($map === false) continue;

                $rows[] = extract_row_by_header_coa($cellData, $map);
            }
        }
        $zip->close();

        if (empty($rows) && $adaKolomWajibHilang !== null) {
            throw new Exception("Kolom berikut tidak ditemukan di header Master COA: " . implode(', ', $adaKolomWajibHilang) . ". Pastikan baris pertama sheet berisi nama kolom yang sesuai.");
        }
        return $rows;
    } catch (Exception $e) {
        $zip->close();
        if (strpos($e->getMessage(), 'Kolom berikut tidak ditemukan') !== false) {
            throw $e;
        }
        return convertXLSXtoCSVAndParseCoaByHeader($filePath);
    }
}

function convertXLSXtoCSVAndParseCoaByHeader($filePath) {
    $temp_csv = tempnam(sys_get_temp_dir(), 'xlsxcoa_') . '.csv';

    $command = "libreoffice --headless --convert-to csv:Text --outdir " .
               escapeshellarg(dirname($temp_csv)) . " " . escapeshellarg($filePath) . " 2>/dev/null";
    @shell_exec($command);

    $base_name = pathinfo($filePath, PATHINFO_FILENAME);
    $expected_csv = dirname($temp_csv) . '/' . $base_name . '.csv';

    if (!file_exists($expected_csv) && !file_exists($temp_csv)) {
        $command = "ssconvert " . escapeshellarg($filePath) . " " . escapeshellarg($temp_csv) . " 2>/dev/null";
        @shell_exec($command);
    } elseif (file_exists($expected_csv)) {
        $temp_csv = $expected_csv;
    }

    if (file_exists($temp_csv)) {
        $rows = readCoaCSVByHeader($temp_csv);
        @unlink($temp_csv);
        if (!empty($rows)) return $rows;
    }

    throw new Exception("Tidak dapat membaca file XLSX. Pastikan LibreOffice/Gnumeric terinstall, atau gunakan format CSV/XLS.");
}

function readCoaXLSByHeader($filePath) {
    $temp_csv = tempnam(sys_get_temp_dir(), 'xlscoa_') . '.csv';
    $command = "ssconvert " . escapeshellarg($filePath) . " " . escapeshellarg($temp_csv) . " 2>/dev/null";
    @shell_exec($command);

    if (file_exists($temp_csv)) {
        $rows = readCoaCSVByHeader($temp_csv);
        @unlink($temp_csv);
        if (!empty($rows)) return $rows;
    }

    throw new Exception("Tidak dapat membaca file XLS. Pastikan file valid atau gunakan format XLSX/CSV.");
}

function saveMasterCoaToDatabase($con, $importedData) {
    if (empty($importedData)) {
        return 0;
    }

    $create_table_sql = "CREATE TABLE IF NOT EXISTS master_coa (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_determination VARCHAR(20) NOT NULL,
        akun_akumulasi_penyusutan VARCHAR(20),
        ckpn VARCHAR(20),
        akun_beban_penyusutan VARCHAR(20),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        imported_by VARCHAR(20),
        UNIQUE KEY uk_coa_account_determination (account_determination)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!mysqli_query($con, $create_table_sql)) {
        throw new Exception("Gagal membuat tabel: " . mysqli_error($con));
    }

    $nipp = isset($_SESSION['nipp']) ? $_SESSION['nipp'] : 'unknown';

    global $DB_COLUMNS_COA_ORDERED;
    $column_names = $DB_COLUMNS_COA_ORDERED;

    $saved_count = 0;
    $failed_rows = [];
    $skipped_blank = 0;
    $idxAccountDeterminationCol = array_search('account_determination', $column_names);

    mysqli_begin_transaction($con);

    try {
        foreach ($importedData as $row_index => $row) {
            $accountDeterminationVal = trim((string)($row[$idxAccountDeterminationCol] ?? ''));
            if ($accountDeterminationVal === '') {
                $skipped_blank++;
                continue;
            }

            $values = [];
            foreach ($column_names as $col_idx => $col_name) {
                $value = isset($row[$col_idx]) ? $row[$col_idx] : '';
                $value = trim((string)$value);
                $values[] = "'" . mysqli_real_escape_string($con, $value) . "'";
            }
            $values[] = "'" . mysqli_real_escape_string($con, $nipp) . "'";

            $columns = implode(', ', $column_names) . ', imported_by';
            $updateParts = [];
            foreach ($column_names as $col_name) {
                if ($col_name === 'account_determination') continue; // key, jangan di-update
                $updateParts[] = "$col_name = VALUES($col_name)";
            }
            $updateParts[] = "imported_by = VALUES(imported_by)";
            $updateParts[] = "updated_at = CURRENT_TIMESTAMP";

            $insert_sql = "INSERT INTO master_coa (" . $columns . ") VALUES (" . implode(', ', $values) . ")
                ON DUPLICATE KEY UPDATE " . implode(', ', $updateParts);

            try {
                if (mysqli_query($con, $insert_sql)) {
                    $saved_count++;
                } else {
                    $failed_rows[] = "Baris " . ($row_index + 2) . ": " . mysqli_error($con);
                }
            } catch (\Throwable $eRow) {
                $failed_rows[] = "Baris " . ($row_index + 2) . ": " . $eRow->getMessage();
            }
        }

        mysqli_commit($con);

    } catch (Exception $e) {
        mysqli_rollback($con);
        throw new Exception("Gagal menyimpan data: " . $e->getMessage());
    }

    if (!empty($failed_rows)) {
        error_log("Import Master COA failed rows: " . implode("; ", array_slice($failed_rows, 0, 5)));
    }
    if ($skipped_blank > 0) {
        error_log("Import Master COA: $skipped_blank baris kosong dilewati.");
    }

    return $saved_count;
}

// ==========================================================
// Fungsi-fungsi untuk card "Import KK Aset"
// (pola sama persis dengan fungsi *_ar02 di atas, tapi tabelnya MASTER: upsert
// berdasarkan Account Determination, bukan per periode_bulan/periode_tahun)
// ==========================================================
function normalize_header_kka($h) {
    $h = strtolower(trim((string)$h));
    $h = str_replace('.', '', $h);
    $h = preg_replace('/\s+/', ' ', $h);
    return trim($h);
}

function build_header_map_kka($headerRow) {
    $map = [];
    foreach ($headerRow as $idx => $h) {
        $norm = normalize_header_kka($h);
        if ($norm !== '' && !isset($map[$norm])) {
            $map[$norm] = $idx;
        }
    }
    return $map;
}

function cek_kolom_wajib_kka($indexMap) {
    global $WAJIB_ADA_KKA;
    $hilang = [];
    foreach ($WAJIB_ADA_KKA as $h) {
        if (!isset($indexMap[$h])) $hilang[] = $h;
    }
    return $hilang;
}

function extract_row_by_header_kka($dataRow, $indexMap) {
    global $HEADER_TO_FIELD_KKA, $DB_COLUMNS_KKA_ORDERED;
    static $fieldToHeader = null;
    if ($fieldToHeader === null) {
        $fieldToHeader = array_flip($HEADER_TO_FIELD_KKA);
    }
    $out = [];
    foreach ($DB_COLUMNS_KKA_ORDERED as $field) {
        $normHeader = $fieldToHeader[$field] ?? null;
        $idx = ($normHeader !== null) ? ($indexMap[$normHeader] ?? null) : null;
        $val = ($idx !== null && isset($dataRow[$idx])) ? $dataRow[$idx] : '';
        $out[] = $val;
    }
    return $out;
}

function readKkaFileByHeader($filePath, $ext) {
    $raw = [];
    if ($ext === 'csv') {
        $raw = readKkaCSVByHeader($filePath);
    } elseif ($ext === 'xlsx') {
        $raw = readKkaXLSXByHeader($filePath);
    } elseif ($ext === 'xls') {
        $raw = readKkaXLSByHeader($filePath);
    }
    return kka_proses_baris($raw);
}

// Cari baris header (mengandung "GL Aset") di 40 baris pertama; kalau tidak ada, tebak posisi kolom dari baris data pertama
// (kolom pertama yang berisi kode 10 digit = GL Aset, lalu Akumulasi, CKPN, Beban, Deskripsi berurutan di kanannya).
// Hanya baris yang GL Aset-nya berupa kode angka (>= 6 digit) yang dipakai, jadi baris kosong, judul, dan baris nomor kolom otomatis terlewat.
function kka_proses_baris($raw) {
    $map = null; $hdrIdx = -1;
    $limit = min(count($raw), 40);
    for ($i = 0; $i < $limit; $i++) {
        $m = build_header_map_kka($raw[$i]);
        if (isset($m['gl aset'])) { $map = $m; $hdrIdx = $i; break; }
    }
    if ($map === null) {
        $g = null;
        foreach ($raw as $r) {
            foreach ($r as $ci => $cell) {
                if (preg_match('/^\d{10}$/', trim((string)perbaiki_notasi_ilmiah_ps($cell)))) { $g = $ci; break 2; }
            }
        }
        if ($g === null) {
            throw new Exception("Kolom GL Aset tidak ditemukan. Pastikan ada kolom berisi kode akun (mis. 1205010100) atau header \"GL Aset\".");
        }
        $map = ['asset class' => $g - 1, 'gl aset' => $g, 'gl akumulasi' => $g + 1, 'gl ckpn' => $g + 2,
                'gl beban penyusutan' => $g + 3, 'deskripsi gl beban penyusutan' => $g + 4];
    }
    $out = [];
    $idxGl = array_search('gl_aset', $GLOBALS['DB_COLUMNS_KKA_ORDERED']);
    foreach ($raw as $i => $r) {
        if ($i <= $hdrIdx) continue;
        foreach ($r as $ci => $cell) { $r[$ci] = perbaiki_notasi_ilmiah_ps($cell); }
        $row = extract_row_by_header_kka($r, $map);
        foreach ($row as $ci => $v) {
            $v = trim((string)$v);
            if ($ci !== 0 && $ci !== 5) { $v = preg_replace('/\.0+$/', '', $v); } // kode akun: buang ".0" sisa Excel
            $row[$ci] = $v;
        }
        if (!preg_match('/^\d{6,}$/', $row[$idxGl])) continue;
        $out[] = $row;
    }
    return $out;
}

function readKkaCSVByHeader($filePath) {
    // Baca SEMUA baris apa adanya (header bisa ada di baris mana pun / ada baris nomor kolom); disaring di kka_proses_baris()
    $rows = [];
    if (($handle = fopen($filePath, 'r')) !== false) {
        $first_line = fgets($handle);
        $delimiter = (strpos($first_line, "\t") !== false) ? "\t" : ((strpos($first_line, ';') !== false) ? ';' : ',');
        rewind($handle);
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
    }
    return $rows;
}

function readKkaXLSXByHeader($filePath) {
    $rows = [];
    if (!class_exists('ZipArchive')) return convertXLSXtoCSVAndParseKkaByHeader($filePath);
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) return convertXLSXtoCSVAndParseKkaByHeader($filePath);

    try {
        $sharedStrings = [];
        if (($xmlContent = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $xml = simplexml_load_string($xmlContent);
            foreach ($xml->si as $si) {
                $text = '';
                if (isset($si->t)) { $text = (string)$si->t; }
                elseif (isset($si->r)) { foreach ($si->r as $r) { if (isset($r->t)) $text .= (string)$r->t; } }
                $sharedStrings[] = $text;
            }
        }

        $xmlContent = $zip->getFromName('xl/workbook.xml');
        $xml = simplexml_load_string($xmlContent);

        $sheetIds = [];
        foreach ($xml->sheets->sheet as $sheet) {
            $sheetIds[] = (string)$sheet->attributes('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        }
        if (empty($sheetIds)) throw new Exception("Tidak ada worksheet ditemukan");

        $relsContent = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $relsXml = simplexml_load_string($relsContent);
        $relMap = [];
        foreach ($relsXml->Relationship as $rel) {
            $relMap[(string)$rel->attributes()['Id']] = 'xl/' . (string)$rel->attributes()['Target'];
        }

        $adaKolomWajibHilang = null;
        foreach ($sheetIds as $sheetId) {
            $worksheetPath = $relMap[$sheetId] ?? '';
            if (empty($worksheetPath) || !$zip->locateName($worksheetPath)) continue;

            $sheetXmlContent = $zip->getFromName($worksheetPath);
            $sheetXml = simplexml_load_string($sheetXmlContent);
            if (!$sheetXml || !isset($sheetXml->sheetData)) continue;

            foreach ($sheetXml->sheetData->row as $row) {
                $sparse = [];
                foreach ($row->c as $cell) {
                    $ref = (string)($cell->attributes()['r'] ?? '');
                    $colIdx = $ref !== '' ? excel_col_to_index_ps($ref) : count($sparse);
                    $value = ''; $type = (string)($cell->attributes()['t'] ?? 'n');
                    if (isset($cell->v)) {
                        $value = (string)$cell->v;
                        if ($type === 's') { $value = $sharedStrings[(int)$value] ?? ''; }
                    }
                    $sparse[$colIdx] = $value;
                }
                if (empty($sparse)) continue;
                $maxIdx = max(array_keys($sparse));
                $cellData = [];
                for ($i = 0; $i <= $maxIdx; $i++) { $cellData[] = $sparse[$i] ?? ''; }

                $rows[] = $cellData;
            }
        }
        $zip->close();

        if (empty($rows) && $adaKolomWajibHilang !== null) {
            throw new Exception("Kolom berikut tidak ditemukan di header KK Aset: " . implode(', ', $adaKolomWajibHilang) . ". Pastikan baris pertama sheet berisi nama kolom yang sesuai.");
        }
        return $rows;
    } catch (Exception $e) {
        $zip->close();
        if (strpos($e->getMessage(), 'Kolom berikut tidak ditemukan') !== false) {
            throw $e;
        }
        return convertXLSXtoCSVAndParseKkaByHeader($filePath);
    }
}

function convertXLSXtoCSVAndParseKkaByHeader($filePath) {
    $temp_csv = tempnam(sys_get_temp_dir(), 'xlsxkka_') . '.csv';

    $command = "libreoffice --headless --convert-to csv:Text --outdir " .
               escapeshellarg(dirname($temp_csv)) . " " . escapeshellarg($filePath) . " 2>/dev/null";
    @shell_exec($command);

    $base_name = pathinfo($filePath, PATHINFO_FILENAME);
    $expected_csv = dirname($temp_csv) . '/' . $base_name . '.csv';

    if (!file_exists($expected_csv) && !file_exists($temp_csv)) {
        $command = "ssconvert " . escapeshellarg($filePath) . " " . escapeshellarg($temp_csv) . " 2>/dev/null";
        @shell_exec($command);
    } elseif (file_exists($expected_csv)) {
        $temp_csv = $expected_csv;
    }

    if (file_exists($temp_csv)) {
        $rows = readKkaCSVByHeader($temp_csv);
        @unlink($temp_csv);
        if (!empty($rows)) return $rows;
    }

    throw new Exception("Tidak dapat membaca file XLSX. Pastikan LibreOffice/Gnumeric terinstall, atau gunakan format CSV/XLS.");
}

function readKkaXLSByHeader($filePath) {
    $temp_csv = tempnam(sys_get_temp_dir(), 'xlskka_') . '.csv';
    $command = "ssconvert " . escapeshellarg($filePath) . " " . escapeshellarg($temp_csv) . " 2>/dev/null";
    @shell_exec($command);

    if (file_exists($temp_csv)) {
        $rows = readKkaCSVByHeader($temp_csv);
        @unlink($temp_csv);
        if (!empty($rows)) return $rows;
    }

    throw new Exception("Tidak dapat membaca file XLS. Pastikan file valid atau gunakan format XLSX/CSV.");
}

function saveMasterKkAsetToDatabase($con, $importedData) {
    if (empty($importedData)) {
        return 0;
    }

    $create_table_sql = "CREATE TABLE IF NOT EXISTS master_kk_aset (
        id INT AUTO_INCREMENT PRIMARY KEY,
        asset_class VARCHAR(150),
        gl_aset VARCHAR(20) NOT NULL,
        gl_akumulasi VARCHAR(20),
        gl_ckpn VARCHAR(20),
        gl_beban_penyusutan VARCHAR(20),
        deskripsi_gl_beban VARCHAR(200),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        imported_by VARCHAR(20),
        UNIQUE KEY uk_kka_gl_aset (gl_aset)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!mysqli_query($con, $create_table_sql)) {
        throw new Exception("Gagal membuat tabel: " . mysqli_error($con));
    }

    // Tabel dari versi awal belum punya kolom ini -> tambahkan kalau belum ada
    $kkaCols = [];
    $rcK = mysqli_query($con, "SHOW COLUMNS FROM master_kk_aset");
    if ($rcK) { while ($rwK = mysqli_fetch_assoc($rcK)) { $kkaCols[] = $rwK['Field']; } }
    if (!in_array('asset_class', $kkaCols, true))        { mysqli_query($con, "ALTER TABLE master_kk_aset ADD COLUMN asset_class VARCHAR(150) NULL AFTER id"); }
    if (!in_array('deskripsi_gl_beban', $kkaCols, true)) { mysqli_query($con, "ALTER TABLE master_kk_aset ADD COLUMN deskripsi_gl_beban VARCHAR(200) NULL AFTER gl_beban_penyusutan"); }

    $nipp = isset($_SESSION['nipp']) ? $_SESSION['nipp'] : 'unknown';

    global $DB_COLUMNS_KKA_ORDERED;
    $column_names = $DB_COLUMNS_KKA_ORDERED;

    $saved_count = 0;
    $failed_rows = [];
    $skipped_blank = 0;
    $idxGlAsetCol = array_search('gl_aset', $column_names);

    mysqli_begin_transaction($con);

    try {
        foreach ($importedData as $row_index => $row) {
            $accountDeterminationVal = trim((string)($row[$idxGlAsetCol] ?? ''));
            if ($accountDeterminationVal === '') {
                $skipped_blank++;
                continue;
            }

            $values = [];
            foreach ($column_names as $col_idx => $col_name) {
                $value = isset($row[$col_idx]) ? $row[$col_idx] : '';
                $value = trim((string)$value);
                $values[] = "'" . mysqli_real_escape_string($con, $value) . "'";
            }
            $values[] = "'" . mysqli_real_escape_string($con, $nipp) . "'";

            $columns = implode(', ', $column_names) . ', imported_by';
            $updateParts = [];
            foreach ($column_names as $col_name) {
                if ($col_name === 'gl_aset') continue; // key, jangan di-update
                $updateParts[] = "$col_name = VALUES($col_name)";
            }
            $updateParts[] = "imported_by = VALUES(imported_by)";
            $updateParts[] = "updated_at = CURRENT_TIMESTAMP";

            $insert_sql = "INSERT INTO master_kk_aset (" . $columns . ") VALUES (" . implode(', ', $values) . ")
                ON DUPLICATE KEY UPDATE " . implode(', ', $updateParts);

            try {
                if (mysqli_query($con, $insert_sql)) {
                    $saved_count++;
                } else {
                    $failed_rows[] = "Baris " . ($row_index + 2) . ": " . mysqli_error($con);
                }
            } catch (\Throwable $eRow) {
                $failed_rows[] = "Baris " . ($row_index + 2) . ": " . $eRow->getMessage();
            }
        }

        mysqli_commit($con);

    } catch (Exception $e) {
        mysqli_rollback($con);
        throw new Exception("Gagal menyimpan data: " . $e->getMessage());
    }

    if (!empty($failed_rows)) {
        error_log("Import KK Aset failed rows: " . implode("; ", array_slice($failed_rows, 0, 5)));
    }
    if ($skipped_blank > 0) {
        error_log("Import KK Aset: $skipped_blank baris kosong dilewati.");
    }

    return $saved_count;
}

// ==========================================================
// Fungsi-fungsi untuk card "Import Data F.01"
// (pola sama persis dengan fungsi *_ar02 di atas)
// ==========================================================
function normalize_header_f01($h) {
    $h = strtolower(trim((string)$h));
    $h = str_replace('.', '', $h);
    $h = preg_replace('/\s+/', ' ', $h);
    return trim($h);
}

function build_header_map_f01($headerRow) {
    $map = [];
    foreach ($headerRow as $idx => $h) {
        $norm = normalize_header_f01($h);
        if ($norm !== '' && !isset($map[$norm])) {
            $map[$norm] = $idx;
        }
    }
    return $map;
}

function cek_kolom_wajib_f01($indexMap) {
    global $WAJIB_ADA_F01;
    $hilang = [];
    foreach ($WAJIB_ADA_F01 as $h) {
        if (!isset($indexMap[$h])) $hilang[] = $h;
    }
    return $hilang;
}

function extract_row_by_header_f01($dataRow, $indexMap) {
    global $HEADER_TO_FIELD_F01, $DB_COLUMNS_F01_ORDERED;
    static $fieldToHeader = null;
    if ($fieldToHeader === null) {
        $fieldToHeader = [];
        foreach ($HEADER_TO_FIELD_F01 as $normHeader => $field) { $fieldToHeader[$field] = $normHeader; }
    }

    $out = [];
    foreach ($DB_COLUMNS_F01_ORDERED as $field) {
        $normHeader = $fieldToHeader[$field] ?? null;
        $idx = ($normHeader !== null) ? ($indexMap[$normHeader] ?? null) : null;
        $val = ($idx !== null && isset($dataRow[$idx])) ? $dataRow[$idx] : '';
        $out[] = $val;
    }
    return $out;
}

function readF01FileByHeader($filePath, $ext) {
    if ($ext === 'csv') {
        return readF01CSVByHeader($filePath);
    } elseif ($ext === 'xlsx') {
        return readF01XLSXByHeader($filePath);
    } elseif ($ext === 'xls') {
        return readF01XLSByHeader($filePath);
    }
    return [];
}

function readF01CSVByHeader($filePath) {
    $rows = [];
    if (($handle = fopen($filePath, 'r')) !== false) {
        $first_line = fgets($handle);
        $delimiter = (strpos($first_line, "\t") !== false) ? "\t" : ((strpos($first_line, ';') !== false) ? ';' : ',');
        rewind($handle);

        $headerRow = fgetcsv($handle, 0, $delimiter);
        if ($headerRow === false) { fclose($handle); return []; }

        $map = build_header_map_f01($headerRow);
        $hilang = cek_kolom_wajib_f01($map);
        if (!empty($hilang)) {
            fclose($handle);
            throw new Exception("Kolom berikut tidak ditemukan di header file F.01: " . implode(', ', $hilang) . ". Pastikan baris pertama file berisi nama kolom yang sesuai.");
        }

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = extract_row_by_header_f01($row, $map);
        }
        fclose($handle);
    }
    return $rows;
}

function readF01XLSXByHeader($filePath) {
    $rows = [];
    if (!class_exists('ZipArchive')) return convertXLSXtoCSVAndParseF01ByHeader($filePath);
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) return convertXLSXtoCSVAndParseF01ByHeader($filePath);

    try {
        $sharedStrings = [];
        if (($xmlContent = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $xml = simplexml_load_string($xmlContent);
            foreach ($xml->si as $si) {
                $text = '';
                if (isset($si->t)) { $text = (string)$si->t; }
                elseif (isset($si->r)) { foreach ($si->r as $r) { if (isset($r->t)) $text .= (string)$r->t; } }
                $sharedStrings[] = $text;
            }
        }

        $xmlContent = $zip->getFromName('xl/workbook.xml');
        $xml = simplexml_load_string($xmlContent);

        $sheetIds = [];
        foreach ($xml->sheets->sheet as $sheet) {
            $sheetIds[] = (string)$sheet->attributes('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        }
        if (empty($sheetIds)) throw new Exception("Tidak ada worksheet ditemukan");

        $relsContent = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $relsXml = simplexml_load_string($relsContent);
        $relMap = [];
        foreach ($relsXml->Relationship as $rel) {
            $relMap[(string)$rel->attributes()['Id']] = 'xl/' . (string)$rel->attributes()['Target'];
        }

        $adaKolomWajibHilang = null;
        foreach ($sheetIds as $sheetId) {
            $worksheetPath = $relMap[$sheetId] ?? '';
            if (empty($worksheetPath) || !$zip->locateName($worksheetPath)) continue;

            $sheetXmlContent = $zip->getFromName($worksheetPath);
            $sheetXml = simplexml_load_string($sheetXmlContent);
            if (!$sheetXml || !isset($sheetXml->sheetData)) continue;

            $map = null;
            foreach ($sheetXml->sheetData->row as $row) {
                $sparse = [];
                foreach ($row->c as $cell) {
                    $ref = (string)($cell->attributes()['r'] ?? '');
                    $colIdx = $ref !== '' ? excel_col_to_index_ps($ref) : count($sparse);
                    $value = ''; $type = (string)($cell->attributes()['t'] ?? 'n');
                    if (isset($cell->v)) {
                        $value = (string)$cell->v;
                        if ($type === 's') { $value = $sharedStrings[(int)$value] ?? ''; }
                    }
                    $sparse[$colIdx] = $value;
                }
                if (empty($sparse)) continue;
                $maxIdx = max(array_keys($sparse));
                $cellData = [];
                for ($i = 0; $i <= $maxIdx; $i++) { $cellData[] = $sparse[$i] ?? ''; }

                if ($map === null) {
                    $map = build_header_map_f01($cellData);
                    $hilang = cek_kolom_wajib_f01($map);
                    if (!empty($hilang)) {
                        $adaKolomWajibHilang = $hilang;
                        $map = false;
                        continue;
                    }
                    continue;
                }
                if ($map === false) continue;

                $rows[] = extract_row_by_header_f01($cellData, $map);
            }
        }
        $zip->close();

        if (empty($rows) && $adaKolomWajibHilang !== null) {
            throw new Exception("Kolom berikut tidak ditemukan di header F.01: " . implode(', ', $adaKolomWajibHilang) . ". Pastikan baris pertama sheet berisi nama kolom yang sesuai.");
        }
        return $rows;
    } catch (Exception $e) {
        $zip->close();
        if (strpos($e->getMessage(), 'Kolom berikut tidak ditemukan') !== false) {
            throw $e;
        }
        return convertXLSXtoCSVAndParseF01ByHeader($filePath);
    }
}

function convertXLSXtoCSVAndParseF01ByHeader($filePath) {
    $temp_csv = tempnam(sys_get_temp_dir(), 'xlsxf01_') . '.csv';

    $command = "libreoffice --headless --convert-to csv:Text --outdir " .
               escapeshellarg(dirname($temp_csv)) . " " . escapeshellarg($filePath) . " 2>/dev/null";
    @shell_exec($command);

    $base_name = pathinfo($filePath, PATHINFO_FILENAME);
    $expected_csv = dirname($temp_csv) . '/' . $base_name . '.csv';

    if (!file_exists($expected_csv) && !file_exists($temp_csv)) {
        $command = "ssconvert " . escapeshellarg($filePath) . " " . escapeshellarg($temp_csv) . " 2>/dev/null";
        @shell_exec($command);
    } elseif (file_exists($expected_csv)) {
        $temp_csv = $expected_csv;
    }

    if (file_exists($temp_csv)) {
        $rows = readF01CSVByHeader($temp_csv);
        @unlink($temp_csv);
        if (!empty($rows)) return $rows;
    }

    throw new Exception("Tidak dapat membaca file XLSX. Pastikan LibreOffice/Gnumeric terinstall, atau gunakan format CSV/XLS.");
}

function readF01XLSByHeader($filePath) {
    $temp_csv = tempnam(sys_get_temp_dir(), 'xlsf01_') . '.csv';
    $command = "ssconvert " . escapeshellarg($filePath) . " " . escapeshellarg($temp_csv) . " 2>/dev/null";
    @shell_exec($command);

    if (file_exists($temp_csv)) {
        $rows = readF01CSVByHeader($temp_csv);
        @unlink($temp_csv);
        if (!empty($rows)) return $rows;
    }

    throw new Exception("Tidak dapat membaca file XLS. Pastikan file valid atau gunakan format XLSX/CSV.");
}

function saveF01ToDatabase($con, $importedData, $periodeBulan, $periodeTahun) {
    if (empty($importedData)) {
        return 0;
    }

    $create_table_sql = "CREATE TABLE IF NOT EXISTS import_data_f01 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_number VARCHAR(20),
        financial_statement_item VARCHAR(150),
        text_bs_pl_item VARCHAR(150),
        total_reporting_period VARCHAR(30),
        total_comparison_period VARCHAR(30),
        absolute_difference VARCHAR(30),
        percentage_difference VARCHAR(30),
        periode_bulan VARCHAR(20) NOT NULL,
        periode_tahun VARCHAR(4) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        imported_by VARCHAR(20),
        KEY idx_f01_account_periode (account_number, periode_tahun, periode_bulan)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!mysqli_query($con, $create_table_sql)) {
        throw new Exception("Gagal membuat tabel: " . mysqli_error($con));
    }

    // Hapus dulu data periode yang sama sebelum insert ulang, biar upload ulang untuk periode
    // yang sama TIDAK numpuk jadi data ganda.
    $pbEsc = mysqli_real_escape_string($con, $periodeBulan);
    $tbEsc = mysqli_real_escape_string($con, $periodeTahun);
    if (!mysqli_query($con, "DELETE FROM import_data_f01 WHERE periode_bulan = '$pbEsc' AND periode_tahun = '$tbEsc'")) {
        throw new Exception("Gagal menghapus data lama periode $periodeBulan/$periodeTahun: " . mysqli_error($con));
    }

    $nipp = isset($_SESSION['nipp']) ? $_SESSION['nipp'] : 'unknown';

    global $DB_COLUMNS_F01_ORDERED;
    $column_names = $DB_COLUMNS_F01_ORDERED;

    $saved_count = 0;
    $failed_rows = [];
    $skipped_blank = 0;
    $idxAccountNumberCol = array_search('account_number', $column_names);

    mysqli_begin_transaction($con);

    try {
        foreach ($importedData as $row_index => $row) {
            $accountNumberVal = trim((string)($row[$idxAccountNumberCol] ?? ''));
            if ($accountNumberVal === '') {
                $skipped_blank++;
                continue;
            }

            $values = [];
            foreach ($column_names as $col_idx => $col_name) {
                $value = isset($row[$col_idx]) ? $row[$col_idx] : '';
                if ($col_name === 'account_number') {
                    $value = trim((string)$value);
                }
                $values[] = "'" . mysqli_real_escape_string($con, $value) . "'";
            }

            $values[] = "'" . mysqli_real_escape_string($con, $periodeBulan) . "'";
            $values[] = "'" . mysqli_real_escape_string($con, $periodeTahun) . "'";
            $values[] = "'" . mysqli_real_escape_string($con, $nipp) . "'";

            $columns = implode(', ', $column_names) . ', periode_bulan, periode_tahun, imported_by';
            $insert_sql = "INSERT INTO import_data_f01 (" . $columns . ") VALUES (" . implode(', ', $values) . ")";

            if (mysqli_query($con, $insert_sql)) {
                $saved_count++;
            } else {
                $failed_rows[] = "Baris " . ($row_index + 2) . ": " . mysqli_error($con);
            }
        }

        mysqli_commit($con);

    } catch (Exception $e) {
        mysqli_rollback($con);
        throw new Exception("Gagal menyimpan data: " . $e->getMessage());
    }

    if (!empty($failed_rows)) {
        error_log("Import Data F.01 failed rows: " . implode("; ", array_slice($failed_rows, 0, 5)));
    }
    if ($skipped_blank > 0) {
        error_log("Import Data F.01: $skipped_blank baris kosong dilewati.");
    }

    return $saved_count;
}
?>
<!doctype html>
<html lang="en">
  <!--begin::Head-->
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title> Import Data Monitoring DAT-SAP - Web Aset Tetap</title>
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
    <link rel="stylesheet" href="../../dist/css/adminlte.css" />
    <!--end::Required Plugin(AdminLTE)-->

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
      /* Kartu import: tinggi sama per baris, tombol Upload selalu sejajar di dasar kartu, jarak antar kartu seragam */
      .import-row > [class*="col-"] > .card {
        height: 100%;
        margin-bottom: 0 !important;
        display: flex;
        flex-direction: column;
      }
      .import-row .card > form {
        display: flex;
        flex-direction: column;
        flex: 1 1 auto;
      }
      .import-row .card > form > .card-body { flex: 1 1 auto; }
      .import-row .card > form > .card-footer { margin-top: auto; }
    </style>
    <!-- apexcharts -->
    <link
      rel="stylesheet"
      href="../../dist/css/apexcharts.css"
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

                'Import DAT'                         => 'Administrasi Sistem', 
                'Manajemen Menu'                     => 'Administrasi Sistem', 
                'Manajemen User'                     => 'Administrasi Sistem',
            ];
            $groupIcon = [
                'Penghapusan Aset'                   => 'bi bi-file-earmark-minus', 
                'Penyusutan Aset'                    => 'bi bi-graph-down-arrow',
                'Monitoring Aset Tetap'              => 'bi bi-arrow-left-right', 
                'Administrasi Sistem'                => 'bi bi-sliders',
            ];
            $groupOrder = ['Penghapusan Aset', 'Penyusutan Aset', 'Monitoring Aset Tetap', 'Administrasi Sistem'];

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
              <div class="col-sm-6"><h3 class="mb-0">Import Data Monitoring DAT-SAP</h3></div>
              <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                  <li class="breadcrumb-item"><a href="../dasbor/dasbor.php">Home</a></li>
                  <li class="breadcrumb-item active">Import Data Monitoring DAT-SAP</li>
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
            <?php
            // Tab yang aktif: tab kartu yang barusan di-upload (supaya pesan sukses/gagal langsung kelihatan), default Mutasi Aset.
            $tabAktif = 'mutasi';
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                foreach (['file_ar02' => 'mutasi', 'file_f01' => 'mutasi', 'file_kka' => 'mutasi', 'file_excel' => 'mutasi',
                          'file_nilai_perolehan' => 'selisih', 'file_nilai_akumulasi' => 'selisih', 'file_nilai_dep' => 'selisih',
                          'file_coa' => 'master'] as $fldTab => $keyTab) {
                    if (isset($_FILES[$fldTab])) { $tabAktif = $keyTab; break; }
                }
            }
            ?>
            <!--begin::Tabs Import-->
            <ul class="nav nav-tabs mb-3" id="importTabs" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link<?php echo $tabAktif === 'mutasi' ? ' active' : ''; ?>" id="tab-mutasi" data-bs-toggle="tab" data-bs-target="#pane-mutasi" type="button" role="tab" aria-controls="pane-mutasi" aria-selected="<?php echo $tabAktif === 'mutasi' ? 'true' : 'false'; ?>">Mutasi Aset</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link<?php echo $tabAktif === 'selisih' ? ' active' : ''; ?>" id="tab-selisih" data-bs-toggle="tab" data-bs-target="#pane-selisih" type="button" role="tab" aria-controls="pane-selisih" aria-selected="<?php echo $tabAktif === 'selisih' ? 'true' : 'false'; ?>">Selisih per Cabang</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link<?php echo $tabAktif === 'master' ? ' active' : ''; ?>" id="tab-master" data-bs-toggle="tab" data-bs-target="#pane-master" type="button" role="tab" aria-controls="pane-master" aria-selected="<?php echo $tabAktif === 'master' ? 'true' : 'false'; ?>">Master COA</button>
              </li>
            </ul>

            <div class="tab-content" id="importTabContent">
              <div class="tab-pane fade<?php echo $tabAktif === 'mutasi' ? ' show active' : ''; ?>" id="pane-mutasi" role="tabpanel" aria-labelledby="tab-mutasi" tabindex="0">
                <p class="text-muted small mb-3">Sumber data laporan Mutasi Aset Tetap, Aset Tak Berwujud, dan Properti Investasi.</p>
                <div class="row g-4 mb-4 import-row">
              <!--begin::Card Import AR02 reg3-->
              <div class="col-lg-6">
                <div class="card card-primary card-outline mb-4 h-100">
                  <!--begin::Header-->
                  <div class="card-header"><div class="card-title">Import AR02 reg3</div></div>
                  <!--end::Header-->
                  <!--begin::Form-->
                  <form method="POST" enctype="multipart/form-data" id="formImportAr02" data-loading-text="Sedang memproses upload data AR02 reg3">
                    <!--begin::Body-->
                    <div class="card-body">
                      <?php if (!empty($pesanAr02)): ?>
                      <div class="alert alert-<?php echo $tipeAr02; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($pesanAr02); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                      </div>
                      <?php endif; ?>

                      <div class="row mb-3">
                        <div class="col-6">
                          <label for="periode_bulan_ar02" class="form-label">Bulan</label>
                          <select class="form-select" id="periode_bulan_ar02" name="periode_bulan_ar02" required>
                            <?php
                              $namaBulanAr02 = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
                              foreach ($namaBulanAr02 as $noBln => $nmBln) {
                                  $sel = ($noBln == (int)date('n')) ? ' selected' : '';
                                  echo '<option value="' . $noBln . '"' . $sel . '>' . $nmBln . '</option>';
                              }
                            ?>
                          </select>
                        </div>
                        <div class="col-6">
                          <label for="periode_tahun_ar02" class="form-label">Tahun</label>
                          <input type="number" class="form-control" id="periode_tahun_ar02" name="periode_tahun_ar02" min="2000" max="2100" value="<?php echo date('Y'); ?>" required>
                        </div>
                      </div>
                      <div class="mb-3">
                        <label for="file_ar02" class="form-label">Pilih File Excel atau CSV</label>
                        <input type="file" class="form-control" id="file_ar02" name="file_ar02" accept=".xls,.xlsx,.csv" required>
                        <small class="form-text text-muted">
                          Format yang didukung: <strong>(.xls, .xlsx, .csv)</strong><br>
                        </small>
                      </div>
                    </div>
                    <!--end::Body-->
                    <!--begin::Footer-->
                    <div class="card-footer">
                      <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                          <i class="bi bi-cloud-arrow-up"></i> Upload & Simpan ke Database
                        </button>
                      </div>
                    </div>
                    <!--end::Footer-->
                  </form>
                  <!--end::Form-->
                </div>
              </div>
              <!--end::Card Import AR02 reg3-->
              <!--begin::Card Import Data F.01-->
              <div class="col-lg-6">
                <div class="card card-primary card-outline mb-4 h-100">
                  <!--begin::Header-->
                  <div class="card-header"><div class="card-title">Import Data F.01</div></div>
                  <!--end::Header-->
                  <!--begin::Form-->
                  <form method="POST" enctype="multipart/form-data" id="formImportF01" data-loading-text="Sedang memproses upload data F.01.">
                    <!--begin::Body-->
                    <div class="card-body">
                      <?php if (!empty($pesanF01)): ?>
                      <div class="alert alert-<?php echo $tipeF01; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($pesanF01); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                      </div>
                      <?php endif; ?>

                      <div class="row mb-3">
                        <div class="col-6">
                          <label for="periode_bulan_f01" class="form-label">Bulan</label>
                          <select class="form-select" id="periode_bulan_f01" name="periode_bulan_f01" required><option value="1"<?php if ((int)date('n') === 1) echo ' selected'; ?>>Januari</option><option value="2"<?php if ((int)date('n') === 2) echo ' selected'; ?>>Februari</option><option value="3"<?php if ((int)date('n') === 3) echo ' selected'; ?>>Maret</option><option value="4"<?php if ((int)date('n') === 4) echo ' selected'; ?>>April</option><option value="5"<?php if ((int)date('n') === 5) echo ' selected'; ?>>Mei</option><option value="6"<?php if ((int)date('n') === 6) echo ' selected'; ?>>Juni</option><option value="7"<?php if ((int)date('n') === 7) echo ' selected'; ?>>Juli</option><option value="8"<?php if ((int)date('n') === 8) echo ' selected'; ?>>Agustus</option><option value="9"<?php if ((int)date('n') === 9) echo ' selected'; ?>>September</option><option value="10"<?php if ((int)date('n') === 10) echo ' selected'; ?>>Oktober</option><option value="11"<?php if ((int)date('n') === 11) echo ' selected'; ?>>November</option><option value="12"<?php if ((int)date('n') === 12) echo ' selected'; ?>>Desember</option></select>
                        </div>
                        <div class="col-6">
                          <label for="periode_tahun_f01" class="form-label">Tahun</label>
                          <input type="number" class="form-control" id="periode_tahun_f01" name="periode_tahun_f01" min="2000" max="2100" value="<?php echo date('Y'); ?>" required>
                        </div>
                      </div>
                      <div class="mb-3">
                        <label for="file_f01" class="form-label">Pilih File Excel atau CSV</label>
                        <input type="file" class="form-control" id="file_f01" name="file_f01" accept=".xls,.xlsx,.csv" required>
                        <small class="form-text text-muted">
                          Format yang didukung: <strong>(.xls, .xlsx, .csv)</strong><br>
                          
                        </small>
                      </div>
                    </div>
                    <!--end::Body-->
                    <!--begin::Footer-->
                    <div class="card-footer">
                      <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                          <i class="bi bi-cloud-arrow-up"></i> Upload & Simpan ke Database
                        </button>
                      </div>
                    </div>
                    <!--end::Footer-->
                  </form>
                  <!--end::Form-->
                </div>
              </div>
              <!--end::Card Import Data F.01-->
              <!--begin::Card Import KK Aset-->
              <div class="col-lg-6">
                <div class="card card-primary card-outline mb-4 h-100">
                  <!--begin::Header-->
                  <div class="card-header"><div class="card-title">Import KK Aset</div></div>
                  <!--end::Header-->
                  <!--begin::Form-->
                  <form method="POST" enctype="multipart/form-data" id="formImportKka" data-loading-text="Sedang memproses upload data KK Aset.">
                    <!--begin::Body-->
                    <div class="card-body">
                      <?php if (!empty($pesanKka)): ?>
                      <div class="alert alert-<?php echo $tipeKka; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($pesanKka); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                      </div>
                      <?php endif; ?>

                      <div class="mb-3">
                        <label for="file_kka" class="form-label">Pilih File Excel atau CSV</label>
                        <input type="file" class="form-control" id="file_kka" name="file_kka" accept=".xls,.xlsx,.csv" required>
                        <small class="form-text text-muted">
                          Format yang didukung: <strong>(.xls, .xlsx, .csv)</strong><br>
                        </small>
                      </div>
                    </div>
                    <!--end::Body-->
                    <!--begin::Footer-->
                    <div class="card-footer">
                      <div class="d-flex gap-1">
                        <button type="submit" class="btn btn-primary">
                          <i class="bi bi-cloud-arrow-up"></i> Upload & Simpan ke Database
                        </button>
                      </div>
                    </div>
                    <!--end::Footer-->
                  </form>
                  <!--end::Form-->
                </div>
              </div>
              <!--end::Card Import KK Aset-->
              <!--begin::Card Import DAT-->
              <div class="col-lg-6">
                <div class="card card-primary card-outline mb-4 h-100">
                  <!--begin::Header-->
                  <div class="card-header"><div class="card-title">Import DAT Des Close 16</div></div>
                  <!--end::Header-->
                  <!--begin::Form-->
                  <form method="POST" enctype="multipart/form-data" id="formImportDat" data-loading-text="Sedang memproses upload data DAT Des Close 16.">
                    <!--begin::Body-->
                    <div class="card-body">
                      <?php if (!empty($pesan)): ?>
                      <div class="alert alert-<?php echo $tipe_pesan; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($pesan); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                      </div>
                      <?php endif; ?>
                      
                      <div class="mb-3">
                        <label for="file_excel" class="form-label">Pilih File Excel atau CSV</label>
                        <input type="file" class="form-control" id="file_excel" name="file_excel" accept=".xls,.xlsx,.csv" required>
                        <small class="form-text text-muted">
                          Format yang didukung: <strong>(.xls, .xlsx, .csv)</strong>
                        </small>
                      </div>
                    </div>
                    <!--end::Body-->
                    <!--begin::Footer-->
                    <div class="card-footer">
                      <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                          <i class="bi bi-cloud-arrow-up"></i> Upload & Simpan ke Database
                        </button>
                      </div>
                    </div>
                    <!--end::Footer-->
                  </form>
                  <!--end::Form-->
                </div>
              </div>
              <!--end::Card Import DAT-->
                </div>
              </div>
              <div class="tab-pane fade<?php echo $tabAktif === 'selisih' ? ' show active' : ''; ?>" id="pane-selisih" role="tabpanel" aria-labelledby="tab-selisih" tabindex="0">
                <p class="text-muted small mb-3">Sumber data dasbor Selisih per Cabang. Format file sama; upload per bulan.</p>
                <div class="row g-4 mb-4 import-row">
              <!--begin::Card Import Nilai Perolehan-->
              <div class="col-xl-4 col-lg-6">
                <div class="card card-primary card-outline mb-4 h-100">
                  <div class="card-header"><div class="card-title">Import Nilai Perolehan</div></div>
                  <form method="POST" enctype="multipart/form-data" id="formImportNilaiPerolehan" data-loading-text="Sedang memproses upload data Nilai Perolehan.">
                    <div class="card-body">
                      <?php if (!empty($pesanNilai['nilai_perolehan'])): ?>
                      <div class="alert alert-<?php echo $tipeNilai['nilai_perolehan']; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($pesanNilai['nilai_perolehan']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                      </div>
                      <?php endif; ?>
                      <div class="row mb-3">
                        <div class="col-6">
                          <label for="periode_bulan_nilai_perolehan" class="form-label">Bulan</label>
                          <select class="form-select" id="periode_bulan_nilai_perolehan" name="periode_bulan_nilai_perolehan" required><option value="1"<?php if ((int)date('n') === 1) echo ' selected'; ?>>Januari</option><option value="2"<?php if ((int)date('n') === 2) echo ' selected'; ?>>Februari</option><option value="3"<?php if ((int)date('n') === 3) echo ' selected'; ?>>Maret</option><option value="4"<?php if ((int)date('n') === 4) echo ' selected'; ?>>April</option><option value="5"<?php if ((int)date('n') === 5) echo ' selected'; ?>>Mei</option><option value="6"<?php if ((int)date('n') === 6) echo ' selected'; ?>>Juni</option><option value="7"<?php if ((int)date('n') === 7) echo ' selected'; ?>>Juli</option><option value="8"<?php if ((int)date('n') === 8) echo ' selected'; ?>>Agustus</option><option value="9"<?php if ((int)date('n') === 9) echo ' selected'; ?>>September</option><option value="10"<?php if ((int)date('n') === 10) echo ' selected'; ?>>Oktober</option><option value="11"<?php if ((int)date('n') === 11) echo ' selected'; ?>>November</option><option value="12"<?php if ((int)date('n') === 12) echo ' selected'; ?>>Desember</option></select>
                        </div>
                        <div class="col-6">
                          <label for="periode_tahun_nilai_perolehan" class="form-label">Tahun</label>
                          <input type="number" class="form-control" id="periode_tahun_nilai_perolehan" name="periode_tahun_nilai_perolehan" min="2000" max="2100" value="<?php echo date('Y'); ?>" required>
                        </div>
                      </div>
                      <div class="mb-3">
                        <label for="file_nilai_perolehan" class="form-label">Pilih File Excel atau CSV</label>
                        <input type="file" class="form-control" id="file_nilai_perolehan" name="file_nilai_perolehan" accept=".xls,.xlsx,.csv" required>
                        <small class="form-text text-muted">
                          Format yang didukung: <strong>(.xls, .xlsx, .csv)</strong><br>
                          <!-- Header: Cost Center, Asset, Asset Subnumber, Account, Document Number, Posting Date, Amount in Local Currency, Profit Center, Text.<br>
                          Upload ulang untuk bulan/tahun yang sama menimpa data lama. -->
                        </small>
                      </div>
                    </div>
                    <div class="card-footer">
                      <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-cloud-arrow-up"></i> Upload &amp; Simpan ke Database</button>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
              <!--end::Card Import Nilai Perolehan-->
              <!--begin::Card Import Nilai Akumulasi-->
              <div class="col-xl-4 col-lg-6">
                <div class="card card-primary card-outline mb-4 h-100">
                  <div class="card-header"><div class="card-title">Import Nilai Akumulasi</div></div>
                  <form method="POST" enctype="multipart/form-data" id="formImportNilaiAkumulasi" data-loading-text="Sedang memproses upload data Nilai Akumulasi.">
                    <div class="card-body">
                      <?php if (!empty($pesanNilai['nilai_akumulasi'])): ?>
                      <div class="alert alert-<?php echo $tipeNilai['nilai_akumulasi']; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($pesanNilai['nilai_akumulasi']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                      </div>
                      <?php endif; ?>
                      <div class="row mb-3">
                        <div class="col-6">
                          <label for="periode_bulan_nilai_akumulasi" class="form-label">Bulan</label>
                          <select class="form-select" id="periode_bulan_nilai_akumulasi" name="periode_bulan_nilai_akumulasi" required><option value="1"<?php if ((int)date('n') === 1) echo ' selected'; ?>>Januari</option><option value="2"<?php if ((int)date('n') === 2) echo ' selected'; ?>>Februari</option><option value="3"<?php if ((int)date('n') === 3) echo ' selected'; ?>>Maret</option><option value="4"<?php if ((int)date('n') === 4) echo ' selected'; ?>>April</option><option value="5"<?php if ((int)date('n') === 5) echo ' selected'; ?>>Mei</option><option value="6"<?php if ((int)date('n') === 6) echo ' selected'; ?>>Juni</option><option value="7"<?php if ((int)date('n') === 7) echo ' selected'; ?>>Juli</option><option value="8"<?php if ((int)date('n') === 8) echo ' selected'; ?>>Agustus</option><option value="9"<?php if ((int)date('n') === 9) echo ' selected'; ?>>September</option><option value="10"<?php if ((int)date('n') === 10) echo ' selected'; ?>>Oktober</option><option value="11"<?php if ((int)date('n') === 11) echo ' selected'; ?>>November</option><option value="12"<?php if ((int)date('n') === 12) echo ' selected'; ?>>Desember</option></select>
                        </div>
                        <div class="col-6">
                          <label for="periode_tahun_nilai_akumulasi" class="form-label">Tahun</label>
                          <input type="number" class="form-control" id="periode_tahun_nilai_akumulasi" name="periode_tahun_nilai_akumulasi" min="2000" max="2100" value="<?php echo date('Y'); ?>" required>
                        </div>
                      </div>
                      <div class="mb-3">
                        <label for="file_nilai_akumulasi" class="form-label">Pilih File Excel atau CSV</label>
                        <input type="file" class="form-control" id="file_nilai_akumulasi" name="file_nilai_akumulasi" accept=".xls,.xlsx,.csv" required>
                        <small class="form-text text-muted">
                          Format yang didukung: <strong>(.xls, .xlsx, .csv)</strong><br>
                          <!-- Header: Cost Center, Asset, Asset Subnumber, Account, Document Number, Posting Date, Amount in Local Currency, Profit Center, Text.<br>
                          Upload ulang untuk bulan/tahun yang sama menimpa data lama. -->
                        </small>
                      </div>
                    </div>
                    <div class="card-footer">
                      <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-cloud-arrow-up"></i> Upload &amp; Simpan ke Database</button>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
              <!--end::Card Import Nilai Akumulasi-->
              <!--begin::Card Import Nilai DEP-->
              <div class="col-xl-4 col-lg-6">
                <div class="card card-primary card-outline mb-4 h-100">
                  <div class="card-header"><div class="card-title">Import Nilai DEP</div></div>
                  <form method="POST" enctype="multipart/form-data" id="formImportNilaiDep" data-loading-text="Sedang memproses upload data Nilai DEP.">
                    <div class="card-body">
                      <?php if (!empty($pesanNilai['nilai_dep'])): ?>
                      <div class="alert alert-<?php echo $tipeNilai['nilai_dep']; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($pesanNilai['nilai_dep']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                      </div>
                      <?php endif; ?>
                      <div class="row mb-3">
                        <div class="col-6">
                          <label for="periode_bulan_nilai_dep" class="form-label">Bulan</label>
                          <select class="form-select" id="periode_bulan_nilai_dep" name="periode_bulan_nilai_dep" required><option value="1"<?php if ((int)date('n') === 1) echo ' selected'; ?>>Januari</option><option value="2"<?php if ((int)date('n') === 2) echo ' selected'; ?>>Februari</option><option value="3"<?php if ((int)date('n') === 3) echo ' selected'; ?>>Maret</option><option value="4"<?php if ((int)date('n') === 4) echo ' selected'; ?>>April</option><option value="5"<?php if ((int)date('n') === 5) echo ' selected'; ?>>Mei</option><option value="6"<?php if ((int)date('n') === 6) echo ' selected'; ?>>Juni</option><option value="7"<?php if ((int)date('n') === 7) echo ' selected'; ?>>Juli</option><option value="8"<?php if ((int)date('n') === 8) echo ' selected'; ?>>Agustus</option><option value="9"<?php if ((int)date('n') === 9) echo ' selected'; ?>>September</option><option value="10"<?php if ((int)date('n') === 10) echo ' selected'; ?>>Oktober</option><option value="11"<?php if ((int)date('n') === 11) echo ' selected'; ?>>November</option><option value="12"<?php if ((int)date('n') === 12) echo ' selected'; ?>>Desember</option></select>
                        </div>
                        <div class="col-6">
                          <label for="periode_tahun_nilai_dep" class="form-label">Tahun</label>
                          <input type="number" class="form-control" id="periode_tahun_nilai_dep" name="periode_tahun_nilai_dep" min="2000" max="2100" value="<?php echo date('Y'); ?>" required>
                        </div>
                      </div>
                      <div class="mb-3">
                        <label for="file_nilai_dep" class="form-label">Pilih File Excel atau CSV</label>
                        <input type="file" class="form-control" id="file_nilai_dep" name="file_nilai_dep" accept=".xls,.xlsx,.csv" required>
                        <small class="form-text text-muted">
                          Format yang didukung: <strong>(.xls, .xlsx, .csv)</strong><br>
                          <!-- Header: Cost Center, Asset, Asset Subnumber, Account, Document Number, Posting Date, Amount in Local Currency, Profit Center, Text.<br>
                          Upload ulang untuk bulan/tahun yang sama menimpa data lama. -->
                        </small>
                      </div>
                    </div>
                    <div class="card-footer">
                      <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-cloud-arrow-up"></i> Upload &amp; Simpan ke Database</button>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
              <!--end::Card Import Nilai DEP-->
                </div>
              </div>
              <div class="tab-pane fade<?php echo $tabAktif === 'master' ? ' show active' : ''; ?>" id="pane-master" role="tabpanel" aria-labelledby="tab-master" tabindex="0">
                <p class="text-muted small mb-3">Data referensi, jarang berubah.</p>
                <div class="row g-4 mb-4 import-row">
              <!--begin::Card Import Master COA-->
              <div class="col-lg-6">
                <div class="card card-primary card-outline mb-4 h-100">
                  <!--begin::Header-->
                  <div class="card-header"><div class="card-title">Import Master COA</div></div>
                  <!--end::Header-->
                  <!--begin::Form-->
                  <form method="POST" enctype="multipart/form-data" id="formImportCoa" data-loading-text="Sedang memproses upload data Master COA.">
                    <!--begin::Body-->
                    <div class="card-body">
                      <?php if (!empty($pesanCoa)): ?>
                      <div class="alert alert-<?php echo $tipeCoa; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($pesanCoa); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                      </div>
                      <?php endif; ?>

                      <div class="mb-3">
                        <label for="file_coa" class="form-label">Pilih File Excel atau CSV</label>
                        <input type="file" class="form-control" id="file_coa" name="file_coa" accept=".xls,.xlsx,.csv" required>
                        <small class="form-text text-muted">
                          Format yang didukung: <strong>(.xls, .xlsx, .csv)</strong><br>
                          <!-- Upload ulang untuk bulan/tahun yang sama menimpa data lama. -->
                        </small>
                      </div>
                    </div>
                    <!--end::Body-->
                    <!--begin::Footer-->
                    <div class="card-footer">
                      <div class="d-flex gap-1">
                        <button type="submit" class="btn btn-primary">
                          <i class="bi bi-cloud-arrow-up"></i> Upload & Simpan ke Database
                        </button>
                      </div>
                    </div>
                    <!--end::Footer-->
                  </form>
                  <!--end::Form-->
                </div>
              </div>
              <!--end::Card Import Master COA-->
                </div>
              </div>
            </div>
            <!--end::Tabs Import-->
          </div>
        </div>
        <!--end::App Content-->
      </main>
      <!--end::App Main-->
      <!--begin::Footer-->
      <footer class="app-footer">
        <!--begin::To the end-->
        <div class="float-end d-none d-sm-inline">PT Pelabuhan Indoensia (Persero)</div>
        <!--end::To the end-->
        <!--begin::Copyright-->
        <strong>
          Copyright &copy; Proyek Aset Tetap Regional&nbsp;
        </strong>
        <!--end::Copyright-->
      </footer>
      <!--end::Footer-->
    </div>
    <!--end::App Wrapper-->
    <!--begin::Script-->
    <!--begin::Third Party Plugin(OverlayScrollbars)-->
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
      });
    </script>
    <!--end::OverlayScrollbars Configure-->

    <!-- Loading Modal saat proses upload/import file (Bootstrap) -->
    <div class="modal fade" id="uploadLoadingModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-body text-center py-4">
            <div class="spinner-border text-primary mb-3" role="status" style="width:3rem;height:3rem;">
              <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mb-0 fw-semibold" id="uploadLoadingModalText">Sedang memproses upload...</p>
            <small class="text-muted">Mohon tunggu, jangan tutup atau refresh halaman ini.</small>
          </div>
        </div>
      </div>
    </div>

    <!-- Confirmation Modal (Bootstrap) -->
    <div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header align-items-center">
            <h5 class="modal-title d-flex align-items-center" id="confirmModalTitle">
              <span id="confirmModalIcon" class="me-2"></span>
              <span id="confirmModalTitleText"></span>
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body" id="confirmModalBody"></div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="confirmModalCancelBtn" data-bs-dismiss="modal">Batal</button>
            <button type="button" class="btn btn-primary" id="confirmModalConfirmBtn">Ya</button>
          </div>
        </div>
      </div>
    </div>

    <!-- OPTIONAL SCRIPTS -->
    <!-- apexcharts -->
    <script
      src="../../dist/js/apexcharts.min.js"
      integrity="sha256-+vh8GkaU7C9/wbSLIcwq82tQ2wTf44aOHA8HlBMwRI8="
      crossorigin="anonymous"
    ></script>
    <script>
      function showConfirmModal(title, message, options = {}) {
        return new Promise((resolve) => {
          const modalEl = document.getElementById('confirmModal');
          const modalTitleText = document.getElementById('confirmModalTitleText');
          const modalBody = document.getElementById('confirmModalBody');
          const modalIcon = document.getElementById('confirmModalIcon');
          const confirmBtn = document.getElementById('confirmModalConfirmBtn');
          const cancelBtn = document.getElementById('confirmModalCancelBtn');

          const variant = options.variant || 'primary';
          const confirmText = options.confirmText || 'Ya';
          const cancelText = options.cancelText || 'Batal';
          const showCancel = (options.showCancel !== false);

          // Set content
          modalTitleText.textContent = title;
          modalBody.textContent = message;
          confirmBtn.textContent = confirmText;
          cancelBtn.textContent = cancelText;
          cancelBtn.style.display = showCancel ? '' : 'none';

          // set icon / color classes
          let iconHtml = '';
          confirmBtn.className = 'btn ' + (variant === 'danger' ? 'btn-danger' : variant === 'success' ? 'btn-success' : variant === 'warning' ? 'btn-warning' : 'btn-primary');

          if (variant === 'danger') {
            iconHtml = '<i class="bi bi-trash-fill text-danger"></i>';
          } else if (variant === 'success') {
            iconHtml = '<i class="bi bi-check-circle-fill text-success"></i>';
          } else if (variant === 'warning') {
            iconHtml = '<i class="bi bi-exclamation-triangle-fill text-warning"></i>';
          } else {
            iconHtml = '<i class="bi bi-question-circle-fill text-primary"></i>';
          }
          modalIcon.innerHTML = iconHtml;

          const bsModal = new bootstrap.Modal(modalEl, { backdrop: 'static', keyboard: false });

          const confirmHandler = () => {
            cleanup();
            resolve(true);
          };
          const cancelHandler = () => {
            cleanup();
            resolve(false);
          };
          const hideHandler = () => {
            cleanup();
            resolve(false);
          };
          function cleanup() {
            confirmBtn.removeEventListener('click', confirmHandler);
            cancelBtn.removeEventListener('click', cancelHandler);
            modalEl.removeEventListener('hidden.bs.modal', hideHandler);
            try { bsModal.hide(); } catch (e) {}
          }

          confirmBtn.addEventListener('click', confirmHandler);
          cancelBtn.addEventListener('click', cancelHandler);
          modalEl.addEventListener('hidden.bs.modal', hideHandler);

          bsModal.show();
        });
      }

      function showAlertModal(title, message, variant = 'info') {
        return showConfirmModal(title, message, { variant: variant, showCancel: false, confirmText: 'OK' });
      }

      (function () {
        const uploadForms = [
          document.getElementById('formImportDat'),
          document.getElementById('formImportF01'),
          document.getElementById('formImportNilaiPerolehan'),
          document.getElementById('formImportNilaiAkumulasi'),
          document.getElementById('formImportNilaiDep'),
          document.getElementById('formImportKka'),
          document.getElementById('formImportAr02'),
        ];

        uploadForms.forEach(function (form) {
          if (!form) return;
          form.addEventListener('submit', function (e) {
            const fileInput = form.querySelector('input[type="file"]');
            if (fileInput && fileInput.files.length === 0) {
              return;
            }

            const loadingText = form.dataset.loadingText || 'Sedang memproses upload...';
            document.getElementById('uploadLoadingModalText').textContent = loadingText;

            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;

            const loadingModal = new bootstrap.Modal(document.getElementById('uploadLoadingModal'), {
              backdrop: 'static'
            });
            loadingModal.show();
          });
        });
      })();
    </script>
    <!--end::Script-->
  </body>
  <!--end::Body-->
</html>