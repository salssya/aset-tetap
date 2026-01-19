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

$nipp = $_GET['nipp'];
$query = "DELETE FROM users WHERE NIPP = '$nipp'";
if (mysqli_query($con, $query)) {
    echo "<script>alert('User berhasil dihapus'); window.location='manajemen_user.php';</script>";
} else {
    echo "<script>alert('Error: " . mysqli_error($con) . "'); window.location='manajemen_user.php';</script>";
}
?>
