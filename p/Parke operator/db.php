<?php
$host = "localhost";
$user = "root";
$password = "root";
$database = "parke";

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

date_default_timezone_set("Africa/Kampala");
?>