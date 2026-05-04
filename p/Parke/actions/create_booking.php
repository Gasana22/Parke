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
$location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
$slot_id = isset($_POST['slot_id']) ? intval($_POST['slot_id']) : 0;
$vehicle_number = isset($_POST['vehicle_number']) ? trim($_POST['vehicle_number']) : '';

if (empty($vehicle_number)) {
    echo json_encode(['success' => false, 'message' => 'Vehicle number is required']);
    exit();
}

if ($location_id <= 0 || $slot_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid location or slot']);
    exit();
}

// Get database connection
$conn = getConnection();

// Start transaction
$conn->begin_transaction();

try {
    // Check if slot is still available
    $check_slot = $conn->prepare("SELECT status FROM parking_slots WHERE id = ?");
    $check_slot->bind_param("i", $slot_id);
    $check_slot->execute();
    $slot_result = $check_slot->get_result();
    $slot = $slot_result->fetch_assoc();
    
    if (!$slot || $slot['status'] !== 'available') {
        throw new Exception('Slot is no longer available');
    }
    
    // Create booking
    $query = "INSERT INTO bookings (user_id, slot_id, location_id, vehicle_number, start_time) 
              VALUES (?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iiis", $user_id, $slot_id, $location_id, $vehicle_number);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to create booking: ' . $stmt->error);
    }
    
    $booking_id = $stmt->insert_id;
    $stmt->close();
    
    // Update slot status to occupied
    $update_slot = $conn->prepare("UPDATE parking_slots SET status = 'occupied', current_vehicle = ? WHERE id = ?");
    $update_slot->bind_param("si", $vehicle_number, $slot_id);
    if (!$update_slot->execute()) {
        throw new Exception('Failed to update slot: ' . $update_slot->error);
    }
    $update_slot->close();
    
    // Update available slots count
    $update_location = $conn->prepare("UPDATE parking_locations SET available_slots = available_slots - 1 WHERE id = ?");
    $update_location->bind_param("i", $location_id);
    if (!$update_location->execute()) {
        throw new Exception('Failed to update location: ' . $update_location->error);
    }
    $update_location->close();
    
    $conn->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Booking created successfully',
        'booking_id' => $booking_id
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>