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
if (!$con) {
    header("Location: manajemen_user.php?status=error&msg=" . urlencode("DB connection failed: " . mysqli_connect_error()));
    exit();
}

// Validate input
if (!isset($_GET['nipp']) || !is_string($_GET['nipp'])) {
    header("Location: manajemen_user.php?status=invalid_id");
    exit();
}

$target_nipp = trim($_GET['nipp']);
if ($target_nipp === '' || strlen($target_nipp) > 50) {
    header("Location: manajemen_user.php?status=invalid_id");
    exit();
}

// Prevent users from deleting themselves
if ($target_nipp === $_SESSION['nipp']) {
    header("Location: manajemen_user.php?status=cannot_delete_self");
    exit();
}

// Use transaction and prepared statements for safe deletion
mysqli_begin_transaction($con);
try {
    // Remove related user_access entries first
    $stmt = mysqli_prepare($con, "DELETE FROM user_access WHERE NIPP = ?");
    if (!$stmt) throw new Exception(mysqli_error($con));
    mysqli_stmt_bind_param($stmt, 's', $target_nipp);
    if (!mysqli_stmt_execute($stmt)) throw new Exception(mysqli_stmt_error($stmt));
    mysqli_stmt_close($stmt);

    // Delete user
    $stmt2 = mysqli_prepare($con, "DELETE FROM users WHERE NIPP = ?");
    if (!$stmt2) throw new Exception(mysqli_error($con));
    mysqli_stmt_bind_param($stmt2, 's', $target_nipp);
    if (!mysqli_stmt_execute($stmt2)) throw new Exception(mysqli_stmt_error($stmt2));

    $affected = mysqli_stmt_affected_rows($stmt2);
    mysqli_stmt_close($stmt2);

    if ($affected > 0) {
        mysqli_commit($con);
        header("Location: manajemen_user.php?status=deleted");
        exit();
    } else {
        mysqli_rollback($con);
        header("Location: manajemen_user.php?status=not_found");
        exit();
    }
} catch (Exception $e) {
    mysqli_rollback($con);
    header("Location: manajemen_user.php?status=error&msg=" . urlencode($e->getMessage()));
    exit();
}

mysqli_close($con);
?>
