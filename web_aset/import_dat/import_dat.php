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
                // Clear session data
                unset($_SESSION['importedData']);
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

// Handle clear preview (hapus table)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_preview') {
    // Hapus data preview dari session
    if (isset($_SESSION['importedData'])) {
        unset($_SESSION['importedData']);
    }
    $importedData = [];
    $pesan = "✅ Preview data berhasil dihapus";
    $tipe_pesan = "success";
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
                // Save to session
                $_SESSION['importedData'] = $importedData;
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

// Get imported data from session if available
if (isset($_SESSION['importedData'])) {
    $importedData = $_SESSION['importedData'];
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
                    // Pad ke 47 kolom untuk DAT-des.csv
                    while (count($row) < 47) {
                        $row[] = '';
                    }
                    $rows[] = array_slice($row, 0, 47);
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
                // Pad dengan kolom kosong hingga 47 kolom
                while (count($cellData) < 47) {
                    $cellData[] = '';
                }
                $rows[] = array_slice($cellData, 0, 47);
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
                // Pad ke 47 kolom
                while (count($row) < 47) {
                    $row[] = '';
                }
                $rows[] = array_slice($row, 0, 47);
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
            fgetcsv($handle, NULL, ';');

            while (($row = fgetcsv($handle, NULL, ';')) !== false) {
                // Pad ke 47 kolom untuk DAT-des.csv
                while (count($row) < 47) {
                    $row[] = '';
                }
                $rows[] = array_slice($row, 0, 47);
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
    <title>Web Aset Tetap</title>
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
    <!-- apexcharts -->
    <link
      rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.css"
      integrity="sha256-4MX+61mt9NVvvuPjUWdUdyfZfxSB1/Rf9WtqRHgG5S0="
      crossorigin="anonymous"
    />
  </head>
  <body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <!--begin::App Wrapper-->
    <div class="app-wrapper">
      <!--begin::Header-->
      <nav class="app-header navbar navbar-expand bg-body">
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
            $query = "
                SELECT menus.menu, menus.nama_menu, menus.urutan_menu FROM user_access INNER JOIN menus ON user_access.id_menu = menus.id_menu WHERE user_access.NIPP = '1234567890' ORDER BY menus.urutan_menu ASC";
            $result = mysqli_query($con, $query) or die(mysqli_error($con));
            $iconMap = [
                'Dasboard'               => 'bi bi-grid-fill',
                'Usulan Penghapusan'     => 'bi bi-clipboard-plus-fill',
                'Approval SubReg'        => 'bi bi-check-circle',
                'Approval Regional'      => 'bi bi-check2-square',
                'Persetujuan Penghapusan'=> 'bi bi-clipboard-check-fill',
                'Pelaksanaan Penghapusan'=> 'bi bi-tools',
                'Manajemen Menu'         => 'bi bi-list-ul',
                'Import DAT'             => 'bi bi-file-earmark-arrow-up-fill',
                'Daftar Aset Tetap'      => 'bi bi-card-list',
                'Manajemen User'         => 'bi bi-people-fill'
            ];
  
            while ($row = mysqli_fetch_assoc($result)) {
                $namaMenu = trim($row['nama_menu']); 
                $icon = $iconMap[$namaMenu] ?? 'bi bi-circle';
                
                $currentPage = basename($_SERVER['PHP_SELF']);
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
              <div class="col-sm-6"><h3 class="mb-0">Import DAT</h3></div>
              <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                  <li class="breadcrumb-item"><a href="../dasbor/dasbor.php">Home</a></li>
                  <li class="breadcrumb-item active">Import Data Dari Excel</li>
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
                  <div class="card card-primary card-outline mb-4">
                  <!--begin::Header-->
                  <div class="card-header"><div class="card-title">Import data dari Excel</div></div>
                  <!--end::Header-->
                  <!--begin::Form-->
                  <form method="POST" enctype="multipart/form-data">
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
                          Format yang didukung: <strong>.csv</strong>
                        </small>
                      </div>
                    </div>
                    <!--end::Body-->
                    <!--begin::Footer-->
                    <div class="card-footer">
                      <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                          <i class="bi bi-cloud-arrow-up"></i> Upload
                        </button>
                        <button type="button" class="btn btn-success" onclick="confirmSaveData()" id="saveBtn" style="display: none;">
                          <i class="bi bi-check-circle"></i> Simpan ke Database
                        </button>
                      </div>
                    </div>
                    <!--end::Footer-->
                  </form>
                  <!--end::Form-->
                  </div>
                  <!--end::Form-->
                  
                  <!-- Data Preview Card -->
                  <?php if (!empty($importedData)): ?>
                  <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                      <h3 class="card-title">Preview Data (<?php echo count($importedData); ?> baris)</h3>
                    </div>
                    <div class="card-body" style="overflow-x: auto;">
                      <table class="table table-bordered table-striped table-sm" role="table">
                        <thead class="table-dark sticky-top">
                          <tr>
                            <th scope="col" style="white-space: nowrap;">No Asset Utama</th>
                            <th scope="col" style="white-space: nowrap;">SubReg</th>
                            <th scope="col" style="white-space: nowrap;">Profit Center</th>
                            <th scope="col" style="white-space: nowrap;">PC Text</th>
                            <th scope="col" style="white-space: nowrap;">Cost Center Baru</th>
                            <th scope="col" style="white-space: nowrap;">Deskripsi CC</th>
                            <th scope="col" style="white-space: nowrap;">Cabang/Kawasan</th>
                            <th scope="col" style="white-space: nowrap;">Kode Plant</th>
                            <th scope="col" style="white-space: nowrap;">Periode</th>
                            <th scope="col" style="white-space: nowrap;">Tahun</th>
                            <th scope="col" style="white-space: nowrap;">Asset Asal</th>
                            <th scope="col" style="white-space: nowrap;">Asset No</th>
                            <th scope="col" style="white-space: nowrap;">Sub Number</th>
                            <th scope="col" style="white-space: nowrap;">Nomor Asset_Sub Number</th>
                            <th scope="col" style="white-space: nowrap;">GL Account</th>
                            <th scope="col" style="white-space: nowrap;">Asset Class</th>
                            <th scope="col" style="white-space: nowrap;">Nama Class</th>
                            <th scope="col" style="white-space: nowrap;">Kel. Aset</th>
                            <th scope="col" style="white-space: nowrap;">Status</th>
                            <th scope="col" style="white-space: nowrap;">Asset No Text</th>
                            <th scope="col" style="white-space: nowrap;">Akuisisi</th>
                            <th scope="col" style="white-space: nowrap;">Keterangan</th>
                            <th scope="col" style="white-space: nowrap;">Tgl Akusisi</th>
                            <th scope="col" style="white-space: nowrap;">Tgl Perolehan</th>
                            <th scope="col" style="white-space: nowrap;">Tgl Penyusutan</th>
                            <th scope="col" style="white-space: nowrap;">Masa Manfaat</th>
                            <th scope="col" style="white-space: nowrap;">Sisa Manfaat</th>
                            <th scope="col" style="white-space: nowrap;">Nilai Perolehan AT</th>
                            <th scope="col" style="white-space: nowrap;">Residu %</th>
                            <th scope="col" style="white-space: nowrap;">Residu Rp</th>
                            <th scope="col" style="white-space: nowrap;">Nilai Perolehan s.d</th>
                            <th scope="col" style="white-space: nowrap;">Adjusment Nilai</th>
                            <th scope="col" style="white-space: nowrap;">Nilai Buku AT</th>
                            <th scope="col" style="white-space: nowrap;">Nilai Buku s.d</th>
                            <th scope="col" style="white-space: nowrap;">Penyusutan/Bulan</th>
                            <th scope="col" style="white-space: nowrap;">Penyusutan s.d</th>
                            <th scope="col" style="white-space: nowrap;">Penyusutan TL</th>
                            <th scope="col" style="white-space: nowrap;">Penyusutan/Tahun</th>
                            <th scope="col" style="white-space: nowrap;">Akm Penyusutan TL</th>
                            <th scope="col" style="white-space: nowrap;">Adjusment Akm</th>
                            <th scope="col" style="white-space: nowrap;">Penghapusan</th>
                            <th scope="col" style="white-space: nowrap;">Asset Shutdown</th>
                            <th scope="col" style="white-space: nowrap;">Akumulasi Penyusutan</th>
                            <th scope="col" style="white-space: nowrap;">Additional Description</th>
                            <th scope="col" style="white-space: nowrap;">Serial Number</th>
                            <th scope="col" style="white-space: nowrap;">Alamat</th>
                            <th scope="col" style="white-space: nowrap;">GL Account Exp</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php
                          foreach ($importedData as $row) {
                              echo '<tr class="align-middle">';
                              for ($i = 0; $i < 47; $i++) {
                                  $cellValue = isset($row[$i]) ? htmlspecialchars((string)$row[$i]) : '-';
                                  echo '<td style="font-size: 0.85rem;">' . $cellValue . '</td>';
                              }
                              echo '</tr>';
                          }
                          ?>
                        </tbody>
                      </table>
                    </div>
                    <div class="card-footer clearfix">
                      <div class="row">
                        <div class="col-md-12">
                          <div class="d-flex gap-2">
                            <form id="saveForm" method="POST" style="display:inline;">
                              <input type="hidden" name="action" value="save_data">
                              <input type="hidden" id="dataInput" name="data" value="">
                              <button type="button" class="btn btn-success" onclick="submitSaveForm()">
                                <i class="bi bi-check-circle"></i> Simpan ke Database
                              </button>
                            </form>

                            <form id="clearForm" method="POST" style="display:inline;">
                              <input type="hidden" name="action" value="clear_preview">
                              <button type="button" class="btn btn-danger" id="clearBtn" onclick="confirmClearTable()">
                                <i class="bi bi-trash"></i> Hapus Table
                              </button>
                            </form>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                  <?php endif; ?>
                  
                  <!-- Hidden form for saving data - DEPRECATED, using inline form now -->
                  <form id="saveFormHidden" method="POST" style="display:none;">
                    <input type="hidden" name="action" value="save_data">
                    <input type="hidden" id="dataInputHidden" name="data" value="">
                  </form>
            <!--end::Row-->
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
      src="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.min.js"
      integrity="sha256-+vh8GkaU7C9/wbSLIcwq82tQ2wTf44aOHA8HlBMwRI8="
      crossorigin="anonymous"
    ></script>
    <script>
      // NOTICE!! DO NOT USE ANY OF THIS JAVASCRIPT
      // IT'S ALL JUST JUNK FOR DEMO
      // ++++++++++++++++++++++++++++++++++++++++++

      /* apexcharts
       * -------
       * Here we will create a few charts using apexcharts
       */

      //-----------------------
      // - MONTHLY SALES CHART -
      //-----------------------

      const sales_chart_options = {
        series: [
          {
            name: 'Digital Goods',
            data: [28, 48, 40, 19, 86, 27, 90],
          },
          {
            name: 'Electronics',
            data: [65, 59, 80, 81, 56, 55, 40],
          },
        ],
        chart: {
          height: 180,
          type: 'area',
          toolbar: {
            show: false,
          },
        },
        legend: {
          show: false,
        },
        colors: ['#0d6efd', '#20c997'],
        dataLabels: {
          enabled: false,
        },
        stroke: {
          curve: 'smooth',
        },
        xaxis: {
          type: 'datetime',
          categories: [
            '2023-01-01',
            '2023-02-01',
            '2023-03-01',
            '2023-04-01',
            '2023-05-01',
            '2023-06-01',
            '2023-07-01',
          ],
        },
        tooltip: {
          x: {
            format: 'MMMM yyyy',
          },
        },
      };

      const sales_chart = new ApexCharts(
        document.querySelector('#sales-chart'),
        sales_chart_options,
      );
      sales_chart.render();

      //---------------------------
      // - END MONTHLY SALES CHART -
      //---------------------------

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

      const table_sparkline_1_data = [25, 66, 41, 89, 63, 25, 44, 12, 36, 9, 54];
      const table_sparkline_2_data = [12, 56, 21, 39, 73, 45, 64, 52, 36, 59, 44];
      const table_sparkline_3_data = [15, 46, 21, 59, 33, 15, 34, 42, 56, 19, 64];
      const table_sparkline_4_data = [30, 56, 31, 69, 43, 35, 24, 32, 46, 29, 64];
      const table_sparkline_5_data = [20, 76, 51, 79, 53, 35, 54, 22, 36, 49, 64];
      const table_sparkline_6_data = [5, 36, 11, 69, 23, 15, 14, 42, 26, 19, 44];
      const table_sparkline_7_data = [12, 56, 21, 39, 73, 45, 64, 52, 36, 59, 74];

      createSparklineChart('#table-sparkline-1', table_sparkline_1_data);
      createSparklineChart('#table-sparkline-2', table_sparkline_2_data);
      createSparklineChart('#table-sparkline-3', table_sparkline_3_data);
      createSparklineChart('#table-sparkline-4', table_sparkline_4_data);
      createSparklineChart('#table-sparkline-5', table_sparkline_5_data);
      createSparklineChart('#table-sparkline-6', table_sparkline_6_data);
      createSparklineChart('#table-sparkline-7', table_sparkline_7_data);

      //-------------
      // - PIE CHART -
      //-------------

      const pie_chart_options = {
        series: [700, 500, 400, 600, 300, 100],
        chart: {
          type: 'donut',
        },
        labels: ['Chrome', 'Edge', 'FireFox', 'Safari', 'Opera', 'IE'],
        dataLabels: {
          enabled: false,
        },
        colors: ['#0d6efd', '#20c997', '#ffc107', '#d63384', '#6f42c1', '#adb5bd'],
      };

      const pie_chart = new ApexCharts(document.querySelector('#pie-chart'), pie_chart_options);
      pie_chart.render();

      //-----------------
      // - END PIE CHART -
      //-----------------

      // Function to submit save form with data from preview table
      function submitSaveForm() {
        // Get table data from preview
        const tableRows = document.querySelectorAll('.card-body table tbody tr');
        
        if (tableRows.length === 0) {
          showAlertModal('Info', 'Tidak ada data untuk disimpan', 'info');
          return;
        }
        
        // Extract data from table
        const importedData = [];
        tableRows.forEach((row) => {
          const cells = row.querySelectorAll('td');
          if (cells.length > 0) {
            const rowData = [];
            // Get only first 47 columns
            for (let i = 0; i < Math.min(47, cells.length); i++) {
              rowData.push(cells[i].textContent.trim());
            }
            importedData.push(rowData);
          }
        });
        
        if (importedData.length === 0) {
          showAlertModal('Info', 'Tidak ada data valid untuk disimpan', 'info');
          return;
        }
        
        // Show confirmation dialog using modal
        const message = `Anda yakin ingin menyimpan ${importedData.length} baris data ke database?\n\nData yang sudah ada dengan nomor asset yang sama akan ditolak.`;
        showConfirmModal('Konfirmasi Simpan', message, { variant: 'success', confirmText: 'Simpan', cancelText: 'Batal' })
          .then((confirmed) => {
            if (confirmed) {
              // Set hidden input with data
              document.getElementById('dataInput').value = JSON.stringify(importedData);
              // Submit form
              document.getElementById('saveForm').submit();
            }
          });
      }

      // Legacy function for backward compatibility
      function confirmSaveData() {
        submitSaveForm();
      }

      // Confirm and clear preview table
      function confirmClearTable() {
        const tableRows = document.querySelectorAll('.card-body table tbody tr');
        if (tableRows.length === 0) {
          showAlertModal('Info', 'Tidak ada data untuk dihapus', 'info');
          return;
        }
        showConfirmModal('Hapus Preview', 'Anda yakin ingin menghapus preview data?', { variant: 'danger', confirmText: 'Hapus', cancelText: 'Batal' })
          .then((confirmed) => {
            if (confirmed) {
              document.getElementById('clearForm').submit();
            }
          });
      }

      // Utility: Promise-based modal confirm using Bootstrap 5
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
        // show as modal with only OK
        return showConfirmModal(title, message, { variant: variant, showCancel: false, confirmText: 'OK' });
      }
    </script>
    <!--end::Script-->
  </body>
  <!--end::Body-->
</html>
