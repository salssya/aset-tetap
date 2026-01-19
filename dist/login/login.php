<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "asetreg3_db";

// Create connection
$con = mysqli_connect($servername, $username, $password, $dbname);

$query = "SELECT * FROM users WHERE NIPP='". $_POST['nipp'] ."' and Password='" . $_POST['pass'] . "'";
$result = mysqli_query($con, $query) or die(mysqli_error($con));
$flag = FALSE;
while ($row = mysqli_fetch_array($result, MYSQLI_BOTH)) {
    $_SESSION['nipp'] = $row['NIPP'];
    $flag = TRUE;
    echo "Login Berhasil nipp : " . $_SESSION['nipp'] . " nama : " . $row['Nama'];
}