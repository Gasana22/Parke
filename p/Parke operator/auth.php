<?php
session_start();

if (!isset($_SESSION['location_id'])) {
    header("Location: location_login.php");
    exit();
}
?>