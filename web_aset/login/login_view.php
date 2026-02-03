<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "asetreg3_db";

// Create connection
$con = mysqli_connect($servername, $username, $password, $dbname);

session_start();

error_reporting(0);

if (isset($_POST["login"])) {
    $nipp = mysqli_real_escape_string($con, $_POST["nipp"]);
    $password = mysqli_real_escape_string($con, $_POST["password"]);

    $check_user = mysqli_query($con, "SELECT * FROM users WHERE NIPP='$nipp'");

if (mysqli_num_rows($check_user) > 0) {
    $row = mysqli_fetch_assoc($check_user);

    // Verify password (supports hashed passwords and legacy plaintext)
    $isPasswordValid = false;
    if (!empty($row['Password'])) {
        if (password_verify($password, $row['Password'])) {
            $isPasswordValid = true;
        } elseif ($password === $row['Password']) {
            // fallback for plain-text stored passwords
            $isPasswordValid = true;
        }
    }

    if ($isPasswordValid) {
        // login sukses
        $_SESSION["nipp"]  = $row['NIPP'];
        $_SESSION["name"]  = $row['Nama'];
        $_SESSION["email"] = $row['Email'];
        // set Type_User and Cabang if available
        $_SESSION["Type_User"] = isset($row['Type_User']) ? $row['Type_User'] : '';
        $_SESSION["Cabang"]    = isset($row['Cabang']) ? $row['Cabang'] : '';
        $_SESSION["profit_center_text"] = '';

        // Try to populate profit_center_text from import_dat if Cabang available
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

        // Use json_encode to safely inject strings into JS
        echo "
        <script>
            sessionStorage.setItem('nipp', " . json_encode($row['NIPP']) . ");
            sessionStorage.setItem('name', " . json_encode($row['Nama']) . ");
            sessionStorage.setItem('email', " . json_encode($row['Email']) . ");
            window.location = '../../web_aset/dasbor/dasbor.php';
        </script>";
    } else {
        // password salah
        $error = "Password salah!";
    }
} else {
    // NIPP tidak ada di database
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
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            padding: 12px 15px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: #0073b1;
            box-shadow: 0 0 0 3px rgba(0, 115, 177, 0.1);
            outline: none;
        }

        .password-toggle {
            position: relative;
        }

        .password-toggle .toggle-icon {
            position: absolute;
            right: 15px;
            top: 45px;
            cursor: pointer;
            color: #6c757d;
            z-index: 10;
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

        .register-link {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }

        .register-link p {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 10px;
        }

        .register-link a {
            color: white;
            text-decoration: none;
            font-weight: 600;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            padding: 10px 20px;
            border-radius: 6px;
            display: inline-block;
            transition: all 0.3s ease;
        }

        .register-link a:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(40, 167, 69, 0.4);
            color: white;
            text-decoration: none;
        }

        .alert {
            margin-bottom: 20px;
            border-radius: 6px;
            border: none;
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
                <?php echo $error; ?>
            </div>
            <?php endif; ?>

            <form action="" method="POST">
                <div class="form-group">
                    <label for="nipp">NIPP</label>
                    <input type="text" class="form-control" id="nipp" name="nipp" 
                           placeholder="Masukkan NIPP Anda" value="<?php echo isset($_POST['nipp']) ? htmlspecialchars($_POST['nipp']) : ''; ?>" required>
                </div>

                <div class="form-group password-toggle">
                    <label for="password">Password</label>
                    <input type="password" class="form-control" id="password" name="password" 
                           placeholder="Masukkan password" required>
                    <i class="fas fa-eye toggle-icon" onclick="togglePasswordVisibility('password')"></i>
                </div>

                <button type="submit" name="login" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i> Masuk
                </button>
            </form>
        </div>
    </div>

    <script>
        function togglePasswordVisibility(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = event.target;
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>