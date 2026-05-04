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

// Start transaction
$conn->begin_transaction();

try {
    // Get booking details with price per hour
    $query = "SELECT b.*, pl.price_per_hour, b.slot_id, b.location_id
              FROM bookings b
              JOIN parking_locations pl ON b.location_id = pl.id
              WHERE b.id = ? AND b.user_id = ? AND b.end_time IS NULL";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $booking_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $booking = $result->fetch_assoc();
    $stmt->close();
    
    if (!$booking) {
        throw new Exception('Invalid booking or already ended');
    }
    
    // Calculate total cost (time between start and now, rounded up to nearest hour)
    $start_time = strtotime($booking['start_time']);
    $end_time = time();
    $minutes = ($end_time - $start_time) / 60;
    $hours = ceil($minutes / 60); // Round UP
    $total_cost = $hours * $booking['price_per_hour'];
    
    // Update booking with end time and total cost
    $update_booking = $conn->prepare("UPDATE bookings 
                                     SET end_time = NOW(), total_cost = ?
                                     WHERE id = ?");
    $update_booking->bind_param("di", $total_cost, $booking_id);
    if (!$update_booking->execute()) {
        throw new Exception('Failed to update booking: ' . $update_booking->error);
    }
    $update_booking->close();
    
    // Update slot status back to available
    $update_slot = $conn->prepare("UPDATE parking_slots 
                                  SET status = 'available', current_vehicle = NULL 
                                  WHERE id = ?");
    $update_slot->bind_param("i", $booking['slot_id']);
    if (!$update_slot->execute()) {
        throw new Exception('Failed to update slot: ' . $update_slot->error);
    }
    $update_slot->close();
    
    // Update available slots count
    $update_location = $conn->prepare("UPDATE parking_locations 
                                      SET available_slots = available_slots + 1 
                                      WHERE id = ?");
    $update_location->bind_param("i", $booking['location_id']);
    if (!$update_location->execute()) {
        throw new Exception('Failed to update location: ' . $update_location->error);
    }
    $update_location->close();
    
    $conn->commit();
    
    echo json_encode([
        'success' => true, 
        'booking_id' => $booking_id,
        'total_cost' => $total_cost,
        'hours' => $hours,
        'message' => 'Parking ended successfully'
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>