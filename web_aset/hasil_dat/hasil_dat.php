<?php
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

// Initialize variables
$importedData = [];
$pesan = "";
$tipe_pesan = "";
$saved_count = 0;

// Handle save to database
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_data') {
    // Validate CSRF (simple check)
    if (!isset($_POST['data']) || empty($_POST['data'])) {
        $pesan = "Tidak ada data untuk disimpan";
        $tipe_pesan = "danger";
    } else {
        try {
            $data_json = $_POST['data'];
            $importedData = json_decode($data_json, true);
            
            if (is_null($importedData)) {
                throw new Exception("Data tidak valid");
            }
            
            // Save to database
            $saved_count = saveDataToDatabase($con, $importedData);
            
            if ($saved_count > 0) {
                $pesan = "✅ Berhasil menyimpan " . $saved_count . " baris data ke database";
                $tipe_pesan = "success";
                // Clear the imported data after saving
                $importedData = [];
            } else {
                $pesan = "Gagal menyimpan data ke database";
                $tipe_pesan = "danger";
            }
        } catch (Exception $e) {
            $pesan = "Error: " . $e->getMessage();
            $tipe_pesan = "danger";
        }
    }
}

// Handle file upload
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
    // Cek ukuran file (max 5MB)
    else if ($file_size > 5 * 1024 * 1024) {
        $pesan = "Ukuran file terlalu besar. Maksimal 5MB";
        $tipe_pesan = "danger";
    }
    // Cek error upload
    else if ($file['error'] !== UPLOAD_ERR_OK) {
        $pesan = "Terjadi kesalahan saat upload file";
        $tipe_pesan = "danger";
    }
    else {
        // Proses file Excel
        try {
            $importedData = readExcelFile($file['tmp_name'], $file_ext);
            
            if (empty($importedData)) {
                $pesan = "File tidak memiliki data untuk diimport";
                $tipe_pesan = "warning";
            } else {
                $pesan = "File berhasil diimport! Total " . count($importedData) . " baris data";
                $tipe_pesan = "success";
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

/**
 * Fungsi untuk membaca file Excel
 * Mendukung format: .xlsx, .xls, .csv (semicolon delimiter)
 */
function readExcelFile($filePath, $ext) {
    $rows = [];
    
    try {
        if ($ext === 'csv') {
            // Baca CSV dengan semicolon delimiter
            if (($handle = fopen($filePath, 'r')) !== false) {
                // Detect delimiter
                $first_line = fgets($handle);
                $delimiter = (strpos($first_line, ';') !== false) ? ';' : ',';
                rewind($handle);
                
                // Skip header
                fgetcsv($handle, 0, $delimiter);
                
                while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                    // Pad ke 44 kolom untuk DAT-des.csv
                    while (count($row) < 44) {
                        $row[] = '';
                    }
                    $rows[] = array_slice($row, 0, 44);
                }
                fclose($handle);
            }
        } 
        else if ($ext === 'xlsx') {
            // Baca XLSX menggunakan ZipArchive atau fallback
            $rows = readXLSXFile($filePath);
        }
        else if ($ext === 'xls') {
            // Untuk XLS, coba berbagai metode
            $rows = readXLSFile($filePath);
        }
    } catch (Exception $e) {
        throw new Exception($e->getMessage());
    }
    
    return $rows;
}

/**
 * Membaca file XLSX
 */
function readXLSXFile($filePath) {
    $rows = [];
    
    // Check if ZipArchive is available
    if (!class_exists('ZipArchive')) {
        // Fallback: Try to convert XLSX to CSV using system command
        return convertXLSXtoCSVAndParse($filePath);
    }
    
    $zip = new ZipArchive();
    
    if ($zip->open($filePath) !== true) {
        // If ZipArchive fails, try fallback
        return convertXLSXtoCSVAndParse($filePath);
    }
    
    try {
        // Parse shared strings
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
        
        // Parse workbook.xml untuk mendapatkan sheet
        $xmlContent = $zip->getFromName('xl/workbook.xml');
        $xml = simplexml_load_string($xmlContent);
        
        // Ambil sheet ID pertama
        $sheetId = null;
        foreach ($xml->sheets->sheet as $sheet) {
            $sheetId = (string)$sheet->attributes('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            break;
        }
        
        if (!$sheetId) {
            throw new Exception("Tidak ada worksheet ditemukan");
        }
        
        // Parse workbook.xml.rels untuk mendapatkan path file worksheet
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
        
        // Parse worksheet
        $xmlContent = $zip->getFromName($worksheetPath);
        $xml = simplexml_load_string($xmlContent);
        
        $firstRow = true;
        foreach ($xml->sheetData->row as $row) {
            // Skip header row
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
                    
                    // Handle shared strings
                    if ($type === 's') {
                        $value = $sharedStrings[(int)$value] ?? '';
                    }
                }
                
                $cellData[] = $value;
            }
            
            if (!empty($cellData)) {
                // Pad dengan kolom kosong hingga 44 kolom
                while (count($cellData) < 44) {
                    $cellData[] = '';
                }
                $rows[] = array_slice($cellData, 0, 44);
            }
        }
        
        $zip->close();
        return $rows;
    } catch (Exception $e) {
        // If ZipArchive parsing fails, try fallback
        $zip->close();
        return convertXLSXtoCSVAndParse($filePath);
    }
}

/**
 * Fallback: Convert XLSX to CSV using system command
 */
function convertXLSXtoCSVAndParse($filePath) {
    $temp_csv = tempnam(sys_get_temp_dir(), 'xlsx_') . '.csv';
    $rows = [];
    
    // Try libreoffice first
    $command = "libreoffice --headless --convert-to csv:Text --outdir " . 
               escapeshellarg(dirname($temp_csv)) . " " . escapeshellarg($filePath) . " 2>/dev/null";
    @shell_exec($command);
    
    // Check if conversion was successful
    $base_name = pathinfo($filePath, PATHINFO_FILENAME);
    $expected_csv = dirname($temp_csv) . '/' . $base_name . '.csv';
    
    if (!file_exists($expected_csv) && !file_exists($temp_csv)) {
        // Try ssconvert
        $command = "ssconvert " . escapeshellarg($filePath) . " " . escapeshellarg($temp_csv) . " 2>/dev/null";
        @shell_exec($command);
    } else if (file_exists($expected_csv)) {
        $temp_csv = $expected_csv;
    }
    
    // Read the CSV file
    if (file_exists($temp_csv)) {
        if (($handle = fopen($temp_csv, 'r')) !== false) {
            // Detect delimiter
            $first_line = fgets($handle);
            $delimiter = (strpos($first_line, ';') !== false) ? ';' : ',';
            rewind($handle);
            
            // Skip header
            fgetcsv($handle, 0, $delimiter);
            
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                // Pad ke 44 kolom
                while (count($row) < 44) {
                    $row[] = '';
                }
                $rows[] = array_slice($row, 0, 44);
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

/**
 * Membaca file XLS (Excel 97-2003)
 */
function readXLSFile($filePath) {
    $rows = [];
    
    // Fallback: Coba konversi ke CSV menggunakan system command
    $temp_csv = tempnam(sys_get_temp_dir(), 'xls_') . '.csv';
    
    // Coba menggunakan ssconvert (bagian dari gnumeric)
    $command = "ssconvert " . escapeshellarg($filePath) . " " . escapeshellarg($temp_csv) . " 2>/dev/null";
    @shell_exec($command);
    
    if (file_exists($temp_csv)) {
        if (($handle = fopen($temp_csv, 'r')) !== false) {
            // Skip header
            fgetcsv($handle, 1000, ',');
            
            while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                // Pad ke 44 kolom untuk DAT-des.csv
                while (count($row) < 44) {
                    $row[] = '';
                }
                $rows[] = array_slice($row, 0, 44);
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

/**
 * Fungsi untuk menyimpan data ke database
 * Membuat tabel import_dat jika belum ada
 */
function saveDataToDatabase($con, $importedData) {
    if (empty($importedData)) {
        return 0;
    }
    
    // Create table if not exists
    $create_table_sql = "CREATE TABLE IF NOT EXISTS import_dat (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nomor_asset_utama VARCHAR(50),
        subreg varchar(50),
        profit_center VARCHAR(20),
        profit_center_text VARCHAR(100),
        cost_center_baru VARCHAR(20),
        deskripsi_cost_center VARCHAR(200),
        nama_cabang_kawasan VARCHAR(100),
        kode_plant VARCHAR(20),
        periode_bulan VARCHAR(20),
        tahun_buku VARCHAR(4),
        nomor_asset_asal VARCHAR(50),
        nomor_asset VARCHAR(50),
        sub_number VARCHAR(50),
        nomor_asset_sub_num VARCHAR(50),
        gl_account VARCHAR(20),
        asset_class VARCHAR(20),
        asset_class_name VARCHAR(100),
        kelompok_aset VARCHAR(200),
        status_aset VARCHAR(100),
        asset_main_no_text VARCHAR(100),
        akuisisi VARCHAR(20),
        keterangan_asset TEXT,
        tgl_akuisisi VARCHAR(20),
        tgl_perolehan VARCHAR(20),
        tgl_penyusutan VARCHAR(20),
        masa_manfaat VARCHAR(20),
        sisa_manfaat VARCHAR(20),
        nilai_perolehan_awal VARCHAR(50),
        nilai_residu_persen VARCHAR(20),
        nilai_residu_rp VARCHAR(50),
        nilai_perolehan_sd BIGINT,
        adjusment_nilai_perolehan VARCHAR(50),
        nilai_buku_awal VARCHAR(50),
        nilai_buku_sd BIGINT,
        penyusutan_bulan VARCHAR(50),
        penyusutan_sd VARCHAR(50),
        penyusutan_tahun_lalu VARCHAR(50),
        penyusutan_tahun VARCHAR(50),
        akm_penyusutan_tahun_lalu VARCHAR(50),
        adjusment_akm_penyusutan VARCHAR(50),
        penghapusan VARCHAR(20),
        asset_shutdown VARCHAR(20),
        akumulasi_penyusutan BIGINT,
        additional_description VARCHAR(200),
        serial_number VARCHAR(25),
        alamat VARCHAR(500),
        gl_account_exp VARCHAR(25),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        imported_by VARCHAR(20),
        UNIQUE KEY uk_nomor_asset (nomor_asset_utama)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if (!mysqli_query($con, $create_table_sql)) {
        throw new Exception("Gagal membuat tabel: " . mysqli_error($con));
    }
    
    // Hapus data lama sebelum memasukkan data baru
    $truncate_sql = "TRUNCATE TABLE import_dat";
    if (!mysqli_query($con, $truncate_sql)) {
        throw new Exception("Gagal menghapus data lama: " . mysqli_error($con));
    }
    
    // Get current user
    $nipp = isset($_SESSION['nipp']) ? $_SESSION['nipp'] : 'unknown';
    
    // Prepare column names
    $column_names = [
        'nomor_asset_utama',
        'subreg',
        'profit_center',
        'profit_center_text',
        'cost_center_baru',
        'deskripsi_cost_center',
        'nama_cabang_kawasan',
        'kode_plant',
        'periode_bulan',
        'tahun_buku',
        'nomor_asset_asal',
        'nomor_asset',
        'sub_number',
        'nomor_asset_sub_num',
        'gl_account',
        'asset_class',
        'asset_class_name',
        'kelompok_aset',
        'status_aset',
        'asset_main_no_text',
        'akuisisi',
        'keterangan_asset',
        'tgl_akuisisi',
        'tgl_perolehan',
        'tgl_penyusutan',
        'masa_manfaat',
        'sisa_manfaat',
        'nilai_perolehan_awal',
        'nilai_residu_persen',
        'nilai_residu_rp',
        'nilai_perolehan_sd',
        'adjusment_nilai_perolehan',
        'nilai_buku_awal',
        'nilai_buku_sd',
        'penyusutan_bulan',
        'penyusutan_sd',
        'penyusutan_tahun_lalu',
        'penyusutan_tahun',
        'akm_penyusutan_tahun_lalu',
        'adjusment_akm_penyusutan',
        'penghapusan',
        'asset_shutdown',
        'akumulasi_penyusutan',
        'additional_description',
        'serial_number',
        'alamat',
        'gl_account_exp'
    ];
    
    $saved_count = 0;
    $failed_rows = [];
    
    // Begin transaction for data integrity
    mysqli_begin_transaction($con);
    
    try {
        foreach ($importedData as $row_index => $row) {
            // Prepare values
            $values = [];
            foreach ($column_names as $col_idx => $col_name) {
                $value = isset($row[$col_idx]) ? $row[$col_idx] : '';
                $values[] = "'" . mysqli_real_escape_string($con, $value) . "'";
            }
            
            // Add imported_by
            $values[] = "'" . mysqli_real_escape_string($con, $nipp) . "'";
            
            // Build insert query
            $columns = implode(', ', $column_names) . ', imported_by';
            $insert_sql = "INSERT INTO import_dat (" . $columns . ") VALUES (" . implode(', ', $values) . ")";
            
            if (mysqli_query($con, $insert_sql)) {
                $saved_count++;
            } else {
                // Check if error is duplicate entry
                $error = mysqli_error($con);
                if (strpos($error, 'Duplicate entry') !== false) {
                    $failed_rows[] = "Baris " . ($row_index + 2) . ": Asset sudah ada di database";
                } else {
                    $failed_rows[] = "Baris " . ($row_index + 2) . ": " . $error;
                }
            }
        }
        
        // Commit transaction
        mysqli_commit($con);
        
    } catch (Exception $e) {
        // Rollback on error
        mysqli_rollback($con);
        throw new Exception("Gagal menyimpan data: " . $e->getMessage());
    }
    
    // Log failed rows (optional)
    if (!empty($failed_rows)) {
        error_log("Import failed rows: " . implode("; ", array_slice($failed_rows, 0, 5)));
    }
    
    return $saved_count;
}
?>
<!doctype html>
<html lang="en">
  <!--begin::Head-->
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Import DAT - Web Aset Tetap</title>
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
            <!--end::Navbar Search-->
            <!--begin::Messages Dropdown Menu-->
            <!--end::Notifications Dropdown Menu-->
            <!--begin::Fullscreen Toggle-->
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
              <div class="col-sm-6"><h3 class="mb-0">Hasil Import DAT</h3></div>
              <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                  <li class="breadcrumb-item"><a href="../dasbor/dasbor.php">Home</a></li>
                  <li class="breadcrumb-item active">Daftar Aset Tetap</li>
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
            <!--begin::Row-->
                  <!--begin::Header-->
                  <!--end::Header-->
                  <!--begin::Form-->
                  <!--begin::Header-->
                  <div class="row">
                    <div class="card card-outline mb-4">
                     <div class="card-header">
                    <div class="row w-100">
                      <div class="col-md-8">
                        <h3 class="card-title">Hasil Import Data (Loading Data Est 1 Menit)</h3>
                      </div>
                      <div class="col-md-4 text-end">
                        <?php
                        // Get the latest import info
                        $latestImportQuery = "SELECT COUNT(*) as total_records, MAX(created_at) as last_import FROM import_dat";
                        $latestImportResult = mysqli_query($con, $latestImportQuery);
                        
                        if ($latestImportResult) {
                          $latestImportRow = mysqli_fetch_assoc($latestImportResult);
                          $totalRecords = $latestImportRow['total_records'] ?? 0;
                          $lastImportDate = $latestImportRow['last_import'] ?? null;
                          
                          if ($lastImportDate) {
                            // Format tanggal ke format Indonesia
                            $dateTime = new DateTime($lastImportDate);
                            $formattedDate = $dateTime->format('d M Y H:i:s');
                            echo '<span style="color: #dc3545; ms-auto; font-weight: bold;">Diimport: ' . htmlspecialchars($formattedDate) . '</span>';
                          }
                        }
                        ?>
                      </div>
                    </div>
                  </div>
                  <div class="card-body">
                        <!-- Table Wrapper with Horizontal Scroll -->
                        <div class="table-responsive">
                        <!-- Table -->
                        <table id="myTable" class="display nowrap table table-striped" style="width:100%; min-width: 5400px;">
                            <thead>
                                <tr>
                                  <th >No Asset Utama</th>
                                  <th >Subreg</th>
                                  <th >Profit Center</th>
                                  <th >PC Text</th>
                                  <th >Cost Center Baru</th>
                                  <th >Deskripsi CC</th>
                                  <th >Cabang/Kawasan</th>
                                  <th >Kode Plant</th>
                                  <th >Periode</th>
                                  <th >Tahun</th>
                                  <th >Asset Asal</th>
                                  <th >GL Account</th>
                                  <th >Asset Class</th>
                                  <th >Nama Class</th>
                                  <th >Kel. Aset</th>
                                  <th >Status</th>
                                  <th >Asset No Text</th>
                                  <th >Akuisisi</th>
                                  <th >Keterangan</th>
                                  <th>Tgl Akusisi</th>
                                  <th>Tgl Perolehan</th>
                                  <th>Tgl Penyusutan</th>
                                  <th>Masa Manfaat</th>
                                  <th>Sisa Manfaat</th>
                                  <th>Nilai Perolehan AT</th>
                                  <th>Residu %</th>
                                  <th>Residu Rp</th>
                                  <th>Nilai Perolehan s.d</th>
                                  <th>Adjusment Nilai</th>
                                  <th>Nilai Buku AT</th>
                                  <th>Nilai Buku s.d</th>
                                  <th>Penyusutan/Bulan</th>
                                  <th>Penyusutan s.d</th>
                                  <th>Penyusutan TL</th>
                                  <th>Penyusutan/Tahun</th>
                                  <th>Akm Penyusutan TL</th>
                                  <th>Adjusment Akm</th>
                                  <th>Penghapusan</th>
                                  <th>Asset Shutdown</th>
                                  <th>Akumulasi Penyusutan</th>
                                  <th>Additional Description</th>
                                  <th>Serial Number</th>
                                  <th>Alamat</th>
                                  <th>GL Account EXP. Depre.</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php  

            $query = "SELECT * FROM import_dat";
            $result = mysqli_query($con, $query);
            
            if (!$result) {
                echo '<tr><td colspan="45">Error: ' . mysqli_error($con) . '</td></tr>';
            } elseif (mysqli_num_rows($result) == 0) {
                // Jika tidak ada data dengan kolom tersebut, tampilkan semua data
                $query = "SELECT * FROM import_dat";
                $result = mysqli_query($con, $query);
            }
            
            while ($row = mysqli_fetch_assoc($result)) {
              echo '
                                <tr>
                                    <td>'.htmlspecialchars($row['nomor_asset_utama']).'</td>
                                    <td>'.htmlspecialchars($row['subreg']).'</td>
                                    <td>'.htmlspecialchars($row['profit_center']).'</td>
                                    <td>'.htmlspecialchars($row['profit_center_text']).'</td>
                                    <td>'.htmlspecialchars($row['cost_center_baru']).'</td>
                                    <td>'.htmlspecialchars($row['deskripsi_cost_center']).'</td>
                                    <td>'.htmlspecialchars($row['nama_cabang_kawasan']).'</td>
                                    <td>'.htmlspecialchars($row['kode_plant']).'</td>
                                    <td>'.htmlspecialchars($row['periode_bulan']).'</td>
                                    <td>'.htmlspecialchars($row['tahun_buku']).'</td>
                                    <td>'.htmlspecialchars($row['nomor_asset_asal']).'</td>
                                    <td>'.htmlspecialchars($row['gl_account']).'</td>
                                    <td>'.htmlspecialchars($row['asset_class']).'</td>
                                    <td>'.htmlspecialchars($row['asset_class_name']).'</td>
                                    <td>'.htmlspecialchars($row['kelompok_aset']).'</td>
                                    <td>'.htmlspecialchars($row['status_aset']).'</td>
                                    <td>'.htmlspecialchars($row['asset_main_no_text']).'</td>
                                    <td>'.htmlspecialchars($row['akuisisi']).'</td>
                                    <td>'.htmlspecialchars($row['keterangan_asset']).'</td>
                                    <td>'.htmlspecialchars($row['tgl_akuisisi']).'</td>
                                    <td>'.htmlspecialchars($row['tgl_perolehan']).'</td>
                                    <td>'.htmlspecialchars($row['tgl_penyusutan']).'</td>
                                    <td>'.htmlspecialchars($row['masa_manfaat']).'</td>
                                    <td>'.htmlspecialchars($row['sisa_manfaat']).'</td>
                                    <td>'.htmlspecialchars($row['nilai_perolehan_awal']).'</td>
                                    <td>'.htmlspecialchars($row['nilai_residu_persen']).'</td>
                                    <td>'.htmlspecialchars($row['nilai_residu_rp']).'</td>
                                    <td>'.htmlspecialchars($row['nilai_perolehan_sd']).'</td>
                                    <td>'.htmlspecialchars($row['adjusment_nilai_perolehan']).'</td>
                                    <td>'.htmlspecialchars($row['nilai_buku_awal']).'</td>
                                    <td>'.htmlspecialchars($row['nilai_buku_sd']).'</td>
                                    <td>'.htmlspecialchars($row['penyusutan_bulan']).'</td>
                                    <td>'.htmlspecialchars($row['penyusutan_sd']).'</td>
                                    <td>'.htmlspecialchars($row['penyusutan_tahun_lalu']).'</td>
                                    <td>'.htmlspecialchars($row['penyusutan_tahun']).'</td>
                                    <td>'.htmlspecialchars($row['akm_penyusutan_tahun_lalu']).'</td>
                                    <td>'.htmlspecialchars($row['adjusment_akm_penyusutan']).'</td>
                                    <td>'.htmlspecialchars($row['penghapusan']).'</td>
                                    <td>'.htmlspecialchars($row['asset_shutdown']).'</td>
                                    <td>'.htmlspecialchars($row['akumulasi_penyusutan']).'</td>
                                    <td>'.htmlspecialchars($row['additional_description']).'</td>
                                    <td>'.htmlspecialchars($row['serial_number'] ?? '-').'</td>
                                    <td>'.htmlspecialchars($row['alamat'] ?? '-').'</td>
                                    <td>'.htmlspecialchars($row['gl_account_exp'] ?? '-').'</td>
                                </tr>
              ';
            }
            ?>
                            </tbody>
                        </table>
                        </div>
                        <!-- End Table Wrapper -->
                  </div>
                </div>
            </div>
                  <!--end::Form-->
            <!--end::Row-->
          </div>
        </div>
        <!--end::App Content-->
      </main>
      <!--end::App Main-->
      <!--begin::Footer-->
      <footer class="app-footer">
        <!--begin::To the end-->
        <div class="float-end d-none d-sm-inline">PT Pelabuhan Indon3sia (Persero)</div>
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
    <!--begin::Script-->
    <!--begin::Third Party Plugin(OverlayScrollbars)-->
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
      // NOTICE!! DO NOT USE ANY OF THIS JAVASCRIPT
      // IT'S ALL JUST JUNK FOR DEMO
      // ++++++++++++++++++++++++++++++++++++++++++

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
      });

      // Initialize DataTable dengan responsive
      let dataTable = null;
      
      $(document).ready(function() {
        // Destroy existing DataTable jika ada
        if ($.fn.DataTable.isDataTable('#myTable')) {
          $('#myTable').DataTable().destroy();
        }

        dataTable = $('#myTable').DataTable({
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
              targets: '_all',
              className: 'dt-body-center'
            }
          ],
          language: {
            url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/id.json'
          },
          initComplete: function() {
            console.log('DataTable initialized successfully');
          }
        });
      });

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

      // Function to confirm and save data to database
      function confirmSaveData() {
        // Validasi table tersedia
        if (!dataTable) {
          alert('Tabel belum siap. Silakan refresh halaman.');
          return;
        }
        
        // Get table data dari DataTable instance
        const rows = dataTable.rows().data();
        
        if (rows.length === 0) {
          alert('Tidak ada data untuk disimpan');
          return;
        }
        
        // Extract data dari setiap row
        const importedData = [];
        
        // Iterate through all rows in the table
        dataTable.rows().every(function(index) {
          const node = this.node();
          const cells = $(node).find('td');
          
          const rowData = [];
          
          // Ambil semua cell dalam row
          cells.each(function() {
            rowData.push($(this).text().trim());
          });
          
          // Pastikan row bukan placeholder "Belum ada data"
          if (rowData[0] && rowData[0] !== 'Belum ada data' && rowData[0] !== '') {
            importedData.push(rowData);
          }
        });
        
        if (importedData.length === 0) {
          alert('Tidak ada data valid untuk disimpan');
          return;
        }
        
        // Show confirmation dialog
        const message = `Anda yakin ingin menyimpan ${importedData.length} baris data ke database?\n\nData yang sudah ada dengan nomor asset yang sama akan ditolak.`;
        
        if (confirm(message)) {
          // Set hidden input with data
          document.getElementById('dataInput').value = JSON.stringify(importedData);
          
          // Disable save button during submit
          const saveBtn = document.getElementById('saveBtn');
          saveBtn.disabled = true;
          saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Sedang menyimpan...';
          
          // Submit form
          document.getElementById('saveForm').submit();
        }
      }
    </script>
    <!--end::Script-->
  </body>
  <!--end::Body-->
</html>
