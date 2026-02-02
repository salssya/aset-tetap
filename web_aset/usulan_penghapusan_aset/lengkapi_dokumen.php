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

// Get submitted data
$query = "SELECT * FROM usulan_penghapusan WHERE status = 'submitted' ORDER BY created_at DESC";
$result = mysqli_query($con, $query);

$submitted_data = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $submitted_data[] = $row;
    }
}

// Handle document upload
$pesan = "";
$tipe_pesan = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_document') {
    $asset_id = $_POST['asset_id'];
    $document_type = $_POST['document_type'];
    
    // Handle file upload
    if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['document'];
        $upload_dir = '../../uploads/documents/';
        
        // Create directory if not exists
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $new_filename = $asset_id . '_' . $document_type . '_' . time() . '.' . $file_ext;
        $upload_path = $upload_dir . $new_filename;
        
        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
            // Save to database
            $stmt = $con->prepare("UPDATE usulan_penghapusan SET 
                document_path = ?, 
                document_type = ?,
                updated_at = NOW()
                WHERE id = ?");
            $stmt->bind_param("ssi", $upload_path, $document_type, $asset_id);
            
            if ($stmt->execute()) {
                $pesan = "Dokumen berhasil diupload";
                $tipe_pesan = "success";
            } else {
                $pesan = "Gagal menyimpan informasi dokumen";
                $tipe_pesan = "danger";
            }
            $stmt->close();
        } else {
            $pesan = "Gagal mengupload file";
            $tipe_pesan = "danger";
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Lengkapi Dokumen - Usulan Penghapusan Aset</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/styles/overlayscrollbars.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="../../dist/css/adminlte.css" />
    <link rel="stylesheet" href="https://cdn.datatables.net/2.3.6/css/dataTables.dataTables.css" />
    <style>
        .badge-submitted {
            background-color: #17a2b8;
            color: white;
        }
        .action-btn {
            margin: 2px;
        }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
    <div class="app-wrapper">
        <!-- Your navbar and sidebar code here -->
        
        <main class="app-main">
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6">
                            <h3 class="mb-0">Lengkapi Dokumen</h3>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <li class="breadcrumb-item"><a href="#">Home</a></li>
                                <li class="breadcrumb-item active">Lengkapi Dokumen</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="app-content">
                <div class="container-fluid">
                    <?php if ($pesan): ?>
                    <div class="alert alert-<?= $tipe_pesan ?> alert-dismissible fade show" role="alert">
                        <?= $pesan ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title">Data Usulan yang Perlu Dilengkapi Dokumen</h3>
                                    <div class="card-tools">
                                        <span class="badge bg-info"><?= count($submitted_data) ?> Data</span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table id="documentTable" class="table table-bordered table-striped">
                                            <thead>
                                                <tr>
                                                    <th>No</th>
                                                    <th>Nomor Asset</th>
                                                    <th>Nama Asset</th>
                                                    <th>Kantor</th>
                                                    <th>Nilai Buku</th>
                                                    <th>Status</th>
                                                    <th>Tanggal Submit</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($submitted_data)): ?>
                                                <tr>
                                                    <td colspan="8" class="text-center">Belum ada data yang perlu dilengkapi</td>
                                                </tr>
                                                <?php else: ?>
                                                <?php foreach ($submitted_data as $index => $row): ?>
                                                <tr>
                                                    <td><?= $index + 1 ?></td>
                                                    <td><?= htmlspecialchars($row['nomor_asset']) ?></td>
                                                    <td><?= htmlspecialchars($row['nama_asset']) ?></td>
                                                    <td><?= htmlspecialchars($row['kantor']) ?></td>
                                                    <td>Rp <?= number_format($row['nilai_buku'], 2, ',', '.') ?></td>
                                                    <td><span class="badge badge-submitted"><?= strtoupper($row['status']) ?></span></td>
                                                    <td><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></td>
                                                    <td>
                                                        <button class="btn btn-sm btn-primary action-btn" onclick="viewDetail(<?= $row['id'] ?>)">
                                                            <i class="bi bi-eye"></i> Detail
                                                        </button>
                                                        <button class="btn btn-sm btn-success action-btn" onclick="uploadDocument(<?= $row['id'] ?>)">
                                                            <i class="bi bi-upload"></i> Upload
                                                        </button>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Modal Upload Document -->
    <div class="modal fade" id="uploadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Upload Dokumen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="upload_document">
                        <input type="hidden" name="asset_id" id="asset_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Jenis Dokumen</label>
                            <select class="form-select" name="document_type" required>
                                <option value="">Pilih Jenis Dokumen</option>
                                <option value="ba_pemeriksaan">BA Pemeriksaan</option>
                                <option value="foto_aset">Foto Aset</option>
                                <option value="surat_pernyataan">Surat Pernyataan</option>
                                <option value="dokumen_pendukung">Dokumen Pendukung Lainnya</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">File Dokumen</label>
                            <input type="file" class="form-control" name="document" required>
                            <small class="text-muted">Format: PDF, JPG, PNG (Max 5MB)</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">Upload</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Detail -->
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Detail Aset</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailContent">
                    <!-- Content will be loaded here -->
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/browser/overlayscrollbars.browser.es6.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.min.js"></script>
    <script src="../../dist/js/adminlte.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/2.3.6/js/dataTables.js"></script>
    
    <script>
        $(document).ready(function() {
            $('#documentTable').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/id.json'
                },
                order: [[6, 'desc']] // Sort by date
            });
        });
        
        function uploadDocument(assetId) {
            $('#asset_id').val(assetId);
            const modal = new bootstrap.Modal(document.getElementById('uploadModal'));
            modal.show();
        }
        
        function viewDetail(assetId) {
            // Load detail via AJAX
            $.ajax({
                url: 'get_asset_detail.php',
                method: 'GET',
                data: { id: assetId },
                success: function(response) {
                    $('#detailContent').html(response);
                    const modal = new bootstrap.Modal(document.getElementById('detailModal'));
                    modal.show();
                },
                error: function() {
                    alert('Gagal memuat detail');
                }
            });
        }
    </script>
</body>
</html>