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

$userNipp = mysqli_real_escape_string($con, $_SESSION['nipp']);
$query = "
  SELECT u.NIPP, u.Nama, u.Email, u.Type_User, u.Cabang, i.profit_center_text
  FROM users u
  LEFT JOIN (
    SELECT DISTINCT profit_center, profit_center_text
    FROM import_dat
  ) i ON u.Cabang = i.profit_center
  WHERE u.NIPP = '$userNipp'
";

$result = mysqli_query($con, $query);
$userRole = 'User';
if ($result && mysqli_num_rows($result) > 0) {
    $row = mysqli_fetch_assoc($result);
    $userName   = $row['Nama'];
    $userEmail  = $row['Email'];
    $userNipp   = $row['NIPP'];
    $userType   = $row['Type_User'];
    $userCabang = $row['Cabang'] . ' - ' . $row['profit_center_text'];
}

$avatarPath = '../../dist/assets/img/profile.png';

// Handle update email & password
$updateSuccess = '';
$updateError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $newEmail       = trim(mysqli_real_escape_string($con, $_POST['new_email'] ?? ''));
    $currentPass    = $_POST['current_password'] ?? '';
    $newPass        = $_POST['new_password'] ?? '';
    $confirmPass    = $_POST['confirm_password'] ?? '';

    // Ambil password lama dari DB
    $nippEsc = mysqli_real_escape_string($con, $userNipp);
    $qPass   = mysqli_query($con, "SELECT Password FROM users WHERE NIPP = '$nippEsc'");
    $rowPass = mysqli_fetch_assoc($qPass);
    $storedPass = $rowPass['Password'] ?? '';

    $changeEmail = !empty($newEmail) && $newEmail !== $userEmail;
    $changePass  = !empty($newPass);

    if (!$changeEmail && !$changePass) {
        $updateError = 'Tidak ada perubahan yang dilakukan.';
    } else {
        // Verifikasi password lama (mendukung MD5 atau plain jika perlu)
        $passValid = (md5($currentPass) === $storedPass) || ($currentPass === $storedPass);
        if (!$passValid) {
            $updateError = 'Password saat ini tidak sesuai.';
        } elseif ($changePass && $newPass !== $confirmPass) {
            $updateError = 'Konfirmasi password baru tidak cocok.';
        } elseif ($changePass && strlen($newPass) < 6) {
            $updateError = 'Password baru minimal 6 karakter.';
        } else {
            // Build query dinamis
            $setParts = [];
            if ($changeEmail)  $setParts[] = "Email = '$newEmail'";
            if ($changePass)   $setParts[] = "Password = '" . md5($newPass) . "'";
            $setClause = implode(', ', $setParts);

            if (mysqli_query($con, "UPDATE users SET $setClause WHERE NIPP = '$nippEsc'")) {
                if ($changeEmail) {
                    $_SESSION['email'] = $newEmail;
                    $userEmail = $newEmail;
                }
                $updateSuccess = 'Profil berhasil diperbarui.';
            } else {
                $updateError = 'Gagal memperbarui profil: ' . mysqli_error($con);
            }
        }
    }
}

