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
                // Langsung simpan ke database
                $saved_count = saveDataToDatabase($con, $importedData);
                
                if ($saved_count > 0) {
                    $pesan = "✅ File berhasil diimport! Total " . count($importedData) . " baris data, " . $saved_count . " baris berhasil disimpan ke database";
                    $tipe_pesan = "success";
                } else {
                    $pesan = "⚠️ File berhasil dibaca dengan " . count($importedData) . " baris, namun tidak ada data yang berhasil disimpan (mungkin duplikat atau error)";
                    $tipe_pesan = "warning";
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
                    // Pad ke 40 kolom untuk DAT-des.csv
                    while (count($row) < 45) {
                        $row[] = '';
                    }
                    $rows[] = array_slice($row, 0, 45);
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
                // Pad dengan kolom kosong hingga 45 kolom
                while (count($cellData) < 45) {
                    $cellData[] = '';
                }
                $rows[] = array_slice($cellData, 0, 45);
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
                // Pad ke 45 kolom
                while (count($row) < 45) {
                    $row[] = '';
                }
                $rows[] = array_slice($row, 0, 45);
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
                // Pad ke 45 kolom untuk DAT-des.csv
                while (count($row) < 45) {
                    $row[] = '';
                }
                $rows[] = array_slice($row, 0, 45);
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
    $nipp = isset($_SESSION['nipp']) ? $_SESSION['nipp'] : 'unknown';
    // Define column names based on DAT-des.csv structure

    $column_names = [
        'nomor_asset_utama', 'profit_center', 'profit_center_text', 'cost_center_baru', 'deskripsi_cost_center',
        'nama_cabang_kawasan', 'kode_plant', 'periode_bulan', 'tahun_buku', 'nomor_asset_asal',
        'gl_account', 'asset_class', 'asset_class_name',
        'kelompok_aset', 'status_aset', 'asset_main_no_text', 'akuisisi', 'keterangan_asset',
        'tgl_akuisisi', 'tgl_perolehan', 'tgl_penyusutan', 'masa_manfaat', 'sisa_manfaat',
        'nilai_perolehan_awal', 'nilai_residu_persen', 'nilai_residu_rp', 'nilai_perolehan_sd', 'adjusment_nilai_perolehan',
        'nilai_buku_awal', 'nilai_buku_sd', 'penyusutan_bulan', 'penyusutan_sd', 'penyusutan_tahun_lalu',
        'penyusutan_tahun', 'akm_penyusutan_tahun_lalu', 'adjusment_akm_penyusutan', 'penghapusan', 'asset_shutdown',
        'akumulasi_penyusutan', 'additional_description', 'serial_number', 'alamat', 'gl_account_exp'
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
    <!-- Custom Styles for Horizontal Scroll -->
    <style>
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
      <nav class="app-header navbar navbar-expand bg-body">
        <!--begin::Container-->
        <div class="container-fluid">
          <!--begin::Start Navbar Links-->
          <!--end::Start Navbar Links-->
          <!--begin::End Navbar Links-->
          <ul class="navbar-nav ms-auto">
            <!--begin::Navbar Search-->
            <li class="nav-item">
              <a class="nav-link" data-widget="navbar-search" href="#" role="button">
                <i class="bi bi-search"></i>
              </a>
            </li>
            <!--end::Navbar Search-->
            <!--begin::Messages Dropdown Menu-->
            <!--end::Notifications Dropdown Menu-->
            <!--begin::Fullscreen Toggle-->
            <li class="nav-item">
              <a class="nav-link" href="#" data-lte-toggle="fullscreen">
                <i data-lte-icon="maximize" class="bi bi-arrows-fullscreen"></i>
                <i data-lte-icon="minimize" class="bi bi-fullscreen-exit" style="display: none"></i>
              </a>
            </li>
            <!--end::Fullscreen Toggle-->
            <!--begin::User Menu Dropdown-->
            <li class="nav-item dropdown user-menu">
              <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                <img
                  src="../../dist/assets/img/profile.png"
                  class="user-image rounded-circle shadow"
                  alt="User Image"
                />
                <span class="d-none d-md-inline"><?php echo isset($_SESSION['name']) ? htmlspecialchars($_SESSION['name']) : ''; ?></span>
              </a>
              <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                <!--begin::User Image-->
                <li class="user-header text-bg-primary">
                  <img
                    src="../../dist/assets/img/profile.png"
                    class="rounded-circle shadow"
                    alt="User Image"
                  />
                  <p>
                    <?php echo isset($_SESSION['name']) ? htmlspecialchars($_SESSION['name']) : ''; ?>
                  </p>
                </li>
                <!--end::User Image-->
                <!--begin::Menu Body-->
                <!--end::Menu Body-->
                <!--begin::Menu Footer-->
                <li class="user-footer">
                  <a href="#" class="btn btn-default btn-flat">NIPP: <?php echo isset($_SESSION['nipp']) ? htmlspecialchars($_SESSION['nipp']) : ''; ?></a>
                  <a href="../login/login_view.php" class="btn btn-danger ms-auto" >Logout</a>
                </li>
                <!--end::Menu Footer-->
              </ul>
            </li>
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
                'Dasboard'                => 'bi bi-grid',
                'Usulan Penghapusan'      => 'bi bi-clipboard-plus',
                'Approval SubReg'         => 'bi bi-check-circle',
                'Approval Regional'       => 'bi bi-check2-square',
                'Persetujuan Penghapusan' => 'bi bi-clipboard-check',
                'Pelaksanaan Penghapusan' => 'bi bi-tools',
                'Manajemen Menu'          => 'bi bi-list-ul',
                'Manajemen User'         => 'bi bi-people-fill',
                'Import DAT'              => 'bi bi-file-earmark-arrow-up'
            ];
  
            while ($row = mysqli_fetch_assoc($result)) {
                $namaMenu = trim($row['nama_menu']); 
                $icon = $iconMap[$namaMenu] ?? 'bi bi-circle'; 
              if ($namaMenu === 'Manajemen Menu') {
               echo '<li class="nav-header"></li>';
              }
                echo '
                <li class="nav-item">
                    <a href="../'.$row['menu'].'/'.$row['menu'].'.php" class="nav-link">
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
                  <div class="card-header"><div class="card-title">Import Data dari Excel</div></div>
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
                          Format: <strong>.csv</strong>
                        </small>
                      </div>
                    </div>
                    <!--end::Body-->
                    <!--begin::Footer-->
                    <div class="card-footer">
                      <button type="submit" class="btn btn-primary" id="importBtn">
                        <i class="bi bi-upload"></i> Import </button>
                    </div>
                    <!--end::Footer-->
                  </form>
                  <!--end::Form-->
                  </div>
                  <div class="row">
                <div class="card card-outline mb-4">
                  <div class="card-header">
                    <h3 class="card-title">Hasil Import Data</h3>
                  </div>
                  <div class="card-body">
                        <!-- Table Wrapper with Horizontal Scroll -->
                        <div class="table-responsive">
                        <!-- Table -->
                        <table id="myTable" class="display nowrap" style="width:100%; min-width: 5400px;">
                            <thead>
                                <tr>
                                  <th >No Asset Utama</th>
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
            $query = "SELECT * FROM import_dat WHERE serial_number IS NOT NULL OR alamat IS NOT NULL OR gl_account_exp IS NOT NULL ORDER BY id DESC LIMIT 500";
            $result = mysqli_query($con, $query);
            
            if (!$result) {
                echo '<tr><td colspan="45">Error: ' . mysqli_error($con) . '</td></tr>';
            } elseif (mysqli_num_rows($result) == 0) {
                // Jika tidak ada data dengan kolom tersebut, tampilkan semua data
                $query = "SELECT * FROM import_dat ORDER BY id DESC LIMIT 500";
                $result = mysqli_query($con, $query);
            }
            
            while ($row = mysqli_fetch_assoc($result)) {
              echo '
                                <tr>
                                    <td>'.htmlspecialchars($row['nomor_asset_utama']).'</td>
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
                            <tfoot>
                                <tr>
                                  <th>No Asset Utama</th>
                                  <th>Profit Center</th>
                                  <th>PC Text</th>
                                  <th>Cost Center Baru</th>
                                  <th>Deskripsi CC</th>
                                  <th>Cabang/Kawasan</th>
                                  <th>Kode Plant</th>
                                  <th>Periode</th>
                                  <th>Tahun</th>
                                  <th>Asset Asal</th>
                                  <th>GL Account</th>
                                  <th>Asset Class</th>
                                  <th>Nama Class</th>
                                  <th>Kel. Aset</th>
                                  <th>Status</th>
                                  <th>Asset No Text</th>
                                  <th>Akuisisi</th>
                                  <th>Keterangan</th>
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
                            </tfoot>
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

      // Add loading state to import button
      document.getElementById('importBtn').addEventListener('click', function(e) {
        const btn = this;
        const originalText = btn.innerHTML;
        
        // Check if file is selected
        const fileInput = document.getElementById('file_excel');
        if (!fileInput.value) {
          e.preventDefault();
          alert('Silakan pilih file terlebih dahulu');
          return;
        }
        
        // Show loading state
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Sedang memproses...';
        
        // Reset after 30 seconds if something goes wrong
        setTimeout(function() {
          if (btn.disabled) {
            btn.disabled = false;
            btn.innerHTML = originalText;
          }
        }, 30000);
      });

      // Initialize DataTable dengan responsive
      $(document).ready(function() {
        // Destroy existing DataTable jika ada
        if ($.fn.DataTable.isDataTable('#myTable')) {
          $('#myTable').DataTable().destroy();
        }

        let table = $('#myTable').DataTable({
          responsive: false,
          autoWidth: false,
          scrollX: true,
          scrollCollapse: false,
          paging: true,
          searching: true,
          ordering: true,
          info: true,
          processing: false,
          retrieve: true,
          columnDefs: [
            {
              targets: '_all',
              width: '130px'
            }
          ],
          language: {
            url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/id.json'
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

      // Function to confirm and save data to database
      function confirmSaveData() {
        // Get table data dari DataTable instance
        const table = $('#import_dat').DataTable();
        const rows = table.rows().data();
        
        if (rows.length === 0) {
          alert('Tidak ada data untuk disimpan');
          return;
        }
        
        // Extract data dari setiap row
        const importedData = [];
        rows.each(function(index) {
          const rowData = [];
          // Get cells dari row DOM
          const cells = table.row(index).node().querySelectorAll('td');
          
          // Ambil semua 45 kolom
          for (let i = 0; i < 45; i++) {
            if (cells[i]) {
              rowData.push(cells[i].textContent.trim());
            } else {
              rowData.push('');
            }
          }
          
          // Pastikan row bukan placeholder "Belum ada data"
          if (rowData[0] && rowData[0] !== 'Belum ada data') {
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
          
          // Submit form
          document.getElementById('saveForm').submit();
        }
      }
    </script>
    <!--end::Script-->
  </body>
  <!--end::Body-->
</html>
