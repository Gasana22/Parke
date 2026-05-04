<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: find_parking.php?step=1");
    exit();
}

$user_id = $_SESSION['user_id'];
$booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;

if ($booking_id <= 0) {
    header("Location: find_parking.php?step=6&booking_id=$booking_id&error=Invalid+booking");
    exit();
}

// Get database connection
$conn = getConnection();

// Check if booking exists and belongs to user
$check_query = "SELECT b.*, pl.name as location_name 
                FROM bookings b
                JOIN parking_locations pl ON b.location_id = pl.id
                WHERE b.id = ? AND b.user_id = ? AND b.end_time IS NOT NULL 
                AND b.payment_status = 'pending'";
$check_stmt = $conn->prepare($check_query);
$check_stmt->bind_param("ii", $booking_id, $user_id);
$check_stmt->execute();
$check_result = $check_stmt->get_result();
$booking = $check_result->fetch_assoc();
$check_stmt->close();

if (!$booking) {
    header("Location: find_parking.php?step=6&booking_id=$booking_id&error=Invalid+booking+or+already+paid");
    exit();
}

// Update payment status
$update_query = "UPDATE bookings SET payment_status = 'paid' WHERE id = ?";
$update_stmt = $conn->prepare($update_query);
$update_stmt->bind_param("i", $booking_id);
$update_stmt->execute();
$update_stmt->close();

$conn->close();

// Store success message
$_SESSION['payment_success'] = true;
$_SESSION['payment_amount'] = $booking['total_cost'];
$_SESSION['payment_booking_id'] = $booking_id;

// Redirect to success page or dashboard
header("Location: payment_success.php?booking_id=" . $booking_id);
exit();
?>