?>
<!doctype html>
<html lang="en">
  <!--begin::Head-->
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Profile - Web Aset Tetap</title>
    <!-- Favicon -->
    
    <!-- emblem pelindo -->
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
      integrity="sha256-4MX+61mt9NVvvuPjUWdUdyfZfxSB1/Rf9WtqRHgG5S0="
      crossorigin="anonymous"
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
                    <p class="fw-semibold"><?php echo htmlspecialchars($userCabang); ?></p>
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
                'Daftar Aset Tetap'               => 'bi bi-card-list',
                'Manajemen User'                  => 'bi bi-people',
            ];
                $allMenus = [];
                while ($row = mysqli_fetch_assoc($result_menu)) {
                    $allMenus[] = $row;
                }

                // Sort berdasarkan urutan_menu untuk memastikan urutan selalu konsisten
                usort($allMenus, function($a, $b) {
                    return $a['urutan_menu'] <=> $b['urutan_menu'];
                });

                $currentPage = basename($_SERVER['PHP_SELF']);
                foreach ($allMenus as $row) {
                    $namaMenu = trim($row['nama_menu']);
                    $icon     = $iconMap[$namaMenu] ?? 'bi bi-circle';
                    $isActive = ($currentPage === $row['menu'] . '.php') ? 'active' : '';
                    if ($namaMenu === 'Manajemen Menu') echo '<li class="nav-header"></li>';
                    echo '<li class="nav-item"><a href="../' . $row['menu'] . '/' . $row['menu'] . '.php" class="nav-link ' . $isActive . '"><i class="nav-icon ' . $icon . '"></i><p>' . htmlspecialchars($namaMenu) . '</p></a></li>';
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
              <div class="col-sm-6"><h3 class="mb-0">Profile</h3></div>
            </div>
            <!--end::Row-->
          </div>
          <!--end::Container-->
        </div>
        <div class="app-content">
  <div class="container-fluid">
    <div class="row">
      <div class="col-lg-8 col-md-10 mx-auto">
        <div class="card card-primary card-outline">
          <div class="card-header">
            <h3 class="card-title">Informasi Profile</h3>
          </div>
          <div class="card-body">
            <div class="row">
              <!-- Foto dan Role -->
              <div class="col-md-4 text-center">
                <img src="<?php echo $avatarPath; ?>" 
                     class="rounded-circle shadow mb-2 border border-3 border-primary" 
                     alt="User Image" 
                     style="width:110px;height:110px;">
                <div class="mt-3">
                  <h5 class="mt-1 mb-0 fw-bold">
                     <?php echo htmlspecialchars($_SESSION['name']); ?> 
                    </h5> 
                    <span class="badge bg-primary"> 
                      <?php echo htmlspecialchars($_SESSION['Type_User']); ?> 
                </span>
                </div>
              </div>
              <!-- Informasi User -->
              <div class="col-md-8">
                <div class="info mt-2">
                    <div class="mb-2">
                      <h6 class="mb-1 text-muted">NIPP:</h6>
                      <p class="fw-semibold"><?php echo htmlspecialchars($_SESSION['nipp']); ?></p>
                    </div>

                    <div class="mb-2">
                      <h6 class="mb-1 text-muted">Email:</h6>
                      <p class="fw-semibold"><?php echo htmlspecialchars($_SESSION['email']); ?></p>
                    </div>
                    
                    <div class="mb-2">
                      <h6 class="mb-1 text-muted">Cabang:</h6>
                      <p class="fw-semibold">
                        <?php echo !empty($userCabang) ? htmlspecialchars($userCabang) : 'Belum ada data'; ?>
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <?php if ($updateSuccess): ?>
            <div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
              <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($updateSuccess); ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            <?php if ($updateError): ?>
            <div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
              <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($updateError); ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

          </div>
          <div class="card-footer text-end" style="padding-top: 1rem; padding-bottom: 1rem;">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editProfileModal">
              <i class="bi bi-pencil-square me-1"></i> Edit Profil
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal Edit Profil -->
  <div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <form method="POST" action="">
          <input type="hidden" name="action" value="update_profile">
          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title" id="editProfileModalLabel">
              <i class="bi bi-pencil-square me-2"></i>Edit Email &amp; Password
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label fw-semibold">Email Baru</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                <input type="email" name="new_email" class="form-control"
                  placeholder="Kosongkan jika tidak ingin mengubah"
                  value="<?php echo htmlspecialchars($userEmail); ?>">
              </div>
              <div class="form-text">Email saat ini: <strong><?php echo htmlspecialchars($userEmail); ?></strong></div>
            </div>
            <hr>
            <div class="mb-3">
              <label class="form-label fw-semibold">Password Baru</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                <input type="password" name="new_password" id="newPassword" class="form-control"
                  placeholder="Kosongkan jika tidak ingin mengubah">
                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('newPassword', this)">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
              <div class="form-text">Minimal 6 karakter.</div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Konfirmasi Password Baru</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                <input type="password" name="confirm_password" id="confirmPassword" class="form-control"
                  placeholder="Ulangi password baru">
                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirmPassword', this)">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>
            <hr>
            <div class="mb-1">
              <label class="form-label fw-semibold">
                <i class="bi bi-shield-lock me-1"></i>Password Saat Ini <span class="text-danger">*</span>
              </label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-key"></i></span>
                <input type="password" name="current_password" id="currentPassword" class="form-control"
                  placeholder="Wajib diisi untuk konfirmasi" required>
                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('currentPassword', this)">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
              <div class="form-text text-danger">Masukkan password Anda saat ini untuk menyimpan perubahan.</div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
              <i class="bi bi-x-circle me-1"></i> Batal
            </button>
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-save me-1"></i> Simpan Perubahan
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

        <!--end::App Content-->
      </main>
      <!--end::App Main-->
      <!--begin::Footer-->
      <footer class="app-footer">
        <!--begin::To the end-->
        <div class="float-end d-none d-sm-inline">PT Pelabuhan Indonesia (Persero)</div>
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
      src="../../dist/js/overlayscrollbars.browser.es6.min.js"

    ></script>
    <!--end::Third Party Plugin(OverlayScrollbars)--><!--begin::Required Plugin(popperjs for Bootstrap 5)-->
    <script
      src="../../dist/js/popper.min.js"
      
    ></script>
    <!--end::Required Plugin(popperjs for Bootstrap 5)--><!--begin::Required Plugin(Bootstrap 5)-->
    <script
      src="../../dist/js/bootstrap.min.js"
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
    <script>
      function togglePassword(fieldId, btn) {
        const input = document.getElementById(fieldId);
        const icon  = btn.querySelector('i');
        if (input.type === 'password') {
          input.type = 'text';
          icon.classList.replace('bi-eye', 'bi-eye-slash');
        } else {
          input.type = 'password';
          icon.classList.replace('bi-eye-slash', 'bi-eye');
        }
      }
      <?php if ($updateError): ?>
      document.addEventListener('DOMContentLoaded', function() {
        var modal = new bootstrap.Modal(document.getElementById('editProfileModal'));
        modal.show();
      });
      <?php endif; ?>
    </script>
    <script>
      sessionStorage.setItem("nipp", "<?php echo $_SESSION['nipp']; ?>");
      sessionStorage.setItem("name", "<?php echo $_SESSION['name']; ?>");
      sessionStorage.setItem("email", "<?php echo $_SESSION['email']; ?>");
    </script>
    <!--end::Script-->
  </body>
  <!--end::Body-->
</html>