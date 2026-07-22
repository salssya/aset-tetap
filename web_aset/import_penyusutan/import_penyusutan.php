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

// ==========================================================
// Variabel untuk card "Import DAT"
// ==========================================================
$importedData = [];
$pesan = "";
$tipe_pesan = "";
$saved_count = 0;

// ==========================================================
// Variabel untuk card "Import Data Penyusutan"
// ==========================================================
$importedDataPenyusutan = [];
$pesanPenyusutan = "";
$tipePenyusutan = "";
$savedCountPenyusutan = 0;

$TARGET_HEADERS_PENYUSUTAN = [
    'cost center', 'asset', 'asset subnumber', 'account',
    'posting date', 'amount in local currency', 'profit center', 'text', 'document number'
];
// Document Number TIDAK wajib ada (opsional) -- dipakai untuk membedakan transaksi yang
// kebetulan identik di 5 kolom kunci lain (cost_center/asset/sub/account/posting_date),
// misal 2 dokumen jurnal berbeda untuk aset & tanggal yang sama. Kalau file tidak
// punya kolom ini, tetap boleh diimport, cuma document_number-nya kosong.
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
    // Cek ukuran file (max 20MB)
    else if ($file_size > 20 * 1024 * 1024) {
        $pesan = "Ukuran file terlalu besar. Maksimal 20MB";
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
            $importedData = readDatPenyusutanFileByHeader($file['tmp_name'], $file_ext);
            
            if (empty($importedData)) {
                $pesan = "File tidak memiliki data untuk diimport";
                $tipe_pesan = "warning";
            } else {
                // Langsung simpan ke database tanpa preview
                try {
                    $saved_count = saveDatPenyusutanToDatabase($con, $importedData);
                    
                    if ($saved_count > 0) {
                        $pesan = "✅ Berhasil mengimport dan menyimpan " . $saved_count . " baris data ke database";
                        $tipe_pesan = "success";
                        // Clear imported data
                        $importedData = [];
                        if (isset($_SESSION['importedData'])) {
                            unset($_SESSION['importedData']);
                        }
                    } else {
                        $pesan = "File berhasil dibaca namun tidak ada data yang tersimpan (mungkin duplikat)";
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
    // Cek ukuran file (max 20MB)
    else if ($file_size > 20 * 1024 * 1024) {
        $pesanPenyusutan = "Ukuran file terlalu besar. Maksimal 20MB";
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

function readExcelFile($filePath, $ext, $numCols = 47) {
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
    
    return $rows;
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
            $delimiter = (strpos($first_line, ';') !== false) ? ';' : ',';
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
 */
function forward_fill_row_ps(array $row, array &$lastValues) {
    // Index sesuai urutan $TARGET_HEADERS_PENYUSUTAN: 0=cost_center,1=asset,2=asset_subnumber,3=account,4=posting_date,5=amount,6=profit_center,7=text
    $idxIdentitas = [0, 1, 2, 3, 6];
    foreach ($idxIdentitas as $i) {
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
        $delimiter = (strpos($first_line, ';') !== false) ? ';' : ',';
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
            $lastValuesFF = []; // reset forward-fill tiap ganti sheet, supaya bulan lain tidak ikut ke-isi
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
                        // Sheet ini tidak punya header yang sesuai (mis. sheet kosong/beda format) -> lewati sheet ini,
                        // tapi tetap lanjut proses sheet lain supaya sheet lain tidak ikut gagal
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
        $delimiter = (strpos($first_line, ';') !== false) ? ';' : ',';
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
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        imported_by VARCHAR(20),
        UNIQUE KEY uk_asset_sub_periode (nomor_asset, sub_number, periode_bulan, tahun_buku)
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
                $values[] = "'" . mysqli_real_escape_string($con, $value) . "'";
            }
            
            $values[] = "'" . mysqli_real_escape_string($con, $nipp) . "'";
            
            $columns = implode(', ', $column_names) . ', imported_by';
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
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        imported_by VARCHAR(20),
        KEY idx_penyusutan_lookup (cost_center, asset, asset_subnumber, account, posting_date)
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
                $values[] = "'" . mysqli_real_escape_string($con, $value) . "'";
            }
            
            $values[] = "'" . mysqli_real_escape_string($con, $nipp) . "'";

            $columns = implode(', ', $column_names) . ', imported_by';
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
?>
<!doctype html>
<html lang="en">
  <!--begin::Head-->
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title> Import Data Penyusutan - Web Aset Tetap</title>
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
                'Import Data Penyusutan'          => 'bi bi-cloud-upload',
                'Daftar Data Penyusutan'          => 'bi bi-table',
                'Selisih Penyusutan'              => 'bi bi-bar-chart-line',
                'Daftar Aset Tetap'               => 'bi bi-boxes',
                'Manajemen User'                  => 'bi bi-people',
            ];

            // ── Pengelompokan menu jadi 3 grup dropdown: Penghapusan, Penyusutan, Manajemen Admin ──
            // (menu di luar mapping ini, misal "Dasboard", dirender sebagai item biasa di luar grup)
            $groupMap = [
                'Usulan Penghapusan'             => 'Penghapusan',
                'Daftar Usulan Penghapusan'      => 'Penghapusan',
                'Approval SubReg'                => 'Penghapusan',
                'Approval Regional'              => 'Penghapusan',
                'Persetujuan Penghapusan'        => 'Penghapusan',
                'Daftar Persetujuan Penghapusan' => 'Penghapusan',
                'Pelaksanaan Penghapusan'        => 'Penghapusan',
                'Daftar Aset Tetap'              => 'Penghapusan',
                'Daftar Pelaksanaan Penghapusan' => 'Penghapusan',

                'Import Data Penyusutan'         => 'Penyusutan',
                'Daftar Data Penyusutan'         => 'Penyusutan',
                'Selisih Penyusutan'             => 'Penyusutan',

                'Import DAT'                     => 'Manajemen Admin',
                'Manajemen Menu'                 => 'Manajemen Admin',
                'Manajemen User'                 => 'Manajemen Admin',
            ];
            $groupIcon = [
                'Penghapusan'      => 'bi bi-file-earmark-minus',
                'Penyusutan'       => 'bi bi-graph-down-arrow',
                'Manajemen Admin'  => 'bi bi-sliders',
            ];
            $groupOrder = ['Penghapusan', 'Penyusutan', 'Manajemen Admin'];

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
              <div class="col-sm-6"><h3 class="mb-0">Import Data Penyusutan</h3></div>
              <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                  <li class="breadcrumb-item"><a href="../dasbor/dasbor.php">Home</a></li>
                  <li class="breadcrumb-item active">Import Data Penyusutan</li>
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
            <div class="row">

              <!--begin::Card Import DAT-->
              <div class="col-lg-6">
                <div class="card card-primary card-outline mb-4 h-100">
                  <!--begin::Header-->
                  <div class="card-header"><div class="card-title">Import DAT</div></div>
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
                          Format yang didukung: <strong>CSV / Excel (.xls, .xlsx, .csv)</strong>
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

              <!--begin::Card Import Data Penyusutan-->
              <div class="col-lg-6">
                <div class="card card-primary card-outline mb-4 h-100">
                  <!--begin::Header-->
                  <div class="card-header"><div class="card-title">Import Penyusutan SAP</div></div>
                  <!--end::Header-->
                  <!--begin::Form-->
                  <form method="POST" enctype="multipart/form-data">
                    <!--begin::Body-->
                    <div class="card-body">
                      <?php if (!empty($pesanPenyusutan)): ?>
                      <div class="alert alert-<?php echo $tipePenyusutan; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($pesanPenyusutan); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                      </div>
                      <?php endif; ?>
                      
                      <div class="mb-3">
                        <label for="file_penyusutan" class="form-label">Pilih File Excel atau CSV</label>
                        <input type="file" class="form-control" id="file_penyusutan" name="file_penyusutan" accept=".xls,.xlsx,.csv" required>
                        <small class="form-text text-muted">
                          Format yang didukung: <strong>CSV / Excel (.xls, .xlsx, .csv)</strong><br>
                            Pastikan file yang diupload adalah file export dari SAP untuk data penyusutan.
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
              <!--end::Card Import Data Penyusutan-->

            </div>
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
        // show as modal with only OK
        return showConfirmModal(title, message, { variant: variant, showCancel: false, confirmText: 'OK' });
      }
    </script>
    <!--end::Script-->
  </body>
  <!--end::Body-->
</html>