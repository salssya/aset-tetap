<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "asetreg3_db";

$con = mysqli_connect($servername, $username, $password, $dbname);
session_start();
error_reporting(0);

if (isset($_POST["login"])) {
    $nipp = mysqli_real_escape_string($con, $_POST["nipp"]);
    $password = mysqli_real_escape_string($con, $_POST["password"]);

    $check_user = mysqli_query($con, "SELECT * FROM users WHERE NIPP='$nipp'");

if (mysqli_num_rows($check_user) > 0) {
    $row = mysqli_fetch_assoc($check_user);

    $isPasswordValid = false;
    if (!empty($row['Password'])) {
        if (password_verify($password, $row['Password'])) {
            $isPasswordValid = true;
        } elseif ($password === $row['Password']) {
            $isPasswordValid = true;
        }
    }

    if ($isPasswordValid) {
        $_SESSION["nipp"]  = $row['NIPP'];
        $_SESSION["name"]  = $row['Nama'];
        $_SESSION["email"] = $row['Email'];
        $_SESSION["Type_User"] = isset($row['Type_User']) ? $row['Type_User'] : '';
        $_SESSION["Cabang"]    = isset($row['Cabang']) ? $row['Cabang'] : '';
        $_SESSION["profit_center_text"] = '';

        if (!empty($_SESSION["Cabang"])) {
            $stmt = mysqli_prepare($con, "SELECT profit_center_text FROM import_dat WHERE profit_center = ? LIMIT 1");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 's', $_SESSION["Cabang"]);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                if ($r = mysqli_fetch_assoc($res)) {
                    $_SESSION["profit_center_text"] = $r['profit_center_text'];
                }
                mysqli_stmt_close($stmt);
            }
        }

        echo "
        <script>
            sessionStorage.setItem('nipp', " . json_encode($row['NIPP']) . ");
            sessionStorage.setItem('name', " . json_encode($row['Nama']) . ");
            sessionStorage.setItem('email', " . json_encode($row['Email']) . ");
            window.location = '../../web_aset/dasbor/dasbor.php';
        </script>";
    } else {
        $error = "Password salah!";
    }
} else {
    $error = "NIPP tidak terdaftar!";
}

}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - ASET TETAP</title>
    <link rel="icon" type="image/png" href="../../dist/assets/img/emblem.png" /> 
    <link rel="shortcut icon" type="image/png" href="../../dist/assets/img/emblem.png" />  
    <link rel="stylesheet" href="../../dist/css/bootstrap-icons/bootstrap-icons.min.css" />

    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    html, body {
        height: 100%;
        background: linear-gradient(135deg, #003f87 0%, #0073b1 100%);
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    .login-container {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 100vh;
        padding: 20px;
    }

    .login-card {
        background: white;
        border-radius: 10px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        width: 100%;
        max-width: 450px;
        padding: 40px;
        animation: slideUp 0.5s ease-out;
    }

    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .login-header {
        text-align: center;
        margin-bottom: 30px;
    }

    .login-header h1 {
        color: #003f87;
        font-size: 2.5rem;
        font-weight: bold;
        margin-bottom: 10px;
    }

    .login-header p {
        color: #6c757d;
        font-size: 0.95rem;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        color: #003f87;
        font-weight: 600;
        margin-bottom: 8px;
        display: block;
        font-size: 0.9rem;
    }

    .form-control {
        width: 100% !important;
        border: 2px solid #e0e0e0 !important;
        border-radius: 6px !important;
        padding: 12px 15px !important;
        font-size: 0.95rem !important;
        line-height: 1.5 !important;
        height: auto !important;
        background-color: #fff !important;
        transition: all 0.3s ease;
        box-sizing: border-box !important;
    }

    .form-control:focus {
        border-color: #0073b1 !important;
        box-shadow: 0 0 0 3px rgba(0, 115, 177, 0.1) !important;
        outline: none !important;
        background-color: #fff !important;
    }

    .form-control::placeholder {
        color: #999 !important;
        opacity: 1 !important;
    }

    /* ===== CRITICAL FIX: Hide native browser password reveal button ===== */
    /* Untuk Microsoft Edge & IE */
    input[type="password"]::-ms-reveal,
    input[type="password"]::-ms-clear {
        display: none !important;
        width: 0 !important;
        height: 0 !important;
    }

    /* Untuk Chrome & Edge Chromium */
    input[type="password"]::-webkit-credentials-auto-fill-button,
    input[type="password"]::-webkit-clear-button {
        display: none !important;
    }

    /* Untuk semua browser - pastikan tidak ada button bawaan */
    input[type="password"]::-webkit-textfield-decoration-container {
        visibility: hidden !important;
        pointer-events: none !important;
    }
    /* ===== END FIX ===== */

    /* Password toggle styling */
    .password-wrapper {
        position: relative;
        display: block;
    }

    .password-wrapper input {
        padding-right: 45px !important;
    }

    .password-wrapper .toggle-icon {
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        cursor: pointer;
        color: #6c757d;
        font-size: 1.1rem;
        user-select: none;
        transition: color 0.2s ease;
        z-index: 10;
    }

    .password-wrapper .toggle-icon:hover {
        color: #003f87;
    }

    .btn-login {
        width: 100%;
        padding: 12px;
        background: linear-gradient(135deg, #003f87 0%, #0073b1 100%);
        color: white;
        border: none;
        border-radius: 6px;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        margin-top: 10px;
        margin-bottom: 20px;
    }

    .btn-login:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 20px rgba(0, 115, 177, 0.4);
        color: white;
        text-decoration: none;
    }

    .btn-login:active {
        transform: translateY(0);
    }

    .alert {
        margin-bottom: 20px;
        border-radius: 6px;
        border: none;
        padding: 12px 15px;
    }

    .alert-danger {
        background-color: #f8d7da;
        color: #721c24;
        border-left: 4px solid #dc3545;
    }

    @media (max-width: 480px) {
        .login-card {
            padding: 30px 20px;
        }

        .login-header h1 {
            font-size: 2rem;
        }
    }

    .login-header .logo-img {
        height: 100px;
        width: 100px;
        object-fit: contain;
        margin-bottom: 20px;
    }
</style>
</head>

<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <img src="../../dist/assets/img/emblem.png" alt="Logo ASET TETAP" class="logo-img">
                <h1>ASET TETAP</h1>
                <p>Sistem Manajemen Aset Regional 3</p>
            </div>

            <?php if (isset($error)): ?>
            <div class="alert alert-danger" role="alert">
                <strong></strong> <?php echo $error; ?>
            </div>
            <?php endif; ?>

            <form action="" method="POST">
                <div class="form-group">
                    <label for="nipp">NIPP</label>
                    <input type="text" class="form-control" id="nipp" name="nipp" 
                           placeholder="Masukkan NIPP Anda" 
                           value="<?php echo isset($_POST['nipp']) ? htmlspecialchars($_POST['nipp']) : ''; ?>" 
                           required autocomplete="username">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-wrapper">
                        <input type="password" class="form-control" id="password" name="password" 
                            placeholder="Masukkan password" 
                            required autocomplete="current-password">
                        <i class="bi bi-eye toggle-icon" id="togglePassword"></i>
                    </div>
                </div>

                <button type="submit" name="login" class="btn-login">
                    <i class="bi bi-box-arrow-in-right"></i> Masuk
                </button>
            </form>
        </div>
    </div>

    <script>

        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordField = document.getElementById('password');
            const icon = this;
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                passwordField.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        });

        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>

    <script src="../../dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>