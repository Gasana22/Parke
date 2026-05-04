<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$user_id = $_SESSION['user_id'];
$booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;

if ($booking_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
    exit();
}

// Get database connection
$conn = getConnection();

// Verify booking belongs to user and hasn't started yet
$check_query = "SELECT b.*, ps.id as slot_id 
                FROM bookings b
                JOIN parking_slots ps ON b.slot_id = ps.id
                WHERE b.id = ? AND b.user_id = ? AND b.end_time IS NULL";
$check_stmt = $conn->prepare($check_query);
$check_stmt->bind_param("ii", $booking_id, $user_id);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows === 0) {
    $check_stmt->close();
    $conn->close();
    echo json_encode(['success' => false, 'message' => 'Invalid booking or already completed']);
    exit();
}

$booking = $check_result->fetch_assoc();
$check_stmt->close();

// Update booking start time to current time
$update_query = "UPDATE bookings SET start_time = NOW() WHERE id = ?";
$update_stmt = $conn->prepare($update_query);
$update_stmt->bind_param("i", $booking_id);

if ($update_stmt->execute()) {
    // Update slot status to occupied
    $update_slot = $conn->prepare("UPDATE parking_slots SET status = 'occupied' WHERE id = ?");
    $update_slot->bind_param("i", $booking['slot_id']);
    $update_slot->execute();
    $update_slot->close();
    
    echo json_encode([
        'success' => true, 
        'booking_id' => $booking_id,
        'message' => 'Parking started successfully'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to start parking: ' . $update_stmt->error]);
}

$update_stmt->close();
$conn->close();
?>