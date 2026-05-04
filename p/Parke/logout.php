<?php
// logout.php
require_once 'includes/functions.php';

// Destroy all session data
$_SESSION = array();

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: login.php");
exit();
?>