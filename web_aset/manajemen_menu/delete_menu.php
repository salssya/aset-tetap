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

// Check if id is provided via GET parameter
if (isset($_GET['id'])) {
    $id = mysqli_real_escape_string($con, $_GET['id']);
    
    // First, delete related records from user_access table to avoid foreign key constraint error
    $sql_delete_access = "DELETE FROM user_access WHERE id_menu='$id'";
    mysqli_query($con, $sql_delete_access);

    // Then delete the menu record
    $sql = "DELETE FROM menus WHERE id_menu='$id'";
    
    if (mysqli_query($con, $sql)) {
        echo "Record deleted successfully";
        header("Location: manajemen_menu.php"); // Redirect back to menu management page
        exit();
    } else {
        echo "Error deleting record: " . mysqli_error($con);
    }
} else {
    echo "No ID provided";
}

mysqli_close($con);
?>
