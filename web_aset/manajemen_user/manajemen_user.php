<?php
session_start();
if(!isset($_SESSION["nipp"])) {
    header("Location: ../login/login_view.php");
    exit();
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "asetreg3_db";

// Create connection
$con = mysqli_connect($servername, $username, $password, $dbname);

//kode akses menu
$nipp = $_SESSION['nipp'];
$akses = [];
$result = mysqli_query($con, "SELECT id_menu FROM user_access WHERE NIPP='$nipp'");
while($row = mysqli_fetch_assoc($result)) {
    $akses[] = $row['id_menu'];
}
$_SESSION['akses'] = $akses;

?>
<!doctype html>
<html lang="en">
  <!--begin::Head-->
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Manajemen User - Web Aset Tetap</title>

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
                  <div>
                    <p class="mb-0"><?php echo isset($_SESSION['name']) ? htmlspecialchars($_SESSION['name']) : ''; ?></p>
                    <small>NIPP: <?php echo isset($_SESSION['nipp']) ? htmlspecialchars($_SESSION['nipp']) : ''; ?></small>
                  </div>
                </li>
                <!--end::User Image-->
                <!--begin::Menu Body-->
                <li class="user-menu-body">
                  <div class="ps-3 pe-3 pt-2 pb-2">
                    <span class="badge text-bg-success"><i class="bi bi-circle-fill"></i> Online</span>
                  </div>
                  <hr class="m-0" />
                </li>
                <!--end::Menu Body-->
                <!--begin::Menu Footer-->
                <li class="user-footer">
                  <a href="../manajemen_user/manajemen_user.php" class="btn btn-sm btn-default btn-flat">
                    <i class="bi bi-person"></i> Profile
                  </a>
                  <a href="../login/login_view.php" class="btn btn-sm btn-danger ms-auto" >
                    <i class="bi bi-box-arrow-right"></i> Logout
                  </a>
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
            $userNipp = isset($_SESSION['nipp']) ? htmlspecialchars($_SESSION['nipp']) : '';
            $query = "SELECT menus.menu, menus.nama_menu, menus.urutan_menu FROM user_access INNER JOIN menus ON user_access.id_menu = menus.id_menu WHERE user_access.NIPP = '" . mysqli_real_escape_string($con, $userNipp) . "' ORDER BY menus.urutan_menu ASC";
            $result = mysqli_query($con, $query) or die(mysqli_error($con));
            $iconMap = [
                'Dasboard'               => 'bi bi-grid-fill',
                'Usulan Penghapusan'     => 'bi bi-clipboard-plus-fill',
                'Approval SubReg'        => 'bi bi-check-circle',
                'Approval Regional'      => 'bi bi-check2-square',
                'Persetujuan Penghapusan'=> 'bi bi-clipboard-check-fill',
                'Pelaksanaan Penghapusan'=> 'bi bi-tools',
                'Manajemen Menu'         => 'bi bi-list-ul',
                'Manajemen User'         => 'bi bi-people-fill',
                'Import DAT'             => 'bi bi-file-earmark-arrow-up-fill'
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
              <div class="col-sm-6"><h3 class="mb-0">Manajemen User</h3></div>
              <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                  <li class="breadcrumb-item"><a href="../dasbor/dasbor.php">Home</a></li>
                  <li class="breadcrumb-item active">Manajemen User</li>
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
            <div class="card mb-4">
              <div class="card-header">
                <h3 class="card-title">Daftar User</h3>
                <div class="card-tools">
                  <a href="tambah_user.php" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus"></i> Tambah User
                  </a>
                </div>
              </div>
              <!-- /.card-header -->
              <div class="card-body">
                <table class="table table-bordered table-striped">
                  <thead>
                    <tr>
                      <th>NIPP</th>
                      <th>Nama</th>
                      <th>Email</th>
                      <th>Akses Menu</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                    $query_users = "SELECT users.*, GROUP_CONCAT(user_access.id_menu ORDER BY user_access.id_menu) AS akses_menu 
                                    FROM users 
                                    LEFT JOIN user_access ON users.NIPP = user_access.NIPP 
                                    GROUP BY users.NIPP";
                    $result_users = mysqli_query($con, $query_users);
                    while ($user = mysqli_fetch_assoc($result_users)) {
                      echo "<tr>
                              <td>{$user['NIPP']}</td>
                              <td>{$user['Nama']}</td>
                              <td>{$user['Email']}</td>
                              <td>{$user['akses_menu']}</td>
                              <td>
                                <a href='edit_user.php?nipp={$user['NIPP']}' class='btn btn-warning btn-sm'>Edit</a>
                                <a href='delete_user.php?nipp={$user['NIPP']}' class='btn btn-danger btn-sm' onclick='return confirm(\"Yakin hapus user?\")'>Delete</a>
                              </td>
                            </tr>";
                    }
                    ?>
                  </tbody>
                </table>
              </div>
              <!-- /.card-body -->
            </div>
            <!-- /.card -->
          </div>
          <!--end::Container-->
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
    <script>
      function logout() {
        sessionStorage.removeItem('nipp');
        sessionStorage.removeItem('name');
        sessionStorage.removeItem('email');
        window.location.href = '../login/logout.php';
      }
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
